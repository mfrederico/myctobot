<div class="container-fluid">
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><i class="bi bi-hdd-network"></i> Shard Analysis in Progress</h1>
                <a href="/analysis" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>

            <div class="card">
                <div class="card-header bg-info text-white">
                    <i class="bi bi-kanban"></i> <?= htmlspecialchars($job['board_name']) ?>
                    <code class="ms-2 text-white-50"><?= htmlspecialchars($job['project_key']) ?></code>
                </div>
                <div class="card-body text-center py-5">
                    <!-- Progress Animation -->
                    <div class="position-relative d-inline-block mb-4">
                        <div class="spinner-border text-info" role="status" style="width: 5rem; height: 5rem;" id="mainSpinner">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <div class="position-absolute top-50 start-50 translate-middle">
                            <i class="bi bi-hdd-network h3 text-info mb-0" id="shardIcon"></i>
                        </div>
                    </div>

                    <!-- Status -->
                    <h4 id="statusMessage">Analysis running on shard...</h4>

                    <!-- Status Badge -->
                    <p class="mb-3">
                        Status: <span class="badge bg-info" id="statusBadge"><?= ucfirst($job['status']) ?></span>
                    </p>

                    <!-- Items Count -->
                    <div class="mb-3" id="itemsCountContainer" style="display: none;">
                        <span class="display-6 text-primary" id="itemsCount">0</span>
                        <p class="text-muted mb-0">issues analyzed</p>
                    </div>

                    <!-- Job Details -->
                    <div class="text-muted small">
                        <p class="mb-1">Job ID: <code><?= htmlspecialchars($job['job_id']) ?></code></p>
                        <p class="mb-1">Started: <?= $job['started_at'] ?? $job['created_at'] ?></p>
                        <p class="mb-1" id="lastUpdated" style="display: none;">Last update: <span></span></p>
                    </div>
                </div>
            </div>

            <!-- What's Happening -->
            <div class="card mt-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-list-check"></i> Shard Processing Steps</span>
                    <span class="badge bg-secondary" id="elapsedTime">0:00</span>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-center mb-3" id="step-connecting">
                        <i class="bi bi-check-circle-fill text-success me-3 step-icon"></i>
                        <span>Job queued on shard</span>
                    </div>
                    <div class="d-flex align-items-center mb-3" id="step-fetching_jira">
                        <div class="spinner-border spinner-border-sm text-info me-3 step-icon" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <span>Connecting to Jira via MCP</span>
                    </div>
                    <div class="d-flex align-items-center mb-3 text-muted" id="step-analyzing">
                        <i class="bi bi-circle me-3 step-icon"></i>
                        <span>Claude analyzing sprint issues</span>
                    </div>
                    <div class="d-flex align-items-center mb-3 text-muted" id="step-generating_report">
                        <i class="bi bi-circle me-3 step-icon"></i>
                        <span>Generating markdown report</span>
                    </div>
                    <div class="d-flex align-items-center text-muted" id="step-sending">
                        <i class="bi bi-circle me-3 step-icon"></i>
                        <span>Sending results to MyCTOBot</span>
                    </div>
                </div>
            </div>

            <!-- Error Message (hidden by default) -->
            <div class="alert alert-danger mt-4 d-none" id="errorAlert">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Shard Analysis Failed:</strong> <span id="errorMessage"></span>
                <div class="mt-2">
                    <a href="/analysis/runshard/<?= $job['board_id'] ?? '' ?>" class="btn btn-outline-danger btn-sm">Try Again</a>
                </div>
            </div>

            <!-- Success Message (hidden by default) -->
            <div class="alert alert-success mt-4 d-none" id="successAlert">
                <i class="bi bi-check-circle"></i>
                <strong>Shard Analysis Complete!</strong> Redirecting to results...
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const jobId = '<?= htmlspecialchars($jobId) ?>';
    const pollInterval = 3000; // 3 seconds (shard analysis is slower)
    let pollTimer = null;
    let pollCount = 0;
    const maxPolls = 400; // ~20 minutes max (matches shard timeout)

    const phaseOrder = ['connecting', 'fetching_jira', 'analyzing', 'generating_report', 'sending'];

    function formatElapsed(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return mins + ':' + (secs < 10 ? '0' : '') + secs;
    }

    function updatePhaseUI(currentPhase) {
        const currentIndex = phaseOrder.indexOf(currentPhase);

        phaseOrder.forEach((phase, index) => {
            const step = document.getElementById('step-' + phase);
            if (!step) return;

            const iconContainer = step.querySelector('.step-icon');
            if (!iconContainer) return;

            if (index < currentIndex) {
                // Completed
                iconContainer.outerHTML = '<i class="bi bi-check-circle-fill text-success me-3 step-icon"></i>';
                step.classList.remove('text-muted');
            } else if (index === currentIndex) {
                // In progress
                iconContainer.outerHTML = '<div class="spinner-border spinner-border-sm text-info me-3 step-icon" role="status"><span class="visually-hidden">Loading...</span></div>';
                step.classList.remove('text-muted');
            }
            // Future steps remain muted with circle
        });
    }

    function pollStatus() {
        pollCount++;

        fetch('/analysis/shardstatus/' + encodeURIComponent(jobId), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(result => {
            if (result.success && result.data) {
                const data = result.data;

                // Update status badge
                const statusBadge = document.getElementById('statusBadge');
                statusBadge.textContent = (data.status || 'queued').charAt(0).toUpperCase() + (data.status || 'queued').slice(1);

                if (data.status === 'running') {
                    statusBadge.className = 'badge bg-primary';
                    document.getElementById('statusMessage').textContent = 'Analysis running on shard...';
                }

                // Update phase UI
                if (data.phase) {
                    updatePhaseUI(data.phase);
                }

                // Update elapsed time
                if (data.elapsed_seconds > 0) {
                    document.getElementById('elapsedTime').textContent = formatElapsed(data.elapsed_seconds);
                }

                // Update items count if available
                if (data.items_count > 0) {
                    document.getElementById('itemsCountContainer').style.display = 'block';
                    document.getElementById('itemsCount').textContent = data.items_count;
                }

                // Update last updated time
                if (data.updated) {
                    document.getElementById('lastUpdated').style.display = 'block';
                    document.getElementById('lastUpdated').querySelector('span').textContent = data.updated;
                }

                // Check for completion
                if (data.status === 'completed') {
                    clearInterval(pollTimer);
                    statusBadge.className = 'badge bg-success';
                    document.getElementById('mainSpinner').className = '';
                    document.getElementById('mainSpinner').innerHTML = '<i class="bi bi-check-circle-fill text-success" style="font-size: 5rem;"></i>';
                    const shardIcon = document.getElementById('shardIcon');
                    if (shardIcon) shardIcon.remove();
                    document.getElementById('statusMessage').textContent = 'Analysis complete!';
                    document.getElementById('successAlert').classList.remove('d-none');

                    // Mark all steps complete
                    updatePhaseUI('complete');

                    // Redirect to results
                    if (data.analysis_id) {
                        setTimeout(function() {
                            window.location.href = '/analysis/view/' + data.analysis_id;
                        }, 1500);
                    } else {
                        setTimeout(function() {
                            window.location.href = '/analysis';
                        }, 1500);
                    }
                }

                // Check for failure
                if (data.status === 'failed') {
                    clearInterval(pollTimer);
                    statusBadge.className = 'badge bg-danger';
                    document.getElementById('mainSpinner').className = '';
                    document.getElementById('mainSpinner').innerHTML = '<i class="bi bi-x-circle-fill text-danger" style="font-size: 5rem;"></i>';
                    const shardIcon = document.getElementById('shardIcon');
                    if (shardIcon) shardIcon.remove();
                    document.getElementById('statusMessage').textContent = 'Analysis failed';
                    document.getElementById('errorMessage').textContent = data.error || 'Unknown error';
                    document.getElementById('errorAlert').classList.remove('d-none');
                }
            }

            // Safety: stop polling after max polls
            if (pollCount >= maxPolls) {
                clearInterval(pollTimer);
                document.getElementById('statusMessage').textContent = 'Polling timeout - check back later';
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
