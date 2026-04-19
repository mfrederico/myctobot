<?php
/**
 * @step_type: stitch_mcp
 * @category: integration
 * @label: Google Stitch
 * @icon: bi-palette
 * @color: info
 * @description: Call Google Stitch MCP tools for AI design
 *
 * Required view data: $stitchConnections
 */
?>
<div class="config-panel" id="config_stitch_mcp" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Stitch Connection</label>
                    <select class="form-select" name="config_stitch_connection_id" id="stitchConnectionSelect">
                        <option value="">-- Select Account --</option>
                        <?php foreach ($stitchConnections ?? [] as $conn): ?>
                        <option value="<?= $conn->id ?>">
                            <?= h($conn->google_email ?: $conn->connection_name) ?>
                            <?php if ($conn->gcp_project_id): ?>(<?= h($conn->gcp_project_id) ?>)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">MCP Tool</label>
                    <select class="form-select" name="config_stitch_tool" id="stitchToolSelect">
                        <option value="">-- Select Tool --</option>
                        <optgroup label="Project Management">
                            <option value="list_projects">list_projects</option>
                            <option value="create_project">create_project</option>
                            <option value="get_project">get_project</option>
                        </optgroup>
                        <optgroup label="Screen Design">
                            <option value="list_screens">list_screens</option>
                            <option value="get_screen">get_screen</option>
                            <option value="generate_screen_from_text">generate_screen_from_text</option>
                        </optgroup>
                        <optgroup label="Export">
                            <option value="fetch_screen_code">fetch_screen_code</option>
                            <option value="fetch_screen_image">fetch_screen_image</option>
                            <option value="extract_design_context">extract_design_context</option>
                        </optgroup>
                    </select>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Tool Arguments (JSON)</label>
                <textarea class="form-control font-monospace"
                          name="config_stitch_arguments"
                          rows="8"
                          placeholder='{ "project_id": "{context.stitch_project_id}", "prompt": "Create a login page" }'></textarea>
                <small class="text-muted">
                    Supports variable substitution: <code>{context.key}</code>, <code>{step_name.output.key}</code>
                </small>
            </div>

            <div class="alert alert-info mb-0 py-2">
                <strong>Output:</strong> <code>{step_name.output}</code> contains the MCP tool response.
                <small class="d-block text-muted">Example: <code>{generate_ui.output.result.content}</code></small>
            </div>
        </div>
    </div>
</div>
