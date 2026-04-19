<?php
/**
 * PipelineDispatcher - Centralized pipeline execution routing
 *
 * Routes pipeline execution to the fastest available method:
 *   1. Persistent engine (OpenSwoole service) — fast path (~0.1s)
 *   2. In-process PipelineExecutor — sync fallback
 *   3. Background runpipe.php process — async fallback
 *
 * All pipeline dispatch sites should use this instead of directly
 * spawning processes or instantiating PipelineExecutor.
 */

namespace app\services;

use app\Bean;

class PipelineDispatcher
{
    /** @var string Base directory of the project */
    private static ?string $projectDir = null;

    /**
     * Dispatch a pipeline run for execution
     *
     * @param int $runId The pipeline run ID (must already exist in DB with status=pending)
     * @param string $workspace The workspace slug
     * @param bool $sync If true, wait for completion and return results
     * @param array $options Optional: ['entry_step' => str, 'member_id' => int, 'schedule_id' => int]
     * @return array Result with 'success', 'method' (engine|process_sync|process_async), and execution data
     */
    public static function dispatch(int $runId, string $workspace, bool $sync = false, array $options = []): array
    {
        // Try engine first
        $engineResult = self::tryEngine($runId, $workspace, $sync);
        if ($engineResult !== null) {
            return $engineResult;
        }

        // Fallback: sync in-process or async background process
        if ($sync) {
            return self::executeSyncInProcess($runId);
        } else {
            return self::executeAsyncProcess($runId, $workspace, $options);
        }
    }

    /**
     * Resume a paused pipeline (from await_input)
     *
     * @param int $runId The pipeline run ID
     * @param string $workspace The workspace slug
     * @param array $input The input data to resume with
     * @param string $inputToken The await input token
     * @param string $source Source identifier (api, form, mcp)
     * @return array Result with 'success' and resume data
     */
    public static function resume(int $runId, string $workspace, array $input, string $inputToken, string $source = 'api'): array
    {
        // Try engine first
        try {
            $client = new PipelineEngineClient($workspace);
            if ($client->isAvailable()) {
                $result = $client->resume($runId, $input, $inputToken, $source);
                if ($result['success'] ?? false) {
                    $result['method'] = 'engine';
                    return $result;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to direct execution
        }

        // Fallback: in-process resume
        return self::resumeInProcess($runId, $input, $inputToken, $source);
    }

    /**
     * Try dispatching to the persistent engine service
     *
     * @return array|null Result if engine handled it, null if unavailable
     */
    private static function tryEngine(int $runId, string $workspace, bool $sync): ?array
    {
        try {
            $client = new PipelineEngineClient($workspace);
            if (!$client->isAvailable()) {
                return null;
            }

            $result = $client->run($runId, $sync);
            if ($result['success'] ?? false) {
                $result['method'] = 'engine';
                return $result;
            }

            // Engine returned an error — fall back to process
            return null;

        } catch (\Throwable $e) {
            // Engine unreachable — fall back to process
            return null;
        }
    }

    /**
     * Execute synchronously in the current process
     */
    private static function executeSyncInProcess(int $runId): array
    {
        try {
            $executor = new PipelineExecutor($runId);
            $executor->execute();

            $run = Bean::load('pipelineruns', $runId);

            return [
                'success' => true,
                'method' => 'process_sync',
                'run_id' => $runId,
                'status' => $run->status,
                'output' => json_decode($run->output_json ?: '{}', true),
                'completed_at' => $run->completed_at,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'method' => 'process_sync',
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Execute asynchronously via background process (legacy fallback)
     */
    private static function executeAsyncProcess(int $runId, string $workspace, array $options = []): array
    {
        $projectDir = self::getProjectDir();
        $scriptPath = $projectDir . '/scripts/runpipe.php';
        $logPath = $projectDir . '/log/pipeline-' . date('Y-m-d') . '.log';

        // Build command with optional args
        $args = sprintf(
            '--workspace=%s --run-id=%d',
            escapeshellarg($workspace),
            $runId
        );

        if (!empty($options['member_id'])) {
            $args .= sprintf(' --member=%d', (int) $options['member_id']);
        }

        if (!empty($options['entry_step'])) {
            $args .= sprintf(' --entry-step=%s', escapeshellarg($options['entry_step']));
        }

        if (!empty($options['schedule_id'])) {
            $args .= sprintf(' --schedule-id=%d', (int) $options['schedule_id']);
        }

        $cmd = sprintf(
            'nohup php %s %s >> %s 2>&1 &',
            escapeshellarg($scriptPath),
            $args,
            escapeshellarg($logPath)
        );

        exec($cmd);

        return [
            'success' => true,
            'method' => 'process_async',
            'run_id' => $runId,
            'status' => 'pending',
        ];
    }

    /**
     * Resume a pipeline in-process (fallback)
     */
    private static function resumeInProcess(int $runId, array $input, string $inputToken, string $source): array
    {
        try {
            $executor = new PipelineExecutor($runId);
            $result = $executor->resumeFromAwaitInput($input, $inputToken, $source);
            $result['method'] = 'process_sync';
            return $result;
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'method' => 'process_sync',
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get the project root directory
     */
    private static function getProjectDir(): string
    {
        if (self::$projectDir === null) {
            self::$projectDir = dirname(__DIR__);
        }
        return self::$projectDir;
    }
}
