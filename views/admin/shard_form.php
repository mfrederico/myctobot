<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-<?= isset($shard) ? 'pencil' : 'plus-lg' ?>"></i>
                        <?= isset($shard) ? 'Edit Workstation: ' . htmlspecialchars($shard['name']) : 'Add Workstation' ?>
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="/admin/<?= isset($shard) ? 'editshard/' . $shard['id'] : 'createshard' ?>" id="shardForm">
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
                                       value="<?= htmlspecialchars($shard['name'] ?? '') ?>" required
                                       placeholder="e.g., Local Dev or Production Workstation 1">
                            </div>

                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="2"
                                          placeholder="Optional description of this shard's purpose"><?= htmlspecialchars($shard['description'] ?? '') ?></textarea>
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
                                               <?= ($shard['execution_mode'] ?? 'ssh_tmux') === 'ssh_tmux' ? 'checked' : '' ?>
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
                                               <?= ($shard['execution_mode'] ?? '') === 'http_api' ? 'checked' : '' ?>
                                               onchange="toggleExecutionMode()">
                                        <label class="form-check-label" for="mode_http">
                                            <strong>HTTP API</strong>
                                            <div class="text-muted small">Uses API credits via shard server endpoint</div>
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
                                    <?php if (isset($shard) && !empty($shard['ssh_validated'])): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle"></i> Validated</span>
                                    <?php endif; ?>
                                </span>
                            </h6>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="host" class="form-label">Host <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="host" name="host"
                                           value="<?= htmlspecialchars($shard['host'] ?? 'localhost') ?>" required
                                           placeholder="e.g., localhost or 173.231.12.84">
                                    <div class="form-text">Use <code>localhost</code> for local development</div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="ssh_user" class="form-label">SSH User <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="ssh_user" name="ssh_user"
                                           value="<?= htmlspecialchars($shard['ssh_user'] ?? 'claudeuser') ?>"
                                           placeholder="claudeuser">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="ssh_port" class="form-label">SSH Port</label>
                                    <input type="number" class="form-control" id="ssh_port" name="ssh_port"
                                           value="<?= $shard['ssh_port'] ?? 22 ?>" min="1" max="65535">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="ssh_key_path" class="form-label">SSH Key Path (Optional)</label>
                                <input type="text" class="form-control" id="ssh_key_path" name="ssh_key_path"
                                       value="<?= htmlspecialchars($shard['ssh_key_path'] ?? '') ?>"
                                       placeholder="Leave blank to use default ~/.ssh/id_rsa">
                                <div class="form-text">Absolute path to SSH private key. Leave blank to use system default.</div>
                            </div>

                            <?php if (isset($shard)): ?>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-info" onclick="runDiagnostic()">
                                    <i class="bi bi-clipboard-check"></i> Run Full Diagnostic
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="testSSH()">
                                    <i class="bi bi-plug"></i> Quick Test
                                </button>
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
                                           value="<?= htmlspecialchars($shard['host'] ?? '') ?>"
                                           placeholder="e.g., 173.231.12.84 or shard1.example.com"
                                           onchange="document.getElementById('host').value = this.value">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="port" class="form-label">Port</label>
                                    <input type="number" class="form-control" id="port" name="port"
                                           value="<?= $shard['port'] ?? 3500 ?>" min="1" max="65535">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="api_key" class="form-label">API Key <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="api_key" name="api_key"
                                           value="<?= htmlspecialchars($shard['api_key'] ?? '') ?>"
                                           placeholder="<?= isset($shard) ? 'Leave blank to keep existing' : 'Shard API key' ?>">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('api_key')">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <?php if (isset($shard)): ?>
                                <div class="form-text">Leave blank to keep the existing API key.</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Workstation Configuration -->
                        <div class="mb-4">
                            <h6 class="text-muted border-bottom pb-2">Workstation Configuration</h6>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="shard_type" class="form-label">Workstation Type</label>
                                    <select class="form-select" id="shard_type" name="shard_type">
                                        <option value="general" <?= ($shard['shard_type'] ?? 'general') === 'general' ? 'selected' : '' ?>>General Purpose</option>
                                        <option value="playwright" <?= ($shard['shard_type'] ?? '') === 'playwright' ? 'selected' : '' ?>>Playwright (Browser Testing)</option>
                                        <option value="database" <?= ($shard['shard_type'] ?? '') === 'database' ? 'selected' : '' ?>>Database Operations</option>
                                        <option value="full" <?= ($shard['shard_type'] ?? '') === 'full' ? 'selected' : '' ?>>Full Featured</option>
                                        <option value="custom" <?= ($shard['shard_type'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="max_concurrent_jobs" class="form-label">Max Concurrent Jobs</label>
                                    <input type="number" class="form-control" id="max_concurrent_jobs" name="max_concurrent_jobs"
                                           value="<?= $shard['max_concurrent_jobs'] ?? 2 ?>" min="1" max="10">
                                    <div class="form-text">How many jobs can run simultaneously on this workstation.</div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Capabilities (MCP Servers)</label>
                                <div class="row">
                                    <?php
                                    $capabilities = isset($shard['capabilities'])
                                        ? (is_string($shard['capabilities']) ? json_decode($shard['capabilities'], true) : $shard['capabilities'])
                                        : ['git', 'filesystem'];
                                    $allCapabilities = [
                                        'git' => 'Git Operations',
                                        'filesystem' => 'Filesystem Access',
                                        'github' => 'GitHub API',
                                        'playwright' => 'Playwright Browser',
                                        'postgres' => 'PostgreSQL',
                                        'mysql' => 'MySQL/MariaDB',
                                        'sqlite' => 'SQLite',
                                        'fetch' => 'HTTP Fetch',
                                        'puppeteer' => 'Puppeteer Browser',
                                        'slack' => 'Slack Integration',
                                        'jira' => 'Jira MCP Server'
                                    ];
                                    foreach ($allCapabilities as $cap => $label):
                                    ?>
                                    <div class="col-md-4 col-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   id="cap_<?= $cap ?>" name="capabilities[]" value="<?= $cap ?>"
                                                   <?= in_array($cap, $capabilities ?? []) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="cap_<?= $cap ?>"><?= $label ?></label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
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
                                               <?= ($shard['is_active'] ?? 1) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="is_active">Active</label>
                                        <div class="form-text">Enable this workstation for job routing.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="is_default" name="is_default" value="1"
                                               <?= ($shard['is_default'] ?? 0) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="is_default">Public</label>
                                        <div class="form-text">Available to all members without specific assignment.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="d-flex justify-content-between">
                            <a href="/admin/shards" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg"></i> <?= isset($shard) ? 'Update Workstation' : 'Create Workstation' ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Diagnostic Results (shown after running diagnostic) -->
            <?php if (isset($shard)): ?>
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
document.addEventListener('DOMContentLoaded', toggleExecutionMode);

<?php if (isset($shard)): ?>
async function testSSH() {
    const btn = event.target.closest('button');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';

    try {
        const response = await fetch('/admin/testshard/<?= $shard['id'] ?>');
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
        const response = await fetch('/admin/diagnoseshard/<?= $shard['id'] ?>');
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
document.getElementById('shardForm').addEventListener('submit', function(e) {
    const capabilities = document.querySelectorAll('input[name="capabilities[]"]:checked');
    if (capabilities.length === 0) {
        e.preventDefault();
        alert('Please select at least one capability.');
        return false;
    }
});
</script>
