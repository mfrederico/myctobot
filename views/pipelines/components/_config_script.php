<?php
/**
 * @step_type: script
 * @category: core
 * @label: Script
 * @icon: bi-file-code
 * @color: info
 * @description: Pull and execute a script from a repo
 *
 * Required view data: $repos
 */
?>
<div class="config-panel" id="config_script" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Repository</label>
                        <select class="form-select" name="config_repo_id">
                            <option value="">Select a repository...</option>
                            <?php foreach ($repos as $repo): ?>
                            <option value="<?= $repo['id'] ?>"><?= htmlspecialchars($repo['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Script Path</label>
                        <input type="text" class="form-control" name="config_script_path" placeholder="scripts/deploy.sh">
                    </div>
                </div>
            </div>
            <div class="mb-0">
                <label class="form-label">Arguments</label>
                <input type="text" class="form-control" name="config_script_args" placeholder="--env=staging">
            </div>
        </div>
    </div>
</div>
