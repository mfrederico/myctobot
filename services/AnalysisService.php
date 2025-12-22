<?php
/**
 * Unified Analysis Service
 *
 * Handles sprint analysis for both on-demand (web UI) and scheduled (cron) execution.
 * Supports all Pro/Enterprise features including image analysis.
 */

namespace app\services;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\plugins\AtlassianAuth;

require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';
require_once __DIR__ . '/UserDatabaseService.php';
require_once __DIR__ . '/JiraClient.php';
require_once __DIR__ . '/ClaudeClient.php';
require_once __DIR__ . '/AnalysisStatusService.php';
require_once __DIR__ . '/SubscriptionService.php';
require_once __DIR__ . '/TierFeatures.php';
require_once __DIR__ . '/MailgunService.php';
require_once __DIR__ . '/ShardService.php';
require_once __DIR__ . '/ShardRouter.php';
require_once __DIR__ . '/../analyzers/PriorityAnalyzer.php';
require_once __DIR__ . '/../analyzers/ClarityAnalyzer.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class AnalysisService {

    private $logger;
    private $verbose;
    private $memberId;
    private $member;
    private $userDb;

    public function __construct(int $memberId, bool $verbose = false) {
        $this->logger = Flight::get('log');
        $this->verbose = $verbose;
        $this->memberId = $memberId;

        // Load member
        $this->member = R::load('member', $memberId);
        if (!$this->member->id) {
            throw new \Exception("Member not found: {$memberId}");
        }

        // Initialize user database
        $this->userDb = new UserDatabaseService($memberId);
    }

    /**
     * Run analysis for a board
     *
     * @param int $boardId Board ID to analyze
     * @param array $options Options:
     *   - job_id: Job ID for progress tracking (optional)
     *   - status_filter: Override status filter (optional)
     *   - send_email: Send email after analysis (default: false)
     *   - analysis_type: Type for storage - 'priorities' or 'digest' (default: 'priorities')
     * @return array Result with analysis_id, success status, etc.
     */
    public function runAnalysis(int $boardId, array $options = []): array {
        $jobId = $options['job_id'] ?? null;
        $statusFilter = $options['status_filter'] ?? null;
        $sendEmail = $options['send_email'] ?? false;
        $analysisType = $options['analysis_type'] ?? 'priorities';

        try {
            // Update status: Starting
            $this->updateProgress($jobId, 'Initializing analysis...', 5);

            // Get board details
            $board = $this->userDb->getBoard($boardId);
            if (!$board) {
                $this->failJob($jobId, 'Board not found');
                throw new \Exception("Board not found: {$boardId}");
            }

            // Determine status filter
            if (!$statusFilter) {
                $statusFilter = $board['status_filter'] ?? 'To Do';
            }
            $statusArray = array_map('trim', explode(',', $statusFilter));

            $this->log("Board: {$board['board_name']} ({$board['project_key']})");
            $this->log("Status filter: {$statusFilter}");

            // Update status: Connecting to Jira
            $this->updateProgress($jobId, 'Connecting to Jira...', 10);

            // Initialize Jira client
            $jiraClient = new JiraClient($this->memberId, $board['cloud_id']);

            // Update status: Fetching issues
            $this->updateProgress($jobId, 'Fetching sprint issues...', 20);

            // Fetch sprint issues
            $issues = $jiraClient->getCurrentSprintIssues($board['project_key'], $statusArray);

            if (empty($issues)) {
                $this->failJob($jobId, 'No issues found in the current sprint');
                return [
                    'success' => false,
                    'error' => 'No issues found in the current sprint',
                    'board' => $board
                ];
            }

            $this->log("Found " . count($issues) . " issues");

            // Update status: AI Analysis starting
            $this->updateProgress($jobId, 'Starting AI analysis (' . count($issues) . ' issues)...', 30);

            // Check subscription tier for features
            $isPro = SubscriptionService::isPro($this->memberId);
            $includeImages = $isPro; // Image analysis is a Pro feature

            // Initialize Claude client and analyzers
            $claudeClient = new ClaudeClient();
            $priorityAnalyzer = new \app\analyzers\PriorityAnalyzer($claudeClient);

            // Pass JiraClient to ClarityAnalyzer for image fetching (Pro feature)
            $clarityAnalyzer = new \app\analyzers\ClarityAnalyzer(
                $claudeClient,
                $includeImages ? $jiraClient : null,
                $includeImages
            );

            // Load board weights and goals (Pro features)
            $weights = null;
            $goals = null;
            $clarityThreshold = 6;

            if ($isPro) {
                if (!empty($board['priority_weights'])) {
                    $weights = json_decode($board['priority_weights'], true);
                }
                if (!empty($board['goals'])) {
                    $goals = json_decode($board['goals'], true);
                    $clarityThreshold = $goals['clarity_threshold'] ?? 6;
                }
            }

            // Run clarity analysis with caching (Pro feature)
            $clarityResult = null;
            if ($isPro || SubscriptionService::canAccessFeature($this->memberId, TierFeatures::FEATURE_CLARITY_ANALYSIS)) {
                $imageNote = $includeImages ? ' (with image analysis)' : '';
                $this->updateProgress($jobId, 'Analyzing ticket clarity' . $imageNote . '...', 50);

                $clarityResult = $clarityAnalyzer->analyzeWithCache(
                    $issues,
                    $boardId,
                    $this->userDb,
                    $clarityThreshold
                );

                $this->log("Clarity analysis: {$clarityResult['analyzed_count']} analyzed, {$clarityResult['cached_count']} cached");
            }

            // Update status: Priority analysis
            $this->updateProgress($jobId, 'Generating priority recommendations...', 70);

            // Run priority analysis
            $priorityResult = $priorityAnalyzer->generateDailyPriorities(
                $issues,
                null, // estimations
                $clarityResult ? $clarityResult['clarifications_needed'] : null,
                null, // similarities
                $weights,
                $goals
            );

            if (!$priorityResult['success']) {
                $error = 'Priority analysis failed: ' . ($priorityResult['error'] ?? 'Unknown error');
                $this->failJob($jobId, $error);
                throw new \Exception($error);
            }

            // Add clarity results to the priority result
            if ($clarityResult) {
                $priorityResult['analysis']['clarifications_needed'] = $clarityResult['clarifications_needed'];
                $priorityResult['clarity_stats'] = [
                    'analyzed_count' => $clarityResult['analyzed_count'],
                    'cached_count' => $clarityResult['cached_count'],
                    'clarification_count' => count($clarityResult['clarifications_needed'])
                ];
            }

            // Update status: Generating report
            $this->updateProgress($jobId, 'Generating report...', 85);

            // Get Jira site URL for creating ticket links
            $jiraSiteUrl = AtlassianAuth::getSiteUrl($this->memberId, $board['cloud_id']);

            // Generate markdown report with Jira links
            $markdown = $priorityAnalyzer->generateDailyLog($priorityResult, $jiraSiteUrl);

            // Append clarification section if there are items
            if ($clarityResult && !empty($clarityResult['clarifications_needed'])) {
                $markdown .= $this->generateClarificationMarkdown($clarityResult['clarifications_needed'], $jiraSiteUrl);
            }

            // Update status: Saving results
            $this->updateProgress($jobId, 'Saving analysis results...', 90);

            // Store results
            $priorityResult['status_filter'] = $statusFilter;
            $analysisId = $this->userDb->storeAnalysis($boardId, $analysisType, $priorityResult, $markdown);

            $this->logger->info('Analysis completed', [
                'member_id' => $this->memberId,
                'board_id' => $boardId,
                'analysis_id' => $analysisId,
                'issue_count' => count($issues),
                'job_id' => $jobId,
                'send_email' => $sendEmail
            ]);

            // Send email if requested
            $emailSent = false;
            if ($sendEmail) {
                $this->updateProgress($jobId, 'Sending email...', 95);
                $emailSent = $this->sendDigestEmail($board, $markdown);
            }

            // Update status: Complete
            if ($jobId) {
                AnalysisStatusService::complete($this->memberId, $jobId, $analysisId);
            }

            $this->log("Analysis complete! ID: {$analysisId}" . ($emailSent ? " (email sent)" : ""));

            return [
                'success' => true,
                'analysis_id' => $analysisId,
                'board' => $board,
                'issue_count' => count($issues),
                'email_sent' => $emailSent,
                'clarity_stats' => $priorityResult['clarity_stats'] ?? null
            ];

        } catch (\Exception $e) {
            $this->logger->error('Analysis failed', [
                'member_id' => $this->memberId,
                'board_id' => $boardId,
                'error' => $e->getMessage()
            ]);

            if ($jobId) {
                AnalysisStatusService::fail($this->memberId, $jobId, $e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Send digest email for a board analysis
     */
    private function sendDigestEmail(array $board, string $markdown): bool {
        $mailgun = new MailgunService();

        if (!$mailgun->isEnabled()) {
            $this->logger->warning('Mailgun not configured, skipping email');
            return false;
        }

        $subject = "[{$board['project_key']}] Daily Sprint Digest - " . date('Y-m-d');
        $ccEmails = !empty($board['digest_cc']) ? $board['digest_cc'] : null;

        $success = $mailgun->sendMarkdownEmail($subject, $markdown, $this->member->email, $ccEmails);

        if ($success) {
            // Log successful digest
            $this->userDb->logDigest(
                $board['id'],
                $this->member->email,
                $subject,
                substr($markdown, 0, 500),
                'sent'
            );
            $this->log("Email sent to {$this->member->email}");
        } else {
            $this->logger->error('Failed to send digest email', [
                'member_id' => $this->memberId,
                'board_id' => $board['id']
            ]);
        }

        return $success;
    }

    /**
     * Generate markdown section for clarification items
     */
    private function generateClarificationMarkdown(array $clarifications, ?string $jiraSiteUrl = null): string {
        if (empty($clarifications)) {
            return '';
        }

        $md = "\n\n## Tickets Needing Clarification\n\n";
        $md .= "The following tickets have low clarity scores and may need stakeholder input before work can begin.\n\n";

        foreach ($clarifications as $item) {
            $ticketLink = $jiraSiteUrl
                ? "[{$item['key']}](" . rtrim($jiraSiteUrl, '/') . '/browse/' . $item['key'] . ")"
                : $item['key'];

            $scoreClass = $item['clarity_score'] < 4 ? '🔴' : ($item['clarity_score'] < 6 ? '🟡' : '🟢');

            $md .= "### {$scoreClass} {$ticketLink} - {$item['summary']}\n\n";
            $md .= "| Attribute | Value |\n";
            $md .= "|-----------|-------|\n";
            $md .= "| Clarity Score | **{$item['clarity_score']}/10** |\n";
            $md .= "| Reporter | {$item['reporter_name']} |\n";
            if (!empty($item['reporter_email'])) {
                $md .= "| Email | {$item['reporter_email']} |\n";
            }
            $md .= "| Type | {$item['type']} |\n";
            $md .= "| Priority | {$item['priority']} |\n\n";

            if (!empty($item['assessment'])) {
                $md .= "**Assessment**: {$item['assessment']}\n\n";
            }

            if (!empty($item['missing_elements'])) {
                $md .= "**Missing Elements**:\n";
                foreach ($item['missing_elements'] as $element) {
                    $md .= "- {$element}\n";
                }
                $md .= "\n";
            }

            if (!empty($item['suggested_questions'])) {
                $md .= "**Suggested Questions for Stakeholder**:\n";
                foreach ($item['suggested_questions'] as $question) {
                    $md .= "- {$question}\n";
                }
                $md .= "\n";
            }

            $md .= "---\n\n";
        }

        return $md;
    }

    /**
     * Update job progress
     */
    private function updateProgress(?string $jobId, string $message, int $progress): void {
        if ($jobId) {
            AnalysisStatusService::updateStatus($this->memberId, $jobId, $message, $progress, 'running');
        }
        $this->log("[{$progress}%] {$message}");
    }

    /**
     * Fail a job
     */
    private function failJob(?string $jobId, string $error): void {
        if ($jobId) {
            AnalysisStatusService::fail($this->memberId, $jobId, $error);
        }
        $this->log("FAILED: {$error}");
    }

    /**
     * Log message (verbose mode)
     */
    private function log(string $message): void {
        if ($this->verbose) {
            echo "[" . date('Y-m-d H:i:s') . "] {$message}\n";
        }
    }

    /**
     * Get the user database service
     */
    public function getUserDb(): UserDatabaseService {
        return $this->userDb;
    }

    /**
     * Get member info
     */
    public function getMember() {
        return $this->member;
    }

    /**
     * Run analysis on a shard (Enterprise tier)
     *
     * Delegates digest analysis to a remote Claude Code shard.
     * The shard uses MCP servers to fetch Jira data and generate the digest.
     *
     * @param int $boardId Board ID to analyze
     * @param array $options Options:
     *   - send_email: Send email after analysis (default: false)
     *   - anthropic_api_key: Override Anthropic API key (optional)
     *   - use_jira_mcp: Let shard fetch from Jira via MCP (default: true)
     *   - issues: Pre-fetched issues if not using Jira MCP
     * @return array Result with job_id, shard info, etc.
     */
    public function runShardAnalysis(int $boardId, array $options = []): array {
        $sendEmail = $options['send_email'] ?? false;
        $useJiraMcp = $options['use_jira_mcp'] ?? true;

        // Get board details
        $board = $this->userDb->getBoard($boardId);
        if (!$board) {
            throw new \Exception("Board not found: {$boardId}");
        }

        // Check subscription tier - shard analysis requires Enterprise
        if (!SubscriptionService::canAccessFeature($this->memberId, TierFeatures::FEATURE_AI_DEVELOPER)) {
            throw new \Exception("Shard analysis requires Enterprise tier");
        }

        // Get Anthropic API key - from member or options
        $anthropicApiKey = $options['anthropic_api_key'] ?? $this->getMemberAnthropicKey();
        if (empty($anthropicApiKey)) {
            throw new \Exception("Anthropic API key not configured");
        }

        // Get MCP credentials (Jira/GitHub)
        $credentials = ShardRouter::getMemberMcpCredentials($this->memberId, $board['cloud_id']);

        // Find an available shard with 'jira' capability if using Jira MCP
        $requiredCapabilities = $useJiraMcp ? ['jira'] : [];
        $shard = ShardRouter::findAvailableShard($this->memberId, $requiredCapabilities);

        if (!$shard) {
            throw new \Exception("No available shards found");
        }

        // Generate unique job ID
        $jobId = bin2hex(random_bytes(16));

        // Create digest job record via member association
        // Uses RedBeanPHP associations: member->ownDigestjobsList
        $digestJob = R::dispense('digestjobs');
        $digestJob->job_id = $jobId;
        $digestJob->board_id = $boardId;  // Cross-DB reference to user's SQLite
        $digestJob->shard_id = $shard['id'];  // External shard ID
        $digestJob->status = 'queued';
        $digestJob->send_email = $sendEmail ? 1 : 0;
        $digestJob->board_name = $board['board_name'];
        $digestJob->project_key = $board['project_key'];
        $digestJob->digest_cc = $board['digest_cc'] ?? null;
        $digestJob->created_at = date('Y-m-d H:i:s');

        // Add to member's ownDigestjobsList (sets member_id automatically)
        $this->member->ownDigestjobsList[] = $digestJob;
        R::store($this->member);

        $this->log("Created digest job {$jobId} for board {$board['board_name']}");

        // Build callback URL and get API key for webhook auth
        $baseUrl = Flight::get('app.baseurl') ?? 'https://myctobot.ai';
        $callbackUrl = rtrim($baseUrl, '/') . '/webhook/digest';
        $webhookApiKey = Flight::get('cron.api_key');

        // Prepare board info for shard
        $boardInfo = [
            'board_id' => $boardId,
            'board_name' => $board['board_name'],
            'project_key' => $board['project_key'],
            'status_filter' => $board['status_filter'] ?? 'To Do',
            'cloud_id' => $board['cloud_id']
        ];

        // Get Jira site URL for ticket links
        $jiraSiteUrl = AtlassianAuth::getSiteUrl($this->memberId, $board['cloud_id']);
        if ($jiraSiteUrl) {
            $boardInfo['jira_site_url'] = $jiraSiteUrl;
        }

        // Build request payload
        $payload = [
            'job_id' => $jobId,
            'anthropic_api_key' => $anthropicApiKey,
            'board' => $boardInfo,
            'options' => [
                'use_jira_mcp' => $useJiraMcp,
                'include_clarity' => true,
                'clarity_threshold' => $board['goals'] ? (json_decode($board['goals'], true)['clarity_threshold'] ?? 6) : 6
            ],
            'callback_url' => $callbackUrl,
            'callback_api_key' => $webhookApiKey
        ];

        // Add credentials if using Jira MCP
        if ($useJiraMcp && !empty($credentials)) {
            $payload['jira_host'] = $credentials['jira_host'] ?? '';
            $payload['jira_email'] = $credentials['jira_email'] ?? '';
            $payload['jira_api_token'] = $credentials['jira_api_token'] ?? '';
            // Site URL for ticket links in reports (different from API gateway URL)
            $payload['jira_site_url'] = $credentials['jira_site_url'] ?? $jiraSiteUrl ?? '';
        }

        // If not using Jira MCP, fetch issues locally and pass them
        if (!$useJiraMcp || !empty($options['issues'])) {
            if (!empty($options['issues'])) {
                $payload['issues'] = $options['issues'];
            } else {
                // Fetch issues locally
                $statusFilter = $board['status_filter'] ?? 'To Do';
                $statusArray = array_map('trim', explode(',', $statusFilter));
                $jiraClient = new JiraClient($this->memberId, $board['cloud_id']);
                $issues = $jiraClient->getCurrentSprintIssues($board['project_key'], $statusArray);
                $payload['issues'] = $issues;
            }
        }

        // Call shard /analysis/sharddigest endpoint (unified PHP codebase)
        $shardPort = $shard['port'];
        // Use HTTPS only for port 443 (or if shard has ssl flag set)
        // Port 8443 uses HTTP until SSL certificate is configured
        $shardProtocol = ($shardPort == 443 || !empty($shard['ssl'])) ? 'https' : 'http';
        try {
            $client = new Client([
                'base_uri' => "{$shardProtocol}://{$shard['host']}:{$shardPort}",
                'verify' => false, // Allow self-signed certs for now
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . ($shard['api_key'] ?? ''),
                    'Content-Type' => 'application/json'
                ]
            ]);

            $response = $client->post('/analysis/sharddigest', [
                'json' => $payload
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            // Update job status
            $digestJob->status = 'running';
            $digestJob->started_at = date('Y-m-d H:i:s');
            R::store($digestJob);

            $this->log("Shard analysis started on {$shard['name']}");

            $this->logger->info('Shard digest analysis started', [
                'member_id' => $this->memberId,
                'board_id' => $boardId,
                'job_id' => $jobId,
                'shard_id' => $shard['id'],
                'shard_name' => $shard['name']
            ]);

            return [
                'success' => true,
                'job_id' => $jobId,
                'shard_id' => $shard['id'],
                'shard_name' => $shard['name'],
                'board' => $board,
                'status' => 'running',
                'message' => "Digest analysis started on shard '{$shard['name']}'"
            ];

        } catch (GuzzleException $e) {
            // Update job as failed
            $digestJob->status = 'failed';
            $digestJob->error = $e->getMessage();
            $digestJob->completed_at = date('Y-m-d H:i:s');
            R::store($digestJob);

            $this->logger->error('Failed to start shard analysis', [
                'member_id' => $this->memberId,
                'board_id' => $boardId,
                'shard_id' => $shard['id'],
                'error' => $e->getMessage()
            ]);

            throw new \Exception("Failed to start shard analysis: " . $e->getMessage());
        }
    }

    /**
     * Get digest job status
     *
     * @param string $jobId Job ID
     * @return array|null Job status or null if not found
     */
    public function getDigestJobStatus(string $jobId): ?array {
        $job = R::findOne('digestjobs', 'job_id = ? AND member_id = ?', [$jobId, $this->memberId]);

        if (!$job) {
            return null;
        }

        return [
            'job_id' => $job->job_id,
            'status' => $job->status,
            'board_id' => $job->board_id,
            'board_name' => $job->board_name,
            'project_key' => $job->project_key,
            'shard_id' => $job->shard_id,
            'created_at' => $job->created_at,
            'started_at' => $job->started_at,
            'completed_at' => $job->completed_at,
            'error' => $job->error,
            'send_email' => (bool) $job->send_email
        ];
    }

    /**
     * Get member's Anthropic API key
     */
    private function getMemberAnthropicKey(): ?string {
        // Check member's stored API key
        if (!empty($this->member->anthropic_api_key)) {
            return $this->member->anthropic_api_key;
        }

        // Check member's enterprise settings (key-value pattern)
        // Note: This requires connecting to the user's database first
        $setting = R::findOne('enterpriseSetting', 'setting_key = ?', ['anthropic_api_key']);
        if ($setting && !empty($setting->settingValue)) {
            return $setting->settingValue;
        }

        // Fall back to system key (if configured)
        return Flight::get('anthropic.api_key');
    }
}
