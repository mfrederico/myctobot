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
require_once __DIR__ . '/UserDatabaseService.php';
require_once __DIR__ . '/JiraClient.php';
require_once __DIR__ . '/AIDevStatusService.php';
require_once __DIR__ . '/ShardRouter.php';
require_once __DIR__ . '/ShopifyClient.php';
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
     * @return array Result with 'success', 'job_id', 'error' keys
     */
    public function triggerJob(int $memberId, string $issueKey, string $cloudId, ?int $boardId = null, ?int $repoId = null, bool $useOrchestrator = false): array {
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

            // Get member's database
            $db = $this->getMemberDb($memberId, $member);
            if (!$db) {
                return ['success' => false, 'error' => 'Member database not initialized'];
            }

            // Get Anthropic API key
            $apiKeyResult = $db->querySingle(
                "SELECT setting_value FROM enterprisesettings WHERE setting_key = 'anthropic_api_key'"
            );

            if (empty($apiKeyResult)) {
                return ['success' => false, 'error' => 'Anthropic API key not configured'];
            }

            $apiKey = EncryptionService::decrypt($apiKeyResult);

            // Auto-detect board ID from issue key if not provided
            if (!$boardId) {
                $projectKey = explode('-', $issueKey)[0];
                $boardId = $db->querySingle(
                    "SELECT id FROM jiraboards WHERE project_key = '" . $db->escapeString($projectKey) . "' LIMIT 1"
                );
                if (!$boardId) {
                    return ['success' => false, 'error' => "No board found for project: {$projectKey}"];
                }
            }

            // Auto-detect repo ID if not provided
            if (!$repoId) {
                $repoId = $db->querySingle("SELECT id FROM repoconnections WHERE enabled = 1 LIMIT 1");
                if (!$repoId) {
                    return ['success' => false, 'error' => 'No enabled repository connections'];
                }
            }

            // Check for existing active job (prevent duplicates)
            $existingJob = AIDevStatusService::findJobByIssueKey($memberId, $issueKey);
            if ($existingJob) {
                // Block if job is running or pending
                if (in_array($existingJob['status'], ['running', 'pending'])) {
                    return ['success' => false, 'error' => 'Job already running for this issue', 'job_id' => $existingJob['job_id']];
                }

                // Cooldown: if job completed/failed within last 2 minutes, skip
                // This prevents rapid re-triggering from webhook loops
                $lastUpdated = strtotime($existingJob['updated_at'] ?? $existingJob['created_at'] ?? '');
                $cooldownSeconds = 120; // 2 minutes
                if ($lastUpdated && (time() - $lastUpdated) < $cooldownSeconds) {
                    return ['success' => false, 'error' => 'Recent job exists, cooldown active', 'job_id' => $existingJob['job_id']];
                }
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
            $repoResult = $db->querySingle(
                "SELECT * FROM repoconnections WHERE id = " . (int)$repoId,
                true
            );

            if (!$repoResult) {
                return ['success' => false, 'error' => 'Repository not found'];
            }

            $repoToken = EncryptionService::decrypt($repoResult['access_token']);

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
                    'repo_owner' => $repoResult['repo_owner'],
                    'repo_name' => $repoResult['repo_name'],
                    'default_branch' => $repoResult['default_branch'] ?? 'main',
                    'clone_url' => $repoResult['clone_url']
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
        $db = $this->getMemberDb($memberId);
        $maxConcurrent = 3; // Default

        if ($db) {
            $setting = $db->querySingle(
                "SELECT setting_value FROM enterprisesettings WHERE setting_key = 'max_concurrent_aidev_jobs'"
            );
            if ($setting) {
                $maxConcurrent = (int)$setting;
            }
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
        $db = $this->getMemberDb($memberId);
        if ($db) {
            $botAccountId = $db->querySingle(
                "SELECT setting_value FROM enterprisesettings WHERE setting_key = 'jira_bot_account_id'"
            );
            if ($botAccountId && $accountId === $botAccountId) {
                return true;
            }
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

    /**
     * Get member's SQLite database
     */
    private function getMemberDb(int $memberId, $member = null): ?\SQLite3 {
        if (!$member) {
            $member = R::load('member', $memberId);
        }

        if (!$member || empty($member->ceobot_db)) {
            return null;
        }

        $dbPath = Flight::get('ceobot.user_db_path') ?? 'database/';
        $dbFile = $dbPath . $member->ceobot_db . '.sqlite';

        if (!file_exists($dbFile)) {
            return null;
        }

        return new \SQLite3($dbFile);
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
        $db = $this->getMemberDb($memberId);
        if (!$db) {
            return [];
        }

        $result = $db->querySingle(
            "SELECT aidev_status_working, aidev_status_pr_created, aidev_status_clarification, aidev_status_failed
             FROM jiraboards WHERE id = " . (int)$boardId,
            true
        );

        return $result ?: [];
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
                $db = $this->getMemberDb($memberId);
                if ($db) {
                    // Storefront password
                    $password = $db->querySingle(
                        "SELECT setting_value FROM enterprisesettings WHERE setting_key = 'shopify_storefront_password'"
                    );
                    if ($password) {
                        $settings['storefront_password'] = EncryptionService::decrypt($password);
                    }

                    // Playwright verification setting
                    $verifyEnabled = $db->querySingle(
                        "SELECT setting_value FROM enterprisesettings WHERE setting_key = 'shopify_verify_playwright'"
                    );
                    $settings['verify_with_playwright'] = ($verifyEnabled === '1' || $verifyEnabled === 'true');
                }
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
