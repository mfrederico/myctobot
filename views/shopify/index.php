<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1"><i class="bi bi-shop text-success me-2"></i>Shopify Integration</h1>
            <p class="text-muted mb-0">Connect your Shopify store for theme development</p>
        </div>
        <a href="/settings/connections" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Connections
        </a>
    </div>

    <?php if ($isConnected): ?>
    <!-- Connected State -->
    <div class="card border-success mb-4">
        <div class="card-header bg-success text-white">
            <i class="bi bi-check-circle-fill me-2"></i>Connected to Shopify
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h5><?= htmlspecialchars($connectionDetails['shop_info']['name'] ?? $shop) ?></h5>
                    <p class="text-muted mb-2">
                        <i class="bi bi-globe me-1"></i>
                        <a href="https://<?= htmlspecialchars($shop) ?>" target="_blank">
                            <?= htmlspecialchars($shop) ?>
                        </a>
                    </p>
                    <?php if (!empty($connectionDetails['shop_info']['email'])): ?>
                    <p class="text-muted mb-2">
                        <i class="bi bi-envelope me-1"></i>
                        <?= htmlspecialchars($connectionDetails['shop_info']['email']) ?>
                    </p>
                    <?php endif; ?>
                    <?php if (!empty($connectionDetails['shop_info']['plan_name'])): ?>
                    <p class="text-muted mb-2">
                        <i class="bi bi-credit-card me-1"></i>
                        Plan: <?= htmlspecialchars($connectionDetails['shop_info']['plan_name']) ?>
                    </p>
                    <?php endif; ?>
                    <?php if (!empty($connectionDetails['token_hint'])): ?>
                    <p class="text-muted mb-0">
                        <i class="bi bi-key me-1"></i>
                        Token: <code><?= htmlspecialchars($connectionDetails['token_hint']) ?></code>
                    </p>
                    <?php endif; ?>
                </div>
                <div class="col-md-6 text-md-end">
                    <button type="button" class="btn btn-outline-success me-2" id="test-connection-btn">
                        <i class="bi bi-check2-circle"></i> Test Connection
                    </button>
                    <a href="https://<?= htmlspecialchars($shop) ?>/admin" target="_blank" class="btn btn-outline-primary me-2">
                        <i class="bi bi-box-arrow-up-right"></i> Open Admin
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Themes -->
    <?php if (!empty($themes)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-palette"></i> Themes
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Theme</th>
                            <th>Role</th>
                            <th>Updated</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($themes as $theme): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($theme['name']) ?></strong>
                                <?php if ($theme['role'] === 'main'): ?>
                                <span class="badge bg-success ms-2">Live</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="text-muted"><?= htmlspecialchars(ucfirst($theme['role'])) ?></span>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?= date('M j, Y', strtotime($theme['updated_at'])) ?>
                                </small>
                            </td>
                            <td class="text-end">
                                <a href="https://<?= htmlspecialchars($shop) ?>/admin/themes/<?= $theme['id'] ?>"
                                   target="_blank" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i> Edit
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Update Credentials -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-arrow-repeat"></i> Update Credentials
        </div>
        <div class="card-body">
            <form method="POST" action="/shopify">
                <?php if (!empty($csrf) && is_array($csrf)): ?>
                    <?php foreach ($csrf as $name => $value): ?>
                        <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="mb-3">
                    <label for="shopify_shop" class="form-label">Shop Domain</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="shopify_shop" name="shopify_shop"
                               value="<?= htmlspecialchars(str_replace('.myshopify.com', '', $shop ?? '')) ?>"
                               placeholder="your-store">
                        <span class="input-group-text">.myshopify.com</span>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="shopify_access_token" class="form-label">Admin API Access Token</label>
                    <input type="password" class="form-control" id="shopify_access_token" name="shopify_access_token"
                           placeholder="<?=htmlspecialchars(@$connectionDetails['token_hint'])?>">
                    <div class="form-text">Enter a new token to update</div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Update Credentials
                </button>
            </form>
        </div>
    </div>

    <!-- Danger Zone -->
    <div class="card border-danger">
        <div class="card-header bg-danger text-white">
            <i class="bi bi-exclamation-triangle"></i> Danger Zone
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">
                Disconnect and remove all Shopify configuration. You'll need to re-enter your credentials to reconnect.
            </p>
            <a href="/shopify/disconnect" class="btn btn-outline-danger"
               onclick="return confirm('Disconnect from Shopify? This cannot be undone.')">
                <i class="bi bi-trash"></i> Disconnect Shopify
            </a>
        </div>
    </div>

    <?php else: ?>
    <!-- Not configured - Setup Form -->
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-shop"></i> Configure Shopify Connection
                </div>
                <div class="card-body">
                    <form method="POST" action="/shopify">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="shopify_shop" class="form-label">Shop Domain <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="shopify_shop" name="shopify_shop"
                                       placeholder="your-store" required>
                                <span class="input-group-text">.myshopify.com</span>
                            </div>
                            <div class="form-text">Your Shopify store subdomain (e.g., gwt-staging)</div>
                        </div>

                        <div class="mb-3">
                            <label for="shopify_access_token" class="form-label">Admin API Access Token <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="shopify_access_token" name="shopify_access_token"
                                   placeholder="shpat_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" required>
                            <div class="form-text">Starts with <code>shpat_</code></div>
                        </div>

                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-plug"></i> Connect Shopify
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
                    <ol class="mb-0">
                        <li class="mb-2">
                            Go to your <a href="https://admin.shopify.com" target="_blank">Shopify Admin</a>
                        </li>
                        <li class="mb-2">
                            Click <strong>Settings</strong> (bottom left)
                        </li>
                        <li class="mb-2">
                            Click <strong>Apps and sales channels</strong>
                        </li>
                        <li class="mb-2">
                            Click <strong>Develop apps</strong>
                        </li>
                        <li class="mb-2">
                            Create or select your app
                        </li>
                        <li class="mb-2">
                            Under <strong>API credentials</strong>, generate an <strong>Admin API access token</strong>
                        </li>
                        <li>
                            Copy the token (starts with <code>shpat_</code>)
                        </li>
                    </ol>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">
                    <i class="bi bi-shield-check"></i> Required Scopes
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-2">When creating your app, enable these scopes:</p>
                    <ul class="list-unstyled mb-0">
                        <li><i class="bi bi-check text-success me-2"></i>read_themes</li>
                        <li><i class="bi bi-check text-success me-2"></i>write_themes</li>
                        <li><i class="bi bi-check text-success me-2"></i>read_content</li>
                        <li><i class="bi bi-check text-success me-2"></i>write_content</li>
                        <li><i class="bi bi-check text-success me-2"></i>read_products</li>
                        <li><i class="bi bi-check text-success me-2"></i>read_orders</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const testBtn = document.getElementById('test-connection-btn');
    if (testBtn) {
        testBtn.addEventListener('click', async function() {
            testBtn.disabled = true;
            testBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';

            try {
                const response = await fetch('/shopify/test');
                const data = await response.json();

                let message = data.message || 'Unknown result';

                if (data.success) {
                    if (data.scopes && data.scopes.length > 0) {
                        message += '\n\nAvailable scopes: ' + data.scopes.join(', ');
                    }
                    alert('Connection successful!\n\n' + message);
                } else {
                    if (data.missing_scopes && data.missing_scopes.length > 0) {
                        message += '\n\nPlease add these scopes to your Shopify app:\n• ' + data.missing_scopes.join('\n• ');
                    }
                    alert('Connection issue:\n\n' + message);
                }
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                testBtn.disabled = false;
                testBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Test Connection';
            }
        });
    }
});
</script>
