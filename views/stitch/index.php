<div class="container py-4" data-assist-page="stitch" data-assist-purpose="Connect and manage Google Stitch AI design integrations">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1"><i class="bi bi-palette text-info me-2"></i>Google Stitch</h1>
            <p class="text-muted mb-0">AI design tool for generating UI screens and production-ready code</p>
        </div>
        <a href="/settings/connections" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Connections
        </a>
    </div>

    <?php if (!empty($connections)): ?>
    <!-- Connected Accounts -->
    <div class="row mb-4">
        <?php foreach ($connections as $conn): ?>
        <?php $isOwner = ($conn->member_id == $member_id); ?>
        <?php $meta = json_decode($conn->metadata_json ?: '{}', true); ?>
        <div class="col-12 mb-3">
            <div class="card <?= $conn->enabled ? 'border-info' : 'border-secondary' ?>">
                <div class="card-header <?= $conn->enabled ? 'bg-info text-white' : 'bg-secondary text-white' ?> d-flex justify-content-between align-items-center">
                    <span>
                        <i class="bi bi-google me-2"></i>
                        <?= h($conn->external_eid ?: $conn->connection_name ?: 'Google Account') ?>
                        <?php if (!$conn->enabled): ?>
                        <span class="badge bg-warning text-dark ms-2">Disabled</span>
                        <?php endif; ?>
                        <?php if ($conn->shared && !$isOwner): ?>
                        <span class="badge bg-light text-dark ms-2">
                            <i class="bi bi-people-fill"></i> Shared
                        </span>
                        <?php endif; ?>
                    </span>
                    <div>
                        <button type="button" class="btn btn-sm btn-light test-connection-btn" data-id="<?= $conn->id ?>">
                            <i class="bi bi-check2-circle"></i> Test
                        </button>
                        <a href="/stitch/browse/<?= $conn->id ?>" class="btn btn-sm btn-light ms-1">
                            <i class="bi bi-folder"></i> Projects
                        </a>
                        <?php if ($isOwner): ?>
                        <a href="/stitch/disconnect/<?= $conn->id ?>"
                           class="btn btn-sm btn-danger ms-1"
                           onclick="return confirm('Disconnect this Google account?')">
                            <i class="bi bi-trash"></i> Disconnect
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <?php if ($conn->external_eid): ?>
                            <p class="mb-2">
                                <i class="bi bi-envelope me-1 text-muted"></i>
                                <?= h($conn->external_eid) ?>
                            </p>
                            <?php endif; ?>
                            <?php if (($meta['gcp_project_id'] ?? '')): ?>
                            <p class="mb-2">
                                <i class="bi bi-cloud me-1 text-muted"></i>
                                GCP Project: <code><?= h(($meta['gcp_project_id'] ?? '')) ?></code>
                            </p>
                            <?php endif; ?>
                            <p class="mb-2">
                                <i class="bi bi-clock me-1 text-muted"></i>
                                Token expires: <?= $conn->expires_at ? date('M j, Y g:i A', strtotime($conn->expires_at)) : 'Unknown' ?>
                                <?php if ($conn->expires_at && strtotime($conn->expires_at) > time()): ?>
                                <span class="badge bg-success ms-1">Valid</span>
                                <?php else: ?>
                                <span class="badge bg-warning text-dark ms-1">Will auto-refresh</span>
                                <?php endif; ?>
                            </p>
                            <p class="mb-0 text-muted small">
                                Connected <?= date('M j, Y', strtotime($conn->created_at)) ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <?php if ($isOwner): ?>
                            <div class="mb-3">
                                <label class="form-label small text-muted mb-1">
                                    <i class="bi bi-cloud me-1"></i> GCP Project ID
                                </label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control gcp-project-input"
                                           value="<?= h(($meta['gcp_project_id'] ?? '') ?? '') ?>"
                                           placeholder="my-gcp-project-id"
                                           data-id="<?= $conn->id ?>">
                                    <button class="btn btn-outline-info save-project-btn" type="button" data-id="<?= $conn->id ?>">
                                        <i class="bi bi-check"></i> Save
                                    </button>
                                </div>
                                <small class="text-muted">Required for API quota attribution</small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Test Result -->
                    <div class="test-result mt-2" id="test-result-<?= $conn->id ?>" style="display: none;">
                        <div class="alert mb-0 py-2"></div>
                    </div>

                    <!-- Projects link now goes to /stitch/browse/{id} -->
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Connect New Account -->
    <div class="card">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Connect Google Account</h5>
        </div>
        <div class="card-body">
            <?php if ($oauth_configured): ?>
            <p class="text-muted mb-3">
                Connect your Google account to use Stitch AI for generating UI screens and exporting production-ready code.
            </p>
            <div class="mb-3">
                <label class="form-label">GCP Project ID <small class="text-muted">(optional, can set later)</small></label>
                <input type="text" class="form-control" id="gcpProjectId" placeholder="my-gcp-project-id">
                <small class="text-muted">Your Google Cloud project ID for API quota attribution</small>
            </div>
            <button type="button" class="btn btn-info" id="connectBtn">
                <i class="bi bi-google me-2"></i> Connect with Google
            </button>
            <?php else: ?>
            <div class="alert alert-warning mb-0">
                <i class="bi bi-exclamation-triangle me-2"></i>
                Google OAuth is not configured. Please add your OAuth client credentials to <code>conf/stitch.ini</code>.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Features Info -->
    <div class="card mt-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Available MCP Tools</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <h6><i class="bi bi-folder-plus text-info me-1"></i> Project Management</h6>
                    <ul class="list-unstyled small text-muted">
                        <li><code>list_projects</code> - List all projects</li>
                        <li><code>create_project</code> - Create a new project</li>
                        <li><code>get_project</code> - Get project details</li>
                    </ul>
                </div>
                <div class="col-md-4 mb-3">
                    <h6><i class="bi bi-brush text-info me-1"></i> Screen Design</h6>
                    <ul class="list-unstyled small text-muted">
                        <li><code>list_screens</code> - List project screens</li>
                        <li><code>get_screen</code> - Get screen details</li>
                        <li><code>generate_screen_from_text</code> - AI generate screen</li>
                    </ul>
                </div>
                <div class="col-md-4 mb-3">
                    <h6><i class="bi bi-code-slash text-info me-1"></i> Editing &amp; Variants</h6>
                    <ul class="list-unstyled small text-muted">
                        <li><code>edit_screens</code> - Edit screens with AI prompt</li>
                        <li><code>generate_variants</code> - Generate screen variants</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Connect button
    const connectBtn = document.getElementById('connectBtn');
    if (connectBtn) {
        connectBtn.addEventListener('click', function() {
            const gcpProject = document.getElementById('gcpProjectId')?.value || '';
            // Store GCP project in session via AJAX before redirect
            if (gcpProject) {
                fetch('/stitch/connect', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'gcp_project=' + encodeURIComponent(gcpProject)
                });
                // Small delay then redirect
                setTimeout(() => { window.location.href = '/stitch/connect'; }, 100);
            } else {
                window.location.href = '/stitch/connect';
            }
        });
    }

    // Test connection buttons
    document.querySelectorAll('.test-connection-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const resultDiv = document.getElementById('test-result-' + id);
            const alertDiv = resultDiv.querySelector('.alert');

            this.disabled = true;
            this.innerHTML = '<i class="bi bi-hourglass-split"></i> Testing...';
            resultDiv.style.display = 'block';
            alertDiv.className = 'alert alert-info mb-0 py-2';
            alertDiv.textContent = 'Testing connection...';

            fetch('/stitch/test/' + id)
                .then(r => r.json())
                .then(data => {
                    alertDiv.className = 'alert mb-0 py-2 ' + (data.success ? 'alert-success' : 'alert-danger');
                    alertDiv.textContent = data.message;
                    this.disabled = false;
                    this.innerHTML = '<i class="bi bi-check2-circle"></i> Test';
                })
                .catch(err => {
                    alertDiv.className = 'alert alert-danger mb-0 py-2';
                    alertDiv.textContent = 'Error: ' + err.message;
                    this.disabled = false;
                    this.innerHTML = '<i class="bi bi-check2-circle"></i> Test';
                });
        });
    });

    // Save GCP Project ID buttons
    document.querySelectorAll('.save-project-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const input = document.querySelector('.gcp-project-input[data-id="' + id + '"]');
            const gcpProjectId = input?.value || '';

            this.disabled = true;

            fetch('/stitch/updateproject/' + id, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'gcp_project_id=' + encodeURIComponent(gcpProjectId)
            })
            .then(r => r.json())
            .then(data => {
                this.disabled = false;
                if (data.success) {
                    this.innerHTML = '<i class="bi bi-check text-success"></i> Saved';
                    setTimeout(() => { this.innerHTML = '<i class="bi bi-check"></i> Save'; }, 2000);
                } else {
                    alert(data.message || 'Failed to save');
                }
            })
            .catch(err => {
                this.disabled = false;
                alert('Error: ' + err.message);
            });
        });
    });
});
</script>
