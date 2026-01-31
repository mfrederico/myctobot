<?php
/**
 * MCP Jobs Controller - Job Status Management via MCP
 *
 * Provides MCP tools for AI Dev runners to report job status back to the orchestrator.
 * This allows Claude Code sessions to signal completion without needing to exit.
 * Workspace is determined from subdomain (e.g., gwt.myctobot.ai).
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
 *         "url": "https://gwt.myctobot.ai/mcp/jobs",
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
use app\services\ApiAuthService;

require_once __DIR__ . '/../services/AIDevStatusService.php';
require_once __DIR__ . '/../services/AIDevJobService.php';
require_once __DIR__ . '/../services/McpResponseTrait.php';

class Mcpjobs extends Control {
    use McpResponseTrait;

    private ?int $memberId = null;
    private ?string $jobId = null;
    private ?string $workspace = null;

    public function __construct() {
        $this->logger = Flight::get('log');
        // Workspace from subdomain - set by front controller in public/index.php
        $this->workspace = $_SERVER['WORKSPACE'] ?? null;
    }

    /**
     * Main MCP endpoint handler
     * POST /mcp/jobs
     * Workspace is determined from subdomain: https://{workspace}.myctobot.ai/mcp/jobs
     */
    public function index() {
        // Require workspace from subdomain
        if (empty($this->workspace)) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32600,
                    'message' => 'Workspace required. Use https://{workspace}.myctobot.ai/mcp/jobs'
                ]
            ]);
            return;
        }

        $this->logger->debug('MCP Jobs request', ['workspace' => $this->workspace]);

        // Load workspace config and switch database context
        $this->loadWorkspaceDatabase($this->workspace);
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
     * Load workspace database context
     */
    private function loadWorkspaceDatabase(string $workspace): void {
        $configFile = "conf/config.{$workspace}.ini";
        if (!file_exists($configFile)) {
            return;
        }

        $workspaceConfig = parse_ini_file($configFile, true);
        if (!$workspaceConfig || empty($workspaceConfig['database'])) {
            return;
        }

        $dbConfig = $workspaceConfig['database'];
        $type = $dbConfig['type'] ?? 'mysql';

        if ($type === 'sqlite') {
            $dbPath = $dbConfig['path'] ?? "database/{$workspace}.sqlite";
            $dsn = "sqlite:{$dbPath}";
            Bean::useDatabase($workspace, $dsn);
        } else {
            $host = $dbConfig['host'] ?? 'localhost';
            $port = $dbConfig['port'] ?? 3306;
            $name = $dbConfig['name'] ?? $workspace;
            $user = $dbConfig['user'] ?? 'root';
            $pass = $dbConfig['pass'] ?? '';
            $dsn = "{$type}:host={$host};port={$port};dbname={$name}";
            Bean::useDatabase($workspace, $dsn, $user, $pass);
        }
        $this->logger->debug('MCP Jobs switched to workspace database', ['workspace' => $workspace]);
    }

    /**
     * Authenticate the MCP request
     *
     * Uses ApiAuthService for modern API key authentication.
     * Falls back to job-specific auth (Basic auth, custom headers) for runner scripts.
     */
    private function authenticate(): bool {
        $request = Flight::request();

        // Method 1: ApiAuthService (modern API key from apikeys table)
        $authResult = ApiAuthService::authenticate('mcpjobs', 'handle');
        if ($authResult['success']) {
            $this->memberId = (int)$authResult['member']->id;
            return true;
        }

        // Method 2: Basic auth with member_id:job_uid (for runner scripts)
        $authHeader = $request->getHeader('Authorization') ?? '';
        if (preg_match('/^Basic\s+(.+)$/', $authHeader, $matches)) {
            $decoded = base64_decode($matches[1]);
            if ($decoded && strpos($decoded, ':') !== false) {
                list($memberId, $jobId) = explode(':', $decoded, 2);
                $this->memberId = (int)$memberId;
                $this->jobId = $jobId;
                return $this->memberId > 0;
            }
        }

        // Method 3: Custom headers (for runner scripts)
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
                'description' => 'Mark the current AI Dev job as complete. Call this after creating a PR and posting the summary to the issue tracker.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
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
                    'required' => ['success']
                ]
            ],
            [
                'name' => 'job_update_status',
                'description' => 'Update the status/progress of the current job.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
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
                    'required' => ['status']
                ]
            ],
            [
                'name' => 'job_failed',
                'description' => 'Mark the current job as failed.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
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
                    'required' => ['error_message']
                ]
            ],
            [
                'name' => 'job_checkpoint',
                'description' => 'Save a checkpoint with current progress. Use this after creating a PR to record results while keeping your session ALIVE to receive further updates from the issue tracker. This does NOT end the job.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'success' => [
                            'type' => 'boolean',
                            'description' => 'Whether the current phase completed successfully'
                        ],
                        'issue_key' => [
                            'type' => 'string',
                            'description' => 'The Jira/GitHub issue key'
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
                    'required' => ['success']
                ]
            ],
            [
                'name' => 'post_message',
                'description' => 'Post a chat message to the pipeline. Use this to respond conversationally to user instructions. The message will appear in the pipeline chat interface.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => [
                            'type' => 'string',
                            'description' => 'The message to post to the chat'
                        ]
                    ],
                    'required' => ['message']
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
            'job_uid' => $args['job_uid'] ?? 'unknown'
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

                case 'job_checkpoint':
                    $result = $this->toolJobCheckpoint($args);
                    break;

                case 'post_message':
                    $result = $this->toolPostMessage($args);
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
        $jobId = $args['job_uid'] ?? $this->jobId ?? '';
        $success = $args['success'] ?? false;
        $prUrl = $args['pr_url'] ?? '';
        $prNumber = $args['pr_number'] ?? null;
        $branchName = $args['branch_name'] ?? '';
        $filesChanged = $args['files_changed'] ?? [];
        $summary = $args['summary'] ?? '';
        $verificationPassed = $args['verification_passed'] ?? false;

        if (empty($jobId)) {
            throw new \InvalidArgumentException('job_uid is required - not provided in args and not found in auth context');
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
                'job_uid' => $jobId,
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
                'job_uid' => $jobId,
                'pr_url' => $prUrl
            ];
        } else {
            // Completed but no PR (maybe research task or pipeline agent)
            AIDevStatusService::updateStatus(
                $this->memberId,
                $jobId,
                $summary ?: 'Task completed',
                100,
                'completed'
            );

            // Trigger post-completion actions (including pipeline continuation)
            $this->triggerPostCompletionActions($jobId, [
                'success' => $success,
                'summary' => $summary,
                'files_changed' => $filesChanged
            ]);

            return [
                'status' => 'completed',
                'message' => 'Job marked as complete.',
                'job_uid' => $jobId
            ];
        }
    }

    /**
     * Tool: job_update_status - Update job progress
     */
    private function toolJobUpdateStatus(array $args): array {
        $jobId = $args['job_uid'] ?? $this->jobId ?? '';
        $status = $args['status'] ?? 'running';
        $message = $args['message'] ?? '';
        $progress = $args['progress'] ?? null;

        if (empty($jobId)) {
            throw new \InvalidArgumentException('job_uid is required - not provided in args and not found in auth context');
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
            'job_uid' => $jobId,
            'status' => $status,
            'message' => $message
        ]);

        return [
            'status' => 'updated',
            'message' => "Job status updated to: {$status}",
            'job_uid' => $jobId
        ];
    }

    /**
     * Tool: job_failed - Mark job as failed
     */
    private function toolJobFailed(array $args): array {
        $jobId = $args['job_uid'] ?? $this->jobId ?? '';
        $errorMessage = $args['error_message'] ?? 'Unknown error';
        $errorType = $args['error_type'] ?? 'technical_error';

        if (empty($jobId)) {
            throw new \InvalidArgumentException('job_uid is required - not provided in args and not found in auth context');
        }

        AIDevStatusService::fail($this->memberId, $jobId, $errorMessage);

        $this->logger->warning('Job failed', [
            'job_uid' => $jobId,
            'member_id' => $this->memberId,
            'error_type' => $errorType,
            'error_message' => $errorMessage
        ]);

        // Trigger failure handling (Jira transition, notifications, etc.)
        $this->triggerFailureActions($jobId, $errorMessage, $errorType);

        return [
            'status' => 'failed',
            'message' => 'Job marked as failed. Error has been logged.',
            'job_uid' => $jobId,
            'error_type' => $errorType
        ];
    }

    /**
     * Tool: job_checkpoint - Save checkpoint without ending the job
     *
     * This saves progress (PR URL, summary, etc.) but keeps the session alive
     * to receive further updates from the issue tracker.
     */
    private function toolJobCheckpoint(array $args): array {
        // Use job_uid from auth context if not provided in args
        $jobId = $args['job_uid'] ?? $this->jobId ?? '';
        $success = $args['success'] ?? false;
        $issueKey = $args['issue_key'] ?? '';
        $prUrl = $args['pr_url'] ?? '';
        $prNumber = $args['pr_number'] ?? null;
        $branchName = $args['branch_name'] ?? '';
        $filesChanged = $args['files_changed'] ?? [];
        $summary = $args['summary'] ?? '';
        $verificationPassed = $args['verification_passed'] ?? false;

        if (empty($jobId)) {
            throw new \InvalidArgumentException('job_uid is required - not provided in args and not found in auth context');
        }

        // Find the job bean to update
        $job = Bean::findOne('aidevjobs', 'job_uid = ?', [$jobId]);
        if (!$job) {
            throw new \InvalidArgumentException("Job not found: {$jobId}");
        }

        // Save checkpoint data to job record
        $checkpointData = [
            'success' => $success,
            'issue_key' => $issueKey ?: $job->issue_key,
            'pr_url' => $prUrl,
            'pr_number' => $prNumber,
            'branch_name' => $branchName,
            'files_changed' => $filesChanged,
            'summary' => $summary,
            'verification_passed' => $verificationPassed,
            'checkpoint_at' => date('Y-m-d H:i:s')
        ];

        // Update job with checkpoint data (but keep status as 'running')
        $job->checkpoint_data = json_encode($checkpointData);
        $job->pr_url = $prUrl ?: $job->pr_url;
        $job->branch_name = $branchName ?: $job->branch_name;
        $job->updated_at = date('Y-m-d H:i:s');
        // Keep status as running - session stays alive
        if ($job->status !== 'waiting_clarification') {
            $job->status = 'running';
        }
        $job->current_step = $success ? 'PR created, waiting for feedback' : 'Checkpoint saved';
        Bean::store($job);

        $this->logger->info('Job checkpoint saved', [
            'job_uid' => $jobId,
            'member_id' => $this->memberId,
            'pr_url' => $prUrl,
            'success' => $success,
            'verification_passed' => $verificationPassed
        ]);

        // Log the checkpoint event
        AIDevStatusService::log($jobId, $this->memberId, 'info', 'Checkpoint saved', [
            'pr_url' => $prUrl,
            'summary' => $summary,
            'verification_passed' => $verificationPassed
        ]);

        return [
            'status' => 'checkpoint_saved',
            'message' => 'Checkpoint saved successfully. Your session remains ALIVE to receive further updates.',
            'job_uid' => $jobId,
            'pr_url' => $prUrl,
            'session_alive' => true
        ];
    }

    /**
     * Tool: post_message - Post a chat message to the pipeline
     *
     * If the job is associated with a pipeline run, this adds a message
     * to the pipeline's chat context for display in the web UI.
     */
    private function toolPostMessage(array $args): array {
        $message = $args['message'] ?? '';
        $jobId = $args['job_uid'] ?? $this->jobId ?? '';

        if (empty($message)) {
            throw new \InvalidArgumentException('message is required');
        }

        if (empty($jobId)) {
            throw new \InvalidArgumentException('job_uid is required');
        }

        // Find the job to get pipeline run association
        $job = Bean::findOne('aidevjobs', 'job_uid = ?', [$jobId]);
        if (!$job) {
            throw new \InvalidArgumentException("Job not found: {$jobId}");
        }

        // Check if job is associated with a pipeline run
        $runId = $job->pipelineruns_id ?? null;
        if (!$runId) {
            // Try to extract from issue_key (e.g., "PIPE-340-spawn_agent")
            if (preg_match('/^PIPE-(\d+)-/', $job->issue_key, $matches)) {
                $runId = (int) $matches[1];
            }
        }

        if (!$runId) {
            return [
                'success' => false,
                'message' => 'Job is not associated with a pipeline run. Message not posted.',
                'job_uid' => $jobId
            ];
        }

        // Load the pipeline run
        $run = Bean::load('pipelineruns', $runId);
        if (!$run || !$run->id) {
            return [
                'success' => false,
                'message' => "Pipeline run {$runId} not found",
                'job_uid' => $jobId
            ];
        }

        // Add message to context
        $context = json_decode($run->context_json ?: '{}', true);
        if (!isset($context['_messages'])) {
            $context['_messages'] = [];
        }

        $context['_messages'][] = [
            'role' => 'agent',
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        // Keep only last 50 messages
        if (count($context['_messages']) > 50) {
            $context['_messages'] = array_slice($context['_messages'], -50);
        }

        $run->context_json = json_encode($context);
        $run->updated_at = date('Y-m-d H:i:s');
        Bean::store($run);

        $this->logger->info('Chat message posted to pipeline', [
            'job_uid' => $jobId,
            'run_id' => $runId,
            'message_length' => strlen($message)
        ]);

        return [
            'success' => true,
            'message' => 'Message posted to pipeline chat',
            'job_uid' => $jobId,
            'run_id' => $runId,
            'message_count' => count($context['_messages'])
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
                $this->logger->warning('Could not find job for post-completion', ['job_uid' => $jobId]);
                return;
            }

            $issueKey = $jobData['issue_key'] ?? '';
            $boardId = $jobData['board_id'] ?? 0;
            $cloudId = $jobData['cloud_uid'] ?? '';

            // Check if this is a pipeline job - if so, trigger pipeline continuation
            $pipelineRunId = $jobData['pipelineruns_id'] ?? null;
            if ($pipelineRunId) {
                $this->triggerPipelineContinuation($pipelineRunId, $jobId, $result);
            }

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
                'job_uid' => $jobId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Trigger pipeline continuation when a pipeline job completes
     */
    private function triggerPipelineContinuation(int $runId, string $jobId, array $result): void {
        try {
            $this->logger->info('Pipeline job completed, triggering continuation', [
                'run_id' => $runId,
                'job_uid' => $jobId
            ]);

            // Load the pipeline run
            $run = Bean::load('pipelineruns', $runId);
            if (!$run || !$run->id) {
                $this->logger->warning('Pipeline run not found for continuation', ['run_id' => $runId]);
                return;
            }

            // Only continue if run is still in 'running' status
            if ($run->status !== 'running') {
                $this->logger->info('Pipeline run not in running status, skipping continuation', [
                    'run_id' => $runId,
                    'status' => $run->status
                ]);
                return;
            }

            // Store job result in run context for next step to access
            $context = json_decode($run->context_json ?: '{}', true);
            $context['_last_job_result'] = $result;
            $context['_last_job_uid'] = $jobId;
            $run->context_json = json_encode($context);
            $run->updated_at = date('Y-m-d H:i:s');
            Bean::store($run);

            // Trigger continuation via cron script (non-blocking)
            $workspace = $this->workspace ?: 'dev';
            $scriptPath = dirname(__DIR__) . '/scripts/cron-scheduled-tasks.php';
            $cmd = sprintf(
                'php %s --script --workspace=%s --continue-run=%d >> %s/log/pipeline-continuation.log 2>&1 &',
                escapeshellarg($scriptPath),
                escapeshellarg($workspace),
                $runId,
                dirname(__DIR__)
            );

            exec($cmd);

            $this->logger->info('Pipeline continuation triggered', [
                'run_id' => $runId,
                'workspace' => $workspace
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error triggering pipeline continuation', [
                'run_id' => $runId,
                'job_uid' => $jobId,
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
            $cloudId = $jobData['cloud_uid'] ?? '';

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
                'job_uid' => $jobId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
