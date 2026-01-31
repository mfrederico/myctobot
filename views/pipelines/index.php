<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi bi-diagram-3"></i> Pipelines
            </h1>
            <p class="text-muted mb-0">Automate workflows with spreadsheet-like execution grids</p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <div class="form-check form-switch" title="Auto-redirect to handoff target when viewing completed runs">
                <input class="form-check-input" type="checkbox" id="followHandoffs" onchange="toggleFollowHandoffs()">
                <label class="form-check-label small text-muted" for="followHandoffs">Follow Handoffs</label>
            </div>
            <a href="/pipelines/create" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> New Pipeline
            </a>
        </div>
    </div>

    <script>
    // Initialize follow handoffs toggle from localStorage
    document.addEventListener('DOMContentLoaded', function() {
        const followHandoffs = localStorage.getItem('pipeline_follow_handoffs') === 'true';
        document.getElementById('followHandoffs').checked = followHandoffs;
    });

    function toggleFollowHandoffs() {
        const checked = document.getElementById('followHandoffs').checked;
        localStorage.setItem('pipeline_follow_handoffs', checked ? 'true' : 'false');
    }
    </script>

    <!-- Explanation Section -->
    <div class="card bg-light border-0 mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h5 class="card-title mb-3">
                        <i class="bi bi-question-circle text-primary"></i> What are Pipelines?
                    </h5>
                    <p class="mb-2">
                        <strong>Pipelines are automation workflows organized like a spreadsheet.</strong>
                        Each pipeline has columns (phases) and rows (iterations) with steps that:
                    </p>
                    <ul class="mb-2">
                        <li><strong>Execute AI agents</strong> - Run impl, verify, or custom agents</li>
                        <li><strong>Run shell commands</strong> - Execute scripts with stdin/stdout</li>
                        <li><strong>Call webhooks</strong> - POST to external services</li>
                        <li><strong>Transform data</strong> - Parse and modify output between steps</li>
                    </ul>
                    <div class="alert alert-info py-2 px-3 mb-0 small">
                        <i class="bi bi-plug"></i> <strong>MCP Integration:</strong>
                        Pipelines can be exposed as MCP tools, allowing AI agents (like Claude) to trigger them and receive structured results.
                        Configure this in <a href="/mcpservers">MCP Server Library</a> or per-pipeline settings.
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="bg-white rounded p-3 border">
                        <h6 class="text-muted mb-3"><i class="bi bi-lightning-charge"></i> Trigger Options</h6>
                        <?php foreach ($triggerTypes as $type => $info): ?>
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

    <?php if (empty($pipelines)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-diagram-3 display-4 text-muted"></i>
            <h4 class="mt-3">No pipelines yet</h4>
            <p class="text-muted mb-4" style="max-width: 500px; margin: 0 auto;">
                Create your first pipeline to automate CI/CD, deployments, or any multi-step workflow.
            </p>
            <a href="/pipelines/create" class="btn btn-primary btn-lg">
                <i class="bi bi-plus-lg"></i> Create Your First Pipeline
            </a>
        </div>
    </div>
    <?php else: ?>
    <div class="row">
        <?php foreach ($pipelines as $pipeline): ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 <?= !$pipeline['is_active'] ? 'border-secondary' : '' ?>">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>
                        <i class="bi bi-<?= $pipeline['trigger_info']['icon'] ?? 'diagram-3' ?> text-primary"></i>
                        <strong><?= htmlspecialchars($pipeline['name']) ?></strong>
                        <?php if (!empty($pipeline['is_system'])): ?>
                        <i class="bi bi-lock-fill text-secondary ms-1" title="System Pipeline - Protected from deletion"></i>
                        <?php endif; ?>
                    </span>
                    <span class="badge <?= $pipeline['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                        <?= $pipeline['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-2">
                        <?= htmlspecialchars($pipeline['description'] ?: 'No description') ?>
                    </p>

                    <div class="mb-3">
                        <code class="small"><?= htmlspecialchars($pipeline['slug']) ?></code>
                    </div>

                    <table class="table table-sm table-borderless mb-3">
                        <tr>
                            <td class="text-muted" style="width: 40%">Trigger:</td>
                            <td>
                                <span class="badge bg-info">
                                    <i class="bi <?= $pipeline['trigger_info']['icon'] ?? 'bi-play' ?>"></i>
                                    <?= $pipeline['trigger_info']['label'] ?? ucfirst($pipeline['trigger_type'] ?? 'manual') ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Columns:</td>
                            <td><?= $pipeline['column_count'] ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Steps:</td>
                            <td><?= $pipeline['step_count'] ?></td>
                        </tr>
                    </table>

                    <!-- Stats -->
                    <div class="row text-center border-top pt-2">
                        <div class="col-4">
                            <div class="small text-muted">Runs</div>
                            <div class="fw-bold"><?= $pipeline['run_count'] ?></div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Success</div>
                            <div class="fw-bold <?= $pipeline['success_rate'] !== null ? ($pipeline['success_rate'] >= 80 ? 'text-success' : ($pipeline['success_rate'] >= 50 ? 'text-warning' : 'text-danger')) : 'text-muted' ?>">
                                <?= $pipeline['success_rate'] !== null ? $pipeline['success_rate'] . '%' : '-' ?>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Last Run</div>
                            <div class="fw-bold small">
                                <?= $pipeline['last_run_at'] ? date('M j', strtotime($pipeline['last_run_at'])) : '-' ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent">
                    <div class="btn-group w-100">
                        <a href="/pipelines/edit/<?= $pipeline['id'] ?>" class="btn btn-outline-primary btn-sm" title="Edit">
                            <i class="bi bi-pencil"></i> Edit
                        </a>
                        <a href="/pipelines/runs/<?= $pipeline['id'] ?>" class="btn btn-outline-secondary btn-sm" title="View Runs">
                            <i class="bi bi-list-task"></i> Runs
                        </a>
                        <?php if ($pipeline['is_active']): ?>
                        <button class="btn btn-outline-success btn-sm" onclick="triggerPipeline(<?= $pipeline['id'] ?>, '<?= htmlspecialchars(addslashes($pipeline['name'])) ?>')" title="Run">
                            <i class="bi bi-play-fill"></i>
                        </button>
                        <?php endif; ?>
                        <?php if (!empty($pipeline['is_system'])): ?>
                        <button class="btn btn-outline-secondary btn-sm" disabled title="System pipelines cannot be deleted">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php else: ?>
                        <button class="btn btn-outline-danger btn-sm" onclick="deletePipeline(<?= $pipeline['id'] ?>, '<?= htmlspecialchars(addslashes($pipeline['name'])) ?>')" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
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
                <p>Run pipeline: <strong id="triggerPipelineName"></strong></p>
                <div class="mb-3">
                    <label class="form-label">Context (JSON, optional)</label>
                    <textarea class="form-control font-monospace" id="triggerContext" rows="4" placeholder='{&#10;  "key": "value"&#10;}'></textarea>
                    <small class="text-muted">Pass initial context/variables to the pipeline</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="triggerConfirmBtn">
                    <i class="bi bi-play-fill"></i> Run
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?= Flight::csrf()->getToken() ?>';
let currentPipelineId = null;

function triggerPipeline(pipelineId, pipelineName) {
    currentPipelineId = pipelineId;
    document.getElementById('triggerPipelineName').textContent = pipelineName;
    document.getElementById('triggerContext').value = '';
    new bootstrap.Modal(document.getElementById('triggerModal')).show();
}

document.getElementById('triggerConfirmBtn').addEventListener('click', async function() {
    const btn = this;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Starting...';

    try {
        const context = document.getElementById('triggerContext').value || '{}';

        const response = await fetch('/pipelines/trigger/' + currentPipelineId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&context=' + encodeURIComponent(context)
        });

        const data = await response.json();

        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('triggerModal')).hide();
            // Redirect to run view
            window.location.href = '/pipelines/viewrun/' + data.data.run_id;
        } else {
            alert('Error: ' + (data.error || 'Failed to start pipeline'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
});

function deletePipeline(pipelineId, pipelineName) {
    if (!confirm(`Are you sure you want to delete pipeline "${pipelineName}"?\n\nThis will also delete all runs and step history.`)) {
        return;
    }

    fetch('/pipelines/delete/' + pipelineId, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrfToken
        },
        body: 'csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Failed to delete pipeline'));
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
    });
}
</script>
