<?php
/**
 * AI Developer Job Service
 *
 * Handles triggering and managing AI Developer jobs on shards.
 * Used by both Enterprise UI and Webhook handlers.
 */

namespace app\services;

use \Flight as Flight;
use \RedBeanPHP\R as R;

require_once __DIR__ . '/EncryptionService.php';
require_once __DIR__ . '/JiraClient.php';
require_once __DIR__ . '/AIDevStatusService.php';
require_once __DIR__ . '/ShardRouter.php';
require_once __DIR__ . '/ShopifyClient.php';
require_once __DIR__ . '/TmuxService.php';
require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';

use \app\plugins\AtlassianAuth;

class AIDevJobService {

    private $logger;

    public function __construct() {
        $this->logger = Flight::get('log');
    }

    /**
     * Trigger an AI Developer job on a shard
     *
     * @param int $memberId Member ID
     * @param string $issueKey Jira issue key (e.g., "SSI-1871")
     * @param string $cloudId Atlassian cloud ID
     * @param int|null $boardId Board ID (optional, will be auto-detected from issue key)
     * @param int|null $repoId Repository connection ID (optional, will use first enabled)
     * @param string|null $tenant Tenant slug for multi-tenancy
     * @param bool $useOrchestrator Whether to use orchestrator mode
     * @return array Result with 'success', 'job_id', 'error' keys
     */
    public function triggerJob(int $memberId, string $issueKey, string $cloudId, ?int $boardId = null, ?int $repoId = null, ?string $tenant = null, bool $useOrchestrator = false): array {
        try {
            // Validate member has enterprise tier
            $member = R::load('member', $memberId);
            if (!$member || !$member->id) {
                return ['success' => false, 'error' => 'Member not found'];
            }

            $tier = $member->getTier();
            if ($tier !== 'enterprise') {
                return ['success' => false, 'error' => 'Enterprise tier required'];
            }

            // API key and model will be determined per-board later (after boardId is resolved)

            // Auto-detect board ID from issue key if not provided
            if (!$boardId) {
                $projectKey = explode('-', $issueKey)[0];
                $board = R::findOne('jiraboards', 'project_key = ? AND member_id = ?', [$projectKey, $memberId]);
                if (!$board) {
                    return ['success' => false, 'error' => "No board found for project: {$projectKey}"];
                }
                $boardId = $board->id;
            }

            // Auto-detect repo ID if not provided
            if (!$repoId) {
                $repo = R::findOne('repoconnections', 'enabled = ? AND member_id = ?', [1, $memberId]);
                if (!$repo) {
                    return ['success' => false, 'error' => 'No enabled repository connections'];
                }
                $repoId = $repo->id;
            }

            // Check for existing active job (prevent duplicates)
            // Use TmuxService to verify actual tmux session exists (authoritative source)
            $tmux = new TmuxService($memberId, $issueKey);
            $tmuxSessionRunning = $tmux->exists() && $tmux->isClaudeRunning();

            $existingJob = AIDevStatusService::findJobByIssueKey($memberId, $issueKey);
            if ($existingJob) {
                // Status file says running/pending - verify with tmux
                if (in_array($existingJob['status'], ['running', 'pending'])) {
                    if ($tmuxSessionRunning) {
                        // Tmux session exists - actually running
                        return ['success' => false, 'error' => 'Job already running for this issue', 'job_id' => $existingJob['job_id']];
                    } else {
                        // Stale status file - tmux session is gone, auto-cleanup
                        $this->logger->info('Auto-cleanup stale job status (tmux gone)', [
                            'issue_key' => $issueKey,
                            'job_id' => $existingJob['job_id'],
                            'stale_status' => $existingJob['status']
                        ]);
                        AIDevStatusService::fail($memberId, $existingJob['job_id'], 'Session ended unexpectedly');
                    }
                }

                // Cooldown: if job completed/failed within last 2 minutes, skip
                // This prevents rapid re-triggering from webhook loops
                $lastUpdated = strtotime($existingJob['updated_at'] ?? $existingJob['created_at'] ?? '');
                $cooldownSeconds = 120; // 2 minutes
                if ($lastUpdated && (time() - $lastUpdated) < $cooldownSeconds) {
                    return ['success' => false, 'error' => 'Recent job exists, cooldown active', 'job_id' => $existingJob['job_id']];
                }
            } elseif ($tmuxSessionRunning) {
                // No status file but tmux session exists (orphaned session)
                $this->logger->info('Tmux session exists but no status file', [
                    'issue_key' => $issueKey,
                    'member_id' => $memberId
                ]);
                return ['success' => false, 'error' => 'Session exists for this issue (no status file)'];
            }

            // Branch affinity: find existing branch from ANY previous job (including completed ones)
            // This ensures we reuse branches instead of creating duplicates
            $existingBranch = AIDevStatusService::findBranchForIssueKey($memberId, $issueKey);
            if ($existingBranch) {
                Flight::get('log')->info('Branch affinity: reusing existing branch', [
                    'issue_key' => $issueKey,
                    'branch' => $existingBranch
                ]);
            }

            // Get board's API key setting: NULL = local runner, ID = use that key
            $boardBean = R::load('jiraboards', $boardId);
            $boardKeyId = $boardBean->aidev_anthropic_key_id;

            // Determine execution mode: NULL key ID = local runner
            $useLocalRunner = ($boardKeyId === null || $boardKeyId === '' || !$boardKeyId);

            // Fall back to global config if board has no setting
            if ($useLocalRunner) {
                // Check if global config overrides to use API
                $globalUseLocal = Flight::get('aidev.use_local_runner') ?? true;
                $useLocalRunner = $globalUseLocal;
            }

            if ($useLocalRunner) {
                $this->logger->info('Using local runner for AI Developer job', [
                    'member_id' => $memberId,
                    'issue_key' => $issueKey,
                    'board_key_id' => $boardKeyId,
                    'source' => 'local_runner'
                ]);

                $result = $this->spawnLocalRunner($memberId, $issueKey, $boardId, $cloudId, $repoId, $tenant, $useOrchestrator);

                if ($result['success']) {
                    // Add working label and transition status
                    $this->onJobStarted($memberId, $cloudId, $issueKey, $boardId);
                }

                return $result;
            }

            // === API execution path - get key and model from anthropickeys table ===
            $keyBean = R::load('anthropickeys', $boardKeyId);

            if (!$keyBean || !$keyBean->id || empty($keyBean->api_key)) {
                return ['success' => false, 'error' => 'Selected API key not found or invalid'];
            }

            $apiKey = EncryptionService::decrypt($keyBean->api_key);
            $model = $keyBean->model ?? 'claude-sonnet-4-20250514';

            $this->logger->info('Using API key for AI Developer job', [
                'member_id' => $memberId,
                'issue_key' => $issueKey,
                'board_key_id' => $boardKeyId,
                'model' => $model
            ]);

            // === Shard execution path below ===

            // Check shard concurrency limits
            $concurrencyCheck = $this->checkShardConcurrency($memberId);
            if (!$concurrencyCheck['available']) {
                return ['success' => false, 'error' => $concurrencyCheck['error']];
            }

            // Find an available shard
            $shard = ShardRouter::findAvailableShard($memberId, ['git', 'filesystem']);
            if (!$shard) {
                return ['success' => false, 'error' => 'No available shards. Please try again later.'];
            }

            // Get repository details
            $repoBean = R::load('repoconnections', $repoId);

            if (!$repoBean || !$repoBean->id) {
                return ['success' => false, 'error' => 'Repository not found'];
            }

            $repoToken = EncryptionService::decrypt($repoBean->access_token);

            // Get issue details from Jira
            $jiraClient = new JiraClient($memberId, $cloudId);
            $issue = $jiraClient->getIssue($issueKey);

            $summary = $issue['fields']['summary'] ?? '';
            $description = JiraClient::extractTextFromAdf($issue['fields']['description'] ?? null);
            $issueType = $issue['fields']['issuetype']['name'] ?? 'Task';
            $priority = $issue['fields']['priority']['name'] ?? 'Medium';

            // Get comments
            $comments = $issue['fields']['comment']['comments'] ?? [];
            $commentText = '';
            foreach (array_slice($comments, -10) as $comment) {
                $commentText .= JiraClient::extractTextFromAdf($comment['body']) . "\n\n";
            }

            // Get attachment info
            $attachments = $issue['fields']['attachment'] ?? [];
            $attachmentInfo = '';
            if (!empty($attachments)) {
                $attachmentInfo = "## Attachments\n";
                foreach ($attachments as $att) {
                    $attachmentInfo .= "- {$att['filename']} ({$att['mimeType']}, {$att['size']} bytes)\n";
                    $attachmentInfo .= "  Download: {$att['content']}\n";
                }
            }

            // Extract URLs from description and comments
            $urlsToCheck = $this->extractUrls($description . ' ' . $commentText);

            // Get Jira credentials
            $jiraCreds = ShardRouter::getMemberMcpCredentials($memberId);
            $jiraHost = $jiraCreds['jira_host'] ?? '';
            $jiraEmail = $jiraCreds['jira_email'] ?? '';
            $jiraToken = $jiraCreds['jira_api_token'] ?? '';
            $jiraSiteUrl = $jiraCreds['jira_site_url'] ?? '';

            // Get OAuth access token for Jira API calls (needed for posting comments)
            $jiraOAuthToken = AtlassianAuth::getValidToken($memberId, $cloudId);

            // Create job
            $jobId = AIDevStatusService::createJob($memberId, $boardId, $issueKey, $repoId, $cloudId);

            // Check for existing Shopify theme to reuse
            $existingThemeId = AIDevStatusService::getShopifyThemeForIssue($memberId, $issueKey);

            // Get Shopify settings
            $shopifySettings = $this->getShopifySettingsForPayload($memberId);

            // Build payload for shard
            $payload = [
                'anthropic_api_key' => $apiKey,
                'anthropic_model' => $model,
                'job_id' => $jobId,
                'issue_key' => $issueKey,
                'issue_data' => [
                    'summary' => $summary,
                    'description' => $description,
                    'type' => $issueType,
                    'priority' => $priority,
                    'comments' => $commentText,
                    'attachment_info' => $attachmentInfo,
                    'urls_to_check' => $urlsToCheck
                ],
                'repo_config' => [
                    'repo_owner' => $repoBean->repo_owner,
                    'repo_name' => $repoBean->repo_name,
                    'default_branch' => $repoBean->default_branch ?? 'main',
                    'clone_url' => $repoBean->clone_url
                ],
                'jira_host' => $jiraHost,
                'jira_email' => $jiraEmail,
                'jira_api_token' => $jiraToken,
                'jira_oauth_token' => $jiraOAuthToken,
                'jira_site_url' => $jiraSiteUrl,
                'cloud_id' => $cloudId,
                'github_token' => $repoToken,
                'callback_url' => Flight::get('baseurl') . '/webhook/aidev',
                'callback_api_key' => Flight::get('cron.api_key'),
                'action' => 'implement',
                // Branch affinity: reuse existing branch if available
                'existing_branch' => $existingBranch,
                // Shopify integration
                'shopify' => $shopifySettings,
                'existing_shopify_theme_id' => $existingThemeId,
                // Agent orchestrator pattern
                'use_orchestrator' => $useOrchestrator
            ];

            // Call shard endpoint
            $shardPort = $shard['port'];
            $shardProtocol = ($shardPort == 443 || !empty($shard['ssl'])) ? 'https' : 'http';
            $shardUrl = "{$shardProtocol}://{$shard['host']}:{$shardPort}/analysis/shardaidev";

            $client = new \GuzzleHttp\Client([
                'timeout' => 30,
                'verify' => false
            ]);

            $response = $client->post($shardUrl, [
                'json' => $payload
            ]);

            if ($response->getStatusCode() !== 202) {
                throw new \Exception('Shard returned non-202 status: ' . $response->getStatusCode());
            }

            // Update job status to running
            AIDevStatusService::updateStatus(
                $memberId,
                $jobId,
                'Running on shard',
                5,
                AIDevStatusService::STATUS_RUNNING
            );

            $this->logger->info('AI Developer shard job started', [
                'member_id' => $memberId,
                'job_id' => $jobId,
                'shard_id' => $shard['id'],
                'shard_name' => $shard['name'] ?? $shard['host'],
                'issue_key' => $issueKey,
                'trigger' => 'service'
            ]);

            // Add working label and transition status (async, don't block on failure)
            $this->onJobStarted($memberId, $cloudId, $issueKey, $boardId);

            return [
                'success' => true,
                'job_id' => $jobId,
                'shard' => $shard['name'] ?? $shard['host'],
                'message' => 'Job started on shard',
                'board_id' => $boardId
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to trigger AI dev job', [
                'member_id' => $memberId,
                'issue_key' => $issueKey,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check shard concurrency limits
     *
     * @param int $memberId Member ID
     * @return array ['available' => bool, 'error' => string|null, 'running_count' => int]
     */
    public function checkShardConcurrency(int $memberId): array {
        // Get member's max concurrent jobs setting (default: 3)
        $maxConcurrent = 3; // Default

        $setting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['max_concurrent_aidev_jobs', $memberId]);
        if ($setting && $setting->setting_value) {
            $maxConcurrent = (int)$setting->setting_value;
        }

        // Count running jobs for this member
        $runningJobs = AIDevStatusService::getRunningJobsCount($memberId);

        if ($runningJobs >= $maxConcurrent) {
            return [
                'available' => false,
                'error' => "Concurrency limit reached: {$runningJobs}/{$maxConcurrent} jobs running",
                'running_count' => $runningJobs,
                'max_concurrent' => $maxConcurrent
            ];
        }

        return [
            'available' => true,
            'running_count' => $runningJobs,
            'max_concurrent' => $maxConcurrent
        ];
    }

    /**
     * Post a summary comment to Jira when PR is created
     *
     * @param int $memberId Member ID
     * @param string $cloudId Atlassian cloud ID
     * @param string $issueKey Jira issue key
     * @param array $result Job result with pr_url, pr_number, files_changed, summary
     * @return bool Success
     */
    public function postPRSummaryToJira(int $memberId, string $cloudId, string $issueKey, array $result): bool {
        try {
            $jiraClient = new JiraClient($memberId, $cloudId);

            $prUrl = $result['pr_url'] ?? '';
            $prNumber = $result['pr_number'] ?? '';
            $branchName = $result['branch_name'] ?? '';
            $filesChanged = $result['files_changed'] ?? [];
            $summary = $result['summary'] ?? 'Implementation complete';

            // Build proper ADF document with links
            $adfContent = [];

            // Header
            $adfContent[] = [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'MyCTOBot AI Developer - Pull Request Created', 'marks' => [['type' => 'strong']]]
                ]
            ];

            // Intro
            $adfContent[] = [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => "I've created a pull request to address this ticket:"]
                ]
            ];

            // PR link as bullet list
            $listItems = [];

            // PR item with clickable link
            $prDisplayText = $prNumber ? "PR #{$prNumber}" : $prUrl;
            $listItems[] = [
                'type' => 'listItem',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => 'PR: ', 'marks' => [['type' => 'strong']]],
                            ['type' => 'text', 'text' => $prDisplayText, 'marks' => [['type' => 'link', 'attrs' => ['href' => $prUrl]]]]
                        ]
                    ]
                ]
            ];

            // Branch item
            if ($branchName) {
                $listItems[] = [
                    'type' => 'listItem',
                    'content' => [
                        [
                            'type' => 'paragraph',
                            'content' => [
                                ['type' => 'text', 'text' => 'Branch: ', 'marks' => [['type' => 'strong']]],
                                ['type' => 'text', 'text' => $branchName, 'marks' => [['type' => 'code']]]
                            ]
                        ]
                    ]
                ];
            }

            $adfContent[] = [
                'type' => 'bulletList',
                'content' => $listItems
            ];

            // Summary
            if ($summary && $summary !== 'Implementation complete') {
                $adfContent[] = [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Summary:', 'marks' => [['type' => 'strong']]],
                        ['type' => 'hardBreak'],
                        ['type' => 'text', 'text' => $summary]
                    ]
                ];
            }

            // Files changed
            if (!empty($filesChanged)) {
                $adfContent[] = [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Files Changed:', 'marks' => [['type' => 'strong']]]
                    ]
                ];

                $fileItems = [];
                foreach (array_slice($filesChanged, 0, 10) as $file) {
                    $fileItems[] = [
                        'type' => 'listItem',
                        'content' => [
                            [
                                'type' => 'paragraph',
                                'content' => [
                                    ['type' => 'text', 'text' => $file, 'marks' => [['type' => 'code']]]
                                ]
                            ]
                        ]
                    ];
                }
                if (count($filesChanged) > 10) {
                    $fileItems[] = [
                        'type' => 'listItem',
                        'content' => [
                            [
                                'type' => 'paragraph',
                                'content' => [
                                    ['type' => 'text', 'text' => '...and ' . (count($filesChanged) - 10) . ' more files']
                                ]
                            ]
                        ]
                    ];
                }

                $adfContent[] = [
                    'type' => 'bulletList',
                    'content' => $fileItems
                ];
            }

            // Footer
            $adfContent[] = [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Please review the PR and merge when ready.']
                ]
            ];

            $adfBody = [
                'type' => 'doc',
                'version' => 1,
                'content' => $adfContent
            ];

            $jiraClient->addCommentAdf($issueKey, $adfBody);

            $this->logger->info('Posted PR summary to Jira', [
                'member_id' => $memberId,
                'issue_key' => $issueKey,
                'pr_url' => $prUrl
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Failed to post PR summary to Jira', [
                'member_id' => $memberId,
                'issue_key' => $issueKey,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if an update is from the bot itself (to avoid loops)
     *
     * @param array $webhookData Jira webhook payload
     * @param int $memberId Member ID to check against
     * @return bool True if this is a bot update that should be ignored
     */
    public function isBotUpdate(array $webhookData, int $memberId): bool {
        // Check if the update was made by the bot's account
        $user = $webhookData['user'] ?? [];
        $accountId = $user['accountId'] ?? '';

        // Get the bot's Jira account ID for this member
        $setting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['jira_bot_account_id', $memberId]);
        if ($setting && $setting->setting_value && $accountId === $setting->setting_value) {
            return true;
        }

        // Check for bot markers in comments
        if (isset($webhookData['comment'])) {
            $commentBody = $webhookData['comment']['body'] ?? '';
            // If comment contains our bot marker, it's our own update
            if (is_string($commentBody) && strpos($commentBody, 'MyCTOBot AI Developer') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract URLs from text
     */
    private function extractUrls(string $text): array {
        $urls = [];
        if (preg_match_all('#https?://[^\s<>"\')\]]+#i', $text, $matches)) {
            $urls = array_unique($matches[0]);
            // Filter out Jira/Atlassian internal URLs
            $urls = array_filter($urls, function($url) {
                return !preg_match('#atlassian\.net|atlassian\.com#i', $url);
            });
        }
        return array_values($urls);
    }

    // ========================================
    // Local Runner Support
    // ========================================

    /**
     * Spawn a local AI Developer runner in a tmux session
     * Uses the user's Claude Code subscription instead of API credits
     *
     * @param int $memberId Member ID
     * @param string $issueKey Issue key
     * @param int $boardId Board ID
     * @param string $cloudId Cloud ID
     * @param int|null $repoId Repository connection ID
     * @param string|null $tenant Tenant slug for multi-tenancy
     * @param bool $useOrchestrator Use orchestrator mode
     * @return array Result with 'success', 'job_id', 'session_name' keys
     */
    private function spawnLocalRunner(int $memberId, string $issueKey, int $boardId, string $cloudId, ?int $repoId = null, ?string $tenant = null, bool $useOrchestrator = true): array {
        $tmux = new TmuxService($memberId, $issueKey);

        // Check if session already exists
        if ($tmux->exists()) {
            $this->logger->info('Local tmux session already exists', [
                'issue_key' => $issueKey,
                'member_id' => $memberId,
                'session' => $tmux->getSessionName()
            ]);
            return [
                'success' => false,
                'error' => 'Session already exists for this issue',
                'session_name' => $tmux->getSessionName()
            ];
        }

        // Create job record for tracking (include repoId)
        $jobId = AIDevStatusService::createJob($memberId, $boardId, $issueKey, $repoId, $cloudId);

        // Update status to running
        AIDevStatusService::updateStatus(
            $memberId,
            $jobId,
            'Running locally (Claude Code)',
            5,
            AIDevStatusService::STATUS_RUNNING
        );

        // Spawn the tmux session
        $scriptPath = dirname(__DIR__) . '/scripts/local-aidev-full.php';

        if (!file_exists($scriptPath)) {
            $this->logger->error('Local runner script not found', ['path' => $scriptPath]);
            return ['success' => false, 'error' => 'Local runner script not found'];
        }

        if ($tmux->spawnWithScript($scriptPath, $useOrchestrator, $jobId, $repoId, $tenant)) {
            $this->logger->info('Local AI Developer spawned in tmux', [
                'issue_key' => $issueKey,
                'session' => $tmux->getSessionName(),
                'member_id' => $memberId,
                'job_id' => $jobId
            ]);

            return [
                'success' => true,
                'job_id' => $jobId,
                'session_name' => $tmux->getSessionName(),
                'message' => 'Job started locally (Claude Code)',
                'board_id' => $boardId,
                'local' => true
            ];
        } else {
            $this->logger->error('Failed to spawn local AI Developer', [
                'issue_key' => $issueKey,
                'member_id' => $memberId
            ]);
            return ['success' => false, 'error' => 'Failed to spawn tmux session'];
        }
    }

    // ========================================
    // Status Transitions & Label Management
    // ========================================

    const WORKING_LABEL = 'myctobot-working';

    /**
     * Get board's AI Developer status settings
     *
     * @param int $memberId Member ID
     * @param int $boardId Board ID
     * @return array Status settings (working, pr_created, clarification, failed)
     */
    public function getBoardStatusSettings(int $memberId, int $boardId): array {
        $board = R::load('jiraboards', $boardId);
        if (!$board || !$board->id) {
            return [];
        }

        return [
            'aidev_status_working' => $board->aidev_status_working,
            'aidev_status_pr_created' => $board->aidev_status_pr_created,
            'aidev_status_clarification' => $board->aidev_status_clarification,
            'aidev_status_failed' => $board->aidev_status_failed
        ];
    }

    /**
     * Handle job started - add working label and transition status
     *
     * @param int $memberId Member ID
     * @param string $cloudId Cloud ID
     * @param string $issueKey Issue key
     * @param int $boardId Board ID
     */
    public function onJobStarted(int $memberId, string $cloudId, string $issueKey, int $boardId): void {
        $this->logger->info('onJobStarted called', [
            'member_id' => $memberId,
            'cloud_id' => $cloudId,
            'issue_key' => $issueKey,
            'board_id' => $boardId
        ]);

        try {
            $jiraClient = new JiraClient($memberId, $cloudId);

            // Add working label
            try {
                $jiraClient->addLabel($issueKey, self::WORKING_LABEL);
                $this->logger->info('Added working label', ['issue_key' => $issueKey]);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to add working label', [
                    'issue_key' => $issueKey,
                    'error' => $e->getMessage()
                ]);
            }

            // Transition to "working" status if configured
            $settings = $this->getBoardStatusSettings($memberId, $boardId);
            $workingStatus = $settings['aidev_status_working'] ?? null;

            $this->logger->info('Board status settings', [
                'issue_key' => $issueKey,
                'board_id' => $boardId,
                'working_status' => $workingStatus,
                'all_settings' => $settings
            ]);

            if ($workingStatus) {
                $result = $jiraClient->transitionToStatus($issueKey, $workingStatus);
                if ($result['success']) {
                    $this->logger->info('Transitioned issue to working status', [
                        'issue_key' => $issueKey,
                        'from' => $result['from_status'],
                        'to' => $result['to_status']
                    ]);
                } else {
                    $this->logger->warning('Could not transition to working status', [
                        'issue_key' => $issueKey,
                        'target_status' => $workingStatus,
                        'message' => $result['message'],
                        'available' => $result['available_statuses'] ?? []
                    ]);
                }
            } else {
                $this->logger->info('No working status configured for board', [
                    'issue_key' => $issueKey,
                    'board_id' => $boardId
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Error in onJobStarted', [
                'issue_key' => $issueKey,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Handle job completed with PR - remove working label and transition status
     *
     * @param int $memberId Member ID
     * @param string $cloudId Cloud ID
     * @param string $issueKey Issue key
     * @param int $boardId Board ID
     */
    public function onJobCompleted(int $memberId, string $cloudId, string $issueKey, int $boardId): void {
        try {
            $jiraClient = new JiraClient($memberId, $cloudId);

            // Remove working label
            try {
                $jiraClient->removeLabel($issueKey, self::WORKING_LABEL);
                $this->logger->debug('Removed working label', ['issue_key' => $issueKey]);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to remove working label', [
                    'issue_key' => $issueKey,
                    'error' => $e->getMessage()
                ]);
            }

            // Transition to "PR created" status if configured
            $settings = $this->getBoardStatusSettings($memberId, $boardId);
            $prCreatedStatus = $settings['aidev_status_pr_created'] ?? null;

            if ($prCreatedStatus) {
                $result = $jiraClient->transitionToStatus($issueKey, $prCreatedStatus);
                if ($result['success']) {
                    $this->logger->info('Transitioned issue to PR created status', [
                        'issue_key' => $issueKey,
                        'from' => $result['from_status'],
                        'to' => $result['to_status']
                    ]);
                } else {
                    // Post comment if transition failed
                    $this->postTransitionFailureComment($jiraClient, $issueKey, $prCreatedStatus, $result);
                }
            }

        } catch (\Exception $e) {
            $this->logger->error('Error in onJobCompleted', [
                'issue_key' => $issueKey,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle job needs clarification - transition status
     *
     * @param int $memberId Member ID
     * @param string $cloudId Cloud ID
     * @param string $issueKey Issue key
     * @param int $boardId Board ID
     */
    public function onJobNeedsClarification(int $memberId, string $cloudId, string $issueKey, int $boardId): void {
        try {
            $jiraClient = new JiraClient($memberId, $cloudId);

            // Transition to "clarification" status if configured
            $settings = $this->getBoardStatusSettings($memberId, $boardId);
            $clarificationStatus = $settings['aidev_status_clarification'] ?? null;

            if ($clarificationStatus) {
                $result = $jiraClient->transitionToStatus($issueKey, $clarificationStatus);
                if ($result['success']) {
                    $this->logger->info('Transitioned issue to clarification status', [
                        'issue_key' => $issueKey,
                        'from' => $result['from_status'],
                        'to' => $result['to_status']
                    ]);
                } else {
                    $this->logger->warning('Could not transition to clarification status', [
                        'issue_key' => $issueKey,
                        'message' => $result['message']
                    ]);
                }
            }

        } catch (\Exception $e) {
            $this->logger->error('Error in onJobNeedsClarification', [
                'issue_key' => $issueKey,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle job failed - remove working label and optionally transition status
     *
     * @param int $memberId Member ID
     * @param string $cloudId Cloud ID
     * @param string $issueKey Issue key
     * @param int $boardId Board ID
     * @param string $errorMessage Error message
     */
    public function onJobFailed(int $memberId, string $cloudId, string $issueKey, int $boardId, string $errorMessage): void {
        try {
            $jiraClient = new JiraClient($memberId, $cloudId);

            // Remove working label
            try {
                $jiraClient->removeLabel($issueKey, self::WORKING_LABEL);
                $this->logger->debug('Removed working label after failure', ['issue_key' => $issueKey]);
            } catch (\Exception $e) {
                // Ignore - label might not exist
            }

            // Transition to "failed" status if configured
            $settings = $this->getBoardStatusSettings($memberId, $boardId);
            $failedStatus = $settings['aidev_status_failed'] ?? null;

            if ($failedStatus) {
                $result = $jiraClient->transitionToStatus($issueKey, $failedStatus);
                $this->logger->info('Transitioned issue after failure', [
                    'issue_key' => $issueKey,
                    'success' => $result['success']
                ]);
            }

            // Post failure comment
            $this->postFailureComment($jiraClient, $issueKey, $errorMessage);

        } catch (\Exception $e) {
            $this->logger->error('Error in onJobFailed', [
                'issue_key' => $issueKey,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Post a comment when status transition fails
     */
    private function postTransitionFailureComment(JiraClient $jiraClient, string $issueKey, string $targetStatus, array $result): void {
        try {
            $availableStr = implode(', ', $result['available_statuses'] ?? []);
            $comment = "MyCTOBot: I couldn't move this ticket to \"{$targetStatus}\" status. " .
                       "Available transitions from \"{$result['from_status']}\": {$availableStr}";
            $jiraClient->addComment($issueKey, $comment);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to post transition failure comment', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Post a comment when job fails
     */
    private function postFailureComment(JiraClient $jiraClient, string $issueKey, string $errorMessage): void {
        try {
            $comment = "**MyCTOBot AI Developer - Job Failed**\n\n" .
                       "I encountered an error while working on this ticket:\n\n" .
                       $errorMessage . "\n\n" .
                       "Please review and try again, or contact support if the issue persists.";
            $jiraClient->addComment($issueKey, $comment);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to post failure comment', ['error' => $e->getMessage()]);
        }
    }

    // ========================================
    // Shopify Preview Integration
    // ========================================

    /**
     * Post Shopify preview URL to Jira ticket
     *
     * @param int $memberId Member ID
     * @param string $cloudId Atlassian cloud ID
     * @param string $issueKey Jira issue key
     * @param string $previewUrl Shopify preview URL
     * @param array|null $playwrightResults Playwright test results
     * @return bool Success
     */
    public function postPreviewToJira(
        int $memberId,
        string $cloudId,
        string $issueKey,
        string $previewUrl,
        ?array $playwrightResults = null
    ): bool {
        try {
            $jiraClient = new JiraClient($memberId, $cloudId);

            // Build ADF document for preview comment
            $adfContent = [];

            // Header
            $adfContent[] = [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'MyCTOBot AI Developer - Shopify Preview Ready', 'marks' => [['type' => 'strong']]]
                ]
            ];

            // Preview link
            $adfContent[] = [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Preview your changes: '],
                    ['type' => 'text', 'text' => 'View Preview', 'marks' => [['type' => 'link', 'attrs' => ['href' => $previewUrl]]]]
                ]
            ];

            // Playwright results if available
            if ($playwrightResults !== null) {
                $passed = $playwrightResults['passed'] ?? 0;
                $failed = $playwrightResults['failed'] ?? 0;
                $total = $passed + $failed;
                $status = $failed > 0 ? 'Some tests failed' : 'All tests passed';

                $adfContent[] = [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Playwright Tests: ', 'marks' => [['type' => 'strong']]],
                        ['type' => 'text', 'text' => "{$status} ({$passed}/{$total})"]
                    ]
                ];

                // Add failed test details if any
                if ($failed > 0 && !empty($playwrightResults['failures'])) {
                    $adfContent[] = [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => 'Failed tests:', 'marks' => [['type' => 'strong']]]
                        ]
                    ];

                    $failItems = [];
                    foreach (array_slice($playwrightResults['failures'], 0, 5) as $failure) {
                        $failItems[] = [
                            'type' => 'listItem',
                            'content' => [
                                [
                                    'type' => 'paragraph',
                                    'content' => [
                                        ['type' => 'text', 'text' => $failure['name'] ?? 'Unknown test', 'marks' => [['type' => 'code']]]
                                    ]
                                ]
                            ]
                        ];
                    }
                    $adfContent[] = [
                        'type' => 'bulletList',
                        'content' => $failItems
                    ];
                }
            }

            // Note about preview
            $adfContent[] = [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Note: ', 'marks' => [['type' => 'em']]],
                    ['type' => 'text', 'text' => 'This is an unpublished preview. Changes will not affect your live store.', 'marks' => [['type' => 'em']]]
                ]
            ];

            $adfBody = [
                'type' => 'doc',
                'version' => 1,
                'content' => $adfContent
            ];

            $jiraClient->addCommentAdf($issueKey, $adfBody);

            $this->logger->info('Posted Shopify preview to Jira', [
                'member_id' => $memberId,
                'issue_key' => $issueKey,
                'preview_url' => $previewUrl
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Failed to post Shopify preview to Jira', [
                'member_id' => $memberId,
                'issue_key' => $issueKey,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    // ========================================
    // Cleanup on Ticket Done
    // ========================================

    /**
     * Handle ticket transition to "Done" - cleanup resources
     *
     * @param int $memberId Member ID
     * @param string $issueKey Jira issue key
     * @return array Cleanup results
     */
    public function cleanupOnTicketDone(int $memberId, string $issueKey): array {
        $results = [
            'shopify_themes_deleted' => 0,
            'branches_to_cleanup' => [],
            'jobs_marked_complete' => 0,
            'errors' => []
        ];

        try {
            // Find all jobs for this issue
            $jobs = AIDevStatusService::findAllJobsByIssueKey($memberId, $issueKey);

            if (empty($jobs)) {
                return $results;
            }

            // Get Shopify client for cleanup
            $shopifyClient = new ShopifyClient($memberId);

            // Cleanup Shopify development themes
            if ($shopifyClient->isConnected()) {
                try {
                    $results['shopify_themes_deleted'] = $shopifyClient->cleanupDevThemes($issueKey);
                    $this->logger->info('Cleaned up Shopify dev themes', [
                        'issue_key' => $issueKey,
                        'themes_deleted' => $results['shopify_themes_deleted']
                    ]);
                } catch (\Exception $e) {
                    $results['errors'][] = 'Shopify cleanup: ' . $e->getMessage();
                }
            }

            // Mark jobs as complete and collect branches for cleanup
            foreach ($jobs as $job) {
                $jobId = $job['job_id'];
                $status = $job['status'];

                // Collect branch names for potential cleanup
                if (!empty($job['branch_name'])) {
                    $results['branches_to_cleanup'][] = $job['branch_name'];
                }

                // Mark active jobs as complete
                if (in_array($status, [
                    AIDevStatusService::STATUS_PR_CREATED,
                    AIDevStatusService::STATUS_PREVIEW_READY,
                    AIDevStatusService::STATUS_RUNNING,
                    AIDevStatusService::STATUS_WAITING_CLARIFICATION
                ])) {
                    AIDevStatusService::complete(
                        $memberId,
                        $jobId,
                        $job['pr_url'] ?? '',
                        $job['pr_number'] ?? null,
                        $job['branch_name'] ?? null
                    );
                    $results['jobs_marked_complete']++;
                }
            }

            $this->logger->info('Ticket cleanup completed', [
                'member_id' => $memberId,
                'issue_key' => $issueKey,
                'results' => $results
            ]);

        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
            $this->logger->error('Error during ticket cleanup', [
                'member_id' => $memberId,
                'issue_key' => $issueKey,
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }

    /**
     * Check if an issue should trigger cleanup (transition to Done)
     *
     * @param array $webhookData Jira webhook payload
     * @return bool True if this is a "Done" transition
     */
    public function isDoneTransition(array $webhookData): bool {
        $changelog = $webhookData['changelog'] ?? [];
        $items = $changelog['items'] ?? [];

        foreach ($items as $item) {
            if ($item['field'] === 'status') {
                $toStatus = strtolower($item['toString'] ?? '');
                // Common "done" status names
                if (in_array($toStatus, ['done', 'closed', 'resolved', 'complete', 'completed'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get Shopify settings for payload (storefront password, etc.)
     *
     * @param int $memberId Member ID
     * @return array Shopify settings
     */
    public function getShopifySettingsForPayload(int $memberId): array {
        $settings = [
            'enabled' => false,
            'member_id' => $memberId,
            'shop_domain' => null,
            'access_token' => null,
            'storefront_password' => null,
            'verify_with_playwright' => false
        ];

        try {
            $shopifyClient = new ShopifyClient($memberId);

            if ($shopifyClient->isConnected()) {
                $settings['enabled'] = true;
                $settings['shop_domain'] = $shopifyClient->getShop();
                $settings['access_token'] = $shopifyClient->getAccessToken();

                // Get additional settings from user database
                // Storefront password
                $passwordSetting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['shopify_storefront_password', $memberId]);
                if ($passwordSetting && $passwordSetting->setting_value) {
                    $settings['storefront_password'] = EncryptionService::decrypt($passwordSetting->setting_value);
                }

                // Playwright verification setting
                $verifySetting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['shopify_verify_playwright', $memberId]);
                $verifyEnabled = $verifySetting ? $verifySetting->setting_value : null;
                $settings['verify_with_playwright'] = ($verifyEnabled === '1' || $verifyEnabled === 'true');
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not get Shopify settings', [
                'member_id' => $memberId,
                'error' => $e->getMessage()
            ]);
        }

        return $settings;
    }
}
