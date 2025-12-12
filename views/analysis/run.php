<div class="container-fluid">
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>Run Analysis</h1>
                <a href="/analysis" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>

            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-kanban"></i> <?= htmlspecialchars($board['board_name']) ?>
                    <code class="ms-2 text-white-50"><?= htmlspecialchars($board['project_key']) ?></code>
                </div>
                <div class="card-body">
                    <form method="POST" action="/analysis/run/<?= $board['id'] ?>" id="analysisForm">
                        <div class="mb-4">
                            <label for="status_filter" class="form-label">
                                <strong>Status Filter</strong>
                            </label>
                            <input type="text" class="form-control" id="status_filter" name="status_filter"
                                   value="<?= htmlspecialchars($board['status_filter'] ?? 'To Do') ?>"
                                   placeholder="To Do, In Progress">
                            <small class="text-muted">
                                Comma-separated list of Jira statuses to include. Leave empty for all issues in the current sprint.
                            </small>
                        </div>

                        <div class="mb-4">
                            <h6>Common Status Presets</h6>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setStatusFilter('To Do')">
                                    To Do
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setStatusFilter('To Do, In Progress')">
                                    To Do + In Progress
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setStatusFilter('In Progress')">
                                    In Progress Only
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setStatusFilter('')">
                                    All Statuses
                                </button>
                            </div>
                        </div>

                        <hr>

                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            <strong>What happens next:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Fetches issues from the current sprint matching your filter</li>
                                <li>Analyzes priorities using AI (Claude)</li>
                                <li>Identifies blocked tickets and risks</li>
                                <li>Generates actionable recommendations</li>
                            </ul>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-success btn-lg" id="submitBtn">
                                <i class="bi bi-play-circle"></i> Run Analysis
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Recent Analyses for this Board -->
            <?php if (!empty($recentAnalyses)): ?>
            <div class="card mt-4">
                <div class="card-header">
                    <i class="bi bi-clock-history"></i> Recent Analyses for this Board
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <?php foreach (array_slice($recentAnalyses, 0, 5) as $analysis): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <small class="text-muted"><?= date('M j, Y H:i', strtotime($analysis['created_at'])) ?></small>
                                <span class="badge bg-secondary ms-2"><?= $analysis['analysis_type'] ?></span>
                                <?php if (!empty($analysis['status_filter'])): ?>
                                <small class="text-muted ms-2">Filter: <?= htmlspecialchars($analysis['status_filter']) ?></small>
                                <?php endif; ?>
                            </div>
                            <a href="/analysis/view/<?= $analysis['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function setStatusFilter(value) {
    document.getElementById('status_filter').value = value;
}

document.getElementById('analysisForm').addEventListener('submit', function() {
    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Analyzing...';
});
</script>
