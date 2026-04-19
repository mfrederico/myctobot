#!/usr/bin/env php
<?php
/**
 * MyCTOBot Pipeline Runner
 *
 * CLI script to execute pipelines from command line or cron.
 *
 * USAGE:
 * ------
 * # Run a pipeline by slug:
 * php scripts/runpipe.php --workspace=gwt --pipeline=my-deploy-pipeline
 *
 * # Run a pipeline by ID:
 * php scripts/runpipe.php --workspace=gwt --pipeline-id=123
 *
 * # Resume/execute an existing run:
 * php scripts/runpipe.php --workspace=gwt --run-id=456
 *
 * # Resume a paused interactive run with mappings:
 * php scripts/runpipe.php --workspace=gwt --run-id=456 --resume --mappings='[{"source":"data.id","target":"item_id"}]'
 *
 * # With context data:
 * php scripts/runpipe.php --workspace=gwt --pipeline=my-pipeline --context='{"key":"value"}'
 *
 * OPTIONS:
 * --------
 *   --workspace        REQUIRED - Workspace slug (e.g., gwt)
 *   --pipeline      Pipeline slug to run
 *   --pipeline-id   Pipeline ID to run (alternative to slug)
 *   --run-id        Existing run ID to execute (skips creating new run)
 *   --resume        Resume a paused interactive run (requires --run-id)
 *   --mappings      JSON array of field mappings for resume (optional)
 *   --passthrough   Pass entire output to context (for resume)
 *   --save-mappings Save mappings to step definition for future runs
 *   --context       JSON context data to pass to pipeline
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
    'workspace:',
    'pipeline:',
    'pipeline-id:',
    'run-id:',
    'member:',
    'resume',
    'mappings:',
    'passthrough',
    'save-mappings',
    'context:',
    'verbose',
    'help'
]);

if (isset($options['help'])) {
    echo "MyCTOBot Pipeline Runner\n\n";
    echo "Usage:\n";
    echo "  php scripts/runpipe.php --workspace=SLUG --pipeline=SLUG [options]\n\n";
    echo "Options:\n";
    echo "  --workspace        REQUIRED - Workspace slug (e.g., gwt)\n";
    echo "  --pipeline      Pipeline slug to run\n";
    echo "  --pipeline-id   Pipeline ID to run (alternative to slug)\n";
    echo "  --run-id        Existing run ID to execute\n";
    echo "  --member        Member ID for the run (default: 1)\n";
    echo "  --resume        Resume a paused interactive run\n";
    echo "  --mappings      JSON array of field mappings for resume\n";
    echo "  --passthrough   Pass entire output to context (for resume)\n";
    echo "  --save-mappings Save mappings to step definition for future runs\n";
    echo "  --context       JSON context data\n";
    echo "  --verbose       Show detailed output\n";
    echo "  --help          Show this help message\n\n";
    echo "Examples:\n";
    echo "  php scripts/runpipe.php --workspace=gwt --pipeline=deploy-staging\n";
    echo "  php scripts/runpipe.php --workspace=gwt --pipeline=ci --context='{\"branch\":\"main\"}'\n";
    echo "  php scripts/runpipe.php --workspace=gwt --run-id=123\n";
    echo "  php scripts/runpipe.php --workspace=gwt --run-id=123 --resume --passthrough\n";
    exit(0);
}

$verbose = isset($options['verbose']);
$workspace = $options['workspace'] ?? null;
$pipelineSlug = $options['pipeline'] ?? null;
$pipelineId = $options['pipeline-id'] ?? null;
$runId = $options['run-id'] ?? null;
$memberId = $options['member'] ?? 1;  // Default to member 1 for CLI
$isResume = isset($options['resume']);
$mappingsJson = $options['mappings'] ?? '[]';
$passthrough = isset($options['passthrough']);
$saveMappings = isset($options['save-mappings']);
$contextJson = $options['context'] ?? '{}';

if ($verbose) {
    echo "MyCTOBot Pipeline Runner\n";
    echo "========================\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n";
    echo "Base: {$baseDir}\n\n";
}

// Validate required parameters
if (empty($workspace)) {
    echo "Error: --workspace is required\n";
    exit(1);
}

if (empty($pipelineSlug) && empty($pipelineId) && empty($runId)) {
    echo "Error: Either --pipeline, --pipeline-id, or --run-id is required\n";
    exit(1);
}

// Load vendor autoload first
require_once $baseDir . '/vendor/autoload.php';

// Load bootstrap class and instantiate it
require_once $baseDir . '/bootstrap.php';

use \Flight as Flight;
use \app\Bean;
use \app\services\PipelineExecutor;

// Load the service
require_once $baseDir . '/services/PipelineExecutor.php';

try {
    // Determine config file based on workspace parameter
    $configFile = $baseDir . "/conf/config.{$workspace}.ini";
    if (!file_exists($configFile)) {
        echo "Error: Workspace config not found: {$configFile}\n";
        exit(1);
    }

    if ($verbose) {
        echo "Loading workspace config: {$configFile}\n";
    }

    // Initialize bootstrap
    $bootstrap = new \app\Bootstrap($configFile);

    // Disable query caching for CLI to avoid stale data in long-running processes
    Bean::disableCache();

    if ($verbose) {
        echo "Bootstrap initialized\n";
    }

    // Parse context
    $context = json_decode($contextJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "Error: Invalid JSON in --context\n";
        exit(1);
    }

    // If run-id provided, execute or resume that run
    if (!empty($runId)) {
        $runId = (int) $runId;

        // Resume mode: resume a paused interactive run
        if ($isResume) {
            if ($verbose) {
                echo "Resuming paused run ID: {$runId}\n";
            }

            // Parse mappings
            $mappings = json_decode($mappingsJson, true);
            if (!is_array($mappings)) {
                $mappings = [];
            }

            if ($verbose) {
                echo "Mappings: " . json_encode($mappings) . "\n";
                echo "Passthrough: " . ($passthrough ? 'yes' : 'no') . "\n";
                echo "Save mappings: " . ($saveMappings ? 'yes' : 'no') . "\n";
            }

            $executor = new PipelineExecutor($runId);
            $success = $executor->resume($mappings, $passthrough, $saveMappings);

            // Check final status
            $run = Bean::load('pipelineruns', $runId);

            if ($verbose) {
                echo "Run status after resume: {$run->status}\n";
            }

            if ($run->status === 'paused') {
                echo "Pipeline paused again (awaiting next mapping)\n";
                exit(0);  // Successful pause
            } elseif ($run->status === 'completed') {
                echo "Pipeline run {$runId} completed\n";
                exit(0);
            } else {
                echo "Pipeline run {$runId} ended with status: {$run->status}\n";
                exit($run->status === 'failed' ? 1 : 0);
            }
        }

        // Normal execution mode
        if ($verbose) {
            echo "Executing existing run ID: {$runId}\n";
        }

        $executor = new PipelineExecutor($runId);
        $success = $executor->execute();

        // Check final status (may be paused in interactive mode)
        $run = Bean::load('pipelineruns', $runId);

        if ($run->status === 'paused') {
            echo "Pipeline paused (awaiting user input)\n";
            exit(0);  // Successful pause
        } elseif ($success) {
            echo "Pipeline run {$runId} completed\n";
            exit(0);
        } else {
            echo "Pipeline run {$runId} failed\n";
            exit(1);
        }
    }

    // Load pipeline by slug or ID
    $pipeline = null;
    if (!empty($pipelineSlug)) {
        $pipeline = Bean::findOne('pipelines', 'slug = ?', [$pipelineSlug]);
        if (!$pipeline) {
            echo "Error: Pipeline not found with slug: {$pipelineSlug}\n";
            exit(1);
        }
    } elseif (!empty($pipelineId)) {
        $pipeline = Bean::load('pipelines', (int) $pipelineId);
        if (!$pipeline->id) {
            echo "Error: Pipeline not found with ID: {$pipelineId}\n";
            exit(1);
        }
    }

    if ($verbose) {
        echo "Loaded pipeline: {$pipeline->name} (ID: {$pipeline->id})\n";
    }

    // Check if pipeline is active
    if (!$pipeline->is_active) {
        echo "Error: Pipeline is not active\n";
        exit(1);
    }

    // Count active steps
    $stepCount = Bean::count('pipelinesteps', 'pipelines_id = ? AND is_active = 1', [$pipeline->id]);
    if ($stepCount === 0) {
        echo "Error: Pipeline has no active steps\n";
        exit(1);
    }

    if ($verbose) {
        echo "Pipeline has {$stepCount} active steps\n";
    }

    // Create a new run
    $runUid = 'run-' . bin2hex(random_bytes(8));

    $run = Bean::dispense('pipelineruns');
    $run->run_uid = $runUid;
    $run->pipelines_id = $pipeline->id;
    $run->member_id = (int) $memberId;
    $run->trigger_source = 'cli';
    $run->trigger_data_json = json_encode($context);
    $run->status = 'pending';
    $run->context_json = $pipeline->default_context_json ?: '{}';
    $run->steps_total = $stepCount;
    $run->steps_completed = 0;
    $run->progress_percent = 0;
    $run->created_at = date('Y-m-d H:i:s');
    $run->updated_at = date('Y-m-d H:i:s');

    $runId = Bean::store($run);

    if ($verbose) {
        echo "Created run: {$runUid} (ID: {$runId})\n";
    }

    // Update pipeline stats
    $pipeline->run_count = ($pipeline->run_count ?? 0) + 1;
    $pipeline->last_run_at = date('Y-m-d H:i:s');
    Bean::store($pipeline);

    // Execute the run
    if ($verbose) {
        echo "\nStarting execution...\n";
        echo str_repeat('-', 40) . "\n";
    }

    $executor = new PipelineExecutor($runId);
    $success = $executor->execute();

    // Reload run for final status
    $run = Bean::load('pipelineruns', $runId);

    if ($verbose) {
        echo str_repeat('-', 40) . "\n";
        echo "Final status: {$run->status}\n";
        echo "Progress: {$run->steps_completed}/{$run->steps_total} steps\n";
        if ($run->error_message) {
            echo "Error: {$run->error_message}\n";
        }
        echo "Duration: ";
        if ($run->started_at && $run->completed_at) {
            $duration = strtotime($run->completed_at) - strtotime($run->started_at);
            echo "{$duration}s\n";
        } else {
            echo "N/A\n";
        }
    }

    // Output result
    $result = [
        'run_id' => $runId,
        'run_uid' => $runUid,
        'status' => $run->status,
        'steps_completed' => $run->steps_completed,
        'steps_total' => $run->steps_total,
        'error_message' => $run->error_message
    ];

    if (!$verbose) {
        // Output JSON for machine parsing
        echo json_encode($result) . "\n";
    }

    exit($success ? 0 : 1);

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    if ($verbose) {
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
    exit(1);
}
