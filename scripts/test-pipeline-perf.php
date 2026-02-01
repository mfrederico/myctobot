#!/usr/bin/env php
<?php
/**
 * Pipeline Performance Test
 *
 * Measures time spent in key operations during pipeline resume.
 *
 * Usage:
 *   php scripts/test-pipeline-perf.php --workspace=dev --run=359
 */

error_reporting(E_ALL);
$scriptDir = dirname(__FILE__);
$baseDir = dirname($scriptDir);
chdir($baseDir);

$options = getopt('', ['workspace:', 'run:', 'help']);

if (isset($options['help']) || !isset($options['workspace']) || !isset($options['run'])) {
    echo "Usage: php scripts/test-pipeline-perf.php --workspace=dev --run=RUN_ID\n";
    exit(1);
}

$workspace = $options['workspace'];
$runId = (int) $options['run'];

// Bootstrap
require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/bootstrap.php';

$configFile = $baseDir . "/conf/config.{$workspace}.ini";
if (!file_exists($configFile)) {
    die("Config not found: {$configFile}\n");
}
$bootstrap = new \app\Bootstrap($configFile);

use \app\Bean;

// Performance measurements
$timings = [];
function timing(string $label): void {
    global $timings;
    $timings[] = ['label' => $label, 'time' => microtime(true), 'memory' => memory_get_usage(true)];
}

function reportTimings(): void {
    global $timings;
    if (empty($timings)) return;

    $start = $timings[0]['time'];
    $prevTime = $start;

    echo "\n=== TIMING REPORT ===\n";
    echo str_pad('Operation', 40) . str_pad('Elapsed', 12) . str_pad('Delta', 12) . "Memory\n";
    echo str_repeat('-', 76) . "\n";

    foreach ($timings as $t) {
        $elapsed = ($t['time'] - $start) * 1000;
        $delta = ($t['time'] - $prevTime) * 1000;
        $memory = round($t['memory'] / 1024 / 1024, 2);

        printf("%-40s %10.2f ms %10.2f ms %6.2f MB\n",
            $t['label'], $elapsed, $delta, $memory);

        $prevTime = $t['time'];
    }

    $total = (end($timings)['time'] - $start) * 1000;
    echo str_repeat('-', 76) . "\n";
    printf("TOTAL: %.2f ms\n", $total);
}

timing('start');

// Test 1: Load run
echo "Loading pipeline run {$runId}...\n";
timing('load_run_start');
$run = Bean::load('pipelineruns', $runId);
timing('load_run_end');

if (!$run->id) {
    die("Run not found: {$runId}\n");
}

echo "Run status: {$run->status}\n";
echo "Pipeline ID: {$run->pipelines_id}\n";

// Test 2: Load pipeline
timing('load_pipeline_start');
$pipeline = Bean::load('pipelines', $run->pipelines_id);
timing('load_pipeline_end');

// Test 3: Parse context JSON
timing('parse_context_start');
$context = json_decode($run->context_json ?: '{}', true);
timing('parse_context_end');
echo "Context size: " . strlen($run->context_json) . " bytes\n";
echo "Context keys: " . count($context) . "\n";

// Test 4: Load step lookups
timing('load_steps_start');
$steps = Bean::find('pipelinesteps',
    ' pipelines_id = ? AND is_active = 1 ORDER BY sequence ASC ',
    [$pipeline->id]
);
timing('load_steps_end');
echo "Steps count: " . count($steps) . "\n";

// Test 5: Load completed step runs
timing('load_step_runs_start');
$stepRuns = Bean::find('pipelinestepruns',
    ' pipelineruns_id = ? ORDER BY id ASC ',
    [$run->id]
);
timing('load_step_runs_end');
echo "Step runs count: " . count($stepRuns) . "\n";

// Test 6: Restore step outputs (the problematic loop)
timing('restore_outputs_start');
$stepOutputs = [];
foreach ($stepRuns as $sr) {
    if ($sr->status === 'success') {
        timing('restore_loop_load_step_start');
        $stepDef = Bean::load('pipelinesteps', $sr->pipelinesteps_id);
        timing('restore_loop_load_step_end');

        if ($stepDef && $stepDef->step_name) {
            timing('restore_loop_parse_json_start');
            $output = json_decode($sr->output_json ?: '{}', true);
            timing('restore_loop_parse_json_end');
            $stepOutputs[$stepDef->step_name] = $output;
        }
    }
}
timing('restore_outputs_end');
echo "Step outputs restored: " . count($stepOutputs) . "\n";

// Test 7: Find awaiting step
timing('find_awaiting_start');
$awaitingStep = Bean::findOne('pipelinestepruns',
    ' pipelineruns_id = ? AND awaiting_input = 1 ',
    [$run->id]
);
timing('find_awaiting_end');
echo "Awaiting step: " . ($awaitingStep ? $awaitingStep->step_name : 'none') . "\n";

// Test 8: Serialize context back (simulating update)
timing('serialize_context_start');
$contextJson = json_encode($context);
timing('serialize_context_end');

// Test 9: Store run (if we wanted to, but just measure Bean::store overhead)
// NOTE: Not actually storing to avoid side effects
timing('store_prep_start');
$run->updated_at = date('Y-m-d H:i:s');
timing('store_prep_end');

timing('done');

reportTimings();

echo "\n=== ANALYSIS ===\n";
$start = $timings[0]['time'];
foreach ($timings as $i => $t) {
    if ($i === 0) continue;
    $delta = ($t['time'] - $timings[$i-1]['time']) * 1000;
    if ($delta > 10) { // Flag anything over 10ms
        echo "SLOW: {$t['label']} took {$delta}ms\n";
    }
}

// Check if issue is in the restore loop
$restoreStart = null;
$restoreEnd = null;
foreach ($timings as $t) {
    if ($t['label'] === 'restore_outputs_start') $restoreStart = $t['time'];
    if ($t['label'] === 'restore_outputs_end') $restoreEnd = $t['time'];
}
if ($restoreStart && $restoreEnd) {
    $restoreTime = ($restoreEnd - $restoreStart) * 1000;
    echo "\nRestore outputs loop: {$restoreTime}ms for " . count($stepOutputs) . " outputs\n";
    if ($restoreTime > 100) {
        echo "WARNING: Restore loop is slow! Consider optimizing.\n";
    }
}

echo "\nDone.\n";
