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
        <a href="/github" class="btn btn-dark">
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
                        <a href="/github" class="btn btn-dark">
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
                                    <?php if (!empty($repo['webhook_id'])): ?>
                                    <span class="badge bg-info" title="Webhook auto-configured">
                                        <i class="bi bi-link-45deg"></i> Webhook
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-warning text-dark" title="Webhook needs manual setup - see instructions below">
                                        <i class="bi bi-exclamation-triangle"></i> No Webhook
                                    </span>
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div>
                                <a href="/github/disconnectrepo/<?= $repo['id'] ?>" class="btn btn-sm btn-outline-danger"
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
                                    (<?= htmlspecialchars($agent['provider_label'] ?? $agent['provider']) ?>)
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
                        <?php if ($repo['provider'] === 'github'): ?>
                        <!-- Issue Tracking Source -->
                        <div class="d-flex align-items-center gap-2 pt-2 border-top">
                            <label class="text-muted small mb-0" style="min-width: 80px;">
                                <i class="bi bi-card-checklist"></i> Issues:
                            </label>
                            <select class="form-select form-select-sm" style="max-width: 200px;"
                                    id="issuesSource<?= $repo['id'] ?>"
                                    onchange="setIssueSource(<?= $repo['id'] ?>, this.value, this)">
                                <option value="jira" <?= !$repo['issues_enabled'] ? 'selected' : '' ?>>
                                    Jira (default)
                                </option>
                                <option value="github" <?= $repo['issues_enabled'] ? 'selected' : '' ?>>
                                    GitHub Issues
                                </option>
                            </select>
                            <span id="issuesStatus<?= $repo['id'] ?>" class="ms-auto"></span>
                        </div>
                        <?php endif; ?>
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
                    <div class="mb-3">
                        <label for="addRepoSelect" class="form-label">Select Repository</label>
                        <select class="form-select" id="addRepoSelect">
                            <option value="">Choose a repository...</option>
                            <?php
                            $connectedRepoNames = array_map(fn($r) => $r['repo_owner'] . '/' . $r['repo_name'], $repos);
                            foreach ($availableRepos as $availRepo):
                                $fullName = $availRepo['full_name'];
                                if (in_array($fullName, $connectedRepoNames)) continue;
                            ?>
                            <option value="<?= htmlspecialchars($fullName) ?>" data-branch="<?= htmlspecialchars($availRepo['default_branch'] ?? 'main') ?>">
                                <?= htmlspecialchars($fullName) ?>
                                <?= $availRepo['private'] ? ' (private)' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="button" class="btn btn-primary" id="addRepoBtn" onclick="connectRepository()">
                        <i class="bi bi-plus-lg"></i> Connect Repository
                    </button>
                    <span id="addRepoStatus" class="ms-2"></span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Board Repository Mappings (Board-Centric) -->
            <?php if (!empty($boards)): ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Board Repository Mappings</h5>
                    <a href="/boards" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-kanban"></i> Manage Boards
                    </a>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-4">
                        Map repositories to boards. Add both labels to a Jira ticket: <code>repo-{id}</code> specifies the repository, then <code>ai-dev</code> triggers the job.
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
                                            $fullLabels = $repoLabel . ' ai-dev';
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
                                                    <code class="bg-secondary text-white px-2 py-1 rounded"><?= $repoLabel ?></code>
                                                    <code class="bg-primary text-white px-2 py-1 rounded">ai-dev</code>
                                                    <button class="btn btn-sm btn-outline-secondary" type="button"
                                                            onclick="copyLabel('<?= $fullLabels ?>', this)" title="Copy both labels">
                                                        <i class="bi bi-clipboard"></i>
                                                    </button>
                                                </div>
                                            </td>
                                            <td>
                                                <a href="/github/unmapboard?board_id=<?= $board['id'] ?>&repo_id=<?= $repo['id'] ?>"
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
                            <form method="POST" action="/github/mapboard" class="d-flex gap-2">
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
                        Each repository has a unique label like <code>repo-42</code>.
                        Add this label first, then add <code>ai-dev</code> to trigger the job.
                    </p>
                    <p class="text-muted small mb-0">
                        <strong>Example:</strong> If your frontend repo has label <code>repo-5</code> and backend
                        has <code>repo-8</code>, add the appropriate repo label first, then <code>ai-dev</code> to trigger.
                    </p>
                </div>
            </div>

            <div class="card mt-3 border-info">
                <div class="card-header bg-info text-white">
                    <i class="bi bi-github"></i> GitHub Webhook Setup
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-2">
                        <i class="bi bi-check-circle text-success"></i> <strong>Webhooks are auto-created</strong> when you connect a repository. If a repo shows "No Webhook", you may need to set it up manually:
                    </p>
                    <ol class="small mb-3">
                        <li>Go to your repo on GitHub</li>
                        <li>Click <strong>Settings</strong> &rarr; <strong>Webhooks</strong></li>
                        <li>Click <strong>Add webhook</strong></li>
                        <li>Configure:
                            <ul class="mb-1">
                                <li><strong>Payload URL:</strong><br>
                                    <code class="user-select-all">https://myctobot.ai/webhook/github</code>
                                </li>
                                <li><strong>Content type:</strong> <code>application/json</code></li>
                                <li><strong>Events:</strong> Select "Let me select individual events" then check <strong>Issues</strong> and <strong>Issue comments</strong></li>
                            </ul>
                        </li>
                        <li>Click <strong>Add webhook</strong></li>
                    </ol>
                    <p class="text-muted small mb-0">
                        <i class="bi bi-info-circle"></i> Manual setup is usually needed if you don't have admin access to the repo. After adding, check "Recent Deliveries" for a green checkmark to confirm it's working.
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
                        <a href="/settings/connections" class="list-group-item list-group-item-action">
                            <i class="bi bi-gear"></i> Settings
                        </a>
                        <a href="/jobs" class="list-group-item list-group-item-action">
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
    fetch('/github/assignagent', {
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

function setIssueSource(repoId, source, selectEl) {
    const statusEl = document.getElementById('issuesStatus' + repoId);
    const enabled = (source === 'github');
    const previousValue = enabled ? 'jira' : 'github';

    fetch('/github/toggleissues', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            id: repoId,
            enabled: enabled
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show brief success indicator
            statusEl.innerHTML = '<i class="bi bi-check-circle text-success"></i>';
            selectEl.style.borderColor = '#198754';
            setTimeout(() => {
                statusEl.innerHTML = '';
                selectEl.style.borderColor = '';
            }, 1500);
        } else {
            // Revert dropdown on error
            selectEl.value = previousValue;
            alert('Error: ' + (data.message || 'Failed to update issue source'));
        }
    })
    .catch(error => {
        selectEl.value = previousValue;
        alert('Error: ' + error.message);
    });
}

function connectRepository() {
    const select = document.getElementById('addRepoSelect');
    const btn = document.getElementById('addRepoBtn');
    const status = document.getElementById('addRepoStatus');
    const fullName = select.value;
    const defaultBranch = select.options[select.selectedIndex]?.dataset?.branch || 'main';

    if (!fullName) {
        alert('Please select a repository');
        return;
    }

    btn.disabled = true;
    status.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Connecting...';

    fetch('/github/addrepo', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            full_name: fullName,
            default_branch: defaultBranch
        })
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        if (data.success) {
            status.innerHTML = '<i class="bi bi-check-circle text-success"></i> Connected!';
            // Reload page to show new repo
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            status.innerHTML = '<i class="bi bi-x-circle text-danger"></i> ' + (data.message || 'Failed');
        }
    })
    .catch(error => {
        btn.disabled = false;
        status.innerHTML = '<i class="bi bi-x-circle text-danger"></i> Error: ' + error.message;
    });
}
</script>
