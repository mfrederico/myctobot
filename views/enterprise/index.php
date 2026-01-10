<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">AI Developer</h1>
        <span class="badge bg-primary">Enterprise</span>
    </div>

    <!-- Credit Balance Warning -->
    <?php if (!empty($creditBalanceError)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <div class="d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
            <div>
                <strong>Anthropic API Credit Balance Issue</strong><br>
                <span class="small"><?= htmlspecialchars($creditBalanceError) ?></span>
                <div class="mt-2">
                    <a href="https://console.anthropic.com/settings/billing" target="_blank" class="btn btn-sm btn-outline-danger me-2">
                        <i class="bi bi-credit-card"></i> Add Credits at Anthropic
                    </a>
                    <a href="/settings/connections" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-key"></i> Update API Key
                    </a>
                </div>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <!-- Setup Progress -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Setup Status</h5>
                    <a href="/settings/connections" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-plug me-1"></i>Manage Connections
                    </a>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 text-center mb-3">
                            <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-2 <?= $apiKeySet ? 'bg-success' : 'bg-secondary' ?>" style="width: 50px; height: 50px;">
                                <i class="bi <?= $apiKeySet ? 'bi-check-lg' : 'bi-key' ?> text-white"></i>
                            </div>
                            <div class="fw-bold">
                                Anthropic API Key
                                <a href="#" data-bs-toggle="modal" data-bs-target="#apiKeyHelpModal" class="text-muted ms-1" title="What is this?">
                                    <i class="bi bi-question-circle"></i>
                                </a>
                            </div>
                            <small class="text-muted"><?= $apiKeySet ? 'Configured' : 'Not configured' ?></small>
                            <?php if (!$apiKeySet): ?>
                                <div><a href="/settings/connections" class="btn btn-sm btn-primary mt-2">Configure</a></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3 text-center mb-3">
                            <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-2 <?= $githubConnected ? 'bg-success' : 'bg-secondary' ?>" style="width: 50px; height: 50px;">
                                <i class="bi <?= $githubConnected ? 'bi-check-lg' : 'bi-github' ?> text-white"></i>
                            </div>
                            <div class="fw-bold">GitHub</div>
                            <small class="text-muted"><?= $githubConnected ? 'Connected' : 'Not connected' ?></small>
                            <?php if (!$githubConnected && $githubConfigured): ?>
                                <div><a href="/github" class="btn btn-sm btn-dark mt-2"><i class="bi bi-github"></i> Connect</a></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3 text-center mb-3">
                            <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-2 <?= count($repos) > 0 ? 'bg-success' : 'bg-secondary' ?>" style="width: 50px; height: 50px;">
                                <i class="bi <?= count($repos) > 0 ? 'bi-check-lg' : 'bi-folder' ?> text-white"></i>
                            </div>
                            <div class="fw-bold">Repositories</div>
                            <small class="text-muted"><?= count($repos) ?> connected</small>
                            <?php if (count($repos) === 0): ?>
                                <div><a href="/github/repos" class="btn btn-sm btn-primary mt-2">Add Repository</a></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3 text-center mb-3">
                            <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-2 <?= $hasWriteScopes ? 'bg-success' : 'bg-warning' ?>" style="width: 50px; height: 50px;">
                                <i class="bi <?= $hasWriteScopes ? 'bi-check-lg' : 'bi-exclamation-triangle' ?> text-white"></i>
                            </div>
                            <div class="fw-bold">Jira Write</div>
                            <small class="text-muted"><?= $hasWriteScopes ? 'Enabled' : 'Upgrade needed' ?></small>
                            <?php if (!$hasWriteScopes): ?>
                                <div><a href="/atlassian/upgradescopes" class="btn btn-sm btn-warning mt-2">Upgrade Scopes</a></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="btn-group w-100" role="group">
                <a href="/github/repos" class="btn btn-outline-primary">
                    <i class="bi bi-folder"></i> Repositories
                </a>
                <a href="/jobs" class="btn btn-outline-primary">
                    <i class="bi bi-list-task"></i> Jobs
                </a>
                <a href="/agents" class="btn btn-outline-primary">
                    <i class="bi bi-robot"></i> Agent Profiles
                </a>
                <a href="/admin/shards" class="btn btn-outline-primary">
                    <i class="bi bi-pc-display-horizontal"></i> Workstations
                </a>
                <a href="/settings/connections" class="btn btn-outline-primary">
                    <i class="bi bi-gear"></i> Settings
                </a>
            </div>
        </div>
    </div>

    <!-- Active Jobs -->
    <?php if (!empty($activeJobs)): ?>
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="card-title mb-0"><i class="bi bi-activity"></i> Active Jobs</h5>
        </div>
        <div class="card-body">
            <?php foreach ($activeJobs as $job): ?>
            <a href="/jobs/view/<?= urlencode($job['issue_key']) ?>" class="text-decoration-none text-body">
                <div class="border rounded p-3 mb-2 job-card" id="job-<?= htmlspecialchars($job['issue_key']) ?>">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong><?= htmlspecialchars($job['issue_key']) ?></strong>
                            <span class="badge <?= $job['status'] === 'waiting_clarification' ? 'bg-warning' : 'bg-info' ?> ms-2">
                                <?= ucfirst(str_replace('_', ' ', $job['status'])) ?>
                            </span>
                        </div>
                        <small class="text-muted">
                            Run #<?= $job['run_count'] ?? 1 ?>
                            <?php if ($job['status'] === 'running'): ?>
                            <span class="spinner-border spinner-border-sm ms-2" role="status"></span>
                            <?php endif; ?>
                        </small>
                    </div>
                    <?php if ($job['status'] === 'waiting_clarification'): ?>
                    <div class="mt-2">
                        <small class="text-warning"><i class="bi bi-hourglass-split"></i> Waiting for clarification response in Jira</small>
                    </div>
                    <?php endif; ?>
                    <div class="mt-2 text-muted small">
                        <i class="bi bi-arrow-right"></i> Click to view details and output
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-lightning"></i> Quick Start</h5>
                    <p class="card-text">Start an AI Developer job on a Jira ticket</p>
                    <?php if ($apiKeySet && count($repos) > 0 && count($sites) > 0): ?>
                    <form id="quick-start-form">
                        <div class="mb-3">
                            <label for="issue_key" class="form-label">Issue Key</label>
                            <input type="text" class="form-control" id="issue_key" placeholder="e.g., PROJ-123" required>
                        </div>
                        <div class="mb-3">
                            <label for="board_id" class="form-label">Board</label>
                            <select class="form-select" id="board_id" required>
                                <option value="">Select a board...</option>
                                <?php foreach ($boards ?? [] as $board): ?>
                                <option value="<?= $board['id'] ?>" data-cloud-id="<?= htmlspecialchars($board['cloud_id'] ?? '') ?>">
                                    <?= htmlspecialchars($board['board_name']) ?> (<?= htmlspecialchars($board['project_key']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="repo_id" class="form-label">Repository</label>
                            <select class="form-select" id="repo_id" required>
                                <option value="">Select a repository...</option>
                                <?php foreach ($repos as $repo): ?>
                                <option value="<?= $repo['id'] ?>"><?= htmlspecialchars($repo['repo_owner'] . '/' . $repo['repo_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input type="hidden" id="cloud_id" value="">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-robot"></i> Start AI Developer
                        </button>
                    </form>
                    <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> Complete the setup above to start using AI Developer.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-info-circle"></i> How It Works</h5>
                    <ol class="ps-3">
                        <li>Enter a Jira issue key or add the <code>ai-dev</code> label in Jira</li>
                        <li>AI analyzes the requirements and checks clarity</li>
                        <li>If unclear, AI posts questions to Jira</li>
                        <li>AI clones repo, implements changes, creates PR</li>
                        <li>Review and merge the PR</li>
                    </ol>
                    <a href="/jobs" class="btn btn-outline-primary">
                        <i class="bi bi-list-ul"></i> View All Jobs
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Jobs -->
    <?php if (!empty($jobs)): ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">Recent Jobs</h5>
            <a href="/jobs" class="btn btn-sm btn-outline-primary">View All</a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Issue</th>
                        <th>Status</th>
                        <th>PR</th>
                        <th>Started</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($jobs, 0, 5) as $job): ?>
                    <tr class="cursor-pointer" onclick="window.location='/jobs/view/<?= urlencode($job['issue_key']) ?>'">
                        <td><strong><?= htmlspecialchars($job['issue_key']) ?></strong></td>
                        <td>
                            <span class="badge <?php
                                switch ($job['status']) {
                                    case 'complete': echo 'bg-success'; break;
                                    case 'pr_created': echo 'bg-success'; break;
                                    case 'failed': echo 'bg-danger'; break;
                                    case 'waiting_clarification': echo 'bg-warning'; break;
                                    case 'running': echo 'bg-info'; break;
                                    default: echo 'bg-secondary';
                                }
                            ?>">
                                <?= ucfirst(str_replace('_', ' ', $job['status'])) ?>
                            </span>
                            <?php if ($job['status'] === 'failed' && !empty($job['error_message'])): ?>
                            <div class="small text-danger mt-1" style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($job['error_message']) ?>">
                                <?= htmlspecialchars($job['error_message']) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($job['pr_url'])): ?>
                            <a href="<?= htmlspecialchars($job['pr_url']) ?>" target="_blank" class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation();">
                                #<?= $job['pr_number'] ?? 'View' ?>
                            </a>
                            <?php else: ?>
                            -
                            <?php endif; ?>
                        </td>
                        <td><small class="text-muted"><?= htmlspecialchars($job['started_at'] ?? $job['created_at'] ?? '') ?></small></td>
                        <td><a href="/jobs/view/<?= urlencode($job['issue_key']) ?>" class="btn btn-sm btn-outline-secondary" onclick="event.stopPropagation();"><i class="bi bi-eye"></i></a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Anthropic API Key Help Modal -->
<div class="modal fade" id="apiKeyHelpModal" tabindex="-1" aria-labelledby="apiKeyHelpModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="apiKeyHelpModalLabel">
                    <i class="bi bi-key"></i> Getting Your Anthropic API Key
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>
                    The AI Developer uses <strong>Claude</strong>, Anthropic's AI assistant, to analyze Jira tickets,
                    understand your codebase, and generate code implementations.
                </p>
                <p>To use this feature, you need your own Anthropic API key:</p>
                <ol>
                    <li>Go to <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a></li>
                    <li>Sign in or create an account</li>
                    <li>Navigate to <strong>API Keys</strong> in your account settings</li>
                    <li>Click <strong>Create Key</strong> to generate a new API key</li>
                    <li>Copy the key (it starts with <code>sk-ant-</code>)</li>
                    <li>Paste it in the <a href="/settings/connections">Settings</a> page</li>
                </ol>
                <div class="alert alert-info mb-0">
                    <i class="bi bi-info-circle"></i>
                    <strong>Note:</strong> Your API key is encrypted and stored securely.
                    API usage is billed directly to your Anthropic account.
                </div>
            </div>
            <div class="modal-footer">
                <a href="https://console.anthropic.com/" target="_blank" class="btn btn-primary">
                    <i class="bi bi-box-arrow-up-right"></i> Open Anthropic Console
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const boardSelect = document.getElementById('board_id');
    const cloudIdInput = document.getElementById('cloud_id');

    // Update cloud_id when board is selected
    if (boardSelect) {
        boardSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const cloudId = selectedOption.getAttribute('data-cloud-id') || '';
            cloudIdInput.value = cloudId;
        });
    }

    // Handle quick start form
    const form = document.getElementById('quick-start-form');
    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            const issueKey = document.getElementById('issue_key').value;
            const boardId = document.getElementById('board_id').value;
            const repoId = document.getElementById('repo_id').value;
            const selectedBoardOption = boardSelect.options[boardSelect.selectedIndex];
            const cloudId = selectedBoardOption.getAttribute('data-cloud-id') || '';

            try {
                const response = await fetch('/jobs/start', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        issue_key: issueKey,
                        board_id: boardId,
                        repo_id: repoId,
                        cloud_id: cloudId,
                        <?php if (!empty($csrf)): ?>
                        csrf_token: '<?= $csrf['csrf_token'] ?? '' ?>'
                        <?php endif; ?>
                    })
                });

                const data = await response.json();
                if (data.success) {
                    alert('Job started! Check the active jobs section.');
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Error: ' + err.message);
            }
        });
    }
});
</script>
