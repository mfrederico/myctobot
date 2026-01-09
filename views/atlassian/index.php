<div class="container-fluid">
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <h1 class="mb-4">Atlassian Connection</h1>

            <?php if (empty($sites)): ?>
            <!-- No Sites Connected -->
            <div class="card">
                <div class="card-header bg-warning">
                    <i class="bi bi-exclamation-triangle"></i> No Atlassian Sites Connected
                </div>
                <div class="card-body text-center py-5">
                    <i class="bi bi-cloud-slash" style="font-size: 4rem; color: #ccc;"></i>
                    <h4 class="mt-3">Connect Your Jira Account</h4>
                    <p class="text-muted">Link your Atlassian account to access your Jira boards and enable sprint analysis.</p>
                    <?php if ($atlassianConfigured): ?>
                    <a href="/atlassian/connect" class="btn btn-primary btn-lg">
                        <i class="bi bi-link-45deg"></i> Connect Atlassian
                    </a>
                    <?php else: ?>
                    <div class="alert alert-danger mt-3">
                        Atlassian OAuth is not configured. Please contact the administrator.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php else: ?>
            <!-- Connected Sites -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-cloud-check"></i> Connected Atlassian Sites</span>
                    <a href="/atlassian/connect" class="btn btn-sm btn-light">
                        <i class="bi bi-plus"></i> Add Another Site
                    </a>
                </div>
                <div class="card-body">
                    <?php foreach ($sites as $site): ?>
                    <?php $webhooks = $webhooksPerSite[$site->cloud_id] ?? []; ?>
                    <div class="p-3 bg-light rounded mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-1">
                                    <i class="bi bi-building"></i>
                                    <?= htmlspecialchars($site->site_name) ?>
                                </h5>
                                <a href="<?= htmlspecialchars($site->site_url) ?>" target="_blank" class="text-muted small">
                                    <?= htmlspecialchars($site->site_url) ?>
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                                <br>
                                <small class="text-muted">
                                    Token expires: <?= date('M j, Y H:i', strtotime($site->expires_at)) ?>
                                    <?php if (strtotime($site->expires_at) < time()): ?>
                                    <span class="badge bg-danger">Expired</span>
                                    <?php elseif (strtotime($site->expires_at) < time() + 86400): ?>
                                    <span class="badge bg-warning">Expiring Soon</span>
                                    <?php else: ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div class="btn-group">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="refreshToken('<?= $site->cloud_id ?>')">
                                    <i class="bi bi-arrow-repeat"></i> Refresh
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="disconnectSite('<?= $site->cloud_id ?>', '<?= htmlspecialchars($site->site_name) ?>')">
                                    <i class="bi bi-x-circle"></i> Disconnect
                                </button>
                            </div>
                        </div>

                        <!-- Actions for this site -->
                        <div class="mt-3 pt-3 border-top">
                            <div class="d-flex gap-2 mb-3">
                                <a href="/boards/discover?cloud_id=<?= urlencode($site->cloud_id) ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-search"></i> Discover Boards
                                </a>
                                <a href="/boards?cloud_id=<?= urlencode($site->cloud_id) ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-kanban"></i> Manage Boards
                                </a>
                            </div>

                            <!-- Webhooks for this site -->
                            <?php if (!empty($webhooks)): ?>
                            <h6 class="mb-2"><i class="bi bi-link-45deg"></i> Registered Webhooks</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0 bg-white">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 60px;">ID</th>
                                            <th>URL</th>
                                            <th>JQL Filter</th>
                                            <th>Events</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($webhooks as $webhook): ?>
                                        <tr>
                                            <td><code><?= htmlspecialchars($webhook['id'] ?? 'N/A') ?></code></td>
                                            <td class="text-break" style="max-width: 200px;">
                                                <small><?= htmlspecialchars($webhook['url'] ?? 'N/A') ?></small>
                                            </td>
                                            <td><code><?= htmlspecialchars($webhook['jqlFilter'] ?? 'N/A') ?></code></td>
                                            <td>
                                                <?php if (!empty($webhook['events'])): ?>
                                                    <?php foreach ($webhook['events'] as $event): ?>
                                                    <span class="badge bg-secondary me-1"><?= htmlspecialchars($event) ?></span>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">None</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-2">
                                <a href="/atlassian/refreshwebhook?cloud_id=<?= urlencode($site->cloud_id) ?>"
                                   class="btn btn-sm btn-outline-warning"
                                   onclick="return confirm('This will delete existing webhooks and register a new one. Continue?')">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh Webhook
                                </a>
                            </div>
                            <?php else: ?>
                            <div class="d-flex align-items-center gap-2">
                                <small class="text-muted"><i class="bi bi-link-45deg"></i> No webhooks registered</small>
                                <a href="/atlassian/refreshwebhook?cloud_id=<?= urlencode($site->cloud_id) ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-plus-lg"></i> Register Webhook
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Danger Zone -->
            <div class="card mt-4 border-danger">
                <div class="card-header bg-danger text-white">
                    <i class="bi bi-exclamation-triangle"></i> Danger Zone
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Disconnecting all Atlassian sites will remove all your boards and analysis history.
                    </p>
                    <button type="button" class="btn btn-outline-danger" onclick="disconnectAll()">
                        <i class="bi bi-x-circle"></i> Disconnect All Atlassian Sites
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Help Section -->
            <div class="card mt-4">
                <div class="card-header">
                    <i class="bi bi-question-circle"></i> About Atlassian Connection
                </div>
                <div class="card-body">
                    <h6>What permissions does MyCTOBot need?</h6>

                    <p class="small text-muted mb-2"><strong>Read Permissions</strong> - Required for sprint analysis and digest emails:</p>
                    <ul class="small text-muted">
                        <li><strong>read:jira-work</strong> - Read your tickets to analyze sprint progress and generate insights</li>
                        <li><strong>read:jira-user</strong> - See who's assigned to tickets for workload analysis</li>
                        <li><strong>read:board-scope:jira-software</strong> - Access your Kanban/Scrum boards</li>
                        <li><strong>read:sprint:jira-software</strong> - Read sprint data for velocity and burndown analysis</li>
                        <li><strong>read:project:jira</strong> - Access project settings and structure</li>
                    </ul>

                    <p class="small text-muted mb-2"><strong>Write Permissions</strong> - Required for AI Developer feature (Enterprise):</p>
                    <ul class="small text-muted">
                        <li><strong>write:jira-work</strong> - Post comments on tickets when AI Developer needs clarification or completes work</li>
                        <li><strong>manage:jira-webhook</strong> - Automatically listen for ticket updates when you add the <code>ai-dev</code> label</li>
                    </ul>

                    <div class="alert alert-info small py-2 mt-3">
                        <i class="bi bi-shield-check"></i>
                        <strong>Your data is safe:</strong> MyCTOBot uses OAuth 2.0 for secure authentication. We never store your Atlassian password, and write permissions are only used when you explicitly trigger AI Developer on a ticket.
                    </div>

                    <p class="small text-muted mb-0">
                        You can revoke access at any time from your
                        <a href="https://id.atlassian.com/manage-profile/apps" target="_blank">Atlassian account settings</a>.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function refreshToken(cloudId) {
    fetch('/atlassian/refresh/' + cloudId, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Token refreshed successfully');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to refresh token'));
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
}

function disconnectSite(cloudId, siteName) {
    if (confirm('Are you sure you want to disconnect "' + siteName + '"? This will remove all boards from this site.')) {
        fetch('/atlassian/disconnect/' + cloudId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Failed to disconnect'));
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
        });
    }
}

function disconnectAll() {
    if (confirm('Are you sure you want to disconnect ALL Atlassian sites? This will remove all your boards and analysis history.')) {
        if (confirm('This action cannot be undone. Are you absolutely sure?')) {
            fetch('/atlassian/disconnectall', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('All Atlassian sites disconnected');
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to disconnect'));
                }
            })
            .catch(error => {
                alert('Error: ' + error.message);
            });
        }
    }
}
</script>
