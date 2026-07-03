<?php
/**
 * Workstations Controller
 *
 * Manages workstations (Claude Code execution runners) and SSH keys.
 * Extracted from Admin controller to provide standalone access at /workstations.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \Exception as Exception;
use app\BaseControls\Control;

class Workstations extends Control {

    const MEMBER_LEVEL = 100;

    public function __construct() {
        parent::__construct();

        // Check if user is logged in
        if (!Flight::isLoggedIn()) {
            Flight::redirect('/auth/login?redirect=' . urlencode(Flight::request()->url));
            exit;
        }
    }

    /**
     * List all workstations
     */
    public function index($params = []) {
        require_once __DIR__ . '/../services/RunnerService.php';

        $this->viewData['title'] = 'Workstations & SSH Keys';
        $this->viewData['runners'] = \app\services\RunnerService::enrichRunners(
            \app\services\RunnerService::getAllRunners(false)
        );

        $this->render('workstations/index', $this->viewData);
    }

    /**
     * Create a new workstation
     */
    public function create($params = []) {
        require_once __DIR__ . '/../services/RunnerService.php';
        require_once __DIR__ . '/../services/SSHKeyService.php';

        $request = Flight::request();

        if ($request->method === 'POST') {
            if (!Flight::csrf()->validateRequest()) {
                $this->flash('error', 'Invalid CSRF token');
                Flight::redirect('/workstations/create');
                return;
            }

            // Get execution mode
            $executionMode = $request->data->execution_mode ?? 'ssh_tmux';

            $data = [
                'name' => $request->data->name ?? '',
                'description' => $request->data->description ?? '',
                'host' => $request->data->host ?? '',
                'port' => (int)($request->data->port ?? 3500),
                'api_key' => $request->data->api_key ?? '',
                'runner_type' => $request->data->runner_type ?? 'general',
                'capabilities' => is_array($request->data->capabilities) ? $request->data->capabilities : [],
                'max_concurrent_jobs' => (int)($request->data->max_concurrent_jobs ?? 2),
                'is_active' => isset($request->data->is_active) ? 1 : 0,
                'is_default' => isset($request->data->is_default) ? 1 : 0,
                // SSH fields
                'execution_mode' => $executionMode,
                'ssh_user' => trim($request->data->ssh_user ?? 'claudeuser'),
                'ssh_port' => (int)($request->data->ssh_port ?? 22),
                'sshkey_id' => ($request->data->sshkey_id ?? '') ?: null,
                'ssh_validated' => 0
            ];

            // Validation depends on execution mode
            if ($executionMode === 'ssh_tmux') {
                if (empty($data['name']) || empty($data['host']) || empty($data['ssh_user'])) {
                    $this->flash('error', 'Name, host, and SSH user are required for SSH mode');
                    Flight::redirect('/workstations/create');
                    return;
                }
            } else {
                if (empty($data['name']) || empty($data['host']) || empty($data['api_key'])) {
                    $this->flash('error', 'Name, host, and API key are required for HTTP mode');
                    Flight::redirect('/workstations/create');
                    return;
                }
            }

            try {
                $runnerId = \app\services\RunnerService::createRunner($data);
                $this->logger->info('Workstation created', ['runner_id' => $runnerId, 'name' => $data['name']]);
                $this->flash('success', 'Workstation created successfully');
                Flight::redirect('/workstations');
            } catch (\Exception $e) {
                $this->flash('error', 'Failed to create workstation: ' . $e->getMessage());
                Flight::redirect('/workstations/create');
            }
            return;
        }

        $this->viewData['title'] = 'Create Workstation';
        $this->viewData['sshKeys'] = \app\services\SSHKeyService::getKeys();
        $this->render('workstations/form', $this->viewData);
    }

    /**
     * Edit a workstation
     */
    public function edit($params = []) {
        require_once __DIR__ . '/../services/RunnerService.php';
        require_once __DIR__ . '/../services/SSHKeyService.php';

        $runnerId = (int)($this->opId() ?? 0);
        if (!$runnerId) {
            Flight::redirect('/workstations');
            return;
        }

        $runner = \app\services\RunnerService::getRunner($runnerId);
        if (!$runner) {
            $this->flash('error', 'Workstation not found');
            Flight::redirect('/workstations');
            return;
        }

        $request = Flight::request();

        if ($request->method === 'POST') {
            if (!Flight::csrf()->validateRequest()) {
                $this->flash('error', 'Invalid CSRF token');
                Flight::redirect('/workstations/edit/' . $runnerId);
                return;
            }

            // Get execution mode
            $executionMode = $request->data->execution_mode ?? ($runner['execution_mode'] ?? 'ssh_tmux');

            $data = [
                'name' => $request->data->name ?? '',
                'description' => $request->data->description ?? '',
                'host' => $request->data->host ?? '',
                'port' => (int)($request->data->port ?? 3500),
                'runner_type' => $request->data->runner_type ?? 'general',
                'capabilities' => is_array($request->data->capabilities) ? $request->data->capabilities : [],
                'max_concurrent_jobs' => (int)($request->data->max_concurrent_jobs ?? 2),
                'is_active' => isset($request->data->is_active) ? 1 : 0,
                'is_default' => isset($request->data->is_default) ? 1 : 0,
                // SSH fields
                'execution_mode' => $executionMode,
                'ssh_user' => trim($request->data->ssh_user ?? 'claudeuser'),
                'ssh_port' => (int)($request->data->ssh_port ?? 22),
                'sshkey_id' => ($request->data->sshkey_id ?? '') ?: null
            ];

            // Only update API key if provided
            if (!empty($request->data->api_key)) {
                $data['api_key'] = $request->data->api_key;
            }

            // Reset validation if SSH settings changed
            $sshChanged = ($data['host'] !== $runner['host'] ||
                          $data['ssh_user'] !== ($runner['ssh_user'] ?? 'claudeuser') ||
                          $data['ssh_port'] !== ($runner['ssh_port'] ?? 22));
            if ($sshChanged) {
                $data['ssh_validated'] = 0;
            }

            try {
                $this->logger->info('Updating workstation', ['runner_id' => $runnerId, 'data' => $data]);
                \app\services\RunnerService::updateRunner($runnerId, $data);
                $this->logger->info('Workstation updated', ['runner_id' => $runnerId]);
                $this->flash('success', 'Workstation updated successfully');
                Flight::redirect('/workstations');
            } catch (\Exception $e) {
                $this->flash('error', 'Failed to update workstation: ' . $e->getMessage());
                Flight::redirect('/workstations/edit/' . $runnerId);
            }
            return;
        }

        $runner['capabilities'] = json_decode($runner['capabilities'] ?? '[]', true);
        $this->viewData['title'] = 'Edit Workstation';
        $this->viewData['runner'] = $runner;
        $this->viewData['sshKeys'] = \app\services\SSHKeyService::getKeys();
        $this->render('workstations/form', $this->viewData);
    }

    /**
     * Delete a workstation
     */
    public function delete($params = []) {
        require_once __DIR__ . '/../services/RunnerService.php';

        $runnerId = (int)($this->opId() ?? 0);
        if (!$runnerId) {
            Flight::redirect('/workstations');
            return;
        }

        try {
            \app\services\RunnerService::deleteRunner($runnerId);
            $this->logger->info('Workstation deleted', ['runner_id' => $runnerId]);
            $this->flash('success', 'Workstation deleted');
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to delete workstation: ' . $e->getMessage());
        }

        Flight::redirect('/workstations');
    }

    /**
     * Test workstation connectivity (routes to HTTP or SSH based on execution_mode)
     */
    public function test($params = []) {
        require_once __DIR__ . '/../services/RunnerService.php';
        require_once __DIR__ . '/../services/RunnerDiagnosticService.php';

        $runnerId = (int)($this->opId() ?? 0);
        if (!$runnerId) {
            $this->json(['success' => false, 'error' => 'Workstation ID required']);
            return;
        }

        $runner = \app\services\RunnerService::getRunner($runnerId);
        if (!$runner) {
            $this->json(['success' => false, 'error' => 'Workstation not found']);
            return;
        }

        // Check if POST data provided - use form values instead of saved values
        $request = Flight::request();
        if ($request->method === 'POST') {
            // Override runner values with form data for testing unsaved changes
            if (!empty($request->data->host)) {
                $runner['host'] = $request->data->host;
            }
            if (!empty($request->data->ssh_user)) {
                $runner['ssh_user'] = $request->data->ssh_user;
            }
            if (!empty($request->data->ssh_port)) {
                $runner['ssh_port'] = (int)$request->data->ssh_port;
            }
            if (isset($request->data->sshkey_id)) {
                $runner['sshkey_id'] = $request->data->sshkey_id ?: null;
            }
            if (!empty($request->data->execution_mode)) {
                $runner['execution_mode'] = $request->data->execution_mode;
            }
        }

        // Route based on execution mode
        $executionMode = $runner['execution_mode'] ?? 'http_api';

        if ($executionMode === 'ssh_tmux') {
            // Use SSH diagnostic for quick check
            $diagnostic = new \app\services\RunnerDiagnosticService($runner);
            $result = $diagnostic->quickCheck();

            // Only update health status in database if testing saved values (GET request)
            if ($request->method !== 'POST') {
                $healthStatus = $result['connected'] ? 'healthy' : 'unhealthy';
                $runnerBean = Bean::load('runners', $runnerId);
                $runnerBean->health_status = $healthStatus;
                $runnerBean->last_health_check = date('Y-m-d H:i:s');
                Bean::store($runnerBean);
            } else {
                $healthStatus = $result['connected'] ? 'healthy' : 'unhealthy';
            }

            $this->json([
                'success' => $result['connected'],
                'data' => [
                    'execution_mode' => 'ssh_tmux',
                    'ssh_user' => $runner['ssh_user'] ?? 'claudeuser',
                    'host' => $runner['host'],
                    'time_ms' => $result['time_ms'],
                    'health_status' => $healthStatus
                ],
                'error' => $result['error'] ?? null
            ]);
        } else {
            // Use HTTP health check
            $result = \app\services\RunnerService::healthCheck($runnerId);

            // Include runner info from DB alongside remote health data
            $this->json([
                'success' => $result['healthy'],
                'data' => [
                    'execution_mode' => 'http_api',
                    'host' => $runner['host'],
                    'port' => $runner['port'],
                    'runner_type' => $runner['runner_type'] ?? 'general',
                    'max_concurrent_jobs' => $runner['max_concurrent_jobs'] ?? 2,
                    'capabilities' => json_decode($runner['capabilities'] ?? '[]', true),
                    'remote_health' => $result['data'] ?? null
                ],
                'error' => $result['error'] ?? null
            ]);
        }
    }

    /**
     * Run full SSH diagnostic on a workstation
     */
    public function diagnose($params = []) {
        require_once __DIR__ . '/../services/RunnerService.php';
        require_once __DIR__ . '/../services/RunnerDiagnosticService.php';

        $runnerId = (int)($this->opId() ?? 0);
        if (!$runnerId) {
            $this->json(['success' => false, 'error' => 'Workstation ID required']);
            return;
        }

        $runner = \app\services\RunnerService::getRunner($runnerId);
        if (!$runner) {
            $this->json(['success' => false, 'error' => 'Workstation not found']);
            return;
        }

        // Check if POST data provided - use form values instead of saved values
        $request = Flight::request();
        $testingUnsaved = false;
        if ($request->method === 'POST') {
            $testingUnsaved = true;
            // Override runner values with form data for testing unsaved changes
            if (!empty($request->data->host)) {
                $runner['host'] = $request->data->host;
            }
            if (!empty($request->data->ssh_user)) {
                $runner['ssh_user'] = $request->data->ssh_user;
            }
            if (!empty($request->data->ssh_port)) {
                $runner['ssh_port'] = (int)$request->data->ssh_port;
            }
            if (isset($request->data->sshkey_id)) {
                $runner['sshkey_id'] = $request->data->sshkey_id ?: null;
            }
            if (!empty($request->data->execution_mode)) {
                $runner['execution_mode'] = $request->data->execution_mode;
            }
        }

        $diagnostic = new \app\services\RunnerDiagnosticService($runner);
        $result = $diagnostic->runDiagnostic();

        // Only save diagnostic result to database if testing saved values (not POST)
        if (!$testingUnsaved) {
            $healthStatus = $result['ready'] ? 'healthy' : 'unhealthy';
            $runnerBean = Bean::load('runners', $runnerId);
            $runnerBean->ssh_validated = $result['ready'] ? 1 : 0;
            $runnerBean->health_status = $healthStatus;
            $runnerBean->last_health_check = date('Y-m-d H:i:s');
            Bean::store($runnerBean);
        }

        // If ready, also get install commands for anything missing
        if (!$result['ready']) {
            $result['install_commands'] = $diagnostic->getInstallCommands();
        }

        $this->json($result);
    }

    /**
     * Run a "Hello World" test on a workstation using Claude CLI
     */
    public function helloworld($params = []) {
        require_once __DIR__ . '/../services/RunnerService.php';
        require_once __DIR__ . '/../services/RunnerDiagnosticService.php';

        $runnerId = (int)($this->opId() ?? 0);
        if (!$runnerId) {
            $this->json(['success' => false, 'error' => 'Workstation ID required']);
            return;
        }

        $runner = \app\services\RunnerService::getRunner($runnerId);
        if (!$runner) {
            $this->json(['success' => false, 'error' => 'Workstation not found']);
            return;
        }

        // Check if POST data provided - use form values instead of saved values
        $request = Flight::request();
        if ($request->method === 'POST') {
            if (!empty($request->data->host)) {
                $runner['host'] = $request->data->host;
            }
            if (!empty($request->data->ssh_user)) {
                $runner['ssh_user'] = $request->data->ssh_user;
            }
            if (!empty($request->data->ssh_port)) {
                $runner['ssh_port'] = (int)$request->data->ssh_port;
            }
            if (isset($request->data->sshkey_id)) {
                $runner['sshkey_id'] = $request->data->sshkey_id ?: null;
            }
        }

        $diagnostic = new \app\services\RunnerDiagnosticService($runner);
        $result = $diagnostic->runHelloWorld();

        $this->json($result);
    }

    /**
     * Test a workstation with a specific agent configuration (via job-executor)
     */
    public function testwithagent($params = []) {
        require_once __DIR__ . '/../services/JobExecutorConfig.php';

        $runnerId = (int)($this->opId() ?? 0);
        $agentId = (int)($this->getParam('agent_id') ?? 0);

        if (!$runnerId || !$agentId) {
            $this->json(['success' => false, 'error' => 'Workstation ID and Agent ID are required']);
            return;
        }

        $memberId = $this->member->id ?? 1;
        $workspaceSlug = $_SESSION['workspace_slug'] ?? 'default';

        $result = \app\services\JobExecutorConfig::submitTestJob($agentId, $runnerId, $memberId, $workspaceSlug);

        $this->json($result);
    }

    /**
     * Get test status (for workstation tests)
     */
    public function teststatus($params = []) {
        $testId = $this->opId() ?? $this->getParam('test_id') ?? '';
        $sendExit = $this->getParam('send_exit') === '1' || $this->getParam('send_exit') === 'true';

        if (empty($testId)) {
            $this->json(['success' => false, 'error' => 'Test ID is required']);
            return;
        }

        // First check database for job-executor jobs (job_uid contains test_id)
        $job = Bean::findOne('aidevjobs', 'job_uid LIKE ?', ['%' . $testId]);

        if ($job) {
            $status = $job->status ?? 'pending';
            $phase = $job->phase ?? '';

            // Calculate duration if we have timing info
            $durationMs = null;
            if ($job->started_at && ($job->completed_at || $job->updated_at)) {
                $startTime = strtotime($job->started_at);
                $endTime = strtotime($job->completed_at ?? $job->updated_at);
                if ($startTime && $endTime) {
                    $durationMs = ($endTime - $startTime) * 1000;
                }
            }

            // Load workstation info if available
            $workstationInfo = null;
            if ($job->runners_id) {
                $runner = Bean::load('runners', $job->runners_id);
                if ($runner && $runner->id) {
                    $workstationInfo = [
                        'name' => $runner->name,
                        'host' => $runner->host,
                        'ssh_user' => $runner->ssh_user ?? 'claudeuser',
                    ];
                }
            }

            // Determine work directory path
            $sshUser = $workstationInfo['ssh_user'] ?? 'claudeuser';
            $workDir = "/home/{$sshUser}/jobs/" . $job->job_uid;

            $response = [
                'success' => true,
                'status' => $status,
                'phase' => $phase,
                'job_uid' => $job->job_uid,
                'duration_ms' => $durationMs,
                'work_dir' => $workDir,
                'started_at' => $job->started_at,
                'completed_at' => $job->completed_at,
                'workstation' => $workstationInfo,
            ];

            // Map status to old format for UI compatibility
            if ($status === 'completed') {
                $response['success'] = true;
                $response['status'] = 'completed';
                $response['message'] = $job->summary ?? 'Test completed successfully';
            } elseif ($status === 'failed') {
                $response['success'] = false;
                $response['status'] = 'failed';
                $response['error'] = $job->error_message ?? 'Test failed';
            } elseif (in_array($status, ['running', 'validated'])) {
                $response['status'] = 'running';
                $response['phase'] = $phase;
            }

            // Send /exit to tmux session if requested
            if ($sendExit && $job->job_uid) {
                $exitResult = \app\services\JobExecutorConfig::sendExit($job->job_uid);
                $response['exit_sent'] = $exitResult['success'] ?? false;
                $response['exit_message'] = $exitResult['message'] ?? $exitResult['error'] ?? null;
            }

            $this->json($response);
            return;
        }

        // Fall back to status file (legacy method)
        $statusFile = "/tmp/agent-test-{$testId}.json";

        if (!file_exists($statusFile)) {
            $this->json(['success' => false, 'error' => 'Test not found']);
            return;
        }

        $status = json_decode(file_get_contents($statusFile), true);

        if (!$status) {
            $this->json(['success' => false, 'error' => 'Failed to read test status']);
            return;
        }

        $this->json(array_merge(['success' => true], $status));
    }

    /**
     * Get available agents for workstation testing dropdown
     */
    public function testagents($params = []) {
        require_once __DIR__ . '/../services/AgentTestService.php';
        $agents = \app\services\AgentTestService::getActiveAgents();

        $this->json([
            'success' => true,
            'agents' => $agents
        ]);
    }

    /**
     * Test job-executor ping/pong flow
     */
    public function testjobexecutor($params = []) {
        require_once __DIR__ . '/../services/RunnerService.php';

        $runnerId = (int)($this->opId() ?? 0);
        if (!$runnerId) {
            $this->json(['success' => false, 'error' => 'Workstation ID required']);
            return;
        }

        $runner = \app\services\RunnerService::getRunner($runnerId);
        if (!$runner) {
            $this->json(['success' => false, 'error' => 'Workstation not found']);
            return;
        }

        // Get job-executor URL from POST or use config default
        $jobExecutorUrl = $this->getParam('job_executor_url') ?: \app\services\JobExecutorConfig::getUrl();

        // Get workspace slug
        $workspaceSlug = $_SESSION['workspace_slug'] ?? 'default';

        $startTime = microtime(true);

        try {
            // Step 1: Create a test job in aidevjobs
            $jobUid = 'test-' . $workspaceSlug . '-' . bin2hex(random_bytes(8));

            // Find a repo that has this runner's agent assigned (or any repo for testing)
            $repo = null;
            $agent = Bean::findOne('aiagents', 'runners_id = ? AND is_active = 1', [$runnerId]);
            if ($agent) {
                $repo = Bean::findOne('repoconnections', 'aiagents_id = ?', [$agent->id]);
            }

            // Create test job
            $job = Bean::dispense('aidevjobs');
            $job->job_uid = $jobUid;
            $job->member_id = $this->member->id ?? 1;
            $job->boards_id = null;
            $job->repoconnections_id = $repo ? $repo->id : null;
            $job->runners_id = $runnerId; // Direct runner assignment for testing
            $job->issue_key = 'TEST-JE-001';
            $job->status = 'pending';
            $job->phase = 'test';
            $job->prompt = 'This is a test job for job-executor ping/pong validation. Please respond with "Hello from job-executor test!"';
            $job->created_at = date('Y-m-d H:i:s');
            Bean::store($job);

            Flight::get('log')->info('Created test job for job-executor', [
                'job_uid' => $jobUid,
                'runner_id' => $runnerId,
                'workspace' => $workspaceSlug,
            ]);

            // Step 2: Submit to job-executor (PING)
            $submitUrl = rtrim($jobExecutorUrl, '/') . '/api/jobs/submit';
            // Build callback URL with workspace subdomain from site config
            $callbackUrl = \app\services\SiteConfig::getWorkspaceUrl($workspaceSlug) . '/api/jobexecutor';
            $verifySsl = \app\services\JobExecutorConfig::shouldVerifySsl();

            $ch = curl_init($submitUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'workspace' => $workspaceSlug,
                    'job_uid' => $jobUid,
                    'callback_url' => $callbackUrl,
                ]),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => $verifySsl,
                CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $duration = round((microtime(true) - $startTime) * 1000);

            // Check result
            if ($curlError) {
                // Cleanup test job
                Bean::trash($job);

                $this->json([
                    'success' => false,
                    'error' => "Connection failed: {$curlError}",
                    'duration_ms' => $duration,
                    'job_executor_url' => $jobExecutorUrl,
                ]);
                return;
            }

            if ($httpCode !== 200) {
                // Cleanup test job
                Bean::trash($job);

                $this->json([
                    'success' => false,
                    'error' => "Job-executor returned HTTP {$httpCode}",
                    'response' => $response,
                    'duration_ms' => $duration,
                    'job_executor_url' => $jobExecutorUrl,
                ]);
                return;
            }

            $result = json_decode($response, true);

            if (!$result || !($result['success'] ?? false)) {
                // Cleanup test job
                Bean::trash($job);

                $this->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Job-executor rejected submission',
                    'response' => $result,
                    'duration_ms' => $duration,
                ]);
                return;
            }

            // Step 3: Verify job was validated (check status in DB)
            $job = Bean::load('aidevjobs', $job->id);
            $wasValidated = ($job->status === 'validated');

            // Cleanup test job
            Bean::trash($job);

            $this->json([
                'success' => true,
                'message' => 'Job-executor ping/pong test passed!',
                'details' => [
                    'ping' => 'Job submitted to job-executor',
                    'pong' => $wasValidated ? 'Job-executor validated with MyCTOBot' : 'Validation pending',
                    'job_uid' => $jobUid,
                    'workstation' => $result['data']['workstation'] ?? $runner['name'],
                ],
                'duration_ms' => $duration,
                'job_executor_url' => $jobExecutorUrl,
            ]);

        } catch (\Exception $e) {
            Flight::get('log')->error('Job-executor test failed', ['error' => $e->getMessage()]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000),
            ]);
        }
    }

    /**
     * Health check all workstations
     */
    public function health($params = []) {
        require_once __DIR__ . '/../services/RunnerService.php';

        $results = \app\services\RunnerService::healthCheckAll();

        $this->json([
            'success' => true,
            'results' => $results
        ]);
    }

    // ========================================
    // SSH Key Management
    // ========================================

    /**
     * List SSH keys (AJAX)
     */
    public function sshkeys($params = []) {
        require_once __DIR__ . '/../services/SSHKeyService.php';

        $keys = \app\services\SSHKeyService::getKeys();

        $this->json([
            'success' => true,
            'keys' => $keys,
            'key_types' => \app\services\SSHKeyService::getSupportedTypes()
        ]);
    }

    /**
     * Generate a new SSH key
     */
    public function generatesshkey($params = []) {
        require_once __DIR__ . '/../services/SSHKeyService.php';

        $request = Flight::request();

        if ($request->method !== 'POST') {
            $this->json(['success' => false, 'error' => 'POST required']);
            return;
        }

        if (!Flight::csrf()->validateJson()) {
            $this->json(['success' => false, 'error' => 'Invalid CSRF token']);
            return;
        }

        // validateJson() populates $_POST with JSON data
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $keyType = $_POST['key_type'] ?? 'ecdsa';
        $comment = trim($_POST['comment'] ?? '');

        if (empty($name)) {
            $this->json(['success' => false, 'error' => 'Name is required']);
            return;
        }

        try {
            $key = \app\services\SSHKeyService::createKey(
                $this->member->id,
                $name,
                $keyType,
                $description,
                $comment
            );

            $this->logger->info('SSH key generated', [
                'member_id' => $this->member->id,
                'key_id' => $key['id'],
                'key_type' => $keyType,
                'fingerprint' => $key['fingerprint']
            ]);

            $this->json([
                'success' => true,
                'key' => $key,
                'message' => 'SSH key generated successfully. Save the private key now - it cannot be retrieved later.'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('SSH key generation failed', [
                'error' => $e->getMessage()
            ]);
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Get SSH key details (with private key for download)
     */
    public function getsshkey($params = []) {
        require_once __DIR__ . '/../services/SSHKeyService.php';

        $keyId = (int)($this->opId() ?? 0);
        if (!$keyId) {
            $this->json(['success' => false, 'error' => 'Key ID required']);
            return;
        }

        // SECURITY (HIGH-3): enforce ownership. Previously any member could read
        // any key by id (IDOR) and download its decrypted private key.
        $keyBean = \app\Bean::load('sshkeys', $keyId);
        if (!$this->authorizeOwnership($keyBean)) {
            $this->json(['success' => false, 'error' => 'Key not found']);
            return;
        }

        // Never re-expose the private key over the API — it is shown only once at
        // creation ("cannot be retrieved later"). Return metadata + public key.
        $key = \app\services\SSHKeyService::getKey($keyId, false);

        if (!$key) {
            $this->json(['success' => false, 'error' => 'Key not found']);
            return;
        }

        $this->json([
            'success' => true,
            'key' => $key
        ]);
    }

    /**
     * Delete an SSH key
     */
    public function deletesshkey($params = []) {
        require_once __DIR__ . '/../services/SSHKeyService.php';

        $keyId = (int)($this->opId() ?? 0);
        if (!$keyId) {
            $this->json(['success' => false, 'error' => 'Key ID required']);
            return;
        }

        // SECURITY (HIGH-3): enforce ownership before delete (IDOR).
        $keyBean = \app\Bean::load('sshkeys', $keyId);
        if (!$this->authorizeOwnership($keyBean)) {
            $this->json(['success' => false, 'error' => 'Key not found']);
            return;
        }

        // Get key info for logging
        $key = \app\services\SSHKeyService::getKey($keyId);
        if (!$key) {
            $this->json(['success' => false, 'error' => 'Key not found']);
            return;
        }

        $deleted = \app\services\SSHKeyService::deleteKey($keyId);

        $this->logger->info('SSH key deleted', [
            'member_id' => $this->member->id,
            'key_id' => $keyId,
            'key_name' => $key['name'],
            'fingerprint' => $key['fingerprint']
        ]);

        $this->json([
            'success' => $deleted,
            'message' => $deleted ? 'Key deleted successfully' : 'Failed to delete key'
        ]);
    }
}
