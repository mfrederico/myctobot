#!/usr/bin/env php
<?php
/**
 * MyCTOBot Background Analysis Runner
 *
 * Can be run via cron for scheduled analyses or triggered by the web UI.
 *
 * COMMAND LINE USAGE:
 * -------------------
 * # Run analysis for a specific board (requires --script flag):
 * php scripts/cron-analysis.php --script --member=3 --board=1 --verbose
 *
 * # With status filter:
 * php scripts/cron-analysis.php --script --member=3 --board=1 --status-filter="To Do,In Progress"
 *
 * # With job ID for status tracking (used by web UI):
 * php scripts/cron-analysis.php --script --member=3 --board=1 --job=m3_123456_abc123
 *
 * # Run all scheduled analyses (cron mode):
 * php scripts/cron-analysis.php --script --all-scheduled
 *
 * OPTIONS:
 * --------
 *   --script        REQUIRED for CLI execution (bypasses controller routing)
 *   --member        Member ID to run analysis for
 *   --board         Board ID to analyze
 *   --job           Job ID for status tracking (optional)
 *   --status-filter Comma-separated list of statuses (default: from board settings)
 *   --all-scheduled Run scheduled analyses for all boards (cron mode)
 *   --verbose       Show detailed output
 *   --help          Show this help message
 *
 * NOTES:
 * ------
 * - Pro users get image analysis (screenshots, mockups attached to tickets)
 * - Comments on tickets are analyzed for additional context
 * - Results are cached per-ticket (clear cache to re-analyze)
 */

// Determine paths
$scriptDir = dirname(__FILE__);
$baseDir = dirname($scriptDir);

// Change to base directory
chdir($baseDir);

// Parse command line options
$options = getopt('', ['member:', 'board:', 'job:', 'status-filter:', 'all-scheduled', 'verbose', 'help', 'script']);

if (isset($options['help'])) {
    echo "MyCTOBot Background Analysis Runner\n\n";
    echo "Usage:\n";
    echo "  php scripts/cron-analysis.php --member=ID --board=ID --job=JOB_ID\n";
    echo "  php scripts/cron-analysis.php --all-scheduled\n\n";
    echo "Options:\n";
    echo "  --member        Member ID to run analysis for\n";
    echo "  --board         Board ID to analyze\n";
    echo "  --job           Job ID for status tracking\n";
    echo "  --status-filter Comma-separated status filter\n";
    echo "  --all-scheduled Run scheduled analyses (cron mode)\n";
    echo "  --verbose       Show detailed output\n";
    echo "  --help          Show this help message\n";
    exit(0);
}

$verbose = isset($options['verbose']);
$allScheduled = isset($options['all-scheduled']);

if ($verbose) {
    echo "MyCTOBot Analysis Runner\n";
    echo "========================\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n";
    echo "Base: {$baseDir}\n\n";
}

// Load vendor autoload first
require_once $baseDir . '/vendor/autoload.php';

// Load bootstrap class and instantiate it
require_once $baseDir . '/bootstrap.php';

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\plugins\AtlassianAuth;
use \app\services\UserDatabaseService;
use \app\services\JiraClient;
use \app\services\ClaudeClient;
use \app\services\AnalysisStatusService;
use \app\analyzers\PriorityAnalyzer;
use \app\analyzers\ClarityAnalyzer;

// Load required files
require_once $baseDir . '/lib/plugins/AtlassianAuth.php';
require_once $baseDir . '/services/UserDatabaseService.php';
require_once $baseDir . '/services/JiraClient.php';
require_once $baseDir . '/services/ClaudeClient.php';
require_once $baseDir . '/services/AnalysisStatusService.php';
require_once $baseDir . '/analyzers/PriorityAnalyzer.php';
require_once $baseDir . '/analyzers/ClarityAnalyzer.php';

try {
    $bootstrap = new \app\Bootstrap($baseDir . '/conf/config.ini');

    if ($verbose) {
        echo "Application initialized\n\n";
    }

    // Mode: Run a specific analysis for a member/board
    if (!$allScheduled) {
        $memberId = $options['member'] ?? null;
        $boardId = $options['board'] ?? null;
        $jobId = $options['job'] ?? null;
        $statusFilter = $options['status-filter'] ?? null;

        if (!$memberId || !$boardId) {
            echo "Error: --member and --board are required\n";
            exit(1);
        }

        runAnalysis((int)$memberId, (int)$boardId, $jobId, $statusFilter, $verbose);
        exit(0);
    }

    // Mode: Run all scheduled analyses (for cron)
    runAllScheduledAnalyses($verbose);
    exit(0);

} catch (Exception $e) {
    $message = "FATAL ERROR: " . $e->getMessage();

    if ($verbose) {
        echo "\n{$message}\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }

    // Try to log if possible
    try {
        $logger = Flight::log();
        if ($logger) {
            $logger->error($message, ['trace' => $e->getTraceAsString()]);
        }
    } catch (Exception $logError) {
        // Ignore logging errors
    }

    exit(1);
}

/**
 * Run analysis for a specific member/board
 */
function runAnalysis(int $memberId, int $boardId, ?string $jobId, ?string $statusFilter, bool $verbose): void {
    $logger = Flight::log();

    // Verify member exists
    $member = R::load('member', $memberId);
    if (!$member->id) {
        throw new Exception("Member not found: {$memberId}");
    }

    if ($verbose) {
        echo "Running analysis for member {$memberId}, board {$boardId}\n";
    }

    // Update status: Starting
    if ($jobId) {
        AnalysisStatusService::updateStatus($jobId, 'Initializing analysis...', 5, 'running');
    }

    // Initialize user database
    $userDb = new UserDatabaseService($memberId);

    // Get board details
    $board = $userDb->getBoard($boardId);
    if (!$board) {
        if ($jobId) {
            AnalysisStatusService::fail($jobId, 'Board not found');
        }
        throw new Exception("Board not found: {$boardId}");
    }

    // Determine status filter
    if (!$statusFilter) {
        $statusFilter = $board['status_filter'] ?? 'To Do';
    }
    $statusArray = array_map('trim', explode(',', $statusFilter));

    if ($verbose) {
        echo "Board: {$board['board_name']} ({$board['project_key']})\n";
        echo "Status filter: {$statusFilter}\n";
    }

    // Update status: Connecting to Jira
    if ($jobId) {
        AnalysisStatusService::updateStatus($jobId, 'Connecting to Jira...', 10, 'running');
    }

    // Initialize Jira client
    $jiraClient = new JiraClient($memberId, $board['cloud_id']);

    // Update status: Fetching issues
    if ($jobId) {
        AnalysisStatusService::updateStatus($jobId, 'Fetching sprint issues...', 20, 'running');
    }

    // Fetch sprint issues
    $issues = $jiraClient->getCurrentSprintIssues($board['project_key'], $statusArray);

    if (empty($issues)) {
        if ($jobId) {
            AnalysisStatusService::fail($jobId, 'No issues found in the current sprint');
        }
        throw new Exception('No issues found in the current sprint');
    }

    if ($verbose) {
        echo "Found " . count($issues) . " issues\n";
    }

    // Update status: AI Analysis starting
    if ($jobId) {
        AnalysisStatusService::updateStatus($jobId, 'Starting AI analysis (' . count($issues) . ' issues)...', 30, 'running');
    }

    // Initialize Claude client and analyzers
    $claudeClient = new ClaudeClient();
    $priorityAnalyzer = new PriorityAnalyzer($claudeClient);

    // Check if Pro user has image analysis enabled
    $isPro = Flight::isPro();
    $includeImages = $isPro; // Image analysis is a Pro feature

    // Pass JiraClient to ClarityAnalyzer for image fetching (Pro feature)
    $clarityAnalyzer = new ClarityAnalyzer($claudeClient, $includeImages ? $jiraClient : null, $includeImages);

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
    if ($isPro || Flight::canAccessFeature(\app\services\TierFeatures::FEATURE_CLARITY_ANALYSIS)) {
        $imageNote = $includeImages ? ' (with image analysis)' : '';
        if ($jobId) {
            AnalysisStatusService::updateStatus($jobId, 'Analyzing ticket clarity' . $imageNote . '...', 50, 'running');
        }

        $clarityResult = $clarityAnalyzer->analyzeWithCache(
            $issues,
            $boardId,
            $userDb,
            $clarityThreshold
        );

        if ($verbose) {
            echo "Clarity analysis: {$clarityResult['analyzed_count']} analyzed, {$clarityResult['cached_count']} cached\n";
        }
    }

    // Update status: Priority analysis
    if ($jobId) {
        AnalysisStatusService::updateStatus($jobId, 'Generating priority recommendations...', 70, 'running');
    }

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
        if ($jobId) {
            AnalysisStatusService::fail($jobId, 'Priority analysis failed: ' . ($priorityResult['error'] ?? 'Unknown error'));
        }
        throw new Exception('Analysis failed: ' . ($priorityResult['error'] ?? 'Unknown error'));
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
    if ($jobId) {
        AnalysisStatusService::updateStatus($jobId, 'Generating report...', 85, 'running');
    }

    // Get Jira site URL for creating ticket links
    $jiraSiteUrl = AtlassianAuth::getSiteUrl($memberId, $board['cloud_id']);

    // Generate markdown report with Jira links
    $markdown = $priorityAnalyzer->generateDailyLog($priorityResult, $jiraSiteUrl);

    // Append clarification section if there are items
    if ($clarityResult && !empty($clarityResult['clarifications_needed'])) {
        $markdown .= generateClarificationMarkdown($clarityResult['clarifications_needed'], $jiraSiteUrl);
    }

    // Update status: Saving results
    if ($jobId) {
        AnalysisStatusService::updateStatus($jobId, 'Saving analysis results...', 95, 'running');
    }

    // Store results
    $priorityResult['status_filter'] = $statusFilter;
    $analysisId = $userDb->storeAnalysis($boardId, 'priorities', $priorityResult, $markdown);

    $logger->info('Background analysis completed', [
        'member_id' => $memberId,
        'board_id' => $boardId,
        'analysis_id' => $analysisId,
        'issue_count' => count($issues),
        'job_id' => $jobId
    ]);

    // Update status: Complete
    if ($jobId) {
        AnalysisStatusService::complete($jobId, $analysisId);
    }

    if ($verbose) {
        echo "Analysis complete! ID: {$analysisId}\n";
    }
}

/**
 * Run all scheduled analyses (cron mode)
 */
function runAllScheduledAnalyses(bool $verbose): void {
    // This could be expanded to run scheduled analyses based on board settings
    // For now, just a placeholder that could be implemented later
    if ($verbose) {
        echo "Scheduled analysis mode not yet implemented\n";
    }
}

/**
 * Generate markdown section for clarification items
 */
function generateClarificationMarkdown(array $clarifications, ?string $jiraSiteUrl = null): string {
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
