<?php
/**
 * Agent Test Service
 *
 * Runs agent tests by combining agent configuration (WHAT runs)
 * with workstation configuration (WHERE/HOW to connect).
 *
 * The agent defines: provider, model, API keys, Ollama settings
 * The workstation defines: SSH connection, host, user, port, key
 */

namespace app\services;

require_once __DIR__ . '/SSHKeyService.php';
require_once __DIR__ . '/../lib/Bean.php';

use \app\Bean;

class AgentTestService {

    private array $agent;
    private array $workstation;
    private ?string $tempKeyFile = null;

    /**
     * Create test service for agent + workstation combination
     */
    public function __construct(array $agent, array $workstation) {
        $this->agent = $agent;
        $this->workstation = $workstation;
    }

    /**
     * Cleanup temp key file on destruction
     */
    public function __destruct() {
        $this->cleanupTempKey();
    }

    /**
     * Prepare SSH key from database for use
     */
    private function prepareSSHKey(): ?string {
        $sshKeyId = $this->workstation['sshkey_id'] ?? null;

        if (!$sshKeyId) {
            return null;
        }

        $key = SSHKeyService::getKey((int)$sshKeyId, true);
        if (!$key || empty($key['private_key'])) {
            return null;
        }

        $tempDir = sys_get_temp_dir();
        $this->tempKeyFile = $tempDir . '/agent_test_' . uniqid() . '_' . time();

        file_put_contents($this->tempKeyFile, $key['private_key']);
        chmod($this->tempKeyFile, 0600);
        SSHKeyService::markUsed((int)$sshKeyId);

        return $this->tempKeyFile;
    }

    /**
     * Clean up temp key file
     */
    private function cleanupTempKey(): void {
        if ($this->tempKeyFile && file_exists($this->tempKeyFile)) {
            $size = filesize($this->tempKeyFile);
            file_put_contents($this->tempKeyFile, str_repeat("\0", $size));
            unlink($this->tempKeyFile);
            $this->tempKeyFile = null;
        }
    }

    /**
     * Build SSH command prefix for the workstation
     */
    private function sshPrefix(): string {
        $user = $this->workstation['ssh_user'] ?? 'claudeuser';
        $host = $this->workstation['host'];
        $port = $this->workstation['ssh_port'] ?? 22;

        $cmd = "ssh -o ConnectTimeout=15 -o StrictHostKeyChecking=accept-new -o BatchMode=yes";

        if ($port !== 22) {
            $cmd .= " -p {$port}";
        }

        $keyPath = $this->prepareSSHKey();
        if ($keyPath && file_exists($keyPath)) {
            $cmd .= " -i " . escapeshellarg($keyPath);
        }

        $cmd .= " " . escapeshellarg("{$user}@{$host}");

        return $cmd;
    }

    /**
     * Execute command via SSH and return result
     */
    private function sshExec(string $remoteCmd, int $timeout = 60): array {
        $start = microtime(true);
        $sshCmd = $this->sshPrefix() . " " . escapeshellarg($remoteCmd) . " 2>&1";

        exec($sshCmd, $output, $exitCode);

        return [
            'exit_code' => $exitCode,
            'output' => implode("\n", $output),
            'time_ms' => round((microtime(true) - $start) * 1000)
        ];
    }

    /**
     * Build environment variables based on agent config
     */
    private function buildEnvVars(): string {
        $provider = $this->agent['provider'] ?? 'claude_cli';
        $providerConfig = $this->agent['provider_config'] ?? [];
        $envVars = [];

        // Check if using Ollama as backend (via LiteLLM proxy or direct)
        if (!empty($providerConfig['use_ollama'])) {
            // Claude CLI with Ollama backend
            $ollamaHost = $providerConfig['ollama_host'] ?? 'http://localhost:11434';
            $apiKey = $providerConfig['ollama_api_key'] ?? 'sk-litellm-proxy-key';
            $envVars[] = "export ANTHROPIC_BASE_URL=\"{$ollamaHost}\"";
            $envVars[] = "export ANTHROPIC_API_KEY=\"{$apiKey}\"";
        } elseif ($provider === 'ollama') {
            // Standalone Ollama provider
            $ollamaHost = $providerConfig['base_url'] ?? 'http://localhost:11434';
            $apiKey = $providerConfig['api_key'] ?? 'sk-litellm-proxy-key';
            $envVars[] = "export ANTHROPIC_BASE_URL=\"{$ollamaHost}\"";
            $envVars[] = "export ANTHROPIC_API_KEY=\"{$apiKey}\"";
        }

        // Add any other env vars needed
        $envVars[] = "export GIT_TERMINAL_PROMPT=0";

        return implode("; ", $envVars);
    }

    /**
     * Get model flag for Claude CLI
     */
    private function getModelFlag(): string {
        $provider = $this->agent['provider'] ?? 'claude_cli';
        $providerConfig = $this->agent['provider_config'] ?? [];

        if (!empty($providerConfig['use_ollama'])) {
            // Claude CLI with Ollama backend
            $model = $providerConfig['ollama_model'] ?? 'qwen3-coder';
            return "--model " . escapeshellarg($model);
        }

        if ($provider === 'ollama') {
            // Standalone Ollama provider
            $model = $providerConfig['model'] ?? 'llama3';
            return "--model " . escapeshellarg($model);
        }

        // Anthropic model
        $model = $providerConfig['model'] ?? 'sonnet';
        return "--model " . escapeshellarg($model);
    }

    /**
     * Get agent info for display
     */
    public function getAgentInfo(): array {
        $provider = $this->agent['provider'] ?? 'claude_cli';
        $providerConfig = $this->agent['provider_config'] ?? [];

        // Determine if using Ollama (either via claude_cli + use_ollama, or standalone ollama provider)
        $useOllama = !empty($providerConfig['use_ollama']) || $provider === 'ollama';

        // Get Ollama settings based on provider type
        if ($provider === 'ollama') {
            $ollamaHost = $providerConfig['base_url'] ?? 'http://localhost:11434';
            $ollamaModel = $providerConfig['model'] ?? '';
        } else {
            $ollamaHost = $providerConfig['ollama_host'] ?? 'http://localhost:11434';
            $ollamaModel = $providerConfig['ollama_model'] ?? '';
        }

        return [
            'name' => $this->agent['name'] ?? 'Unknown',
            'provider' => $provider,
            'use_ollama' => $useOllama,
            'ollama_host' => $ollamaHost,
            'ollama_model' => $ollamaModel,
            'anthropic_model' => $providerConfig['model'] ?? 'sonnet',
        ];
    }

    /**
     * Get workstation info for display
     */
    public function getWorkstationInfo(): array {
        return [
            'name' => $this->workstation['name'] ?? 'Unknown',
            'host' => $this->workstation['host'] ?? '',
            'ssh_user' => $this->workstation['ssh_user'] ?? 'claudeuser',
            'ssh_port' => $this->workstation['ssh_port'] ?? 22,
        ];
    }

    /**
     * Run a "Hello World" test combining agent config with workstation SSH
     *
     * This verifies the full pipeline:
     * - SSH connection to workstation
     * - Agent config (environment variables, model)
     * - Claude CLI execution
     *
     * @return array Test results
     */
    public function runHelloWorld(): array {
        $startTime = microtime(true);
        $testId = 'agent-test-' . uniqid();
        $workDir = "/tmp/{$testId}";

        // Build env vars based on agent config
        $envVars = $this->buildEnvVars();
        $modelFlag = $this->getModelFlag();

        // Create work directory
        $mkdirResult = $this->sshExec("mkdir -p {$workDir}", 10);
        if ($mkdirResult['exit_code'] !== 0) {
            return [
                'success' => false,
                'error' => 'Failed to create work directory: ' . $mkdirResult['output'],
                'duration_ms' => round((microtime(true) - $startTime) * 1000),
                'agent' => $this->getAgentInfo(),
                'workstation' => $this->getWorkstationInfo()
            ];
        }

        // Build the Claude command
        // Source profile to get node/npm in PATH, set env vars, then run Claude
        $claudeCmd = implode('; ', [
            'source ~/.bashrc 2>/dev/null',
            'source ~/.profile 2>/dev/null',
            'source ~/.nvm/nvm.sh 2>/dev/null',
            $envVars,
            'cd ' . escapeshellarg($workDir),
            "timeout 90 claude --print {$modelFlag} \"Say exactly: Hello from Claude! Agent: {$this->agent['name']}, Workstation: {$this->workstation['name']}. Then tell me what directory you are in and what model you are.\""
        ]);

        $claudeResult = $this->sshExec($claudeCmd, 95);

        // Cleanup work directory
        $this->sshExec("rm -rf {$workDir}", 5);

        $success = $claudeResult['exit_code'] === 0 && !empty(trim($claudeResult['output']));

        // Check if output looks like a valid Claude response
        $output = trim($claudeResult['output']);
        $hasHello = stripos($output, 'hello') !== false;

        return [
            'success' => $success && $hasHello,
            'output' => $output,
            'exit_code' => $claudeResult['exit_code'],
            'duration_ms' => round((microtime(true) - $startTime) * 1000),
            'error' => !$success ? 'Claude command failed: ' . $claudeResult['output'] : null,
            'warning' => ($success && !$hasHello) ? 'Response received but may not contain expected greeting' : null,
            'agent' => $this->getAgentInfo(),
            'workstation' => $this->getWorkstationInfo()
        ];
    }

    /**
     * Quick SSH connectivity test (no Claude)
     */
    public function quickSSHTest(): array {
        $result = $this->sshExec('echo "SSH_OK"', 10);

        return [
            'connected' => ($result['exit_code'] === 0 && strpos($result['output'], 'SSH_OK') !== false),
            'time_ms' => $result['time_ms'],
            'error' => $result['exit_code'] !== 0 ? $result['output'] : null,
            'workstation' => $this->getWorkstationInfo()
        ];
    }

    /**
     * Load agent and workstation from database by IDs
     *
     * @param int $agentId
     * @param int $workstationId
     * @return AgentTestService|null
     */
    public static function fromIds(int $agentId, int $workstationId): ?self {
        $agentBean = Bean::load('aiagents', $agentId);
        if (!$agentBean || !$agentBean->id) {
            return null;
        }

        $workstationBean = Bean::load('runners', $workstationId);
        if (!$workstationBean || !$workstationBean->id) {
            return null;
        }

        $agent = [
            'id' => $agentBean->id,
            'name' => $agentBean->name,
            'provider' => $agentBean->provider ?? 'claude_cli',
            'provider_config' => json_decode($agentBean->provider_config ?: '{}', true),
        ];

        $workstation = [
            'id' => $workstationBean->id,
            'name' => $workstationBean->name,
            'host' => $workstationBean->host,
            'ssh_user' => $workstationBean->ssh_user ?? 'claudeuser',
            'ssh_port' => (int)($workstationBean->ssh_port ?? 22),
            'sshkey_id' => $workstationBean->sshkey_id,
            'execution_mode' => $workstationBean->execution_mode ?? 'ssh_tmux',
        ];

        return new self($agent, $workstation);
    }

    /**
     * Get all active workstations for dropdown
     */
    public static function getActiveWorkstations(): array {
        $beans = Bean::find('runners', 'is_active = 1 ORDER BY name ASC');
        $workstations = [];

        foreach ($beans as $bean) {
            $workstations[] = [
                'id' => $bean->id,
                'name' => $bean->name,
                'host' => $bean->host,
                'ssh_user' => $bean->ssh_user ?? 'claudeuser',
                'execution_mode' => $bean->execution_mode ?? 'ssh_tmux',
            ];
        }

        return $workstations;
    }

    /**
     * Get all active agents for dropdown
     */
    public static function getActiveAgents(): array {
        $beans = Bean::find('aiagents', 'is_active = 1 ORDER BY name ASC');
        $agents = [];

        foreach ($beans as $bean) {
            $provider = $bean->provider ?? 'claude_cli';
            $providerConfig = json_decode($bean->provider_config ?: '{}', true);

            // Determine if using Ollama
            $useOllama = !empty($providerConfig['use_ollama']) || $provider === 'ollama';

            // Get model based on provider type
            if ($provider === 'ollama') {
                $model = $providerConfig['model'] ?? 'unknown';
            } elseif (!empty($providerConfig['use_ollama'])) {
                $model = $providerConfig['ollama_model'] ?? 'unknown';
            } else {
                $model = $providerConfig['model'] ?? 'sonnet';
            }

            $agents[] = [
                'id' => $bean->id,
                'name' => $bean->name,
                'provider' => $provider,
                'use_ollama' => $useOllama,
                'model' => $model,
            ];
        }

        return $agents;
    }
}
