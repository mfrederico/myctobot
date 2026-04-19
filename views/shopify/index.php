<div class="container py-4" data-assist-page="shopify" data-assist-purpose="Connect and manage Shopify store integrations">
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
        <?php $meta = json_decode($conn->metadata_json ?: '{}', true); ?>
        <div class="col-12 mb-3">
            <div class="card <?= $conn->enabled ? 'border-success' : 'border-secondary' ?>">
                <?php $isOwner = ($conn->member_id == $member_id); ?>
                <div class="card-header <?= $conn->enabled ? 'bg-success text-white' : 'bg-secondary text-white' ?> d-flex justify-content-between align-items-center">
                    <span>
                        <i class="bi bi-shop me-2"></i>
                        <?= h($conn->external_name ?: $conn->external_eid) ?>
                        <?php if (!$conn->enabled): ?>
                        <span class="badge bg-warning text-dark ms-2">Disabled</span>
                        <?php endif; ?>
                        <?php if ($conn->shared && !$isOwner): ?>
                        <span class="badge bg-info ms-2" title="Shared by <?= h($meta['created_by_name'] ?? 'Unknown') ?>">
                            <i class="bi bi-people-fill"></i> Shared
                        </span>
                        <?php elseif ($conn->shared): ?>
                        <span class="badge bg-info ms-2"><i class="bi bi-people-fill"></i> Shared</span>
                        <?php endif; ?>
                    </span>
                    <div>
                        <button type="button" class="btn btn-sm btn-light test-connection-btn" data-id="<?= $conn->id ?>">
                            <i class="bi bi-check2-circle"></i> Test
                        </button>
                        <?php if ($isOwner): ?>
                        <a href="/shopify/connect?shop=<?= urlencode(str_replace('.myshopify.com', '', $conn->external_eid)) ?>"
                           class="btn btn-sm btn-warning ms-1" title="Re-authorize to update permissions">
                            <i class="bi bi-arrow-repeat"></i> Reconnect
                        </a>
                        <?php endif; ?>
                        <a href="https://<?= h($conn->external_eid) ?>/admin" target="_blank"
                           class="btn btn-sm btn-light ms-1">
                            <i class="bi bi-box-arrow-up-right"></i> Admin
                        </a>
                        <?php if ($isOwner): ?>
                        <button type="button" class="btn btn-sm btn-danger ms-1 disconnect-btn" data-id="<?= $conn->id ?>" data-shop="<?= h($conn->external_eid) ?>">
                            <i class="bi bi-trash"></i> Disconnect
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p class="mb-2">
                                <i class="bi bi-globe me-1 text-muted"></i>
                                <a href="https://<?= h($conn->external_eid) ?>" target="_blank">
                                    <?= h($conn->external_eid) ?>
                                </a>
                            </p>
                            <?php if (($meta['shop_email'] ?? '')): ?>
                            <p class="mb-2">
                                <i class="bi bi-envelope me-1 text-muted"></i>
                                <?= h(($meta['shop_email'] ?? '')) ?>
                            </p>
                            <?php endif; ?>
                            <?php if ($conn->connection_name): ?>
                            <p class="mb-2">
                                <i class="bi bi-tag me-1 text-muted"></i>
                                <?= h($conn->connection_name) ?>
                            </p>
                            <?php endif; ?>
                            <p class="mb-0 text-muted small">
                                Connected <?= date('M j, Y', strtotime($conn->created_at)) ?>
                                by <?= h($meta['created_by_name'] ?? 'Unknown') ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <!-- Repo Linking -->
                            <div class="mb-3">
                                <label class="form-label small text-muted mb-1">
                                    <i class="bi bi-github me-1"></i> Linked Repository
                                </label>
                                <?php if (($meta['repoconnections_id'] ?? null)): ?>
                                    <?php
                                    $linkedRepo = null;
                                    foreach ($repos as $repo) {
                                        if ($repo->id == ($meta['repoconnections_id'] ?? null)) {
                                            $linkedRepo = $repo;
                                            break;
                                        }
                                    }
                                    ?>
                                    <div class="d-flex align-items-center">
                                        <span class="badge bg-primary me-2">
                                            <?= $linkedRepo ? h("{$linkedRepo->repo_owner}/{$linkedRepo->repo_name}") : 'Repo #' . ($meta['repoconnections_id'] ?? 'N/A') ?>
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
                                                <?= h("{$repo->repo_owner}/{$repo->repo_name}") ?>
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
                            <div class="d-flex justify-content-between align-items-center">
                                <?php if ($isOwner): ?>
                                <!-- Share toggle (owner only) -->
                                <div class="form-check form-switch">
                                    <input class="form-check-input share-toggle" type="checkbox"
                                           data-id="<?= $conn->id ?>"
                                           <?= $conn->shared ? 'checked' : '' ?>>
                                    <label class="form-check-label small">Share with workspace</label>
                                </div>
                                <?php else: ?>
                                <span class="text-muted small">
                                    <i class="bi bi-person"></i> Owned by <?= h($meta['created_by_name'] ?? 'Unknown') ?>
                                </span>
                                <span></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Customer Support Section -->
                    <div class="border-top mt-3 pt-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0">
                                <i class="bi bi-headset text-primary me-1"></i> Customer Support Widget
                                <?php if (($meta['public_agent_token'] ?? '') && ($meta['pipelines_id'] ?? null)): ?>
                                <span class="badge bg-success ms-1">Active</span>
                                <?php else: ?>
                                <span class="badge bg-secondary ms-1">Not Configured</span>
                                <?php endif; ?>
                            </h6>
                            <button type="button" class="btn btn-sm btn-outline-primary toggle-support-btn"
                                    data-id="<?= $conn->id ?>" data-bs-toggle="collapse"
                                    data-bs-target="#support-panel-<?= $conn->id ?>">
                                <i class="bi bi-chevron-down"></i> Configure
                            </button>
                        </div>

                        <div class="collapse" id="support-panel-<?= $conn->id ?>">
                            <div class="card card-body bg-light">
                                <?php if ($isOwner): ?>
                                <!-- Support Pipeline Selection -->
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">
                                        <i class="bi bi-diagram-3 me-1"></i> Support Pipeline
                                    </label>
                                    <div class="form-text mb-2">
                                        Select a pipeline to handle customer support conversations
                                    </div>
                                    <div class="d-flex gap-2 align-items-center">
                                        <select class="form-select form-select-sm support-pipeline-select" data-id="<?= $conn->id ?>">
                                            <option value="">Select a pipeline...</option>
                                            <?php
                                            $pipelines = \app\Bean::find('pipelines', ' is_active = 1 ORDER BY name ASC ');
                                            foreach ($pipelines as $pipeline):
                                            ?>
                                            <option value="<?= $pipeline->id ?>" <?= (($meta['pipelines_id'] ?? null) == $pipeline->id) ? 'selected' : '' ?>>
                                                <?= h($pipeline->name) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-sm btn-primary save-support-pipeline-btn" data-id="<?= $conn->id ?>">
                                            <i class="bi bi-save"></i> Save
                                        </button>
                                    </div>
                                </div>

                                <!-- Widget Token -->
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">
                                        <i class="bi bi-key me-1"></i> Widget Token
                                    </label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" class="form-control font-monospace widget-token-input"
                                               data-id="<?= $conn->id ?>"
                                               value="<?= h(($meta['public_agent_token'] ?? '') ?? '') ?>"
                                               readonly>
                                        <?php if (($meta['public_agent_token'] ?? '')): ?>
                                        <button type="button" class="btn btn-outline-secondary copy-widget-token-btn"
                                                data-id="<?= $conn->id ?>" title="Copy token">
                                            <i class="bi bi-clipboard"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-outline-primary generate-widget-token-btn"
                                                data-id="<?= $conn->id ?>">
                                            <i class="bi bi-arrow-repeat"></i> <?= ($meta['public_agent_token'] ?? '') ? 'Regenerate' : 'Generate' ?>
                                        </button>
                                    </div>
                                    <div class="form-text">This token identifies your store in the widget embed code</div>
                                </div>

                                <!-- Embed Code -->
                                <?php if (($meta['public_agent_token'] ?? '') && ($meta['pipelines_id'] ?? null)): ?>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">
                                        <i class="bi bi-code-slash me-1"></i> Embed Code
                                    </label>
                                    <div class="form-text mb-2">
                                        Add this code to your Shopify theme (theme.liquid before &lt;/body&gt;)
                                    </div>
                                    <div class="bg-dark p-2 rounded">
                                        <code class="text-success small" style="white-space: pre-wrap;">&lt;script src="https://<?= $_SERVER['HTTP_HOST'] ?>/widget/js" data-token="<?= h(($meta['public_agent_token'] ?? '')) ?>"&gt;&lt;/script&gt;</code>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-success mt-2 copy-embed-code-btn"
                                            data-id="<?= $conn->id ?>"
                                            data-code='<script src="https://<?= $_SERVER['HTTP_HOST'] ?>/widget/js" data-token="<?= h(($meta['public_agent_token'] ?? '')) ?>"></script>'>
                                        <i class="bi bi-clipboard"></i> Copy Embed Code
                                    </button>
                                    <a href="/customersupport?token=<?= h(($meta['public_agent_token'] ?? '')) ?>&embed=1" target="_blank"
                                       class="btn btn-sm btn-outline-info mt-2 ms-1">
                                        <i class="bi bi-box-arrow-up-right"></i> Preview Widget
                                    </a>
                                </div>

                                <!-- Auto-Install on Shopify -->
                                <div class="mb-0">
                                    <label class="form-label small fw-bold">
                                        <i class="bi bi-cloud-upload me-1"></i> Auto-Install on Shopify
                                    </label>
                                    <div class="form-text mb-2">
                                        Automatically add/remove the widget script tag via Shopify Script Tags API
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge widget-status-badge" data-id="<?= $conn->id ?>">
                                            <span class="spinner-border spinner-border-sm" role="status"></span> Checking...
                                        </span>
                                        <button type="button" class="btn btn-sm btn-success install-widget-btn"
                                                data-id="<?= $conn->id ?>" style="display: none;">
                                            <i class="bi bi-cloud-upload"></i> Install on Shopify
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger uninstall-widget-btn"
                                                data-id="<?= $conn->id ?>" style="display: none;">
                                            <i class="bi bi-cloud-slash"></i> Uninstall from Shopify
                                        </button>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="alert alert-info mb-0">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Select a pipeline and generate a widget token to get your embed code.
                                </div>
                                <?php endif; ?>
                                <?php else: ?>
                                <div class="text-muted small">
                                    <i class="bi bi-lock me-1"></i> Only the connection owner can configure customer support.
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Shopify Partners Deployment Section -->
                    <div class="border-top mt-3 pt-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0">
                                <i class="bi bi-cloud-upload text-warning me-1"></i> Shopify Partners Deployment
                                <?php if (($meta['shopify_cli_token'] ?? '') || ($meta['shopify_client_id'] ?? '')): ?>
                                <span class="badge bg-success ms-1">Configured</span>
                                <?php else: ?>
                                <span class="badge bg-secondary ms-1">Not Configured</span>
                                <?php endif; ?>
                            </h6>
                            <button type="button" class="btn btn-sm btn-outline-warning toggle-partners-btn"
                                    data-id="<?= $conn->id ?>" data-bs-toggle="collapse"
                                    data-bs-target="#partners-panel-<?= $conn->id ?>">
                                <i class="bi bi-chevron-down"></i> Configure
                            </button>
                        </div>

                        <div class="collapse" id="partners-panel-<?= $conn->id ?>">
                            <div class="card card-body bg-light">
                                <?php if ($isOwner): ?>
                                <div class="form-text mb-3">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Configure Shopify Partners credentials for deploying apps using <code>shopify app deploy</code>.
                                    Get these from <a href="https://partners.shopify.com" target="_blank">Shopify Partners</a> dashboard.
                                </div>

                                <!-- CLI Token -->
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">
                                        <i class="bi bi-key me-1"></i> CLI Token
                                        <span class="text-danger">*</span>
                                    </label>
                                    <div class="form-text mb-2">
                                        Partners CLI token for non-interactive deployment (SHOPIFY_CLI_PARTNERS_TOKEN)
                                    </div>
                                    <input type="password" class="form-control form-control-sm partners-cli-token"
                                           data-id="<?= $conn->id ?>" placeholder="Enter CLI token..."
                                           value="<?= h(($meta['shopify_cli_token'] ?? '')) ?>">
                                </div>

                                <!-- Client ID -->
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">
                                        <i class="bi bi-fingerprint me-1"></i> Client ID
                                        <span class="text-muted">(optional)</span>
                                    </label>
                                    <div class="form-text mb-2">
                                        App client ID from Partners dashboard
                                    </div>
                                    <input type="text" class="form-control form-control-sm partners-client-id"
                                           data-id="<?= $conn->id ?>" placeholder="Enter client ID..."
                                           value="<?= h(($meta['shopify_client_id'] ?? '')) ?>">
                                </div>

                                <!-- Organization ID -->
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">
                                        <i class="bi bi-building me-1"></i> Organization ID
                                        <span class="text-muted">(optional)</span>
                                    </label>
                                    <div class="form-text mb-2">
                                        Partner organization ID
                                    </div>
                                    <input type="text" class="form-control form-control-sm partners-org-id"
                                           data-id="<?= $conn->id ?>" placeholder="Enter organization ID..."
                                           value="<?= h(($meta['shopify_org_id'] ?? '')) ?>">
                                </div>

                                <div>
                                    <button type="button" class="btn btn-sm btn-warning save-partners-config-btn"
                                            data-id="<?= $conn->id ?>">
                                        <i class="bi bi-save"></i> Save Partners Config
                                    </button>
                                </div>
                                <?php else: ?>
                                <div class="text-muted small">
                                    <i class="bi bi-lock me-1"></i> Only the connection owner can configure deployment credentials.
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Crawler Section -->
                    <div class="border-top mt-3 pt-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0">
                                <i class="bi bi-robot text-info me-1"></i> Store Crawler (RAG)
                                <span class="badge bg-info ms-1 crawler-status-badge" data-id="<?= $conn->id ?>">Loading...</span>
                            </h6>
                            <button type="button" class="btn btn-sm btn-outline-info toggle-crawler-btn"
                                    data-id="<?= $conn->id ?>" data-bs-toggle="collapse"
                                    data-bs-target="#crawler-panel-<?= $conn->id ?>">
                                <i class="bi bi-chevron-down"></i> Configure
                            </button>
                        </div>

                        <div class="collapse" id="crawler-panel-<?= $conn->id ?>">
                            <div class="card card-body bg-light">
                                <!-- Crawler Auth Config (owner only) -->
                                <?php if ($isOwner): ?>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">
                                        <i class="bi bi-shield-lock me-1"></i> Bot Authentication
                                        <a href="https://help.shopify.com/en/manual/privacy-and-security/privacy/customer-privacy-settings/crawler-authorization"
                                           target="_blank" class="text-muted ms-1" title="Shopify docs">
                                            <i class="bi bi-question-circle"></i>
                                        </a>
                                    </label>
                                    <div class="form-text mb-2">
                                        Get these from Shopify Admin &rarr; Settings &rarr; Customer privacy &rarr; Authorize external tools
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-12">
                                            <input type="text" class="form-control form-control-sm crawler-primary-domain"
                                                   data-id="<?= $conn->id ?>" placeholder="Primary domain (e.g., yourstore.com)">
                                        </div>
                                        <div class="col-12">
                                            <textarea class="form-control form-control-sm crawler-signature" rows="2"
                                                      data-id="<?= $conn->id ?>" placeholder="Signature: sig1=:xxx..."></textarea>
                                        </div>
                                        <div class="col-12">
                                            <textarea class="form-control form-control-sm crawler-signature-input" rows="2"
                                                      data-id="<?= $conn->id ?>" placeholder="Signature-Input: sig1=..."></textarea>
                                        </div>
                                        <div class="col-12">
                                            <button type="button" class="btn btn-sm btn-primary save-crawler-config-btn"
                                                    data-id="<?= $conn->id ?>">
                                                <i class="bi bi-save"></i> Save Auth Config
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <hr>
                                <?php endif; ?>

                                <!-- Crawl Progress -->
                                <div class="crawler-progress" data-id="<?= $conn->id ?>">
                                    <div class="row text-center mb-2">
                                        <div class="col-3">
                                            <div class="fs-4 fw-bold text-primary crawler-discovered">-</div>
                                            <div class="small text-muted">Discovered</div>
                                        </div>
                                        <div class="col-3">
                                            <div class="fs-4 fw-bold text-success crawler-crawled">-</div>
                                            <div class="small text-muted">Crawled</div>
                                        </div>
                                        <div class="col-3">
                                            <div class="fs-4 fw-bold text-warning crawler-pending">-</div>
                                            <div class="small text-muted">Pending</div>
                                        </div>
                                        <div class="col-3">
                                            <div class="fs-4 fw-bold text-danger crawler-errors">-</div>
                                            <div class="small text-muted">Errors</div>
                                        </div>
                                    </div>
                                    <div class="progress mb-2" style="height: 6px;">
                                        <div class="progress-bar bg-success crawler-progress-bar" data-id="<?= $conn->id ?>" style="width: 0%"></div>
                                    </div>
                                    <div class="small text-muted crawler-status-text" data-id="<?= $conn->id ?>">-</div>
                                </div>

                                <!-- Crawl Actions -->
                                <div class="mt-3 d-flex gap-2 flex-wrap">
                                    <button type="button" class="btn btn-sm btn-outline-primary discover-urls-btn"
                                            data-id="<?= $conn->id ?>">
                                        <i class="bi bi-search"></i> Discover URLs
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-success crawl-pages-btn"
                                            data-id="<?= $conn->id ?>">
                                        <i class="bi bi-play-fill"></i> Crawl Pages
                                    </button>
                                    <div class="input-group input-group-sm" style="width: 200px;">
                                        <input type="text" class="form-control crawler-search-input"
                                               data-id="<?= $conn->id ?>" placeholder="Search RAG...">
                                        <button type="button" class="btn btn-outline-secondary search-crawler-btn"
                                                data-id="<?= $conn->id ?>">
                                            <i class="bi bi-search"></i>
                                        </button>
                                    </div>
                                    <?php if ($isOwner): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger reset-crawler-btn"
                                            data-id="<?= $conn->id ?>">
                                        <i class="bi bi-trash"></i> Reset
                                    </button>
                                    <?php endif; ?>
                                </div>

                                <!-- Search Results -->
                                <div class="crawler-search-results mt-2" data-id="<?= $conn->id ?>" style="display: none;">
                                    <div class="small fw-bold">Search Results:</div>
                                    <div class="crawler-results-list"></div>
                                </div>
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
                    <?php
                    // Check if OAuth is configured via ShopifyConnector
                    require_once __DIR__ . '/../../services/connectors/ShopifyConnector.php';
                    $oauthConfigured = \app\services\connectors\ShopifyConnector::isOAuthReady();
                    ?>

                    <?php if ($oauthConfigured): ?>
                    <!-- OAuth Connect (Recommended) -->
                    <div class="mb-4 p-3 bg-light rounded">
                        <h6 class="mb-3"><i class="bi bi-shield-check text-success me-2"></i>Connect with OAuth (Recommended)</h6>
                        <form method="GET" action="/shopify/connect" class="d-flex gap-2 align-items-end">
                            <div class="flex-grow-1">
                                <label for="oauth_shop" class="form-label small">Shop Domain</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="oauth_shop" name="shop"
                                           placeholder="your-store" required>
                                    <span class="input-group-text">.myshopify.com</span>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-box-arrow-in-right"></i> Connect with Shopify
                            </button>
                        </form>
                        <div class="form-text mt-2">
                            <i class="bi bi-info-circle me-1"></i>
                            You'll be redirected to Shopify to authorize access. No manual token needed.
                        </div>
                    </div>

                    <div class="text-center text-muted mb-3">
                        <span class="px-3 bg-white">or connect manually</span>
                        <hr class="mt-n2">
                    </div>
                    <?php endif; ?>

                    <!-- Manual Token Entry -->
                    <form method="POST" action="/shopify/add">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= h($name) ?>" value="<?= h($value) ?>">
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

                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="shared" name="shared" value="1">
                                <label class="form-check-label" for="shared">
                                    <i class="bi bi-people-fill me-1"></i> Share with workspace members
                                </label>
                                <div class="form-text">Allow other workspace members to see and use this store</div>
                            </div>
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
    // Disconnect buttons
    document.querySelectorAll('.disconnect-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const shop = this.dataset.shop;

            if (!confirm(`Disconnect ${shop}?\n\nThis will permanently remove this store connection and cannot be undone.`)) {
                return;
            }

            // Navigate to disconnect URL
            window.location.href = `/shopify/disconnect/${id}`;
        });
    });

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

    // Share toggle
    document.querySelectorAll('.share-toggle').forEach(toggle => {
        toggle.addEventListener('change', async function() {
            const id = this.dataset.id;
            const shared = this.checked ? 1 : 0;

            try {
                const response = await fetch(`/shopify/update/${id}?shared=${shared}`, { method: 'POST' });
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

    // ===========================================
    // SHOPIFY PARTNERS DEPLOYMENT
    // ===========================================

    // Save Partners config
    document.querySelectorAll('.save-partners-config-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.dataset.id;
            const cliToken = document.querySelector(`.partners-cli-token[data-id="${id}"]`).value.trim();
            const clientId = document.querySelector(`.partners-client-id[data-id="${id}"]`).value.trim();
            const orgId = document.querySelector(`.partners-org-id[data-id="${id}"]`).value.trim();

            if (!cliToken) {
                alert('CLI Token is required');
                return;
            }

            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';

            try {
                const params = new URLSearchParams({
                    cli_token: cliToken,
                    client_id: clientId,
                    org_id: orgId
                });
                const response = await fetch(`/shopify/savepartnersconfig/${id}?${params}`, { method: 'POST' });
                const data = await response.json();

                if (data.success) {
                    alert('Partners configuration saved!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                this.disabled = false;
                this.innerHTML = '<i class="bi bi-save"></i> Save Partners Config';
            }
        });
    });

    // ===========================================
    // CRAWLER FUNCTIONALITY
    // ===========================================

    // Load crawler status for all connections
    async function loadCrawlerStatus(id) {
        try {
            const response = await fetch(`/shopify/crawlstatus/${id}`);
            const data = await response.json();

            const badge = document.querySelector(`.crawler-status-badge[data-id="${id}"]`);
            const progress = document.querySelector(`.crawler-progress[data-id="${id}"]`);

            if (data.success && data.status) {
                const s = data.status;

                // Update badge
                if (badge) {
                    badge.textContent = s.status.charAt(0).toUpperCase() + s.status.slice(1);
                    badge.className = 'badge ms-1 crawler-status-badge';
                    if (s.status === 'complete') badge.classList.add('bg-success');
                    else if (s.status === 'crawling' || s.status === 'discovering') badge.classList.add('bg-warning');
                    else if (s.status === 'error') badge.classList.add('bg-danger');
                    else badge.classList.add('bg-secondary');
                }

                // Update progress
                if (progress) {
                    progress.querySelector('.crawler-discovered').textContent = s.urls_discovered || 0;
                    progress.querySelector('.crawler-crawled').textContent = s.urls_crawled || 0;
                    progress.querySelector('.crawler-pending').textContent = s.urls_pending || 0;
                    progress.querySelector('.crawler-errors').textContent = s.urls_errored || 0;

                    const total = s.urls_discovered || 0;
                    const crawled = s.urls_crawled || 0;
                    const pct = total > 0 ? Math.round((crawled / total) * 100) : 0;
                    document.querySelector(`.crawler-progress-bar[data-id="${id}"]`).style.width = pct + '%';

                    const statusText = document.querySelector(`.crawler-status-text[data-id="${id}"]`);
                    if (statusText) statusText.textContent = s.message || '-';
                }
            } else {
                if (badge) {
                    badge.textContent = 'Not Started';
                    badge.className = 'badge ms-1 crawler-status-badge bg-secondary';
                }
            }
        } catch (err) {
            console.error('Failed to load crawler status:', err);
        }
    }

    // Load status for all connections on page load
    document.querySelectorAll('.crawler-status-badge').forEach(badge => {
        loadCrawlerStatus(badge.dataset.id);
    });

    // Save crawler config
    document.querySelectorAll('.save-crawler-config-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.dataset.id;
            const signature = document.querySelector(`.crawler-signature[data-id="${id}"]`).value;
            const signatureInput = document.querySelector(`.crawler-signature-input[data-id="${id}"]`).value;
            const primaryDomain = document.querySelector(`.crawler-primary-domain[data-id="${id}"]`).value;

            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            try {
                const params = new URLSearchParams({
                    crawler_signature: signature,
                    crawler_signature_input: signatureInput,
                    primary_domain: primaryDomain
                });
                const response = await fetch(`/shopify/savecrawlerconfig/${id}?${params}`, { method: 'POST' });
                const data = await response.json();

                if (data.success) {
                    alert('Crawler configuration saved!');
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                this.disabled = false;
                this.innerHTML = '<i class="bi bi-save"></i> Save Auth Config';
            }
        });
    });

    // Discover URLs
    document.querySelectorAll('.discover-urls-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.dataset.id;
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Discovering...';

            try {
                const response = await fetch(`/shopify/crawldiscover/${id}`, { method: 'POST' });
                const data = await response.json();

                if (data.success) {
                    alert(`Discovered ${data.total_urls} URLs (${data.new_urls} new)`);
                    loadCrawlerStatus(id);
                } else {
                    alert('Error: ' + (data.error || data.message));
                }
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                this.disabled = false;
                this.innerHTML = '<i class="bi bi-search"></i> Discover URLs';
            }
        });
    });

    // Crawl pages
    document.querySelectorAll('.crawl-pages-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.dataset.id;
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Crawling...';

            try {
                const response = await fetch(`/shopify/crawlpages/${id}?max_pages=10`, { method: 'POST' });
                const data = await response.json();

                if (data.success) {
                    alert(`Crawled ${data.crawled} pages, ${data.pending} pending, ${data.errored} errors`);
                    loadCrawlerStatus(id);
                } else {
                    alert('Error: ' + (data.error || data.message));
                }
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                this.disabled = false;
                this.innerHTML = '<i class="bi bi-play-fill"></i> Crawl Pages';
            }
        });
    });

    // Search crawler
    document.querySelectorAll('.search-crawler-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.dataset.id;
            const input = document.querySelector(`.crawler-search-input[data-id="${id}"]`);
            const query = input.value.trim();
            const resultsDiv = document.querySelector(`.crawler-search-results[data-id="${id}"]`);
            const resultsList = resultsDiv.querySelector('.crawler-results-list');

            if (!query) {
                alert('Please enter a search query');
                return;
            }

            this.disabled = true;

            try {
                const response = await fetch(`/shopify/crawlsearch/${id}?q=${encodeURIComponent(query)}`);
                const data = await response.json();

                if (data.success) {
                    resultsDiv.style.display = 'block';
                    if (data.results.length === 0) {
                        resultsList.innerHTML = '<div class="text-muted">No results found</div>';
                    } else {
                        resultsList.innerHTML = data.results.map(r =>
                            `<div class="border-bottom py-1">
                                <strong>${r.title || r.page}</strong>
                                <span class="badge bg-secondary ms-1">${Math.round(r.similarity * 100)}%</span>
                                <div class="small text-muted">${(r.content || '').substring(0, 100)}...</div>
                            </div>`
                        ).join('');
                    }
                } else {
                    alert('Error: ' + (data.error || data.message));
                }
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                this.disabled = false;
            }
        });
    });

    // Enter key for search
    document.querySelectorAll('.crawler-search-input').forEach(input => {
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.querySelector(`.search-crawler-btn[data-id="${this.dataset.id}"]`).click();
            }
        });
    });

    // Reset crawler
    document.querySelectorAll('.reset-crawler-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!confirm('Reset all crawler data for this store? This cannot be undone.')) return;

            const id = this.dataset.id;
            this.disabled = true;

            try {
                const response = await fetch(`/shopify/crawlreset/${id}`, { method: 'POST' });
                const data = await response.json();

                if (data.success) {
                    alert('Crawler data reset');
                    loadCrawlerStatus(id);
                } else {
                    alert('Error: ' + (data.error || data.message));
                }
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                this.disabled = false;
            }
        });
    });

    // ===========================================
    // CUSTOMER SUPPORT WIDGET FUNCTIONALITY
    // ===========================================

    // Save support pipeline
    document.querySelectorAll('.save-support-pipeline-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.dataset.id;
            const select = document.querySelector(`.support-pipeline-select[data-id="${id}"]`);
            const pipelineId = select.value || null;

            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            try {
                const response = await fetch(`/shopify/linksupportpipeline/${id}?pipeline_id=${pipelineId || ''}`, { method: 'POST' });
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
                this.innerHTML = '<i class="bi bi-save"></i> Save';
            }
        });
    });

    // Generate widget token
    document.querySelectorAll('.generate-widget-token-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.dataset.id;
            const tokenInput = document.querySelector(`.widget-token-input[data-id="${id}"]`);
            const hasToken = tokenInput.value.trim() !== '';

            if (hasToken && !confirm('Regenerating the token will break existing widget installations. Continue?')) {
                return;
            }

            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            try {
                const response = await fetch(`/shopify/generatewidgettoken/${id}`, { method: 'POST' });
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
                this.innerHTML = '<i class="bi bi-arrow-repeat"></i> ' + (hasToken ? 'Regenerate' : 'Generate');
            }
        });
    });

    // Copy widget token
    document.querySelectorAll('.copy-widget-token-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const tokenInput = document.querySelector(`.widget-token-input[data-id="${id}"]`);
            navigator.clipboard.writeText(tokenInput.value).then(() => {
                const originalHtml = this.innerHTML;
                this.innerHTML = '<i class="bi bi-check"></i>';
                setTimeout(() => this.innerHTML = originalHtml, 1500);
            });
        });
    });

    // Copy embed code
    document.querySelectorAll('.copy-embed-code-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const code = this.dataset.code;
            navigator.clipboard.writeText(code).then(() => {
                const originalHtml = this.innerHTML;
                this.innerHTML = '<i class="bi bi-check"></i> Copied!';
                setTimeout(() => this.innerHTML = originalHtml, 1500);
            });
        });
    });

    // ===========================================
    // WIDGET PUBLISHING (Script Tags API)
    // ===========================================

    // Check widget install status on page load
    function checkWidgetStatus(id) {
        const badge = document.querySelector(`.widget-status-badge[data-id="${id}"]`);
        const installBtn = document.querySelector(`.install-widget-btn[data-id="${id}"]`);
        const uninstallBtn = document.querySelector(`.uninstall-widget-btn[data-id="${id}"]`);

        if (!badge) return;

        fetch(`/shopify/widgetstatus/${id}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (data.installed) {
                        badge.className = 'badge bg-success widget-status-badge';
                        badge.innerHTML = '<i class="bi bi-check-circle"></i> Installed';
                        if (installBtn) installBtn.style.display = 'none';
                        if (uninstallBtn) uninstallBtn.style.display = '';
                    } else {
                        badge.className = 'badge bg-secondary widget-status-badge';
                        badge.innerHTML = '<i class="bi bi-x-circle"></i> Not installed';
                        if (installBtn) installBtn.style.display = '';
                        if (uninstallBtn) uninstallBtn.style.display = 'none';
                    }
                } else {
                    badge.className = 'badge bg-warning text-dark widget-status-badge';
                    badge.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Error';
                }
            })
            .catch(() => {
                badge.className = 'badge bg-warning text-dark widget-status-badge';
                badge.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Error';
            });
    }

    // Load status for all configured widgets
    document.querySelectorAll('.widget-status-badge').forEach(badge => {
        checkWidgetStatus(badge.dataset.id);
    });

    // Install widget
    document.querySelectorAll('.install-widget-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.dataset.id;
            if (!confirm('Install the support widget on your Shopify store?\n\nThis will add a script tag that loads the widget on every page.')) return;

            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Installing...';

            try {
                const response = await fetch(`/shopify/publishwidget/${id}?action=install`, { method: 'POST' });
                const data = await response.json();

                if (data.success) {
                    checkWidgetStatus(id);
                    alert('Widget installed on Shopify!');
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                this.disabled = false;
                this.innerHTML = '<i class="bi bi-cloud-upload"></i> Install on Shopify';
            }
        });
    });

    // Uninstall widget
    document.querySelectorAll('.uninstall-widget-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.dataset.id;
            if (!confirm('Remove the support widget from your Shopify store?\n\nThe script tag will be deleted from Shopify.')) return;

            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Removing...';

            try {
                const response = await fetch(`/shopify/publishwidget/${id}?action=uninstall`, { method: 'POST' });
                const data = await response.json();

                if (data.success) {
                    checkWidgetStatus(id);
                    alert('Widget removed from Shopify.');
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                this.disabled = false;
                this.innerHTML = '<i class="bi bi-cloud-slash"></i> Uninstall from Shopify';
            }
        });
    });
});
</script>
