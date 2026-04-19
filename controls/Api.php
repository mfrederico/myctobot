<?php
/**
 * API Controller - Stateless REST API
 *
 * ALL /api/* routes require Bearer token authentication (tk_*) via ApiAuthService.
 * Uses DefaultRoute pattern: /api/{method}/{operation}/{id}
 *
 * URL Mapping:
 *   /api              -> index()
 *   /api/health       -> health()
 *   /api/mcp/tools    -> mcp($params) where operation->name='tools'
 *   /api/auth/validate -> auth($params) where operation->name='validate'
 *   /api/jobs/complete -> jobs($params) where operation->name='complete'
 *   etc.
 *
 * The only public-facing HTTP services are Tenant Apps which run as
 * separate OpenSwoole servers with their own auth mechanisms.
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;
use \Exception as Exception;
use \app\services\ApiAuthService;
use \app\services\AgentToolService;
use \app\WorkspaceResolver;

/**
 * Stateless API Controller
 *
 * Does NOT use sessions - authenticates via Bearer token (tk_*) on every request.
 * Permissions controlled via ApiAuthService which validates token scopes and
 * checks member's authcontrol permissions.
 */
class Api {

    protected $logger;
    private ?string $workspaceSlug = null;
    private ?int $workspaceMemberId = null;
    private ?object $member = null;
    private ?object $apiKey = null;

    public function __construct() {
        // Stateless - no parent::__construct(), no sessions
        $this->logger = Flight::get('log');

        // CORS headers for cross-origin requests (static app exports)
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Workspace, X-App-User-Token, X-API-Key');
        header('Access-Control-Max-Age: 86400');

        // Handle OPTIONS preflight
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    /**
     * Authenticate via API key using ApiAuthService
     * Requires workspace to be determined first (via subdomain, header, or query param)
     *
     * @param string $controller Controller name for scope check
     * @param string $method Method name for scope check
     * @param string|null $urlWorkspace Optional workspace from URL path
     * @return bool True if authenticated, false otherwise (error already sent)
     */
    private function requireAuth(string $controller, string $method, ?string $urlWorkspace = null): bool {
        // Determine workspace from multiple sources (URL param takes priority)
        $workspace = $urlWorkspace
            ?? $_SERVER['WORKSPACE']
            ?? $_SERVER['HTTP_X_WORKSPACE']
            ?? $_GET['workspace']
            ?? $_POST['workspace']
            ?? null;

        if (empty($workspace)) {
            Flight::jsonError('Workspace required (via subdomain, X-Workspace header, or ?workspace= param)', 400);
            return false;
        }

        // Switch to workspace database
        if (!WorkspaceResolver::switchDatabase($workspace)) {
            Flight::jsonError("Invalid workspace: {$workspace}", 400);
            return false;
        }

        $this->workspaceSlug = $workspace;

        // Authenticate via ApiAuthService (validates tk_* token, scopes, member permissions)
        $authResult = ApiAuthService::authenticate($controller, $method);
        if (!$authResult['success']) {
            Flight::jsonError($authResult['error'], $authResult['code']);
            return false;
        }

        $this->member = $authResult['member'];
        $this->apiKey = $authResult['apikey'];
        $this->workspaceMemberId = $authResult['member']->id;

        return true;
    }

    /**
     * Helper to get request parameter
     */
    private function getParam(string $key, $default = null) {
        return $_REQUEST[$key] ?? $_GET[$key] ?? $_POST[$key] ?? $default;
    }

    // =========================================================================
    // /api - Index
    // =========================================================================

    /**
     * GET /api
     * API index - authenticated status check
     */
    public function index($params = []) {
        if (!$this->requireAuth('api', 'index')) return;

        Flight::jsonSuccess([
            'status' => 'ok',
            'service' => 'myctobot-api',
            'workspace' => $this->workspaceSlug,
            'member_id' => $this->member->id,
            'timestamp' => date('c')
        ]);
    }

    // =========================================================================
    // /api/health - Health check
    // =========================================================================

    /**
     * GET /api/health
     * Health check endpoint
     */
    public function health($params = []) {
        if (!$this->requireAuth('api', 'health')) return;

        Flight::jsonSuccess([
            'status' => 'healthy',
            'workspace' => $this->workspaceSlug,
            'timestamp' => date('c'),
            'version' => '1.0.0'
        ]);
    }

    // =========================================================================
    // /api/mcp/* - MCP Gateway (uses AgentToolService)
    // /api/mcp/tools, /api/mcp/call, /api/mcp/config/{agentId}
    // =========================================================================

    /**
     * /api/mcp/{action}/{param}
     * MCP gateway - tools, call, config endpoints
     */
    public function mcp($params = []) {
        $action = $params['operation']->name ?? 'tools';
        $param = $params['operation']->type ?? null;

        if (!$this->requireAuth('api', 'mcp')) return;

        $toolService = new AgentToolService($this->workspaceMemberId, $this->workspaceSlug);

        switch ($action) {
            case 'tools':
                $tools = $toolService->listTools();
                Flight::jsonSuccess(['tools' => $tools]);
                break;

            case 'call':
                $rawBody = file_get_contents('php://input');
                $request = json_decode($rawBody, true);

                $toolName = $request['tool_name'] ?? $this->getParam('tool_name', '');
                $arguments = $request['arguments'] ?? json_decode($this->getParam('arguments', '{}'), true) ?: [];

                if (empty($toolName)) {
                    Flight::jsonError('tool_name is required', 400);
                    return;
                }

                try {
                    $result = $toolService->callTool($toolName, $arguments);
                    Flight::jsonSuccess(['result' => $result]);
                } catch (Exception $e) {
                    Flight::jsonError($e->getMessage(), $e->getCode() ?: 400);
                }
                break;

            case 'config':
                $agentId = (int) $param;
                if ($agentId <= 0) {
                    Flight::jsonError('Agent ID required: /api/mcp/config/{agentId}', 400);
                    return;
                }

                $config = $toolService->getAgentConfig($agentId);
                if (!$config) {
                    Flight::jsonError('Agent not found or not exposed as MCP', 404);
                    return;
                }

                header('Content-Type: application/json');
                echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;

            default:
                Flight::jsonError("Unknown MCP action: {$action}. Use tools, call, or config/{agentId}", 400);
        }
    }

    // =========================================================================
    // /api/auth/* - Authentication endpoints
    // /api/auth/validate
    // =========================================================================

    /**
     * /api/auth/{action}
     * Auth endpoints - validate tokens
     */
    public function auth($params = []) {
        $action = $params['operation']->name ?? 'validate';

        // Auth validation doesn't require prior authentication (it IS the auth check)
        if ($action === 'validate') {
            $this->authValidateInternal();
        } else {
            Flight::jsonError("Unknown auth action: {$action}. Use validate", 400);
        }
    }

    private function authValidateInternal(): void {
        $token = $this->getParam('token');
        $workspace = $this->getParam('workspace') ?? 'default';

        if (empty($token)) {
            Flight::jsonError('Token required', 400);
            return;
        }

        if (!WorkspaceResolver::switchDatabase($workspace)) {
            Flight::jsonError("Invalid workspace: {$workspace}", 400);
            return;
        }

        $result = ApiAuthService::validateToken($token, 'mcp');
        if (!$result['success']) {
            Flight::jsonError($result['error'], $result['code'] ?? 401);
            return;
        }

        $member = $result['member'];
        $apiKey = $result['apikey'];

        $connections = method_exists($member, 'getConnections') ? $member->getConnections() : [];

        Flight::jsonSuccess([
            'valid' => true,
            'member_id' => (int) $member->id,
            'member_email' => $member->email,
            'member_name' => $member->username ?? $member->display_name ?? $member->email,
            'level' => (int) ($member->level ?? 100),
            'scopes' => json_decode($apiKey->scopes_json ?? '[]', true),
            'workspace' => $workspace,
            'connections' => $connections,
        ]);
    }

    // =========================================================================
    // /api/jobs/* - Job management
    // /api/jobs/complete
    // =========================================================================

    /**
     * /api/jobs/{action}
     * Job management endpoints
     */
    public function jobs($params = []) {
        $action = is_array($params) ? ($params['operation']->name ?? 'complete') : $params;

        if ($action === 'complete') {
            $this->jobsCompleteInternal();
        } else {
            Flight::jsonError("Unknown jobs action: {$action}. Use complete", 400);
        }
    }

    private function jobsCompleteInternal(): void {
        $workspace = $this->getParam('workspace')
            ?? $_SERVER['HTTP_X_WORKSPACE']
            ?? $_SERVER['WORKSPACE']
            ?? null;

        if (!$workspace) {
            Flight::jsonError('Workspace required', 400);
            return;
        }

        if (!WorkspaceResolver::switchDatabase($workspace)) {
            Flight::jsonError('Invalid workspace', 400);
            return;
        }

        // Authenticate - check for Basic auth (job_id:job_uid) or Bearer token (tk_*)
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $memberId = null;
        $jobId = null;

        if (preg_match('/^Basic\s+(.+)$/i', $authHeader, $matches)) {
            $decoded = base64_decode($matches[1]);
            if ($decoded && strpos($decoded, ':') !== false) {
                [$jobIdStr, $jobUid] = explode(':', $decoded, 2);
                $jobId = (int) $jobIdStr;

                $job = Bean::load('aidevjobs', $jobId);
                if (!$job || !$job->id || $job->job_uid !== $jobUid) {
                    Flight::jsonError('Invalid job credentials', 401);
                    return;
                }
                $memberId = $job->member_id;
            }
        } elseif (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $authResult = ApiAuthService::authenticate('api', 'jobs');
            if (!$authResult['success']) {
                Flight::jsonError($authResult['error'], $authResult['code']);
                return;
            }
            $memberId = $authResult['member']->id;
        } else {
            Flight::jsonError('Authentication required (Bearer tk_* or Basic job_id:job_uid)', 401);
            return;
        }

        $jobId = $jobId ?: (int) $this->getParam('job_id');
        if (!$jobId) {
            Flight::jsonError('job_id required', 400);
            return;
        }

        $job = Bean::load('aidevjobs', $jobId);
        if (!$job || !$job->id) {
            Flight::jsonError('Job not found', 404);
            return;
        }

        $status = $this->getParam('status') ?? 'complete';
        $summary = $this->getParam('summary') ?? '';
        $lastOutput = $this->getParam('last_output') ?? '';

        $job->status = $status;
        $job->completed_at = date('Y-m-d H:i:s');
        $job->updated_at = date('Y-m-d H:i:s');

        if ($summary) {
            $job->last_result_json = json_encode(['summary' => $summary]);
        }
        if ($lastOutput) {
            $job->last_output = $lastOutput;
        }

        Bean::store($job);

        $this->logger->info('Job status updated via API', [
            'job_id' => $jobId,
            'status' => $status,
            'workspace' => $workspace
        ]);

        // Trigger pipeline continuation if applicable
        $pipelineContinued = false;
        if ($status === 'complete' && str_starts_with($job->issue_key, 'PIPE-')) {
            $parts = explode('-', $job->issue_key);
            if (count($parts) >= 2) {
                $runId = (int) $parts[1];
                $run = Bean::load('pipelineruns', $runId);

                if ($run && $run->id && $run->status === 'running') {
                    $this->logger->info('Triggering pipeline continuation', [
                        'run_id' => $runId,
                        'job_id' => $jobId
                    ]);

                    $scriptPath = Flight::get('flight.base_path') . '/scripts/cron/cron-scheduled-tasks.php';
                    $cmd = sprintf(
                        'php %s --workspace=%s --continue-run=%d > /dev/null 2>&1 &',
                        escapeshellarg($scriptPath),
                        escapeshellarg($workspace),
                        $runId
                    );
                    exec($cmd);
                    $pipelineContinued = true;
                }
            }
        }

        Flight::jsonSuccess([
            'job_id' => $jobId,
            'status' => $status,
            'updated_at' => $job->updated_at,
            'pipeline_continued' => $pipelineContinued
        ]);
    }

    // =========================================================================
    // /api/workstations - Workstation listing (workspace from subdomain)
    // =========================================================================

    /**
     * /api/workstations
     * List active workstations/runners
     */
    public function workstations($params = []) {
        if (!$this->requireAuth('api', 'workstations')) return;

        $runners = Bean::find('runners', 'is_active = 1 ORDER BY name ASC');

        $workstations = [];
        foreach ($runners as $runner) {
            $workstations[] = [
                'id' => (int) $runner->id,
                'name' => $runner->name,
                'host' => $runner->host,
                'ssh_user' => $runner->ssh_user ?? 'claudeuser',
                'ssh_port' => (int) ($runner->ssh_port ?? 22),
                'execution_mode' => $runner->execution_mode ?? 'ssh_tmux',
                'sshkey_id' => $runner->sshkey_id ? (int) $runner->sshkey_id : null,
            ];
        }

        Flight::jsonSuccess(['workstations' => $workstations]);
    }

    // =========================================================================
    // /api/jobexecutor/* - Job executor integration
    // /api/jobexecutor/validate, /api/jobexecutor/status
    // =========================================================================

    /**
     * /api/jobexecutor/{action}
     * Job executor ping/pong validation and status updates
     */
    public function jobexecutor($params = []) {
        $action = $params['operation']->name ?? 'validate';

        // No Bearer token auth — job-executor callbacks authenticate by job_uid lookup.
        // The Jobexecutor controller handles its own workspace DB switching and validation.

        // Delegate to Jobexecutor controller
        $controller = new Jobexecutor();
        if ($action === 'validate') {
            $controller->validate();
        } elseif ($action === 'status') {
            $controller->status();
        } else {
            Flight::jsonError("Unknown jobexecutor action: {$action}. Use validate or status", 400);
        }
    }

    // =========================================================================
    // /api/ceo/* - CEO Directive endpoints
    // /api/ceo/directive, /api/ceo/directive/{id}
    // =========================================================================

    /**
     * /api/ceo/{resource}/{id?}
     * CEO directive management
     */
    public function ceo($params = []) {
        $resource = $params['operation']->name ?? 'directive';
        $id = $params['operation']->type ?? null;

        if ($resource !== 'directive') {
            Flight::jsonError("Unknown CEO resource: {$resource}. Use directive", 400);
            return;
        }

        if (!$this->requireAuth('ceodirective', $id ? 'get' : 'receive')) return;

        $controller = new Ceodirective();
        if ($id) {
            $controller->get($id);
        } else {
            $controller->receive();
        }
    }

    // =========================================================================
    // /api/pm/* - PM Assistant context
    // /api/pm/context (workspace from subdomain)
    // =========================================================================

    /**
     * /api/pm/{resource}
     * PM Assistant data endpoints
     */
    public function pm($params = []) {
        $resource = $params['operation']->name ?? 'context';

        if ($resource !== 'context') {
            Flight::jsonError("Unknown PM resource: {$resource}. Use context", 400);
            return;
        }

        if (!$this->requireAuth('api', 'pmcontext')) return;

        $this->pmContextInternal();
    }

    private function pmContextInternal(): void {
        $projectId = $this->getParam('project_uid');

        try {
            $context = $this->buildPmContext($projectId);
            Flight::jsonSuccess($context);
        } catch (Exception $e) {
            $this->logger->error('PM Context error: ' . $e->getMessage());
            Flight::jsonError('Failed to build PM context: ' . $e->getMessage(), 500);
        }
    }

    private function buildPmContext(?string $projectId = null): array {
        $projects = $this->getPmProjects($projectId);
        $epics = $this->getPmEpicsWithStats($projectId);
        $stories = $this->getPmPendingStories($projectId);
        $completed = $this->getPmCompletedStories($projectId);
        $blocked = $this->getPmBlockedItems($projectId);
        $archived = $this->getPmArchivedStories($projectId);
        $summary = $this->buildPmSummary($projects, $epics, $stories, $completed, $blocked, $archived);

        return compact('projects', 'epics', 'stories', 'completed', 'blocked', 'archived', 'summary');
    }

    private function getPmProjects(?string $projectId): array {
        $condition = 'status IN (?, ?) ORDER BY created_at DESC LIMIT 10';
        $params = ['planning', 'in_progress'];

        if ($projectId) {
            $condition = 'status IN (?, ?) AND project_uid = ? ORDER BY created_at DESC LIMIT 10';
            $params[] = $projectId;
        }

        $beans = Bean::find('ctoprojects', $condition, $params);

        return array_values(array_map(fn($p) => [
            'project_uid' => $p->project_uid,
            'name' => $p->name,
            'description' => $p->description,
            'status' => $p->status,
            'created_at' => $p->created_at
        ], $beans));
    }

    private function getPmEpicsWithStats(?string $projectId): array {
        $sql = "SELECT e.id, e.epic_eid, e.title, e.description, e.priority, e.sequence,
                       e.project_uid, p.name as project_name,
                       COUNT(s.id) as total_stories,
                       SUM(CASE WHEN s.status = 'done' THEN 1 ELSE 0 END) as completed_stories,
                       SUM(CASE WHEN s.status = 'pending_review' THEN 1 ELSE 0 END) as pending_stories,
                       SUM(CASE WHEN s.status = 'approved' THEN 1 ELSE 0 END) as approved_stories,
                       SUM(CASE WHEN s.status = 'blocked' THEN 1 ELSE 0 END) as blocked_stories,
                       SUM(COALESCE(s.story_points, 0)) as total_points,
                       SUM(CASE WHEN s.status = 'done' THEN COALESCE(s.story_points, 0) ELSE 0 END) as completed_points
                FROM ctoepics e
                JOIN ctoprojects p ON e.project_uid = p.id
                LEFT JOIN ctostories s ON s.epic_id = e.id
                WHERE p.status IN ('planning', 'in_progress')";

        $params = [];
        if ($projectId) {
            $sql .= " AND p.project_uid = ?";
            $params[] = $projectId;
        }

        $sql .= " GROUP BY e.id ORDER BY e.priority ASC, e.sequence ASC LIMIT 50";

        return Bean::getAll($sql, $params);
    }

    private function getPmPendingStories(?string $projectId): array {
        $beans = Bean::find('ctostories',
            'status IN (?, ?, ?, ?, ?) ORDER BY sequence ASC LIMIT 100',
            ['pending_review', 'approved', 'backlog', 'ready', 'in_progress']
        );

        $result = [];
        foreach ($beans as $s) {
            $epic = $s->ctoepics;
            if (!$epic->id) continue;

            $project = $epic->ctoprojects;
            if (!$project->id || !in_array($project->status, ['planning', 'in_progress'])) continue;
            if ($projectId && $project->project_uid !== $projectId) continue;

            $result[] = [
                'id' => $s->id,
                'story_id' => $s->story_uid,
                'title' => $s->title,
                'description' => $s->description,
                'status' => $s->status,
                'story_points' => $s->story_points,
                'jira_issue_key' => $s->jira_issue_key,
                'epic_title' => $epic->title,
                'epic_id' => $epic->epic_uid,
                'project_name' => $project->name
            ];
        }

        $order = ['in_progress' => 1, 'ready' => 2, 'approved' => 3, 'pending_review' => 4, 'backlog' => 5];
        usort($result, fn($a, $b) => ($order[$a['status']] ?? 99) - ($order[$b['status']] ?? 99));

        return $result;
    }

    private function getPmCompletedStories(?string $projectId): array {
        $jobs = Bean::find('aidevjobs',
            'status IN (?, ?) ORDER BY completed_at ASC LIMIT 50',
            ['pr_created', 'complete']
        );

        $result = [];
        foreach ($jobs as $job) {
            $story = Bean::findOne('ctostories', 'jira_issue_key = ?', [$job->issue_key]);
            if (!$story) continue;

            $epic = $story->ctoepics;
            if (!$epic->id) continue;

            $project = $epic->ctoprojects;
            if (!$project->id || !in_array($project->status, ['planning', 'in_progress'])) continue;
            if ($projectId && $project->project_uid !== $projectId) continue;

            $result[] = [
                'id' => $story->id,
                'story_id' => $story->story_uid,
                'title' => $story->title,
                'status' => $story->status,
                'story_points' => $story->story_points,
                'jira_issue_key' => $story->jira_issue_key,
                'updated_at' => $story->updated_at,
                'epic_title' => $epic->title,
                'epic_id' => $epic->epic_uid,
                'epic_sequence' => $epic->sequence,
                'project_name' => $project->name,
                'job_status' => $job->status,
                'branch_name' => $job->branch_name,
                'pr_url' => $job->pr_url,
                'completed_at' => $job->completed_at
            ];
        }

        return $result;
    }

    private function getPmBlockedItems(?string $projectId): array {
        $beans = Bean::find('ctostories', 'status = ? LIMIT 20', ['blocked']);

        $result = [];
        foreach ($beans as $s) {
            $epic = $s->ctoepics;
            if (!$epic->id) continue;

            $project = $epic->ctoprojects;
            if (!$project->id || !in_array($project->status, ['planning', 'in_progress'])) continue;
            if ($projectId && $project->project_uid !== $projectId) continue;

            $result[] = [
                'id' => $s->id,
                'story_id' => $s->story_uid,
                'title' => $s->title,
                'description' => $s->description,
                'jira_issue_key' => $s->jira_issue_key,
                'epic_title' => $epic->title,
                'project_name' => $project->name
            ];
        }

        return $result;
    }

    private function getPmArchivedStories(?string $projectId): array {
        $jobs = Bean::find('aidevjobs', 'status = ? ORDER BY merged_at DESC LIMIT 100', ['merged']);

        $result = [];
        foreach ($jobs as $job) {
            $story = Bean::findOne('ctostories', 'jira_issue_key = ?', [$job->issue_key]);
            if (!$story) continue;

            $epic = $story->ctoepics;
            if (!$epic->id) continue;

            $project = $epic->ctoprojects;
            if (!$project->id) continue;
            if ($projectId && $project->project_uid !== $projectId) continue;

            $result[] = [
                'id' => $story->id,
                'story_id' => $story->story_uid,
                'title' => $story->title,
                'story_points' => $story->story_points,
                'jira_issue_key' => $story->jira_issue_key,
                'epic_title' => $epic->title,
                'project_name' => $project->name,
                'qa_branch' => $job->qa_branch,
                'merged_at' => $job->merged_at
            ];
        }

        return $result;
    }

    private function buildPmSummary(array $projects, array $epics, array $stories, array $completed, array $blocked, array $archived = []): string {
        $parts = [];
        $parts[] = count($projects) . ' active project(s)';
        $parts[] = count($epics) . ' epic(s)';
        $parts[] = count($stories) . ' pending story(ies)';
        if (count($completed) > 0) {
            $parts[] = count($completed) . ' completed (ready to merge)';
        }
        if (count($blocked) > 0) {
            $parts[] = count($blocked) . ' blocked';
        }
        if (count($archived) > 0) {
            $parts[] = count($archived) . ' archived/merged';
        }
        return implode(', ', $parts);
    }

    // =========================================================================
    // /api/runner/* - Runner API
    // /api/runner/boot, /api/runner/jobs/{id}/manifest, etc.
    // =========================================================================

    /**
     * /api/runner/{action}/{jobId?}/{subaction?}
     * Runner API for remote job execution
     */
    public function runner($params = []) {
        $action = $params['operation']->name ?? 'boot';
        $jobIdOrAction = $params['operation']->type ?? null;

        // For runner API, delegate to Runnerapi controller
        // The Runnerapi controller handles its own auth via X-Job-Token
        $controller = new Runnerapi();

        switch ($action) {
            case 'boot':
                // Boot script - requires tk_* auth
                if (!$this->requireAuth('runnerapi', 'boot')) return;
                $controller->boot();
                break;
            case 'jobs':
                // Job-specific endpoints use X-Job-Token, not tk_*
                // Parse the rest of the URL from route params
                $jobId = $jobIdOrAction;
                // The subaction comes from the route remainder
                $subaction = $params['route'] ?? '';
                $subaction = trim($subaction, '/');
                $subParts = explode('/', $subaction);
                $subaction = $subParts[0] ?? 'manifest';
                $filename = $subParts[1] ?? null;

                switch ($subaction) {
                    case 'manifest':
                        $controller->manifest($jobId);
                        break;
                    case 'runner':
                        $controller->runner($jobId);
                        break;
                    case 'files':
                        $controller->files($jobId, $filename);
                        break;
                    case 'status':
                        $controller->status($jobId);
                        break;
                    case 'log':
                        $controller->log($jobId);
                        break;
                    case 'complete':
                        $controller->complete($jobId);
                        break;
                    default:
                        Flight::jsonError("Unknown runner jobs subaction: {$subaction}", 400);
                }
                break;
            default:
                Flight::jsonError("Unknown runner action: {$action}. Use boot or jobs/{id}/{action}", 400);
        }
    }

    // =========================================================================
    // /api/pipelines/{slug}/run - Pipeline execution
    // /api/pipelinestatus/{runId} - Pipeline status
    // =========================================================================

    /**
     * /api/pipelines/{slug}/run
     * Execute a pipeline via API
     */
    public function pipelines($params = []) {
        $slug = $params['operation']->name ?? null;
        $action = $params['operation']->type ?? 'run';

        if ($action !== 'run') {
            Flight::jsonError("Unknown pipelines action: {$action}. Use /api/pipelines/{slug}/run", 400);
            return;
        }

        if (empty($slug)) {
            Flight::jsonError('Pipeline slug required: /api/pipelines/{slug}/run', 400);
            return;
        }

        if (!$this->requireAuth('pipelines', 'run')) return;

        $pipeline = Bean::findOne('pipelines', 'slug = ? AND is_active = 1', [$slug]);
        if (!$pipeline) {
            Flight::jsonError("Pipeline not found: {$slug}", 404);
            return;
        }

        $rawBody = file_get_contents('php://input');
        $inputData = json_decode($rawBody, true) ?: [];

        $run = Bean::dispense('pipelineruns');
        $run->run_uid = 'run-' . bin2hex(random_bytes(8));
        $run->pipelines_id = $pipeline->id;
        $run->member_id = $this->member->id;
        $run->trigger_source = 'api';
        $run->trigger_data_json = json_encode([
            'api_key_id' => $this->apiKey->id,
            'source_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
        $run->status = 'pending';
        $run->context_json = json_encode($inputData);
        $run->created_at = date('Y-m-d H:i:s');
        Bean::store($run);

        $this->logger->info('Pipeline run created via API', [
            'run_id' => $run->id,
            'run_uid' => $run->run_uid,
            'pipeline' => $slug,
            'workspace' => $this->workspaceSlug,
            'member_id' => $this->member->id
        ]);

        $sync = $this->getParam('sync') === 'true' || $this->getParam('sync') === '1';

        $result = \app\services\PipelineDispatcher::dispatch(
            $run->id,
            $this->workspaceSlug,
            $sync
        );

        if ($sync) {
            if ($result['success']) {
                $run = Bean::load('pipelineruns', $run->id);
                $output = json_decode($run->output_json ?: '{}', true);

                Flight::jsonSuccess([
                    'run_id' => (int) $run->id,
                    'run_uid' => $run->run_uid,
                    'status' => $run->status,
                    'output' => $output,
                    'completed_at' => $run->completed_at
                ]);
            } else {
                $this->logger->error('Pipeline sync execution failed', [
                    'run_id' => $run->id,
                    'error' => $result['error'] ?? 'Unknown',
                    'method' => $result['method'] ?? 'unknown'
                ]);
                Flight::jsonError('Pipeline execution failed: ' . ($result['error'] ?? 'Unknown'), 500);
            }
        } else {
            Flight::jsonSuccess([
                'run_id' => (int) $run->id,
                'run_uid' => $run->run_uid,
                'status' => 'pending',
                'message' => 'Pipeline execution started'
            ]);
        }
    }

    /**
     * /api/pipelinestatus/{runId}
     * Get pipeline run status
     */
    public function pipelinestatus($params = []) {
        $runId = $params['operation']->name ?? $this->getParam('run_id') ?? null;

        if (empty($runId)) {
            Flight::jsonError('Run ID required: /api/pipelinestatus/{runId}', 400);
            return;
        }

        if (!$this->requireAuth('pipelines', 'status')) return;

        $run = Bean::load('pipelineruns', (int) $runId);
        if (!$run || !$run->id) {
            Flight::jsonError('Run not found', 404);
            return;
        }

        // Only allow access to own runs (or admin)
        if ($run->member_id !== $this->member->id && ($this->member->level ?? 100) > LEVELS['ADMIN']) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        $output = json_decode($run->output_json ?: '{}', true);

        Flight::jsonSuccess([
            'run_id' => (int) $run->id,
            'run_uid' => $run->run_uid,
            'status' => $run->status,
            'output' => $output,
            'error_message' => $run->error_message,
            'created_at' => $run->created_at,
            'started_at' => $run->started_at,
            'completed_at' => $run->completed_at
        ]);
    }
}
