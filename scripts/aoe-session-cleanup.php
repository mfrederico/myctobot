#!/usr/bin/env php
<?php
/**
 * AOE Session Cleanup Script
 *
 * Called by wrapper script exit trap to update session status when Claude exits.
 *
 * Usage:
 *   php aoe-session-cleanup.php --tenant=gwt --session-id=<id> [--status=stopped|completed]
 */

// Parse command line arguments
$options = getopt('', ['tenant:', 'session-id:', 'status:', 'exit-code:', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
AOE Session Cleanup

Usage: php aoe-session-cleanup.php --tenant=<tenant> --session-id=<id> [options]

Options:
  --tenant=<name>      Required. Tenant slug (e.g., gwt)
  --session-id=<id>    Required. AOE session ID
  --status=<status>    Status to set (stopped, completed, error). Default: auto-detect
  --exit-code=<code>   Exit code from claude process (used for auto-detection)
  --help               Show this help message

Example:
  php aoe-session-cleanup.php --tenant=gwt --session-id=abc123 --exit-code=0

HELP;
    exit(0);
}

if (empty($options['tenant']) || empty($options['session-id'])) {
    echo "Error: --tenant and --session-id are required\n";
    exit(1);
}

$tenant = $options['tenant'];
$sessionId = $options['session-id'];
$requestedStatus = $options['status'] ?? null;
$exitCode = isset($options['exit-code']) ? (int) $options['exit-code'] : null;

// Change to project root
chdir(dirname(__DIR__));

// Load AOE-PHP
$aoePath = dirname(__DIR__) . '/../aoe-php/vendor/autoload.php';
if (!file_exists($aoePath)) {
    echo "Error: AOE-PHP not found at {$aoePath}\n";
    exit(1);
}
require_once $aoePath;

use Aoe\Session\Storage;
use Aoe\Session\Status;
use Aoe\Tenant\TenantContext;
use Aoe\Tmux\TmuxService;
use Aoe\Tmux\StatusDetector;

// Set tenant context
TenantContext::set($tenant);

// Load storage
$storage = new Storage($tenant);

// Find session
$session = $storage->find($sessionId);
if (!$session) {
    // Try prefix match
    $session = $storage->findByPrefix($sessionId);
}

if (!$session) {
    echo "Error: Session not found: {$sessionId}\n";
    exit(1);
}

echo "[AOE Cleanup] Session: {$session->title} ({$session->id})\n";
echo "[AOE Cleanup] Previous status: {$session->status->value}\n";

// Determine new status
$newStatus = null;

if ($requestedStatus) {
    // Use explicit status
    $newStatus = match($requestedStatus) {
        'stopped' => Status::Stopped,
        'completed' => Status::Idle,
        'error' => Status::Error,
        default => Status::Stopped,
    };
} elseif ($exitCode !== null) {
    // Auto-detect based on exit code
    $newStatus = ($exitCode === 0) ? Status::Idle : Status::Error;
} else {
    // Check if tmux session still exists
    $tmux = new TmuxService($tenant);
    $tmuxName = $session->getTmuxName();

    if ($tmux->sessionExistsByName($tmuxName)) {
        // Tmux still running - try to detect status from content
        $content = $tmux->capturePaneByName($tmuxName, 20);
        $detector = new StatusDetector();
        $newStatus = $detector->detect($content);
        echo "[AOE Cleanup] Tmux still running, detected status: {$newStatus->value}\n";
    } else {
        // Tmux gone - mark as stopped
        $newStatus = Status::Stopped;
    }
}

// Update session
$session->status = $newStatus;
$session->lastAccessedAt = \Carbon\Carbon::now();
$storage->save($session);

echo "[AOE Cleanup] Updated status: {$newStatus->value}\n";

// Optional: Trigger queue check for next job
// This could notify the directive processor that a slot is available
$queueCheckFile = "/tmp/aoe-queue-check-{$tenant}";
touch($queueCheckFile);
echo "[AOE Cleanup] Triggered queue check: {$queueCheckFile}\n";

echo "[AOE Cleanup] Done\n";
exit(0);
