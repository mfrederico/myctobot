<div class="container-fluid">
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><i class="bi bi-hdd-network"></i> Run Shard Analysis</h1>
                <a href="/analysis" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>

            <?php if (!empty($activeJob)): ?>
            <!-- Active Job Alert -->
            <div class="alert alert-info mb-4">
                <div class="d-flex align-items-center">
                    <div class="spinner-border spinner-border-sm me-3" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="flex-grow-1">
                        <strong>Shard Analysis in Progress</strong><br>
                        <small>
                            Status: <?= htmlspecialchars($activeJob['status']) ?>
                            (Started: <?= $activeJob['created_at'] ?>)
                        </small>
                    </div>
                    <a href="/analysis/shardprogress/<?= urlencode($activeJob['job_id']) ?>" class="btn btn-primary btn-sm">
                        <i class="bi bi-eye"></i> View Progress
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header bg-info text-white">
                    <i class="bi bi-hdd-network"></i> Shard Analysis: <?= htmlspecialchars($board['board_name']) ?>
                    <code class="ms-2 text-white-50"><?= htmlspecialchars($board['project_key']) ?></code>
                    <span class="badge bg-warning text-dark float-end">Enterprise</span>
                </div>
                <div class="card-body">
                    <form method="POST" action="/analysis/runshard/<?= $board['id'] ?>" id="analysisForm">
                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="use_jira_mcp" name="use_jira_mcp" value="1" checked>
                                <label class="form-check-label" for="use_jira_mcp">
                                    <strong>Use Jira MCP</strong>
                                    <small class="text-muted d-block">Let the shard fetch issues from Jira directly via MCP server</small>
                                </label>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="send_email" name="send_email" value="1">
                                <label class="form-check-label" for="send_email">
                                    <strong>Send Email on Completion</strong>
                                    <small class="text-muted d-block">Email digest to <?= htmlspecialchars($board['digest_cc'] ?? 'your email') ?></small>
                                </label>
                            </div>
                        </div>

                        <hr>

                        <div class="alert alert-info">
                            <i class="bi bi-hdd-network"></i>
                            <strong>What happens with Shard Analysis:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Job is sent to a remote Claude Code shard</li>
                                <li>Shard uses Jira MCP to fetch current sprint issues</li>
                                <li>Claude analyzes priorities and generates digest</li>
                                <li>Results are returned via webhook callback</li>
                                <li>Email sent automatically (if enabled)</li>
                            </ul>
                        </div>

                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>Note:</strong> Shard analysis typically takes 1-3 minutes depending on the number of issues.
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-info btn-lg" id="submitBtn">
                                <i class="bi bi-hdd-network"></i> Run Shard Analysis
                            </button>
                            <a href="/analysis/run/<?= $board['id'] ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-right"></i> Use Local Analysis Instead
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Board Info -->
            <div class="card mt-4">
                <div class="card-header">
                    <i class="bi bi-info-circle"></i> Board Configuration
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <th style="width: 30%">Status Filter:</th>
                            <td><?= htmlspecialchars($board['status_filter'] ?? 'To Do') ?></td>
                        </tr>
                        <tr>
                            <th>Cloud ID:</th>
                            <td><code><?= htmlspecialchars($board['cloud_id']) ?></code></td>
                        </tr>
                        <?php if (!empty($board['digest_cc'])): ?>
                        <tr>
                            <th>Digest CC:</th>
                            <td><?= htmlspecialchars($board['digest_cc']) ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('analysisForm').addEventListener('submit', function() {
    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Sending to Shard...';
});
</script>
