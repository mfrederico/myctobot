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
                        <select class="form-select" name="config_agent_id">
                            <option value="">Select an agent...</option>
                            <?php foreach ($agents as $agent): ?>
                            <option value="<?= $agent['id'] ?>"><?= htmlspecialchars($agent['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Runner</label>
                        <select class="form-select" name="config_runner_id">
                            <option value="">Default / Auto</option>
                            <?php foreach ($runners as $runner): ?>
                            <option value="<?= $runner['id'] ?>"><?= htmlspecialchars($runner['name']) ?> (<?= $runner['host'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
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
