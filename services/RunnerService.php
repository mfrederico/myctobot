<?php
/**
 * Runner Service
 * Manages Claude Code execution runners (remote workstations)
 */

namespace app\services;

use \app\Bean;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class RunnerService {

    /**
     * Get all runners
     */
    public static function getAllRunners(bool $activeOnly = true): array {
        $sql = "SELECT * FROM runners";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY name ASC";

        return Bean::getAll($sql);
    }

    /**
     * Get a runner by ID
     */
    public static function getRunner(int $runnerId): ?array {
        $runner = Bean::getRow("SELECT * FROM runners WHERE id = ?", [$runnerId]);
        return $runner ?: null;
    }

    /**
     * Get runner by host and port
     */
    public static function getRunnerByHost(string $host, int $port = 3500): ?array {
        $runner = Bean::getRow(
            "SELECT * FROM runners WHERE host = ? AND port = ?",
            [$host, $port]
        );
        return $runner ?: null;
    }

    /**
     * Create a new runner
     */
    public static function createRunner(array $data): int {
        $runner = Bean::dispense('runners');
        $runner->name = $data['name'];
        $runner->description = $data['description'] ?? '';
        $runner->host = $data['host'];
        $runner->port = $data['port'] ?? 3500;
        $runner->api_key = $data['api_key'] ?? '';
        $runner->runner_type = $data['runner_type'] ?? 'general';
        $runner->capabilities = json_encode($data['capabilities'] ?? ['git', 'filesystem']);
        $runner->max_concurrent_jobs = $data['max_concurrent_jobs'] ?? 2;
        $runner->is_active = $data['is_active'] ?? 1;
        $runner->is_default = $data['is_default'] ?? 0;
        $runner->health_status = 'unknown';

        // SSH execution mode fields
        $runner->execution_mode = $data['execution_mode'] ?? 'ssh_tmux';
        $runner->ssh_user = $data['ssh_user'] ?? 'claudeuser';
        $runner->ssh_port = $data['ssh_port'] ?? 22;
        $runner->sshkey_id = $data['sshkey_id'] ?? null;
        $runner->ssh_validated = $data['ssh_validated'] ?? 0;

        return Bean::store($runner);
    }

    /**
     * Update a runner
     */
    public static function updateRunner(int $runnerId, array $data): bool {
        $runner = Bean::load('runners', $runnerId);
        if (!$runner->id) {
            return false;
        }

        // List of allowed fields to update
        $allowedFields = [
            'name', 'description', 'host', 'port', 'api_key',
            'runner_type', 'capabilities', 'max_concurrent_jobs',
            'is_active', 'is_default', 'health_status',
            'execution_mode', 'ssh_user', 'ssh_port', 'sshkey_id',
            'ssh_validated', 'ssh_last_diagnostic', 'ssh_last_check'
        ];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                if ($key === 'capabilities' && is_array($value)) {
                    $runner->$key = json_encode($value);
                } else {
                    $runner->$key = $value;
                }
            }
        }

        Bean::store($runner);
        return true;
    }

    /**
     * Delete a runner
     */
    public static function deleteRunner(int $runnerId): bool {
        $runner = Bean::load('runners', $runnerId);
        if (!$runner->id) {
            return false;
        }

        Bean::trash($runner);
        return true;
    }

    /**
     * Health check a runner
     */
    public static function healthCheck(int $runnerId): array {
        $runner = self::getRunner($runnerId);
        if (!$runner) {
            return ['healthy' => false, 'error' => 'Runner not found'];
        }

        try {
            $client = new Client([
                'base_uri' => "http://{$runner['host']}:{$runner['port']}",
                'timeout' => 10
            ]);

            $response = $client->get('/health');
            $data = json_decode($response->getBody()->getContents(), true);

            // Update health status
            $runnerBean = Bean::load('runners', $runnerId);
            $runnerBean->health_status = 'healthy';
            $runnerBean->last_health_check = date('Y-m-d H:i:s');
            Bean::store($runnerBean);

            return [
                'healthy' => true,
                'data' => $data
            ];

        } catch (GuzzleException $e) {
            // Update health status
            $runnerBean = Bean::load('runners', $runnerId);
            $runnerBean->health_status = 'unhealthy';
            $runnerBean->last_health_check = date('Y-m-d H:i:s');
            Bean::store($runnerBean);

            return [
                'healthy' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Health check all runners
     */
    public static function healthCheckAll(): array {
        $runners = self::getAllRunners(false);
        $results = [];

        foreach ($runners as $runner) {
            $results[$runner['id']] = self::healthCheck($runner['id']);
        }

        return $results;
    }

    /**
     * Get runner capabilities
     */
    public static function getCapabilities(int $runnerId): array {
        $runner = self::getRunner($runnerId);
        if (!$runner) {
            return [];
        }

        $capabilities = json_decode($runner['capabilities'] ?? '[]', true);
        return is_array($capabilities) ? $capabilities : [];
    }

    /**
     * Get runners assigned to a member
     */
    public static function getMemberRunners(int $memberId): array {
        return Bean::getAll("
            SELECT r.*, ra.priority
            FROM runners r
            JOIN runnerassignments ra ON r.id = ra.runner_id
            WHERE ra.member_id = ? AND r.is_active = 1
            ORDER BY ra.priority DESC, r.name ASC
        ", [$memberId]);
    }

    /**
     * Assign a runner to a member
     */
    public static function assignRunner(int $memberId, int $runnerId, int $priority = 0): bool {
        try {
            // Check if assignment already exists
            $assignment = Bean::findOne('runnerassignments', 'member_id = ? AND runner_id = ?', [$memberId, $runnerId]);

            if ($assignment) {
                // Update existing
                $assignment->priority = $priority;
            } else {
                // Create new
                $assignment = Bean::dispense('runnerassignments');
                $assignment->member_id = $memberId;
                $assignment->runner_id = $runnerId;
                $assignment->priority = $priority;
            }

            Bean::store($assignment);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Remove runner assignment from member
     */
    public static function unassignRunner(int $memberId, int $runnerId): bool {
        $assignment = Bean::findOne('runnerassignments', 'member_id = ? AND runner_id = ?', [$memberId, $runnerId]);
        if ($assignment) {
            Bean::trash($assignment);
        }
        return true;
    }

    /**
     * Get default runners (available to all members)
     */
    public static function getDefaultRunners(): array {
        return Bean::getAll("
            SELECT * FROM runners
            WHERE is_active = 1 AND is_default = 1
            ORDER BY name ASC
        ");
    }

    /**
     * Get runner stats from aidevjobs table
     */
    public static function getRunnerStats(int $runnerId): array {
        $stats = Bean::getRow("
            SELECT
                COUNT(*) as total_jobs,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running_jobs,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_jobs,
                SUM(CASE WHEN status IN ('queued', 'pending') THEN 1 ELSE 0 END) as queued_jobs
            FROM aidevjobs
            WHERE runners_id = ?
        ", [$runnerId]);

        return $stats ?: [
            'total_jobs' => 0,
            'running_jobs' => 0,
            'completed_jobs' => 0,
            'failed_jobs' => 0,
            'queued_jobs' => 0
        ];
    }

    /**
     * Get running job count for a runner (from local DB)
     */
    public static function getRunningJobCount(int $runnerId): int {
        return (int) Bean::getCell(
            "SELECT COUNT(*) FROM aidevjobs WHERE runners_id = ? AND status = 'running'",
            [$runnerId]
        );
    }

}
