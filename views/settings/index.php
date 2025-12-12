<div class="container-fluid">
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <h1 class="mb-4">Settings</h1>

            <!-- Profile Section -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-person"></i> Profile
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-2 text-center">
                            <?php if (!empty($member->avatar_url)): ?>
                            <img src="<?= htmlspecialchars($member->avatar_url) ?>" alt="Avatar" class="rounded-circle" width="80">
                            <?php else: ?>
                            <div class="bg-secondary rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                                <i class="bi bi-person-fill text-white" style="font-size: 2rem;"></i>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-10">
                            <h4><?= htmlspecialchars($member->display_name ?? $member->email) ?></h4>
                            <p class="text-muted mb-1"><?= htmlspecialchars($member->email) ?></p>
                            <small class="text-muted">
                                Member since <?= date('F j, Y', strtotime($member->created_at)) ?>
                            </small>
                            <?php if (!empty($member->google_id)): ?>
                            <br>
                            <span class="badge bg-danger mt-2">
                                <i class="bi bi-google"></i> Google Account
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Atlassian Connection -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-cloud"></i> Atlassian Connection
                </div>
                <div class="card-body">
                    <?php if (empty($sites)): ?>
                    <p class="text-muted">No Atlassian sites connected.</p>
                    <?php if ($atlassianConfigured): ?>
                    <a href="/atlassian/connect" class="btn btn-primary">
                        <i class="bi bi-link-45deg"></i> Connect Atlassian
                    </a>
                    <?php endif; ?>
                    <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($sites as $site): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?= htmlspecialchars($site->site_name) ?></strong>
                                <br>
                                <small class="text-muted"><?= htmlspecialchars($site->site_url) ?></small>
                            </div>
                            <span class="badge bg-success">Connected</span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="mt-3">
                        <a href="/atlassian" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-gear"></i> Manage Connections
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Digest Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-envelope"></i> Email Digest Settings
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Email digest settings are configured per board. Go to
                        <a href="/boards">Board Management</a> to configure digest schedules.
                    </p>
                    <?php if (!empty($stats['total_digests'])): ?>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle"></i>
                        You have received <strong><?= $stats['total_digests'] ?></strong> digest emails.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Statistics -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-graph-up"></i> Your Statistics
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <h3 class="text-primary"><?= $stats['total_boards'] ?? 0 ?></h3>
                            <small class="text-muted">Tracked Boards</small>
                        </div>
                        <div class="col-md-3">
                            <h3 class="text-success"><?= $stats['total_analyses'] ?? 0 ?></h3>
                            <small class="text-muted">Analyses Run</small>
                        </div>
                        <div class="col-md-3">
                            <h3 class="text-info"><?= $stats['total_digests'] ?? 0 ?></h3>
                            <small class="text-muted">Digests Sent</small>
                        </div>
                        <div class="col-md-3">
                            <h3 class="text-secondary"><?= count($sites) ?></h3>
                            <small class="text-muted">Connected Sites</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Account Actions -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-gear"></i> Account
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="/member/edit" class="btn btn-outline-primary">
                            <i class="bi bi-pencil"></i> Edit Profile
                        </a>
                        <?php if (empty($member->google_id)): ?>
                        <a href="/member/password" class="btn btn-outline-secondary">
                            <i class="bi bi-key"></i> Change Password
                        </a>
                        <?php endif; ?>
                        <a href="/auth/logout" class="btn btn-outline-danger">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </div>
                </div>
            </div>

            <!-- Danger Zone -->
            <div class="card border-danger">
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
        </div>
    </div>
</div>

<script>
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
