<div class="container py-4" data-assist-page="reviewboard/project" data-assist-purpose="Project-specific AI job review board">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <a href="/reviewboard" class="text-decoration-none text-muted">
                        <i class="bi bi-arrow-left"></i> Back to Review Board
                    </a>
                    <h1 class="mt-2"><i class="bi bi-diagram-3"></i> <?= h($project->name) ?></h1>
                    <?php if ($directive): ?>
                    <p class="text-muted mb-0">
                        <small>
                            <i class="bi bi-envelope"></i> From: <?= h($directive->email_subject) ?>
                            <span class="ms-2"><i class="bi bi-calendar"></i> <?= date('M j, Y g:i a', strtotime($project->created_at)) ?></span>
                        </small>
                    </p>
                    <?php endif; ?>
                </div>
                <div>
                    <button class="btn btn-outline-primary" onclick="editProject()">
                        <i class="bi bi-pencil"></i> Edit Project
                    </button>
                </div>
            </div>

            <!-- Project Description -->
            <?php if ($project->description): ?>
            <div class="card mb-4">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 text-muted">Description</h6>
                    <p class="card-text"><?= nl2br(h($project->description)) ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Epics and Stories -->
            <?php if (empty($epics)): ?>
            <div class="alert alert-info">
                <h4 class="alert-heading"><i class="bi bi-inbox"></i> No Epics</h4>
                <p>This project doesn't have any epics yet.</p>
            </div>
            <?php else: ?>

            <?php foreach ($epics as $epicData): ?>
            <?php $epic = $epicData['epic']; ?>
            <?php $stories = $epicData['stories']; ?>
            <div class="card mb-4" id="epic-<?= $epic->id ?>">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <div>
                        <i class="bi bi-collection"></i>
                        <strong><?= h($epic->title) ?></strong>
                        <span class="badge bg-secondary ms-2"><?= count($stories) ?> stories</span>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-primary" onclick="editEpic('<?= $epic->epic_uid ?>')">
                            <i class="bi bi-pencil"></i> Edit Epic
                        </button>
                        <button class="btn btn-sm btn-outline-success" onclick="addStory('<?= $epic->epic_uid ?>')">
                            <i class="bi bi-plus"></i> Add Story
                        </button>
                    </div>
                </div>
                <?php if ($epic->description): ?>
                <div class="card-body border-bottom bg-white">
                    <small class="text-muted"><?= nl2br(h($epic->description)) ?></small>
                </div>
                <?php endif; ?>
                <div class="card-body p-0">
                    <?php if (empty($stories)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-inbox"></i> No stories in this epic
                    </div>
                    <?php else: ?>
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Story</th>
                                <th style="width: 80px;">Points</th>
                                <th style="width: 120px;">Status</th>
                                <th style="width: 180px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stories as $story): ?>
                            <tr id="story-row-<?= $story->story_uid ?>">
                                <td>
                                    <strong><?= h($story->title) ?></strong>
                                    <?php if ($story->jira_issue_key): ?>
                                    <br><small class="text-success"><i class="bi bi-link-45deg"></i> <?= h($story->jira_issue_key) ?></small>
                                    <?php endif; ?>
                                    <?php if ($story->description): ?>
                                    <br><small class="text-muted"><?= h(substr($story->description, 0, 100)) ?><?= strlen($story->description) > 100 ? '...' : '' ?></small>
                                    <?php endif; ?>
                                    <?php
                                    $criteria = json_decode($story->acceptance_criteria, true);
                                    if (!empty($criteria)):
                                    ?>
                                    <br><small class="text-muted"><i class="bi bi-check2-square"></i> <?= count($criteria) ?> acceptance criteria</small>
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
                                        'review' => 'info',
                                        'done' => 'success',
                                        'blocked' => 'danger',
                                    ];
                                    $color = $statusColors[$story->status] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $color ?>"><?= ucfirst(str_replace('_', ' ', $story->status)) ?></span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" onclick="editStory('<?= $story->story_uid ?>')" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php if ($story->status === 'pending_review'): ?>
                                    <button class="btn btn-sm btn-outline-success" onclick="approveStory('<?= $story->story_uid ?>')" title="Approve">
                                        <i class="bi bi-check"></i>
                                    </button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteStory('<?= $story->story_uid ?>')" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary" onclick="moveStory('<?= $story->story_uid ?>')" title="Move to Epic">
                                        <i class="bi bi-arrows-move"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Add Epic Button -->
            <div class="text-center mb-4">
                <button class="btn btn-outline-primary" onclick="addEpic()">
                    <i class="bi bi-plus-lg"></i> Add New Epic
                </button>
            </div>

            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Edit Project Modal -->
<div class="modal fade" id="editProjectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-diagram-3"></i> Edit Project</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Project Name</label>
                    <input type="text" class="form-control" id="edit-project-name" value="<?= h($project->name) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" id="edit-project-description" rows="4"><?= h($project->description ?? '') ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveProject()">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Epic Modal -->
<div class="modal fade" id="editEpicModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-collection"></i> <span id="epic-modal-title">Edit Epic</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit-epic-id">
                <div class="mb-3">
                    <label class="form-label">Epic Title</label>
                    <input type="text" class="form-control" id="edit-epic-title">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" id="edit-epic-description" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveEpic()">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Story Modal -->
<div class="modal fade" id="editStoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil"></i> <span id="story-modal-title">Edit Story</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit-story-id">
                <input type="hidden" id="edit-story-epic-id">
                <div class="mb-3">
                    <label class="form-label">Title</label>
                    <input type="text" class="form-control" id="edit-story-title">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" id="edit-story-description" rows="4"></textarea>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Story Points</label>
                            <select class="form-select" id="edit-story-points">
                                <option value="1">1</option>
                                <option value="2">2</option>
                                <option value="3">3</option>
                                <option value="5">5</option>
                                <option value="8">8</option>
                                <option value="13">13</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" id="edit-story-status">
                                <option value="pending_review">Pending Review</option>
                                <option value="approved">Approved</option>
                                <option value="backlog">Backlog</option>
                                <option value="ready">Ready</option>
                                <option value="in_progress">In Progress</option>
                                <option value="review">Review</option>
                                <option value="done">Done</option>
                                <option value="blocked">Blocked</option>
                            </select>
                        </div>
                    </div>
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

<!-- Move Story Modal -->
<div class="modal fade" id="moveStoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-arrows-move"></i> Move Story</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="move-story-id">
                <div class="mb-3">
                    <label class="form-label">Move to Epic</label>
                    <select class="form-select" id="move-story-epic">
                        <?php foreach ($epics as $epicData): ?>
                        <option value="<?= $epicData['epic']->epic_uid ?>"><?= h($epicData['epic']->title) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmMoveStory()">Move Story</button>
            </div>
        </div>
    </div>
</div>

<script>
const projectId = '<?= h($project->project_uid) ?>';

// AJAX helper
async function apiCall(endpoint, data) {
    const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });
    return response.json();
}

// Project actions
function editProject() {
    new bootstrap.Modal(document.getElementById('editProjectModal')).show();
}

async function saveProject() {
    const name = document.getElementById('edit-project-name').value;
    const description = document.getElementById('edit-project-description').value;

    const result = await apiCall('/reviewboard/updateproject', {
        project_uid: projectId,
        name: name,
        description: description
    });

    if (result.success) {
        location.reload();
    } else {
        alert('Error: ' + result.message);
    }
}

// Epic actions
function editEpic(epicId) {
    document.getElementById('epic-modal-title').textContent = 'Edit Epic';
    document.getElementById('edit-epic-id').value = epicId;
    // Would need to fetch epic data or store it in data attributes
    new bootstrap.Modal(document.getElementById('editEpicModal')).show();
}

function addEpic() {
    document.getElementById('epic-modal-title').textContent = 'Add Epic';
    document.getElementById('edit-epic-id').value = '';
    document.getElementById('edit-epic-title').value = '';
    document.getElementById('edit-epic-description').value = '';
    new bootstrap.Modal(document.getElementById('editEpicModal')).show();
}

async function saveEpic() {
    const epicId = document.getElementById('edit-epic-id').value;
    const title = document.getElementById('edit-epic-title').value;
    const description = document.getElementById('edit-epic-description').value;

    const endpoint = epicId ? '/reviewboard/updateepic' : '/reviewboard/createepic';
    const result = await apiCall(endpoint, {
        project_uid: projectId,
        epic_id: epicId || undefined,
        title: title,
        description: description
    });

    if (result.success) {
        location.reload();
    } else {
        alert('Error: ' + result.message);
    }
}

// Story actions
function editStory(storyId) {
    document.getElementById('story-modal-title').textContent = 'Edit Story';
    document.getElementById('edit-story-id').value = storyId;
    document.getElementById('edit-story-epic-id').value = '';
    // Clear fields - would normally fetch story data
    document.getElementById('edit-story-title').value = '';
    document.getElementById('edit-story-description').value = '';
    document.getElementById('edit-acceptance-criteria').innerHTML = '';
    new bootstrap.Modal(document.getElementById('editStoryModal')).show();

    // Fetch story data
    fetchStoryData(storyId);
}

async function fetchStoryData(storyId) {
    const result = await apiCall('/reviewboard/getstory', { story_id: storyId });
    if (result.success) {
        const story = result.data;
        document.getElementById('edit-story-title').value = story.title || '';
        document.getElementById('edit-story-description').value = story.description || '';
        document.getElementById('edit-story-points').value = story.story_points || '3';
        document.getElementById('edit-story-status').value = story.status || 'pending_review';

        // Load acceptance criteria
        const container = document.getElementById('edit-acceptance-criteria');
        container.innerHTML = '';
        const criteria = story.acceptance_criteria || [];
        criteria.forEach(c => {
            addCriterionWithValue(c);
        });
    }
}

function addStory(epicId) {
    document.getElementById('story-modal-title').textContent = 'Add Story';
    document.getElementById('edit-story-id').value = '';
    document.getElementById('edit-story-epic-id').value = epicId;
    document.getElementById('edit-story-title').value = '';
    document.getElementById('edit-story-description').value = '';
    document.getElementById('edit-story-points').value = '3';
    document.getElementById('edit-story-status').value = 'pending_review';
    document.getElementById('edit-acceptance-criteria').innerHTML = '';
    new bootstrap.Modal(document.getElementById('editStoryModal')).show();
}

async function saveStory() {
    const storyId = document.getElementById('edit-story-id').value;
    const epicId = document.getElementById('edit-story-epic-id').value;
    const title = document.getElementById('edit-story-title').value;
    const description = document.getElementById('edit-story-description').value;
    const points = document.getElementById('edit-story-points').value;
    const status = document.getElementById('edit-story-status').value;

    // Gather acceptance criteria
    const criteria = [];
    document.querySelectorAll('#edit-acceptance-criteria input').forEach(input => {
        if (input.value.trim()) {
            criteria.push(input.value.trim());
        }
    });

    const endpoint = storyId ? '/reviewboard/updatestory' : '/reviewboard/createstory';
    const result = await apiCall(endpoint, {
        story_id: storyId || undefined,
        epic_id: epicId || undefined,
        title: title,
        description: description,
        story_points: points,
        status: status,
        acceptance_criteria: criteria
    });

    if (result.success) {
        bootstrap.Modal.getInstance(document.getElementById('editStoryModal')).hide();
        location.reload();
    } else {
        alert('Error: ' + result.message);
    }
}

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

    const result = await apiCall('/reviewboard/deletestory', { story_id: storyId });
    if (result.success) {
        document.getElementById('story-row-' + storyId)?.remove();
    } else {
        alert('Error: ' + result.message);
    }
}

function moveStory(storyId) {
    document.getElementById('move-story-id').value = storyId;
    new bootstrap.Modal(document.getElementById('moveStoryModal')).show();
}

async function confirmMoveStory() {
    const storyId = document.getElementById('move-story-id').value;
    const epicId = document.getElementById('move-story-epic').value;

    const result = await apiCall('/reviewboard/movestory', {
        story_id: storyId,
        epic_id: epicId
    });

    if (result.success) {
        location.reload();
    } else {
        alert('Error: ' + result.message);
    }
}

// Acceptance criteria helpers
function addCriterion() {
    addCriterionWithValue('');
}

function addCriterionWithValue(value) {
    const container = document.getElementById('edit-acceptance-criteria');
    const div = document.createElement('div');
    div.className = 'input-group mb-2';

    // Handle object format (given/when/then) or string format
    let displayValue = value;
    if (typeof value === 'object' && value !== null) {
        if (value.given && value.when && value.then) {
            displayValue = `Given ${value.given}, When ${value.when}, Then ${value.then}`;
        } else {
            displayValue = JSON.stringify(value);
        }
    }

    div.innerHTML = `
        <input type="text" class="form-control" placeholder="Acceptance criterion..." value="${escapeHtml(displayValue)}">
        <button type="button" class="btn btn-outline-danger" onclick="this.parentElement.remove()">
            <i class="bi bi-x"></i>
        </button>
    `;
    container.appendChild(div);
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
$workspaceSlug = $_SESSION['workspace_slug'] ?? 'default';
include __DIR__ . '/../partials/pmchatbox.php';
?>
