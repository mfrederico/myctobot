<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <h1 class="mb-4">Dashboard</h1>

            <?php if (!$hasAtlassian): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <h4 class="alert-heading"><i class="bi bi-exclamation-triangle"></i> Connect Your Jira Account</h4>
                <p>To get started with MyCTOBot, connect your Atlassian account to access your Jira boards.</p>
                <hr>
                <?php if ($atlassianConfigured): ?>
                <a href="/atlassian/connect" class="btn btn-primary">
                    <i class="bi bi-link-45deg"></i> Connect Atlassian
                </a>
                <?php else: ?>
                <p class="mb-0 text-muted">Atlassian integration is not configured. Please contact the administrator.</p>
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row mt-4">
        <!-- Stats Card -->
        <div class="col-md-3">
            <div class="card text-white bg-primary">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4><?= $stats['user']['total_boards'] ?? 0 ?></h4>
                            <p class="mb-0">Tracked Boards</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-kanban fs-1"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <a href="/boards" class="text-white text-decoration-none">Manage Boards <i class="bi bi-arrow-right"></i></a>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-white bg-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4><?= $stats['user']['total_analyses'] ?? 0 ?></h4>
                            <p class="mb-0">Total Analyses</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-graph-up fs-1"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <a href="/analysis" class="text-white text-decoration-none">View Analysis <i class="bi bi-arrow-right"></i></a>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-white bg-info">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4><?= $stats['user']['total_digests'] ?? 0 ?></h4>
                            <p class="mb-0">Digests Sent</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-envelope fs-1"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <span class="text-white">Daily Email Reports</span>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card text-white bg-secondary">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4><?= count($sites) ?></h4>
                            <p class="mb-0">Connected Sites</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-cloud-check fs-1"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <a href="/settings" class="text-white text-decoration-none">Manage <i class="bi bi-arrow-right"></i></a>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <!-- Quick Actions -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-lightning"></i> Quick Actions
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <?php if ($hasAtlassian && !empty($boards)): ?>
                        <a href="/analysis" class="btn btn-primary">
                            <i class="bi bi-play-circle"></i> Run Analysis
                        </a>
                        <?php else: ?>
                        <a href="/boards/discover" class="btn btn-outline-primary">
                            <i class="bi bi-search"></i> Discover Boards
                        </a>
                        <?php endif; ?>

                        <a href="/boards" class="btn btn-outline-success">
                            <i class="bi bi-kanban"></i> Manage Boards
                        </a>

                        <?php if (!$hasAtlassian && $atlassianConfigured): ?>
                        <a href="/atlassian/connect" class="btn btn-outline-info">
                            <i class="bi bi-link-45deg"></i> Connect Atlassian
                        </a>
                        <?php endif; ?>

                        <a href="/settings" class="btn btn-outline-secondary">
                            <i class="bi bi-gear"></i> Settings
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Connected Sites -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-cloud"></i> Connected Jira Sites
                </div>
                <div class="card-body">
                    <?php if (empty($sites)): ?>
                    <p class="text-muted">No Atlassian sites connected yet.</p>
                    <?php if ($atlassianConfigured): ?>
                    <a href="/atlassian/connect" class="btn btn-sm btn-primary">Connect Now</a>
                    <?php endif; ?>
                    <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($sites as $site): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?= htmlspecialchars($site->site_name) ?></strong>
                                <br>
                                <small class="text-muted"><?= htmlspecialchars($site->site_url) ?></small>
                            </div>
                            <span class="badge bg-success">Connected</span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Analyses -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <i class="bi bi-clock-history"></i> Recent Analyses
                </div>
                <div class="card-body">
                    <?php if (empty($recentAnalyses)): ?>
                    <p class="text-muted">No analyses yet. Run your first analysis!</p>
                    <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($recentAnalyses as $analysis): ?>
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <strong><?= htmlspecialchars($analysis['project_key'] ?? 'N/A') ?></strong>
                                    <br>
                                    <small class="text-muted"><?= $analysis['analysis_type'] ?></small>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted"><?= date('M j', strtotime($analysis['created_at'])) ?></small>
                                    <br>
                                    <a href="/analysis/view/<?= $analysis['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                                </div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Boards List -->
    <?php if (!empty($boards)): ?>
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <i class="bi bi-kanban"></i> Your Jira Boards
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Board</th>
                                    <th>Project</th>
                                    <th>Status</th>
                                    <th>Daily Digest</th>
                                    <th>Last Analysis</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($boards as $board): ?>
                                <tr>
                                    <td><?= htmlspecialchars($board['board_name']) ?></td>
                                    <td><code><?= htmlspecialchars($board['project_key']) ?></code></td>
                                    <td>
                                        <?php if ($board['enabled']): ?>
                                        <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">Disabled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($board['digest_enabled']): ?>
                                        <span class="badge bg-info"><?= $board['digest_time'] ?></span>
                                        <?php else: ?>
                                        <span class="text-muted">Off</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($board['last_analysis_at']): ?>
                                        <?= date('M j, H:i', strtotime($board['last_analysis_at'])) ?>
                                        <?php else: ?>
                                        <span class="text-muted">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="/analysis/run/<?= $board['id'] ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-play"></i> Analyze
                                        </a>
                                        <a href="/boards/edit/<?= $board['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-gear"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.card {
    margin-bottom: 20px;
    box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
    transition: transform 0.2s;
}
.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
}
.card-footer {
    background-color: rgba(0,0,0,0.1);
}
</style>
