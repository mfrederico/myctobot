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
require_once __DIR__ . '/CeoDirectiveLogger.php';
require_once __DIR__ . '/DeliveryConfirmationService.php';
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
            // Validate member exists
            $member = R::load('member', $memberId);
            if (!$member || !$member->id) {
                return ['success' => false, 'error' => 'Member not found'];
            }
            // All features now available to all tiers

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

            // Auto-detect repo ID if not provided (workspace-level - shared by all members)
            if (!$repoId) {
                $repo = R::findOne('repoconnections', 'enabled = ?', [1]);
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

            // Branch affinity: find existing branch from multiple sources
            // 1. First check our database for branches from previous jobs
            // 2. If not found, check Jira's development panel for linked branches
            $existingBranch = AIDevStatusService::findBranchForIssueKey($memberId, $issueKey);
            $branchSource = 'database';

            if (!$existingBranch) {
                // Try to get branch from Jira's development info
                try {
                    $jiraClient = new JiraClient($memberId, $cloudId);
                    $existingBranch = $jiraClient->getBranchForIssue($issueKey);
                    $branchSource = 'jira_dev_panel';
                } catch (\Exception $e) {
                    Flight::get('log')->debug('Could not get branch from Jira dev panel', [
                        'issue_key' => $issueKey,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            if ($existingBranch) {
                Flight::get('log')->info('Branch affinity: reusing existing branch', [
                    'issue_key' => $issueKey,
                    'branch' => $existingBranch,
                    'source' => $branchSource
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
                // Check concurrency limits for local runner
                $concurrencyCheck = $this->checkConcurrency($memberId);
                if (!$concurrencyCheck['available']) {
                    return ['success' => false, 'error' => $concurrencyCheck['error']];
                }

                $this->logger->info('Using local runner for AI Developer job', [
                    'member_id' => $memberId,
                    'issue_key' => $issueKey,
                    'board_key_id' => $boardKeyId,
                    'source' => 'local_runner',
                    'running_jobs' => $concurrencyCheck['running_count'],
                    'max_concurrent' => $concurrencyCheck['max_concurrent']
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

            // Get Shopify settings (uses repo-linked store if available)
            $shopifySettings = $this->getShopifySettingsForPayload($memberId, $repoId);

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
     * Check concurrency limits for AI dev jobs
     *
     * @param int $memberId Member ID
     * @return array ['available' => bool, 'error' => string|null, 'running_count' => int]
     */
    public function checkShardConcurrency(int $memberId): array {
        return $this->checkConcurrency($memberId);
    }

    /**
     * Check concurrency limits for AI dev jobs (works for both local and shard)
     *
     * Priority: INI config > enterprisesettings > default (3)
     *
     * @param int $memberId Member ID
     * @return array ['available' => bool, 'error' => string|null, 'running_count' => int]
     */
    public function checkConcurrency(int $memberId): array {
        // Default limit
        $maxConcurrent = 3;

        // 1. Check INI config first (tenant-level setting)
        $iniMaxConcurrent = Flight::get('aidev.max_concurrent_jobs');
        if ($iniMaxConcurrent !== null && $iniMaxConcurrent !== '') {
            $maxConcurrent = (int)$iniMaxConcurrent;
        } else {
            // 2. Fall back to enterprisesettings (member-level setting)
            $setting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['max_concurrent_aidev_jobs', $memberId]);
            if ($setting && $setting->setting_value) {
                $maxConcurrent = (int)$setting->setting_value;
            }
        }

        // Count running jobs for this member
        $runningJobs = AIDevStatusService::getRunningJobsCount($memberId);

        if ($runningJobs >= $maxConcurrent) {
            return [
                'available' => false,
                'error' => "Concurrency limit reached: {$runningJobs}/{$maxConcurrent} jobs running. Please wait for current jobs to complete.",
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

            // Log the response for CEO directive audit trail
            $this->logCeoDirectiveResponse(
                $memberId,
                $issueKey,
                "PR #{$prNumber} created: {$prUrl}\nBranch: {$branchName}\nSummary: {$summary}",
                CeoDirectiveLogger::DELIVERY_SUCCESS
            );

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Failed to post PR summary to Jira', [
                'member_id' => $memberId,
                'issue_key' => $issueKey,
                'error' => $e->getMessage()
            ]);

            // Log the failed response attempt
            $this->logCeoDirectiveResponse(
                $memberId,
                $issueKey,
                'Failed to post PR summary: ' . $e->getMessage(),
                CeoDirectiveLogger::DELIVERY_FAILED
            );

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
    // GitHub Issues Support
    // ========================================

    /**
     * Trigger an AI Developer job for a GitHub issue
     *
     * @param int $memberId Member ID
     * @param string $issueKey GitHub issue key (e.g., "owner/repo#123")
     * @param int $repoId Repository connection ID
     * @param string|null $tenant Tenant slug for multi-tenancy
     * @return array Result with 'success', 'job_id', 'error' keys
     */
    public function triggerGitHubJob(int $memberId, string $issueKey, int $repoId, ?string $tenant = null): array {
        try {
            // Validate member exists
            $member = R::load('member', $memberId);
            if (!$member || !$member->id) {
                return ['success' => false, 'error' => 'Member not found'];
            }

            // Parse issue key: owner/repo#123
            if (!preg_match('/^([^\/]+)\/([^#]+)#(\d+)$/', $issueKey, $matches)) {
                return ['success' => false, 'error' => 'Invalid GitHub issue key format'];
            }
            $owner = $matches[1];
            $repoName = $matches[2];
            $issueNumber = (int)$matches[3];

            // Use TmuxService to check for existing session
            $tmux = new TmuxService($memberId, $issueKey);
            $tmuxSessionRunning = $tmux->exists() && $tmux->isClaudeRunning();

            // Check for existing active job
            $existingJob = AIDevStatusService::findJobByIssueKey($memberId, $issueKey);
            if ($existingJob) {
                if (in_array($existingJob['status'], ['running', 'pending'])) {
                    if ($tmuxSessionRunning) {
                        return ['success' => false, 'error' => 'Job already running for this issue', 'job_id' => $existingJob['job_id']];
                    } else {
                        // Stale status file - auto cleanup
                        $this->logger->info('Auto-cleanup stale GitHub job status', [
                            'issue_key' => $issueKey,
                            'job_id' => $existingJob['job_id']
                        ]);
                        AIDevStatusService::fail($memberId, $existingJob['job_id'], 'Session ended unexpectedly');
                    }
                }

                // Cooldown check
                $lastUpdated = strtotime($existingJob['updated_at'] ?? $existingJob['created_at'] ?? '');
                if ($lastUpdated && (time() - $lastUpdated) < 120) {
                    return ['success' => false, 'error' => 'Recent job exists, cooldown active', 'job_id' => $existingJob['job_id']];
                }
            } elseif ($tmuxSessionRunning) {
                return ['success' => false, 'error' => 'Session exists for this issue (no status file)'];
            }

            // Branch affinity
            $existingBranch = AIDevStatusService::findBranchForIssueKey($memberId, $issueKey);
            if ($existingBranch) {
                $this->logger->info('Branch affinity: reusing existing branch', [
                    'issue_key' => $issueKey,
                    'branch' => $existingBranch
                ]);
            }

            // Check concurrency limits
            $concurrencyCheck = $this->checkConcurrency($memberId);
            if (!$concurrencyCheck['available']) {
                return ['success' => false, 'error' => $concurrencyCheck['error']];
            }

            // Spawn local runner for GitHub (always local for now)
            return $this->spawnGitHubLocalRunner($memberId, $issueKey, $repoId, $tenant);

        } catch (\Exception $e) {
            $this->logger->error('Failed to trigger GitHub AI Dev job', [
                'error' => $e->getMessage(),
                'member_id' => $memberId,
                'issue_key' => $issueKey
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Spawn a local AI Developer runner for GitHub issue
     */
    private function spawnGitHubLocalRunner(int $memberId, string $issueKey, int $repoId, ?string $tenant = null): array {
        $tmux = new TmuxService($memberId, $issueKey);

        if ($tmux->exists()) {
            return [
                'success' => false,
                'error' => 'Session already exists for this issue',
                'session_name' => $tmux->getSessionName()
            ];
        }

        // Create job record (use 0 for boardId since GitHub doesn't have boards)
        $jobId = AIDevStatusService::createJob($memberId, 0, $issueKey, $repoId, 'github');

        // Update status to running
        AIDevStatusService::updateStatus(
            $memberId,
            $jobId,
            'Running locally (Claude Code)',
            5,
            AIDevStatusService::STATUS_RUNNING
        );

        // Spawn the tmux session with GitHub-specific script
        $scriptPath = dirname(__DIR__) . '/scripts/local-aidev-github.php';

        // Fall back to generic script if GitHub-specific doesn't exist
        if (!file_exists($scriptPath)) {
            $scriptPath = dirname(__DIR__) . '/scripts/local-aidev-full.php';
        }

        if (!file_exists($scriptPath)) {
            $this->logger->error('Local runner script not found', ['path' => $scriptPath]);
            AIDevStatusService::fail($memberId, $jobId, 'Local runner script not found');
            return ['success' => false, 'error' => 'Local runner script not found'];
        }

        // GitHub jobs use the standard spawner but with provider=github
        if ($tmux->spawnWithScript($scriptPath, true, $jobId, $repoId, $tenant, 'github')) {
            $this->logger->info('Local GitHub AI Developer spawned in tmux', [
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
                'local' => true,
                'provider' => 'github'
            ];
        } else {
            $this->logger->error('Failed to spawn local GitHub AI Developer', [
                'issue_key' => $issueKey,
                'member_id' => $memberId
            ]);
            AIDevStatusService::fail($memberId, $jobId, 'Failed to spawn tmux session');
            return ['success' => false, 'error' => 'Failed to spawn tmux session'];
        }
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
     * Remove job-related labels from a Jira issue (myctobot-working, ai-dev, ai-dev-{id})
     */
    private function removeJobLabels(JiraClient $jiraClient, string $issueKey): void {
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

        // Remove ai-dev labels (ai-dev or ai-dev-{id})
        try {
            $issue = $jiraClient->getIssue($issueKey);
            $labels = $issue['fields']['labels'] ?? [];
            foreach ($labels as $label) {
                if ($label === 'ai-dev' || preg_match('/^ai-dev-\d+$/', $label)) {
                    $jiraClient->removeLabel($issueKey, $label);
                    $this->logger->debug('Removed ai-dev label', ['issue_key' => $issueKey, 'label' => $label]);
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to remove ai-dev labels', [
                'issue_key' => $issueKey,
                'error' => $e->getMessage()
            ]);
        }
    }

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
     * Handle job completed with PR - remove working label, transition status, and send confirmation
     *
     * @param int $memberId Member ID
     * @param string $cloudId Cloud ID
     * @param string $issueKey Issue key
     * @param int $boardId Board ID
     * @param string|null $jobId Job ID for confirmation tracking
     * @param array $result Job result containing pr_url, pr_number, branch_name, files_changed, summary
     */
    public function onJobCompleted(int $memberId, string $cloudId, string $issueKey, int $boardId, ?string $jobId = null, array $result = []): void {
        try {
            $jiraClient = new JiraClient($memberId, $cloudId);

            // Remove job labels (myctobot-working, ai-dev, ai-dev-{id})
            $this->removeJobLabels($jiraClient, $issueKey);

            // Transition to "PR created" status if configured
            $settings = $this->getBoardStatusSettings($memberId, $boardId);
            $prCreatedStatus = $settings['aidev_status_pr_created'] ?? null;

            if ($prCreatedStatus) {
                $transitionResult = $jiraClient->transitionToStatus($issueKey, $prCreatedStatus);
                if ($transitionResult['success']) {
                    $this->logger->info('Transitioned issue to PR created status', [
                        'issue_key' => $issueKey,
                        'from' => $transitionResult['from_status'],
                        'to' => $transitionResult['to_status']
                    ]);
                } else {
                    // Post comment if transition failed
                    $this->postTransitionFailureComment($jiraClient, $issueKey, $prCreatedStatus, $transitionResult);
                }
            }

            // Send delivery confirmation to CEO
            if ($jobId) {
                $this->sendDeliveryConfirmation($memberId, $jobId, $issueKey, $cloudId, $result, 'success');
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
     * Handle job failed - remove working label, transition status, and send failure notification
     *
     * @param int $memberId Member ID
     * @param string $cloudId Cloud ID
     * @param string $issueKey Issue key
     * @param int $boardId Board ID
     * @param string $errorMessage Error message
     * @param string|null $jobId Job ID for confirmation tracking
     * @param array $context Additional context about the failure
     */
    public function onJobFailed(int $memberId, string $cloudId, string $issueKey, int $boardId, string $errorMessage, ?string $jobId = null, array $context = []): void {
        try {
            $jiraClient = new JiraClient($memberId, $cloudId);

            // Remove job labels (myctobot-working, ai-dev, ai-dev-{id})
            $this->removeJobLabels($jiraClient, $issueKey);

            // Transition to "failed" status if configured
            $settings = $this->getBoardStatusSettings($memberId, $boardId);
            $failedStatus = $settings['aidev_status_failed'] ?? null;

            if ($failedStatus) {
                $transitionResult = $jiraClient->transitionToStatus($issueKey, $failedStatus);
                $this->logger->info('Transitioned issue after failure', [
                    'issue_key' => $issueKey,
                    'success' => $transitionResult['success']
                ]);
            }

            // Post failure comment
            $this->postFailureComment($jiraClient, $issueKey, $errorMessage);

            // Log CEO directive error for audit trail
            $this->logCeoDirectiveError(
                $memberId,
                $issueKey,
                $errorMessage,
                null,
                'Posted failure comment to Jira; ticket transitioned to failed status'
            );

            // Send failure notification to CEO
            if ($jobId) {
                $this->sendDeliveryConfirmation($memberId, $jobId, $issueKey, $cloudId, [
                    'error_message' => $errorMessage,
                    'context' => $context
                ], 'failure');
            }

        } catch (\Exception $e) {
            $this->logger->error('Error in onJobFailed', [
                'issue_key' => $issueKey,
                'error' => $e->getMessage()
            ]);

            // Log the secondary error
            $this->logCeoDirectiveError(
                $memberId,
                $issueKey,
                'Error during failure handling: ' . $e->getMessage(),
                $e,
                'Original error: ' . $errorMessage
            );
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

            // Collect unique repo IDs from jobs to find linked Shopify stores
            $repoIds = [];
            foreach ($jobs as $job) {
                if (!empty($job['repo_id'])) {
                    $repoIds[$job['repo_id']] = true;
                }
            }

            // Cleanup Shopify development themes from all linked stores
            $cleanedStores = [];
            foreach (array_keys($repoIds) as $repoId) {
                $shopifyConn = ShopifyClient::getConnectionByRepo($repoId);
                if ($shopifyConn && !isset($cleanedStores[$shopifyConn->id])) {
                    try {
                        $shopifyClient = new ShopifyClient($shopifyConn->id);
                        $deleted = $shopifyClient->cleanupDevThemes($issueKey);
                        $results['shopify_themes_deleted'] += $deleted;
                        $cleanedStores[$shopifyConn->id] = true;

                        $this->logger->info('Cleaned up Shopify dev themes', [
                            'issue_key' => $issueKey,
                            'shop_domain' => $shopifyConn->shop_domain,
                            'themes_deleted' => $deleted
                        ]);
                    } catch (\Exception $e) {
                        $results['errors'][] = "Shopify cleanup ({$shopifyConn->shop_domain}): " . $e->getMessage();
                    }
                }
            }

            // Also try legacy member-based Shopify cleanup
            if (empty($cleanedStores)) {
                $shopifyClient = new ShopifyClient(null, $memberId);
                if ($shopifyClient->isConnected()) {
                    try {
                        $results['shopify_themes_deleted'] = $shopifyClient->cleanupDevThemes($issueKey);
                        $this->logger->info('Cleaned up Shopify dev themes (legacy)', [
                            'issue_key' => $issueKey,
                            'themes_deleted' => $results['shopify_themes_deleted']
                        ]);
                    } catch (\Exception $e) {
                        $results['errors'][] = 'Shopify cleanup: ' . $e->getMessage();
                    }
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
     * Multi-store mode: If repoId is provided, looks up the linked Shopify connection.
     * Legacy fallback: If no repo-linked store found, falls back to member's enterprisesettings.
     *
     * @param int $memberId Member ID
     * @param int|null $repoId Repo connection ID (for multi-store mode)
     * @return array Shopify settings
     */
    public function getShopifySettingsForPayload(int $memberId, ?int $repoId = null): array {
        $settings = [
            'enabled' => false,
            'member_id' => $memberId,
            'connection_id' => null,
            'shop_domain' => null,
            'access_token' => null,
            'storefront_password' => null,
            'verify_with_playwright' => false
        ];

        try {
            $shopifyClient = null;
            $shopifyConn = null;

            // Multi-store mode: Look up Shopify connection linked to this repo
            if ($repoId) {
                $shopifyConn = ShopifyClient::getConnectionByRepo($repoId);
                if ($shopifyConn) {
                    $shopifyClient = new ShopifyClient($shopifyConn->id);
                    $settings['connection_id'] = $shopifyConn->id;

                    $this->logger->debug('Found Shopify connection linked to repo', [
                        'repo_id' => $repoId,
                        'shopify_connection_id' => $shopifyConn->id,
                        'shop_domain' => $shopifyConn->shop_domain
                    ]);
                }
            }

            // Legacy fallback: Use member's enterprisesettings if no repo-linked store found
            if (!$shopifyClient) {
                $shopifyClient = new ShopifyClient(null, $memberId);
            }

            if ($shopifyClient->isConnected()) {
                $settings['enabled'] = true;
                $settings['shop_domain'] = $shopifyClient->getShop();
                $settings['access_token'] = $shopifyClient->getAccessToken();

                // Get additional settings from shopifyconnections table if using multi-store
                if ($shopifyConn) {
                    if ($shopifyConn->storefront_password) {
                        $settings['storefront_password'] = EncryptionService::decrypt($shopifyConn->storefront_password);
                    }
                    $settings['verify_with_playwright'] = (bool)$shopifyConn->verify_with_playwright;
                } else {
                    // Legacy: Get settings from enterprisesettings
                    $passwordSetting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['shopify_storefront_password', $memberId]);
                    if ($passwordSetting && $passwordSetting->setting_value) {
                        $settings['storefront_password'] = EncryptionService::decrypt($passwordSetting->setting_value);
                    }

                    $verifySetting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['shopify_verify_playwright', $memberId]);
                    $verifyEnabled = $verifySetting ? $verifySetting->setting_value : null;
                    $settings['verify_with_playwright'] = ($verifyEnabled === '1' || $verifyEnabled === 'true');
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not get Shopify settings', [
                'member_id' => $memberId,
                'repo_id' => $repoId,
                'error' => $e->getMessage()
            ]);
        }

        return $settings;
    }

    // ========================================
    // CEO Directive Logging Helpers
    // ========================================

    /**
     * Log a CEO directive response for audit trail
     * Creates a new directive entry if no active directive is found
     *
     * @param int $memberId Member ID
     * @param string $issueKey Issue key
     * @param string $responseContent Content of the response
     * @param string $deliveryStatus Delivery status (success, failed, pending)
     */
    private function logCeoDirectiveResponse(int $memberId, string $issueKey, string $responseContent, string $deliveryStatus): void {
        try {
            $directiveLogger = new CeoDirectiveLogger($memberId);

            // Try to find an existing directive for this issue from recent logs
            $recentLogs = $directiveLogger->getLogsForIssue($issueKey, 1);
            $directiveId = null;

            if (!empty($recentLogs)) {
                // Use the most recent directive ID for this issue
                $directiveId = $recentLogs[0]['directive_id'];
            } else {
                // No existing directive found, create a new one
                $directiveId = $directiveLogger->logDirectiveReceived($issueKey, 'system', [
                    'source' => 'AIDevJobService',
                    'action' => 'response_delivery'
                ]);
            }

            $directiveLogger->logResponse($directiveId, $responseContent, $deliveryStatus, [
                'delivery_method' => 'jira_comment',
                'member_id' => $memberId
            ]);

        } catch (\Exception $e) {
            // Don't let logging failures break the main flow
            $this->logger->warning('Failed to log CEO directive response', [
                'member_id' => $memberId,
                'issue_key' => $issueKey,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Log a CEO directive error for audit trail
     *
     * @param int $memberId Member ID
     * @param string $issueKey Issue key
     * @param string $errorMessage Error description
     * @param \Throwable|null $exception Exception for stack trace
     * @param string|null $recoveryAction Recovery action taken
     */
    public function logCeoDirectiveError(int $memberId, string $issueKey, string $errorMessage, ?\Throwable $exception = null, ?string $recoveryAction = null): void {
        try {
            $directiveLogger = new CeoDirectiveLogger($memberId);

            // Try to find an existing directive for this issue
            $recentLogs = $directiveLogger->getLogsForIssue($issueKey, 1);
            $directiveId = null;

            if (!empty($recentLogs)) {
                $directiveId = $recentLogs[0]['directive_id'];
            } else {
                // No existing directive found, create a new one
                $directiveId = $directiveLogger->logDirectiveReceived($issueKey, 'system', [
                    'source' => 'AIDevJobService',
                    'action' => 'error_logging'
                ]);
            }

            $directiveLogger->logError($directiveId, $errorMessage, $exception, $recoveryAction);

        } catch (\Exception $e) {
            // Don't let logging failures break the main flow
            $this->logger->warning('Failed to log CEO directive error', [
                'member_id' => $memberId,
                'issue_key' => $issueKey,
                'error' => $e->getMessage()
            ]);
        }
    }

    // ========================================
    // Delivery Confirmation Support
    // ========================================

    /**
     * Send delivery confirmation via DeliveryConfirmationService
     *
     * @param int $memberId Member ID
     * @param string $jobId Job ID
     * @param string $issueKey Issue key
     * @param string|null $cloudId Cloud ID for Jira
     * @param array $data Job result data or error context
     * @param string $type 'success' or 'failure'
     */
    private function sendDeliveryConfirmation(
        int $memberId,
        string $jobId,
        string $issueKey,
        ?string $cloudId,
        array $data,
        string $type
    ): void {
        try {
            $confirmationService = new DeliveryConfirmationService();

            if ($type === 'success') {
                $result = $confirmationService->sendSuccessConfirmation(
                    $memberId,
                    $jobId,
                    $issueKey,
                    $cloudId,
                    $data
                );
            } else {
                $result = $confirmationService->sendFailureNotification(
                    $memberId,
                    $jobId,
                    $issueKey,
                    $cloudId,
                    $data['error_message'] ?? 'Unknown error',
                    $data['context'] ?? []
                );
            }

            $this->logger->info('Delivery confirmation sent', [
                'job_id' => $jobId,
                'issue_key' => $issueKey,
                'type' => $type,
                'success' => $result['success'],
                'delivery_results' => $result['delivery_results'] ?? []
            ]);

        } catch (\Exception $e) {
            // Log but don't fail the job completion/failure flow
            $this->logger->error('Failed to send delivery confirmation', [
                'job_id' => $jobId,
                'issue_key' => $issueKey,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Process retry queue for failed delivery confirmations
     * Can be called from a cron job
     *
     * @param int|null $memberId Optional member ID to filter
     * @return array Summary of retry results
     */
    public function processConfirmationRetryQueue(?int $memberId = null): array {
        try {
            $confirmationService = new DeliveryConfirmationService();
            return $confirmationService->retryFailedDeliveries($memberId);
        } catch (\Exception $e) {
            $this->logger->error('Failed to process confirmation retry queue', [
                'member_id' => $memberId,
                'error' => $e->getMessage()
            ]);
            return [
                'processed' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
}
