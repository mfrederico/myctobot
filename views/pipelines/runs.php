<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="/pipelines">Pipelines</a></li>
                    <li class="breadcrumb-item"><a href="/pipelines/edit/<?= $pipeline['id'] ?>"><?= htmlspecialchars($pipeline['name']) ?></a></li>
                    <li class="breadcrumb-item active">Runs</li>
                </ol>
            </nav>
            <h1 class="h2 mb-0">
                <i class="bi bi-list-task"></i> Pipeline Runs
            </h1>
        </div>
        <button class="btn btn-success" onclick="triggerPipeline()">
            <i class="bi bi-play-fill"></i> Run Pipeline
        </button>
    </div>

    <?php if (empty($runs)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-list-task display-4 text-muted"></i>
            <h4 class="mt-3">No runs yet</h4>
            <p class="text-muted mb-4">
                This pipeline hasn't been executed yet. Click "Run Pipeline" to start.
            </p>
            <button class="btn btn-success btn-lg" onclick="triggerPipeline()">
                <i class="bi bi-play-fill"></i> Run Pipeline
            </button>
        </div>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Run ID</th>
                        <th>Trigger</th>
                        <th>Status</th>
                        <th>Progress</th>
                        <th>Started</th>
                        <th>Duration</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($runs as $run): ?>
                    <tr>
                        <td>
                            <a href="/pipelines/viewrun/<?= $run['id'] ?>">
                                <code><?= htmlspecialchars(substr($run['run_uid'], 0, 16)) ?></code>
                            </a>
                        </td>
                        <td>
                            <?php
                            $triggerIcon = 'bi-hand-index';
                            $triggerSource = $run['trigger_source'] ?? '';
                            if (strpos($triggerSource, 'webhook') === 0) $triggerIcon = 'bi-link-45deg';
                            if ($triggerSource === 'cron') $triggerIcon = 'bi-clock';
                            if ($triggerSource === 'mcp') $triggerIcon = 'bi-robot';
                            ?>
                            <i class="bi <?= $triggerIcon ?>"></i>
                            <?= htmlspecialchars($triggerSource ?: 'manual') ?>
                        </td>
                        <td>
                            <?php
                            $statusColors = [
                                'pending' => 'secondary',
                                'running' => 'primary',
                                'completed' => 'success',
                                'failed' => 'danger',
                                'paused' => 'warning',
                                'cancelled' => 'dark'
                            ];
                            $statusIcons = [
                                'pending' => 'bi-hourglass',
                                'running' => 'bi-play-circle',
                                'completed' => 'bi-check-circle',
                                'failed' => 'bi-x-circle',
                                'paused' => 'bi-pause-circle',
                                'cancelled' => 'bi-slash-circle'
                            ];
                            $color = $statusColors[$run['status']] ?? 'secondary';
                            $icon = $statusIcons[$run['status']] ?? 'bi-question-circle';
                            ?>
                            <span class="badge bg-<?= $color ?>">
                                <i class="bi <?= $icon ?>"></i>
                                <?= ucfirst($run['status']) ?>
                            </span>
                            <?php if ($run['status'] === 'running' && $run['current_step_name']): ?>
                            <small class="text-muted d-block"><?= htmlspecialchars($run['current_step_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="progress" style="height: 20px; width: 100px;">
                                <div class="progress-bar bg-<?= $color ?>" style="width: <?= $run['progress_percent'] ?>%">
                                    <?= $run['progress_percent'] ?>%
                                </div>
                            </div>
                            <small class="text-muted"><?= $run['steps_completed'] ?>/<?= $run['steps_total'] ?> steps</small>
                        </td>
                        <td>
                            <?= $run['started_at'] ? date('M j, g:i A', strtotime($run['started_at'])) : '-' ?>
                        </td>
                        <td>
                            <?php
                            if ($run['started_at'] && $run['completed_at']) {
                                $start = strtotime($run['started_at']);
                                $end = strtotime($run['completed_at']);
                                $duration = $end - $start;
                                if ($duration < 60) {
                                    echo $duration . 's';
                                } elseif ($duration < 3600) {
                                    echo floor($duration / 60) . 'm ' . ($duration % 60) . 's';
                                } else {
                                    echo floor($duration / 3600) . 'h ' . floor(($duration % 3600) / 60) . 'm';
                                }
                            } elseif ($run['started_at']) {
                                echo '<i class="bi bi-clock text-primary"></i> Running...';
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td>
                            <a href="/pipelines/viewrun/<?= $run['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i> View
                            </a>
                            <?php if (in_array($run['status'], ['pending', 'running'])): ?>
                            <button class="btn btn-sm btn-outline-danger" onclick="cancelRun(<?= $run['id'] ?>)">
                                <i class="bi bi-x-lg"></i>
                            </button>
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

<!-- Trigger Modal -->
<div class="modal fade" id="triggerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-play-fill"></i> Run Pipeline</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Context (JSON, optional)</label>
                    <textarea class="form-control font-monospace" id="triggerContext" rows="4" placeholder='{&#10;  "issue_key": "PROJ-123"&#10;}'></textarea>
                    <small class="text-muted">Pass initial context/variables to the pipeline</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="confirmTrigger()">
                    <i class="bi bi-play-fill"></i> Run
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?= Flight::csrf()->getToken() ?>';
const pipelineId = <?= $pipeline['id'] ?>;

function triggerPipeline() {
    document.getElementById('triggerContext').value = '';
    new bootstrap.Modal(document.getElementById('triggerModal')).show();
}

async function confirmTrigger() {
    const context = document.getElementById('triggerContext').value || '{}';

    try {
        const response = await fetch('/pipelines/trigger/' + pipelineId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&context=' + encodeURIComponent(context)
        });

        const result = await response.json();

        if (result.success) {
            window.location.href = '/pipelines/viewrun/' + result.data.run_id;
        } else {
            alert('Error: ' + (result.error || 'Failed to start pipeline'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

async function cancelRun(runId) {
    if (!confirm('Are you sure you want to cancel this run?')) {
        return;
    }

    try {
        const response = await fetch('/pipelines/cancelrun/' + runId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'csrf_token=' + encodeURIComponent(csrfToken)
        });

        const result = await response.json();

        if (result.success) {
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Failed to cancel run'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}
</script>
