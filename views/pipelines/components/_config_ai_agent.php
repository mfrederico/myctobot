<?php
/**
 * @step_type: ai_agent
 * @category: ai
 * @label: AI Agent
 * @icon: bi-robot
 * @color: primary
 * @description: Run an AI agent (impl, verify, fix, or custom)
 *
 * Required view data: $agents, $runners
 */
?>
<div class="config-panel" id="config_ai_agent" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Agent</label>
                        <div class="input-group">
                            <select class="form-select" name="config_agent_id" id="agentIdSelect" onchange="onAgentSelectChange()">
                                <option value="">Select an agent...</option>
                                <option value="_variable">Use Variable...</option>
                                <?php foreach ($agents as $agent): ?>
                                <option value="<?= $agent['id'] ?>"><?= htmlspecialchars($agent['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="agentIdVariableInput" style="display: none;" class="mt-2">
                            <input type="text" class="form-control font-monospace" name="config_agent_id_variable"
                                   placeholder="{context.agent_id}" value="">
                            <small class="text-muted">Enter variable like <code>{context.agent_id}</code></small>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Runner / Workstation</label>
                        <div class="input-group">
                            <select class="form-select" name="config_runner_id" id="runnerIdSelect" onchange="onRunnerSelectChange()">
                                <option value="">Default / Auto</option>
                                <option value="_variable">Use Variable...</option>
                                <?php foreach ($runners as $runner): ?>
                                <option value="<?= $runner['id'] ?>"><?= htmlspecialchars($runner['name']) ?> (<?= $runner['host'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="runnerIdVariableInput" style="display: none;" class="mt-2">
                            <input type="text" class="form-control font-monospace" name="config_runner_id_variable"
                                   placeholder="{context.runner_id}" value="">
                            <small class="text-muted">Enter variable like <code>{context.runner_id}</code></small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Execution Mode</label>
                        <select class="form-select" name="config_execution_mode">
                            <option value="api">API - Direct Claude API call</option>
                            <option value="runner">Runner - Full CLI session with MCP tools</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Working Directory <small class="text-muted">(Runner mode)</small></label>
                        <input type="text" class="form-control font-monospace" name="config_working_dir"
                               placeholder="~/jobs/{job_uid} (default)">
                        <small class="text-muted">Remote path: <code>/var/www/html/mysite</code> or <code>{context.work_dir}</code></small>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Options</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="config_wait_for_completion" id="agentWaitForCompletion" checked>
                            <label class="form-check-label" for="agentWaitForCompletion">Wait for completion</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="config_json_output" id="agentJsonOutput">
                            <label class="form-check-label" for="agentJsonOutput">Expect JSON output</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mb-0">
                <label class="form-label">Prompt Template</label>
                <textarea class="form-control font-monospace" name="config_prompt" rows="3"
                          placeholder="Execute the task. Context: {context.issue_key}"></textarea>
                <small class="text-muted">Use <code>{context.key}</code> or <code>{step_name.output.key}</code> for variables</small>
            </div>
        </div>
    </div>
</div>
<script>
function onAgentSelectChange() {
    const select = document.getElementById('agentIdSelect');
    const varInput = document.getElementById('agentIdVariableInput');
    if (select.value === '_variable') {
        varInput.style.display = 'block';
    } else {
        varInput.style.display = 'none';
    }
}

function onRunnerSelectChange() {
    const select = document.getElementById('runnerIdSelect');
    const varInput = document.getElementById('runnerIdVariableInput');
    if (select.value === '_variable') {
        varInput.style.display = 'block';
    } else {
        varInput.style.display = 'none';
    }
}
</script>
