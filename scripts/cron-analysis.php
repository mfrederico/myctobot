#!/usr/bin/env php
<?php
/**
 * MyCTOBot Background Analysis Runner
 *
 * Unified analysis script used by both web UI and cron scheduler.
 * Supports all Pro/Enterprise features including image analysis.
 *
 * USAGE:
 * ------
 * # Run analysis for a specific board:
 * php scripts/cron-analysis.php --script --secret=KEY --member=3 --board=1 --verbose
 *
 * # Run analysis and send email (for scheduled digests):
 * php scripts/cron-analysis.php --script --secret=KEY --member=3 --board=1 --email
 *
 * # With job ID for progress tracking (used by web UI):
 * php scripts/cron-analysis.php --script --secret=KEY --member=3 --board=1 --job=abc123
 *
 * OPTIONS:
 * --------
 *   --script        REQUIRED for CLI execution
 *   --secret        REQUIRED - Authentication key (cron.api_key in config.ini)
 *   --member        Member ID to run analysis for
 *   --board         Board ID to analyze
 *   --job           Job ID for progress tracking (optional)
 *   --status-filter Comma-separated list of statuses (optional)
 *   --email         Send digest email after analysis
 *   --verbose       Show detailed output
 *   --help          Show this help message
 */

// Determine paths
$scriptDir = dirname(__FILE__);
$baseDir = dirname($scriptDir);

// Change to base directory
chdir($baseDir);

// Parse command line options
$options = getopt('', [
    'member:',
    'board:',
    'job:',
    'tenant:',
    'status-filter:',
    'email',
    'verbose',
    'help',
    'script',
    'secret:'
]);

if (isset($options['help'])) {
    echo "MyCTOBot Background Analysis Runner\n\n";
    echo "Usage:\n";
    echo "  php scripts/cron-analysis.php --script --secret=KEY --member=ID --board=ID\n\n";
    echo "Options:\n";
    echo "  --script        REQUIRED for CLI execution\n";
    echo "  --secret        REQUIRED - Authentication key (from config.ini)\n";
    echo "  --member        Member ID to run analysis for\n";
    echo "  --board         Board ID to analyze\n";
    echo "  --job           Job ID for progress tracking\n";
    echo "  --status-filter Comma-separated status filter\n";
    echo "  --email         Send digest email after analysis\n";
    echo "  --verbose       Show detailed output\n";
    echo "  --help          Show this help message\n";
    exit(0);
}

$verbose = isset($options['verbose']);
$sendEmail = isset($options['email']);

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
use \app\services\AnalysisService;

// Load the service
require_once $baseDir . '/services/AnalysisService.php';

try {
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

    $bootstrap = new \app\Bootstrap($configFile);

    if ($verbose) {
        echo "Application initialized" . ($tenant ? " (tenant: {$tenant})" : "") . "\n\n";
    }

    // Validate CLI secret key for authentication
    $providedSecret = $options['secret'] ?? null;
    $expectedSecret = Flight::get('cron.api_key');

    if (empty($providedSecret) || !hash_equals($expectedSecret, $providedSecret)) {
        echo "Error: Invalid or missing --secret parameter\n";
        echo "The secret key must match cron.api_key in config.ini\n";
        exit(1);
    }

    // Validate required parameters
    $memberId = $options['member'] ?? null;
    $boardId = $options['board'] ?? null;

    if (!$memberId || !$boardId) {
        echo "Error: --member and --board are required\n";
        exit(1);
    }

    // Build options for AnalysisService
    $analysisOptions = [
        'job_id' => $options['job'] ?? null,
        'status_filter' => $options['status-filter'] ?? null,
        'send_email' => $sendEmail,
        'analysis_type' => $sendEmail ? 'digest' : 'priorities'
    ];

    // Run analysis using the unified service
    $service = new AnalysisService((int)$memberId, $verbose);
    $result = $service->runAnalysis((int)$boardId, $analysisOptions);

    if ($verbose) {
        echo "\n========================\n";
        echo "Analysis complete!\n";
        echo "Analysis ID: {$result['analysis_id']}\n";
        echo "Issues analyzed: {$result['issue_count']}\n";
        if ($result['email_sent']) {
            echo "Email sent: Yes\n";
        }
    }

    exit(0);

} catch (Exception $e) {
    $message = "FATAL ERROR: " . $e->getMessage();

    if ($verbose) {
        echo "\n{$message}\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }

    // Try to log if possible
    try {
        $logger = Flight::get('log');
        if ($logger) {
            $logger->error($message, ['trace' => $e->getTraceAsString()]);
        }
    } catch (Exception $logError) {
        // Ignore logging errors
    }

    exit(1);
}
