#!/usr/bin/env php
<?php
/**
 * MyCTOBot Pipeline Scheduler Processor
 *
 * Run this script via cron every minute to process scheduled pipelines.
 * Uses the scheduledrecurring table for robust, fault-tolerant recurring tasks.
 *
 * Features:
 * - Multiple schedule types (minutely, hourly, daily, weekly, monthly, cron)
 * - Timezone support
 * - Concurrency control (max_concurrent, overlap policies)
 * - Survives failures and server restarts
 *
 * CRONTAB:
 * --------
 * * * * * * cd /path/to/myctobot/scripts && php cron-pipeline-triggers.php --script >> ../log/cron-scheduler.log 2>&1
 *
 * OPTIONS:
 * --------
 *   --script     REQUIRED for CLI execution
 *   --workspace  Process specific workspace (omit to process all)
 *   --verbose    Show detailed output
 *   --dry-run    Check what would trigger without triggering
 *   --help       Show this help message
 */

$scriptDir = dirname(__FILE__);
$baseDir = dirname($scriptDir, 2);
chdir($baseDir);

$options = getopt('', ['verbose', 'dry-run', 'script', 'workspace:', 'help']);

if (isset($options['help'])) {
    echo "MyCTOBot Pipeline Scheduler Processor\n\n";
    echo "Usage: php cron-pipeline-triggers.php --script [options]\n\n";
    echo "Options:\n";
    echo "  --script     REQUIRED for standalone script mode\n";
    echo "  --workspace  Process specific workspace (omit to process all)\n";
    echo "  --verbose    Show detailed output\n";
    echo "  --dry-run    Check what would trigger without triggering\n";
    echo "  --help       Show this help message\n";
    exit(0);
}

$verbose = isset($options['verbose']);
$dryRun = isset($options['dry-run']);
$specificWorkspace = $options['workspace'] ?? null;

if ($verbose) {
    echo "MyCTOBot Pipeline Scheduler Processor\n";
    echo "=====================================\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n";
    if ($specificWorkspace) echo "Workspace: {$specificWorkspace}\n";
    if ($dryRun) echo "Mode: DRY RUN\n";
    echo "\n";
}

require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/bootstrap.php';
require_once $baseDir . '/services/SchedulerService.php';

use \Flight as Flight;
use \app\Bean;
use \app\WorkspaceResolver;
use \app\services\SchedulerService;

/**
 * Trigger a pipeline run from a schedule
 */
function triggerScheduledPipeline(
    object $schedule,
    object $pipeline,
    string $workspace,
    bool $verbose,
    bool $dryRun
): bool {
    global $baseDir;

    if ($dryRun) {
        if ($verbose) {
            echo "    [DRY RUN] Would trigger pipeline #{$pipeline->id} ({$pipeline->slug})\n";
        }
        return true;
    }

    // Create the run record
    $runUid = 'sched-' . bin2hex(random_bytes(8));
    $stepCount = Bean::count('pipelinesteps', 'pipelines_id = ? AND is_active = 1', [$pipeline->id]);

    // Merge default context with schedule input data
    $context = json_decode($pipeline->default_context_json ?: '{}', true);
    $inputData = json_decode($schedule->input_data_json ?: '{}', true);
    $context = array_merge($context, $inputData);
    $context['_schedule_id'] = (int) $schedule->id;
    $context['_schedule_name'] = $schedule->name;

    $run = Bean::dispense('pipelineruns');
    $run->run_uid = $runUid;
    $run->pipelines_id = $pipeline->id;
    $run->member_id = $pipeline->member_id;
    $run->trigger_source = 'scheduler';
    $run->trigger_data_json = json_encode([
        'schedule_id' => (int) $schedule->id,
        'schedule_name' => $schedule->name,
        'scheduled_time' => date('Y-m-d H:i:s')
    ]);
    $run->status = 'pending';
    $run->context_json = json_encode($context);
    $run->steps_total = $stepCount;
    $run->steps_completed = 0;
    $run->progress_percent = 0;
    $run->entry_step = $schedule->entry_step;
    $run->created_at = date('Y-m-d H:i:s');
    $run->updated_at = date('Y-m-d H:i:s');

    $runId = Bean::store($run);

    // Update pipeline stats
    $pipeline->run_count = ($pipeline->run_count ?? 0) + 1;
    $pipeline->last_run_at = date('Y-m-d H:i:s');
    Bean::store($pipeline);

    // Mark schedule as running
    SchedulerService::markRunning($schedule);

    // Dispatch via engine or background process
    \app\services\PipelineDispatcher::dispatch($runId, $workspace, false, [
        'member_id' => $pipeline->member_id,
        'entry_step' => $schedule->entry_step ?: null,
        'schedule_id' => $schedule->id,
    ]);

    if ($verbose) {
        echo "    Triggered run #{$runId} ({$runUid})\n";
        echo "    Run count: {$schedule->run_count}, Next: {$schedule->next_run_at}\n";
    }

    return true;
}

// ============================================================================
// Main Processing
// ============================================================================

// Get list of workspaces to process
$workspaces = [];
if ($specificWorkspace) {
    $workspaces[] = $specificWorkspace;
} else {
    $configFiles = glob($baseDir . '/conf/config.*.ini');
    foreach ($configFiles as $file) {
        if (preg_match('/config\.([^.]+)\.ini$/', $file, $m)) {
            $workspaces[] = $m[1];
        }
    }
    if (empty($workspaces)) {
        $workspaces[] = 'default';
    }
}

$totalTriggered = 0;
$totalSkipped = 0;
$totalQueued = 0;

foreach ($workspaces as $workspace) {
    if ($verbose) {
        echo "Processing workspace: {$workspace}\n";
    }

    if (!WorkspaceResolver::switchDatabase($workspace)) {
        if ($verbose) {
            echo "  Warning: Could not switch to workspace {$workspace}\n";
        }
        continue;
    }

    // Get all due schedules
    $schedules = SchedulerService::getDueSchedules();

    if ($verbose && empty($schedules)) {
        echo "  No schedules due\n";
    }

    foreach ($schedules as $schedule) {
        $pipeline = Bean::load('pipelines', $schedule->pipeline_id);

        if (!$pipeline || !$pipeline->id) {
            if ($verbose) {
                echo "  Schedule #{$schedule->id} ({$schedule->name}): Pipeline not found\n";
            }
            continue;
        }

        if (!$pipeline->is_active) {
            if ($verbose) {
                echo "  Schedule #{$schedule->id} ({$schedule->name}): Pipeline inactive\n";
            }
            continue;
        }

        if ($verbose) {
            echo "  Schedule #{$schedule->id} ({$schedule->name}):\n";
            echo "    Pipeline: {$pipeline->slug}\n";
            echo "    Type: {$schedule->schedule_type}, TZ: {$schedule->timezone}\n";
            echo "    Running: {$schedule->current_running}/{$schedule->max_concurrent}\n";
        }

        // Check concurrency
        $canRun = SchedulerService::canRun($schedule);

        if ($canRun['can_run']) {
            if ($verbose) {
                echo "    Status: TRIGGERING\n";
            }
            if (triggerScheduledPipeline($schedule, $pipeline, $workspace, $verbose, $dryRun)) {
                $totalTriggered++;
            }
        } elseif (!empty($canRun['queue'])) {
            if ($verbose) {
                echo "    Status: QUEUED (reason: {$canRun['reason']})\n";
            }
            if (!$dryRun) {
                SchedulerService::incrementQueue($schedule);
            }
            $totalQueued++;
        } else {
            if ($verbose) {
                echo "    Status: SKIPPED (reason: {$canRun['reason']})\n";
            }
            $totalSkipped++;

            // Still need to calculate next run time even if skipped
            if (!$dryRun) {
                $config = json_decode($schedule->schedule_config_json ?: '{}', true);
                $schedule->next_run_at = SchedulerService::calculateNextRun(
                    $schedule->schedule_type,
                    $config,
                    $schedule->timezone
                );
                $schedule->updated_at = date('Y-m-d H:i:s');
                Bean::store($schedule);
            }
        }
    }

    if ($verbose) {
        echo "\n";
    }
}

if ($verbose) {
    echo "Done. Triggered: {$totalTriggered}, Skipped: {$totalSkipped}, Queued: {$totalQueued}\n";
}

exit(0);
