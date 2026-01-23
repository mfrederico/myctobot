<?php
/**
 * @step_type: file_write
 * @category: core
 * @label: Write File
 * @icon: bi-file-earmark-arrow-down
 * @color: info
 * @description: Write content to a file in the run directory
 */
?>
<div class="config-panel" id="config_file_write" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Filename</label>
                        <input type="text" class="form-control font-monospace" name="config_file_filename"
                               placeholder="output.csv">
                        <small class="text-muted">Supports variables: {step.output}.csv</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Content Source</label>
                        <select class="form-select" name="config_file_source" onchange="toggleFileContentSource(this)">
                            <option value="template">Template (with variables)</option>
                            <option value="stdin">Previous Step Output (stdin)</option>
                            <option value="step_output">Step Output Field</option>
                            <option value="base64">Base64 Decode</option>
                        </select>
                    </div>
                </div>
            </div>

            <div id="fileSourceTemplate">
                <div class="mb-3">
                    <label class="form-label">Content</label>
                    <textarea class="form-control font-monospace" name="config_file_content" rows="6"
                              placeholder="File content here... supports {variables}"></textarea>
                    <small class="text-muted">Use {step_name.stdout} or {step_name.output.field} to include data from previous steps</small>
                </div>
            </div>

            <div id="fileSourceStepOutput" style="display: none;">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Source Step</label>
                            <input type="text" class="form-control font-monospace" name="config_file_source_step"
                                   placeholder="step_name">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Output Field</label>
                            <input type="text" class="form-control font-monospace" name="config_file_source_field"
                                   placeholder="output.data or stdout">
                        </div>
                    </div>
                </div>
            </div>

            <div id="fileSourceBase64" style="display: none;">
                <div class="mb-3">
                    <label class="form-label">Base64 Content Variable</label>
                    <input type="text" class="form-control font-monospace" name="config_file_base64_var"
                           placeholder="{step_name.output.base64_data}">
                    <small class="text-muted">The base64-encoded content will be decoded before writing</small>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Content Type (optional)</label>
                        <select class="form-select" name="config_file_content_type">
                            <option value="">Auto-detect</option>
                            <option value="text/plain">Plain Text</option>
                            <option value="text/csv">CSV</option>
                            <option value="application/json">JSON</option>
                            <option value="image/png">PNG Image</option>
                            <option value="image/jpeg">JPEG Image</option>
                            <option value="application/pdf">PDF</option>
                            <option value="application/octet-stream">Binary</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Append Mode</label>
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" name="config_file_append" id="fileAppendCheck">
                            <label class="form-check-label" for="fileAppendCheck">Append to existing file</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="alert alert-info small mb-0">
                <i class="bi bi-info-circle"></i>
                <strong>Output:</strong> The step outputs <code>{step.file}</code> with the full path to the written file.
                Use <code>{run_directory}</code> to reference the run's file storage directory.
            </div>
        </div>
    </div>
</div>

<script>
function toggleFileContentSource(select) {
    const source = select.value;
    document.getElementById('fileSourceTemplate').style.display = source === 'template' ? 'block' : 'none';
    document.getElementById('fileSourceStepOutput').style.display = source === 'step_output' ? 'block' : 'none';
    document.getElementById('fileSourceBase64').style.display = source === 'base64' ? 'block' : 'none';
}
</script>
