<?php
/**
 * MCP Controller - HTTP Transport for Model Context Protocol
 *
 * Implements Streamable HTTP transport for MCP servers.
 * This allows Claude Code to connect via HTTP instead of stdio,
 * providing persistent connections through nginx/PHP-FPM.
 *
 * Usage in Claude Code:
 *   claude mcp add --transport http jira https://myctobot.ai/mcp/jira
 *
 * Or in .mcp.json:
 *   {
 *     "mcpServers": {
 *       "jira": {
 *         "type": "http",
 *         "url": "https://myctobot.ai/mcp/jira",
 *         "headers": {
 *           "X-MCP-Member-ID": "3",
 *           "X-MCP-Cloud-ID": "xxx"
 *         }
 *       }
 *     }
 *   }
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use app\BaseControls\Control;
use app\services\JiraClient;

require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';
require_once __DIR__ . '/../services/JiraClient.php';

class Mcp extends Control {

    private ?int $memberId = null;
    private ?string $cloudId = null;
    private ?JiraClient $jiraClient = null;

    public function __construct() {
        // Don't call parent - MCP requests don't have sessions
        $this->logger = Flight::get('log');
    }

    /**
     * Tenant-aware MCP endpoint - handles JSON-RPC requests with tenant context
     * POST /mcp/{tenant}/jira
     *
     * The tenant parameter is the domain ID (e.g., gwt-myctobot-ai)
     * This allows using a fixed URL regardless of which subdomain is active.
     *
     * @param string $tenant Domain ID from the URL
     */
    public function jiraWithTenant(string $tenant) {
        // Store tenant for logging purposes
        $this->logger->debug('MCP Jira request with tenant', ['tenant' => $tenant]);

        // Load tenant config and switch database context
        $configFile = "conf/config.{$tenant}.ini";
        if (file_exists($configFile)) {
            $tenantConfig = parse_ini_file($configFile, true);
            if ($tenantConfig && !empty($tenantConfig['database'])) {
                // Switch to tenant database for token lookup
                $dbConfig = $tenantConfig['database'];
                $type = $dbConfig['type'] ?? 'mysql';
                if ($type === 'sqlite') {
                    $dbPath = $dbConfig['path'] ?? "database/{$tenant}.sqlite";
                    $dsn = "sqlite:{$dbPath}";
                    R::addDatabase($tenant, $dsn);
                } else {
                    $host = $dbConfig['host'] ?? 'localhost';
                    $port = $dbConfig['port'] ?? 3306;
                    $name = $dbConfig['name'] ?? $tenant;
                    $user = $dbConfig['user'] ?? 'root';
                    $pass = $dbConfig['pass'] ?? '';
                    $dsn = "{$type}:host={$host};port={$port};dbname={$name}";
                    R::addDatabase($tenant, $dsn, $user, $pass);
                }
                R::selectDatabase($tenant);
                $this->logger->debug('MCP switched to tenant database', ['tenant' => $tenant]);
            }
        }

        // Call the main jira handler
        $this->jira(['tenant' => $tenant]);
    }

    /**
     * Main MCP endpoint - handles all JSON-RPC requests
     * POST /mcp/jira
     */
    public function jira($params = []) {
        // Set CORS headers for MCP clients
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-MCP-Member-ID, X-MCP-Cloud-ID');
        header('Content-Type: application/json');

        // Handle preflight
        if (Flight::request()->method === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        // GET request returns server info (for discovery)
        if (Flight::request()->method === 'GET') {
            echo json_encode([
                'name' => 'mcp-jira-server',
                'version' => '1.0.0',
                'transport' => 'http',
                'protocolVersion' => '2024-11-05'
            ]);
            return;
        }

        // Authenticate request
        if (!$this->authenticate()) {
            http_response_code(401);
            echo json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32000,
                    'message' => 'Authentication required. Provide X-MCP-Member-ID and X-MCP-Cloud-ID headers.'
                ]
            ]);
            return;
        }

        // Parse JSON-RPC request
        $body = file_get_contents('php://input');
        $request = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendError(null, -32700, 'Parse error: Invalid JSON');
            return;
        }

        $this->logger->debug('MCP HTTP request', [
            'method' => $request['method'] ?? 'unknown',
            'member_id' => $this->memberId
        ]);

        try {
            $response = $this->handleRequest($request);
            if (!empty($response)) {
                echo json_encode($response);
            }
        } catch (\Exception $e) {
            $this->logger->error('MCP error', ['error' => $e->getMessage()]);
            $this->sendError($request['id'] ?? null, -32603, $e->getMessage());
        }
    }

    /**
     * Authenticate the MCP request
     *
     * Supports multiple auth methods:
     * 1. Basic Auth: username=member_id, password=cloud_id
     * 2. Bearer token: "member_id:cloud_id"
     * 3. Custom headers: X-MCP-Member-ID and X-MCP-Cloud-ID
     */
    private function authenticate(): bool {
        $request = Flight::request();

        // Method 1: Basic Auth (preferred for Claude Code)
        $authHeader = $request->getHeader('Authorization') ?? '';
        $this->logger->debug('MCP authenticate', [
            'authHeader' => substr($authHeader, 0, 20) . '...',
            'method' => $request->method
        ]);
        if (preg_match('/^Basic\s+(.+)$/', $authHeader, $matches)) {
            $decoded = base64_decode($matches[1]);
            $this->logger->debug('MCP Basic Auth decoded', ['decoded' => $decoded]);
            if ($decoded && strpos($decoded, ':') !== false) {
                list($this->memberId, $this->cloudId) = explode(':', $decoded, 2);
                $this->memberId = (int)$this->memberId;
                $this->logger->debug('MCP credentials parsed', ['memberId' => $this->memberId, 'cloudId' => $this->cloudId]);
            }
        }
        // Method 2: Bearer token containing member:cloud
        elseif (preg_match('/^Bearer\s+(\d+):(.+)$/', $authHeader, $matches)) {
            $this->memberId = (int)$matches[1];
            $this->cloudId = $matches[2];
        }
        // Method 3: Custom headers
        else {
            $this->memberId = (int)($request->getHeader('X-MCP-Member-ID') ?? 0);
            $this->cloudId = $request->getHeader('X-MCP-Cloud-ID') ?? '';
        }

        // Validate member exists and has access to this cloud
        if (!$this->memberId || !$this->cloudId) {
            $this->logger->debug('MCP auth failed: missing credentials', ['memberId' => $this->memberId, 'cloudId' => $this->cloudId]);
            return false;
        }

        // Verify member has a token for this cloud
        $token = R::findOne('atlassiantoken', 'member_id = ? AND cloud_id = ?',
            [$this->memberId, $this->cloudId]);

        $this->logger->debug('MCP auth token lookup', ['found' => !empty($token)]);
        return !empty($token);
    }

    /**
     * Get or create JiraClient
     */
    private function getJiraClient(): JiraClient {
        if (!$this->jiraClient) {
            $this->jiraClient = new JiraClient($this->memberId, $this->cloudId);
        }
        return $this->jiraClient;
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
                return []; // No response needed

            default:
                return $this->errorResponse($id, -32601, "Method not found: {$method}");
        }
    }

    /**
     * Handle initialize request
     */
    private function handleInitialize($id, array $params): array {
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
    private function handleToolsList($id): array {
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
                        'name' => 'jira_get_issue',
                        'description' => 'Get details of a Jira issue including summary, description, status, assignee, and list of attachments',
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
                        'name' => 'jira_get_attachment',
                        'description' => 'Download and view an image attachment from a Jira ticket. Returns the image for visual analysis.',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'attachment_id' => [
                                    'type' => 'string',
                                    'description' => 'The attachment ID (get this from jira_get_issue attachments list)'
                                ]
                            ],
                            'required' => ['attachment_id']
                        ]
                    ],
                    [
                        'name' => 'jira_upload_attachment',
                        'description' => 'Upload an image (e.g., screenshot) to a Jira ticket. Provide EITHER file_path (preferred) OR image_data (base64).',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'issue_key' => [
                                    'type' => 'string',
                                    'description' => 'The Jira issue key (e.g., SSI-1893)'
                                ],
                                'file_path' => [
                                    'type' => 'string',
                                    'description' => 'Path to the image file (preferred method, avoids base64 encoding)'
                                ],
                                'filename' => [
                                    'type' => 'string',
                                    'description' => 'Filename for the attachment. Required if using image_data, optional for file_path'
                                ],
                                'image_data' => [
                                    'type' => 'string',
                                    'description' => 'Base64-encoded image data (alternative to file_path)'
                                ]
                            ],
                            'required' => ['issue_key']
                        ]
                    ],
                    [
                        'name' => 'jira_comment_with_image',
                        'description' => 'Post a comment with an inline image. Either provide an existing attachment_id, or provide file_path to upload and embed in one step.',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'issue_key' => [
                                    'type' => 'string',
                                    'description' => 'The Jira issue key (e.g., SSI-1893)'
                                ],
                                'message' => [
                                    'type' => 'string',
                                    'description' => 'The comment text'
                                ],
                                'attachment_id' => [
                                    'type' => 'string',
                                    'description' => 'Existing attachment ID to embed (use if already uploaded)'
                                ],
                                'file_path' => [
                                    'type' => 'string',
                                    'description' => 'Path to image file - will upload and embed in one step'
                                ]
                            ],
                            'required' => ['issue_key', 'message']
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Handle tools/call request
     */
    private function handleToolCall($id, array $params): array {
        $toolName = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        switch ($toolName) {
            case 'jira_comment':
                return $this->toolJiraComment($id, $args);

            case 'jira_transition':
                return $this->toolJiraTransition($id, $args);

            case 'jira_get_transitions':
                return $this->toolJiraGetTransitions($id, $args);

            case 'jira_get_issue':
                return $this->toolJiraGetIssue($id, $args);

            case 'jira_get_attachment':
                return $this->toolJiraGetAttachment($id, $args);

            case 'jira_upload_attachment':
                return $this->toolJiraUploadAttachment($id, $args);

            case 'jira_comment_with_image':
                return $this->toolJiraCommentWithImage($id, $args);

            default:
                return $this->toolErrorResponse($id, "Unknown tool: {$toolName}");
        }
    }

    /**
     * Tool: Post a comment to Jira
     */
    private function toolJiraComment($id, array $args): array {
        $issueKey = $args['issue_key'] ?? '';
        $message = $args['message'] ?? '';

        if (empty($issueKey) || empty($message)) {
            return $this->toolErrorResponse($id, "Missing issue_key or message");
        }

        try {
            $client = $this->getJiraClient();
            $result = $client->addComment($issueKey, $message);
            return $this->toolSuccessResponse($id, "Comment posted successfully to {$issueKey}");
        } catch (\Exception $e) {
            return $this->toolErrorResponse($id, "Failed to post comment: " . $e->getMessage());
        }
    }

    /**
     * Tool: Transition a Jira ticket
     */
    private function toolJiraTransition($id, array $args): array {
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
                    "Status '{$statusName}' not available. Available: " . implode(', ', $available));
            }

            $result = $client->transitionIssue($issueKey, $transitionId);
            return $this->toolSuccessResponse($id, "Transitioned {$issueKey} to '{$statusName}'");

        } catch (\Exception $e) {
            return $this->toolErrorResponse($id, "Failed to transition: " . $e->getMessage());
        }
    }

    /**
     * Tool: Get available transitions
     */
    private function toolJiraGetTransitions($id, array $args): array {
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

        } catch (\Exception $e) {
            return $this->toolErrorResponse($id, "Failed to get transitions: " . $e->getMessage());
        }
    }

    /**
     * Tool: Get issue details
     */
    private function toolJiraGetIssue($id, array $args): array {
        $issueKey = $args['issue_key'] ?? '';

        if (empty($issueKey)) {
            return $this->toolErrorResponse($id, "Missing issue_key");
        }

        try {
            $client = $this->getJiraClient();
            $issue = $client->getIssue($issueKey);

            // Format attachments with IDs for downloading
            $attachments = [];
            foreach ($issue['fields']['attachment'] ?? [] as $att) {
                $attachments[] = [
                    'id' => $att['id'],
                    'filename' => $att['filename'],
                    'mimeType' => $att['mimeType'],
                    'size' => $att['size'],
                    'created' => $att['created'] ?? ''
                ];
            }

            $formatted = [
                'key' => $issue['key'] ?? $issueKey,
                'summary' => $issue['fields']['summary'] ?? '',
                'status' => $issue['fields']['status']['name'] ?? 'Unknown',
                'assignee' => $issue['fields']['assignee']['displayName'] ?? 'Unassigned',
                'reporter' => $issue['fields']['reporter']['displayName'] ?? 'Unknown',
                'priority' => $issue['fields']['priority']['name'] ?? 'None',
                'description' => $this->extractDescription($issue['fields']['description'] ?? null),
                'labels' => $issue['fields']['labels'] ?? [],
                'attachments' => $attachments,
                'created' => $issue['fields']['created'] ?? '',
                'updated' => $issue['fields']['updated'] ?? ''
            ];

            return $this->toolSuccessResponse($id, json_encode($formatted, JSON_PRETTY_PRINT));

        } catch (\Exception $e) {
            return $this->toolErrorResponse($id, "Failed to get issue: " . $e->getMessage());
        }
    }

    /**
     * Tool: Get attachment (image) from Jira
     */
    private function toolJiraGetAttachment($id, array $args): array {
        $attachmentId = $args['attachment_id'] ?? '';

        if (empty($attachmentId)) {
            return $this->toolErrorResponse($id, "Missing attachment_id");
        }

        try {
            $client = $this->getJiraClient();

            // Get attachment metadata first
            $attachment = $client->getAttachment($attachmentId);

            if (empty($attachment)) {
                return $this->toolErrorResponse($id, "Attachment not found");
            }

            $mimeType = $attachment['mimeType'] ?? 'application/octet-stream';
            $filename = $attachment['filename'] ?? 'attachment';
            $contentUrl = $attachment['content'] ?? null;

            if (empty($contentUrl)) {
                return $this->toolErrorResponse($id, "No content URL for attachment");
            }

            // Check if it's an image type
            $isImage = str_starts_with($mimeType, 'image/');

            if (!$isImage) {
                // For non-images, just return metadata
                return $this->toolSuccessResponse($id,
                    "Attachment '{$filename}' is not an image (type: {$mimeType}). " .
                    "Only image attachments can be displayed.");
            }

            // Download the image content
            $imageData = $client->downloadAttachmentContent($contentUrl);

            if (empty($imageData)) {
                return $this->toolErrorResponse($id, "Failed to download attachment content");
            }

            // Return as image content type for MCP
            return $this->toolImageResponse($id, $imageData, $mimeType, $filename);

        } catch (\Exception $e) {
            return $this->toolErrorResponse($id, "Failed to get attachment: " . $e->getMessage());
        }
    }

    /**
     * Tool: Upload an image attachment to Jira
     * Supports either file_path (direct file) or image_data (base64)
     */
    private function toolJiraUploadAttachment($id, array $args): array {
        $issueKey = $args['issue_key'] ?? '';
        $filePath = $args['file_path'] ?? '';
        $filename = $args['filename'] ?? '';
        $imageData = $args['image_data'] ?? '';

        if (empty($issueKey)) {
            return $this->toolErrorResponse($id, "Missing issue_key");
        }

        if (empty($filePath) && empty($imageData)) {
            return $this->toolErrorResponse($id, "Must provide either file_path or image_data");
        }

        try {
            $client = $this->getJiraClient();
            $tempFile = null;

            // Method 1: Direct file path (preferred)
            if (!empty($filePath)) {
                if (!file_exists($filePath)) {
                    return $this->toolErrorResponse($id, "File not found: {$filePath}");
                }

                $result = $client->uploadAttachment($issueKey, $filePath);
                $attachmentId = $result['id'] ?? 'unknown';
                $attachmentFilename = $result['filename'] ?? basename($filePath);

                return $this->toolSuccessResponse($id,
                    "Successfully uploaded '{$attachmentFilename}' to {$issueKey} (attachment ID: {$attachmentId})");
            }

            // Method 2: Base64 image data
            if (empty($filename)) {
                return $this->toolErrorResponse($id, "filename is required when using image_data");
            }

            // Decode base64 image data
            $binaryData = base64_decode($imageData);
            if ($binaryData === false) {
                return $this->toolErrorResponse($id, "Invalid base64 image data");
            }

            // Create temp file
            $tempFile = sys_get_temp_dir() . '/' . uniqid('jira_upload_') . '_' . $filename;
            if (file_put_contents($tempFile, $binaryData) === false) {
                return $this->toolErrorResponse($id, "Failed to create temporary file");
            }

            try {
                $result = $client->uploadAttachment($issueKey, $tempFile);
                $attachmentId = $result['id'] ?? 'unknown';
                $attachmentFilename = $result['filename'] ?? $filename;

                return $this->toolSuccessResponse($id,
                    "Successfully uploaded '{$attachmentFilename}' to {$issueKey} (attachment ID: {$attachmentId})");

            } finally {
                // Ensure temp file is cleaned up
                if ($tempFile && file_exists($tempFile)) {
                    @unlink($tempFile);
                }
            }

        } catch (\Exception $e) {
            return $this->toolErrorResponse($id, "Failed to upload attachment: " . $e->getMessage());
        }
    }

    /**
     * Tool: Post a comment with an inline image
     */
    private function toolJiraCommentWithImage($id, array $args): array {
        $issueKey = $args['issue_key'] ?? '';
        $message = $args['message'] ?? '';
        $attachmentId = $args['attachment_id'] ?? '';
        $filePath = $args['file_path'] ?? '';

        if (empty($issueKey) || empty($message)) {
            return $this->toolErrorResponse($id, "Missing issue_key or message");
        }

        if (empty($attachmentId) && empty($filePath)) {
            return $this->toolErrorResponse($id, "Must provide either attachment_id or file_path");
        }

        try {
            $client = $this->getJiraClient();

            // If file_path provided, upload first
            if (!empty($filePath)) {
                if (!file_exists($filePath)) {
                    return $this->toolErrorResponse($id, "File not found: {$filePath}");
                }

                $uploadResult = $client->uploadAttachment($issueKey, $filePath);
                $attachmentId = $uploadResult['id'] ?? '';

                if (empty($attachmentId)) {
                    return $this->toolErrorResponse($id, "Failed to upload attachment - no ID returned");
                }
            }

            // Post comment with inline image
            $result = $client->addCommentWithImage($issueKey, $message, $attachmentId);

            $commentId = $result['id'] ?? 'unknown';
            return $this->toolSuccessResponse($id,
                "Comment posted to {$issueKey} with inline image (comment ID: {$commentId}, attachment ID: {$attachmentId})");

        } catch (\Exception $e) {
            return $this->toolErrorResponse($id, "Failed to post comment with image: " . $e->getMessage());
        }
    }

    /**
     * Extract plain text from Jira's ADF description format
     */
    private function extractDescription($description): string {
        if (empty($description)) {
            return '';
        }

        if (is_string($description)) {
            return $description;
        }

        // Handle ADF (Atlassian Document Format)
        if (is_array($description) && isset($description['content'])) {
            return $this->extractTextFromAdf($description['content']);
        }

        return '';
    }

    /**
     * Recursively extract text from ADF content
     */
    private function extractTextFromAdf(array $content): string {
        $text = '';
        foreach ($content as $node) {
            if (isset($node['text'])) {
                $text .= $node['text'];
            }
            if (isset($node['content'])) {
                $text .= $this->extractTextFromAdf($node['content']);
            }
            if (($node['type'] ?? '') === 'paragraph') {
                $text .= "\n";
            }
        }
        return trim($text);
    }

    /**
     * Build a successful tool response
     */
    private function toolSuccessResponse($id, string $text): array {
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
     * Build an image tool response
     */
    private function toolImageResponse($id, string $imageData, string $mimeType, string $filename): array {
        $base64 = base64_encode($imageData);

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "Image: {$filename}"
                    ],
                    [
                        'type' => 'image',
                        'data' => $base64,
                        'mimeType' => $mimeType
                    ]
                ]
            ]
        ];
    }

    /**
     * Build a tool error response
     */
    private function toolErrorResponse($id, string $error): array {
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
    private function errorResponse($id, int $code, string $message): array {
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
     * Send an error response
     */
    private function sendError($id, int $code, string $message): void {
        echo json_encode($this->errorResponse($id, $code, $message));
    }
}
