<?php
/**
 * Shard Service
 * Manages Claude Code execution shards
 */

namespace app\services;

use \RedBeanPHP\R as R;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ShardService {

    /**
     * Get all shards
     */
    public static function getAllShards(bool $activeOnly = true): array {
        $sql = "SELECT * FROM claudeshards";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY name ASC";

        return R::getAll($sql);
    }

    /**
     * Get a shard by ID
     */
    public static function getShard(int $shardId): ?array {
        $shard = R::getRow("SELECT * FROM claudeshards WHERE id = ?", [$shardId]);
        return $shard ?: null;
    }

    /**
     * Get shard by host and port
     */
    public static function getShardByHost(string $host, int $port = 3500): ?array {
        $shard = R::getRow(
            "SELECT * FROM claudeshards WHERE host = ? AND port = ?",
            [$host, $port]
        );
        return $shard ?: null;
    }

    /**
     * Create a new shard
     */
    public static function createShard(array $data): int {
        $shard = R::dispense('claudeshards');
        $shard->name = $data['name'];
        $shard->description = $data['description'] ?? '';
        $shard->host = $data['host'];
        $shard->port = $data['port'] ?? 3500;
        $shard->api_key = $data['api_key'] ?? '';
        $shard->shard_type = $data['shard_type'] ?? 'general';
        $shard->capabilities = json_encode($data['capabilities'] ?? ['git', 'filesystem']);
        $shard->max_concurrent_jobs = $data['max_concurrent_jobs'] ?? 2;
        $shard->is_active = $data['is_active'] ?? 1;
        $shard->is_default = $data['is_default'] ?? 0;
        $shard->health_status = 'unknown';

        // SSH execution mode fields
        $shard->execution_mode = $data['execution_mode'] ?? 'ssh_tmux';
        $shard->ssh_user = $data['ssh_user'] ?? 'claudeuser';
        $shard->ssh_port = $data['ssh_port'] ?? 22;
        $shard->ssh_key_path = $data['ssh_key_path'] ?? null;
        $shard->ssh_validated = $data['ssh_validated'] ?? 0;

        return R::store($shard);
    }

    /**
     * Update a shard
     */
    public static function updateShard(int $shardId, array $data): bool {
        $shard = R::load('claudeshards', $shardId);
        if (!$shard->id) {
            return false;
        }

        // List of allowed fields to update
        $allowedFields = [
            'name', 'description', 'host', 'port', 'api_key',
            'shard_type', 'capabilities', 'max_concurrent_jobs',
            'is_active', 'is_default', 'health_status',
            'execution_mode', 'ssh_user', 'ssh_port', 'ssh_key_path',
            'ssh_validated', 'ssh_last_diagnostic', 'ssh_last_check'
        ];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                if ($key === 'capabilities' && is_array($value)) {
                    $shard->$key = json_encode($value);
                } else {
                    $shard->$key = $value;
                }
            }
        }

        R::store($shard);
        return true;
    }

    /**
     * Delete a shard
     */
    public static function deleteShard(int $shardId): bool {
        $shard = R::load('claudeshards', $shardId);
        if (!$shard->id) {
            return false;
        }

        R::trash($shard);
        return true;
    }

    /**
     * Health check a shard
     */
    public static function healthCheck(int $shardId): array {
        $shard = self::getShard($shardId);
        if (!$shard) {
            return ['healthy' => false, 'error' => 'Shard not found'];
        }

        try {
            $client = new Client([
                'base_uri' => "http://{$shard['host']}:{$shard['port']}",
                'timeout' => 10
            ]);

            $response = $client->get('/health');
            $data = json_decode($response->getBody()->getContents(), true);

            // Update health status
            $shardBean = R::load('claudeshards', $shardId);
            $shardBean->health_status = 'healthy';
            $shardBean->last_health_check = date('Y-m-d H:i:s');
            R::store($shardBean);

            return [
                'healthy' => true,
                'data' => $data
            ];

        } catch (GuzzleException $e) {
            // Update health status
            $shardBean = R::load('claudeshards', $shardId);
            $shardBean->health_status = 'unhealthy';
            $shardBean->last_health_check = date('Y-m-d H:i:s');
            R::store($shardBean);

            return [
                'healthy' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Health check all shards
     */
    public static function healthCheckAll(): array {
        $shards = self::getAllShards(false);
        $results = [];

        foreach ($shards as $shard) {
            $results[$shard['id']] = self::healthCheck($shard['id']);
        }

        return $results;
    }

    /**
     * Get shard capabilities
     */
    public static function getCapabilities(int $shardId): array {
        $shard = self::getShard($shardId);
        if (!$shard) {
            return [];
        }

        $capabilities = json_decode($shard['capabilities'] ?? '[]', true);
        return is_array($capabilities) ? $capabilities : [];
    }

    /**
     * Get shards assigned to a member
     */
    public static function getMemberShards(int $memberId): array {
        return R::getAll("
            SELECT s.*, sa.priority
            FROM claudeshards s
            JOIN shardassignments sa ON s.id = sa.shard_id
            WHERE sa.member_id = ? AND s.is_active = 1
            ORDER BY sa.priority DESC, s.name ASC
        ", [$memberId]);
    }

    /**
     * Assign a shard to a member
     */
    public static function assignShard(int $memberId, int $shardId, int $priority = 0): bool {
        try {
            // Check if assignment already exists
            $assignment = R::findOne('shardassignments', 'member_id = ? AND shard_id = ?', [$memberId, $shardId]);

            if ($assignment) {
                // Update existing
                $assignment->priority = $priority;
            } else {
                // Create new
                $assignment = R::dispense('shardassignments');
                $assignment->member_id = $memberId;
                $assignment->shard_id = $shardId;
                $assignment->priority = $priority;
            }

            R::store($assignment);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Remove shard assignment from member
     */
    public static function unassignShard(int $memberId, int $shardId): bool {
        $assignment = R::findOne('shardassignments', 'member_id = ? AND shard_id = ?', [$memberId, $shardId]);
        if ($assignment) {
            R::trash($assignment);
        }
        return true;
    }

    /**
     * Get default shards (available to all members)
     */
    public static function getDefaultShards(): array {
        return R::getAll("
            SELECT * FROM claudeshards
            WHERE is_active = 1 AND is_default = 1
            ORDER BY name ASC
        ");
    }

    /**
     * Get shard stats
     */
    public static function getShardStats(int $shardId): array {
        $stats = R::getRow("
            SELECT
                COUNT(*) as total_jobs,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running_jobs,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_jobs,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued_jobs
            FROM shardjobs
            WHERE shard_id = ?
        ", [$shardId]);

        return $stats ?: [
            'total_jobs' => 0,
            'running_jobs' => 0,
            'completed_jobs' => 0,
            'failed_jobs' => 0,
            'queued_jobs' => 0
        ];
    }

    /**
     * Get running job count for a shard (from local DB)
     */
    public static function getRunningJobCount(int $shardId): int {
        return (int) R::getCell(
            "SELECT COUNT(*) FROM shardjobs WHERE shard_id = ? AND status = 'running'",
            [$shardId]
        );
    }
}
