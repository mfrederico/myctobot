<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="/pipelines">Pipelines</a></li>
                    <li class="breadcrumb-item active"><?= $pipeline ? htmlspecialchars($pipeline['name']) : 'New Pipeline' ?></li>
                </ol>
            </nav>
            <h1 class="h2 mb-0">
                <i class="bi bi-diagram-3"></i>
                <?= $pipeline ? 'Edit: ' . htmlspecialchars($pipeline['name']) : 'Create Pipeline' ?>
            </h1>
        </div>
        <div>
            <?php if ($pipeline): ?>
            <a href="/pipelines/runs/<?= $pipeline['id'] ?>" class="btn btn-outline-secondary me-2">
                <i class="bi bi-list-task"></i> View Runs
            </a>
            <button class="btn btn-success" onclick="triggerPipeline()">
                <i class="bi bi-play-fill"></i> Run Pipeline
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$pipeline): ?>
    <!-- Create Form -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-plus-circle"></i> Pipeline Settings
        </div>
        <div class="card-body">
            <form method="POST" action="/pipelines/store">
                <input type="hidden" name="csrf_token" value="<?= Flight::csrf()->getToken() ?>">

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required placeholder="My Deploy Pipeline">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Slug</label>
                            <input type="text" class="form-control" name="slug" placeholder="my-deploy-pipeline">
                            <small class="text-muted">URL-safe identifier. Auto-generated if empty.</small>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="description" rows="2" placeholder="What does this pipeline do?"></textarea>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Trigger Type</label>
                            <select class="form-select" name="trigger_type">
                                <?php foreach ($triggerTypes as $type => $info): ?>
                                <option value="<?= $type ?>"><?= $info['label'] ?> - <?= $info['description'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Columns (phases)</label>
                            <input type="text" class="form-control" name="columns"
                                   value="<?= implode(', ', $defaultColumns ?? ['Start', 'Execute', 'Validate', 'Complete']) ?>"
                                   placeholder="Start, Execute, Validate, Complete">
                            <small class="text-muted">Comma-separated column names</small>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i> Create Pipeline
                </button>
            </form>
        </div>
    </div>
    <?php else: ?>

    <!-- Pipeline Settings Panel (Collapsible) -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center" data-bs-toggle="collapse" data-bs-target="#settingsPanel" style="cursor: pointer;">
            <span><i class="bi bi-gear"></i> Pipeline Settings</span>
            <i class="bi bi-chevron-down"></i>
        </div>
        <div class="collapse" id="settingsPanel">
            <div class="card-body">
                <form id="settingsForm">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Name</label>
                                <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($pipeline['name']) ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Slug</label>
                                <input type="text" class="form-control" name="slug" value="<?= htmlspecialchars($pipeline['slug']) ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Trigger Type</label>
                                <select class="form-select" name="trigger_type">
                                    <?php foreach ($triggerTypes as $type => $info): ?>
                                    <option value="<?= $type ?>" <?= $pipeline['trigger_type'] === $type ? 'selected' : '' ?>>
                                        <?= $info['label'] ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="2"><?= htmlspecialchars($pipeline['description']) ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Columns (Stages)</label>
                        <input type="text" class="form-control" name="columns"
                               value="<?= htmlspecialchars(implode(', ', $pipeline['columns'] ?? [])) ?>"
                               placeholder="Stage 1, Stage 2, Stage 3, Stage 4">
                        <small class="text-muted">Comma-separated column names. Changes take effect after saving.</small>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Webhook URL</label>
                                <div class="input-group">
                                    <input type="text" class="form-control font-monospace" value="<?= htmlspecialchars($webhookUrl) ?>" readonly id="webhookUrl">
                                    <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('webhookUrl')">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                                <small class="text-muted">POST to this URL to trigger the pipeline</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">CLI Command</label>
                                <div class="input-group">
                                    <input type="text" class="form-control font-monospace" value="php scripts/runpipe.php --workspace=<?= $_SESSION['workspace_slug'] ?? 'default' ?> --pipeline=<?= htmlspecialchars($pipeline['slug']) ?>" readonly id="cliCommand">
                                    <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('cliCommand')">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- MCP Tool Exposure -->
                    <div class="card bg-light mb-3">
                        <div class="card-header py-2">
                            <i class="bi bi-robot"></i> Expose as MCP Tool
                        </div>
                        <div class="card-body">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="expose_as_tool" id="exposeAsTool"
                                       <?= $pipeline['expose_as_tool'] ? 'checked' : '' ?> onchange="toggleInputSchema()">
                                <label class="form-check-label" for="exposeAsTool">
                                    <strong>Enable MCP Tool Access</strong>
                                    <small class="d-block text-muted">Allow AI agents to trigger this pipeline via MCP protocol</small>
                                </label>
                            </div>

                            <div id="mcpToolSettings" style="<?= $pipeline['expose_as_tool'] ? '' : 'display: none;' ?>">
                                <div class="mb-3">
                                    <label class="form-label">Tool Name</label>
                                    <div class="input-group">
                                        <span class="input-group-text font-monospace">myctobot_</span>
                                        <input type="text" class="form-control font-monospace" value="<?= htmlspecialchars($pipeline['slug']) ?>" readonly>
                                    </div>
                                    <small class="text-muted">AI agents will call this tool as <code>myctobot_<?= htmlspecialchars($pipeline['slug']) ?></code></small>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">MCP Endpoint</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control font-monospace" value="<?= htmlspecialchars($mcpToolsUrl) ?>" readonly id="mcpToolsUrl">
                                        <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('mcpToolsUrl')">
                                            <i class="bi bi-clipboard"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted">Add this as an MCP server to your AI agent</small>
                                </div>

                                <div class="mb-0">
                                    <label class="form-label">
                                        Input Schema (JSON)
                                        <button type="button" class="btn btn-sm btn-link p-0 ms-2" onclick="insertSampleSchema()">
                                            <i class="bi bi-lightning"></i> Insert sample
                                        </button>
                                    </label>
                                    <textarea class="form-control font-monospace" id="inputSchemaJson" name="input_schema_json" rows="6"
                                              placeholder='{"type": "object", "properties": {...}}'><?= htmlspecialchars($pipeline['input_schema_json']) ?></textarea>
                                    <small class="text-muted">JSON Schema defining the tool's input parameters. Properties become available in pipeline context.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" id="isActive" <?= $pipeline['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="isActive">Pipeline Active</label>
                        </div>
                        <button type="button" class="btn btn-primary" onclick="saveSettings()">
                            <i class="bi bi-check-lg"></i> Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Pipeline Grid -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-grid-3x3"></i> Pipeline Grid</span>
            <div>
                <button class="btn btn-sm btn-outline-secondary me-2" onclick="trimGrid()" title="Remove empty rows and unused columns">
                    <i class="bi bi-scissors"></i> Trim Grid
                </button>
                <button class="btn btn-sm btn-outline-primary" onclick="addRow()">
                    <i class="bi bi-plus-lg"></i> Add Row
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0" id="pipelineGrid">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 60px;" class="text-center">#</th>
                            <?php foreach ($pipeline['columns'] as $colIndex => $colName): ?>
                            <th class="text-center column-header" style="min-width: 200px; cursor: pointer;"
                                data-col-index="<?= $colIndex ?>"
                                ondblclick="renameColumn(<?= $colIndex ?>, this)"
                                title="Double-click to rename">
                                <span class="column-name"><?= htmlspecialchars($colName) ?></span>
                            </th>
                            <?php endforeach; ?>
                            <th style="width: 60px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($row = 0; $row <= max($maxRow, 0); $row++): ?>
                        <tr data-row="<?= $row ?>">
                            <td class="text-center text-muted align-middle"><?= $row + 1 ?></td>
                            <?php foreach ($pipeline['columns'] as $colIndex => $colName): ?>
                            <td class="p-2" data-col="<?= $colIndex ?>">
                                <?php if (isset($stepGrid[$row][$colIndex])): ?>
                                    <?php $step = $stepGrid[$row][$colIndex]; ?>
                                    <div class="step-cell bg-<?= $step['type_info']['color'] ?? 'secondary' ?>-subtle border border-<?= $step['type_info']['color'] ?? 'secondary' ?> rounded p-2 <?= !$step['is_active'] ? 'opacity-50' : '' ?>"
                                         data-step-id="<?= $step['id'] ?>"
                                         onclick="editStep(<?= $step['id'] ?>, <?= $row ?>, <?= $colIndex ?>)">
                                        <div class="d-flex align-items-center mb-1">
                                            <i class="bi <?= $step['type_info']['icon'] ?? 'bi-square' ?> me-2"></i>
                                            <strong class="small"><?= htmlspecialchars($step['label']) ?></strong>
                                        </div>
                                        <code class="small text-muted"><?= htmlspecialchars($step['step_name']) ?></code>
                                        <?php if (!empty($step['condition'])): ?>
                                        <div class="mt-1">
                                            <span class="badge bg-warning text-dark" style="font-size: 0.65rem;">
                                                <i class="bi bi-question-circle"></i> Conditional
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="step-cell-empty border border-dashed rounded p-3 text-center text-muted"
                                         onclick="addStep(<?= $row ?>, <?= $colIndex ?>)"
                                         style="cursor: pointer; min-height: 80px;">
                                        <i class="bi bi-plus-lg"></i>
                                        <div class="small">Add Step</div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                            <td class="text-center align-middle">
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteRow(<?= $row ?>)" title="Delete Row">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<?php if ($pipeline): ?>
<!-- Step Editor Modal -->
<div class="modal fade" id="stepModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="stepModalTitle">
                    <i class="bi bi-puzzle"></i> <span id="stepModalTitleText">Add Step</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="stepForm">
                    <input type="hidden" name="step_id" id="stepId" value="0">
                    <input type="hidden" name="row" id="stepRow" value="0">
                    <input type="hidden" name="col" id="stepCol" value="0">

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Step Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="step_name" id="stepName" required
                                       pattern="[a-z][a-z0-9_]*" placeholder="checkout_code" oninput="updateStepNameHint()">
                                <small class="text-muted">Lowercase, no spaces. Used for references: <code id="stepNameHint">checkout_code</code>.output</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Display Label</label>
                                <input type="text" class="form-control" name="label" id="stepLabel" placeholder="Checkout Code">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Step Type</label>
                        <div class="row g-2">
                            <?php foreach ($stepTypes as $type => $info): ?>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="step_type" id="type_<?= $type ?>"
                                           value="<?= $type ?>" onchange="onStepTypeChange('<?= $type ?>')">
                                    <label class="form-check-label" for="type_<?= $type ?>">
                                        <i class="bi <?= $info['icon'] ?> text-<?= $info['color'] ?>"></i>
                                        <?= $info['label'] ?>
                                        <small class="d-block text-muted"><?= $info['description'] ?></small>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Type-specific config panels -->
                    <div id="configPanels">
                        <!-- AI Agent Config -->
                        <div class="config-panel" id="config_ai_agent" style="display: none;">
                            <div class="card bg-light mb-3">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Agent</label>
                                                <select class="form-select" name="config_agent_id">
                                                    <option value="">Select an agent...</option>
                                                    <?php foreach ($agents as $agent): ?>
                                                    <option value="<?= $agent['id'] ?>"><?= htmlspecialchars($agent['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Runner</label>
                                                <select class="form-select" name="config_runner_id">
                                                    <option value="">Default / Auto</option>
                                                    <?php foreach ($runners as $runner): ?>
                                                    <option value="<?= $runner['id'] ?>"><?= htmlspecialchars($runner['name']) ?> (<?= $runner['host'] ?>)</option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mb-0">
                                        <label class="form-label">Prompt Template</label>
                                        <textarea class="form-control font-monospace" name="config_prompt" rows="3"
                                                  placeholder="Execute the task. Context: {context.issue_key}"></textarea>
                                        <small class="text-muted">Use <code>{context.key}</code> or <code>{step_name.output.key}</code> for variables</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Direct Exec Config -->
                        <div class="config-panel" id="config_direct_exec" style="display: none;">
                            <div class="card bg-light mb-3">
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label class="form-label">Command / Code</label>
                                        <textarea class="form-control font-monospace" name="config_command" rows="3"
                                                  placeholder="echo 'Hello World'"></textarea>
                                        <small class="text-muted">Use <code>{context.key}</code> or <code>{step_name.output.key}</code> for variables</small>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Executor</label>
                                                <input type="text" class="form-control font-monospace" name="config_executor"
                                                       placeholder="/bin/bash -c" list="executorOptions">
                                                <datalist id="executorOptions">
                                                    <option value="/bin/bash -c">Bash shell</option>
                                                    <option value="/bin/zsh -c">Zsh shell</option>
                                                    <option value="/bin/sh -c">POSIX shell</option>
                                                    <option value="/usr/bin/python3 -c">Python code</option>
                                                    <option value="/usr/bin/php -r">PHP code</option>
                                                    <option value="node -e">Node.js code</option>
                                                    <option value="">Direct (no wrapper)</option>
                                                </datalist>
                                                <small class="text-muted">How to run the command. Default: <code>/bin/bash -c</code></small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Workstation (SSH)</label>
                                                <select class="form-select" name="config_workstation_id">
                                                    <option value="">Local execution</option>
                                                    <?php foreach ($workstations ?? [] as $ws): ?>
                                                    <option value="<?= $ws['id'] ?>"><?= htmlspecialchars($ws['name']) ?> (<?= $ws['ssh_user'] ?>@<?= $ws['ssh_host'] ?><?= $ws['ssh_port'] != 22 ? ':' . $ws['ssh_port'] : '' ?>)</option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="text-muted">Run on remote server via SSH</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mb-0">
                                        <label class="form-label">Working Directory</label>
                                        <input type="text" class="form-control" name="config_working_dir" placeholder="/tmp">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Script Config -->
                        <div class="config-panel" id="config_script" style="display: none;">
                            <div class="card bg-light mb-3">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Repository</label>
                                                <select class="form-select" name="config_repo_id">
                                                    <option value="">Select a repository...</option>
                                                    <?php foreach ($repos as $repo): ?>
                                                    <option value="<?= $repo['id'] ?>"><?= htmlspecialchars($repo['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Script Path</label>
                                                <input type="text" class="form-control" name="config_script_path" placeholder="scripts/deploy.sh">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mb-0">
                                        <label class="form-label">Arguments</label>
                                        <input type="text" class="form-control" name="config_script_args" placeholder="--env=staging">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Webhook Out Config -->
                        <div class="config-panel" id="config_webhook_out" style="display: none;">
                            <div class="card bg-light mb-3">
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label class="form-label">URL</label>
                                        <input type="url" class="form-control" name="config_webhook_url" placeholder="https://api.example.com/webhook">
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Method</label>
                                                <select class="form-select" name="config_webhook_method">
                                                    <option value="POST">POST</option>
                                                    <option value="PUT">PUT</option>
                                                    <option value="PATCH">PATCH</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Headers (JSON)</label>
                                                <input type="text" class="form-control font-monospace" name="config_webhook_headers"
                                                       placeholder='{"Authorization": "Bearer ..."}'>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mb-0">
                                        <label class="form-label">Body Template (JSON)</label>
                                        <textarea class="form-control font-monospace" name="config_webhook_body" rows="3"
                                                  placeholder='{"status": "{prev.output.status}"}'></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Parser Config -->
                        <div class="config-panel" id="config_parser" style="display: none;">
                            <div class="card bg-light mb-3">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Parser Type</label>
                                                <select class="form-select" name="config_parser_type">
                                                    <option value="jq">jq (JSON)</option>
                                                    <option value="php">PHP</option>
                                                    <option value="regex">Regex</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-8">
                                            <div class="mb-3">
                                                <label class="form-label">Expression</label>
                                                <input type="text" class="form-control font-monospace" name="config_parser_expression"
                                                       placeholder=".data.items[]">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Wait Config -->
                        <div class="config-panel" id="config_wait" style="display: none;">
                            <div class="card bg-light mb-3">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Wait Type</label>
                                                <select class="form-select" name="config_wait_type">
                                                    <option value="delay">Delay (seconds)</option>
                                                    <option value="approval">Manual Approval</option>
                                                    <option value="webhook">Wait for Webhook</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Duration (seconds)</label>
                                                <input type="number" class="form-control" name="config_wait_duration" value="60" min="1">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Harvest Config -->
                        <div class="config-panel" id="config_harvest" style="display: none;">
                            <div class="card bg-light mb-3">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Harvest Policy</label>
                                                <select class="form-select" name="config_harvest_policy">
                                                    <option value="all_required">All Required - fail if any failed</option>
                                                    <option value="any_success">Any Success - pass if at least one succeeded</option>
                                                    <option value="best_effort">Best Effort - always pass, collect results</option>
                                                </select>
                                                <small class="text-muted">How to handle incomplete/failed parallel rows</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">On Incomplete</label>
                                                <select class="form-select" name="config_harvest_on_incomplete">
                                                    <option value="fail">Fail Pipeline</option>
                                                    <option value="continue">Continue with Partial Results</option>
                                                    <option value="goto">Goto Error Handler</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Output Template (optional, jq expression)</label>
                                        <textarea class="form-control font-monospace" name="config_harvest_template" rows="3"
                                                  placeholder='{"artifacts": [.[] | select(.status == "success") | .output]}'></textarea>
                                        <small class="text-muted">Leave empty for default structure. Use jq to reshape harvested results.</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- MCP Call Config -->
                        <div class="config-panel" id="config_mcp_call" style="display: none;">
                            <div class="card bg-light mb-3">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">MCP Server</label>
                                                <select class="form-select" name="config_mcp_server_id" onchange="onMcpServerChange(this)">
                                                    <option value="">-- Inline Config (no server) --</option>
                                                    <?php foreach ($mcpServers ?? [] as $server): ?>
                                                    <option value="<?= $server['id'] ?>" data-type="<?= $server['server_type'] ?>">
                                                        <?= htmlspecialchars($server['name']) ?> (<?= $server['server_type'] ?>)
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="text-muted">Select a configured MCP server or use inline config</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Tool Name</label>
                                                <input type="text" class="form-control font-monospace" name="config_mcp_tool" placeholder="echo">
                                                <small class="text-muted">The tool to call on the MCP server</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div id="mcpInlineConfig">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Transport Type</label>
                                                    <select class="form-select" name="config_mcp_transport" onchange="onMcpTransportChange(this)">
                                                        <option value="stdio">stdio (subprocess)</option>
                                                        <option value="http">HTTP/SSE</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-6" id="mcpCommandField">
                                                <div class="mb-3">
                                                    <label class="form-label">Command</label>
                                                    <input type="text" class="form-control font-monospace" name="config_mcp_command"
                                                           placeholder="python scripts/test-mcp-server.py">
                                                    <small class="text-muted">Command to start the MCP server</small>
                                                </div>
                                            </div>
                                            <div class="col-md-6" id="mcpUrlField" style="display: none;">
                                                <div class="mb-3">
                                                    <label class="form-label">URL</label>
                                                    <input type="text" class="form-control font-monospace" name="config_mcp_url"
                                                           placeholder="http://localhost:8080/mcp">
                                                    <small class="text-muted">MCP server endpoint URL</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Arguments (JSON)</label>
                                        <textarea class="form-control font-monospace" name="config_mcp_arguments" rows="3"
                                                  placeholder='{"message": "{context.message}"}'></textarea>
                                        <small class="text-muted">Tool arguments as JSON. Use <code>{context.key}</code> or <code>{step.output.key}</code> for variables</small>
                                    </div>

                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="config_mcp_list_tools_only" id="mcpListToolsOnly">
                                        <label class="form-check-label" for="mcpListToolsOnly">
                                            List Tools Only (don't call, just return available tools)
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Input Source -->
                    <div class="mb-3">
                        <label class="form-label">Input Source</label>
                        <select class="form-select" name="input_source" id="inputSource">
                            <option value="context">Context (ENV variables)</option>
                            <option value="stdin">STDIN from previous step</option>
                            <option value="getfrom">Get from specific step</option>
                        </select>
                    </div>

                    <div class="mb-3" id="getfromConfig" style="display: none;">
                        <label class="form-label">Get From Step</label>
                        <input type="text" class="form-control" name="input_getfrom_step" placeholder="checkout_code">
                        <small class="text-muted">Reference another step's output</small>
                    </div>

                    <!-- Flow Control -->
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">On Success</label>
                                <select class="form-select" name="on_success" id="onSuccessSelect" onchange="onFlowControlChange('success')">
                                    <option value="next_col">Next Column</option>
                                    <option value="next_row">Next Row</option>
                                    <option value="exit">Exit (Complete)</option>
                                    <option value="ignore">Ignore (No Action)</option>
                                    <option value="goto">Goto...</option>
                                    <option value="handoff">Handoff to Pipeline...</option>
                                </select>
                            </div>
                            <div class="mb-3" id="gotoSuccessConfig" style="display: none;">
                                <input type="text" class="form-control font-monospace" name="goto_success_target" id="gotoSuccessTarget" placeholder="2.execute or step_name">
                                <small class="text-muted">Format: ROW.COLUMN or step_name</small>
                            </div>
                            <div class="mb-3" id="handoffSuccessConfig" style="display: none;">
                                <input type="text" class="form-control font-monospace" name="handoff_success_target" id="handoffSuccessTarget" placeholder="ci-pipeline.run_tests">
                                <small class="text-muted">Format: pipeline-slug.entry_point</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">On Failure</label>
                                <select class="form-select" name="on_failure" id="onFailureSelect" onchange="onFlowControlChange('failure')">
                                    <option value="exit">Exit (Fail)</option>
                                    <option value="retry">Retry</option>
                                    <option value="skip">Skip to Next</option>
                                    <option value="ignore">Ignore (Continue)</option>
                                    <option value="goto">Goto...</option>
                                    <option value="handoff">Handoff to Pipeline...</option>
                                </select>
                            </div>
                            <div class="mb-3" id="gotoFailureConfig" style="display: none;">
                                <input type="text" class="form-control font-monospace" name="goto_failure_target" id="gotoFailureTarget" placeholder="1.error_handler or cleanup">
                                <small class="text-muted">Format: ROW.COLUMN or step_name</small>
                            </div>
                            <div class="mb-3" id="handoffFailureConfig" style="display: none;">
                                <input type="text" class="form-control font-monospace" name="handoff_failure_target" id="handoffFailureTarget" placeholder="ci-pipeline.rollback">
                                <small class="text-muted">Format: pipeline-slug.entry_point</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Timeout (seconds)</label>
                                <input type="number" class="form-control" name="timeout_seconds" value="300" min="0">
                                <small class="text-muted">0 = no timeout (wait forever)</small>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="stepIsActive" checked>
                                <label class="form-check-label" for="stepIsActive">Step Active</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="run_parallel" id="stepRunParallel">
                                <label class="form-check-label" for="stepRunParallel">Run Row in Parallel</label>
                                <small class="d-block text-muted">This row runs concurrently with other parallel rows</small>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-danger me-auto" id="deleteStepBtn" style="display: none;" onclick="deleteStep()">
                    <i class="bi bi-trash"></i> Delete
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveStep()">
                    <i class="bi bi-check-lg"></i> Save Step
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Trigger Modal -->
<div class="modal fade" id="triggerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-play-fill"></i> Run Pipeline</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Context (JSON, optional)</label>
                    <textarea class="form-control font-monospace" id="triggerContext" rows="4" placeholder='{&#10;  "issue_key": "PROJ-123"&#10;}'></textarea>
                    <small class="text-muted">Pass initial context/variables to the pipeline</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="confirmTrigger()">
                    <i class="bi bi-play-fill"></i> Run
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.step-cell {
    cursor: pointer;
    transition: all 0.2s;
}
.step-cell:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.step-cell-empty {
    background: #f8f9fa;
    transition: all 0.2s;
}
.step-cell-empty:hover {
    background: #e9ecef;
    border-color: #6c757d !important;
}
.border-dashed {
    border-style: dashed !important;
}
</style>

<script>
const csrfToken = '<?= Flight::csrf()->getToken() ?>';
const pipelineId = <?= $pipeline['id'] ?>;
let stepModal = null;

document.addEventListener('DOMContentLoaded', function() {
    stepModal = new bootstrap.Modal(document.getElementById('stepModal'));

    // Input source change handler
    document.getElementById('inputSource').addEventListener('change', function() {
        document.getElementById('getfromConfig').style.display = this.value === 'getfrom' ? 'block' : 'none';
    });
});

function onStepTypeChange(type) {
    // Hide all config panels
    document.querySelectorAll('.config-panel').forEach(panel => {
        panel.style.display = 'none';
    });

    // Show selected type's panel
    const panel = document.getElementById('config_' + type);
    if (panel) {
        panel.style.display = 'block';
    }
}

// MCP Call helpers
function onMcpServerChange(select) {
    const serverId = select.value;
    const inlineConfig = document.getElementById('mcpInlineConfig');
    // Show inline config only when no server is selected
    inlineConfig.style.display = serverId ? 'none' : 'block';
}

function onMcpTransportChange(select) {
    const transport = select.value;
    document.getElementById('mcpCommandField').style.display = transport === 'stdio' ? 'block' : 'none';
    document.getElementById('mcpUrlField').style.display = transport === 'http' ? 'block' : 'none';
}

function updateStepNameHint() {
    const stepName = document.getElementById('stepName').value.trim();
    const hint = document.getElementById('stepNameHint');
    hint.textContent = stepName || 'step_name';
}

function onFlowControlChange(which) {
    if (which === 'success') {
        const select = document.getElementById('onSuccessSelect');
        document.getElementById('gotoSuccessConfig').style.display = select.value === 'goto' ? 'block' : 'none';
        document.getElementById('handoffSuccessConfig').style.display = select.value === 'handoff' ? 'block' : 'none';
    } else {
        const select = document.getElementById('onFailureSelect');
        document.getElementById('gotoFailureConfig').style.display = select.value === 'goto' ? 'block' : 'none';
        document.getElementById('handoffFailureConfig').style.display = select.value === 'handoff' ? 'block' : 'none';
    }
}

function setFlowControlValue(which, value) {
    // Reset all config fields first
    if (which === 'success') {
        document.getElementById('gotoSuccessConfig').style.display = 'none';
        document.getElementById('handoffSuccessConfig').style.display = 'none';
        document.getElementById('gotoSuccessTarget').value = '';
        document.getElementById('handoffSuccessTarget').value = '';
    } else {
        document.getElementById('gotoFailureConfig').style.display = 'none';
        document.getElementById('handoffFailureConfig').style.display = 'none';
        document.getElementById('gotoFailureTarget').value = '';
        document.getElementById('handoffFailureTarget').value = '';
    }

    // Check if it's a goto value
    if (value && value.startsWith('goto:')) {
        const target = value.substring(5);
        if (which === 'success') {
            document.getElementById('onSuccessSelect').value = 'goto';
            document.getElementById('gotoSuccessTarget').value = target;
            document.getElementById('gotoSuccessConfig').style.display = 'block';
        } else {
            document.getElementById('onFailureSelect').value = 'goto';
            document.getElementById('gotoFailureTarget').value = target;
            document.getElementById('gotoFailureConfig').style.display = 'block';
        }
    // Check if it's a handoff value
    } else if (value && value.startsWith('handoff:')) {
        const target = value.substring(8);
        if (which === 'success') {
            document.getElementById('onSuccessSelect').value = 'handoff';
            document.getElementById('handoffSuccessTarget').value = target;
            document.getElementById('handoffSuccessConfig').style.display = 'block';
        } else {
            document.getElementById('onFailureSelect').value = 'handoff';
            document.getElementById('handoffFailureTarget').value = target;
            document.getElementById('handoffFailureConfig').style.display = 'block';
        }
    } else {
        if (which === 'success') {
            document.getElementById('onSuccessSelect').value = value || 'next_col';
        } else {
            document.getElementById('onFailureSelect').value = value || 'exit';
        }
    }
}

function getFlowControlValue(which) {
    if (which === 'success') {
        const select = document.getElementById('onSuccessSelect');
        if (select.value === 'goto') {
            const target = document.getElementById('gotoSuccessTarget').value.trim();
            return target ? 'goto:' + target : 'next_col';
        } else if (select.value === 'handoff') {
            const target = document.getElementById('handoffSuccessTarget').value.trim();
            return target ? 'handoff:' + target : 'next_col';
        }
        return select.value;
    } else {
        const select = document.getElementById('onFailureSelect');
        if (select.value === 'goto') {
            const target = document.getElementById('gotoFailureTarget').value.trim();
            return target ? 'goto:' + target : 'exit';
        } else if (select.value === 'handoff') {
            const target = document.getElementById('handoffFailureTarget').value.trim();
            return target ? 'handoff:' + target : 'exit';
        }
        return select.value;
    }
}

function addStep(row, col) {
    document.getElementById('stepModalTitleText').textContent = 'Add Step';
    document.getElementById('stepId').value = '0';
    document.getElementById('stepRow').value = row;
    document.getElementById('stepCol').value = col;
    document.getElementById('stepForm').reset();
    document.getElementById('deleteStepBtn').style.display = 'none';
    document.getElementById('type_direct_exec').checked = true;
    onStepTypeChange('direct_exec');
    // Reset flow control to defaults
    setFlowControlValue('success', 'next_col');
    setFlowControlValue('failure', 'exit');
    // Reset step name hint
    updateStepNameHint();
    stepModal.show();
}

function editStep(stepId, row, col) {
    document.getElementById('stepModalTitleText').textContent = 'Edit Step';
    document.getElementById('stepId').value = stepId;
    document.getElementById('stepRow').value = row;
    document.getElementById('stepCol').value = col;
    document.getElementById('deleteStepBtn').style.display = 'block';

    // Fetch step data
    fetch('/pipelines/getstep/' + pipelineId + '?step_id=' + stepId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const step = data.data.step;
                document.getElementById('stepName').value = step.step_name;
                document.getElementById('stepLabel').value = step.label;
                updateStepNameHint();

                // Set step type
                const typeRadio = document.getElementById('type_' + step.step_type);
                if (typeRadio) {
                    typeRadio.checked = true;
                    onStepTypeChange(step.step_type);
                }

                // Set config values based on type
                populateConfig(step.step_type, step.config);

                // Set other fields
                document.getElementById('inputSource').value = step.input_source;
                document.getElementById('getfromConfig').style.display = step.input_source === 'getfrom' ? 'block' : 'none';
                if (step.input_config && step.input_config.step) {
                    document.querySelector('[name="input_getfrom_step"]').value = step.input_config.step;
                }

                setFlowControlValue('success', step.on_success);
                setFlowControlValue('failure', step.on_failure);
                document.querySelector('[name="timeout_seconds"]').value = step.timeout_seconds;
                document.getElementById('stepIsActive').checked = step.is_active;
                document.getElementById('stepRunParallel').checked = step.run_parallel;

                stepModal.show();
            } else {
                alert('Error loading step: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

function populateConfig(type, config) {
    // Ensure config is an object to prevent null reference errors
    config = config || {};

    switch (type) {
        case 'ai_agent':
            document.querySelector('[name="config_agent_id"]').value = config.agent_id || '';
            document.querySelector('[name="config_runner_id"]').value = config.runner_id || '';
            document.querySelector('[name="config_prompt"]').value = config.prompt || '';
            break;
        case 'direct_exec':
            document.querySelector('[name="config_command"]').value = config.command || '';
            document.querySelector('[name="config_executor"]').value = config.executor || '';
            document.querySelector('[name="config_workstation_id"]').value = config.workstation_id || '';
            document.querySelector('[name="config_working_dir"]').value = config.working_dir || '';
            break;
        case 'script':
            document.querySelector('[name="config_repo_id"]').value = config.repo_id || '';
            document.querySelector('[name="config_script_path"]').value = config.script_path || '';
            document.querySelector('[name="config_script_args"]').value = config.args || '';
            break;
        case 'webhook_out':
            document.querySelector('[name="config_webhook_url"]').value = config.url || '';
            document.querySelector('[name="config_webhook_method"]').value = config.method || 'POST';
            document.querySelector('[name="config_webhook_headers"]').value = config.headers ? JSON.stringify(config.headers) : '';
            document.querySelector('[name="config_webhook_body"]').value = config.body || '';
            break;
        case 'parser':
            document.querySelector('[name="config_parser_type"]').value = config.parser_type || 'jq';
            document.querySelector('[name="config_parser_expression"]').value = config.expression || '';
            break;
        case 'wait':
            document.querySelector('[name="config_wait_type"]').value = config.wait_type || 'delay';
            document.querySelector('[name="config_wait_duration"]').value = config.duration || 60;
            break;
        case 'harvest':
            document.querySelector('[name="config_harvest_policy"]').value = config.policy || 'all_required';
            document.querySelector('[name="config_harvest_on_incomplete"]').value = config.on_incomplete || 'fail';
            document.querySelector('[name="config_harvest_template"]').value = config.template || '';
            break;
        case 'mcp_call':
            document.querySelector('[name="config_mcp_server_id"]').value = config.mcp_server_id || '';
            document.querySelector('[name="config_mcp_tool"]').value = config.tool || '';
            document.querySelector('[name="config_mcp_transport"]').value = config.transport || 'stdio';
            document.querySelector('[name="config_mcp_command"]').value = config.command || '';
            document.querySelector('[name="config_mcp_url"]').value = config.url || '';
            document.querySelector('[name="config_mcp_arguments"]').value = config.arguments ? JSON.stringify(config.arguments, null, 2) : '';
            document.getElementById('mcpListToolsOnly').checked = config.list_tools_only || false;
            // Show/hide inline config based on server selection
            onMcpServerChange(document.querySelector('[name="config_mcp_server_id"]'));
            // Show/hide command/url based on transport
            onMcpTransportChange(document.querySelector('[name="config_mcp_transport"]'));
            break;
    }
}

function buildConfig() {
    const type = document.querySelector('[name="step_type"]:checked').value;
    let config = {};

    switch (type) {
        case 'ai_agent':
            config = {
                agent_id: document.querySelector('[name="config_agent_id"]').value,
                runner_id: document.querySelector('[name="config_runner_id"]').value,
                prompt: document.querySelector('[name="config_prompt"]').value
            };
            break;
        case 'direct_exec':
            config = {
                command: document.querySelector('[name="config_command"]').value,
                executor: document.querySelector('[name="config_executor"]').value,
                workstation_id: document.querySelector('[name="config_workstation_id"]').value,
                working_dir: document.querySelector('[name="config_working_dir"]').value
            };
            break;
        case 'script':
            config = {
                repo_id: document.querySelector('[name="config_repo_id"]').value,
                script_path: document.querySelector('[name="config_script_path"]').value,
                args: document.querySelector('[name="config_script_args"]').value
            };
            break;
        case 'webhook_out':
            let headers = {};
            try {
                headers = JSON.parse(document.querySelector('[name="config_webhook_headers"]').value || '{}');
            } catch (e) {}
            config = {
                url: document.querySelector('[name="config_webhook_url"]').value,
                method: document.querySelector('[name="config_webhook_method"]').value,
                headers: headers,
                body: document.querySelector('[name="config_webhook_body"]').value
            };
            break;
        case 'parser':
            config = {
                parser_type: document.querySelector('[name="config_parser_type"]').value,
                expression: document.querySelector('[name="config_parser_expression"]').value
            };
            break;
        case 'wait':
            config = {
                wait_type: document.querySelector('[name="config_wait_type"]').value,
                duration: parseInt(document.querySelector('[name="config_wait_duration"]').value) || 60
            };
            break;
        case 'harvest':
            config = {
                policy: document.querySelector('[name="config_harvest_policy"]').value,
                on_incomplete: document.querySelector('[name="config_harvest_on_incomplete"]').value,
                template: document.querySelector('[name="config_harvest_template"]').value
            };
            break;
        case 'mcp_call':
            let mcpArgs = {};
            try {
                mcpArgs = JSON.parse(document.querySelector('[name="config_mcp_arguments"]').value || '{}');
            } catch (e) {}
            config = {
                mcp_server_id: document.querySelector('[name="config_mcp_server_id"]').value,
                tool: document.querySelector('[name="config_mcp_tool"]').value,
                transport: document.querySelector('[name="config_mcp_transport"]').value,
                command: document.querySelector('[name="config_mcp_command"]').value,
                url: document.querySelector('[name="config_mcp_url"]').value,
                arguments: mcpArgs,
                list_tools_only: document.getElementById('mcpListToolsOnly').checked
            };
            break;
    }

    return config;
}

async function saveStep() {
    const form = document.getElementById('stepForm');
    const stepType = document.querySelector('[name="step_type"]:checked')?.value;

    if (!stepType) {
        alert('Please select a step type');
        return;
    }

    const config = buildConfig();
    const inputSource = document.getElementById('inputSource').value;
    let inputConfig = {};
    if (inputSource === 'getfrom') {
        inputConfig = { step: document.querySelector('[name="input_getfrom_step"]').value };
    }

    const data = new URLSearchParams({
        csrf_token: csrfToken,
        step_id: document.getElementById('stepId').value,
        step_name: document.getElementById('stepName').value,
        label: document.getElementById('stepLabel').value,
        row: document.getElementById('stepRow').value,
        col: document.getElementById('stepCol').value,
        step_type: stepType,
        config: JSON.stringify(config),
        input_source: inputSource,
        input_config: JSON.stringify(inputConfig),
        condition: '{}',
        on_success: getFlowControlValue('success'),
        on_failure: getFlowControlValue('failure'),
        timeout_seconds: document.querySelector('[name="timeout_seconds"]').value,
        is_active: document.getElementById('stepIsActive').checked ? '1' : '0',
        run_parallel: document.getElementById('stepRunParallel').checked ? '1' : '0'
    });

    try {
        const response = await fetch('/pipelines/savestep/' + pipelineId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: data.toString()
        });

        const result = await response.json();

        if (result.success) {
            stepModal.hide();
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Failed to save step'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

async function deleteStep() {
    const stepId = document.getElementById('stepId').value;

    if (!confirm('Are you sure you want to delete this step?')) {
        return;
    }

    try {
        const response = await fetch('/pipelines/deletestep/' + pipelineId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&step_id=' + stepId
        });

        const result = await response.json();

        if (result.success) {
            stepModal.hide();
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Failed to delete step'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

function addRow() {
    location.reload(); // Simple approach - page will show new empty row
}

// Double-click column header to rename
function renameColumn(colIndex, thElement) {
    const nameSpan = thElement.querySelector('.column-name');
    const currentName = nameSpan.textContent.trim();

    // Create input field
    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentName;
    input.className = 'form-control form-control-sm text-center';
    input.style.minWidth = '100px';

    // Replace span with input
    nameSpan.style.display = 'none';
    thElement.appendChild(input);
    input.focus();
    input.select();

    // Handle save on blur or enter
    const saveRename = async () => {
        const newName = input.value.trim();
        if (newName && newName !== currentName) {
            // Get current columns from all headers
            const headers = document.querySelectorAll('.column-header .column-name');
            const columns = Array.from(headers).map((h, i) =>
                i === colIndex ? newName : h.textContent.trim()
            );

            // Save via API
            try {
                const data = new URLSearchParams();
                data.append('columns', columns.join(', '));
                data.append('csrf_token', csrfToken);

                const response = await fetch('/pipelines/update/' + pipelineId, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-Token': csrfToken
                    },
                    body: data.toString()
                });

                if (response.ok) {
                    nameSpan.textContent = newName;
                }
            } catch (err) {
                console.error('Failed to rename column:', err);
            }
        }

        // Restore span
        input.remove();
        nameSpan.style.display = '';
    };

    input.addEventListener('blur', saveRename);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            input.blur();
        } else if (e.key === 'Escape') {
            input.value = currentName; // Reset
            input.blur();
        }
    });
}

// Trim unused rows and columns from grid
async function trimGrid() {
    // Find all steps to determine used rows/columns
    const steps = document.querySelectorAll('[data-step-id]');
    if (steps.length === 0) {
        alert('No steps to trim around.');
        return;
    }

    let minRow = Infinity, maxRow = -1;
    let minCol = Infinity, maxCol = -1;

    steps.forEach(step => {
        const td = step.closest('td');
        const tr = td.closest('tr');
        const row = parseInt(tr.dataset.row);
        const col = parseInt(td.dataset.col);

        minRow = Math.min(minRow, row);
        maxRow = Math.max(maxRow, row);
        minCol = Math.min(minCol, col);
        maxCol = Math.max(maxCol, col);
    });

    // Get current columns
    const headers = document.querySelectorAll('.column-header .column-name');
    const allColumns = Array.from(headers).map(h => h.textContent.trim());

    // Trim to used columns (with 1 buffer on each side if possible)
    const startCol = Math.max(0, minCol);
    const endCol = Math.min(allColumns.length - 1, maxCol + 1); // +1 for one empty column after
    const trimmedColumns = allColumns.slice(startCol, endCol + 1);

    if (trimmedColumns.length === allColumns.length) {
        alert('Grid is already optimized - no unused columns to trim.');
        return;
    }

    if (!confirm(`Trim grid to ${trimmedColumns.length} columns (${trimmedColumns.join(', ')})?\n\nThis will remove ${allColumns.length - trimmedColumns.length} unused columns.`)) {
        return;
    }

    // Save trimmed columns
    try {
        const data = new URLSearchParams();
        data.append('columns', trimmedColumns.join(', '));
        data.append('csrf_token', csrfToken);

        const response = await fetch('/pipelines/update/' + pipelineId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: data.toString()
        });

        if (response.ok) {
            location.reload();
        } else {
            alert('Failed to trim grid');
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

function deleteRow(row) {
    // TODO: Implement row deletion (delete all steps in row)
    alert('Delete row ' + row + ' - not yet implemented');
}

// MCP Tool exposure functions
function toggleInputSchema() {
    const enabled = document.getElementById('exposeAsTool').checked;
    document.getElementById('mcpToolSettings').style.display = enabled ? '' : 'none';
}

function insertSampleSchema() {
    const sampleSchema = {
        "type": "object",
        "properties": {
            "message": {
                "type": "string",
                "description": "A message to pass to the pipeline"
            },
            "options": {
                "type": "object",
                "description": "Optional configuration parameters"
            }
        },
        "required": ["message"]
    };
    document.getElementById('inputSchemaJson').value = JSON.stringify(sampleSchema, null, 2);
}

async function saveSettings() {
    const form = document.getElementById('settingsForm');
    const data = new URLSearchParams(new FormData(form));
    data.append('csrf_token', csrfToken);
    data.set('is_active', document.getElementById('isActive').checked ? '1' : '0');
    data.set('expose_as_tool', document.getElementById('exposeAsTool').checked ? '1' : '0');

    // Include input schema
    const inputSchemaEl = document.getElementById('inputSchemaJson');
    if (inputSchemaEl) {
        data.set('input_schema_json', inputSchemaEl.value);
    }

    try {
        const response = await fetch('/pipelines/update/' + pipelineId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: data.toString()
        });

        const result = await response.json();

        if (result.success) {
            alert('Settings saved');
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Failed to save settings'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

function triggerPipeline() {
    document.getElementById('triggerContext').value = '';
    new bootstrap.Modal(document.getElementById('triggerModal')).show();
}

async function confirmTrigger() {
    const context = document.getElementById('triggerContext').value || '{}';

    try {
        const response = await fetch('/pipelines/trigger/' + pipelineId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&context=' + encodeURIComponent(context)
        });

        const result = await response.json();

        if (result.success) {
            window.location.href = '/pipelines/viewrun/' + result.data.run_id;
        } else {
            alert('Error: ' + (result.error || 'Failed to start pipeline'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

function copyToClipboard(elementId) {
    const el = document.getElementById(elementId);
    el.select();
    document.execCommand('copy');

    const btn = el.nextElementSibling;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check"></i>';
    btn.classList.remove('btn-outline-secondary');
    btn.classList.add('btn-success');
    setTimeout(() => {
        btn.innerHTML = originalHtml;
        btn.classList.remove('btn-success');
        btn.classList.add('btn-outline-secondary');
    }, 1500);
}
</script>
<?php endif; ?>
