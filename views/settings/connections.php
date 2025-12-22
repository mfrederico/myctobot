<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">Settings</h1>
            <p class="text-muted mb-0">Manage your account and integrations</p>
        </div>
    </div>

    <!-- Profile & Subscription Row -->
    <div class="row g-4 mb-4">
        <!-- Profile Card -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-person"></i> Profile
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="me-3">
                            <?php if (!empty($member->avatar_url)): ?>
                            <img src="<?= htmlspecialchars($member->avatar_url) ?>" alt="Avatar" class="rounded-circle" width="60">
                            <?php else: ?>
                            <div class="bg-secondary rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
                                <i class="bi bi-person-fill text-white" style="font-size: 1.5rem;"></i>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow-1">
                            <h5 class="mb-0"><?= htmlspecialchars($member->display_name ?? $member->email) ?></h5>
                            <small class="text-muted"><?= htmlspecialchars($member->email) ?></small>
                            <?php if (!empty($member->google_id)): ?>
                            <span class="badge bg-danger ms-2"><i class="bi bi-google"></i></span>
                            <?php endif; ?>
                            <div class="mt-2">
                                <a href="/member/edit" class="btn btn-sm btn-outline-primary me-1">
                                    <i class="bi bi-pencil"></i> Edit
                                </a>
                                <?php if (empty($member->google_id)): ?>
                                <a href="/member/password" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-key"></i> Password
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Subscription Card -->
        <div class="col-md-6">
            <div class="card h-100 border-<?= $tierInfo['color'] ?>">
                <div class="card-header bg-<?= $tierInfo['color'] ?> <?= $tier !== 'free' ? 'text-white' : '' ?>">
                    <i class="bi bi-star-fill"></i> Subscription
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-1"><?= $tierInfo['name'] ?> Plan</h5>
                            <p class="text-muted mb-0 small"><?= $tierInfo['description'] ?></p>
                        </div>
                        <div class="text-end">
                            <div class="h6 mb-1"><?= $tierInfo['price'] ?></div>
                            <a href="/settings/subscription" class="btn btn-<?= $tier === 'free' ? 'primary' : 'outline-' . $tierInfo['color'] ?> btn-sm">
                                <?= $tier === 'free' ? '<i class="bi bi-rocket"></i> Upgrade' : '<i class="bi bi-gear"></i> Manage' ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-lightning me-1"></i>Quick Links</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <a href="/boards" class="text-decoration-none">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-kanban fs-4 text-primary me-3"></i>
                            <div>
                                <strong>Boards</strong>
                                <small class="d-block text-muted">Manage tracked boards</small>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="/atlassian" class="text-decoration-none">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-cloud fs-4 text-info me-3"></i>
                            <div>
                                <strong>Atlassian</strong>
                                <small class="d-block text-muted">Manage Jira sites</small>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="/settings/export" class="text-decoration-none">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-download fs-4 text-success me-3"></i>
                            <div>
                                <strong>Export</strong>
                                <small class="d-block text-muted">Download your data</small>
                            </div>
                        </div>
                    </a>
                </div>
                <?php if ($tier === 'enterprise'): ?>
                <div class="col-md-3">
                    <a href="/enterprise" class="text-decoration-none">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-robot fs-4 text-warning me-3"></i>
                            <div>
                                <strong>AI Developer</strong>
                                <small class="d-block text-muted">Enterprise dashboard</small>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endif; ?>
                <?php if ($member->level <= 50): ?>
                <div class="col-md-3">
                    <a href="/admin/shards" class="text-decoration-none">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-pc-display-horizontal fs-4 text-secondary me-3"></i>
                            <div>
                                <strong>Workstation Shards</strong>
                                <small class="d-block text-muted">Manage AI compute nodes</small>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Statistics Row -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-graph-up"></i> Your Statistics
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col-3">
                    <h4 class="text-primary mb-0"><?= $stats['total_boards'] ?? 0 ?></h4>
                    <small class="text-muted">Boards</small>
                </div>
                <div class="col-3">
                    <h4 class="text-success mb-0"><?= $stats['total_analyses'] ?? 0 ?></h4>
                    <small class="text-muted">Analyses</small>
                </div>
                <div class="col-3">
                    <h4 class="text-info mb-0"><?= $stats['total_digests'] ?? 0 ?></h4>
                    <small class="text-muted">Digests</small>
                </div>
                <div class="col-3">
                    <h4 class="text-secondary mb-0"><?= count($sites ?? []) ?></h4>
                    <small class="text-muted">Sites</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Connected Services Section -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><i class="bi bi-plug me-2"></i>Connected Services</h5>
        <span class="badge bg-<?= $summary['connected'] > 0 ? 'success' : 'secondary' ?>">
            <?= $summary['connected'] ?> of <?= $summary['available'] ?> connected
        </span>
    </div>

    <?php if ($tier !== 'enterprise'): ?>
    <div class="alert alert-info mb-4">
        <i class="bi bi-info-circle me-2"></i>
        <strong>Upgrade to Enterprise</strong> to unlock GitHub, Anthropic API, and upcoming Shopify integrations.
        <a href="/settings/subscription" class="alert-link ms-2">View Plans</a>
    </div>
    <?php endif; ?>

    <?php if (!$aiDevReady['ready'] && $tier === 'enterprise'): ?>
    <div class="alert alert-warning mb-4">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <strong>AI Developer Setup Incomplete:</strong>
        Missing connections: <?= implode(', ', $aiDevReady['missing']) ?>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <?php foreach ($connections as $key => $conn): ?>
        <div class="col-md-6">
            <div class="card h-100 <?= $conn['coming_soon'] ? 'border-dashed' : '' ?> <?= !$conn['available'] ? 'opacity-75' : '' ?>">
                <div class="card-header d-flex align-items-center justify-content-between bg-transparent">
                    <div class="d-flex align-items-center">
                        <i class="bi <?= $conn['icon'] ?> fs-4 text-<?= $conn['color'] ?> me-3"></i>
                        <div>
                            <h5 class="mb-0"><?= htmlspecialchars($conn['name']) ?></h5>
                            <?php if ($conn['coming_soon']): ?>
                                <span class="badge bg-secondary">Coming Soon</span>
                            <?php elseif (!$conn['available']): ?>
                                <span class="badge bg-info">Enterprise Required</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($conn['connected']): ?>
                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Connected</span>
                    <?php elseif ($conn['available'] && !$conn['coming_soon']): ?>
                        <span class="badge bg-secondary">Not Connected</span>
                    <?php endif; ?>
                </div>

                <div class="card-body">
                    <p class="text-muted small mb-3"><?= htmlspecialchars($conn['description']) ?></p>

                    <?php if ($conn['connected'] && !empty($conn['details'])): ?>
                        <!-- Connection Details -->
                        <?php if ($key === 'atlassian' && !empty($conn['details']['sites'])): ?>
                            <div class="mb-3">
                                <small class="text-muted d-block mb-2">Connected Sites:</small>
                                <?php foreach ($conn['details']['sites'] as $site): ?>
                                    <div class="d-flex align-items-center mb-1">
                                        <i class="bi bi-building me-2 text-primary"></i>
                                        <span class="small"><?= htmlspecialchars($site['site_name'] ?? $site->site_name) ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <small class="text-muted">Scopes: <?= htmlspecialchars($conn['details']['scopes']) ?></small>
                            </div>
                        <?php endif; ?>

                        <?php if ($key === 'github' && !empty($conn['details']['user'])): ?>
                            <div class="mb-3">
                                <div class="d-flex align-items-center mb-2">
                                    <img src="https://github.com/<?= htmlspecialchars($conn['details']['user']['login']) ?>.png?size=24"
                                         class="rounded-circle me-2" width="24" height="24"
                                         alt="<?= htmlspecialchars($conn['details']['user']['login']) ?>">
                                    <span class="small">@<?= htmlspecialchars($conn['details']['user']['login']) ?></span>
                                </div>
                                <small class="text-muted"><?= $conn['details']['repo_count'] ?> repositories connected</small>
                            </div>
                        <?php endif; ?>

                        <?php if ($key === 'anthropic' && !empty($conn['details']['masked_key'])): ?>
                            <div class="mb-3">
                                <code class="small"><?= htmlspecialchars($conn['details']['masked_key']) ?></code>
                                <?php if ($conn['details']['has_credit_warning']): ?>
                                    <br><small class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Low credit balance</small>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Features this connection enables -->
                    <div class="mb-3">
                        <small class="text-muted d-block mb-1">Features:</small>
                        <?php foreach ($conn['features'] as $feature): ?>
                            <span class="badge bg-light text-dark me-1 mb-1"><?= htmlspecialchars($feature) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (!$conn['coming_soon'] && $conn['available'] && !empty($conn['actions'])): ?>
                <div class="card-footer bg-transparent border-0">
                    <div class="d-flex gap-2 flex-wrap">
                        <?php foreach ($conn['actions'] as $action): ?>
                            <?php if (!empty($action['confirm'])): ?>
                                <a href="<?= htmlspecialchars($action['url']) ?>"
                                   class="btn btn-sm <?= $action['class'] ?>"
                                   onclick="return confirm('<?= htmlspecialchars($action['confirm']) ?>')">
                                    <?= htmlspecialchars($action['label']) ?>
                                </a>
                            <?php elseif (!empty($action['ajax'])): ?>
                                <button type="button"
                                        class="btn btn-sm <?= $action['class'] ?>"
                                        onclick="testConnection('<?= $key ?>', '<?= htmlspecialchars($action['url']) ?>')">
                                    <?= htmlspecialchars($action['label']) ?>
                                </button>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars($action['url']) ?>"
                                   class="btn btn-sm <?= $action['class'] ?>">
                                    <?= htmlspecialchars($action['label']) ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php elseif ($conn['coming_soon']): ?>
                <div class="card-footer bg-transparent border-0">
                    <button class="btn btn-sm btn-outline-secondary" disabled>
                        <i class="bi bi-clock me-1"></i>Coming Soon
                    </button>
                </div>
                <?php elseif (!$conn['available']): ?>
                <div class="card-footer bg-transparent border-0">
                    <a href="/settings/subscription" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-arrow-up-circle me-1"></i>Upgrade to Connect
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Session -->
    <div class="card mt-4">
        <div class="card-header">
            <i class="bi bi-box-arrow-right"></i> Session
        </div>
        <div class="card-body d-flex justify-content-between align-items-center">
            <p class="text-muted small mb-0">
                Member since <?= date('F j, Y', strtotime($member->created_at)) ?>
            </p>
            <a href="/auth/logout" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </div>
</div>

<style>
.border-dashed {
    border-style: dashed !important;
}
</style>

<script>
function testConnection(type, url) {
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testing...';

    fetch(url, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Success';
            btn.classList.remove('btn-outline-secondary');
            btn.classList.add('btn-success');
        } else {
            btn.innerHTML = '<i class="bi bi-x-circle me-1"></i>Failed';
            btn.classList.remove('btn-outline-secondary');
            btn.classList.add('btn-danger');
            if (data.error) {
                alert('Test failed: ' + data.error);
            }
        }
    })
    .catch(err => {
        btn.innerHTML = '<i class="bi bi-x-circle me-1"></i>Error';
        btn.classList.add('btn-danger');
    })
    .finally(() => {
        setTimeout(() => {
            btn.innerHTML = originalText;
            btn.disabled = false;
            btn.classList.remove('btn-success', 'btn-danger');
            btn.classList.add('btn-outline-secondary');
        }, 3000);
    });
}
</script>
