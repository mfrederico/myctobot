<div class="container py-4" data-assist-page="orchestration/project" data-assist-purpose="View orchestration project status and execution history">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-3">
            <li class="breadcrumb-item"><a href="/orchestration">Studio</a></li>
            <li class="breadcrumb-item active"><?= h($project['name'] ?? 'Untitled') ?></li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h2 mb-1">
                <?php if ($template): ?>
                <i class="bi <?= $template['icon'] ?> text-<?= $template['color'] ?>"></i>
                <?php else: ?>
                <i class="bi bi-gear text-secondary"></i>
                <?php endif; ?>
                <?= h($project['name'] ?? 'Untitled') ?>
            </h1>
            <p class="text-muted mb-0">
                <?= $template ? h($template['name']) : 'Custom Orchestration' ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <?php $si = $project['status_info']; ?>
            <span class="badge bg-<?= $si['color'] ?? 'secondary' ?> fs-6 py-2 px-3">
                <i class="bi <?= $si['icon'] ?? 'bi-circle' ?>"></i>
                <?= $si['label'] ?? $project['status'] ?>
            </span>
        </div>
    </div>

    <div class="row">
        <!-- Main Content -->
        <div class="col-lg-8">
            <!-- Quick Actions -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2">
                        <?php if ($project['status'] === 'active' || $project['status'] === 'draft'): ?>
                        <button type="button" class="btn btn-success" onclick="executeProject(<?= $project['id'] ?>)">
                            <i class="bi bi-play-fill"></i> Run Now
                        </button>
                        <?php endif; ?>

                        <?php if ($project['status'] === 'active'): ?>
                        <button type="button" class="btn btn-warning" onclick="pauseProject(<?= $project['id'] ?>)">
                            <i class="bi bi-pause-fill"></i> Pause
                        </button>
                        <?php elseif ($project['status'] === 'paused'): ?>
                        <button type="button" class="btn btn-success" onclick="resumeProject(<?= $project['id'] ?>)">
                            <i class="bi bi-play-fill"></i> Resume
                        </button>
                        <?php endif; ?>

                        <a href="/orchestration/history/<?= $project['id'] ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-clock-history"></i> History
                        </a>

                        <?php if ($pipeline): ?>
                        <a href="/pipelines/edit/<?= $pipeline->id ?>" class="btn btn-outline-primary">
                            <i class="bi bi-diagram-3"></i> View Pipeline
                        </a>
                        <?php endif; ?>

                        <button type="button" class="btn btn-outline-danger" onclick="deleteProject(<?= $project['id'] ?>)">
                            <i class="bi bi-trash"></i> Delete
                        </button>
                    </div>
                </div>
            </div>

            <!-- Recent Runs -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-activity"></i> Recent Runs</h5>
                    <span class="badge bg-secondary"><?= count($runs) ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($runs)): ?>
                    <div class="text-center py-4">
                        <i class="bi bi-inbox display-4 text-muted"></i>
                        <p class="text-muted mt-2 mb-0">No runs yet. Click "Run Now" to execute.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Run</th>
                                    <th>Status</th>
                                    <th>Trigger</th>
                                    <th>Started</th>
                                    <th>Completed</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($runs as $run): ?>
                                <tr>
                                    <td>
                                        <code class="small"><?= h($run['run_uid']) ?></code>
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
                                    <td>
                                        <small class="text-muted"><?= h($run['trigger_source']) ?></small>
                                    </td>
                                    <td>
                                        <?php if ($run['started_at']): ?>
                                        <small><?= date('M j, g:ia', strtotime($run['started_at'])) ?></small>
                                        <?php else: ?>
                                        <small class="text-muted">-</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($run['completed_at']): ?>
                                        <small><?= date('M j, g:ia', strtotime($run['completed_at'])) ?></small>
                                        <?php else: ?>
                                        <small class="text-muted">-</small>
                                        <?php endif; ?>
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

            <!-- Scheduled Tasks -->
            <?php if (!empty($scheduledTasks)): ?>
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-clock"></i> Scheduled Tasks</h5>
                    <span class="badge bg-secondary"><?= count($scheduledTasks) ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Scheduled For</th>
                                <th>Completed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($scheduledTasks as $task): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-light text-dark"><?= h($task['task_type']) ?></span>
                                </td>
                                <td>
                                    <?php
                                    $taskColors = [
                                        'pending' => 'warning',
                                        'running' => 'primary',
                                        'completed' => 'success',
                                        'failed' => 'danger',
                                        'cancelled' => 'secondary'
                                    ];
                                    $color = $taskColors[$task['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $color ?>"><?= ucfirst($task['status']) ?></span>
                                </td>
                                <td>
                                    <small><?= date('M j, g:ia', strtotime($task['scheduled_at'])) ?></small>
                                </td>
                                <td>
                                    <?php if ($task['completed_at']): ?>
                                    <small><?= date('M j, g:ia', strtotime($task['completed_at'])) ?></small>
                                    <?php else: ?>
                                    <small class="text-muted">-</small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Stats -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Statistics</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <div class="display-6 fw-bold text-primary"><?= $project['run_count'] ?></div>
                            <small class="text-muted">Total Runs</small>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="display-6 fw-bold text-success">-</div>
                            <small class="text-muted">Success Rate</small>
                        </div>
                    </div>
                    <?php if ($project['last_run_at']): ?>
                    <div class="border-top pt-3">
                        <small class="text-muted d-block">Last Run</small>
                        <span><?= date('M j, Y g:ia', strtotime($project['last_run_at'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($project['next_run_at']): ?>
                    <div class="border-top pt-3 mt-3">
                        <small class="text-muted d-block">Next Scheduled Run</small>
                        <span><?= date('M j, Y g:ia', strtotime($project['next_run_at'])) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Configuration -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-gear"></i> Configuration</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($project['variables'])): ?>
                    <dl class="mb-0">
                        <?php foreach ($project['variables'] as $key => $value): ?>
                        <dt class="small text-muted"><?= h($key) ?></dt>
                        <dd class="mb-2">
                            <?php if (is_array($value)): ?>
                            <?= h(implode(', ', $value)) ?>
                            <?php else: ?>
                            <?= h($value) ?>
                            <?php endif; ?>
                        </dd>
                        <?php endforeach; ?>
                    </dl>
                    <?php else: ?>
                    <p class="text-muted mb-0 small">No configuration variables.</p>
                    <?php endif; ?>
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
            alert('Orchestration started!');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    });
}

function pauseProject(projectId) {
    fetch('/orchestration/pause/' + projectId, {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) location.reload();
        else alert('Error: ' + data.message);
    });
}

function resumeProject(projectId) {
    fetch('/orchestration/resume/' + projectId, {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) location.reload();
        else alert('Error: ' + data.message);
    });
}

function deleteProject(projectId) {
    if (!confirm('Are you sure you want to delete this project? This cannot be undone.')) return;

    fetch('/orchestration/delete/' + projectId, {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = '/orchestration';
        } else {
            alert('Error: ' + data.message);
        }
    });
}
</script>
