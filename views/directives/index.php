<div class="container py-4" data-assist-page="directives" data-assist-purpose="List CEO directives with status filtering">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><i class="bi bi-envelope-paper"></i> CEO Directives</h1>
                <div>
                    <a href="/reviewboard" class="btn btn-outline-primary me-2">
                        <i class="bi bi-kanban"></i> Review Board
                    </a>
                    <a href="/dashboard" class="btn btn-outline-secondary">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </div>
            </div>

            <!-- Status Filter Tabs -->
            <ul class="nav nav-pills mb-4">
                <li class="nav-item">
                    <a class="nav-link <?= empty($status) ? 'active' : '' ?>" href="/directives">
                        All <span class="badge bg-secondary"><?= array_sum($statusCounts) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status === 'received' ? 'active' : '' ?>" href="/directives?status=received">
                        <i class="bi bi-inbox"></i> Received <span class="badge bg-info"><?= $statusCounts['received'] ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status === 'parsing' ? 'active' : '' ?>" href="/directives?status=parsing">
                        <i class="bi bi-cpu"></i> Parsing <span class="badge bg-warning"><?= $statusCounts['parsing'] ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status === 'planning' ? 'active' : '' ?>" href="/directives?status=planning">
                        <i class="bi bi-diagram-3"></i> Planning <span class="badge bg-warning"><?= $statusCounts['planning'] ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status === 'executing' ? 'active' : '' ?>" href="/directives?status=executing">
                        <i class="bi bi-play-circle"></i> Executing <span class="badge bg-primary"><?= $statusCounts['executing'] ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status === 'completed' ? 'active' : '' ?>" href="/directives?status=completed">
                        <i class="bi bi-check-circle"></i> Completed <span class="badge bg-success"><?= $statusCounts['completed'] ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status === 'failed' ? 'active' : '' ?>" href="/directives?status=failed">
                        <i class="bi bi-x-circle"></i> Failed <span class="badge bg-danger"><?= $statusCounts['failed'] ?></span>
                    </a>
                </li>
            </ul>

            <?php if (empty($directives)): ?>
            <div class="alert alert-info">
                <h4 class="alert-heading"><i class="bi bi-inbox"></i> No Directives Yet</h4>
                <p>CEO directives will appear here when emails are sent to your directive address.</p>
                <hr>
                <p class="mb-0">
                    <strong>To receive directives:</strong> Send emails to
                    <code><?= h($_SESSION['workspace_slug'] ?? 'your-workspace') ?>@myctobot.ai</code>
                </p>
                <p class="mb-0 mt-2">
                    <strong>Tip:</strong> Add <code>[AUTO]</code> to the subject to auto-execute, or <code>[REVIEW]</code> to require approval.
                </p>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-list-ul"></i> Directives
                    <span class="badge bg-light text-dark float-end"><?= $total ?> total</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Subject</th>
                                    <th>From</th>
                                    <th>Status</th>
                                    <th>Intent</th>
                                    <th>Mode</th>
                                    <th>Received</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($directives as $directive): ?>
                                <tr>
                                    <td>
                                        <a href="/directives/view/<?= h($directive->directive_uid) ?>">
                                            <strong><?= h($directive->email_subject ?: '(No Subject)') ?></strong>
                                        </a>
                                        <?php if ($directive->parsed_summary): ?>
                                        <br><small class="text-muted"><?= h(substr($directive->parsed_summary, 0, 80)) ?>...</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small><?= h($directive->email_from) ?></small>
                                    </td>
                                    <td>
                                        <?php
                                        $statusClass = [
                                            'received' => 'bg-info',
                                            'parsing' => 'bg-warning text-dark',
                                            'planning' => 'bg-warning text-dark',
                                            'executing' => 'bg-primary',
                                            'completed' => 'bg-success',
                                            'failed' => 'bg-danger'
                                        ];
                                        ?>
                                        <span class="badge <?= $statusClass[$directive->status] ?? 'bg-secondary' ?>">
                                            <?= ucfirst($directive->status) ?>
                                        </span>
                                        <?php if ($directive->current_phase): ?>
                                        <br><small class="text-muted"><?= h($directive->current_phase) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($directive->parsed_intent): ?>
                                        <span class="badge bg-secondary"><?= ucfirst($directive->parsed_intent) ?></span>
                                        <?php else: ?>
                                        <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($directive->approval_mode === 'manual'): ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-hand-thumbs-up"></i> Review</span>
                                        <?php else: ?>
                                        <span class="badge bg-success"><i class="bi bi-lightning"></i> Auto</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small><?= date('M j, Y H:i', strtotime($directive->created_at)) ?></small>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="/directives/view/<?= h($directive->directive_uid) ?>"
                                               class="btn btn-outline-primary" title="View">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if (in_array($directive->status, ['failed', 'received'])): ?>
                                            <button type="button" class="btn btn-outline-warning retry-directive"
                                                    data-id="<?= h($directive->directive_uid) ?>" title="Retry">
                                                <i class="bi bi-arrow-clockwise"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if (!in_array($directive->status, ['completed', 'failed'])): ?>
                                            <button type="button" class="btn btn-outline-secondary cancel-directive"
                                                    data-id="<?= h($directive->directive_uid) ?>" title="Cancel">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-outline-danger delete-directive"
                                                    data-id="<?= h($directive->directive_uid) ?>" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="card-footer">
                    <nav aria-label="Directives pagination">
                        <ul class="pagination mb-0 justify-content-center">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="/directives?page=<?= $page - 1 ?><?= $status ? '&status=' . $status : '' ?>">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="/directives?page=<?= $i ?><?= $status ? '&status=' . $status : '' ?>"><?= $i ?></a>
                            </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="/directives?page=<?= $page + 1 ?><?= $status ? '&status=' . $status : '' ?>">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Retry directive
document.querySelectorAll('.retry-directive').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        if (confirm('Retry this directive?')) {
            fetch('/directives/retry/' + id, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'}
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'Failed to retry directive');
                }
            });
        }
    });
});

// Cancel directive
document.querySelectorAll('.cancel-directive').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        if (confirm('Cancel this directive? This cannot be undone.')) {
            fetch('/directives/cancel/' + id, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'}
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'Failed to cancel directive');
                }
            });
        }
    });
});

// Delete directive
document.querySelectorAll('.delete-directive').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        if (confirm('DELETE this directive permanently? This will also delete any associated projects, epics, and stories. This cannot be undone!')) {
            fetch('/directives/delete/' + id, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'}
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'Failed to delete directive');
                }
            });
        }
    });
});
</script>
