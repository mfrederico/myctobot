<?php
/**
 * Runner API Controller
 *
 * Provides HTTP API for remote runners to fetch job configuration
 * and report status. This enables SSH push + API pull architecture
 * where runners fetch everything they need via authenticated HTTPS.
 *
 * Endpoints:
 *   GET  /api/runner/boot                     - Bootstrap script (no auth)
 *   GET  /api/runner/jobs/{id}/manifest       - Job configuration
 *   GET  /api/runner/jobs/{id}/runner         - Runner script
 *   GET  /api/runner/jobs/{id}/files/{name}   - Job files (prompt, claude.md, etc.)
 *   POST /api/runner/jobs/{id}/status         - Update job status
 *   POST /api/runner/jobs/{id}/log            - Append job logs
 *   POST /api/runner/jobs/{id}/complete       - Mark job complete
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use app\BaseControls\Control;
use \app\services\RunnerRouter;
use \app\services\RunnerService;

class Runnerapi extends Control {

    private ?string $workspace = null;
    private ?int $jobId = null;
    private ?object $job = null;

    public function __construct() {
        // Don't call parent - API requests may not have sessions
        $this->logger = Flight::get('log');
    }

    /**
     * Validate job token from X-Job-Token header
     * Returns the job bean if valid, null otherwise
     */
    private function validateJobToken(string $jobIdOrUid): ?object {
        $token = $_SERVER['HTTP_X_JOB_TOKEN'] ?? '';

        if (empty($token)) {
            $this->logger->warning('Runner API: Missing job token');
            return null;
        }

        // Try to find job by ID or UID
        $job = is_numeric($jobIdOrUid)
            ? Bean::load('aidevjobs', (int)$jobIdOrUid)
            : Bean::findOne('aidevjobs', 'job_uid = ?', [$jobIdOrUid]);

        if (!$job || !$job->id) {
            $this->logger->warning('Runner API: Job not found', ['job' => $jobIdOrUid]);
            return null;
        }

        // Verify token matches (constant-time — SECURITY LOW, CWE-208)
        if (empty($token) || !hash_equals((string)$job->job_token, (string)$token)) {
            $this->logger->warning('Runner API: Invalid job token', ['job_id' => $job->id]);
            return null;
        }

        // Check token hasn't expired (tokens valid for 24 hours)
        if ($job->token_expires_at && strtotime($job->token_expires_at) < time()) {
            $this->logger->warning('Runner API: Job token expired', ['job_id' => $job->id]);
            return null;
        }

        // Load workspace database if job has workspace
        if ($job->workspace_slug) {
            $this->switchToworkspace($job->workspace_slug);
        }

        return $job;
    }

    /**
     * Switch to workspace database
     */
    private function switchToworkspace(string $workspace): void {
        $this->workspace = $workspace;
        $configFile = "conf/config.{$workspace}.ini";

        if (!file_exists($configFile)) {
            return;
        }

        $workspaceConfig = parse_ini_file($configFile, true);
        if (!$workspaceConfig || empty($workspaceConfig['database'])) {
            return;
        }

        $dbConfig = $workspaceConfig['database'];
        $type = $dbConfig['type'] ?? 'mysql';

        if ($type === 'sqlite') {
            $dbPath = $dbConfig['path'] ?? "database/{$workspace}.sqlite";
            $dsn = "sqlite:{$dbPath}";
            Bean::useDatabase($workspace, $dsn);
        } else {
            $host = $dbConfig['host'] ?? 'localhost';
            $port = $dbConfig['port'] ?? 3306;
            $name = $dbConfig['name'] ?? $workspace;
            $user = $dbConfig['user'] ?? 'root';
            $pass = $dbConfig['pass'] ?? '';
            $dsn = "{$type}:host={$host};port={$port};dbname={$name}";
            Bean::useDatabase($workspace, $dsn, $user, $pass);
        }
    }

    /**
     * GET /api/runner/boot
     * Returns the bootstrap script (no auth required - minimal script)
     */
    public function boot() {
        header('Content-Type: text/plain');

        // Get site config for domain branding
        $siteDomain = \app\services\SiteConfig::getDomain();
        $siteName = \app\services\SiteConfig::getName();
        $siteBaseUrl = \app\services\SiteConfig::getBaseUrl();

        echo <<<BASH
#!/bin/bash
#
# {$siteName} Runner Bootstrap
# Fetched fresh on every job - always up to date
#
# Usage: curl -sfL {$siteBaseUrl}/api/runner/boot | bash -s -- --workspace=workspace --job=JOB_ID --token=TOKEN
#

set -e

# Parse arguments
while [[ \$# -gt 0 ]]; do
    case \$1 in
        --workspace=*) workspace="\${1#*=}"; shift ;;
        --job=*) JOB_ID="\${1#*=}"; shift ;;
        --token=*) TOKEN="\${1#*=}"; shift ;;
        *) echo "Unknown option: \$1"; exit 1 ;;
    esac
done

# Validate required args
if [[ -z "\$workspace" || -z "\$JOB_ID" || -z "\$TOKEN" ]]; then
    echo "Usage: boot.sh --workspace=workspace --job=JOB_ID --token=TOKEN"
    exit 1
fi

# Setup
API="https://\${workspace}.{$siteDomain}/api/runner"
WORK="\$HOME/jobs/\${workspace}/\${JOB_ID}"
AUTH_HEADER="X-Job-Token: \${TOKEN}"

echo "=== {$siteName} Runner Bootstrap ==="
echo "workspace: \$workspace"
echo "Job ID: \$JOB_ID"
echo "Work Dir: \$WORK"
echo ""

# Create work directory
mkdir -p "\$WORK"
cd "\$WORK"

# Fetch job manifest
echo "Fetching job manifest..."
if ! curl -sf -H "\$AUTH_HEADER" "\${API}/jobs/\${JOB_ID}/manifest" -o manifest.json; then
    echo "ERROR: Failed to fetch manifest"
    exit 1
fi

# Fetch runner script
echo "Fetching runner script..."
if ! curl -sf -H "\$AUTH_HEADER" "\${API}/jobs/\${JOB_ID}/runner" -o runner.sh; then
    echo "ERROR: Failed to fetch runner"
    exit 1
fi
chmod +x runner.sh

# Hand off to runner
echo "Starting runner..."
exec ./runner.sh
BASH;
        exit;
    }

    /**
     * GET /api/runner/jobs/{id}/manifest
     * Returns job configuration as JSON
     */
    public function manifest(string $jobId) {
        $job = $this->validateJobToken($jobId);
        if (!$job) {
            Flight::json(['error' => 'Unauthorized'], 401);
            return;
        }

        // Load related data
        $repo = $job->repoconnections_id ? Bean::load('repoconnections', $job->repoconnections_id) : null;
        $agent = $repo && $repo->aiagents_id ? Bean::load('aiagents', $repo->aiagents_id) : null;

        // Build manifest
        $manifest = [
            'job_id' => $job->id,
            'job_uid' => $job->job_uid,
            'issue_key' => $job->issue_key,
            'workspace' => $job->workspace_slug,
            'created_at' => $job->created_at,

            'repo' => $repo ? [
                'url' => $repo->repo_url,
                'name' => $repo->repo_name,
                'default_branch' => $repo->default_branch ?? 'main',
            ] : null,

            'agent' => $agent ? [
                'name' => $agent->name,
                'provider' => $agent->provider,
                'provider_config' => json_decode($agent->provider_config ?: '{}', true),
            ] : null,

            'plugins' => json_decode($agent->plugins ?? '[]', true),

            'files' => [
                'prompt.txt',
                'claude.md',
                'mcp.json',
                'env.sh',
            ],
        ];

        Flight::json($manifest);
    }

    /**
     * GET /api/runner/jobs/{id}/runner
     * Returns the runner script
     */
    public function runner(string $jobId) {
        $job = $this->validateJobToken($jobId);
        if (!$job) {
            Flight::json(['error' => 'Unauthorized'], 401);
            return;
        }

        header('Content-Type: text/plain');

        // Get site config for domain branding
        $siteDomain = \app\services\SiteConfig::getDomain();
        $siteName = \app\services\SiteConfig::getName();

        echo <<<BASH
#!/bin/bash
#
# {$siteName} Job Runner
# Executes Claude CLI with job configuration
#

set -e

# Load manifest
workspace=\$(jq -r '.workspace' manifest.json)
JOB_ID=\$(jq -r '.job_id' manifest.json)
REPO_URL=\$(jq -r '.repo.url // empty' manifest.json)
BRANCH=\$(jq -r '.repo.default_branch // "main"' manifest.json)
AGENT_NAME=\$(jq -r '.agent.name // "AI Assistant"' manifest.json)

API="https://\${workspace}.{$siteDomain}/api/runner"
TOKEN="\${TOKEN:-\$(cat .token 2>/dev/null || echo '')}"
AUTH_HEADER="X-Job-Token: \${TOKEN}"

# Status update helper
update_status() {
    local status=\$1
    local phase=\$2
    curl -sf -X POST -H "\$AUTH_HEADER" -H "Content-Type: application/json" \\
        "\${API}/jobs/\${JOB_ID}/status" \\
        -d "{\\"status\\":\\"\$status\\",\\"phase\\":\\"\$phase\\"}" >/dev/null 2>&1 || true
}

echo "=== {$siteName} Job Runner ==="
echo "Agent: \$AGENT_NAME"
echo "Job: \$JOB_ID"
echo ""

# Update status
update_status "running" "setup"

# Fetch job files
echo "Fetching job files..."
for file in prompt.txt claude.md mcp.json env.sh; do
    curl -sf -H "\$AUTH_HEADER" "\${API}/jobs/\${JOB_ID}/files/\${file}" -o "\$file" 2>/dev/null || true
done

# Source environment (sets GITHUB_TOKEN, ANTHROPIC_API_KEY, etc.)
if [[ -f env.sh ]]; then
    source env.sh
fi

# Clone repository if specified
if [[ -n "\$REPO_URL" ]]; then
    echo "Cloning repository..."
    update_status "running" "cloning"

    if [[ -d repo ]]; then
        rm -rf repo
    fi

    git clone --depth 1 -b "\$BRANCH" "\$REPO_URL" repo
    cd repo

    # Copy config files into repo
    cp ../mcp.json .mcp.json 2>/dev/null || true
    cp ../claude.md CLAUDE.md 2>/dev/null || true

    # Setup .claude directory
    mkdir -p .claude
    echo '{"enableAllProjectMcpServers":true}' > .claude/settings.json
fi

# Run Claude
echo "Starting Claude..."
update_status "running" "claude"

PROMPT=\$(cat ../prompt.txt 2>/dev/null || echo "Hello, please help with this task.")

if command -v claude &>/dev/null; then
    claude --dangerously-skip-permissions "\$PROMPT"
    EXIT_CODE=\$?
else
    echo "ERROR: Claude CLI not found"
    EXIT_CODE=1
fi

# Report completion
if [[ \$EXIT_CODE -eq 0 ]]; then
    echo "Job completed successfully"
    curl -sf -X POST -H "\$AUTH_HEADER" -H "Content-Type: application/json" \\
        "\${API}/jobs/\${JOB_ID}/complete" \\
        -d '{"status":"success"}' >/dev/null 2>&1 || true
else
    echo "Job failed with exit code \$EXIT_CODE"
    curl -sf -X POST -H "\$AUTH_HEADER" -H "Content-Type: application/json" \\
        "\${API}/jobs/\${JOB_ID}/complete" \\
        -d "{\\"status\\":\\"failed\\",\\"error\\":\\"Exit code \$EXIT_CODE\\"}" >/dev/null 2>&1 || true
fi

# Cleanup
cd ~
rm -rf "\$WORK"

exit \$EXIT_CODE
BASH;
        exit;
    }

    /**
     * GET /api/runner/jobs/{id}/files/{filename}
     * Returns job files (prompt.txt, claude.md, mcp.json, env.sh)
     */
    public function files(string $jobId, string $filename) {
        $job = $this->validateJobToken($jobId);
        if (!$job) {
            Flight::json(['error' => 'Unauthorized'], 401);
            return;
        }

        // Sanitize filename
        $filename = basename($filename);
        $allowed = ['prompt.txt', 'claude.md', 'mcp.json', 'env.sh'];

        if (!in_array($filename, $allowed)) {
            Flight::json(['error' => 'File not found'], 404);
            return;
        }

        // Check job work directory
        $workDir = $job->work_dir ?? "/tmp/aoe-{$job->workspace_slug}-{$job->issue_key}-*";
        $dirs = glob($workDir, GLOB_ONLYDIR);

        if (!empty($dirs)) {
            $jobDir = $dirs[0];
            $filePath = "{$jobDir}/{$filename}";

            // Map alternate names
            if ($filename === 'claude.md' && !file_exists($filePath)) {
                $filePath = "{$jobDir}/CLAUDE.md";
            }
            if ($filename === 'mcp.json' && !file_exists($filePath)) {
                $filePath = "{$jobDir}/.mcp.json";
            }

            if (file_exists($filePath)) {
                header('Content-Type: text/plain');
                readfile($filePath);
                exit;
            }
        }

        // Generate dynamically if not in work dir
        header('Content-Type: text/plain');

        switch ($filename) {
            case 'prompt.txt':
                echo $job->prompt ?? "Please help with issue {$job->issue_key}";
                break;

            case 'claude.md':
                echo $this->generateClaudeMd($job);
                break;

            case 'mcp.json':
                echo $this->generateMcpJson($job);
                break;

            case 'env.sh':
                echo $this->generateEnvSh($job);
                break;

            default:
                echo "# File not found: {$filename}";
        }
        exit;
    }

    /**
     * POST /api/runner/jobs/{id}/status
     * Update job status
     */
    public function status(string $jobId) {
        $job = $this->validateJobToken($jobId);
        if (!$job) {
            Flight::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $status = $input['status'] ?? null;
        $phase = $input['phase'] ?? null;

        if ($status) {
            $job->status = $status;
        }
        if ($phase) {
            $job->phase = $phase;
        }
        $job->updated_at = date('Y-m-d H:i:s');

        Bean::store($job);

        $this->logger->info('Runner API: Job status updated', [
            'job_id' => $job->id,
            'status' => $status,
            'phase' => $phase,
        ]);

        Flight::json(['success' => true]);
    }

    /**
     * POST /api/runner/jobs/{id}/log
     * Append to job logs
     */
    public function log(string $jobId) {
        $job = $this->validateJobToken($jobId);
        if (!$job) {
            Flight::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $lines = $input['lines'] ?? [];

        if (!empty($lines)) {
            // Store logs in aidevjoblogs table
            foreach ($lines as $line) {
                $log = Bean::dispense('aidevjoblogs');
                $log->aidevjobs_id = $job->id;
                $log->content = $line;
                $log->created_at = date('Y-m-d H:i:s');
                Bean::store($log);
            }
        }

        Flight::json(['success' => true]);
    }

    /**
     * POST /api/runner/jobs/{id}/complete
     * Mark job as complete
     */
    public function complete(string $jobId) {
        $job = $this->validateJobToken($jobId);
        if (!$job) {
            Flight::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $status = $input['status'] ?? 'completed';
        $error = $input['error'] ?? null;
        $summary = $input['summary'] ?? null;

        $job->status = $status === 'success' ? 'completed' : 'failed';
        $job->completed_at = date('Y-m-d H:i:s');

        if ($error) {
            $job->error_message = $error;
        }
        if ($summary) {
            $job->summary = $summary;
        }

        // Invalidate token
        $job->job_token = null;
        $job->token_expires_at = null;

        Bean::store($job);

        $this->logger->info('Runner API: Job completed', [
            'job_id' => $job->id,
            'status' => $job->status,
        ]);

        Flight::json(['success' => true]);
    }

    /**
     * Generate CLAUDE.md content for a job
     */
    private function generateClaudeMd(object $job): string {
        $agent = null;
        if ($job->repoconnections_id) {
            $repo = Bean::load('repoconnections', $job->repoconnections_id);
            if ($repo && $repo->aiagents_id) {
                $agent = Bean::load('aiagents', $repo->aiagents_id);
            }
        }

        $content = "# Project Instructions\n\n";

        if ($agent && $agent->system_prompt) {
            $content .= $agent->system_prompt . "\n\n";
        }

        $content .= "## Current Task\n\n";
        $content .= "Issue: {$job->issue_key}\n";
        $content .= "workspace: {$job->workspace_slug}\n\n";

        return $content;
    }

    /**
     * Generate .mcp.json content for a job
     */
    private function generateMcpJson(object $job): string {
        $workspace = $job->workspace_slug ?? 'default';

        // Get member ID and cloud ID from job or related data
        $memberId = $job->member_id ?? 0;
        $cloudId = '';

        // Try to get cloud ID from atlassian connection
        $token = Bean::findOne('connections', 'connector_type = ? AND (member_id = ? OR shared = 1) ORDER BY updated_at DESC LIMIT 1', ['atlassian', $memberId]);
        if ($token) {
            $cloudId = $token->external_eid;
        }

        // Get member's API token for pipeline auth
        $apiToken = '';
        if ($memberId) {
            $member = Bean::load('member', (int)$memberId);
            if ($member && $member->id) {
                $apiToken = $member->api_token ?? '';
            }
        }

        $wsBaseUrl = \app\services\SiteConfig::getWorkspaceUrl($workspace);

        $config = [
            'mcpServers' => [
                'jira' => [
                    'type' => 'http',
                    'url' => $wsBaseUrl . '/mcp/jira',
                    'headers' => [
                        'X-MCP-Member-ID' => (string)$memberId,
                        'X-MCP-Cloud-ID' => $cloudId,
                    ],
                ],
                'pipelines' => [
                    'type' => 'http',
                    'url' => $wsBaseUrl . '/mcp/pipelines',
                    'headers' => [
                        'Authorization' => "Bearer {$apiToken}",
                        'X-Workspace' => $workspace,
                    ],
                ],
            ],
        ];

        return json_encode($config, JSON_PRETTY_PRINT);
    }

    /**
     * Generate env.sh content for a job (environment variables)
     */
    private function generateEnvSh(object $job): string {
        require_once __DIR__ . '/../services/EncryptionService.php';

        $lines = ["#!/bin/bash", "# Environment variables for job {$job->id}", ""];

        // Get repo for GitHub token
        if ($job->repoconnections_id) {
            $repo = Bean::load('repoconnections', $job->repoconnections_id);
            if ($repo && $repo->access_token) {
                $token = \app\services\EncryptionService::decrypt($repo->access_token);
                $lines[] = "export GITHUB_TOKEN=\"{$token}\"";
            }
        }

        // Get agent for Anthropic key
        if ($job->repoconnections_id) {
            $repo = Bean::load('repoconnections', $job->repoconnections_id);
            if ($repo && $repo->aiagents_id) {
                $agent = Bean::load('aiagents', $repo->aiagents_id);
                if ($agent && $agent->connections_id) {
                    require_once __DIR__ . '/../services/AnthropicKeyService.php';
                    $key = \app\services\AnthropicKeyService::getKey($agent->connections_id);
                    if ($key && !empty($key['decrypted_key'])) {
                        $lines[] = "export ANTHROPIC_API_KEY=\"{$key['decrypted_key']}\"";
                    }
                }
            }
        }

        // Add agent name for MCP signatures
        $agentName = 'AI Assistant';
        if ($job->repoconnections_id) {
            $repo = Bean::load('repoconnections', $job->repoconnections_id);
            if ($repo && $repo->aiagents_id) {
                $agent = Bean::load('aiagents', $repo->aiagents_id);
                if ($agent) {
                    $agentName = $agent->name;
                }
            }
        }
        $lines[] = "export MYCTOBOT_AGENT_NAME=\"{$agentName}\"";

        // Add job metadata
        $lines[] = "export MYCTOBOT_JOB_ID=\"{$job->id}\"";
        $lines[] = "export MYCTOBOT_ISSUE_KEY=\"{$job->issue_key}\"";
        $lines[] = "export MYCTOBOT_workspace=\"{$job->workspace_slug}\"";

        return implode("\n", $lines) . "\n";
    }

    // ========================================
    // Legacy runner methods (migrated from Enterprise controller)
    // ========================================

    /**
     * Get member ID from session (for session-based methods called via redirect stub)
     */
    private function getSessionMemberId(): ?int {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            session_start();
        }
        $id = $_SESSION['member_id'] ?? null;
        return $id ? (int) $id : null;
    }

    /**
     * POST /runnerapi/runnercallback
     * Callback endpoint for runner job completion (stateless, no auth required)
     */
    public function runnercallback($params = []) {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data || empty($data['job_uid'])) {
            Flight::json(['success' => false, 'error' => 'Invalid callback data']);
            return;
        }

        $jobId = $data['job_uid'];
        $status = $data['status'] ?? 'unknown';

        if ($status === 'completed') {
            RunnerRouter::updateJobResult($jobId, $data['result'] ?? []);
            $this->logger->info('Runner job completed', ['job_uid' => $jobId]);
        } elseif ($status === 'failed') {
            RunnerRouter::updateJobStatus($jobId, 'failed', $data['error'] ?? 'Unknown error');
            $this->logger->error('Runner job failed', ['job_uid' => $jobId, 'error' => $data['error'] ?? '']);
        }

        Flight::json(['success' => true]);
    }

    /**
     * GET /runnerapi/runnerjobstatus/{jobId}
     * Get runner job status by job ID
     */
    public function runnerjobstatus($params = []) {
        $memberId = $this->getSessionMemberId();
        if (!$memberId) {
            Flight::json(['success' => false, 'error' => 'Login required']);
            return;
        }

        $jobId = $params[0] ?? '';
        if (empty($jobId)) {
            Flight::json(['success' => false, 'error' => 'Job ID required']);
            return;
        }

        $status = RunnerRouter::getJobStatus($jobId);

        if (!$status) {
            Flight::json(['success' => false, 'error' => 'Job not found']);
            return;
        }

        if ($status['member_id'] != $memberId) {
            Flight::json(['success' => false, 'error' => 'Access denied']);
            return;
        }

        Flight::json([
            'success' => true,
            'status' => $status
        ]);
    }

    /**
     * GET /runnerapi/runnerjoboutput/{jobId}
     * Get runner job output/logs
     */
    public function runnerjoboutput($params = []) {
        $memberId = $this->getSessionMemberId();
        if (!$memberId) {
            Flight::json(['success' => false, 'error' => 'Login required']);
            return;
        }

        $jobId = $params[0] ?? '';
        if (empty($jobId)) {
            Flight::json(['success' => false, 'error' => 'Job ID required']);
            return;
        }

        $job = RunnerRouter::getJobStatus($jobId);
        if (!$job || $job['member_id'] != $memberId) {
            Flight::json(['success' => false, 'error' => 'Job not found']);
            return;
        }

        $output = RunnerRouter::getJobOutput($jobId);

        Flight::json([
            'success' => true,
            'output' => $output
        ]);
    }

    /**
     * GET /runnerapi/runners
     * List available runners for the current member
     */
    public function runners($params = []) {
        $memberId = $this->getSessionMemberId();
        if (!$memberId) {
            Flight::json(['success' => false, 'error' => 'Login required']);
            return;
        }

        $memberRunners = RunnerService::getMemberRunners($memberId);
        if (empty($memberRunners)) {
            $memberRunners = RunnerService::getDefaultRunners();
        }

        Flight::json([
            'success' => true,
            'runners' => RunnerService::enrichRunners($memberRunners)
        ]);
    }
}
