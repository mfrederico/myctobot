<?php
/**
 * @step_type: wait
 * @category: flow
 * @label: Wait
 * @icon: bi-hourglass-split
 * @color: secondary
 * @description: Wait for delay, approval, webhook, or external input
 */
?>
<div class="config-panel" id="config_wait" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Wait Type</label>
                        <select class="form-select" name="config_wait_type" onchange="toggleAwaitInputConfig(this)">
                            <option value="delay">Delay (seconds)</option>
                            <option value="approval">Manual Approval</option>
                            <option value="webhook">Wait for Webhook</option>
                            <option value="await_input">Await External Input (MCP, Form, Webhook)</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6" id="wait_duration_group">
                    <div class="mb-3">
                        <label class="form-label">Duration (seconds)</label>
                        <input type="number" class="form-control" name="config_wait_duration" value="60" min="1">
                    </div>
                </div>
            </div>

            <!-- Await Input Configuration -->
            <div id="await_input_config" style="display: none;">
                <hr>
                <h6 class="text-muted"><i class="bi bi-input-cursor-text"></i> Await Input Configuration</h6>

                <div class="mb-3">
                    <label class="form-label">Prompt <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="config_await_prompt"
                           placeholder="What would you like to do next?">
                    <div class="form-text">The question or prompt shown to the user/AI when input is needed.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Input Schema (JSON)</label>
                    <textarea class="form-control font-monospace" name="config_await_schema" rows="6"
                              placeholder='{
  "type": "object",
  "properties": {
    "selection": { "type": "string", "description": "User selection" }
  },
  "required": ["selection"]
}'></textarea>
                    <div class="form-text">JSON Schema defining expected input structure. Leave empty for freeform text.</div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Timeout (seconds)</label>
                            <input type="number" class="form-control" name="config_await_timeout" value="86400" min="60">
                            <div class="form-text">How long to wait for input (default: 24 hours)</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Allowed Input Sources</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="config_await_source_mcp" value="1" checked>
                                <label class="form-check-label">MCP (AI Tool Call)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="config_await_source_webhook" value="1" checked>
                                <label class="form-check-label">Webhook (API POST)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="config_await_source_form" value="1" checked>
                                <label class="form-check-label">Web Form</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info small mb-0">
                    <i class="bi bi-lightbulb"></i>
                    <strong>How it works:</strong> When this step executes, the pipeline pauses and returns an
                    <code>awaiting_input</code> status with URLs for input submission. The AI can call
                    <code>continue_pipeline</code> with the provided token, or users can submit via the form URL.
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleAwaitInputConfig(select) {
    const awaitInputConfig = document.getElementById('await_input_config');
    const durationGroup = document.getElementById('wait_duration_group');

    if (select.value === 'await_input') {
        awaitInputConfig.style.display = 'block';
        durationGroup.style.display = 'none';
    } else {
        awaitInputConfig.style.display = 'none';
        durationGroup.style.display = 'block';
    }
}

// Initialize on page load if editing existing step
document.addEventListener('DOMContentLoaded', function() {
    const waitTypeSelect = document.querySelector('select[name="config_wait_type"]');
    if (waitTypeSelect) {
        toggleAwaitInputConfig(waitTypeSelect);
    }
});
</script>
