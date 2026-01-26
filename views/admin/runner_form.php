<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-<?= isset($runner) ? 'pencil' : 'plus-lg' ?>"></i>
                        <?= isset($runner) ? 'Edit Workstation: ' . htmlspecialchars($runner['name']) : 'Add Workstation' ?>
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="/admin/<?= isset($runner) ? 'editrunner/' . $runner['id'] : 'createrunner' ?>" id="runnerForm">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Basic Info -->
                        <div class="mb-4">
                            <h6 class="text-muted border-bottom pb-2">Basic Information</h6>

                            <div class="mb-3">
                                <label for="name" class="form-label">Workstation Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name"
                                       value="<?= htmlspecialchars($runner['name'] ?? '') ?>" required
                                       placeholder="e.g., Local Dev or Production Workstation 1">
                            </div>

                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="2"
                                          placeholder="Optional description of this runner's purpose"><?= htmlspecialchars($runner['description'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <!-- Execution Mode -->
                        <div class="mb-4">
                            <h6 class="text-muted border-bottom pb-2">Execution Mode</h6>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="execution_mode"
                                               id="mode_ssh" value="ssh_tmux"
                                               <?= ($runner['execution_mode'] ?? 'ssh_tmux') === 'ssh_tmux' ? 'checked' : '' ?>
                                               onchange="toggleExecutionMode()">
                                        <label class="form-check-label" for="mode_ssh">
                                            <strong>SSH + Tmux</strong> (Recommended)
                                            <div class="text-muted small">Uses your Claude subscription via interactive CLI</div>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="execution_mode"
                                               id="mode_http" value="http_api"
                                               <?= ($runner['execution_mode'] ?? '') === 'http_api' ? 'checked' : '' ?>
                                               onchange="toggleExecutionMode()">
                                        <label class="form-check-label" for="mode_http">
                                            <strong>HTTP API</strong>
                                            <div class="text-muted small">Uses API credits via runner server endpoint</div>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- SSH Settings (shown when SSH mode selected) -->
                        <div class="mb-4" id="sshSettings">
                            <h6 class="text-muted border-bottom pb-2">
                                <i class="bi bi-terminal"></i> SSH Connection
                                <span id="sshValidatedBadge">
                                    <?php if (isset($runner) && !empty($runner['ssh_validated'])): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle"></i> Validated</span>
                                    <?php endif; ?>
                                </span>
                            </h6>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="host" class="form-label">Host <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="host" name="host"
                                           value="<?= htmlspecialchars($runner['host'] ?? 'localhost') ?>" required
                                           placeholder="e.g., localhost or 173.231.12.84">
                                    <div class="form-text">Use <code>localhost</code> for local development</div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="ssh_user" class="form-label">SSH User <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="ssh_user" name="ssh_user"
                                           value="<?= htmlspecialchars($runner['ssh_user'] ?? 'claudeuser') ?>"
                                           placeholder="claudeuser">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="ssh_port" class="form-label">SSH Port</label>
                                    <input type="number" class="form-control" id="ssh_port" name="ssh_port"
                                           value="<?= $runner['ssh_port'] ?? 22 ?>" min="1" max="65535">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="sshkey_id" class="form-label">SSH Key</label>
                                <select class="form-select" id="sshkey_id" name="sshkey_id">
                                    <option value="">Use system default (~/.ssh/id_rsa)</option>
                                    <?php if (!empty($sshKeys)): ?>
                                        <?php foreach ($sshKeys as $key): ?>
                                        <option value="<?= $key['id'] ?>" <?= ($runner['sshkey_id'] ?? '') == $key['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($key['name']) ?> (<?= $key['key_type'] ?>) - <?= substr($key['fingerprint'], 0, 20) ?>...
                                        </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <div class="form-text">
                                    Select an SSH key from your <a href="/admin/runners#sshkeys">SSH Keys</a> or use system default.
                                </div>
                            </div>

                            <?php if (isset($runner)): ?>
                            <div class="d-flex gap-2 flex-wrap mb-3">
                                <button type="button" class="btn btn-outline-info" onclick="runDiagnostic()">
                                    <i class="bi bi-clipboard-check"></i> Full Diagnostic
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="testSSH()">
                                    <i class="bi bi-plug"></i> Quick Test
                                </button>
                                <button type="button" class="btn btn-outline-success" onclick="runHelloWorld()">
                                    <i class="bi bi-robot"></i> Test Claude
                                </button>
                            </div>

                            <!-- Test with Agent -->
                            <div class="card bg-light mb-2">
                                <div class="card-body py-2">
                                    <div class="row align-items-end">
                                        <div class="col-md-8 mb-2 mb-md-0">
                                            <label for="test_agent" class="form-label small mb-1">
                                                <i class="bi bi-robot"></i> Test with Agent Configuration
                                            </label>
                                            <select class="form-select form-select-sm" id="test_agent">
                                                <option value="">Loading agents...</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <button type="button" class="btn btn-sm btn-primary w-100" onclick="runTestWithAgent()">
                                                <i class="bi bi-play-circle"></i> Run Agent Test
                                            </button>
                                        </div>
                                    </div>
                                    <div class="form-text mt-1">
                                        Tests this workstation's SSH connection using the selected agent's model/Ollama config.
                                    </div>
                                </div>
                            </div>

                            <!-- Test Job Executor -->
                            <div class="card bg-light">
                                <div class="card-body py-2">
                                    <div class="row align-items-end">
                                        <div class="col-md-8 mb-2 mb-md-0">
                                            <label for="job_executor_url" class="form-label small mb-1">
                                                <i class="bi bi-server"></i> Job Executor URL
                                            </label>
                                            <input type="text" class="form-control form-control-sm" id="job_executor_url"
                                                   value="<?= htmlspecialchars(\app\services\JobExecutorConfig::getUrl()) ?>" placeholder="https://jobs.myctobot.ai">
                                        </div>
                                        <div class="col-md-4">
                                            <button type="button" class="btn btn-sm btn-warning w-100" onclick="testJobExecutor()">
                                                <i class="bi bi-lightning"></i> Test Job Executor
                                            </button>
                                        </div>
                                    </div>
                                    <div class="form-text mt-1">
                                        Tests the job-executor ping/pong validation flow. Creates a test job and verifies two-way communication.
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- HTTP API Settings (shown when HTTP mode selected) -->
                        <div class="mb-4" id="httpSettings" style="display: none;">
                            <h6 class="text-muted border-bottom pb-2">
                                <i class="bi bi-cloud"></i> HTTP API Connection
                            </h6>

                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label for="http_host" class="form-label">Host <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="http_host"
                                           value="<?= htmlspecialchars($runner['host'] ?? '') ?>"
                                           placeholder="e.g., 173.231.12.84 or runner1.example.com"
                                           onchange="document.getElementById('host').value = this.value">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="port" class="form-label">Port</label>
                                    <input type="number" class="form-control" id="port" name="port"
                                           value="<?= $runner['port'] ?? 3500 ?>" min="1" max="65535">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="api_key" class="form-label">API Key <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="api_key" name="api_key"
                                           value="<?= htmlspecialchars($runner['api_key'] ?? '') ?>"
                                           placeholder="<?= isset($runner) ? 'Leave blank to keep existing' : 'Runner API key' ?>">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('api_key')">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <?php if (isset($runner)): ?>
                                <div class="form-text">Leave blank to keep the existing API key.</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Workstation Configuration -->
                        <div class="mb-4">
                            <h6 class="text-muted border-bottom pb-2">Workstation Configuration</h6>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="runner_type" class="form-label">Workstation Type</label>
                                    <select class="form-select" id="runner_type" name="runner_type">
                                        <option value="general" <?= ($runner['runner_type'] ?? 'general') === 'general' ? 'selected' : '' ?>>General Purpose</option>
                                        <option value="playwright" <?= ($runner['runner_type'] ?? '') === 'playwright' ? 'selected' : '' ?>>Playwright (Browser Testing)</option>
                                        <option value="database" <?= ($runner['runner_type'] ?? '') === 'database' ? 'selected' : '' ?>>Database Operations</option>
                                        <option value="full" <?= ($runner['runner_type'] ?? '') === 'full' ? 'selected' : '' ?>>Full Featured</option>
                                        <option value="custom" <?= ($runner['runner_type'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="max_concurrent_jobs" class="form-label">Max Concurrent Jobs</label>
                                    <input type="number" class="form-control" id="max_concurrent_jobs" name="max_concurrent_jobs"
                                           value="<?= $runner['max_concurrent_jobs'] ?? 2 ?>" min="1" max="10">
                                    <div class="form-text">How many jobs can run simultaneously on this workstation.</div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Capabilities (MCP Servers)</label>
                                <div class="row">
                                    <?php
                                    $capabilities = isset($runner['capabilities'])
                                        ? (is_string($runner['capabilities']) ? json_decode($runner['capabilities'], true) : $runner['capabilities'])
                                        : [];
                                    // For new runners, auto-select required servers
                                    if (!isset($runner) && empty($capabilities)) {
                                        $capabilities = [];
                                        foreach ($mcpServers as $server) {
                                            if (!empty($server['is_required'])) {
                                                $capabilities[] = $server['name'];
                                            }
                                        }
                                    }
                                    if (!empty($mcpServers)):
                                        foreach ($mcpServers as $server):
                                            $serverName = $server['name'];
                                            $serverDesc = $server['description'] ?? $serverName;
                                            $isRequired = !empty($server['is_required']);
                                            $isBuiltin = !empty($server['is_builtin']);
                                            $isChecked = $isRequired || in_array($serverName, $capabilities ?? []);
                                    ?>
                                    <div class="col-md-4 col-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   id="cap_<?= htmlspecialchars($serverName) ?>"
                                                   name="capabilities[]"
                                                   value="<?= htmlspecialchars($serverName) ?>"
                                                   <?= $isChecked ? 'checked' : '' ?>
                                                   <?= $isRequired ? 'disabled' : '' ?>>
                                            <?php if ($isRequired): ?>
                                            <!-- Hidden input to ensure required values are submitted -->
                                            <input type="hidden" name="capabilities[]" value="<?= htmlspecialchars($serverName) ?>">
                                            <?php endif; ?>
                                            <label class="form-check-label" for="cap_<?= htmlspecialchars($serverName) ?>">
                                                <?= htmlspecialchars($serverDesc) ?>
                                                <?php if ($isRequired): ?>
                                                <span class="badge bg-primary ms-1" title="Required - cannot be disabled">required</span>
                                                <?php elseif ($isBuiltin): ?>
                                                <span class="badge bg-secondary ms-1" title="Built-in MCP server">built-in</span>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    </div>
                                    <?php
                                        endforeach;
                                    else:
                                    ?>
                                    <div class="col-12">
                                        <p class="text-muted mb-0">
                                            No MCP servers configured.
                                            <a href="/mcpservers">Add MCP servers</a> to enable capabilities.
                                        </p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="form-text mt-2">
                                    <i class="bi bi-info-circle"></i> Required servers are always enabled for core functionality.
                                    Built-in servers are pre-configured and don't require setup.
                                </div>
                            </div>
                        </div>

                        <!-- Status Flags -->
                        <div class="mb-4">
                            <h6 class="text-muted border-bottom pb-2">Status</h6>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                                               <?= ($runner['is_active'] ?? 1) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="is_active">Active</label>
                                        <div class="form-text">Enable this workstation for job routing.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="is_default" name="is_default" value="1"
                                               <?= ($runner['is_default'] ?? 0) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="is_default">Public</label>
                                        <div class="form-text">Available to all members without specific assignment.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="d-flex justify-content-between">
                            <a href="/admin/runners" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg"></i> <?= isset($runner) ? 'Update Workstation' : 'Create Workstation' ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Diagnostic Results (shown after running diagnostic) -->
            <?php if (isset($runner)): ?>
            <div class="card mt-4" id="diagnosticCard" style="display: none;">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-clipboard-check"></i> Diagnostic Results</h6>
                    <button type="button" class="btn-close" onclick="document.getElementById('diagnosticCard').style.display='none'"></button>
                </div>
                <div class="card-body" id="diagnosticResults">
                    <!-- Results populated by JavaScript -->
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const icon = event.target.closest('button').querySelector('i');

    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

function toggleExecutionMode() {
    const sshMode = document.getElementById('mode_ssh').checked;
    document.getElementById('sshSettings').style.display = sshMode ? 'block' : 'none';
    document.getElementById('httpSettings').style.display = sshMode ? 'none' : 'block';

    // Sync host field
    if (!sshMode) {
        document.getElementById('http_host').value = document.getElementById('host').value;
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleExecutionMode();
    loadAgentsForTest();
});

// Load available agents for testing dropdown
function loadAgentsForTest() {
    fetch('/admin/testagents')
        .then(r => r.json())
        .then(data => {
            const select = document.getElementById('test_agent');
            if (!select) return;

            if (data.success && data.agents && data.agents.length > 0) {
                select.innerHTML = '<option value="">-- Select an agent --</option>';
                data.agents.forEach(agent => {
                    const opt = document.createElement('option');
                    opt.value = agent.id;
                    const modelInfo = agent.use_ollama
                        ? 'Ollama: ' + agent.model
                        : 'Anthropic: ' + agent.model;
                    opt.textContent = agent.name + ' (' + modelInfo + ')';
                    select.appendChild(opt);
                });
            } else {
                select.innerHTML = '<option value="">No active agents found</option>';
            }
        })
        .catch(e => {
            const select = document.getElementById('test_agent');
            if (select) {
                select.innerHTML = '<option value="">Error loading agents</option>';
            }
        });
}

<?php if (isset($runner)): ?>
async function testSSH() {
    const btn = event.target.closest('button');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';

    try {
        // Collect current form values for testing
        const formData = new FormData();
        formData.append('host', document.getElementById('host').value);
        formData.append('ssh_user', document.getElementById('ssh_user').value);
        formData.append('ssh_port', document.getElementById('ssh_port').value);
        formData.append('sshkey_id', document.getElementById('sshkey_id').value);
        formData.append('execution_mode', document.querySelector('input[name="execution_mode"]:checked').value);

        const response = await fetch('/admin/testrunner/<?= $runner['id'] ?>', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            alert('SSH Connection successful!\n\nConnected to ' + data.data.ssh_user + '@' + data.data.host + '\nTime: ' + data.data.time_ms + 'ms');
        } else {
            alert('SSH Connection failed!\n\n' + (data.error || 'Unknown error'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = original;
    }
}

async function runHelloWorld() {
    const btn = event.target.closest('button');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Running Claude...';

    const card = document.getElementById('diagnosticCard');
    const results = document.getElementById('diagnosticResults');

    results.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-success"></div><p class="mt-2">Running Claude CLI test...</p><p class="text-muted small">This may take up to 60 seconds</p></div>';
    card.style.display = 'block';
    card.scrollIntoView({ behavior: 'smooth' });

    try {
        // Collect current form values for testing
        const formData = new FormData();
        formData.append('host', document.getElementById('host').value);
        formData.append('ssh_user', document.getElementById('ssh_user').value);
        formData.append('ssh_port', document.getElementById('ssh_port').value);
        formData.append('sshkey_id', document.getElementById('sshkey_id').value);

        const response = await fetch('/admin/testhelloworld/<?= $runner['id'] ?>', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        let html = '';

        if (data.success) {
            html += `
                <div class="alert alert-success d-flex align-items-center">
                    <i class="bi bi-check-circle-fill me-2 fs-4"></i>
                    <div>
                        <strong>Claude CLI Test Passed!</strong>
                        <div class="small">Completed in ${data.duration_ms}ms</div>
                    </div>
                </div>
            `;
        } else {
            html += `
                <div class="alert alert-danger d-flex align-items-center">
                    <i class="bi bi-x-circle-fill me-2 fs-4"></i>
                    <div>
                        <strong>Claude CLI Test Failed</strong>
                        <div class="small">${data.error || 'Unknown error'}</div>
                    </div>
                </div>
            `;
        }

        if (data.warning) {
            html += `
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i> ${data.warning}
                </div>
            `;
        }

        if (data.output) {
            html += `
                <div class="card">
                    <div class="card-header">
                        <strong><i class="bi bi-chat-left-text"></i> Claude Response</strong>
                    </div>
                    <div class="card-body">
                        <pre class="mb-0 bg-dark text-light p-3 rounded" style="white-space: pre-wrap; max-height: 300px; overflow-y: auto;">${escapeHtml(data.output)}</pre>
                    </div>
                </div>
            `;
        }

        results.innerHTML = html;

    } catch (err) {
        results.innerHTML = `
            <div class="alert alert-danger">
                <i class="bi bi-x-circle"></i> <strong>Error running test</strong>
                <p class="mb-0 mt-2">${err.message}</p>
            </div>
        `;
    } finally {
        btn.disabled = false;
        btn.innerHTML = original;
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

let wsCurrentTestId = null;
let wsPollInterval = null;

async function runTestWithAgent() {
    const agentId = document.getElementById('test_agent').value;
    if (!agentId) {
        alert('Please select an agent');
        return;
    }

    const btn = event.target.closest('button');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Starting...';

    const card = document.getElementById('diagnosticCard');
    const results = document.getElementById('diagnosticResults');

    results.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2">Starting agent test...</p></div>';
    card.style.display = 'block';
    card.scrollIntoView({ behavior: 'smooth' });

    try {
        const formData = new FormData();
        formData.append('agent_id', agentId);

        const response = await fetch('/admin/testwithagent/<?= $runner['id'] ?>', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Failed to start test');
        }

        wsCurrentTestId = data.test_id;

        // Show initial status
        results.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary"></div>
                <p class="mt-2">Running agent test on this workstation...</p>
                <p class="text-muted small">Test ID: ${wsCurrentTestId}</p>
            </div>
            ${data.agent && data.workstation ? `
            <div class="row mb-3">
                <div class="col-md-6">
                    <div class="card bg-light">
                        <div class="card-body py-2">
                            <strong><i class="bi bi-robot"></i> Agent:</strong> ${escapeHtml(data.agent.name)}<br>
                            <small class="text-muted">
                                ${data.agent.use_ollama
                                    ? 'Ollama: ' + escapeHtml(data.agent.ollama_model || '')
                                    : 'Anthropic: ' + escapeHtml(data.agent.anthropic_model || '')}
                            </small>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card bg-light">
                        <div class="card-body py-2">
                            <strong><i class="bi bi-hdd-network"></i> Workstation:</strong> ${escapeHtml(data.workstation.name)}<br>
                            <small class="text-muted">${escapeHtml(data.workstation.ssh_user)}@${escapeHtml(data.workstation.host)}</small>
                        </div>
                    </div>
                </div>
            </div>
            ` : ''}
        `;

        // Start polling
        wsPollInterval = setInterval(() => pollWsTestStatus(original), 2000);

    } catch (err) {
        btn.disabled = false;
        btn.innerHTML = original;
        results.innerHTML = `
            <div class="alert alert-danger">
                <i class="bi bi-x-circle"></i> <strong>Error starting test</strong>
                <p class="mb-0 mt-2">${err.message}</p>
            </div>
        `;
    }
}

async function pollWsTestStatus(originalBtnHtml) {
    if (!wsCurrentTestId) return;

    try {
        const response = await fetch('/admin/teststatus/' + wsCurrentTestId);
        const data = await response.json();

        if (!data.success && !data.status) {
            return;
        }

        const status = data.status;

        if (status === 'running') {
            const msg = data.message || 'Running...';
            const spinnerDiv = document.querySelector('#diagnosticResults .spinner-border');
            if (spinnerDiv) {
                spinnerDiv.parentElement.querySelector('p').textContent = msg;
            }
            return;
        }

        // Test completed - send exit to clean up tmux session
        clearInterval(wsPollInterval);
        wsPollInterval = null;

        // Send exit command to gracefully close the session
        try {
            await fetch('/admin/teststatus/' + wsCurrentTestId + '?send_exit=1');
        } catch (e) {
            console.warn('Failed to send exit:', e);
        }

        const btn = document.querySelector('button[onclick="runTestWithAgent()"]');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalBtnHtml || '<i class="bi bi-play-circle"></i> Run Agent Test';
        }

        displayWsTestResults(data);

    } catch (err) {
        console.error('Poll error:', err);
    }
}

function displayWsTestResults(data) {
    const results = document.getElementById('diagnosticResults');
    let html = '';

    const result = data.result || data;
    const isSuccess = data.status === 'completed' || result.success;

    if (isSuccess) {
        const durationDisplay = result.duration_ms ? `${result.duration_ms}ms` : 'N/A';
        html += `
            <div class="alert alert-success d-flex align-items-center">
                <i class="bi bi-check-circle-fill me-2 fs-4"></i>
                <div>
                    <strong>Agent Test Passed!</strong>
                    <div class="small">Completed in ${durationDisplay}</div>
                </div>
            </div>
        `;

        // Show job details
        if (result.job_uid || result.work_dir) {
            html += `
                <div class="card mb-3">
                    <div class="card-header py-2"><strong><i class="bi bi-info-circle"></i> Job Details</strong></div>
                    <div class="card-body py-2">
                        <table class="table table-sm mb-0">
                            ${result.job_uid ? `<tr><td class="fw-bold" style="width:120px">Job UID:</td><td><code>${escapeHtml(result.job_uid)}</code></td></tr>` : ''}
                            ${result.work_dir ? `<tr><td class="fw-bold">Work Dir:</td><td><code>${escapeHtml(result.work_dir)}</code></td></tr>` : ''}
                            ${result.started_at ? `<tr><td class="fw-bold">Started:</td><td>${escapeHtml(result.started_at)}</td></tr>` : ''}
                            ${result.completed_at ? `<tr><td class="fw-bold">Completed:</td><td>${escapeHtml(result.completed_at)}</td></tr>` : ''}
                        </table>
                    </div>
                </div>
            `;
        }
    } else {
        html += `
            <div class="alert alert-danger d-flex align-items-center">
                <i class="bi bi-x-circle-fill me-2 fs-4"></i>
                <div>
                    <strong>Agent Test Failed</strong>
                    <div class="small">${result.error || data.error || data.message || 'Unknown error'}</div>
                </div>
            </div>
        `;
    }

    if (result.warning) {
        html += `<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> ${result.warning}</div>`;
    }

    const agentInfo = data.agent || result.agent;
    const wsInfo = data.workstation || result.workstation;

    if (agentInfo && wsInfo) {
        html += `
            <div class="row mb-3">
                <div class="col-md-6">
                    <div class="card bg-light">
                        <div class="card-body py-2">
                            <strong><i class="bi bi-robot"></i> Agent:</strong> ${escapeHtml(agentInfo.name)}<br>
                            <small class="text-muted">
                                ${agentInfo.use_ollama
                                    ? 'Ollama: ' + escapeHtml(agentInfo.ollama_model || '')
                                    : 'Anthropic: ' + escapeHtml(agentInfo.anthropic_model || '')}
                            </small>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card bg-light">
                        <div class="card-body py-2">
                            <strong><i class="bi bi-hdd-network"></i> Workstation:</strong> ${escapeHtml(wsInfo.name)}<br>
                            <small class="text-muted">${escapeHtml(wsInfo.ssh_user)}@${escapeHtml(wsInfo.host)}</small>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    if (result.output) {
        html += `
            <div class="card">
                <div class="card-header"><strong><i class="bi bi-chat-left-text"></i> Claude Response</strong></div>
                <div class="card-body">
                    <pre class="mb-0 bg-dark text-light p-3 rounded" style="white-space: pre-wrap; max-height: 300px; overflow-y: auto;">${escapeHtml(result.output)}</pre>
                </div>
            </div>
        `;
    }

    results.innerHTML = html;
}

async function testJobExecutor() {
    const btn = event.target.closest('button');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';

    const card = document.getElementById('diagnosticCard');
    const results = document.getElementById('diagnosticResults');

    const jobExecutorUrl = document.getElementById('job_executor_url').value || '<?= \app\services\JobExecutorConfig::getUrl() ?>';

    results.innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-warning"></div>
            <p class="mt-2">Testing job-executor at <strong>${escapeHtml(jobExecutorUrl)}</strong>...</p>
            <p class="text-muted small">Testing ping/pong validation flow</p>
        </div>
    `;
    card.style.display = 'block';
    card.scrollIntoView({ behavior: 'smooth' });

    try {
        const formData = new FormData();
        formData.append('job_executor_url', jobExecutorUrl);

        const response = await fetch('/admin/testjobexecutor/<?= $runner['id'] ?>', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        let html = '';

        if (data.success) {
            html += `
                <div class="alert alert-success d-flex align-items-center">
                    <i class="bi bi-check-circle-fill me-2 fs-4"></i>
                    <div>
                        <strong>Job Executor Test Passed!</strong>
                        <div class="small">${data.message || 'Ping/pong validation successful'}</div>
                    </div>
                </div>
            `;

            if (data.details) {
                html += `
                    <div class="card mb-3">
                        <div class="card-header"><strong><i class="bi bi-arrow-left-right"></i> Flow Details</strong></div>
                        <div class="card-body">
                            <table class="table table-sm mb-0">
                                <tr>
                                    <td><i class="bi bi-arrow-right text-primary"></i> <strong>PING</strong></td>
                                    <td>${escapeHtml(data.details.ping)}</td>
                                </tr>
                                <tr>
                                    <td><i class="bi bi-arrow-left text-success"></i> <strong>PONG</strong></td>
                                    <td>${escapeHtml(data.details.pong)}</td>
                                </tr>
                                <tr>
                                    <td><i class="bi bi-tag"></i> Job UID</td>
                                    <td><code>${escapeHtml(data.details.job_uid)}</code></td>
                                </tr>
                                <tr>
                                    <td><i class="bi bi-hdd-network"></i> Workstation</td>
                                    <td>${escapeHtml(data.details.workstation || 'N/A')}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                `;
            }
        } else {
            html += `
                <div class="alert alert-danger d-flex align-items-center">
                    <i class="bi bi-x-circle-fill me-2 fs-4"></i>
                    <div>
                        <strong>Job Executor Test Failed</strong>
                        <div class="small">${data.error || 'Unknown error'}</div>
                    </div>
                </div>
            `;

            if (data.response) {
                html += `
                    <div class="card">
                        <div class="card-header"><strong>Response Details</strong></div>
                        <div class="card-body">
                            <pre class="mb-0 bg-dark text-light p-3 rounded" style="white-space: pre-wrap; max-height: 200px; overflow-y: auto;">${escapeHtml(typeof data.response === 'string' ? data.response : JSON.stringify(data.response, null, 2))}</pre>
                        </div>
                    </div>
                `;
            }
        }

        html += `<div class="text-muted small mt-2">Duration: ${data.duration_ms || 'N/A'}ms | URL: ${escapeHtml(data.job_executor_url || jobExecutorUrl)}</div>`;

        results.innerHTML = html;

    } catch (err) {
        results.innerHTML = `
            <div class="alert alert-danger">
                <i class="bi bi-x-circle"></i> <strong>Error running test</strong>
                <p class="mb-0 mt-2">${err.message}</p>
            </div>
        `;
    } finally {
        btn.disabled = false;
        btn.innerHTML = original;
    }
}

async function runDiagnostic() {
    const btn = event.target.closest('button');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Running diagnostics...';

    const card = document.getElementById('diagnosticCard');
    const results = document.getElementById('diagnosticResults');

    results.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2">Running diagnostic checks...</p></div>';
    card.style.display = 'block';
    card.scrollIntoView({ behavior: 'smooth' });

    try {
        // Collect current form values for testing
        const formData = new FormData();
        formData.append('host', document.getElementById('host').value);
        formData.append('ssh_user', document.getElementById('ssh_user').value);
        formData.append('ssh_port', document.getElementById('ssh_port').value);
        formData.append('sshkey_id', document.getElementById('sshkey_id').value);
        formData.append('execution_mode', document.querySelector('input[name="execution_mode"]:checked').value);

        const response = await fetch('/admin/diagnoserunner/<?= $runner['id'] ?>', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        let html = '';

        // Summary
        const summaryClass = data.ready ? 'alert-success' : 'alert-warning';
        const summaryIcon = data.ready ? 'check-circle-fill' : 'exclamation-triangle-fill';
        html += `
            <div class="alert ${summaryClass} d-flex align-items-center">
                <i class="bi bi-${summaryIcon} me-2"></i>
                <div>
                    <strong>${data.summary}</strong>
                    ${data.ready ? ' - Workstation is ready!' : ' - Some checks failed'}
                    <div class="small">Completed in ${data.duration_ms}ms</div>
                </div>
            </div>
        `;

        // Check results table
        html += '<table class="table table-sm">';
        html += '<thead><tr><th>Check</th><th>Status</th><th>Details</th><th>Time</th></tr></thead><tbody>';

        for (const [key, check] of Object.entries(data.checks)) {
            const statusClass = check.passed ? 'text-success' : 'text-danger';
            const statusIcon = check.passed ? 'check-circle' : 'x-circle';
            html += `
                <tr>
                    <td><strong>${check.name}</strong></td>
                    <td><i class="bi bi-${statusIcon} ${statusClass}"></i> ${check.passed ? 'Pass' : 'Fail'}</td>
                    <td class="small">${check.details || ''}</td>
                    <td class="text-muted">${check.time_ms}ms</td>
                </tr>
            `;
        }
        html += '</tbody></table>';

        // Install commands if needed
        if (data.install_commands && data.install_commands.length > 0) {
            html += '<div class="alert alert-info mt-3">';
            html += '<strong><i class="bi bi-terminal"></i> Install missing dependencies:</strong>';
            html += '<pre class="mb-0 mt-2">' + data.install_commands.join('\n') + '</pre>';
            html += '</div>';
        }

        results.innerHTML = html;

        // Update validation badge in sidebar without page reload
        const statusBadge = document.getElementById('sshValidatedBadge');
        if (statusBadge) {
            if (data.ready) {
                statusBadge.innerHTML = '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Validated</span>';
            } else {
                statusBadge.innerHTML = '<span class="badge bg-warning"><i class="bi bi-exclamation-triangle"></i> Issues Found</span>';
            }
        }

        // Update health status display
        const healthBadge = document.getElementById('healthStatusBadge');
        if (healthBadge) {
            healthBadge.className = 'badge ' + (data.ready ? 'bg-success' : 'bg-warning');
            healthBadge.textContent = data.ready ? 'healthy' : 'unhealthy';
        }

    } catch (err) {
        results.innerHTML = `
            <div class="alert alert-danger">
                <i class="bi bi-x-circle"></i> <strong>Error running diagnostic</strong>
                <p class="mb-0 mt-2">${err.message}</p>
            </div>
        `;
    } finally {
        btn.disabled = false;
        btn.innerHTML = original;
    }
}
<?php endif; ?>

// Form validation
document.getElementById('runnerForm').addEventListener('submit', function(e) {
    const capabilities = document.querySelectorAll('input[name="capabilities[]"]:checked');
    if (capabilities.length === 0) {
        e.preventDefault();
        alert('Please select at least one capability.');
        return false;
    }
});
</script>
