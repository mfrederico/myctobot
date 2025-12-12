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
use \app\analyzers\PriorityAnalyzer;

require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';
require_once __DIR__ . '/../services/UserDatabaseService.php';
require_once __DIR__ . '/../services/JiraClient.php';
require_once __DIR__ . '/../services/ClaudeClient.php';
require_once __DIR__ . '/../services/MailgunService.php';
require_once __DIR__ . '/../analyzers/PriorityAnalyzer.php';

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
     * Run analysis on a specific board
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
            $this->render('analysis/run', [
                'title' => 'Run Analysis - ' . $board['board_name'],
                'board' => $board
            ]);
            return;
        }

        // Run analysis on POST
        try {
            // Get status filter from form or board defaults
            $statusFilter = $this->getParam('status_filter') ?? $board['status_filter'] ?? 'To Do';
            $statusArray = array_map('trim', explode(',', $statusFilter));

            // Initialize Jira client
            $jiraClient = new JiraClient($this->member->id, $board['cloud_id']);

            // Fetch sprint issues using project key (uses standard Jira REST API with JQL)
            $issues = $jiraClient->getCurrentSprintIssues($board['project_key'], $statusArray);

            if (empty($issues)) {
                $this->flash('warning', 'No issues found in the current sprint with the selected status filter');
                Flight::redirect('/analysis/run/' . $boardId);
                return;
            }

            // Initialize Claude client and analyzer
            $claudeClient = new ClaudeClient();
            $priorityAnalyzer = new PriorityAnalyzer($claudeClient);

            // Run priority analysis
            $priorityResult = $priorityAnalyzer->generateDailyPriorities($issues);

            if (!$priorityResult['success']) {
                throw new Exception('Analysis failed: ' . ($priorityResult['error'] ?? 'Unknown error'));
            }

            // Generate markdown report
            $markdown = $priorityAnalyzer->generateDailyLog($priorityResult);

            // Store results
            $priorityResult['status_filter'] = $statusFilter;
            $analysisId = $this->userDb->storeAnalysis($boardId, 'priorities', $priorityResult, $markdown);

            $this->logger->info('Analysis completed', [
                'member_id' => $this->member->id,
                'board_id' => $boardId,
                'analysis_id' => $analysisId,
                'issue_count' => count($issues)
            ]);

            $this->flash('success', 'Analysis completed successfully for ' . count($issues) . ' issues');
            Flight::redirect('/analysis/view/' . $analysisId);

        } catch (Exception $e) {
            $this->logger->error('Analysis failed: ' . $e->getMessage());
            $this->flash('error', 'Analysis failed: ' . $e->getMessage());
            Flight::redirect('/analysis/run/' . $boardId);
        }
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
}
