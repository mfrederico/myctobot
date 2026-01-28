# Input Inspector Implementation Guide

## Overview

The Input Inspector panel solves the critical visibility problem where users editing jq/parser steps cannot see the actual STDIN data that will be piped to their expression.

## Problem Statement

**Current situation:**
- Variable Browser shows exported variables: `{query_products.output.data.products.edges}`
- But jq/bash receives the FULL output as STDIN: `{"data": {"products": {...}}, "extensions": {...}}`
- Users can't see what jq operates on, making it impossible to write correct expressions

**The confusion:**
- **Exported Variables** = Specific paths for `{variable}` substitution in configs
- **STDIN** = Full output data piped to jq/bash/parser steps
- These are DIFFERENT things!

## Design Solution

### 1. Input Inspector Panel

**Location:** Between "Input Source" dropdown and "Variable Browser" in the step editor modal

**Features:**
- Collapsible panel with clear visual distinction (blue border, gradient header)
- Shows what STDIN the step will receive based on `input_source`
- Three tabs: Data Preview, Test Playground, Comparison View
- Context-aware based on step type and input source

### 2. Three Tabs

#### Tab 1: Data Preview
- Shows the actual STDIN data this step will receive
- Copy/download buttons for easy access
- Data size badge
- Explainer banner about what STDIN is
- Input source visualization (previous step, specific step, or context)

#### Tab 2: Test Playground (for jq/parser steps)
- Live jq expression tester
- Monaco editor or textarea for jq expression
- "Test Expression" button
- Shows output or error messages
- Validates jq syntax before running pipeline

#### Tab 3: STDIN vs Variables
- Side-by-side comparison
- Left: Full STDIN data
- Right: Exported variables
- Clear explanation of differences
- Helps users understand data flow

### 3. Context-Aware Behavior

| Input Source | STDIN Content | Inspector Shows |
|--------------|---------------|-----------------|
| `previous` | Full output from previous step | Previous step's output |
| `getfrom` | Full output from specified step | Specified step's output |
| `context` | No STDIN, only ENV | "No STDIN Available" message |

## Implementation Details

### Frontend Changes

#### 1. Add HTML to `/views/pipelines/edit.php`

Insert AFTER the "Input Source" section (around line 540):

```php
<!-- Input Inspector Panel -->
<div class="input-inspector mb-3" id="inputInspectorPanel" style="display: none;">
    <div class="input-inspector-header" data-bs-toggle="collapse"
         data-bs-target="#inputInspectorBody" role="button">
        <div class="input-inspector-title">
            <i class="bi bi-funnel-fill"></i>
            Input Inspector
            <span class="input-source-badge" id="stdinSourceBadge">STDIN: Previous Step</span>
        </div>
        <i class="bi bi-chevron-down" id="inputInspectorChevron"></i>
    </div>

    <div class="collapse" id="inputInspectorBody">
        <!-- Tabs -->
        <div class="input-tabs">
            <button class="input-tab active" data-tab="preview">
                <i class="bi bi-eye"></i> Data Preview
            </button>
            <button class="input-tab" data-tab="playground" id="playgroundTab" style="display: none;">
                <i class="bi bi-play-circle"></i> Test Playground
            </button>
            <button class="input-tab" data-tab="comparison">
                <i class="bi bi-columns-gap"></i> STDIN vs Variables
            </button>
        </div>

        <!-- Tab Content: Data Preview -->
        <div class="input-tab-content active" data-tab-content="preview">
            <!-- Explainer -->
            <div class="stdin-explainer">
                <i class="bi bi-info-circle-fill"></i>
                <div class="stdin-explainer-content">
                    <h6>What is STDIN?</h6>
                    <p>
                        STDIN is the <strong>full output data</strong> that gets piped to this step.
                        This is different from <strong>Exported Variables</strong> which are specific paths
                        you chose to expose for <code>{variable}</code> substitution.
                    </p>
                </div>
            </div>

            <!-- Input source info -->
            <div class="input-source-info" id="stdinSourceInfo">
                <i class="bi bi-diagram-3"></i>
                <div class="input-source-details">
                    <h6>Input Source Configuration</h6>
                    <p id="stdinSourceDescription">Loading...</p>
                    <div class="step-reference" id="stdinStepReference" style="display: none;">
                        <i class="bi bi-box-arrow-in-left"></i>
                        <span id="stdinStepName"></span>
                    </div>
                </div>
            </div>

            <!-- Loading state -->
            <div id="stdinLoading" style="display: none; text-align: center; padding: 40px;">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2 text-muted">Loading STDIN data...</p>
            </div>

            <!-- No data state -->
            <div id="stdinNoData" class="no-data-state" style="display: none;">
                <i class="bi bi-inbox"></i>
                <h6>No STDIN Available</h6>
                <p id="noDataMessage">No data available yet.</p>
            </div>

            <!-- Data preview -->
            <div class="data-preview" id="stdinDataPreview" style="display: none;">
                <div class="preview-toolbar">
                    <span class="data-size-badge" id="dataSizeBadge">0 B</span>
                    <button class="preview-btn" onclick="copyStdinToClipboard()">
                        <i class="bi bi-clipboard"></i> Copy
                    </button>
                    <button class="preview-btn" onclick="downloadStdinData()">
                        <i class="bi bi-download"></i> Download
                    </button>
                </div>
                <pre id="stdinDataContent"></pre>
            </div>
        </div>

        <!-- Tab Content: Test Playground -->
        <div class="input-tab-content" data-tab-content="playground">
            <div class="stdin-explainer">
                <i class="bi bi-lightbulb-fill"></i>
                <div class="stdin-explainer-content">
                    <h6>Test Your Expression</h6>
                    <p>
                        Write and test your jq expression against the actual STDIN data.
                        This helps you verify your transformation logic before running the pipeline.
                    </p>
                </div>
            </div>

            <div class="jq-playground">
                <div class="jq-editor-label">
                    <i class="bi bi-code-slash"></i>
                    JQ Expression
                </div>
                <textarea class="jq-expression-input" id="jqTestExpression"
                          placeholder=". | .data.products.edges[] | .node | {id, title, price: .priceRange.minVariantPrice.amount}"></textarea>

                <button class="btn jq-test-btn" onclick="testJqExpression()">
                    <i class="bi bi-play-fill"></i>
                    Test Expression
                </button>

                <!-- Output area -->
                <div class="jq-output" id="jqOutputSection" style="display: none;">
                    <div class="jq-output-label">
                        <i class="bi bi-arrow-right-circle"></i>
                        Output
                    </div>
                    <div id="jqOutputContainer"></div>
                </div>
            </div>
        </div>

        <!-- Tab Content: Comparison -->
        <div class="input-tab-content" data-tab-content="comparison">
            <div class="stdin-explainer">
                <i class="bi bi-arrow-left-right"></i>
                <div class="stdin-explainer-content">
                    <h6>Understanding the Difference</h6>
                    <p>
                        <strong>STDIN</strong> is the raw data piped to your step (jq/bash input).
                        <strong>Variables</strong> are specific paths exported for <code>{variable}</code> substitution in configs.
                    </p>
                </div>
            </div>

            <div class="comparison-grid">
                <!-- STDIN -->
                <div class="comparison-card">
                    <div class="comparison-header stdin-header">
                        <i class="bi bi-funnel"></i>
                        STDIN (What jq/bash receives)
                    </div>
                    <div class="comparison-body">
                        <pre id="comparisonStdin">Loading...</pre>
                    </div>
                </div>

                <!-- Variables -->
                <div class="comparison-card">
                    <div class="comparison-header vars-header">
                        <i class="bi bi-braces"></i>
                        Exported Variables (For substitution)
                    </div>
                    <div class="comparison-body">
                        <pre id="comparisonVars">Loading...</pre>
                    </div>
                </div>
            </div>

            <div class="alert alert-info mt-3" style="font-size: 0.875rem;">
                <strong>Key Differences:</strong>
                <ul class="mb-0 mt-2">
                    <li><strong>STDIN</strong>: Used by jq/bash/parser steps as their input data</li>
                    <li><strong>Variables</strong>: Used in config fields with <code>{stepname.path}</code> syntax</li>
                    <li>jq expressions operate on STDIN, not variables</li>
                    <li>Variables are created by exporting paths from step outputs</li>
                </ul>
            </div>
        </div>
    </div>
</div>
```

#### 2. Add CSS (append to existing `<style>` block)

```css
/* Input Inspector Panel */
.input-inspector {
    border: 2px solid #0d6efd;
    border-radius: 8px;
    background: #fff;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(13, 110, 253, 0.1);
}

.input-inspector-header {
    background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
    color: white;
    padding: 12px 16px;
    border-radius: 6px 6px 0 0;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    user-select: none;
}

.input-inspector-header:hover {
    background: linear-gradient(135deg, #0a58ca 0%, #084298 100%);
}

.input-inspector-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    font-size: 0.95rem;
}

.input-source-badge {
    background: rgba(255, 255, 255, 0.25);
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
    margin-left: 8px;
}

.input-tabs {
    display: flex;
    border-bottom: 1px solid #dee2e6;
    background: #f8f9fa;
    padding: 0 12px;
}

.input-tab {
    padding: 10px 16px;
    border: none;
    background: transparent;
    color: #6c757d;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
}

.input-tab:hover {
    color: #0d6efd;
    background: rgba(13, 110, 253, 0.05);
}

.input-tab.active {
    color: #0d6efd;
    border-bottom-color: #0d6efd;
    background: white;
}

.input-tab-content {
    padding: 16px;
    display: none;
}

.input-tab-content.active {
    display: block;
}

.stdin-explainer {
    background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
    border-left: 4px solid #ffc107;
    padding: 12px 16px;
    margin-bottom: 16px;
    border-radius: 4px;
    font-size: 0.875rem;
    display: flex;
    gap: 12px;
}

.stdin-explainer .bi {
    color: #856404;
    font-size: 1.5rem;
    flex-shrink: 0;
    margin-top: 2px;
}

.stdin-explainer-content h6 {
    margin: 0 0 6px 0;
    color: #856404;
    font-size: 0.875rem;
    font-weight: 600;
}

.stdin-explainer-content p {
    margin: 0;
    color: #664d03;
    line-height: 1.5;
}

.stdin-explainer-content code {
    background: rgba(0, 0, 0, 0.1);
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 0.8rem;
}

.input-source-info {
    background: #e7f1ff;
    border: 1px solid #b6d4fe;
    border-radius: 6px;
    padding: 12px 16px;
    margin-bottom: 16px;
    display: flex;
    align-items: start;
    gap: 12px;
}

.input-source-info .bi {
    color: #0d6efd;
    font-size: 1.5rem;
    flex-shrink: 0;
}

.step-reference {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #fff;
    border: 1px solid #dee2e6;
    padding: 4px 10px;
    border-radius: 4px;
    font-family: 'Courier New', monospace;
    font-size: 0.85rem;
    margin-top: 6px;
}

.data-preview {
    background: #1e1e1e;
    color: #d4d4d4;
    border-radius: 6px;
    padding: 16px;
    font-family: 'Courier New', monospace;
    font-size: 0.85rem;
    max-height: 400px;
    overflow-y: auto;
    position: relative;
    border: 1px solid #333;
}

.data-preview pre {
    margin: 0;
    color: #d4d4d4;
    white-space: pre-wrap;
    word-wrap: break-word;
}

.preview-toolbar {
    position: absolute;
    top: 8px;
    right: 8px;
    display: flex;
    gap: 4px;
}

.preview-btn {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #d4d4d4;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 0.75rem;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 4px;
}

.preview-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    color: #fff;
}

.data-size-badge {
    background: rgba(13, 110, 253, 0.2);
    color: #9ec5fe;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: 500;
}

.no-data-state {
    text-align: center;
    padding: 48px 16px;
    color: #6c757d;
}

.no-data-state .bi {
    font-size: 3rem;
    color: #dee2e6;
    margin-bottom: 12px;
}

.jq-playground {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 16px;
}

.jq-expression-input {
    font-family: 'Courier New', monospace;
    font-size: 0.9rem;
    background: #1e1e1e;
    color: #d4d4d4;
    border: 1px solid #333;
    border-radius: 4px;
    padding: 10px 12px;
    width: 100%;
    resize: vertical;
    min-height: 80px;
}

.jq-expression-input:focus {
    outline: none;
    border-color: #0d6efd;
    box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
}

.jq-test-btn {
    width: 100%;
    margin-top: 10px;
    background: #198754;
    border-color: #198754;
    font-weight: 500;
}

.jq-result-success {
    background: #d1e7dd;
    border: 1px solid #a3cfbb;
    border-left: 4px solid #198754;
    border-radius: 4px;
    padding: 12px;
}

.jq-result-success pre {
    margin: 0;
    font-family: 'Courier New', monospace;
    font-size: 0.85rem;
    color: #0f5132;
    white-space: pre-wrap;
}

.jq-result-error {
    background: #f8d7da;
    border: 1px solid #f1aeb5;
    border-left: 4px solid #dc3545;
    border-radius: 4px;
    padding: 12px;
}

.jq-result-error pre {
    margin: 0;
    font-family: 'Courier New', monospace;
    font-size: 0.85rem;
    color: #842029;
}

.comparison-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.comparison-card {
    border: 1px solid #dee2e6;
    border-radius: 6px;
    overflow: hidden;
}

.comparison-header {
    padding: 10px 14px;
    font-size: 0.85rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}

.comparison-header.stdin-header {
    background: #e7f1ff;
    color: #084298;
}

.comparison-header.vars-header {
    background: #f3e5f5;
    color: #6a1b9a;
}

.comparison-body {
    padding: 12px;
    background: #1e1e1e;
    color: #d4d4d4;
    font-family: 'Courier New', monospace;
    font-size: 0.8rem;
    max-height: 300px;
    overflow-y: auto;
}

@media (max-width: 768px) {
    .comparison-grid {
        grid-template-columns: 1fr;
    }
}
```

#### 3. Add JavaScript Functions

```javascript
// =========================================================================
// Input Inspector
// =========================================================================

let currentStdinData = null;

// Tab switching
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.input-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            const targetTab = this.getAttribute('data-tab');

            // Remove active from all
            document.querySelectorAll('.input-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.input-tab-content').forEach(c => c.classList.remove('active'));

            // Add active to target
            this.classList.add('active');
            document.querySelector(`[data-tab-content="${targetTab}"]`).classList.add('active');
        });
    });

    // Watch input source changes
    document.getElementById('inputSource').addEventListener('change', function() {
        updateInputInspector();
    });

    // Watch getfrom step changes
    document.querySelector('[name="input_getfrom_step"]').addEventListener('change', function() {
        if (document.getElementById('inputSource').value === 'getfrom') {
            updateInputInspector();
        }
    });

    // Watch step type changes (show/hide playground tab)
    document.getElementById('stepType').addEventListener('change', function() {
        const stepType = this.value;
        const playgroundTab = document.getElementById('playgroundTab');

        // Show playground only for jq/parser steps
        if (stepType === 'parser' || stepType === 'jq') {
            playgroundTab.style.display = 'flex';
        } else {
            playgroundTab.style.display = 'none';
        }
    });
});

async function updateInputInspector() {
    const panel = document.getElementById('inputInspectorPanel');
    const inputSource = document.getElementById('inputSource').value;
    const badge = document.getElementById('stdinSourceBadge');
    const description = document.getElementById('stdinSourceDescription');
    const stepReference = document.getElementById('stdinStepReference');
    const stepName = document.getElementById('stdinStepName');

    const row = document.getElementById('stepRow').value;
    const col = document.getElementById('stepCol').value;
    const stepId = document.getElementById('stepId').value || 0;

    // Update badge
    if (inputSource === 'context') {
        badge.textContent = 'Context Only';
        description.textContent = 'This step uses context variables only. No STDIN data is piped.';
        stepReference.style.display = 'none';
        panel.style.display = 'block';
        showNoStdinData('This step doesn\'t receive piped input. Only context variables are available.');
        return;
    } else if (inputSource === 'stdin' || inputSource === 'previous') {
        badge.textContent = 'STDIN: Previous Step';
        description.textContent = 'This step receives STDIN from the previous step in the pipeline.';
    } else if (inputSource === 'getfrom') {
        const fromStep = document.querySelector('[name="input_getfrom_step"]').value;
        badge.textContent = 'STDIN: ' + (fromStep || 'Specific Step');
        description.textContent = 'This step receives STDIN from a specific step.';
        if (fromStep) {
            stepName.textContent = fromStep + ' → this_step';
            stepReference.style.display = 'flex';
        }
    }

    panel.style.display = 'block';

    // Load STDIN data
    await loadStdinData(row, col, stepId, inputSource);
}

async function loadStdinData(row, col, stepId, inputSource) {
    const loading = document.getElementById('stdinLoading');
    const noData = document.getElementById('stdinNoData');
    const preview = document.getElementById('stdinDataPreview');

    loading.style.display = 'block';
    noData.style.display = 'none';
    preview.style.display = 'none';

    try {
        const getfromStep = inputSource === 'getfrom'
            ? document.querySelector('[name="input_getfrom_step"]').value
            : '';

        const response = await fetch(
            `/pipelines/getstdin/${pipelineId}?row=${row}&col=${col}&step_id=${stepId}&input_source=${inputSource}&getfrom_step=${getfromStep}`
        );
        const result = await response.json();

        loading.style.display = 'none';

        if (!result.success || !result.data) {
            showNoStdinData(result.message || 'No STDIN data available. Run the pipeline first to generate preview data.');
            return;
        }

        // Show data
        currentStdinData = result.data.stdin;
        const formatted = JSON.stringify(currentStdinData, null, 2);

        document.getElementById('stdinDataContent').textContent = formatted;
        document.getElementById('dataSizeBadge').textContent = formatBytes(formatted.length);
        preview.style.display = 'block';

        // Update comparison tab
        updateComparisonView(currentStdinData, result.data.variables || {});

    } catch (err) {
        loading.style.display = 'none';
        showNoStdinData('Failed to load STDIN data. ' + err.message);
        console.error('Error loading STDIN:', err);
    }
}

function showNoStdinData(message) {
    document.getElementById('stdinNoData').style.display = 'block';
    document.getElementById('noDataMessage').textContent = message;
}

function updateComparisonView(stdinData, variables) {
    const stdinPre = document.getElementById('comparisonStdin');
    const varsPre = document.getElementById('comparisonVars');

    // STDIN
    stdinPre.textContent = JSON.stringify(stdinData, null, 2);

    // Variables (formatted as variable references)
    let varsText = '';
    for (const [path, value] of Object.entries(variables)) {
        varsText += `{${path}}\n→ ${JSON.stringify(value, null, 2)}\n\n`;
    }
    varsPre.textContent = varsText || 'No exported variables from this step';
}

function copyStdinToClipboard() {
    if (!currentStdinData) return;

    const json = JSON.stringify(currentStdinData, null, 2);
    navigator.clipboard.writeText(json).then(() => {
        showToast('STDIN data copied to clipboard', 'success');
    }).catch(err => {
        console.error('Copy failed:', err);
        alert('Failed to copy to clipboard');
    });
}

function downloadStdinData() {
    if (!currentStdinData) return;

    const json = JSON.stringify(currentStdinData, null, 2);
    const blob = new Blob([json], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'stdin-preview.json';
    a.click();
    URL.revokeObjectURL(url);
}

async function testJqExpression() {
    const expression = document.getElementById('jqTestExpression').value;
    const outputSection = document.getElementById('jqOutputSection');
    const outputContainer = document.getElementById('jqOutputContainer');

    if (!expression.trim()) {
        alert('Please enter a jq expression to test');
        return;
    }

    if (!currentStdinData) {
        alert('No STDIN data available to test against');
        return;
    }

    outputSection.style.display = 'block';
    outputContainer.innerHTML = '<div class="text-center"><div class="spinner-border spinner-border-sm"></div> Testing...</div>';

    try {
        const response = await fetch('/pipelines/testjq', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                csrf_token: csrfToken,
                expression: expression,
                stdin: currentStdinData
            })
        });

        const result = await response.json();

        if (result.success) {
            outputContainer.innerHTML = `
                <div class="jq-result-success">
                    <pre>${JSON.stringify(result.data.output, null, 2)}</pre>
                </div>
            `;
        } else {
            outputContainer.innerHTML = `
                <div class="jq-result-error">
                    <pre>${result.message || 'JQ expression failed'}</pre>
                </div>
            `;
        }
    } catch (err) {
        outputContainer.innerHTML = `
            <div class="jq-result-error">
                <pre>Network error: ${err.message}</pre>
            </div>
        `;
    }
}

function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 10) / 10 + ' ' + sizes[i];
}

// Call updateInputInspector when step modal opens
// (Add to existing openStepModal function)
function openStepModal(row, col, stepId = 0) {
    // ... existing code ...

    // Load input inspector
    updateInputInspector();
}
```

### Backend Changes

#### 1. Add Controller Method: `/pipelines/getstdin/{pipelineId}`

```php
// controls/Pipelines.php

public function getstdin(): void {
    if (!Flight::hasLevel(LEVELS['MEMBER'])) {
        Flight::jsonError('Unauthorized', 401);
        return;
    }

    $pipelineId = $this->getParam('pipelineId');
    $row = (int)$_GET['row'];
    $col = (int)$_GET['col'];
    $stepId = (int)$_GET['step_id'];
    $inputSource = $_GET['input_source'] ?? 'prev';
    $getfromStep = $_GET['getfrom_step'] ?? '';

    $pipeline = Bean::load('pipelines', $pipelineId);
    if (!$pipeline->id || $pipeline->member_id != $this->member->id) {
        Flight::jsonError('Pipeline not found', 404);
        return;
    }

    // Get the source step based on input_source
    $sourceStep = null;

    if ($inputSource === 'context') {
        // No STDIN, just context
        Flight::jsonSuccess([
            'stdin' => null,
            'variables' => []
        ], 'Context only');
        return;
    }

    if ($inputSource === 'getfrom' && $getfromStep) {
        // Get from specific step by name
        $sourceStep = Bean::findOne('pipelinesteps',
            'pipeline_id = ? AND step_name = ?',
            [$pipelineId, $getfromStep]
        );
    } else {
        // Get previous step (same row, col - 1)
        $prevCol = $col - 1;
        if ($prevCol >= 1) {
            $sourceStep = Bean::findOne('pipelinesteps',
                'pipeline_id = ? AND row = ? AND col = ? AND id != ?',
                [$pipelineId, $row, $prevCol, $stepId]
            );
        }
    }

    if (!$sourceStep || !$sourceStep->id) {
        Flight::jsonError('Source step not found', 404);
        return;
    }

    // Try to get stdin from last run
    $lastRun = Bean::findOne('pipelineruns',
        'pipeline_id = ? ORDER BY created_at DESC',
        [$pipelineId]
    );

    if (!$lastRun || !$lastRun->id) {
        Flight::jsonError('No pipeline runs yet. Run the pipeline to generate preview data.', 404);
        return;
    }

    // Get the step execution for the source step
    $stepExec = Bean::findOne('pipelinestepexecutions',
        'run_id = ? AND step_id = ? ORDER BY created_at DESC',
        [$lastRun->id, $sourceStep->id]
    );

    if (!$stepExec || !$stepExec->id) {
        Flight::jsonError('Source step has not been executed yet', 404);
        return;
    }

    // Get the output from that execution
    $output = json_decode($stepExec->output_json ?? '{}', true);

    // Get exported variables
    $variables = [];
    $exports = json_decode($sourceStep->config_json ?? '{}', true)['export'] ?? [];
    foreach ($exports as $export) {
        $path = $sourceStep->step_name . '.output.' . $export['path'];
        $variables[$path] = $this->extractPath($output, $export['path']);
    }

    Flight::jsonSuccess([
        'stdin' => $output,
        'variables' => $variables,
        'source_step' => [
            'name' => $sourceStep->step_name,
            'label' => $sourceStep->label
        ]
    ], 'STDIN data loaded');
}

private function extractPath($data, $path) {
    $parts = explode('.', $path);
    $current = $data;

    foreach ($parts as $part) {
        if (is_array($current) && isset($current[$part])) {
            $current = $current[$part];
        } else {
            return null;
        }
    }

    return $current;
}
```

#### 2. Add Controller Method: `/pipelines/testjq`

```php
public function testjq(): void {
    if (!Flight::hasLevel(LEVELS['MEMBER'])) {
        Flight::jsonError('Unauthorized', 401);
        return;
    }

    if (!$this->validateCSRF()) {
        Flight::jsonError('Invalid CSRF token', 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $expression = $input['expression'] ?? '';
    $stdin = $input['stdin'] ?? null;

    if (empty($expression)) {
        Flight::jsonError('Expression is required', 400);
        return;
    }

    if ($stdin === null) {
        Flight::jsonError('STDIN data is required', 400);
        return;
    }

    // Write stdin to temp file
    $stdinFile = tempnam(sys_get_temp_dir(), 'jq_test_');
    file_put_contents($stdinFile, json_encode($stdin));

    // Execute jq
    $cmd = sprintf('jq %s < %s 2>&1',
        escapeshellarg($expression),
        escapeshellarg($stdinFile)
    );

    exec($cmd, $output, $returnCode);
    unlink($stdinFile);

    if ($returnCode !== 0) {
        Flight::jsonError(implode("\n", $output), 400);
        return;
    }

    // Parse output
    $result = json_decode(implode("\n", $output), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $result = implode("\n", $output); // Return as string if not valid JSON
    }

    Flight::jsonSuccess(['output' => $result], 'Expression executed successfully');
}
```

## User Workflow

### Before (Current State)
1. User opens step editor for jq parser step
2. Sees Variable Browser with `{query_products.output.data.products.edges}`
3. Tries to write jq expression: `. | .edges[]`
4. Pipeline fails: "Cannot index array with string 'edges'"
5. User confused - the variable shows `.edges` exists!

### After (With Input Inspector)
1. User opens step editor for jq parser step
2. Sees Input Inspector showing FULL stdin: `{"data": {"products": {"edges": [...]}}}`
3. Clicks "STDIN vs Variables" tab, sees the difference clearly
4. Writes correct jq expression: `. | .data.products.edges[]`
5. Clicks "Test Expression" to verify output
6. Sees preview: `[{node: {...}}, {node: {...}}]`
7. Saves step with confidence

## Benefits

1. **Eliminates confusion** between stdin and exported variables
2. **Faster development** - test expressions before running pipeline
3. **Better learning curve** - visual explanation of data flow
4. **Reduces errors** - see actual data structure
5. **Saves time** - no more trial-and-error debugging

## Future Enhancements

1. **Monaco Editor** integration for better jq syntax highlighting
2. **Expression templates** (common jq patterns)
3. **Diff view** showing before/after transformation
4. **History** of tested expressions
5. **AI suggestions** based on data structure
