<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/directives">Directives</a></li>
                    <li class="breadcrumb-item active"><?= htmlspecialchars(substr($directive->email_subject ?: 'Untitled', 0, 50)) ?></li>
                </ol>
            </nav>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><?= htmlspecialchars($directive->email_subject ?: '(No Subject)') ?></h1>
                <div>
                    <?php if (in_array($directive->status, ['failed', 'received'])): ?>
                    <button class="btn btn-warning retry-directive" data-id="<?= htmlspecialchars($directive->directive_uid) ?>">
                        <i class="bi bi-arrow-clockwise"></i> Retry
                    </button>
                    <?php endif; ?>
                    <?php if ($directive->status !== 'completed'): ?>
                    <button class="btn btn-outline-danger cancel-directive" data-id="<?= htmlspecialchars($directive->directive_uid) ?>">
                        <i class="bi bi-x-lg"></i> Cancel
                    </button>
                    <?php endif; ?>
                    <a href="/directives" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                </div>
            </div>

            <div class="row">
                <!-- Main Content -->
                <div class="col-lg-8">
                    <!-- Email Content Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <i class="bi bi-envelope"></i> Email Content
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>From:</strong> <?= htmlspecialchars($directive->email_from) ?>
                                </div>
                                <div class="col-md-6">
                                    <strong>Received:</strong> <?= date('M j, Y H:i:s', strtotime($directive->created_at)) ?>
                                </div>
                            </div>
                            <hr>
                            <div class="email-body bg-light p-3 rounded">
                                <pre class="mb-0" style="white-space: pre-wrap; font-family: inherit;"><?= htmlspecialchars($directive->email_body) ?></pre>
                            </div>
                        </div>
                    </div>

                    <!-- Parsed Requirements Card -->
                    <?php if ($directive->parsed_requirements): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <i class="bi bi-list-check"></i> Parsed Requirements
                        </div>
                        <div class="card-body">
                            <?php
                            $requirements = json_decode($directive->parsed_requirements, true);
                            if ($requirements):
                            ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($requirements as $req): ?>
                                <li class="list-group-item"><?= htmlspecialchars(is_array($req) ? json_encode($req) : $req) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php else: ?>
                            <pre class="mb-0"><?= htmlspecialchars($directive->parsed_requirements) ?></pre>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Processing Logs Card -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <i class="bi bi-journal-text"></i> Processing Log
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($logs)): ?>
                            <div class="p-3 text-muted">No processing logs yet.</div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Time</th>
                                            <th>Phase</th>
                                            <th>Level</th>
                                            <th>Message</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <td class="text-nowrap">
                                                <small><?= date('H:i:s', strtotime($log->created_at)) ?></small>
                                            </td>
                                            <td>
                                                <code><?= htmlspecialchars($log->phase) ?></code>
                                            </td>
                                            <td>
                                                <?php
                                                $levelClass = [
                                                    'info' => 'text-info',
                                                    'warning' => 'text-warning',
                                                    'error' => 'text-danger'
                                                ];
                                                ?>
                                                <i class="bi bi-circle-fill <?= $levelClass[$log->log_level] ?? '' ?>"></i>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($log->message) ?>
                                                <?php if ($log->context_json): ?>
                                                <button class="btn btn-link btn-sm p-0 ms-2" onclick="toggleLogContext(this)">
                                                    <i class="bi bi-code-slash"></i>
                                                </button>
                                                <pre class="log-context d-none mt-2 p-2 bg-light rounded small"><?= htmlspecialchars(json_encode(json_decode($log->context_json), JSON_PRETTY_PRINT)) ?></pre>
                                                <?php endif; ?>
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
                    <!-- Status Card -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <i class="bi bi-info-circle"></i> Status
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <strong>Current Status:</strong>
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
                                <span class="badge <?= $statusClass[$directive->status] ?? 'bg-secondary' ?> fs-6">
                                    <?= ucfirst($directive->status) ?>
                                </span>
                            </div>

                            <?php if ($directive->current_phase): ?>
                            <div class="mb-3">
                                <strong>Current Phase:</strong>
                                <span class="text-muted"><?= htmlspecialchars($directive->current_phase) ?></span>
                            </div>
                            <?php endif; ?>

                            <?php if ($directive->error_message): ?>
                            <div class="alert alert-danger mb-3">
                                <strong>Error:</strong>
                                <?= htmlspecialchars($directive->error_message) ?>
                            </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <strong>Approval Mode:</strong>
                                <?php if ($directive->approval_mode === 'manual'): ?>
                                <span class="badge bg-warning text-dark"><i class="bi bi-hand-thumbs-up"></i> Requires Review</span>
                                <?php else: ?>
                                <span class="badge bg-success"><i class="bi bi-lightning"></i> Auto-Execute</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($directive->parsed_intent): ?>
                            <div class="mb-3">
                                <strong>Intent:</strong>
                                <span class="badge bg-secondary"><?= ucfirst($directive->parsed_intent) ?></span>
                            </div>
                            <?php endif; ?>

                            <?php if ($directive->parsed_summary): ?>
                            <div class="mb-3">
                                <strong>Summary:</strong>
                                <p class="text-muted mb-0"><?= htmlspecialchars($directive->parsed_summary) ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Linked Project Card -->
                    <?php if ($project): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <i class="bi bi-folder"></i> Linked Project
                        </div>
                        <div class="card-body">
                            <h5><?= htmlspecialchars($project->name) ?></h5>
                            <p class="text-muted small"><?= htmlspecialchars($project->description ?: 'No description') ?></p>
                            <div class="mb-2">
                                <strong>Status:</strong>
                                <span class="badge bg-secondary"><?= ucfirst($project->status) ?></span>
                            </div>
                            <div class="mb-2">
                                <strong>Progress:</strong>
                                <div class="progress mt-1">
                                    <div class="progress-bar" role="progressbar"
                                         style="width: <?= $project->completion_percentage ?>%"
                                         aria-valuenow="<?= $project->completion_percentage ?>"
                                         aria-valuemin="0" aria-valuemax="100">
                                        <?= $project->completion_percentage ?>%
                                    </div>
                                </div>
                            </div>
                            <a href="/projects/view/<?= $project->project_uid ?>" class="btn btn-sm btn-outline-primary">
                                View Project <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Metadata Card -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <i class="bi bi-tag"></i> Metadata
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <dt class="col-5">Directive ID</dt>
                                <dd class="col-7"><code class="small"><?= htmlspecialchars(substr($directive->directive_uid, 0, 16)) ?>...</code></dd>

                                <dt class="col-5">Message ID</dt>
                                <dd class="col-7"><small class="text-muted"><?= htmlspecialchars($directive->email_message_id ?: '-') ?></small></dd>

                                <?php if ($member): ?>
                                <dt class="col-5">Member</dt>
                                <dd class="col-7"><?= htmlspecialchars($member->email) ?></dd>
                                <?php endif; ?>

                                <dt class="col-5">Created</dt>
                                <dd class="col-7"><?= date('Y-m-d H:i:s', strtotime($directive->created_at)) ?></dd>

                                <?php if ($directive->updated_at): ?>
                                <dt class="col-5">Updated</dt>
                                <dd class="col-7"><?= date('Y-m-d H:i:s', strtotime($directive->updated_at)) ?></dd>
                                <?php endif; ?>

                                <?php if ($directive->response_sent_at): ?>
                                <dt class="col-5">Response Sent</dt>
                                <dd class="col-7"><?= date('Y-m-d H:i:s', strtotime($directive->response_sent_at)) ?></dd>
                                <?php endif; ?>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleLogContext(btn) {
    const pre = btn.parentElement.querySelector('.log-context');
    pre.classList.toggle('d-none');
}

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
</script>
