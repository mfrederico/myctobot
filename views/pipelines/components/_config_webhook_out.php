<?php
/**
 * @step_type: webhook_out
 * @category: integration
 * @label: Webhook
 * @icon: bi-send
 * @color: warning
 * @description: POST to an external service
 */
?>
<div class="config-panel" id="config_webhook_out" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">URL</label>
                <input type="url" class="form-control" name="config_webhook_url" placeholder="https://api.example.com/webhook">
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Method</label>
                        <select class="form-select" name="config_webhook_method">
                            <option value="POST">POST</option>
                            <option value="PUT">PUT</option>
                            <option value="PATCH">PATCH</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Headers (JSON)</label>
                        <input type="text" class="form-control font-monospace" name="config_webhook_headers"
                               placeholder='{"Authorization": "Bearer ..."}'>
                    </div>
                </div>
            </div>
            <div class="mb-0">
                <label class="form-label">Body Template (JSON)</label>
                <textarea class="form-control font-monospace" name="config_webhook_body" rows="3"
                          placeholder='{"status": "{prev.output.status}"}'></textarea>
            </div>
        </div>
    </div>
</div>
