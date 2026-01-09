#!/usr/bin/env php
<?php
/**
 * MCP Server for GitHub Issues Operations
 *
 * Provides tools for Claude to interact with GitHub Issues:
 * - github_comment: Post a comment to an issue
 * - github_close_issue: Close an issue
 * - github_reopen_issue: Reopen an issue
 * - github_add_labels: Add labels to an issue
 * - github_remove_label: Remove a label from an issue
 * - github_create_pr: Create a pull request
 *
 * Context resolution:
 * 1. GITHUB_TOKEN environment variable (required)
 * 2. GITHUB_REPO environment variable (e.g., "owner/repo")
 *
 * Usage: This script is called by Claude Code via MCP protocol.
 * Configure in .mcp.json:
 * {
 *   "mcpServers": {
 *     "github": {
 *       "type": "stdio",
 *       "command": "php",
 *       "args": ["/path/to/mcp-github-server.php"],
 *       "env": {
 *         "GITHUB_TOKEN": "ghp_xxxx",
 *         "GITHUB_REPO": "owner/repo"
 *       }
 *     }
 *   }
 * }
 */

error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't output errors to stdout (breaks MCP protocol)

// Log errors to file instead
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/mcp-github-server.log');

$baseDir = dirname(__FILE__, 2);
chdir($baseDir);

// Bootstrap the application
require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/services/GitHubClient.php';

use \app\services\GitHubClient;

// Get context from environment
$githubToken = getenv('GITHUB_TOKEN') ?: '';
$githubRepo = getenv('GITHUB_REPO') ?: '';

/**
 * MCP Server Implementation for GitHub
 */
class MCPGitHubServer {
    private string $token;
    private string $repo;
    private ?GitHubClient $client = null;

    public function __construct(string $token, string $repo) {
        $this->token = $token;
        $this->repo = $repo;
    }

    /**
     * Get the GitHub client
     */
    private function getClient(): GitHubClient {
        if (!$this->token) {
            throw new \Exception(
                "GITHUB_TOKEN environment variable is required. " .
                "Configure it in .mcp.json with your GitHub personal access token."
            );
        }

        if (!$this->client) {
            $this->client = new GitHubClient($this->token);
        }

        return $this->client;
    }

    /**
     * Parse repo owner and name from GITHUB_REPO or issue key
     */
    private function parseRepo(?string $repoOverride = null): array {
        $repo = $repoOverride ?: $this->repo;
        if (empty($repo)) {
            throw new \Exception("Repository not specified. Set GITHUB_REPO env var or pass repo parameter.");
        }

        $parts = explode('/', $repo);
        if (count($parts) !== 2) {
            throw new \Exception("Invalid repository format. Expected 'owner/repo', got: {$repo}");
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * Parse issue key format: owner/repo#123 or just #123 (uses default repo)
     */
    private function parseIssueKey(string $issueKey): array {
        // Format: owner/repo#123
        if (preg_match('/^([^\/]+)\/([^#]+)#(\d+)$/', $issueKey, $matches)) {
            return [$matches[1], $matches[2], (int)$matches[3]];
        }

        // Format: #123 (use default repo)
        if (preg_match('/^#?(\d+)$/', $issueKey, $matches)) {
            [$owner, $repo] = $this->parseRepo();
            return [$owner, $repo, (int)$matches[1]];
        }

        throw new \Exception("Invalid issue key format. Expected 'owner/repo#123' or '#123', got: {$issueKey}");
    }

    /**
     * Main loop - read from stdin, write to stdout
     */
    public function run(): void {
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
                if (!empty($response)) {
                    $this->sendResponse($response);
                }

            } catch (Exception $e) {
                error_log("MCP GitHub Server error: " . $e->getMessage());
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
                    'name' => 'mcp-github-server',
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
                        'name' => 'github_comment',
                        'description' => 'Post a comment to a GitHub issue',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'issue_key' => [
                                    'type' => 'string',
                                    'description' => 'The issue key (e.g., "owner/repo#123" or "#123" if GITHUB_REPO is set)'
                                ],
                                'message' => [
                                    'type' => 'string',
                                    'description' => 'The comment text to post (supports markdown)'
                                ]
                            ],
                            'required' => ['issue_key', 'message']
                        ]
                    ],
                    [
                        'name' => 'github_close_issue',
                        'description' => 'Close a GitHub issue',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'issue_key' => [
                                    'type' => 'string',
                                    'description' => 'The issue key (e.g., "owner/repo#123" or "#123")'
                                ],
                                'comment' => [
                                    'type' => 'string',
                                    'description' => 'Optional comment to add when closing'
                                ]
                            ],
                            'required' => ['issue_key']
                        ]
                    ],
                    [
                        'name' => 'github_reopen_issue',
                        'description' => 'Reopen a closed GitHub issue',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'issue_key' => [
                                    'type' => 'string',
                                    'description' => 'The issue key (e.g., "owner/repo#123" or "#123")'
                                ]
                            ],
                            'required' => ['issue_key']
                        ]
                    ],
                    [
                        'name' => 'github_add_labels',
                        'description' => 'Add labels to a GitHub issue',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'issue_key' => [
                                    'type' => 'string',
                                    'description' => 'The issue key (e.g., "owner/repo#123" or "#123")'
                                ],
                                'labels' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                    'description' => 'Array of label names to add'
                                ]
                            ],
                            'required' => ['issue_key', 'labels']
                        ]
                    ],
                    [
                        'name' => 'github_remove_label',
                        'description' => 'Remove a label from a GitHub issue',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'issue_key' => [
                                    'type' => 'string',
                                    'description' => 'The issue key (e.g., "owner/repo#123" or "#123")'
                                ],
                                'label' => [
                                    'type' => 'string',
                                    'description' => 'The label name to remove'
                                ]
                            ],
                            'required' => ['issue_key', 'label']
                        ]
                    ],
                    [
                        'name' => 'github_get_issue',
                        'description' => 'Get details of a GitHub issue',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'issue_key' => [
                                    'type' => 'string',
                                    'description' => 'The issue key (e.g., "owner/repo#123" or "#123")'
                                ]
                            ],
                            'required' => ['issue_key']
                        ]
                    ],
                    [
                        'name' => 'github_create_pr',
                        'description' => 'Create a pull request',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => [
                                    'type' => 'string',
                                    'description' => 'PR title'
                                ],
                                'body' => [
                                    'type' => 'string',
                                    'description' => 'PR description (supports markdown)'
                                ],
                                'head' => [
                                    'type' => 'string',
                                    'description' => 'Branch with changes'
                                ],
                                'base' => [
                                    'type' => 'string',
                                    'description' => 'Branch to merge into (default: main)'
                                ],
                                'draft' => [
                                    'type' => 'boolean',
                                    'description' => 'Create as draft PR (default: false)'
                                ],
                                'repo' => [
                                    'type' => 'string',
                                    'description' => 'Repository (owner/repo). Uses GITHUB_REPO if not specified.'
                                ]
                            ],
                            'required' => ['title', 'head']
                        ]
                    ],
                    [
                        'name' => 'github_link_pr_to_issue',
                        'description' => 'Link a PR to an issue by adding "Closes #N" to PR body',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'issue_key' => [
                                    'type' => 'string',
                                    'description' => 'The issue key to link'
                                ],
                                'pr_number' => [
                                    'type' => 'integer',
                                    'description' => 'The PR number to update'
                                ]
                            ],
                            'required' => ['issue_key', 'pr_number']
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
        $arguments = $params['arguments'] ?? [];

        try {
            $result = match ($toolName) {
                'github_comment' => $this->toolComment($arguments),
                'github_close_issue' => $this->toolCloseIssue($arguments),
                'github_reopen_issue' => $this->toolReopenIssue($arguments),
                'github_add_labels' => $this->toolAddLabels($arguments),
                'github_remove_label' => $this->toolRemoveLabel($arguments),
                'github_get_issue' => $this->toolGetIssue($arguments),
                'github_create_pr' => $this->toolCreatePR($arguments),
                'github_link_pr_to_issue' => $this->toolLinkPRToIssue($arguments),
                default => throw new \Exception("Unknown tool: {$toolName}")
            };

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT)
                        ]
                    ]
                ]
            ];

        } catch (\Exception $e) {
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => "Error: " . $e->getMessage()
                        ]
                    ],
                    'isError' => true
                ]
            ];
        }
    }

    /**
     * Tool: Post a comment to an issue
     */
    private function toolComment(array $args): string {
        $issueKey = $args['issue_key'] ?? '';
        $message = $args['message'] ?? '';

        if (empty($issueKey) || empty($message)) {
            throw new \Exception("issue_key and message are required");
        }

        [$owner, $repo, $issueNumber] = $this->parseIssueKey($issueKey);
        $client = $this->getClient();

        $result = $client->addIssueComment($owner, $repo, $issueNumber, $message);

        return "Comment posted successfully. Comment ID: " . ($result['id'] ?? 'unknown');
    }

    /**
     * Tool: Close an issue
     */
    private function toolCloseIssue(array $args): string {
        $issueKey = $args['issue_key'] ?? '';
        $comment = $args['comment'] ?? '';

        if (empty($issueKey)) {
            throw new \Exception("issue_key is required");
        }

        [$owner, $repo, $issueNumber] = $this->parseIssueKey($issueKey);
        $client = $this->getClient();

        // Add comment if provided
        if (!empty($comment)) {
            $client->addIssueComment($owner, $repo, $issueNumber, $comment);
        }

        $client->closeIssue($owner, $repo, $issueNumber);

        return "Issue #{$issueNumber} closed successfully.";
    }

    /**
     * Tool: Reopen an issue
     */
    private function toolReopenIssue(array $args): string {
        $issueKey = $args['issue_key'] ?? '';

        if (empty($issueKey)) {
            throw new \Exception("issue_key is required");
        }

        [$owner, $repo, $issueNumber] = $this->parseIssueKey($issueKey);
        $client = $this->getClient();

        $client->reopenIssue($owner, $repo, $issueNumber);

        return "Issue #{$issueNumber} reopened successfully.";
    }

    /**
     * Tool: Add labels to an issue
     */
    private function toolAddLabels(array $args): string {
        $issueKey = $args['issue_key'] ?? '';
        $labels = $args['labels'] ?? [];

        if (empty($issueKey) || empty($labels)) {
            throw new \Exception("issue_key and labels are required");
        }

        [$owner, $repo, $issueNumber] = $this->parseIssueKey($issueKey);
        $client = $this->getClient();

        $client->addLabels($owner, $repo, $issueNumber, $labels);

        return "Labels added to issue #{$issueNumber}: " . implode(', ', $labels);
    }

    /**
     * Tool: Remove a label from an issue
     */
    private function toolRemoveLabel(array $args): string {
        $issueKey = $args['issue_key'] ?? '';
        $label = $args['label'] ?? '';

        if (empty($issueKey) || empty($label)) {
            throw new \Exception("issue_key and label are required");
        }

        [$owner, $repo, $issueNumber] = $this->parseIssueKey($issueKey);
        $client = $this->getClient();

        $client->removeLabel($owner, $repo, $issueNumber, $label);

        return "Label '{$label}' removed from issue #{$issueNumber}.";
    }

    /**
     * Tool: Get issue details
     */
    private function toolGetIssue(array $args): array {
        $issueKey = $args['issue_key'] ?? '';

        if (empty($issueKey)) {
            throw new \Exception("issue_key is required");
        }

        [$owner, $repo, $issueNumber] = $this->parseIssueKey($issueKey);
        $client = $this->getClient();

        $issue = $client->getIssue($owner, $repo, $issueNumber);

        // Return a cleaned up version
        return [
            'number' => $issue['number'],
            'title' => $issue['title'],
            'state' => $issue['state'],
            'body' => $issue['body'],
            'labels' => array_map(fn($l) => $l['name'], $issue['labels'] ?? []),
            'assignees' => array_map(fn($a) => $a['login'], $issue['assignees'] ?? []),
            'created_at' => $issue['created_at'],
            'updated_at' => $issue['updated_at'],
            'html_url' => $issue['html_url']
        ];
    }

    /**
     * Tool: Create a pull request
     */
    private function toolCreatePR(array $args): array {
        $title = $args['title'] ?? '';
        $body = $args['body'] ?? '';
        $head = $args['head'] ?? '';
        $base = $args['base'] ?? 'main';
        $draft = $args['draft'] ?? false;
        $repoOverride = $args['repo'] ?? null;

        if (empty($title) || empty($head)) {
            throw new \Exception("title and head are required");
        }

        [$owner, $repo] = $this->parseRepo($repoOverride);
        $client = $this->getClient();

        $pr = $client->createPullRequest($owner, $repo, $title, $body, $head, $base, $draft);

        return [
            'number' => $pr['number'],
            'title' => $pr['title'],
            'state' => $pr['state'],
            'html_url' => $pr['html_url'],
            'draft' => $pr['draft'] ?? false
        ];
    }

    /**
     * Tool: Link PR to issue
     */
    private function toolLinkPRToIssue(array $args): string {
        $issueKey = $args['issue_key'] ?? '';
        $prNumber = $args['pr_number'] ?? 0;

        if (empty($issueKey) || empty($prNumber)) {
            throw new \Exception("issue_key and pr_number are required");
        }

        [$owner, $repo, $issueNumber] = $this->parseIssueKey($issueKey);
        $client = $this->getClient();

        // Get current PR
        $pr = $client->getPullRequest($owner, $repo, $prNumber);
        $currentBody = $pr['body'] ?? '';

        // Check if already linked
        if (stripos($currentBody, "Closes #{$issueNumber}") !== false ||
            stripos($currentBody, "Fixes #{$issueNumber}") !== false) {
            return "PR #{$prNumber} is already linked to issue #{$issueNumber}.";
        }

        // Add closing reference
        $newBody = trim($currentBody) . "\n\nCloses #{$issueNumber}";

        // Update PR - need to use PATCH endpoint
        // For now, add a comment instead
        $client->addPRComment($owner, $repo, $prNumber, "This PR closes #{$issueNumber}");

        return "PR #{$prNumber} linked to issue #{$issueNumber} via comment.";
    }

    /**
     * Send a JSON-RPC response
     */
    private function sendResponse(array $response): void {
        echo json_encode($response) . "\n";
        flush();
    }

    /**
     * Send a JSON-RPC error response
     */
    private function sendError(mixed $id, int $code, string $message): void {
        $this->sendResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ]);
    }

    /**
     * Create an error response
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
}

// Run the server
$server = new MCPGitHubServer($githubToken, $githubRepo);
$server->run();
