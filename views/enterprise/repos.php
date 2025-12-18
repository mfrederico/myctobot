<div class="container py-4">
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/enterprise">AI Developer</a></li>
            <li class="breadcrumb-item active">Repositories</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2 mb-0">Repository Connections</h1>
        <?php if (!$githubConnected): ?>
        <a href="/enterprise/github" class="btn btn-dark">
            <i class="bi bi-github"></i> Connect GitHub
        </a>
        <?php endif; ?>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <!-- Connected Repositories -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Connected Repositories</h5>
                </div>
                <?php if (empty($repos)): ?>
                <div class="card-body">
                    <div class="text-center py-4">
                        <i class="bi bi-folder2-open display-4 text-muted"></i>
                        <p class="text-muted mt-2">No repositories connected yet.</p>
                        <?php if ($githubConnected): ?>
                        <p>Add a repository from the list below.</p>
                        <?php else: ?>
                        <a href="/enterprise/github" class="btn btn-dark">
                            <i class="bi bi-github"></i> Connect GitHub First
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($repos as $repo): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-<?= $repo['provider'] === 'github' ? 'github' : 'git' ?>"></i>
                                <strong><?= htmlspecialchars($repo['repo_owner'] . '/' . $repo['repo_name']) ?></strong>
                                <br>
                                <small class="text-muted">
                                    Branch: <?= htmlspecialchars($repo['default_branch']) ?>
                                    <?php if ($repo['enabled']): ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Disabled</span>
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div>
                                <a href="/enterprise/disconnectrepo/<?= $repo['id'] ?>" class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Are you sure you want to disconnect this repository?')">
                                    <i class="bi bi-x-lg"></i> Disconnect
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Add Repository -->
            <?php if ($githubConnected && !empty($availableRepos)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Add Repository</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="/enterprise/connectrepo">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <input type="hidden" name="provider" value="github">

                        <div class="mb-3">
                            <label for="repo" class="form-label">Select Repository</label>
                            <select class="form-select" name="repo" id="repo" required>
                                <option value="">Choose a repository...</option>
                                <?php
                                $connectedRepoNames = array_map(fn($r) => $r['repo_owner'] . '/' . $r['repo_name'], $repos);
                                foreach ($availableRepos as $availRepo):
                                    $fullName = $availRepo['full_name'];
                                    if (in_array($fullName, $connectedRepoNames)) continue;
                                ?>
                                <option value="<?= htmlspecialchars($fullName) ?>">
                                    <?= htmlspecialchars($fullName) ?>
                                    <?= $availRepo['private'] ? ' (private)' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-lg"></i> Connect Repository
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Board Mappings -->
            <?php if (!empty($repos) && !empty($boards)): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Board to Repository Mappings</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">Map your Jira boards to repositories for AI Developer.</p>

                    <form method="POST" action="/enterprise/mapboard">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-5">
                                <label for="board_id" class="form-label">Board</label>
                                <select class="form-select" name="board_id" id="board_id" required>
                                    <option value="">Select board...</option>
                                    <?php foreach ($boards as $board): ?>
                                    <option value="<?= $board['id'] ?>">
                                        <?= htmlspecialchars($board['board_name']) ?> (<?= htmlspecialchars($board['project_key']) ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label for="repo_id" class="form-label">Repository</label>
                                <select class="form-select" name="repo_id" id="repo_id" required>
                                    <option value="">Select repository...</option>
                                    <?php foreach ($repos as $repo): ?>
                                    <option value="<?= $repo['id'] ?>">
                                        <?= htmlspecialchars($repo['repo_owner'] . '/' . $repo['repo_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-link"></i> Map
                                </button>
                            </div>
                        </div>
                    </form>

                    <?php if (!empty($mappings)): ?>
                    <hr>
                    <h6>Current Mappings</h6>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Board</th>
                                <th>Repository</th>
                                <th>Default</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mappings as $boardId => $boardMappings):
                                $board = array_filter($boards, fn($b) => $b['id'] == $boardId);
                                $board = reset($board);
                                foreach ($boardMappings as $mapping):
                                    $repo = array_filter($repos, fn($r) => $r['id'] == $mapping['repo_connection_id']);
                                    $repo = reset($repo);
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($board['board_name'] ?? 'Unknown') ?></td>
                                <td><?= htmlspecialchars(($repo['repo_owner'] ?? '') . '/' . ($repo['repo_name'] ?? '')) ?></td>
                                <td><?= $mapping['is_default'] ? '<span class="badge bg-primary">Default</span>' : '' ?></td>
                            </tr>
                            <?php endforeach; endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">About Repository Connections</h5>
                    <p class="text-muted">
                        Connect your GitHub repositories to enable the AI Developer to create branches, commit code, and open pull requests.
                    </p>
                    <ul class="small">
                        <li>OAuth tokens are securely encrypted</li>
                        <li>Only repositories you have push access to are shown</li>
                        <li>Map boards to specific repositories for automatic PR creation</li>
                    </ul>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-body">
                    <h5 class="card-title">Quick Links</h5>
                    <div class="list-group">
                        <a href="/enterprise" class="list-group-item list-group-item-action">
                            <i class="bi bi-house"></i> AI Developer Dashboard
                        </a>
                        <a href="/enterprise/settings" class="list-group-item list-group-item-action">
                            <i class="bi bi-gear"></i> Settings
                        </a>
                        <a href="/enterprise/jobs" class="list-group-item list-group-item-action">
                            <i class="bi bi-list-ul"></i> View Jobs
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
