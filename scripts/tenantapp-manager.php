#!/usr/bin/env php
<?php
/**
 * Tenant App Manager CLI
 *
 * Manage tenant apps from the command line.
 *
 * Usage:
 *   php scripts/tenantapp-manager.php --workspace=gwt --list
 *   php scripts/tenantapp-manager.php --workspace=gwt --start --app=my-app
 *   php scripts/tenantapp-manager.php --workspace=gwt --stop --app=my-app
 *   php scripts/tenantapp-manager.php --workspace=gwt --restart --app=my-app
 *   php scripts/tenantapp-manager.php --workspace=gwt --status --app=my-app
 *   php scripts/tenantapp-manager.php --workspace=gwt --logs --app=my-app
 *   php scripts/tenantapp-manager.php --ports
 */

require_once __DIR__ . '/../vendor/autoload.php';

use app\Bean;
use app\services\TenantAppService;
use app\services\TenantAppPortManager;
use app\services\TenantAppDatabase;

// Parse arguments
$options = getopt('', [
    'workspace:',
    'app:',
    'list',
    'start',
    'stop',
    'restart',
    'status',
    'logs',
    'ports',
    'lines:',
    'help',
]);

if (isset($options['help']) || empty($options)) {
    echo <<<HELP
Tenant App Manager CLI

Usage:
  php scripts/tenantapp-manager.php [options]

Options:
  --workspace=SLUG   Workspace slug (required for most operations)
  --app=SLUG         App slug (required for app operations)
  --list             List all apps in workspace
  --start            Start an app
  --stop             Stop an app
  --restart          Restart an app
  --status           Get app status
  --logs             Get app logs
  --lines=N          Number of log lines (default: 100)
  --ports            Show port allocation statistics
  --help             Show this help

Examples:
  php scripts/tenantapp-manager.php --workspace=gwt --list
  php scripts/tenantapp-manager.php --workspace=gwt --start --app=my-api
  php scripts/tenantapp-manager.php --workspace=gwt --logs --app=my-api --lines=50
  php scripts/tenantapp-manager.php --ports

HELP;
    exit(0);
}

// Handle ports command (doesn't need workspace)
if (isset($options['ports'])) {
    // Need database for port info
    $workspace = $options['workspace'] ?? 'default';
    TenantAppDatabase::init($workspace);

    $portManager = new TenantAppPortManager();
    $stats = $portManager->getStats();

    echo "Port Allocation Statistics\n";
    echo "==========================\n";
    echo "Range: {$stats['range_start']} - {$stats['range_end']}\n";
    echo "Total: {$stats['total_ports']}\n";
    echo "Reserved: {$stats['reserved_count']} (" . implode(', ', $stats['reserved_ports']) . ")\n";
    echo "Allocated: {$stats['allocated_count']}\n";
    echo "Available: {$stats['available_count']}\n";

    if (!empty($stats['allocated_ports'])) {
        echo "\nAllocated Ports:\n";
        foreach ($stats['allocated_ports'] as $port => $slug) {
            echo "  {$port} => {$slug}\n";
        }
    }
    exit(0);
}

// Require workspace for other operations
$workspace = $options['workspace'] ?? null;
if (!$workspace) {
    fwrite(STDERR, "Error: --workspace is required\n");
    exit(1);
}

// Initialize database
TenantAppDatabase::init($workspace);

$service = new TenantAppService();

// List apps
if (isset($options['list'])) {
    $apps = $service->listApps($workspace);

    if (empty($apps)) {
        echo "No apps found in workspace: {$workspace}\n";
        exit(0);
    }

    echo sprintf("%-20s %-10s %-6s %-20s %-12s\n", 'SLUG', 'STATUS', 'PORT', 'PIPELINE', 'AUTH');
    echo str_repeat('-', 80) . "\n";

    foreach ($apps as $app) {
        $pipeline = $app['pipeline_id'] ? "pipeline:{$app['pipeline_id']}" : '-';
        $port = $app['port'] ?: '-';
        echo sprintf(
            "%-20s %-10s %-6s %-20s %-12s\n",
            substr($app['slug'], 0, 20),
            $app['status'],
            $port,
            substr($pipeline, 0, 20),
            $app['auth_type']
        );
    }
    exit(0);
}

// Require app for other operations
$appSlug = $options['app'] ?? null;
if (!$appSlug) {
    fwrite(STDERR, "Error: --app is required for this operation\n");
    exit(1);
}

// Find app by slug
$app = Bean::findOne('tenantapps', ' workspace = ? AND slug = ? ', [$workspace, $appSlug]);
if (!$app) {
    fwrite(STDERR, "Error: App not found: {$appSlug} in workspace {$workspace}\n");
    exit(1);
}

$appId = $app->id;

try {
    if (isset($options['start'])) {
        echo "Starting app: {$appSlug}...\n";
        $result = $service->start($appId);
        echo "Status: {$result['status']}\n";
        if (!empty($result['url'])) {
            echo "URL: {$result['url']}\n";
        }
        exit($result['success'] ? 0 : 1);
    }

    if (isset($options['stop'])) {
        echo "Stopping app: {$appSlug}...\n";
        $result = $service->stop($appId);
        echo "Status: {$result['status']}\n";
        exit($result['success'] ? 0 : 1);
    }

    if (isset($options['restart'])) {
        echo "Restarting app: {$appSlug}...\n";
        $result = $service->restart($appId);
        echo "Status: {$result['status']}\n";
        if (!empty($result['url'])) {
            echo "URL: {$result['url']}\n";
        }
        exit($result['success'] ? 0 : 1);
    }

    if (isset($options['status'])) {
        $status = $service->status($appId);
        echo "App Status: {$status['name']}\n";
        echo str_repeat('-', 40) . "\n";
        echo "Status: {$status['status']}\n";
        echo "Port: " . ($status['port'] ?: '-') . "\n";
        echo "URL: " . ($status['url'] ?: '-') . "\n";
        echo "Session Exists: " . ($status['session_exists'] ? 'Yes' : 'No') . "\n";
        echo "Healthy: " . ($status['healthy'] ? 'Yes' : 'No') . "\n";
        echo "PID: " . ($status['pid'] ?: '-') . "\n";
        echo "Auto-restart: " . ($status['auto_restart'] ? 'Yes' : 'No') . "\n";
        echo "Last Started: " . ($status['last_started_at'] ?: '-') . "\n";
        echo "Last Health Check: " . ($status['last_health_check'] ?: '-') . "\n";
        if ($status['error_message']) {
            echo "Error: {$status['error_message']}\n";
        }
        exit(0);
    }

    if (isset($options['logs'])) {
        $lines = (int)($options['lines'] ?? 100);
        $logs = $service->getLogs($appId, $lines);

        echo "Logs for: {$logs['name']} (Status: {$logs['status']})\n";
        echo str_repeat('=', 60) . "\n";

        if (!empty($logs['logs']['tmux'])) {
            echo "\n--- Console Output ---\n";
            echo implode("\n", $logs['logs']['tmux']) . "\n";
        }

        if (!empty($logs['logs']['file'])) {
            echo "\n--- File Logs ---\n";
            echo implode("\n", $logs['logs']['file']) . "\n";
        }

        if (empty($logs['logs']['tmux']) && empty($logs['logs']['file'])) {
            echo "(No logs available)\n";
        }
        exit(0);
    }

    fwrite(STDERR, "Error: No operation specified. Use --help for usage.\n");
    exit(1);

} catch (Exception $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}
