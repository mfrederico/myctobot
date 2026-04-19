<div class="container py-4" data-assist-page="projects/view" data-assist-purpose="View project details with status tracking">
    <div class="row">
        <div class="col-md-12">
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/projects">Projects</a></li>
                    <li class="breadcrumb-item active"><?= h($project->name) ?></li>
                </ol>
            </nav>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><?= h($project->name) ?></h1>
                <div>
                    <?php if ($project->status === 'in_progress'): ?>
                    <button class="btn btn-warning pause-project" data-id="<?= h($project->project_uid) ?>">
                        <i class="bi bi-pause-circle"></i> Pause
                    </button>
                    <?php elseif ($project->status === 'blocked'): ?>
                    <button class="btn btn-success resume-project" data-id="<?= h($project->project_uid) ?>">
                        <i class="bi bi-play-circle"></i> Resume
                    </button>
                    <?php endif; ?>
                    <a href="/projects/report/<?= h($project->project_uid) ?>" class="btn btn-outline-primary">
                        <i class="bi bi-file-earmark-text"></i> Report
                    </a>
                    <a href="/projects" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                </div>
            </div>

            <div class="row">
                <!-- Main Content -->
                <div class="col-lg-8">
                    <!-- Progress Overview Card -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-primary text-white">
                            <i class="bi bi-graph-up"></i> Progress Overview
                        </div>
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-3 text-center">
                                    <div class="display-3 fw-bold text-primary"><?= $project->completion_percentage ?>%</div>
                                    <div class="text-muted">Complete</div>
                                </div>
                                <div class="col-md-9">
                                    <div class="progress mb-3" style="height: 20px;">
                                        <div class="progress-bar bg-success" role="progressbar"
                                             style="width: <?= $project->completion_percentage ?>%"
                                             aria-valuenow="<?= $project->completion_percentage ?>"
                                             aria-valuemin="0" aria-valuemax="100">
                                        </div>
                                    </div>
                                    <div class="row text-center">
                                        <?php
                                        $totalStories = 0;
                                        $doneStories = 0;
                                        $inProgressStories = 0;
                                        $blockedStories = 0;
                                        foreach ($epicsWithStories as $item) {
                                            foreach ($item['stories'] as $story) {
                                                $totalStories++;
                                                if ($story->status === 'done') $doneStories++;
                                                elseif ($story->status === 'in_progress' || $story->status === 'review') $inProgressStories++;
                                                elseif ($story->status === 'blocked') $blockedStories++;
                                            }
                                        }
                                        ?>
                                        <div class="col">
                                            <div class="fw-bold text-success"><?= $doneStories ?></div>
                                            <small class="text-muted">Done</small>
                                        </div>
                                        <div class="col">
                                            <div class="fw-bold text-primary"><?= $inProgressStories ?></div>
                                            <small class="text-muted">In Progress</small>
                                        </div>
                                        <div class="col">
                                            <div class="fw-bold text-warning"><?= $blockedStories ?></div>
                                            <small class="text-muted">Blocked</small>
                                        </div>
                                        <div class="col">
                                            <div class="fw-bold"><?= $totalStories - $doneStories - $inProgressStories - $blockedStories ?></div>
                                            <small class="text-muted">Pending</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Epics Accordion -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header">
                            <i class="bi bi-layers"></i> Epics (<?= count($epicsWithStories) ?>)
                        </div>
                        <div class="card-body p-0">
                            <div class="accordion" id="epicsAccordion">
                                <?php foreach ($epicsWithStories as $index => $item):
                                    $epic = $item['epic'];
                                    $stories = $item['stories'];
                                    $epicCompletion = $epic->story_count > 0
                                        ? round(($epic->stories_completed / $epic->story_count) * 100)
                                        : 0;
                                ?>
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button <?= $index > 0 ? 'collapsed' : '' ?>" type="button"
                                                data-bs-toggle="collapse" data-bs-target="#epic-<?= $epic->id ?>">
                                            <div class="d-flex justify-content-between align-items-center w-100 me-3">
                                                <span>
                                                    <strong><?= h($epic->title) ?></strong>
                                                    <span class="badge bg-secondary ms-2"><?= count($stories) ?> stories</span>
                                                </span>
                                                <span class="badge <?= $epic->status === 'completed' ? 'bg-success' : 'bg-info' ?>">
                                                    <?= ucfirst($epic->status) ?>
                                                </span>
                                            </div>
                                        </button>
                                    </h2>
                                    <div id="epic-<?= $epic->id ?>" class="accordion-collapse collapse <?= $index === 0 ? 'show' : '' ?>">
                                        <div class="accordion-body">
                                            <p class="text-muted"><?= h($epic->description) ?></p>

                                            <!-- Epic progress -->
                                            <div class="progress mb-3" style="height: 8px;">
                                                <div class="progress-bar bg-success" style="width: <?= $epicCompletion ?>%"></div>
                                            </div>

                                            <!-- Stories table -->
                                            <?php if (!empty($stories)): ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm mb-0">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Story</th>
                                                            <th>Points</th>
                                                            <th>Status</th>
                                                            <th>Jira</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($stories as $story): ?>
                                                        <tr>
                                                            <td>
                                                                <span title="<?= h($story->title) ?>">
                                                                    <?= h(substr($story->title, 0, 60)) ?>...
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-light text-dark"><?= $story->story_points ?></span>
                                                            </td>
                                                            <td>
                                                                <?php
                                                                $storyStatusClass = [
                                                                    'backlog' => 'bg-secondary',
                                                                    'ready' => 'bg-info',
                                                                    'in_progress' => 'bg-primary',
                                                                    'review' => 'bg-warning text-dark',
                                                                    'done' => 'bg-success',
                                                                    'blocked' => 'bg-danger'
                                                                ];
                                                                ?>
                                                                <span class="badge <?= $storyStatusClass[$story->status] ?? 'bg-secondary' ?>">
                                                                    <?= ucfirst(str_replace('_', ' ', $story->status)) ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <?php if ($story->jira_issue_key): ?>
                                                                <a href="#" class="text-decoration-none" title="View in Jira">
                                                                    <code><?= h($story->jira_issue_key) ?></code>
                                                                </a>
                                                                <?php else: ?>
                                                                <span class="text-muted">-</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php else: ?>
                                            <p class="text-muted mb-0">No stories created yet.</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="col-lg-4">
                    <!-- Status Card -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header">
                            <i class="bi bi-info-circle"></i> Project Info
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <strong>Status:</strong>
                                <?php
                                $statusClass = [
                                    'planning' => 'bg-info',
                                    'in_progress' => 'bg-primary',
                                    'blocked' => 'bg-warning text-dark',
                                    'completed' => 'bg-success'
                                ];
                                ?>
                                <span class="badge <?= $statusClass[$project->status] ?? 'bg-secondary' ?> fs-6">
                                    <?= ucfirst(str_replace('_', ' ', $project->status)) ?>
                                </span>
                            </div>

                            <div class="mb-3">
                                <strong>Estimated Effort:</strong>
                                <span class="badge bg-secondary"><?= $project->estimated_effort ?? 'M' ?></span>
                            </div>

                            <?php if (!empty($techStack)): ?>
                            <div class="mb-3">
                                <strong>Tech Stack:</strong>
                                <div class="mt-1">
                                    <?php foreach ($techStack as $tech): ?>
                                    <span class="badge bg-light text-dark me-1"><?= h($tech) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="mb-0">
                                <strong>Created:</strong>
                                <?= date('M j, Y H:i', strtotime($project->created_at)) ?>
                            </div>
                        </div>
                    </div>

                    <!-- Goals Card -->
                    <?php if (!empty($goals)): ?>
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header">
                            <i class="bi bi-bullseye"></i> Goals
                        </div>
                        <div class="card-body">
                            <ul class="list-group list-group-flush">
                                <?php foreach ($goals as $goal): ?>
                                <li class="list-group-item px-0">
                                    <i class="bi bi-check2 text-success me-2"></i>
                                    <?= h($goal) ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Risks Card -->
                    <?php if (!empty($risks)): ?>
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header">
                            <i class="bi bi-exclamation-triangle"></i> Risks
                        </div>
                        <div class="card-body p-0">
                            <ul class="list-group list-group-flush">
                                <?php foreach ($risks as $risk): ?>
                                <li class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong><?= h($risk['risk'] ?? $risk) ?></strong>
                                            <?php if (!empty($risk['mitigation'])): ?>
                                            <br><small class="text-muted">Mitigation: <?= h($risk['mitigation']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($risk['severity'])): ?>
                                        <?php
                                        $severityClass = [
                                            'low' => 'bg-success',
                                            'medium' => 'bg-warning text-dark',
                                            'high' => 'bg-danger'
                                        ];
                                        ?>
                                        <span class="badge <?= $severityClass[$risk['severity']] ?? 'bg-secondary' ?>">
                                            <?= ucfirst($risk['severity']) ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Linked Directive Card -->
                    <?php if ($directive): ?>
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header">
                            <i class="bi bi-link-45deg"></i> Source Directive
                        </div>
                        <div class="card-body">
                            <h6><?= h($directive->email_subject ?: '(No Subject)') ?></h6>
                            <small class="text-muted">From: <?= h($directive->email_from) ?></small>
                            <hr>
                            <a href="/directives/view/<?= h($directive->directive_uid) ?>"
                               class="btn btn-sm btn-outline-primary w-100">
                                View Directive <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Pause project
document.querySelectorAll('.pause-project').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        if (confirm('Pause this project? No new work will be started.')) {
            fetch('/projects/pause/' + id, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'}
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'Failed to pause project');
                }
            });
        }
    });
});

// Resume project
document.querySelectorAll('.resume-project').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        if (confirm('Resume this project?')) {
            fetch('/projects/resume/' + id, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'}
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'Failed to resume project');
                }
            });
        }
    });
});
</script>
