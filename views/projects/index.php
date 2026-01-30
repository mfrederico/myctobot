<div class="container py-4">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><i class="bi bi-folder2-open"></i> CTO Projects</h1>
                <div>
                    <a href="/directives" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-envelope-paper"></i> Directives
                    </a>
                    <a href="/dashboard" class="btn btn-outline-secondary">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </div>
            </div>

            <!-- Status Filter Tabs -->
            <ul class="nav nav-pills mb-4">
                <li class="nav-item">
                    <a class="nav-link <?= empty($status) ? 'active' : '' ?>" href="/projects">
                        All <span class="badge bg-secondary"><?= array_sum($statusCounts) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status === 'planning' ? 'active' : '' ?>" href="/projects?status=planning">
                        <i class="bi bi-pencil"></i> Planning <span class="badge bg-info"><?= $statusCounts['planning'] ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status === 'in_progress' ? 'active' : '' ?>" href="/projects?status=in_progress">
                        <i class="bi bi-play-circle"></i> In Progress <span class="badge bg-primary"><?= $statusCounts['in_progress'] ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status === 'blocked' ? 'active' : '' ?>" href="/projects?status=blocked">
                        <i class="bi bi-pause-circle"></i> Blocked <span class="badge bg-warning"><?= $statusCounts['blocked'] ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status === 'completed' ? 'active' : '' ?>" href="/projects?status=completed">
                        <i class="bi bi-check-circle"></i> Completed <span class="badge bg-success"><?= $statusCounts['completed'] ?></span>
                    </a>
                </li>
            </ul>

            <?php if (empty($projects)): ?>
            <div class="alert alert-info">
                <h4 class="alert-heading"><i class="bi bi-folder2-open"></i> No Projects Yet</h4>
                <p>Projects are created automatically when CEO directives are processed.</p>
                <hr>
                <p class="mb-0">
                    <a href="/directives" class="btn btn-primary">View Directives</a>
                </p>
            </div>
            <?php else: ?>
            <div class="row">
                <?php foreach ($projects as $project): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title mb-0">
                                    <a href="/projects/view/<?= htmlspecialchars($project->project_uid) ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($project->name) ?>
                                    </a>
                                </h5>
                                <?php
                                $statusClass = [
                                    'planning' => 'bg-info',
                                    'in_progress' => 'bg-primary',
                                    'blocked' => 'bg-warning text-dark',
                                    'completed' => 'bg-success',
                                    'cancelled' => 'bg-secondary'
                                ];
                                ?>
                                <span class="badge <?= $statusClass[$project->status] ?? 'bg-secondary' ?>">
                                    <?= ucfirst(str_replace('_', ' ', $project->status)) ?>
                                </span>
                            </div>

                            <p class="card-text text-muted small">
                                <?= htmlspecialchars(substr($project->description, 0, 120)) ?>...
                            </p>

                            <!-- Progress Bar -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span>Progress</span>
                                    <span><?= $project->completion_percentage ?>%</span>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-success" role="progressbar"
                                         style="width: <?= $project->completion_percentage ?>%"
                                         aria-valuenow="<?= $project->completion_percentage ?>"
                                         aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                            </div>

                            <!-- Stats -->
                            <div class="d-flex justify-content-between text-muted small">
                                <span><i class="bi bi-layers"></i> <?= $project->epic_count ?> epics</span>
                                <span><i class="bi bi-card-list"></i> <?= $project->story_count ?> stories</span>
                                <span><i class="bi bi-speedometer"></i> <?= $project->estimated_effort ?? 'M' ?></span>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-top-0">
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted">
                                    Created <?= date('M j', strtotime($project->created_at)) ?>
                                </small>
                                <div>
                                    <a href="/projects/view/<?= htmlspecialchars($project->project_uid) ?>"
                                       class="btn btn-sm btn-outline-primary">
                                        View <i class="bi bi-arrow-right"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
