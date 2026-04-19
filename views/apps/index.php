<?php
// Pre-compute counts for deployment apps
$screenCounts = [];
$pipelineCounts = [];
foreach ($apps as $a) {
    $screenCounts[$a['id']] = (int) ($a['screen_count'] ?? 0);
    $pipelineCounts[$a['id']] = (int) ($a['pipeline_count'] ?? 0);
}
?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi bi-box-seam"></i> Apps
            </h1>
            <p class="text-muted mb-0">Local services, Shopify apps, Docker containers, and more</p>
        </div>
        <div class="btn-group">
            <a href="/apps/form" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> New App
            </a>
            <button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="visually-hidden">Toggle Dropdown</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="/apps/form"><i class="bi bi-hdd-stack me-2"></i>Local Service App</a></li>
                <li><a class="dropdown-item" href="/apps/templates"><i class="bi bi-grid-3x3-gap me-2"></i>From Template</a></li>
                <li><a class="dropdown-item" href="/apps/form?target=docker"><i class="bi bi-box-seam me-2"></i>Docker App</a></li>
                <li><a class="dropdown-item" href="/apps/form?target=shopify"><i class="bi bi-shop me-2"></i>Shopify App</a></li>
            </ul>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="d-flex align-items-center gap-3 mb-4">
        <label class="form-label mb-0 small text-muted">Filter:</label>
        <select class="form-select form-select-sm" style="width: auto;" id="appFilter" onchange="filterApps(this.value)">
            <option value="all">All Apps</option>
            <option value="local">Local Service</option>
            <option value="docker">Docker</option>
            <option value="shopify">Shopify</option>
            <option value="static">Static</option>
            <option value="hosted">Hosted</option>
        </select>
        <span class="text-muted small" id="appCount"><?= count($apps) ?> app<?= count($apps) !== 1 ? 's' : '' ?></span>
    </div>

    <?php if (empty($apps)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-box-seam display-4 text-muted"></i>
            <h4 class="mt-3">No apps yet</h4>
            <p class="text-muted mb-4" style="max-width: 500px; margin: 0 auto;">
                Create your first app -- a local HTTP service, Shopify app, Docker container, or static site.
            </p>
            <a href="/apps/form" class="btn btn-primary btn-lg">
                <i class="bi bi-plus-lg"></i> Create Your First App
            </a>
        </div>
    </div>
    <?php else: ?>
    <div class="row" id="appGrid">
        <?php foreach ($apps as $app):
            $deployTarget = $app['deployment_target'] ?? '';
            if (empty($deployTarget)) {
                $deployTarget = 'local';
            }
            $isLocal = ($deployTarget === 'local');
            $isShopify = ($deployTarget === 'shopify');
            $isDocker = ($deployTarget === 'docker');
            $isStatic = ($deployTarget === 'static');
            $isHosted = !empty($app['is_hosted']);

            // Deployment target badge config
            $targetBadge = match($deployTarget) {
                'local'   => ['bg-info text-dark', 'bi-hdd-stack', 'Local'],
                'docker'  => ['bg-warning text-dark', 'bi-box-seam', 'Docker'],
                'shopify' => ['bg-success', 'bi-shop', 'Shopify'],
                'static'  => ['bg-secondary', 'bi-file-earmark-code', 'Static'],
                'hosted'  => ['bg-primary', 'bi-globe2', 'Hosted'],
                default   => ['bg-secondary', 'bi-box', ucfirst($deployTarget)],
            };
        ?>
        <div class="col-md-6 col-lg-4 mb-4 app-card-wrapper" data-deploy-target="<?= h($deployTarget) ?>" data-hosted="<?= $isHosted ? '1' : '0' ?>">
            <div class="card h-100" id="app-card-<?= $app['id'] ?>">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>
                        <?php if ($isLocal): ?>
                            <?php
                            $statusIcon = match($app['status'] ?? '') {
                                'running' => '<span class="text-success"><i class="bi bi-circle-fill"></i></span>',
                                'starting' => '<span class="text-warning"><i class="bi bi-circle-fill"></i></span>',
                                'error', 'crashed' => '<span class="text-danger"><i class="bi bi-circle-fill"></i></span>',
                                default => '<span class="text-secondary"><i class="bi bi-circle"></i></span>',
                            };
                            echo $statusIcon;
                            ?>
                        <?php else: ?>
                            <i class="bi bi-<?= $targetBadge[1] ?> text-primary"></i>
                        <?php endif; ?>
                        <strong class="ms-1"><?= h($app['name']) ?></strong>
                    </span>
                    <span class="d-flex align-items-center gap-1">
                        <!-- Deployment target badge -->
                        <span class="badge <?= $targetBadge[0] ?>">
                            <i class="bi <?= $targetBadge[1] ?>"></i> <?= $targetBadge[2] ?>
                        </span>
                        <?php if ($isLocal && !empty($app['status'])): ?>
                        <span class="badge bg-<?= ($app['status'] ?? '') === 'running' ? 'success' : (($app['status'] ?? '') === 'error' ? 'danger' : 'secondary') ?>">
                            <?= ucfirst($app['status'] ?? 'stopped') ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($isHosted): ?>
                        <span class="badge bg-primary" title="Hosted mode enabled">
                            <i class="bi bi-globe2"></i>
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($app['current_version'])): ?>
                        <span class="badge bg-primary"><?= h($app['current_version']) ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="card-body">
                    <?php if (!empty($app['description'])): ?>
                    <p class="card-text text-muted small mb-2"><?= h($app['description']) ?></p>
                    <?php endif; ?>

                    <?php if ($isLocal): ?>
                        <!-- Local service app details -->
                        <div class="mb-3">
                            <?php if (!empty($app['app_type'])): ?>
                            <small class="text-muted d-block">
                                <i class="bi bi-<?= $appTypes[$app['app_type']]['icon'] ?? 'box' ?>"></i>
                                <?= $appTypes[$app['app_type']]['label'] ?? $app['app_type'] ?>
                                <?php if (!empty($app['pipeline_name'])): ?>
                                    &rarr; <?= h($app['pipeline_name']) ?>
                                <?php endif; ?>
                            </small>
                            <?php endif; ?>
                            <?php if (!empty($app['auth_type'])): ?>
                            <small class="text-muted d-block">
                                <i class="bi bi-<?= $authTypes[$app['auth_type']]['icon'] ?? 'lock' ?>"></i>
                                <?= $authTypes[$app['auth_type']]['label'] ?? $app['auth_type'] ?>
                            </small>
                            <?php endif; ?>
                        </div>

                        <?php if (($app['status'] ?? '') === 'running' && !empty($app['port'])): ?>
                        <div class="mb-3">
                            <code class="bg-light px-2 py-1 rounded">Port <?= $app['port'] ?></code>
                            <?php if (!empty($app['url'])): ?>
                            <a href="<?= h($app['url']) ?>" target="_blank" class="ms-2 small" title="Direct port access">
                                <i class="bi bi-box-arrow-up-right"></i> Direct
                            </a>
                            <?php endif; ?>
                            <a href="/app/<?= h($app['slug']) ?>" target="_blank" class="ms-2 small text-success" title="Public proxy URL">
                                <i class="bi bi-globe"></i> Public
                            </a>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($app['auto_restart'])): ?>
                        <span class="badge bg-info text-dark me-1"><i class="bi bi-arrow-repeat"></i> Auto-restart</span>
                        <?php endif; ?>
                        <?php if (!empty($app['expose_mcp'])): ?>
                        <span class="badge bg-purple text-white"><i class="bi bi-plug"></i> MCP</span>
                        <?php endif; ?>

                    <?php else: ?>
                        <!-- Deployment app details (Docker, Shopify, etc.) -->
                        <?php
                        $buildStatus = $app['build_status'] ?? 'idle';
                        $statusClass = match ($buildStatus) {
                            'building' => 'bg-warning text-dark',
                            'failed'   => 'bg-danger',
                            'complete', 'success' => 'bg-success',
                            default    => 'bg-secondary',
                        };
                        $statusIcon = match ($buildStatus) {
                            'building' => 'bi-arrow-repeat',
                            'failed'   => 'bi-x-circle',
                            'complete', 'success' => 'bi-check-circle',
                            default    => 'bi-circle',
                        };
                        ?>
                        <div class="mb-3">
                            <span class="badge <?= $statusClass ?>">
                                <i class="bi <?= $statusIcon ?>"></i>
                                Build: <?= h(ucfirst($buildStatus)) ?>
                            </span>
                            <?php if (!empty($app['template_slug'])): ?>
                            <span class="badge bg-info ms-1" title="Created from template">
                                <i class="bi bi-grid-3x3-gap"></i> Template
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($app['git_repo_url'])): ?>
                            <span class="badge bg-dark ms-1" title="Git repository connected">
                                <i class="bi bi-git"></i> Git
                            </span>
                            <?php endif; ?>
                        </div>

                        <div class="row text-center border-top pt-2">
                            <div class="col-6">
                                <div class="small text-muted">Screens</div>
                                <div class="fw-bold"><?= $screenCounts[$app['id']] ?? 0 ?></div>
                            </div>
                            <div class="col-6">
                                <div class="small text-muted">Pipelines</div>
                                <div class="fw-bold"><?= $pipelineCounts[$app['id']] ?? 0 ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-transparent">
                    <?php if ($isLocal): ?>
                        <!-- Local service controls -->
                        <div class="btn-group btn-group-sm w-100">
                            <?php if (($app['status'] ?? '') === 'running'): ?>
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
                            <button type="button" class="btn btn-outline-secondary" onclick="showLogs(<?= $app['id'] ?>, '<?= h(addslashes($app['name'])) ?>')" title="Logs">
                                <i class="bi bi-terminal"></i>
                            </button>
                            <a href="/apps/form/<?= $app['id'] ?>" class="btn btn-outline-primary" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php $cfg = json_decode($app['config_json'] ?? '{}', true) ?: [];
                            if (($cfg['build_target'] ?? '') === 'static'): ?>
                            <a href="/apps/download/<?= $app['id'] ?>" class="btn btn-outline-info" title="Download Static App">
                                <i class="bi bi-download"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <!-- Deployment app controls -->
                        <div class="d-flex flex-wrap gap-1">
                            <?php if ($isHosted): ?>
                            <a href="/dapp/<?= h($app['slug']) ?>" class="btn btn-success btn-sm" title="View hosted app" target="_blank">
                                <i class="bi bi-box-arrow-up-right"></i> View
                            </a>
                            <?php endif; ?>
                            <a href="/apps/form/<?= (int) $app['id'] ?>" class="btn btn-outline-primary btn-sm" title="Edit settings">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            <a href="/apps/screens/<?= (int) $app['id'] ?>" class="btn btn-outline-secondary btn-sm" title="Manage screens">
                                <i class="bi bi-window-stack"></i> Screens
                            </a>
                            <a href="/apps/pipelines/<?= (int) $app['id'] ?>" class="btn btn-outline-secondary btn-sm" title="Bind pipelines">
                                <i class="bi bi-diagram-3"></i> Pipelines
                            </a>
                            <?php if ($isShopify): ?>
                            <a href="/apps/liquidfiles/<?= (int) $app['id'] ?>" class="btn btn-outline-success btn-sm" title="Shopify theme files">
                                <i class="bi bi-braces"></i> Shopify
                            </a>
                            <?php endif; ?>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-outline-warning btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Build options">
                                    <i class="bi bi-hammer"></i> Build
                                </button>
                                <ul class="dropdown-menu">
                                    <?php if ($isDocker || (!$isShopify && !$isStatic)): ?>
                                    <li>
                                        <form method="POST" action="/apps/build/<?= (int) $app['id'] ?>" onsubmit="return confirm('Start Docker build for <?= h(addslashes($app['name'])) ?>?')">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                            <button type="submit" class="dropdown-item">
                                                <i class="bi bi-box-seam me-2"></i>Docker Build
                                            </button>
                                        </form>
                                    </li>
                                    <?php endif; ?>
                                    <?php if ($isShopify || (!$isDocker && !$isStatic)): ?>
                                    <li>
                                        <a class="dropdown-item" href="/apps/buildshopify/<?= (int) $app['id'] ?>">
                                            <i class="bi bi-shop me-2"></i>Shopify Build
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            <a href="/apps/releases/<?= (int) $app['id'] ?>" class="btn btn-outline-secondary btn-sm" title="Release history">
                                <i class="bi bi-tag"></i>
                            </a>
                            <a href="/apps/download/<?= (int) $app['id'] ?>" class="btn btn-outline-info btn-sm" title="Download build">
                                <i class="bi bi-download"></i>
                            </a>
                        </div>
                    <?php endif; ?>
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

// --- Filter functionality ---
function filterApps(value) {
    const cards = document.querySelectorAll('.app-card-wrapper');
    let visibleCount = 0;

    cards.forEach(card => {
        const target = card.dataset.deployTarget;
        const hosted = card.dataset.hosted === '1';
        let show = false;

        switch (value) {
            case 'all':
                show = true;
                break;
            case 'local':
                show = (target === 'local');
                break;
            case 'docker':
                show = (target === 'docker');
                break;
            case 'shopify':
                show = (target === 'shopify');
                break;
            case 'static':
                show = (target === 'static');
                break;
            case 'hosted':
                show = hosted;
                break;
        }

        card.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });

    document.getElementById('appCount').textContent = visibleCount + ' app' + (visibleCount !== 1 ? 's' : '');
}

// Auto-select filter from URL ?target= param
(function() {
    const params = new URLSearchParams(window.location.search);
    const target = params.get('target');
    if (target) {
        const select = document.getElementById('appFilter');
        if (select) {
            select.value = target;
            filterApps(target);
        }
    }
})();

// --- Local service app controls ---
async function startApp(id) {
    const card = document.getElementById(`app-card-${id}`);
    const badges = card.querySelectorAll('.card-header .badge');
    const statusBadge = Array.from(badges).find(b => b.textContent.trim().match(/^(Running|Stopped|Error|Crashed|Starting)$/i));
    if (statusBadge) {
        statusBadge.className = 'badge bg-warning';
        statusBadge.textContent = 'Starting...';
    }

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
    const badges = card.querySelectorAll('.card-header .badge');
    const statusBadge = Array.from(badges).find(b => b.textContent.trim().match(/^(Running|Stopped|Error|Crashed|Starting)$/i));
    if (statusBadge) {
        statusBadge.className = 'badge bg-warning';
        statusBadge.textContent = 'Stopping...';
    }

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
    const badges = card.querySelectorAll('.card-header .badge');
    const statusBadge = Array.from(badges).find(b => b.textContent.trim().match(/^(Running|Stopped|Error|Crashed|Starting)$/i));
    if (statusBadge) {
        statusBadge.className = 'badge bg-warning';
        statusBadge.textContent = 'Restarting...';
    }

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

// --- Logs modal ---
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

// --- Auto-refresh status for running local apps ---
(function() {
    const localCards = document.querySelectorAll('.app-card-wrapper[data-deploy-target="local"]');
    if (localCards.length === 0) return;

    // Refresh status every 30 seconds
    setInterval(() => {
        localCards.forEach(card => {
            const appId = card.querySelector('.card')?.id?.replace('app-card-', '');
            if (!appId) return;

            fetch(`/apps/status/${appId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.data) {
                        const status = data.data.status || 'stopped';
                        const badges = card.querySelectorAll('.card-header .badge');
                        const statusBadge = Array.from(badges).find(b =>
                            b.textContent.trim().match(/^(Running|Stopped|Error|Crashed|Starting|Stopping|Restarting)$/i)
                        );
                        if (statusBadge) {
                            statusBadge.className = 'badge bg-' + (status === 'running' ? 'success' : (status === 'error' ? 'danger' : 'secondary'));
                            statusBadge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
                        }
                    }
                })
                .catch(() => {}); // Silently ignore status check failures
        });
    }, 30000);
})();
</script>
