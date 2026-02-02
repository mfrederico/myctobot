<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi bi-box-seam"></i> Tenant Apps
            </h1>
            <p class="text-muted mb-0">Standalone HTTP services backed by pipelines</p>
        </div>
        <div>
            <a href="/apps/form" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> New App
            </a>
        </div>
    </div>

    <!-- Info Card -->
    <div class="card bg-light border-0 mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h5 class="card-title mb-3">
                        <i class="bi bi-question-circle text-primary"></i> What are Tenant Apps?
                    </h5>
                    <p class="mb-2">
                        <strong>Apps are standalone HTTP servers</strong> that run on dedicated ports and can:
                    </p>
                    <ul class="mb-2">
                        <li><strong>Expose pipelines</strong> - Execute pipelines via HTTP POST /run endpoint</li>
                        <li><strong>Custom authentication</strong> - None, API key, or Bearer token</li>
                        <li><strong>Auto-restart</strong> - Automatically restart if the server crashes</li>
                        <li><strong>Health checks</strong> - Built-in /health endpoint for monitoring</li>
                    </ul>
                    <div class="alert alert-info py-2 px-3 mb-0 small">
                        <i class="bi bi-info-circle"></i> Apps run on ports 9600-9699 and can later be migrated to Proxmox containers.
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="bg-white rounded p-3 border">
                        <h6 class="text-muted mb-3"><i class="bi bi-shield-check"></i> Auth Options</h6>
                        <?php foreach ($authTypes as $type => $info): ?>
                        <div class="d-flex align-items-start mb-2">
                            <i class="bi <?= $info['icon'] ?> text-primary me-2 mt-1"></i>
                            <div>
                                <strong><?= $info['label'] ?></strong>
                                <small class="d-block text-muted"><?= $info['description'] ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($apps)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-box-seam display-4 text-muted"></i>
            <h4 class="mt-3">No apps yet</h4>
            <p class="text-muted mb-4" style="max-width: 500px; margin: 0 auto;">
                Create your first app to expose a pipeline as an HTTP endpoint.
            </p>
            <a href="/apps/form" class="btn btn-primary btn-lg">
                <i class="bi bi-plus-lg"></i> Create Your First App
            </a>
        </div>
    </div>
    <?php else: ?>
    <div class="row">
        <?php foreach ($apps as $app): ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100" id="app-card-<?= $app['id'] ?>">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>
                        <?php
                        $statusIcon = match($app['status']) {
                            'running' => '<span class="text-success"><i class="bi bi-circle-fill"></i></span>',
                            'starting' => '<span class="text-warning"><i class="bi bi-circle-fill"></i></span>',
                            'error', 'crashed' => '<span class="text-danger"><i class="bi bi-circle-fill"></i></span>',
                            default => '<span class="text-secondary"><i class="bi bi-circle"></i></span>',
                        };
                        echo $statusIcon;
                        ?>
                        <strong class="ms-1"><?= htmlspecialchars($app['name']) ?></strong>
                    </span>
                    <span class="badge bg-<?= $app['status'] === 'running' ? 'success' : ($app['status'] === 'error' ? 'danger' : 'secondary') ?>">
                        <?= ucfirst($app['status']) ?>
                    </span>
                </div>
                <div class="card-body">
                    <?php if ($app['description']): ?>
                    <p class="card-text text-muted small mb-2"><?= htmlspecialchars($app['description']) ?></p>
                    <?php endif; ?>

                    <div class="mb-3">
                        <small class="text-muted d-block">
                            <i class="bi bi-<?= $appTypes[$app['app_type']]['icon'] ?? 'box' ?>"></i>
                            <?= $appTypes[$app['app_type']]['label'] ?? $app['app_type'] ?>
                            <?php if ($app['pipeline_name']): ?>
                                &rarr; <?= htmlspecialchars($app['pipeline_name']) ?>
                            <?php endif; ?>
                        </small>
                        <small class="text-muted d-block">
                            <i class="bi bi-<?= $authTypes[$app['auth_type']]['icon'] ?? 'lock' ?>"></i>
                            <?= $authTypes[$app['auth_type']]['label'] ?? $app['auth_type'] ?>
                        </small>
                    </div>

                    <?php if ($app['status'] === 'running' && $app['port']): ?>
                    <div class="mb-3">
                        <code class="bg-light px-2 py-1 rounded">Port <?= $app['port'] ?></code>
                        <?php if ($app['url']): ?>
                        <a href="<?= htmlspecialchars($app['url']) ?>" target="_blank" class="ms-2 small">
                            <i class="bi bi-box-arrow-up-right"></i> Open
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($app['auto_restart']): ?>
                    <span class="badge bg-info text-dark me-1"><i class="bi bi-arrow-repeat"></i> Auto-restart</span>
                    <?php endif; ?>
                    <?php if ($app['expose_mcp']): ?>
                    <span class="badge bg-purple text-white"><i class="bi bi-plug"></i> MCP</span>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-transparent">
                    <div class="btn-group btn-group-sm w-100">
                        <?php if ($app['status'] === 'running'): ?>
                        <button type="button" class="btn btn-outline-danger" onclick="stopApp(<?= $app['id'] ?>)" title="Stop">
                            <i class="bi bi-stop-fill"></i> Stop
                        </button>
                        <button type="button" class="btn btn-outline-warning" onclick="restartApp(<?= $app['id'] ?>)" title="Restart">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                        <?php else: ?>
                        <button type="button" class="btn btn-outline-success" onclick="startApp(<?= $app['id'] ?>)" title="Start">
                            <i class="bi bi-play-fill"></i> Start
                        </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline-secondary" onclick="showLogs(<?= $app['id'] ?>, '<?= htmlspecialchars($app['name']) ?>')" title="Logs">
                            <i class="bi bi-terminal"></i>
                        </button>
                        <a href="/apps/form/<?= $app['id'] ?>" class="btn btn-outline-primary" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
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
                <ul class="nav nav-tabs" id="logsTabs">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tmuxLogs">
                            <i class="bi bi-terminal"></i> Console Output
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#fileLogs">
                            <i class="bi bi-file-text"></i> File Logs
                        </button>
                    </li>
                </ul>
                <div class="tab-content mt-3">
                    <div class="tab-pane fade show active" id="tmuxLogs">
                        <pre id="tmuxLogsContent" class="bg-dark text-light p-3 rounded" style="height: 400px; overflow-y: auto; font-size: 12px;"></pre>
                    </div>
                    <div class="tab-pane fade" id="fileLogs">
                        <pre id="fileLogsContent" class="bg-dark text-light p-3 rounded" style="height: 400px; overflow-y: auto; font-size: 12px;"></pre>
                    </div>
                </div>
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

<script>
let currentLogsAppId = null;

async function startApp(id) {
    const card = document.getElementById(`app-card-${id}`);
    const badge = card.querySelector('.badge');
    badge.className = 'badge bg-warning';
    badge.textContent = 'Starting...';

    try {
        const response = await fetch(`/apps/start/${id}`, { method: 'POST' });
        const data = await response.json();

        if (data.success) {
            location.reload();
        } else {
            alert('Failed to start app: ' + (data.message || 'Unknown error'));
            location.reload();
        }
    } catch (e) {
        alert('Error: ' + e.message);
        location.reload();
    }
}

async function stopApp(id) {
    if (!confirm('Stop this app?')) return;

    const card = document.getElementById(`app-card-${id}`);
    const badge = card.querySelector('.badge');
    badge.className = 'badge bg-warning';
    badge.textContent = 'Stopping...';

    try {
        const response = await fetch(`/apps/stop/${id}`, { method: 'POST' });
        const data = await response.json();

        if (data.success) {
            location.reload();
        } else {
            alert('Failed to stop app: ' + (data.message || 'Unknown error'));
            location.reload();
        }
    } catch (e) {
        alert('Error: ' + e.message);
        location.reload();
    }
}

async function restartApp(id) {
    if (!confirm('Restart this app?')) return;

    const card = document.getElementById(`app-card-${id}`);
    const badge = card.querySelector('.badge');
    badge.className = 'badge bg-warning';
    badge.textContent = 'Restarting...';

    try {
        const response = await fetch(`/apps/restart/${id}`, { method: 'POST' });
        const data = await response.json();

        if (data.success) {
            location.reload();
        } else {
            alert('Failed to restart app: ' + (data.message || 'Unknown error'));
            location.reload();
        }
    } catch (e) {
        alert('Error: ' + e.message);
        location.reload();
    }
}

async function showLogs(id, name) {
    currentLogsAppId = id;
    document.getElementById('logsAppName').textContent = name;
    document.getElementById('tmuxLogsContent').textContent = 'Loading...';
    document.getElementById('fileLogsContent').textContent = 'Loading...';

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

            // Tmux logs
            const tmuxLogs = logs.tmux || [];
            document.getElementById('tmuxLogsContent').textContent =
                tmuxLogs.length > 0 ? tmuxLogs.join('\n') : '(No console output)';

            // File logs
            const fileLogs = logs.file || [];
            document.getElementById('fileLogsContent').textContent =
                fileLogs.length > 0 ? fileLogs.join('\n') : '(No file logs)';
        } else {
            document.getElementById('tmuxLogsContent').textContent = 'Failed to load logs';
            document.getElementById('fileLogsContent').textContent = 'Failed to load logs';
        }
    } catch (e) {
        document.getElementById('tmuxLogsContent').textContent = 'Error: ' + e.message;
        document.getElementById('fileLogsContent').textContent = 'Error: ' + e.message;
    }
}
</script>
