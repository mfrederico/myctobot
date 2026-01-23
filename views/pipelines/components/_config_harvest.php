<?php
/**
 * @step_type: harvest
 * @category: flow
 * @label: Harvest
 * @icon: bi-collection
 * @color: success
 * @description: Gather results from parallel rows
 */
?>
<div class="config-panel" id="config_harvest" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Harvest Policy</label>
                        <select class="form-select" name="config_harvest_policy">
                            <option value="all_required">All Required - fail if any failed</option>
                            <option value="any_success">Any Success - pass if at least one succeeded</option>
                            <option value="best_effort">Best Effort - always pass, collect results</option>
                        </select>
                        <small class="text-muted">How to handle incomplete/failed parallel rows</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">On Incomplete</label>
                        <select class="form-select" name="config_harvest_on_incomplete">
                            <option value="fail">Fail Pipeline</option>
                            <option value="continue">Continue with Partial Results</option>
                            <option value="goto">Goto Error Handler</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Output Template (optional, jq expression)</label>
                <textarea class="form-control font-monospace" name="config_harvest_template" rows="3"
                          placeholder='{"artifacts": [.[] | select(.status == "success") | .output]}'></textarea>
                <small class="text-muted">Leave empty for default structure. Use jq to reshape harvested results.</small>
            </div>
        </div>
    </div>
</div>
