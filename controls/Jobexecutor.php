<?php
/**
 * Job Executor Controller
 *
 * Simple ping/pong validation for job-executor service.
 * PING: MyCTOBot sends job_uid to job-executor
 * PONG: Job-executor calls back with job_uid to validate and get details
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\services\EncryptionService;

class Jobexecutor extends BaseControls\Control {

    /**
     * POST /api/jobexecutor/validate
     *
     * PONG: Job-executor calls back to validate a job.
     * Returns job details if valid.
     *
     * Expected input: { "workspace": "gwt", "job_uid": "abc123..." }
     * Also accepts "tenant" as alias for "workspace" (job-executor compatibility)
     */
    public function validate() {
        $input = json_decode(file_get_contents('php://input'), true);

        // Accept both "workspace" and "tenant" for compatibility
        $workspace = $input['workspace'] ?? $input['tenant'] ?? null;
        $jobUid = $input['job_uid'] ?? null;

        if (!$input || empty($workspace) || empty($jobUid)) {
            Flight::jsonError('Missing workspace/tenant or job_uid', 400);
            return;
        }

        Flight::get('log')->info('Job validation request (PONG)', [
            'workspace' => $workspace,
            'job_uid' => substr($jobUid, 0, 8) . '...',
        ]);

        // Switch to workspace database
        if (!$this->switchToWorkspaceDatabase($workspace)) {
            Flight::json(['valid' => false, 'error' => 'Failed to access workspace'], 200);
            return;
        }

        // Find the job by job_uid
        $job = Bean::findOne('aidevjobs', 'job_uid = ?', [$jobUid]);

        if (!$job) {
            Flight::get('log')->warning('Job not found', ['job_uid' => substr($jobUid, 0, 8)]);
            Flight::json(['valid' => false, 'error' => 'Job not found'], 200);
            return;
        }

        // Load repo details if assigned
        $repo = null;
        if ($job->repoconnections_id) {
            $repo = Bean::load('repoconnections', $job->repoconnections_id);
        }

        // Load agent - check direct assignment first, then repo
        $agent = null;
        if ($job->aiagents_id) {
            // Direct agent assignment (used by agent test endpoint)
            $agent = Bean::load('aiagents', $job->aiagents_id);
        } elseif ($repo && $repo->aiagents_id) {
            // Agent from repo (normal job flow)
            $agent = Bean::load('aiagents', $repo->aiagents_id);
        }

        // Load workstation - check direct assignment first (for testing), then agent default
        $workstation = null;
        if ($job->runners_id) {
            // Direct runner assignment takes priority (used by test endpoint)
            $workstation = Bean::load('runners', $job->runners_id);
        } elseif ($agent && $agent->runners_id) {
            // Fall back to agent's default workstation
            $workstation = Bean::load('runners', $agent->runners_id);
        }

        // Mark job as validated
        $job->status = 'validated';
        $job->phase = 'validated';
        $job->updated_at = date('Y-m-d H:i:s');
        Bean::store($job);

        Flight::get('log')->info('Job validated', [
            'job_id' => $job->id,
            'job_uid' => $jobUid,
            'workstation' => $workstation ? $workstation->name : 'none',
        ]);

        // Return validation response with all job details
        $response = [
            'valid' => true,
            'job' => [
                'id' => (int) $job->id,
                'job_uid' => $job->job_uid,
                'member_id' => (int) $job->member_id,
                'issue_key' => $job->issue_key,
                'prompt' => $job->prompt,
                'branch' => $job->branch_name ?? 'main',
                'status' => $job->status,
                'delay' => (int) ($job->delay ?? 0),  // Optional delay in seconds
            ],
        ];

        // Add workstation if available
        if ($workstation && $workstation->id) {
            $response['workstation'] = [
                'id' => (int) $workstation->id,
                'name' => $workstation->name,
                'host' => $workstation->host,
                'ssh_user' => $workstation->ssh_user ?? 'claudeuser',
                'ssh_port' => (int) ($workstation->ssh_port ?? 22),
            ];
        }

        // Add repo if available
        if ($repo && $repo->id) {
            // Determine repo URL - try stored URLs first, then construct from owner/name
            $repoUrl = $repo->github_repo_url ?? $repo->repo_url ?? '';
            if (empty($repoUrl) && !empty($repo->repo_owner) && !empty($repo->repo_name)) {
                // Construct URL from repo_owner and repo_name
                $repoUrl = "https://github.com/{$repo->repo_owner}/{$repo->repo_name}.git";
            }

            $response['repo'] = [
                'id' => (int) $repo->id,
                'name' => $repo->repo_name,
                'url' => $repoUrl,
                'branch' => $repo->default_branch ?? 'main',
            ];

            // Add GitHub token for git operations
            $githubToken = null;
            if (!empty($repo->access_token)) {
                try {
                    $githubToken = EncryptionService::decrypt($repo->access_token);
                } catch (\Exception $e) {
                    // Ignore decryption errors
                }
            }
            if (!$githubToken) {
                // Fall back to enterprise-level token
                $tokenSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);
                if ($tokenSetting && !empty($tokenSetting->setting_value)) {
                    try {
                        $githubToken = EncryptionService::decrypt($tokenSetting->setting_value);
                    } catch (\Exception $e) {
                        // Ignore
                    }
                }
            }
            if ($githubToken) {
                $response['secrets'] = [
                    'github_token' => $githubToken,
                ];
            }
        }

        // Add agent if available
        if ($agent && $agent->id) {
            // Use job-prepared files if available (from job-dispatcher.php),
            // otherwise fall back to agent's stored config
            $claudeMd = $job->claude_md ?: $agent->claude_md;
            // Pass mcp_config as raw JSON string to avoid decode/encode cycles
            // that can corrupt {} vs [] distinction
            $mcpConfigJson = $job->mcp_config_json ?: $agent->mcp_config ?: '{"mcpServers":{}}';

            $response['agent'] = [
                'id' => (int) $agent->id,
                'name' => $agent->name,
                'provider' => $agent->provider ?: 'claude_cli',  // Include provider type
                'claude_md' => $claudeMd,
                'mcp_config_json' => $mcpConfigJson,  // Raw JSON string, not decoded
                'provider_config' => json_decode($agent->provider_config ?: '{}', true),
            ];
        }

        // Add prepared env script if available (from job-dispatcher.php)
        if (!empty($job->env_script)) {
            $response['prepared_files'] = [
                'env_script' => $job->env_script,
            ];
        }

        Flight::json($response);
    }

    /**
     * POST /api/jobexecutor/status
     *
     * Job-executor reports status updates.
     *
     * Expected input: { "workspace": "gwt", "job_uid": "abc123...", "status": "running", "phase": "executing" }
     * Also accepts "tenant" as alias for "workspace" (job-executor compatibility)
     */
    public function status() {
        $input = json_decode(file_get_contents('php://input'), true);

        // Accept both "workspace" and "tenant" for compatibility
        $workspace = $input['workspace'] ?? $input['tenant'] ?? null;
        $jobUid = $input['job_uid'] ?? null;

        if (!$input || empty($workspace) || empty($jobUid)) {
            Flight::jsonError('Missing workspace/tenant or job_uid', 400);
            return;
        }
        $status = $input['status'] ?? '';
        $phase = $input['phase'] ?? '';
        $summary = $input['summary'] ?? null;
        $errorMessage = $input['error_message'] ?? null;

        // Switch to workspace database
        if (!$this->switchToWorkspaceDatabase($workspace)) {
            Flight::jsonError('Failed to access workspace', 500);
            return;
        }

        // Find the job
        $job = Bean::findOne('aidevjobs', 'job_uid = ?', [$jobUid]);

        if (!$job) {
            Flight::jsonError('Job not found', 404);
            return;
        }

        // Update status
        if ($status) {
            $job->status = $status;
        }
        if ($phase) {
            $job->phase = $phase;
        }
        if ($summary) {
            $job->summary = $summary;
        }
        if ($errorMessage) {
            $job->error_message = $errorMessage;
        }

        $job->updated_at = date('Y-m-d H:i:s');

        if ($status === 'completed' || $status === 'failed') {
            $job->completed_at = date('Y-m-d H:i:s');
        }

        if ($status === 'running' && !$job->started_at) {
            $job->started_at = date('Y-m-d H:i:s');
        }

        Bean::store($job);

        Flight::get('log')->info('Job status updated', [
            'job_uid' => $jobUid,
            'status' => $status,
            'phase' => $phase,
        ]);

        Flight::jsonSuccess(['updated' => true]);
    }

    /**
     * Switch to workspace database
     */
    private function switchToWorkspaceDatabase(string $workspace): bool {
        try {
            $configFile = BASE_PATH . "/conf/config.{$workspace}.ini";
            if (!file_exists($configFile)) {
                Flight::get('log')->error('Workspace config not found', ['workspace' => $workspace]);
                return false;
            }

            $workspaceConfig = parse_ini_file($configFile, true);
            if (!$workspaceConfig || empty($workspaceConfig['database'])) {
                return false;
            }

            $dbConfig = $workspaceConfig['database'];
            $dsn = sprintf('%s:host=%s;port=%d;dbname=%s',
                $dbConfig['type'] ?? 'mysql',
                $dbConfig['host'] ?? 'localhost',
                $dbConfig['port'] ?? 3306,
                $dbConfig['name'] ?? $workspace
            );

            if (!Bean::hasDatabase($workspace)) {
                Bean::addDatabase($workspace, $dsn, $dbConfig['user'] ?? 'root', $dbConfig['pass'] ?? '');
            }
            Bean::selectDatabase($workspace);
            return true;
        } catch (\Exception $e) {
            Flight::get('log')->error('Failed to switch to workspace: ' . $e->getMessage());
            return false;
        }
    }
}
