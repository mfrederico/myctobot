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
                                    <input type="text" class="form-control font-monospace" value="php scripts/runpipe.php --tenant=<?= $_SESSION['tenant_slug'] ?? 'default' ?> --pipeline=<?= htmlspecialchars($pipeline['slug']) ?>" readonly id="cliCommand">
                                    <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('cliCommand')">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
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
            <button class="btn btn-sm btn-outline-primary" onclick="addRow()">
                <i class="bi bi-plus-lg"></i> Add Row
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0" id="pipelineGrid">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 60px;" class="text-center">#</th>
                            <?php foreach ($pipeline['columns'] as $colIndex => $colName): ?>
                            <th class="text-center" style="min-width: 200px;">
                                <?= htmlspecialchars($colName) ?>
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
                                       pattern="[a-z][a-z0-9_]*" placeholder="checkout_code">
                                <small class="text-muted">Lowercase, no spaces. Used for references: <code>checkout_code.output</code></small>
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
                                    <option value="goto">Goto...</option>
                                </select>
                            </div>
                            <div class="mb-3" id="gotoSuccessConfig" style="display: none;">
                                <input type="text" class="form-control font-monospace" name="goto_success_target" id="gotoSuccessTarget" placeholder="2.execute or step_name">
                                <small class="text-muted">Format: ROW.COLUMN or step_name</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">On Failure</label>
                                <select class="form-select" name="on_failure" id="onFailureSelect" onchange="onFlowControlChange('failure')">
                                    <option value="exit">Exit (Fail)</option>
                                    <option value="retry">Retry</option>
                                    <option value="skip">Skip to Next</option>
                                    <option value="goto">Goto...</option>
                                </select>
                            </div>
                            <div class="mb-3" id="gotoFailureConfig" style="display: none;">
                                <input type="text" class="form-control font-monospace" name="goto_failure_target" id="gotoFailureTarget" placeholder="1.error_handler or cleanup">
                                <small class="text-muted">Format: ROW.COLUMN or step_name</small>
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

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="stepIsActive" checked>
                        <label class="form-check-label" for="stepIsActive">Step Active</label>
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

function onFlowControlChange(which) {
    if (which === 'success') {
        const select = document.getElementById('onSuccessSelect');
        const config = document.getElementById('gotoSuccessConfig');
        config.style.display = select.value === 'goto' ? 'block' : 'none';
    } else {
        const select = document.getElementById('onFailureSelect');
        const config = document.getElementById('gotoFailureConfig');
        config.style.display = select.value === 'goto' ? 'block' : 'none';
    }
}

function setFlowControlValue(which, value) {
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
    } else {
        if (which === 'success') {
            document.getElementById('onSuccessSelect').value = value || 'next_col';
            document.getElementById('gotoSuccessTarget').value = '';
            document.getElementById('gotoSuccessConfig').style.display = 'none';
        } else {
            document.getElementById('onFailureSelect').value = value || 'exit';
            document.getElementById('gotoFailureTarget').value = '';
            document.getElementById('gotoFailureConfig').style.display = 'none';
        }
    }
}

function getFlowControlValue(which) {
    if (which === 'success') {
        const select = document.getElementById('onSuccessSelect');
        if (select.value === 'goto') {
            const target = document.getElementById('gotoSuccessTarget').value.trim();
            return target ? 'goto:' + target : 'next_col';
        }
        return select.value;
    } else {
        const select = document.getElementById('onFailureSelect');
        if (select.value === 'goto') {
            const target = document.getElementById('gotoFailureTarget').value.trim();
            return target ? 'goto:' + target : 'exit';
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

                stepModal.show();
            } else {
                alert('Error loading step: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

function populateConfig(type, config) {
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
        is_active: document.getElementById('stepIsActive').checked ? '1' : '0'
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

function deleteRow(row) {
    // TODO: Implement row deletion (delete all steps in row)
    alert('Delete row ' + row + ' - not yet implemented');
}

async function saveSettings() {
    const form = document.getElementById('settingsForm');
    const data = new URLSearchParams(new FormData(form));
    data.append('csrf_token', csrfToken);
    data.set('is_active', document.getElementById('isActive').checked ? '1' : '0');

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
