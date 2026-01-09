#!/usr/bin/env php
<?php
/**
 * AI Developer Agent Background Runner
 *
 * Usage:
 *   php scripts/ai-dev-agent.php --secret=KEY --member=ID --job=JOB_ID --issue=ISSUE_KEY --cloud=CLOUD_ID --repo=REPO_ID --action=process [--tenant=TENANT]
 *   php scripts/ai-dev-agent.php --secret=KEY --member=ID --job=JOB_ID --action=resume [--comment=COMMENT_ID] [--tenant=TENANT]
 *
 * Options:
 *   --tenant     Tenant slug for multi-tenancy (e.g., gwt). Loads conf/config.{tenant}.ini
 */

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Determine paths
$scriptDir = dirname(__FILE__);
$baseDir = dirname($scriptDir);

// Change to base directory
chdir($baseDir);

// Load vendor autoload first
require_once $baseDir . '/vendor/autoload.php';

// Load bootstrap class and instantiate it
require_once $baseDir . '/bootstrap.php';

// Load required services
require_once $baseDir . '/lib/plugins/AtlassianAuth.php';
require_once $baseDir . '/services/EncryptionService.php';
require_once $baseDir . '/services/JiraClient.php';
require_once $baseDir . '/services/ClaudeClient.php';
require_once $baseDir . '/services/GitHubClient.php';
require_once $baseDir . '/services/GitOperations.php';
require_once $baseDir . '/services/AIDevAgent.php';
require_once $baseDir . '/services/AIDevStatusService.php';

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\services\EncryptionService;
use \app\services\AIDevAgent;
use \app\services\AIDevStatusService;

// Parse command line arguments BEFORE bootstrap (need tenant param for config)
$options = getopt('', [
    'script',
    'secret:',
    'member:',
    'job:',
    'issue:',
    'cloud:',
    'repo:',
    'action:',
    'comment:',
    'branch:',
    'pr:',
    'tenant:',
    'help',
    'verbose'
]);

// Show help
if (isset($options['help'])) {
    echo "AI Developer Agent Background Runner\n\n";
    echo "Usage:\n";
    echo "  Process new ticket:\n";
    echo "    php ai-dev-agent.php --secret=KEY --member=ID --job=JOB_ID --issue=ISSUE --cloud=CLOUD --repo=REPO --action=process [--tenant=TENANT]\n\n";
    echo "  Resume after clarification:\n";
    echo "    php ai-dev-agent.php --secret=KEY --member=ID --job=JOB_ID --action=resume [--comment=COMMENT_ID] [--tenant=TENANT]\n\n";
    echo "  Retry on existing branch:\n";
    echo "    php ai-dev-agent.php --secret=KEY --member=ID --job=JOB_ID --action=retry --branch=BRANCH [--pr=PR_NUMBER] [--tenant=TENANT]\n\n";
    echo "Options:\n";
    echo "  --secret     Required. CLI authentication secret\n";
    echo "  --member     Required. Member ID\n";
    echo "  --job        Required. Job ID\n";
    echo "  --issue      Issue key (required for process action)\n";
    echo "  --cloud      Atlassian Cloud ID (required for process action)\n";
    echo "  --repo       Repository connection ID (required for process action)\n";
    echo "  --action     Required. Action: process, resume, or retry\n";
    echo "  --comment    Comment ID containing clarification (optional for resume)\n";
    echo "  --branch     Branch name (required for retry action)\n";
    echo "  --pr         PR number (optional for retry action)\n";
    echo "  --tenant     Tenant slug for multi-tenancy (e.g., gwt)\n";
    echo "  --verbose    Show detailed output\n";
    exit(0);
}

// Determine config file based on tenant parameter
$tenant = $options['tenant'] ?? null;
if ($tenant) {
    $configFile = $baseDir . "/conf/config.{$tenant}.ini";
    if (!file_exists($configFile)) {
        echo "Error: Tenant config not found: {$configFile}\n";
        exit(1);
    }
} else {
    $configFile = $baseDir . '/conf/config.ini';
}

// Initialize the application with tenant-specific config
$bootstrap = new \app\Bootstrap($configFile);

// Validate CLI secret key
$providedSecret = $options['secret'] ?? null;
$expectedSecret = Flight::get('cron.api_key');

if (empty($providedSecret) || !hash_equals($expectedSecret, $providedSecret)) {
    echo "Error: Invalid or missing --secret parameter\n";
    exit(1);
}

// Validate required parameters
$memberId = (int)($options['member'] ?? 0);
$jobId = $options['job'] ?? '';
$action = $options['action'] ?? '';

if (empty($memberId) || empty($jobId) || empty($action)) {
    echo "Error: Missing required parameters (--member, --job, --action)\n";
    exit(1);
}

// Validate action
if (!in_array($action, ['process', 'resume', 'retry'])) {
    echo "Error: Invalid action. Must be 'process', 'resume', or 'retry'\n";
    exit(1);
}

$verbose = isset($options['verbose']);

// Log function
function logMessage($message, $level = 'INFO') {
    global $verbose;
    $timestamp = date('Y-m-d H:i:s');
    $output = "[{$timestamp}] [{$level}] {$message}\n";

    if ($verbose) {
        echo $output;
    }

    // Also log to application log
    if (Flight::has('log')) {
        Flight::get('log')->info("AI-Dev-Agent: {$message}");
    }
}

try {
    logMessage("Starting AI Developer Agent");
    logMessage("Action: {$action}, Member: {$memberId}, Job: {$jobId}");

    // Load member
    $member = R::load('member', $memberId);
    if (!$member || !$member->id) {
        throw new \Exception("Member not found: {$memberId}");
    }

    // Get API key using RedBeanPHP (single MySQL database)
    $apiKeySetting = R::findOne('enterprisesettings', 'member_id = ? AND setting_key = ?', [$memberId, 'anthropic_api_key']);

    if (!$apiKeySetting || empty($apiKeySetting->setting_value)) {
        throw new \Exception("Anthropic API key not configured");
    }

    $apiKey = EncryptionService::decrypt($apiKeySetting->setting_value);

    // Handle action
    if ($action === 'process') {
        // Additional parameters for process action
        $issueKey = $options['issue'] ?? '';
        $cloudId = $options['cloud'] ?? '';
        $repoId = (int)($options['repo'] ?? 0);

        if (empty($issueKey) || empty($cloudId) || empty($repoId)) {
            throw new \Exception("Missing required parameters for process action (--issue, --cloud, --repo)");
        }

        logMessage("Processing ticket: {$issueKey}");

        // Create agent and process
        $agent = new AIDevAgent($memberId, $cloudId, $repoId, $apiKey, $jobId);
        $result = $agent->processTicket($issueKey);

        logMessage("Process result: " . ($result['status'] ?? 'unknown'));

        if ($result['status'] === 'success') {
            logMessage("PR created: " . ($result['pr_url'] ?? 'N/A'));
        } elseif ($result['status'] === 'waiting_clarification') {
            logMessage("Waiting for clarification. Questions posted to Jira.");
        } elseif ($result['status'] === 'error') {
            logMessage("Error: " . ($result['error'] ?? 'Unknown error'), 'ERROR');
        }

    } elseif ($action === 'resume') {
        // Get job status to find issue details
        $job = AIDevStatusService::getStatus($jobId, $memberId);

        if (!$job) {
            throw new \Exception("Job not found: {$jobId}");
        }

        if ($job['status'] !== AIDevStatusService::STATUS_WAITING_CLARIFICATION) {
            throw new \Exception("Job is not waiting for clarification");
        }

        $issueKey = $job['issue_key'];
        $cloudId = $job['cloud_id'] ?? '';
        $repoId = $job['repo_connection_id'];
        $answerCommentId = $options['comment'] ?? $job['clarification_comment_id'];

        logMessage("Resuming job for ticket: {$issueKey}");

        // Get cloud_id if not in job data
        if (empty($cloudId)) {
            // Try to find it from board using RedBeanPHP
            $boardBean = R::load('jiraboards', (int)$job['board_id']);
            $cloudId = $boardBean->cloud_id ?? '';
        }

        if (empty($cloudId)) {
            throw new \Exception("Could not determine cloud ID for job");
        }

        // Create agent and resume
        $agent = new AIDevAgent($memberId, $cloudId, $repoId, $apiKey, $jobId);
        $result = $agent->resumeAfterClarification($issueKey, $answerCommentId);

        logMessage("Resume result: " . ($result['status'] ?? 'unknown'));

        if ($result['status'] === 'success') {
            logMessage("PR created: " . ($result['pr_url'] ?? 'N/A'));
        } elseif ($result['status'] === 'error') {
            logMessage("Error: " . ($result['error'] ?? 'Unknown error'), 'ERROR');
        }

    } elseif ($action === 'retry') {
        // Get job status to find issue details
        $job = AIDevStatusService::getStatus($jobId, $memberId);

        if (!$job) {
            throw new \Exception("Job not found: {$jobId}");
        }

        $branchName = $options['branch'] ?? $job['branch_name'] ?? '';
        $prNumber = isset($options['pr']) ? (int)$options['pr'] : ($job['pr_number'] ?? null);
        $issueKey = $job['issue_key'];
        $cloudId = $job['cloud_id'] ?? '';
        $repoId = $job['repo_connection_id'];

        if (empty($branchName)) {
            throw new \Exception("Branch name is required for retry action");
        }

        // Get cloud_id if not in job data using RedBeanPHP
        if (empty($cloudId)) {
            $boardBean = R::load('jiraboards', (int)$job['board_id']);
            $cloudId = $boardBean->cloud_id ?? '';
        }

        if (empty($cloudId)) {
            throw new \Exception("Could not determine cloud ID for job");
        }

        logMessage("Retrying job for ticket: {$issueKey} on branch: {$branchName}");

        // Create agent and retry
        $agent = new AIDevAgent($memberId, $cloudId, $repoId, $apiKey, $jobId);
        $result = $agent->retryOnBranch($issueKey, $branchName, $prNumber);

        logMessage("Retry result: " . ($result['status'] ?? 'unknown'));

        if ($result['status'] === 'success') {
            logMessage("PR updated: " . ($result['pr_url'] ?? 'N/A'));
        } elseif ($result['status'] === 'error') {
            logMessage("Error: " . ($result['error'] ?? 'Unknown error'), 'ERROR');
        }
    }

    logMessage("AI Developer Agent completed");

} catch (\Exception $e) {
    logMessage("Fatal error: " . $e->getMessage(), 'ERROR');

    // Update job status to failed
    if (!empty($memberId) && !empty($jobId)) {
        AIDevStatusService::fail($memberId, $jobId, $e->getMessage());
    }

    exit(1);
}

exit(0);
