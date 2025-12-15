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
require_once __DIR__ . '/../analyzers/PriorityAnalyzer.php';
require_once __DIR__ . '/../analyzers/ClarityAnalyzer.php';

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
            $cmd = sprintf(
                'php %s --script --member=%d --board=%d --job=%s --status-filter=%s > /dev/null 2>&1 &',
                escapeshellarg($scriptPath),
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
}
