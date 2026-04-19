<?php
$appId = (int) ($app->id ?? 0);
$appName = h($app->name ?? 'App');
?>
<div class="container py-4">
    <!-- Step Indicator -->
    <div class="mb-4">
        <nav aria-label="App setup steps">
            <ol class="breadcrumb bg-light p-3 rounded">
                <li class="breadcrumb-item">
                    <a href="/apps/form/<?= $appId ?>">
                        <i class="bi bi-1-circle-fill text-primary"></i> Setup
                    </a>
                </li>
                <li class="breadcrumb-item">
                    <a href="/apps/screens/<?= $appId ?>">
                        <i class="bi bi-2-circle-fill text-primary"></i> Screens
                    </a>
                </li>
                <li class="breadcrumb-item active" aria-current="page">
                    <i class="bi bi-3-circle-fill text-primary"></i> <strong>Pipelines</strong>
                </li>
                <li class="breadcrumb-item text-muted">
                    <i class="bi bi-4-circle"></i> Build
                </li>
            </ol>
        </nav>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi bi-diagram-3 text-primary"></i> Pipelines &mdash; <?= $appName ?>
            </h1>
            <p class="text-muted mb-0">Bind pipelines as API endpoints in the app</p>
        </div>
        <div class="d-flex gap-2">
            <a href="/apps/screens/<?= $appId ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Screens
            </a>
            <a href="/apps/download/<?= $appId ?>" class="btn btn-success">
                <i class="bi bi-download"></i> Download
            </a>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <i class="bi bi-list-task"></i> Workspace Pipelines
                <span class="badge bg-secondary ms-1"><?= count($pipelines) ?></span>
            </span>
            <span class="text-muted small">
                <?= count(array_filter($pipelines, fn($p) => !empty($p['is_bound']))) ?> bound
            </span>
        </div>
        <?php if (empty($pipelines)): ?>
        <div class="card-body text-center py-5">
            <i class="bi bi-diagram-3 display-6 text-muted"></i>
            <p class="text-muted mt-2 mb-0">No pipelines found in this workspace.</p>
            <a href="/pipelines/create" class="btn btn-outline-primary mt-3">
                <i class="bi bi-plus-lg"></i> Create a Pipeline
            </a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 60px;">Bind</th>
                        <th>Pipeline</th>
                        <th>Slug</th>
                        <th>Alias (API name)</th>
                        <th class="text-muted text-end">Steps</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pipelines as $p):
                        $pId = (int) ($p['id'] ?? 0);
                        $isBound = !empty($p['is_bound']);
                        $alias = $p['alias'] ?? $p['slug'] ?? '';
                        $bindingId = $p['binding_id'] ?? null;
                    ?>
                    <tr class="pipeline-row <?= $isBound ? 'table-success' : '' ?>" data-pipeline-id="<?= $pId ?>">
                        <td class="text-center">
                            <div class="form-check form-switch">
                                <input class="form-check-input pipeline-toggle" type="checkbox"
                                       id="toggle-<?= $pId ?>"
                                       data-pipeline-id="<?= $pId ?>"
                                       data-binding-id="<?= (int) ($bindingId ?? 0) ?>"
                                       <?= $isBound ? 'checked' : '' ?>
                                       onchange="togglePipeline(this)">
                            </div>
                        </td>
                        <td>
                            <strong><?= h($p['name'] ?? '') ?></strong>
                            <?php if (!empty($p['description'])): ?>
                            <br><small class="text-muted"><?= h(substr($p['description'] ?? '', 0, 60)) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><code class="small"><?= h($p['slug'] ?? '') ?></code></td>
                        <td>
                            <input type="text" class="form-control form-control-sm font-monospace pipeline-alias"
                                   data-pipeline-id="<?= $pId ?>"
                                   value="<?= h($alias) ?>"
                                   placeholder="<?= h($p['slug'] ?? '') ?>"
                                   <?= !$isBound ? 'disabled' : '' ?>>
                        </td>
                        <td class="text-end text-muted"><?= (int) ($p['step_count'] ?? 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Build shortcut -->
    <div class="text-center mt-4">
        <form method="POST" action="/apps/build/<?= $appId ?>" class="d-inline" onsubmit="return confirm('Start a new build?')">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
            <button type="submit" class="btn btn-warning">
                <i class="bi bi-hammer"></i> Build App Now
            </button>
        </form>
    </div>
</div>

<script>
const csrfToken = '<?= $_SESSION['csrf_token'] ?? '' ?>';
const appId = <?= $appId ?>;

function togglePipeline(checkbox) {
    const pipelineId = checkbox.dataset.pipelineId;
    const isBind = checkbox.checked;
    const row = checkbox.closest('.pipeline-row');
    const aliasInput = row.querySelector('.pipeline-alias');
    const alias = aliasInput ? aliasInput.value : '';

    const endpoint = isBind
        ? '/apps/bindpipeline/' + appId
        : '/apps/unbindpipeline/' + appId;

    const body = new URLSearchParams({
        csrf_token: csrfToken,
        pipeline_id: pipelineId,
        alias: alias
    });

    fetch(endpoint, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            row.classList.toggle('table-success', isBind);
            aliasInput.disabled = !isBind;
            if (data.binding_id) {
                checkbox.dataset.bindingId = data.binding_id;
            }
        } else {
            // Revert toggle
            checkbox.checked = !isBind;
            alert('Error: ' + (data.error || 'Failed to update binding'));
        }
    })
    .catch(err => {
        checkbox.checked = !isBind;
        alert('Error: ' + err.message);
    });
}

// Save alias on blur
document.querySelectorAll('.pipeline-alias').forEach(input => {
    input.addEventListener('change', function() {
        const pipelineId = this.dataset.pipelineId;
        const row = this.closest('.pipeline-row');
        const toggle = row.querySelector('.pipeline-toggle');

        // Only update if bound
        if (!toggle.checked) return;

        const body = new URLSearchParams({
            csrf_token: csrfToken,
            pipeline_id: pipelineId,
            alias: this.value
        });

        fetch('/apps/bindpipeline/' + appId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Error: ' + (data.error || 'Failed to update alias'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
    });
});
</script>
