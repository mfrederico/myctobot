<?php
$servers = $servers ?? [];
$presets = $presets ?? [];
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2 mb-0">
            <i class="bi bi-plug"></i> MCP Server Library
        </h1>
        <div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addServerModal">
                <i class="bi bi-plus-lg"></i> Add Server
            </button>
        </div>
    </div>

    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i>
        MCP servers can be shared across your workspace and linked to multiple agents.
        <strong>Jira and Playwright</strong> are always auto-configured at runtime.
    </div>

    <?php if (empty($servers)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-plug display-4 text-muted"></i>
            <h5 class="mt-3">No MCP Servers</h5>
            <p class="text-muted">Create reusable MCP server configurations that can be shared across agents.</p>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addServerModal">
                <i class="bi bi-plus-lg"></i> Add Your First Server
            </button>
        </div>
    </div>
    <?php else: ?>
    <div class="row g-4">
        <?php foreach ($servers as $server): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>
                        <i class="bi bi-<?= $server['server_type'] === 'stdio' ? 'terminal' : 'globe' ?>"></i>
                        <strong><?= htmlspecialchars($server['name']) ?></strong>
                    </span>
                    <div>
                        <?php if ($server['is_shared']): ?>
                        <span class="badge bg-success" title="Shared with workspace"><i class="bi bi-people"></i></span>
                        <?php endif; ?>
                        <span class="badge bg-<?= $server['server_type'] === 'stdio' ? 'info' : 'warning' ?>">
                            <?= strtoupper($server['server_type']) ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($server['description']): ?>
                    <p class="text-muted small mb-2"><?= htmlspecialchars($server['description']) ?></p>
                    <?php endif; ?>

                    <?php if ($server['server_type'] === 'stdio'): ?>
                    <code class="small d-block text-truncate" title="<?= htmlspecialchars($server['command'] . ' ' . implode(' ', $server['args'])) ?>">
                        <?= htmlspecialchars($server['command']) ?> <?= htmlspecialchars(implode(' ', $server['args'])) ?>
                    </code>
                    <?php else: ?>
                    <code class="small d-block text-truncate" title="<?= htmlspecialchars($server['url']) ?>">
                        <?= htmlspecialchars($server['url']) ?>
                    </code>
                    <?php endif; ?>

                    <?php if (!empty($server['env'])): ?>
                    <div class="mt-2">
                        <span class="badge bg-secondary">ENV: <?= htmlspecialchars(implode(', ', array_keys($server['env']))) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-transparent">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <?= $server['is_own'] ? 'Created by you' : 'Shared' ?>
                        </small>
                        <?php if ($server['is_own']): ?>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary" onclick="toggleShared(<?= $server['id'] ?>)" title="<?= $server['is_shared'] ? 'Make Private' : 'Share with Workspace' ?>">
                                <i class="bi bi-<?= $server['is_shared'] ? 'lock' : 'people' ?>"></i>
                            </button>
                            <button type="button" class="btn btn-outline-primary" onclick="editServer(<?= $server['id'] ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-outline-danger" onclick="deleteServer(<?= $server['id'] ?>, '<?= htmlspecialchars($server['name'], ENT_QUOTES) ?>')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Add/Edit Server Modal -->
<div class="modal fade" id="addServerModal" tabindex="-1" aria-labelledby="addServerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addServerModalLabel"><i class="bi bi-plug"></i> <span id="modalTitle">Add MCP Server</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="serverForm">
                    <input type="hidden" name="csrf_token" value="<?= Flight::csrf()->getToken() ?>">
                    <input type="hidden" id="serverId" name="server_id" value="">

                    <!-- Preset Templates -->
                    <div class="mb-4" id="presetSection">
                        <label class="form-label fw-bold">Quick Add from Templates</label>
                        <div class="row g-2">
                            <?php foreach ($presets as $key => $preset): ?>
                            <div class="col-md-4">
                                <?php
                                $icons = [
                                    'github' => 'github',
                                    'fetch' => 'cloud-download',
                                    'filesystem' => 'folder',
                                    'memory' => 'brain',
                                    'puppeteer' => 'browser-chrome',
                                    'playwright' => 'play-circle',
                                    'mantic' => 'search'
                                ];
                                $icon = $icons[$key] ?? 'plug';
                                ?>
                                <button type="button" class="btn btn-outline-secondary w-100" onclick="loadPreset('<?= $key ?>')">
                                    <i class="bi bi-<?= $icon ?>"></i>
                                    <?= ucfirst($key) ?>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <hr id="presetDivider">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Server Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="serverName" name="name" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <input type="text" class="form-control" id="serverDescription" name="description">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Server Type <span class="text-danger">*</span></label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="server_type" id="typeStdio" value="stdio" checked onchange="toggleTypeFields()">
                            <label class="btn btn-outline-primary" for="typeStdio">
                                <i class="bi bi-terminal"></i> STDIO (Local Process)
                            </label>
                            <input type="radio" class="btn-check" name="server_type" id="typeHttp" value="http" onchange="toggleTypeFields()">
                            <label class="btn btn-outline-primary" for="typeHttp">
                                <i class="bi bi-globe"></i> HTTP (Remote Server)
                            </label>
                        </div>
                    </div>

                    <!-- STDIO Fields -->
                    <div id="stdioFields">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Command <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="serverCommand" name="command" placeholder="e.g., npx, uvx, node">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Arguments</label>
                            <input type="text" class="form-control" id="serverArgs" name="args" placeholder="e.g., -y, @modelcontextprotocol/server-github">
                            <div class="form-text">Comma-separated arguments</div>
                        </div>
                    </div>

                    <!-- HTTP Fields -->
                    <div id="httpFields" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label fw-bold">URL <span class="text-danger">*</span></label>
                            <input type="url" class="form-control" id="serverUrl" name="url" placeholder="https://api.example.com/mcp">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Headers (JSON)</label>
                            <textarea class="form-control font-monospace" id="serverHeaders" name="headers" rows="3" placeholder='{"Authorization": "Bearer token"}'></textarea>
                        </div>
                    </div>

                    <!-- Environment Variables -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">Environment Variables</label>
                        <div id="envVarList"></div>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="addEnvVar()">
                            <i class="bi bi-plus"></i> Add Environment Variable
                        </button>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveServer()">
                    <i class="bi bi-check-lg"></i> <span id="saveButtonText">Save Server</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const presets = <?= json_encode($presets) ?>;
let editingServerId = null;

// Toggle STDIO/HTTP fields
function toggleTypeFields() {
    const isStdio = document.getElementById('typeStdio').checked;
    document.getElementById('stdioFields').style.display = isStdio ? 'block' : 'none';
    document.getElementById('httpFields').style.display = isStdio ? 'none' : 'block';
}

// Load preset into form
function loadPreset(presetName) {
    const preset = presets[presetName];
    if (!preset) return;

    document.getElementById('serverName').value = preset.name;
    document.getElementById('serverDescription').value = preset.description || '';

    if (preset.server_type === 'stdio') {
        document.getElementById('typeStdio').checked = true;
        document.getElementById('serverCommand').value = preset.command || '';
        document.getElementById('serverArgs').value = (preset.args || []).join(', ');
    } else {
        document.getElementById('typeHttp').checked = true;
        document.getElementById('serverUrl').value = preset.url || '';
        document.getElementById('serverHeaders').value = JSON.stringify(preset.headers || {}, null, 2);
    }
    toggleTypeFields();

    // Load env vars
    document.getElementById('envVarList').innerHTML = '';
    if (preset.env) {
        for (const [key, value] of Object.entries(preset.env)) {
            addEnvVar(key, value);
        }
    }
}

// Add environment variable row
function addEnvVar(key = '', value = '') {
    const list = document.getElementById('envVarList');
    const row = document.createElement('div');
    row.className = 'row g-2 mb-2 env-var-row';
    row.innerHTML = `
        <div class="col-5">
            <input type="text" class="form-control form-control-sm env-key" placeholder="KEY" value="${escapeHtml(key)}">
        </div>
        <div class="col-5">
            <input type="text" class="form-control form-control-sm env-value" placeholder="value" value="${escapeHtml(value)}">
        </div>
        <div class="col-2">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.env-var-row').remove()">
                <i class="bi bi-x"></i>
            </button>
        </div>
    `;
    list.appendChild(row);
}

// Reset form
function resetForm() {
    editingServerId = null;
    document.getElementById('serverId').value = '';
    document.getElementById('serverForm').reset();
    document.getElementById('envVarList').innerHTML = '';
    document.getElementById('typeStdio').checked = true;
    toggleTypeFields();
    document.getElementById('modalTitle').textContent = 'Add MCP Server';
    document.getElementById('saveButtonText').textContent = 'Save Server';
    document.getElementById('presetSection').style.display = 'block';
    document.getElementById('presetDivider').style.display = 'block';
}

// Edit server
function editServer(serverId) {
    fetch('/mcpservers/list')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const server = data.data.find(s => s.id === serverId);
                if (server) {
                    editingServerId = serverId;
                    document.getElementById('serverId').value = serverId;
                    document.getElementById('serverName').value = server.name;
                    document.getElementById('serverDescription').value = server.description || '';

                    if (server.server_type === 'stdio') {
                        document.getElementById('typeStdio').checked = true;
                        document.getElementById('serverCommand').value = server.command || '';
                        document.getElementById('serverArgs').value = (server.args || []).join(', ');
                    } else {
                        document.getElementById('typeHttp').checked = true;
                        document.getElementById('serverUrl').value = server.url || '';
                        document.getElementById('serverHeaders').value = JSON.stringify(server.headers || {}, null, 2);
                    }
                    toggleTypeFields();

                    document.getElementById('envVarList').innerHTML = '';
                    if (server.env) {
                        for (const [key, value] of Object.entries(server.env)) {
                            addEnvVar(key, value);
                        }
                    }

                    document.getElementById('modalTitle').textContent = 'Edit MCP Server';
                    document.getElementById('saveButtonText').textContent = 'Update Server';
                    document.getElementById('presetSection').style.display = 'none';
                    document.getElementById('presetDivider').style.display = 'none';

                    new bootstrap.Modal(document.getElementById('addServerModal')).show();
                }
            }
        });
}

// Save server
function saveServer() {
    const form = document.getElementById('serverForm');
    const isStdio = document.getElementById('typeStdio').checked;

    // Collect env vars
    const envVars = {};
    document.querySelectorAll('.env-var-row').forEach(row => {
        const key = row.querySelector('.env-key').value.trim();
        const value = row.querySelector('.env-value').value.trim();
        if (key) envVars[key] = value;
    });

    const data = {
        csrf_token: form.csrf_token.value,
        name: document.getElementById('serverName').value,
        description: document.getElementById('serverDescription').value,
        server_type: isStdio ? 'stdio' : 'http',
        env: JSON.stringify(envVars)
    };

    if (isStdio) {
        data.command = document.getElementById('serverCommand').value;
        data.args = document.getElementById('serverArgs').value;
    } else {
        data.url = document.getElementById('serverUrl').value;
        data.headers = document.getElementById('serverHeaders').value;
    }

    const url = editingServerId ? `/mcpservers/update/${editingServerId}` : '/mcpservers/store';

    fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(data)
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            location.reload();
        } else {
            alert(result.message || 'Error saving server');
        }
    });
}

// Delete server
function deleteServer(serverId, name) {
    if (!confirm(`Delete MCP server "${name}"?`)) return;

    fetch(`/mcpservers/delete/${serverId}`, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({csrf_token: '<?= Flight::csrf()->getToken() ?>'})
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            location.reload();
        } else {
            alert(result.message || 'Error deleting server');
        }
    });
}

// Toggle shared status
function toggleShared(serverId) {
    fetch(`/mcpservers/toggleshared/${serverId}`, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({csrf_token: '<?= Flight::csrf()->getToken() ?>'})
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            location.reload();
        } else {
            alert(result.message || 'Error updating sharing');
        }
    });
}

// Escape HTML
function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Reset form when modal closes
document.getElementById('addServerModal').addEventListener('hidden.bs.modal', resetForm);
</script>
