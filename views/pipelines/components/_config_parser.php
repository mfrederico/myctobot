<?php
/**
 * @step_type: parser
 * @category: core
 * @label: Parser
 * @icon: bi-braces
 * @color: secondary
 * @description: Transform data (jq, php, regex)
 */
?>
<div class="config-panel" id="config_parser" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <div class="mb-3">
                        <label class="form-label">Parser Type</label>
                        <select class="form-select" name="config_parser_type" onchange="updateParserHelp(this.value)">
                            <option value="jq">jq (JSON)</option>
                            <option value="php">PHP</option>
                            <option value="regex">Regex</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="mb-3">
                        <label class="form-label d-flex justify-content-between align-items-center">
                            <span>Expression</span>
                            <a href="https://jqlang.github.io/jq/manual/" target="_blank" class="small text-decoration-none" id="parserDocsLink">
                                <i class="bi bi-box-arrow-up-right"></i> jq Docs
                            </a>
                        </label>
                        <textarea class="form-control font-monospace" name="config_parser_expression"
                                  rows="3" placeholder=".data.items[] | {id, name}"></textarea>
                        <small class="text-muted" id="parserHelpText">
                            jq receives the full output from the previous step as stdin (JSON).
                        </small>
                    </div>
                </div>
            </div>

            <!-- Test Expression Panel -->
            <div class="card border-warning mt-3" id="parserTestPanel">
                <div class="card-header bg-warning-subtle d-flex justify-content-between align-items-center py-2">
                    <span>
                        <i class="bi bi-play-circle me-1"></i>
                        <strong>Test Expression</strong>
                    </span>
                    <button type="button" class="btn btn-sm btn-warning" onclick="testParserExpression()">
                        <i class="bi bi-lightning-fill"></i> Run Test
                    </button>
                </div>
                <div class="card-body p-0">
                    <div id="parserTestResult" style="max-height: 200px; overflow: auto; display: none;">
                        <div class="p-2 bg-light border-bottom d-flex justify-content-between align-items-center">
                            <small class="text-muted"><i class="bi bi-box-arrow-up me-1"></i> Output Preview</small>
                            <span class="badge bg-secondary" id="parserTestStatus">-</span>
                        </div>
                        <pre class="mb-0 p-3 bg-dark text-light small" id="parserTestOutput" style="white-space: pre-wrap; word-break: break-word;"></pre>
                    </div>
                    <div class="p-3 text-muted small" id="parserTestPlaceholder">
                        <i class="bi bi-lightbulb me-1"></i>
                        Click "Run Test" to preview what your expression outputs against the Input Preview data above.
                    </div>
                </div>
            </div>

            <!-- Understanding Data Flow -->
            <div class="alert alert-info mt-3 mb-0 small">
                <i class="bi bi-lightbulb me-1"></i>
                <strong>How it works:</strong> Your expression runs against whatever data this step receives.
                First step? It gets the pipeline context. Later step? It gets the previous step's output.
            </div>
        </div>
    </div>
</div>

<script>
function updateParserHelp(type) {
    const helpText = document.getElementById('parserHelpText');
    const docsLink = document.getElementById('parserDocsLink');

    switch (type) {
        case 'jq':
            helpText.textContent = 'jq receives the full output from the previous step as stdin (JSON).';
            docsLink.href = 'https://jqlang.github.io/jq/manual/';
            docsLink.innerHTML = '<i class="bi bi-box-arrow-up-right"></i> jq Docs';
            break;
        case 'php':
            helpText.textContent = 'PHP expression with $input containing the previous step output.';
            docsLink.href = 'https://www.php.net/manual/en/';
            docsLink.innerHTML = '<i class="bi bi-box-arrow-up-right"></i> PHP Docs';
            break;
        case 'regex':
            helpText.textContent = 'Regex pattern to extract from stdout. Use capture groups.';
            docsLink.href = 'https://regex101.com/';
            docsLink.innerHTML = '<i class="bi bi-box-arrow-up-right"></i> Regex Tester';
            break;
    }
}

async function testParserExpression() {
    const expression = document.querySelector('[name="config_parser_expression"]').value;
    const parserType = document.querySelector('[name="config_parser_type"]').value;
    const resultPanel = document.getElementById('parserTestResult');
    const placeholder = document.getElementById('parserTestPlaceholder');
    const outputEl = document.getElementById('parserTestOutput');
    const statusEl = document.getElementById('parserTestStatus');

    if (!expression.trim()) {
        alert('Please enter an expression to test');
        return;
    }

    // Get input data from the universal Input Preview
    const universalInputEl = document.getElementById('inputPreviewContent');
    if (!universalInputEl) {
        alert('Input Preview not available. Start an interactive debug session first.');
        return;
    }

    let inputData = universalInputEl.textContent;
    try {
        // Validate it's JSON
        JSON.parse(inputData);
    } catch (e) {
        alert('No valid JSON input data available. Run an interactive debug session and ensure the previous step has completed.');
        return;
    }

    // Show result panel
    resultPanel.style.display = 'block';
    placeholder.style.display = 'none';
    outputEl.textContent = 'Testing...';
    statusEl.textContent = 'Running';
    statusEl.className = 'badge bg-info';

    try {
        const response = await fetch('/pipelines/testparser', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: new URLSearchParams({
                csrf_token: csrfToken,
                parser_type: parserType,
                expression: expression,
                input: inputData
            })
        });

        const result = await response.json();
        console.log('testparser response:', result);

        if (result.success) {
            // Server returns {success: true, data: {output: "..."}}
            const output = result.data?.output ?? result.output;
            outputEl.textContent = output || '(empty output)';
            outputEl.classList.remove('text-danger');
            statusEl.textContent = 'Success';
            statusEl.className = 'badge bg-success';

            // Update Variable Exporter with test output (if output is valid JSON)
            if (output && output.trim()) {
                try {
                    const parsedOutput = JSON.parse(output);
                    const stepId = document.getElementById('stepId').value;
                    const stepName = document.getElementById('stepName').value;

                    // Get existing mappings from the step
                    const existingMappings = typeof currentStepData !== 'undefined' && currentStepData.output_mapping
                        ? currentStepData.output_mapping
                        : {};

                    // Update Variable Exporter with test output (pass true for isTestPreview)
                    if (typeof showModalVariableExporter === 'function') {
                        showModalVariableExporter(stepId, stepName, parsedOutput, existingMappings, true);
                    }
                } catch (jsonErr) {
                    // Output isn't JSON, can't update Variable Exporter
                    console.log('Test output is not JSON, Variable Exporter not updated');
                }
            }
        } else {
            outputEl.textContent = 'Error: ' + (result.error || 'Unknown error');
            outputEl.classList.add('text-danger');
            statusEl.textContent = 'Error';
            statusEl.className = 'badge bg-danger';
        }
    } catch (err) {
        outputEl.textContent = 'Request failed: ' + err.message;
        outputEl.classList.add('text-danger');
        statusEl.textContent = 'Error';
        statusEl.className = 'badge bg-danger';
    }
}
</script>
