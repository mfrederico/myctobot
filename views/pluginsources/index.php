<div class="container py-4">
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/settings/connections">Settings</a></li>
            <li class="breadcrumb-item active">Plugin Repository Sources</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">Plugin Repository Sources</h1>
            <p class="text-muted mb-0">Configure where the system searches for plugins</p>
        </div>
        <a href="/pluginsources/add" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Add Source
        </a>
    </div>

    <?php if (empty($sources)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-folder2-open display-4 text-muted mb-3"></i>
            <h5 class="text-muted">No Repository Sources Configured</h5>
            <p class="text-muted mb-4">Add plugin repository sources to enable plugin discovery.</p>
            <a href="/pluginsources/add" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Add Your First Source
            </a>
        </div>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">Configured Sources</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Provider</th>
                        <th>Repository URL</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Last Validated</th>
                        <th style="width: 200px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sources as $source): ?>
                    <tr id="source-row-<?= $source['id'] ?>">
                        <td>
                            <span class="badge bg-secondary">
                                <?php
                                $providerIcons = [
                                    'github' => 'bi-github',
                                    'gitlab' => 'bi-gitlab',
                                    'bitbucket' => 'bi-bucket',
                                    'git' => 'bi-git'
                                ];
                                $icon = $providerIcons[$source['provider']] ?? 'bi-git';
                                ?>
                                <i class="bi <?= $icon ?>"></i>
                                <?= htmlspecialchars(ucfirst($source['provider'])) ?>
                            </span>
                        </td>
                        <td>
                            <code><?= htmlspecialchars($source['repository_url']) ?></code>
                            <?php if (!empty($source['description'])): ?>
                            <br><small class="text-muted"><?= htmlspecialchars($source['description']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="text-muted"><?= htmlspecialchars(ucfirst($source['source_type'])) ?></span>
                        </td>
                        <td>
                            <?php
                            $statusBadges = [
                                'pending' => 'bg-warning text-dark',
                                'valid' => 'bg-success',
                                'error' => 'bg-danger'
                            ];
                            $statusClass = $statusBadges[$source['validation_status']] ?? 'bg-secondary';
                            ?>
                            <span class="badge <?= $statusClass ?>" id="status-badge-<?= $source['id'] ?>">
                                <?= htmlspecialchars(ucfirst($source['validation_status'])) ?>
                            </span>
                            <?php if ($source['validation_status'] === 'error' && !empty($source['validation_error'])): ?>
                            <br><small class="text-danger" id="validation-error-<?= $source['id'] ?>"><?= htmlspecialchars($source['validation_error']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($source['last_validated_at'])): ?>
                            <small class="text-muted" id="last-validated-<?= $source['id'] ?>"><?= htmlspecialchars($source['last_validated_at']) ?></small>
                            <?php else: ?>
                            <small class="text-muted">Never</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-primary" onclick="validateSource(<?= $source['id'] ?>)" title="Validate">
                                    <i class="bi bi-check-circle"></i>
                                </button>
                                <button type="button" class="btn btn-outline-<?= $source['is_active'] ? 'warning' : 'success' ?>"
                                        onclick="toggleSource(<?= $source['id'] ?>)"
                                        title="<?= $source['is_active'] ? 'Disable' : 'Enable' ?>"
                                        id="toggle-btn-<?= $source['id'] ?>">
                                    <i class="bi bi-<?= $source['is_active'] ? 'pause' : 'play' ?>"></i>
                                </button>
                                <a href="/pluginsources/delete/<?= $source['id'] ?>"
                                   class="btn btn-outline-danger"
                                   onclick="return confirm('Are you sure you want to delete this source?')"
                                   title="Delete">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </div>
                            <?php if (!$source['is_active']): ?>
                            <br><small class="text-warning" id="inactive-label-<?= $source['id'] ?>">Inactive</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="card mt-4">
        <div class="card-header">
            <h5 class="card-title mb-0">About Plugin Sources</h5>
        </div>
        <div class="card-body">
            <p class="mb-3">Plugin repository sources define where the system searches for plugins. You can add:</p>
            <ul class="mb-0">
                <li><strong>GitHub repositories or organizations</strong> - Individual repos or entire orgs to scan for plugins</li>
                <li><strong>GitLab repositories or groups</strong> - Single repos or group namespaces</li>
                <li><strong>Bitbucket repositories</strong> - Workspace/repository combinations</li>
                <li><strong>Generic Git URLs</strong> - Any Git repository accessible via URL</li>
            </ul>
        </div>
    </div>
</div>

<script>
function validateSource(sourceId) {
    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    btn.disabled = true;

    fetch('/pluginsources/validate/' + sourceId, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
        }
    })
    .then(response => response.json())
    .then(data => {
        btn.innerHTML = originalHtml;
        btn.disabled = false;

        if (data.success) {
            // Update status badge
            const badge = document.getElementById('status-badge-' + sourceId);
            if (badge) {
                const status = data.data.validation_status;
                badge.className = 'badge ' + (status === 'valid' ? 'bg-success' : status === 'error' ? 'bg-danger' : 'bg-warning text-dark');
                badge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
            }

            // Update validation error
            let errorEl = document.getElementById('validation-error-' + sourceId);
            if (data.data.validation_error) {
                if (!errorEl) {
                    errorEl = document.createElement('small');
                    errorEl.id = 'validation-error-' + sourceId;
                    errorEl.className = 'text-danger';
                    badge.parentNode.appendChild(document.createElement('br'));
                    badge.parentNode.appendChild(errorEl);
                }
                errorEl.textContent = data.data.validation_error;
            } else if (errorEl) {
                errorEl.remove();
            }

            // Update last validated
            const lastValidated = document.getElementById('last-validated-' + sourceId);
            if (lastValidated && data.data.last_validated_at) {
                lastValidated.textContent = data.data.last_validated_at;
            }

            alert(data.message);
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        alert('Request failed: ' + error.message);
    });
}

function toggleSource(sourceId) {
    const btn = document.getElementById('toggle-btn-' + sourceId);
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    btn.disabled = true;

    fetch('/pluginsources/toggle/' + sourceId, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
        }
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;

        if (data.success) {
            const isActive = data.data.is_active;
            btn.innerHTML = '<i class="bi bi-' + (isActive ? 'pause' : 'play') + '"></i>';
            btn.className = 'btn btn-outline-' + (isActive ? 'warning' : 'success');
            btn.title = isActive ? 'Disable' : 'Enable';

            // Update inactive label
            let label = document.getElementById('inactive-label-' + sourceId);
            if (!isActive && !label) {
                const td = btn.closest('td');
                td.appendChild(document.createElement('br'));
                label = document.createElement('small');
                label.id = 'inactive-label-' + sourceId;
                label.className = 'text-warning';
                label.textContent = 'Inactive';
                td.appendChild(label);
            } else if (isActive && label) {
                // Remove the br before the label too
                if (label.previousSibling && label.previousSibling.nodeName === 'BR') {
                    label.previousSibling.remove();
                }
                label.remove();
            }
        } else {
            btn.innerHTML = originalHtml;
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        alert('Request failed: ' + error.message);
    });
}
</script>
