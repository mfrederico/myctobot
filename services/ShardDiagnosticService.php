<?php
/**
 * Shard Diagnostic Service
 *
 * Runs diagnostic checks on shards to validate SSH connectivity
 * and required dependencies for running Claude Code sessions.
 */

namespace app\services;

class ShardDiagnosticService {

    private array $shard;
    private array $results = [];
    private float $startTime;

    /**
     * Create diagnostic service for a shard
     */
    public function __construct(array $shard) {
        $this->shard = $shard;
    }

    /**
     * Run all diagnostic checks
     *
     * @return array Diagnostic results
     */
    public function runDiagnostic(): array {
        $this->startTime = microtime(true);
        $this->results = [
            'shard_id' => $this->shard['id'],
            'shard_name' => $this->shard['name'],
            'execution_mode' => $this->shard['execution_mode'] ?? 'ssh_tmux',
            'started_at' => date('Y-m-d H:i:s'),
            'checks' => [],
            'summary' => '',
            'ready' => false
        ];

        // Run checks in order (some depend on previous passing)
        $this->checkSSHConnection();

        // Only continue if SSH works
        if ($this->results['checks']['ssh_connect']['passed'] ?? false) {
            $this->checkClaudeCLI();
            $this->checkTmux();
            $this->checkGit();
            $this->checkNode();
            $this->checkWorkDirectory();
            $this->checkTmuxSession();
            $this->checkMCPServer();
        }

        // Calculate summary
        $passed = 0;
        $total = count($this->results['checks']);
        foreach ($this->results['checks'] as $check) {
            if ($check['passed']) $passed++;
        }

        $this->results['summary'] = "{$passed}/{$total} checks passed";
        $this->results['ready'] = ($passed === $total);
        $this->results['duration_ms'] = round((microtime(true) - $this->startTime) * 1000);
        $this->results['completed_at'] = date('Y-m-d H:i:s');

        return $this->results;
    }

    /**
     * Build SSH command prefix
     */
    private function sshPrefix(): string {
        $user = $this->shard['ssh_user'] ?? 'claudeuser';
        $host = $this->shard['host'];
        $port = $this->shard['ssh_port'] ?? 22;
        $keyPath = $this->shard['ssh_key_path'] ?? null;

        $cmd = "ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new -o BatchMode=yes";

        if ($port !== 22) {
            $cmd .= " -p {$port}";
        }

        if ($keyPath && file_exists($keyPath)) {
            $cmd .= " -i " . escapeshellarg($keyPath);
        }

        $cmd .= " " . escapeshellarg("{$user}@{$host}");

        return $cmd;
    }

    /**
     * Execute SSH command and return result
     */
    private function sshExec(string $remoteCmd, int $timeout = 10): array {
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
     * Check SSH connectivity
     */
    private function checkSSHConnection(): void {
        $result = $this->sshExec('echo "SSH_OK"');

        $this->results['checks']['ssh_connect'] = [
            'name' => 'SSH Connection',
            'passed' => ($result['exit_code'] === 0 && strpos($result['output'], 'SSH_OK') !== false),
            'output' => $result['output'],
            'time_ms' => $result['time_ms'],
            'details' => $result['exit_code'] === 0
                ? 'Connected to ' . ($this->shard['ssh_user'] ?? 'claudeuser') . '@' . $this->shard['host']
                : 'Failed to connect: ' . $result['output']
        ];
    }

    /**
     * Check Claude CLI is installed
     */
    private function checkClaudeCLI(): void {
        // Source profile to get npm global bin in PATH, or check common locations
        $result = $this->sshExec('source ~/.bashrc 2>/dev/null; source ~/.profile 2>/dev/null; source ~/.nvm/nvm.sh 2>/dev/null; claude --version 2>/dev/null || ~/.npm-global/bin/claude --version 2>/dev/null || /usr/local/bin/claude --version 2>/dev/null || echo "NOT_FOUND"');

        $passed = $result['exit_code'] === 0 && strpos($result['output'], 'NOT_FOUND') === false;

        $this->results['checks']['claude_cli'] = [
            'name' => 'Claude CLI',
            'passed' => $passed,
            'output' => $result['output'],
            'time_ms' => $result['time_ms'],
            'details' => $passed
                ? 'Claude CLI: ' . trim($result['output'])
                : 'Claude CLI not found. Install with: npm install -g @anthropic-ai/claude-code'
        ];
    }

    /**
     * Check tmux is installed
     */
    private function checkTmux(): void {
        $result = $this->sshExec('tmux -V 2>/dev/null || echo "NOT_FOUND"');

        $passed = $result['exit_code'] === 0 && strpos($result['output'], 'NOT_FOUND') === false;

        $this->results['checks']['tmux'] = [
            'name' => 'Tmux',
            'passed' => $passed,
            'output' => $result['output'],
            'time_ms' => $result['time_ms'],
            'details' => $passed
                ? 'Tmux: ' . trim($result['output'])
                : 'Tmux not found. Install with: apt install tmux'
        ];
    }

    /**
     * Check Git is installed
     */
    private function checkGit(): void {
        $result = $this->sshExec('git --version 2>/dev/null || echo "NOT_FOUND"');

        $passed = $result['exit_code'] === 0 && strpos($result['output'], 'NOT_FOUND') === false;

        $this->results['checks']['git'] = [
            'name' => 'Git',
            'passed' => $passed,
            'output' => $result['output'],
            'time_ms' => $result['time_ms'],
            'details' => $passed
                ? 'Git: ' . trim($result['output'])
                : 'Git not found. Install with: apt install git'
        ];
    }

    /**
     * Check Node.js is installed
     */
    private function checkNode(): void {
        // Source profile for nvm or other node version managers
        $result = $this->sshExec('source ~/.bashrc 2>/dev/null; source ~/.profile 2>/dev/null; source ~/.nvm/nvm.sh 2>/dev/null; node --version 2>/dev/null || echo "NOT_FOUND"');

        $passed = $result['exit_code'] === 0 && strpos($result['output'], 'NOT_FOUND') === false;

        $this->results['checks']['node'] = [
            'name' => 'Node.js',
            'passed' => $passed,
            'output' => $result['output'],
            'time_ms' => $result['time_ms'],
            'details' => $passed
                ? 'Node.js: ' . trim($result['output'])
                : 'Node.js not found. Required for Claude CLI.'
        ];
    }

    /**
     * Check work directory is writable
     */
    private function checkWorkDirectory(): void {
        $testDir = '/tmp/aidev-diagnostic-test-' . uniqid();
        $result = $this->sshExec("mkdir -p {$testDir} && touch {$testDir}/test && rm -rf {$testDir} && echo 'WRITABLE'");

        $passed = strpos($result['output'], 'WRITABLE') !== false;

        $this->results['checks']['work_dir'] = [
            'name' => 'Work Directory',
            'passed' => $passed,
            'output' => $result['output'],
            'time_ms' => $result['time_ms'],
            'details' => $passed
                ? '/tmp is writable'
                : 'Cannot write to /tmp directory'
        ];
    }

    /**
     * Check tmux session creation/deletion works
     */
    private function checkTmuxSession(): void {
        $testSession = 'diag-test-' . uniqid();

        // Create session
        $create = $this->sshExec("tmux new-session -d -s {$testSession} 'sleep 2'");
        if ($create['exit_code'] !== 0) {
            $this->results['checks']['tmux_session'] = [
                'name' => 'Tmux Sessions',
                'passed' => false,
                'output' => $create['output'],
                'time_ms' => $create['time_ms'],
                'details' => 'Failed to create tmux session: ' . $create['output']
            ];
            return;
        }

        // Verify exists
        $check = $this->sshExec("tmux has-session -t {$testSession}");

        // Kill session
        $kill = $this->sshExec("tmux kill-session -t {$testSession}");

        $passed = $create['exit_code'] === 0 && $check['exit_code'] === 0;

        $this->results['checks']['tmux_session'] = [
            'name' => 'Tmux Sessions',
            'passed' => $passed,
            'output' => 'create: ' . $create['exit_code'] . ', check: ' . $check['exit_code'] . ', kill: ' . $kill['exit_code'],
            'time_ms' => $create['time_ms'] + $check['time_ms'] + $kill['time_ms'],
            'details' => $passed
                ? 'Can create and manage tmux sessions'
                : 'Failed to manage tmux sessions'
        ];
    }

    /**
     * Check MCP Jira server exists on remote
     */
    private function checkMCPServer(): void {
        // Check multiple possible paths for the MCP server script
        $paths = [
            '/var/www/myctobot/scripts/mcp-jira-server.php',           // Production
            '/var/www/html/default/myctobot/scripts/mcp-jira-server.php', // Shard servers
            '/home/mfrederico/development/myctobot/scripts/mcp-jira-server.php', // Local dev
        ];

        $checkCmd = '';
        foreach ($paths as $path) {
            $checkCmd .= "test -f {$path} && php -l {$path} 2>/dev/null | grep -q 'No syntax errors' && echo 'MCP_OK:{$path}' && exit 0; ";
        }
        $checkCmd .= "echo 'MCP_MISSING'";

        $result = $this->sshExec($checkCmd);

        $passed = strpos($result['output'], 'MCP_OK') !== false;
        $foundPath = '';
        if ($passed && preg_match('/MCP_OK:(.+)/', $result['output'], $matches)) {
            $foundPath = $matches[1];
        }

        $this->results['checks']['mcp_server'] = [
            'name' => 'MCP Jira Server',
            'passed' => $passed,
            'output' => $result['output'],
            'time_ms' => $result['time_ms'],
            'details' => $passed
                ? 'MCP server found at ' . $foundPath
                : 'MCP server not found. Run sync-to-shards.sh to deploy.'
        ];
    }

    /**
     * Quick connectivity check (just SSH)
     */
    public function quickCheck(): array {
        $result = $this->sshExec('echo "OK"', 5);

        return [
            'connected' => ($result['exit_code'] === 0 && trim($result['output']) === 'OK'),
            'time_ms' => $result['time_ms'],
            'error' => $result['exit_code'] !== 0 ? $result['output'] : null
        ];
    }

    /**
     * Get installation commands for missing dependencies
     */
    public function getInstallCommands(): array {
        $commands = [];

        foreach ($this->results['checks'] as $key => $check) {
            if ($check['passed']) continue;

            switch ($key) {
                case 'claude_cli':
                    $commands[] = 'npm install -g @anthropic-ai/claude-code';
                    break;
                case 'tmux':
                    $commands[] = 'apt install -y tmux';
                    break;
                case 'git':
                    $commands[] = 'apt install -y git';
                    break;
                case 'node':
                    $commands[] = 'curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && apt install -y nodejs';
                    break;
                case 'mcp_server':
                    $commands[] = '# Run sync-to-shards.sh from control interface';
                    break;
            }
        }

        return $commands;
    }
}
