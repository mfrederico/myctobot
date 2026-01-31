<?php
$servers = $servers ?? [];
$presets = $presets ?? [];
?>

<div class="container py-4">
    <!-- Navigation Tabs -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link active" href="/mcpservers">
                <i class="bi bi-plug"></i> MCP Servers
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="/agents">
                <i class="bi bi-robot"></i> Agents
            </a>
        </li>
    </ul>

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
                        <?php
                        $typeIcon = match($server['server_type']) {
                            'stdio' => 'terminal',
                            'sse' => 'broadcast',
                            default => 'globe'
                        };
                        $typeBadgeClass = match($server['server_type']) {
                            'stdio' => 'info',
                            'sse' => 'success',
                            default => 'warning'
                        };
                        ?>
                        <i class="bi bi-<?= $typeIcon ?>"></i>
                        <strong><?= htmlspecialchars($server['name']) ?></strong>
                    </span>
                    <div>
                        <?php if (!$server['is_active']): ?>
                        <span class="badge bg-secondary" title="Inactive - not available in pipelines"><i class="bi bi-pause-circle"></i></span>
                        <?php endif; ?>
                        <?php if ($server['is_shared']): ?>
                        <span class="badge bg-primary" title="Shared with workspace"><i class="bi bi-people"></i></span>
                        <?php endif; ?>
                        <span class="badge bg-<?= $typeBadgeClass ?>">
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

                    <?php if ($server['tools_count'] !== null): ?>
                    <div class="mt-2 d-flex gap-2 flex-wrap">
                        <span class="badge bg-primary" title="Number of tools available">
                            <i class="bi bi-tools"></i> <?= $server['tools_count'] ?> tools
                        </span>
                        <?php if ($server['tools_token_estimate']): ?>
                        <span class="badge bg-success" title="Estimated token overhead for context window">
                            <i class="bi bi-speedometer2"></i> ~<?= number_format($server['tools_token_estimate']) ?> tokens
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-transparent">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-success" onclick="testConnection(<?= $server['id'] ?>, '<?= htmlspecialchars($server['name'], ENT_QUOTES) ?>')" title="Test Connection">
                                <i class="bi bi-plug"></i> Test
                            </button>
                            <?php if ($server['tools_count'] > 0): ?>
                            <button type="button" class="btn btn-outline-primary" onclick="viewTools(<?= $server['id'] ?>)" title="View Cached Tools">
                                <i class="bi bi-tools"></i> Tools
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php if ($server['is_own']): ?>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-<?= $server['is_active'] ? 'warning' : 'success' ?>" onclick="toggleActive(<?= $server['id'] ?>)" title="<?= $server['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                <i class="bi bi-<?= $server['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                            </button>
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
                                    'myctobot-gateway' => 'cloud-arrow-up',
                                    'pipelines' => 'diagram-3',
                                    'github' => 'github',
                                    'fetch' => 'cloud-download',
                                    'filesystem' => 'folder',
                                    'memory' => 'brain',
                                    'puppeteer' => 'browser-chrome',
                                    'playwright' => 'play-circle',
                                    'mantic' => 'search'
                                ];
                                $icon = $icons[$key] ?? 'plug';
                                $btnClass = $key === 'myctobot-gateway' ? 'btn-primary' : ($key === 'pipelines' ? 'btn-outline-primary' : 'btn-outline-secondary');
                                ?>
                                <button type="button" class="btn <?= $btnClass ?> w-100" onclick="loadPreset('<?= $key ?>')">
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
                                <i class="bi bi-terminal"></i> STDIO
                            </label>
                            <input type="radio" class="btn-check" name="server_type" id="typeHttp" value="http" onchange="toggleTypeFields()">
                            <label class="btn btn-outline-primary" for="typeHttp">
                                <i class="bi bi-globe"></i> HTTP
                            </label>
                            <input type="radio" class="btn-check" name="server_type" id="typeSse" value="sse" onchange="toggleTypeFields()">
                            <label class="btn btn-outline-primary" for="typeSse">
                                <i class="bi bi-broadcast"></i> SSE
                            </label>
                        </div>
                        <div class="form-text">STDIO for local processes, HTTP/SSE for remote servers</div>
                    </div>

                    <!-- STDIO Fields -->
                    <div id="stdioFields">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Command <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="serverCommand" name="command" placeholder="e.g., php, npx, node">
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

                    <!-- Prompt Additions -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">Prompt Additions</label>
                        <textarea class="form-control font-monospace" id="serverPromptAdditions" name="prompt_additions" rows="6"
                            placeholder="Additional instructions injected into CLAUDE.md when this MCP server is used.

Supports variable substitution:
{context.issue_key} - Current issue key
{context.workspace} - Workspace slug
{context.repo} - Repository path
{context.branch} - Current branch
{context.agent_name} - Agent name"></textarea>
                        <div class="form-text">
                            These instructions are added to the agent's CLAUDE.md file when this MCP server is assigned.
                            Use <code>{context.variable}</code> for dynamic values.
                        </div>
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

<!-- Test Connection Modal -->
<div class="modal fade" id="testResultModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plug"></i> <span id="testServerName">Connection Test</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="testLoading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Testing connection...</span>
                    </div>
                    <p class="mt-2">Connecting to MCP server...</p>
                </div>
                <div id="testResults" style="display: none;">
                    <div id="testSuccess" class="alert alert-success" style="display: none;">
                        <i class="bi bi-check-circle"></i> <strong>Connection successful!</strong>
                    </div>
                    <div id="testError" class="alert alert-danger" style="display: none;">
                        <i class="bi bi-x-circle"></i> <strong>Connection failed:</strong> <span id="testErrorMsg"></span>
                    </div>

                    <div id="serverInfoSection" style="display: none;">
                        <h6><i class="bi bi-info-circle"></i> Server Info</h6>
                        <table class="table table-sm">
                            <tr><th>Name</th><td id="infoServerName">-</td></tr>
                            <tr><th>Version</th><td id="infoServerVersion">-</td></tr>
                        </table>
                    </div>

                    <div id="tokenOverheadSection" style="display: none;">
                        <h6><i class="bi bi-speedometer2"></i> Token Overhead</h6>
                        <div class="alert alert-light border">
                            <div class="row text-center">
                                <div class="col-6">
                                    <div class="fw-bold text-primary fs-4" id="toolsByteCount">-</div>
                                    <small class="text-muted">Bytes</small>
                                </div>
                                <div class="col-6">
                                    <div class="fw-bold text-success fs-4" id="toolsTokenEstimate">-</div>
                                    <small class="text-muted">Est. Tokens</small>
                                </div>
                            </div>
                            <p class="small text-muted mb-0 mt-2">
                                <i class="bi bi-info-circle"></i> Token overhead is the context window cost of including this MCP server's tool definitions.
                            </p>
                        </div>
                    </div>

                    <div id="toolsSection" style="display: none;">
                        <h6><i class="bi bi-tools"></i> Available Tools (<span id="toolsCount">0</span>)</h6>
                        <div id="toolsList" class="list-group list-group-flush" style="max-height: 300px; overflow-y: auto;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
const presets = <?= json_encode($presets) ?>;
let editingServerId = null;

// Toggle STDIO/HTTP/SSE fields
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
    } else if (preset.server_type === 'sse') {
        document.getElementById('typeSse').checked = true;
        document.getElementById('serverUrl').value = preset.url || '';
        document.getElementById('serverHeaders').value = JSON.stringify(preset.headers || {}, null, 2);
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
    document.getElementById('serverPromptAdditions').value = '';
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
                const server = data.data.find(s => s.id == serverId);
                if (server) {
                    editingServerId = serverId;
                    document.getElementById('serverId').value = serverId;
                    document.getElementById('serverName').value = server.name;
                    document.getElementById('serverDescription').value = server.description || '';

                    if (server.server_type === 'stdio') {
                        document.getElementById('typeStdio').checked = true;
                        document.getElementById('serverCommand').value = server.command || '';
                        document.getElementById('serverArgs').value = (server.args || []).join(', ');
                    } else if (server.server_type === 'sse') {
                        document.getElementById('typeSse').checked = true;
                        document.getElementById('serverUrl').value = server.url || '';
                        document.getElementById('serverHeaders').value = JSON.stringify(server.headers || {}, null, 2);
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

                    // Load prompt additions
                    document.getElementById('serverPromptAdditions').value = server.prompt_additions || '';

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
    const isSse = document.getElementById('typeSse').checked;

    // Collect env vars
    const envVars = {};
    document.querySelectorAll('.env-var-row').forEach(row => {
        const key = row.querySelector('.env-key').value.trim();
        const value = row.querySelector('.env-value').value.trim();
        if (key) envVars[key] = value;
    });

    let serverType = 'http';
    if (isStdio) serverType = 'stdio';
    else if (isSse) serverType = 'sse';

    const data = {
        csrf_token: form.csrf_token.value,
        name: document.getElementById('serverName').value,
        description: document.getElementById('serverDescription').value,
        server_type: serverType,
        env: JSON.stringify(envVars),
        prompt_additions: document.getElementById('serverPromptAdditions').value
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

// Toggle active status
function toggleActive(serverId) {
    fetch(`/mcpservers/toggleactive/${serverId}`, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({csrf_token: '<?= Flight::csrf()->getToken() ?>'})
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            location.reload();
        } else {
            alert(result.message || 'Error updating active status');
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

// View cached tools (without re-testing)
function viewTools(serverId) {
    fetch('/mcpservers/list')
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Error loading server data');
                return;
            }

            const server = data.data.find(s => s.id == serverId);
            if (!server) {
                alert('Server not found');
                return;
            }

            const modal = new bootstrap.Modal(document.getElementById('testResultModal'));

            // Set modal state for cached view
            document.getElementById('testServerName').textContent = server.name;
            document.getElementById('testLoading').style.display = 'none';
            document.getElementById('testResults').style.display = 'block';
            document.getElementById('testSuccess').style.display = 'block';
            document.getElementById('testError').style.display = 'none';

            // Server info
            if (server.server_name || server.server_version) {
                document.getElementById('serverInfoSection').style.display = 'block';
                document.getElementById('infoServerName').textContent = server.server_name || '-';
                document.getElementById('infoServerVersion').textContent = server.server_version || '-';
            } else {
                document.getElementById('serverInfoSection').style.display = 'none';
            }

            // Token overhead
            if (server.tools_byte_count) {
                document.getElementById('tokenOverheadSection').style.display = 'block';
                document.getElementById('toolsByteCount').textContent = server.tools_byte_count.toLocaleString();
                document.getElementById('toolsTokenEstimate').textContent = server.tools_token_estimate ? '~' + server.tools_token_estimate.toLocaleString() : '-';
            } else {
                document.getElementById('tokenOverheadSection').style.display = 'none';
            }

            // Tools
            const tools = server.tools || [];
            if (tools.length > 0) {
                document.getElementById('toolsSection').style.display = 'block';
                document.getElementById('toolsCount').textContent = tools.length;

                const toolsList = document.getElementById('toolsList');
                toolsList.innerHTML = '';

                tools.forEach(tool => {
                    const item = document.createElement('div');
                    item.className = 'list-group-item';
                    item.innerHTML = `
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <strong class="text-primary">${escapeHtml(tool.name)}</strong>
                                ${tool.description ? `<p class="mb-0 small text-muted">${escapeHtml(tool.description)}</p>` : ''}
                            </div>
                        </div>
                        ${tool.inputSchema ? `<details class="mt-1"><summary class="small text-muted">Parameters</summary><pre class="small bg-light p-2 mt-1 mb-0">${escapeHtml(JSON.stringify(tool.inputSchema.properties || {}, null, 2))}</pre></details>` : ''}
                    `;
                    toolsList.appendChild(item);
                });
            } else {
                document.getElementById('toolsSection').style.display = 'none';
            }

            modal.show();
        })
        .catch(err => {
            alert('Error: ' + err.message);
        });
}

// Test connection
function testConnection(serverId, serverName) {
    const modal = new bootstrap.Modal(document.getElementById('testResultModal'));

    // Reset modal state
    document.getElementById('testServerName').textContent = serverName;
    document.getElementById('testLoading').style.display = 'block';
    document.getElementById('testResults').style.display = 'none';
    document.getElementById('testSuccess').style.display = 'none';
    document.getElementById('testError').style.display = 'none';
    document.getElementById('serverInfoSection').style.display = 'none';
    document.getElementById('tokenOverheadSection').style.display = 'none';
    document.getElementById('toolsSection').style.display = 'none';

    modal.show();

    fetch(`/mcpservers/test/${serverId}`)
        .then(r => r.json())
        .then(result => {
            document.getElementById('testLoading').style.display = 'none';
            document.getElementById('testResults').style.display = 'block';

            if (result.success) {
                document.getElementById('testSuccess').style.display = 'block';

                // Server info
                const serverInfo = result.data.server_info || {};
                if (serverInfo.name || serverInfo.version) {
                    document.getElementById('serverInfoSection').style.display = 'block';
                    document.getElementById('infoServerName').textContent = serverInfo.name || '-';
                    document.getElementById('infoServerVersion').textContent = serverInfo.version || '-';
                }

                // Token Overhead
                const byteCount = result.data.tools_byte_count;
                const tokenEstimate = result.data.tools_token_estimate;
                if (byteCount !== undefined && byteCount !== null) {
                    document.getElementById('tokenOverheadSection').style.display = 'block';
                    document.getElementById('toolsByteCount').textContent = byteCount.toLocaleString();
                    document.getElementById('toolsTokenEstimate').textContent = tokenEstimate ? '~' + tokenEstimate.toLocaleString() : '-';
                }

                // Tools
                const tools = result.data.tools || [];
                if (tools.length > 0) {
                    document.getElementById('toolsSection').style.display = 'block';
                    document.getElementById('toolsCount').textContent = tools.length;

                    const toolsList = document.getElementById('toolsList');
                    toolsList.innerHTML = '';

                    tools.forEach(tool => {
                        const item = document.createElement('div');
                        item.className = 'list-group-item';
                        item.innerHTML = `
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <strong class="text-primary">${escapeHtml(tool.name)}</strong>
                                    ${tool.description ? `<p class="mb-0 small text-muted">${escapeHtml(tool.description)}</p>` : ''}
                                </div>
                            </div>
                            ${tool.inputSchema ? `<details class="mt-1"><summary class="small text-muted">Parameters</summary><pre class="small bg-light p-2 mt-1 mb-0">${escapeHtml(JSON.stringify(tool.inputSchema.properties || {}, null, 2))}</pre></details>` : ''}
                        `;
                        toolsList.appendChild(item);
                    });
                }
            } else {
                document.getElementById('testError').style.display = 'block';
                document.getElementById('testErrorMsg').textContent = result.message || 'Unknown error';
            }
        })
        .catch(err => {
            document.getElementById('testLoading').style.display = 'none';
            document.getElementById('testResults').style.display = 'block';
            document.getElementById('testError').style.display = 'block';
            document.getElementById('testErrorMsg').textContent = err.message || 'Network error';
        });
}
</script>
