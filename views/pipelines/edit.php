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
            <div class="btn-group">
                <button class="btn btn-success" onclick="triggerPipeline()">
                    <i class="bi bi-play-fill"></i> Run Pipeline
                </button>
                <button type="button" class="btn btn-success dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="visually-hidden">Toggle Dropdown</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <a class="dropdown-item" href="#" onclick="triggerPipeline(); return false;">
                            <i class="bi bi-play-fill text-success"></i> Run Pipeline
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item" href="#" onclick="triggerInteractive(); return false;">
                            <i class="bi bi-bug text-warning"></i> Interactive/Debug Run
                            <small class="d-block text-muted">Pause after each step to map output fields</small>
                        </a>
                    </li>
                </ul>
            </div>
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
                            <th style="width: 80px;" class="text-center align-middle">
                                <button class="btn btn-sm btn-outline-primary" onclick="addColumn()" title="Add Column">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($row = 0; $row <= max($maxRow, 0); $row++):
                            // Check if all steps in this row are inactive
                            $rowSteps = array_filter($stepGrid[$row] ?? [], fn($s) => isset($s['id']));
                            $rowActive = empty($rowSteps) || array_reduce($rowSteps, fn($carry, $s) => $carry || $s['is_active'], false);
                        ?>
                        <tr data-row="<?= $row ?>" class="<?= !$rowActive ? 'row-disabled' : '' ?>">
                            <td class="text-center text-muted align-middle"><?= $row + 1 ?></td>
                            <?php foreach ($pipeline['columns'] as $colIndex => $colName): ?>
                            <td class="p-2 drop-zone" data-row="<?= $row ?>" data-col="<?= $colIndex ?>"
                                ondragover="handleDragOver(event)" ondrop="handleDrop(event, <?= $row ?>, <?= $colIndex ?>)"
                                ondragleave="handleDragLeave(event)">
                                <?php if (isset($stepGrid[$row][$colIndex])): ?>
                                    <?php $step = $stepGrid[$row][$colIndex]; ?>
                                    <div class="step-cell bg-<?= $step['type_info']['color'] ?? 'secondary' ?>-subtle border border-<?= $step['type_info']['color'] ?? 'secondary' ?> rounded p-2 <?= !$step['is_active'] ? 'opacity-50' : '' ?>"
                                         data-step-id="<?= $step['id'] ?>"
                                         draggable="true"
                                         ondragstart="handleDragStart(event, <?= $step['id'] ?>, <?= $row ?>, <?= $colIndex ?>)"
                                         ondragend="handleDragEnd(event)"
                                         onclick="editStep(<?= $step['id'] ?>, <?= $row ?>, <?= $colIndex ?>)">
                                        <div class="d-flex align-items-center mb-1">
                                            <i class="bi bi-grip-vertical drag-handle me-1 text-muted"></i>
                                            <i class="bi <?= $step['type_info']['icon'] ?? 'bi-square' ?> me-1"></i>
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
                                <div class="btn-group-vertical btn-group-sm">
                                    <button class="btn btn-outline-<?= $rowActive ? 'warning' : 'success' ?>"
                                            onclick="toggleRow(<?= $row ?>, <?= $rowActive ? 'false' : 'true' ?>)"
                                            title="<?= $rowActive ? 'Disable Row' : 'Enable Row' ?>">
                                        <i class="bi bi-<?= $rowActive ? 'pause-circle' : 'play-circle' ?>"></i>
                                    </button>
                                    <button class="btn btn-outline-danger" onclick="deleteRow(<?= $row ?>)" title="Delete Row">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
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
                        <div class="accordion accordion-flush" id="stepTypeAccordion">
                            <?php $catIndex = 0; foreach ($stepTypesGrouped as $catKey => $category): $catIndex++; ?>
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button <?= $catIndex > 1 ? 'collapsed' : '' ?> py-2" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#stepCat_<?= $catKey ?>">
                                        <i class="bi <?= $category['icon'] ?> me-2"></i>
                                        <strong><?= $category['label'] ?></strong>
                                        <span class="badge bg-secondary ms-2"><?= count($category['types']) ?></span>
                                    </button>
                                </h2>
                                <div id="stepCat_<?= $catKey ?>" class="accordion-collapse collapse <?= $catIndex === 1 ? 'show' : '' ?>"
                                     data-bs-parent="#stepTypeAccordion">
                                    <div class="accordion-body py-2">
                                        <div class="row g-2">
                                            <?php foreach ($category['types'] as $type => $info): ?>
                                            <div class="col-md-6">
                                                <div class="form-check step-type-option" onclick="selectStepType('<?= $type ?>')">
                                                    <input class="form-check-input" type="radio" name="step_type" id="type_<?= $type ?>"
                                                           value="<?= $type ?>" onchange="onStepTypeChange('<?= $type ?>')">
                                                    <label class="form-check-label w-100 cursor-pointer" for="type_<?= $type ?>">
                                                        <i class="bi <?= $info['icon'] ?> text-<?= $info['color'] ?>"></i>
                                                        <?= $info['label'] ?>
                                                        <small class="d-block text-muted"><?= $info['description'] ?></small>
                                                    </label>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Type-specific config panels (auto-discovered) -->
                    <div id="configPanels">
                        <?php foreach ($stepTypes as $type => $info): ?>
                        <?php if (isset($info['partial_path']) && file_exists($info['partial_path'])): ?>
                        <?php include $info['partial_path']; ?>
                        <?php endif; ?>
                        <?php endforeach; ?>
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

                    <!-- Variable Browser -->
                    <div class="card bg-light mb-3" id="variableBrowserCard">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center"
                             data-bs-toggle="collapse" data-bs-target="#variableBrowserPanel"
                             style="cursor: pointer;" role="button">
                            <span>
                                <i class="bi bi-braces"></i> Variable Browser
                                <span class="badge bg-secondary ms-1" id="variableCount">0</span>
                            </span>
                            <i class="bi bi-chevron-down" id="variableBrowserChevron"></i>
                        </div>
                        <div class="collapse" id="variableBrowserPanel">
                            <div class="card-body py-2">
                                <p class="small text-muted mb-2">
                                    Click a variable to insert <code>{variable}</code> at cursor, or right-click to copy.
                                </p>

                                <!-- Built-in Variables -->
                                <div class="mb-2" id="builtinVarsSection" style="display: none;">
                                    <strong class="small text-muted">Built-in Context</strong>
                                    <div class="d-flex flex-wrap gap-1 mt-1" id="builtinVarsList"></div>
                                </div>

                                <!-- Pipeline Input Variables -->
                                <div class="mb-2" id="contextVarsSection" style="display: none;">
                                    <strong class="small text-muted">Pipeline Input</strong>
                                    <div class="d-flex flex-wrap gap-1 mt-1" id="contextVarsList"></div>
                                </div>

                                <!-- Previous Step Variables -->
                                <div id="stepVarsSection" style="display: none;">
                                    <div class="d-flex align-items-center mb-1">
                                        <strong class="small text-muted">From Previous Steps</strong>
                                        <i class="bi bi-question-circle ms-1 text-muted"
                                           style="cursor: help; font-size: 0.75rem;"
                                           data-bs-toggle="popover"
                                           data-bs-trigger="hover focus"
                                           data-bs-html="true"
                                           data-bs-content="<strong>.stdout</strong> = Raw text output from the command<br><br><strong>.output</strong> = Parsed/structured data (JSON object)<br><br><strong>.output.field</strong> = Access specific fields from structured output<br><br><em>Example:</em> A shell command's stdout is the raw text, while a Shopify step's output contains parsed data like <code>output.data.shop.name</code>"></i>
                                    </div>
                                    <div id="stepVarsList" class="mt-1"></div>
                                </div>

                                <!-- Loading state -->
                                <div id="variablesLoading" class="text-center py-2">
                                    <span class="spinner-border spinner-border-sm"></span>
                                    <span class="small text-muted ms-1">Loading variables...</span>
                                </div>

                                <!-- Empty state -->
                                <div id="variablesEmpty" class="text-center py-2 text-muted small" style="display: none;">
                                    <i class="bi bi-info-circle"></i> No previous steps - this is the first step.
                                </div>
                            </div>
                        </div>
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
                <h5 class="modal-title" id="triggerModalLabel"><i class="bi bi-play-fill"></i> Run Pipeline</h5>
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
                <button type="button" class="btn btn-success" id="triggerModalSubmit" onclick="confirmTrigger()">
                    <i class="bi bi-play-fill"></i> Run
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* Make placeholders lighter so they don't look like real content */
#stepModal input::placeholder,
#stepModal textarea::placeholder,
#stepModal select::placeholder {
    color: #adb5bd !important;
    opacity: 1;
    font-style: italic;
}
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
/* Drag and Drop */
.step-cell[draggable="true"] {
    cursor: grab;
}
.step-cell[draggable="true"]:active {
    cursor: grabbing;
}
.step-cell.dragging {
    opacity: 0.4;
    transform: scale(0.95);
}
.drop-zone.drag-over {
    background: #cfe2ff !important;
}
.drop-zone.drag-over .step-cell-empty {
    border-color: #0d6efd !important;
    background: transparent;
}
.drag-handle {
    cursor: grab;
    opacity: 0.4;
}
.step-cell:hover .drag-handle {
    opacity: 1;
}
/* Row disabled state */
tr.row-disabled {
    background: #f8f9fa;
}
tr.row-disabled .step-cell {
    opacity: 0.35;
}
tr.row-disabled td:first-child {
    text-decoration: line-through;
}
/* Step type accordion */
.step-type-option {
    padding: 8px 12px;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    transition: all 0.15s;
    cursor: pointer;
    position: relative;
}
.step-type-option:hover {
    background: #f8f9fa;
    border-color: #6c757d;
}
.step-type-option.selected {
    background: #e7f1ff;
    border-color: #0d6efd;
}
.step-type-option.selected::after {
    content: '\f26b';
    font-family: 'bootstrap-icons';
    position: absolute;
    top: 8px;
    right: 8px;
    color: #0d6efd;
    font-size: 0.9rem;
}
.step-type-option .form-check-input {
    display: none;
}
.cursor-pointer {
    cursor: pointer;
}
#stepTypeAccordion .accordion-button {
    background: #f8f9fa;
}
#stepTypeAccordion .accordion-button:not(.collapsed) {
    background: #e7f1ff;
    color: #0a58ca;
}
/* Variable Browser */
.variable-chip {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    font-size: 0.75rem;
    font-family: monospace;
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}
.variable-chip:hover {
    background: #e7f1ff;
    border-color: #0d6efd;
    color: #0d6efd;
}
.variable-chip.mapped {
    background: #d1e7dd;
    border-color: #198754;
}
.variable-chip.mapped:hover {
    background: #badbcc;
}
.variable-chip .bi {
    font-size: 0.65rem;
    margin-right: 3px;
    opacity: 0.6;
}
.step-vars-group {
    margin-bottom: 8px;
    padding: 6px 8px;
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 4px;
}
.step-vars-group .step-name {
    font-size: 0.7rem;
    font-weight: 600;
    color: #6c757d;
    margin-bottom: 4px;
}
.step-vars-group .step-vars {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}
#variableBrowserPanel.show ~ .card-header #variableBrowserChevron {
    transform: rotate(180deg);
}
.variable-tooltip {
    position: absolute;
    z-index: 1070;
    padding: 4px 8px;
    font-size: 0.75rem;
    background: #212529;
    color: #fff;
    border-radius: 4px;
    max-width: 250px;
    pointer-events: none;
}
</style>

<script>
const csrfToken = '<?= Flight::csrf()->getToken() ?>';
const pipelineId = <?= $pipeline['id'] ?>;
let stepModal = null;

document.addEventListener('DOMContentLoaded', function() {
    stepModal = new bootstrap.Modal(document.getElementById('stepModal'));

    // Initialize Bootstrap popovers
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => {
        new bootstrap.Popover(el);
    });

    // Input source change handler
    document.getElementById('inputSource').addEventListener('change', function() {
        document.getElementById('getfromConfig').style.display = this.value === 'getfrom' ? 'block' : 'none';
    });
});

// Select step type (helper for clicking the option div)
function selectStepType(type) {
    const radio = document.getElementById('type_' + type);
    if (radio) {
        radio.checked = true;
        radio.dispatchEvent(new Event('change'));
    }
}

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

    // Highlight selected option
    document.querySelectorAll('.step-type-option').forEach(opt => {
        opt.classList.remove('selected');
    });
    const selectedOption = document.querySelector('.step-type-option:has(#type_' + type + ')');
    if (selectedOption) {
        selectedOption.classList.add('selected');
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

// Shell Command test
async function testShellCommand() {
    const command = document.querySelector('[name="config_command"]').value.trim();
    const executor = document.querySelector('[name="config_executor"]').value.trim() || '/bin/bash -c';
    const workingDir = document.querySelector('[name="config_working_dir"]').value.trim() || '/tmp';

    if (!command) {
        alert('Please enter a command to test');
        return;
    }

    const btn = document.getElementById('testShellCommandBtn');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Running...';
    btn.disabled = true;

    try {
        const response = await fetch('/pipelines/testcommand', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({ command, executor, working_dir: workingDir })
        });

        const result = await response.json();
        const resultDiv = document.getElementById('shellCommandResult');
        const resultContent = document.getElementById('shellCommandResultContent');

        if (result.success) {
            resultContent.textContent = result.output || '(no output)';
            resultContent.className = '';
        } else {
            resultContent.textContent = 'Error: ' + (result.error || result.message || 'Command failed') +
                (result.output ? '\n\nOutput:\n' + result.output : '');
            resultContent.className = 'text-danger';
        }

        resultDiv.style.display = 'block';
    } catch (err) {
        alert('Error: ' + err.message);
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

// Mailgun helpers
function onMailgunContentTypeChange(select) {
    // Could show/hide formatting hints based on content type
}

const mailgunTemplates = {
    pipeline_success: {
        subject: 'Pipeline Success: {context.pipeline_name}',
        body: `# Pipeline Completed Successfully

**Pipeline:** {context.pipeline_name}
**Run ID:** {context.run_id}
**Completed:** {context.completed_at}

## Summary
The pipeline has completed all steps successfully.

---
*Sent by MyCTOBot Pipelines*`
    },
    pipeline_failure: {
        subject: 'Pipeline Failed: {context.pipeline_name}',
        body: `# Pipeline Failed

**Pipeline:** {context.pipeline_name}
**Run ID:** {context.run_id}
**Failed At:** {context.failed_at}

## Error Details
**Step:** {context.failed_step}
**Error:** {context.error_message}

---
*Sent by MyCTOBot Pipelines*`
    },
    step_output: {
        subject: 'Step Output: {prev.step_name}',
        body: `# Step Output Report

**Step:** {prev.step_name}
**Status:** {prev.status}

## Output
\`\`\`
{prev.output}
\`\`\`

---
*Sent by MyCTOBot Pipelines*`
    },
    simple_notification: {
        subject: '{context.subject}',
        body: `{context.message}`
    }
};

function applyMailgunTemplate(templateName) {
    if (!templateName) return;

    const template = mailgunTemplates[templateName];
    if (!template) return;

    document.querySelector('[name="config_mailgun_subject"]').value = template.subject;
    document.querySelector('[name="config_mailgun_body"]').value = template.body;
}

function sanitizeStepName(input) {
    // Replace spaces with underscores, remove invalid chars, lowercase
    // Must match backend: ^[a-z][a-z0-9_]*$
    let sanitized = input
        .toLowerCase()
        .replace(/\s+/g, '_')           // spaces to underscores
        .replace(/[^a-z0-9_]/g, '');    // remove invalid chars

    // If starts with number or underscore, prefix with 'step_'
    if (sanitized && !/^[a-z]/.test(sanitized)) {
        sanitized = 'step_' + sanitized;
    }
    return sanitized;
}

function updateStepNameHint() {
    const input = document.getElementById('stepName');
    const cursorPos = input.selectionStart;
    const originalLen = input.value.length;

    // Sanitize the value
    input.value = sanitizeStepName(input.value);

    // Restore cursor position (adjusted for any removed chars)
    const newLen = input.value.length;
    const newPos = Math.max(0, cursorPos - (originalLen - newLen));
    input.setSelectionRange(newPos, newPos);

    // Update hint
    const hint = document.getElementById('stepNameHint');
    hint.textContent = input.value || 'step_name';
}

// =========================================================================
// Variable Browser
// =========================================================================

let lastFocusedField = null;
let availableVariables = null;

// Track the last focused text input/textarea in the modal
document.addEventListener('DOMContentLoaded', function() {
    const stepModalEl = document.getElementById('stepModal');
    if (stepModalEl) {
        stepModalEl.addEventListener('focusin', function(e) {
            if (e.target.tagName === 'INPUT' && e.target.type === 'text' ||
                e.target.tagName === 'TEXTAREA') {
                lastFocusedField = e.target;
            }
        });
    }
});

async function loadStepVariables(row, col, stepId = 0) {
    const loading = document.getElementById('variablesLoading');
    const empty = document.getElementById('variablesEmpty');
    const builtinSection = document.getElementById('builtinVarsSection');
    const contextSection = document.getElementById('contextVarsSection');
    const stepSection = document.getElementById('stepVarsSection');
    const countBadge = document.getElementById('variableCount');

    // Reset
    loading.style.display = 'block';
    empty.style.display = 'none';
    builtinSection.style.display = 'none';
    contextSection.style.display = 'none';
    stepSection.style.display = 'none';
    countBadge.textContent = '0';

    try {
        const response = await fetch(`/pipelines/getstepvariables/${pipelineId}?row=${row}&col=${col}&step_id=${stepId}`);
        const result = await response.json();

        loading.style.display = 'none';

        if (!result.success) {
            empty.textContent = 'Error loading variables';
            empty.style.display = 'block';
            return;
        }

        availableVariables = result.data.variables;
        let totalCount = 0;

        // Render built-in variables
        if (availableVariables.builtins && availableVariables.builtins.length > 0) {
            const list = document.getElementById('builtinVarsList');
            list.innerHTML = '';
            availableVariables.builtins.forEach(v => {
                list.appendChild(createVariableChip('context.' + v.name, v.description, 'builtin'));
                totalCount++;
            });
            builtinSection.style.display = 'block';
        }

        // Render pipeline context variables
        if (availableVariables.context && availableVariables.context.length > 0) {
            const list = document.getElementById('contextVarsList');
            list.innerHTML = '';
            availableVariables.context.forEach(v => {
                const label = v.required ? v.name + '*' : v.name;
                list.appendChild(createVariableChip('context.' + v.name, v.description, 'context'));
                totalCount++;
            });
            contextSection.style.display = 'block';
        }

        // Render previous step variables
        if (availableVariables.previous_steps && availableVariables.previous_steps.length > 0) {
            const container = document.getElementById('stepVarsList');
            container.innerHTML = '';

            availableVariables.previous_steps.forEach(step => {
                const group = document.createElement('div');
                group.className = 'step-vars-group';

                const nameEl = document.createElement('div');
                nameEl.className = 'step-name';
                nameEl.innerHTML = `<i class="bi bi-arrow-right-short"></i> ${step.label} <code class="small">${step.step_name}</code>`;
                group.appendChild(nameEl);

                const varsEl = document.createElement('div');
                varsEl.className = 'step-vars';

                step.variables.forEach(v => {
                    const chipType = v.type === 'mapped' ? 'mapped' : 'step';
                    varsEl.appendChild(createVariableChip(v.path, v.description, chipType));
                    totalCount++;
                });

                group.appendChild(varsEl);
                container.appendChild(group);
            });
            stepSection.style.display = 'block';
        }

        countBadge.textContent = totalCount;

        // Show empty state if no variables
        if (totalCount === 0) {
            empty.style.display = 'block';
        }

    } catch (err) {
        loading.style.display = 'none';
        empty.textContent = 'Failed to load variables';
        empty.style.display = 'block';
        console.error('Error loading variables:', err);
    }
}

function createVariableChip(path, description, type) {
    const chip = document.createElement('span');
    chip.className = 'variable-chip' + (type === 'mapped' ? ' mapped' : '');
    chip.title = description || path;

    // Icon based on type
    let icon = 'bi-braces';
    if (type === 'builtin') icon = 'bi-gear';
    else if (type === 'context') icon = 'bi-box-arrow-in-right';
    else if (type === 'mapped') icon = 'bi-link-45deg';
    else if (type === 'step') icon = 'bi-arrow-right';

    chip.innerHTML = `<i class="bi ${icon}"></i>{${path}}`;

    // Left click - insert into field
    chip.addEventListener('click', function(e) {
        e.preventDefault();
        insertVariable(path);
    });

    // Right click - copy to clipboard
    chip.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        copyVariable(path, chip);
    });

    return chip;
}

function insertVariable(path) {
    const varText = '{' + path + '}';

    if (lastFocusedField) {
        const field = lastFocusedField;
        const start = field.selectionStart;
        const end = field.selectionEnd;
        const text = field.value;

        // Insert at cursor position
        field.value = text.substring(0, start) + varText + text.substring(end);

        // Move cursor after inserted text
        const newPos = start + varText.length;
        field.setSelectionRange(newPos, newPos);
        field.focus();

        // Trigger input event for any listeners
        field.dispatchEvent(new Event('input', { bubbles: true }));

        showToast('Inserted: ' + varText, 'success');
    } else {
        // No field focused - copy to clipboard instead
        copyVariable(path, null);
    }
}

function copyVariable(path, chipElement) {
    const varText = '{' + path + '}';

    navigator.clipboard.writeText(varText).then(() => {
        showToast('Copied: ' + varText, 'info');

        // Visual feedback on chip
        if (chipElement) {
            const originalBg = chipElement.style.background;
            chipElement.style.background = '#d1e7dd';
            setTimeout(() => {
                chipElement.style.background = originalBg;
            }, 300);
        }
    }).catch(err => {
        console.error('Failed to copy:', err);
        // Fallback - show in prompt
        prompt('Copy this variable:', varText);
    });
}

function showToast(message, type = 'info') {
    // Create a simple toast notification
    const toast = document.createElement('div');
    toast.className = `alert alert-${type === 'success' ? 'success' : 'info'} position-fixed`;
    toast.style.cssText = 'bottom: 20px; right: 20px; z-index: 9999; padding: 8px 16px; font-size: 0.875rem; animation: fadeIn 0.2s;';
    toast.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle' : 'clipboard'}"></i> ${message}`;

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s';
        setTimeout(() => toast.remove(), 300);
    }, 1500);
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
    // Reset last focused field
    lastFocusedField = null;
    // Load available variables for this position
    loadStepVariables(row, col, 0);
    stepModal.show();
}

function editStep(stepId, row, col) {
    document.getElementById('stepModalTitleText').textContent = 'Edit Step';
    document.getElementById('stepId').value = stepId;
    document.getElementById('stepRow').value = row;
    document.getElementById('stepCol').value = col;
    document.getElementById('deleteStepBtn').style.display = 'block';
    // Reset last focused field
    lastFocusedField = null;
    // Load available variables for this position
    loadStepVariables(row, col, stepId);

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
        case 'shopify_graphql':
            document.querySelector('[name="config_shopify_connection_id"]').value = config.connection_id || '';
            document.querySelector('[name="config_shopify_query"]').value = config.query || '';
            document.querySelector('[name="config_shopify_variables"]').value = config.variables ? JSON.stringify(config.variables, null, 2) : '';
            break;
        case 'mailgun':
            document.querySelector('[name="config_mailgun_to"]').value = config.to || '';
            document.querySelector('[name="config_mailgun_cc"]').value = config.cc || '';
            document.querySelector('[name="config_mailgun_subject"]').value = config.subject || '';
            document.querySelector('[name="config_mailgun_content_type"]').value = config.content_type || 'markdown';
            document.querySelector('[name="config_mailgun_body"]').value = config.body || '';
            document.querySelector('[name="config_mailgun_attachments"]').value = config.attachments || '';
            break;
        case 'file_write':
            document.querySelector('[name="config_file_filename"]').value = config.filename || '';
            document.querySelector('[name="config_file_source"]').value = config.source || 'template';
            document.querySelector('[name="config_file_content"]').value = config.content || '';
            document.querySelector('[name="config_file_source_step"]').value = config.source_step || '';
            document.querySelector('[name="config_file_source_field"]').value = config.source_field || 'stdout';
            document.querySelector('[name="config_file_base64_var"]').value = config.base64_var || '';
            document.querySelector('[name="config_file_content_type"]').value = config.content_type || '';
            document.querySelector('[name="config_file_append"]').checked = config.append || false;
            // Toggle visibility based on source
            const srcSelect = document.querySelector('[name="config_file_source"]');
            if (srcSelect && typeof toggleFileContentSource === 'function') {
                toggleFileContentSource(srcSelect);
            }
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
        case 'shopify_graphql':
            let shopifyVars = {};
            try {
                shopifyVars = JSON.parse(document.querySelector('[name="config_shopify_variables"]').value || '{}');
            } catch (e) {}
            config = {
                connection_id: document.querySelector('[name="config_shopify_connection_id"]').value,
                query: document.querySelector('[name="config_shopify_query"]').value,
                variables: shopifyVars
            };
            break;
        case 'mailgun':
            config = {
                to: document.querySelector('[name="config_mailgun_to"]').value,
                cc: document.querySelector('[name="config_mailgun_cc"]').value,
                subject: document.querySelector('[name="config_mailgun_subject"]').value,
                content_type: document.querySelector('[name="config_mailgun_content_type"]').value,
                body: document.querySelector('[name="config_mailgun_body"]').value,
                attachments: document.querySelector('[name="config_mailgun_attachments"]').value
            };
            break;
        case 'file_write':
            config = {
                filename: document.querySelector('[name="config_file_filename"]').value,
                source: document.querySelector('[name="config_file_source"]').value,
                content: document.querySelector('[name="config_file_content"]').value,
                source_step: document.querySelector('[name="config_file_source_step"]').value,
                source_field: document.querySelector('[name="config_file_source_field"]').value,
                base64_var: document.querySelector('[name="config_file_base64_var"]').value,
                content_type: document.querySelector('[name="config_file_content_type"]').value,
                append: document.querySelector('[name="config_file_append"]').checked
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
            alert('Error: ' + (result.message || result.error || 'Failed to save step'));
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
    const tbody = document.querySelector('#pipelineGrid tbody');
    const rows = tbody.querySelectorAll('tr[data-row]');
    const newRowNum = rows.length; // 0-indexed, so length is the next row

    // Count columns from header
    const columnCount = document.querySelectorAll('.column-header').length;

    // Build new row HTML
    let rowHtml = `<tr data-row="${newRowNum}">`;
    rowHtml += `<td class="text-center text-muted align-middle">${newRowNum + 1}</td>`;

    for (let col = 0; col < columnCount; col++) {
        rowHtml += `
            <td class="p-2 drop-zone" data-row="${newRowNum}" data-col="${col}"
                ondragover="handleDragOver(event)" ondrop="handleDrop(event, ${newRowNum}, ${col})"
                ondragleave="handleDragLeave(event)">
                <div class="step-cell-empty border border-dashed rounded p-3 text-center text-muted"
                     onclick="addStep(${newRowNum}, ${col})"
                     style="cursor: pointer; min-height: 80px;">
                    <i class="bi bi-plus-lg"></i>
                    <div class="small">Add Step</div>
                </div>
            </td>`;
    }

    // Add row controls
    rowHtml += `
        <td class="text-center align-middle">
            <div class="btn-group-vertical btn-group-sm">
                <button class="btn btn-outline-warning" onclick="toggleRow(${newRowNum}, false)" title="Disable Row">
                    <i class="bi bi-pause-circle"></i>
                </button>
                <button class="btn btn-outline-danger" onclick="deleteRow(${newRowNum})" title="Delete Row">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </td>`;
    rowHtml += '</tr>';

    tbody.insertAdjacentHTML('beforeend', rowHtml);
}

// Add a new column to the grid
async function addColumn() {
    const newName = prompt('Enter new column name:', 'New Stage');
    if (!newName || !newName.trim()) return;

    // Get current columns
    const headers = document.querySelectorAll('.column-header .column-name');
    const columns = Array.from(headers).map(h => h.textContent.trim());
    columns.push(newName.trim());

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
            location.reload();
        } else {
            alert('Failed to add column');
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
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

    let minCol = Infinity, maxCol = -1;

    steps.forEach(step => {
        const td = step.closest('td');
        const col = parseInt(td.dataset.col);
        minCol = Math.min(minCol, col);
        maxCol = Math.max(maxCol, col);
    });

    // Get current columns
    const headers = document.querySelectorAll('.column-header .column-name');
    const allColumns = Array.from(headers).map(h => h.textContent.trim());

    // Calculate what to trim
    // startCol = first used column (trim everything before)
    // endCol = last used column + 1 buffer (trim everything after)
    const startCol = minCol;
    const endCol = Math.min(allColumns.length - 1, maxCol + 1);
    const trimmedColumns = allColumns.slice(startCol, endCol + 1);

    if (trimmedColumns.length === allColumns.length && startCol === 0) {
        alert('Grid is already optimized - no unused columns to trim.');
        return;
    }

    // Build info message
    let message = `Trim grid to ${trimmedColumns.length} columns (${trimmedColumns.join(', ')})?`;
    if (startCol > 0) {
        message += `\n\nThis will remove ${startCol} column(s) from the beginning and shift all steps left.`;
    }
    const trailingRemoved = allColumns.length - (endCol + 1);
    if (trailingRemoved > 0) {
        message += `\n\nThis will remove ${trailingRemoved} trailing column(s).`;
    }

    if (!confirm(message)) {
        return;
    }

    try {
        // If trimming from the left, we need to update step positions first
        if (startCol > 0) {
            // Move each step's column position
            for (const step of steps) {
                const td = step.closest('td');
                const stepId = step.dataset.stepId;
                const oldCol = parseInt(td.dataset.col);
                const newCol = oldCol - startCol;
                const row = parseInt(td.closest('tr').dataset.row);

                const response = await fetch('/pipelines/movestep/' + pipelineId, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        csrf_token: csrfToken,
                        step_id: stepId,
                        target_row: row,
                        target_col: newCol
                    })
                });

                if (!response.ok) {
                    alert('Failed to relocate step ' + stepId);
                    return;
                }
            }
        }

        // Now save the trimmed columns
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

async function deleteRow(row) {
    // Find all steps in this row
    const rowElement = document.querySelector(`tr[data-row="${row}"]`);
    if (!rowElement) return;

    const stepCells = rowElement.querySelectorAll('[data-step-id]');
    const stepCount = stepCells.length;

    if (stepCount === 0) {
        // No steps in row - just remove the visual row (it will come back on reload if needed)
        rowElement.remove();
        return;
    }

    if (!confirm(`Delete all ${stepCount} step(s) in row ${row + 1}?`)) {
        return;
    }

    // Delete each step
    for (const cell of stepCells) {
        const stepId = cell.dataset.stepId;
        try {
            await fetch('/pipelines/deletestep/' + pipelineId, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    step_id: stepId
                })
            });
        } catch (err) {
            console.error('Failed to delete step', stepId, err);
        }
    }

    location.reload();
}

// =========================================================================
// Drag and Drop
// =========================================================================

let draggedStepId = null;
let draggedFromRow = null;
let draggedFromCol = null;

function handleDragStart(event, stepId, row, col) {
    draggedStepId = stepId;
    draggedFromRow = row;
    draggedFromCol = col;
    event.target.classList.add('dragging');
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', stepId);
}

function handleDragEnd(event) {
    event.target.classList.remove('dragging');
    draggedStepId = null;
    draggedFromRow = null;
    draggedFromCol = null;
    // Remove all drag-over highlights
    document.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
}

function handleDragOver(event) {
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    event.currentTarget.classList.add('drag-over');
}

function handleDragLeave(event) {
    event.currentTarget.classList.remove('drag-over');
}

async function handleDrop(event, targetRow, targetCol) {
    event.preventDefault();
    event.currentTarget.classList.remove('drag-over');

    if (!draggedStepId) return;

    // Don't do anything if dropped on same cell
    if (targetRow === draggedFromRow && targetCol === draggedFromCol) return;

    // Check if target cell already has a step
    const targetCell = event.currentTarget;
    const existingStep = targetCell.querySelector('.step-cell[data-step-id]');
    if (existingStep) {
        if (!confirm('This cell already has a step. Swap positions?')) {
            return;
        }
    }

    try {
        const response = await fetch('/pipelines/movestep/' + pipelineId, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: csrfToken,
                step_id: draggedStepId,
                target_row: targetRow,
                target_col: targetCol
            })
        });

        const result = await response.json();
        if (result.success) {
            location.reload();
        } else {
            alert('Failed to move step: ' + (result.message || 'Unknown error'));
        }
    } catch (err) {
        alert('Error moving step: ' + err.message);
    }
}

// =========================================================================
// Row Toggle (Enable/Disable)
// =========================================================================

async function toggleRow(row, enable) {
    const action = enable ? 'enable' : 'disable';
    if (!confirm(`${enable ? 'Enable' : 'Disable'} all steps in row ${row + 1}?`)) {
        return;
    }

    try {
        const response = await fetch('/pipelines/togglerow/' + pipelineId, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: csrfToken,
                row: row,
                enable: enable ? '1' : '0'
            })
        });

        const result = await response.json();
        if (result.success) {
            location.reload();
        } else {
            alert('Failed to ' + action + ' row: ' + (result.message || 'Unknown error'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
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
    // Reset modal to normal state
    document.getElementById('triggerModalLabel').innerHTML = '<i class="bi bi-play-fill"></i> Run Pipeline';
    document.getElementById('triggerModalSubmit').onclick = confirmTrigger;
    document.getElementById('triggerModalSubmit').innerHTML = '<i class="bi bi-play-fill"></i> Run';
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

function triggerInteractive() {
    // Show same modal but with interactive label
    document.getElementById('triggerContext').value = '';
    document.getElementById('triggerModalLabel').innerHTML = '<i class="bi bi-bug"></i> Interactive/Debug Run';
    document.getElementById('triggerModalSubmit').onclick = confirmTriggerInteractive;
    document.getElementById('triggerModalSubmit').innerHTML = '<i class="bi bi-play-fill"></i> Start Interactive Run';
    new bootstrap.Modal(document.getElementById('triggerModal')).show();
}

async function confirmTriggerInteractive() {
    const context = document.getElementById('triggerContext').value || '{}';

    try {
        const response = await fetch('/pipelines/triggerinteractive/' + pipelineId, {
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
            alert('Error: ' + (result.error || 'Failed to start interactive run'));
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

// Test Shopify GraphQL query
async function testShopifyQuery() {
    const connectionId = document.querySelector('[name="config_shopify_connection_id"]').value;
    const query = document.querySelector('[name="config_shopify_query"]').value;
    const variablesRaw = document.querySelector('[name="config_shopify_variables"]').value;

    if (!connectionId) {
        alert('Please select a Shopify connection first');
        return;
    }

    if (!query.trim()) {
        alert('Please enter a GraphQL query');
        return;
    }

    let variables = {};
    if (variablesRaw.trim()) {
        try {
            variables = JSON.parse(variablesRaw);
        } catch (e) {
            alert('Invalid JSON in variables field: ' + e.message);
            return;
        }
    }

    const btn = document.getElementById('testShopifyQueryBtn');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';

    const resultDiv = document.getElementById('shopifyQueryResult');
    const resultContent = document.getElementById('shopifyQueryResultContent');

    try {
        const response = await fetch('/shopify/testquery', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                csrf_token: csrfToken,
                connection_id: connectionId,
                query: query,
                variables: variables
            })
        });

        const result = await response.json();

        if (result.success) {
            resultContent.textContent = JSON.stringify(result.data, null, 2);
            resultContent.className = '';
        } else {
            resultContent.textContent = 'Error: ' + (result.error || 'Unknown error') +
                (result.errors ? '\n\nGraphQL Errors:\n' + JSON.stringify(result.errors, null, 2) : '');
            resultContent.className = 'text-danger';
        }

        resultDiv.style.display = 'block';
    } catch (err) {
        resultContent.textContent = 'Request failed: ' + err.message;
        resultContent.className = 'text-danger';
        resultDiv.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

// Shopify GraphQL Query Templates
const shopifyQueryTemplates = {
    shop_info: {
        query: `{
  shop {
    name
    email
    myshopifyDomain
    plan {
      displayName
    }
    currencyCode
    timezoneAbbreviation
  }
}`,
        variables: {}
    },
    list_products: {
        query: `query getProducts($first: Int!, $query: String) {
  products(first: $first, query: $query) {
    edges {
      node {
        id
        title
        handle
        status
        totalInventory
        priceRangeV2 {
          minVariantPrice {
            amount
            currencyCode
          }
        }
        featuredImage {
          url
        }
      }
    }
    pageInfo {
      hasNextPage
      endCursor
    }
  }
}`,
        variables: { first: 10, query: null }
    },
    get_product: {
        query: `query getProductByHandle($handle: String!) {
  productByHandle(handle: $handle) {
    id
    title
    description
    handle
    status
    totalInventory
    variants(first: 10) {
      edges {
        node {
          id
          title
          sku
          price
          inventoryQuantity
        }
      }
    }
    metafields(first: 10) {
      edges {
        node {
          namespace
          key
          value
          type
        }
      }
    }
  }
}`,
        variables: { handle: "example-product" }
    },
    list_orders: {
        query: `query getOrders($first: Int!, $query: String) {
  orders(first: $first, query: $query, sortKey: CREATED_AT, reverse: true) {
    edges {
      node {
        id
        name
        createdAt
        displayFinancialStatus
        displayFulfillmentStatus
        totalPriceSet {
          shopMoney {
            amount
            currencyCode
          }
        }
        customer {
          displayName
          email
        }
        lineItems(first: 5) {
          edges {
            node {
              title
              quantity
            }
          }
        }
      }
    }
    pageInfo {
      hasNextPage
      endCursor
    }
  }
}`,
        variables: { first: 10, query: null }
    },
    get_customer: {
        query: `query getCustomer($id: ID!) {
  customer(id: $id) {
    id
    displayName
    email
    phone
    createdAt
    numberOfOrders
    amountSpent {
      amount
      currencyCode
    }
    defaultAddress {
      address1
      city
      province
      country
      zip
    }
    orders(first: 5, sortKey: CREATED_AT, reverse: true) {
      edges {
        node {
          id
          name
          createdAt
          totalPriceSet {
            shopMoney {
              amount
            }
          }
        }
      }
    }
    metafields(first: 10) {
      edges {
        node {
          namespace
          key
          value
        }
      }
    }
  }
}`,
        variables: { id: "gid://shopify/Customer/123456789" }
    }
};

function applyShopifyQueryTemplate(templateName) {
    if (!templateName) return;

    const template = shopifyQueryTemplates[templateName];
    if (!template) return;

    document.querySelector('[name="config_shopify_query"]').value = template.query.trim();
    document.querySelector('[name="config_shopify_variables"]').value =
        Object.keys(template.variables).length > 0
            ? JSON.stringify(template.variables, null, 2)
            : '';

    // Reset the template selector after applying (so user knows it's now custom)
    // Uncomment if you want this behavior:
    // document.getElementById('shopifyQueryTemplate').value = '';
}
</script>
<?php endif; ?>
