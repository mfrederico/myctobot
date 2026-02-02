<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="/apps">Apps</a></li>
                    <li class="breadcrumb-item active"><?= $app ? 'Edit' : 'Create' ?></li>
                </ol>
            </nav>
            <h1 class="h2 mb-0">
                <i class="bi bi-box-seam"></i>
                <?= $app ? 'Edit App: ' . htmlspecialchars($app->name) : 'Create New App' ?>
            </h1>
        </div>
        <?php if ($app): ?>
        <div>
            <?php if ($app->status === 'running'): ?>
            <button type="button" class="btn btn-outline-danger me-2" onclick="stopApp(<?= $app->id ?>)">
                <i class="bi bi-stop-fill"></i> Stop
            </button>
            <?php else: ?>
            <button type="button" class="btn btn-success me-2" onclick="startApp(<?= $app->id ?>)">
                <i class="bi bi-play-fill"></i> Start
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-danger" onclick="deleteApp(<?= $app->id ?>)">
                <i class="bi bi-trash"></i> Delete
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($app && $app->status === 'running' && $app->port): ?>
    <div class="alert alert-success d-flex align-items-center mb-4">
        <i class="bi bi-check-circle-fill me-2"></i>
        <div>
            <strong>App is running</strong> on port <?= $app->port ?>.
            <a href="http://127.0.0.1:<?= $app->port ?>" target="_blank" class="alert-link">
                Open in browser <i class="bi bi-box-arrow-up-right"></i>
            </a>
        </div>
    </div>
    <?php elseif ($app && $app->error_message): ?>
    <div class="alert alert-danger mb-4">
        <strong><i class="bi bi-exclamation-triangle"></i> Error:</strong>
        <pre class="mb-0 mt-2 small"><?= htmlspecialchars($app->error_message) ?></pre>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-gear"></i> App Configuration
                </div>
                <div class="card-body">
                    <form method="POST" action="<?= $app ? '/apps/update/' . $app->id : '/apps/store' ?>">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">

                        <!-- Basic Info -->
                        <div class="mb-4">
                            <h6 class="text-muted mb-3"><i class="bi bi-info-circle"></i> Basic Information</h6>

                            <div class="mb-3">
                                <label for="name" class="form-label">App Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name"
                                       value="<?= htmlspecialchars($app->name ?? '') ?>" required>
                            </div>

                            <?php if (!$app): ?>
                            <div class="mb-3">
                                <label for="slug" class="form-label">Slug</label>
                                <input type="text" class="form-control" id="slug" name="slug"
                                       value="" placeholder="auto-generated-from-name">
                                <div class="form-text">URL-friendly identifier. Leave blank to auto-generate.</div>
                            </div>
                            <?php else: ?>
                            <div class="mb-3">
                                <label class="form-label">Slug</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($app->slug) ?>" disabled>
                                <div class="form-text">Slug cannot be changed after creation.</div>
                            </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="2"><?= htmlspecialchars($app->description ?? '') ?></textarea>
                            </div>
                        </div>

                        <!-- App Type -->
                        <div class="mb-4">
                            <h6 class="text-muted mb-3"><i class="bi bi-diagram-3"></i> App Type</h6>

                            <div class="mb-3">
                                <label for="app_type" class="form-label">Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="app_type" name="app_type" onchange="togglePipelineSelect()">
                                    <?php foreach ($appTypes as $type => $info): ?>
                                    <option value="<?= $type ?>"
                                            <?= ($app->app_type ?? 'pipeline') === $type ? 'selected' : '' ?>>
                                        <?= $info['label'] ?> - <?= $info['description'] ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3" id="pipeline_select_group">
                                <label for="pipeline_id" class="form-label">Pipeline <span class="text-danger">*</span></label>
                                <select class="form-select" id="pipeline_id" name="pipeline_id">
                                    <option value="">-- Select a pipeline --</option>
                                    <?php foreach ($pipelines as $p): ?>
                                    <option value="<?= $p['id'] ?>"
                                            <?= ($app->pipelines_id ?? null) == $p['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['name']) ?>
                                        <?php if ($p['description']): ?>
                                            - <?= htmlspecialchars(substr($p['description'], 0, 50)) ?>
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    The app will expose <code>POST /run</code> to execute this pipeline.
                                </div>
                            </div>
                        </div>

                        <!-- Authentication -->
                        <div class="mb-4">
                            <h6 class="text-muted mb-3"><i class="bi bi-shield-lock"></i> Authentication</h6>

                            <div class="mb-3">
                                <label for="auth_type" class="form-label">Auth Type</label>
                                <select class="form-select" id="auth_type" name="auth_type" onchange="toggleApiKeyInput()">
                                    <?php foreach ($authTypes as $type => $info): ?>
                                    <option value="<?= $type ?>"
                                            <?= ($app->auth_type ?? 'api_key') === $type ? 'selected' : '' ?>>
                                        <?= $info['label'] ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3" id="api_key_group">
                                <label for="api_key" class="form-label">API Key</label>
                                <div class="input-group">
                                    <input type="text" class="form-control font-monospace" id="api_key" name="api_key"
                                           value="<?= htmlspecialchars($app->api_key ?? '') ?>"
                                           placeholder="app_...">
                                    <button type="button" class="btn btn-outline-secondary" onclick="generateApiKey()">
                                        <i class="bi bi-arrow-repeat"></i> Generate
                                    </button>
                                </div>
                                <div class="form-text">
                                    Clients must provide via <code>X-API-Key</code> header or <code>api_key</code> query parameter.
                                </div>
                            </div>
                        </div>

                        <!-- Options -->
                        <div class="mb-4">
                            <h6 class="text-muted mb-3"><i class="bi bi-sliders"></i> Options</h6>

                            <div class="mb-3">
                                <label for="health_check_path" class="form-label">Health Check Path</label>
                                <input type="text" class="form-control" id="health_check_path" name="health_check_path"
                                       value="<?= htmlspecialchars($app->health_check_path ?? '/health') ?>">
                            </div>

                            <div class="form-check mb-2">
                                <input type="checkbox" class="form-check-input" id="auto_restart" name="auto_restart" value="1"
                                       <?= !empty($app->auto_restart) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="auto_restart">
                                    <strong>Auto-restart</strong> - Automatically restart if the server crashes
                                </label>
                            </div>

                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="expose_mcp" name="expose_mcp" value="1"
                                       <?= !empty($app->expose_mcp) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="expose_mcp">
                                    <strong>Expose as MCP Server</strong> - Add /mcp endpoint for MCP protocol
                                </label>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg"></i> <?= $app ? 'Save Changes' : 'Create App' ?>
                            </button>
                            <a href="/apps" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <?php if ($app): ?>
            <!-- Status Card -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-activity"></i> Status
                </div>
                <div class="card-body">
                    <dl class="mb-0">
                        <dt>Status</dt>
                        <dd>
                            <span class="badge bg-<?= $app->status === 'running' ? 'success' : ($app->status === 'error' ? 'danger' : 'secondary') ?>">
                                <?= ucfirst($app->status) ?>
                            </span>
                        </dd>

                        <?php if ($app->port): ?>
                        <dt>Port</dt>
                        <dd><code><?= $app->port ?></code></dd>
                        <?php endif; ?>

                        <?php if ($app->last_started_at): ?>
                        <dt>Last Started</dt>
                        <dd class="small text-muted"><?= $app->last_started_at ?></dd>
                        <?php endif; ?>

                        <?php if ($app->last_stopped_at): ?>
                        <dt>Last Stopped</dt>
                        <dd class="small text-muted"><?= $app->last_stopped_at ?></dd>
                        <?php endif; ?>

                        <dt>Created</dt>
                        <dd class="small text-muted"><?= $app->created_at ?></dd>
                    </dl>
                </div>
            </div>

            <!-- Endpoints Card -->
            <?php if ($app->status === 'running' && $app->port): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-link-45deg"></i> Endpoints
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-2">Base URL:</p>
                    <code class="d-block bg-light p-2 rounded mb-3">http://127.0.0.1:<?= $app->port ?></code>

                    <table class="table table-sm small mb-0">
                        <tr>
                            <td><code>GET /</code></td>
                            <td>App info</td>
                        </tr>
                        <tr>
                            <td><code>GET /health</code></td>
                            <td>Health check</td>
                        </tr>
                        <?php if ($app->app_type === 'pipeline' && $app->pipelines_id): ?>
                        <tr>
                            <td><code>POST /run</code></td>
                            <td>Execute pipeline</td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($app->expose_mcp): ?>
                        <tr>
                            <td><code>POST /mcp</code></td>
                            <td>MCP JSON-RPC</td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-lightning"></i> Quick Actions
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-outline-secondary" onclick="showLogs(<?= $app->id ?>, '<?= htmlspecialchars($app->name) ?>')">
                            <i class="bi bi-terminal"></i> View Logs
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="checkStatus(<?= $app->id ?>)">
                            <i class="bi bi-heart-pulse"></i> Check Status
                        </button>
                        <?php if ($app->app_type === 'pipeline' && $app->pipelines_id): ?>
                        <a href="/pipelines/edit/<?= $app->pipelines_id ?>" class="btn btn-outline-primary">
                            <i class="bi bi-diagram-3"></i> Edit Pipeline
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- Help Card for new apps -->
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-lightbulb"></i> Getting Started
                </div>
                <div class="card-body small">
                    <ol class="mb-0">
                        <li class="mb-2">Enter a name for your app</li>
                        <li class="mb-2">Select a pipeline to expose</li>
                        <li class="mb-2">Configure authentication (API key recommended)</li>
                        <li class="mb-2">Click "Create App"</li>
                        <li>Start the app and test with <code>curl</code></li>
                    </ol>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Logs Modal -->
<div class="modal fade" id="logsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-terminal"></i> App Logs: <span id="logsAppName"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre id="logsContent" class="bg-dark text-light p-3 rounded" style="height: 400px; overflow-y: auto; font-size: 12px;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="refreshLogs()">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Status Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-heart-pulse"></i> App Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre id="statusContent" class="bg-light p-3 rounded"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentLogsAppId = null;

function togglePipelineSelect() {
    const appType = document.getElementById('app_type').value;
    const group = document.getElementById('pipeline_select_group');
    group.style.display = appType === 'pipeline' ? 'block' : 'none';
}

function toggleApiKeyInput() {
    const authType = document.getElementById('auth_type').value;
    const group = document.getElementById('api_key_group');
    group.style.display = authType === 'api_key' ? 'block' : 'none';
}

async function generateApiKey() {
    try {
        const response = await fetch('/apps/generatekey');
        const data = await response.json();
        if (data.success && data.data && data.data.api_key) {
            document.getElementById('api_key').value = data.data.api_key;
        }
    } catch (e) {
        alert('Error generating API key: ' + e.message);
    }
}

async function startApp(id) {
    try {
        const response = await fetch(`/apps/start/${id}`, { method: 'POST' });
        const data = await response.json();
        if (data.success) {
            location.reload();
        } else {
            alert('Failed to start app: ' + (data.message || 'Unknown error'));
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function stopApp(id) {
    if (!confirm('Stop this app?')) return;
    try {
        const response = await fetch(`/apps/stop/${id}`, { method: 'POST' });
        const data = await response.json();
        if (data.success) {
            location.reload();
        } else {
            alert('Failed to stop app: ' + (data.message || 'Unknown error'));
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function deleteApp(id) {
    if (!confirm('Delete this app? This cannot be undone.')) return;
    try {
        const response = await fetch(`/apps/delete/${id}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'csrf_token=' + encodeURIComponent('<?= $_SESSION['csrf_token'] ?? '' ?>')
        });
        const data = await response.json();
        if (data.success) {
            window.location.href = '/apps';
        } else {
            alert('Failed to delete app: ' + (data.message || 'Unknown error'));
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function showLogs(id, name) {
    currentLogsAppId = id;
    document.getElementById('logsAppName').textContent = name;
    document.getElementById('logsContent').textContent = 'Loading...';

    const modal = new bootstrap.Modal(document.getElementById('logsModal'));
    modal.show();

    await refreshLogs();
}

async function refreshLogs() {
    if (!currentLogsAppId) return;
    try {
        const response = await fetch(`/apps/logs/${currentLogsAppId}?lines=200`);
        const data = await response.json();
        if (data.success && data.data) {
            const logs = data.data.logs || {};
            let content = '';
            if (logs.tmux && logs.tmux.length > 0) {
                content += '=== Console Output ===\n' + logs.tmux.join('\n') + '\n\n';
            }
            if (logs.file && logs.file.length > 0) {
                content += '=== File Logs ===\n' + logs.file.join('\n');
            }
            document.getElementById('logsContent').textContent = content || '(No logs available)';
        } else {
            document.getElementById('logsContent').textContent = 'Failed to load logs';
        }
    } catch (e) {
        document.getElementById('logsContent').textContent = 'Error: ' + e.message;
    }
}

async function checkStatus(id) {
    try {
        const response = await fetch(`/apps/status/${id}`);
        const data = await response.json();
        document.getElementById('statusContent').textContent = JSON.stringify(data.data || data, null, 2);
        const modal = new bootstrap.Modal(document.getElementById('statusModal'));
        modal.show();
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Auto-generate slug from app name
function setupSlugGeneration() {
    const nameField = document.getElementById('name');
    const slugField = document.getElementById('slug');

    // Only set up for new apps (slug field is editable)
    if (!nameField || !slugField || slugField.disabled) return;

    nameField.addEventListener('input', function() {
        // Only auto-generate if user hasn't manually edited the slug
        if (!slugField.dataset.userEdited) {
            slugField.value = this.value
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-|-$/g, '');
        }
    });

    slugField.addEventListener('input', function() {
        // Mark as user-edited once they type in it
        this.dataset.userEdited = 'true';
    });
}

// Initialize on load
document.addEventListener('DOMContentLoaded', function() {
    togglePipelineSelect();
    toggleApiKeyInput();
    setupSlugGeneration();
});
</script>
