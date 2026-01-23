<?php
/**
 * @step_type: direct_exec
 * @category: core
 * @label: Shell Command
 * @icon: bi-terminal
 * @color: dark
 * @description: Execute a shell command with stdin/stdout
 *
 * Required view data: $workstations
 */
?>
<div class="config-panel" id="config_direct_exec" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Command / Code</label>
                <textarea class="form-control font-monospace" name="config_command" rows="3"
                          placeholder="echo 'Hello World'"></textarea>
                <small class="text-muted">Use <code>{context.key}</code> or <code>{step_name.output.key}</code> for variables</small>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Executor</label>
                        <input type="text" class="form-control font-monospace" name="config_executor"
                               placeholder="/bin/bash -c" list="executorOptions">
                        <datalist id="executorOptions">
                            <option value="/bin/bash -c">Bash shell</option>
                            <option value="/bin/zsh -c">Zsh shell</option>
                            <option value="/bin/sh -c">POSIX shell</option>
                            <option value="/usr/bin/python3 -c">Python code</option>
                            <option value="/usr/bin/php -r">PHP code</option>
                            <option value="node -e">Node.js code</option>
                            <option value="">Direct (no wrapper)</option>
                        </datalist>
                        <small class="text-muted">How to run the command. Default: <code>/bin/bash -c</code></small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Workstation (SSH)</label>
                        <select class="form-select" name="config_workstation_id">
                            <option value="">Local execution</option>
                            <?php foreach ($workstations ?? [] as $ws): ?>
                            <option value="<?= $ws['id'] ?>"><?= htmlspecialchars($ws['name']) ?> (<?= $ws['ssh_user'] ?>@<?= $ws['ssh_host'] ?><?= $ws['ssh_port'] != 22 ? ':' . $ws['ssh_port'] : '' ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Run on remote server via SSH</small>
                    </div>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Working Directory</label>
                <input type="text" class="form-control" name="config_working_dir" placeholder="/tmp">
            </div>

            <div class="d-flex justify-content-between align-items-start mb-3">
                <div class="alert alert-warning mb-0 flex-grow-1 me-3 py-2">
                    <i class="bi bi-exclamation-triangle"></i>
                    <small>Test runs locally on the server. Use with caution.</small>
                </div>
                <button type="button" class="btn btn-outline-dark" onclick="testShellCommand()" id="testShellCommandBtn">
                    <i class="bi bi-play-circle"></i> Test Command
                </button>
            </div>

            <div id="shellCommandResult" style="display: none;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label mb-0"><strong>Command Output</strong></label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('shellCommandResult').style.display='none'">
                        <i class="bi bi-x"></i> Close
                    </button>
                </div>
                <pre class="bg-dark text-light p-3 rounded" style="max-height: 300px; overflow: auto; font-size: 0.85rem;"><code id="shellCommandResultContent"></code></pre>
            </div>
        </div>
    </div>
</div>
