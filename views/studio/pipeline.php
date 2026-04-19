<div class="container py-4" data-assist-page="studio/pipeline" data-assist-purpose="Pipeline studio for visual pipeline building">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi <?= $studio['icon'] ?? 'bi-gear' ?> text-<?= $studio['color'] ?? 'info' ?>"></i>
                <?= h($studio['name'] ?? 'Pipeline Studio') ?>
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
        <div class="col-md-3 col-6">
            <a href="/pipelines/create" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded bg-warning bg-opacity-10 p-3 me-3">
                        <i class="bi bi-plus-lg fs-4 text-warning"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">New Pipeline</div>
                        <small class="text-muted">Create a workflow</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-6">
            <a href="/studio/apps" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded bg-info bg-opacity-10 p-3 me-3">
                        <i class="bi bi-box-seam fs-4 text-info"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">App Studio</div>
                        <small class="text-muted">Deploy as apps</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-6">
            <a href="/mcpservers" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded bg-primary bg-opacity-10 p-3 me-3">
                        <i class="bi bi-plug fs-4 text-primary"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">MCP Servers</div>
                        <small class="text-muted">External tools</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-6">
            <a href="/schedules" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded bg-success bg-opacity-10 p-3 me-3">
                        <i class="bi bi-clock fs-4 text-success"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">Schedules</div>
                        <small class="text-muted">Automated triggers</small>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="row">
        <!-- Pipelines -->
        <div class="col-lg-8">
            <!-- Pipelines List -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-diagram-3"></i> Pipelines</h5>
                    <a href="/pipelines" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($pipelines)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-diagram-3 display-4 text-muted"></i>
                        <p class="text-muted mt-3 mb-0">No pipelines yet. Create your first workflow!</p>
                        <a href="/pipelines/create" class="btn btn-info mt-3">
                            <i class="bi bi-plus-lg"></i> New Pipeline
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Pipeline</th>
                                    <th>Runs</th>
                                    <th>Last Run</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pipelines as $pipeline): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="rounded bg-info bg-opacity-10 p-2 me-3">
                                                <i class="bi bi-diagram-3 text-info"></i>
                                            </div>
                                            <div>
                                                <a href="/pipelines/edit/<?= $pipeline['id'] ?>" class="fw-semibold text-decoration-none">
                                                    <?= h($pipeline['name'] ?? 'Unnamed') ?>
                                                </a>
                                                <?php if (!empty($pipeline['description'])): ?>
                                                <small class="d-block text-muted"><?= h(substr($pipeline['description'] ?? '', 0, 40)) ?>...</small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="text-muted"><?= $pipeline['run_count'] ?></span>
                                    </td>
                                    <td>
                                        <?php if ($pipeline['last_run_at']): ?>
                                        <small class="text-muted"><?= date('M j, g:ia', strtotime($pipeline['last_run_at'])) ?></small>
                                        <?php else: ?>
                                        <small class="text-muted">Never</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($pipeline['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="/pipelines/edit/<?= $pipeline['id'] ?>" class="btn btn-sm btn-outline-primary">
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

            <!-- Recent Runs -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-activity"></i> Recent Runs</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($runs)): ?>
                    <div class="text-center py-4">
                        <p class="text-muted mb-0">No runs yet.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Run</th>
                                    <th>Pipeline</th>
                                    <th>Trigger</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($runs as $run): ?>
                                <tr>
                                    <td>
                                        <code class="small"><?= h($run['run_uid'] ?? '') ?></code>
                                    </td>
                                    <td>
                                        <small><?= h($run['pipeline_name'] ?? '') ?></small>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= h($run['trigger_source'] ?? '') ?></small>
                                    </td>
                                    <td>
                                        <?php
                                        $statusColors = [
                                            'pending' => 'secondary',
                                            'running' => 'primary',
                                            'completed' => 'success',
                                            'failed' => 'danger',
                                            'cancelled' => 'warning'
                                        ];
                                        $color = $statusColors[$run['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?= $color ?>"><?= ucfirst($run['status']) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <a href="/pipelines/viewrun/<?= $run['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i>
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
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6">
                            <div class="display-6 fw-bold text-info"><?= count($pipelines) ?></div>
                            <small class="text-muted">Pipelines</small>
                        </div>
                        <div class="col-6">
                            <div class="display-6 fw-bold text-warning"><?= count($servers) ?></div>
                            <small class="text-muted">MCP Servers</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MCP Servers -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-plug"></i> MCP Servers</h6>
                    <a href="/mcpservers" class="btn btn-sm btn-outline-secondary">Manage</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($servers)): ?>
                    <div class="text-center py-4">
                        <p class="text-muted mb-0 small">No MCP servers configured.</p>
                        <a href="/mcpservers/create" class="btn btn-sm btn-outline-warning mt-2">Add Server</a>
                    </div>
                    <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($servers as $server): ?>
                        <li class="list-group-item d-flex align-items-center justify-content-between">
                            <div>
                                <i class="bi bi-plug me-2 text-muted"></i>
                                <span class="small"><?= h($server['name'] ?? 'Unnamed') ?></span>
                            </div>
                            <?php if ($server['is_active']): ?>
                            <span class="badge bg-success">Active</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Templates -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-lightning-charge"></i> Quick Start Templates</h6>
                    <a href="/orchestration" class="btn btn-sm btn-outline-secondary">Browse All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($templates)): ?>
                    <div class="text-center py-4">
                        <p class="text-muted mb-0 small">No templates available.</p>
                    </div>
                    <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach (array_slice($templates, 0, 3) as $template): ?>
                        <li class="list-group-item">
                            <a href="/orchestration/wizard/<?= $template['id'] ?>" class="text-decoration-none">
                                <div class="fw-semibold small"><?= h($template['name'] ?? 'Template') ?></div>
                                <?php if (!empty($template['description'])): ?>
                                <small class="text-muted"><?= h(substr($template['description'] ?? '', 0, 50)) ?>...</small>
                                <?php endif; ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
