<div class="container py-4" data-assist-page="anthropic/keys" data-assist-purpose="Manage Anthropic API keys for AI-powered features">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1"><i class="bi bi-key text-warning me-2"></i>API Keys</h1>
            <p class="text-muted mb-0">Manage Anthropic API keys for AI-powered features</p>
        </div>
        <a href="/settings/connections" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Connections
        </a>
    </div>

    <?php if (!empty($keys)): ?>
    <!-- Existing Keys -->
    <div class="row mb-4">
        <?php foreach ($keys as $key): ?>
        <div class="col-12 mb-3">
            <div class="card border-success">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <span>
                        <i class="bi bi-key me-2"></i>
                        <?= h($key['name']) ?>
                        <?php if ($key['shared'] && !$key['is_owner']): ?>
                        <span class="badge bg-info ms-2" title="Shared by <?= h($key['owner_name'] ?: 'Unknown') ?>">
                            <i class="bi bi-people-fill"></i> Shared
                        </span>
                        <?php elseif ($key['shared']): ?>
                        <span class="badge bg-info ms-2"><i class="bi bi-people-fill"></i> Shared</span>
                        <?php endif; ?>
                    </span>
                    <div>
                        <button type="button" class="btn btn-sm btn-light test-key-btn" data-id="<?= $key['id'] ?>">
                            <i class="bi bi-check2-circle"></i> Test
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p class="mb-2">
                                <i class="bi bi-lock me-1 text-muted"></i>
                                <code><?= h($key['masked_key']) ?></code>
                            </p>
                            <p class="mb-2">
                                <i class="bi bi-cpu me-1 text-muted"></i>
                                Model: <?= h($key['model'] ?: 'claude-sonnet-4-20250514') ?>
                            </p>
                            <p class="mb-0 text-muted small">
                                Added <?= date('M j, Y', strtotime($key['created_at'])) ?>
                                by <?= h($key['owner_name'] ?: 'Unknown') ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex justify-content-between align-items-center">
                                <?php if ($key['is_owner']): ?>
                                <!-- Share toggle (owner only) -->
                                <div class="form-check form-switch">
                                    <input class="form-check-input share-toggle" type="checkbox"
                                           data-id="<?= $key['id'] ?>"
                                           <?= $key['shared'] ? 'checked' : '' ?>>
                                    <label class="form-check-label small">Share with workspace</label>
                                </div>
                                <a href="/anthropic/deletekey/<?= $key['id'] ?>" class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Delete this API key? Any boards using it will switch to local runner.')">
                                    <i class="bi bi-trash"></i> Delete
                                </a>
                                <?php else: ?>
                                <span class="text-muted small">
                                    <i class="bi bi-person"></i> Owned by <?= h($key['owner_name'] ?: 'Unknown') ?>
                                </span>
                                <span></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Add New Key -->
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <i class="bi bi-plus-circle"></i> Add API Key
                </div>
                <div class="card-body">
                    <form method="POST" action="/anthropic/addkey">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= h($name) ?>" value="<?= h($value) ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="key_name" class="form-label">Key Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="key_name" name="key_name"
                                       placeholder="e.g., Production, Development" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="key_model" class="form-label">Default Model</label>
                                <select class="form-select" id="key_model" name="key_model">
                                    <option value="claude-sonnet-4-20250514" selected>Claude Sonnet 4 (Recommended)</option>
                                    <option value="claude-opus-4-20250514">Claude Opus 4</option>
                                    <option value="claude-3-5-haiku-20241022">Claude 3.5 Haiku</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="api_key" class="form-label">API Key <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="api_key" name="api_key"
                                   placeholder="sk-ant-api03-..." required>
                            <div class="form-text">Starts with <code>sk-ant-</code></div>
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="shared" name="shared" value="1">
                                <label class="form-check-label" for="shared">
                                    <i class="bi bi-people-fill me-1"></i> Share with workspace members
                                </label>
                                <div class="form-text">Allow other workspace members to use this API key</div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-plus-lg"></i> Add Key
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-info-circle"></i> Getting an API Key
                </div>
                <div class="card-body">
                    <ol class="small mb-0">
                        <li class="mb-2">Go to <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a></li>
                        <li class="mb-2">Sign in or create an account</li>
                        <li class="mb-2">Navigate to <strong>API Keys</strong></li>
                        <li class="mb-2">Click <strong>Create Key</strong></li>
                        <li>Copy your key (starts with <code>sk-ant-</code>)</li>
                    </ol>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">
                    <i class="bi bi-shield-check"></i> Security
                </div>
                <div class="card-body">
                    <ul class="list-unstyled small mb-0">
                        <li class="mb-2">
                            <i class="bi bi-lock text-success me-1"></i>
                            Encrypted at rest
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-person-lock text-success me-1"></i>
                            Owner controls sharing
                        </li>
                        <li>
                            <i class="bi bi-eye-slash text-success me-1"></i>
                            Key never displayed after saving
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Test key buttons
    document.querySelectorAll('.test-key-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.dataset.id;
            const originalHtml = this.innerHTML;
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            try {
                const response = await fetch(`/anthropic/testkey/${id}`);
                const data = await response.json();

                if (data.success) {
                    alert('Success! API key is valid and working.');
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                this.disabled = false;
                this.innerHTML = originalHtml;
            }
        });
    });

    // Share toggle
    document.querySelectorAll('.share-toggle').forEach(toggle => {
        toggle.addEventListener('change', async function() {
            const id = this.dataset.id;

            try {
                const response = await fetch(`/anthropic/toggleshare/${id}`, { method: 'POST' });
                const data = await response.json();

                if (!data.success) {
                    alert('Error: ' + data.message);
                    this.checked = !this.checked; // Revert
                } else {
                    // Reload to update badges
                    location.reload();
                }
            } catch (err) {
                alert('Error: ' + err.message);
                this.checked = !this.checked; // Revert
            }
        });
    });
});
</script>
