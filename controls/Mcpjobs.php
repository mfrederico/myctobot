<?php
/**
 * MCP Jobs Controller - Job Status Management via MCP
 *
 * Provides MCP tools for AI Dev runners to report job status back to the orchestrator.
 * This allows Claude Code sessions to signal completion without needing to exit.
 *
 * Tools:
 *   - job_complete: Mark job as complete with results (PR URL, files changed, etc.)
 *   - job_update_status: Update job progress/status
 *   - job_failed: Mark job as failed with error details
 *
 * Usage in .mcp.json:
 *   {
 *     "mcpServers": {
 *       "myctobot": {
 *         "type": "http",
 *         "url": "https://myctobot.ai/mcp/{tenant}/jobs",
 *         "headers": {
 *           "Authorization": "Bearer {api_key}"
 *         }
 *       }
 *     }
 *   }
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;
use app\BaseControls\Control;
use app\services\AIDevStatusService;
use app\services\AIDevJobService;
use app\services\McpResponseTrait;

require_once __DIR__ . '/../services/AIDevStatusService.php';
require_once __DIR__ . '/../services/AIDevJobService.php';
require_once __DIR__ . '/../services/McpResponseTrait.php';

class Mcpjobs extends Control {
    use McpResponseTrait;

    private ?int $memberId = null;
    private ?string $jobId = null;
    private ?string $tenant = null;

    public function __construct() {
        $this->logger = Flight::get('log');
    }

    /**
     * Tenant-aware MCP Jobs endpoint
     * POST /mcp/{tenant}/jobs
     *
     * @param string $tenant Domain ID from the URL
     */
    public function handlewithtenant(string $tenant) {
        $this->tenant = $tenant;
        $this->logger->debug('MCP Jobs request', ['tenant' => $tenant]);

        // Load tenant config and switch database context
        $this->loadTenantDatabase($tenant);

        $this->handle();
    }

    /**
     * Main MCP endpoint handler
     */
    public function handle() {
        // Set CORS headers
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Job-ID, X-Member-ID');
        header('Content-Type: application/json');

        // Handle preflight
        if (Flight::request()->method === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        // GET request returns server info
        if (Flight::request()->method === 'GET') {
            echo json_encode([
                'name' => 'mcp-myctobot-jobs',
                'version' => '1.0.0',
                'transport' => 'http',
                'protocolVersion' => '2024-11-05',
                'description' => 'MyCTOBot Job Status MCP Server - Report job completion/status'
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
                    'message' => 'Authentication required. Provide Authorization header with API key.'
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

        $this->logger->debug('MCP Jobs request', [
            'method' => $request['method'] ?? 'unknown',
            'member_id' => $this->memberId
        ]);

        try {
            $response = $this->handleRequest($request);
            if (!empty($response)) {
                echo json_encode($response);
            }
        } catch (\Exception $e) {
            $this->logger->error('MCP Jobs error', ['error' => $e->getMessage()]);
            $this->sendError($request['id'] ?? null, -32603, $e->getMessage());
        }
    }

    /**
     * Load tenant database context
     */
    private function loadTenantDatabase(string $tenant): void {
        $configFile = "conf/config.{$tenant}.ini";
        if (!file_exists($configFile)) {
            return;
        }

        $tenantConfig = parse_ini_file($configFile, true);
        if (!$tenantConfig || empty($tenantConfig['database'])) {
            return;
        }

        $dbConfig = $tenantConfig['database'];
        $type = $dbConfig['type'] ?? 'mysql';

        if ($type === 'sqlite') {
            $dbPath = $dbConfig['path'] ?? "database/{$tenant}.sqlite";
            $dsn = "sqlite:{$dbPath}";
            Bean::useDatabase($tenant, $dsn);
        } else {
            $host = $dbConfig['host'] ?? 'localhost';
            $port = $dbConfig['port'] ?? 3306;
            $name = $dbConfig['name'] ?? $tenant;
            $user = $dbConfig['user'] ?? 'root';
            $pass = $dbConfig['pass'] ?? '';
            $dsn = "{$type}:host={$host};port={$port};dbname={$name}";
            Bean::useDatabase($tenant, $dsn, $user, $pass);
        }
        $this->logger->debug('MCP Jobs switched to tenant database', ['tenant' => $tenant]);
    }

    /**
     * Authenticate the MCP request
     *
     * Supports:
     * 1. Bearer token with member API key
     * 2. Custom headers: X-Member-ID + X-Job-ID
     */
    private function authenticate(): bool {
        $request = Flight::request();

        // Method 1: Bearer token (member's api_token)
        $authHeader = $request->getHeader('Authorization') ?? '';
        if (preg_match('/^Bearer\s+(.+)$/', $authHeader, $matches)) {
            $apiKey = $matches[1];

            // Look up member by api_token
            $member = R::findOne('member', 'api_token = ?', [$apiKey]);
            if ($member) {
                $this->memberId = (int)$member->id;
                return true;
            }
        }

        // Method 2: Basic auth with member_id:job_id
        if (preg_match('/^Basic\s+(.+)$/', $authHeader, $matches)) {
            $decoded = base64_decode($matches[1]);
            if ($decoded && strpos($decoded, ':') !== false) {
                list($memberId, $jobId) = explode(':', $decoded, 2);
                $this->memberId = (int)$memberId;
                $this->jobId = $jobId;
                return $this->memberId > 0;
            }
        }

        // Method 3: Custom headers
        $this->memberId = (int)($request->getHeader('X-Member-ID') ?? 0);
        $this->jobId = $request->getHeader('X-Job-ID') ?? '';

        return $this->memberId > 0;
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
                    'tools' => ['listChanged' => false]
                ],
                'serverInfo' => [
                    'name' => 'mcp-myctobot-jobs',
                    'version' => '1.0.0'
                ]
            ]
        ];
    }

    /**
     * Handle tools/list request
     */
    private function handleToolsList($id): array {
        $tools = [
            [
                'name' => 'job_complete',
                'description' => 'Mark the current AI Dev job as complete. Call this after creating a PR and posting the summary to the issue tracker. This signals the orchestrator that the job is done.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'job_id' => [
                            'type' => 'string',
                            'description' => 'The job ID (from MYCTOBOT_JOB_ID environment variable)'
                        ],
                        'success' => [
                            'type' => 'boolean',
                            'description' => 'Whether the job completed successfully'
                        ],
                        'pr_url' => [
                            'type' => 'string',
                            'description' => 'URL of the created pull request'
                        ],
                        'pr_number' => [
                            'type' => 'integer',
                            'description' => 'PR number'
                        ],
                        'branch_name' => [
                            'type' => 'string',
                            'description' => 'Name of the feature branch'
                        ],
                        'files_changed' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'List of files that were modified'
                        ],
                        'summary' => [
                            'type' => 'string',
                            'description' => 'Brief summary of what was done'
                        ],
                        'verification_passed' => [
                            'type' => 'boolean',
                            'description' => 'Whether verification tests passed'
                        ]
                    ],
                    'required' => ['job_id', 'success']
                ]
            ],
            [
                'name' => 'job_update_status',
                'description' => 'Update the status/progress of the current job. Use this to report progress during long-running operations.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'job_id' => [
                            'type' => 'string',
                            'description' => 'The job ID'
                        ],
                        'status' => [
                            'type' => 'string',
                            'enum' => ['running', 'implementing', 'verifying', 'creating_pr', 'waiting_clarification'],
                            'description' => 'Current job status'
                        ],
                        'message' => [
                            'type' => 'string',
                            'description' => 'Status message describing current activity'
                        ],
                        'progress' => [
                            'type' => 'integer',
                            'minimum' => 0,
                            'maximum' => 100,
                            'description' => 'Progress percentage (0-100)'
                        ]
                    ],
                    'required' => ['job_id', 'status']
                ]
            ],
            [
                'name' => 'job_failed',
                'description' => 'Mark the current job as failed. Call this when the job cannot be completed due to an error.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'job_id' => [
                            'type' => 'string',
                            'description' => 'The job ID'
                        ],
                        'error_message' => [
                            'type' => 'string',
                            'description' => 'Description of what went wrong'
                        ],
                        'error_type' => [
                            'type' => 'string',
                            'enum' => ['clarification_needed', 'technical_error', 'access_denied', 'invalid_requirements'],
                            'description' => 'Type of failure'
                        ]
                    ],
                    'required' => ['job_id', 'error_message']
                ]
            ]
        ];

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['tools' => $tools]
        ];
    }

    /**
     * Handle tools/call request
     */
    private function handleToolCall($id, array $params): array {
        $toolName = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        $this->logger->info('MCP Jobs tool call', [
            'tool' => $toolName,
            'member_id' => $this->memberId,
            'job_id' => $args['job_id'] ?? 'unknown'
        ]);

        try {
            switch ($toolName) {
                case 'job_complete':
                    $result = $this->toolJobComplete($args);
                    break;

                case 'job_update_status':
                    $result = $this->toolJobUpdateStatus($args);
                    break;

                case 'job_failed':
                    $result = $this->toolJobFailed($args);
                    break;

                default:
                    return $this->errorResponse($id, -32602, "Unknown tool: {$toolName}");
            }

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [
                        ['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT)]
                    ]
                ]
            ];
        } catch (\Exception $e) {
            $this->logger->error('MCP Jobs tool error', [
                'tool' => $toolName,
                'error' => $e->getMessage()
            ]);
            return $this->errorResponse($id, -32603, $e->getMessage());
        }
    }

    /**
     * Tool: job_complete - Mark job as complete
     */
    private function toolJobComplete(array $args): array {
        $jobId = $args['job_id'] ?? '';
        $success = $args['success'] ?? false;
        $prUrl = $args['pr_url'] ?? '';
        $prNumber = $args['pr_number'] ?? null;
        $branchName = $args['branch_name'] ?? '';
        $filesChanged = $args['files_changed'] ?? [];
        $summary = $args['summary'] ?? '';
        $verificationPassed = $args['verification_passed'] ?? false;

        if (empty($jobId)) {
            throw new \InvalidArgumentException('job_id is required');
        }

        // Update job status via AIDevStatusService
        if ($success && $prUrl) {
            AIDevStatusService::complete(
                $this->memberId,
                $jobId,
                $prUrl,
                $prNumber,
                $branchName
            );

            $this->logger->info('Job completed successfully', [
                'job_id' => $jobId,
                'member_id' => $this->memberId,
                'pr_url' => $prUrl,
                'verification_passed' => $verificationPassed
            ]);

            // Trigger post-completion actions (Jira transitions, etc.)
            $this->triggerPostCompletionActions($jobId, [
                'success' => true,
                'pr_url' => $prUrl,
                'pr_number' => $prNumber,
                'branch_name' => $branchName,
                'files_changed' => $filesChanged,
                'summary' => $summary,
                'verification_passed' => $verificationPassed
            ]);

            return [
                'status' => 'completed',
                'message' => 'Job marked as complete. PR created successfully.',
                'job_id' => $jobId,
                'pr_url' => $prUrl
            ];
        } else {
            // Completed but no PR (maybe research task)
            AIDevStatusService::updateStatus(
                $this->memberId,
                $jobId,
                $summary ?: 'Task completed',
                100,
                'completed'
            );

            return [
                'status' => 'completed',
                'message' => 'Job marked as complete.',
                'job_id' => $jobId
            ];
        }
    }

    /**
     * Tool: job_update_status - Update job progress
     */
    private function toolJobUpdateStatus(array $args): array {
        $jobId = $args['job_id'] ?? '';
        $status = $args['status'] ?? 'running';
        $message = $args['message'] ?? '';
        $progress = $args['progress'] ?? null;

        if (empty($jobId)) {
            throw new \InvalidArgumentException('job_id is required');
        }

        // Map friendly status to internal status
        $statusMap = [
            'running' => AIDevStatusService::STATUS_RUNNING,
            'implementing' => AIDevStatusService::STATUS_RUNNING,
            'verifying' => AIDevStatusService::STATUS_RUNNING,
            'creating_pr' => AIDevStatusService::STATUS_RUNNING,
            'waiting_clarification' => AIDevStatusService::STATUS_WAITING_CLARIFICATION
        ];

        $internalStatus = $statusMap[$status] ?? AIDevStatusService::STATUS_RUNNING;

        AIDevStatusService::updateStatus(
            $this->memberId,
            $jobId,
            $message,
            $progress ?? 50,
            $internalStatus
        );

        $this->logger->debug('Job status updated', [
            'job_id' => $jobId,
            'status' => $status,
            'message' => $message
        ]);

        return [
            'status' => 'updated',
            'message' => "Job status updated to: {$status}",
            'job_id' => $jobId
        ];
    }

    /**
     * Tool: job_failed - Mark job as failed
     */
    private function toolJobFailed(array $args): array {
        $jobId = $args['job_id'] ?? '';
        $errorMessage = $args['error_message'] ?? 'Unknown error';
        $errorType = $args['error_type'] ?? 'technical_error';

        if (empty($jobId)) {
            throw new \InvalidArgumentException('job_id is required');
        }

        AIDevStatusService::fail($this->memberId, $jobId, $errorMessage);

        $this->logger->warning('Job failed', [
            'job_id' => $jobId,
            'member_id' => $this->memberId,
            'error_type' => $errorType,
            'error_message' => $errorMessage
        ]);

        // Trigger failure handling (Jira transition, notifications, etc.)
        $this->triggerFailureActions($jobId, $errorMessage, $errorType);

        return [
            'status' => 'failed',
            'message' => 'Job marked as failed. Error has been logged.',
            'job_id' => $jobId,
            'error_type' => $errorType
        ];
    }

    /**
     * Trigger post-completion actions
     */
    private function triggerPostCompletionActions(string $jobId, array $result): void {
        try {
            // Find job details to get issue key, board ID, etc.
            $jobData = AIDevStatusService::getStatus($jobId, $this->memberId);
            if (!$jobData) {
                $this->logger->warning('Could not find job for post-completion', ['job_id' => $jobId]);
                return;
            }

            $issueKey = $jobData['issue_key'] ?? '';
            $boardId = $jobData['board_id'] ?? 0;
            $cloudId = $jobData['cloud_id'] ?? '';

            if (empty($issueKey)) {
                return;
            }

            // Use AIDevJobService for Jira transitions and notifications
            $jobService = new AIDevJobService();

            // For Jira issues (not GitHub), trigger transitions
            if (!empty($cloudId) && $cloudId !== 'github') {
                $jobService->onJobCompleted(
                    $this->memberId,
                    $cloudId,
                    $issueKey,
                    $boardId,
                    $jobId,
                    $result
                );

                // Post PR summary to Jira
                if (!empty($result['pr_url'])) {
                    $jobService->postPRSummaryToJira(
                        $this->memberId,
                        $cloudId,
                        $issueKey,
                        $result
                    );
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error in post-completion actions', [
                'job_id' => $jobId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Trigger failure handling actions
     */
    private function triggerFailureActions(string $jobId, string $errorMessage, string $errorType): void {
        try {
            $jobData = AIDevStatusService::getStatus($jobId, $this->memberId);
            if (!$jobData) {
                return;
            }

            $issueKey = $jobData['issue_key'] ?? '';
            $boardId = $jobData['board_id'] ?? 0;
            $cloudId = $jobData['cloud_id'] ?? '';

            if (empty($issueKey) || empty($cloudId) || $cloudId === 'github') {
                return;
            }

            $jobService = new AIDevJobService();
            $jobService->onJobFailed(
                $this->memberId,
                $cloudId,
                $issueKey,
                $boardId,
                $errorMessage,
                $jobId
            );
        } catch (\Exception $e) {
            $this->logger->error('Error in failure actions', [
                'job_id' => $jobId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
