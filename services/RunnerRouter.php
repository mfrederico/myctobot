<?php
/**
 * Runner Router Service
 * Routes jobs to available runners and manages execution
 */

namespace app\services;

use \app\Bean;
use \Flight as Flight;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use \app\plugins\AtlassianAuth;

require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';

class RunnerRouter {

    /**
     * Find the best available runner for a job (least-loaded routing)
     *
     * @param int $memberId Member ID
     * @param array $requiredCapabilities Required MCP capabilities
     * @return array|null Runner data or null if none available
     */
    public static function findAvailableRunner(int $memberId, array $requiredCapabilities = []): ?array {
        // First, check member-specific runner assignments
        $memberRunners = RunnerService::getMemberRunners($memberId);

        // If no specific assignments, use default (public) runners
        if (empty($memberRunners)) {
            $memberRunners = RunnerService::getDefaultRunners();
        }

        // Collect all eligible runners with their load info
        $candidates = [];

        foreach ($memberRunners as $runner) {
            // Check capabilities
            if (!empty($requiredCapabilities)) {
                $runnerCapabilities = json_decode($runner['capabilities'] ?? '[]', true);
                $hasAllCapabilities = empty(array_diff($requiredCapabilities, $runnerCapabilities));
                if (!$hasAllCapabilities) {
                    continue;
                }
            }

            // Check health status
            if ($runner['health_status'] === 'unhealthy') {
                continue;
            }

            // Get running job count
            $runningJobs = RunnerService::getRunningJobCount($runner['id']);
            $maxJobs = (int)$runner['max_concurrent_jobs'];

            // Skip if at capacity
            if ($runningJobs >= $maxJobs) {
                continue;
            }

            // For SSH mode, we trust local job tracking
            // For HTTP mode, optionally verify with live health check
            $executionMode = $runner['execution_mode'] ?? 'http_api';

            if ($executionMode === 'ssh_tmux') {
                // SSH mode - trust local tracking, add to candidates
                $candidates[] = [
                    'runner' => $runner,
                    'running' => $runningJobs,
                    'max' => $maxJobs,
                    'load' => $maxJobs > 0 ? ($runningJobs / $maxJobs) : 1
                ];
            } else {
                // HTTP mode - verify with live health check
                $health = self::quickHealthCheck($runner);
                if ($health && isset($health['jobs']['running'])) {
                    $liveRunning = $health['jobs']['running'];
                    if ($liveRunning < $maxJobs) {
                        $candidates[] = [
                            'runner' => $runner,
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

        // Return the least-loaded runner
        return $candidates[0]['runner'];
    }

    /**
     * Quick health check (just checks if runner is responding)
     */
    private static function quickHealthCheck(array $runner): ?array {
        try {
            $client = new Client([
                'base_uri' => "http://{$runner['host']}:{$runner['port']}",
                'timeout' => 5
            ]);

            $response = $client->get('/health');
            return json_decode($response->getBody()->getContents(), true);

        } catch (GuzzleException $e) {
            return null;
        }
    }

    /**
     * Execute a job on a specific runner
     *
     * @param int $runnerId Runner ID
     * @param array $jobPayload Job configuration
     * @return array Result with job_uid and status
     */
    public static function executeJob(int $runnerId, array $jobPayload): array {
        $runner = RunnerService::getRunner($runnerId);
        if (!$runner) {
            throw new \Exception("Runner not found: {$runnerId}");
        }

        // Generate job ID if not provided
        $jobId = $jobPayload['job_uid'] ?? bin2hex(random_bytes(16));
        $jobPayload['job_uid'] = $jobId;

        // Record job in local database
        $memberId = $jobPayload['member_id'] ?? 0;
        self::recordJob($jobId, $memberId, $runnerId, $jobPayload);

        try {
            $client = new Client([
                'base_uri' => "http://{$runner['host']}:{$runner['port']}",
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $runner['api_key'],
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
                'job_uid' => $jobId,
                'runner_id' => $runnerId,
                'runner_name' => $runner['name'],
                'result' => $result
            ];

        } catch (GuzzleException $e) {
            // Update job as failed
            self::updateJobStatus($jobId, 'failed', $e->getMessage());

            throw new \Exception("Failed to execute job on runner: " . $e->getMessage());
        }
    }

    /**
     * Get job status from runner
     */
    public static function getJobStatus(string $jobId): ?array {

        // Get job record using bean operations
        $job = Bean::findOne('runnerjobs', 'job_uid = ?', [$jobId]);
        if (!$job) {
            return null;
        }

        // Convert bean to array for return value
        $jobData = $job->export();

        // Get runner
        $runner = RunnerService::getRunner($job->runner_id);
        if (!$runner) {
            return $jobData; // Return local record if runner unavailable
        }

        try {
            $client = new Client([
                'base_uri' => "http://{$runner['host']}:{$runner['port']}",
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Bearer ' . $runner['api_key']
                ]
            ]);

            $response = $client->get("/job/{$jobId}/status");
            $runnerStatus = json_decode($response->getBody()->getContents(), true);

            // Sync status to local DB
            if (!empty($runnerStatus['status'])) {
                self::updateJobStatus($jobId, $runnerStatus['status']);
            }

            return array_merge($jobData, ['runner_status' => $runnerStatus]);

        } catch (GuzzleException $e) {
            // Return local record if runner unavailable
            return $jobData;
        }
    }

    /**
     * Get job output from runner
     */
    public static function getJobOutput(string $jobId): ?array {

        $job = Bean::findOne('runnerjobs', 'job_uid = ?', [$jobId]);
        if (!$job) {
            return null;
        }

        $runner = RunnerService::getRunner($job->runner_id);
        if (!$runner) {
            return null;
        }

        try {
            $client = new Client([
                'base_uri' => "http://{$runner['host']}:{$runner['port']}",
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $runner['api_key']
                ]
            ]);

            $response = $client->get("/job/{$jobId}/output");
            return json_decode($response->getBody()->getContents(), true);

        } catch (GuzzleException $e) {
            return null;
        }
    }

    /**
     * Cancel a job on runner
     */
    public static function cancelJob(string $jobId): bool {

        $job = Bean::findOne('runnerjobs', 'job_uid = ?', [$jobId]);
        if (!$job) {
            return false;
        }

        $runner = RunnerService::getRunner($job->runner_id);
        if (!$runner) {
            return false;
        }

        try {
            $client = new Client([
                'base_uri' => "http://{$runner['host']}:{$runner['port']}",
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Bearer ' . $runner['api_key']
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
    private static function recordJob(string $jobId, int $memberId, int $runnerId, array $payload): void {

        $job = Bean::dispense('runnerjobs');
        $job->job_uid = $jobId;
        $job->member_id = $memberId;
        $job->runner_id = $runnerId;
        $job->issue_key = $payload['task']['issue_key'] ?? null;
        $job->status = 'queued';
        $job->request_payload = json_encode($payload);
        $job->created_at = date('Y-m-d H:i:s');
        Bean::store($job);
    }

    /**
     * Update job status in local database
     */
    public static function updateJobStatus(string $jobId, string $status, ?string $error = null): void {

        $job = Bean::findOne('runnerjobs', 'job_uid = ?', [$jobId]);
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

        Bean::store($job);
    }

    /**
     * Update job result
     */
    public static function updateJobResult(string $jobId, array $result): void {

        $job = Bean::findOne('runnerjobs', 'job_uid = ?', [$jobId]);
        if (!$job) return;

        $job->result_payload = json_encode($result);
        $job->status = 'completed';
        $job->completed_at = date('Y-m-d H:i:s');
        Bean::store($job);
    }

    /**
     * Get jobs for a member
     */
    public static function getMemberJobs(int $memberId, int $limit = 50): array {

        return Bean::getAll("
            SELECT rj.*, r.name as runner_name
            FROM runnerjobs rj
            JOIN runners r ON rj.runner_id = r.id
            WHERE rj.member_id = ?
            ORDER BY rj.created_at DESC
            LIMIT ?
        ", [$memberId, $limit]);
    }

    /**
     * Get active jobs for a member
     */
    public static function getMemberActiveJobs(int $memberId): array {

        return Bean::getAll("
            SELECT rj.*, r.name as runner_name
            FROM runnerjobs rj
            JOIN runners r ON rj.runner_id = r.id
            WHERE rj.member_id = ? AND rj.status IN ('queued', 'running')
            ORDER BY rj.created_at DESC
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

        // Get member using bean operations
        $member = Bean::load('member', $memberId);

        // Get Atlassian/Jira token using bean operations (include shared tokens)
        $whereClause = '(member_id = ? OR is_shared = 1)';
        $params = [$memberId];

        if ($cloudId) {
            $whereClause .= ' AND cloud_uid = ?';
            $params[] = $cloudId;
        }

        $whereClause .= ' ORDER BY updated_at DESC LIMIT 1';
        $token = Bean::findOne('atlassiantoken', $whereClause, $params);

        if ($token) {
            // For OAuth tokens, must use Atlassian API gateway URL (not site_url)
            // site_url is for browser links, API calls must go through api.atlassian.com
            $jiraHost = AtlassianAuth::getApiBaseUrl($token->cloud_uid);
            // Remove /rest/api/3 suffix - Claude will add the appropriate path
            $jiraHost = str_replace('/rest/api/3', '', $jiraHost);

            $credentials['jira_host'] = $jiraHost;
            $credentials['jira_email'] = $member->email ?? '';
            // Also include site_url for display/links in reports
            $credentials['jira_site_url'] = $token->site_url ?? '';

            // Get a valid access token (refreshes if expired)
            $validToken = AtlassianAuth::getValidToken($memberId, $token->cloud_uid);
            $credentials['jira_api_token'] = $validToken;
        }

        return $credentials;
    }

    /**
     * Get SSE stream URL for a job
     */
    public static function getStreamUrl(string $jobId): ?string {

        $job = Bean::findOne('runnerjobs', 'job_uid = ?', [$jobId]);
        if (!$job) {
            return null;
        }

        $runner = RunnerService::getRunner($job->runner_id);
        if (!$runner) {
            return null;
        }

        return "http://{$runner['host']}:{$runner['port']}/job/{$jobId}/stream";
    }

}
