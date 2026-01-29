<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-3">
            <li class="breadcrumb-item"><a href="/orchestration">Studio</a></li>
            <li class="breadcrumb-item"><a href="/orchestration/templates">Templates</a></li>
            <li class="breadcrumb-item active"><?= htmlspecialchars($title) ?></li>
        </ol>
    </nav>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <?php if ($template): ?>
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="rounded bg-<?= $template['color'] ?> bg-opacity-10 p-3 me-3">
                            <i class="bi <?= $template['icon'] ?> fs-4 text-<?= $template['color'] ?>"></i>
                        </div>
                        <div>
                            <h4 class="mb-1"><?= htmlspecialchars($template['name']) ?></h4>
                            <p class="text-muted mb-0"><?= htmlspecialchars($template['description']) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Wizard Card -->
            <div class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-magic"></i>
                            <span id="stepTitle">Getting Started</span>
                        </h5>
                        <div id="stepIndicator" class="text-muted small">
                            Step <span id="currentStep">1</span> of <span id="totalSteps"><?= count($wizardConfig['steps'] ?? []) ?></span>
                        </div>
                    </div>
                    <!-- Progress Bar -->
                    <div class="progress mt-3" style="height: 4px;">
                        <div id="progressBar" class="progress-bar bg-primary" role="progressbar" style="width: 0%"></div>
                    </div>
                </div>

                <div class="card-body p-4">
                    <div id="wizardContent">
                        <!-- Steps will be rendered here dynamically -->
                    </div>
                </div>

                <div class="card-footer d-flex justify-content-between">
                    <button type="button" id="btnPrev" class="btn btn-outline-secondary" onclick="previousStep()" disabled>
                        <i class="bi bi-arrow-left"></i> Previous
                    </button>
                    <button type="button" id="btnNext" class="btn btn-primary" onclick="nextStep()">
                        Next <i class="bi bi-arrow-right"></i>
                    </button>
                    <button type="button" id="btnCreate" class="btn btn-success d-none" onclick="createProject()">
                        <i class="bi bi-check-lg"></i> Create Orchestration
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const wizardConfig = <?= json_encode($wizardConfig) ?>;
const templateId = <?= $templateId ? (int)$templateId : 'null' ?>;
let currentStepIndex = 0;
let projectId = null;
let wizardData = {};

document.addEventListener('DOMContentLoaded', function() {
    renderStep(0);
});

function renderStep(index) {
    if (!wizardConfig.steps || index >= wizardConfig.steps.length) return;

    const step = wizardConfig.steps[index];
    currentStepIndex = index;

    // Update header
    document.getElementById('stepTitle').textContent = step.title || 'Step ' + (index + 1);
    document.getElementById('currentStep').textContent = index + 1;
    document.getElementById('totalSteps').textContent = wizardConfig.steps.length;

    // Update progress
    const progress = ((index + 1) / wizardConfig.steps.length) * 100;
    document.getElementById('progressBar').style.width = progress + '%';

    // Update buttons
    document.getElementById('btnPrev').disabled = (index === 0);

    const isLastStep = (index === wizardConfig.steps.length - 1);
    document.getElementById('btnNext').classList.toggle('d-none', isLastStep);
    document.getElementById('btnCreate').classList.toggle('d-none', !isLastStep);

    // Render step content
    const content = document.getElementById('wizardContent');
    content.innerHTML = renderStepContent(step);

    // Initialize any special components
    initializeStepComponents(step);
}

function renderStepContent(step) {
    switch (step.type) {
        case 'form':
            return renderFormStep(step);
        case 'ai_assisted_text':
            return renderAiTextStep(step);
        case 'connection_picker':
            return renderConnectionPicker(step);
        case 'summary':
            return renderSummaryStep(step);
        default:
            return `<p class="text-muted">Unknown step type: ${step.type}</p>`;
    }
}

function renderFormStep(step) {
    let html = '';
    if (step.help_text) {
        html += `<p class="text-muted mb-4">${step.help_text}</p>`;
    }

    (step.fields || []).forEach(field => {
        const value = wizardData[field.name] || field.default || '';
        const required = field.required ? 'required' : '';

        html += `<div class="mb-3">`;
        html += `<label class="form-label">${field.label || field.name}${field.required ? ' <span class="text-danger">*</span>' : ''}</label>`;

        switch (field.type) {
            case 'textarea':
                html += `<textarea class="form-control" name="${field.name}" rows="3" ${required}>${value}</textarea>`;
                break;
            case 'select':
                html += `<select class="form-select" name="${field.name}" ${required}>`;
                (field.options || []).forEach(opt => {
                    const selected = opt == value ? 'selected' : '';
                    html += `<option value="${opt}" ${selected}>${opt}</option>`;
                });
                html += `</select>`;
                break;
            case 'slider':
                html += `<input type="range" class="form-range" name="${field.name}" min="${field.min || 0}" max="${field.max || 100}" value="${value || field.default || 50}" oninput="updateSliderValue(this, '${field.name}')">`;
                html += `<div class="d-flex justify-content-between"><small class="text-muted">${field.min || 0}</small><span id="sliderValue_${field.name}" class="badge bg-primary">${value || field.default || 50}</span><small class="text-muted">${field.max || 100}</small></div>`;
                break;
            default:
                html += `<input type="${field.type || 'text'}" class="form-control" name="${field.name}" value="${value}" ${required}>`;
        }

        if (field.description) {
            html += `<small class="form-text text-muted">${field.description}</small>`;
        }
        html += `</div>`;
    });

    return html;
}

function renderAiTextStep(step) {
    const value = wizardData[step.variable] || '';
    let html = `
        <div class="mb-3">
            <label class="form-label">${step.title}</label>
            <textarea class="form-control" id="aiTextInput" name="${step.variable}" rows="4"
                      placeholder="${step.placeholder || 'Describe what you want...'}">${value}</textarea>
        </div>
    `;

    if (step.examples && step.examples.length) {
        html += `<div class="mb-3"><small class="text-muted">Examples:</small><ul class="small text-muted">`;
        step.examples.forEach(ex => {
            html += `<li><a href="#" onclick="setAiText('${ex}'); return false;">${ex}</a></li>`;
        });
        html += `</ul></div>`;
    }

    html += `
        <button type="button" class="btn btn-outline-primary btn-sm" onclick="getAiAssistance()">
            <i class="bi bi-stars"></i> Get AI Suggestions
        </button>
        <div id="aiSuggestions" class="mt-3"></div>
    `;

    return html;
}

function renderConnectionPicker(step) {
    let html = `<p class="text-muted mb-3">Select the integrations to use:</p>`;
    html += `<div class="list-group">`;

    // TODO: Load actual connections from API
    const connections = [
        {id: 'shopify', name: 'Shopify', icon: 'bi-shop', connected: true},
        {id: 'github', name: 'GitHub', icon: 'bi-github', connected: true},
        {id: 'jira', name: 'Jira', icon: 'bi-kanban', connected: false}
    ];

    connections.forEach(conn => {
        const checked = (wizardData[step.variable] || []).includes(conn.id) ? 'checked' : '';
        const disabled = !conn.connected ? 'disabled' : '';

        html += `
            <label class="list-group-item list-group-item-action d-flex align-items-center ${disabled ? 'text-muted' : ''}">
                <input type="${step.multiple ? 'checkbox' : 'radio'}" name="${step.variable}"
                       value="${conn.id}" class="form-check-input me-3" ${checked} ${disabled}>
                <i class="bi ${conn.icon} fs-4 me-3"></i>
                <div class="flex-grow-1">
                    <div>${conn.name}</div>
                    ${!conn.connected ? '<small class="text-danger">Not connected</small>' : '<small class="text-success">Connected</small>'}
                </div>
            </label>
        `;
    });

    html += `</div>`;
    return html;
}

function renderSummaryStep(step) {
    let html = `
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Review your configuration before creating the orchestration.
        </div>
        <div class="card bg-light">
            <div class="card-body">
                <h6 class="card-title">Configuration Summary</h6>
                <dl class="row mb-0">
    `;

    for (const [key, value] of Object.entries(wizardData)) {
        html += `
            <dt class="col-sm-4">${key}</dt>
            <dd class="col-sm-8">${Array.isArray(value) ? value.join(', ') : value}</dd>
        `;
    }

    html += `
                </dl>
            </div>
        </div>
    `;

    return html;
}

function initializeStepComponents(step) {
    // Add any initialization logic for special components
}

function updateSliderValue(input, name) {
    document.getElementById('sliderValue_' + name).textContent = input.value;
}

function setAiText(text) {
    document.getElementById('aiTextInput').value = text;
}

function collectStepData() {
    const step = wizardConfig.steps[currentStepIndex];
    const data = {};

    if (step.type === 'form') {
        (step.fields || []).forEach(field => {
            const input = document.querySelector(`[name="${field.name}"]`);
            if (input) data[field.name] = input.value;
        });
    } else if (step.variable) {
        const input = document.querySelector(`[name="${step.variable}"]`);
        if (input) {
            if (input.type === 'checkbox') {
                data[step.variable] = Array.from(document.querySelectorAll(`[name="${step.variable}"]:checked`)).map(el => el.value);
            } else {
                data[step.variable] = input.value;
            }
        }
    }

    return data;
}

async function saveStepData() {
    const stepData = collectStepData();
    Object.assign(wizardData, stepData);

    try {
        const response = await fetch('/orchestration/wizardstep', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                template_id: templateId,
                step_id: wizardConfig.steps[currentStepIndex].id,
                data: stepData,
                project_id: projectId
            })
        });

        const result = await response.json();
        if (result.success && result.data.project_id) {
            projectId = result.data.project_id;
        }
    } catch (err) {
        console.error('Error saving step:', err);
    }
}

async function previousStep() {
    if (currentStepIndex > 0) {
        renderStep(currentStepIndex - 1);
    }
}

async function nextStep() {
    await saveStepData();
    if (currentStepIndex < wizardConfig.steps.length - 1) {
        renderStep(currentStepIndex + 1);
    }
}

async function createProject() {
    await saveStepData();

    // Update button to show loading state
    const btnCreate = document.getElementById('btnCreate');
    const originalText = btnCreate.innerHTML;
    btnCreate.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Creating...';
    btnCreate.disabled = true;

    try {
        // Finalize the project by saving final step and setting name
        const response = await fetch('/orchestration/finalize', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                template_id: templateId,
                project_id: projectId,
                data: wizardData
            })
        });

        const result = await response.json();

        if (result.success) {
            window.location.href = '/orchestration/project/' + result.data.project_id;
        } else {
            alert('Error: ' + result.message);
            btnCreate.innerHTML = originalText;
            btnCreate.disabled = false;
        }
    } catch (err) {
        alert('Error creating project: ' + err.message);
        btnCreate.innerHTML = originalText;
        btnCreate.disabled = false;
    }
}

async function getAiAssistance() {
    const input = document.getElementById('aiTextInput').value;
    const step = wizardConfig.steps[currentStepIndex];

    const suggestionsDiv = document.getElementById('aiSuggestions');
    suggestionsDiv.innerHTML = '<div class="spinner-border spinner-border-sm text-primary"></div> Getting suggestions...';

    try {
        const response = await fetch('/orchestration/aiassist', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                field_type: step.type,
                context: wizardData,
                input: input
            })
        });

        const result = await response.json();
        if (result.success) {
            suggestionsDiv.innerHTML = `<div class="alert alert-light">${result.data.suggestion}</div>`;
        } else {
            suggestionsDiv.innerHTML = `<div class="alert alert-warning">${result.message}</div>`;
        }
    } catch (err) {
        suggestionsDiv.innerHTML = `<div class="alert alert-danger">Error: ${err.message}</div>`;
    }
}
</script>
