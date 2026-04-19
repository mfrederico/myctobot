#!/usr/bin/env php
<?php
/**
 * MyCTOBot Await Input Timeout Processor
 *
 * Processes pipeline steps that are awaiting input but have exceeded their timeout.
 * Uses InnoDB row locking to prevent race conditions with user input.
 *
 * CRONTAB (run every minute):
 * * * * * * cd /path/to/myctobot/scripts && php cron-await-timeouts.php --script >> ../log/cron-timeouts.log 2>&1
 */

$scriptDir = dirname(__FILE__);
$baseDir = dirname($scriptDir, 2);
chdir($baseDir);

$options = getopt('', ['verbose', 'dry-run', 'script', 'workspace:', 'help']);

if (isset($options['help'])) {
    echo "MyCTOBot Await Input Timeout Processor\n\n";
    echo "Usage: php cron-await-timeouts.php --script [options]\n\n";
    echo "Options:\n";
    echo "  --script     Required for CLI execution\n";
    echo "  --workspace  Process specific workspace only\n";
    echo "  --verbose    Show detailed output\n";
    echo "  --dry-run    Check without processing\n";
    exit(0);
}

$verbose = isset($options['verbose']);
$dryRun = isset($options['dry-run']);
$specificWorkspace = $options['workspace'] ?? null;

require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/bootstrap.php';

use \app\Bean;

try {
    // Discover workspaces
    $workspaces = [];
    if ($specificWorkspace) {
        $configFile = $baseDir . "/conf/config.{$specificWorkspace}.ini";
        if (!file_exists($configFile)) {
            echo "Error: Config not found: {$configFile}\n";
            exit(1);
        }
        $workspaces[$specificWorkspace] = $configFile;
    } else {
        foreach (glob($baseDir . '/conf/config.*.ini') as $configFile) {
            if (preg_match('/config\.([a-z0-9_]+)\.ini$/', basename($configFile), $m)) {
                if ($m[1] !== 'example') {
                    $workspaces[$m[1]] = $configFile;
                }
            }
        }
    }

    if (empty($workspaces)) {
        if ($verbose) echo "No workspaces found.\n";
        exit(0);
    }

    $totalProcessed = 0;
    $totalErrors = 0;

    foreach ($workspaces as $workspace => $configFile) {
        if ($verbose) echo "=== Workspace: {$workspace} ===\n";

        try {
            $bootstrap = new \app\Bootstrap($configFile);

            // Find timed-out await_input steps
            $timedOut = Bean::getAll(
                "SELECT psr.id, psr.pipelineruns_id, psr.step_name, psr.awaiting_input_timeout_at,
                        pr.run_uid, p.name as pipeline_name
                 FROM pipelinestepruns psr
                 JOIN pipelineruns pr ON psr.pipelineruns_id = pr.id
                 JOIN pipelines p ON pr.pipelines_id = p.id
                 WHERE psr.status = 'awaiting_input'
                   AND psr.awaiting_input_timeout_at IS NOT NULL
                   AND psr.awaiting_input_timeout_at < NOW()
                 ORDER BY psr.awaiting_input_timeout_at ASC
                 LIMIT 100"
            );

            if (empty($timedOut)) {
                if ($verbose) echo "  No timed-out steps.\n";
                continue;
            }

            if ($verbose) echo "  Found " . count($timedOut) . " timed-out step(s)\n";

            require_once $baseDir . '/services/PipelineExecutor.php';

            foreach ($timedOut as $row) {
                $stepRunId = $row['id'];
                $runId = $row['pipelineruns_id'];

                if ($verbose) {
                    echo "  -> Step {$stepRunId}: {$row['pipeline_name']} / {$row['step_name']} (timeout: {$row['awaiting_input_timeout_at']})\n";
                }

                if ($dryRun) {
                    echo "     [DRY RUN] Would process timeout\n";
                    $totalProcessed++;
                    continue;
                }

                // Use the executor to claim and process the timeout
                $executor = new \app\services\PipelineExecutor($runId);
                $claim = $executor->claimStepForTimeout($stepRunId);

                if (!$claim['claimed']) {
                    if ($verbose) echo "     Skipped: {$claim['reason']}\n";
                    continue;
                }

                // Resume the pipeline with timed_out flag
                // The step's on_failure handler will be triggered
                $result = $executor->resumeFromAwaitInput(
                    ['_timed_out' => true],
                    $claim['step_run']['awaiting_input_token'],
                    'timeout'
                );

                if ($result['success']) {
                    if ($verbose) echo "     Processed successfully\n";
                    $totalProcessed++;
                } else {
                    if ($verbose) echo "     Error: " . ($result['error'] ?? 'Unknown') . "\n";
                    $totalErrors++;
                }
            }

            // Check for stuck "running" step runs (background process may have crashed)
            // Honor each step's configured timeout_seconds (default 300s if not set)
            $stuckSteps = Bean::getAll(
                "SELECT psr.id as step_run_id, psr.pipelineruns_id, psr.step_name, psr.started_at,
                        ps.timeout_seconds, pr.run_uid, p.name as pipeline_name
                 FROM pipelinestepruns psr
                 JOIN pipelineruns pr ON psr.pipelineruns_id = pr.id
                 JOIN pipelines p ON pr.pipelines_id = p.id
                 JOIN pipelinesteps ps ON psr.pipelinesteps_id = ps.id
                 WHERE psr.status = 'running'
                   AND psr.started_at IS NOT NULL
                   AND psr.started_at < DATE_SUB(NOW(), INTERVAL COALESCE(ps.timeout_seconds, 300) SECOND)
                 ORDER BY psr.started_at ASC
                 LIMIT 50"
            );

            if (!empty($stuckSteps)) {
                if ($verbose) echo "  Found " . count($stuckSteps) . " stuck step(s)\n";

                foreach ($stuckSteps as $row) {
                    $timeoutSecs = $row['timeout_seconds'] ?? 300;
                    if ($verbose) {
                        echo "  -> Step {$row['step_run_id']}: {$row['pipeline_name']} / {$row['step_name']} (started: {$row['started_at']}, timeout: {$timeoutSecs}s)\n";
                    }

                    if ($dryRun) {
                        echo "     [DRY RUN] Would mark step and run as failed\n";
                        $totalProcessed++;
                        continue;
                    }

                    // Mark step run as failed
                    $stepRun = Bean::load('pipelinestepruns', $row['step_run_id']);
                    $stepRun->status = 'failed';
                    $stepRun->error_message = "Step timed out after {$timeoutSecs}s (process may have crashed)";
                    $stepRun->completed_at = date('Y-m-d H:i:s');
                    $stepRun->updated_at = date('Y-m-d H:i:s');
                    Bean::store($stepRun);

                    // Mark parent run as failed
                    $run = Bean::load('pipelineruns', $row['pipelineruns_id']);
                    $run->status = 'failed';
                    $run->error_message = "Step '{$row['step_name']}' timed out after {$timeoutSecs}s";
                    $run->completed_at = date('Y-m-d H:i:s');
                    $run->updated_at = date('Y-m-d H:i:s');
                    Bean::store($run);

                    if ($verbose) echo "     Marked step and run as failed\n";
                    $totalProcessed++;
                }
            } elseif ($verbose) {
                echo "  No stuck steps.\n";
            }

        } catch (\Exception $e) {
            echo "  Error: " . $e->getMessage() . "\n";
            $totalErrors++;
        }
    }

    if ($verbose) {
        echo "\n=== Complete ===\n";
        echo "Processed: {$totalProcessed}\n";
        echo "Errors: {$totalErrors}\n";
    }

    exit($totalErrors > 0 ? 1 : 0);

} catch (\Exception $e) {
    echo "FATAL: " . $e->getMessage() . "\n";
    exit(1);
}
