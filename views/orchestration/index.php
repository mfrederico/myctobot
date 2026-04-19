<div class="container py-4" data-assist-page="orchestration" data-assist-purpose="View and create business automation workflows">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi bi-lightning-charge"></i> Orchestration
            </h1>
            <p class="text-muted mb-0">AI-powered business orchestration</p>
        </div>
        <div class="d-flex gap-2">
            <a href="/orchestration/templates" class="btn btn-outline-primary">
                <i class="bi bi-grid"></i> Browse Templates
            </a>
            <a href="/orchestration/wizard" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> New Orchestration
            </a>
        </div>
    </div>

    <!-- Hero Section -->
    <div class="card bg-gradient-primary text-white mb-4" style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);">
        <div class="card-body p-4">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h3 class="mb-3">
                        <i class="bi bi-stars"></i> Orchestrate Your Business
                    </h3>
                    <p class="mb-3 opacity-90">
                        Create powerful automations that connect your systems. Describe what you want in plain English,
                        and Studio generates the pipelines to make it happen.
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="badge bg-white bg-opacity-25"><i class="bi bi-shop"></i> Shopify</span>
                        <span class="badge bg-white bg-opacity-25"><i class="bi bi-github"></i> GitHub</span>
                        <span class="badge bg-white bg-opacity-25"><i class="bi bi-kanban"></i> Jira</span>
                        <span class="badge bg-white bg-opacity-25"><i class="bi bi-envelope"></i> Email</span>
                        <span class="badge bg-white bg-opacity-25"><i class="bi bi-robot"></i> AI</span>
                    </div>
                </div>
                <div class="col-md-4 text-center d-none d-md-block">
                    <i class="bi bi-diagram-3 display-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Projects Section -->
    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-folder2-open"></i> Your Projects</h5>
                    <span class="badge bg-secondary"><?= count($projects) ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($projects)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox display-4 text-muted"></i>
                        <p class="text-muted mt-3 mb-0">No projects yet. Create your first orchestration!</p>
                        <a href="/orchestration/templates" class="btn btn-primary mt-3">
                            <i class="bi bi-grid"></i> Browse Templates
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Project</th>
                                    <th>Status</th>
                                    <th>Runs</th>
                                    <th>Last Run</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($projects as $project): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="rounded bg-light p-2 me-3">
                                                <i class="bi <?= h($project['template_icon'] ?? 'bi-gear') ?> text-primary"></i>
                                            </div>
                                            <div>
                                                <a href="/orchestration/project/<?= $project['id'] ?>" class="fw-semibold text-decoration-none">
                                                    <?= h($project['name'] ?? 'Untitled') ?>
                                                </a>
                                                <small class="d-block text-muted"><?= h($project['template_name'] ?? 'Custom') ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php $si = $project['status_info']; ?>
                                        <span class="badge bg-<?= $si['color'] ?? 'secondary' ?>">
                                            <i class="bi <?= $si['icon'] ?? 'bi-circle' ?>"></i>
                                            <?= $si['label'] ?? $project['status'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-muted"><?= $project['run_count'] ?></span>
                                    </td>
                                    <td>
                                        <?php if ($project['last_run_at']): ?>
                                        <small class="text-muted"><?= date('M j, g:ia', strtotime($project['last_run_at'])) ?></small>
                                        <?php else: ?>
                                        <small class="text-muted">Never</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <a href="/orchestration/project/<?= $project['id'] ?>" class="btn btn-outline-primary" title="View">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($project['status'] === 'active' || $project['status'] === 'draft'): ?>
                                            <button type="button" class="btn btn-outline-success" title="Run Now" onclick="executeProject(<?= $project['id'] ?>)">
                                                <i class="bi bi-play-fill"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
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

        <!-- Templates Sidebar -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-collection"></i> Templates</h5>
                    <a href="/orchestration/templates" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($templates)): ?>
                    <div class="text-center py-4">
                        <p class="text-muted mb-0">No templates available yet.</p>
                    </div>
                    <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($templates as $template): ?>
                        <a href="/orchestration/wizard/<?= $template['id'] ?>" class="list-group-item list-group-item-action">
                            <div class="d-flex align-items-center">
                                <div class="rounded bg-<?= $template['color'] ?> bg-opacity-10 p-2 me-3">
                                    <i class="bi <?= $template['icon'] ?> text-<?= $template['color'] ?>"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold"><?= h($template['name'] ?? 'Template') ?></div>
                                    <small class="text-muted"><?= h(substr($template['description'] ?? '', 0, 60)) ?>...</small>
                                </div>
                                <i class="bi bi-chevron-right text-muted"></i>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-lightning"></i> Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="/orchestration/wizard" class="btn btn-outline-primary text-start">
                            <i class="bi bi-plus-lg me-2"></i> Custom Orchestration
                        </a>
                        <a href="/pipelines" class="btn btn-outline-secondary text-start">
                            <i class="bi bi-diagram-3 me-2"></i> View Pipelines
                        </a>
                        <a href="/mcpservers" class="btn btn-outline-secondary text-start">
                            <i class="bi bi-plug me-2"></i> MCP Servers
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function executeProject(projectId) {
    if (!confirm('Run this orchestration now?')) return;

    fetch('/orchestration/execute/' + projectId, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Orchestration started! Run ID: ' + data.data.run_uid);
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
    });
}
</script>
