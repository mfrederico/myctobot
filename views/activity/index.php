<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1"><i class="bi bi-activity"></i> Activity</h1>
            <p class="text-muted mb-0">Unified timeline of pipeline runs and AI dev jobs</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap gap-4 align-items-start">
                <div>
                    <small class="text-muted d-block mb-1">Type</small>
                    <div class="btn-group btn-group-sm">
                        <a href="?type=all&status=<?= h($statusFilter) ?>&period=<?= h($periodFilter) ?>" class="btn btn-outline-secondary <?= $typeFilter === 'all' ? 'active' : '' ?>">All</a>
                        <a href="?type=pipeline&status=<?= h($statusFilter) ?>&period=<?= h($periodFilter) ?>" class="btn btn-outline-primary <?= $typeFilter === 'pipeline' ? 'active' : '' ?>">Pipeline Runs</a>
                        <a href="?type=aidev&status=<?= h($statusFilter) ?>&period=<?= h($periodFilter) ?>" class="btn btn-outline-dark <?= $typeFilter === 'aidev' ? 'active' : '' ?>">AI Dev Jobs</a>
                    </div>
                </div>
                <div>
                    <small class="text-muted d-block mb-1">Status</small>
                    <div class="btn-group btn-group-sm">
                        <a href="?type=<?= h($typeFilter) ?>&status=all&period=<?= h($periodFilter) ?>" class="btn btn-outline-secondary <?= $statusFilter === 'all' ? 'active' : '' ?>">All</a>
                        <a href="?type=<?= h($typeFilter) ?>&status=running&period=<?= h($periodFilter) ?>" class="btn btn-outline-warning <?= $statusFilter === 'running' ? 'active' : '' ?>">Running</a>
                        <a href="?type=<?= h($typeFilter) ?>&status=completed&period=<?= h($periodFilter) ?>" class="btn btn-outline-success <?= $statusFilter === 'completed' ? 'active' : '' ?>">Completed</a>
                        <a href="?type=<?= h($typeFilter) ?>&status=failed&period=<?= h($periodFilter) ?>" class="btn btn-outline-danger <?= $statusFilter === 'failed' ? 'active' : '' ?>">Failed</a>
                    </div>
                </div>
                <div>
                    <small class="text-muted d-block mb-1">Period</small>
                    <div class="btn-group btn-group-sm">
                        <a href="?type=<?= h($typeFilter) ?>&status=<?= h($statusFilter) ?>&period=today" class="btn btn-outline-secondary <?= $periodFilter === 'today' ? 'active' : '' ?>">Today</a>
                        <a href="?type=<?= h($typeFilter) ?>&status=<?= h($statusFilter) ?>&period=week" class="btn btn-outline-secondary <?= $periodFilter === 'week' ? 'active' : '' ?>">This Week</a>
                        <a href="?type=<?= h($typeFilter) ?>&status=<?= h($statusFilter) ?>&period=month" class="btn btn-outline-secondary <?= $periodFilter === 'month' ? 'active' : '' ?>">This Month</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Activity List -->
    <?php if (empty($activities)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-activity display-4 text-muted"></i>
            <h4 class="mt-3">No activity found</h4>
            <p class="text-muted">Try adjusting your filters or check back later.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="list-group list-group-flush">
            <?php foreach ($activities as $activity): ?>
            <?php
                $statusClass = match($activity['status']) {
                    'running' => 'warning',
                    'completed', 'success' => 'success',
                    'failed', 'error' => 'danger',
                    'pending' => 'secondary',
                    'cancelled' => 'secondary',
                    'awaiting_input' => 'info',
                    default => 'secondary',
                };
                $typeClass = $activity['type'] === 'pipeline_run' ? 'primary' : 'dark';
                $typeLabel = $activity['type'] === 'pipeline_run' ? 'Pipeline' : 'AI Dev';
                $typeIcon = $activity['type'] === 'pipeline_run' ? 'bi-diagram-3' : 'bi-cpu';

                $duration = '';
                if ($activity['completed_at'] && $activity['created_at']) {
                    $start = strtotime($activity['created_at']);
                    $end = strtotime($activity['completed_at']);
                    $diff = $end - $start;
                    if ($diff < 60) $duration = $diff . 's';
                    elseif ($diff < 3600) $duration = floor($diff / 60) . 'm ' . ($diff % 60) . 's';
                    else $duration = floor($diff / 3600) . 'h ' . floor(($diff % 3600) / 60) . 'm';
                }
            ?>
            <a href="<?= h($activity['detail_url']) ?>" class="list-group-item list-group-item-action">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="d-flex align-items-start gap-3">
                        <span class="badge bg-<?= $typeClass ?> mt-1">
                            <i class="bi <?= $typeIcon ?>"></i> <?= $typeLabel ?>
                        </span>
                        <div>
                            <h6 class="mb-1"><?= h($activity['title']) ?></h6>
                            <?php if ($activity['subtitle']): ?>
                            <small class="text-muted"><?= h($activity['subtitle']) ?></small>
                            <?php endif; ?>
                            <?php if ($activity['error_message']): ?>
                            <small class="text-danger d-block"><?= h(substr($activity['error_message'], 0, 120)) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-<?= $statusClass ?>"><?= h(ucfirst($activity['status'])) ?></span>
                        <div class="small text-muted mt-1">
                            <?= $activity['created_at'] ? date('M j, g:ia', strtotime($activity['created_at'])) : '' ?>
                        </div>
                        <?php if ($duration): ?>
                        <div class="small text-muted"><i class="bi bi-stopwatch"></i> <?= $duration ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
