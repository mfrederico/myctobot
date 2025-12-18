<div class="container py-4">
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/enterprise">AI Developer</a></li>
            <li class="breadcrumb-item active">Jobs</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2 mb-0">AI Developer Jobs</h1>
        <a href="/enterprise" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> New Job
        </a>
    </div>

    <!-- Active Jobs -->
    <?php if (!empty($activeJobs)): ?>
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="card-title mb-0"><i class="bi bi-activity"></i> Active Jobs</h5>
        </div>
        <div class="card-body">
            <?php foreach ($activeJobs as $job): ?>
            <div class="border rounded p-3 mb-3" id="active-job-<?= htmlspecialchars($job['job_id']) ?>">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="mb-1">
                            <strong><?= htmlspecialchars($job['issue_key']) ?></strong>
                            <span class="badge <?php
                                switch ($job['status']) {
                                    case 'waiting_clarification': echo 'bg-warning'; break;
                                    case 'running': echo 'bg-info'; break;
                                    default: echo 'bg-secondary';
                                }
                            ?> ms-2">
                                <?= ucfirst(str_replace('_', ' ', $job['status'])) ?>
                            </span>
                        </h6>
                        <small class="text-muted">
                            Started: <?= htmlspecialchars($job['started_at']) ?>
                        </small>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-secondary" onclick="showJobLogs('<?= htmlspecialchars($job['job_id']) ?>')">
                            <i class="bi bi-terminal"></i> Logs
                        </button>
                    </div>
                </div>

                <div class="mt-3">
                    <div class="d-flex justify-content-between mb-1">
                        <small><?= htmlspecialchars($job['current_step']) ?></small>
                        <small><?= $job['progress'] ?>%</small>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar <?= $job['status'] === 'running' ? 'progress-bar-striped progress-bar-animated' : '' ?>"
                             role="progressbar" style="width: <?= $job['progress'] ?>%"></div>
                    </div>
                </div>

                <?php if ($job['status'] === 'waiting_clarification'): ?>
                <div class="alert alert-warning mt-3 mb-0 py-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-hourglass-split"></i>
                            Waiting for clarification response in Jira.
                            <?php if (!empty($job['clarification_questions'])): ?>
                            <br><small>Questions asked: <?= count($job['clarification_questions']) ?></small>
                            <?php endif; ?>
                        </div>
                        <button class="btn btn-sm btn-success" onclick="resumeJob('<?= htmlspecialchars($job['job_id']) ?>')">
                            <i class="bi bi-play-fill"></i> Resume Job
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- All Jobs -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">Job History</h5>
        </div>
        <?php if (empty($jobs)): ?>
        <div class="card-body">
            <div class="text-center py-5">
                <i class="bi bi-inbox display-4 text-muted"></i>
                <p class="text-muted mt-2">No jobs yet.</p>
                <a href="/enterprise" class="btn btn-primary">Start Your First Job</a>
            </div>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Issue</th>
                        <th>Status</th>
                        <th>Branch</th>
                        <th>PR</th>
                        <th>Started</th>
                        <th>Completed</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($job['issue_key']) ?></strong>
                        </td>
                        <td>
                            <span class="badge <?php
                                switch ($job['status']) {
                                    case 'complete': echo 'bg-success'; break;
                                    case 'failed': echo 'bg-danger'; break;
                                    case 'cancelled': echo 'bg-secondary'; break;
                                    case 'waiting_clarification': echo 'bg-warning'; break;
                                    case 'running': echo 'bg-info'; break;
                                    default: echo 'bg-secondary';
                                }
                            ?>">
                                <?= ucfirst(str_replace('_', ' ', $job['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($job['branch_name'])): ?>
                            <code class="small"><?= htmlspecialchars($job['branch_name']) ?></code>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($job['pr_url'])): ?>
                            <a href="<?= htmlspecialchars($job['pr_url']) ?>" target="_blank" class="btn btn-sm btn-outline-success">
                                <i class="bi bi-github"></i> #<?= $job['pr_number'] ?? 'View' ?>
                            </a>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><small><?= htmlspecialchars($job['started_at']) ?></small></td>
                        <td>
                            <?php if (!empty($job['completed_at'])): ?>
                            <small><?= htmlspecialchars($job['completed_at']) ?></small>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($job['status'] === 'waiting_clarification'): ?>
                            <button class="btn btn-sm btn-success" onclick="resumeJob('<?= htmlspecialchars($job['job_id']) ?>')" title="Resume Job">
                                <i class="bi bi-play-fill"></i>
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-outline-secondary" onclick="showJobLogs('<?= htmlspecialchars($job['job_id']) ?>')" title="View Logs">
                                <i class="bi bi-terminal"></i>
                            </button>
                            <?php if (!empty($job['error'])): ?>
                            <button class="btn btn-sm btn-outline-danger" onclick="alert('<?= htmlspecialchars(addslashes($job['error'])) ?>')" title="View Error">
                                <i class="bi bi-exclamation-triangle"></i>
                            </button>
                            <?php endif; ?>
                            <?php if (!empty($job['branch_name']) && in_array($job['status'], ['complete', 'failed'])): ?>
                            <button class="btn btn-sm btn-outline-primary" onclick="retryJob('<?= htmlspecialchars($job['job_id']) ?>')" title="Retry on Same Branch">
                                <i class="bi bi-arrow-clockwise"></i> Retry
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Job Logs Modal -->
<div class="modal fade" id="logsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Job Logs</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <pre id="logs-content" class="bg-dark text-light p-3 rounded" style="max-height: 400px; overflow-y: auto;">Loading...</pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
async function retryJob(jobId) {
    if (!confirm('Retry this job on the same branch/PR? This will create a new implementation attempt based on updated ticket info.')) {
        return;
    }

    const btn = event.target.closest('button');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    try {
        const response = await fetch('/enterprise/retryjob/' + jobId, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'}
        });
        const data = await response.json();

        if (data.success) {
            alert('Retry job started! ' + (data.message || ''));
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    } catch (err) {
        alert('Error: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

async function resumeJob(jobId) {
    if (!confirm('Resume this job? Make sure you have answered the clarification questions in Jira.')) {
        return;
    }

    const btn = event.target.closest('button');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    try {
        const response = await fetch('/enterprise/resumejob/' + jobId, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'}
        });
        const data = await response.json();

        if (data.success) {
            alert('Job resumed! The AI Developer is now processing your clarifications.');
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    } catch (err) {
        alert('Error: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

async function showJobLogs(jobId) {
    const modal = new bootstrap.Modal(document.getElementById('logsModal'));
    const logsContent = document.getElementById('logs-content');

    logsContent.textContent = 'Loading...';
    modal.show();

    try {
        const response = await fetch('/enterprise/joblogs/' + jobId);
        const data = await response.json();

        if (data.success && data.logs) {
            if (data.logs.length === 0) {
                logsContent.textContent = 'No logs available.';
            } else {
                logsContent.textContent = data.logs.map(log =>
                    `[${log.timestamp}] [${log.level.toUpperCase()}] ${log.message}` +
                    (log.context && Object.keys(log.context).length > 0 ? '\n  ' + JSON.stringify(log.context) : '')
                ).join('\n');
            }
        } else {
            logsContent.textContent = 'Error loading logs: ' + (data.error || 'Unknown error');
        }
    } catch (err) {
        logsContent.textContent = 'Error: ' + err.message;
    }
}

// Auto-refresh active jobs every 5 seconds
<?php if (!empty($activeJobs)): ?>
setInterval(async function() {
    <?php foreach ($activeJobs as $job): ?>
    try {
        const response = await fetch('/enterprise/jobstatus/<?= htmlspecialchars($job['job_id']) ?>');
        const data = await response.json();

        if (data.success && data.status) {
            const status = data.status;
            const el = document.getElementById('active-job-<?= htmlspecialchars($job['job_id']) ?>');

            if (el) {
                // Update progress bar
                const progressBar = el.querySelector('.progress-bar');
                if (progressBar) {
                    progressBar.style.width = status.progress + '%';
                }

                // Update current step
                const stepEl = el.querySelector('small');
                if (stepEl) {
                    stepEl.textContent = status.current_step;
                }

                // If job completed, refresh page
                if (status.status === 'complete' || status.status === 'failed') {
                    location.reload();
                }
            }
        }
    } catch (e) {
        console.error('Error refreshing job status:', e);
    }
    <?php endforeach; ?>
}, 5000);
<?php endif; ?>
</script>
