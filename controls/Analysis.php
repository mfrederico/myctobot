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

use \app\services\AnalysisService;

class Analysis extends BaseControls\Control {

    private $userDb;

    private function initUserDb() {
        if (!$this->userDb && $this->member && !empty($this->member->ceobot_db)) {
            try {
                $this->userDb = new UserDatabaseService($this->member->id);
            } catch (Exception $e) {
                $this->logger->error('Failed to initialize user database: ' . $e->getMessage());
                return false;
            }
        }
        return $this->userDb !== null;
    }

    /**
     * Analysis dashboard - list boards and recent analyses
     */
    public function index() {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->flash('error', 'User database not initialized');
            Flight::redirect('/dashboard');
            return;
        }

        $boards = $this->userDb->getEnabledBoards();
        $recentAnalyses = $this->userDb->getAllRecentAnalyses(20);

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

        $board = $this->userDb->getBoard($boardId);
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
            $cmd = sprintf(
                'php %s --script --secret=%s --member=%d --board=%d --job=%s --status-filter=%s > /dev/null 2>&1 &',
                escapeshellarg($scriptPath),
                escapeshellarg($cronSecret),
                $this->member->id,
                (int)$boardId,
                escapeshellarg($jobId),
                escapeshellarg($statusFilter)
            );

            // Execute in background
            exec($cmd);

            $this->logger->info('Background analysis started', [
                'member_id' => $this->member->id,
                'board_id' => $boardId,
                'job_id' => $jobId
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
            Flight::redirect('/dashboard');
            return;
        }

        // Analysis ID comes from URL: /analysis/view/{id}
        $analysisId = $params['operation']->name ?? $this->getParam('id');
        if (!$analysisId) {
            $this->flash('error', 'No analysis specified');
            Flight::redirect('/analysis');
            return;
        }

        $analysis = $this->userDb->getAnalysis($analysisId);
        if (!$analysis) {
            $this->flash('error', 'Analysis not found');
            Flight::redirect('/analysis');
            return;
        }

        // Get board info
        $board = $this->userDb->getBoard($analysis['board_id']);

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

        $analysis = $this->userDb->getAnalysis($analysisId);
        if (!$analysis) {
            if ($isAjax) {
                $this->jsonError('Analysis not found');
            } else {
                $this->flash('error', 'Analysis not found');
                Flight::redirect('/analysis');
            }
            return;
        }

        $board = $this->userDb->getBoard($analysis['board_id']);
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
                $this->userDb->logDigest(
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
        $analyses = $this->userDb->getRecentAnalyses($boardId, $limit);

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

        $board = $this->userDb->getBoard($boardId);
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
            $analysis = $this->userDb->getRecentAnalyses($job->board_id, 1);
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
            $analysis = $this->userDb->getRecentAnalyses($job->board_id, 1);
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

        // Return accepted immediately
        header('Content-Type: application/json');
        http_response_code(202);
        echo json_encode([
            'success' => true,
            'job_id' => $jobId,
            'status' => 'running',
            'message' => 'Digest analysis started (PHP shard)'
        ]);

        // Flush output and continue processing in background
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            ob_end_flush();
            flush();
        }

        // Run Claude Code analysis
        $this->runClaudeShardAnalysis(
            $jobId, $apiKey, $prompt,
            $jiraHost, $jiraEmail, $jiraToken,
            $callbackUrl, $callbackApiKey
        );
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
        $jiraSiteUrl = $input['jira_site_url'] ?? '';
        $githubToken = $input['github_token'] ?? '';
        $callbackUrl = $input['callback_url'] ?? '';
        $callbackApiKey = $input['callback_api_key'] ?? '';
        $action = $input['action'] ?? 'implement'; // implement, retry, resume
        $existingBranch = $input['existing_branch'] ?? null; // Branch affinity

        // Return accepted immediately
        header('Content-Type: application/json');
        http_response_code(202);
        echo json_encode([
            'success' => true,
            'job_id' => $jobId,
            'status' => 'running',
            'message' => 'AI Developer started (Claude Code CLI)'
        ]);

        // Flush output and continue processing in background
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            ob_end_flush();
            flush();
        }

        // Build the prompt
        $prompt = $this->buildAIDevPrompt($issueKey, $issueData, $repoConfig, $jiraHost, $jiraSiteUrl, $action, $existingBranch);

        // Run Claude Code
        $this->runClaudeAIDev(
            $jobId, $apiKey, $prompt,
            $jiraHost, $jiraEmail, $jiraToken,
            $githubToken, $repoConfig,
            $callbackUrl, $callbackApiKey
        );
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
     * Run Claude Code for AI Developer implementation
     */
    private function runClaudeAIDev($jobId, $apiKey, $prompt, $jiraHost, $jiraEmail, $jiraToken, $githubToken, $repoConfig, $callbackUrl, $callbackApiKey) {
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

        // Create working directory for repo
        $workDir = "{$homeDir}/repo";
        @mkdir($workDir, 0755, true);

        // Write prompt to temp file
        $promptFile = "{$homeDir}/prompt.txt";
        file_put_contents($promptFile, $prompt);

        // Build environment with all credentials
        $env = [
            'ANTHROPIC_API_KEY' => $apiKey,
            'HOME' => $homeDir,
            'JIRA_API_TOKEN' => $jiraToken,
            'JIRA_HOST' => $jiraHost,
            'JIRA_EMAIL' => $jiraEmail,
            'GITHUB_TOKEN' => $githubToken,
            'PATH' => '/home/claudeuser/.bun/bin:/usr/local/bin:/usr/bin:/bin',
            'GIT_TERMINAL_PROMPT' => '0'
        ];

        $envStr = '';
        foreach ($env as $k => $v) {
            $envStr .= "$k=" . escapeshellarg($v) . " ";
        }

        // Change to work directory for git operations
        $claudePath = '/usr/bin/claude';
        $cmd = "cd " . escapeshellarg($workDir) . " && {$envStr} {$claudePath} --print --dangerously-skip-permissions < " . escapeshellarg($promptFile) . " 2>&1";

        $startTime = time();
        $output = shell_exec($cmd);
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
}
