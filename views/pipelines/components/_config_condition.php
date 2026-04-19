<?php
/**
 * @step_type: condition
 * @category: flow
 * @label: Condition (If/Then/Else)
 * @icon: bi-signpost-split
 * @color: warning
 * @description: Evaluate a condition and branch to different steps based on the result.
 */
?>
<div class="config-panel" id="config_condition" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <h6 class="text-muted mb-3"><i class="bi bi-signpost-split"></i> Condition Configuration</h6>

            <!-- The Condition -->
            <div class="card bg-white mb-3">
                <div class="card-header py-2">
                    <strong>IF</strong> <small class="text-muted">- Define the condition to evaluate</small>
                </div>
                <div class="card-body">
                    <div class="row align-items-end">
                        <div class="col-md-5">
                            <div class="mb-2">
                                <label class="form-label small">Left Value</label>
                                <input type="text" class="form-control font-monospace" name="config_condition_left"
                                       placeholder="{prev.output.timed_out}" onchange="updateConditionPreview()">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-2">
                                <label class="form-label small">Operator</label>
                                <select class="form-select" name="config_condition_operator" onchange="updateConditionPreview(); toggleRightValue()">
                                    <option value="equals">== equals</option>
                                    <option value="not_equals">!= not equals</option>
                                    <option value="greater_than">&gt; greater than</option>
                                    <option value="less_than">&lt; less than</option>
                                    <option value="greater_equal">&gt;= greater or equal</option>
                                    <option value="less_equal">&lt;= less or equal</option>
                                    <option value="contains">contains</option>
                                    <option value="not_contains">not contains</option>
                                    <option value="starts_with">starts with</option>
                                    <option value="ends_with">ends with</option>
                                    <option value="is_empty">is empty</option>
                                    <option value="is_not_empty">is not empty</option>
                                    <option value="is_true">is truthy</option>
                                    <option value="is_false">is falsy</option>
                                    <option value="regex">regex match</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-5" id="conditionRightCol">
                            <div class="mb-2">
                                <label class="form-label small">Right Value</label>
                                <input type="text" class="form-control font-monospace" name="config_condition_right"
                                       placeholder="true" onchange="updateConditionPreview()">
                            </div>
                        </div>
                    </div>

                    <!-- Quick Examples -->
                    <div class="mt-2">
                        <small class="text-muted">Examples:</small>
                        <div class="d-flex flex-wrap gap-1 mt-1">
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0"
                                    onclick="setConditionExample('{prev.output.timed_out}', 'is_true', '')">
                                Timed out?
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0"
                                    onclick="setConditionExample('{context.response}', 'is_empty', '')">
                                No response?
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0"
                                    onclick="setConditionExample('{prev.output.count}', 'greater_than', '0')">
                                Count > 0?
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0"
                                    onclick="setConditionExample('{prev.output.status}', 'equals', 'confirmed')">
                                Status confirmed?
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Then / Else Branches -->
            <div class="row">
                <div class="col-md-6">
                    <div class="card border-success">
                        <div class="card-header py-2 bg-success bg-opacity-10">
                            <strong class="text-success"><i class="bi bi-check-circle"></i> THEN</strong>
                            <small class="text-muted">- Condition is TRUE</small>
                        </div>
                        <div class="card-body">
                            <label class="form-label small">Go to step:</label>
                            <div class="input-group">
                                <span class="input-group-text">goto:</span>
                                <input type="text" class="form-control" name="config_condition_then_goto"
                                       placeholder="step_name or row.col" list="availableStepsThen">
                                <datalist id="availableStepsThen"></datalist>
                            </div>
                            <div class="form-text">Leave empty to continue to next step</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-danger">
                        <div class="card-header py-2 bg-danger bg-opacity-10">
                            <strong class="text-danger"><i class="bi bi-x-circle"></i> ELSE</strong>
                            <small class="text-muted">- Condition is FALSE</small>
                        </div>
                        <div class="card-body">
                            <label class="form-label small">Go to step:</label>
                            <div class="input-group">
                                <span class="input-group-text">goto:</span>
                                <input type="text" class="form-control" name="config_condition_else_goto"
                                       placeholder="step_name or row.col" list="availableStepsElse">
                                <datalist id="availableStepsElse"></datalist>
                            </div>
                            <div class="form-text">Leave empty to continue to next step</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Preview -->
            <div class="alert alert-secondary mt-3 mb-0 py-2">
                <code id="conditionPreview" class="d-block">IF {left} == {right} THEN goto:... ELSE goto:...</code>
            </div>
        </div>
    </div>
</div>

<script>
function setConditionExample(left, operator, right) {
    document.querySelector('[name="config_condition_left"]').value = left;
    document.querySelector('[name="config_condition_operator"]').value = operator;
    document.querySelector('[name="config_condition_right"]').value = right;
    toggleRightValue();
    updateConditionPreview();
}

function toggleRightValue() {
    const operator = document.querySelector('[name="config_condition_operator"]').value;
    const rightCol = document.getElementById('conditionRightCol');
    const unaryOps = ['is_empty', 'is_not_empty', 'is_true', 'is_false'];

    if (unaryOps.includes(operator)) {
        rightCol.style.visibility = 'hidden';
    } else {
        rightCol.style.visibility = 'visible';
    }
}

function updateConditionPreview() {
    const left = document.querySelector('[name="config_condition_left"]').value || '{value}';
    const operator = document.querySelector('[name="config_condition_operator"]').value;
    const right = document.querySelector('[name="config_condition_right"]').value;
    const thenGoto = document.querySelector('[name="config_condition_then_goto"]').value || 'next';
    const elseGoto = document.querySelector('[name="config_condition_else_goto"]').value || 'next';

    const opText = {
        'equals': '==', 'not_equals': '!=', 'greater_than': '>', 'less_than': '<',
        'greater_equal': '>=', 'less_equal': '<=', 'contains': 'contains',
        'not_contains': '!contains', 'starts_with': 'starts with', 'ends_with': 'ends with',
        'is_empty': 'is empty', 'is_not_empty': 'is not empty',
        'is_true': 'is truthy', 'is_false': 'is falsy', 'regex': '=~'
    }[operator] || operator;

    const unaryOps = ['is_empty', 'is_not_empty', 'is_true', 'is_false'];
    let condition = unaryOps.includes(operator)
        ? `${left} ${opText}`
        : `${left} ${opText} ${right || '{value}'}`;

    document.getElementById('conditionPreview').textContent =
        `IF ${condition} THEN goto:${thenGoto} ELSE goto:${elseGoto}`;
}

// Initialize on load
document.addEventListener('DOMContentLoaded', function() {
    // Populate step datalists when condition panel is shown
    const configPanel = document.getElementById('config_condition');
    if (configPanel) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'style' && configPanel.style.display !== 'none') {
                    populateStepDatalist();
                }
            });
        });
        observer.observe(configPanel, { attributes: true });
    }

    // Bind change events
    ['config_condition_left', 'config_condition_operator', 'config_condition_right',
     'config_condition_then_goto', 'config_condition_else_goto'].forEach(name => {
        const el = document.querySelector(`[name="${name}"]`);
        if (el) {
            el.addEventListener('input', updateConditionPreview);
            el.addEventListener('change', updateConditionPreview);
        }
    });
});

function populateStepDatalist() {
    // Get all step names from the current pipeline
    const stepNames = [];
    document.querySelectorAll('.step-cell[data-step-name]').forEach(cell => {
        const name = cell.dataset.stepName;
        if (name) stepNames.push(name);
    });

    ['availableStepsThen', 'availableStepsElse'].forEach(id => {
        const datalist = document.getElementById(id);
        if (datalist) {
            datalist.innerHTML = stepNames.map(n => `<option value="${n}">`).join('');
        }
    });
}
</script>
