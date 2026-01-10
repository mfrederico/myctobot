<div class="container py-4">
    <!-- Header with Back Button -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="/enterprise" class="btn btn-outline-secondary btn-sm mb-2">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
            <h1 class="h2 mb-0"><?= htmlspecialchars($job['issue_key']) ?></h1>
        </div>
        <span class="badge fs-5 <?php
            switch ($job['status']) {
                case 'complete': echo 'bg-success'; break;
                case 'pr_created': echo 'bg-success'; break;
                case 'failed': echo 'bg-danger'; break;
                case 'waiting_clarification': echo 'bg-warning'; break;
                case 'running': echo 'bg-info'; break;
                default: echo 'bg-secondary';
            }
        ?>">
            <?= ucfirst(str_replace('_', ' ', $job['status'])) ?>
        </span>
    </div>

    <div class="row">
        <!-- Job Details -->
        <div class="col-lg-4 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="bi bi-info-circle"></i> Job Details</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <th>Issue Key</th>
                            <td><?= htmlspecialchars($job['issue_key']) ?></td>
                        </tr>
                        <tr>
                            <th>Status</th>
                            <td><?= ucfirst(str_replace('_', ' ', $job['status'])) ?></td>
                        </tr>
                        <tr>
                            <th>Run Count</th>
                            <td><?= $job['run_count'] ?? 0 ?></td>
                        </tr>
                        <?php if (!empty($job['branch_name'])): ?>
                        <tr>
                            <th>Branch</th>
                            <td><code><?= htmlspecialchars($job['branch_name']) ?></code></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($job['pr_url'])): ?>
                        <tr>
                            <th>Pull Request</th>
                            <td>
                                <a href="<?= htmlspecialchars($job['pr_url']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-github"></i> PR #<?= $job['pr_number'] ?? 'View' ?>
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th>Created</th>
                            <td><?= htmlspecialchars($job['created_at'] ?? '-') ?></td>
                        </tr>
                        <?php if (!empty($job['started_at'])): ?>
                        <tr>
                            <th>Started</th>
                            <td><?= htmlspecialchars($job['started_at']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($job['completed_at'])): ?>
                        <tr>
                            <th>Completed</th>
                            <td><?= htmlspecialchars($job['completed_at']) ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>

                    <!-- Actions -->
                    <div class="mt-3">
                        <?php if ($job['status'] === 'waiting_clarification'): ?>
                        <button class="btn btn-warning btn-sm w-100" onclick="resumeJob('<?= htmlspecialchars($job['issue_key']) ?>')">
                            <i class="bi bi-play-fill"></i> Resume Job
                        </button>
                        <?php endif; ?>

                        <?php if (in_array($job['status'], ['failed', 'pr_created', 'complete']) && !empty($job['branch_name'])): ?>
                        <button class="btn btn-primary btn-sm w-100 mb-2" onclick="retryJob('<?= htmlspecialchars($job['issue_key']) ?>')">
                            <i class="bi bi-arrow-repeat"></i> Retry on Branch
                        </button>
                        <?php endif; ?>

                        <?php if ($job['status'] === 'pr_created'): ?>
                        <button class="btn btn-success btn-sm w-100" onclick="completeJob('<?= htmlspecialchars($job['issue_key']) ?>')">
                            <i class="bi bi-check-lg"></i> Mark Complete
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Error Message -->
            <?php if (!empty($job['error_message'])): ?>
            <div class="card mt-3 border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-exclamation-triangle"></i> Error</h5>
                </div>
                <div class="card-body">
                    <pre class="mb-0 text-danger" style="white-space: pre-wrap; word-break: break-word;"><?= htmlspecialchars($job['error_message']) ?></pre>
                </div>
            </div>
            <?php endif; ?>

            <!-- Clarification Questions -->
            <?php if ($job['status'] === 'waiting_clarification' && !empty($job['clarification_questions'])): ?>
            <div class="card mt-3 border-warning">
                <div class="card-header bg-warning">
                    <h5 class="card-title mb-0"><i class="bi bi-question-circle"></i> Clarification Needed</h5>
                </div>
                <div class="card-body">
                    <ol class="mb-0">
                        <?php foreach ($job['clarification_questions'] as $question): ?>
                        <li><?= htmlspecialchars($question) ?></li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Output and Logs -->
        <div class="col-lg-8">
            <!-- Last Output -->
            <?php if (!empty($job['last_output'])): ?>
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="bi bi-terminal"></i> Output</h5>
                    <button class="btn btn-sm btn-outline-secondary" onclick="toggleOutput()">
                        <i class="bi bi-arrows-expand" id="toggle-icon"></i>
                    </button>
                </div>
                <div class="card-body p-0">
                    <pre id="output-content" class="mb-0 p-3 bg-dark text-light" style="max-height: 400px; overflow-y: auto; font-size: 0.85rem; white-space: pre-wrap; word-break: break-word;"><?= htmlspecialchars($job['last_output']) ?></pre>
                </div>
            </div>
            <?php endif; ?>

            <!-- Last Result (JSON) -->
            <?php if (!empty($job['last_result'])): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="bi bi-code-square"></i> Result Data</h5>
                </div>
                <div class="card-body p-0">
                    <pre class="mb-0 p-3 bg-light" style="max-height: 300px; overflow-y: auto; font-size: 0.85rem;"><?= htmlspecialchars(json_encode($job['last_result'], JSON_PRETTY_PRINT)) ?></pre>
                </div>
            </div>
            <?php endif; ?>

            <!-- Files Changed -->
            <?php if (!empty($job['files_changed'])): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="bi bi-file-earmark-diff"></i> Files Changed</h5>
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($job['files_changed'] as $file): ?>
                    <li class="list-group-item"><code><?= htmlspecialchars($file) ?></code></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Job Logs -->
            <?php if (!empty($logs)): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="bi bi-list-ul"></i> Activity Log</h5>
                </div>
                <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-sm mb-0">
                        <thead class="sticky-top bg-white">
                            <tr>
                                <th style="width: 150px;">Time</th>
                                <th style="width: 80px;">Level</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><small class="text-muted"><?= htmlspecialchars($log['created_at']) ?></small></td>
                                <td>
                                    <span class="badge <?php
                                        switch ($log['level']) {
                                            case 'error': echo 'bg-danger'; break;
                                            case 'warning': echo 'bg-warning'; break;
                                            default: echo 'bg-secondary';
                                        }
                                    ?>"><?= htmlspecialchars($log['level']) ?></span>
                                </td>
                                <td><?= htmlspecialchars($log['message']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="card-body text-center text-muted">
                    <i class="bi bi-inbox fs-1"></i>
                    <p class="mt-2">No activity logs yet.</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.cursor-pointer { cursor: pointer; }
</style>

<script>
function toggleOutput() {
    const content = document.getElementById('output-content');
    const icon = document.getElementById('toggle-icon');

    if (content.style.maxHeight === 'none') {
        content.style.maxHeight = '400px';
        icon.className = 'bi bi-arrows-expand';
    } else {
        content.style.maxHeight = 'none';
        icon.className = 'bi bi-arrows-collapse';
    }
}

async function resumeJob(issueKey) {
    if (!confirm('Resume this job?')) return;

    try {
        const response = await fetch('/jobs/resume/' + encodeURIComponent(issueKey), {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'}
        });
        const data = await response.json();

        if (data.success) {
            alert('Job resumed!');
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

async function retryJob(issueKey) {
    if (!confirm('Retry this job on its existing branch?')) return;

    try {
        const response = await fetch('/jobs/retry/' + encodeURIComponent(issueKey), {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'}
        });
        const data = await response.json();

        if (data.success) {
            alert('Retry job started!');
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

async function completeJob(issueKey) {
    if (!confirm('Mark this job as complete?')) return;

    try {
        const response = await fetch('/jobs/complete/' + encodeURIComponent(issueKey), {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'}
        });
        const data = await response.json();

        if (data.success) {
            alert('Job marked as complete!');
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

// Auto-refresh for running jobs
<?php if ($job['status'] === 'running'): ?>
setTimeout(function() {
    location.reload();
}, 10000); // Refresh every 10 seconds
<?php endif; ?>
</script>
