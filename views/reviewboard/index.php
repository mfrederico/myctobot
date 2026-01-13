<div class="container-fluid">
    <!-- Header with Runner Status -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="mb-0"><i class="bi bi-kanban"></i> Review Board</h1>
        </div>
        <div class="d-flex align-items-center gap-3">
            <!-- Runner Status -->
            <div class="d-flex align-items-center bg-light rounded px-3 py-2" id="runner-status-container">
                <span class="text-muted me-2">AI Runners:</span>
                <select id="runner-limit-select" class="form-select form-select-sm me-2" style="width: 60px;">
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                </select>
                <div class="progress me-2" style="width: 80px; height: 20px;">
                    <div id="runner-progress-bar" class="progress-bar bg-primary" role="progressbar" style="width: 0%"></div>
                </div>
                <span id="runner-count-text" class="small text-muted">0/2</span>
                <button class="btn btn-sm btn-outline-warning ms-2" onclick="checkStaleJobs()" title="Check for crashed jobs">
                    <i class="bi bi-heartbreak"></i>
                </button>
            </div>
            <div>
                <a href="/directives" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-envelope-paper"></i> Directives
                </a>
                <a href="/dashboard" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Stale Jobs Alert (hidden by default) -->
    <div id="stale-jobs-alert" class="alert alert-warning alert-dismissible d-none mb-3" role="alert">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong id="stale-jobs-count">0</strong> job(s) appear to have crashed (tmux session no longer exists).
            </div>
            <div>
                <button class="btn btn-warning btn-sm me-2" onclick="cleanupStaleJobs()">
                    <i class="bi bi-trash"></i> Mark as Failed
                </button>
                <button type="button" class="btn-close" onclick="dismissStaleAlert()"></button>
            </div>
        </div>
        <div id="stale-jobs-list" class="mt-2 small"></div>
    </div>

    <!-- Recent QA Release Banner (loaded from localStorage) -->
    <div id="qa-release-banner" class="alert alert-success alert-dismissible d-none mb-3" role="alert">
        <div class="d-flex justify-content-between align-items-start">
            <div class="flex-grow-1">
                <div class="d-flex align-items-center">
                    <i class="bi bi-git me-2"></i>
                    <strong>QA Release:</strong>
                    <span class="ms-2" id="qa-release-story-count">0</span> stories merged to
                    <code class="ms-1" id="qa-release-target-branch">main</code>
                </div>
                <div id="qa-release-time" class="mt-1"></div>
            </div>
            <div class="d-flex gap-2 align-items-start">
                <a id="qa-release-github-link" href="#" target="_blank" class="btn btn-success btn-sm" style="display: none;">
                    <i class="bi bi-github"></i> View on GitHub
                </a>
                <button id="qa-release-retry-btn" class="btn btn-warning btn-sm" style="display: none;" onclick="retryQARelease()">
                    <i class="bi bi-arrow-clockwise"></i> Retry
                </button>
                <button type="button" class="btn-close" onclick="dismissQAReleaseBanner()"></button>
            </div>
        </div>
    </div>

    <?php if (empty($projects)): ?>
    <div class="alert alert-info">
        <h4 class="alert-heading"><i class="bi bi-inbox"></i> No Stories Yet</h4>
        <p>When CEO directives are processed, stories will appear here for review.</p>
        <hr>
        <p class="mb-0">
            Send a directive to <code><?= htmlspecialchars($_SESSION['tenant_slug'] ?? 'your-tenant') ?>@myctobot.ai</code> to get started.
        </p>
    </div>
    <?php else: ?>

    <?php
    // Collect all stories across projects and categorize by status
    $pendingReview = [];
    $approved = [];
    $inProgress = [];
    $complete = [];
    $merged = [];  // Archived/merged stories
    $failed = [];

    foreach ($projects as $projectData) {
        $project = $projectData['project'];
        foreach ($projectData['epics'] as $epicData) {
            $epic = $epicData['epic'];
            foreach ($epicData['stories'] as $story) {
                $storyCard = [
                    'story' => $story,
                    'project' => $project,
                    'epic' => $epic,
                    'job' => $story->_job ?? null,
                ];

                $job = $story->_job;

                // Check for merged jobs first (regardless of story status)
                if ($job && $job['status'] === 'merged') {
                    $merged[] = $storyCard;
                } elseif ($story->status === 'pending_review') {
                    $pendingReview[] = $storyCard;
                } elseif ($story->status === 'approved') {
                    if (!$job || in_array($job['status'], ['pending', 'queued'])) {
                        $approved[] = $storyCard;
                    } elseif (in_array($job['status'], ['running', 'waiting_clarification', 'preview_ready'])) {
                        $inProgress[] = $storyCard;
                    } elseif (in_array($job['status'], ['pr_created', 'complete'])) {
                        $complete[] = $storyCard;
                    } elseif (in_array($job['status'], ['failed', 'cancelled'])) {
                        $failed[] = $storyCard;
                    } else {
                        // Unknown status - put in approved
                        $approved[] = $storyCard;
                    }
                } elseif ($story->status === 'in_progress') {
                    // Stories marked in_progress by orchestrator
                    $inProgress[] = $storyCard;
                } elseif ($story->status === 'done') {
                    // Stories completed by orchestrator - check job status too
                    if ($job && in_array($job['status'], ['pr_created', 'complete'])) {
                        $complete[] = $storyCard;
                    } elseif ($job && in_array($job['status'], ['failed', 'cancelled'])) {
                        $failed[] = $storyCard;
                    } else {
                        $complete[] = $storyCard;
                    }
                } elseif ($story->status === 'blocked') {
                    // Stories that failed during orchestrator processing
                    $failed[] = $storyCard;
                }
            }
        }
    }

    // Sort all arrays by project name to group stories together
    $sortByProject = fn($a, $b) => strcmp($a['project']->name, $b['project']->name);
    usort($pendingReview, $sortByProject);
    usort($approved, $sortByProject);
    usort($inProgress, $sortByProject);
    usort($complete, $sortByProject);
    usort($failed, $sortByProject);

    // Sort merged by merged_at date (newest first)
    usort($merged, function($a, $b) {
        $aDate = $a['job']['merged_at'] ?? '0';
        $bDate = $b['job']['merged_at'] ?? '0';
        return strcmp($bDate, $aDate);  // Descending
    });

    // Collect unique project names for tabs (excluding merged from main count)
    $allStories = array_merge($pendingReview, $approved, $inProgress, $complete, $failed);
    $projectNames = array_unique(array_map(fn($s) => $s['project']->name, $allStories));
    sort($projectNames);
    ?>

    <!-- Project Tabs -->
    <?php if (count($projectNames) > 1): ?>
    <ul class="nav nav-tabs mb-3" id="projectTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-all" data-project-filter="all" type="button" role="tab">
                <i class="bi bi-grid-3x3"></i> All Projects
                <span class="badge bg-secondary ms-1"><?= count($allStories) ?></span>
            </button>
        </li>
        <?php foreach ($projectNames as $projectName): ?>
        <?php
        $projectSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower($projectName));
        $projectCount = count(array_filter($allStories, fn($s) => $s['project']->name === $projectName));
        ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-<?= $projectSlug ?>" data-project-filter="<?= htmlspecialchars($projectName) ?>" type="button" role="tab">
                <?= htmlspecialchars($projectName) ?>
                <span class="badge bg-secondary ms-1"><?= $projectCount ?></span>
            </button>
        </li>
        <?php endforeach; ?>
        <?php if (count($merged) > 0): ?>
        <li class="nav-item ms-auto" role="presentation">
            <a class="nav-link" href="/reviewboard/history">
                <i class="bi bi-archive"></i> History
                <span class="badge bg-secondary ms-1"><?= count($merged) ?></span>
            </a>
        </li>
        <?php endif; ?>
    </ul>
    <?php endif; ?>

    <?php if (count($merged) > 0 && count($projectNames) <= 1): ?>
    <!-- History link for single project view -->
    <div class="mb-3 text-end">
        <a href="/reviewboard/history" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-archive"></i> History
            <span class="badge bg-secondary ms-1"><?= count($merged) ?></span>
        </a>
    </div>
    <?php endif; ?>

    <!-- Kanban Board -->
    <div class="row g-3">
        <!-- Column 1: Pending Review -->
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-eye"></i> Pending Review</strong>
                    <div>
                        <button class="btn btn-sm btn-dark me-1" onclick="toggleSelectAllPending()" title="Select all for batch approve">
                            <i class="bi bi-check2-square"></i>
                        </button>
                        <span class="badge bg-dark"><?= count($pendingReview) ?></span>
                    </div>
                </div>
                <div class="card-body kanban-column" data-status="pending_review">
                    <?php foreach ($pendingReview as $card): ?>
                    <?php $story = $card['story']; $project = $card['project']; $epic = $card['epic']; ?>
                    <div class="card mb-2 kanban-card" data-story-id="<?= $story->story_id ?>" data-project="<?= htmlspecialchars($project->name) ?>">
                        <div class="card-body p-2">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <div class="form-check">
                                    <input class="form-check-input pending-select-checkbox" type="checkbox"
                                           value="<?= $story->id ?>"
                                           data-story-id="<?= $story->story_id ?>"
                                           data-issue-key="<?= htmlspecialchars($story->jira_issue_key ?? '') ?>"
                                           data-title="<?= htmlspecialchars($story->title) ?>"
                                           onchange="updatePendingSelection()">
                                </div>
                                <small class="text-muted text-truncate" style="max-width: 40%;" title="<?= htmlspecialchars($project->name . ' / ' . $epic->title) ?>">
                                    <?= htmlspecialchars($project->name) ?>
                                </small>
                                <div>
                                    <?php
                                    // Extract GitHub issue number from jira_issue_key (e.g., mfrederico/myctobot#99 → #99)
                                    $issueNum = $story->jira_issue_key && preg_match('/#(\d+)$/', $story->jira_issue_key, $m) ? $m[1] : $story->id;
                                    ?><span class="badge bg-light text-dark" title="<?= htmlspecialchars($story->jira_issue_key ?? 'Story #' . $story->id) ?>">#<?= $issueNum ?></span>
                                    <span class="badge bg-secondary"><?= $story->story_points ?? '?' ?> pts</span>
                                </div>
                            </div>
                            <h6 class="card-title mb-2" style="font-size: 0.9rem;">
                                <?= htmlspecialchars($story->title) ?>
                            </h6>
                            <?php
                            $criteria = json_decode($story->acceptance_criteria, true);
                            if (!empty($criteria)):
                            ?>
                            <small class="text-muted d-block mb-2">
                                <i class="bi bi-check2-square"></i> <?= count($criteria) ?> criteria
                            </small>
                            <?php endif; ?>
                            <div class="btn-group btn-group-sm w-100">
                                <button class="btn btn-success" onclick="approveStory('<?= $story->story_id ?>')" title="Approve">
                                    <i class="bi bi-check"></i>
                                </button>
                                <button class="btn btn-outline-primary" onclick="editStory('<?= $story->story_id ?>')" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-outline-danger" onclick="deleteStory('<?= $story->story_id ?>')" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($pendingReview)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                        <p class="mb-0 mt-2">No stories pending</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Column 2: Approved (Queue) -->
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-hourglass-split"></i> Queued</strong>
                    <span class="badge bg-dark"><?= count($approved) ?></span>
                </div>
                <div class="card-body kanban-column" data-status="approved">
                    <?php foreach ($approved as $card): ?>
                    <?php $story = $card['story']; $project = $card['project']; $epic = $card['epic']; $job = $card['job']; ?>
                    <div class="card mb-2 kanban-card" data-story-id="<?= $story->story_id ?>" data-project="<?= htmlspecialchars($project->name) ?>">
                        <div class="card-body p-2">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <small class="text-muted text-truncate" style="max-width: 60%;">
                                    <?= htmlspecialchars($project->name) ?>
                                </small>
                                <div>
                                    <?php
                                    // Extract GitHub issue number from jira_issue_key (e.g., mfrederico/myctobot#99 → #99)
                                    $issueNum = $story->jira_issue_key && preg_match('/#(\d+)$/', $story->jira_issue_key, $m) ? $m[1] : $story->id;
                                    ?><span class="badge bg-light text-dark" title="<?= htmlspecialchars($story->jira_issue_key ?? 'Story #' . $story->id) ?>">#<?= $issueNum ?></span>
                                    <span class="badge bg-secondary"><?= $story->story_points ?? '?' ?> pts</span>
                                </div>
                            </div>
                            <h6 class="card-title mb-2" style="font-size: 0.9rem;">
                                <?= htmlspecialchars($story->title) ?>
                            </h6>
                            <?php if ($story->jira_issue_key): ?>
                            <small class="text-success d-block mb-2">
                                <i class="bi bi-link-45deg"></i> <?= htmlspecialchars($story->jira_issue_key) ?>
                            </small>
                            <?php endif; ?>
                            <?php if ($job && $job['status'] === 'failed'): ?>
                            <div class="alert alert-danger py-1 px-2 mb-2 small">
                                <i class="bi bi-x-circle"></i> Failed - needs retry
                            </div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="badge bg-info">Waiting</span>
                                <div class="btn-group btn-group-sm">
                                    <?php if ($story->jira_issue_key && !$job): ?>
                                    <button class="btn btn-success" onclick="startJob('<?= htmlspecialchars($story->jira_issue_key) ?>')" title="Start Job">
                                        <i class="bi bi-play-fill"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($story->jira_issue_key): ?>
                                    <button class="btn btn-outline-secondary" onclick="viewJobResults('<?= htmlspecialchars($story->jira_issue_key) ?>')" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($approved)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                        <p class="mb-0 mt-2">Queue empty</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Column 3: In Progress -->
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-gear-fill"></i> In Progress</strong>
                    <span class="badge bg-dark"><?= count($inProgress) ?></span>
                </div>
                <div class="card-body kanban-column" data-status="in_progress">
                    <?php foreach ($inProgress as $card): ?>
                    <?php $story = $card['story']; $project = $card['project']; $job = $card['job']; ?>
                    <div class="card mb-2 kanban-card border-primary" data-story-id="<?= $story->story_id ?>" data-project="<?= htmlspecialchars($project->name) ?>">
                        <div class="card-body p-2">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <small class="text-muted text-truncate" style="max-width: 60%;">
                                    <?= htmlspecialchars($project->name) ?>
                                </small>
                                <div>
                                    <?php
                                    // Extract GitHub issue number from jira_issue_key (e.g., mfrederico/myctobot#99 → #99)
                                    $issueNum = $story->jira_issue_key && preg_match('/#(\d+)$/', $story->jira_issue_key, $m) ? $m[1] : $story->id;
                                    ?><span class="badge bg-light text-dark" title="<?= htmlspecialchars($story->jira_issue_key ?? 'Story #' . $story->id) ?>">#<?= $issueNum ?></span>
                                    <span class="badge bg-secondary"><?= $story->story_points ?? '?' ?> pts</span>
                                </div>
                            </div>
                            <h6 class="card-title mb-2" style="font-size: 0.9rem;">
                                <?= htmlspecialchars($story->title) ?>
                            </h6>
                            <?php if ($story->jira_issue_key): ?>
                            <small class="text-success d-block mb-1">
                                <i class="bi bi-link-45deg"></i> <?= htmlspecialchars($story->jira_issue_key) ?>
                            </small>
                            <?php endif; ?>
                            <?php if ($job): ?>
                            <div class="progress mb-2" style="height: 20px;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated"
                                     role="progressbar"
                                     style="width: <?= $job['progress'] ?>%">
                                    <?= $job['progress'] ?>%
                                </div>
                            </div>
                            <small class="text-muted d-block mb-2">
                                <i class="bi bi-activity"></i> <?= htmlspecialchars($job['current_step'] ?? 'Processing...') ?>
                            </small>
                            <?php if ($job['status'] === 'waiting_clarification'): ?>
                            <div class="alert alert-warning py-1 px-2 mb-2 small">
                                <i class="bi bi-question-circle"></i> Needs clarification
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-outline-info w-100" onclick="viewJobResults('<?= htmlspecialchars($story->jira_issue_key) ?>')">
                                <i class="bi bi-eye"></i> View Details
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($inProgress)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                        <p class="mb-0 mt-2">No active jobs</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Column 4: Complete -->
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-check-circle-fill"></i> Complete</strong>
                    <div>
                        <button class="btn btn-sm btn-light me-1" onclick="toggleSelectAll()" title="Select all for QA merge">
                            <i class="bi bi-check2-square"></i>
                        </button>
                        <span class="badge bg-dark"><?= count($complete) ?></span>
                    </div>
                </div>
                <div class="card-body kanban-column" data-status="complete">
                    <?php foreach ($complete as $card): ?>
                    <?php $story = $card['story']; $project = $card['project']; $job = $card['job']; ?>
                    <div class="card mb-2 kanban-card border-success" data-story-id="<?= $story->story_id ?>" data-project="<?= htmlspecialchars($project->name) ?>" data-issue-key="<?= htmlspecialchars($story->jira_issue_key ?? '') ?>">
                        <div class="card-body p-2">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <div class="form-check">
                                    <input class="form-check-input qa-select-checkbox" type="checkbox"
                                           value="<?= $story->id ?>"
                                           data-story-id="<?= $story->story_id ?>"
                                           data-issue-key="<?= htmlspecialchars($story->jira_issue_key ?? '') ?>"
                                           data-project-id="<?= $project->id ?>"
                                           data-title="<?= htmlspecialchars($story->title) ?>"
                                           onchange="updateQASelection()">
                                </div>
                                <small class="text-muted text-truncate" style="max-width: 50%;">
                                    <?= htmlspecialchars($project->name) ?>
                                </small>
                                <div>
                                    <?php
                                    // Extract GitHub issue number from jira_issue_key (e.g., mfrederico/myctobot#99 → #99)
                                    $issueNum = $story->jira_issue_key && preg_match('/#(\d+)$/', $story->jira_issue_key, $m) ? $m[1] : $story->id;
                                    ?><span class="badge bg-light text-dark" title="<?= htmlspecialchars($story->jira_issue_key ?? 'Story #' . $story->id) ?>">#<?= $issueNum ?></span>
                                    <span class="badge bg-success"><i class="bi bi-check"></i></span>
                                </div>
                            </div>
                            <h6 class="card-title mb-2" style="font-size: 0.9rem;">
                                <?= htmlspecialchars($story->title) ?>
                            </h6>
                            <?php if ($story->jira_issue_key): ?>
                            <small class="text-success d-block mb-2">
                                <i class="bi bi-link-45deg"></i> <?= htmlspecialchars($story->jira_issue_key) ?>
                            </small>
                            <?php endif; ?>
                            <div class="btn-group btn-group-sm w-100">
                                <?php if ($job && $job['pr_url']): ?>
                                <a href="<?= htmlspecialchars($job['pr_url']) ?>" target="_blank" class="btn btn-outline-success">
                                    <i class="bi bi-git"></i> View PR
                                </a>
                                <?php endif; ?>
                                <button class="btn btn-outline-secondary" onclick="viewJobResults('<?= htmlspecialchars($story->jira_issue_key) ?>')">
                                    <i class="bi bi-eye"></i> Details
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($complete)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                        <p class="mb-0 mt-2">No completed stories</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- QA Release Action Bar (shown when stories selected) - fixed at bottom -->
    <div id="qa-action-bar" class="card border-primary d-none" style="position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); z-index: 1040; width: 90%; max-width: 900px; box-shadow: 0 -4px 20px rgba(0,0,0,0.15);">
        <div class="card-body py-2">
            <div class="d-flex justify-content-between align-items-start">
                <div class="flex-grow-1 me-3">
                    <div class="mb-1">
                        <i class="bi bi-box-seam text-primary me-2"></i>
                        <strong><span id="qa-selected-count">0</span> stories selected for QA Release</strong>
                    </div>
                    <div id="qa-selected-list" class="d-flex flex-wrap gap-1"></div>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <div class="input-group input-group-sm" style="width: 280px;">
                        <span class="input-group-text">Base</span>
                        <div class="dropdown flex-grow-1">
                            <input type="text" class="form-control form-control-sm" id="qa-target-branch"
                                   value="main" placeholder="Type or select branch..."
                                   data-bs-toggle="dropdown" aria-expanded="false" autocomplete="off">
                            <ul class="dropdown-menu w-100" id="branch-dropdown" style="max-height: 250px; overflow-y: auto;">
                                <li><a class="dropdown-item" href="#" data-branch="main">main</a></li>
                            </ul>
                        </div>
                        <button class="btn btn-outline-secondary" type="button" onclick="refreshBranches()" title="Refresh branches">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                    <button class="btn btn-outline-secondary btn-sm" onclick="clearQASelection()">
                        <i class="bi bi-x"></i> Clear
                    </button>
                    <button class="btn btn-primary btn-sm" onclick="buildQARelease()">
                        <i class="bi bi-box-seam"></i> Build QA Release
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Pending Review Action Bar (shown when pending stories selected) - fixed at bottom -->
    <div id="pending-action-bar" class="card border-warning d-none" style="position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); z-index: 1039; width: 90%; max-width: 900px; box-shadow: 0 -4px 20px rgba(0,0,0,0.15);">
        <div class="card-body py-2">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-play-circle text-warning me-2"></i>
                    <strong><span id="pending-selected-count">0</span> stories selected for batch processing</strong>
                    <small class="text-muted ms-2" id="pending-selected-list"></small>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm" onclick="clearPendingSelection()">
                        <i class="bi bi-x"></i> Clear
                    </button>
                    <button class="btn btn-success btn-sm" onclick="batchApproveOnly()">
                        <i class="bi bi-check-lg"></i> Approve Only
                    </button>
                    <button class="btn btn-warning btn-sm" onclick="batchApproveAndRun()">
                        <i class="bi bi-play-fill"></i> Approve & Run Jobs
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($failed)): ?>
    <!-- Failed Jobs Section -->
    <div class="card mt-3 border-danger">
        <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
            <strong><i class="bi bi-x-circle-fill"></i> Failed Jobs</strong>
            <span class="badge bg-dark"><?= count($failed) ?></span>
        </div>
        <div class="card-body">
            <div class="row g-2">
                <?php foreach ($failed as $card): ?>
                <?php $story = $card['story']; $project = $card['project']; $job = $card['job']; ?>
                <div class="col-md-3 failed-card-col" data-project="<?= htmlspecialchars($project->name) ?>">
                    <div class="card border-danger kanban-card" data-story-id="<?= $story->story_id ?>" data-project="<?= htmlspecialchars($project->name) ?>">
                        <div class="card-body p-2">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <small class="text-muted text-truncate" style="max-width: 50%;">
                                    <?= htmlspecialchars($project->name) ?>
                                </small>
                                <div>
                                    <?php
                                    // Extract GitHub issue number from jira_issue_key (e.g., mfrederico/myctobot#99 → #99)
                                    $issueNum = $story->jira_issue_key && preg_match('/#(\d+)$/', $story->jira_issue_key, $m) ? $m[1] : $story->id;
                                    ?><span class="badge bg-light text-dark" title="<?= htmlspecialchars($story->jira_issue_key ?? 'Story #' . $story->id) ?>">#<?= $issueNum ?></span>
                                    <span class="badge bg-danger"><i class="bi bi-x"></i> Failed</span>
                                </div>
                            </div>
                            <h6 class="card-title mb-1" style="font-size: 0.9rem;">
                                <?= htmlspecialchars($story->title) ?>
                            </h6>
                            <?php if ($story->jira_issue_key): ?>
                            <small class="text-muted d-block mb-1">
                                <i class="bi bi-link-45deg"></i> <?= htmlspecialchars($story->jira_issue_key) ?>
                            </small>
                            <?php endif; ?>
                            <?php if ($job && !empty($job['error_message'])): ?>
                            <small class="text-danger d-block mb-2 text-truncate" title="<?= htmlspecialchars($job['error_message']) ?>">
                                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars(substr($job['error_message'], 0, 50)) ?>...
                            </small>
                            <?php endif; ?>
                            <div class="btn-group btn-group-sm w-100">
                                <?php if ($job && !empty($job['job_id'])): ?>
                                <button class="btn btn-warning" onclick="retryJob('<?= htmlspecialchars($job['job_id']) ?>')" title="Retry">
                                    <i class="bi bi-arrow-repeat"></i> Retry
                                </button>
                                <?php endif; ?>
                                <button class="btn btn-outline-secondary" onclick="viewJobResults('<?= htmlspecialchars($story->jira_issue_key) ?>')" title="View Logs">
                                    <i class="bi bi-journal-text"></i> Logs
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
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

<style>
.kanban-column {
    min-height: 400px;
    max-height: calc(100vh - 250px);
    overflow-y: auto;
}
.kanban-card {
    transition: box-shadow 0.2s ease;
}
.kanban-card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}
.kanban-card .card-title {
    line-height: 1.3;
}
</style>

<script>
// AJAX helpers
async function apiCall(endpoint, data) {
    const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });
    return response.json();
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Runner Status Functions
async function loadRunnerStatus() {
    try {
        const result = await apiCall('/reviewboard/getRunnerStatus', {});
        if (result.success) {
            updateRunnerUI(result.data);
        }
    } catch (e) {
        console.error('Failed to load runner status:', e);
    }
}

function updateRunnerUI(status) {
    const select = document.getElementById('runner-limit-select');
    const progressBar = document.getElementById('runner-progress-bar');
    const countText = document.getElementById('runner-count-text');

    select.value = status.max;
    const percent = status.max > 0 ? (status.running / status.max) * 100 : 0;
    progressBar.style.width = percent + '%';
    countText.textContent = `${status.running}/${status.max}`;

    // Color based on usage
    if (percent >= 100) {
        progressBar.className = 'progress-bar bg-danger';
    } else if (percent >= 75) {
        progressBar.className = 'progress-bar bg-warning';
    } else {
        progressBar.className = 'progress-bar bg-primary';
    }
}

document.getElementById('runner-limit-select').addEventListener('change', async function() {
    const newLimit = parseInt(this.value);
    const result = await apiCall('/reviewboard/updateRunnerLimit', { limit: newLimit });
    if (result.success) {
        loadRunnerStatus();
    } else {
        alert('Failed to update runner limit: ' + (result.message || 'Unknown error'));
        loadRunnerStatus(); // Reset to actual value
    }
});

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

    const result = await apiCall('/reviewboard/deleteStory', { story_id: storyId });
    if (result.success) {
        document.querySelector(`[data-story-id="${storyId}"]`)?.remove();
    } else {
        alert('Error: ' + result.message);
    }
}

async function retryJob(jobId) {
    if (!confirm('Retry this failed job? It will start a new tmux session and append to the existing logs.')) return;

    try {
        const result = await apiCall('/reviewboard/retryJob', { job_id: jobId });
        if (result.success) {
            showToast(`Job retry started for ${result.data.issue_key}`, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(result.message || 'Failed to retry job', 'error');
        }
    } catch (e) {
        console.error('Failed to retry job:', e);
        showToast('Failed to retry job', 'error');
    }
}

async function startJob(issueKey) {
    if (!confirm(`Start a new AI Developer job for ${issueKey}?`)) return;

    try {
        const result = await apiCall('/reviewboard/startJob', { issue_key: issueKey });
        if (result.success) {
            showToast(`Job started for ${issueKey}`, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(result.message || 'Failed to start job', 'error');
        }
    } catch (e) {
        console.error('Failed to start job:', e);
        showToast('Failed to start job', 'error');
    }
}

function editStory(storyId) {
    document.getElementById('edit-story-id').value = storyId;
    document.getElementById('edit-story-title').value = '';
    document.getElementById('edit-story-description').value = '';
    document.getElementById('edit-story-points').value = '3';
    document.getElementById('edit-acceptance-criteria').innerHTML = '';

    new bootstrap.Modal(document.getElementById('editStoryModal')).show();
    fetchStoryData(storyId);
}

async function fetchStoryData(storyId) {
    const result = await apiCall('/reviewboard/getStory', { story_id: storyId });
    if (result.success) {
        const story = result.data;
        document.getElementById('edit-story-title').value = story.title || '';
        document.getElementById('edit-story-description').value = story.description || '';
        document.getElementById('edit-story-points').value = story.story_points || '3';

        const container = document.getElementById('edit-acceptance-criteria');
        container.innerHTML = '';
        const criteria = story.acceptance_criteria || [];
        criteria.forEach(c => {
            addCriterionWithValue(c);
        });
    }
}

async function saveStory() {
    const storyId = document.getElementById('edit-story-id').value;
    const title = document.getElementById('edit-story-title').value;
    const description = document.getElementById('edit-story-description').value;
    const points = document.getElementById('edit-story-points').value;

    const criteria = [];
    document.querySelectorAll('#edit-acceptance-criteria input').forEach(input => {
        if (input.value.trim()) {
            criteria.push(input.value.trim());
        }
    });

    const result = await apiCall('/reviewboard/updateStory', {
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
    addCriterionWithValue('');
}

function addCriterionWithValue(value) {
    const container = document.getElementById('edit-acceptance-criteria');
    const div = document.createElement('div');
    div.className = 'input-group mb-2';
    let displayValue = '';
    if (typeof value === 'object') {
        // Handle Given/When/Then format from AI-generated criteria
        if (value.given && value.when && value.then) {
            displayValue = `Given ${value.given}, When ${value.when}, Then ${value.then}`;
        } else {
            displayValue = value.text || value.criterion || '';
        }
    } else {
        displayValue = value;
    }
    div.innerHTML = `
        <input type="text" class="form-control" placeholder="Acceptance criterion..." value="${escapeHtml(displayValue)}">
        <button type="button" class="btn btn-outline-danger" onclick="this.parentElement.remove()">
            <i class="bi bi-x"></i>
        </button>
    `;
    container.appendChild(div);
}

// Job Results Modal
async function viewJobResults(issueKey) {
    try {
        const result = await apiCall('/reviewboard/getJobDetails', { issue_key: issueKey });
        if (result.success && result.data.job) {
            // Only show modal if we have job data
            document.getElementById('job-results-loading').style.display = 'none';
            document.getElementById('job-results-content').style.display = 'block';
            new bootstrap.Modal(document.getElementById('jobResultsModal')).show();
            displayJobResults(result.data.job, result.data.logs);
        } else {
            // No job exists - show a toast instead of modal
            showToast('info', `No job found for ${issueKey}. Click the play button to start one.`);
        }
    } catch (e) {
        showToast('error', 'Failed to load job details');
    }
}

function displayJobResults(job, logs) {
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

    document.getElementById('job-issue-key').textContent = job.issue_key;
    document.getElementById('job-current-step').textContent = job.current_step || '-';
    document.getElementById('job-started-at').textContent = job.started_at || '-';
    document.getElementById('job-completed-at').textContent = job.completed_at || '-';

    const progressBar = document.getElementById('job-progress-bar');
    progressBar.style.width = `${job.progress}%`;
    progressBar.textContent = `${job.progress}%`;
    progressBar.className = `progress-bar bg-${statusColors[job.status] || 'secondary'}`;

    document.getElementById('job-branch-name').textContent = job.branch_name || '-';
    document.getElementById('job-commit-sha').textContent = job.commit_sha ? job.commit_sha.substring(0, 8) : '-';

    const prLinkEl = document.getElementById('job-pr-link');
    if (job.pr_url) {
        prLinkEl.innerHTML = `<a href="${escapeHtml(job.pr_url)}" target="_blank" class="text-decoration-none">
            <i class="bi bi-box-arrow-up-right"></i> View PR #${job.pr_number || ''}
        </a>`;
    } else {
        prLinkEl.textContent = '-';
    }

    const errorSection = document.getElementById('job-error-section');
    if (job.status === 'failed' && job.error) {
        errorSection.style.display = 'block';
        document.getElementById('job-error-message').textContent = job.error;
    } else {
        errorSection.style.display = 'none';
    }

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

// Stale Job Detection Functions
async function checkStaleJobs(showSuccessToast = true) {
    try {
        const result = await apiCall('/reviewboard/findStaleJobs', {});
        if (result.success) {
            const staleJobs = result.data.stale_jobs || [];
            const alert = document.getElementById('stale-jobs-alert');
            const countEl = document.getElementById('stale-jobs-count');
            const listEl = document.getElementById('stale-jobs-list');

            if (staleJobs.length > 0) {
                countEl.textContent = staleJobs.length;
                listEl.innerHTML = staleJobs.map(job =>
                    `<span class="badge bg-dark me-1">${escapeHtml(job.issue_key)}</span>`
                ).join('');
                alert.classList.remove('d-none');
            } else {
                alert.classList.add('d-none');
                // Only show success toast if manually clicked
                if (showSuccessToast) {
                    showToast('All running jobs have active sessions', 'success');
                }
            }
        }
    } catch (e) {
        console.error('Failed to check stale jobs:', e);
        if (showSuccessToast) {
            showToast('Failed to check for stale jobs', 'error');
        }
    }
}

async function cleanupStaleJobs() {
    if (!confirm('Mark all crashed jobs as failed? This will move them out of "In Progress".')) {
        return;
    }

    try {
        const result = await apiCall('/reviewboard/cleanupStaleJobs', {});
        if (result.success) {
            const alert = document.getElementById('stale-jobs-alert');
            alert.classList.add('d-none');

            if (result.data.cleaned > 0) {
                showToast(`${result.data.cleaned} job(s) marked as failed`, 'success');
                // Reload page to reflect changes
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('No jobs needed cleanup', 'info');
            }
        } else {
            showToast(result.message || 'Failed to cleanup stale jobs', 'error');
        }
    } catch (e) {
        console.error('Failed to cleanup stale jobs:', e);
        showToast('Failed to cleanup stale jobs', 'error');
    }
}

function dismissStaleAlert() {
    document.getElementById('stale-jobs-alert').classList.add('d-none');
}

function showToast(message, type = 'info') {
    // Create a simple toast notification
    const toast = document.createElement('div');
    toast.className = `position-fixed bottom-0 end-0 m-3 p-3 rounded shadow text-white bg-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'}`;
    toast.style.zIndex = '9999';
    toast.innerHTML = `<i class="bi bi-${type === 'error' ? 'x-circle' : type === 'success' ? 'check-circle' : 'info-circle'} me-2"></i>${escapeHtml(message)}`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// Project Tab Filtering
function filterByProject(projectName) {
    const allCards = document.querySelectorAll('.kanban-card');
    const failedCols = document.querySelectorAll('.failed-card-col');

    // Show/hide cards based on project filter
    allCards.forEach(card => {
        const cardProject = card.getAttribute('data-project');
        if (projectName === 'all' || cardProject === projectName) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });

    // Also handle failed section (which uses col-md-3 wrappers)
    failedCols.forEach(col => {
        const colProject = col.getAttribute('data-project');
        if (projectName === 'all' || colProject === projectName) {
            col.style.display = '';
        } else {
            col.style.display = 'none';
        }
    });

    // Update column badge counts
    document.querySelectorAll('.kanban-column').forEach(column => {
        const visibleCards = column.querySelectorAll('.kanban-card:not([style*="display: none"])').length;
        const badge = column.closest('.card').querySelector('.card-header .badge');
        if (badge) {
            badge.textContent = visibleCards;
        }
    });

    // Update failed section count
    const failedSection = document.querySelector('.border-danger.mt-3');
    if (failedSection) {
        const visibleFailed = failedSection.querySelectorAll('.failed-card-col:not([style*="display: none"])').length;
        const failedBadge = failedSection.querySelector('.card-header .badge');
        if (failedBadge) {
            failedBadge.textContent = visibleFailed;
        }
        // Hide entire failed section if no visible cards
        failedSection.style.display = visibleFailed > 0 ? '' : 'none';
    }

    // Update empty state messages
    document.querySelectorAll('.kanban-column').forEach(column => {
        const visibleCards = column.querySelectorAll('.kanban-card:not([style*="display: none"])').length;
        const emptyState = column.querySelector('.text-center.text-muted.py-4');
        if (emptyState) {
            emptyState.style.display = visibleCards === 0 ? '' : 'none';
        }
    });
}

function setupProjectTabs() {
    const tabs = document.querySelectorAll('#projectTabs button[data-project-filter]');
    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            // Update active state
            tabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');

            // Filter cards
            const project = this.getAttribute('data-project-filter');
            filterByProject(project);
        });
    });
}

// QA Release Selection Functions
let selectedStories = [];
let branchesLoaded = false;
let allBranches = ['main'];

// Fetch and populate branch dropdown
async function loadBranches() {
    if (branchesLoaded) return;

    try {
        const result = await apiCall('/reviewboard/getBranches', {});
        if (result.success && result.data && result.data.branches) {
            allBranches = result.data.branches;
            populateBranchDropdown(allBranches);
            branchesLoaded = true;
        }
    } catch (e) {
        console.error('Failed to load branches:', e);
    }
}

function populateBranchDropdown(branches) {
    const dropdown = document.getElementById('branch-dropdown');
    if (!dropdown) return;

    if (branches.length === 0) {
        dropdown.innerHTML = '<li><span class="dropdown-item text-muted">No matching branches</span></li>';
    } else {
        dropdown.innerHTML = branches.map(b => {
            const icon = (b === 'main' || b === 'master') ? 'bi-star-fill text-warning' :
                         b.startsWith('qa/') ? 'bi-box-seam text-info' : 'bi-git';
            return `<li><a class="dropdown-item branch-option" href="#" data-branch="${b}">
                <i class="bi ${icon} me-2"></i>${b}
            </a></li>`;
        }).join('');
    }
}

function filterBranches(query) {
    const filtered = allBranches.filter(b =>
        b.toLowerCase().includes(query.toLowerCase())
    );
    populateBranchDropdown(filtered);
}

// Initialize branch dropdown behavior
document.addEventListener('DOMContentLoaded', function() {
    const branchInput = document.getElementById('qa-target-branch');
    const branchDropdown = document.getElementById('branch-dropdown');

    if (branchInput && branchDropdown) {
        // Filter on input
        branchInput.addEventListener('input', function() {
            filterBranches(this.value);
        });

        // Handle selection
        branchDropdown.addEventListener('click', function(e) {
            const item = e.target.closest('.branch-option');
            if (item) {
                e.preventDefault();
                branchInput.value = item.dataset.branch;
                bootstrap.Dropdown.getInstance(branchInput)?.hide();
            }
        });

        // Show all on focus
        branchInput.addEventListener('focus', function() {
            if (!branchesLoaded) loadBranches();
            populateBranchDropdown(allBranches);
        });
    }
});

async function refreshBranches() {
    branchesLoaded = false;
    const btn = document.querySelector('#qa-action-bar .bi-arrow-clockwise').parentElement;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    try {
        const result = await apiCall('/reviewboard/getBranches', {});
        if (result.success && result.data && result.data.branches) {
            allBranches = result.data.branches;
            populateBranchDropdown(allBranches);
            branchesLoaded = true;
            showToast(`Loaded ${result.data.branches.length} branches`, 'success');
        }
    } catch (e) {
        showToast('Failed to refresh branches', 'warning');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i>';
    }
}

// Load branches when first story is selected for QA
function updateQASelection() {
    const checkboxes = document.querySelectorAll('.qa-select-checkbox:checked');
    selectedStories = [];

    checkboxes.forEach(cb => {
        selectedStories.push({
            id: cb.value,
            story_id: cb.dataset.storyId,
            issue_key: cb.dataset.issueKey,
            project_id: cb.dataset.projectId,
            title: cb.dataset.title
        });
    });

    const actionBar = document.getElementById('qa-action-bar');
    const countEl = document.getElementById('qa-selected-count');
    const listEl = document.getElementById('qa-selected-list');

    if (selectedStories.length > 0) {
        actionBar.classList.remove('d-none');
        countEl.textContent = selectedStories.length;

        // Show issue keys as badges
        listEl.innerHTML = selectedStories.map(s => {
            const key = s.issue_key || `Story #${s.story_id}`;
            const title = s.title ? ` title="${s.title.replace(/"/g, '&quot;')}"` : '';
            return `<span class="badge bg-light text-dark border"${title}>${key}</span>`;
        }).join('');

        // Load branches on first selection
        loadBranches();
    } else {
        actionBar.classList.add('d-none');
    }
}

function toggleSelectAll() {
    const checkboxes = document.querySelectorAll('.qa-select-checkbox');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);

    checkboxes.forEach(cb => {
        cb.checked = !allChecked;
    });

    updateQASelection();
}

function clearQASelection() {
    document.querySelectorAll('.qa-select-checkbox').forEach(cb => {
        cb.checked = false;
    });
    updateQASelection();
}

async function buildQARelease() {
    if (selectedStories.length === 0) {
        showToast('No stories selected', 'warning');
        return;
    }

    const targetBranch = document.getElementById('qa-target-branch').value || 'main';
    const storyIds = selectedStories.map(s => s.id);

    // Confirm with user
    const storyTitles = selectedStories.map(s => `• ${s.title}`).join('\n');
    if (!confirm(`Build QA Release with ${selectedStories.length} stories?\n\n${storyTitles}\n\nTarget branch: ${targetBranch}`)) {
        return;
    }

    // Show loading state
    const buildBtn = document.querySelector('#qa-action-bar .btn-primary');
    const originalText = buildBtn.innerHTML;
    buildBtn.disabled = true;
    buildBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Building...';

    try {
        const result = await apiCall('/reviewboard/buildQARelease', {
            story_ids: storyIds,
            target_branch: targetBranch
        });

        if (result.success) {
            showToast('QA Release build started! Check logs for progress.', 'success');

            // Show results modal or link
            if (result.data && result.data.log_file) {
                showQABuildStatus(result.data);
            }

            clearQASelection();
        } else {
            showToast(result.message || 'Failed to start QA build', 'error');
        }
    } catch (e) {
        console.error('QA build error:', e);
        showToast('Failed to start QA build', 'error');
    } finally {
        buildBtn.disabled = false;
        buildBtn.innerHTML = originalText;
    }
}

function showQABuildStatus(data) {
    // Save QA release info to localStorage for persistent display
    const qaReleaseInfo = {
        storyCount: data.story_count || 0,
        targetBranch: data.target_branch || 'main',
        qaBranch: data.qa_branch || 'qa-release',
        projectId: data.project_id || null,
        workDir: data.work_dir || null,
        status: 'running',
        timestamp: new Date().toISOString()
    };
    localStorage.setItem('myctobot_qa_release', JSON.stringify(qaReleaseInfo));

    // Show the persistent banner with "building" status
    showQAReleaseBanner(qaReleaseInfo);

    // Poll for actual result
    if (data.work_dir) {
        pollQABuildStatus(data.work_dir, qaReleaseInfo);
    }
}

async function pollQABuildStatus(workDir, qaReleaseInfo) {
    const maxAttempts = 60; // Poll for up to 5 minutes (60 * 5s)
    let attempts = 0;

    const poll = async () => {
        attempts++;
        try {
            const result = await apiCall('/reviewboard/qaReleaseStatus', { work_dir: workDir });

            if (result.success && result.data) {
                const status = result.data.status;

                if (status === 'running') {
                    // Still running, continue polling
                    if (attempts < maxAttempts) {
                        setTimeout(poll, 5000);
                    }
                    return;
                }

                // Update localStorage with final status
                qaReleaseInfo.status = status;
                qaReleaseInfo.merged = result.data.merged || 0;
                qaReleaseInfo.failed = result.data.failed || 0;
                qaReleaseInfo.conflicts = result.data.conflicts || {};
                qaReleaseInfo.buildPassed = result.data.build_passed;
                qaReleaseInfo.testsPassed = result.data.tests_passed;
                qaReleaseInfo.message = result.data.message;
                localStorage.setItem('myctobot_qa_release', JSON.stringify(qaReleaseInfo));

                // Update banner with final status
                showQAReleaseBanner(qaReleaseInfo);

                // Show toast with result
                if (status === 'success') {
                    showToast(`QA release complete: ${result.data.merged} stories merged and pushed`, 'success');
                } else {
                    showToast(result.data.message || 'QA release failed', 'error');
                }
            }
        } catch (e) {
            console.error('Error polling QA status:', e);
            if (attempts < maxAttempts) {
                setTimeout(poll, 5000);
            }
        }
    };

    // Start polling after a short delay
    setTimeout(poll, 3000);
}

function showQAReleaseBanner(info) {
    const banner = document.getElementById('qa-release-banner');
    if (!banner) return;

    const status = info.status || 'success';

    // Update banner color based on status
    banner.classList.remove('alert-success', 'alert-warning', 'alert-danger', 'alert-info');
    if (status === 'running') {
        banner.classList.add('alert-info');
    } else if (status === 'success') {
        banner.classList.add('alert-success');
    } else {
        banner.classList.add('alert-warning');
    }

    // Update content based on status
    const storyCountEl = document.getElementById('qa-release-story-count');
    const targetBranchEl = document.getElementById('qa-release-target-branch');
    const timeEl = document.getElementById('qa-release-time');
    const githubLink = document.getElementById('qa-release-github-link');

    const retryBtn = document.getElementById('qa-release-retry-btn');

    if (status === 'running') {
        storyCountEl.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> Building ${info.storyCount}`;
        targetBranchEl.textContent = info.qaBranch || info.targetBranch;
        timeEl.textContent = 'in progress...';
        githubLink.style.display = 'none';
        retryBtn.style.display = 'none';
    } else if (status === 'success') {
        storyCountEl.textContent = info.merged || info.storyCount;
        targetBranchEl.textContent = info.qaBranch || info.targetBranch;
        retryBtn.style.display = 'none';
        // Show GitHub link for success
        const completedCard = document.querySelector('[data-status="complete"] .kanban-card[data-issue-key]');
        if (completedCard) {
            const issueKey = completedCard.dataset.issueKey;
            if (issueKey && issueKey.includes('/')) {
                const [owner, repoAndIssue] = issueKey.split('/');
                const repo = repoAndIssue.split('#')[0];
                const branchUrl = `https://github.com/${owner}/${repo}/tree/${encodeURIComponent(info.qaBranch || info.targetBranch)}`;
                githubLink.href = branchUrl;
                githubLink.style.display = 'inline-block';
            }
        }
    } else {
        // Failed status - show warning with details
        const merged = info.merged || 0;
        const failed = info.failed || 0;
        storyCountEl.innerHTML = `${merged} merged, <span class="text-danger">${failed} failed</span>`;
        targetBranchEl.textContent = info.qaBranch || info.targetBranch;
        githubLink.style.display = 'none';
        retryBtn.style.display = 'inline-block';

        // Show conflict details with resolve links
        if (info.conflicts && Object.keys(info.conflicts).length > 0) {
            let conflictHtml = '<div class="mt-2">';
            for (const [issueKey, conflict] of Object.entries(info.conflicts)) {
                const files = conflict.files || conflict; // Handle both old and new format
                const prUrl = conflict.pr_url;
                const issueNum = issueKey.match(/#(\d+)$/)?.[1] || issueKey;

                conflictHtml += `<div class="d-flex align-items-center gap-2 mb-1">`;
                conflictHtml += `<span class="text-danger"><strong>#${issueNum}</strong>: ${Array.isArray(files) ? files.join(', ') : conflict.error || 'Merge failed'}</span>`;

                if (prUrl) {
                    // Convert PR URL to conflicts URL
                    const conflictsUrl = prUrl.replace(/\/pull\/(\d+)$/, '/pull/$1/conflicts');
                    conflictHtml += `<a href="${conflictsUrl}" target="_blank" class="btn btn-sm btn-outline-warning">`;
                    conflictHtml += `<i class="bi bi-github"></i> Resolve</a>`;
                    conflictHtml += `<button class="btn btn-sm btn-outline-danger" onclick="abandonPR('${issueKey}', '${prUrl}')">`;
                    conflictHtml += `<i class="bi bi-trash"></i> Abandon</button>`;
                }
                conflictHtml += `</div>`;
            }
            conflictHtml += '</div>';
            timeEl.innerHTML = conflictHtml;
        } else if (info.message) {
            timeEl.textContent = info.message;
        }
    }

    // Format timestamp for non-running states
    if (status !== 'running') {
        const time = new Date(info.timestamp);
        const now = new Date();
        const diffMins = Math.round((now - time) / 60000);
        let timeText = '';
        if (diffMins < 1) {
            timeText = 'just now';
        } else if (diffMins < 60) {
            timeText = `${diffMins} min ago`;
        } else if (diffMins < 1440) {
            timeText = `${Math.round(diffMins / 60)} hours ago`;
        } else {
            timeText = time.toLocaleDateString();
        }
        if (status === 'success') {
            timeEl.textContent = timeText;
        }
    }

    banner.classList.remove('d-none');
}

async function abandonPR(issueKey, prUrl) {
    if (!confirm(`Abandon PR for ${issueKey}?\n\nThis will:\n• Close the PR on GitHub\n• Mark the job as failed\n• Remove it from the Complete column`)) {
        return;
    }

    try {
        const result = await apiCall('/reviewboard/abandonPR', {
            issue_key: issueKey,
            pr_url: prUrl
        });

        if (result.success) {
            showToast(`PR abandoned for ${issueKey}`, 'success');
            // Reload to refresh the board
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(result.message || 'Failed to abandon PR', 'error');
        }
    } catch (e) {
        showToast('Failed to abandon PR: ' + e.message, 'error');
    }
}

function retryQARelease() {
    // Get the stored info to find which stories to retry
    const saved = localStorage.getItem('myctobot_qa_release');
    if (!saved) {
        showToast('No previous QA release to retry', 'warning');
        return;
    }

    const info = JSON.parse(saved);

    // Clear the old banner and storage
    dismissQAReleaseBanner();

    // Show a message to reselect stories
    showToast('Select stories in the Complete column to retry the QA release', 'info');

    // Scroll to complete column
    const completeColumn = document.querySelector('[data-status="complete"]');
    if (completeColumn) {
        completeColumn.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

function dismissQAReleaseBanner() {
    localStorage.removeItem('myctobot_qa_release');
    document.getElementById('qa-release-banner').classList.add('d-none');
}

function loadQAReleaseBanner() {
    const saved = localStorage.getItem('myctobot_qa_release');
    if (!saved) return;

    try {
        const info = JSON.parse(saved);
        // Only show if less than 24 hours old
        const age = new Date() - new Date(info.timestamp);
        if (age < 24 * 60 * 60 * 1000) {
            showQAReleaseBanner(info);

            // Resume polling if status is still "running"
            if (info.status === 'running' && info.workDir) {
                console.log('Resuming QA build status polling...');
                pollQABuildStatus(info.workDir, info);
            }
        } else {
            // Expired, clean up
            localStorage.removeItem('myctobot_qa_release');
        }
    } catch (e) {
        console.error('Failed to load QA release info:', e);
        localStorage.removeItem('myctobot_qa_release');
    }
}

// ============================================================================
// Pending Review Selection Functions
// ============================================================================

let selectedPendingStories = [];

function updatePendingSelection() {
    const checkboxes = document.querySelectorAll('.pending-select-checkbox:checked');
    selectedPendingStories = [];

    checkboxes.forEach(cb => {
        selectedPendingStories.push({
            id: cb.value,
            story_id: cb.dataset.storyId,
            issue_key: cb.dataset.issueKey,
            title: cb.dataset.title
        });
    });

    const actionBar = document.getElementById('pending-action-bar');
    const countEl = document.getElementById('pending-selected-count');
    const listEl = document.getElementById('pending-selected-list');

    if (selectedPendingStories.length > 0) {
        actionBar.classList.remove('d-none');
        countEl.textContent = selectedPendingStories.length;

        // Show first 3 issue keys or titles
        const keys = selectedPendingStories.map(s => s.issue_key || s.title.substring(0, 20) + '...').filter(k => k);
        if (keys.length <= 3) {
            listEl.textContent = keys.join(', ');
        } else {
            listEl.textContent = keys.slice(0, 3).join(', ') + ` +${keys.length - 3} more`;
        }
    } else {
        actionBar.classList.add('d-none');
    }
}

function toggleSelectAllPending() {
    const checkboxes = document.querySelectorAll('.pending-select-checkbox');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);

    checkboxes.forEach(cb => {
        cb.checked = !allChecked;
    });

    updatePendingSelection();
}

function clearPendingSelection() {
    document.querySelectorAll('.pending-select-checkbox').forEach(cb => {
        cb.checked = false;
    });
    updatePendingSelection();
}

async function batchApproveOnly() {
    if (selectedPendingStories.length === 0) {
        showToast('No stories selected', 'warning');
        return;
    }

    const storyTitles = selectedPendingStories.map(s => `• ${s.title}`).join('\n');
    if (!confirm(`Approve ${selectedPendingStories.length} stories?\n\n${storyTitles}`)) {
        return;
    }

    const btn = document.querySelector('#pending-action-bar .btn-success');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Approving...';

    try {
        const storyIds = selectedPendingStories.map(s => s.story_id);
        const result = await apiCall('/reviewboard/batchApprove', { story_ids: storyIds });

        if (result.success) {
            showToast(`${result.data?.approved || selectedPendingStories.length} stories approved!`, 'success');
            clearPendingSelection();
            // Reload page to show updated status
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(result.message || 'Failed to approve stories', 'error');
        }
    } catch (e) {
        console.error('Batch approve error:', e);
        showToast('Failed to approve stories', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

async function batchApproveAndRun() {
    if (selectedPendingStories.length === 0) {
        showToast('No stories selected', 'warning');
        return;
    }

    const storyTitles = selectedPendingStories.map(s => `• ${s.title}`).join('\n');
    if (!confirm(`Approve and start AI jobs for ${selectedPendingStories.length} stories?\n\n${storyTitles}\n\nThis will queue jobs to run based on your runner limits.`)) {
        return;
    }

    const btn = document.querySelector('#pending-action-bar .btn-warning');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing...';

    try {
        const storyIds = selectedPendingStories.map(s => s.story_id);
        const result = await apiCall('/reviewboard/batchApproveAndRun', { story_ids: storyIds });

        if (result.success) {
            const msg = `${result.data?.approved || 0} approved, ${result.data?.jobs_started || 0} jobs started`;
            showToast(msg, 'success');
            clearPendingSelection();
            // Reload page to show updated status
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(result.message || 'Failed to process stories', 'error');
        }
    } catch (e) {
        console.error('Batch approve and run error:', e);
        showToast('Failed to process stories', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    loadRunnerStatus();
    // Refresh runner status every 30 seconds
    setInterval(loadRunnerStatus, 30000);

    // Auto-check for stale jobs on page load (silent - no toast)
    checkStaleJobs(false);

    // Setup project tabs filtering
    setupProjectTabs();

    // Load QA release banner if one exists
    loadQAReleaseBanner();
});
</script>

<?php
// PM Chatbox - floating assistant for project questions
// Note: $ragServiceUrl is auto-detected in the partial based on HTTP_HOST
$tenantSlug = $_SESSION['tenant_slug'] ?? 'default';
$projectId = null;
include __DIR__ . '/../partials/pm-chatbox.php';
?>
