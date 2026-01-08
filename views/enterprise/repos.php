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
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Connected Repositories</h5>
                </div>
                <?php if (empty($repos)): ?>
                <div class="card-body">
                    <div class="text-center py-4">
                        <i class="bi bi-folder2-open display-4 text-muted"></i>
                        <p class="text-muted mt-2">No repositories connected yet.</p>
                        <?php if ($githubConnected): ?>
                        <p>Add a repository below to get started.</p>
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
                        <div class="d-flex justify-content-between align-items-center mb-2">
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
                        <!-- Agent Assignment -->
                        <div class="d-flex align-items-center gap-2 pt-2 border-top">
                            <label class="text-muted small mb-0" style="min-width: 60px;">
                                <i class="bi bi-robot"></i> Agent:
                            </label>
                            <select class="form-select form-select-sm" style="max-width: 250px;"
                                    onchange="assignAgent(<?= $repo['id'] ?>, this.value, this)">
                                <option value="">-- Use Default --</option>
                                <?php foreach ($agents ?? [] as $agent): ?>
                                <option value="<?= $agent['id'] ?>"
                                        <?= ($repo['agent_id'] ?? null) == $agent['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($agent['name']) ?>
                                    (<?= htmlspecialchars($agent['runner_type_label'] ?? $agent['runner_type']) ?>)
                                    <?= $agent['is_default'] ? '[Default]' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!empty($repo['agent_name'])): ?>
                            <small class="text-muted">
                                <i class="bi bi-check-circle text-success"></i>
                            </small>
                            <?php endif; ?>
                            <a href="/agents" class="btn btn-sm btn-link text-muted p-0 ms-auto">
                                <i class="bi bi-gear"></i> Manage Agents
                            </a>
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

            <!-- Board Repository Mappings (Board-Centric) -->
            <?php if (!empty($boards)): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Board Repository Mappings</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-4">
                        Map repositories to boards. Add both labels to a Jira ticket: <code>ai-dev</code> triggers the job, <code>repo-{id}</code> specifies which repository.
                    </p>

                    <?php foreach ($boards as $board):
                        $boardMappings = $mappings[$board['id']] ?? [];
                    ?>
                    <div class="card mb-3 bg-light">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h6 class="mb-1">
                                        <i class="bi bi-kanban"></i>
                                        <?= htmlspecialchars($board['board_name']) ?>
                                    </h6>
                                    <small class="text-muted"><?= htmlspecialchars($board['project_key']) ?></small>
                                </div>
                            </div>

                            <?php if (empty($boardMappings)): ?>
                            <p class="text-muted mb-3"><em>No repositories mapped</em></p>
                            <?php else: ?>
                            <div class="table-responsive mb-3">
                                <table class="table table-sm table-bordered bg-white mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Repository</th>
                                            <th style="width: 240px;">Jira Labels</th>
                                            <th style="width: 80px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($boardMappings as $mapping):
                                            $repo = null;
                                            foreach ($repos as $r) {
                                                if ($r['id'] == $mapping['repo_connection_id']) {
                                                    $repo = $r;
                                                    break;
                                                }
                                            }
                                            if (!$repo) continue;
                                            $repoLabel = 'repo-' . $repo['id'];
                                            $fullLabels = 'ai-dev ' . $repoLabel;
                                        ?>
                                        <tr>
                                            <td>
                                                <i class="bi bi-github"></i>
                                                <?= htmlspecialchars($repo['repo_owner'] . '/' . $repo['repo_name']) ?>
                                                <br>
                                                <small class="text-muted">Branch: <?= htmlspecialchars($repo['default_branch']) ?></small>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-1 align-items-center">
                                                    <code class="bg-primary text-white px-2 py-1 rounded">ai-dev</code>
                                                    <code class="bg-secondary text-white px-2 py-1 rounded"><?= $repoLabel ?></code>
                                                    <button class="btn btn-sm btn-outline-secondary" type="button"
                                                            onclick="copyLabel('<?= $fullLabels ?>', this)" title="Copy both labels">
                                                        <i class="bi bi-clipboard"></i>
                                                    </button>
                                                </div>
                                            </td>
                                            <td>
                                                <a href="/enterprise/unmapboard?board_id=<?= $board['id'] ?>&repo_id=<?= $repo['id'] ?>"
                                                   class="btn btn-sm btn-outline-danger"
                                                   onclick="return confirm('Remove this mapping?')">
                                                    <i class="bi bi-x-lg"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>

                            <!-- Add repo to this board -->
                            <?php if (!empty($repos)): ?>
                            <?php
                            // Find repos not yet mapped to this board
                            $mappedRepoIds = array_map(fn($m) => $m['repo_connection_id'], $boardMappings);
                            $unmappedRepos = array_filter($repos, fn($r) => !in_array($r['id'], $mappedRepoIds));
                            ?>
                            <?php if (!empty($unmappedRepos)): ?>
                            <form method="POST" action="/enterprise/mapboard" class="d-flex gap-2">
                                <?php if (!empty($csrf) && is_array($csrf)): ?>
                                    <?php foreach ($csrf as $name => $value): ?>
                                        <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <input type="hidden" name="board_id" value="<?= $board['id'] ?>">
                                <select class="form-select form-select-sm" name="repo_id" required style="max-width: 300px;">
                                    <option value="">Add repository...</option>
                                    <?php foreach ($unmappedRepos as $repo): ?>
                                    <option value="<?= $repo['id'] ?>">
                                        <?= htmlspecialchars($repo['repo_owner'] . '/' . $repo['repo_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-primary">
                                    <i class="bi bi-plus-lg"></i> Add
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="alert alert-info mt-3 mb-0">
                        <i class="bi bi-info-circle"></i>
                        <strong>How it works:</strong> Add the Jira label (e.g., <code>ai-dev-42</code>) to a ticket to trigger
                        AI Developer for that specific repository. The label tells the system exactly which repo to use.
                    </div>
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
                        <li>Each repo gets a unique label for AI Developer</li>
                    </ul>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-body">
                    <h5 class="card-title">Using Labels</h5>
                    <p class="text-muted small">
                        Each repository has a unique label like <code>ai-dev-42</code>.
                        Add this label to a Jira ticket to trigger AI Developer for that specific repo.
                    </p>
                    <p class="text-muted small mb-0">
                        <strong>Example:</strong> If your frontend repo has label <code>ai-dev-5</code> and backend
                        has <code>ai-dev-8</code>, use the appropriate label based on which codebase the ticket affects.
                    </p>
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

<script>
function copyLabel(label, btn) {
    navigator.clipboard.writeText(label).then(() => {
        const icon = btn.querySelector('i');
        icon.className = 'bi bi-check';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-success');
        setTimeout(() => {
            icon.className = 'bi bi-clipboard';
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-secondary');
        }, 1500);
    });
}

function assignAgent(repoId, agentId, selectEl) {
    fetch('/enterprise/assignagent', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            repo_id: repoId,
            agent_id: agentId || null
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show brief success indicator
            selectEl.style.borderColor = '#198754';
            setTimeout(() => {
                selectEl.style.borderColor = '';
            }, 1500);
        } else {
            alert('Error: ' + (data.message || 'Failed to assign agent'));
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
}
</script>
