<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="mb-0">Sprint Analysis</h1>
                <a href="/boards" class="btn btn-outline-secondary">
                    <i class="bi bi-kanban"></i> Manage Boards
                </a>
            </div>

            <?php if (empty($boards)): ?>
            <div class="alert alert-info">
                <h4 class="alert-heading"><i class="bi bi-info-circle"></i> No Boards Available</h4>
                <p>You need to add Jira boards before you can run analyses.</p>
                <hr>
                <a href="/boards/discover" class="btn btn-primary">
                    <i class="bi bi-search"></i> Discover Boards
                </a>
            </div>
            <?php else: ?>

            <div class="row">
                <!-- Run Analysis Card -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <i class="bi bi-play-circle"></i> Run New Analysis
                        </div>
                        <div class="card-body">
                            <p class="text-muted">Select a board to analyze the current sprint issues.</p>
                            <div class="list-group">
                                <?php foreach ($boards as $board): ?>
                                <a href="/analysis/run/<?= $board['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?= htmlspecialchars($board['board_name']) ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <code><?= htmlspecialchars($board['project_key']) ?></code>
                                        </small>
                                    </div>
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Analyses -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <i class="bi bi-clock-history"></i> Recent Analyses
                        </div>
                        <div class="card-body">
                            <?php if (empty($recentAnalyses)): ?>
                            <p class="text-muted text-center py-4">No analyses yet. Run your first analysis!</p>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Board</th>
                                            <th>Type</th>
                                            <th>Status Filter</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentAnalyses as $analysis): ?>
                                        <tr>
                                            <td>
                                                <small><?= date('M j, Y', strtotime($analysis['created_at'])) ?></small>
                                                <br>
                                                <small class="text-muted"><?= date('H:i', strtotime($analysis['created_at'])) ?></small>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($analysis['board_name'] ?? 'Unknown') ?></strong>
                                                <br>
                                                <code class="small"><?= htmlspecialchars($analysis['project_key'] ?? 'N/A') ?></code>
                                            </td>
                                            <td>
                                                <span class="badge <?= $analysis['analysis_type'] === 'digest' ? 'bg-info' : 'bg-primary' ?>">
                                                    <?= htmlspecialchars($analysis['analysis_type']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?= htmlspecialchars($analysis['status_filter'] ?? 'All') ?></small>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <a href="/analysis/view/<?= $analysis['id'] ?>" class="btn btn-sm btn-outline-primary" title="View">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-sm btn-outline-success"
                                                            onclick="emailAnalysis(<?= $analysis['id'] ?>)" title="Email">
                                                        <i class="bi bi-envelope"></i>
                                                    </button>
                                                </div>
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
            </div>

            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function emailAnalysis(analysisId) {
    if (confirm('Send this analysis to your email?')) {
        fetch('/analysis/email/' + analysisId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Analysis sent to ' + (data.data.sent_to || 'your email'));
            } else {
                alert('Error: ' + (data.message || 'Failed to send email'));
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
        });
    }
}
</script>
