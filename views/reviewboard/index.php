<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><i class="bi bi-clipboard-check"></i> Review Board</h1>
                <div>
                    <a href="/directives" class="btn btn-outline-secondary">
                        <i class="bi bi-envelope-paper"></i> Directives
                    </a>
                    <a href="/dashboard" class="btn btn-outline-secondary">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </div>
            </div>

            <!-- Summary Stats -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card bg-warning text-dark">
                        <div class="card-body text-center">
                            <h2 class="mb-0"><?= $totalPending ?></h2>
                            <small>Pending Review</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <h2 class="mb-0"><?= $totalApproved ?></h2>
                            <small>Approved</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <h2 class="mb-0"><?= count($projects) ?></h2>
                            <small>Active Projects</small>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (empty($projects)): ?>
            <div class="alert alert-info">
                <h4 class="alert-heading"><i class="bi bi-inbox"></i> No Stories to Review</h4>
                <p>When CEO directives are processed, stories will appear here for review before becoming issues.</p>
                <hr>
                <p class="mb-0">
                    Send a directive to <code><?= htmlspecialchars($_SESSION['tenant_slug'] ?? 'your-tenant') ?>@myctobot.ai</code> to get started.
                </p>
            </div>
            <?php else: ?>

            <!-- Projects with Pending Stories -->
            <?php foreach ($projects as $projectData): ?>
            <?php $project = $projectData['project']; ?>
            <?php $directive = $projectData['directive']; ?>
            <div class="card mb-4" id="project-<?= htmlspecialchars($project->project_id) ?>">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <div>
                        <i class="bi bi-diagram-3"></i>
                        <strong><?= htmlspecialchars($project->name) ?></strong>
                        <?php if ($projectData['pending_count'] > 0): ?>
                        <span class="badge bg-warning text-dark ms-2"><?= $projectData['pending_count'] ?> pending</span>
                        <?php endif; ?>
                        <?php if ($projectData['approved_count'] > 0): ?>
                        <span class="badge bg-success ms-1"><?= $projectData['approved_count'] ?> approved</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ($projectData['pending_count'] > 0): ?>
                        <button class="btn btn-sm btn-success" onclick="approveProject('<?= $project->project_id ?>')">
                            <i class="bi bi-check-all"></i> Approve All
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="deleteProject('<?= $project->project_id ?>')">
                            <i class="bi bi-trash"></i> Delete All
                        </button>
                        <?php endif; ?>
                        <a href="/reviewboard/project/<?= htmlspecialchars($project->project_id) ?>" class="btn btn-sm btn-light">
                            <i class="bi bi-pencil"></i> Edit
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($directive): ?>
                    <p class="text-muted mb-3">
                        <small>
                            <i class="bi bi-envelope"></i> From directive: <?= htmlspecialchars($directive->email_subject) ?>
                            <span class="ms-2"><i class="bi bi-calendar"></i> <?= date('M j, Y g:i a', strtotime($project->created_at)) ?></span>
                        </small>
                    </p>
                    <?php endif; ?>

                    <!-- Epics -->
                    <?php foreach ($projectData['epics'] as $epicData): ?>
                    <?php $epic = $epicData['epic']; ?>
                    <div class="card mb-3">
                        <div class="card-header bg-light d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-collection"></i>
                                <strong><?= htmlspecialchars($epic->title) ?></strong>
                                <?php if ($epicData['pending_count'] > 0): ?>
                                <span class="badge bg-warning text-dark ms-2"><?= $epicData['pending_count'] ?> pending</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 30px;">
                                            <input type="checkbox" class="form-check-input select-all-epic"
                                                   data-epic="<?= $epic->id ?>"
                                                   onchange="toggleEpicSelection(this, <?= $epic->id ?>)">
                                        </th>
                                        <th>Story</th>
                                        <th style="width: 80px;">Points</th>
                                        <th style="width: 120px;">Status</th>
                                        <th style="width: 150px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($epicData['stories'] as $story): ?>
                                    <tr id="story-row-<?= $story->story_id ?>" class="<?= $story->status === 'pending_review' ? '' : 'table-secondary' ?>">
                                        <td>
                                            <?php if ($story->status === 'pending_review'): ?>
                                            <input type="checkbox" class="form-check-input story-checkbox"
                                                   data-story-id="<?= $story->story_id ?>"
                                                   data-epic="<?= $epic->id ?>">
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($story->title) ?></strong>
                                            <?php if ($story->jira_issue_key): ?>
                                            <br><small class="text-success"><i class="bi bi-link-45deg"></i> <?= htmlspecialchars($story->jira_issue_key) ?></small>
                                            <?php endif; ?>
                                            <?php
                                            $criteria = json_decode($story->acceptance_criteria, true);
                                            if (!empty($criteria)):
                                            ?>
                                            <br><small class="text-muted"><?= count($criteria) ?> acceptance criteria</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?= $story->story_points ?? '?' ?> pts</span>
                                        </td>
                                        <td>
                                            <?php
                                            $statusColors = [
                                                'pending_review' => 'warning',
                                                'approved' => 'success',
                                                'backlog' => 'secondary',
                                                'ready' => 'info',
                                                'in_progress' => 'primary',
                                                'done' => 'success',
                                            ];
                                            $color = $statusColors[$story->status] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $color ?>"><?= ucfirst(str_replace('_', ' ', $story->status)) ?></span>
                                            <?php if ($story->_job): ?>
                                            <?php
                                                $job = $story->_job;
                                                $jobStatusColors = [
                                                    'pending' => 'secondary',
                                                    'running' => 'primary',
                                                    'waiting_clarification' => 'warning',
                                                    'preview_ready' => 'info',
                                                    'pr_created' => 'info',
                                                    'complete' => 'success',
                                                    'failed' => 'danger',
                                                    'cancelled' => 'secondary',
                                                ];
                                                $jobColor = $jobStatusColors[$job['status']] ?? 'secondary';
                                                $jobIcons = [
                                                    'pending' => 'hourglass',
                                                    'running' => 'gear-fill',
                                                    'waiting_clarification' => 'question-circle',
                                                    'preview_ready' => 'eye',
                                                    'pr_created' => 'git-pull-request',
                                                    'complete' => 'check-circle-fill',
                                                    'failed' => 'x-circle-fill',
                                                    'cancelled' => 'slash-circle',
                                                ];
                                                $jobIcon = $jobIcons[$job['status']] ?? 'circle';
                                            ?>
                                            <br>
                                            <span class="badge bg-<?= $jobColor ?>" title="AI Dev: <?= htmlspecialchars($job['current_step']) ?>">
                                                <i class="bi bi-<?= $jobIcon ?>"></i>
                                                <?php if ($job['status'] === 'running'): ?>
                                                    <?= $job['progress'] ?>%
                                                <?php else: ?>
                                                    <?= ucfirst(str_replace('_', ' ', $job['status'])) ?>
                                                <?php endif; ?>
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($story->status === 'pending_review'): ?>
                                            <button class="btn btn-sm btn-outline-success" onclick="approveStory('<?= $story->story_id ?>')" title="Approve">
                                                <i class="bi bi-check"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-primary" onclick="editStory('<?= $story->story_id ?>')" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteStory('<?= $story->story_id ?>')" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                            <?php elseif ($story->_job): ?>
                                            <button class="btn btn-sm btn-outline-info" onclick="viewJobResults('<?= htmlspecialchars($story->jira_issue_key) ?>')" title="View Results">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <?php if ($story->_job['pr_url']): ?>
                                            <a href="<?= htmlspecialchars($story->_job['pr_url']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="View PR">
                                                <i class="bi bi-git"></i>
                                            </a>
                                            <?php endif; ?>
                                            <?php else: ?>
                                            <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Bulk Actions (floating) -->
            <div id="bulk-actions" class="position-fixed bottom-0 start-50 translate-middle-x mb-4 p-3 bg-dark text-white rounded shadow" style="display: none; z-index: 1000;">
                <span id="selected-count">0</span> stories selected
                <button class="btn btn-success btn-sm ms-3" onclick="approveSelected()">
                    <i class="bi bi-check-all"></i> Approve Selected
                </button>
                <button class="btn btn-danger btn-sm ms-2" onclick="deleteSelected()">
                    <i class="bi bi-trash"></i> Delete Selected
                </button>
                <button class="btn btn-secondary btn-sm ms-2" onclick="clearSelection()">
                    Cancel
                </button>
            </div>

            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Edit Story Modal -->
<div class="modal fade" id="editStoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Story</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit-story-id">
                <div class="mb-3">
                    <label class="form-label">Title</label>
                    <input type="text" class="form-control" id="edit-story-title">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" id="edit-story-description" rows="4"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Story Points</label>
                    <select class="form-select" id="edit-story-points" style="width: 100px;">
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                        <option value="5">5</option>
                        <option value="8">8</option>
                        <option value="13">13</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Acceptance Criteria</label>
                    <div id="edit-acceptance-criteria"></div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="addCriterion()">
                        <i class="bi bi-plus"></i> Add Criterion
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveStory()">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Job Results Modal -->
<div class="modal fade" id="jobResultsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-robot"></i> AI Dev Job Results</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="job-results-loading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading job details...</p>
                </div>
                <div id="job-results-content" style="display: none;">
                    <!-- Job Summary -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <strong><i class="bi bi-info-circle"></i> Job Summary</strong>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm mb-0">
                                        <tr>
                                            <th style="width: 120px;">Status</th>
                                            <td><span id="job-status-badge" class="badge"></span></td>
                                        </tr>
                                        <tr>
                                            <th>Issue</th>
                                            <td><code id="job-issue-key"></code></td>
                                        </tr>
                                        <tr>
                                            <th>Progress</th>
                                            <td>
                                                <div class="progress" style="height: 20px;">
                                                    <div id="job-progress-bar" class="progress-bar" role="progressbar"></div>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Current Step</th>
                                            <td id="job-current-step"></td>
                                        </tr>
                                        <tr>
                                            <th>Started</th>
                                            <td id="job-started-at"></td>
                                        </tr>
                                        <tr>
                                            <th>Completed</th>
                                            <td id="job-completed-at"></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <strong><i class="bi bi-git"></i> Git Details</strong>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm mb-0">
                                        <tr>
                                            <th style="width: 120px;">Branch</th>
                                            <td><code id="job-branch-name">-</code></td>
                                        </tr>
                                        <tr>
                                            <th>PR</th>
                                            <td id="job-pr-link">-</td>
                                        </tr>
                                        <tr>
                                            <th>Commit</th>
                                            <td><code id="job-commit-sha">-</code></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Error Message (if failed) -->
                    <div id="job-error-section" class="alert alert-danger" style="display: none;">
                        <strong><i class="bi bi-exclamation-triangle"></i> Error:</strong>
                        <pre id="job-error-message" class="mb-0 mt-2" style="white-space: pre-wrap;"></pre>
                    </div>

                    <!-- Files Changed -->
                    <div id="job-files-section" class="card mb-4" style="display: none;">
                        <div class="card-header bg-light">
                            <strong><i class="bi bi-file-earmark-diff"></i> Files Changed</strong>
                            <span id="job-files-count" class="badge bg-secondary ms-2"></span>
                        </div>
                        <div class="card-body p-0">
                            <ul id="job-files-list" class="list-group list-group-flush"></ul>
                        </div>
                    </div>

                    <!-- Job Logs -->
                    <div class="card">
                        <div class="card-header bg-light d-flex justify-content-between align-items-center">
                            <strong><i class="bi bi-journal-text"></i> Job Logs</strong>
                            <button class="btn btn-sm btn-outline-secondary" onclick="toggleJobLogs()">
                                <i class="bi bi-chevron-down" id="job-logs-toggle-icon"></i>
                            </button>
                        </div>
                        <div id="job-logs-container" class="card-body p-0" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-striped mb-0">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th style="width: 150px;">Time</th>
                                        <th style="width: 80px;">Level</th>
                                        <th>Message</th>
                                    </tr>
                                </thead>
                                <tbody id="job-logs-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Store for edit modal
let currentStoryData = {};

// Selection tracking
let selectedStories = new Set();

function updateBulkActions() {
    const bulkActions = document.getElementById('bulk-actions');
    const countSpan = document.getElementById('selected-count');
    countSpan.textContent = selectedStories.size;
    bulkActions.style.display = selectedStories.size > 0 ? 'block' : 'none';
}

function toggleEpicSelection(checkbox, epicId) {
    const checkboxes = document.querySelectorAll(`input.story-checkbox[data-epic="${epicId}"]`);
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
        if (checkbox.checked) {
            selectedStories.add(cb.dataset.storyId);
        } else {
            selectedStories.delete(cb.dataset.storyId);
        }
    });
    updateBulkActions();
}

document.addEventListener('change', function(e) {
    if (e.target.classList.contains('story-checkbox')) {
        if (e.target.checked) {
            selectedStories.add(e.target.dataset.storyId);
        } else {
            selectedStories.delete(e.target.dataset.storyId);
        }
        updateBulkActions();
    }
});

function clearSelection() {
    selectedStories.clear();
    document.querySelectorAll('.story-checkbox, .select-all-epic').forEach(cb => cb.checked = false);
    updateBulkActions();
}

// AJAX helpers
async function apiCall(endpoint, data) {
    const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });
    return response.json();
}

// Story actions
async function approveStory(storyId) {
    if (!confirm('Approve this story and create a GitHub/Jira issue?')) return;

    const result = await apiCall('/reviewboard/approve', { story_ids: [storyId] });
    if (result.success) {
        location.reload();
    } else {
        alert('Error: ' + result.message);
    }
}

async function deleteStory(storyId) {
    if (!confirm('Delete this story? This cannot be undone.')) return;

    const result = await apiCall('/reviewboard/delete-story', { story_id: storyId });
    if (result.success) {
        document.getElementById('story-row-' + storyId)?.remove();
    } else {
        alert('Error: ' + result.message);
    }
}

function editStory(storyId) {
    // Fetch story data (for now, get from DOM or make API call)
    // For simplicity, we'll show the modal with current values
    document.getElementById('edit-story-id').value = storyId;

    // Get story row
    const row = document.getElementById('story-row-' + storyId);
    if (row) {
        const title = row.querySelector('strong').textContent;
        document.getElementById('edit-story-title').value = title;
    }

    // Show modal
    new bootstrap.Modal(document.getElementById('editStoryModal')).show();
}

async function saveStory() {
    const storyId = document.getElementById('edit-story-id').value;
    const title = document.getElementById('edit-story-title').value;
    const description = document.getElementById('edit-story-description').value;
    const points = document.getElementById('edit-story-points').value;

    // Gather acceptance criteria
    const criteria = [];
    document.querySelectorAll('#edit-acceptance-criteria input').forEach(input => {
        if (input.value.trim()) {
            criteria.push(input.value.trim());
        }
    });

    const result = await apiCall('/reviewboard/update-story', {
        story_id: storyId,
        title: title,
        description: description,
        story_points: points,
        acceptance_criteria: criteria
    });

    if (result.success) {
        bootstrap.Modal.getInstance(document.getElementById('editStoryModal')).hide();
        location.reload();
    } else {
        alert('Error: ' + result.message);
    }
}

function addCriterion() {
    const container = document.getElementById('edit-acceptance-criteria');
    const div = document.createElement('div');
    div.className = 'input-group mb-2';
    div.innerHTML = `
        <input type="text" class="form-control" placeholder="Acceptance criterion...">
        <button type="button" class="btn btn-outline-danger" onclick="this.parentElement.remove()">
            <i class="bi bi-x"></i>
        </button>
    `;
    container.appendChild(div);
}

// Bulk actions
async function approveSelected() {
    if (selectedStories.size === 0) return;
    if (!confirm(`Approve ${selectedStories.size} stories and create issues?`)) return;

    const result = await apiCall('/reviewboard/approve', { story_ids: Array.from(selectedStories) });
    if (result.success) {
        location.reload();
    } else {
        alert('Error: ' + result.message);
    }
}

async function deleteSelected() {
    if (selectedStories.size === 0) return;
    if (!confirm(`Delete ${selectedStories.size} stories? This cannot be undone.`)) return;

    for (const storyId of selectedStories) {
        await apiCall('/reviewboard/delete-story', { story_id: storyId });
    }
    location.reload();
}

// Project actions
async function approveProject(projectId) {
    if (!confirm('Approve ALL pending stories in this project?')) return;

    const result = await apiCall('/reviewboard/approve-project', { project_id: projectId });
    if (result.success) {
        location.reload();
    } else {
        alert('Error: ' + result.message);
    }
}

async function deleteProject(projectId) {
    if (!confirm('Delete ALL pending stories in this project? This cannot be undone.')) return;

    const result = await apiCall('/reviewboard/delete-project', { project_id: projectId });
    if (result.success) {
        location.reload();
    } else {
        alert('Error: ' + result.message);
    }
}

// Job Results Modal Functions
async function viewJobResults(issueKey) {
    // Show modal with loading state
    document.getElementById('job-results-loading').style.display = 'block';
    document.getElementById('job-results-content').style.display = 'none';

    new bootstrap.Modal(document.getElementById('jobResultsModal')).show();

    try {
        const result = await apiCall('/reviewboard/get-job-details', { issue_key: issueKey });

        if (result.success) {
            displayJobResults(result.data.job, result.data.logs);
        } else {
            alert('Error: ' + result.message);
            bootstrap.Modal.getInstance(document.getElementById('jobResultsModal')).hide();
        }
    } catch (e) {
        alert('Failed to load job details');
        bootstrap.Modal.getInstance(document.getElementById('jobResultsModal')).hide();
    }
}

function displayJobResults(job, logs) {
    // Status badge
    const statusColors = {
        'pending': 'secondary',
        'running': 'primary',
        'waiting_clarification': 'warning',
        'preview_ready': 'info',
        'pr_created': 'info',
        'complete': 'success',
        'failed': 'danger',
        'cancelled': 'secondary'
    };
    const statusBadge = document.getElementById('job-status-badge');
    statusBadge.className = `badge bg-${statusColors[job.status] || 'secondary'}`;
    statusBadge.textContent = job.status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());

    // Basic info
    document.getElementById('job-issue-key').textContent = job.issue_key;
    document.getElementById('job-current-step').textContent = job.current_step || '-';
    document.getElementById('job-started-at').textContent = job.started_at || '-';
    document.getElementById('job-completed-at').textContent = job.completed_at || '-';

    // Progress bar
    const progressBar = document.getElementById('job-progress-bar');
    progressBar.style.width = `${job.progress}%`;
    progressBar.textContent = `${job.progress}%`;
    progressBar.className = `progress-bar bg-${statusColors[job.status] || 'secondary'}`;

    // Git details
    document.getElementById('job-branch-name').textContent = job.branch_name || '-';
    document.getElementById('job-commit-sha').textContent = job.commit_sha ? job.commit_sha.substring(0, 8) : '-';

    // PR link
    const prLinkEl = document.getElementById('job-pr-link');
    if (job.pr_url) {
        prLinkEl.innerHTML = `<a href="${escapeHtml(job.pr_url)}" target="_blank" class="text-decoration-none">
            <i class="bi bi-box-arrow-up-right"></i> View PR #${job.pr_number || ''}
        </a>`;
    } else {
        prLinkEl.textContent = '-';
    }

    // Error section
    const errorSection = document.getElementById('job-error-section');
    if (job.status === 'failed' && job.error) {
        errorSection.style.display = 'block';
        document.getElementById('job-error-message').textContent = job.error;
    } else {
        errorSection.style.display = 'none';
    }

    // Files changed
    const filesSection = document.getElementById('job-files-section');
    const filesList = document.getElementById('job-files-list');
    if (job.files_changed && job.files_changed.length > 0) {
        filesSection.style.display = 'block';
        document.getElementById('job-files-count').textContent = job.files_changed.length;
        filesList.innerHTML = job.files_changed.map(file => `
            <li class="list-group-item py-2">
                <i class="bi bi-file-earmark-code text-muted me-2"></i>
                <code>${escapeHtml(file)}</code>
            </li>
        `).join('');
    } else {
        filesSection.style.display = 'none';
    }

    // Logs
    const logsBody = document.getElementById('job-logs-body');
    if (logs && logs.length > 0) {
        logsBody.innerHTML = logs.map(log => {
            const levelColors = {
                'info': 'text-info',
                'warning': 'text-warning',
                'error': 'text-danger',
                'debug': 'text-muted'
            };
            return `
                <tr>
                    <td class="text-muted small">${escapeHtml(log.timestamp)}</td>
                    <td><span class="${levelColors[log.level] || ''}">${escapeHtml(log.level)}</span></td>
                    <td>${escapeHtml(log.message)}</td>
                </tr>
            `;
        }).join('');
    } else {
        logsBody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">No logs available</td></tr>';
    }

    // Show content, hide loading
    document.getElementById('job-results-loading').style.display = 'none';
    document.getElementById('job-results-content').style.display = 'block';
}

function toggleJobLogs() {
    const container = document.getElementById('job-logs-container');
    const icon = document.getElementById('job-logs-toggle-icon');

    if (container.style.maxHeight === 'none') {
        container.style.maxHeight = '300px';
        icon.className = 'bi bi-chevron-down';
    } else {
        container.style.maxHeight = 'none';
        icon.className = 'bi bi-chevron-up';
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php
// PM Chatbox - floating assistant for project questions
$ragServiceUrl = getenv('RAG_SERVICE_URL') ?: 'http://localhost:9501';
$tenantSlug = $_SESSION['tenant_slug'] ?? 'default';
$projectId = null; // Could be set if viewing a specific project
include __DIR__ . '/../partials/pm-chatbox.php';
?>
