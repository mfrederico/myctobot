<div class="container py-4" data-assist-page="studio/developer" data-assist-purpose="Developer studio for code-based automation">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi <?= $studio['icon'] ?? 'bi-code-slash' ?> text-<?= $studio['color'] ?? 'primary' ?>"></i>
                <?= h($studio['name'] ?? 'Developer Studio') ?>
            </h1>
            <p class="text-muted mb-0"><?= h($studio['tagline'] ?? '') ?></p>
        </div>
        <div class="dropdown">
            <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-arrow-left-right"></i> Switch Studio
            </button>
            <ul class="dropdown-menu dropdown-menu-end" style="min-width: 240px;">
                <?php foreach ($studios as $key => $s): ?>
                <li>
                    <a class="dropdown-item py-2 <?= $key === $studioKey ? 'active' : '' ?>" href="/studio/<?= $key ?>">
                        <div class="d-flex align-items-start">
                            <i class="bi <?= $s['icon'] ?> me-2 mt-1"></i>
                            <div>
                                <div class="fw-semibold"><?= h($s['shortName'] ?? $s['name'] ?? '') ?></div>
                                <small class="<?= $key === $studioKey ? 'text-light opacity-75' : 'text-muted' ?>"><?= h($s['description'] ?? '') ?></small>
                            </div>
                        </div>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <a href="/activity?type=aidev" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded bg-primary bg-opacity-10 p-3 me-3">
                        <i class="bi bi-plus-lg fs-4 text-primary"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">New AI Job</div>
                        <small class="text-muted">Create a new dev job</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="/settings/repos" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded bg-dark bg-opacity-10 p-3 me-3">
                        <i class="bi bi-github fs-4 text-dark"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">Connect Repo</div>
                        <small class="text-muted">Add a Git repository</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="/settings/boards" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded bg-info bg-opacity-10 p-3 me-3">
                        <i class="bi bi-kanban fs-4 text-info"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">Connect Jira</div>
                        <small class="text-muted">Add a Jira board</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="/workstations" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded bg-warning bg-opacity-10 p-3 me-3">
                        <i class="bi bi-pc-display fs-4 text-warning"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">Workstations</div>
                        <small class="text-muted">Manage AI agents</small>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="row">
        <!-- Recent Jobs -->
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-cpu"></i> Recent AI Dev Jobs</h5>
                    <a href="/activity?type=aidev" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($jobs)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox display-4 text-muted"></i>
                        <p class="text-muted mt-3 mb-0">No jobs yet. Create your first AI dev job!</p>
                        <a href="/activity?type=aidev" class="btn btn-primary mt-3">
                            <i class="bi bi-plus-lg"></i> New Job
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Issue</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($jobs as $job): ?>
                                <tr>
                                    <td>
                                        <code class="fw-semibold"><?= h($job['issue_key']) ?></code>
                                    </td>
                                    <td>
                                        <?php
                                        $statusColors = [
                                            'pending' => 'secondary',
                                            'running' => 'primary',
                                            'completed' => 'success',
                                            'failed' => 'danger',
                                            'cancelled' => 'warning'
                                        ];
                                        $color = $statusColors[$job['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?= $color ?>"><?= ucfirst($job['status']) ?></span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= date('M j, g:ia', strtotime($job['created_at'] ?? time())) ?></small>
                                    </td>
                                    <td class="text-end">
                                        <a href="/jobs/view/<?= $job['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Connection Status -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-plug"></i> Connections</h6>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex align-items-center justify-content-between">
                            <div>
                                <i class="bi bi-github me-2"></i>
                                <span class="small">GitHub</span>
                            </div>
                            <?php if ($gitHubConnected): ?>
                            <span class="badge bg-success"><i class="bi bi-check-lg"></i> Connected</span>
                            <?php else: ?>
                            <a href="/settings/connections" class="btn btn-sm btn-outline-primary">Connect</a>
                            <?php endif; ?>
                        </li>
                        <li class="list-group-item d-flex align-items-center justify-content-between">
                            <div>
                                <i class="bi bi-kanban me-2"></i>
                                <span class="small">Atlassian/Jira</span>
                            </div>
                            <?php if ($atlassianConnected): ?>
                            <span class="badge bg-success"><i class="bi bi-check-lg"></i> Connected</span>
                            <?php else: ?>
                            <a href="/settings/connections" class="btn btn-sm btn-outline-primary">Connect</a>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Connected Repos -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-github"></i> Repositories</h6>
                    <a href="/settings/repos" class="btn btn-sm btn-outline-secondary">Manage</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($repos)): ?>
                    <div class="text-center py-4">
                        <p class="text-muted mb-0 small">No repositories connected.</p>
                        <a href="/settings/repos" class="btn btn-sm btn-outline-primary mt-2">Connect Repo</a>
                    </div>
                    <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($repos as $repo): ?>
                        <li class="list-group-item d-flex align-items-center">
                            <i class="bi bi-<?= $repo['provider'] ?? 'github' ?> me-2 text-muted"></i>
                            <span class="small"><?= h($repo['name'] ?? 'Unnamed') ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Jira Boards -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-kanban"></i> Jira Boards</h6>
                    <a href="/settings/boards" class="btn btn-sm btn-outline-secondary">Manage</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($boards)): ?>
                    <div class="text-center py-4">
                        <p class="text-muted mb-0 small">No Jira boards connected.</p>
                        <a href="/settings/boards" class="btn btn-sm btn-outline-primary mt-2">Connect Board</a>
                    </div>
                    <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($boards as $board): ?>
                        <li class="list-group-item d-flex align-items-center">
                            <span class="badge bg-light text-dark me-2"><?= h($board['project_key'] ?? '') ?></span>
                            <span class="small"><?= h($board['name'] ?? 'Unnamed') ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
