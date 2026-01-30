<div class="container py-4">
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
                                        <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="deriveSchemaFromSteps()">
                                            <i class="bi bi-magic"></i> Derive from Steps
                                        </button>
                                        <button type="button" class="btn btn-sm btn-link p-0 ms-2" onclick="insertSampleSchema()">
                                            <i class="bi bi-lightning"></i> Insert sample
                                        </button>
                                    </label>
                                    <textarea class="form-control font-monospace" id="inputSchemaJson" name="input_schema_json" rows="6"
                                              placeholder='{"type": "object", "properties": {...}}'><?= htmlspecialchars($pipeline['input_schema_json']) ?></textarea>
                                    <small class="text-muted">
                                        JSON Schema defining the tool's input parameters. Properties become available in pipeline context.
                                        <a href="/docs/pipelines#input-schema" target="_blank" class="ms-1"><i class="bi bi-question-circle"></i> Help</a>
                                    </small>
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

    <!-- Debugger Toolbar (shown during interactive/debug runs) -->
    <div id="runStatusBar" class="debugger-toolbar mb-4" style="display: none !important;">
        <div class="debugger-toolbar-inner">
            <!-- Left: Status and Current Step -->
            <div class="debugger-status">
                <span class="spinner-border spinner-border-sm me-2" id="runStatusSpinner"></span>
                <span class="debugger-status-badge" id="runStatusBadge">
                    <i class="bi bi-play-fill"></i>
                    <span id="runStatusLabel">Running</span>
                </span>
                <span class="debugger-step-info ms-3" id="currentStepInfo">
                    <span class="text-muted">Step:</span>
                    <strong id="currentStepName">-</strong>
                    <span class="text-muted ms-2">Position:</span>
                    <code id="currentStepPosition">-</code>
                </span>
            </div>

            <!-- Center: Progress -->
            <div class="debugger-progress">
                <span class="debugger-progress-text" id="runStatusDetail">0/0 steps</span>
                <div class="progress" style="width: 120px; height: 6px;">
                    <div class="progress-bar" id="runStatusProgress" role="progressbar" style="width: 0%"></div>
                </div>
            </div>

            <!-- Right: Controls -->
            <div class="debugger-controls">
                <button class="btn btn-sm btn-outline-info" onclick="toggleAllOutputRows()" id="toggleOutputBtn" style="display: none;" title="Toggle step outputs">
                    <i class="bi bi-terminal"></i>
                </button>

                <!-- Step Back Button - move playhead backwards -->
                <button class="btn btn-sm btn-outline-secondary" onclick="stepBack()" id="stepBackBtn" style="display: none;" title="Go back to previous step">
                    <i class="bi bi-skip-backward-fill"></i>
                    <span id="prevStepName" class="prev-step-label"></span>
                </button>

                <!-- Next Step Button - shows what step will execute -->
                <button class="btn btn-sm btn-primary debugger-next-btn" onclick="executeNextStep()" id="nextStepBtn" style="display: none;" title="Execute next step">
                    <i class="bi bi-skip-forward-fill"></i>
                    <span>Next:</span>
                    <span id="nextStepName" class="next-step-label">-</span>
                </button>

                <!-- Continue Button (run all remaining) -->
                <button class="btn btn-sm btn-success" onclick="continueInteractiveRun()" id="continueRunBtn" style="display: none;" title="Run all remaining steps">
                    <i class="bi bi-play-fill"></i> Run All
                </button>

                <!-- Restart Button -->
                <button class="btn btn-sm btn-outline-warning" onclick="restartInteractiveRun()" id="restartRunBtn" style="display: none;" title="Restart from beginning">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </button>

                <!-- Stop/Cancel Button -->
                <button class="btn btn-sm btn-outline-danger" onclick="cancelInteractiveRun()" id="cancelRunBtn" title="Stop execution">
                    <i class="bi bi-stop-fill"></i>
                </button>
            </div>
        </div>

        <!-- Keyboard shortcuts hint -->
        <div class="debugger-shortcuts" id="debuggerShortcuts">
            <kbd>Space</kbd> Next Step
            <kbd>Ctrl+Enter</kbd> Run All
            <kbd>Esc</kbd> Stop
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
                    <thead class="table-dark">
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
                            // Check if any step in this row has run_parallel enabled
                            $rowParallel = array_reduce($rowSteps, fn($carry, $s) => $carry || ($s['run_parallel'] ?? false), false);
                        ?>
                        <tr data-row="<?= $row ?>" class="<?= !$rowActive ? 'row-disabled' : '' ?><?= $rowParallel ? ' row-parallel' : '' ?>" data-parallel="<?= $rowParallel ? '1' : '0' ?>">
                            <td class="text-center text-muted align-middle row-number-cell">
                                <div class="d-flex flex-column align-items-center">
                                    <span class="row-num"><?= $row + 1 ?></span>
                                    <button type="button"
                                            class="btn btn-sm p-0 row-parallel-toggle <?= $rowParallel ? 'active' : '' ?>"
                                            onclick="toggleRowParallel(<?= $row ?>, this)"
                                            title="<?= $rowParallel ? 'Parallel ON: This row runs concurrently with other parallel rows. Click to disable.' : 'Click to enable parallel execution for this row' ?>">
                                        <i class="bi <?= $rowParallel ? 'bi-lightning-fill text-warning' : 'bi-lightning text-muted' ?>" style="font-size: 0.75rem;"></i>
                                    </button>
                                </div>
                            </td>
                            <?php foreach ($pipeline['columns'] as $colIndex => $colName): ?>
                            <td class="p-2 drop-zone" data-row="<?= $row ?>" data-col="<?= $colIndex ?>"
                                ondragover="handleDragOver(event)" ondrop="handleDrop(event, <?= $row ?>, <?= $colIndex ?>)"
                                ondragleave="handleDragLeave(event)">
                                <?php if (isset($stepGrid[$row][$colIndex])): ?>
                                    <?php $step = $stepGrid[$row][$colIndex]; ?>
                                    <div class="step-cell bg-<?= $step['type_info']['color'] ?? 'secondary' ?>-subtle border border-<?= $step['type_info']['color'] ?? 'secondary' ?> rounded p-2 <?= !$step['is_active'] ? 'opacity-50' : '' ?>"
                                         data-step-id="<?= $step['id'] ?>"
                                         data-step-name="<?= htmlspecialchars($step['step_name']) ?>"
                                         data-step-type="<?= htmlspecialchars($step['step_type']) ?>"
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
                                        <!-- Output section (hidden by default, shown after step completes) -->
                                        <div class="step-output-section" style="display: none;">
                                            <div class="step-output-header d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                                                <small class="text-muted"><i class="bi bi-terminal"></i> Output</small>
                                                <button type="button" class="btn btn-sm btn-link p-0 step-output-toggle" onclick="event.stopPropagation(); toggleStepOutput(this);">
                                                    <i class="bi bi-chevron-down"></i>
                                                </button>
                                            </div>
                                            <div class="step-output-content mt-1">
                                                <pre class="step-output-pre mb-0"></pre>
                                            </div>
                                        </div>
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
                                    <button class="btn btn-outline-info row-output-btn" data-row="<?= $row ?>"
                                            onclick="toggleRowOutput(<?= $row ?>); event.stopPropagation();"
                                            title="Toggle Output" style="display: none;">
                                        <i class="bi bi-terminal"></i>
                                    </button>
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
    <div class="modal-dialog modal-xl modal-fullscreen-lg-down">
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

                    <!-- Universal Input Preview (shown during debug mode) -->
                    <div class="card border-info mb-3" id="universalInputPreview" style="display: none;">
                        <div class="card-header bg-info-subtle py-2 d-flex justify-content-between align-items-center"
                             data-bs-toggle="collapse" data-bs-target="#inputPreviewPanel"
                             style="cursor: pointer;" role="button">
                            <span>
                                <i class="bi bi-box-arrow-in-down text-info"></i> Input Preview
                                <span class="badge bg-info ms-1" id="inputPreviewBadge">STDIN</span>
                            </span>
                            <div>
                                <button type="button" class="btn btn-sm btn-outline-info me-1" onclick="event.stopPropagation(); refreshInputPreview();">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                                <i class="bi bi-chevron-down" id="inputPreviewChevron"></i>
                            </div>
                        </div>
                        <div class="collapse show" id="inputPreviewPanel">
                            <div class="card-body p-0">
                                <div class="p-2 bg-light border-bottom small" id="inputPreviewSource">
                                    <i class="bi bi-info-circle me-1"></i>
                                    <span id="inputPreviewSourceText">Loading...</span>
                                </div>
                                <div id="inputPreviewData" style="max-height: 250px; overflow: auto;">
                                    <pre class="mb-0 p-3 bg-dark text-light small" id="inputPreviewContent" style="white-space: pre-wrap; word-break: break-word;">(Start an Interactive Debug run to see input data)</pre>
                                </div>
                            </div>
                        </div>
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

                    <!-- Variable Exporter (shown when step has output from interactive run) -->
                    <div class="card bg-light mb-3" id="variableExporterCard" style="display: none;">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center"
                             data-bs-toggle="collapse" data-bs-target="#variableExporterPanel"
                             style="cursor: pointer;" role="button">
                            <span>
                                <i class="bi bi-box-arrow-right text-success"></i> Variable Exporter
                                <span class="badge bg-success ms-1" id="exportCount">0</span>
                            </span>
                            <i class="bi bi-chevron-down" id="variableExporterChevron"></i>
                        </div>
                        <div class="collapse show" id="variableExporterPanel">
                            <div class="card-body">
                                <p class="small text-muted mb-2">
                                    This step has output from the current run. Click paths to export them for subsequent steps:
                                </p>

                                <!-- Output Preview -->
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Output Preview:</label>
                                    <pre class="bg-dark text-light p-2 rounded" id="exporterOutputPreview" style="max-height: 150px; overflow: auto; font-size: 0.75rem;"></pre>
                                </div>

                                <!-- Available Paths -->
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Available Paths:</label>
                                    <div class="export-paths-list" id="modalExportPathsList"></div>
                                </div>

                                <!-- Exported Variables -->
                                <div id="modalExportedVars" style="display: none;">
                                    <label class="form-label small fw-bold text-success">
                                        <i class="bi bi-check-circle-fill"></i> Exported:
                                    </label>
                                    <div class="d-flex flex-wrap gap-2" id="modalExportedVarsList"></div>
                                </div>

                                <!-- Save Button -->
                                <div class="d-flex justify-content-end mt-3">
                                    <button type="button" class="btn btn-success" id="saveExportsBtn" onclick="saveExportedVariablesFromModal()">
                                        <i class="bi bi-check-lg"></i> Save Exported Variables
                                    </button>
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
                <span class="text-muted small me-3 d-none d-md-inline">
                    <kbd>Ctrl</kbd>+<kbd>S</kbd> to save
                </span>
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
    position: relative;
}
.step-cell:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.step-cell-empty {
    background: var(--bs-secondary-bg);
    transition: all 0.2s;
}
.step-cell-empty:hover {
    background: var(--bs-tertiary-bg);
    border-color: var(--bs-border-color-translucent) !important;
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
    background: var(--bs-primary-bg-subtle) !important;
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
    background: var(--bs-secondary-bg);
}
tr.row-disabled .step-cell {
    opacity: 0.35;
}
tr.row-disabled td:first-child {
    text-decoration: line-through;
}
/* Parallel row indicator */
tr.row-parallel {
    background: linear-gradient(90deg, rgba(255,193,7,0.08) 0%, transparent 40%);
}
tr.row-parallel .row-number-cell {
    position: relative;
}
tr.row-parallel .row-number-cell::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: #ffc107;
}
/* Row parallel toggle button */
.row-parallel-toggle {
    border: none;
    background: transparent;
    opacity: 0.4;
    transition: all 0.2s;
    line-height: 1;
}
.row-parallel-toggle:hover {
    opacity: 1;
    transform: scale(1.2);
}
.row-parallel-toggle.active {
    opacity: 1;
}
.row-number-cell .row-num {
    font-size: 0.85rem;
    line-height: 1.2;
}
/* Step type accordion */
.step-type-option {
    padding: 8px 12px;
    border: 1px solid var(--bs-border-color);
    border-radius: 6px;
    transition: all 0.15s;
    cursor: pointer;
    position: relative;
    background: var(--bs-body-bg);
}
.step-type-option:hover {
    background: var(--bs-tertiary-bg);
    border-color: var(--bs-primary);
}
.step-type-option.selected {
    background: var(--bs-primary-bg-subtle);
    border-color: var(--bs-primary);
}
.step-type-option.selected::after {
    content: '\f26b';
    font-family: 'bootstrap-icons';
    position: absolute;
    top: 8px;
    right: 8px;
    color: var(--bs-primary);
    font-size: 0.9rem;
}
.step-type-option .form-check-input {
    display: none;
}
.cursor-pointer {
    cursor: pointer;
}
#stepTypeAccordion .accordion-button {
    background: var(--bs-secondary-bg);
    color: var(--bs-body-color);
}
#stepTypeAccordion .accordion-button:not(.collapsed) {
    background: var(--bs-primary-bg-subtle);
    color: var(--bs-primary-text-emphasis);
}
/* Variable Browser */
.variable-chip {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    font-size: 0.75rem;
    font-family: monospace;
    background: var(--bs-body-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}
.variable-chip:hover {
    background: var(--bs-primary-bg-subtle);
    border-color: var(--bs-primary);
    color: var(--bs-primary);
}
.variable-chip.mapped {
    background: var(--bs-success-bg-subtle);
    border-color: var(--bs-success);
}
.variable-chip.mapped:hover {
    background: var(--bs-success-bg-subtle);
    filter: brightness(0.9);
}
.variable-chip .bi {
    font-size: 0.65rem;
    margin-right: 3px;
    opacity: 0.6;
}
.step-vars-group {
    margin-bottom: 8px;
    padding: 6px 8px;
    background: var(--bs-body-bg);
    border: 1px solid var(--bs-border-color);
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
/* Full-height modal for step editor */
#stepModal .modal-xl .modal-body {
    max-height: calc(100vh - 200px);
    overflow-y: auto;
}
/* GraphQL editor specific styles */
.graphql-editor-row {
    min-height: 350px;
}
.graphql-query-editor,
.graphql-variables-editor {
    resize: vertical;
    min-height: 280px;
    font-size: 0.875rem;
    line-height: 1.4;
}
.graphql-result-panel {
    max-height: 300px;
    overflow: auto;
    font-size: 0.85rem;
}
/* Make textareas take full height on larger screens */
@media (min-width: 992px) {
    .graphql-editor-row {
        min-height: 400px;
    }
    .graphql-editor-row .col-lg-7,
    .graphql-editor-row .col-lg-5 {
        display: flex;
        flex-direction: column;
    }
    .graphql-query-editor,
    .graphql-variables-editor {
        flex: 1;
        min-height: 350px;
    }
}
/* Interactive run styles */
.step-cell.step-running {
    animation: pulse-border 1.5s infinite;
    border-color: #0d6efd !important;
    border-width: 2px !important;
    position: relative;
}
.step-cell.step-running::after {
    content: '';
    position: absolute;
    top: 4px;
    right: 4px;
    width: 12px;
    height: 12px;
    border: 2px solid #0d6efd;
    border-top-color: transparent;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}
@keyframes pulse-border {
    0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.4); }
    50% { opacity: 0.85; box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.1); }
}
@keyframes spin {
    to { transform: rotate(360deg); }
}
.step-cell.step-success {
    position: relative;
}
.step-cell.step-success::before {
    content: '\f26b';
    font-family: 'bootstrap-icons';
    position: absolute;
    top: -8px;
    right: -8px;
    width: 20px;
    height: 20px;
    background: #198754;
    color: white;
    border-radius: 50%;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
}
.step-cell.step-failed {
    border-color: #dc3545 !important;
    background: rgba(220, 53, 69, 0.1) !important;
    position: relative;
}
.step-cell.step-failed::before {
    content: '\f62a';
    font-family: 'bootstrap-icons';
    position: absolute;
    top: -8px;
    right: -8px;
    width: 20px;
    height: 20px;
    background: #dc3545;
    color: white;
    border-radius: 50%;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
}
/* In-cell output section */
.step-output-section {
    width: 100%;
}
.step-output-header {
    border-top-color: rgba(0,0,0,0.1) !important;
}
.step-output-toggle {
    color: inherit;
    text-decoration: none;
}
.step-output-toggle:hover {
    color: #0d6efd;
}
.step-output-content {
    max-height: 250px;
    overflow: hidden;
    transition: max-height 0.3s ease;
}
.step-output-content.collapsed {
    max-height: 0;
}
.step-output-pre {
    background: #1e1e1e;
    color: #d4d4d4;
    padding: 8px;
    border-radius: 4px;
    font-size: 0.7rem;
    line-height: 1.4;
    max-height: 200px;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-word;
}
.step-output-pre.error {
    color: #f48771;
}
/* Make cells with output larger */
.step-cell.has-output {
    min-height: auto;
}
/* Adjust cell when output is shown */
td.drop-zone {
    vertical-align: top;
}
/* Variable Exporter styles */
.variable-exporter {
    background: var(--bs-secondary-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 4px;
    padding: 12px;
    font-size: 0.8rem;
    margin-top: 8px;
}
.export-paths-list {
    min-height: 200px;
    max-height: 400px;
    overflow-y: auto;
    background: var(--bs-body-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 4px;
    padding: 8px;
}
.export-path-item {
    display: flex;
    align-items: center;
    padding: 8px 12px;
    margin: 4px 0;
    background: var(--bs-secondary-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s;
}
.export-path-item:hover {
    background: var(--bs-primary-bg-subtle);
    border-color: var(--bs-primary);
    transform: translateX(2px);
}
.export-path-item.exported {
    background: var(--bs-success-bg-subtle);
    border-color: var(--bs-success);
}
.export-path-item .path-name {
    flex: 1;
    font-family: monospace;
    font-size: 0.85rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.export-path-item .path-type {
    font-size: 0.7rem;
    color: var(--bs-secondary-color);
    margin-left: 8px;
    padding: 2px 6px;
    background: var(--bs-tertiary-bg);
    border-radius: 3px;
}
.export-path-item .export-check {
    margin-right: 10px;
    color: #198754;
    font-size: 1rem;
}
.exported-var-chip {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    font-size: 0.75rem;
    font-family: monospace;
    background: var(--bs-success-bg-subtle);
    border: 1px solid var(--bs-success);
    border-radius: 4px;
    color: var(--bs-success-text-emphasis);
}
.exported-var-chip .remove-export {
    margin-left: 6px;
    cursor: pointer;
    opacity: 0.6;
    font-size: 0.9rem;
}
.exported-var-chip .remove-export:hover {
    opacity: 1;
    color: var(--bs-danger);
}
.exported-vars {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--bs-border-color);
}
.exported-vars-list {
    margin-top: 8px;
}
/* Debugger Toolbar Styling */
.debugger-toolbar {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    border: 1px solid #0f3460;
    border-radius: 8px;
    padding: 0;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}
.debugger-toolbar-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    gap: 16px;
}
.debugger-status {
    display: flex;
    align-items: center;
    flex: 1;
    min-width: 0;
}
.debugger-status .spinner-border {
    color: #00d9ff;
}
.debugger-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    background: rgba(0, 217, 255, 0.15);
    color: #00d9ff;
    border: 1px solid rgba(0, 217, 255, 0.3);
}
.debugger-status-badge.status-paused {
    background: rgba(255, 193, 7, 0.15);
    color: #ffc107;
    border-color: rgba(255, 193, 7, 0.3);
}
.debugger-status-badge.status-completed {
    background: rgba(25, 135, 84, 0.15);
    color: #20c997;
    border-color: rgba(25, 135, 84, 0.3);
}
.debugger-status-badge.status-failed {
    background: rgba(220, 53, 69, 0.15);
    color: #dc3545;
    border-color: rgba(220, 53, 69, 0.3);
}
.debugger-step-info {
    color: #a0a0a0;
    font-size: 0.85rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.debugger-step-info strong {
    color: #ffffff;
}
.debugger-step-info code {
    background: rgba(255, 255, 255, 0.1);
    padding: 2px 6px;
    border-radius: 3px;
    color: #00d9ff;
    font-size: 0.8rem;
}
.debugger-progress {
    display: flex;
    align-items: center;
    gap: 10px;
}
.debugger-progress-text {
    color: #a0a0a0;
    font-size: 0.8rem;
    white-space: nowrap;
}
.debugger-progress .progress {
    background: rgba(255, 255, 255, 0.1);
}
.debugger-progress .progress-bar {
    background: linear-gradient(90deg, #00d9ff, #00ff88);
}
.debugger-controls {
    display: flex;
    align-items: center;
    gap: 8px;
}
.debugger-next-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    font-weight: 500;
    background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
    border: none;
    animation: pulse-next 2s infinite;
}
.debugger-next-btn:hover {
    background: linear-gradient(135deg, #0a58ca 0%, #084298 100%);
}
.debugger-next-btn .next-step-label {
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-family: monospace;
    font-size: 0.85rem;
}
@keyframes pulse-next {
    0%, 100% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.4); }
    50% { box-shadow: 0 0 0 6px rgba(13, 110, 253, 0); }
}
.debugger-shortcuts {
    display: flex;
    justify-content: center;
    gap: 16px;
    padding: 6px 16px;
    background: rgba(0, 0, 0, 0.2);
    border-top: 1px solid rgba(255, 255, 255, 0.05);
    font-size: 0.75rem;
    color: #6c757d;
}
.debugger-shortcuts kbd {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #a0a0a0;
    margin-right: 4px;
    box-shadow: none;
}
/* Keyboard shortcut styling */
kbd {
    background: var(--bs-tertiary-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 3px;
    box-shadow: 0 1px 0 var(--bs-secondary-color);
    color: var(--bs-body-color);
    display: inline-block;
    font-size: 0.75rem;
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    line-height: 1;
    padding: 2px 5px;
}
/* Flow indicator between adjacent steps */
.step-cell::after {
    display: none;
}
.step-cell:not(.step-running)::before {
    display: none;
}
.has-right-neighbor::after {
    content: '\f285';
    font-family: 'bootstrap-icons';
    position: absolute;
    right: -18px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    font-size: 0.8rem;
    z-index: 5;
}
/* CURRENT state - step just completed, paused here (blue pulsing) */
.step-cell.step-paused {
    border-color: #0d6efd !important;
    border-width: 3px !important;
    background: rgba(13, 110, 253, 0.08) !important;
    animation: pulse-current 1.5s ease-in-out infinite;
}
.step-cell.step-paused::before {
    content: '\f4f4';  /* bi-pause-circle-fill */
    font-family: 'bootstrap-icons';
    position: absolute;
    top: -10px;
    right: -10px;
    width: 24px;
    height: 24px;
    background: #0d6efd;
    color: white;
    border-radius: 50%;
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
    box-shadow: 0 2px 6px rgba(13, 110, 253, 0.4);
}
@keyframes pulse-current {
    0%, 100% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.3); }
    50% { box-shadow: 0 0 0 8px rgba(13, 110, 253, 0); }
}

/* NEXT state - will execute when user clicks Next Step (orange dashed border) */
.step-cell.step-next {
    border: 2px dashed #fd7e14 !important;
    background: rgba(253, 126, 20, 0.05) !important;
    position: relative;
}
.step-cell.step-modified {
    border: 2px solid #6f42c1 !important;
    box-shadow: 0 0 8px rgba(111, 66, 193, 0.4);
}
.step-cell.step-modified::after {
    content: 'Modified';
    position: absolute;
    bottom: -8px;
    left: 50%;
    transform: translateX(-50%);
    font-size: 0.65rem;
    background: #6f42c1;
    color: white;
    padding: 1px 6px;
    border-radius: 8px;
    z-index: 10;
}
.step-cell.step-next::before {
    content: '\f4fc';  /* bi-play-circle */
    font-family: 'bootstrap-icons';
    position: absolute;
    top: -10px;
    right: -10px;
    width: 24px;
    height: 24px;
    background: #fd7e14;
    color: white;
    border-radius: 50%;
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
    animation: bounce-next 1s ease-in-out infinite;
}
@keyframes bounce-next {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-3px); }
}

/* Input preview for parser steps */
.step-input-preview {
    background: var(--bs-secondary-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 4px;
    padding: 8px;
    margin-top: 8px;
    font-size: 0.8rem;
}
.step-input-preview-label {
    font-weight: 600;
    color: var(--bs-secondary-color);
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.step-input-preview-content {
    background: var(--bs-body-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 3px;
    padding: 6px;
    max-height: 150px;
    overflow: auto;
    font-family: monospace;
    font-size: 0.75rem;
    white-space: pre-wrap;
    word-break: break-all;
}

/* Selection state for copy/paste */
.step-cell.selected {
    outline: 2px solid #6f42c1;
    outline-offset: 2px;
}
.drop-zone.selected {
    background: rgba(111, 66, 193, 0.1);
    outline: 2px dashed #6f42c1;
    outline-offset: -2px;
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

    // Keyboard shortcuts for step modal
    document.addEventListener('keydown', function(e) {
        const modalEl = document.getElementById('stepModal');
        const isModalOpen = modalEl && modalEl.classList.contains('show');

        if (!isModalOpen) return;

        // Ctrl+S or Cmd+S to save step
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveStep();
        }
    });

    // Global keyboard shortcuts (for debugger and grid)
    document.addEventListener('keydown', function(e) {
        // Don't trigger shortcuts when typing in input fields
        const target = e.target;
        const isTyping = target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable;
        const isModalOpen = document.querySelector('.modal.show');

        // If typing or modal is open, only handle specific shortcuts
        if (isTyping && !isModalOpen) return;
        if (isModalOpen) return;  // Let modal handle its own shortcuts

        // Check if debugger is active
        const isDebuggerActive = activeRunId && document.getElementById('runStatusBar')?.style.display !== 'none';

        if (isDebuggerActive) {
            // Space or Enter - Execute next step
            if (e.key === ' ' || e.key === 'Enter') {
                e.preventDefault();
                const nextStepBtn = document.getElementById('nextStepBtn');
                if (nextStepBtn && nextStepBtn.style.display !== 'none' && !nextStepBtn.disabled) {
                    executeNextStep();
                }
            }

            // Ctrl+Enter - Run all remaining steps
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                const continueBtn = document.getElementById('continueRunBtn');
                if (continueBtn && continueBtn.style.display !== 'none') {
                    continueInteractiveRun();
                }
            }

            // Escape - Stop execution
            if (e.key === 'Escape') {
                e.preventDefault();
                cancelInteractiveRun();
            }
        }

        // Grid shortcuts (when not in debugger mode or also available)

        // Ctrl+C - Copy selected step config (if a step is selected)
        if ((e.ctrlKey || e.metaKey) && e.key === 'c' && !isTyping) {
            const selectedStep = document.querySelector('.step-cell.selected');
            if (selectedStep) {
                e.preventDefault();
                copyStepToClipboard(selectedStep.dataset.stepId);
            }
        }

        // Ctrl+V - Paste step config to empty cell
        if ((e.ctrlKey || e.metaKey) && e.key === 'v' && !isTyping) {
            const selectedCell = document.querySelector('.drop-zone.selected');
            if (selectedCell && !selectedCell.querySelector('.step-cell[data-step-id]')) {
                e.preventDefault();
                pasteStepFromClipboard(selectedCell);
            }
        }

        // Ctrl+Z - Undo (placeholder - would need undo stack implementation)
        if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !isTyping) {
            // TODO: Implement undo stack for editor actions
            console.log('Undo not yet implemented');
        }
    });

    // Mark cells that have adjacent steps for flow indicators
    updateFlowIndicators();
});

// Update flow indicators between adjacent steps
function updateFlowIndicators() {
    const grid = document.getElementById('pipelineGrid');
    if (!grid) return;

    // Remove existing indicators
    grid.querySelectorAll('.step-cell.has-right-neighbor').forEach(cell => {
        cell.classList.remove('has-right-neighbor');
    });

    // Find all step cells and mark those with a neighbor to the right
    const rows = grid.querySelectorAll('tbody tr[data-row]');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td.drop-zone');
        cells.forEach((cell, index) => {
            const stepCell = cell.querySelector('.step-cell[data-step-id]');
            if (!stepCell) return;

            // Check if next cell has a step
            const nextCell = cells[index + 1];
            if (nextCell && nextCell.querySelector('.step-cell[data-step-id]')) {
                stepCell.classList.add('has-right-neighbor');
            }
        });
    });
}

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
    const alertClass = type === 'success' ? 'success' : (type === 'warning' ? 'warning' : 'info');
    const iconClass = type === 'success' ? 'check-circle' : (type === 'warning' ? 'exclamation-triangle' : 'info-circle');
    toast.className = `alert alert-${alertClass} position-fixed`;
    toast.style.cssText = 'bottom: 20px; right: 20px; z-index: 9999; padding: 8px 16px; font-size: 0.875rem; animation: fadeIn 0.2s;';
    toast.innerHTML = `<i class="bi bi-${iconClass}"></i> ${message}`;

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
    // Hide Variable Exporter (no output for new steps)
    hideModalVariableExporter();
    // Show Input Preview if in debug mode - new steps CAN see previous step output
    setTimeout(showUniversalInputPreview, 100);
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

                // Check if this step has output from the current interactive run
                // Look up by step_id (primary key in stepOutputs)
                const stepName = step.step_name;
                const stepOutputData = stepOutputs[stepId];
                const hasCurrentOutput = stepOutputData && stepOutputData.output;
                const hasSavedMappings = step.output_mappings && Object.keys(step.output_mappings).length > 0;

                if (hasCurrentOutput) {
                    // Show exporter with current output
                    showModalVariableExporter(stepId, stepName, stepOutputData.output, step.output_mappings || {});
                } else if (hasSavedMappings) {
                    // Show saved mappings even without current output
                    showSavedMappingsOnly(stepId, stepName, step.output_mappings);
                } else {
                    hideModalVariableExporter();
                }

                // Show universal input preview if in debug mode
                setTimeout(showUniversalInputPreview, 100);

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

            // If in debug mode, don't reload - stay in debug mode and auto-rerun
            if (activeRunId) {
                // Update the cell's visual content without reloading
                const stepId = document.getElementById('stepId').value;
                const stepName = document.getElementById('stepName').value;
                const stepLabel = document.getElementById('stepLabel').value;
                const cell = document.querySelector(`[data-step-id="${stepId}"]`);
                if (cell) {
                    // Update the label in the cell
                    const labelEl = cell.querySelector('strong');
                    if (labelEl) labelEl.textContent = stepLabel || stepName;

                    // Mark as modified
                    cell.classList.add('step-modified');
                }

                // Check if this step has already run (has output)
                const hasRun = cell && (cell.classList.contains('step-success') || cell.classList.contains('step-failed'));

                if (hasRun) {
                    // Step already executed - ask if user wants to re-run
                    showRerunPromptWithOptions(stepId, stepName);
                } else {
                    // Step hasn't run yet - it's the next step to run
                    showToast('Step saved! Click "Next Step" to execute.', 'success');
                }
            } else {
                location.reload();
            }
        } else {
            alert('Error: ' + (result.message || result.error || 'Failed to save step'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

// Show prompt with clear options after saving a step during debug
function showRerunPromptWithOptions(stepId, stepName) {
    const toolbar = document.getElementById('debuggerToolbar');
    if (!toolbar) return;

    // Remove any existing alert
    const existing = document.getElementById('rerunFromHereAlert');
    if (existing) existing.remove();

    // Create new alert with clear options
    const rerunAlert = document.createElement('div');
    rerunAlert.id = 'rerunFromHereAlert';
    rerunAlert.className = 'alert alert-info mb-2';
    rerunAlert.style.cssText = 'border-left: 4px solid #0dcaf0;';
    toolbar.parentNode.insertBefore(rerunAlert, toolbar.nextSibling);

    rerunAlert.innerHTML = `
        <div class="d-flex align-items-start justify-content-between">
            <div>
                <strong><i class="bi bi-pencil-square me-1"></i> Step Updated:</strong> <code>${stepName}</code>
                <p class="mb-2 mt-1 small text-muted">
                    Your changes are saved. Choose how to proceed:
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-sm btn-primary" onclick="rerunAndExecute(${stepId}, '${stepName}')">
                        <i class="bi bi-play-fill"></i> Re-run This Step
                    </button>
                    <button class="btn btn-sm btn-warning" onclick="rerunFromStep(${stepId}, '${stepName}')">
                        <i class="bi bi-fast-forward-fill"></i> Re-run From Here (All Following)
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="this.closest('.alert').remove()">
                        <i class="bi bi-x"></i> Keep Changes, Don't Re-run
                    </button>
                </div>
            </div>
        </div>
    `;
}

// Re-run just the single modified step (reset it and execute immediately)
async function rerunAndExecute(stepId, stepName) {
    if (!activeRunId) {
        alert('No active debug run');
        return;
    }

    try {
        // First reset this step
        const resetResponse = await fetch('/pipelines/rerunfrom/' + activeRunId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: new URLSearchParams({
                csrf_token: csrfToken,
                step_id: stepId
            })
        });

        const resetResult = await resetResponse.json();

        if (resetResult.success) {
            // Remove the alert
            const rerunAlert = document.getElementById('rerunFromHereAlert');
            if (rerunAlert) rerunAlert.remove();

            // Reset cell state
            const cell = document.querySelector(`[data-step-id="${stepId}"]`);
            if (cell) {
                cell.classList.remove('step-success', 'step-failed', 'step-modified');
                cell.classList.add('step-running');
            }

            showToast(`Executing "${stepName}" with new config...`, 'info');

            // Now execute the step immediately
            await executeNextStep();

        } else {
            alert('Error: ' + (resetResult.error || 'Failed to reset step'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

// Show prompt to re-run from a specific step (legacy function)
function showRerunFromHerePrompt(stepId, stepName) {
    showRerunPromptWithOptions(stepId, stepName);
}

// Re-run pipeline from a specific step
async function rerunFromStep(stepId, stepName) {
    if (!activeRunId) {
        alert('No active debug run');
        return;
    }

    if (!confirm(`This will reset "${stepName}" and all following steps, then re-run from there. Continue?`)) {
        return;
    }

    try {
        const response = await fetch('/pipelines/rerunfrom/' + activeRunId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: new URLSearchParams({
                csrf_token: csrfToken,
                step_id: stepId
            })
        });

        const result = await response.json();

        if (result.success) {
            // Remove the rerun alert
            const rerunAlert = document.getElementById('rerunFromHereAlert');
            if (rerunAlert) rerunAlert.remove();

            // Reset cell states from this step forward
            resetCellStatesFrom(stepId);

            // Update next step info
            if (result.next_step) {
                updateNextStepDisplay(result.next_step);
            }

            showToast(`Reset to "${stepName}". Click "Next Step" to execute.`, 'info');

            // Start polling again if we were paused
            if (!pollInterval) {
                startRunPolling();
            }
        } else {
            alert('Error: ' + (result.error || 'Failed to reset'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

// Reset cell visual states from a step forward
function resetCellStatesFrom(fromStepId) {
    const fromCell = document.querySelector(`[data-step-id="${fromStepId}"]`);
    if (!fromCell) return;

    const fromRow = parseInt(fromCell.closest('td').dataset.row);
    const fromCol = parseInt(fromCell.closest('td').dataset.col);

    // Reset all cells at or after this position
    document.querySelectorAll('.step-cell').forEach(cell => {
        const td = cell.closest('td');
        const row = parseInt(td.dataset.row);
        const col = parseInt(td.dataset.col);

        // Reset if same row and same/later col, or later row
        if ((row === fromRow && col >= fromCol) || row > fromRow) {
            cell.classList.remove('step-success', 'step-failed', 'step-running', 'step-current', 'step-next', 'step-modified');
            cell.classList.add('step-pending');

            // Clear output section
            const outputSection = cell.querySelector('.step-output-section');
            if (outputSection) outputSection.style.display = 'none';

            // Clear from stepOutputs
            const stepId = cell.dataset.stepId;
            if (stepId && stepOutputs[stepId]) {
                delete stepOutputs[stepId];
            }
        }
    });

    // Mark the target step as "next"
    fromCell.classList.remove('step-pending');
    fromCell.classList.add('step-next');
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

/**
 * Toggle parallel execution for an entire row
 * This updates all steps in the row to have run_parallel enabled/disabled
 */
async function toggleRowParallel(row, buttonEl) {
    const tr = document.querySelector(`tr[data-row="${row}"]`);
    if (!tr) return;

    const currentlyParallel = tr.dataset.parallel === '1';
    const newValue = !currentlyParallel;

    // Find all steps in this row
    const stepCells = tr.querySelectorAll('.step-cell[data-step-id]');
    if (stepCells.length === 0) {
        showToast('No steps in this row to set parallel mode', 'warning');
        return;
    }

    // Update visual immediately
    buttonEl.classList.toggle('active', newValue);
    const icon = buttonEl.querySelector('i');
    if (icon) {
        icon.className = newValue ? 'bi bi-lightning-fill text-warning' : 'bi bi-lightning text-muted';
    }
    tr.classList.toggle('row-parallel', newValue);
    tr.dataset.parallel = newValue ? '1' : '0';
    buttonEl.title = newValue
        ? 'Parallel ON: This row runs concurrently with other parallel rows. Click to disable.'
        : 'Click to enable parallel execution for this row';

    // Update each step in the row
    for (const cell of stepCells) {
        const stepId = cell.dataset.stepId;
        try {
            await fetch('/pipelines/updatestepparallel/' + pipelineId, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrfToken
                },
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    step_id: stepId,
                    run_parallel: newValue ? '1' : '0'
                })
            });
        } catch (err) {
            console.error('Failed to update step parallel:', err);
        }
    }

    showToast(`Row ${row + 1} parallel mode ${newValue ? 'enabled' : 'disabled'}`, 'success');
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

// =========================================================================
// Step Copy/Paste
// =========================================================================

let clipboardStep = null;  // Store copied step config

async function copyStepToClipboard(stepId) {
    try {
        const response = await fetch('/pipelines/getstep/' + pipelineId + '?step_id=' + stepId);
        const result = await response.json();

        if (result.success) {
            clipboardStep = result.data.step;
            showToast('Step copied to clipboard', 'success');

            // Also try to copy to system clipboard as JSON
            try {
                await navigator.clipboard.writeText(JSON.stringify(clipboardStep, null, 2));
            } catch (clipErr) {
                // System clipboard not available, internal clipboard works
            }
        } else {
            showToast('Failed to copy step', 'warning');
        }
    } catch (err) {
        showToast('Error copying step: ' + err.message, 'warning');
    }
}

async function pasteStepFromClipboard(targetCell) {
    if (!clipboardStep) {
        showToast('No step in clipboard. Select a step and press Ctrl+C first.', 'info');
        return;
    }

    const row = parseInt(targetCell.dataset.row);
    const col = parseInt(targetCell.dataset.col);

    // Generate a new step name
    const newStepName = clipboardStep.step_name + '_copy_' + Date.now().toString(36).slice(-4);

    try {
        const data = new URLSearchParams({
            csrf_token: csrfToken,
            step_id: '0',  // New step
            step_name: newStepName,
            label: (clipboardStep.label || clipboardStep.step_name) + ' (Copy)',
            row: row,
            col: col,
            step_type: clipboardStep.step_type,
            config: JSON.stringify(clipboardStep.config || {}),
            input_source: clipboardStep.input_source || 'prev',
            input_config: JSON.stringify(clipboardStep.input_config || {}),
            condition: clipboardStep.condition || '{}',
            on_success: clipboardStep.on_success || 'next_col',
            on_failure: clipboardStep.on_failure || 'exit',
            timeout_seconds: clipboardStep.timeout_seconds || 300,
            is_active: clipboardStep.is_active ? '1' : '0',
            run_parallel: clipboardStep.run_parallel ? '1' : '0'
        });

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
            showToast('Step pasted', 'success');
            location.reload();
        } else {
            alert('Failed to paste step: ' + (result.error || result.message));
        }
    } catch (err) {
        alert('Error pasting step: ' + err.message);
    }
}

// Make cells selectable for copy/paste
document.addEventListener('click', function(e) {
    const stepCell = e.target.closest('.step-cell[data-step-id]');
    const dropZone = e.target.closest('.drop-zone');

    // Clear previous selections
    document.querySelectorAll('.step-cell.selected, .drop-zone.selected').forEach(el => {
        el.classList.remove('selected');
    });

    if (stepCell) {
        stepCell.classList.add('selected');
    } else if (dropZone && !dropZone.querySelector('.step-cell[data-step-id]')) {
        dropZone.classList.add('selected');
    }
});

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

    // Auto-derive schema when enabling MCP and schema is empty
    if (enabled) {
        const schemaEl = document.getElementById('inputSchemaJson');
        const val = schemaEl.value.trim();
        // Consider empty if blank, empty array, or empty object
        const isEmpty = !val || val === '[]' || val === '{}';
        if (isEmpty) {
            deriveSchemaFromSteps();
        }
    }
}

async function deriveSchemaFromSteps() {
    try {
        const resp = await fetch(`/pipelines/extractvariables?id=${pipelineId}`);
        const data = await resp.json();
        if (data.success && data.data) {
            const schema = data.data.schema;
            const variables = data.data.variables || [];

            if (variables.length === 0) {
                alert('No {context.xxx} variables found in first-row steps.');
                return;
            }

            document.getElementById('inputSchemaJson').value = JSON.stringify(schema, null, 2);
        } else {
            alert(data.error || 'Failed to extract variables');
        }
    } catch (err) {
        console.error('Error deriving schema:', err);
        alert('Failed to derive schema: ' + err.message);
    }
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

// =========================================================================
// Interactive Run Infrastructure
// =========================================================================

let activeRunId = null;
let pollInterval = null;
let stepOutputs = {};  // Store output data by step_name

async function confirmTriggerInteractive() {
    const context = document.getElementById('triggerContext').value || '{}';
    const submitBtn = document.getElementById('triggerModalSubmit');
    const originalHtml = submitBtn.innerHTML;

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Starting...';

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
            // Close modal and stay on page
            bootstrap.Modal.getInstance(document.getElementById('triggerModal')).hide();

            // Start tracking the run
            activeRunId = result.data.run_id;
            stepOutputs = {};

            // Show progress bar
            showRunProgressBar(true);

            // Clear any existing step states
            clearStepStates();

            // Start polling for status
            startRunPolling();

            showToast('Interactive run started', 'success');
        } else {
            alert('Error: ' + (result.error || 'Failed to start interactive run'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalHtml;
    }
}

// Track the next step info for the debugger
let nextStepInfo = null;

function showRunProgressBar(show) {
    const statusBar = document.getElementById('runStatusBar');
    if (statusBar) {
        statusBar.style.display = show ? 'block' : 'none';
        statusBar.style.setProperty('display', show ? 'block' : 'none', 'important');
    }
}

function clearStepStates() {
    // Remove all step state classes and output sections
    document.querySelectorAll('.step-cell').forEach(cell => {
        cell.classList.remove('step-running', 'step-success', 'step-failed', 'step-pending', 'step-paused', 'step-next', 'has-output');

        // Hide in-cell output section
        const outputSection = cell.querySelector('.step-output-section');
        if (outputSection) {
            outputSection.style.display = 'none';
        }
    });

    // Hide all output toggle buttons
    document.querySelectorAll('.row-output-btn').forEach(btn => {
        btn.style.display = 'none';
    });

    // Hide the main toggle output button
    const toggleOutputBtn = document.getElementById('toggleOutputBtn');
    if (toggleOutputBtn) {
        toggleOutputBtn.style.display = 'none';
    }

    // Clear stored outputs and next step info
    stepOutputs = {};
    nextStepInfo = null;
}

function startRunPolling() {
    // Clear any existing interval
    if (pollInterval) {
        clearInterval(pollInterval);
    }

    // Poll immediately, then every 2 seconds
    pollRunStatus();
    pollInterval = setInterval(pollRunStatus, 2000);
}

function stopRunPolling() {
    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
}

async function pollRunStatus() {
    if (!activeRunId) {
        console.log('pollRunStatus: No active run ID, stopping poll');
        stopRunPolling();
        return;
    }

    try {
        const response = await fetch('/pipelines/interactivestatus/' + activeRunId);
        const result = await response.json();

        if (!result.success) {
            console.error('Failed to get run status:', result.error);
            // If run not found, the run was probably deleted or ID is invalid
            if (result.error && result.error.includes('not found')) {
                stopRunPolling();
                showToast('Debug session ended (run not found)', 'warning');
                activeRunId = null;
                showRunProgressBar(false);
            }
            return;
        }

        const data = result.data;
        const run = data.run;

        // Update progress bar
        updateProgressBar(run);

        // Update grid cells with step statuses
        updateGridWithStepRuns(data.step_runs || []);

        // Check for completion or failure
        if (run.status === 'completed') {
            stopRunPolling();
            onRunCompleted(run);
        } else if (run.status === 'failed') {
            stopRunPolling();
            onRunFailed(run, data.step_runs);
        } else if (run.status === 'paused') {
            // Interactive mode - paused for user input
            stopRunPolling();  // Stop polling while paused

            // Calculate next step from step runs
            const pausedStepRun = data.step_runs.find(sr => sr.awaiting_input);
            if (pausedStepRun) {
                // Find the next pending step
                const pendingSteps = data.step_runs
                    .filter(sr => sr.status === 'pending')
                    .sort((a, b) => {
                        if (a.row !== b.row) return a.row - b.row;
                        return a.col - b.col;
                    });

                if (pendingSteps.length > 0) {
                    nextStepInfo = {
                        step_id: pendingSteps[0].step_id,
                        step_name: pendingSteps[0].step_name,
                        row: pendingSteps[0].row,
                        col: pendingSteps[0].col
                    };
                } else {
                    nextStepInfo = null;
                }
            }

            updateDebuggerUI(run, nextStepInfo, pausedStepRun);
        } else if (run.status === 'cancelled') {
            stopRunPolling();
            onRunCancelled();
        }

    } catch (err) {
        console.error('Error polling run status:', err);
    }
}

function updateProgressBar(run, statusOverride = null) {
    const progressFill = document.getElementById('runStatusProgress');
    const statusLabel = document.getElementById('runStatusLabel');
    const statusDetail = document.getElementById('runStatusDetail');
    const spinner = document.getElementById('runStatusSpinner');
    const statusBadge = document.getElementById('runStatusBadge');

    if (progressFill) {
        const percent = run.progress_percent ||
            (run.steps_total > 0 ? Math.round((run.steps_completed / run.steps_total) * 100) : 0);
        progressFill.style.width = percent + '%';
    }

    if (statusLabel) {
        if (statusOverride) {
            statusLabel.textContent = statusOverride;
        } else if (run.status === 'paused') {
            statusLabel.textContent = 'Paused';
        } else if (run.status === 'completed') {
            statusLabel.textContent = 'Completed';
        } else if (run.status === 'failed') {
            statusLabel.textContent = 'Failed';
        } else {
            statusLabel.textContent = 'Running';
        }
    }

    if (statusDetail) {
        statusDetail.textContent = `${run.steps_completed}/${run.steps_total} steps`;
    }

    // Update status badge styling
    if (statusBadge) {
        statusBadge.classList.remove('status-paused', 'status-completed', 'status-failed');
        if (run.status === 'paused') {
            statusBadge.classList.add('status-paused');
        } else if (run.status === 'completed') {
            statusBadge.classList.add('status-completed');
        } else if (run.status === 'failed') {
            statusBadge.classList.add('status-failed');
        }
    }

    // Update spinner visibility
    if (spinner) {
        spinner.style.display = (run.status === 'running' || run.status === 'pending') ? '' : 'none';
    }

    // Update current step info
    const currentStepName = document.getElementById('currentStepName');
    const currentStepPosition = document.getElementById('currentStepPosition');
    if (currentStepName) {
        currentStepName.textContent = run.current_step_name || '-';
    }
}

/**
 * Update the debugger UI when paused
 */
function updateDebuggerUI(run, nextStep, pausedStepRun) {
    const nextStepBtn = document.getElementById('nextStepBtn');
    const nextStepNameEl = document.getElementById('nextStepName');
    const stepBackBtn = document.getElementById('stepBackBtn');
    const prevStepNameEl = document.getElementById('prevStepName');
    const continueBtn = document.getElementById('continueRunBtn');
    const restartBtn = document.getElementById('restartRunBtn');
    const spinner = document.getElementById('runStatusSpinner');
    const currentStepName = document.getElementById('currentStepName');
    const currentStepPosition = document.getElementById('currentStepPosition');

    // Hide spinner when paused
    if (spinner) spinner.style.display = 'none';

    // Show Next Step button with next step name
    if (nextStepBtn && nextStepNameEl) {
        if (nextStep) {
            nextStepBtn.style.display = '';
            nextStepNameEl.textContent = nextStep.step_name || 'Unknown';

            // Mark the next step cell visually
            markNextStep(nextStep.step_id);
        } else {
            nextStepBtn.style.display = 'none';
        }
    }

    // Show/hide Step Back button based on completed steps
    if (stepBackBtn && prevStepNameEl) {
        const completedCells = document.querySelectorAll('.step-cell.step-success');
        if (completedCells.length > 0) {
            stepBackBtn.style.display = '';
            // Get the last completed step name
            const lastCompleted = Array.from(completedCells)
                .map(cell => ({
                    name: cell.dataset.stepName,
                    row: parseInt(cell.closest('td').dataset.row),
                    col: parseInt(cell.closest('td').dataset.col)
                }))
                .sort((a, b) => (b.row - a.row) || (b.col - a.col))[0];
            prevStepNameEl.textContent = lastCompleted ? lastCompleted.name : '';
        } else {
            stepBackBtn.style.display = 'none';
        }
    }

    // Show Continue and Restart buttons
    if (continueBtn) continueBtn.style.display = '';
    if (restartBtn) restartBtn.style.display = '';

    // Update current step info
    if (pausedStepRun) {
        if (currentStepName) currentStepName.textContent = pausedStepRun.step_name || '-';
        if (currentStepPosition) currentStepPosition.textContent = `R${pausedStepRun.row + 1}:C${pausedStepRun.col + 1}`;
    }

    // Update progress bar
    updateProgressBar(run);
}

/**
 * Mark a step cell as the "next" step to execute
 * Also shows INPUT preview for parser steps
 */
function markNextStep(stepId) {
    // Remove existing next markers and input previews
    document.querySelectorAll('.step-cell.step-next').forEach(cell => {
        cell.classList.remove('step-next');
    });
    document.querySelectorAll('.step-input-preview').forEach(el => el.remove());

    // Add next marker to the specified step
    if (stepId) {
        const nextCell = document.querySelector(`.step-cell[data-step-id="${stepId}"]`);
        if (nextCell) {
            nextCell.classList.add('step-next');

            // Check if this is a parser step - if so, show the INPUT data
            const stepType = nextCell.dataset.stepType;
            if (stepType === 'parser') {
                showInputPreviewForParserStep(nextCell, stepId);
            }
        }
    }
}

/**
 * Show INPUT preview for parser steps
 * This helps users see what data the parser will operate on
 */
function showInputPreviewForParserStep(stepCell, stepId) {
    // Find the previous step's output (what will be the INPUT for this parser)
    const row = parseInt(stepCell.closest('td').dataset.row);
    const col = parseInt(stepCell.closest('td').dataset.col);

    // Look for the most recent completed step before this one
    let inputData = null;
    let inputSource = 'previous step';

    // Check stepOutputs for any completed step that could feed into this one
    // First, try to find a step in the same row with a lower col, or any step in a previous row
    const completedSteps = Object.values(stepOutputs)
        .filter(so => so.output && Object.keys(so.output).length > 0)
        .sort((a, b) => {
            // Sort by row desc, then col desc to get most recent
            const cellA = document.querySelector(`.step-cell[data-step-id="${a.step_id}"]`);
            const cellB = document.querySelector(`.step-cell[data-step-id="${b.step_id}"]`);
            if (!cellA || !cellB) return 0;

            const rowA = parseInt(cellA.closest('td').dataset.row);
            const colA = parseInt(cellA.closest('td').dataset.col);
            const rowB = parseInt(cellB.closest('td').dataset.row);
            const colB = parseInt(cellB.closest('td').dataset.col);

            if (rowA !== rowB) return rowB - rowA;
            return colB - colA;
        });

    if (completedSteps.length > 0) {
        // Get the most recently completed step that comes before this one
        for (const so of completedSteps) {
            const cell = document.querySelector(`.step-cell[data-step-id="${so.step_id}"]`);
            if (!cell) continue;

            const soRow = parseInt(cell.closest('td').dataset.row);
            const soCol = parseInt(cell.closest('td').dataset.col);

            // Check if this step comes before the parser step
            if (soRow < row || (soRow === row && soCol < col)) {
                inputData = so.output;
                inputSource = so.step_name;
                break;
            }
        }
    }

    if (!inputData) {
        return;  // No input data to show
    }

    // Create and insert the INPUT preview element
    const preview = document.createElement('div');
    preview.className = 'step-input-preview';
    preview.innerHTML = `
        <div class="step-input-preview-label">
            <i class="bi bi-box-arrow-in-right text-warning"></i>
            <span>INPUT from <code>${inputSource}</code>:</span>
        </div>
        <div class="step-input-preview-content">${JSON.stringify(inputData, null, 2)}</div>
    `;

    // Insert after the step cell content
    const outputSection = stepCell.querySelector('.step-output-section');
    if (outputSection) {
        stepCell.insertBefore(preview, outputSection);
    } else {
        stepCell.appendChild(preview);
    }
}

/**
 * Execute exactly one step (Next Step button)
 */
async function executeNextStep() {
    if (!activeRunId) {
        showToast('No active debug run. Start a new Interactive Run to continue.', 'warning');
        return;
    }

    const nextStepBtn = document.getElementById('nextStepBtn');
    const originalHtml = nextStepBtn ? nextStepBtn.innerHTML : '';

    if (nextStepBtn) {
        nextStepBtn.disabled = true;
        nextStepBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Executing...';
    }

    try {
        const response = await fetch('/pipelines/stepnext/' + activeRunId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'csrf_token=' + encodeURIComponent(csrfToken)
        });

        const result = await response.json();

        if (result.success) {
            const data = result.data;

            // Update grid with new step states
            if (data.step_runs) {
                updateGridWithStepRuns(data.step_runs);
            }

            // Update stored next step info
            nextStepInfo = data.next_step;

            // Update debugger UI
            const run = {
                status: data.run_status,
                steps_completed: data.steps_completed,
                steps_total: data.steps_total,
                progress_percent: data.progress_percent,
                current_step_name: data.step_executed ? data.step_executed.step_name : null
            };

            // Find the paused step run
            const pausedStepRun = data.step_runs ? data.step_runs.find(sr => sr.awaiting_input) : data.step_executed;

            if (data.run_status === 'paused') {
                updateDebuggerUI(run, data.next_step, pausedStepRun);
            } else if (data.run_status === 'completed') {
                onRunCompleted(run);
            } else if (data.run_status === 'failed') {
                onRunFailed(run, data.step_runs || []);
            }

            // Show what was executed
            if (data.step_executed) {
                const status = data.step_executed.status === 'success' ? 'success' : 'warning';
                showToast(`Executed: ${data.step_executed.step_name}`, status);
            }

        } else {
            // Check for specific error conditions
            if (result.error && result.error.includes('not found')) {
                showToast('Debug session has ended. Start a new Interactive Run to continue.', 'warning');
                activeRunId = null;
                showRunProgressBar(false);
            } else if (result.error && result.error.includes('cannot be stepped')) {
                showToast('Pipeline has completed. Start a new Interactive Run to debug again.', 'info');
                activeRunId = null;
                showRunProgressBar(false);
            } else {
                alert('Step execution failed: ' + (result.error || 'Unknown error'));
            }
        }

    } catch (err) {
        alert('Error executing step: ' + err.message);
    } finally {
        if (nextStepBtn) {
            nextStepBtn.disabled = false;
            nextStepBtn.innerHTML = originalHtml;
            // Re-populate next step name if available
            const nextStepNameEl = document.getElementById('nextStepName');
            if (nextStepNameEl && nextStepInfo) {
                nextStepNameEl.textContent = nextStepInfo.step_name || '-';
            }
        }
    }
}

// Track execution history for step back functionality
let executionHistory = [];  // Array of step_ids in execution order
let currentPlayheadIndex = -1;  // Current position in history

/**
 * Step back to the previous step (move playhead backwards)
 * This reloads the context from before that step executed
 */
async function stepBack() {
    if (!activeRunId) {
        showToast('No active run', 'info');
        return;
    }

    // Get the completed steps in order
    const completedCells = Array.from(document.querySelectorAll('.step-cell.step-success'))
        .map(cell => ({
            stepId: cell.dataset.stepId,
            stepName: cell.dataset.stepName,
            row: parseInt(cell.closest('td').dataset.row),
            col: parseInt(cell.closest('td').dataset.col)
        }))
        .sort((a, b) => (a.row - b.row) || (a.col - b.col));

    if (completedCells.length === 0) {
        showToast('No completed steps to go back to', 'warning');
        return;
    }

    // Get the last completed step
    const lastCompleted = completedCells[completedCells.length - 1];

    // Confirm the action
    if (!confirm(`Move playhead back to "${lastCompleted.stepName}"?\nThis will reset it so you can re-run with its original input context.`)) {
        return;
    }

    try {
        // Use rerunfrom to reset from this step
        const response = await fetch('/pipelines/rerunfrom/' + activeRunId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: new URLSearchParams({
                csrf_token: csrfToken,
                step_id: lastCompleted.stepId
            })
        });

        const result = await response.json();

        if (result.success) {
            // Reset cell visual state
            resetCellStatesFrom(lastCompleted.stepId);

            // Update next step display
            if (result.next_step) {
                updateNextStepDisplay(result.next_step);
            }

            // Update prev step display (the step before this one)
            updatePrevStepDisplay(completedCells, completedCells.length - 1);

            showToast(`Playhead moved to "${lastCompleted.stepName}". Click Next to re-execute.`, 'info');

        } else {
            alert('Error: ' + (result.error || 'Failed to step back'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

/**
 * Update the previous step display for the back button
 */
function updatePrevStepDisplay(completedCells, currentIndex) {
    const stepBackBtn = document.getElementById('stepBackBtn');
    const prevStepNameEl = document.getElementById('prevStepName');

    if (!stepBackBtn || !prevStepNameEl) return;

    // Show the step we can go back to (the one before current)
    if (currentIndex > 0 && completedCells[currentIndex - 1]) {
        stepBackBtn.style.display = '';
        prevStepNameEl.textContent = completedCells[currentIndex - 1].stepName;
    } else if (currentIndex === 0) {
        // At the first step, show the button but indicate start
        stepBackBtn.style.display = '';
        prevStepNameEl.textContent = '(start)';
    } else {
        stepBackBtn.style.display = 'none';
    }
}

/**
 * Restart the interactive run from the beginning
 */
async function restartInteractiveRun() {
    if (!confirm('Restart the pipeline from the beginning? All progress will be lost.')) {
        return;
    }

    // Cancel current run
    await cancelInteractiveRun();

    // Trigger a new interactive run
    setTimeout(() => {
        triggerInteractive();
    }, 500);
}

function updateGridWithStepRuns(stepRuns) {
    stepRuns.forEach(sr => {
        // Find the step cell by step_id data attribute
        const cell = document.querySelector(`[data-step-id="${sr.step_id}"]`);
        if (!cell) return;

        // Remove previous state classes
        cell.classList.remove('step-running', 'step-success', 'step-failed', 'step-pending', 'step-paused', 'step-next');

        // Add appropriate state class
        if (sr.status === 'running') {
            cell.classList.add('step-running');
        } else if (sr.status === 'success' || sr.status === 'completed') {
            cell.classList.add('step-success');
            // Store output data keyed by step_id for uniqueness
            if (sr.output || sr.stdout) {
                stepOutputs[sr.step_id] = {
                    step_id: sr.step_id,
                    step_name: sr.step_name,
                    output: sr.output,
                    stdout: sr.stdout,
                    stderr: sr.stderr,
                    exit_code: sr.exit_code,
                    duration_ms: sr.duration_ms
                };
            }
        } else if (sr.status === 'failed') {
            cell.classList.add('step-failed');
            // Store error info keyed by step_id
            stepOutputs[sr.step_id] = {
                step_id: sr.step_id,
                step_name: sr.step_name,
                output: sr.output,
                stdout: sr.stdout,
                stderr: sr.stderr,
                error_message: sr.error_message,
                exit_code: sr.exit_code
            };
        }

        // Mark steps that are awaiting input (paused)
        if (sr.awaiting_input) {
            cell.classList.add('step-paused');
        }
    });

    // Update in-cell outputs for completed steps
    updateStepOutputs();
}

function updateStepOutputs() {
    let hasAnyOutputs = false;

    // Update in-cell outputs for each step (keyed by step_id)
    Object.entries(stepOutputs).forEach(([stepId, data]) => {
        // Find the step cell by step_id attribute (primary) or step_name (fallback)
        let cell = document.querySelector(`.step-cell[data-step-id="${stepId}"]`);
        if (!cell && data.step_name) {
            cell = document.querySelector(`.step-cell[data-step-name="${data.step_name}"]`);
        }
        if (!cell) return;

        hasAnyOutputs = true;
        cell.classList.add('has-output');

        const outputSection = cell.querySelector('.step-output-section');
        const outputPre = cell.querySelector('.step-output-pre');

        if (outputSection && outputPre) {
            // Build output content
            let content = '';
            let isError = false;

            if (data.error_message) {
                content = 'ERROR: ' + data.error_message + '\n\n';
                isError = true;
            }

            if (data.output && Object.keys(data.output).length > 0) {
                content += JSON.stringify(data.output, null, 2);
            } else if (data.stdout) {
                content += data.stdout;
            } else if (!content) {
                content = '(no output)';
            }

            if (data.stderr) {
                content += '\n\nSTDERR:\n' + data.stderr;
                isError = true;
            }

            outputPre.textContent = content;
            outputPre.classList.toggle('error', isError);

            // Show the output section
            outputSection.style.display = 'block';
        }

        // Show the row output toggle button
        const td = cell.closest('td');
        if (td) {
            const row = parseInt(td.dataset.row);
            const outputBtn = document.querySelector(`.row-output-btn[data-row="${row}"]`);
            if (outputBtn) {
                outputBtn.style.display = '';
            }
        }
    });

    // Show/hide the main Toggle Output button in status bar
    const toggleOutputBtn = document.getElementById('toggleOutputBtn');
    if (toggleOutputBtn) {
        toggleOutputBtn.style.display = hasAnyOutputs ? '' : 'none';
    }
}

function toggleStepOutput(btn) {
    const content = btn.closest('.step-output-section').querySelector('.step-output-content');
    const icon = btn.querySelector('i');

    if (content.classList.contains('collapsed')) {
        content.classList.remove('collapsed');
        icon.classList.remove('bi-chevron-right');
        icon.classList.add('bi-chevron-down');
    } else {
        content.classList.add('collapsed');
        icon.classList.remove('bi-chevron-down');
        icon.classList.add('bi-chevron-right');
    }
}

function toggleAllOutputRows() {
    const outputSections = document.querySelectorAll('.step-output-section[style*="block"]');
    const outputContents = document.querySelectorAll('.step-output-content');

    // Check if any are expanded (not collapsed)
    const anyExpanded = Array.from(outputContents).some(c => !c.classList.contains('collapsed'));

    outputContents.forEach(content => {
        if (anyExpanded) {
            content.classList.add('collapsed');
        } else {
            content.classList.remove('collapsed');
        }
    });

    // Update all toggle icons
    document.querySelectorAll('.step-output-toggle i').forEach(icon => {
        icon.classList.remove('bi-chevron-down', 'bi-chevron-right');
        icon.classList.add(anyExpanded ? 'bi-chevron-right' : 'bi-chevron-down');
    });
}

function toggleRowOutput(rowNum) {
    // Toggle all step outputs in this row
    const row = document.querySelector(`tr[data-row="${rowNum}"]`);
    if (!row) return;

    const contents = row.querySelectorAll('.step-output-content');
    const anyExpanded = Array.from(contents).some(c => !c.classList.contains('collapsed'));

    contents.forEach(content => {
        content.classList.toggle('collapsed', anyExpanded);
    });

    row.querySelectorAll('.step-output-toggle i').forEach(icon => {
        icon.classList.remove('bi-chevron-down', 'bi-chevron-right');
        icon.classList.add(anyExpanded ? 'bi-chevron-right' : 'bi-chevron-down');
    });
}

// =========================================================================
// Variable Exporter
// =========================================================================

// Store pending exports per step (before saving)
let pendingExports = {};  // stepId -> Set of paths

// Extract all paths from a JSON object
function extractJsonPaths(obj, prefix = '', maxDepth = 4) {
    const paths = [];

    if (maxDepth <= 0) return paths;

    if (obj === null || obj === undefined) {
        return paths;
    }

    if (Array.isArray(obj)) {
        paths.push({ path: prefix, type: 'array', length: obj.length });
        // Show first item's structure if array is not empty
        if (obj.length > 0 && typeof obj[0] === 'object' && obj[0] !== null) {
            const itemPaths = extractJsonPaths(obj[0], prefix + '[0]', maxDepth - 1);
            paths.push(...itemPaths);
        }
    } else if (typeof obj === 'object') {
        if (prefix) {
            paths.push({ path: prefix, type: 'object', keys: Object.keys(obj).length });
        }
        for (const key of Object.keys(obj)) {
            const newPath = prefix ? `${prefix}.${key}` : key;
            const value = obj[key];

            if (value === null) {
                paths.push({ path: newPath, type: 'null' });
            } else if (Array.isArray(value)) {
                paths.push(...extractJsonPaths(value, newPath, maxDepth - 1));
            } else if (typeof value === 'object') {
                paths.push(...extractJsonPaths(value, newPath, maxDepth - 1));
            } else {
                paths.push({ path: newPath, type: typeof value, sample: String(value).substring(0, 30) });
            }
        }
    } else {
        paths.push({ path: prefix, type: typeof obj });
    }

    return paths;
}

// Render the Variable Exporter for a step's output
function renderVariableExporter(cell, output, existingExports = []) {
    const exporter = cell.querySelector('.variable-exporter');
    const pathsList = cell.querySelector('.export-paths-list');
    const exportedSection = cell.querySelector('.exported-vars');
    const exportedList = cell.querySelector('.exported-vars-list');
    const saveBtn = cell.querySelector('.save-exports-btn');

    if (!exporter || !pathsList) return;

    // Check if output is valid JSON object
    if (!output || typeof output !== 'object' || Object.keys(output).length === 0) {
        exporter.style.display = 'none';
        return;
    }

    const stepId = cell.dataset.stepId;
    const stepName = cell.dataset.stepName;

    // Initialize pending exports from existing
    if (!pendingExports[stepId]) {
        pendingExports[stepId] = new Set(existingExports);
    }

    // Extract paths from output
    const paths = extractJsonPaths(output);

    if (paths.length === 0) {
        exporter.style.display = 'none';
        return;
    }

    exporter.style.display = 'block';
    pathsList.innerHTML = '';

    // Render each path
    paths.forEach(({ path, type, length, keys, sample }) => {
        const fullPath = `${stepName}.output.${path}`;
        const isExported = pendingExports[stepId].has(path);

        const item = document.createElement('div');
        item.className = 'export-path-item' + (isExported ? ' exported' : '');
        item.dataset.path = path;
        item.onclick = (e) => {
            e.stopPropagation();
            toggleExportPath(stepId, path, item);
        };

        let typeLabel = type;
        if (type === 'array') typeLabel = `array[${length}]`;
        else if (type === 'object') typeLabel = `object{${keys}}`;

        item.innerHTML = `
            <span class="export-check" style="${isExported ? '' : 'visibility: hidden'}">
                <i class="bi bi-check-lg"></i>
            </span>
            <span class="path-name" title="{${fullPath}}">${path}</span>
            <span class="path-type">${typeLabel}</span>
        `;

        pathsList.appendChild(item);
    });

    // Update exported chips display
    updateExportedChips(cell, stepId, stepName);
}

function toggleExportPath(stepId, path, itemEl) {
    if (!pendingExports[stepId]) {
        pendingExports[stepId] = new Set();
    }

    const checkEl = itemEl.querySelector('.export-check');

    if (pendingExports[stepId].has(path)) {
        pendingExports[stepId].delete(path);
        itemEl.classList.remove('exported');
        checkEl.style.visibility = 'hidden';
    } else {
        pendingExports[stepId].add(path);
        itemEl.classList.add('exported');
        checkEl.style.visibility = 'visible';
    }

    // Show save button and update chips
    const cell = itemEl.closest('.step-cell');
    const saveBtn = cell.querySelector('.save-exports-btn');
    if (saveBtn) {
        saveBtn.style.display = pendingExports[stepId].size > 0 ? '' : 'none';
    }

    updateExportedChips(cell, stepId, cell.dataset.stepName);
}

function updateExportedChips(cell, stepId, stepName) {
    const exportedSection = cell.querySelector('.exported-vars');
    const exportedList = cell.querySelector('.exported-vars-list');

    if (!exportedSection || !exportedList) return;

    const exports = pendingExports[stepId] || new Set();

    if (exports.size === 0) {
        exportedSection.style.display = 'none';
        return;
    }

    exportedSection.style.display = 'block';
    exportedList.innerHTML = '';

    exports.forEach(path => {
        const chip = document.createElement('span');
        chip.className = 'exported-var-chip';
        chip.innerHTML = `
            {${stepName}.output.${path}}
            <span class="remove-export" onclick="event.stopPropagation(); removeExport('${stepId}', '${path}', this);">
                <i class="bi bi-x"></i>
            </span>
        `;
        exportedList.appendChild(chip);
    });
}

function removeExport(stepId, path, chipRemoveEl) {
    if (pendingExports[stepId]) {
        pendingExports[stepId].delete(path);
    }

    const cell = chipRemoveEl.closest('.step-cell');
    const pathItem = cell.querySelector(`.export-path-item[data-path="${path}"]`);
    if (pathItem) {
        pathItem.classList.remove('exported');
        pathItem.querySelector('.export-check').style.visibility = 'hidden';
    }

    updateExportedChips(cell, stepId, cell.dataset.stepName);

    // Update save button visibility
    const saveBtn = cell.querySelector('.save-exports-btn');
    if (saveBtn) {
        saveBtn.style.display = (pendingExports[stepId]?.size || 0) > 0 ? '' : 'none';
    }
}

async function saveExportedVariables(saveBtn) {
    const cell = saveBtn.closest('.step-cell');
    const stepId = cell.dataset.stepId;
    const stepName = cell.dataset.stepName;

    const exports = Array.from(pendingExports[stepId] || []);

    // Build mappings object
    const mappings = {};
    exports.forEach(path => {
        // Create a simple alias (last part of path)
        const alias = path.replace(/\[\d+\]/g, '').split('.').pop();
        mappings[alias] = {
            path: path,
            fullPath: `${stepName}.output.${path}`
        };
    });

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm" style="width: 10px; height: 10px;"></span>';

    try {
        const response = await fetch('/pipelines/savestepmappings/' + pipelineId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: new URLSearchParams({
                csrf_token: csrfToken,
                step_id: stepId,
                mappings: JSON.stringify(mappings)
            })
        });

        const result = await response.json();

        if (result.success) {
            showToast('Variables exported!', 'success');
            saveBtn.innerHTML = '<i class="bi bi-check"></i> Saved';
            saveBtn.classList.remove('btn-outline-success');
            saveBtn.classList.add('btn-success');

            setTimeout(() => {
                saveBtn.innerHTML = '<i class="bi bi-check"></i> Save';
                saveBtn.classList.remove('btn-success');
                saveBtn.classList.add('btn-outline-success');
                saveBtn.disabled = false;
            }, 2000);
        } else {
            alert('Failed to save: ' + (result.error || 'Unknown error'));
            saveBtn.innerHTML = '<i class="bi bi-check"></i> Save';
            saveBtn.disabled = false;
        }
    } catch (err) {
        alert('Error: ' + err.message);
        saveBtn.innerHTML = '<i class="bi bi-check"></i> Save';
        saveBtn.disabled = false;
    }
}

// =========================================================================
// Modal Variable Exporter Functions
// =========================================================================

let modalExporterStepId = null;
let modalExporterStepName = null;

function showModalVariableExporter(stepId, stepName, output, existingMappings = [], isTestPreview = false) {
    const card = document.getElementById('variableExporterCard');
    const preview = document.getElementById('exporterOutputPreview');
    const pathsList = document.getElementById('modalExportPathsList');

    if (!card || !output || typeof output !== 'object') {
        hideModalVariableExporter();
        return;
    }

    modalExporterStepId = stepId;
    modalExporterStepName = stepName;

    // Update description based on whether this is test output or actual run output
    const descEl = card.querySelector('.card-body > p.text-muted');
    if (descEl) {
        if (isTestPreview) {
            descEl.innerHTML = '<i class="bi bi-lightning-fill text-warning me-1"></i><strong>Test Output Preview:</strong> These are the paths from your test expression. Save the step and re-run to update actual output.';
        } else {
            descEl.innerHTML = 'This step has output from the current run. Click paths to export them for subsequent steps:';
        }
    }

    // Always reinitialize pending exports from server data to ensure we show the latest saved mappings
    pendingExports[stepId] = new Set();
    // Load existing mappings from server
    if (existingMappings && typeof existingMappings === 'object') {
        Object.values(existingMappings).forEach(m => {
            if (m.path) pendingExports[stepId].add(m.path);
        });
    }

    // Show the output preview
    preview.textContent = JSON.stringify(output, null, 2);

    // Extract and render paths
    const paths = extractJsonPaths(output);
    pathsList.innerHTML = '';

    if (paths.length === 0) {
        pathsList.innerHTML = '<p class="text-muted small">No exportable paths found in output.</p>';
    } else {
        paths.forEach(({ path, type, length, keys, sample }) => {
            const isExported = pendingExports[stepId].has(path);

            const item = document.createElement('div');
            item.className = 'export-path-item' + (isExported ? ' exported' : '');
            item.dataset.path = path;
            item.onclick = () => toggleModalExportPath(stepId, path, item);

            let typeLabel = type;
            if (type === 'array') typeLabel = `array[${length}]`;
            else if (type === 'object') typeLabel = `object{${keys}}`;

            item.innerHTML = `
                <span class="export-check" style="${isExported ? '' : 'visibility: hidden'}">
                    <i class="bi bi-check-lg"></i>
                </span>
                <span class="path-name" title="{${stepName}.output.${path}}">${path}</span>
                <span class="path-type">${typeLabel}</span>
            `;

            pathsList.appendChild(item);
        });
    }

    // Update exported chips
    updateModalExportedChips();

    // Show the card
    card.style.display = 'block';
}

function hideModalVariableExporter() {
    const card = document.getElementById('variableExporterCard');
    if (card) {
        card.style.display = 'none';
    }
    modalExporterStepId = null;
    modalExporterStepName = null;
}

// =========================================================================
// Universal Input Preview (for ALL step types)
// =========================================================================

function showUniversalInputPreview() {
    const card = document.getElementById('universalInputPreview');
    if (!card) return;

    // Only show if we're in debug mode
    if (typeof activeRunId === 'undefined' || !activeRunId) {
        card.style.display = 'none';
        return;
    }

    card.style.display = 'block';
    refreshInputPreview();
}

function hideUniversalInputPreview() {
    const card = document.getElementById('universalInputPreview');
    if (card) {
        card.style.display = 'none';
    }
}

function refreshInputPreview() {
    const stepId = document.getElementById('stepId').value;
    const row = parseInt(document.getElementById('stepRow').value);
    const col = parseInt(document.getElementById('stepCol').value);
    const inputSource = document.getElementById('inputSource').value;

    const previewCard = document.getElementById('universalInputPreview');
    const contentEl = document.getElementById('inputPreviewContent');
    const sourceTextEl = document.getElementById('inputPreviewSourceText');
    const badgeEl = document.getElementById('inputPreviewBadge');

    if (!previewCard || !contentEl) return;

    // Only show if we're in debug mode
    if (typeof activeRunId === 'undefined' || !activeRunId) {
        previewCard.style.display = 'none';
        return;
    }

    previewCard.style.display = 'block';

    // Update badge based on input source
    if (badgeEl) {
        if (inputSource === 'stdin') {
            badgeEl.textContent = 'STDIN';
            badgeEl.className = 'badge bg-info ms-1';
        } else if (inputSource === 'context') {
            badgeEl.textContent = 'Context';
            badgeEl.className = 'badge bg-secondary ms-1';
        } else if (inputSource === 'getfrom') {
            badgeEl.textContent = 'Get From';
            badgeEl.className = 'badge bg-warning ms-1';
        }
    }

    console.log('refreshInputPreview called:', { stepId, row, col, inputSource });
    console.log('activeRunId:', activeRunId);
    console.log('stepOutputs:', stepOutputs);

    // Find the previous step based on input source
    let prevStepOutput = null;
    let prevStepName = null;
    let foundStepId = null;

    if (inputSource === 'stdin' || inputSource === 'previous' || inputSource === 'context') {
        // Find the step that would be "previous" based on flow
        const prevCol = col - 1;

        // First try the cell directly to the left
        if (prevCol >= 0) {
            const prevCell = document.querySelector(`td[data-row="${row}"][data-col="${prevCol}"] .step-cell`);
            if (prevCell) {
                foundStepId = prevCell.dataset.stepId;
                prevStepName = prevCell.dataset.stepName;
                console.log('Found prev cell:', { foundStepId, prevStepName });
            }
        }

        // If no cell to the left, look at the last cell of the previous row
        if (!foundStepId && row > 0) {
            const prevRowCells = document.querySelectorAll(`td[data-row="${row - 1}"] .step-cell`);
            if (prevRowCells.length > 0) {
                const lastCell = prevRowCells[prevRowCells.length - 1];
                foundStepId = lastCell.dataset.stepId;
                prevStepName = lastCell.dataset.stepName;
                console.log('Found prev row cell:', { foundStepId, prevStepName });
            }
        }

        // Check if we have output for this step
        if (foundStepId && stepOutputs[foundStepId]) {
            const outputData = stepOutputs[foundStepId];
            // Prefer 'output' object, fall back to 'stdout'
            if (outputData.output && Object.keys(outputData.output).length > 0) {
                prevStepOutput = outputData.output;
            } else if (outputData.stdout) {
                // Try to parse stdout as JSON
                try {
                    prevStepOutput = JSON.parse(outputData.stdout);
                } catch (e) {
                    prevStepOutput = outputData.stdout;  // Use raw string if not JSON
                }
            }
            console.log('Found output for step:', prevStepOutput);
        }
    } else if (inputSource === 'getfrom') {
        const getFromStep = document.querySelector('[name="input_getfrom_step"]').value;
        // Find the step by name
        const sourceCell = document.querySelector(`.step-cell[data-step-name="${getFromStep}"]`);
        if (sourceCell) {
            foundStepId = sourceCell.dataset.stepId;
            prevStepName = getFromStep;
            if (stepOutputs[foundStepId]) {
                const outputData = stepOutputs[foundStepId];
                if (outputData.output && Object.keys(outputData.output).length > 0) {
                    prevStepOutput = outputData.output;
                } else if (outputData.stdout) {
                    try {
                        prevStepOutput = JSON.parse(outputData.stdout);
                    } catch (e) {
                        prevStepOutput = outputData.stdout;
                    }
                }
            }
        }
    }

    if (prevStepOutput) {
        const outputStr = typeof prevStepOutput === 'string'
            ? prevStepOutput
            : JSON.stringify(prevStepOutput, null, 2);
        contentEl.textContent = outputStr;
        contentEl.classList.remove('text-muted');
        sourceTextEl.innerHTML = `<strong>Input from:</strong> <code>${prevStepName}</code> (${inputSource})`;
    } else {
        // Show what we found for debugging
        const availableKeys = Object.keys(stepOutputs || {});
        contentEl.textContent = `(Previous step has not run yet or has no output)\n\nDebug info:\n- Looking for step: ${prevStepName || 'unknown'}\n- Step ID: ${foundStepId || 'not found'}\n- Available outputs: ${availableKeys.join(', ') || 'none'}`;
        contentEl.classList.add('text-muted');
        sourceTextEl.textContent = `Input source: ${inputSource} (no data available yet)`;
    }
}

// Update input preview when input source changes
document.getElementById('inputSource')?.addEventListener('change', function() {
    refreshInputPreview();
});

function showSavedMappingsOnly(stepId, stepName, savedMappings) {
    const card = document.getElementById('variableExporterCard');
    const preview = document.getElementById('exporterOutputPreview');
    const pathsList = document.getElementById('modalExportPathsList');

    if (!card) return;

    modalExporterStepId = stepId;
    modalExporterStepName = stepName;

    // Initialize pending exports from saved mappings
    pendingExports[stepId] = new Set();
    if (savedMappings && typeof savedMappings === 'object') {
        Object.values(savedMappings).forEach(m => {
            if (m.path) pendingExports[stepId].add(m.path);
        });
    }

    // Show message instead of output preview
    preview.textContent = '(No output from current run - showing previously saved exports)';
    preview.style.color = '#6c757d';
    preview.style.fontStyle = 'italic';

    // Show saved paths as non-interactive items
    pathsList.innerHTML = '';
    if (pendingExports[stepId].size > 0) {
        pendingExports[stepId].forEach(path => {
            const item = document.createElement('div');
            item.className = 'export-path-item exported';
            item.innerHTML = `
                <span class="export-check">
                    <i class="bi bi-check-lg"></i>
                </span>
                <span class="path-name">{${stepName}.output.${path}}</span>
                <span class="path-type">saved</span>
            `;
            pathsList.appendChild(item);
        });
    } else {
        pathsList.innerHTML = '<p class="text-muted small mb-0">No exports saved for this step.</p>';
    }

    // Update exported chips
    updateModalExportedChips();

    // Show the card
    card.style.display = 'block';
}

function toggleModalExportPath(stepId, path, itemEl) {
    if (!pendingExports[stepId]) {
        pendingExports[stepId] = new Set();
    }

    const checkEl = itemEl.querySelector('.export-check');

    if (pendingExports[stepId].has(path)) {
        pendingExports[stepId].delete(path);
        itemEl.classList.remove('exported');
        checkEl.style.visibility = 'hidden';
    } else {
        pendingExports[stepId].add(path);
        itemEl.classList.add('exported');
        checkEl.style.visibility = 'visible';
    }

    updateModalExportedChips();
}

function updateModalExportedChips() {
    const exportedSection = document.getElementById('modalExportedVars');
    const exportedList = document.getElementById('modalExportedVarsList');
    const countBadge = document.getElementById('exportCount');

    if (!exportedSection || !exportedList || !modalExporterStepId) return;

    const exports = pendingExports[modalExporterStepId] || new Set();

    countBadge.textContent = exports.size;

    if (exports.size === 0) {
        exportedSection.style.display = 'none';
        return;
    }

    exportedSection.style.display = 'block';
    exportedList.innerHTML = '';

    exports.forEach(path => {
        const chip = document.createElement('span');
        chip.className = 'exported-var-chip';
        chip.innerHTML = `
            {${modalExporterStepName}.output.${path}}
            <span class="remove-export" onclick="removeModalExport('${path}')">
                <i class="bi bi-x"></i>
            </span>
        `;
        exportedList.appendChild(chip);
    });
}

function removeModalExport(path) {
    if (!modalExporterStepId || !pendingExports[modalExporterStepId]) return;

    pendingExports[modalExporterStepId].delete(path);

    const pathItem = document.querySelector(`#modalExportPathsList .export-path-item[data-path="${path}"]`);
    if (pathItem) {
        pathItem.classList.remove('exported');
        pathItem.querySelector('.export-check').style.visibility = 'hidden';
    }

    updateModalExportedChips();
}

async function saveExportedVariablesFromModal() {
    if (!modalExporterStepId || !modalExporterStepName) {
        alert('No step selected for export');
        return;
    }

    const exports = Array.from(pendingExports[modalExporterStepId] || []);
    const saveBtn = document.getElementById('saveExportsBtn');

    // Build mappings object
    const mappings = {};
    exports.forEach(path => {
        const alias = path.replace(/\[\d+\]/g, '').split('.').pop();
        mappings[alias] = {
            path: path,
            fullPath: `${modalExporterStepName}.output.${path}`
        };
    });

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';

    try {
        const response = await fetch('/pipelines/savestepmappings/' + pipelineId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: new URLSearchParams({
                csrf_token: csrfToken,
                step_id: modalExporterStepId,
                mappings: JSON.stringify(mappings)
            })
        });

        const result = await response.json();

        if (result.success) {
            showToast(`Exported ${exports.length} variable(s)!`, 'success');
            saveBtn.innerHTML = '<i class="bi bi-check-lg"></i> Saved!';
            saveBtn.classList.remove('btn-success');
            saveBtn.classList.add('btn-outline-success');

            setTimeout(() => {
                saveBtn.innerHTML = '<i class="bi bi-check-lg"></i> Save Exported Variables';
                saveBtn.classList.remove('btn-outline-success');
                saveBtn.classList.add('btn-success');
                saveBtn.disabled = false;
            }, 2000);
        } else {
            alert('Failed to save: ' + (result.error || 'Unknown error'));
            saveBtn.innerHTML = '<i class="bi bi-check-lg"></i> Save Exported Variables';
            saveBtn.disabled = false;
        }
    } catch (err) {
        alert('Error: ' + err.message);
        saveBtn.innerHTML = '<i class="bi bi-check-lg"></i> Save Exported Variables';
        saveBtn.disabled = false;
    }
}

function onRunCompleted(run) {
    // IMPORTANT: Set activeRunId to null FIRST to prevent any race conditions
    const completedRunId = activeRunId;
    activeRunId = null;
    stopRunPolling();

    // Update status bar to show completion
    const statusBar = document.getElementById('runStatusBar');
    if (statusBar) {
        statusBar.classList.remove('alert-info');
        statusBar.classList.add('alert-success');
    }

    const statusLabel = document.getElementById('runStatusLabel');
    if (statusLabel) {
        statusLabel.textContent = 'Completed!';
    }

    const spinner = document.getElementById('runStatusSpinner');
    if (spinner) {
        spinner.style.display = 'none';
    }

    const cancelBtn = document.getElementById('cancelRunBtn');
    if (cancelBtn) {
        cancelBtn.style.display = 'none';
    }

    // Hide the debugger toolbar since the run is done
    const debuggerToolbar = document.getElementById('debuggerToolbar');
    if (debuggerToolbar) {
        debuggerToolbar.style.display = 'none';
    }

    // Expand all output sections
    document.querySelectorAll('.step-output-content').forEach(content => {
        content.classList.remove('collapsed');
    });
    document.querySelectorAll('.step-output-toggle i').forEach(icon => {
        icon.classList.remove('bi-chevron-right');
        icon.classList.add('bi-chevron-down');
    });

    showToast('Pipeline completed successfully!', 'success');

    // Show completion options in the status bar instead of a popup
    if (statusBar) {
        const existingActions = statusBar.querySelector('.completion-actions');
        if (existingActions) existingActions.remove();

        const actionsDiv = document.createElement('div');
        actionsDiv.className = 'completion-actions mt-2';
        actionsDiv.innerHTML = `
            <a href="/pipelines/viewrun/${completedRunId}" class="btn btn-sm btn-success me-2">
                <i class="bi bi-eye"></i> View Full Details
            </a>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="location.reload()">
                <i class="bi bi-arrow-clockwise"></i> Start New Debug Run
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="showRunProgressBar(false)">
                <i class="bi bi-x"></i> Close
            </button>
        `;
        statusBar.appendChild(actionsDiv);
    }
}

function onRunFailed(run, stepRuns) {
    showRunProgressBar(false);

    // Find the failed step
    const failedStep = stepRuns.find(sr => sr.status === 'failed');

    // Show error modal
    showRunErrorModal(run, failedStep);

    activeRunId = null;
}

function showRunErrorModal(run, failedStep) {
    // Create error modal if it doesn't exist
    let modal = document.getElementById('runErrorModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'runErrorModal';
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.innerHTML = `
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Pipeline Failed</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" id="runErrorModalBody">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <a href="#" class="btn btn-primary" id="runErrorViewDetails">
                            <i class="bi bi-eye"></i> View Full Details
                        </a>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    // Populate error details
    const body = document.getElementById('runErrorModalBody');
    body.innerHTML = `
        <div class="alert alert-danger mb-3">
            <strong>Error:</strong> ${run.error_message || 'Unknown error'}
        </div>
        ${failedStep ? `
        <h6>Failed Step: <code>${failedStep.step_name}</code></h6>
        <dl class="row mb-0">
            <dt class="col-sm-3">Position</dt>
            <dd class="col-sm-9">Row ${failedStep.row + 1}, Column ${failedStep.col + 1}</dd>
            ${failedStep.exit_code !== null ? `
            <dt class="col-sm-3">Exit Code</dt>
            <dd class="col-sm-9"><code>${failedStep.exit_code}</code></dd>
            ` : ''}
        </dl>
        ${failedStep.error_message ? `
        <div class="mt-3">
            <strong>Step Error:</strong>
            <pre class="bg-dark text-danger p-2 rounded mt-1" style="max-height: 200px; overflow: auto;">${failedStep.error_message}</pre>
        </div>
        ` : ''}
        ${failedStep.stderr ? `
        <div class="mt-3">
            <strong>STDERR:</strong>
            <pre class="bg-dark text-warning p-2 rounded mt-1" style="max-height: 200px; overflow: auto;">${failedStep.stderr}</pre>
        </div>
        ` : ''}
        ` : ''}
    `;

    document.getElementById('runErrorViewDetails').href = '/pipelines/viewrun/' + run.id;

    new bootstrap.Modal(modal).show();
}

function onRunCancelled() {
    showRunProgressBar(false);
    clearStepStates();
    showToast('Run cancelled', 'info');
    activeRunId = null;
}

async function continueInteractiveRun() {
    if (!activeRunId) {
        showToast('No active run to continue', 'info');
        return;
    }

    const continueBtn = document.getElementById('continueRunBtn');
    if (continueBtn) {
        continueBtn.disabled = true;
        continueBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Continuing...';
    }

    try {
        const response = await fetch('/pipelines/resumerun/' + activeRunId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'csrf_token=' + encodeURIComponent(csrfToken)
        });

        const result = await response.json();

        if (result.success) {
            showToast('Pipeline continuing...', 'success');
            // Hide continue button, show spinner
            if (continueBtn) {
                continueBtn.style.display = 'none';
            }
            const spinner = document.getElementById('runStatusSpinner');
            if (spinner) spinner.style.display = '';

            // Resume polling
            startRunPolling();
        } else {
            alert('Failed to continue: ' + (result.error || result.message || 'Unknown error'));
        }
    } catch (err) {
        alert('Error continuing run: ' + err.message);
    } finally {
        if (continueBtn) {
            continueBtn.disabled = false;
            continueBtn.innerHTML = '<i class="bi bi-play-fill"></i> Continue';
        }
    }
}

async function cancelInteractiveRun() {
    if (!activeRunId) {
        showToast('No active run to cancel', 'info');
        return;
    }

    if (!confirm('Cancel the current run?')) return;

    const cancelBtn = document.getElementById('cancelRunBtn');
    if (cancelBtn) {
        cancelBtn.disabled = true;
        cancelBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    }

    try {
        const response = await fetch('/pipelines/cancelrun/' + activeRunId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&run_id=' + activeRunId
        });

        const result = await response.json();

        if (result.success) {
            stopRunPolling();
            onRunCancelled();
        } else {
            alert('Failed to cancel: ' + (result.error || result.message || 'Unknown error'));
        }
    } catch (err) {
        alert('Error cancelling run: ' + err.message);
    } finally {
        if (cancelBtn) {
            cancelBtn.disabled = false;
            cancelBtn.innerHTML = '<i class="bi bi-x-circle"></i> Cancel';
        }
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
