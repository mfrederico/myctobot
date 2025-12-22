#!/usr/bin/env php
<?php
/**
 * Trigger an AI Developer job from CLI
 *
 * Usage: php scripts/trigger-job.php --script --secret=KEY --issue=SSI-1883 [--orchestrator]
 * Example: php scripts/trigger-job.php --script --secret=mykey --issue=SSI-1883 --orchestrator
 */
error_reporting(E_ALL);
$baseDir = dirname(__FILE__, 2);
chdir($baseDir);

// Parse CLI args
$options = getopt('', [
    'script',
    'secret:',
    'issue:',
    'member:',
    'orchestrator',
    'help'
]);

if (isset($options['help']) || !isset($options['script'])) {
    echo "Trigger an AI Developer job from CLI\n\n";
    echo "Usage: php scripts/trigger-job.php --script --secret=KEY --issue=ISSUE_KEY [--orchestrator]\n\n";
    echo "Options:\n";
    echo "  --script        Required for CLI execution\n";
    echo "  --secret        Required API key\n";
    echo "  --issue         Issue key (e.g., SSI-1883)\n";
    echo "  --member        Member ID (default: 3)\n";
    echo "  --orchestrator  Use agent orchestrator pattern\n";
    echo "  --help          Show this help\n";
    exit(1);
}

require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/bootstrap.php';
require_once $baseDir . '/services/AIDevJobService.php';

$bootstrap = new \app\Bootstrap($baseDir . '/conf/config.ini');

// Validate secret
$configSecret = \Flight::get('cron.api_key');
if (empty($options['secret']) || $options['secret'] !== $configSecret) {
    echo "Error: Invalid or missing secret key\n";
    exit(1);
}

$issueKey = $options['issue'] ?? null;
if (!$issueKey) {
    echo "Error: --issue is required\n";
    exit(1);
}

// Settings
$memberId = (int)($options['member'] ?? 3);
$cloudId = 'cb1fabf7-9018-49bb-90c7-afa23343dbe5';
$useOrchestrator = isset($options['orchestrator']);

echo "Triggering job for {$issueKey}...\n";
echo "Orchestrator mode: " . ($useOrchestrator ? "ENABLED" : "disabled") . "\n";

$svc = new \app\services\AIDevJobService();
$result = $svc->triggerJob($memberId, $issueKey, $cloudId, null, null, $useOrchestrator);

echo "\nResult:\n";
print_r($result);
