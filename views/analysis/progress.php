<div class="container-fluid">
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>Analysis in Progress</h1>
                <a href="/analysis" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>

            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-kanban"></i> <?= htmlspecialchars($status['board_name']) ?>
                </div>
                <div class="card-body text-center py-5">
                    <!-- Progress Circle -->
                    <div class="position-relative d-inline-block mb-4">
                        <div class="spinner-border text-primary" role="status" style="width: 5rem; height: 5rem;" id="mainSpinner">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <div class="position-absolute top-50 start-50 translate-middle">
                            <span class="h4 mb-0" id="progressPercent"><?= $status['progress'] ?>%</span>
                        </div>
                    </div>

                    <!-- Progress Bar -->
                    <div class="progress mb-3" style="height: 25px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated"
                             role="progressbar"
                             id="progressBar"
                             style="width: <?= $status['progress'] ?>%"
                             aria-valuenow="<?= $status['progress'] ?>"
                             aria-valuemin="0"
                             aria-valuemax="100">
                            <?= $status['progress'] ?>%
                        </div>
                    </div>

                    <!-- Current Step -->
                    <h5 id="currentStep" class="mb-3"><?= htmlspecialchars($status['current_step']) ?></h5>

                    <!-- Status Badge -->
                    <p class="mb-0">
                        Status: <span class="badge bg-info" id="statusBadge"><?= ucfirst($status['status']) ?></span>
                    </p>

                    <!-- Started time -->
                    <p class="text-muted small mt-3">
                        Started: <?= date('M j, Y H:i:s', strtotime($status['started_at'])) ?>
                    </p>
                </div>
            </div>

            <!-- Steps Completed -->
            <div class="card mt-4">
                <div class="card-header">
                    <i class="bi bi-list-check"></i> Progress Log
                </div>
                <div class="card-body" style="max-height: 300px; overflow-y: auto;" id="stepsLog">
                    <?php if (!empty($status['steps_completed'])): ?>
                        <?php foreach ($status['steps_completed'] as $step): ?>
                        <div class="d-flex align-items-center mb-2">
                            <i class="bi bi-check-circle-fill text-success me-2"></i>
                            <span><?= htmlspecialchars($step['step']) ?></span>
                            <small class="text-muted ms-auto"><?= date('H:i:s', strtotime($step['timestamp'])) ?></small>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted mb-0" id="noStepsMsg">Waiting for progress updates...</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Error Message (hidden by default) -->
            <div class="alert alert-danger mt-4 d-none" id="errorAlert">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Analysis Failed:</strong> <span id="errorMessage"></span>
            </div>

            <!-- Success Message (hidden by default) -->
            <div class="alert alert-success mt-4 d-none" id="successAlert">
                <i class="bi bi-check-circle"></i>
                <strong>Analysis Complete!</strong> Redirecting to results...
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const jobId = '<?= htmlspecialchars($jobId) ?>';
    const pollInterval = 2000; // 2 seconds
    let pollTimer = null;
    let lastStepCount = <?= count($status['steps_completed'] ?? []) ?>;

    function updateUI(data) {
        // Update progress
        const progress = data.progress || 0;
        document.getElementById('progressPercent').textContent = progress + '%';
        document.getElementById('progressBar').style.width = progress + '%';
        document.getElementById('progressBar').textContent = progress + '%';
        document.getElementById('progressBar').setAttribute('aria-valuenow', progress);

        // Update current step
        document.getElementById('currentStep').textContent = data.current_step || 'Processing...';

        // Update status badge
        const statusBadge = document.getElementById('statusBadge');
        statusBadge.textContent = (data.status || 'pending').charAt(0).toUpperCase() + (data.status || 'pending').slice(1);

        if (data.status === 'complete') {
            statusBadge.className = 'badge bg-success';
        } else if (data.status === 'failed') {
            statusBadge.className = 'badge bg-danger';
        } else if (data.status === 'running') {
            statusBadge.className = 'badge bg-primary';
        }

        // Update steps log if there are new steps
        const steps = data.steps_completed || [];
        if (steps.length > lastStepCount) {
            const stepsLog = document.getElementById('stepsLog');
            const noStepsMsg = document.getElementById('noStepsMsg');
            if (noStepsMsg) {
                noStepsMsg.remove();
            }

            // Add only new steps
            for (let i = lastStepCount; i < steps.length; i++) {
                const step = steps[i];
                const stepDiv = document.createElement('div');
                stepDiv.className = 'd-flex align-items-center mb-2';
                stepDiv.innerHTML = `
                    <i class="bi bi-check-circle-fill text-success me-2"></i>
                    <span>${escapeHtml(step.step)}</span>
                    <small class="text-muted ms-auto">${formatTime(step.timestamp)}</small>
                `;
                stepsLog.appendChild(stepDiv);
            }
            lastStepCount = steps.length;

            // Scroll to bottom
            stepsLog.scrollTop = stepsLog.scrollHeight;
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatTime(timestamp) {
        const date = new Date(timestamp);
        return date.toTimeString().split(' ')[0];
    }

    function pollStatus() {
        fetch('/analysis/status/' + encodeURIComponent(jobId), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(result => {
            if (result.success && result.data) {
                const data = result.data;
                updateUI(data);

                // Check for completion
                if (data.status === 'complete') {
                    clearInterval(pollTimer);
                    document.getElementById('mainSpinner').className = '';
                    document.getElementById('mainSpinner').innerHTML = '<i class="bi bi-check-circle-fill text-success" style="font-size: 5rem;"></i>';
                    document.getElementById('successAlert').classList.remove('d-none');

                    // Redirect to results
                    setTimeout(function() {
                        window.location.href = '/analysis/view/' + data.analysis_id;
                    }, 1500);
                }

                // Check for failure
                if (data.status === 'failed') {
                    clearInterval(pollTimer);
                    document.getElementById('mainSpinner').className = '';
                    document.getElementById('mainSpinner').innerHTML = '<i class="bi bi-x-circle-fill text-danger" style="font-size: 5rem;"></i>';
                    document.getElementById('progressBar').classList.remove('progress-bar-animated');
                    document.getElementById('progressBar').classList.add('bg-danger');
                    document.getElementById('errorMessage').textContent = data.error || 'Unknown error';
                    document.getElementById('errorAlert').classList.remove('d-none');
                }
            }
        })
        .catch(error => {
            console.error('Poll error:', error);
        });
    }

    // Start polling
    pollTimer = setInterval(pollStatus, pollInterval);

    // Initial poll
    pollStatus();
})();
</script>
