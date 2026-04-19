<!-- Shopify App Build Page -->
<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- Breadcrumb Navigation -->
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/apps">Apps</a></li>
                    <li class="breadcrumb-item"><a href="/apps/form/<?= $app->id ?>"><?= h($app->name) ?></a></li>
                    <li class="breadcrumb-item"><a href="/apps/liquidfiles/<?= $app->id ?>">Shopify Theme Files</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Build</li>
                </ol>
            </nav>

            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><i class="bi bi-box-seam"></i> Build Shopify App: <?= h($app->name) ?></h2>
                    <p class="text-muted mb-0">Generate a complete Shopify App Store bundle</p>
                </div>
                <div>
                    <?php if ($bundle_exists): ?>
                        <a href="/apps/downloadshopify/<?= $app->id ?>" class="btn btn-outline-primary">
                            <i class="bi bi-download"></i> Download ZIP
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row">
                <!-- Left Column: Build Status & Actions -->
                <div class="col-lg-8">
                    <!-- Bundle Contents Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-file-earmark-code"></i> Bundle Contents</h5>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-3 mb-3">
                                    <div class="border rounded p-3">
                                        <i class="bi bi-grid-3x3-gap-fill text-primary" style="font-size: 2rem;"></i>
                                        <h4 class="mb-0 mt-2"><?= $files_by_type['block'] ?></h4>
                                        <small class="text-muted">App Blocks</small>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="border rounded p-3">
                                        <i class="bi bi-code-square text-success" style="font-size: 2rem;"></i>
                                        <h4 class="mb-0 mt-2"><?= $files_by_type['embed'] ?></h4>
                                        <small class="text-muted">App Embeds</small>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="border rounded p-3">
                                        <i class="bi bi-layout-text-window text-info" style="font-size: 2rem;"></i>
                                        <h4 class="mb-0 mt-2"><?= $files_by_type['section'] ?></h4>
                                        <small class="text-muted">Sections</small>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="border rounded p-3">
                                        <i class="bi bi-puzzle text-warning" style="font-size: 2rem;"></i>
                                        <h4 class="mb-0 mt-2"><?= $files_by_type['snippet'] ?></h4>
                                        <small class="text-muted">Snippets</small>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <div class="row text-center">
                                <div class="col-md-4">
                                    <i class="bi bi-filetype-js text-secondary"></i>
                                    <strong><?= $files_by_type['asset_js'] ?></strong> JavaScript
                                </div>
                                <div class="col-md-4">
                                    <i class="bi bi-filetype-css text-secondary"></i>
                                    <strong><?= $files_by_type['asset_css'] ?></strong> CSS
                                </div>
                                <div class="col-md-4">
                                    <i class="bi bi-diagram-3 text-secondary"></i>
                                    <strong><?= $pipeline_count ?></strong> Pipelines
                                </div>
                            </div>

                            <?php if ($total_files === 0): ?>
                                <div class="alert alert-warning mt-3 mb-0">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    <strong>No Liquid files found.</strong> Add files in the
                                    <a href="/apps/liquidfiles/<?= $app->id ?>" class="alert-link">Shopify Theme Files</a> section first.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Build Actions Card -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-hammer"></i> Build Actions</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($bundle_exists): ?>
                                <div class="alert alert-success">
                                    <i class="bi bi-check-circle"></i>
                                    <strong>Bundle ready!</strong>
                                    <span class="badge bg-primary ms-2">v<?= $current_version ?? 0 ?></span>
                                    <br><small>Last built: <?= $last_build_at ? date('M j, Y g:i A', strtotime($last_build_at)) : 'N/A' ?></small>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i>
                                    No bundle exists yet. Click Build to generate your Shopify app.
                                </div>
                            <?php endif; ?>

                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-primary btn-lg" id="buildBtn" <?= $total_files === 0 ? 'disabled' : '' ?>>
                                    <i class="bi bi-box-seam"></i> Build Shopify App Bundle
                                </button>

                                <?php if ($bundle_exists): ?>
                                    <a href="/apps/downloadshopify/<?= $app->id ?>" class="btn btn-outline-success btn-lg">
                                        <i class="bi bi-download"></i> Download ZIP
                                    </a>

                                    <!-- Shopify Connection Selector for Deployment -->
                                    <div class="mb-2 mt-2">
                                        <label for="shopifyConnectionSelect" class="form-label small">
                                            <i class="bi bi-shop"></i> Deploy using Partners credentials from:
                                        </label>
                                        <select class="form-select" id="shopifyConnectionSelect">
                                            <option value="">No connection (use enterprise settings)</option>
                                            <?php if (!empty($shopify_connections)): ?>
                                                <?php foreach ($shopify_connections as $conn): ?>
                                                    <?php
                                                    $meta = json_decode($conn->metadata_json ?: '{}', true);
                                                    $hasPartners = !empty($meta['shopify_cli_token']);
                                                    ?>
                                                    <option value="<?= $conn->id ?>" <?= $hasPartners ? '' : 'disabled' ?>>
                                                        <?= h($conn->external_name ?: $conn->external_eid) ?>
                                                        <?= $hasPartners ? ' ✓' : ' (no Partners credentials)' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                        <div class="form-text">
                                            Configure Partners credentials in <a href="/shopify" target="_blank">Shopify connections</a>
                                        </div>
                                    </div>

                                    <button type="button" class="btn btn-success btn-lg" id="deployBtn">
                                        <i class="bi bi-rocket-takeoff"></i> Deploy to Shopify
                                    </button>
                                <?php endif; ?>
                            </div>

                            <div id="buildProgress" class="mt-3" style="display: none;">
                                <div class="spinner-border spinner-border-sm me-2" role="status">
                                    <span class="visually-hidden">Building...</span>
                                </div>
                                <span id="buildStatus">Building app bundle...</span>
                            </div>

                            <div id="buildResult" class="mt-3" style="display: none;"></div>

                            <!-- Deployment Progress -->
                            <div id="deployProgress" class="mt-3" style="display: none;">
                                <div class="card border-info">
                                    <div class="card-header bg-info text-white">
                                        <h6 class="mb-0">
                                            <div class="spinner-border spinner-border-sm me-2" role="status">
                                                <span class="visually-hidden">Deploying...</span>
                                            </div>
                                            Deploying to Shopify...
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div id="deployStatus" class="font-monospace small">
                                            <div>• Starting deployment pipeline...</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div id="deployResult" class="mt-3" style="display: none;"></div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Configuration & Instructions -->
                <div class="col-lg-4">
                    <!-- App Configuration Card -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-gear"></i> App Configuration</h5>
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <dt class="col-sm-5">App Handle:</dt>
                                <dd class="col-sm-7"><code><?= h($app->slug) ?></code></dd>

                                <dt class="col-sm-5">API Key:</dt>
                                <dd class="col-sm-7">
                                    <?php if ($app->shopify_api_key): ?>
                                        <code><?= h(substr($app->shopify_api_key, 0, 8)) ?>...</code>
                                    <?php else: ?>
                                        <span class="text-muted">Not set</span>
                                    <?php endif; ?>
                                </dd>

                                <dt class="col-sm-5">OAuth Scopes:</dt>
                                <dd class="col-sm-7">
                                    <small class="text-muted"><?= h($app->oauth_scopes ?: 'read_products,write_themes') ?></small>
                                </dd>

                                <dt class="col-sm-5">Proxy Path:</dt>
                                <dd class="col-sm-7">
                                    <code>/apps/myctobot<?= h($app->app_proxy_subpath ?: '/' . $app->slug) ?></code>
                                </dd>
                            </dl>

                            <hr>

                            <a href="/apps/form/<?= $app->id ?>" class="btn btn-sm btn-outline-secondary w-100">
                                <i class="bi bi-pencil"></i> Edit Configuration
                            </a>
                        </div>
                    </div>

                    <!-- What's Included Card -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-box"></i> What's Included</h5>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled mb-0">
                                <li class="mb-2">
                                    <i class="bi bi-check-circle text-success"></i> <code>shopify.app.toml</code>
                                </li>
                                <li class="mb-2">
                                    <i class="bi bi-check-circle text-success"></i> Theme extension files
                                </li>
                                <li class="mb-2">
                                    <i class="bi bi-check-circle text-success"></i> Embedded pipelines (JSON)
                                </li>
                                <li class="mb-2">
                                    <i class="bi bi-check-circle text-success"></i> README with instructions
                                </li>
                                <li class="mb-2">
                                    <i class="bi bi-check-circle text-success"></i> App proxy configuration
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Deployment Setup Card -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-key"></i> Deployment Setup</h5>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted">
                                To deploy from the web UI, you need to configure a Shopify Partners token:
                            </p>

                            <ol class="small mb-3">
                                <li class="mb-2">Go to <a href="https://partners.shopify.com" target="_blank">Shopify Partners</a></li>
                                <li class="mb-2">Navigate to Settings → CLI Token</li>
                                <li class="mb-2">Generate a new token</li>
                                <li class="mb-2">Add to Enterprise Settings:
                                    <pre class="mt-1 mb-0 p-2 bg-light border rounded"><code>Key: shopify_cli_token
Value: [your token]</code></pre>
                                </li>
                            </ol>

                            <div class="alert alert-info small mb-0">
                                <i class="bi bi-info-circle"></i>
                                <strong>Alternative:</strong> Deploy locally with <code>shopify app deploy</code>
                            </div>
                        </div>
                    </div>

                    <!-- Next Steps Card -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-list-check"></i> Local Development</h5>
                        </div>
                        <div class="card-body">
                            <ol class="mb-0">
                                <li class="mb-2">Install Shopify CLI: <code>npm i -g @shopify/cli</code></li>
                                <li class="mb-2">Authenticate: <code>shopify auth login</code></li>
                                <li class="mb-2">Navigate to app directory</li>
                                <li class="mb-2">Run <code>shopify app dev</code> for local preview</li>
                                <li class="mb-2">Test on development store</li>
                                <li>Deploy with <code>shopify app deploy</code></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const APP_ID = <?= (int)$app->id ?>;

document.getElementById('buildBtn').addEventListener('click', async function() {
    const btn = this;
    const progress = document.getElementById('buildProgress');
    const result = document.getElementById('buildResult');

    btn.disabled = true;
    progress.style.display = 'block';
    result.style.display = 'none';

    try {
        const response = await fetch('/apps/dobuildshopify/' + APP_ID, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>'
            },
            body: JSON.stringify({ create_zip: true })
        });

        const data = await response.json();

        progress.style.display = 'none';

        if (data.success) {
            result.className = 'alert alert-success';
            const version = data.data.version || 'N/A';
            const appDir = data.data.app_dir || 'N/A';
            const appName = data.data.app_name || 'N/A';
            result.innerHTML = `
                <i class="bi bi-check-circle"></i>
                <strong>Build successful!</strong>
                <span class="badge bg-primary ms-2">v${version}</span><br>
                <small>App: ${appName} | Location: ${appDir}</small>
            `;
            result.style.display = 'block';

            // Reload page after 2 seconds to show updated status
            setTimeout(() => location.reload(), 2000);
        } else {
            throw new Error(data.error || 'Build failed');
        }

    } catch (error) {
        progress.style.display = 'none';
        result.className = 'alert alert-danger';
        result.innerHTML = `
            <i class="bi bi-exclamation-triangle"></i>
            <strong>Build failed:</strong> ${error.message}
        `;
        result.style.display = 'block';
        btn.disabled = false;
    }
});

// Deploy to Shopify button handler
const deployBtn = document.getElementById('deployBtn');
if (deployBtn) {
    deployBtn.addEventListener('click', async function() {
        const btn = this;
        const progress = document.getElementById('deployProgress');
        const statusDiv = document.getElementById('deployStatus');
        const result = document.getElementById('deployResult');

        btn.disabled = true;
        progress.style.display = 'block';
        result.style.display = 'none';
        statusDiv.innerHTML = '<div>• Running shopify app deploy --force...</div>';

        try {
            // Get selected connection ID
            const connectionId = document.getElementById('shopifyConnectionSelect')?.value || '';
            const url = '/apps/deployshopify/' + APP_ID + (connectionId ? '?connection_id=' + connectionId : '');

            // Trigger deployment (blocking request - proc_open runs until complete)
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>'
                }
            });

            const data = await response.json();

            progress.style.display = 'none';

            if (data.success) {
                // Success!
                result.className = 'alert alert-success';

                // Parse output for version URL
                const output = data.data.output || '';
                const versionMatch = output.match(/https:\/\/[^\s]+\/versions\/\d+/);
                const versionUrl = versionMatch ? versionMatch[0] : null;

                result.innerHTML = `
                    <h5><i class="bi bi-check-circle"></i> Deployment Successful!</h5>
                    <div class="font-monospace small mt-2 p-2 bg-light border rounded" style="max-height: 300px; overflow-y: auto; white-space: pre-wrap;">
${escapeHtml(output)}
                    </div>
                    ${versionUrl ? `
                        <div class="mt-3">
                            <a href="${versionUrl}" target="_blank" class="btn btn-primary">
                                <i class="bi bi-box-arrow-up-right"></i> View in Shopify Partners Dashboard
                            </a>
                        </div>
                    ` : ''}
                `;
                result.style.display = 'block';

            } else {
                // Deployment failed
                result.className = 'alert alert-danger';

                const error = data.error || 'Unknown error';
                const output = data.data?.output || '';

                result.innerHTML = `
                    <h5><i class="bi bi-exclamation-triangle"></i> Deployment Failed</h5>
                    <div class="mt-2"><strong>Error:</strong> ${escapeHtml(error)}</div>
                    ${output ? `
                        <div class="font-monospace small mt-2 p-2 bg-light border rounded" style="max-height: 300px; overflow-y: auto; white-space: pre-wrap;">
${escapeHtml(output)}
                        </div>
                    ` : ''}
                    <div class="mt-3 text-muted">
                        <small><i class="bi bi-info-circle"></i> Make sure you have authenticated with Shopify CLI: <code>shopify auth login</code></small>
                    </div>
                `;
                result.style.display = 'block';
                btn.disabled = false;
            }

        } catch (error) {
            progress.style.display = 'none';
            result.className = 'alert alert-danger';
            result.innerHTML = `
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Deployment error:</strong> ${error.message}
            `;
            result.style.display = 'block';
            btn.disabled = false;
        }
    });
}

// Helper function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
