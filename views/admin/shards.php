<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2 mb-0">
            <i class="bi bi-pc-display-horizontal"></i> Workstations
        </h1>
        <div>
            <a href="/agents" class="btn btn-outline-info me-2">
                <i class="bi bi-robot"></i> Agent Profiles
            </a>
            <button class="btn btn-outline-secondary me-2" onclick="healthCheckAll()">
                <i class="bi bi-heart-pulse"></i> Health Check All
            </button>
            <a href="/admin/createshard" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Add Workstation
            </a>
        </div>
    </div>

    <!-- Explanation Section -->
    <div class="card bg-light border-0 mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h5 class="card-title mb-3">
                        <i class="bi bi-question-circle text-primary"></i> What are Workstations?
                    </h5>
                    <p class="mb-2">
                        <strong>Workstations are servers where your AI agents do their work.</strong>
                        When you ask an AI agent to implement a feature or fix a bug, it needs a place to:
                    </p>
                    <ul class="mb-3">
                        <li><strong>Clone your repositories</strong> - Download and work with your code</li>
                        <li><strong>Run Claude Code CLI</strong> - Execute AI-powered coding tasks</li>
                        <li><strong>Create commits and branches</strong> - Make changes and push to GitHub</li>
                        <li><strong>Run tests and builds</strong> - Verify changes work correctly</li>
                    </ul>
                    <p class="mb-0 text-muted small">
                        <i class="bi bi-info-circle"></i>
                        Think of workstations as dedicated development machines for your AI agents.
                        Each workstation can run multiple jobs concurrently, and you can add as many as you need.
                    </p>
                </div>
                <div class="col-md-4">
                    <div class="bg-white rounded p-3 border">
                        <h6 class="text-muted mb-3"><i class="bi bi-lightning-charge"></i> Workstation Options</h6>
                        <div class="d-flex align-items-start mb-2">
                            <i class="bi bi-cloud-check text-success me-2 mt-1"></i>
                            <div>
                                <strong>Remote Server</strong>
                                <small class="d-block text-muted">A VPS or dedicated server you control (recommended for production)</small>
                            </div>
                        </div>
                        <div class="d-flex align-items-start mb-2">
                            <i class="bi bi-pc-display text-primary me-2 mt-1"></i>
                            <div>
                                <strong>Local Machine</strong>
                                <small class="d-block text-muted">Your own computer or a server in your office</small>
                            </div>
                        </div>
                        <div class="d-flex align-items-start">
                            <i class="bi bi-hdd-network text-info me-2 mt-1"></i>
                            <div>
                                <strong>MyCTOBot Server</strong>
                                <small class="d-block text-muted">Use our shared infrastructure (coming soon)</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($shards)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-pc-display-horizontal display-4 text-muted"></i>
            <h4 class="mt-3">No workstations configured yet</h4>
            <p class="text-muted mb-4" style="max-width: 500px; margin: 0 auto;">
                To use AI agents with Claude Code CLI, you'll need at least one workstation.
                This is where your agents will clone repos, write code, and create pull requests.
            </p>
            <a href="/admin/createshard" class="btn btn-primary btn-lg">
                <i class="bi bi-plus-lg"></i> Add Your First Workstation
            </a>
            <div class="mt-4 text-muted small">
                <p class="mb-1"><strong>Don't have a server?</strong></p>
                <p class="mb-0">
                    You can use any Linux server with SSH access. Popular options include
                    <a href="https://www.digitalocean.com/" target="_blank">DigitalOcean</a>,
                    <a href="https://www.linode.com/" target="_blank">Linode</a>, or
                    <a href="https://aws.amazon.com/ec2/" target="_blank">AWS EC2</a>.
                    A $12-24/month VPS works great for most teams.
                </p>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="row">
        <?php foreach ($shards as $shard): ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>
                        <i class="bi bi-<?= $shard['health_status'] === 'healthy' ? 'check-circle text-success' : ($shard['health_status'] === 'unhealthy' ? 'x-circle text-danger' : 'question-circle text-muted') ?>"></i>
                        <strong><?= htmlspecialchars($shard['name']) ?></strong>
                    </span>
                    <span class="badge <?= $shard['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                        <?= $shard['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-2"><?= htmlspecialchars($shard['description'] ?: 'No description') ?></p>

                    <table class="table table-sm table-borderless mb-3">
                        <tr>
                            <td class="text-muted" style="width: 40%">Mode:</td>
                            <td>
                                <?php $mode = $shard['execution_mode'] ?? 'http_api'; ?>
                                <?php if ($mode === 'ssh_tmux'): ?>
                                <span class="badge bg-primary"><i class="bi bi-terminal"></i> SSH+Tmux</span>
                                <?php if (!empty($shard['ssh_validated'])): ?>
                                <span class="badge bg-success"><i class="bi bi-check"></i></span>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="badge bg-secondary"><i class="bi bi-cloud"></i> HTTP API</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Host:</td>
                            <td>
                                <?php if ($mode === 'ssh_tmux'): ?>
                                <code><?= htmlspecialchars($shard['ssh_user'] ?? 'claudeuser') ?>@<?= htmlspecialchars($shard['host']) ?></code>
                                <?php else: ?>
                                <code><?= htmlspecialchars($shard['host']) ?>:<?= $shard['port'] ?></code>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Type:</td>
                            <td><span class="badge bg-info"><?= htmlspecialchars($shard['shard_type']) ?></span></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Max Jobs:</td>
                            <td><?= $shard['max_concurrent_jobs'] ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Capabilities:</td>
                            <td>
                                <?php foreach ($shard['capabilities'] as $cap): ?>
                                <span class="badge bg-secondary me-1"><?= htmlspecialchars($cap) ?></span>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    </table>

                    <!-- Stats -->
                    <div class="row text-center border-top pt-2">
                        <div class="col-3">
                            <div class="small text-muted">Total</div>
                            <div class="fw-bold"><?= $shard['stats']['total_jobs'] ?? 0 ?></div>
                        </div>
                        <div class="col-3">
                            <div class="small text-muted">Running</div>
                            <div class="fw-bold text-info"><?= $shard['stats']['running_jobs'] ?? 0 ?></div>
                        </div>
                        <div class="col-3">
                            <div class="small text-muted">Done</div>
                            <div class="fw-bold text-success"><?= $shard['stats']['completed_jobs'] ?? 0 ?></div>
                        </div>
                        <div class="col-3">
                            <div class="small text-muted">Failed</div>
                            <div class="fw-bold text-danger"><?= $shard['stats']['failed_jobs'] ?? 0 ?></div>
                        </div>
                    </div>

                    <?php if ($shard['is_default']): ?>
                    <div class="mt-2">
                        <span class="badge bg-primary"><i class="bi bi-globe"></i> Public</span>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-transparent">
                    <div class="btn-group w-100">
                        <button class="btn btn-outline-secondary btn-sm" onclick="testShard(<?= $shard['id'] ?>)" title="Test Connection">
                            <i class="bi bi-plug"></i> Test
                        </button>
                        <a href="/admin/editshard/<?= $shard['id'] ?>" class="btn btn-outline-primary btn-sm" title="Edit">
                            <i class="bi bi-pencil"></i> Edit
                        </a>
                        <button class="btn btn-outline-danger btn-sm" onclick="deleteShard(<?= $shard['id'] ?>, '<?= htmlspecialchars(addslashes($shard['name'])) ?>')" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Test Result Modal -->
<div class="modal fade" id="testResultModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Shard Health Check</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="testResultContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
async function testShard(shardId) {
    const modal = new bootstrap.Modal(document.getElementById('testResultModal'));
    const content = document.getElementById('testResultContent');

    content.innerHTML = '<div class="text-center"><div class="spinner-border text-primary"></div><p class="mt-2">Testing connection...</p></div>';
    modal.show();

    try {
        const response = await fetch('/admin/testshard/' + shardId);
        const data = await response.json();

        if (data.success) {
            const d = data.data || {};
            const isSSH = d.execution_mode === 'ssh_tmux';

            let details = '';
            if (isSSH) {
                details = `
                    <tr><td>Mode:</td><td><span class="badge bg-primary">SSH + Tmux</span></td></tr>
                    <tr><td>Host:</td><td><code>${d.ssh_user || 'claudeuser'}@${d.host || 'N/A'}</code></td></tr>
                    <tr><td>Response Time:</td><td>${d.time_ms || 0}ms</td></tr>
                `;
            } else {
                details = `
                    <tr><td>Mode:</td><td><span class="badge bg-secondary">HTTP API</span></td></tr>
                    <tr><td>Host:</td><td><code>${d.host || 'N/A'}:${d.port || 3500}</code></td></tr>
                    <tr><td>Type:</td><td>${d.shard_type || 'general'}</td></tr>
                    <tr><td>Max Jobs:</td><td>${d.max_concurrent_jobs || 'N/A'}</td></tr>
                    <tr><td>Capabilities:</td><td>${(d.capabilities || []).join(', ') || 'None'}</td></tr>
                `;
            }

            content.innerHTML = `
                <div class="alert alert-success">
                    <i class="bi bi-check-circle"></i> <strong>Connection Successful!</strong>
                </div>
                <table class="table table-sm">
                    ${details}
                </table>
            `;
        } else {
            content.innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-x-circle"></i> <strong>Connection Failed</strong>
                    <p class="mb-0 mt-2">${data.error || 'Unknown error'}</p>
                </div>
            `;
        }
    } catch (err) {
        content.innerHTML = `
            <div class="alert alert-danger">
                <i class="bi bi-x-circle"></i> <strong>Error</strong>
                <p class="mb-0 mt-2">${err.message}</p>
            </div>
        `;
    }
}

async function healthCheckAll() {
    const btn = event.target.closest('button');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Checking...';

    try {
        await fetch('/admin/shardhealth');
        location.reload();
    } catch (err) {
        alert('Error: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = original;
    }
}

function deleteShard(shardId, shardName) {
    if (!confirm(`Are you sure you want to delete shard "${shardName}"?\n\nThis action cannot be undone.`)) {
        return;
    }
    window.location.href = '/admin/deleteshard/' + shardId;
}
</script>
