<div class="container py-4" data-assist-page="settings/connections" data-assist-purpose="Manage connected services and integrations">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="/settings">Settings</a></li>
                    <li class="breadcrumb-item active">Dashboard</li>
                </ol>
            </nav>
            <h1 class="h2 mb-1">Dashboard</h1>
            <p class="text-muted mb-0">Your command center for MyCTOBot</p>
        </div>
        <div>
            <button type="button" class="btn btn-outline-primary" onclick="openOnboardingWizard()">
                <i class="bi bi-rocket-takeoff me-1"></i> Setup Guide
            </button>
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
                            <img src="<?= h($member->avatar_url) ?>" alt="Avatar" class="rounded-circle" width="60">
                            <?php else: ?>
                            <div class="bg-secondary rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
                                <i class="bi bi-person-fill text-white" style="font-size: 1.5rem;"></i>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow-1">
                            <h5 class="mb-0"><?= h($member->display_name ?? $member->email) ?></h5>
                            <small class="text-muted"><?= h($member->email) ?></small>
                            <?php if (!empty($member->google_eid)): ?>
                            <span class="badge bg-danger ms-2"><i class="bi bi-google"></i></span>
                            <?php endif; ?>
                            <div class="mt-2">
                                <a href="/member/edit" class="btn btn-sm btn-outline-primary me-1">
                                    <i class="bi bi-pencil"></i> Edit
                                </a>
                                <?php if (empty($member->google_eid)): ?>
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
                <div class="card-header bg-<?= $tierInfo['color'] ?> text-dark">
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

    <!-- CEO Directives -->
    <div class="card mb-4 border-warning">
        <div class="card-header bg-warning text-dark">
            <i class="bi bi-briefcase me-1"></i>CEO Directives
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <a href="/directives" class="text-decoration-none">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-envelope-open fs-4 text-primary me-3"></i>
                            <div>
                                <strong>1. Directives</strong>
                                <small class="d-block text-muted">Incoming CEO directives</small>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="/projects" class="text-decoration-none">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-folder fs-4 text-success me-3"></i>
                            <div>
                                <strong>2. Projects</strong>
                                <small class="d-block text-muted">Planning & progress</small>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="/reviewboard" class="text-decoration-none">
                        <div class="d-flex align-items-center p-2 bg-warning-subtle rounded">
                            <i class="bi bi-clipboard-check fs-4 text-warning me-3"></i>
                            <div>
                                <strong>3. Review Board</strong>
                                <small class="d-block text-muted">Approve stories → create issues</small>
                            </div>
                        </div>
                    </a>
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
                <div class="col-md-3">
                    <a href="/activity?type=aidev" class="text-decoration-none">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-robot fs-4 text-warning me-3"></i>
                            <div>
                                <strong>AI Developer</strong>
                                <small class="d-block text-muted">Dashboard</small>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="/agents" class="text-decoration-none">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-robot fs-4 text-success me-3"></i>
                            <div>
                                <strong>Agent Profiles</strong>
                                <small class="d-block text-muted">Configure how jobs run</small>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="/knowledgebase" class="text-decoration-none">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-book fs-4 text-primary me-3"></i>
                            <div>
                                <strong>Knowledge Base</strong>
                                <small class="d-block text-muted">RAG document storage</small>
                            </div>
                        </div>
                    </a>
                </div>
                <?php if ($member->level <= 50): ?>
                <div class="col-md-3">
                    <a href="/workstations" class="text-decoration-none">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-pc-display-horizontal fs-4 text-secondary me-3"></i>
                            <div>
                                <strong>Workstations</strong>
                                <small class="d-block text-muted">Where jobs run</small>
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

    <!-- AI Developer Infrastructure -->
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-robot"></i> Agent Profiles
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1"><?= $agentCount ?? 0 ?></h3>
                            <p class="text-muted mb-0 small">
                                Agents define <em>how</em> AI Developer jobs run: AI provider, MCP servers, and automation hooks.
                            </p>
                        </div>
                        <a href="/agents" class="btn btn-outline-success">
                            <i class="bi bi-gear"></i> Manage
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php if ($member->level <= 50): ?>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header bg-secondary text-white">
                    <i class="bi bi-pc-display-horizontal"></i> Workstations
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1"><?= $runnerCount ?? 0 ?></h3>
                            <p class="text-muted mb-0 small">
                                Workstations define <em>where</em> AI Developer jobs run: servers with Claude CLI installed.
                            </p>
                        </div>
                        <a href="/workstations" class="btn btn-outline-secondary">
                            <i class="bi bi-gear"></i> Manage
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Connected Services -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><i class="bi bi-plug me-2"></i>Connected Services</h5>
        <span class="badge bg-<?= !empty($connBeans) ? 'success' : 'secondary' ?>">
            <?= count($connBeans ?? []) ?> connection(s)
        </span>
    </div>

    <?php if (!$aiDevReady['ready']): ?>
    <div class="alert alert-warning mb-4">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <strong>AI Developer Setup Incomplete:</strong>
        Missing connections: <?= implode(', ', $aiDevReady['missing']) ?>
    </div>
    <?php endif; ?>

    <?php if (empty($connBeans)): ?>
    <div class="alert alert-light text-center py-4 mb-4">
        <i class="bi bi-plug display-4 text-muted d-block mb-2"></i>
        <p class="text-muted mb-0">No services connected yet. Add one below.</p>
    </div>
    <?php else: ?>
    <div class="row g-3 mb-4">
        <?php foreach ($connBeans as $bean):
            $type = $bean->connector_type;
            $meta = $connectorMeta[$type] ?? ['name' => ucfirst($type), 'icon' => 'bi-plug', 'color' => 'secondary'];
            $metadata = json_decode($bean->metadata_json ?: '{}', true);
            $isOwner = ($bean->member_id == $member->id);

            // Determine display name
            $displayName = $bean->connection_name ?: $bean->external_name ?: $meta['name'];
            $externalInfo = $bean->external_eid ?: $bean->external_url ?: '';

            // Build manage URL based on type
            $manageUrls = [
                'atlassian' => '/atlassian',
                'github' => '/github/repos',
                'shopify' => '/shopify',
                'anthropic' => '/anthropic/keys',
                'mailgun' => '/mailgun',
                'stitch' => '/stitch',
            ];
            $manageUrl = $manageUrls[$type] ?? '/settings/connections';
        ?>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <div class="me-3">
                            <div class="rounded-circle bg-<?= $meta['color'] ?>-subtle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                                <i class="bi <?= $meta['icon'] ?> fs-4 text-<?= $meta['color'] ?>"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-0"><?= h($displayName) ?></h6>
                                    <small class="text-muted"><?= h($meta['name']) ?></small>
                                </div>
                                <div>
                                    <?php if ($bean->enabled): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Disabled</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($externalInfo): ?>
                            <div class="mt-1">
                                <small class="text-muted">
                                    <?php if ($type === 'github' && !empty($metadata['user_login'])): ?>
                                    <img src="https://github.com/<?= h($metadata['user_login']) ?>.png?size=16" class="rounded-circle me-1" width="16" height="16">
                                    @<?= h($metadata['user_login']) ?>
                                    <?php elseif ($type === 'shopify'): ?>
                                    <i class="bi bi-shop me-1"></i><?= h($externalInfo) ?>
                                    <?php elseif ($type === 'anthropic'): ?>
                                    <i class="bi bi-key me-1"></i><?= h($bean->connection_name ?: 'API Key') ?>
                                    <?php elseif ($type === 'mailgun'): ?>
                                    <i class="bi bi-envelope me-1"></i><?= h($metadata['domain'] ?? $externalInfo) ?>
                                    <?php else: ?>
                                    <?= h($externalInfo) ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                            <?php endif; ?>
                            <?php if (!$isOwner && $bean->shared): ?>
                            <div class="mt-1">
                                <span class="badge bg-info" style="font-size: 0.65rem;">
                                    <i class="bi bi-people me-1"></i>Shared
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-0 pt-0">
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="<?= h($manageUrl) ?>" class="btn btn-sm btn-outline-<?= $meta['color'] ?>">
                            <i class="bi bi-gear me-1"></i>Manage
                        </a>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="testConnection('<?= $type ?>', '/connections/test/<?= (int)$bean->id ?>')">
                            <i class="bi bi-wifi me-1"></i>Test
                        </button>
                        <?php if ($isOwner): ?>
                        <a href="/connections/disconnect/<?= (int)$bean->id ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Disconnect this <?= h($meta['name']) ?> connection?')">
                            <i class="bi bi-x-lg"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Available Connectors (grouped by category) -->
    <?php
    // Group connectors by category
    $connectorCategories = [
        'E-Commerce' => ['icon' => 'bi-shop', 'color' => 'success', 'keys' => ['shopify']],
        'Development' => ['icon' => 'bi-code-slash', 'color' => 'primary', 'keys' => ['github', 'atlassian']],
        'AI & Automation' => ['icon' => 'bi-cpu', 'color' => 'warning', 'keys' => ['anthropic']],
        'Communication' => ['icon' => 'bi-envelope', 'color' => 'info', 'keys' => ['mailgun']],
        'Design' => ['icon' => 'bi-palette', 'color' => 'secondary', 'keys' => ['stitch']],
    ];
    // Add any connectors not in a category to "Other"
    $categorized = array_merge(...array_column($connectorCategories, 'keys'));
    $uncategorized = array_diff(array_keys($connectorMeta), $categorized);
    if (!empty($uncategorized)) {
        $connectorCategories['Other'] = ['icon' => 'bi-grid', 'color' => 'secondary', 'keys' => array_values($uncategorized)];
    }
    ?>
    <h5 class="mb-3"><i class="bi bi-grid me-2"></i>Available Connectors</h5>
    <?php foreach ($connectorCategories as $catName => $catInfo):
        // Only show categories that have registered connectors
        $catConnectors = array_filter($catInfo['keys'], fn($k) => isset($connectorMeta[$k]));
        if (empty($catConnectors)) continue;
    ?>
    <h6 class="text-muted mb-2 mt-3">
        <i class="bi <?= $catInfo['icon'] ?> me-1"></i> <?= $catName ?>
    </h6>
    <div class="row g-3 mb-3">
        <?php foreach ($catConnectors as $key):
            $cMeta = $connectorMeta[$key];
            $isConnected = !empty($connections[$key]['connected']);
            $authType = $cMeta['auth_type'] ?? 'oauth';
            $connectUrl = $authType === 'api_key' ? "/connections/add/{$key}" : "/connections/connect/{$key}";
            $comingSoon = $connections[$key]['coming_soon'] ?? false;
            $available = $connections[$key]['available'] ?? true;
        ?>
        <div class="col-md-4 col-lg-3">
            <div class="card h-100 text-center <?= $comingSoon ? 'border-dashed opacity-75' : '' ?> <?= $isConnected ? 'border-success border-opacity-50' : '' ?>">
                <div class="card-body py-3">
                    <div class="rounded-circle bg-<?= $cMeta['color'] ?>-subtle d-inline-flex align-items-center justify-content-center mb-2" style="width: 48px; height: 48px;">
                        <i class="bi <?= $cMeta['icon'] ?> fs-4 text-<?= $cMeta['color'] ?>"></i>
                    </div>
                    <h6 class="mb-1">
                        <?= h($cMeta['name']) ?>
                        <?php if ($isConnected): ?>
                        <i class="bi bi-check-circle-fill text-success ms-1" style="font-size: 0.75rem;"></i>
                        <?php endif; ?>
                    </h6>
                    <p class="text-muted small mb-2" style="font-size: 0.75rem;"><?= h($cMeta['description']) ?></p>
                    <div class="mb-2">
                        <?php foreach (array_slice($cMeta['features'] ?? [], 0, 3) as $feat): ?>
                        <span class="badge bg-light text-dark" style="font-size: 0.65rem;"><?= h($feat) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php
                    // OAuth connectors: show the redirect URL that must be registered
                    // in the provider's app settings, plus any credential/mismatch issues.
                    $callbackUrl = $connections[$key]['callback_url'] ?? null;
                    $configuredUri = $connections[$key]['redirect_uri'] ?? null;
                    $isConfigured = $connections[$key]['configured'] ?? true;
                    $uriMismatch = $configuredUri && $callbackUrl && $configuredUri !== $callbackUrl;
                    if ($callbackUrl && !$comingSoon):
                    ?>
                    <div class="border-top pt-2 mt-2 text-start">
                        <?php if (!$isConfigured): ?>
                        <div class="badge bg-warning-subtle text-warning-emphasis mb-1" style="font-size: 0.65rem;">
                            <i class="bi bi-exclamation-triangle me-1"></i>Credentials not configured
                        </div>
                        <?php endif; ?>
                        <label class="text-muted d-block" style="font-size: 0.65rem;">Redirect URL</label>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control font-monospace" style="font-size: 0.6rem;"
                                   value="<?= h($callbackUrl) ?>" readonly
                                   onclick="this.select()">
                            <button class="btn btn-outline-secondary" type="button"
                                    title="Copy redirect URL"
                                    onclick="copyRedirectUrl(this, '<?= h($callbackUrl) ?>')">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                        <?php if ($uriMismatch): ?>
                        <div class="text-danger mt-1" style="font-size: 0.6rem;">
                            <i class="bi bi-exclamation-circle me-1"></i>
                            Configured redirect_uri differs: <span class="font-monospace"><?= h($configuredUri) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-transparent border-0 pt-0">
                    <?php if ($comingSoon): ?>
                    <button class="btn btn-sm btn-outline-secondary w-100" disabled>
                        <i class="bi bi-clock me-1"></i>Coming Soon
                    </button>
                    <?php elseif (!$available): ?>
                    <a href="/settings/subscription" class="btn btn-sm btn-outline-primary w-100">
                        <i class="bi bi-arrow-up-circle me-1"></i>Upgrade
                    </a>
                    <?php elseif ($isConnected): ?>
                    <a href="<?= h($connectUrl) ?>" class="btn btn-sm btn-outline-<?= $cMeta['color'] ?> w-100">
                        <i class="bi bi-plus-lg me-1"></i>Add Another
                    </a>
                    <?php else: ?>
                    <a href="<?= h($connectUrl) ?>" class="btn btn-sm btn-<?= $cMeta['color'] ?> w-100">
                        <i class="bi bi-plug me-1"></i>Connect
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

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
function copyRedirectUrl(btn, url) {
    const icon = btn.querySelector('i');
    const restore = () => { icon.className = 'bi bi-clipboard'; };

    const done = (ok) => {
        icon.className = ok ? 'bi bi-check-lg text-success' : 'bi bi-x-lg text-danger';
        setTimeout(restore, 1500);
    };

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(() => done(true), () => done(false));
        return;
    }

    // Fallback for non-HTTPS / older browsers
    const ta = document.createElement('textarea');
    ta.value = url;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try {
        done(document.execCommand('copy'));
    } catch (e) {
        done(false);
    }
    document.body.removeChild(ta);
}

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
            // Remove credit warning if present (backend already cleared it)
            const warning = document.getElementById('credit-warning');
            if (warning) warning.remove();
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

function dismissCreditWarning() {
    const alert = document.getElementById('credit-warning');
    fetch('/anthropic/clearwarning', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(() => {
        alert.remove();
    }).catch(() => {
        alert.remove(); // Remove visually even if request fails
    });
}
</script>

<?php
// Include onboarding wizard modal
include __DIR__ . '/../partials/onboardingwizard.php';
?>
