<?php
/**
 * @step_type: reaper
 * @category: flow
 * @label: Session Reaper
 * @icon: bi-x-octagon
 * @color: danger
 * @description: Terminate Claude CLI sessions gracefully
 */
?>
<div class="config-panel" id="config_reaper" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <div class="alert alert-info mb-3">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Session Reaper</strong> sends <code>/exit</code> to Claude CLI sessions to terminate them gracefully.
                Use this after AI agent steps complete to clean up resident sessions.
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Job UID</label>
                        <input type="text" class="form-control" name="config_reaper_job_uid"
                               placeholder="{spawn_agent.output.job_key}">
                        <small class="text-muted">Specific job UID to terminate. Supports variable substitution.</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Session Pattern</label>
                        <input type="text" class="form-control" name="config_reaper_session_pattern"
                               placeholder="aidev-*">
                        <small class="text-muted">Tmux session name pattern to match (optional).</small>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Timeout (seconds)</label>
                        <input type="number" class="form-control" name="config_reaper_timeout"
                               value="30" min="5" max="300">
                        <small class="text-muted">Time to wait for graceful shutdown.</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3 pt-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="config_reaper_wait_for_idle" checked>
                            <label class="form-check-label">Wait for Idle</label>
                        </div>
                        <small class="text-muted">Wait for Claude to be idle before sending /exit.</small>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="config_reaper_force_kill">
                            <label class="form-check-label text-danger">Force Kill (Last Resort)</label>
                        </div>
                        <small class="text-muted text-danger">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Only enable if graceful shutdown fails. May leave stale processes on remote servers.
                        </small>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Step Names to Reap (optional)</label>
                <input type="text" class="form-control" name="config_reaper_step_names"
                       placeholder="agent_step, analyze_step">
                <small class="text-muted">Comma-separated list of step names whose sessions to terminate.</small>
            </div>
        </div>
    </div>
</div>
