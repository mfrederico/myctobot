<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Jira Boards</h1>
                <div>
                    <a href="/analysis" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-graph-up"></i> Analysis
                    </a>
                    <?php if ($hasAtlassian): ?>
                    <a href="/boards/discover" class="btn btn-primary">
                        <i class="bi bi-search"></i> Discover Boards
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$hasAtlassian && empty($boards)): ?>
            <div class="card border-warning mb-4">
                <div class="card-body">
                    <h4 class="card-title"><i class="bi bi-exclamation-triangle text-warning"></i> Connect Your Jira Account</h4>
                    <p class="card-text">You need to connect your Atlassian account before you can manage Jira boards.</p>
                    <?php if ($atlassianConfigured): ?>
                    <a href="/atlassian/connect" class="btn btn-primary">
                        <i class="bi bi-link-45deg"></i> Connect Atlassian
                    </a>
                    <?php else: ?>
                    <p class="mb-0 text-muted">Atlassian integration is not configured. Please contact the administrator.</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php elseif (!$hasAtlassian && !empty($boards)): ?>
            <!-- Orphaned boards - Atlassian disconnected but boards exist -->
            <div class="card border-warning mb-4">
                <div class="card-body">
                    <h4 class="card-title"><i class="bi bi-exclamation-triangle text-warning"></i> Atlassian Disconnected</h4>
                    <p class="card-text">Your Atlassian account is not connected, but you have existing boards. You can reconnect to restore access, or delete orphaned boards below.</p>
                    <?php if ($atlassianConfigured): ?>
                    <a href="/atlassian/connect" class="btn btn-primary">
                        <i class="bi bi-link-45deg"></i> Reconnect Atlassian
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <i class="bi bi-kanban"></i> Disconnected Boards
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Board</th>
                                    <th>Project</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($boards as $board): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($board['board_name']) ?></strong>
                                    </td>
                                    <td><code><?= htmlspecialchars($board['project_key']) ?></code></td>
                                    <td>
                                        <span class="badge bg-warning text-dark">
                                            <i class="bi bi-unlink"></i> Disconnected
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                onclick="removeBoard(<?= $board['id'] ?>, '<?= htmlspecialchars($board['board_name'], ENT_QUOTES) ?>')"
                                                title="Delete Board">
                                            <i class="bi bi-trash"></i> Delete
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php elseif (empty($boards)): ?>
            <div class="alert alert-info">
                <h4 class="alert-heading"><i class="bi bi-info-circle"></i> No Boards Added Yet</h4>
                <p>You haven't added any Jira boards to track. Discover available boards from your connected Atlassian sites.</p>
                <hr>
                <a href="/boards/discover" class="btn btn-primary">
                    <i class="bi bi-search"></i> Discover Boards
                </a>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-kanban"></i> Your Tracked Boards
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Board</th>
                                    <th>Project</th>
                                    <th>Site</th>
                                    <th>Status</th>
                                    <th>Daily Digest</th>
                                    <th>Status Filter</th>
                                    <th>Last Analysis</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($boards as $board): ?>
                                <tr>
                                    <td>
                                        <a href="/boards/edit/<?= $board['id'] ?>">
                                            <strong><?= htmlspecialchars($board['board_name']) ?></strong>
                                        </a>
                                    </td>
                                    <td><code><?= htmlspecialchars($board['project_key']) ?></code></td>
                                    <td>
                                        <small class="text-muted"><?= htmlspecialchars($board['site_name'] ?? 'Unknown') ?></small>
                                    </td>
                                    <td>
                                        <?php if ($board['enabled']): ?>
                                        <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">Disabled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($board['digest_enabled']): ?>
                                        <span class="badge bg-info">
                                            <i class="bi bi-clock"></i> <?= htmlspecialchars($board['digest_time']) ?>
                                        </span>
                                        <br>
                                        <small class="text-muted"><?= htmlspecialchars($board['timezone'] ?? 'UTC') ?></small>
                                        <?php else: ?>
                                        <span class="text-muted">Off</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small><?= htmlspecialchars($board['status_filter'] ?? 'To Do') ?></small>
                                    </td>
                                    <td>
                                        <?php if ($board['last_analysis_at']): ?>
                                        <small><?= date('M j, H:i', strtotime($board['last_analysis_at'])) ?></small>
                                        <?php else: ?>
                                        <span class="text-muted">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="/analysis/run/<?= $board['id'] ?>" class="btn btn-sm btn-primary" title="Run Analysis">
                                                <i class="bi bi-play-fill"></i>
                                            </a>
                                            <a href="/boards/edit/<?= $board['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit">
                                                <i class="bi bi-gear"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm <?= $board['enabled'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                                    onclick="toggleBoard(<?= $board['id'] ?>)"
                                                    title="<?= $board['enabled'] ? 'Disable' : 'Enable' ?>">
                                                <i class="bi <?= $board['enabled'] ? 'bi-pause' : 'bi-play' ?>"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    onclick="removeBoard(<?= $board['id'] ?>, '<?= htmlspecialchars($board['board_name']) ?>')"
                                                    title="Remove">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
    <?php endif; ?>
</div>

<script>
function toggleBoard(boardId) {
    fetch('/boards/toggle/' + boardId, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to toggle board'));
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
}

function removeBoard(boardId, boardName) {
    if (confirm('Are you sure you want to remove "' + boardName + '"? This will delete all analysis history for this board.')) {
        fetch('/boards/remove/' + boardId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Failed to remove board'));
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
        });
    }
}
</script>
