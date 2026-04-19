<div class="container py-4"
     data-assist-page="<?= $app ? 'apps/edit' : 'apps/create' ?>"
     data-assist-purpose="<?= $app ? 'Configure an existing standalone app server that exposes pipelines via HTTP' : 'Create a new standalone app server that exposes pipelines as HTTP endpoints' ?>"
     data-assist-context='{"entity":"app","isNew":<?= $app ? 'false' : 'true' ?>}'>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="/apps">Apps</a></li>
                    <li class="breadcrumb-item active"><?= $app ? 'Edit' : 'Create' ?></li>
                </ol>
            </nav>
            <h1 class="h2 mb-0">
                <i class="bi bi-box-seam"></i>
                <?= $app ? 'Edit App: ' . h($app->name) : 'Create New App' ?>
            </h1>
        </div>
        <?php if ($app): ?>
        <div>
            <?php if ($app->status === 'running'): ?>
            <button type="button" class="btn btn-outline-danger me-2" onclick="stopApp(<?= $app->id ?>)">
                <i class="bi bi-stop-fill"></i> Stop
            </button>
            <?php else: ?>
            <button type="button" class="btn btn-success me-2" onclick="startApp(<?= $app->id ?>)">
                <i class="bi bi-play-fill"></i> Start
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-danger" onclick="deleteApp(<?= $app->id ?>)">
                <i class="bi bi-trash"></i> Delete
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($app && $app->status === 'running' && $app->port): ?>
    <div class="alert alert-success d-flex align-items-center mb-4">
        <i class="bi bi-check-circle-fill me-2"></i>
        <div>
            <strong>App is running</strong> on port <?= $app->port ?>.
            <a href="http://127.0.0.1:<?= $app->port ?>" target="_blank" class="alert-link">
                Open in browser <i class="bi bi-box-arrow-up-right"></i>
            </a>
        </div>
    </div>
    <?php elseif ($app && $app->error_message): ?>
    <div class="alert alert-danger mb-4">
        <strong><i class="bi bi-exclamation-triangle"></i> Error:</strong>
        <pre class="mb-0 mt-2 small"><?= h($app->error_message) ?></pre>
    </div>
    <?php endif; ?>

    <?php if ($app): ?>
    <!-- Status, Endpoints, Quick Actions row -->
    <div class="row mb-4">
        <!-- Status Card -->
        <div class="col-lg-4 mb-3 mb-lg-0">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-activity"></i> Status
                </div>
                <div class="card-body">
                    <dl class="mb-0">
                        <dt>Status</dt>
                        <dd>
                            <span class="badge bg-<?= $app->status === 'running' ? 'success' : ($app->status === 'error' ? 'danger' : 'secondary') ?>">
                                <?= ucfirst($app->status) ?>
                            </span>
                        </dd>

                        <?php if ($app->port): ?>
                        <dt>Port</dt>
                        <dd><code><?= $app->port ?></code></dd>
                        <?php endif; ?>

                        <?php if ($app->last_started_at): ?>
                        <dt>Last Started</dt>
                        <dd class="small text-muted"><?= $app->last_started_at ?></dd>
                        <?php endif; ?>

                        <?php if ($app->last_stopped_at): ?>
                        <dt>Last Stopped</dt>
                        <dd class="small text-muted"><?= $app->last_stopped_at ?></dd>
                        <?php endif; ?>

                        <dt>Created</dt>
                        <dd class="small text-muted"><?= $app->created_at ?></dd>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Endpoints Card -->
        <div class="col-lg-4 mb-3 mb-lg-0">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-link-45deg"></i> Endpoints
                </div>
                <div class="card-body">
                    <?php if ($app->status === 'running' && $app->port): ?>
                    <p class="small text-muted mb-2">Base URL:</p>
                    <code class="d-block bg-light p-2 rounded mb-3">http://127.0.0.1:<?= $app->port ?></code>

                    <table class="table table-sm small mb-0">
                        <tr>
                            <td><code>GET /</code></td>
                            <td>App info</td>
                        </tr>
                        <tr>
                            <td><code>GET /health</code></td>
                            <td>Health check</td>
                        </tr>
                        <?php if ($app->app_type === 'pipeline' && $app->pipelines_id): ?>
                        <tr>
                            <td><code>POST /run</code></td>
                            <td>Execute pipeline</td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($app->expose_mcp): ?>
                        <tr>
                            <td><code>POST /mcp</code></td>
                            <td>MCP JSON-RPC</td>
                        </tr>
                        <?php endif; ?>
                    </table>
                    <?php else: ?>
                    <p class="text-muted small mb-0">Start the app to see available endpoints.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions Card -->
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-lightning"></i> Quick Actions
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-outline-secondary" onclick="showLogs(<?= $app->id ?>, '<?= h($app->name) ?>')">
                            <i class="bi bi-terminal"></i> View Logs
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="checkStatus(<?= $app->id ?>)">
                            <i class="bi bi-heart-pulse"></i> Check Status
                        </button>
                        <?php if ($app->app_type === 'pipeline' && $app->pipelines_id): ?>
                        <a href="/pipelines/edit/<?= $app->pipelines_id ?>" class="btn btn-outline-primary">
                            <i class="bi bi-diagram-3"></i> Edit Pipeline
                        </a>
                        <?php endif; ?>
                        <?php if ($app->app_type === 'builder'): ?>
                        <a href="/appbuilder/index/<?= $app->id ?>" class="btn btn-outline-success">
                            <i class="bi bi-bricks"></i> Open Builder
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- App Configuration -->
    <div class="row">
        <div class="<?= $app ? 'col-12' : 'col-lg-8' ?>">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-gear"></i> App Configuration
                </div>
                <div class="card-body">
                    <form method="POST" action="<?= $app ? '/apps/update/' . $app->id : '/apps/store' ?>"
                          data-assist-form="app-config"
                          data-assist-purpose="Configure app settings including pipeline binding, authentication, and server options">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">

                        <!-- Basic Info -->
                        <div class="mb-4">
                            <h6 class="text-muted mb-3"><i class="bi bi-info-circle"></i> Basic Information</h6>

                            <div class="mb-3">
                                <label for="name" class="form-label">App Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name"
                                       value="<?= h($app->name ?? '') ?>" required
                                       data-assist-field="name"
                                       data-assist-required="true"
                                       data-assist-hint="Required. A descriptive name for your app. Examples: 'Order Webhook', 'Customer Portal API', 'Inventory Sync'."
                            </div>

                            <?php if (!$app): ?>
                            <div class="mb-3">
                                <label for="slug" class="form-label">Slug</label>
                                <input type="text" class="form-control" id="slug" name="slug"
                                       value="" placeholder="auto-generated-from-name"
                                       data-assist-field="slug"
                                       data-assist-hint="URL-safe identifier used in the app's endpoint URLs. Auto-generates from name if left blank. Cannot be changed after creation.">
                                <div class="form-text">URL-friendly identifier. Leave blank to auto-generate.</div>
                            </div>
                            <?php else: ?>
                            <div class="mb-3">
                                <label class="form-label">Slug</label>
                                <input type="text" class="form-control" value="<?= h($app->slug) ?>" disabled>
                                <div class="form-text">Slug cannot be changed after creation.</div>
                            </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="2"
                                          data-assist-field="description"
                                          data-assist-hint="Describe what this app does and its use case. Helps team members understand its purpose."><?= h($app->description ?? '') ?></textarea>
                            </div>
                        </div>

                        <!-- Deployment Target -->
                        <div class="mb-4">
                            <h6 class="text-muted mb-3"><i class="bi bi-rocket-takeoff"></i> Deployment Target</h6>
                            <?php $deployTarget = $app->deployment_target ?? 'local'; ?>
                            <div class="mb-3">
                                <label for="deployment_target" class="form-label">Where will this app run?</label>
                                <select class="form-select" id="deployment_target" name="deployment_target" onchange="toggleDeploymentTarget()">
                                    <option value="local" <?= $deployTarget === 'local' ? 'selected' : '' ?>>Local Service &mdash; OpenSwoole HTTP server on this machine</option>
                                    <option value="docker" <?= $deployTarget === 'docker' ? 'selected' : '' ?>>Docker Container &mdash; Packaged app with Git releases</option>
                                    <option value="shopify" <?= $deployTarget === 'shopify' ? 'selected' : '' ?>>Shopify App &mdash; Liquid theme extension</option>
                                    <option value="hosted" <?= $deployTarget === 'hosted' ? 'selected' : '' ?>>Hosted &mdash; Served at /dapp/&lt;slug&gt;</option>
                                </select>
                            </div>
                        </div>

                        <?php if ($app && in_array($deployTarget, ['docker', 'shopify', 'hosted'])): ?>
                        <!-- Deployment App Quick Actions -->
                        <div class="mb-4" id="deploymentActionsSection">
                            <h6 class="text-muted mb-3"><i class="bi bi-lightning"></i> App Management</h6>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="/apps/screens/<?= $app->id ?>" class="btn btn-outline-primary">
                                    <i class="bi bi-window-stack"></i> Screens
                                </a>
                                <a href="/apps/pipelines/<?= $app->id ?>" class="btn btn-outline-info">
                                    <i class="bi bi-diagram-3"></i> Pipelines
                                </a>
                                <a href="/appbuilder/index/<?= $app->id ?>" class="btn btn-outline-success">
                                    <i class="bi bi-bricks"></i> Open Builder
                                </a>
                                <?php if ($deployTarget === 'docker'): ?>
                                <a href="/apps/releases/<?= $app->id ?>" class="btn btn-outline-warning">
                                    <i class="bi bi-tags"></i> Releases
                                </a>
                                <a href="/apps/build/<?= $app->id ?>" class="btn btn-outline-dark">
                                    <i class="bi bi-box-seam"></i> Build
                                </a>
                                <?php elseif ($deployTarget === 'shopify'): ?>
                                <a href="/apps/buildshopify/<?= $app->id ?>" class="btn btn-outline-warning">
                                    <i class="bi bi-shop"></i> Build for Shopify
                                </a>
                                <a href="/apps/liquidfiles/<?= $app->id ?>" class="btn btn-outline-dark">
                                    <i class="bi bi-filetype-html"></i> Liquid Files
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Docker/Shopify Specific Fields -->
                        <div class="mb-4" id="dockerFields" style="display:<?= in_array($deployTarget, ['docker', 'hosted']) ? 'block' : 'none' ?>;">
                            <h6 class="text-muted mb-3"><i class="bi bi-git"></i> Git & Build Configuration</h6>
                            <div class="mb-3">
                                <label for="git_repo_url" class="form-label">Git Repository URL</label>
                                <input type="text" class="form-control" id="git_repo_url" name="git_repo_url"
                                       value="<?= h($app->git_repo_url ?? '') ?>"
                                       placeholder="git@github.com:org/repo.git">
                                <div class="form-text">SSH URL for the release repository</div>
                            </div>
                            <div class="mb-3">
                                <label for="git_deploy_key" class="form-label">Deploy Key (Private)</label>
                                <textarea class="form-control font-monospace" id="git_deploy_key" name="git_deploy_key" rows="3"
                                          placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"><?= h($app->git_deploy_key ?? '') ?></textarea>
                                <div class="form-text">SSH private key for pushing to the release repo</div>
                            </div>
                        </div>

                        <div class="mb-4" id="shopifyFields" style="display:<?= $deployTarget === 'shopify' ? 'block' : 'none' ?>;">
                            <h6 class="text-muted mb-3"><i class="bi bi-shop"></i> Shopify Configuration</h6>
                            <div class="mb-3">
                                <label for="shopify_connection_id" class="form-label">Shopify Connection</label>
                                <select class="form-select" id="shopify_connection_id" name="shopify_connection_id">
                                    <option value="">-- Select a Shopify store --</option>
                                    <?php foreach ($shopifyConnections ?? [] as $sc): ?>
                                    <option value="<?= $sc->id ?? $sc['id'] ?>"
                                            <?= ($app->shopify_connection_id ?? '') == ($sc->id ?? $sc['id']) ? 'selected' : '' ?>>
                                        <?= h($sc->connection_name ?? $sc->name ?? $sc['connection_name'] ?? $sc['name'] ?? 'Store #' . ($sc->id ?? $sc['id'])) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">The Shopify store to deploy this app to</div>
                            </div>
                        </div>

                        <div class="mb-4" id="stitchFields" style="display:<?= in_array($deployTarget, ['docker', 'shopify', 'hosted']) ? 'block' : 'none' ?>;">
                            <h6 class="text-muted mb-3"><i class="bi bi-palette"></i> Stitch Screen Import</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="stitch_connection_eid" class="form-label">Stitch Connection</label>
                                    <select class="form-select" id="stitch_connection_eid" name="stitch_connection_eid">
                                        <option value="">-- Select Stitch connection --</option>
                                        <?php foreach ($stitchConnections ?? [] as $stc): ?>
                                        <option value="<?= h($stc->external_id ?? $stc->id ?? '') ?>"
                                                <?= ($app->stitch_connection_eid ?? '') == ($stc->external_id ?? $stc->id ?? '') ? 'selected' : '' ?>>
                                            <?= h($stc->connection_name ?? $stc->name ?? 'Stitch') ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="stitch_project_eid" class="form-label">Stitch Project ID</label>
                                    <input type="text" class="form-control" id="stitch_project_eid" name="stitch_project_eid"
                                           value="<?= h($app->stitch_project_eid ?? '') ?>"
                                           placeholder="External project ID">
                                </div>
                            </div>
                        </div>

                        <!-- App Type (local apps only) -->
                        <div class="mb-4" id="appTypeSection">
                            <h6 class="text-muted mb-3"><i class="bi bi-diagram-3"></i> App Type</h6>

                            <div class="mb-3">
                                <label for="app_type" class="form-label">Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="app_type" name="app_type" onchange="togglePipelineSelect()"
                                        data-assist-field="app_type"
                                        data-assist-required="true"
                                        data-assist-hint="Required. Pipeline = runs a pipeline when called. Custom = use config_json to define behavior. Most apps use Pipeline type."
                                    <?php
                                    $selectedAppType = $app->app_type ?? $_GET['app_type'] ?? 'pipeline';
                                    foreach ($appTypes as $type => $info): ?>
                                    <option value="<?= $type ?>"
                                            <?= $selectedAppType === $type ? 'selected' : '' ?>>
                                        <?= $info['label'] ?> - <?= $info['description'] ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="alert alert-info mb-3" id="builderHint" style="display:none;">
                                <i class="bi bi-bricks me-1"></i>
                                Builder apps use a visual drag-and-drop interface. After creating the app, you'll be redirected to the App Builder.
                            </div>

                            <?php if ($app && $app->app_type === 'builder'): ?>
                            <div class="alert alert-info mb-3">
                                <i class="bi bi-bricks me-1"></i>
                                This is a <strong>Builder</strong> app.
                                <a href="/appbuilder/index/<?= $app->id ?>" class="alert-link">Open in App Builder <i class="bi bi-box-arrow-up-right"></i></a>
                            </div>
                            <?php endif; ?>

                            <div class="mb-3" id="pipeline_select_group">
                                <label for="pipeline_id" class="form-label">Pipeline <span class="text-danger">*</span></label>
                                <select class="form-select" id="pipeline_id" name="pipeline_id"
                                        data-assist-field="pipeline_id"
                                        data-assist-required="true"
                                        data-assist-hint="Required (for Pipeline type). The pipeline to execute when the app receives a POST /run request. Create pipelines first in the Pipeline Studio."
                                    <option value="">-- Select a pipeline --</option>
                                    <?php foreach ($pipelines as $p): ?>
                                    <option value="<?= $p['id'] ?>"
                                            <?= ($app->pipelines_id ?? null) == $p['id'] ? 'selected' : '' ?>>
                                        <?= h($p['name']) ?>
                                        <?php if ($p['description']): ?>
                                            - <?= h(substr($p['description'], 0, 50)) ?>
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    The app will expose <code>POST /run</code> to execute this pipeline.
                                </div>
                            </div>
                        </div>

                        <!-- Authentication (local apps) -->
                        <div class="mb-4" data-section="auth">
                            <h6 class="text-muted mb-3"><i class="bi bi-shield-lock"></i> Authentication</h6>

                            <div class="mb-3">
                                <label for="auth_type" class="form-label">Incoming Auth Type</label>
                                <select class="form-select" id="auth_type" name="auth_type" onchange="toggleApiKeyInput()"
                                        data-assist-field="auth_type"
                                        data-assist-hint="API Key = simple token auth (recommended). None = no auth (only for testing). JWT = for advanced integrations.">
                                    <?php foreach ($authTypes as $type => $info): ?>
                                    <option value="<?= $type ?>"
                                            <?= ($app->auth_type ?? 'api_key') === $type ? 'selected' : '' ?>>
                                        <?= $info['label'] ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">How clients authenticate to this app</div>
                            </div>

                            <div class="mb-3" id="api_key_group">
                                <label for="api_key" class="form-label">App API Key</label>
                                <div class="input-group">
                                    <input type="text" class="form-control font-monospace" id="api_key" name="api_key"
                                           value="<?= h($app->api_key ?? '') ?>"
                                           placeholder="app_..."
                                           data-assist-field="api_key"
                                           data-assist-hint="The secret key clients must provide. Click Generate for a secure random key. Share this with API consumers.">
                                    <button type="button" class="btn btn-outline-secondary" onclick="generateApiKey()">
                                        <i class="bi bi-arrow-repeat"></i> Generate
                                    </button>
                                </div>
                                <div class="form-text">
                                    Clients must provide via <code>X-API-Key</code> header or <code>api_key</code> query parameter.
                                </div>
                            </div>

                            <?php if ($app && ($app->app_type ?? 'pipeline') === 'pipeline'): ?>
                            <div class="mb-3" id="pipeline_token_group">
                                <label for="apikeys_id" class="form-label">Pipeline API Token</label>
                                <select class="form-select" id="apikeys_id" name="apikeys_id"
                                        data-assist-field="apikeys_id"
                                        data-assist-hint="The internal API token this app uses to trigger pipelines. Create tokens in API Keys section with 'pipelines:run' scope.">
                                    <option value="">-- Select an API token --</option>
                                    <?php foreach ($apiTokens ?? [] as $token): ?>
                                    <option value="<?= $token['id'] ?>"
                                            <?= ($app->apikeys_id ?? null) == $token['id'] ? 'selected' : '' ?>>
                                        <?= h($token['name'] ?: 'Token #' . $token['id']) ?>
                                        (<?= substr($token['token'], 0, 10) ?>...)
                                        <?php if (!$token['is_active']): ?> [Inactive]<?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    Token used by this app to call the Pipeline API.
                                    Must have <code>pipelines:run</code> scope.
                                    <a href="/apikeys" target="_blank">Manage API tokens</a>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Options (local apps) -->
                        <div class="mb-4" data-section="options">
                            <h6 class="text-muted mb-3"><i class="bi bi-sliders"></i> Options</h6>

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="port" class="form-label">Port</label>
                                        <input type="number" class="form-control" id="port" name="port"
                                               value="<?= $app->port ?? '' ?>"
                                               min="9600" max="9699" placeholder="Auto (9600-9699)"
                                               data-assist-field="port"
                                               data-assist-hint="HTTP port for this app server. Leave blank for auto-assignment (9600-9699 range). Useful for fixed URLs or firewall rules.">
                                        <div class="form-text">Leave blank for auto-assign</div>
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <div class="mb-3">
                                        <label for="health_check_path" class="form-label">Health Check Path</label>
                                        <input type="text" class="form-control" id="health_check_path" name="health_check_path"
                                               value="<?= h($app->health_check_path ?? '/health') ?>"
                                               data-assist-field="health_check_path"
                                               data-assist-hint="Endpoint for health/liveness checks. Default /health returns JSON status. Used by load balancers and monitoring.">
                                    </div>
                                </div>
                            </div>

                            <div class="form-check mb-2">
                                <input type="checkbox" class="form-check-input" id="auto_restart" name="auto_restart" value="1"
                                       <?= !empty($app->auto_restart) ? 'checked' : '' ?>
                                       data-assist-field="auto_restart"
                                       data-assist-hint="When enabled, the app server will automatically restart if it crashes. Recommended for production apps.">
                                <label class="form-check-label" for="auto_restart">
                                    <strong>Auto-restart</strong> - Automatically restart if the server crashes
                                </label>
                            </div>

                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="expose_mcp" name="expose_mcp" value="1"
                                       <?= !empty($app->expose_mcp) ? 'checked' : '' ?>
                                       data-assist-field="expose_mcp"
                                       data-assist-hint="Adds a /mcp endpoint for Model Context Protocol. Allows AI agents to interact with this app as an MCP server.">
                                <label class="form-check-label" for="expose_mcp">
                                    <strong>Expose as MCP Server</strong> - Add /mcp endpoint for MCP protocol
                                </label>
                            </div>
                        </div>

                        <!-- Advanced Configuration -->
                        <div class="mb-4">
                            <h6 class="text-muted mb-3">
                                <a class="text-decoration-none" data-bs-toggle="collapse" href="#advancedConfig" role="button">
                                    <i class="bi bi-code-square"></i> Advanced Configuration
                                    <i class="bi bi-chevron-down small"></i>
                                </a>
                            </h6>

                            <div class="collapse <?= !empty($app->config_json) && $app->config_json !== '{}' ? 'show' : '' ?>" id="advancedConfig">
                                <div class="mb-3">
                                    <label for="config_json" class="form-label">App Configuration (JSON)</label>
                                    <textarea class="form-control font-monospace" id="config_json" name="config_json" rows="12"
                                              data-assist-field="config_json"
                                              data-assist-hint="Advanced JSON config. Use 'datastore.schema' to auto-create SQLite tables. Custom apps can read this config at runtime."
                                              placeholder='{
  "datastore": {
    "type": "sqlite",
    "schema": {
      "table_name": {
        "id": "INTEGER PRIMARY KEY AUTOINCREMENT",
        "name": "TEXT NOT NULL",
        "created_at": "DATETIME DEFAULT CURRENT_TIMESTAMP"
      }
    }
  }
}'><?= h($app->config_json ?? '{}') ?></textarea>
                                    <div class="form-text">
                                        Optional JSON configuration. Use <code>datastore.schema</code> to auto-create SQLite tables for this app.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg"></i> <?= $app ? 'Save Changes' : 'Create App' ?>
                            </button>
                            <a href="/apps" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if (!$app): ?>
        <!-- Help Card for new apps -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-lightbulb"></i> Getting Started
                </div>
                <div class="card-body small">
                    <ol class="mb-0">
                        <li class="mb-2">Enter a name for your app</li>
                        <li class="mb-2">Select a pipeline to expose</li>
                        <li class="mb-2">Configure authentication (API key recommended)</li>
                        <li class="mb-2">Click "Create App"</li>
                        <li>Start the app and test with <code>curl</code></li>
                    </ol>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Logs Modal -->
<div class="modal fade" id="logsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-terminal"></i> App Logs: <span id="logsAppName"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre id="logsContent" class="bg-dark text-light p-3 rounded" style="height: 400px; overflow-y: auto; font-size: 12px;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="refreshLogs()">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Status Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-heart-pulse"></i> App Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre id="statusContent" class="bg-light p-3 rounded"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentLogsAppId = null;

function togglePipelineSelect() {
    const appType = document.getElementById('app_type').value;
    const group = document.getElementById('pipeline_select_group');
    group.style.display = appType === 'pipeline' ? 'block' : 'none';

    // Show builder hint
    const builderHint = document.getElementById('builderHint');
    if (builderHint) builderHint.style.display = appType === 'builder' ? 'block' : 'none';
}

function toggleApiKeyInput() {
    const authType = document.getElementById('auth_type').value;
    const group = document.getElementById('api_key_group');
    group.style.display = authType === 'api_key' ? 'block' : 'none';
}

async function generateApiKey() {
    try {
        const response = await fetch('/apps/generatekey');
        const data = await response.json();
        if (data.success && data.data && data.data.api_key) {
            document.getElementById('api_key').value = data.data.api_key;
        }
    } catch (e) {
        alert('Error generating API key: ' + e.message);
    }
}

async function startApp(id) {
    try {
        const response = await fetch(`/apps/start/${id}`, { method: 'POST' });
        const data = await response.json();
        if (data.success) {
            location.reload();
        } else {
            alert('Failed to start app: ' + (data.message || 'Unknown error'));
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function stopApp(id) {
    if (!confirm('Stop this app?')) return;
    try {
        const response = await fetch(`/apps/stop/${id}`, { method: 'POST' });
        const data = await response.json();
        if (data.success) {
            location.reload();
        } else {
            alert('Failed to stop app: ' + (data.message || 'Unknown error'));
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function deleteApp(id) {
    if (!confirm('Delete this app? This cannot be undone.')) return;
    try {
        const response = await fetch(`/apps/delete/${id}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'csrf_token=' + encodeURIComponent('<?= $_SESSION['csrf_token'] ?? '' ?>')
        });
        const data = await response.json();
        if (data.success) {
            window.location.href = '/apps';
        } else {
            alert('Failed to delete app: ' + (data.message || 'Unknown error'));
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function showLogs(id, name) {
    currentLogsAppId = id;
    document.getElementById('logsAppName').textContent = name;
    document.getElementById('logsContent').textContent = 'Loading...';

    const modal = new bootstrap.Modal(document.getElementById('logsModal'));
    modal.show();

    await refreshLogs();
}

async function refreshLogs() {
    if (!currentLogsAppId) return;
    try {
        const response = await fetch(`/apps/logs/${currentLogsAppId}?lines=200`);
        const data = await response.json();
        if (data.success && data.data) {
            const logs = data.data.logs || {};
            let content = '';
            if (logs.tmux && logs.tmux.length > 0) {
                content += '=== Console Output ===\n' + logs.tmux.join('\n') + '\n\n';
            }
            if (logs.file && logs.file.length > 0) {
                content += '=== File Logs ===\n' + logs.file.join('\n');
            }
            document.getElementById('logsContent').textContent = content || '(No logs available)';
        } else {
            document.getElementById('logsContent').textContent = 'Failed to load logs';
        }
    } catch (e) {
        document.getElementById('logsContent').textContent = 'Error: ' + e.message;
    }
}

async function checkStatus(id) {
    try {
        const response = await fetch(`/apps/status/${id}`);
        const data = await response.json();
        document.getElementById('statusContent').textContent = JSON.stringify(data.data || data, null, 2);
        const modal = new bootstrap.Modal(document.getElementById('statusModal'));
        modal.show();
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Auto-generate slug from app name
function setupSlugGeneration() {
    const nameField = document.getElementById('name');
    const slugField = document.getElementById('slug');

    // Only set up for new apps (slug field is editable)
    if (!nameField || !slugField || slugField.disabled) return;

    nameField.addEventListener('input', function() {
        // Only auto-generate if user hasn't manually edited the slug
        if (!slugField.dataset.userEdited) {
            slugField.value = this.value
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-|-$/g, '');
        }
    });

    slugField.addEventListener('input', function() {
        // Mark as user-edited once they type in it
        this.dataset.userEdited = 'true';
    });
}

function toggleDeploymentTarget() {
    const target = document.getElementById('deployment_target').value;
    const isLocal = (target === 'local');

    // Local-specific sections
    document.getElementById('appTypeSection').style.display = isLocal ? 'block' : 'none';
    const authSection = document.querySelector('[data-section="auth"]');
    if (authSection) authSection.style.display = isLocal ? 'block' : 'none';
    const optionsSection = document.querySelector('[data-section="options"]');
    if (optionsSection) optionsSection.style.display = isLocal ? 'block' : 'none';

    // Docker fields
    document.getElementById('dockerFields').style.display = (target === 'docker' || target === 'hosted') ? 'block' : 'none';

    // Shopify fields
    document.getElementById('shopifyFields').style.display = (target === 'shopify') ? 'block' : 'none';

    // Stitch fields (docker, shopify, hosted)
    document.getElementById('stitchFields').style.display = !isLocal ? 'block' : 'none';
}

// Initialize on load
document.addEventListener('DOMContentLoaded', function() {
    togglePipelineSelect();
    toggleApiKeyInput();
    setupSlugGeneration();
    toggleDeploymentTarget();
});
</script>
