<?php
/**
 * @step_type: schedule_task
 * @category: flow
 * @label: Schedule Task
 * @icon: bi-calendar-event
 * @color: info
 * @description: Schedule a future task (pipeline, webhook, etc.)
 */
?>
<div class="config-panel" id="config_schedule_task" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Task Type</label>
                        <select class="form-select" name="config_schedule_task_type" onchange="toggleScheduleTaskConfig(this)">
                            <option value="execute_pipeline">Execute Pipeline</option>
                            <option value="webhook_call">Webhook Call</option>
                            <option value="revert_action">Revert Action</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Delay</label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="config_schedule_delay" value="3600" min="1">
                            <select class="form-select" name="config_schedule_delay_unit" style="max-width: 120px;">
                                <option value="1">seconds</option>
                                <option value="60">minutes</option>
                                <option value="3600" selected>hours</option>
                                <option value="86400">days</option>
                            </select>
                        </div>
                        <div class="form-text">Time until task executes</div>
                    </div>
                </div>
            </div>

            <!-- Execute Pipeline Config -->
            <div id="schedule_pipeline_config">
                <hr>
                <h6 class="text-muted"><i class="bi bi-diagram-3"></i> Pipeline Configuration</h6>

                <div class="row">
                    <div class="col-md-8">
                        <div class="mb-3">
                            <label class="form-label">Pipeline</label>
                            <select class="form-select" name="config_schedule_pipeline_id">
                                <option value="">-- Select Pipeline --</option>
                                <option value="_self">This Pipeline (self)</option>
                                <?php
                                // Get all active pipelines
                                $pipelines = \app\Bean::find('pipelines', 'is_active = 1 ORDER BY name');
                                foreach ($pipelines as $p): ?>
                                <option value="<?= $p->id ?>"><?= htmlspecialchars($p->name) ?> (<?= $p->slug ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Entry Step <small class="text-muted">(optional)</small></label>
                            <input type="text" class="form-control" name="config_schedule_entry_step"
                                   placeholder="step_name">
                            <div class="form-text">Start from this step</div>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Input Data (JSON)</label>
                    <textarea class="form-control font-monospace" name="config_schedule_input_data" rows="3"
                              placeholder='{"key": "{context.value}"}'></textarea>
                    <div class="form-text">Context passed to the scheduled pipeline. Supports variable substitution.</div>
                </div>
            </div>

            <!-- Webhook Config (hidden by default) -->
            <div id="schedule_webhook_config" style="display: none;">
                <hr>
                <h6 class="text-muted"><i class="bi bi-send"></i> Webhook Configuration</h6>

                <div class="mb-3">
                    <label class="form-label">URL</label>
                    <input type="url" class="form-control" name="config_schedule_webhook_url"
                           placeholder="https://api.example.com/callback">
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Method</label>
                            <select class="form-select" name="config_schedule_webhook_method">
                                <option value="POST">POST</option>
                                <option value="PUT">PUT</option>
                                <option value="GET">GET</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="mb-3">
                            <label class="form-label">Headers (JSON)</label>
                            <input type="text" class="form-control font-monospace" name="config_schedule_webhook_headers"
                                   placeholder='{"Authorization": "Bearer ..."}'>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Body (JSON)</label>
                    <textarea class="form-control font-monospace" name="config_schedule_webhook_body" rows="3"
                              placeholder='{"message": "Scheduled reminder"}'></textarea>
                </div>
            </div>

            <!-- Revert Config (hidden by default) -->
            <div id="schedule_revert_config" style="display: none;">
                <hr>
                <h6 class="text-muted"><i class="bi bi-arrow-counterclockwise"></i> Revert Configuration</h6>

                <div class="alert alert-info small">
                    <i class="bi bi-info-circle"></i>
                    Revert actions use data from previous steps. The revert data should be stored in
                    <code>{prev.output._revert_state}</code> by an earlier step.
                </div>
            </div>

            <div class="alert alert-secondary small mt-3 mb-0">
                <i class="bi bi-lightbulb"></i>
                <strong>How it works:</strong> This step creates a scheduled task that will execute at the specified delay.
                The pipeline continues immediately (non-blocking). Use for reminders, delayed actions, or cleanup tasks.
            </div>
        </div>
    </div>
</div>

<script>
function toggleScheduleTaskConfig(select) {
    const pipelineConfig = document.getElementById('schedule_pipeline_config');
    const webhookConfig = document.getElementById('schedule_webhook_config');
    const revertConfig = document.getElementById('schedule_revert_config');

    pipelineConfig.style.display = 'none';
    webhookConfig.style.display = 'none';
    revertConfig.style.display = 'none';

    switch (select.value) {
        case 'execute_pipeline':
            pipelineConfig.style.display = 'block';
            break;
        case 'webhook_call':
            webhookConfig.style.display = 'block';
            break;
        case 'revert_action':
            revertConfig.style.display = 'block';
            break;
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const taskTypeSelect = document.querySelector('select[name="config_schedule_task_type"]');
    if (taskTypeSelect) {
        toggleScheduleTaskConfig(taskTypeSelect);
    }
});
</script>
