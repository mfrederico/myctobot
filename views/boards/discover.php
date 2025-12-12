<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>Discover Jira Boards</h1>
                <a href="/boards" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Boards
                </a>
            </div>

            <?php if (empty($sites)): ?>
            <div class="alert alert-warning">
                <h4 class="alert-heading"><i class="bi bi-exclamation-triangle"></i> No Connected Sites</h4>
                <p>You need to connect your Atlassian account before discovering boards.</p>
                <hr>
                <a href="/atlassian/connect" class="btn btn-primary">
                    <i class="bi bi-link-45deg"></i> Connect Atlassian
                </a>
            </div>
            <?php else: ?>

            <?php foreach ($sites as $site): ?>
            <div class="card mb-4">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <span>
                        <i class="bi bi-cloud-check"></i>
                        <strong><?= htmlspecialchars($site->site_name) ?></strong>
                        <small class="ms-2"><?= htmlspecialchars($site->site_url) ?></small>
                    </span>
                    <span class="badge bg-light text-dark"><?= count($jiraBoards[$site->cloud_id] ?? []) ?> boards</span>
                </div>
                <div class="card-body">
                    <?php if (empty($jiraBoards[$site->cloud_id])): ?>
                    <p class="text-muted">No boards found on this site, or there was an error fetching boards.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Board Name</th>
                                    <th>Type</th>
                                    <th>Project</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($jiraBoards[$site->cloud_id] as $board): ?>
                                <?php
                                    $isTracked = false;
                                    $trackedBoardId = null;
                                    foreach ($existingBoards as $existing) {
                                        if ($existing['board_id'] == $board['id'] && $existing['cloud_id'] == $site->cloud_id) {
                                            $isTracked = true;
                                            $trackedBoardId = $existing['id'];
                                            break;
                                        }
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($board['name']) ?></strong>
                                        <br>
                                        <small class="text-muted">ID: <?= $board['id'] ?></small>
                                    </td>
                                    <td>
                                        <span class="badge <?= $board['type'] === 'scrum' ? 'bg-primary' : 'bg-info' ?>">
                                            <?= ucfirst($board['type']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($board['project_key'])): ?>
                                        <code><?= htmlspecialchars($board['project_key']) ?></code>
                                        <br>
                                        <small class="text-muted"><?= htmlspecialchars($board['project_name'] ?? '') ?></small>
                                        <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isTracked): ?>
                                        <span class="badge bg-success">
                                            <i class="bi bi-check"></i> Tracked
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">Not Tracked</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isTracked): ?>
                                        <a href="/boards/edit/<?= $trackedBoardId ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-gear"></i> Configure
                                        </a>
                                        <?php else: ?>
                                        <form method="POST" action="/boards/add" class="d-inline">
                                            <input type="hidden" name="cloud_id" value="<?= htmlspecialchars($site->cloud_id) ?>">
                                            <input type="hidden" name="board_id" value="<?= $board['id'] ?>">
                                            <input type="hidden" name="board_name" value="<?= htmlspecialchars($board['name']) ?>">
                                            <input type="hidden" name="board_type" value="<?= htmlspecialchars($board['type']) ?>">
                                            <input type="hidden" name="project_key" value="<?= htmlspecialchars($board['project_key'] ?? '') ?>">
                                            <input type="hidden" name="project_name" value="<?= htmlspecialchars($board['project_name'] ?? '') ?>">
                                            <input type="hidden" name="site_name" value="<?= htmlspecialchars($site->site_name) ?>">
                                            <input type="hidden" name="site_url" value="<?= htmlspecialchars($site->site_url) ?>">
                                            <button type="submit" class="btn btn-sm btn-success">
                                                <i class="bi bi-plus"></i> Add Board
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php endif; ?>
        </div>
    </div>
</div>
