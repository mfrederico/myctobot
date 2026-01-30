<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="/pipelines">Pipelines</a></li>
                    <li class="breadcrumb-item"><a href="/pipelines/edit/<?= $pipeline['id'] ?>"><?= htmlspecialchars($pipeline['name']) ?></a></li>
                    <li class="breadcrumb-item"><a href="/pipelines/runs/<?= $pipeline['id'] ?>">Runs</a></li>
                    <li class="breadcrumb-item active"><?= htmlspecialchars(substr($run['run_uid'], 0, 12)) ?>...</li>
                </ol>
            </nav>
            <h1 class="h2 mb-0">
                <?php
                $statusIcons = [
                    'pending' => 'bi-hourglass text-secondary',
                    'running' => 'bi-play-circle text-primary',
                    'completed' => 'bi-check-circle text-success',
                    'failed' => 'bi-x-circle text-danger',
                    'paused' => 'bi-pause-circle text-warning',
                    'cancelled' => 'bi-slash-circle text-dark',
                    'awaiting_input' => 'bi-input-cursor-text text-info'
                ];
                $icon = $statusIcons[$run['status']] ?? 'bi-question-circle';
                ?>
                <i class="bi <?= $icon ?>"></i>
                Run: <code><?= htmlspecialchars($run['run_uid']) ?></code>
            </h1>
        </div>
        <div>
            <?php if (in_array($run['status'], ['pending', 'running'])): ?>
            <button class="btn btn-outline-danger" onclick="cancelRun()">
                <i class="bi bi-x-lg"></i> Cancel
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Run Status Panel -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-info-circle"></i> Run Status
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="text-muted small">Status</div>
                            <?php
                            $statusColors = [
                                'pending' => 'secondary',
                                'running' => 'primary',
                                'completed' => 'success',
                                'failed' => 'danger',
                                'paused' => 'warning',
                                'cancelled' => 'dark',
                                'awaiting_input' => 'info'
                            ];
                            $color = $statusColors[$run['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?= $color ?> fs-6">
                                <i class="bi <?= $icon ?>"></i>
                                <?= ucfirst($run['status']) ?>
                            </span>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted small">Progress</div>
                            <div class="progress mt-1" style="height: 24px;">
                                <div class="progress-bar bg-<?= $color ?>" style="width: <?= $run['progress_percent'] ?>%">
                                    <?= $run['steps_completed'] ?>/<?= $run['steps_total'] ?> steps (<?= $run['progress_percent'] ?>%)
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted small">Started</div>
                            <div><?= $run['started_at'] ? date('M j, g:i:s A', strtotime($run['started_at'])) : 'Not started' ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted small">Duration</div>
                            <div id="duration">
                                <?php
                                if ($run['started_at'] && $run['completed_at']) {
                                    $start = strtotime($run['started_at']);
                                    $end = strtotime($run['completed_at']);
                                    $duration = $end - $start;
                                    if ($duration < 60) {
                                        echo $duration . ' seconds';
                                    } elseif ($duration < 3600) {
                                        echo floor($duration / 60) . 'm ' . ($duration % 60) . 's';
                                    } else {
                                        echo floor($duration / 3600) . 'h ' . floor(($duration % 3600) / 60) . 'm';
                                    }
                                } elseif ($run['started_at']) {
                                    echo '<span class="text-primary"><i class="bi bi-clock"></i> Running...</span>';
                                } else {
                                    echo '-';
                                }
                                ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($run['handoff_run_id'])): ?>
                    <div class="alert alert-info mt-3 mb-0 d-flex justify-content-between align-items-center" id="handoffAlert">
                        <div>
                            <i class="bi bi-arrow-right-square"></i>
                            <strong>Handed Off:</strong> <?= htmlspecialchars($run['error_message'] ?? 'Pipeline handoff') ?>
                            <span id="redirectNotice" class="ms-2 text-primary small" style="display: none;">
                                <i class="bi bi-hourglass-split"></i> Redirecting...
                            </span>
                        </div>
                        <a href="/pipelines/viewrun/<?= (int) $run['handoff_run_id'] ?>" class="btn btn-primary btn-sm" id="handoffLink">
                            <i class="bi bi-arrow-right"></i> View Handoff Run
                        </a>
                    </div>
                    <script>
                    // Auto-redirect if "Follow Handoffs" is enabled
                    (function() {
                        const followHandoffs = localStorage.getItem('pipeline_follow_handoffs') === 'true';
                        if (followHandoffs) {
                            document.getElementById('redirectNotice').style.display = 'inline';
                            setTimeout(function() {
                                window.location.href = '/pipelines/viewrun/<?= (int) $run['handoff_run_id'] ?>';
                            }, 800);
                        }
                    })();
                    </script>
                    <?php elseif ($run['error_message']): ?>
                    <div class="alert alert-danger mt-3 mb-0">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Error:</strong> <?= htmlspecialchars($run['error_message']) ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($run['current_step_name'] && $run['status'] === 'running'): ?>
                    <div class="alert alert-info mt-3 mb-0">
                        <i class="bi bi-arrow-right-circle"></i>
                        <strong>Current Step:</strong> <?= htmlspecialchars($run['current_step_name']) ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($run['status'] === 'awaiting_input' && !empty($awaitingStep)): ?>
                    <div class="alert alert-info mt-3 mb-0">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="alert-heading">
                                    <i class="bi bi-input-cursor-text"></i> Awaiting Input
                                </h6>
                                <p class="mb-2"><?= nl2br(htmlspecialchars($awaitingStep['prompt'] ?? 'Waiting for external input')) ?></p>
                                <small class="text-muted">
                                    Step: <?= htmlspecialchars($awaitingStep['step_name'] ?? '') ?>
                                    <?php if (!empty($awaitingStep['timeout_at'])): ?>
                                    | Expires: <?= date('M j, g:i A', strtotime($awaitingStep['timeout_at'])) ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div class="text-end">
                                <?php if (!empty($awaitingStep['form_url'])): ?>
                                <a href="<?= htmlspecialchars($awaitingStep['form_url']) ?>" target="_blank" class="btn btn-primary btn-sm mb-1">
                                    <i class="bi bi-box-arrow-up-right"></i> Open Form
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($awaitingStep['webhook_url'])): ?>
                        <hr class="my-2">
                        <div class="small">
                            <strong>Webhook URL:</strong>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control font-monospace" value="<?= htmlspecialchars($awaitingStep['webhook_url']) ?>" readonly id="webhookUrl">
                                <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('webhookUrl').value)">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($awaitingStep['schema'])): ?>
                        <details class="mt-2">
                            <summary class="small text-muted">Expected Input Schema</summary>
                            <pre class="small bg-light p-2 mt-1 mb-0 rounded"><?= htmlspecialchars(json_encode($awaitingStep['schema'], JSON_PRETTY_PRINT)) ?></pre>
                        </details>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-braces"></i> Trigger Data
                </div>
                <div class="card-body">
                    <pre class="mb-0 small" style="max-height: 150px; overflow: auto;"><?= htmlspecialchars(json_encode($run['trigger_data'], JSON_PRETTY_PRINT)) ?></pre>
                </div>
            </div>
        </div>
    </div>

    <!-- Execution Grid -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-grid-3x3"></i> Execution Grid
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0" id="executionGrid">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 60px;" class="text-center">#</th>
                            <?php foreach ($pipeline['columns'] as $colIndex => $colName): ?>
                            <th class="text-center" style="min-width: 200px;">
                                <?= htmlspecialchars($colName) ?>
                            </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $maxRow = 0;
                        foreach ($stepRunGrid as $row => $cols) {
                            $maxRow = max($maxRow, $row);
                        }
                        ?>
                        <?php for ($row = 0; $row <= $maxRow; $row++): ?>
                        <tr data-row="<?= $row ?>">
                            <td class="text-center text-muted align-middle"><?= $row + 1 ?></td>
                            <?php foreach ($pipeline['columns'] as $colIndex => $colName): ?>
                            <td class="p-2" data-col="<?= $colIndex ?>">
                                <?php if (isset($stepRunGrid[$row][$colIndex])): ?>
                                    <?php
                                    $stepRun = $stepRunGrid[$row][$colIndex];
                                    $stepStatusColors = [
                                        'pending' => 'secondary',
                                        'running' => 'primary',
                                        'success' => 'success',
                                        'failure' => 'danger',
                                        'fault' => 'warning',
                                        'skipped' => 'dark',
                                        'cancelled' => 'dark'
                                    ];
                                    $stepColor = $stepStatusColors[$stepRun['status']] ?? 'secondary';
                                    $stepStatusIcons = [
                                        'pending' => 'bi-hourglass',
                                        'running' => 'bi-play-circle',
                                        'success' => 'bi-check-circle',
                                        'failure' => 'bi-x-circle',
                                        'fault' => 'bi-exclamation-triangle',
                                        'skipped' => 'bi-skip-forward',
                                        'cancelled' => 'bi-slash-circle'
                                    ];
                                    $stepIcon = $stepStatusIcons[$stepRun['status']] ?? 'bi-question-circle';
                                    ?>
                                    <div class="step-run-cell bg-<?= $stepColor ?>-subtle border border-<?= $stepColor ?> rounded p-2"
                                         data-step-run-id="<?= $stepRun['id'] ?>"
                                         onclick="showStepDetail(<?= $stepRun['id'] ?>)">
                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                            <strong class="small"><?= htmlspecialchars($stepRun['step_name']) ?></strong>
                                            <span class="badge bg-<?= $stepColor ?>">
                                                <i class="bi <?= $stepIcon ?>"></i>
                                            </span>
                                        </div>
                                        <?php if ($stepRun['duration_ms'] > 0): ?>
                                        <div class="small text-muted">
                                            <?= number_format($stepRun['duration_ms'] / 1000, 1) ?>s
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($stepRun['fault_count'] > 0): ?>
                                        <div class="small text-warning">
                                            <i class="bi bi-arrow-repeat"></i> <?= $stepRun['fault_count'] ?> retries
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($stepRun['error_message']): ?>
                                        <div class="small text-danger text-truncate" style="max-width: 180px;">
                                            <?= htmlspecialchars($stepRun['error_message']) ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center text-muted p-3">
                                        <i class="bi bi-dash"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Step Detail Modal -->
<div class="modal fade" id="stepDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-info-circle"></i> Step Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="stepDetailContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary"></div>
                        <p class="mt-2">Loading...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Output Mapping Modal (Interactive Mode) -->
<div class="modal fade" id="mappingModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-warning-subtle">
                <h5 class="modal-title">
                    <i class="bi bi-pause-circle"></i>
                    Pipeline Paused - Map Output Fields
                </h5>
            </div>
            <div class="modal-body">
                <div class="row">
                    <!-- Left: Step Output (JSON Tree) -->
                    <div class="col-md-6">
                        <h6><i class="bi bi-braces"></i> Step Output</h6>
                        <p class="small text-muted">Click a field to add it to mappings</p>
                        <div id="jsonTreeContainer" class="border rounded p-2 bg-light" style="max-height: 400px; overflow: auto;">
                            <div class="text-center py-4">
                                <div class="spinner-border spinner-border-sm text-primary"></div>
                                <span class="ms-2">Loading output...</span>
                            </div>
                        </div>
                    </div>
                    <!-- Right: Mappings -->
                    <div class="col-md-6">
                        <h6><i class="bi bi-arrow-right-circle"></i> Field Mappings</h6>
                        <p class="small text-muted">Map output fields to context variables for the next step</p>

                        <div id="mappingsList" class="mb-3">
                            <!-- Dynamically added mappings -->
                        </div>

                        <button type="button" class="btn btn-sm btn-outline-primary mb-3" onclick="addMappingRow()">
                            <i class="bi bi-plus-lg"></i> Add Mapping
                        </button>

                        <div class="border-top pt-3">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="passthroughCheck">
                                <label class="form-check-label" for="passthroughCheck">
                                    <strong>Passthrough</strong> - Merge entire output into context
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="saveMappingsCheck">
                                <label class="form-check-label" for="saveMappingsCheck">
                                    <strong>Save mappings</strong> - Auto-apply these mappings in future runs
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cancelMapping()">
                    <i class="bi bi-x-lg"></i> Cancel Run
                </button>
                <button type="button" class="btn btn-primary" onclick="submitMappings()">
                    <i class="bi bi-play-fill"></i> Continue Pipeline
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.step-run-cell {
    cursor: pointer;
    transition: all 0.2s;
    min-height: 60px;
}
.step-run-cell:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

/* JSON Tree Styles */
.json-tree {
    font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
    font-size: 13px;
    line-height: 1.5;
}
.json-tree ul {
    list-style: none;
    padding-left: 1.5em;
    margin: 0;
}
.json-tree > ul {
    padding-left: 0;
}
.json-key {
    color: #881391;
    font-weight: 500;
}
.json-value {
    color: #0b7285;
}
.json-value.string { color: #0a6640; }
.json-value.number { color: #d9480f; }
.json-value.boolean { color: #5c7cfa; }
.json-value.null { color: #868e96; }
.json-clickable {
    cursor: pointer;
    padding: 2px 4px;
    border-radius: 3px;
    transition: background 0.2s;
}
.json-clickable:hover {
    background: #e8f4f8;
}
.json-toggle {
    cursor: pointer;
    user-select: none;
    display: inline-block;
    width: 16px;
    text-align: center;
    color: #868e96;
}
.json-toggle:hover {
    color: #228be6;
}

/* Mapping row */
.mapping-row {
    display: flex;
    gap: 8px;
    margin-bottom: 8px;
    align-items: center;
}
.mapping-row input {
    flex: 1;
}
.mapping-row .source-field {
    background: #e8f4f8;
}
</style>

<script>
const csrfToken = '<?= Flight::csrf()->getToken() ?>';
const runId = <?= $run['id'] ?>;
let currentRunStatus = '<?= $run['status'] ?>';
let pollInterval = null;
let mappingModalInstance = null;
let currentPausedStepOutput = null;

// Poll for updates if running or paused
if (currentRunStatus === 'running' || currentRunStatus === 'pending' || currentRunStatus === 'paused') {
    // Check immediately for paused state
    if (currentRunStatus === 'paused') {
        checkInteractiveStatus();
    }
    pollInterval = setInterval(pollStatus, 3000);
}

async function pollStatus() {
    try {
        // Use interactivestatus endpoint for better pause detection
        const response = await fetch('/pipelines/interactivestatus/' + runId);
        const data = await response.json();

        if (data.success) {
            const run = data.data.run;

            // Handle paused state - show mapping modal
            if (run.status === 'paused' && data.data.paused_at_step) {
                clearInterval(pollInterval);
                showMappingModal(data.data.paused_at_step, data.data.step_output, data.data.saved_mappings);
                return;
            }

            // Update status if changed
            if (run.status !== currentRunStatus) {
                if (['completed', 'failed', 'cancelled'].includes(run.status)) {
                    clearInterval(pollInterval);
                    location.reload();
                    return;
                }
                currentRunStatus = run.status;
            }

            // Update progress
            const progressBar = document.querySelector('.progress-bar');
            if (progressBar) {
                progressBar.style.width = run.progress_percent + '%';
                progressBar.textContent = run.steps_completed + '/' + run.steps_total + ' steps (' + run.progress_percent + '%)';
            }

            // Update step statuses
            const steps = data.data.steps;
            for (const [stepName, stepData] of Object.entries(steps)) {
                const cell = document.querySelector(`[data-row="${stepData.row}"] [data-col="${stepData.col}"] .step-run-cell`);
                if (cell) {
                    // Update status indicator
                    const badge = cell.querySelector('.badge');
                    if (badge) {
                        const statusClasses = {
                            pending: 'bg-secondary',
                            running: 'bg-primary',
                            success: 'bg-success',
                            failure: 'bg-danger',
                            fault: 'bg-warning',
                            skipped: 'bg-dark',
                            cancelled: 'bg-dark'
                        };
                        const statusIcons = {
                            pending: 'bi-hourglass',
                            running: 'bi-play-circle',
                            success: 'bi-check-circle',
                            failure: 'bi-x-circle',
                            fault: 'bi-exclamation-triangle',
                            skipped: 'bi-skip-forward',
                            cancelled: 'bi-slash-circle'
                        };
                        badge.className = 'badge ' + (statusClasses[stepData.status] || 'bg-secondary');
                        badge.innerHTML = '<i class="bi ' + (statusIcons[stepData.status] || 'bi-question-circle') + '"></i>';
                    }
                }
            }

            // Stop polling if complete
            if (['completed', 'failed', 'cancelled'].includes(run.status)) {
                clearInterval(pollInterval);
                location.reload();
            }
        }
    } catch (err) {
        console.error('Poll error:', err);
    }
}

// Check for interactive status on page load (for paused runs)
async function checkInteractiveStatus() {
    try {
        const response = await fetch('/pipelines/interactivestatus/' + runId);
        const data = await response.json();

        if (data.success && data.data.run.status === 'paused' && data.data.paused_at_step) {
            showMappingModal(data.data.paused_at_step, data.data.step_output, data.data.saved_mappings);
        }
    } catch (err) {
        console.error('Check interactive status error:', err);
    }
}

function showMappingModal(pausedStep, stepOutput, savedMappings) {
    currentPausedStepOutput = stepOutput;

    // Update modal title with step name
    const modalTitle = document.querySelector('#mappingModal .modal-title');
    modalTitle.innerHTML = `<i class="bi bi-pause-circle"></i> Paused at: <code>${escapeHtml(pausedStep.step_name)}</code> - Map Output Fields`;

    // Render JSON tree
    const treeContainer = document.getElementById('jsonTreeContainer');
    treeContainer.innerHTML = renderJsonTree(stepOutput, '');

    // Clear and populate mappings list
    const mappingsList = document.getElementById('mappingsList');
    mappingsList.innerHTML = '';

    if (savedMappings && savedMappings.length > 0) {
        savedMappings.forEach(m => addMappingRow(m.source, m.target));
    }

    // Show modal
    mappingModalInstance = new bootstrap.Modal(document.getElementById('mappingModal'));
    mappingModalInstance.show();
}

function renderJsonTree(obj, path, depth = 0) {
    if (obj === null) {
        return `<span class="json-value null">null</span>`;
    }
    if (typeof obj === 'boolean') {
        return `<span class="json-value boolean">${obj}</span>`;
    }
    if (typeof obj === 'number') {
        return `<span class="json-value number">${obj}</span>`;
    }
    if (typeof obj === 'string') {
        // Truncate long strings
        const display = obj.length > 100 ? obj.substring(0, 100) + '...' : obj;
        return `<span class="json-value string json-clickable" onclick="selectJsonPath('${escapeAttr(path)}')" title="Click to map this field">"${escapeHtml(display)}"</span>`;
    }
    if (Array.isArray(obj)) {
        if (obj.length === 0) return '<span class="json-value">[]</span>';

        let html = '<ul>';
        obj.forEach((item, index) => {
            const itemPath = path ? `${path}.${index}` : `${index}`;
            html += `<li>
                <span class="json-toggle" onclick="toggleJsonNode(this)">▼</span>
                <span class="json-key">[${index}]</span>: ${renderJsonTree(item, itemPath, depth + 1)}
            </li>`;
        });
        html += '</ul>';
        return html;
    }
    if (typeof obj === 'object') {
        const keys = Object.keys(obj);
        if (keys.length === 0) return '<span class="json-value">{}</span>';

        let html = '<ul>';
        keys.forEach(key => {
            const keyPath = path ? `${path}.${key}` : key;
            const value = obj[key];
            const isExpandable = typeof value === 'object' && value !== null;

            html += `<li>`;
            if (isExpandable) {
                html += `<span class="json-toggle" onclick="toggleJsonNode(this)">▼</span>`;
            } else {
                html += `<span class="json-toggle"></span>`;
            }
            html += `<span class="json-key json-clickable" onclick="selectJsonPath('${escapeAttr(keyPath)}')" title="Click to map: ${escapeAttr(keyPath)}">"${escapeHtml(key)}"</span>: `;
            html += renderJsonTree(value, keyPath, depth + 1);
            html += `</li>`;
        });
        html += '</ul>';
        return `<div class="json-tree">${html}</div>`;
    }
    return String(obj);
}

function toggleJsonNode(toggle) {
    const li = toggle.parentElement;
    const ul = li.querySelector('ul');
    if (ul) {
        if (ul.style.display === 'none') {
            ul.style.display = '';
            toggle.textContent = '▼';
        } else {
            ul.style.display = 'none';
            toggle.textContent = '▶';
        }
    }
}

function selectJsonPath(path) {
    addMappingRow(path, '');
}

function addMappingRow(source = '', target = '') {
    const mappingsList = document.getElementById('mappingsList');

    const row = document.createElement('div');
    row.className = 'mapping-row';
    row.innerHTML = `
        <input type="text" class="form-control form-control-sm source-field" placeholder="source.path" value="${escapeAttr(source)}" readonly>
        <i class="bi bi-arrow-right text-muted"></i>
        <input type="text" class="form-control form-control-sm target-field" placeholder="variable_name" value="${escapeAttr(target)}">
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.parentElement.remove()">
            <i class="bi bi-trash"></i>
        </button>
    `;

    mappingsList.appendChild(row);

    // Focus the target input
    row.querySelector('.target-field').focus();
}

function getMappings() {
    const mappings = [];
    document.querySelectorAll('.mapping-row').forEach(row => {
        const source = row.querySelector('.source-field').value.trim();
        const target = row.querySelector('.target-field').value.trim();
        if (source && target) {
            mappings.push({ source, target });
        }
    });
    return mappings;
}

async function submitMappings() {
    const mappings = getMappings();
    const passthrough = document.getElementById('passthroughCheck').checked;
    const saveMappings = document.getElementById('saveMappingsCheck').checked;

    try {
        const response = await fetch('/pipelines/submitmappings/' + runId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: new URLSearchParams({
                csrf_token: csrfToken,
                mappings: JSON.stringify(mappings),
                passthrough: passthrough ? '1' : '0',
                save_mappings: saveMappings ? '1' : '0'
            })
        });

        const result = await response.json();

        if (result.success) {
            // Close modal and resume polling
            if (mappingModalInstance) {
                mappingModalInstance.hide();
            }

            // Reload page to see updated state
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Failed to submit mappings'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

async function cancelMapping() {
    if (!confirm('Are you sure you want to cancel this run?')) {
        return;
    }

    await cancelRun();

    if (mappingModalInstance) {
        mappingModalInstance.hide();
    }
}

function showStepDetail(stepRunId) {
    const modal = new bootstrap.Modal(document.getElementById('stepDetailModal'));
    const content = document.getElementById('stepDetailContent');

    // Find the step data from the page
    const stepCell = document.querySelector(`[data-step-run-id="${stepRunId}"]`);
    if (!stepCell) {
        content.innerHTML = '<div class="alert alert-danger">Step not found</div>';
        modal.show();
        return;
    }

    // Get step data from PHP-rendered data
    <?php
    $stepRunsJson = [];
    foreach ($stepRunGrid as $row => $cols) {
        foreach ($cols as $col => $sr) {
            $stepRunsJson[$sr['id']] = $sr;
        }
    }
    ?>
    const stepRuns = <?= json_encode($stepRunsJson) ?>;
    const step = stepRuns[stepRunId];

    if (!step) {
        content.innerHTML = '<div class="alert alert-danger">Step data not found</div>';
        modal.show();
        return;
    }

    let html = `
        <div class="row mb-3">
            <div class="col-md-6">
                <strong>Step Name:</strong> <code>${escapeHtml(step.step_name)}</code>
            </div>
            <div class="col-md-6">
                <strong>Status:</strong> <span class="badge bg-${getStatusColor(step.status)}">${step.status}</span>
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-md-4">
                <strong>Duration:</strong> ${step.duration_ms > 0 ? (step.duration_ms / 1000).toFixed(2) + 's' : '-'}
            </div>
            <div class="col-md-4">
                <strong>Attempt:</strong> ${step.attempt_number}
            </div>
            <div class="col-md-4">
                <strong>Faults:</strong> ${step.fault_count}
            </div>
        </div>
    `;

    if (step.error_message) {
        html += `
            <div class="alert alert-danger">
                <strong>Error:</strong> ${escapeHtml(step.error_message)}
            </div>
        `;

        // Show input when there's an error (helpful for debugging jq, parsers, etc.)
        if (step.input && Object.keys(step.input).length > 0) {
            // Check if input has stdin (raw input) or is an object
            const inputDisplay = step.input.stdin
                ? step.input.stdin
                : JSON.stringify(step.input, null, 2);
            html += `
                <div class="mb-3">
                    <strong>Input (STDIN):</strong>
                    <pre class="bg-warning-subtle text-dark p-2 rounded" style="max-height: 200px; overflow: auto;">${escapeHtml(inputDisplay)}</pre>
                </div>
            `;
        }
    }

    if (step.exit_code !== null) {
        html += `
            <div class="mb-3">
                <strong>Exit Code:</strong> <code>${step.exit_code}</code>
            </div>
        `;
    }

    if (step.stdout) {
        html += `
            <div class="mb-3">
                <strong>STDOUT:</strong>
                <pre class="bg-dark text-light p-2 rounded" style="max-height: 200px; overflow: auto;">${escapeHtml(step.stdout)}</pre>
            </div>
        `;
    }

    if (step.stderr) {
        html += `
            <div class="mb-3">
                <strong>STDERR:</strong>
                <pre class="bg-dark text-danger p-2 rounded" style="max-height: 200px; overflow: auto;">${escapeHtml(step.stderr)}</pre>
            </div>
        `;
    }

    if (step.output && Object.keys(step.output).length > 0) {
        html += `
            <div class="mb-3">
                <strong>Output:</strong>
                <pre class="bg-light p-2 rounded" style="max-height: 200px; overflow: auto;">${escapeHtml(JSON.stringify(step.output, null, 2))}</pre>
            </div>
        `;
    }

    content.innerHTML = html;
    modal.show();
}

function getStatusColor(status) {
    const colors = {
        pending: 'secondary',
        running: 'primary',
        success: 'success',
        failure: 'danger',
        fault: 'warning',
        skipped: 'dark',
        cancelled: 'dark'
    };
    return colors[status] || 'secondary';
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function escapeAttr(text) {
    if (!text) return '';
    return text.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

async function cancelRun() {
    if (!confirm('Are you sure you want to cancel this run?')) {
        return;
    }

    try {
        const response = await fetch('/pipelines/cancelrun/' + runId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'csrf_token=' + encodeURIComponent(csrfToken)
        });

        const result = await response.json();

        if (result.success) {
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Failed to cancel run'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}
</script>
