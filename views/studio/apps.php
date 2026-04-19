<div class="container py-4" data-assist-page="studio/apps" data-assist-purpose="App studio for visual app development">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi <?= $studio['icon'] ?? 'bi-box-seam' ?> text-<?= $studio['color'] ?? 'info' ?>"></i>
                <?= h($studio['name'] ?? 'App Studio') ?>
            </h1>
            <p class="text-muted mb-0"><?= h($studio['tagline'] ?? '') ?></p>
        </div>
        <div class="dropdown">
            <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-arrow-left-right"></i> Switch Studio
            </button>
            <ul class="dropdown-menu dropdown-menu-end" style="min-width: 240px;">
                <?php foreach ($studios as $key => $s): ?>
                <li>
                    <a class="dropdown-item py-2 <?= $key === $studioKey ? 'active' : '' ?>" href="/studio/<?= $key ?>">
                        <div class="d-flex align-items-start">
                            <i class="bi <?= $s['icon'] ?> me-2 mt-1"></i>
                            <div>
                                <div class="fw-semibold"><?= h($s['shortName'] ?? $s['name'] ?? '') ?></div>
                                <small class="<?= $key === $studioKey ? 'text-light opacity-75' : 'text-muted' ?>"><?= h($s['description'] ?? '') ?></small>
                            </div>
                        </div>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <a href="/apps/form" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded bg-info bg-opacity-10 p-3 me-3">
                        <i class="bi bi-plus-lg fs-4 text-info"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">New App</div>
                        <small class="text-muted">Create a microservice</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="/pipelines/form" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded bg-warning bg-opacity-10 p-3 me-3">
                        <i class="bi bi-diagram-3 fs-4 text-warning"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">New Pipeline</div>
                        <small class="text-muted">Create workflow logic</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="/studio/pipeline" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded bg-warning bg-opacity-10 p-3 me-3">
                        <i class="bi bi-arrow-left-right fs-4 text-warning"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">Pipeline Studio</div>
                        <small class="text-muted">Manage workflows</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="/apps/form?app_type=builder" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded bg-success bg-opacity-10 p-3 me-3">
                        <i class="bi bi-bricks fs-4 text-success"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">App Builder</div>
                        <small class="text-muted">Visual block builder</small>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="row">
        <!-- Apps List -->
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-box-seam"></i> Your Apps</h5>
                    <a href="/apps" class="btn btn-sm btn-outline-info">Manage All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($apps)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-box display-4 text-muted"></i>
                        <p class="text-muted mt-3 mb-0">No apps yet. Create your first microservice!</p>
                        <a href="/apps/form" class="btn btn-info mt-3">
                            <i class="bi bi-plus-lg"></i> New App
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>App</th>
                                    <th>Pipeline</th>
                                    <th>Status</th>
                                    <th>Port</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($apps as $app): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= h($app['name']) ?></div>
                                        <small class="text-muted"><?= h($app['slug']) ?></small>
                                    </td>
                                    <td>
                                        <?php if ($app['pipeline_name']): ?>
                                        <a href="/pipelines/form/<?= $app['pipeline_id'] ?>" class="text-decoration-none">
                                            <i class="bi bi-diagram-3 text-warning"></i>
                                            <?= h($app['pipeline_name']) ?>
                                        </a>
                                        <?php else: ?>
                                        <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($app['status'] === 'running'): ?>
                                        <span class="badge bg-success"><i class="bi bi-circle-fill small"></i> Running</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary"><i class="bi bi-circle"></i> Stopped</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($app['port']): ?>
                                        <code><?= $app['port'] ?></code>
                                        <?php else: ?>
                                        <span class="text-muted">Auto</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="/app/<?= h($app['slug']) ?>" target="_blank" class="btn btn-sm btn-outline-success me-1" title="Open App">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                        <?php if (($app['app_type'] ?? '') === 'builder'): ?>
                                        <a href="/appbuilder/index/<?= $app['id'] ?>" class="btn btn-sm btn-outline-info me-1" title="Open Builder">
                                            <i class="bi bi-bricks"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a href="/apps/form/<?= $app['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Stats -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-bar-chart"></i> Overview</h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-4">
                            <div class="h3 mb-0 text-info"><?= $totalApps ?></div>
                            <small class="text-muted">Total Apps</small>
                        </div>
                        <div class="col-4">
                            <div class="h3 mb-0 text-success"><?= $runningCount ?></div>
                            <small class="text-muted">Running</small>
                        </div>
                        <div class="col-4">
                            <div class="h3 mb-0 text-secondary"><?= $stoppedCount ?></div>
                            <small class="text-muted">Stopped</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Available Pipelines -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-diagram-3 text-warning"></i> Available Pipelines</h6>
                    <a href="/pipelines" class="btn btn-sm btn-outline-secondary">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($pipelines)): ?>
                    <div class="text-center py-4">
                        <p class="text-muted mb-0 small">No pipelines available.</p>
                        <a href="/pipelines/form" class="btn btn-sm btn-outline-warning mt-2">Create Pipeline</a>
                    </div>
                    <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach (array_slice($pipelines, 0, 5) as $pipeline): ?>
                        <li class="list-group-item d-flex align-items-center justify-content-between">
                            <div>
                                <i class="bi bi-diagram-3 me-2 text-warning"></i>
                                <span class="small"><?= h($pipeline['name']) ?></span>
                            </div>
                            <a href="/apps/form?pipeline_id=<?= $pipeline['id'] ?>" class="btn btn-sm btn-outline-info" title="Create app from this pipeline">
                                <i class="bi bi-plus"></i>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-link-45deg"></i> Related</h6>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item">
                            <a href="/studio/pipeline" class="text-decoration-none d-flex align-items-center">
                                <i class="bi bi-diagram-3 me-2 text-warning"></i>
                                <span class="small">Pipeline Studio</span>
                                <i class="bi bi-chevron-right ms-auto text-muted small"></i>
                            </a>
                        </li>
                        <li class="list-group-item">
                            <a href="/mcpservers" class="text-decoration-none d-flex align-items-center">
                                <i class="bi bi-plug me-2 text-primary"></i>
                                <span class="small">MCP Servers</span>
                                <i class="bi bi-chevron-right ms-auto text-muted small"></i>
                            </a>
                        </li>
                        <li class="list-group-item">
                            <a href="/apikeys" class="text-decoration-none d-flex align-items-center">
                                <i class="bi bi-key me-2 text-secondary"></i>
                                <span class="small">API Keys</span>
                                <i class="bi bi-chevron-right ms-auto text-muted small"></i>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
