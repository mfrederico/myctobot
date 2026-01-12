<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1"><i class="bi bi-shop text-success me-2"></i>Shopify Stores</h1>
            <p class="text-muted mb-0">Connect Shopify stores and link them to repos for theme development</p>
        </div>
        <a href="/settings/connections" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Connections
        </a>
    </div>

    <?php if (!empty($connections)): ?>
    <!-- Connected Stores -->
    <div class="row mb-4">
        <?php foreach ($connections as $conn): ?>
        <div class="col-12 mb-3">
            <div class="card <?= $conn->enabled ? 'border-success' : 'border-secondary' ?>">
                <div class="card-header <?= $conn->enabled ? 'bg-success text-white' : 'bg-secondary text-white' ?> d-flex justify-content-between align-items-center">
                    <span>
                        <i class="bi bi-shop me-2"></i>
                        <?= htmlspecialchars($conn->shop_name ?: $conn->shop_domain) ?>
                        <?php if (!$conn->enabled): ?>
                        <span class="badge bg-warning text-dark ms-2">Disabled</span>
                        <?php endif; ?>
                    </span>
                    <div>
                        <button type="button" class="btn btn-sm btn-light test-connection-btn" data-id="<?= $conn->id ?>">
                            <i class="bi bi-check2-circle"></i> Test
                        </button>
                        <a href="https://<?= htmlspecialchars($conn->shop_domain) ?>/admin" target="_blank"
                           class="btn btn-sm btn-light ms-1">
                            <i class="bi bi-box-arrow-up-right"></i> Admin
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p class="mb-2">
                                <i class="bi bi-globe me-1 text-muted"></i>
                                <a href="https://<?= htmlspecialchars($conn->shop_domain) ?>" target="_blank">
                                    <?= htmlspecialchars($conn->shop_domain) ?>
                                </a>
                            </p>
                            <?php if ($conn->shop_email): ?>
                            <p class="mb-2">
                                <i class="bi bi-envelope me-1 text-muted"></i>
                                <?= htmlspecialchars($conn->shop_email) ?>
                            </p>
                            <?php endif; ?>
                            <?php if ($conn->connection_name): ?>
                            <p class="mb-2">
                                <i class="bi bi-tag me-1 text-muted"></i>
                                <?= htmlspecialchars($conn->connection_name) ?>
                            </p>
                            <?php endif; ?>
                            <p class="mb-0 text-muted small">
                                Connected <?= date('M j, Y', strtotime($conn->created_at)) ?>
                                by <?= htmlspecialchars($conn->created_by_name ?: 'Unknown') ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <!-- Repo Linking -->
                            <div class="mb-3">
                                <label class="form-label small text-muted mb-1">
                                    <i class="bi bi-github me-1"></i> Linked Repository
                                </label>
                                <?php if ($conn->repo_connection_id): ?>
                                    <?php
                                    $linkedRepo = null;
                                    foreach ($repos as $repo) {
                                        if ($repo->id == $conn->repo_connection_id) {
                                            $linkedRepo = $repo;
                                            break;
                                        }
                                    }
                                    ?>
                                    <div class="d-flex align-items-center">
                                        <span class="badge bg-primary me-2">
                                            <?= $linkedRepo ? htmlspecialchars("{$linkedRepo->repo_owner}/{$linkedRepo->repo_name}") : "Repo #{$conn->repo_connection_id}" ?>
                                        </span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary unlink-repo-btn"
                                                data-id="<?= $conn->id ?>">
                                            <i class="bi bi-x-lg"></i> Unlink
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="input-group input-group-sm">
                                        <select class="form-select link-repo-select" data-id="<?= $conn->id ?>">
                                            <option value="">Select a repo...</option>
                                            <?php foreach ($repos as $repo): ?>
                                            <option value="<?= $repo->id ?>">
                                                <?= htmlspecialchars("{$repo->repo_owner}/{$repo->repo_name}") ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-outline-primary link-repo-btn" data-id="<?= $conn->id ?>">
                                            <i class="bi bi-link-45deg"></i> Link
                                        </button>
                                    </div>
                                    <div class="form-text">Link to a repo for AI Developer theme sync</div>
                                <?php endif; ?>
                            </div>

                            <!-- Actions -->
                            <div class="d-flex justify-content-end">
                                <a href="/shopify/disconnect/<?= $conn->id ?>" class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Disconnect <?= htmlspecialchars($conn->shop_domain) ?>? This cannot be undone.')">
                                    <i class="bi bi-trash"></i> Disconnect
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Add New Store -->
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-plus-circle"></i> Add Shopify Store
                </div>
                <div class="card-body">
                    <form method="POST" action="/shopify/add">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="shop_domain" class="form-label">Shop Domain <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="shop_domain" name="shop_domain"
                                           placeholder="your-store" required>
                                    <span class="input-group-text">.myshopify.com</span>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="connection_name" class="form-label">Friendly Name <span class="text-muted">(optional)</span></label>
                                <input type="text" class="form-control" id="connection_name" name="connection_name"
                                       placeholder="e.g., Client Store, Staging">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="access_token" class="form-label">Admin API Access Token <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="access_token" name="access_token"
                                   placeholder="shpat_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" required>
                            <div class="form-text">Starts with <code>shpat_</code></div>
                        </div>

                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-plug"></i> Connect Store
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-info-circle"></i> How to Get Your Token
                </div>
                <div class="card-body">
                    <ol class="small mb-0">
                        <li class="mb-2">Go to <a href="https://admin.shopify.com" target="_blank">Shopify Admin</a></li>
                        <li class="mb-2">Click <strong>Settings</strong> &rarr; <strong>Apps and sales channels</strong></li>
                        <li class="mb-2">Click <strong>Develop apps</strong></li>
                        <li class="mb-2">Create or select your app</li>
                        <li class="mb-2">Under <strong>API credentials</strong>, generate an <strong>Admin API access token</strong></li>
                        <li>Copy the token (starts with <code>shpat_</code>)</li>
                    </ol>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">
                    <i class="bi bi-shield-check"></i> Required Scopes
                </div>
                <div class="card-body">
                    <ul class="list-unstyled small mb-0">
                        <li><i class="bi bi-check text-success me-1"></i>read_themes</li>
                        <li><i class="bi bi-check text-success me-1"></i>write_themes</li>
                        <li class="text-muted"><i class="bi bi-dash me-1"></i>read_content (optional)</li>
                        <li class="text-muted"><i class="bi bi-dash me-1"></i>read_products (optional)</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Test connection buttons
    document.querySelectorAll('.test-connection-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.dataset.id;
            const originalHtml = this.innerHTML;
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            try {
                const response = await fetch(`/shopify/test/${id}`);
                const data = await response.json();

                let message = data.message || 'Unknown result';
                if (data.success) {
                    if (data.scopes && data.scopes.length > 0) {
                        message += '\n\nScopes: ' + data.scopes.join(', ');
                    }
                    alert('Success!\n\n' + message);
                } else {
                    if (data.missing_scopes && data.missing_scopes.length > 0) {
                        message += '\n\nMissing scopes:\n- ' + data.missing_scopes.join('\n- ');
                    }
                    alert('Issue:\n\n' + message);
                }
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                this.disabled = false;
                this.innerHTML = originalHtml;
            }
        });
    });

    // Link repo buttons
    document.querySelectorAll('.link-repo-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.dataset.id;
            const select = document.querySelector(`.link-repo-select[data-id="${id}"]`);
            const repoId = select.value;

            if (!repoId) {
                alert('Please select a repo');
                return;
            }

            const originalHtml = this.innerHTML;
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            try {
                const response = await fetch(`/shopify/linkrepo/${id}?repo_id=${repoId}`, { method: 'POST' });
                const data = await response.json();

                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                this.disabled = false;
                this.innerHTML = originalHtml;
            }
        });
    });

    // Unlink repo buttons
    document.querySelectorAll('.unlink-repo-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!confirm('Unlink this repo from the store?')) return;

            const id = this.dataset.id;
            const originalHtml = this.innerHTML;
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            try {
                const response = await fetch(`/shopify/unlinkrepo/${id}`, { method: 'POST' });
                const data = await response.json();

                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                this.disabled = false;
                this.innerHTML = originalHtml;
            }
        });
    });
});
</script>
