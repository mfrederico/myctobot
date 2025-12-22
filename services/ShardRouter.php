<?php
/**
 * Shard Router Service
 * Routes jobs to available shards and manages execution
 */

namespace app\services;

use \RedBeanPHP\R as R;
use \Flight as Flight;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use \app\plugins\AtlassianAuth;

require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';

class ShardRouter {

    /**
     * Find the best available shard for a job (least-loaded routing)
     *
     * @param int $memberId Member ID
     * @param array $requiredCapabilities Required MCP capabilities
     * @return array|null Shard data or null if none available
     */
    public static function findAvailableShard(int $memberId, array $requiredCapabilities = []): ?array {
        // First, check member-specific shard assignments
        $memberShards = ShardService::getMemberShards($memberId);

        // If no specific assignments, use default (public) shards
        if (empty($memberShards)) {
            $memberShards = ShardService::getDefaultShards();
        }

        // Collect all eligible shards with their load info
        $candidates = [];

        foreach ($memberShards as $shard) {
            // Check capabilities
            if (!empty($requiredCapabilities)) {
                $shardCapabilities = json_decode($shard['capabilities'] ?? '[]', true);
                $hasAllCapabilities = empty(array_diff($requiredCapabilities, $shardCapabilities));
                if (!$hasAllCapabilities) {
                    continue;
                }
            }

            // Check health status
            if ($shard['health_status'] === 'unhealthy') {
                continue;
            }

            // Get running job count
            $runningJobs = ShardService::getRunningJobCount($shard['id']);
            $maxJobs = (int)$shard['max_concurrent_jobs'];

            // Skip if at capacity
            if ($runningJobs >= $maxJobs) {
                continue;
            }

            // For SSH mode, we trust local job tracking
            // For HTTP mode, optionally verify with live health check
            $executionMode = $shard['execution_mode'] ?? 'http_api';

            if ($executionMode === 'ssh_tmux') {
                // SSH mode - trust local tracking, add to candidates
                $candidates[] = [
                    'shard' => $shard,
                    'running' => $runningJobs,
                    'max' => $maxJobs,
                    'load' => $maxJobs > 0 ? ($runningJobs / $maxJobs) : 1
                ];
            } else {
                // HTTP mode - verify with live health check
                $health = self::quickHealthCheck($shard);
                if ($health && isset($health['jobs']['running'])) {
                    $liveRunning = $health['jobs']['running'];
                    if ($liveRunning < $maxJobs) {
                        $candidates[] = [
                            'shard' => $shard,
                            'running' => $liveRunning,
                            'max' => $maxJobs,
                            'load' => $maxJobs > 0 ? ($liveRunning / $maxJobs) : 1
                        ];
                    }
                }
            }
        }

        // No candidates available
        if (empty($candidates)) {
            return null;
        }

        // Sort by load (ascending) - least loaded first
        usort($candidates, function($a, $b) {
            return $a['load'] <=> $b['load'];
        });

        // Return the least-loaded shard
        return $candidates[0]['shard'];
    }

    /**
     * Quick health check (just checks if shard is responding)
     */
    private static function quickHealthCheck(array $shard): ?array {
        try {
            $client = new Client([
                'base_uri' => "http://{$shard['host']}:{$shard['port']}",
                'timeout' => 5
            ]);

            $response = $client->get('/health');
            return json_decode($response->getBody()->getContents(), true);

        } catch (GuzzleException $e) {
            return null;
        }
    }

    /**
     * Execute a job on a specific shard
     *
     * @param int $shardId Shard ID
     * @param array $jobPayload Job configuration
     * @return array Result with job_id and status
     */
    public static function executeJob(int $shardId, array $jobPayload): array {
        $shard = ShardService::getShard($shardId);
        if (!$shard) {
            throw new \Exception("Shard not found: {$shardId}");
        }

        // Generate job ID if not provided
        $jobId = $jobPayload['job_id'] ?? bin2hex(random_bytes(16));
        $jobPayload['job_id'] = $jobId;

        // Record job in local database
        $memberId = $jobPayload['member_id'] ?? 0;
        self::recordJob($jobId, $memberId, $shardId, $jobPayload);

        try {
            $client = new Client([
                'base_uri' => "http://{$shard['host']}:{$shard['port']}",
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $shard['api_key'],
                    'Content-Type' => 'application/json'
                ]
            ]);

            $response = $client->post('/job/execute', [
                'json' => $jobPayload
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            // Update job status
            self::updateJobStatus($jobId, 'running');

            return [
                'success' => true,
                'job_id' => $jobId,
                'shard_id' => $shardId,
                'shard_name' => $shard['name'],
                'result' => $result
            ];

        } catch (GuzzleException $e) {
            // Update job as failed
            self::updateJobStatus($jobId, 'failed', $e->getMessage());

            throw new \Exception("Failed to execute job on shard: " . $e->getMessage());
        }
    }

    /**
     * Get job status from shard
     */
    public static function getJobStatus(string $jobId): ?array {
        // Get job record
        $job = R::getRow("SELECT * FROM shardjobs WHERE job_id = ?", [$jobId]);
        if (!$job) {
            return null;
        }

        // Get shard
        $shard = ShardService::getShard($job['shard_id']);
        if (!$shard) {
            return $job; // Return local record if shard unavailable
        }

        try {
            $client = new Client([
                'base_uri' => "http://{$shard['host']}:{$shard['port']}",
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Bearer ' . $shard['api_key']
                ]
            ]);

            $response = $client->get("/job/{$jobId}/status");
            $shardStatus = json_decode($response->getBody()->getContents(), true);

            // Sync status to local DB
            if (!empty($shardStatus['status'])) {
                self::updateJobStatus($jobId, $shardStatus['status']);
            }

            return array_merge($job, ['shard_status' => $shardStatus]);

        } catch (GuzzleException $e) {
            // Return local record if shard unavailable
            return $job;
        }
    }

    /**
     * Get job output from shard
     */
    public static function getJobOutput(string $jobId): ?array {
        $job = R::getRow("SELECT * FROM shardjobs WHERE job_id = ?", [$jobId]);
        if (!$job) {
            return null;
        }

        $shard = ShardService::getShard($job['shard_id']);
        if (!$shard) {
            return null;
        }

        try {
            $client = new Client([
                'base_uri' => "http://{$shard['host']}:{$shard['port']}",
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $shard['api_key']
                ]
            ]);

            $response = $client->get("/job/{$jobId}/output");
            return json_decode($response->getBody()->getContents(), true);

        } catch (GuzzleException $e) {
            return null;
        }
    }

    /**
     * Cancel a job on shard
     */
    public static function cancelJob(string $jobId): bool {
        $job = R::getRow("SELECT * FROM shardjobs WHERE job_id = ?", [$jobId]);
        if (!$job) {
            return false;
        }

        $shard = ShardService::getShard($job['shard_id']);
        if (!$shard) {
            return false;
        }

        try {
            $client = new Client([
                'base_uri' => "http://{$shard['host']}:{$shard['port']}",
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Bearer ' . $shard['api_key']
                ]
            ]);

            $client->post("/job/{$jobId}/cancel");
            self::updateJobStatus($jobId, 'cancelled');

            return true;

        } catch (GuzzleException $e) {
            return false;
        }
    }

    /**
     * Record a job in local database
     */
    private static function recordJob(string $jobId, int $memberId, int $shardId, array $payload): void {
        $job = R::dispense('shardjobs');
        $job->job_id = $jobId;
        $job->member_id = $memberId;
        $job->shard_id = $shardId;
        $job->issue_key = $payload['task']['issue_key'] ?? null;
        $job->status = 'queued';
        $job->request_payload = json_encode($payload);
        $job->created_at = date('Y-m-d H:i:s');
        R::store($job);
    }

    /**
     * Update job status in local database
     */
    public static function updateJobStatus(string $jobId, string $status, ?string $error = null): void {
        $job = R::findOne('shardjobs', 'job_id = ?', [$jobId]);
        if (!$job) return;

        $job->status = $status;
        $job->updated_at = date('Y-m-d H:i:s');

        if ($status === 'running' && empty($job->started_at)) {
            $job->started_at = date('Y-m-d H:i:s');
        }

        if (in_array($status, ['completed', 'failed', 'cancelled'])) {
            $job->completed_at = date('Y-m-d H:i:s');
        }

        if ($error) {
            $job->error_message = $error;
        }

        R::store($job);
    }

    /**
     * Update job result
     */
    public static function updateJobResult(string $jobId, array $result): void {
        $job = R::findOne('shardjobs', 'job_id = ?', [$jobId]);
        if (!$job) return;

        $job->result_payload = json_encode($result);
        $job->status = 'completed';
        $job->completed_at = date('Y-m-d H:i:s');
        R::store($job);
    }

    /**
     * Get jobs for a member
     */
    public static function getMemberJobs(int $memberId, int $limit = 50): array {
        return R::getAll("
            SELECT sj.*, cs.name as shard_name
            FROM shardjobs sj
            JOIN claudeshards cs ON sj.shard_id = cs.id
            WHERE sj.member_id = ?
            ORDER BY sj.created_at DESC
            LIMIT ?
        ", [$memberId, $limit]);
    }

    /**
     * Get active jobs for a member
     */
    public static function getMemberActiveJobs(int $memberId): array {
        return R::getAll("
            SELECT sj.*, cs.name as shard_name
            FROM shardjobs sj
            JOIN claudeshards cs ON sj.shard_id = cs.id
            WHERE sj.member_id = ? AND sj.status IN ('queued', 'running')
            ORDER BY sj.created_at DESC
        ", [$memberId]);
    }

    /**
     * Build job payload for AI Developer task
     *
     * @param int $memberId Member ID
     * @param string $anthropicApiKey Anthropic API key for Claude
     * @param array $task Task configuration
     * @param array $context Additional context
     * @param string|null $callbackUrl Webhook callback URL
     * @param array $credentials Optional credentials for MCP servers (github_token, jira_host, jira_email, jira_api_token)
     */
    public static function buildAIDevPayload(
        int $memberId,
        string $anthropicApiKey,
        array $task,
        array $context = [],
        ?string $callbackUrl = null,
        array $credentials = []
    ): array {
        $payload = [
            'member_id' => $memberId,
            'anthropic_api_key' => $anthropicApiKey,
            'task' => $task,
            'context' => $context,
            'callback_url' => $callbackUrl,
            'mcp_servers' => [] // Can be extended for custom MCP configs
        ];

        // Add GitHub credentials if provided
        if (!empty($credentials['github_token'])) {
            $payload['github_token'] = $credentials['github_token'];
        }

        // Add Jira/Atlassian credentials if provided
        if (!empty($credentials['jira_host'])) {
            $payload['jira_host'] = $credentials['jira_host'];
        }
        if (!empty($credentials['jira_email'])) {
            $payload['jira_email'] = $credentials['jira_email'];
        }
        if (!empty($credentials['jira_api_token'])) {
            $payload['jira_api_token'] = $credentials['jira_api_token'];
        }

        return $payload;
    }

    /**
     * Get MCP credentials for a member
     * Extracts Jira/GitHub credentials from member's connected accounts
     *
     * @param int $memberId Member ID
     * @param string|null $cloudId Jira cloud ID (optional, uses first if not specified)
     * @return array Credentials array for buildAIDevPayload
     */
    public static function getMemberMcpCredentials(int $memberId, ?string $cloudId = null): array {
        $credentials = [];

        // Get member email
        $member = R::getRow("SELECT email FROM member WHERE id = ?", [$memberId]);

        // Get Atlassian/Jira token
        $tokenQuery = "SELECT * FROM atlassiantoken WHERE member_id = ?";
        $params = [$memberId];

        if ($cloudId) {
            $tokenQuery .= " AND cloud_id = ?";
            $params[] = $cloudId;
        }

        $tokenQuery .= " ORDER BY updated_at DESC LIMIT 1";
        $token = R::getRow($tokenQuery, $params);

        if ($token) {
            // For OAuth tokens, must use Atlassian API gateway URL (not site_url)
            // site_url is for browser links, API calls must go through api.atlassian.com
            $jiraHost = AtlassianAuth::getApiBaseUrl($token['cloud_id']);
            // Remove /rest/api/3 suffix - Claude will add the appropriate path
            $jiraHost = str_replace('/rest/api/3', '', $jiraHost);

            $credentials['jira_host'] = $jiraHost;
            $credentials['jira_email'] = $member['email'] ?? '';
            // Also include site_url for display/links in reports
            $credentials['jira_site_url'] = $token['site_url'] ?? '';

            // Get a valid access token (refreshes if expired)
            $validToken = AtlassianAuth::getValidToken($memberId, $token['cloud_id']);
            $credentials['jira_api_token'] = $validToken;
        }

        return $credentials;
    }

    /**
     * Get SSE stream URL for a job
     */
    public static function getStreamUrl(string $jobId): ?string {
        $job = R::getRow("SELECT * FROM shardjobs WHERE job_id = ?", [$jobId]);
        if (!$job) {
            return null;
        }

        $shard = ShardService::getShard($job['shard_id']);
        if (!$shard) {
            return null;
        }

        return "http://{$shard['host']}:{$shard['port']}/job/{$jobId}/stream";
    }
}
