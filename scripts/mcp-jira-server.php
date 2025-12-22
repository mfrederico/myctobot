#!/usr/bin/env php
<?php
/**
 * MCP Server for Jira Operations
 *
 * Provides tools for Claude to interact with Jira:
 * - jira_comment: Post a comment to a ticket
 * - jira_transition: Transition a ticket to a new status
 * - jira_upload_attachment: Upload a file as attachment
 * - jira_get_transitions: Get available transitions for a ticket
 *
 * Context resolution (in order of priority):
 * 1. Explicit cloud_id parameter in tool call
 * 2. JIRA_CLOUD_ID and JIRA_MEMBER_ID environment variables
 * 3. Derived from issue key by looking up board → member → cloud_id
 *
 * Usage: This script is called by Claude Code via MCP protocol.
 * Configure in .mcp.json:
 * {
 *   "mcpServers": {
 *     "jira": {
 *       "type": "stdio",
 *       "command": "php",
 *       "args": ["/path/to/mcp-jira-server.php"],
 *       "env": {
 *         "JIRA_MEMBER_ID": "3",
 *         "JIRA_CLOUD_ID": "xxx"
 *       }
 *     }
 *   }
 * }
 */

error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't output errors to stdout (breaks MCP protocol)

// Log errors to file instead
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/mcp-jira-server.log');

$baseDir = dirname(__FILE__, 2);
chdir($baseDir);

// Bootstrap the application
require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/bootstrap.php';
require_once $baseDir . '/lib/plugins/AtlassianAuth.php';
require_once $baseDir . '/services/JiraClient.php';

use \app\Bootstrap;
use \app\services\JiraClient;

$bootstrap = new Bootstrap($baseDir . '/conf/config.ini');

// Get default context from environment (can be overridden per-call)
$defaultMemberId = (int)(getenv('JIRA_MEMBER_ID') ?: 0);
$defaultCloudId = getenv('JIRA_CLOUD_ID') ?: '';

/**
 * MCP Server Implementation
 */
class MCPJiraServer {
    private int $defaultMemberId;
    private string $defaultCloudId;
    private array $clientCache = []; // Cache JiraClient per cloud_id

    public function __construct(int $defaultMemberId, string $defaultCloudId) {
        $this->defaultMemberId = $defaultMemberId;
        $this->defaultCloudId = $defaultCloudId;
    }

    /**
     * Get the required context from environment variables
     *
     * SECURITY: Environment variables are REQUIRED to prevent prompt injection attacks
     * where a malicious prompt could try to write to another user's Jira instance.
     *
     * @throws \Exception if environment variables are not set
     */
    private function getContext(): array {
        if (!$this->defaultMemberId || !$this->defaultCloudId) {
            throw new \Exception(
                "JIRA_MEMBER_ID and JIRA_CLOUD_ID environment variables are required. " .
                "Configure these in .mcp.json to specify which Jira instance to use."
            );
        }

        return ['member_id' => $this->defaultMemberId, 'cloud_id' => $this->defaultCloudId];
    }

    /**
     * Get or create JiraClient for the configured context
     */
    private function getJiraClient(): JiraClient {
        $context = $this->getContext();
        $cacheKey = "{$context['member_id']}:{$context['cloud_id']}";

        if (!isset($this->clientCache[$cacheKey])) {
            $this->clientCache[$cacheKey] = new JiraClient($context['member_id'], $context['cloud_id']);
        }

        return $this->clientCache[$cacheKey];
    }

    /**
     * Main loop - read from stdin, write to stdout
     */
    public function run(): void {
        // Set streams to non-blocking would break things, keep blocking
        stream_set_blocking(STDIN, true);

        while (true) {
            $line = fgets(STDIN);
            if ($line === false) {
                break; // EOF
            }

            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            try {
                $request = json_decode($line, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->sendError(null, -32700, "Parse error");
                    continue;
                }

                $response = $this->handleRequest($request);
                $this->sendResponse($response);

            } catch (Exception $e) {
                error_log("MCP Jira Server error: " . $e->getMessage());
                $this->sendError($request['id'] ?? null, -32603, $e->getMessage());
            }
        }
    }

    /**
     * Handle a JSON-RPC request
     */
    private function handleRequest(array $request): array {
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? null;

        switch ($method) {
            case 'initialize':
                return $this->handleInitialize($id, $params);

            case 'tools/list':
                return $this->handleToolsList($id);

            case 'tools/call':
                return $this->handleToolCall($id, $params);

            case 'notifications/initialized':
                // Client notification, no response needed
                return [];

            default:
                return $this->errorResponse($id, -32601, "Method not found: {$method}");
        }
    }

    /**
     * Handle initialize request
     */
    private function handleInitialize(mixed $id, array $params): array {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [
                    'tools' => new \stdClass()
                ],
                'serverInfo' => [
                    'name' => 'mcp-jira-server',
                    'version' => '1.0.0'
                ]
            ]
        ];
    }

    /**
     * Handle tools/list request
     */
    private function handleToolsList(mixed $id): array {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'tools' => [
                    [
                        'name' => 'jira_comment',
                        'description' => 'Post a comment to a Jira ticket',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'issue_key' => [
                                    'type' => 'string',
                                    'description' => 'The Jira issue key (e.g., SSI-1893)'
                                ],
                                'message' => [
                                    'type' => 'string',
                                    'description' => 'The comment text to post'
                                ]
                            ],
                            'required' => ['issue_key', 'message']
                        ]
                    ],
                    [
                        'name' => 'jira_transition',
                        'description' => 'Transition a Jira ticket to a new status',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'issue_key' => [
                                    'type' => 'string',
                                    'description' => 'The Jira issue key (e.g., SSI-1893)'
                                ],
                                'status_name' => [
                                    'type' => 'string',
                                    'description' => 'The target status name (e.g., "In Progress", "Done")'
                                ]
                            ],
                            'required' => ['issue_key', 'status_name']
                        ]
                    ],
                    [
                        'name' => 'jira_get_transitions',
                        'description' => 'Get available status transitions for a Jira ticket',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'issue_key' => [
                                    'type' => 'string',
                                    'description' => 'The Jira issue key (e.g., SSI-1893)'
                                ]
                            ],
                            'required' => ['issue_key']
                        ]
                    ],
                    [
                        'name' => 'jira_upload_attachment',
                        'description' => 'Upload a file as an attachment to a Jira ticket',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'issue_key' => [
                                    'type' => 'string',
                                    'description' => 'The Jira issue key (e.g., SSI-1893)'
                                ],
                                'file_path' => [
                                    'type' => 'string',
                                    'description' => 'Path to the file to upload'
                                ]
                            ],
                            'required' => ['issue_key', 'file_path']
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Handle tools/call request
     */
    private function handleToolCall(mixed $id, array $params): array {
        $toolName = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        switch ($toolName) {
            case 'jira_comment':
                return $this->toolJiraComment($id, $args);

            case 'jira_transition':
                return $this->toolJiraTransition($id, $args);

            case 'jira_get_transitions':
                return $this->toolJiraGetTransitions($id, $args);

            case 'jira_upload_attachment':
                return $this->toolJiraUploadAttachment($id, $args);

            default:
                return $this->toolErrorResponse($id, "Unknown tool: {$toolName}");
        }
    }

    /**
     * Post a comment to Jira
     */
    private function toolJiraComment(mixed $id, array $args): array {
        $issueKey = $args['issue_key'] ?? '';
        $message = $args['message'] ?? '';

        if (empty($issueKey) || empty($message)) {
            return $this->toolErrorResponse($id, "Missing issue_key or message");
        }

        try {
            $client = $this->getJiraClient();
            $result = $client->addComment($issueKey, $message);
            return $this->toolSuccessResponse($id, "Comment posted successfully to {$issueKey}");
        } catch (Exception $e) {
            return $this->toolErrorResponse($id, "Failed to post comment: " . $e->getMessage());
        }
    }

    /**
     * Transition a Jira ticket
     */
    private function toolJiraTransition(mixed $id, array $args): array {
        $issueKey = $args['issue_key'] ?? '';
        $statusName = $args['status_name'] ?? '';

        if (empty($issueKey) || empty($statusName)) {
            return $this->toolErrorResponse($id, "Missing issue_key or status_name");
        }

        try {
            $client = $this->getJiraClient();

            // Get available transitions
            $transitions = $client->getTransitions($issueKey);

            // Find the transition ID for the target status
            $transitionId = null;
            foreach ($transitions as $t) {
                if (strcasecmp($t['name'], $statusName) === 0 ||
                    strcasecmp($t['to']['name'] ?? '', $statusName) === 0) {
                    $transitionId = $t['id'];
                    break;
                }
            }

            if (!$transitionId) {
                $available = array_map(fn($t) => $t['name'], $transitions);
                return $this->toolErrorResponse($id,
                    "Status '{$statusName}' not available. Available transitions: " . implode(', ', $available));
            }

            // Execute the transition
            $result = $client->transitionIssue($issueKey, $transitionId);
            return $this->toolSuccessResponse($id, "Transitioned {$issueKey} to '{$statusName}'");

        } catch (Exception $e) {
            return $this->toolErrorResponse($id, "Failed to transition: " . $e->getMessage());
        }
    }

    /**
     * Get available transitions
     */
    private function toolJiraGetTransitions(mixed $id, array $args): array {
        $issueKey = $args['issue_key'] ?? '';

        if (empty($issueKey)) {
            return $this->toolErrorResponse($id, "Missing issue_key");
        }

        try {
            $client = $this->getJiraClient();
            $transitions = $client->getTransitions($issueKey);
            $formatted = array_map(function($t) {
                return [
                    'id' => $t['id'],
                    'name' => $t['name'],
                    'to_status' => $t['to']['name'] ?? $t['name']
                ];
            }, $transitions);

            return $this->toolSuccessResponse($id, json_encode($formatted, JSON_PRETTY_PRINT));

        } catch (Exception $e) {
            return $this->toolErrorResponse($id, "Failed to get transitions: " . $e->getMessage());
        }
    }

    /**
     * Upload an attachment to Jira
     */
    private function toolJiraUploadAttachment(mixed $id, array $args): array {
        $issueKey = $args['issue_key'] ?? '';
        $filePath = $args['file_path'] ?? '';

        if (empty($issueKey) || empty($filePath)) {
            return $this->toolErrorResponse($id, "Missing issue_key or file_path");
        }

        if (!file_exists($filePath)) {
            return $this->toolErrorResponse($id, "File not found: {$filePath}");
        }

        try {
            $client = $this->getJiraClient();
            $result = $client->uploadAttachment($issueKey, $filePath);
            $filename = basename($filePath);
            return $this->toolSuccessResponse($id, "Uploaded '{$filename}' to {$issueKey}");

        } catch (Exception $e) {
            return $this->toolErrorResponse($id, "Failed to upload attachment: " . $e->getMessage());
        }
    }

    /**
     * Build a successful tool response
     */
    private function toolSuccessResponse(mixed $id, string $text): array {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $text
                    ]
                ]
            ]
        ];
    }

    /**
     * Build a tool error response
     */
    private function toolErrorResponse(mixed $id, string $error): array {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "Error: {$error}"
                    ]
                ],
                'isError' => true
            ]
        ];
    }

    /**
     * Build an error response
     */
    private function errorResponse(mixed $id, int $code, string $message): array {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ];
    }

    /**
     * Send a response to stdout
     */
    private function sendResponse(array $response): void {
        if (empty($response)) {
            return; // Notification, no response needed
        }
        echo json_encode($response) . "\n";
        flush();
    }

    /**
     * Send an error response
     */
    private function sendError(mixed $id, int $code, string $message): void {
        $this->sendResponse($this->errorResponse($id, $code, $message));
    }
}

// Run the server
$server = new MCPJiraServer($defaultMemberId, $defaultCloudId);
$server->run();
