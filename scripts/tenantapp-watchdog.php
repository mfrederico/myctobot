#!/usr/bin/env php
<?php
/**
 * Tenant App Watchdog
 *
 * Monitors tenant apps and restarts crashed ones with auto_restart enabled.
 * Run via cron every 5 minutes:
 *
 *   php scripts/tenantapp-watchdog.php
 *
 * Options:
 *   --workspace=SLUG   Only check apps in specific workspace
 *   --dry-run          Show what would be done without taking action
 *   --verbose          Show detailed output
 */

require_once __DIR__ . '/../vendor/autoload.php';

use app\Bean;
use app\TmuxManager;
use app\services\TenantAppService;
use app\services\TenantAppPortManager;

// Parse arguments
$options = getopt('', [
    'workspace:',
    'dry-run',
    'verbose',
]);

$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);
$workspaceFilter = $options['workspace'] ?? null;

// Get all workspaces or specific one
$workspaces = [];
if ($workspaceFilter) {
    $workspaces[] = $workspaceFilter;
} else {
    // Find all config files to get workspaces
    $configFiles = glob(__DIR__ . '/../conf/config.*.ini');
    foreach ($configFiles as $file) {
        if (preg_match('/config\.([^.]+)\.ini$/', $file, $matches)) {
            $workspaces[] = $matches[1];
        }
    }
    // Always include default
    if (!in_array('default', $workspaces)) {
        array_unshift($workspaces, 'default');
    }
}

$log = function($message, $level = 'info') use ($verbose) {
    if ($level === 'error' || $verbose) {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] [{$level}] {$message}\n";
    }
};

$log("Tenant App Watchdog starting", 'info');
$log("Checking workspaces: " . implode(', ', $workspaces), 'info');

$totalChecked = 0;
$totalCrashed = 0;
$totalRestarted = 0;
$errors = [];

foreach ($workspaces as $workspace) {
    // Initialize database for workspace
    $configFile = __DIR__ . "/../conf/config.{$workspace}.ini";
    if (!file_exists($configFile)) {
        $configFile = __DIR__ . '/../conf/config.ini';
    }

    if (!file_exists($configFile)) {
        $log("Config not found for workspace: {$workspace}", 'error');
        continue;
    }

    try {
        \app\services\TenantAppDatabase::init($workspace);
    } catch (Exception $e) {
        $log("Failed to init database for {$workspace}: {$e->getMessage()}", 'error');
        continue;
    }

    // Get all apps that should be running
    $apps = Bean::find('tenantapps', ' workspace = ? AND status = ? ', [$workspace, 'running']);

    foreach ($apps as $app) {
        $totalChecked++;
        $sessionName = "tenantapp-" . TmuxManager::sanitizeForSessionName($workspace) . "-" . TmuxManager::sanitizeForSessionName($app->slug);

        $log("Checking app: {$app->slug} in {$workspace}", 'info');

        // Check if tmux session exists
        $sessionExists = TmuxManager::exists($sessionName);

        if (!$sessionExists) {
            $totalCrashed++;
            $log("App crashed: {$app->slug} (session not found)", 'error');

            // Update status
            if (!$dryRun) {
                $app->status = 'crashed';
                $app->error_message = 'Watchdog detected: tmux session terminated unexpectedly';
                $app->updated_at = date('Y-m-d H:i:s');
                Bean::store($app);
            }

            // Restart if auto_restart is enabled
            if ($app->auto_restart) {
                $log("Auto-restarting app: {$app->slug}", 'info');

                if (!$dryRun) {
                    try {
                        $service = new TenantAppService();
                        $result = $service->start($app->id);

                        if ($result['success']) {
                            $totalRestarted++;
                            $log("Successfully restarted: {$app->slug} on port {$result['port']}", 'info');
                        } else {
                            $errors[] = "Failed to restart {$app->slug}: " . ($result['error'] ?? 'unknown');
                            $log("Failed to restart: {$app->slug}", 'error');
                        }
                    } catch (Exception $e) {
                        $errors[] = "Exception restarting {$app->slug}: {$e->getMessage()}";
                        $log("Exception restarting {$app->slug}: {$e->getMessage()}", 'error');
                    }
                } else {
                    $log("[DRY-RUN] Would restart: {$app->slug}", 'info');
                }
            } else {
                $log("Auto-restart disabled for: {$app->slug}", 'info');
            }
        } else {
            // Session exists, optionally check health
            if ($app->port) {
                $healthUrl = "http://127.0.0.1:{$app->port}" . ($app->health_check_path ?: '/health');
                $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
                $response = @file_get_contents($healthUrl, false, $ctx);

                if ($response !== false) {
                    $data = json_decode($response, true);
                    if (isset($data['status']) && $data['status'] === 'ok') {
                        $log("App healthy: {$app->slug}", 'info');
                    } else {
                        $log("App unhealthy (bad response): {$app->slug}", 'error');
                    }
                } else {
                    $log("App unhealthy (no response): {$app->slug}", 'error');
                }
            }
        }
    }

    // Also check for orphaned allocations (apps marked running but port not in use)
    $portManager = new TenantAppPortManager();
    $orphaned = $portManager->findOrphanedAllocations();

    foreach ($orphaned as $orphanApp) {
        if ($orphanApp->workspace !== $workspace) continue;

        $log("Found orphaned allocation: {$orphanApp->slug} (port {$orphanApp->port})", 'error');

        if (!$dryRun) {
            $orphanApp->status = 'crashed';
            $orphanApp->error_message = 'Watchdog detected: port not in use but status was running';
            $orphanApp->updated_at = date('Y-m-d H:i:s');
            Bean::store($orphanApp);
        }
    }
}

// Summary
$log("", 'info');
$log("=== Watchdog Summary ===", 'info');
$log("Apps checked: {$totalChecked}", 'info');
$log("Crashed detected: {$totalCrashed}", 'info');
$log("Restarted: {$totalRestarted}", 'info');

if (!empty($errors)) {
    $log("Errors encountered:", 'error');
    foreach ($errors as $error) {
        $log("  - {$error}", 'error');
    }
}

if ($dryRun) {
    $log("(This was a dry run - no changes were made)", 'info');
}

exit(empty($errors) ? 0 : 1);
