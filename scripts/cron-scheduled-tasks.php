#!/usr/bin/env php
<?php
/**
 * MyCTOBot Scheduled Tasks Processor
 *
 * Run this script via cron every minute to process scheduled tasks.
 * This handles non-blocking temporal scheduling for Studio orchestrations:
 * - Revert actions (e.g., "revert discounts in 4 hours")
 * - Delayed pipeline executions
 * - Webhook callbacks
 * - API calls
 *
 * CRONTAB:
 * --------
 * * * * * * cd /path/to/myctobot/scripts && php cron-scheduled-tasks.php --script >> ../log/cron-tasks.log 2>&1
 *
 * OPTIONS:
 * --------
 *   --script     REQUIRED for CLI execution
 *   --workspace  Process specific workspace (e.g., gwt, greenworks). If omitted, processes all workspaces.
 *   --verbose    Show detailed output
 *   --dry-run    Check what would be executed without actually executing
 *   --help       Show this help message
 */

// Determine paths
$scriptDir = dirname(__FILE__);
$baseDir = dirname($scriptDir);

// Change to base directory
chdir($baseDir);

// Parse command line options
$options = getopt('', ['verbose', 'dry-run', 'script', 'workspace:', 'continue-run:', 'help']);

if (isset($options['help'])) {
    echo "MyCTOBot Scheduled Tasks Processor\n\n";
    echo "Usage: php cron-scheduled-tasks.php --script [options]\n\n";
    echo "Options:\n";
    echo "  --script     REQUIRED for standalone script mode\n";
    echo "  --workspace  Process specific workspace (omit to process all workspaces)\n";
    echo "  --verbose    Show detailed output\n";
    echo "  --dry-run    Check what would be executed without executing\n";
    echo "  --help       Show this help message\n";
    exit(0);
}

$verbose = isset($options['verbose']);
$dryRun = isset($options['dry-run']);
$specificWorkspace = $options['workspace'] ?? null;

if ($verbose) {
    echo "MyCTOBot Scheduled Tasks Processor\n";
    echo "===================================\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n";
    echo "Base: {$baseDir}\n";
    if ($specificWorkspace) echo "Workspace: {$specificWorkspace}\n";
    if ($dryRun) echo "Mode: DRY RUN\n";
    echo "\n";
}

// Load vendor autoload first
require_once $baseDir . '/vendor/autoload.php';

// Load bootstrap class and instantiate it
require_once $baseDir . '/bootstrap.php';

use \Flight as Flight;
use \app\Bean;

// Load the service
require_once $baseDir . '/services/ScheduledTaskProcessor.php';
require_once $baseDir . '/services/PipelineExecutor.php';

// Handle --continue-run option (used by job completion webhook to resume pipelines)
$continueRunId = isset($options['continue-run']) ? (int) $options['continue-run'] : null;
if ($continueRunId && $specificWorkspace) {
    if ($verbose) {
        echo "Continuing pipeline run {$continueRunId} in workspace {$specificWorkspace}\n";
    }

    // Switch to workspace database
    \app\WorkspaceResolver::switchDatabase($specificWorkspace);

    // Load the run
    $run = Bean::load('pipelineruns', $continueRunId);
    if (!$run || !$run->id) {
        echo "Error: Pipeline run {$continueRunId} not found\n";
        exit(1);
    }

    if ($run->status !== 'running') {
        if ($verbose) {
            echo "Run {$continueRunId} is not in 'running' status (current: {$run->status}), skipping\n";
        }
        exit(0);
    }

    // Load the pipeline
    $pipeline = Bean::load('pipelines', $run->pipelines_id);
    if (!$pipeline || !$pipeline->id) {
        echo "Error: Pipeline not found for run {$continueRunId}\n";
        exit(1);
    }

    // Continue execution
    $executor = new \app\services\PipelineExecutor($pipeline, $run->member_id, $specificWorkspace);

    // Decode context from run
    $context = json_decode($run->context_json, true) ?: [];

    // Continue from current step
    $result = $executor->continueRun($run, $context);

    if ($verbose) {
        echo "Pipeline continuation result: " . ($result['success'] ? 'success' : 'failed') . "\n";
        if (isset($result['error'])) {
            echo "Error: {$result['error']}\n";
        }
    }

    exit($result['success'] ? 0 : 1);
}

try {
    // Discover workspaces to process
    $workspacesToProcess = [];
    if ($specificWorkspace) {
        // Single workspace specified
        $workspaceConfig = $baseDir . "/conf/config.{$specificWorkspace}.ini";
        if (!file_exists($workspaceConfig)) {
            echo "Error: Workspace config not found: {$workspaceConfig}\n";
            exit(1);
        }
        $workspacesToProcess[$specificWorkspace] = $workspaceConfig;
    } else {
        // Discover all workspace configs
        $configFiles = glob($baseDir . '/conf/config.*.ini');
        foreach ($configFiles as $configFile) {
            $basename = basename($configFile);
            // Extract workspace name from config.{workspace}.ini
            if (preg_match('/^config\.([a-z0-9]+)\.ini$/', $basename, $matches)) {
                $workspaceSlug = $matches[1];
                // Skip 'example' config
                if ($workspaceSlug !== 'example') {
                    $workspacesToProcess[$workspaceSlug] = $configFile;
                }
            }
        }
    }

    if (empty($workspacesToProcess)) {
        if ($verbose) {
            echo "No workspace configs found. Nothing to process.\n";
        }
        exit(0);
    }

    if ($verbose) {
        echo "Workspaces to process: " . implode(', ', array_keys($workspacesToProcess)) . "\n\n";
    }

    $totalProcessed = 0;
    $totalErrors = 0;

    // Process each workspace
    foreach ($workspacesToProcess as $workspaceSlug => $configFile) {
        if ($verbose) {
            echo "=== Processing workspace: {$workspaceSlug} ===\n";
        }

        try {
            // Initialize fresh bootstrap for this workspace
            $bootstrap = new \app\Bootstrap($configFile);

            // Set timezone from config
            $configTimezone = Flight::get('app.timezone') ?? 'America/New_York';
            date_default_timezone_set($configTimezone);

            if ($verbose) {
                echo "Workspace initialized, timezone: {$configTimezone}\n";
            }

            // Find due scheduled tasks
            $now = date('Y-m-d H:i:s');
            $dueTasks = Bean::find('scheduledtasks',
                'status = ? AND scheduled_at <= ? ORDER BY scheduled_at ASC',
                ['pending', $now]
            );

            if (empty($dueTasks)) {
                if ($verbose) {
                    echo "No due tasks for workspace {$workspaceSlug}\n\n";
                }
                continue;
            }

            if ($verbose) {
                echo "Found " . count($dueTasks) . " due task(s)\n";
            }

            $processor = new \app\services\ScheduledTaskProcessor($verbose);
            $processedCount = 0;
            $errorCount = 0;

            foreach ($dueTasks as $task) {
                if ($verbose) {
                    echo "  -> Task #{$task->id}: {$task->task_type} (scheduled: {$task->scheduled_at})\n";
                }

                if ($dryRun) {
                    echo "     [DRY RUN] Would execute task #{$task->id}\n";
                    $processedCount++;
                    continue;
                }

                try {
                    // Mark as running
                    $task->status = 'running';
                    $task->started_at = date('Y-m-d H:i:s');
                    Bean::store($task);

                    // Process the task
                    $result = $processor->process($task);

                    if ($result['success']) {
                        $task->status = 'completed';
                        $task->completed_at = date('Y-m-d H:i:s');
                        $processedCount++;

                        if ($verbose) {
                            echo "     Completed successfully\n";
                        }
                    } else {
                        $task->retry_count = ($task->retry_count ?? 0) + 1;
                        $maxRetries = $task->max_retries ?? 3;

                        if ($task->retry_count >= $maxRetries) {
                            $task->status = 'failed';
                            $task->error_message = $result['error'] ?? 'Unknown error';
                            $errorCount++;

                            if ($verbose) {
                                echo "     Failed (max retries reached): {$task->error_message}\n";
                            }
                        } else {
                            $task->status = 'pending';
                            $task->error_message = $result['error'] ?? 'Unknown error';

                            if ($verbose) {
                                echo "     Failed, will retry ({$task->retry_count}/{$maxRetries}): {$task->error_message}\n";
                            }
                        }
                    }

                    Bean::store($task);

                } catch (\Exception $e) {
                    $task->status = 'failed';
                    $task->error_message = $e->getMessage();
                    $task->completed_at = date('Y-m-d H:i:s');
                    Bean::store($task);

                    $errorCount++;

                    if ($verbose) {
                        echo "     ERROR: {$e->getMessage()}\n";
                    }

                    Flight::get('log')->error('Scheduled task failed', [
                        'workspace' => $workspaceSlug,
                        'task_id' => $task->id,
                        'task_type' => $task->task_type,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $totalProcessed += $processedCount;
            $totalErrors += $errorCount;

            if ($verbose) {
                echo "Workspace {$workspaceSlug}: {$processedCount} tasks completed, {$errorCount} errors\n\n";
            }

        } catch (\Exception $e) {
            $totalErrors++;
            if ($verbose) {
                echo "Error processing workspace {$workspaceSlug}: {$e->getMessage()}\n\n";
            }

            try {
                Flight::get('log')->error('Workspace processing failed', [
                    'workspace' => $workspaceSlug,
                    'error' => $e->getMessage()
                ]);
            } catch (\Exception $logError) {
                // Ignore logging errors
            }
        }
    }

    if ($verbose) {
        echo "===================================\n";
        echo "All workspaces complete!\n";
        echo "Total tasks processed: {$totalProcessed}\n";
        echo "Total errors: {$totalErrors}\n";
    }

    exit($totalErrors > 0 ? 1 : 0);

} catch (Exception $e) {
    $message = "FATAL ERROR: " . $e->getMessage();

    if ($verbose) {
        echo "\n{$message}\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }

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
