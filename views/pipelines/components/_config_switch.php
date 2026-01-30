<?php
/**
 * @step_type: switch
 * @category: flow
 * @label: Switch (Multi-Branch)
 * @icon: bi-diagram-3
 * @color: warning
 * @description: Evaluate a value against multiple cases and branch accordingly. Like a switch/case statement.
 */
?>
<div class="config-panel" id="config_switch" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <h6 class="text-muted mb-3"><i class="bi bi-diagram-3"></i> Switch Configuration</h6>

            <div class="alert alert-info small mb-3">
                <i class="bi bi-info-circle"></i>
                <strong>How it works:</strong> Evaluates a value and jumps to the matching case's step.
                Like a switch/case statement - first matching case wins.
            </div>

            <!-- Value to Switch On -->
            <div class="mb-3">
                <label class="form-label">Switch Value</label>
                <input type="text" class="form-control font-monospace" name="config_switch_value"
                       placeholder="{prev.output.status}" onchange="updateSwitchPreview()">
                <div class="form-text">The value to match against cases (supports variable substitution)</div>
            </div>

            <!-- Cases -->
            <div class="card bg-white mb-3">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <strong>Cases</strong>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addSwitchCase()">
                        <i class="bi bi-plus"></i> Add Case
                    </button>
                </div>
                <div class="card-body" id="switchCasesContainer">
                    <!-- Cases will be added here dynamically -->
                </div>
            </div>

            <!-- Default Case -->
            <div class="card border-secondary">
                <div class="card-header py-2 bg-secondary bg-opacity-10">
                    <strong><i class="bi bi-arrow-return-right"></i> Default</strong>
                    <small class="text-muted">- No case matches</small>
                </div>
                <div class="card-body">
                    <label class="form-label small">Go to step:</label>
                    <div class="input-group">
                        <span class="input-group-text">goto:</span>
                        <input type="text" class="form-control" name="config_switch_default"
                               placeholder="step_name" onchange="updateSwitchPreview()">
                    </div>
                    <div class="form-text">Leave empty to continue to next step</div>
                </div>
            </div>

            <!-- Preview -->
            <div class="alert alert-secondary mt-3 mb-0 py-2">
                <code id="switchPreview" class="d-block small">SWITCH {value} CASE ... DEFAULT ...</code>
            </div>
        </div>
    </div>
</div>

<script>
let switchCaseCount = 0;

function addSwitchCase(value = '', goto = '') {
    const container = document.getElementById('switchCasesContainer');
    const caseId = switchCaseCount++;

    const caseHtml = `
        <div class="switch-case-row mb-2" data-case-id="${caseId}">
            <div class="input-group">
                <span class="input-group-text">CASE</span>
                <input type="text" class="form-control switch-case-value" placeholder="value"
                       value="${value}" onchange="updateSwitchPreview()">
                <span class="input-group-text">goto:</span>
                <input type="text" class="form-control switch-case-goto" placeholder="step_name"
                       value="${goto}" onchange="updateSwitchPreview()">
                <button type="button" class="btn btn-outline-danger" onclick="removeSwitchCase(${caseId})">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    `;

    container.insertAdjacentHTML('beforeend', caseHtml);
    updateSwitchPreview();
}

function removeSwitchCase(caseId) {
    const row = document.querySelector(`.switch-case-row[data-case-id="${caseId}"]`);
    if (row) {
        row.remove();
        updateSwitchPreview();
    }
}

function getSwitchCases() {
    const cases = {};
    document.querySelectorAll('.switch-case-row').forEach(row => {
        const value = row.querySelector('.switch-case-value').value;
        const goto = row.querySelector('.switch-case-goto').value;
        if (value && goto) {
            cases[value] = goto;
        }
    });
    return cases;
}

function updateSwitchPreview() {
    const value = document.querySelector('[name="config_switch_value"]').value || '{value}';
    const defaultGoto = document.querySelector('[name="config_switch_default"]').value || 'next';
    const cases = getSwitchCases();

    let preview = `SWITCH ${value}`;
    for (const [caseVal, caseGoto] of Object.entries(cases)) {
        preview += `\n  CASE "${caseVal}" -> goto:${caseGoto}`;
    }
    preview += `\n  DEFAULT -> goto:${defaultGoto}`;

    document.getElementById('switchPreview').innerHTML = preview.replace(/\n/g, '<br>');
}

// Initialize with one empty case
document.addEventListener('DOMContentLoaded', function() {
    // Only add default case if container is empty
    const container = document.getElementById('switchCasesContainer');
    if (container && container.children.length === 0) {
        addSwitchCase();
    }
});

// For loading existing config
function loadSwitchConfig(config) {
    if (!config) return;

    document.querySelector('[name="config_switch_value"]').value = config.value || '';
    document.querySelector('[name="config_switch_default"]').value = config.default || '';

    // Clear existing cases
    document.getElementById('switchCasesContainer').innerHTML = '';
    switchCaseCount = 0;

    // Add cases from config
    const cases = config.cases || {};
    for (const [value, goto] of Object.entries(cases)) {
        addSwitchCase(value, goto);
    }

    // Add empty case if none exist
    if (Object.keys(cases).length === 0) {
        addSwitchCase();
    }

    updateSwitchPreview();
}
</script>
