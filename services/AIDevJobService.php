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
require_once __DIR__ . '/GitHubClient.php';
require_once __DIR__ . '/AIDevStatusService.php';
require_once __DIR__ . '/ShardRouter.php';
require_once __DIR__ . '/ShopifyClient.php';
require_once __DIR__ . '/TmuxService.php';
require_once __DIR__ . '/CeoDirectiveLogger.php';
require_once __DIR__ . '/DeliveryConfirmationService.php';
require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';

use \app\plugins\AtlassianAuth;
use \app\Bean;

class AIDevJobService {

    private $logger;

    public function __construct() {
        $this->logger = Flight::get('log');
    }

    // Supported project types
    public const PROJECT_TYPE_JIRA = 'jira';
    public const PROJECT_TYPE_GITHUB = 'github';
    public const PROJECT_TYPE_MONDAY = 'monday';
    public const PROJECT_TYPE_ZOHO = 'zoho';

    /**
     * Trigger an AI Developer job on a shard
     *
     * @param int $memberId Member ID
     * @param string $issueKey Issue key (e.g., "SSI-1871" for Jira, "owner/repo#123" for GitHub)
     * @param string $cloudId Atlassian cloud ID (or identifier for the project source)
     * @param int|null $boardId Board ID (optional, will be auto-detected for Jira)
     * @param int|null $repoId Repository connection ID (optional, will use first enabled)
     * @param string|null $tenant Tenant slug for multi-tenancy
     * @param bool $useOrchestrator Whether to use orchestrator mode
     * @param string $projectType Project source type: 'jira', 'github', 'monday', 'zoho'
     * @return array Result with 'success', 'job_uid', 'error' keys
     */
    public function triggerJob(int $memberId, string $issueKey, string $cloudId, ?int $boardId = null, ?int $repoId = null, ?string $tenant = null, bool $useOrchestrator = false, string $projectType = self::PROJECT_TYPE_JIRA): array {
        try {
            // Validate member exists
            $member = Bean::load('member', $memberId);
            if (!$member || !$member->id) {
                return ['success' => false, 'error' => 'Member not found'];
            }

            $this->logger->debug('triggerJob called', [
                'member_id' => $memberId,
                'issue_key' => $issueKey,
                'project_type' => $projectType,
                'board_id' => $boardId,
                'repo_id' => $repoId
            ]);

            // Auto-detect board ID from issue key if not provided (Jira only)
            if (!$boardId && $projectType === self::PROJECT_TYPE_JIRA) {
                $projectKey = explode('-', $issueKey)[0];
                // Allow shared workspace boards (is_shared = 1) or member-specific boards
                $board = Bean::findOne('jiraboards', 'project_key = ? AND (member_id = ? OR is_shared = 1)', [$projectKey, $memberId]);
                if (!$board) {
                    return ['success' => false, 'error' => "No board found for project: {$projectKey}"];
                }
                $boardId = $board->id;
            }

            // Auto-detect repo ID if not provided (workspace-level - shared by all members)
            if (!$repoId) {
                $repo = Bean::findOne('repoconnections', 'enabled = ?', [1]);
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
                        return ['success' => false, 'error' => 'Job already running for this issue', 'job_uid' => $existingJob['job_uid']];
                    } else {
                        // Stale status file - tmux session is gone, auto-cleanup
                        $this->logger->info('Auto-cleanup stale job status (tmux gone)', [
                            'issue_key' => $issueKey,
                            'job_uid' => $existingJob['job_uid'],
                            'stale_status' => $existingJob['status']
                        ]);
                        AIDevStatusService::fail($memberId, $existingJob['job_uid'], 'Session ended unexpectedly');
                    }
                }

                // Cooldown: if job completed/failed within last 2 minutes, skip
                // This prevents rapid re-triggering from webhook loops
                $lastUpdated = strtotime($existingJob['updated_at'] ?? $existingJob['created_at'] ?? '');
                $cooldownSeconds = 120; // 2 minutes
                if ($lastUpdated && (time() - $lastUpdated) < $cooldownSeconds) {
                    return ['success' => false, 'error' => 'Recent job exists, cooldown active', 'job_uid' => $existingJob['job_uid']];
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

            if (!$existingBranch && $projectType === self::PROJECT_TYPE_JIRA) {
                // Try to get branch from Jira's development info (Jira only)
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

            // Determine execution mode based on project type and board settings
            $useLocalRunner = true; // Default to local runner
            $boardKeyId = null;

            if ($projectType === self::PROJECT_TYPE_JIRA && $boardId) {
                // For Jira, check board's API key setting: NULL = local runner, ID = use that key
                $boardBean = Bean::load('jiraboards', $boardId);
                $boardKeyId = $boardBean->aidev_anthropic_key_id;
                $useLocalRunner = ($boardKeyId === null || $boardKeyId === '' || !$boardKeyId);

                if ($useLocalRunner) {
                    // Fall back to global config if board has no setting
                    $globalUseLocal = Flight::get('aidev.use_local_runner') ?? true;
                    $useLocalRunner = $globalUseLocal;
                }
            }
            // Non-Jira project types (github, monday, zoho) always use local runner

            if ($useLocalRunner) {
                // Check if there's already a running session for this issue
                $runningSession = $this->findRunningSessionForIssue($issueKey, $tenant);
                if ($runningSession) {
                    // Session already running - don't send generic "check for updates" message
                    // The Webhook controller handles forwarding actual comments/updates directly
                    $this->logger->info('Session already running for issue, skipping job start', [
                        'member_id' => $memberId,
                        'issue_key' => $issueKey,
                        'session_id' => $runningSession['session_id'],
                        'tmux_name' => $runningSession['tmux_name']
                    ]);

                    return [
                        'success' => true,
                        'already_running' => true,
                        'session_id' => $runningSession['session_id'],
                        'tmux_name' => $runningSession['tmux_name'],
                        'message' => "Session already running for {$issueKey}"
                    ];
                }

                // Queue-first pattern: All jobs go to queue, cron processor starts them
                // This provides better rate limiting and job management
                $wsLimit = self::checkWorkspaceLocalRunnerLimit();

                $this->logger->info('Queuing local runner job', [
                    'member_id' => $memberId,
                    'issue_key' => $issueKey,
                    'running' => $wsLimit['running'],
                    'max' => $wsLimit['max']
                ]);

                return $this->queueJob($memberId, $issueKey, $boardId, $cloudId, $repoId, $tenant, $useOrchestrator, $projectType, $wsLimit);
            }

            // === API execution path - get key and model from anthropickeys table ===
            $keyBean = Bean::load('anthropickeys', $boardKeyId);

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
            $repoBean = Bean::load('repoconnections', $repoId);

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
                'job_uid' => $jobId,
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
                'cloud_uid' => $cloudId,
                'github_token' => $repoToken,
                'callback_url' => Flight::get('baseurl') . '/webhook/aidev',
                'callback_api_key' => Flight::get('cron.api_key'),
                'action' => 'implement',
                // Branch affinity: reuse existing branch if available
                'existing_branch' => $existingBranch,
                // Shopify integration
                'shopify' => $shopifySettings,
                'existing_shopify_themeid' => $existingThemeId,
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
                'job_uid' => $jobId,
                'shard_id' => $shard['id'],
                'shard_name' => $shard['name'] ?? $shard['host'],
                'issue_key' => $issueKey,
                'trigger' => 'service'
            ]);

            // Add working label and transition status (async, don't block on failure)
            $this->onJobStarted($memberId, $cloudId, $issueKey, $boardId);

            return [
                'success' => true,
                'job_uid' => $jobId,
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
            $setting = Bean::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['max_concurrent_aidev_jobs', $memberId]);
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
     * Check workspace-level local runner concurrency limit
     *
     * This limits how many tmux-claude-runners can run simultaneously
     * across the entire workspace (not per-member).
     *
     * Priority: INI config > enterprisesettings > default (2)
     * Hard cap: 4 (will be configurable by payment tier in future)
     *
     * @return array ['available' => bool, 'running' => int, 'max' => int, 'hard_cap' => int]
     */
    public static function checkWorkspaceLocalRunnerLimit(): array {
        $maxLocalRunners = 2; // default
        $hardCap = 4; // absolute max for now (future: up to 8 based on tier)

        // 1. Check INI config first (tenant override)
        $iniMax = Flight::get('aidev.max_concurrent_local_runners');
        if ($iniMax !== null && $iniMax !== '') {
            $maxLocalRunners = min((int)$iniMax, $hardCap);
        } else {
            // 2. Check workspace setting in database (no member_id = workspace-level)
            $setting = Bean::findOne('enterprisesettings',
                'setting_key = ?',
                ['max_concurrent_local_runners']
            );
            if ($setting && $setting->setting_value) {
                $maxLocalRunners = min((int)$setting->setting_value, $hardCap);
            }
        }

        // Count running jobs by checking actual AOE tmux sessions
        $runningLocal = 0;
        try {
            $aoePath = dirname(__DIR__) . '/../aoe-php/vendor/autoload.php';
            if (file_exists($aoePath)) {
                require_once $aoePath;
                $tenant = Flight::get('tenant.slug') ?: 'default';
                \Aoe\Tenant\TenantContext::set($tenant);
                $aoeStorage = new \Aoe\Session\Storage($tenant);
                $aoeTmux = new \Aoe\Tmux\TmuxService($tenant);

                // Count sessions where tmux is actually running
                foreach ($aoeStorage->loadAll() as $session) {
                    if ($aoeTmux->sessionExistsByName($session->getTmuxName())) {
                        $runningLocal++;
                    }
                }
            }
        } catch (\Exception $e) {
            // Fallback to database count if AOE check fails
            $runningLocal = (int)Bean::count('aidevjobs', 'status = ?', ['running']);
        }

        return [
            'available' => $runningLocal < $maxLocalRunners,
            'running' => $runningLocal,
            'max' => $maxLocalRunners,
            'hard_cap' => $hardCap
        ];
    }

    /**
     * Get current runner status for UI display
     *
     * @return array Runner status info
     */
    public static function getRunnerStatus(): array {
        $limit = self::checkWorkspaceLocalRunnerLimit();

        // Also get queued jobs (approved but not yet running)
        $queuedJobs = (int)Bean::count('aidevjobs',
            'status = ?',
            ['pending']
        );

        return [
            'running' => $limit['running'],
            'max' => $limit['max'],
            'hard_cap' => $limit['hard_cap'],
            'queued' => $queuedJobs,
            'available_slots' => max(0, $limit['max'] - $limit['running'])
        ];
    }

    /**
     * Update workspace runner limit setting
     *
     * @param int $newLimit New limit (will be capped at hard_cap)
     * @return array Result with new limit
     */
    public static function updateRunnerLimit(int $newLimit): array {
        $hardCap = 4; // same as in checkWorkspaceLocalRunnerLimit
        $minLimit = 1;

        // Enforce bounds
        $newLimit = max($minLimit, min($newLimit, $hardCap));

        // Find or create setting
        $setting = Bean::findOne('enterprisesettings',
            'setting_key = ?',
            ['max_concurrent_local_runners']
        );

        if (!$setting) {
            $setting = Bean::dispense('enterprisesettings');
            $setting->setting_key = 'max_concurrent_local_runners';
            $setting->created_at = date('Y-m-d H:i:s');
        }

        $setting->setting_value = (string)$newLimit;
        $setting->updated_at = date('Y-m-d H:i:s');
        Bean::store($setting);

        return [
            'success' => true,
            'limit' => $newLimit,
            'hard_cap' => $hardCap
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
        $setting = Bean::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['jira_bot_account_id', $memberId]);
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
     * @return array Result with 'success', 'job_uid', 'error' keys
     */
    public function triggerGitHubJob(int $memberId, string $issueKey, int $repoId, ?string $tenant = null): array {
        try {
            // Validate member exists
            $member = Bean::load('member', $memberId);
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
                        return ['success' => false, 'error' => 'Job already running for this issue', 'job_uid' => $existingJob['job_uid']];
                    } else {
                        // Stale status file - auto cleanup
                        $this->logger->info('Auto-cleanup stale GitHub job status', [
                            'issue_key' => $issueKey,
                            'job_uid' => $existingJob['job_uid']
                        ]);
                        AIDevStatusService::fail($memberId, $existingJob['job_uid'], 'Session ended unexpectedly');
                    }
                }

                // Cooldown check
                $lastUpdated = strtotime($existingJob['updated_at'] ?? $existingJob['created_at'] ?? '');
                if ($lastUpdated && (time() - $lastUpdated) < 120) {
                    return ['success' => false, 'error' => 'Recent job exists, cooldown active', 'job_uid' => $existingJob['job_uid']];
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
        $tmux = new TmuxService($memberId, $issueKey, null, $tenant);

        if ($tmux->exists()) {
            return [
                'success' => false,
                'error' => 'Session already exists for this issue',
                'session_name' => $tmux->getSessionName()
            ];
        }

        // Create job record (use 0 for boardId since GitHub doesn't have boards)
        $jobId = AIDevStatusService::createJob($memberId, 0, $issueKey, $repoId, null, 'github');

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
                'job_uid' => $jobId
            ]);

            return [
                'success' => true,
                'job_uid' => $jobId,
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
     * @param int $boardId Board ID (0 for non-Jira projects)
     * @param string $cloudId Cloud ID (or identifier for non-Jira sources)
     * @param int|null $repoId Repository connection ID
     * @param string|null $tenant Tenant slug for multi-tenancy
     * @param bool $useOrchestrator Use orchestrator mode
     * @param string $projectType Project source type: 'jira', 'github', 'monday', 'zoho'
     * @return array Result with 'success', 'job_uid', 'session_name' keys
     */
    private function spawnLocalRunner(int $memberId, string $issueKey, int $boardId, string $cloudId, ?int $repoId = null, ?string $tenant = null, bool $useOrchestrator = true, string $projectType = self::PROJECT_TYPE_JIRA): array {
        $tmux = new TmuxService($memberId, $issueKey, null, $tenant);

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

        // Create job record for tracking (include repoId and projectType)
        $jobId = AIDevStatusService::createJob($memberId, $boardId, $issueKey, $repoId, $cloudId, $projectType);

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
                'job_uid' => $jobId
            ]);

            return [
                'success' => true,
                'job_uid' => $jobId,
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
    // Job Queue Management
    // ========================================

    /**
     * Find a running AOE session for the given issue
     *
     * @param string $issueKey Issue key to find
     * @param string|null $tenant Tenant slug
     * @return array|null Session info if found and running, null otherwise
     */
    private function findRunningSessionForIssue(string $issueKey, ?string $tenant): ?array
    {
        try {
            $aoePath = dirname(__DIR__) . '/../aoe-php/vendor/autoload.php';
            if (!file_exists($aoePath)) {
                return null;
            }
            require_once $aoePath;

            $tenantSlug = $tenant ?: (\Aoe\Tmux\TmuxService::getDomainId() ?? 'default');
            \Aoe\Tenant\TenantContext::set($tenantSlug);

            $storage = new \Aoe\Session\Storage($tenantSlug);
            $tmux = new \Aoe\Tmux\TmuxService($tenantSlug);

            // Find session by reference (issue key)
            foreach ($storage->loadAll() as $session) {
                // Check if this session is for the same issue (by reference or title)
                if ($session->reference === $issueKey || $session->title === $issueKey) {
                    $tmuxName = $session->getTmuxName();
                    // Check if tmux session is actually running
                    if ($tmux->sessionExistsByName($tmuxName)) {
                        return [
                            'session_id' => $session->id,
                            'tmux_name' => $tmuxName,
                            'tenant' => $tenantSlug,
                            'session' => $session
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Error checking for running session', [
                'issue_key' => $issueKey,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Send an update to a running session
     *
     * @param array $runningSession Session info from findRunningSessionForIssue
     * @param string $issueKey Issue key
     * @param int $memberId Member ID
     * @return array Result
     */
    private function sendUpdateToRunningSession(array $runningSession, string $issueKey, int $memberId): array
    {
        try {
            $aoePath = dirname(__DIR__) . '/../aoe-php/vendor/autoload.php';
            require_once $aoePath;

            $tmux = new \Aoe\Tmux\TmuxService($runningSession['tenant']);
            $tmuxName = $runningSession['tmux_name'];

            // Send a message to the running session about the update
            $message = "\n\n[WEBHOOK UPDATE] New activity on {$issueKey}. Please check for any new comments or changes to the ticket.\n";

            // Send the message as text followed by Enter
            $tmux->sendTextByName($tmuxName, $message);
            $tmux->sendEnterByName($tmuxName);

            $this->logger->info('Sent update to running session', [
                'issue_key' => $issueKey,
                'session_id' => $runningSession['session_id'],
                'tmux_name' => $tmuxName
            ]);

            return [
                'success' => true,
                'updated_existing' => true,
                'session_id' => $runningSession['session_id'],
                'tmux_name' => $tmuxName,
                'message' => "Update sent to running session for {$issueKey}"
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to send update to running session', [
                'issue_key' => $issueKey,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to send update to running session: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Queue a job for later execution when capacity is available
     *
     * @param int $memberId Member ID
     * @param string $issueKey Issue key
     * @param int $boardId Board ID
     * @param string $cloudId Cloud ID
     * @param int|null $repoId Repository ID
     * @param string|null $tenant Tenant slug
     * @param bool $useOrchestrator Use orchestrator mode
     * @param string $projectType Project type (jira, github, etc.)
     * @param array $wsLimit Current workspace limit info
     * @return array Result with queued status
     */
    private function queueJob(
        int $memberId,
        string $issueKey,
        ?int $boardId,
        string $cloudId,
        ?int $repoId,
        ?string $tenant,
        bool $useOrchestrator,
        string $projectType,
        array $wsLimit
    ): array {
        // Check if job already exists for this issue
        $existingJob = Bean::findOne('aidevjobs',
            'issue_key = ? AND status IN (?, ?, ?)',
            [$issueKey, 'queued', 'running', 'pending']
        );

        if ($existingJob) {
            $this->logger->info('Job already exists for issue', [
                'issue_key' => $issueKey,
                'existing_status' => $existingJob->status,
                'job_uid' => $existingJob->id
            ]);
            return [
                'success' => true,
                'queued' => $existingJob->status === 'queued',
                'job_uid' => $existingJob->id,
                'message' => "Job already {$existingJob->status} for this issue",
                'position' => $this->getQueuePosition($existingJob->id)
            ];
        }

        // Create queued job record
        $job = Bean::dispense('aidevjobs');
        $job->job_uid = bin2hex(random_bytes(16));  // Generate UUID
        $job->member_id = $memberId;
        $job->boards_id = $boardId;
        $job->issue_key = $issueKey;
        $job->cloud_uid = $cloudId;
        $job->repoconnections_id = $repoId;
        $job->status = 'queued';
        $job->current_step = 'Waiting in queue';
        $job->progress = 0;
        $job->created_at = date('Y-m-d H:i:s');
        $job->updated_at = date('Y-m-d H:i:s');
        $job->project_type = $projectType;

        // Store queue metadata for later execution
        $job->queue_metadata = json_encode([
            'tenant' => $tenant,
            'use_orchestrator' => $useOrchestrator,
            'queued_at' => date('Y-m-d H:i:s'),
            'ws_running_at_queue' => $wsLimit['running'],
            'ws_max' => $wsLimit['max']
        ]);

        $jobUuid = $job->job_uid;  // Save UUID before store
        $beanId = Bean::store($job);

        $queuePosition = $this->getQueuePosition($beanId);

        $this->logger->info('Job queued for later execution', [
            'job_uid' => $jobUuid,
            'bean_id' => $beanId,
            'issue_key' => $issueKey,
            'member_id' => $memberId,
            'position' => $queuePosition,
            'running' => $wsLimit['running'],
            'max' => $wsLimit['max']
        ]);

        // Log to job history (uses string job_uid, not bean id)
        AIDevStatusService::log($jobUuid, $memberId, 'info', 'Job queued - waiting for available slot', [
            'position' => $queuePosition,
            'running_jobs' => $wsLimit['running'],
            'max_jobs' => $wsLimit['max']
        ]);

        return [
            'success' => true,
            'queued' => true,
            'job_uid' => $jobUuid,
            'message' => "Job queued (position {$queuePosition}). {$wsLimit['running']}/{$wsLimit['max']} jobs running.",
            'position' => $queuePosition,
            'running' => $wsLimit['running'],
            'max' => $wsLimit['max']
        ];
    }

    /**
     * Get queue position for a job
     */
    private function getQueuePosition(int $jobId): int {
        $queuedJobs = Bean::find('aidevjobs',
            'status = ? ORDER BY created_at ASC',
            ['queued']
        );

        $position = 1;
        foreach ($queuedJobs as $job) {
            if ((int)$job->id === $jobId) {
                return $position;
            }
            $position++;
        }
        return $position;
    }

    /**
     * Process the job queue - start queued jobs if capacity available
     *
     * Called by cron-directive-processor.php
     *
     * @param string|null $tenant Optional tenant filter
     * @return array Results with jobs started
     */
    public function processJobQueue(?string $tenant = null): array {
        $results = [
            'jobs_started' => 0,
            'jobs_remaining' => 0,
            'errors' => []
        ];

        // Check current capacity
        $wsLimit = self::checkWorkspaceLocalRunnerLimit();

        if (!$wsLimit['available']) {
            $results['jobs_remaining'] = (int)Bean::count('aidevjobs', 'status = ?', ['queued']);
            $this->logger->debug('Queue processor: no slots available', [
                'running' => $wsLimit['running'],
                'max' => $wsLimit['max'],
                'queued' => $results['jobs_remaining']
            ]);
            return $results;
        }

        $availableSlots = $wsLimit['max'] - $wsLimit['running'];

        // Get queued jobs (FIFO order)
        $queuedJobs = Bean::find('aidevjobs',
            'status = ? ORDER BY created_at ASC LIMIT ?',
            ['queued', $availableSlots]
        );

        foreach ($queuedJobs as $job) {
            try {
                // Re-check capacity (in case another process started a job)
                $currentLimit = self::checkWorkspaceLocalRunnerLimit();
                if (!$currentLimit['available']) {
                    $this->logger->info('Queue processor: slot taken, stopping', [
                        'job_uid' => $job->id
                    ]);
                    break;
                }

                // Parse queue metadata
                $metadata = json_decode($job->queue_metadata ?? '{}', true);
                $jobTenant = $metadata['tenant'] ?? $tenant;
                $useOrchestrator = $metadata['use_orchestrator'] ?? true;

                $this->logger->info('Queue processor: starting queued job', [
                    'job_uid' => $job->id,
                    'issue_key' => $job->issue_key,
                    'tenant' => $jobTenant
                ]);

                // Update job status to running
                $job->status = 'running';
                $job->started_at = date('Y-m-d H:i:s');
                $job->updated_at = date('Y-m-d H:i:s');
                $job->current_step = 'Starting from queue';
                $job->progress = 5;
                Bean::store($job);

                // Spawn the tmux session
                $tmux = new TmuxService($job->member_id, $job->issue_key, null, $jobTenant);

                // Kill any existing session first
                if ($tmux->exists()) {
                    $tmux->kill();
                    sleep(1);
                }

                $scriptPath = dirname(__DIR__) . '/scripts/local-aidev-full.php';

                if ($tmux->spawnWithScript($scriptPath, $useOrchestrator, $job->job_uid, $job->repoconnections_id, $jobTenant)) {
                    $results['jobs_started']++;

                    AIDevStatusService::log($job->job_uid, $job->member_id, 'info', 'Job started from queue', [
                        'session' => $tmux->getSessionName(),
                        'wait_time' => $this->calculateWaitTime($job->created_at)
                    ]);

                    // Trigger onJobStarted for Jira jobs
                    if (($job->project_type ?? 'jira') === self::PROJECT_TYPE_JIRA) {
                        $this->onJobStarted($job->member_id, $job->cloud_uid, $job->issue_key, $job->boards_id);
                    }
                } else {
                    // Failed to spawn - mark as error
                    $job->status = 'error';
                    $job->error_message = 'Failed to spawn tmux session from queue';
                    $job->updated_at = date('Y-m-d H:i:s');
                    Bean::store($job);

                    $results['errors'][] = "Job {$job->job_uid} ({$job->issue_key}): Failed to spawn";

                    $this->logger->error('Queue processor: failed to spawn job', [
                        'job_uid' => $job->job_uid,
                        'issue_key' => $job->issue_key
                    ]);
                }

            } catch (\Exception $e) {
                $results['errors'][] = "Job {$job->job_uid}: " . $e->getMessage();

                $this->logger->error('Queue processor: exception starting job', [
                    'job_uid' => $job->job_uid,
                    'error' => $e->getMessage()
                ]);

                // Mark job as error
                $job->status = 'error';
                $job->error_message = $e->getMessage();
                $job->updated_at = date('Y-m-d H:i:s');
                Bean::store($job);
            }
        }

        $results['jobs_remaining'] = (int)Bean::count('aidevjobs', 'status = ?', ['queued']);

        $this->logger->info('Queue processor complete', $results);

        return $results;
    }

    /**
     * Calculate wait time from created_at to now
     */
    private function calculateWaitTime(string $createdAt): string {
        $created = new \DateTime($createdAt);
        $now = new \DateTime();
        $diff = $now->diff($created);

        if ($diff->h > 0) {
            return $diff->format('%hh %im');
        } elseif ($diff->i > 0) {
            return $diff->format('%im %ss');
        } else {
            return $diff->format('%ss');
        }
    }

    /**
     * Get queue status for display
     *
     * @return array Queue status info
     */
    public static function getQueueStatus(): array {
        $wsLimit = self::checkWorkspaceLocalRunnerLimit();

        $queuedJobs = Bean::find('aidevjobs',
            'status = ? ORDER BY created_at ASC',
            ['queued']
        );

        $queue = [];
        $position = 1;
        foreach ($queuedJobs as $job) {
            $queue[] = [
                'position' => $position++,
                'job_uid' => $job->id,
                'issue_key' => $job->issue_key,
                'queued_at' => $job->created_at,
                'member_id' => $job->member_id
            ];
        }

        return [
            'running' => $wsLimit['running'],
            'max' => $wsLimit['max'],
            'available_slots' => max(0, $wsLimit['max'] - $wsLimit['running']),
            'queued_count' => count($queue),
            'queue' => $queue
        ];
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
     * Public method to cleanup job labels (for API endpoint use)
     *
     * Handles both Jira and GitHub issues based on job's project_type.
     * Call this from cleanup endpoint after tenant database is switched.
     *
     * @param int|null $jobId Job ID (optional if issueKey provided)
     * @param string|null $issueKey Issue key (optional if jobId provided)
     * @return array Result with labels_removed, errors, and job info
     */
    public function cleanupJobLabels(?int $jobId = null, ?string $issueKey = null): array {
        $result = [
            'success' => false,
            'issue_key' => null,
            'project_type' => null,
            'labels_removed' => [],
            'errors' => []
        ];

        // Find the job
        $job = null;
        if ($jobId) {
            $job = Bean::load('aidevjobs', $jobId);
            if (!$job || !$job->id) {
                $job = null;
            }
        }
        if (!$job && $issueKey) {
            $job = Bean::findOne('aidevjobs', 'issue_key = ? ORDER BY updated_at DESC', [$issueKey]);
        }

        if (!$job) {
            $result['errors'][] = 'Job not found';
            return $result;
        }

        $result['issue_key'] = $job->issue_key;
        $result['project_type'] = $job->project_type ?? 'jira';

        $memberId = (int) $job->member_id;
        $cloudId = $job->cloud_uid ?? '';
        $projectType = $job->project_type ?? 'jira';

        if ($projectType === 'github') {
            $result = $this->cleanupGitHubLabels($job, $memberId, $result);
        } else {
            $result = $this->cleanupJiraLabels($job, $memberId, $cloudId, $result);
        }

        $result['success'] = empty($result['errors']) || !empty($result['labels_removed']);
        return $result;
    }

    /**
     * Cleanup Jira labels for a job
     */
    private function cleanupJiraLabels($job, int $memberId, string $cloudId, array $result): array {
        if (empty($cloudId)) {
            $result['errors'][] = 'No cloud_uid for Jira job';
            return $result;
        }

        try {
            $jiraClient = new JiraClient($memberId, $cloudId);

            // Remove working label
            try {
                $jiraClient->removeLabel($job->issue_key, self::WORKING_LABEL);
                $result['labels_removed'][] = self::WORKING_LABEL;
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), '404') === false) {
                    $result['errors'][] = 'Failed to remove ' . self::WORKING_LABEL . ': ' . $e->getMessage();
                }
            }

            // Remove ai-dev labels
            try {
                $issue = $jiraClient->getIssue($job->issue_key);
                $labels = $issue['fields']['labels'] ?? [];
                foreach ($labels as $label) {
                    if ($label === 'ai-dev' || preg_match('/^ai-dev-\d+$/', $label)) {
                        try {
                            $jiraClient->removeLabel($job->issue_key, $label);
                            $result['labels_removed'][] = $label;
                        } catch (\Exception $e) {
                            $result['errors'][] = "Failed to remove {$label}: " . $e->getMessage();
                        }
                    }
                }
            } catch (\Exception $e) {
                $result['errors'][] = 'Failed to get issue labels: ' . $e->getMessage();
            }

        } catch (\Exception $e) {
            $result['errors'][] = 'Failed to initialize Jira client: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Cleanup GitHub labels for a job
     */
    private function cleanupGitHubLabels($job, int $memberId, array $result): array {
        $repoConnectionId = $job->repoconnections_id ? (int) $job->repoconnections_id : null;

        if (!$repoConnectionId) {
            $result['errors'][] = 'No repoconnections_id for GitHub job';
            return $result;
        }

        $repo = Bean::load('repoconnections', $repoConnectionId);
        if (!$repo || !$repo->id) {
            $result['errors'][] = 'Repository connection not found';
            return $result;
        }

        $owner = $repo->repo_owner;
        $repoName = $repo->repo_name;

        // Parse issue number from issue_key
        $issueNumber = null;
        if (preg_match('/#(\d+)$/', $job->issue_key, $matches)) {
            $issueNumber = (int) $matches[1];
        } elseif (is_numeric($job->issue_key)) {
            $issueNumber = (int) $job->issue_key;
        }

        if (!$issueNumber) {
            $result['errors'][] = "Could not parse issue number from: {$job->issue_key}";
            return $result;
        }

        // Get GitHub token
        $githubToken = $repo->access_token ?? null;
        if (empty($githubToken)) {
            $githubSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);
            if ($githubSetting && !empty($githubSetting->setting_value)) {
                $encryption = new EncryptionService();
                $githubToken = $encryption->decrypt($githubSetting->setting_value);
            }
        }

        if (empty($githubToken)) {
            $result['errors'][] = 'No GitHub token available';
            return $result;
        }

        try {
            $githubClient = new GitHubClient($githubToken);

            $labelsToRemove = ['ai-dev', self::WORKING_LABEL, 'in-progress'];
            foreach ($labelsToRemove as $label) {
                try {
                    $githubClient->removeLabel($owner, $repoName, $issueNumber, $label);
                    $result['labels_removed'][] = $label;
                } catch (\Exception $e) {
                    // 404 means label wasn't on the issue - that's OK
                    if (strpos($e->getMessage(), '404') === false) {
                        $result['errors'][] = "Failed to remove {$label}: " . $e->getMessage();
                    }
                }
            }
        } catch (\Exception $e) {
            $result['errors'][] = 'Failed to initialize GitHub client: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Get board's AI Developer status settings
     *
     * @param int $memberId Member ID
     * @param int $boardId Board ID
     * @return array Status settings (working, pr_created, clarification, failed)
     */
    public function getBoardStatusSettings(int $memberId, int $boardId): array {
        $board = Bean::load('jiraboards', $boardId);
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
     * @param string $projectType Project source type: 'jira', 'github', 'monday', 'zoho'
     */
    public function onJobStarted(int $memberId, string $cloudId, string $issueKey, int $boardId, string $projectType = self::PROJECT_TYPE_JIRA): void {
        $this->logger->info('onJobStarted called', [
            'member_id' => $memberId,
            'cloud_uid' => $cloudId,
            'issue_key' => $issueKey,
            'board_id' => $boardId,
            'project_type' => $projectType
        ]);

        // Skip Jira operations for non-Jira project types
        if ($projectType !== self::PROJECT_TYPE_JIRA) {
            $this->logger->debug('Skipping Jira operations for non-Jira project', ['issue_key' => $issueKey, 'project_type' => $projectType]);
            return;
        }

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
     * @param string $projectType Project source type: 'jira', 'github', 'monday', 'zoho'
     */
    public function onJobCompleted(int $memberId, string $cloudId, string $issueKey, int $boardId, ?string $jobId = null, array $result = [], string $projectType = self::PROJECT_TYPE_JIRA): void {
        // Skip Jira operations for non-Jira project types
        if ($projectType !== self::PROJECT_TYPE_JIRA) {
            $this->logger->debug('Skipping Jira operations for non-Jira project on completion', ['issue_key' => $issueKey, 'project_type' => $projectType]);
            return;
        }

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
     * @param string $projectType Project source type: 'jira', 'github', 'monday', 'zoho'
     */
    public function onJobNeedsClarification(int $memberId, string $cloudId, string $issueKey, int $boardId, string $projectType = self::PROJECT_TYPE_JIRA): void {
        // Skip Jira operations for non-Jira project types
        if ($projectType !== self::PROJECT_TYPE_JIRA) {
            $this->logger->debug('Skipping Jira operations for non-Jira project on clarification', ['issue_key' => $issueKey, 'project_type' => $projectType]);
            return;
        }

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
     * @param string $projectType Project source type: 'jira', 'github', 'monday', 'zoho'
     */
    public function onJobFailed(int $memberId, string $cloudId, string $issueKey, int $boardId, string $errorMessage, ?string $jobId = null, array $context = [], string $projectType = self::PROJECT_TYPE_JIRA): void {
        // Skip Jira operations for non-Jira project types
        if ($projectType !== self::PROJECT_TYPE_JIRA) {
            $this->logger->debug('Skipping Jira operations for non-Jira project on failure', ['issue_key' => $issueKey, 'project_type' => $projectType]);
            return;
        }

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
                $jobId = $job['job_uid'];
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
                    $passwordSetting = Bean::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['shopify_storefront_password', $memberId]);
                    if ($passwordSetting && $passwordSetting->setting_value) {
                        $settings['storefront_password'] = EncryptionService::decrypt($passwordSetting->setting_value);
                    }

                    $verifySetting = Bean::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?', ['shopify_verify_playwright', $memberId]);
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
                $directiveId = $recentLogs[0]['directive_uid'];
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
                $directiveId = $recentLogs[0]['directive_uid'];
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
                'job_uid' => $jobId,
                'issue_key' => $issueKey,
                'type' => $type,
                'success' => $result['success'],
                'delivery_results' => $result['delivery_results'] ?? []
            ]);

        } catch (\Exception $e) {
            // Log but don't fail the job completion/failure flow
            $this->logger->error('Failed to send delivery confirmation', [
                'job_uid' => $jobId,
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

    // ========================================
    // Stale Job Detection & Cleanup
    // ========================================

    /**
     * Find jobs that have status='running' but no live tmux session
     * These are jobs that crashed without updating their status
     *
     * @return array Array of stale job info with 'job_uid', 'issue_key', 'member_id', 'started_at', 'current_step'
     */
    public static function findStaleJobs(): array {
        // Find all jobs with running status
        $runningJobs = \app\Bean::find('aidevjobs', 'status = ?', ['running']);

        $staleJobs = [];
        foreach ($runningJobs as $job) {
            // Get tenant from queue_metadata if available
            $tenant = null;
            if (!empty($job->queue_metadata)) {
                $metadata = json_decode($job->queue_metadata, true);
                $tenant = $metadata['tenant'] ?? null;
            }
            // Fallback to current session tenant
            if (!$tenant) {
                $tenant = $_SESSION['tenant_slug'] ?? null;
            }

            // Check if tmux session still exists
            $tmux = new TmuxService($job->member_id, $job->issue_key, null, $tenant);

            if (!$tmux->exists()) {
                // Session is gone - this is a stale job
                $staleJobs[] = [
                    'job_uid' => $job->job_uid,
                    'id' => $job->id,
                    'issue_key' => $job->issue_key,
                    'member_id' => $job->member_id,
                    'started_at' => $job->started_at,
                    'updated_at' => $job->updated_at,
                    'current_step' => $job->current_step,
                    'progress' => $job->progress,
                    'session_expected' => $tmux->getSessionName(),
                ];
            }
        }

        return $staleJobs;
    }

    /**
     * Mark a specific job as failed due to crash/stale session
     *
     * @param string $jobId Job ID (the UUID)
     * @param string $reason Reason for marking as stale
     * @return array Result with 'success', 'job_uid', 'error' keys
     */
    public static function markJobAsStale(string $jobId, string $reason = 'Tmux session crashed or terminated'): array {
        $job = \app\Bean::findOne('aidevjobs', 'job_uid = ?', [$jobId]);
        if (!$job) {
            return ['success' => false, 'error' => 'Job not found'];
        }

        if ($job->status !== 'running') {
            return ['success' => false, 'error' => 'Job is not in running status'];
        }

        // Verify the tmux session is actually dead
        $tmux = new TmuxService($job->member_id, $job->issue_key);
        if ($tmux->exists()) {
            return ['success' => false, 'error' => 'Tmux session still exists - job may still be running'];
        }

        // Mark as failed
        $job->status = 'failed';
        $job->error_message = $reason;
        $job->completed_at = date('Y-m-d H:i:s');
        $job->updated_at = date('Y-m-d H:i:s');
        \app\Bean::store($job);

        // Log the stale job cleanup
        AIDevStatusService::log($jobId, $job->member_id, 'warning', 'Job marked as stale', [
            'reason' => $reason,
            'last_step' => $job->current_step,
            'last_progress' => $job->progress
        ]);

        return [
            'success' => true,
            'job_uid' => $jobId,
            'issue_key' => $job->issue_key,
            'message' => 'Job marked as failed'
        ];
    }

    /**
     * Clean up all stale jobs (mark them as failed)
     *
     * @return array Summary with 'found', 'cleaned', 'errors'
     */
    public static function cleanupStaleJobs(): array {
        $staleJobs = self::findStaleJobs();

        $results = [
            'found' => count($staleJobs),
            'cleaned' => 0,
            'errors' => [],
            'jobs' => []
        ];

        foreach ($staleJobs as $staleJob) {
            $result = self::markJobAsStale($staleJob['job_uid'], 'Tmux session no longer exists - job crashed or was terminated');

            if ($result['success']) {
                $results['cleaned']++;
                $results['jobs'][] = [
                    'job_uid' => $staleJob['job_uid'],
                    'issue_key' => $staleJob['issue_key'],
                    'status' => 'cleaned'
                ];
            } else {
                $results['errors'][] = [
                    'job_uid' => $staleJob['job_uid'],
                    'issue_key' => $staleJob['issue_key'],
                    'error' => $result['error']
                ];
            }
        }

        return $results;
    }

    /**
     * Check if a specific job's tmux session is still alive
     *
     * @param string $jobId Job ID
     * @return array Status with 'exists', 'job_uid', 'session_name', 'claude_running'
     */
    public static function checkJobSession(string $jobId): array {
        $job = \app\Bean::findOne('aidevjobs', 'job_uid = ?', [$jobId]);
        if (!$job) {
            return ['exists' => false, 'error' => 'Job not found'];
        }

        $tmux = new TmuxService($job->member_id, $job->issue_key);

        return [
            'exists' => $tmux->exists(),
            'job_uid' => $jobId,
            'issue_key' => $job->issue_key,
            'session_name' => $tmux->getActiveSessionName() ?? $tmux->getSessionName(),
            'claude_running' => $tmux->isClaudeRunning(),
            'job_status' => $job->status,
            'current_step' => $job->current_step,
            'progress' => $job->progress,
            'started_at' => $job->started_at,
            'updated_at' => $job->updated_at
        ];
    }

    /**
     * Retry a failed job - resets status and triggers a new run
     * Keeps existing job record and logs, appending new log entries
     *
     * @param string $jobId Job ID (UUID)
     * @param int $memberId Member ID (for authorization)
     * @param string|null $tenant Tenant slug
     * @return array Result with 'success', 'job_uid', 'error' keys
     */
    public function retryJob(string $jobId, int $memberId, ?string $tenant = null): array {
        $job = \app\Bean::findOne('aidevjobs', 'job_uid = ?', [$jobId]);
        if (!$job) {
            return ['success' => false, 'error' => 'Job not found'];
        }

        // Verify ownership
        if ($job->member_id != $memberId) {
            return ['success' => false, 'error' => 'Not authorized to retry this job'];
        }

        // Only allow retry of failed/cancelled jobs
        if (!in_array($job->status, ['failed', 'cancelled', 'error'])) {
            return ['success' => false, 'error' => "Cannot retry job with status '{$job->status}'"];
        }

        // Log the retry attempt
        AIDevStatusService::log($jobId, $memberId, 'info', 'Job retry initiated - adding to queue', [
            'previous_status' => $job->status,
            'previous_error' => $job->error_message
        ]);

        // Queue-first pattern: Reset job to queued status, cron will pick it up
        $job->status = 'queued';
        $job->error_message = null;
        $job->completed_at = null;
        $job->current_step = 'Waiting in queue (retry)';
        $job->progress = 0;
        $job->updated_at = date('Y-m-d H:i:s');

        // Update queue metadata for retry
        $metadata = json_decode($job->queue_metadata ?? '{}', true);
        $metadata['tenant'] = $tenant ?? ($metadata['tenant'] ?? null);
        $metadata['retry_at'] = date('Y-m-d H:i:s');
        $metadata['retry_count'] = ($metadata['retry_count'] ?? 0) + 1;
        $job->queue_metadata = json_encode($metadata);

        \app\Bean::store($job);

        $queuePosition = $this->getQueuePosition($job->id);

        $this->logger->info('Job queued for retry', [
            'job_uid' => $jobId,
            'issue_key' => $job->issue_key,
            'position' => $queuePosition,
            'retry_count' => $metadata['retry_count']
        ]);

        return [
            'success' => true,
            'queued' => true,
            'job_uid' => $jobId,
            'issue_key' => $job->issue_key,
            'position' => $queuePosition,
            'message' => "Job queued for retry (position {$queuePosition})"
        ];
    }
}
