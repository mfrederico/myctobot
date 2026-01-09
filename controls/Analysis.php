<?php
/**
 * Analysis Controller
 * Run and view Jira sprint analyses
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\plugins\AtlassianAuth;
use \app\services\UserDatabaseService;
use \app\services\JiraClient;
use \app\services\ClaudeClient;
use \app\services\MailgunService;
use \app\services\AnalysisStatusService;
use \app\analyzers\PriorityAnalyzer;
use \app\analyzers\ClarityAnalyzer;

require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';
require_once __DIR__ . '/../services/UserDatabaseService.php';
require_once __DIR__ . '/../services/JiraClient.php';
require_once __DIR__ . '/../services/ClaudeClient.php';
require_once __DIR__ . '/../services/MailgunService.php';
require_once __DIR__ . '/../services/AnalysisStatusService.php';
require_once __DIR__ . '/../services/AnalysisService.php';
require_once __DIR__ . '/../analyzers/PriorityAnalyzer.php';
require_once __DIR__ . '/../analyzers/ClarityAnalyzer.php';
require_once __DIR__ . '/../services/ShopifyClient.php';
require_once __DIR__ . '/../services/AIDevAgentOrchestrator.php';

use \app\services\AnalysisService;
use \app\services\ShopifyClient;

class Analysis extends BaseControls\Control {

    private $userDbConnected = false;

    private function initUserDb() {
        if (!$this->userDbConnected && $this->member && !empty($this->member->ceobot_db)) {
            try {
                UserDatabaseService::connect($this->member->id);
                $this->userDbConnected = true;
            } catch (Exception $e) {
                $this->logger->error('Failed to initialize user database: ' . $e->getMessage());
                return false;
            }
        }
        return $this->userDbConnected;
    }

    /**
     * Analysis dashboard - list boards and recent analyses
     */
    public function index() {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->flash('error', 'User database not initialized');
            Flight::redirect('/settings/connections');
            return;
        }

        $boards = UserDatabaseService::getEnabledBoards();
        $recentAnalyses = UserDatabaseService::getAllRecentAnalyses(20);

        $this->render('analysis/index', [
            'title' => 'Sprint Analysis',
            'boards' => $boards,
            'recentAnalyses' => $recentAnalyses
        ]);
    }

    /**
     * Run analysis on a specific board (triggers background job)
     */
    public function run($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->jsonError('User database not initialized');
            return;
        }

        // Board ID comes from URL: /analysis/run/{board_id}
        $boardId = $params['operation']->name ?? $this->getParam('board_id');
        if (!$boardId) {
            $this->flash('error', 'No board specified');
            Flight::redirect('/analysis');
            return;
        }

        $board = UserDatabaseService::getBoard($boardId);
        if (!$board) {
            $this->flash('error', 'Board not found');
            Flight::redirect('/analysis');
            return;
        }

        // Show run form on GET
        $request = Flight::request();
        if ($request->method === 'GET') {
            // Check for any active jobs for this board
            $activeJobs = AnalysisStatusService::getActiveJobs($this->member->id);
            $boardActiveJob = null;
            foreach ($activeJobs as $job) {
                if ($job['board_id'] == $boardId) {
                    $boardActiveJob = $job;
                    break;
                }
            }

            $this->render('analysis/run', [
                'title' => 'Run Analysis - ' . $board['board_name'],
                'board' => $board,
                'activeJob' => $boardActiveJob
            ]);
            return;
        }

        // Run analysis on POST - trigger background job
        try {
            // Get status filter from form or board defaults
            $statusFilter = $this->getParam('status_filter') ?? $board['status_filter'] ?? 'To Do';

            // Create a job for status tracking
            $jobId = AnalysisStatusService::createJob(
                $this->member->id,
                (int)$boardId,
                $board['board_name']
            );

            // Build the command to run in background
            $scriptPath = realpath(__DIR__ . '/../scripts/cron-analysis.php');
            $cronSecret = Flight::get('cron.api_key');

            // Get tenant slug for multi-tenancy support
            $tenantSlug = $_SESSION['tenant_slug'] ?? null;
            $tenantParam = $tenantSlug && $tenantSlug !== 'default'
                ? sprintf(' --tenant=%s', escapeshellarg($tenantSlug))
                : '';

            $cmd = sprintf(
                'php %s --script --secret=%s --member=%d --board=%d --job=%s --status-filter=%s%s > /dev/null 2>&1 &',
                escapeshellarg($scriptPath),
                escapeshellarg($cronSecret),
                $this->member->id,
                (int)$boardId,
                escapeshellarg($jobId),
                escapeshellarg($statusFilter),
                $tenantParam
            );

            // Execute in background
            exec($cmd);

            $this->logger->info('Background analysis started', [
                'member_id' => $this->member->id,
                'board_id' => $boardId,
                'job_id' => $jobId,
                'tenant' => $tenantSlug ?? 'default'
            ]);

            // Redirect to progress page
            Flight::redirect('/analysis/progress/' . urlencode($jobId));

        } catch (Exception $e) {
            $this->logger->error('Failed to start analysis: ' . $e->getMessage());
            $this->flash('error', 'Failed to start analysis: ' . $e->getMessage());
            Flight::redirect('/analysis/run/' . $boardId);
        }
    }

    /**
     * Show analysis progress page
     */
    public function progress($params = []) {
        if (!$this->requireLogin()) return;

        // Job ID comes from URL: /analysis/progress/{job_id}
        $jobId = $params['operation']->name ?? $this->getParam('job_id');
        if (!$jobId) {
            $this->flash('error', 'No job specified');
            Flight::redirect('/analysis');
            return;
        }

        // Get status with ownership verification
        $status = AnalysisStatusService::getStatus($jobId, $this->member->id);
        if (!$status) {
            $this->flash('error', 'Job not found or access denied');
            Flight::redirect('/analysis');
            return;
        }

        // If complete, redirect to the analysis view
        if ($status['status'] === 'complete' && !empty($status['analysis_id'])) {
            $this->flash('success', 'Analysis completed successfully!');
            Flight::redirect('/analysis/view/' . $status['analysis_id']);
            return;
        }

        // If failed, show error and redirect
        if ($status['status'] === 'failed') {
            $this->flash('error', 'Analysis failed: ' . ($status['error'] ?? 'Unknown error'));
            Flight::redirect('/analysis');
            return;
        }

        $this->render('analysis/progress', [
            'title' => 'Analysis in Progress',
            'jobId' => $jobId,
            'status' => $status
        ]);
    }

    /**
     * Get analysis job status (AJAX endpoint)
     */
    public function status($params = []) {
        if (!$this->requireLogin()) return;

        // Job ID comes from URL: /analysis/status/{job_id}
        $jobId = $params['operation']->name ?? $this->getParam('job_id');
        if (!$jobId) {
            $this->jsonError('No job specified');
            return;
        }

        // Get status with ownership verification
        $status = AnalysisStatusService::getStatus($jobId, $this->member->id);
        if (!$status) {
            $this->jsonError('Job not found or access denied');
            return;
        }

        $this->jsonSuccess($status);
    }

    /**
     * View analysis results
     */
    public function view($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->flash('error', 'User database not initialized');
            Flight::redirect('/settings/connections');
            return;
        }

        // Analysis ID comes from URL: /analysis/view/{id}
        $analysisId = $params['operation']->name ?? $this->getParam('id');
        if (!$analysisId) {
            $this->flash('error', 'No analysis specified');
            Flight::redirect('/analysis');
            return;
        }

        $analysis = UserDatabaseService::getAnalysis($analysisId);
        if (!$analysis) {
            $this->flash('error', 'Analysis not found');
            Flight::redirect('/analysis');
            return;
        }

        // Get board info
        $board = UserDatabaseService::getBoard($analysis['board_id']);

        // Convert markdown to HTML for the view
        $markdownHtml = $this->markdownToHtml($analysis['content_markdown'] ?? '');

        $this->render('analysis/view', [
            'title' => 'Analysis Results',
            'analysis' => $analysis,
            'board' => $board,
            'markdown' => $analysis['content_markdown'],
            'markdownHtml' => $markdownHtml,
            'data' => $analysis['content']
        ]);
    }

    /**
     * Email analysis results
     */
    public function email($params = []) {
        if (!$this->requireLogin()) return;

        $isAjax = Flight::request()->ajax;

        if (!$this->initUserDb()) {
            if ($isAjax) {
                $this->jsonError('User database not initialized');
            } else {
                $this->flash('error', 'User database not initialized');
                Flight::redirect('/analysis');
            }
            return;
        }

        // Analysis ID comes from URL: /analysis/email/{id}
        $analysisId = $params['operation']->name ?? $this->getParam('id');
        if (!$analysisId) {
            if ($isAjax) {
                $this->jsonError('No analysis specified');
            } else {
                $this->flash('error', 'No analysis specified');
                Flight::redirect('/analysis');
            }
            return;
        }

        $analysis = UserDatabaseService::getAnalysis($analysisId);
        if (!$analysis) {
            if ($isAjax) {
                $this->jsonError('Analysis not found');
            } else {
                $this->flash('error', 'Analysis not found');
                Flight::redirect('/analysis');
            }
            return;
        }

        $board = UserDatabaseService::getBoard($analysis['board_id']);
        $email = $this->getParam('email') ?? $this->member->email;
        $ccEmails = !empty($board['digest_cc']) ? $board['digest_cc'] : null;

        try {
            $mailgun = new MailgunService();
            if (!$mailgun->isEnabled()) {
                throw new Exception('Email service not configured');
            }

            $subject = "[{$board['project_key']}] Daily Priority Report - " . date('Y-m-d');
            $success = $mailgun->sendMarkdownEmail($subject, $analysis['content_markdown'], $email, $ccEmails);

            if ($success) {
                // Log the email
                $recipients = $email . ($ccEmails ? ", {$ccEmails}" : '');
                UserDatabaseService::logDigest(
                    $analysis['board_id'],
                    $recipients,
                    $subject,
                    substr($analysis['content_markdown'], 0, 500),
                    'sent'
                );

                if ($isAjax) {
                    $this->jsonSuccess(['sent_to' => $email, 'cc' => $ccEmails], 'Analysis emailed successfully');
                } else {
                    $ccMsg = $ccEmails ? " (CC: {$ccEmails})" : '';
                    $this->flash('success', "Analysis emailed to {$email}{$ccMsg}");
                    Flight::redirect('/analysis/view/' . $analysisId);
                }
            } else {
                throw new Exception('Failed to send email');
            }

        } catch (Exception $e) {
            $this->logger->error('Email failed: ' . $e->getMessage());
            if ($isAjax) {
                $this->jsonError('Failed to send email: ' . $e->getMessage());
            } else {
                $this->flash('error', 'Failed to send email: ' . $e->getMessage());
                Flight::redirect('/analysis/view/' . $analysisId);
            }
        }
    }

    /**
     * Get analysis history for a board (AJAX)
     */
    public function history() {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->jsonError('User database not initialized');
            return;
        }

        $boardId = $this->getParam('board_id');
        if (!$boardId) {
            $this->jsonError('No board specified');
            return;
        }

        $limit = (int) ($this->getParam('limit') ?? 10);
        $analyses = UserDatabaseService::getRecentAnalyses($boardId, $limit);

        $this->jsonSuccess(['analyses' => $analyses]);
    }

    /**
     * Run analysis on a shard (Enterprise feature)
     * GET: Show the run form
     * POST: Trigger shard analysis
     */
    public function runshard($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->jsonError('User database not initialized');
            return;
        }

        // Board ID comes from URL: /analysis/runshard/{board_id}
        $boardId = $params['operation']->name ?? $this->getParam('board_id');
        if (!$boardId) {
            $this->flash('error', 'No board specified');
            Flight::redirect('/analysis');
            return;
        }

        $board = UserDatabaseService::getBoard($boardId);
        if (!$board) {
            $this->flash('error', 'Board not found');
            Flight::redirect('/analysis');
            return;
        }

        // Show run form on GET
        $request = Flight::request();
        if ($request->method === 'GET') {
            // Check for any active shard jobs for this board
            $activeJob = R::findOne('digestjobs',
                'member_id = ? AND board_id = ? AND status IN (?, ?)',
                [$this->member->id, $boardId, 'queued', 'running']
            );

            $this->render('analysis/runshard', [
                'title' => 'Run Shard Analysis - ' . $board['board_name'],
                'board' => $board,
                'activeJob' => $activeJob ? [
                    'job_id' => $activeJob->job_id,
                    'status' => $activeJob->status,
                    'created_at' => $activeJob->created_at
                ] : null
            ]);
            return;
        }

        // Run shard analysis on POST
        try {
            $sendEmail = $this->getParam('send_email') === '1';
            $useJiraMcp = $this->getParam('use_jira_mcp') !== '0'; // Default true

            $analysisService = new AnalysisService($this->member->id);
            $result = $analysisService->runShardAnalysis($boardId, [
                'send_email' => $sendEmail,
                'use_jira_mcp' => $useJiraMcp
            ]);

            $this->logger->info('Shard analysis started', [
                'member_id' => $this->member->id,
                'board_id' => $boardId,
                'job_id' => $result['job_id'],
                'shard' => $result['shard_name']
            ]);

            // Redirect to shard progress page
            Flight::redirect('/analysis/shardprogress/' . urlencode($result['job_id']));

        } catch (Exception $e) {
            $this->logger->error('Failed to start shard analysis: ' . $e->getMessage());
            $this->flash('error', 'Failed to start shard analysis: ' . $e->getMessage());
            Flight::redirect('/analysis/runshard/' . $boardId);
        }
    }

    /**
     * Show shard analysis progress page
     */
    public function shardprogress($params = []) {
        if (!$this->requireLogin()) return;

        // Job ID comes from URL: /analysis/shardprogress/{job_id}
        $jobId = $params['operation']->name ?? $this->getParam('job_id');
        if (!$jobId) {
            $this->flash('error', 'No job specified');
            Flight::redirect('/analysis');
            return;
        }

        // Get job with ownership verification
        $job = R::findOne('digestjobs', 'job_id = ? AND member_id = ?', [$jobId, $this->member->id]);
        if (!$job) {
            $this->flash('error', 'Job not found or access denied');
            Flight::redirect('/analysis');
            return;
        }

        // If complete, find the analysis and redirect
        if ($job->status === 'completed') {
            if (!$this->initUserDb()) {
                $this->flash('error', 'User database not initialized');
                Flight::redirect('/analysis');
                return;
            }

            // Find the most recent analysis for this board
            $analysis = UserDatabaseService::getRecentAnalyses($job->board_id, 1);
            if (!empty($analysis)) {
                $this->flash('success', 'Shard analysis completed successfully!');
                Flight::redirect('/analysis/view/' . $analysis[0]['id']);
                return;
            }
        }

        // If failed, show error
        if ($job->status === 'failed') {
            $this->flash('error', 'Shard analysis failed: ' . ($job->error ?? 'Unknown error'));
            Flight::redirect('/analysis');
            return;
        }

        $this->render('analysis/shardprogress', [
            'title' => 'Shard Analysis in Progress',
            'jobId' => $jobId,
            'job' => [
                'job_id' => $job->job_id,
                'status' => $job->status,
                'board_name' => $job->board_name,
                'project_key' => $job->project_key,
                'shard_id' => $job->shard_id,
                'created_at' => $job->created_at,
                'started_at' => $job->started_at
            ]
        ]);
    }

    /**
     * Get shard job status (AJAX endpoint)
     */
    public function shardstatus($params = []) {
        if (!$this->requireLogin()) return;

        // Job ID comes from URL: /analysis/shardstatus/{job_id}
        $jobId = $params['operation']->name ?? $this->getParam('job_id');
        if (!$jobId) {
            $this->jsonError('No job specified');
            return;
        }

        // Get job with ownership verification
        $job = R::findOne('digestjobs', 'job_id = ? AND member_id = ?', [$jobId, $this->member->id]);
        if (!$job) {
            $this->jsonError('Job not found or access denied');
            return;
        }

        $response = [
            'job_id' => $job->job_id,
            'status' => $job->status,
            'board_id' => $job->board_id,
            'board_name' => $job->board_name,
            'project_key' => $job->project_key,
            'shard_id' => $job->shard_id,
            'items_count' => (int) ($job->items_count ?? 0),
            'phase' => $job->phase ?? 'connecting',
            'elapsed_seconds' => (int) ($job->elapsed_seconds ?? 0),
            'created_at' => $job->created_at,
            'started_at' => $job->started_at,
            'completed_at' => $job->completed_at,
            'updated' => $job->updated,
            'error' => $job->error
        ];

        // If completed, include analysis_id if we can find it
        if ($job->status === 'completed' && $this->initUserDb()) {
            $analysis = UserDatabaseService::getRecentAnalyses($job->board_id, 1);
            if (!empty($analysis)) {
                $response['analysis_id'] = $analysis[0]['id'];
            }
        }

        $this->jsonSuccess($response);
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
     * Shard digest analysis endpoint
     * POST /analysis/sharddigest
     *
     * Receives all data in the request payload (no database lookup).
     * This is the unified endpoint that works on both main server and shards.
     * When called on a shard, it spawns Claude Code locally and sends callback.
     */
    public function sharddigest() {
        // Only accept POST
        if (Flight::request()->method !== 'POST') {
            Flight::json(['error' => 'POST required'], 405);
            return;
        }

        $input = Flight::request()->data->getData();

        // If no JSON body, try raw input
        if (empty($input)) {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
        }

        // Validate required fields
        if (empty($input['anthropic_api_key'])) {
            Flight::json(['error' => 'anthropic_api_key is required'], 400);
            return;
        }

        // Extract data from request
        $apiKey = $input['anthropic_api_key'];
        $board = $input['board'] ?? [];
        $jiraHost = $input['jira_host'] ?? '';
        $jiraEmail = $input['jira_email'] ?? '';
        $jiraToken = $input['jira_api_token'] ?? '';
        $jiraSiteUrl = $input['jira_site_url'] ?? '';
        $callbackUrl = $input['callback_url'] ?? '';
        $callbackApiKey = $input['callback_api_key'] ?? '';
        $jobId = $input['job_id'] ?? uniqid('shard_', true);
        $options = $input['options'] ?? [];

        // Ensure options is an array
        if (!is_array($options)) {
            $options = [];
        }

        // Build the prompt
        $projectKey = $board['project_key'] ?? 'UNKNOWN';
        $boardName = $board['board_name'] ?? 'Sprint Board';
        $statusFilter = $board['status_filter'] ?? 'To Do';
        $clarityThreshold = $options['clarity_threshold'] ?? 6;

        $prompt = $this->buildShardPrompt(
            $jiraHost, $jiraSiteUrl, $projectKey, $boardName, $statusFilter, $clarityThreshold
        );

        // Return accepted immediately and continue processing in background
        $response = json_encode([
            'success' => true,
            'job_id' => $jobId,
            'status' => 'running',
            'message' => 'Digest analysis started (PHP shard)'
        ]);

        Flight::response()->status(202);
        Flight::response()->header('Content-Type', 'application/json');
        Flight::response()->write($response);
        Flight::response()->send();

        // Flush and continue in background
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            if (ob_get_level()) {
                ob_end_flush();
            }
            flush();
        }

        // Run Claude Code analysis
        $this->runClaudeShardAnalysis(
            $jobId, $apiKey, $prompt,
            $jiraHost, $jiraEmail, $jiraToken,
            $callbackUrl, $callbackApiKey
        );

        // Prevent Flight from trying to send another response and cleanly exit
        Flight::stop();
        exit(0);
    }

    /**
     * Build the shard digest analysis prompt
     */
    private function buildShardPrompt($jiraHost, $jiraSiteUrl, $projectKey, $boardName, $statusFilter, $clarityThreshold) {
        $statuses = array_map(function($s) { return '"' . trim($s) . '"'; }, explode(',', $statusFilter));
        $statusList = implode(', ', $statuses);
        $siteUrlNote = $jiraSiteUrl ? "- Site URL for ticket links: {$jiraSiteUrl}" : '';
        $linkNote = $jiraSiteUrl ? "5. Use {$jiraSiteUrl}/browse/{key} format for ticket links in your markdown report" : '';

        return <<<PROMPT
You are a sprint analysis assistant. You have access to Jira via REST API.

## Jira API Access (Atlassian Cloud OAuth)
Use curl with the Bearer token to access Jira REST API.
- API Base URL: {$jiraHost}
- Auth Header: Authorization: Bearer \$JIRA_API_TOKEN
{$siteUrlNote}

Example commands:
```bash
# Search for issues (includes attachment metadata)
curl -s -H "Authorization: Bearer \$JIRA_API_TOKEN" "{$jiraHost}/rest/api/3/search?jql=project={$projectKey}+AND+statusCategory!=Done&maxResults=50&fields=*all"

# Get specific issue with full details including attachments
curl -s -H "Authorization: Bearer \$JIRA_API_TOKEN" "{$jiraHost}/rest/api/3/issue/{$projectKey}-123?expand=attachment"

# Download an attachment (use the 'content' URL from the attachment object)
curl -s -H "Authorization: Bearer \$JIRA_API_TOKEN" -o /tmp/attachment.png "{attachment_content_url}"
```

## Handling Images and Attachments
When you find issues with attachments:
1. Look for the "attachment" field in the issue response
2. Each attachment has: filename, mimeType, content (URL), thumbnail
3. For images (mimeType starts with "image/"):
   - Download using curl with the Bearer token
   - View the image using the Read tool
   - Describe what the image shows
4. Include image descriptions in your analysis

## Your Task:
1. Use the Jira REST API to search for issues in project "{$projectKey}"
   - First try JQL: project = {$projectKey} AND status in ({$statusList})
   - If no results, try: project = {$projectKey} AND statusCategory != Done
2. For issues with attachments (especially images), download and analyze them
3. Analyze the issues found and provide:
   - Priority ranking with reasoning
   - Top 3 tickets to focus on today
   - Risk assessment
   - Clarity analysis (flag tickets with clarity score < {$clarityThreshold})
   - Note any insights from images/attachments
4. Generate a professional markdown report
{$linkNote}

## Board: {$boardName} ({$projectKey})
## API Host: {$jiraHost}

After fetching and analyzing the issues, respond with a JSON object:
{
  "success": true,
  "issue_count": <number of issues analyzed>,
  "analysis": {
    "priorities": [{ "rank": 1, "key": "PROJ-123", "summary": "...", "reasoning": "...", "urgency_score": 8 }],
    "daily_focus": [{ "key": "PROJ-123", "summary": "...", "why_today": "..." }],
    "risks": [{ "key": "PROJ-123", "risk": "...", "mitigation": "..." }],
    "clarifications_needed": [{ "key": "PROJ-123", "summary": "...", "clarity_score": 4, "missing_elements": [], "suggested_questions": [] }],
    "attachment_insights": [{ "key": "PROJ-123", "filename": "screenshot.png", "description": "Shows..." }]
  },
  "markdown_report": "# Daily Sprint Digest..."
}
PROMPT;
    }

    /**
     * Run Claude Code analysis on the shard
     */
    private function runClaudeShardAnalysis($jobId, $apiKey, $prompt, $jiraHost, $jiraEmail, $jiraToken, $callbackUrl, $callbackApiKey) {
        $homeDir = "/tmp/claude-job-{$jobId}";
        @mkdir($homeDir, 0755, true);

        // Write prompt to temp file
        $promptFile = "{$homeDir}/prompt.txt";
        file_put_contents($promptFile, $prompt);

        // Build environment
        $env = [
            'ANTHROPIC_API_KEY' => $apiKey,
            'HOME' => $homeDir,
            'JIRA_API_TOKEN' => $jiraToken,
            'JIRA_HOST' => $jiraHost,
            'JIRA_EMAIL' => $jiraEmail,
            'PATH' => '/usr/local/bin:/usr/bin:/bin'
        ];

        $envStr = '';
        foreach ($env as $k => $v) {
            $envStr .= "$k=" . escapeshellarg($v) . " ";
        }

        $claudePath = '/usr/bin/claude';
        $cmd = "{$envStr} {$claudePath} --print --dangerously-skip-permissions < " . escapeshellarg($promptFile) . " 2>&1";

        $startTime = time();
        $output = shell_exec($cmd);
        $elapsed = time() - $startTime;

        // Parse output - try to extract JSON
        $result = ['success' => true, 'markdown_report' => $output];

        if (preg_match('/```json\s*([\s\S]*?)\s*```/', $output, $matches)) {
            $parsed = json_decode($matches[1], true);
            if ($parsed) {
                $result = array_merge(['success' => true], $parsed);
            }
        } elseif (substr(trim($output ?? ''), 0, 1) === '{') {
            $parsed = json_decode(trim($output), true);
            if ($parsed) {
                $result = $parsed;
                $result['success'] = true;
            }
        }

        // Send callback
        if ($callbackUrl) {
            $payload = json_encode([
                'job_id' => $jobId,
                'type' => 'final',
                'status' => 'completed',
                'elapsed_seconds' => $elapsed,
                'result' => $result
            ]);

            $ch = curl_init($callbackUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $headers = ['Content-Type: application/json'];
            if ($callbackApiKey) {
                $headers[] = "Authorization: Bearer {$callbackApiKey}";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_exec($ch);
            curl_close($ch);
        }

        // Cleanup
        @unlink($promptFile);
        @rmdir($homeDir);
    }

    /**
     * Shard-side AI Developer endpoint
     * Receives full context and runs Claude Code CLI for implementation
     */
    public function shardaidev() {
        // Only accept POST
        if (Flight::request()->method !== 'POST') {
            Flight::json(['error' => 'POST required'], 405);
            return;
        }

        $input = Flight::request()->data->getData();
        if (empty($input)) {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
        }

        // Validate required fields
        if (empty($input['anthropic_api_key'])) {
            Flight::json(['error' => 'anthropic_api_key is required'], 400);
            return;
        }

        // Extract data from request
        $apiKey = $input['anthropic_api_key'];
        $jobId = $input['job_id'] ?? uniqid('aidev_', true);
        $issueKey = $input['issue_key'] ?? '';
        $issueData = $input['issue_data'] ?? [];
        $repoConfig = $input['repo_config'] ?? [];
        $jiraHost = $input['jira_host'] ?? '';
        $jiraEmail = $input['jira_email'] ?? '';
        $jiraToken = $input['jira_api_token'] ?? '';
        $jiraOAuthToken = $input['jira_oauth_token'] ?? '';
        $jiraSiteUrl = $input['jira_site_url'] ?? '';
        $cloudId = $input['cloud_id'] ?? '';
        $githubToken = $input['github_token'] ?? '';
        $callbackUrl = $input['callback_url'] ?? '';
        $callbackApiKey = $input['callback_api_key'] ?? '';
        $action = $input['action'] ?? 'implement'; // implement, retry, resume
        $existingBranch = $input['existing_branch'] ?? null; // Branch affinity

        // Shopify integration settings
        $shopifySettings = $input['shopify'] ?? [];
        $existingShopifyThemeId = $input['existing_shopify_theme_id'] ?? null;

        // Return accepted immediately and continue processing in background
        // Use Flight's response to avoid conflicts with its internal handling
        $response = json_encode([
            'success' => true,
            'job_id' => $jobId,
            'status' => 'running',
            'message' => 'AI Developer started (Claude Code CLI)'
        ]);

        Flight::response()->status(202);
        Flight::response()->header('Content-Type', 'application/json');
        Flight::response()->write($response);
        Flight::response()->send();

        // Flush and continue in background
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            if (ob_get_level()) {
                ob_end_flush();
            }
            flush();
        }

        // Create working directory early for image preprocessing
        $homeDir = "/tmp/aidev-job-{$jobId}";
        @mkdir($homeDir, 0755, true);

        // Pre-process attachment images (resize large images to reduce context usage)
        if (!empty($jiraOAuthToken)) {
            $issueData = $this->preprocessAttachmentImages($issueData, $jiraOAuthToken, $homeDir);
        }

        // Check if using the new agent orchestrator pattern
        $useOrchestrator = !empty($input['use_orchestrator']);

        // Build the prompt
        if ($useOrchestrator) {
            $prompt = $this->buildOrchestratorPrompt($issueKey, $issueData, $repoConfig, $jiraSiteUrl, $shopifySettings);
            $this->logger->info("Using agent orchestrator pattern for {$issueKey}");
        } else {
            $prompt = $this->buildAIDevPrompt($issueKey, $issueData, $repoConfig, $jiraHost, $jiraSiteUrl, $action, $existingBranch);
        }

        // Run Claude Code
        $this->runClaudeAIDev(
            $jobId, $apiKey, $prompt,
            $jiraHost, $jiraEmail, $jiraToken,
            $githubToken, $repoConfig,
            $callbackUrl, $callbackApiKey,
            $issueKey, $issueData,
            $shopifySettings, $existingShopifyThemeId,
            $cloudId, $jiraOAuthToken
        );

        // Prevent Flight from trying to send another response and cleanly exit
        Flight::stop();
        exit(0);
    }

    /**
     * Build the AI Developer prompt for Claude Code
     */
    private function buildAIDevPrompt($issueKey, $issueData, $repoConfig, $jiraHost, $jiraSiteUrl, $action, $existingBranch = null) {
        $summary = $issueData['summary'] ?? '';
        $description = $issueData['description'] ?? '';
        $issueType = $issueData['type'] ?? 'Task';
        $priority = $issueData['priority'] ?? 'Medium';
        $comments = $issueData['comments'] ?? '';
        $attachmentInfo = $issueData['attachment_info'] ?? '';
        $urlsToCheck = $issueData['urls_to_check'] ?? [];

        $repoOwner = $repoConfig['repo_owner'] ?? '';
        $repoName = $repoConfig['repo_name'] ?? '';
        $defaultBranch = $repoConfig['default_branch'] ?? 'main';
        $cloneUrl = $repoConfig['clone_url'] ?? '';

        $urlCheckInstructions = '';
        if (!empty($urlsToCheck)) {
            $urlList = implode("\n", array_map(fn($u) => "- {$u}", $urlsToCheck));
            $urlCheckInstructions = <<<URLS

## URLs to Check
The following URLs were mentioned in the ticket. You MUST check these in the browser to understand the current state and verify your changes:
{$urlList}

Use the dev-browser skill with --headless flag to visit these URLs and analyze what you see. Compare against the requirements. IMPORTANT: Always use headless mode since this runs on a server without a display.
URLS;
        }

        $ticketUrl = $jiraSiteUrl ? "{$jiraSiteUrl}/browse/{$issueKey}" : '';
        $ticketLink = $ticketUrl ? "- Ticket URL: {$ticketUrl}" : '';

        // Build branch instruction based on whether existing branch should be reused
        if ($existingBranch) {
            $branchInstruction = "5. **Checkout existing branch**: A branch already exists for this ticket. Checkout and pull the latest:\n" .
                "   ```bash\n" .
                "   git fetch origin\n" .
                "   git checkout {$existingBranch}\n" .
                "   git pull origin {$existingBranch}\n" .
                "   ```\n" .
                "   Continue the work from where the previous run left off. Do NOT create a new branch.";
        } else {
            $branchInstruction = "5. **Create a feature branch**: Use a descriptive name like `fix/{$issueKey}-description`.";
        }

        return <<<PROMPT
You are an AI Developer implementing a Jira ticket. You have full access to:
- Git and GitHub (clone, branch, commit, push, create PR)
- Browser (to check URLs and verify your work)
- Jira API (to fetch details and post comments)
- Filesystem (to read and write code)

## Your Mission
Implement the requirements from Jira ticket **{$issueKey}** and create a Pull Request.

## Jira Ticket: {$issueKey}
{$ticketLink}
- Type: {$issueType}
- Priority: {$priority}

### Summary
{$summary}

### Description
{$description}

### Comments/Clarifications
{$comments}

{$attachmentInfo}
{$urlCheckInstructions}

## Repository
- Owner: {$repoOwner}
- Repo: {$repoName}
- Default Branch: {$defaultBranch}
- Clone URL: {$cloneUrl}

## Jira API Access
Use curl with the Bearer token:
```bash
curl -s -H "Authorization: Bearer \$JIRA_API_TOKEN" "{$jiraHost}/rest/api/3/issue/{$issueKey}"
```

## GitHub Access
The GITHUB_TOKEN environment variable is set. Use it for git operations:
```bash
git clone https://\$GITHUB_TOKEN@github.com/{$repoOwner}/{$repoName}.git
```

## Your Workflow
1. **Understand**: Read the ticket carefully. Check any URLs mentioned to understand the current state.
2. **Fetch Attachments**: If there are image attachments, download and view them.
3. **Clone the repo**: Clone to a working directory.
4. **Analyze the codebase**: Find relevant files for the implementation.
{$branchInstruction}
6. **Implement the changes**: Write clean, well-tested code.
7. **Verify your work**: If URLs were provided, check them in the browser to verify (if applicable).
8. **Commit and push**: Write a good commit message referencing {$issueKey}.
9. **Create a PR**: Use the GitHub CLI or API. Include:
   - Title: [{$issueKey}] Brief description
   - Body: Summary of changes, link to ticket, testing notes

## Important Guidelines
- **Check URLs**: If URLs are mentioned, ALWAYS visit them using the dev-browser skill with --headless flag (headless mode required on this server).
- **Download images**: If attachments exist, download and view them to understand visual requirements.
- **Iterate**: Don't just write code blindly. Verify your understanding first.
- **Be thorough**: Check your changes work correctly before creating the PR.

## Output Format
When complete, output a JSON summary:
```json
{
  "success": true,
  "issue_key": "{$issueKey}",
  "pr_url": "https://github.com/...",
  "pr_number": 123,
  "branch_name": "fix/...",
  "files_changed": ["path/to/file1.php", "path/to/file2.css"],
  "summary": "Brief description of what was implemented"
}
```

If you encounter issues or need clarification, output:
```json
{
  "success": false,
  "issue_key": "{$issueKey}",
  "needs_clarification": true,
  "questions": ["Question 1?", "Question 2?"],
  "reason": "Why clarification is needed"
}
```

Now, implement {$issueKey}!
PROMPT;
    }

    /**
     * Build orchestrator prompt that uses specialized agents
     *
     * This pattern spawns focused agents (impl-agent, verify-agent, fix-agent)
     * instead of accumulating context in a single session.
     *
     * @param string $issueKey Jira issue key
     * @param array $issueData Issue data from Jira
     * @param array $repoConfig Repository configuration
     * @param string $jiraSiteUrl Jira site URL
     * @param array $shopifySettings Shopify settings if applicable
     * @return string The orchestrator prompt
     */
    private function buildOrchestratorPrompt($issueKey, $issueData, $repoConfig, $jiraSiteUrl, $shopifySettings = []) {
        // Build ticket data structure
        $ticket = [
            'key' => $issueKey,
            'summary' => $issueData['summary'] ?? '',
            'description' => $issueData['description'] ?? '',
            'requirements' => $this->extractRequirements($issueData),
            'acceptance_criteria' => $this->extractAcceptanceCriteria($issueData),
            'type' => $issueData['type'] ?? 'Task',
            'priority' => $issueData['priority'] ?? 'Medium',
            'comments' => $issueData['comments'] ?? ''
        ];

        // Build repo data structure
        $repo = [
            'path' => './repo', // Claude Code will clone here
            'clone_url' => $repoConfig['clone_url'] ?? '',
            'default_branch' => $repoConfig['default_branch'] ?? 'main',
            'owner' => $repoConfig['repo_owner'] ?? '',
            'name' => $repoConfig['repo_name'] ?? ''
        ];

        // Determine preview URL (for Shopify themes)
        $previewUrl = null;
        if (!empty($shopifySettings['store_url'])) {
            // Will be set after impl-agent creates the theme
            $previewUrl = null; // Orchestrator will set this after Shopify sync
        }

        // Create orchestrator and build prompt
        $orchestrator = new \app\services\AIDevAgentOrchestrator($ticket, $repo, $previewUrl, 3);
        return $orchestrator->buildOrchestratorPrompt();
    }

    /**
     * Extract requirements from issue data
     */
    private function extractRequirements($issueData) {
        $requirements = [];

        // Try to parse requirements from description
        $description = $issueData['description'] ?? '';
        if (preg_match_all('/(?:^|\n)\s*[-*]\s*(.+)$/m', $description, $matches)) {
            $requirements = array_merge($requirements, $matches[1]);
        }

        // If no bullet points found, use the description as a single requirement
        if (empty($requirements) && !empty($description)) {
            $requirements[] = $description;
        }

        return $requirements;
    }

    /**
     * Extract acceptance criteria from issue data
     */
    private function extractAcceptanceCriteria($issueData) {
        $criteria = [];

        $description = $issueData['description'] ?? '';

        // Look for "Acceptance Criteria" section
        if (preg_match('/acceptance\s+criteria[:\s]*\n([\s\S]*?)(?:\n\n|\z)/i', $description, $matches)) {
            if (preg_match_all('/(?:^|\n)\s*[-*]\s*(.+)$/m', $matches[1], $subMatches)) {
                $criteria = $subMatches[1];
            }
        }

        // Default criteria if none found
        if (empty($criteria)) {
            $criteria[] = 'Verify the implementation matches the requirements in the description';
            $criteria[] = 'Check for any visual regressions';
        }

        return $criteria;
    }

    /**
     * Run Claude Code for AI Developer implementation
     * Uses proc_open for real-time output streaming to log file
     * Monitor with: tail -f /tmp/aidev-job-{jobId}/session.log
     */
    private function runClaudeAIDev($jobId, $apiKey, $prompt, $jiraHost, $jiraEmail, $jiraToken, $githubToken, $repoConfig, $callbackUrl, $callbackApiKey, $issueKey = '', $issueData = [], $shopifySettings = [], $existingShopifyThemeId = null, $cloudId = '', $jiraOAuthToken = '') {
        $homeDir = "/tmp/aidev-job-{$jobId}";
        @mkdir($homeDir, 0755, true);

        // Create .claude directory and symlink plugins from claudeuser's home
        $claudeDir = "{$homeDir}/.claude";
        @mkdir($claudeDir, 0755, true);

        // Symlink plugins directory so dev-browser and other plugins are available
        $pluginsSource = '/home/claudeuser/.claude/plugins';
        $pluginsTarget = "{$claudeDir}/plugins";
        if (is_dir($pluginsSource) && !file_exists($pluginsTarget)) {
            @symlink($pluginsSource, $pluginsTarget);
        }

        // Copy git config from claudeuser's home for commit identity
        $gitConfigSource = '/home/claudeuser/.gitconfig';
        $gitConfigTarget = "{$homeDir}/.gitconfig";
        if (file_exists($gitConfigSource) && !file_exists($gitConfigTarget)) {
            @copy($gitConfigSource, $gitConfigTarget);
        }

        // Create working directory for repo
        $workDir = "{$homeDir}/repo";
        @mkdir($workDir, 0755, true);

        // Write prompt to temp file
        $promptFile = "{$homeDir}/prompt.txt";
        file_put_contents($promptFile, $prompt);

        // Create log file for real-time output capture
        $logFile = "{$homeDir}/session.log";
        $logHandle = fopen($logFile, 'w');

        // Write header to log
        $header = sprintf(
            "=== AI Developer Job: %s ===\n=== Started: %s ===\n=== Monitor: tail -f %s ===\n\n",
            $jobId,
            date('Y-m-d H:i:s'),
            $logFile
        );
        fwrite($logHandle, $header);
        fflush($logHandle);

        // Log the session info for monitoring
        $infoFile = "{$homeDir}/session-info.json";
        file_put_contents($infoFile, json_encode([
            'job_id' => $jobId,
            'log_file' => $logFile,
            'prompt_file' => $promptFile,
            'work_dir' => $workDir,
            'started_at' => date('Y-m-d H:i:s'),
            'tail_command' => "tail -f {$logFile}",
            'status' => 'running'
        ], JSON_PRETTY_PRINT));

        // Build environment with all credentials
        $env = [
            'ANTHROPIC_API_KEY' => $apiKey,
            'HOME' => $homeDir,
            'JIRA_API_TOKEN' => $jiraToken,
            'JIRA_HOST' => $jiraHost,
            'JIRA_EMAIL' => $jiraEmail,
            'GITHUB_TOKEN' => $githubToken,
            'PATH' => '/home/claudeuser/.bun/bin:/usr/local/bin:/usr/bin:/bin',
            'GIT_TERMINAL_PROMPT' => '0',
            'TERM' => 'xterm-256color'
        ];

        // Build the claude command
        // --print outputs text to stdout (required for logging)
        // --dangerously-skip-permissions allows all tool calls
        // --session-id preserves context across runs for the same ticket (must be UUID format)
        $claudePath = '/usr/bin/claude';

        // Generate a deterministic UUID v5 from the issue key for session persistence
        // This ensures the same issue always gets the same session ID
        $namespace = 'f47ac10b-58cc-4372-a567-0e02b2c3d479'; // Random namespace UUID
        $sessionId = $this->generateUuidV5($namespace, $issueKey ?: $jobId);

        $cmd = "{$claudePath} --print --dangerously-skip-permissions --session-id " . escapeshellarg($sessionId);

        // Set up pipes for proc_open
        $descriptors = [
            0 => ['file', $promptFile, 'r'],  // stdin from prompt file
            1 => ['pipe', 'w'],                // stdout pipe
            2 => ['pipe', 'w'],                // stderr pipe
        ];

        $startTime = time();
        $process = proc_open($cmd, $descriptors, $pipes, $workDir, $env);

        if (!is_resource($process)) {
            fwrite($logHandle, "\n=== ERROR: Failed to start Claude process ===\n");
            fclose($logHandle);
            return;
        }

        // Set pipes to non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $maxWait = 1800; // 30 minute timeout

        // Read output in real-time and write to log
        while (true) {
            $status = proc_get_status($process);

            // Read from stdout
            $stdout = stream_get_contents($pipes[1]);
            if ($stdout) {
                $output .= $stdout;
                fwrite($logHandle, $stdout);
                fflush($logHandle);
            }

            // Read from stderr
            $stderr = stream_get_contents($pipes[2]);
            if ($stderr) {
                $output .= $stderr;
                fwrite($logHandle, $stderr);
                fflush($logHandle);
            }

            // Check if process has exited
            if (!$status['running']) {
                // Final read of any remaining output
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                if ($stdout) {
                    $output .= $stdout;
                    fwrite($logHandle, $stdout);
                }
                if ($stderr) {
                    $output .= $stderr;
                    fwrite($logHandle, $stderr);
                }
                break;
            }

            // Check timeout
            if ((time() - $startTime) > $maxWait) {
                proc_terminate($process, 9);
                fwrite($logHandle, "\n=== TIMEOUT: Job killed after 30 minutes ===\n");
                break;
            }

            // Small sleep to prevent CPU spinning
            usleep(100000); // 100ms
        }

        // Close pipes
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $elapsed = time() - $startTime;

        // Parse output - try to extract JSON result
        $result = [
            'success' => false,
            'raw_output' => $output,
            'elapsed_seconds' => $elapsed
        ];

        // Look for JSON in the output
        if (preg_match('/```json\s*([\s\S]*?)\s*```/', $output, $matches)) {
            $parsed = json_decode($matches[1], true);
            if ($parsed) {
                $result = array_merge($result, $parsed);
            }
        } elseif (preg_match('/\{[^{}]*"success"[^{}]*\}/s', $output, $matches)) {
            $parsed = json_decode($matches[0], true);
            if ($parsed) {
                $result = array_merge($result, $parsed);
            }
        }

        // Determine status
        $status = 'completed';
        if (!empty($result['needs_clarification'])) {
            $status = 'needs_clarification';
        } elseif (empty($result['success']) || empty($result['pr_url'])) {
            $status = 'failed';
        }

        // Shopify theme push using CLI (much faster than REST API file uploads)
        if ($status === 'completed' && !empty($shopifySettings['enabled'])) {
            fwrite($logHandle, "\n=== Shopify Theme Push ===\n");

            $shopifyResult = $this->pushThemeWithCLI(
                $workDir,
                $issueKey,
                $issueData,
                $shopifySettings,
                $cloudId,
                $jiraOAuthToken,
                $logHandle
            );

            if ($shopifyResult) {
                $result['shopify_theme_id'] = $shopifyResult['theme_id'] ?? null;
                $result['shopify_preview_url'] = $shopifyResult['preview_url'] ?? null;
                fwrite($logHandle, "Preview URL: " . ($shopifyResult['preview_url'] ?? 'N/A') . "\n");

                // Run Playwright verification loop if enabled
                if (!empty($shopifySettings['verify_with_playwright'])) {
                    fwrite($logHandle, "\n=== Starting Playwright Verification ===\n");

                    // Build Jira credentials for screenshot upload
                    $jiraCredentials = [
                        'issue_key' => $issueKey,
                        'cloud_id' => $cloudId,
                        'oauth_token' => $jiraOAuthToken
                    ];

                    $verifyResult = $this->runPlaywrightVerification(
                        $shopifyResult['preview_url'],
                        $shopifyResult['theme_id'],
                        $homeDir,
                        $workDir,
                        $issueData,
                        $shopifySettings,
                        $apiKey,
                        $jiraCredentials,
                        $logHandle
                    );

                    $result['verification'] = $verifyResult;

                    if ($verifyResult['passed']) {
                        fwrite($logHandle, "\n✅ Playwright verification PASSED\n");
                    } else {
                        fwrite($logHandle, "\n⚠️ Playwright verification completed with issues\n");
                        $result['verification_issues'] = $verifyResult['issues'] ?? [];
                    }

                    fwrite($logHandle, "=== Playwright Verification Complete ===\n");
                }
            }

            fwrite($logHandle, "=== Shopify Theme Push Complete ===\n");
        }

        // Write footer to log
        fwrite($logHandle, sprintf("\n\n=== Job Completed: %s (%.1f seconds) ===\n", date('Y-m-d H:i:s'), $elapsed));
        fclose($logHandle);

        // Update session info with completion status
        file_put_contents($infoFile, json_encode([
            'job_id' => $jobId,
            'log_file' => $logFile,
            'prompt_file' => $promptFile,
            'work_dir' => $workDir,
            'started_at' => date('Y-m-d H:i:s', $startTime),
            'completed_at' => date('Y-m-d H:i:s'),
            'elapsed_seconds' => $elapsed,
            'status' => 'completed'
        ], JSON_PRETTY_PRINT));

        // Send callback
        if ($callbackUrl) {
            $payload = json_encode([
                'job_id' => $jobId,
                'type' => 'final',
                'status' => $status,
                'elapsed_seconds' => $elapsed,
                'result' => $result
            ]);

            $ch = curl_init($callbackUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $headers = ['Content-Type: application/json'];
            if ($callbackApiKey) {
                $headers[] = "Authorization: Bearer {$callbackApiKey}";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_exec($ch);
            curl_close($ch);
        }

        // Cleanup (but leave for debugging if failed)
        if ($status === 'completed') {
            @unlink($promptFile);
            $this->recursiveDelete($workDir);
            @rmdir($homeDir);
        }
    }

    /**
     * Sync changed files to Shopify and verify with Playwright
     *
     * @param string $workDir Git working directory
     * @param string $issueKey Jira issue key
     * @param array $issueData Issue data including summary
     * @param array $shopifySettings Shopify connection settings
     * @param int|null $existingThemeId Existing development theme ID
     * @param string $jiraHost Jira cloud ID
     * @param string $jiraEmail Jira email
     * @param string $jiraToken Jira API token
     * @param resource $logHandle Log file handle
     * @return array|null Result with theme_id, preview_url, playwright_results
     */
    private function syncToShopifyAndVerify($workDir, $issueKey, $issueData, $shopifySettings, $existingThemeId, $jiraHost, $jiraEmail, $jiraToken, $logHandle) {
        // Find the actual repo directory - Claude clones to a subdirectory named after the repo
        $actualRepoDir = $this->findClonedRepoDir($workDir, $logHandle);
        if (!$actualRepoDir) {
            fwrite($logHandle, "Could not find cloned repo directory\n");
            return null;
        }
        $workDir = $actualRepoDir;
        fwrite($logHandle, "Using repo directory: {$workDir}\n");

        // Check if this is a Shopify theme repo
        if (!$this->isShopifyThemeRepo($workDir)) {
            fwrite($logHandle, "Not a Shopify theme repo, skipping sync\n");
            return null;
        }

        fwrite($logHandle, "Detected Shopify theme repository\n");

        // Get credentials from payload (shard doesn't have access to user databases)
        $shopDomain = $shopifySettings['shop_domain'] ?? null;
        $accessToken = $shopifySettings['access_token'] ?? null;

        if (!$shopDomain || !$accessToken) {
            fwrite($logHandle, "ERROR: Missing Shopify credentials in payload\n");
            return null;
        }

        fwrite($logHandle, "Shopify shop: {$shopDomain}\n");

        // Get changed theme files
        $changedFiles = $this->getChangedThemeFiles($workDir);
        if (empty($changedFiles)) {
            fwrite($logHandle, "No theme files changed, skipping sync\n");
            return null;
        }

        fwrite($logHandle, "Found " . count($changedFiles) . " changed theme files:\n");
        foreach ($changedFiles as $file) {
            fwrite($logHandle, "  - {$file}\n");
        }

        // Get or create development theme
        $summary = $issueData['summary'] ?? $issueKey;
        $httpClient = new \GuzzleHttp\Client(['timeout' => 60, 'http_errors' => false]);
        $apiVersion = '2024-10';
        $isNewTheme = false;

        try {
            if ($existingThemeId) {
                fwrite($logHandle, "Using existing development theme: {$existingThemeId}\n");
                $themeId = $existingThemeId;
            } else {
                // First, check if a dev theme already exists for this ticket
                fwrite($logHandle, "Checking for existing development theme...\n");
                $themesResponse = $httpClient->get(
                    "https://{$shopDomain}/admin/api/{$apiVersion}/themes.json",
                    ['headers' => ['X-Shopify-Access-Token' => $accessToken]]
                );

                $themeId = null;
                if ($themesResponse->getStatusCode() === 200) {
                    $themes = json_decode($themesResponse->getBody(), true)['themes'] ?? [];
                    $prefix = "[AI-DEV] {$issueKey}";
                    foreach ($themes as $theme) {
                        if (strpos($theme['name'], $prefix) === 0 && $theme['role'] === 'unpublished') {
                            $themeId = $theme['id'];
                            fwrite($logHandle, "Found existing theme: {$themeId}\n");
                            break;
                        }
                    }
                }

                // Create new theme if none exists
                if (!$themeId) {
                    fwrite($logHandle, "Creating new development theme...\n");

                    // Find main theme to copy from
                    $sourceThemeId = null;
                    foreach ($themes ?? [] as $theme) {
                        if ($theme['role'] === 'main') {
                            $sourceThemeId = $theme['id'];
                            break;
                        }
                    }

                    // Shopify theme names must be <= 50 characters
                    $themeName = substr("[AI-DEV] {$issueKey}: {$summary}", 0, 50);

                    // Create theme request - don't include 'src' field (null causes error)
                    $themePayload = [
                        'theme' => [
                            'name' => $themeName,
                            'role' => 'unpublished'
                        ]
                    ];

                    $createResponse = $httpClient->post(
                        "https://{$shopDomain}/admin/api/{$apiVersion}/themes.json",
                        [
                            'headers' => [
                                'X-Shopify-Access-Token' => $accessToken,
                                'Content-Type' => 'application/json'
                            ],
                            'json' => $themePayload
                        ]
                    );

                    if ($createResponse->getStatusCode() >= 200 && $createResponse->getStatusCode() < 300) {
                        $themeData = json_decode($createResponse->getBody(), true);
                        $themeId = $themeData['theme']['id'] ?? null;
                        fwrite($logHandle, "Created theme ID: {$themeId}\n");

                        // New theme needs ALL files from repo, not just changed ones
                        $isNewTheme = true;
                    } else {
                        fwrite($logHandle, "ERROR: Failed to create theme: " . $createResponse->getBody() . "\n");
                        return null;
                    }
                }
            }

            $previewUrl = "https://{$shopDomain}/?preview_theme_id={$themeId}";
        } catch (\Exception $e) {
            fwrite($logHandle, "ERROR: Could not get/create theme: " . $e->getMessage() . "\n");
            return null;
        }

        // Prepare files for upload
        // For new themes, upload ALL theme files; for existing themes, only changed files
        if ($isNewTheme) {
            fwrite($logHandle, "New theme - uploading all theme files from repo...\n");
            $filesToProcess = $this->getAllThemeFiles($workDir);
        } else {
            $filesToProcess = $changedFiles;
        }

        $filesToUpload = [];
        foreach ($filesToProcess as $file) {
            $fullPath = $workDir . '/' . $file;
            if (!file_exists($fullPath)) {
                continue;
            }

            // Determine the Shopify asset key (e.g., "sections/image-banner.liquid")
            $assetKey = $file;

            // Read file content
            $content = file_get_contents($fullPath);

            // Check if binary file or has encoding issues
            if ($this->isBinaryFile($fullPath)) {
                // Binary files use base64 attachment
                $filesToUpload[] = [
                    'key' => $assetKey,
                    'attachment' => base64_encode($content)
                ];
            } else {
                // Text files - ensure valid UTF-8 for JSON encoding
                // First try to detect and convert encoding
                $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
                if ($encoding && $encoding !== 'UTF-8') {
                    $content = mb_convert_encoding($content, 'UTF-8', $encoding);
                }

                // If still not valid UTF-8, use base64 as fallback
                if (!mb_check_encoding($content, 'UTF-8')) {
                    $filesToUpload[] = [
                        'key' => $assetKey,
                        'attachment' => base64_encode($content)
                    ];
                } else {
                    $filesToUpload[] = [
                        'key' => $assetKey,
                        'value' => $content
                    ];
                }
            }
        }

        // Upload files to Shopify
        try {
            fwrite($logHandle, "Uploading " . count($filesToUpload) . " files to Shopify...\n");
            $uploaded = 0;
            $failed = 0;
            $errors = [];

            foreach ($filesToUpload as $file) {
                try {
                    $assetData = ['asset' => $file];

                    $response = $httpClient->put(
                        "https://{$shopDomain}/admin/api/{$apiVersion}/themes/{$themeId}/assets.json",
                        [
                            'headers' => [
                                'X-Shopify-Access-Token' => $accessToken,
                                'Content-Type' => 'application/json'
                            ],
                            'json' => $assetData
                        ]
                    );

                    if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                        $uploaded++;
                        fwrite($logHandle, "  Uploaded: {$file['key']}\n");
                    } else {
                        $failed++;
                        $errorMsg = "Failed to upload {$file['key']}: " . $response->getBody();
                        $errors[] = $errorMsg;
                        fwrite($logHandle, "  ERROR: {$errorMsg}\n");
                    }
                } catch (\Exception $fileEx) {
                    $failed++;
                    $errorMsg = "Exception uploading {$file['key']}: " . $fileEx->getMessage();
                    $errors[] = $errorMsg;
                    fwrite($logHandle, "  ERROR: {$errorMsg}\n");
                }

                // Rate limiting - Shopify allows 2 requests/second for assets
                usleep(500000); // 0.5 second delay
            }

            fwrite($logHandle, "Upload complete: {$uploaded} uploaded, {$failed} failed\n");
        } catch (\Exception $e) {
            fwrite($logHandle, "ERROR: Upload failed: " . $e->getMessage() . "\n");
            return null;
        }

        // Run Playwright tests if storefront password is available
        $playwrightResults = null;
        $storefrontPassword = $shopifySettings['storefront_password'] ?? null;

        // Note: Playwright testing would go here
        // For now, we'll just log that it would run
        fwrite($logHandle, "\nPlaywright testing: Not yet implemented\n");
        fwrite($logHandle, "Preview URL ready: {$previewUrl}\n");

        // Post preview URL to Jira
        try {
            $this->postShopifyPreviewToJira(
                $issueKey,
                $previewUrl,
                $themeId,
                $jiraHost,
                $jiraEmail,
                $jiraToken,
                $logHandle
            );
        } catch (\Exception $e) {
            fwrite($logHandle, "WARNING: Could not post preview to Jira: " . $e->getMessage() . "\n");
        }

        return [
            'theme_id' => $themeId,
            'preview_url' => $previewUrl,
            'playwright_results' => $playwrightResults,
            'playwright_failed' => false
        ];
    }

    /**
     * Check if directory contains a Shopify theme
     */
    private function isShopifyThemeRepo(string $dir): bool {
        // Check for common Shopify theme indicators
        $indicators = [
            'layout/theme.liquid',
            'config/settings_schema.json',
            'templates/index.liquid',
            'templates/index.json'
        ];

        foreach ($indicators as $indicator) {
            if (file_exists($dir . '/' . $indicator)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the actual cloned repo directory
     * Claude clones repos to a subdirectory, not directly into workDir
     */
    private function findClonedRepoDir(string $baseDir, $logHandle): ?string {
        // First check if baseDir itself is a git repo
        if (is_dir($baseDir . '/.git')) {
            return $baseDir;
        }

        // Look for subdirectories that are git repos
        $parentDir = dirname($baseDir); // Go up one level from /repo to job dir
        if (!is_dir($parentDir)) {
            return null;
        }

        $entries = scandir($parentDir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.claude') {
                continue;
            }
            $path = $parentDir . '/' . $entry;
            if (is_dir($path) && is_dir($path . '/.git')) {
                fwrite($logHandle, "Found git repo at: {$path}\n");
                return $path;
            }
        }

        // Also check workDir subdirectories
        if (is_dir($baseDir)) {
            $entries = scandir($baseDir);
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $baseDir . '/' . $entry;
                if (is_dir($path) && is_dir($path . '/.git')) {
                    fwrite($logHandle, "Found git repo at: {$path}\n");
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Get list of changed theme files from git
     */
    private function getChangedThemeFiles(string $workDir): array {
        // Get files changed in the last commit
        $output = [];
        exec("cd " . escapeshellarg($workDir) . " && git diff --name-only HEAD~1 HEAD 2>/dev/null", $output);

        // Filter to only theme-related files
        $themeFiles = [];
        $themeDirs = ['assets/', 'config/', 'layout/', 'locales/', 'sections/', 'snippets/', 'templates/'];

        foreach ($output as $file) {
            foreach ($themeDirs as $dir) {
                if (strpos($file, $dir) === 0) {
                    $themeFiles[] = $file;
                    break;
                }
            }
        }

        return $themeFiles;
    }

    /**
     * Get ALL theme files from repo (for new theme creation)
     */
    private function getAllThemeFiles(string $workDir): array {
        $themeDirs = ['assets', 'config', 'layout', 'locales', 'sections', 'snippets', 'templates'];
        $themeFiles = [];

        foreach ($themeDirs as $dir) {
            $fullDirPath = $workDir . '/' . $dir;
            if (!is_dir($fullDirPath)) {
                continue;
            }

            // Recursively scan for all files in this directory
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fullDirPath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    // Get relative path from workDir
                    $relativePath = str_replace($workDir . '/', '', $file->getPathname());
                    $themeFiles[] = $relativePath;
                }
            }
        }

        return $themeFiles;
    }

    /**
     * Check if file is binary
     */
    private function isBinaryFile(string $path): bool {
        $binaryExtensions = ['png', 'jpg', 'jpeg', 'gif', 'ico', 'webp', 'woff', 'woff2', 'ttf', 'eot', 'otf'];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, $binaryExtensions);
    }

    /**
     * Push theme to Shopify using CLI
     * Uses `shopify theme push` for fast, reliable uploads
     */
    private function pushThemeWithCLI($workDir, $issueKey, $issueData, $shopifySettings, $cloudId, $jiraOAuthToken, $logHandle) {
        // Find the actual repo directory (Claude might clone to a subdirectory)
        $repoDir = $this->findClonedRepoDir($workDir, $logHandle);
        if (!$repoDir) {
            fwrite($logHandle, "Could not find cloned repo directory\n");
            return null;
        }

        fwrite($logHandle, "Using repo directory: {$repoDir}\n");

        // Check if this is a Shopify theme repo
        if (!$this->isShopifyThemeRepo($repoDir)) {
            fwrite($logHandle, "Not a Shopify theme repo, skipping push\n");
            return null;
        }

        $shopDomain = $shopifySettings['shop_domain'] ?? '';
        $accessToken = $shopifySettings['access_token'] ?? '';
        $storefrontPassword = $shopifySettings['storefront_password'] ?? '';

        if (empty($shopDomain) || empty($accessToken)) {
            fwrite($logHandle, "Missing Shopify credentials\n");
            return null;
        }

        fwrite($logHandle, "Shopify shop: {$shopDomain}\n");

        // Create theme name (max 50 chars)
        $summary = $issueData['summary'] ?? 'AI Dev';
        $themeName = substr("[AI-DEV] {$issueKey}: {$summary}", 0, 50);
        fwrite($logHandle, "Theme name: {$themeName}\n");

        // Run shopify theme push
        $cmd = sprintf(
            'shopify theme push --store=%s --password=%s --unpublished --theme=%s --path=%s --json 2>&1',
            escapeshellarg($shopDomain),
            escapeshellarg($accessToken),
            escapeshellarg($themeName),
            escapeshellarg($repoDir)
        );

        fwrite($logHandle, "Running: shopify theme push...\n");

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);
        $outputStr = implode("\n", $output);

        if ($returnCode !== 0) {
            fwrite($logHandle, "Theme push failed (exit code {$returnCode}):\n{$outputStr}\n");
            return null;
        }

        // Parse JSON output to get theme ID
        // The Shopify CLI output may include ANSI escape codes, so extract the JSON part
        $themeId = null;
        if (preg_match('/\{"theme":\{[^}]*"id":(\d+)[^}]*\}/', $outputStr, $jsonMatch)) {
            $themeId = $jsonMatch[1];
        } else {
            // Try standard JSON decode
            $jsonOutput = json_decode($outputStr, true);
            $themeId = $jsonOutput['theme']['id'] ?? null;
        }

        if (!$themeId) {
            // Try to extract from output if JSON parsing fails
            if (preg_match('/Theme\s+#?(\d+)/', $outputStr, $matches)) {
                $themeId = $matches[1];
            }
        }

        if (!$themeId) {
            fwrite($logHandle, "Could not determine theme ID from output:\n{$outputStr}\n");
            return null;
        }

        fwrite($logHandle, "Theme pushed successfully! ID: {$themeId}\n");

        // Generate preview URL
        $previewUrl = "https://{$shopDomain}/?preview_theme_id={$themeId}";
        fwrite($logHandle, "Preview URL: {$previewUrl}\n");

        // Post preview URL to Jira
        if (!empty($jiraOAuthToken) && !empty($cloudId)) {
            try {
                $this->postShopifyPreviewToJira(
                    $issueKey,
                    $previewUrl,
                    $themeId,
                    $cloudId,
                    '', // jiraEmail not needed with OAuth
                    $jiraOAuthToken,
                    $logHandle
                );
                fwrite($logHandle, "Posted preview to Jira ticket\n");
            } catch (\Exception $e) {
                fwrite($logHandle, "Warning: Could not post to Jira: " . $e->getMessage() . "\n");
            }
        }

        return [
            'theme_id' => $themeId,
            'preview_url' => $previewUrl
        ];
    }

    /**
     * Run Playwright verification loop for Shopify theme
     * Uses Claude Code to visit the preview URL and verify changes match requirements
     *
     * @param string $previewUrl Shopify preview URL
     * @param string $themeId Shopify theme ID for re-pushing
     * @param string $homeDir Job home directory
     * @param string $workDir Repo working directory
     * @param array $issueData Issue requirements to verify against
     * @param array $shopifySettings Shopify credentials
     * @param string $apiKey Anthropic API key
     * @param resource $logHandle Log file handle
     * @return array Verification result with 'passed', 'issues', 'iterations'
     */
    private function runPlaywrightVerification($previewUrl, $themeId, $homeDir, $workDir, $issueData, $shopifySettings, $apiKey, $jiraCredentials, $logHandle) {
        $maxIterations = 4;  // 1 discovery + 3 fix/verify iterations
        $iteration = 0;
        $allIssues = [];
        $creditErrorDetected = false;  // Track credit balance errors for UI warning
        $storefrontPassword = $shopifySettings['storefront_password'] ?? '';

        // Extract Jira credentials for screenshot upload
        $issueKey = $jiraCredentials['issue_key'] ?? '';
        $cloudId = $jiraCredentials['cloud_id'] ?? '';
        $jiraOAuthToken = $jiraCredentials['oauth_token'] ?? '';

        fwrite($logHandle, "\n=== Playwright Verification Loop ===\n");
        fwrite($logHandle, "Preview URL: {$previewUrl}\n");
        fwrite($logHandle, "Max iterations: {$maxIterations}\n");

        while ($iteration < $maxIterations) {
            $iteration++;
            fwrite($logHandle, "\n--- Verification Iteration {$iteration}/{$maxIterations} ---\n");

            // Build verification prompt
            $summary = $issueData['summary'] ?? '';
            $description = $issueData['description'] ?? '';
            $attachmentInfo = $issueData['attachment_info'] ?? '';

            $passwordInstructions = '';
            if (!empty($storefrontPassword)) {
                $passwordInstructions = <<<PASS

**Storefront Password**: The preview store is password protected. When you encounter a password page, enter: `{$storefrontPassword}`
PASS;
            }

            // Create screenshots directory
            $screenshotsDir = "{$homeDir}/screenshots";
            if (!is_dir($screenshotsDir)) {
                mkdir($screenshotsDir, 0755, true);
            }

            // Build iteration-specific prompt
            if ($iteration === 1) {
                // Discovery iteration - deep analysis
                $verifyPrompt = <<<PROMPT
You are a QA engineer doing a DISCOVERY phase for a Shopify theme implementation. This is iteration 1 of 4.

## Your Goal
Deeply understand what's been implemented and what's missing. DO NOT just do a code review - you MUST visually verify in the browser.

## Original Requirements

**Summary**: {$summary}

**Description**:
{$description}

{$attachmentInfo}

## Preview URL
{$previewUrl}
{$passwordInstructions}

## Discovery Tasks

1. Use the dev-browser skill with `--headless` flag to visit the preview URL
2. Navigate to ALL pages/sections where the feature should be visible
3. Check the actual DOM - look for the expected HTML elements, CSS classes, etc.
4. Identify what's working and what's NOT visible/enabled
5. Check theme JSON presets/templates if features aren't enabled

## Key Questions to Answer
- Is the feature VISUALLY working on the preview? (Not just in code)
- Are the required sections using the new feature?
- If not visible, WHY? (Check: schema defaults, JSON presets, template settings)
- What specific files need to be modified to make it work?

## Shopify Theme Structure Notes
- Schema `default` values only apply to NEW sections
- EXISTING sections have settings saved in `templates/*.json` or `config/settings_data.json`
- To enable a feature on existing sections, you must modify those JSON files

## Screenshots Directory
Save screenshots to: {$screenshotsDir}/
- Use `proof-` prefix for screenshots showing feature working
- Use `before-` prefix for screenshots showing what's missing/broken

## Output Format
```json
{
  "passed": true/false,
  "discovery": {
    "feature_visible": true/false,
    "sections_checked": ["list of sections examined"],
    "files_to_modify": ["specific files that need changes"],
    "root_cause": "Why the feature isn't visible (if applicable)"
  },
  "issues": [
    {
      "severity": "critical|major|minor",
      "description": "What's wrong",
      "location": "Where on the page",
      "expected": "What should be there",
      "actual": "What you see",
      "fix_required": "What needs to change"
    }
  ],
  "screenshots_taken": ["paths to saved screenshots"],
  "pages_checked": ["URLs visited"],
  "summary": "Discovery findings"
}
```

Now do a thorough discovery at {$previewUrl}
PROMPT;
            } else {
                // Fix/Verify iterations (2-4)
                $verifyPrompt = <<<PROMPT
You are a QA engineer verifying a Shopify theme implementation. This is iteration {$iteration} of 4.

## Your Goal
Verify the feature is VISUALLY working. If not, identify exactly what's wrong so it can be fixed.

## Original Requirements

**Summary**: {$summary}

**Description**:
{$description}

{$attachmentInfo}

## Preview URL
{$previewUrl}
{$passwordInstructions}

## Verification Tasks

1. Use the dev-browser skill with `--headless` flag to visit the preview URL
2. Navigate to sections where the feature should be visible
3. VISUALLY confirm the feature is working (not just code review!)
4. Take proof screenshots if it's working
5. If NOT working, identify the exact issue and what file needs to change

## IMPORTANT: Do What It Takes
If the feature isn't visible, you need to identify WHY:
- Check if it's enabled in the section settings
- Look at the theme's JSON templates (templates/*.json, config/settings_data.json)
- Features may need to be enabled in JSON presets, not just schema defaults

## Screenshots Directory
Save screenshots to: {$screenshotsDir}/
- `proof-feature-name.png` - Shows feature working
- `before-issue.png` / `after-fix.png` - Shows before/after

## Output Format
```json
{
  "passed": true/false,
  "issues": [
    {
      "severity": "critical|major|minor",
      "description": "What's wrong",
      "location": "Where on the page",
      "expected": "What should be there",
      "actual": "What you see",
      "fix_required": "Specific file and change needed"
    }
  ],
  "screenshots_taken": ["paths to saved screenshots"],
  "pages_checked": ["URLs visited"],
  "summary": "Brief verification summary"
}
```

If the feature is visually working and meets requirements, set `passed: true`.
If there are issues, set `passed: false` and describe exactly what needs to change.

Now verify the preview at {$previewUrl}
PROMPT;
            }

            // Write verification prompt to temp file
            $verifyPromptFile = "{$homeDir}/verify_prompt_{$iteration}.txt";
            file_put_contents($verifyPromptFile, $verifyPrompt);

            // Build Claude command - include iteration in session ID to avoid conflicts
            $claudePath = '/usr/bin/claude';
            $sessionId = $this->generateUuidV5('f47ac10b-58cc-4372-a567-0e02b2c3d479', "verify-{$previewUrl}-iter{$iteration}");

            $cmd = "{$claudePath} --print --dangerously-skip-permissions --session-id " . escapeshellarg($sessionId);

            $env = [
                'ANTHROPIC_API_KEY' => $apiKey,
                'HOME' => $homeDir,
                'PATH' => '/home/claudeuser/.bun/bin:/usr/local/bin:/usr/bin:/bin',
                'TERM' => 'xterm-256color'
            ];

            $descriptors = [
                0 => ['file', $verifyPromptFile, 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            fwrite($logHandle, "Starting Claude verification session...\n");
            $startTime = time();
            $process = proc_open($cmd, $descriptors, $pipes, $workDir, $env);

            if (!is_resource($process)) {
                fwrite($logHandle, "ERROR: Failed to start Claude verification process\n");
                return ['passed' => false, 'issues' => ['Failed to start verification'], 'iterations' => $iteration];
            }

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $output = '';
            $maxWait = 480; // 8 minute timeout for verification

            while (true) {
                $status = proc_get_status($process);

                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);

                if ($stdout) {
                    $output .= $stdout;
                    fwrite($logHandle, $stdout);
                }
                if ($stderr) {
                    $output .= $stderr;
                    fwrite($logHandle, $stderr);
                }

                if (!$status['running']) {
                    $stdout = stream_get_contents($pipes[1]);
                    $stderr = stream_get_contents($pipes[2]);
                    if ($stdout) $output .= $stdout;
                    if ($stderr) $output .= $stderr;
                    break;
                }

                if ((time() - $startTime) > $maxWait) {
                    proc_terminate($process, 9);
                    fwrite($logHandle, "TIMEOUT: Verification killed after 8 minutes\n");
                    break;
                }

                usleep(100000);
            }

            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            @unlink($verifyPromptFile);

            // Parse verification result
            $result = $this->parseVerificationResult($output);

            // Track credit balance errors across iterations
            if (!empty($result['credit_error'])) {
                $creditErrorDetected = true;
                fwrite($logHandle, "\n⚠️ Credit balance error detected - API credits may be low\n");
            }

            if ($result['passed']) {
                fwrite($logHandle, "\n✅ Verification PASSED on iteration {$iteration}\n");

                // Upload screenshots to Jira as proof of verification
                $uploadedScreenshots = [];
                if (!empty($issueKey) && !empty($cloudId) && !empty($jiraOAuthToken)) {
                    $uploadedScreenshots = $this->uploadScreenshotsToJira($homeDir, $issueKey, $cloudId, $jiraOAuthToken, $logHandle);
                }

                $returnResult = [
                    'passed' => true,
                    'issues' => [],
                    'iterations' => $iteration,
                    'summary' => $result['summary'] ?? 'All checks passed',
                    'screenshots_uploaded' => $uploadedScreenshots
                ];
                if ($creditErrorDetected) {
                    $returnResult['credit_error'] = true;
                }
                return $returnResult;
            }

            // Verification failed - log issues
            $issues = $result['issues'] ?? [];
            $allIssues = array_merge($allIssues, $issues);

            fwrite($logHandle, "\n❌ Verification FAILED - Found " . count($issues) . " issues:\n");
            foreach ($issues as $issue) {
                $severity = $issue['severity'] ?? 'unknown';
                $desc = $issue['description'] ?? 'No description';
                fwrite($logHandle, "  [{$severity}] {$desc}\n");
            }

            // If not last iteration, run fix cycle
            if ($iteration < $maxIterations) {
                fwrite($logHandle, "\nAttempting to fix issues...\n");
                $fixed = $this->runFixCycle($homeDir, $workDir, $issues, $issueData, $shopifySettings, $apiKey, $logHandle);

                if ($fixed) {
                    // Re-push theme after fixes
                    fwrite($logHandle, "Re-pushing theme after fixes...\n");
                    $this->repushThemeForVerification($workDir, $shopifySettings, $themeId, $logHandle);
                    // Wait a moment for Shopify to process
                    sleep(5);
                }
            }
        }

        // Max iterations reached - still upload screenshots as evidence
        fwrite($logHandle, "\n⚠️ Max iterations reached. Verification incomplete.\n");

        $uploadedScreenshots = [];
        if (!empty($issueKey) && !empty($cloudId) && !empty($jiraOAuthToken)) {
            $uploadedScreenshots = $this->uploadScreenshotsToJira($homeDir, $issueKey, $cloudId, $jiraOAuthToken, $logHandle);
        }

        $returnResult = [
            'passed' => false,
            'issues' => $allIssues,
            'iterations' => $iteration,
            'summary' => 'Max iterations reached with unresolved issues',
            'screenshots_uploaded' => $uploadedScreenshots
        ];
        if ($creditErrorDetected) {
            $returnResult['credit_error'] = true;
        }
        return $returnResult;
    }

    /**
     * Parse verification result JSON from Claude output
     */
    private function parseVerificationResult(string $output): array {
        // Check for credit balance error first
        $creditError = false;
        if (stripos($output, 'Credit balance is too low') !== false ||
            stripos($output, 'insufficient credits') !== false ||
            stripos($output, 'credit_balance') !== false) {
            $creditError = true;
        }

        // Look for JSON block in output
        if (preg_match('/```json\s*(\{[\s\S]*?\})\s*```/m', $output, $matches)) {
            $json = json_decode($matches[1], true);
            if ($json) {
                if ($creditError) {
                    $json['credit_error'] = true;
                }
                return $json;
            }
        }

        // Try to find raw JSON
        if (preg_match('/\{[^{}]*"passed"\s*:\s*(true|false)[^{}]*\}/s', $output, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json) {
                if ($creditError) {
                    $json['credit_error'] = true;
                }
                return $json;
            }
        }

        // Default to failed if we can't parse
        $result = ['passed' => false, 'issues' => ['Could not parse verification result']];
        if ($creditError) {
            $result['credit_error'] = true;
        }
        return $result;
    }

    /**
     * Run fix cycle for issues found during verification
     */
    private function runFixCycle($homeDir, $workDir, $issues, $issueData, $shopifySettings, $apiKey, $logHandle): bool {
        if (empty($issues)) {
            return false;
        }

        $issuesList = '';
        foreach ($issues as $i => $issue) {
            $num = $i + 1;
            $severity = $issue['severity'] ?? 'unknown';
            $desc = $issue['description'] ?? 'No description';
            $location = $issue['location'] ?? 'Unknown location';
            $expected = $issue['expected'] ?? '';
            $actual = $issue['actual'] ?? '';

            $issuesList .= "{$num}. [{$severity}] {$desc}\n";
            $issuesList .= "   Location: {$location}\n";
            if ($expected) $issuesList .= "   Expected: {$expected}\n";
            if ($actual) $issuesList .= "   Actual: {$actual}\n";
            $issuesList .= "\n";
        }

        $summary = $issueData['summary'] ?? '';

        $fixPrompt = <<<PROMPT
You are fixing issues found during visual QA verification of a Shopify theme.

## Original Task
{$summary}

## Issues Found During Verification
{$issuesList}

## Your Task
1. Analyze each issue and identify the ROOT CAUSE
2. Make the necessary fixes - DO WHAT IT TAKES to make the feature visible
3. Focus on critical issues first
4. Ensure fixes don't break other functionality

## CRITICAL: Shopify Theme Structure
Features may not be visible because:
1. **Schema defaults only affect NEW sections** - changing `default: true` won't enable on existing sections
2. **Existing section settings are in JSON files:**
   - `templates/*.json` - Page templates with section settings
   - `config/settings_data.json` - Theme-wide settings
3. **To enable a feature on existing sections**, you MUST:
   - Find the section in the appropriate JSON template file
   - Add or modify the setting (e.g., `"enable_gradient": true`)

## Example: Enabling a feature in existing sections
If you need to enable `enable_gradient` on an existing image-banner section:
1. Find the template file (e.g., `templates/index.json`)
2. Locate the section block
3. Add the setting: `"enable_gradient": true`

## Guidelines
- The theme files are in the current directory
- Check `templates/` and `config/` directories for JSON presets
- Make targeted fixes - modify the specific settings needed
- If a feature exists in code but isn't visible, the JSON presets likely need updating

Fix these issues now. Make the feature VISUALLY WORKING, not just coded.
PROMPT;

        $fixPromptFile = "{$homeDir}/fix_prompt.txt";
        file_put_contents($fixPromptFile, $fixPrompt);

        $claudePath = '/usr/bin/claude';
        $sessionId = $this->generateUuidV5('f47ac10b-58cc-4372-a567-0e02b2c3d479', "fix-" . md5(json_encode($issues) . time()));

        $cmd = "{$claudePath} --print --dangerously-skip-permissions --session-id " . escapeshellarg($sessionId);

        $env = [
            'ANTHROPIC_API_KEY' => $apiKey,
            'HOME' => $homeDir,
            'PATH' => '/home/claudeuser/.bun/bin:/usr/local/bin:/usr/bin:/bin',
            'TERM' => 'xterm-256color'
        ];

        $descriptors = [
            0 => ['file', $fixPromptFile, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        fwrite($logHandle, "Starting Claude fix session...\n");
        $process = proc_open($cmd, $descriptors, $pipes, $workDir, $env);

        if (!is_resource($process)) {
            fwrite($logHandle, "ERROR: Failed to start fix process\n");
            @unlink($fixPromptFile);
            return false;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $startTime = time();
        $maxWait = 300;

        while (true) {
            $status = proc_get_status($process);

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            if ($stdout) fwrite($logHandle, $stdout);
            if ($stderr) fwrite($logHandle, $stderr);

            if (!$status['running']) {
                break;
            }

            if ((time() - $startTime) > $maxWait) {
                proc_terminate($process, 9);
                fwrite($logHandle, "TIMEOUT: Fix process killed\n");
                break;
            }

            usleep(100000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        @unlink($fixPromptFile);

        fwrite($logHandle, "Fix cycle completed (exit code: {$exitCode})\n");
        return $exitCode === 0;
    }

    /**
     * Re-push theme after fixes
     */
    private function repushThemeForVerification($workDir, $shopifySettings, $themeId, $logHandle) {
        $repoDir = $this->findClonedRepoDir($workDir, $logHandle);
        if (!$repoDir) {
            fwrite($logHandle, "Could not find repo dir for re-push\n");
            return false;
        }

        $shopDomain = $shopifySettings['shop_domain'] ?? '';
        $accessToken = $shopifySettings['access_token'] ?? '';

        if (empty($shopDomain) || empty($accessToken) || empty($themeId)) {
            fwrite($logHandle, "Missing credentials or theme ID for re-push\n");
            return false;
        }

        // Push to the specific theme by ID
        $cmd = sprintf(
            'shopify theme push --store=%s --password=%s --theme=%s --path=%s --json 2>&1',
            escapeshellarg($shopDomain),
            escapeshellarg($accessToken),
            escapeshellarg($themeId),
            escapeshellarg($repoDir)
        );

        fwrite($logHandle, "Re-pushing to theme {$themeId}...\n");
        $output = [];
        exec($cmd, $output, $returnCode);

        if ($returnCode === 0) {
            fwrite($logHandle, "Theme re-pushed successfully\n");
            return true;
        } else {
            fwrite($logHandle, "Theme re-push failed: " . implode("\n", $output) . "\n");
            return false;
        }
    }

    /**
     * Upload verification screenshots to Jira ticket as attachments
     *
     * @param string $homeDir The job home directory containing screenshots/
     * @param string $issueKey The Jira issue key (e.g., PROJ-123)
     * @param string $cloudId The Jira Cloud ID
     * @param string $jiraOAuthToken The Jira OAuth access token
     * @param resource $logHandle Log file handle
     * @return array List of uploaded filenames
     */
    private function uploadScreenshotsToJira($homeDir, $issueKey, $cloudId, $jiraOAuthToken, $logHandle): array {
        $screenshotsDir = "{$homeDir}/screenshots";
        $uploaded = [];

        if (!is_dir($screenshotsDir)) {
            fwrite($logHandle, "No screenshots directory found at {$screenshotsDir}\n");
            return $uploaded;
        }

        // Find all image files in screenshots directory
        $allImages = glob("{$screenshotsDir}/*.{png,jpg,jpeg,gif}", GLOB_BRACE);

        if (empty($allImages)) {
            fwrite($logHandle, "No screenshot images found in {$screenshotsDir}\n");
            return $uploaded;
        }

        // Filter to only proof-of-work screenshots (proof-*, before-*, after-*)
        $imageFiles = array_filter($allImages, function($path) {
            $filename = basename($path);
            return preg_match('/^(proof-|before-|after-)/i', $filename);
        });

        $skippedCount = count($allImages) - count($imageFiles);
        if ($skippedCount > 0) {
            fwrite($logHandle, "\nSkipping {$skippedCount} non-proof screenshots (missing proof-/before-/after- prefix)\n");
        }

        if (empty($imageFiles)) {
            fwrite($logHandle, "No proof-of-work screenshots found (need proof-*, before-*, or after-* prefix)\n");
            return $uploaded;
        }

        fwrite($logHandle, "\n=== Uploading " . count($imageFiles) . " proof screenshots to Jira ===\n");

        // Jira Cloud attachment endpoint
        $url = "https://api.atlassian.com/ex/jira/{$cloudId}/rest/api/3/issue/{$issueKey}/attachments";

        foreach ($imageFiles as $imagePath) {
            $filename = basename($imagePath);
            $filesize = filesize($imagePath);

            // Skip files larger than 10MB
            if ($filesize > 10 * 1024 * 1024) {
                fwrite($logHandle, "Skipping {$filename} - too large ({$filesize} bytes)\n");
                continue;
            }

            fwrite($logHandle, "Uploading: {$filename} ({$filesize} bytes)...");

            // Use cURL for multipart upload
            $ch = curl_init($url);

            $cfile = new \CURLFile($imagePath, mime_content_type($imagePath), $filename);

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => ['file' => $cfile],
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$jiraOAuthToken}",
                    "X-Atlassian-Token: no-check",
                    "Accept: application/json"
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200) {
                fwrite($logHandle, " ✅ uploaded\n");
                $uploaded[] = $filename;
            } else {
                fwrite($logHandle, " ❌ failed (HTTP {$httpCode})\n");
                if ($error) {
                    fwrite($logHandle, "  cURL error: {$error}\n");
                }
                if ($response) {
                    $respData = json_decode($response, true);
                    if (isset($respData['errorMessages'])) {
                        fwrite($logHandle, "  Jira error: " . implode(', ', $respData['errorMessages']) . "\n");
                    }
                }
            }
        }

        fwrite($logHandle, "=== Screenshot upload complete: " . count($uploaded) . "/" . count($imageFiles) . " uploaded ===\n\n");

        return $uploaded;
    }

    /**
     * Post Shopify preview URL to Jira ticket
     */
    private function postShopifyPreviewToJira($issueKey, $previewUrl, $themeId, $cloudId, $jiraEmail, $jiraToken, $logHandle) {
        // Build Jira comment using ADF format
        $comment = [
            'body' => [
                'version' => 1,
                'type' => 'doc',
                'content' => [
                    [
                        'type' => 'heading',
                        'attrs' => ['level' => 3],
                        'content' => [
                            ['type' => 'text', 'text' => '🛍️ Shopify Preview Ready', 'marks' => [['type' => 'strong']]]
                        ]
                    ],
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => 'A development theme preview has been created for this ticket.']
                        ]
                    ],
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => '👉 Preview URL: '],
                            [
                                'type' => 'text',
                                'text' => $previewUrl,
                                'marks' => [['type' => 'link', 'attrs' => ['href' => $previewUrl]]]
                            ]
                        ]
                    ],
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => "Theme ID: {$themeId}", 'marks' => [['type' => 'code']]]
                        ]
                    ],
                    [
                        'type' => 'rule'
                    ],
                    [
                        'type' => 'paragraph',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'This is an unpublished development theme. Changes will not affect the live store.',
                                'marks' => [['type' => 'em']]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        // $cloudId may be the full Jira API URL or just the cloud ID
        // Handle both cases
        if (strpos($cloudId, 'https://') === 0) {
            // It's already the full API base URL
            $jiraApi = "{$cloudId}/rest/api/3/issue/{$issueKey}/comment";
        } else {
            // It's just the cloud ID
            $jiraApi = "https://api.atlassian.com/ex/jira/{$cloudId}/rest/api/3/issue/{$issueKey}/comment";
        }

        $ch = curl_init($jiraApi);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($comment));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $jiraToken
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            fwrite($logHandle, "Posted Shopify preview to Jira ticket {$issueKey}\n");
        } else {
            fwrite($logHandle, "Failed to post to Jira (HTTP {$httpCode}): {$response}\n");
        }
    }

    /**
     * Recursively delete a directory
     */
    private function recursiveDelete($dir) {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object)) {
                        $this->recursiveDelete($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }

    /**
     * Generate a deterministic UUID v5 from a namespace and name
     * This ensures the same input always produces the same UUID
     */
    private function generateUuidV5(string $namespace, string $name): string {
        // Parse namespace UUID
        $nhex = str_replace(['-', '{', '}'], '', $namespace);
        $nstr = '';
        for ($i = 0; $i < strlen($nhex); $i += 2) {
            $nstr .= chr(hexdec(substr($nhex, $i, 2)));
        }

        // Calculate hash
        $hash = sha1($nstr . $name);

        return sprintf(
            '%08s-%04s-%04x-%04x-%12s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000, // Version 5
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000, // Variant
            substr($hash, 20, 12)
        );
    }

    /**
     * Pre-process Jira attachment images before Claude runs
     * Downloads images, resizes them with ImageMagick, and returns updated attachment info
     *
     * @param array $issueData Issue data containing attachment_info
     * @param string $jiraOAuthToken OAuth token for Jira API
     * @param string $homeDir Working directory for this job
     * @return array Updated issueData with preprocessed images
     */
    private function preprocessAttachmentImages(array $issueData, string $jiraOAuthToken, string $homeDir): array {
        $attachmentInfo = $issueData['attachment_info'] ?? '';
        if (empty($attachmentInfo)) {
            return $issueData;
        }

        // Create attachments directory
        $attachDir = "{$homeDir}/attachments";
        @mkdir($attachDir, 0755, true);

        // Parse attachment info to extract image URLs
        // Format: "- filename.png (image/png, 12345 bytes)\n  Download: https://..."
        $pattern = '/- ([^\(]+) \(([^,]+), (\d+) bytes\)\s+Download: (https?:\/\/[^\s]+)/';

        if (!preg_match_all($pattern, $attachmentInfo, $matches, PREG_SET_ORDER)) {
            return $issueData;
        }

        $processedInfo = "## Attachments (Pre-processed for optimal viewing)\n";
        $processedInfo .= "Images have been downloaded and optimized. View them from these local paths:\n\n";

        $imageTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp'];
        $convertPath = '/usr/bin/convert';
        $hasConvert = file_exists($convertPath);

        foreach ($matches as $match) {
            $filename = trim($match[1]);
            $mimeType = trim($match[2]);
            $size = (int)$match[3];
            $url = trim($match[4]);

            // Check if it's an image that needs processing
            $isImage = in_array(strtolower($mimeType), $imageTypes);
            $isLarge = $size > 500000; // Over 500KB

            if ($isImage && $isLarge && $hasConvert) {
                // Download the image
                $tempFile = "{$attachDir}/original_" . preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
                $optimizedFile = "{$attachDir}/" . preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

                // Use curl to download with auth
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer {$jiraOAuthToken}"
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 60);
                $imageData = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 && !empty($imageData)) {
                    file_put_contents($tempFile, $imageData);

                    // Resize with ImageMagick: max 1200px width, maintain aspect ratio, optimize quality
                    $cmd = sprintf(
                        '%s %s -resize "1200x>" -quality 85 %s 2>&1',
                        escapeshellarg($convertPath),
                        escapeshellarg($tempFile),
                        escapeshellarg($optimizedFile)
                    );
                    exec($cmd, $output, $returnCode);

                    if ($returnCode === 0 && file_exists($optimizedFile)) {
                        $newSize = filesize($optimizedFile);
                        $processedInfo .= "- **{$filename}** (optimized: " . round($newSize / 1024) . "KB, original: " . round($size / 1024) . "KB)\n";
                        $processedInfo .= "  Local path: {$optimizedFile}\n";

                        // Clean up original
                        @unlink($tempFile);
                    } else {
                        // Fallback: use original
                        @rename($tempFile, $optimizedFile);
                        $processedInfo .= "- **{$filename}** (original: " . round($size / 1024) . "KB)\n";
                        $processedInfo .= "  Local path: {$optimizedFile}\n";
                    }
                } else {
                    // Download failed, keep original URL
                    $processedInfo .= "- {$filename} ({$mimeType}, {$size} bytes)\n";
                    $processedInfo .= "  Download: {$url}\n";
                }
            } elseif ($isImage && $hasConvert) {
                // Small image - download without resizing
                $localFile = "{$attachDir}/" . preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer {$jiraOAuthToken}"
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 60);
                $imageData = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 && !empty($imageData)) {
                    file_put_contents($localFile, $imageData);
                    $processedInfo .= "- **{$filename}** (" . round($size / 1024) . "KB)\n";
                    $processedInfo .= "  Local path: {$localFile}\n";
                } else {
                    $processedInfo .= "- {$filename} ({$mimeType}, {$size} bytes)\n";
                    $processedInfo .= "  Download: {$url}\n";
                }
            } else {
                // Non-image or no convert available, keep original
                $processedInfo .= "- {$filename} ({$mimeType}, {$size} bytes)\n";
                $processedInfo .= "  Download: {$url}\n";
            }
        }

        $processedInfo .= "\nTo view an image, use: `cat <local_path>` (Claude can read image files directly)\n";

        $issueData['attachment_info'] = $processedInfo;
        return $issueData;
    }
}
