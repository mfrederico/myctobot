<?php
/**
 * @step_type: wait
 * @category: flow
 * @label: Wait
 * @icon: bi-hourglass-split
 * @color: secondary
 * @description: Wait for delay, approval, or webhook
 */
?>
<div class="config-panel" id="config_wait" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Wait Type</label>
                        <select class="form-select" name="config_wait_type">
                            <option value="delay">Delay (seconds)</option>
                            <option value="approval">Manual Approval</option>
                            <option value="webhook">Wait for Webhook</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Duration (seconds)</label>
                        <input type="number" class="form-control" name="config_wait_duration" value="60" min="1">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
