<div class="container py-4">
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/enterprise">AI Developer</a></li>
            <li class="breadcrumb-item active">Settings</li>
        </ol>
    </nav>

    <h1 class="h2 mb-4">Enterprise Settings</h1>

    <div class="row">
        <div class="col-md-8">
            <!-- API Keys Configuration -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="bi bi-key"></i> Anthropic API Keys</h5>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#addKeyForm">
                        <i class="bi bi-plus"></i> Add Key
                    </button>
                </div>
                <div class="card-body">
                    <p class="text-muted small">
                        Manage multiple API keys with different models. Assign keys to boards for per-project billing or use local runner mode.
                    </p>

                    <!-- Add Key Form (collapsed) -->
                    <div class="collapse mb-3" id="addKeyForm">
                        <div class="card card-body bg-light">
                            <form method="POST" action="/enterprise/addkey">
                                <?php if (!empty($csrf) && is_array($csrf)): ?>
                                    <?php foreach ($csrf as $name => $value): ?>
                                        <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="key_name" class="form-label">Name</label>
                                        <input type="text" class="form-control" id="key_name" name="key_name"
                                               placeholder="e.g., Production - Sonnet" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="key_model" class="form-label">Model</label>
                                        <select class="form-select" id="key_model" name="key_model">
                                            <option value="claude-sonnet-4-20250514">Claude Sonnet 4 (Recommended)</option>
                                            <option value="claude-haiku-3-5-20241022">Claude Haiku 3.5 (Faster/Cheaper)</option>
                                            <option value="claude-opus-4-20250514">Claude Opus 4 (Most Capable)</option>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label for="api_key" class="form-label">API Key</label>
                                        <input type="password" class="form-control" id="api_key" name="api_key"
                                               placeholder="sk-ant-api03-..." required>
                                        <div class="form-text">
                                            Get your API key from <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-plus-circle"></i> Add Key
                                        </button>
                                        <button type="button" class="btn btn-secondary" data-bs-toggle="collapse" data-bs-target="#addKeyForm">
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Keys List -->
                    <?php if (empty($anthropicKeys)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No API keys configured. Add a key above or boards will use local runner mode.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Key</th>
                                    <th>Model</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($anthropicKeys as $key): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($key['name']) ?></strong></td>
                                    <td><code class="small"><?= htmlspecialchars($key['masked_key']) ?></code></td>
                                    <td>
                                        <?php
                                        $modelLabels = [
                                            'claude-sonnet-4-20250514' => '<span class="badge bg-primary">Sonnet 4</span>',
                                            'claude-haiku-3-5-20241022' => '<span class="badge bg-success">Haiku 3.5</span>',
                                            'claude-opus-4-20250514' => '<span class="badge bg-warning text-dark">Opus 4</span>',
                                        ];
                                        echo $modelLabels[$key['model']] ?? '<span class="badge bg-secondary">' . htmlspecialchars($key['model']) . '</span>';
                                        ?>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-success" onclick="testKey(<?= $key['id'] ?>)">
                                            <i class="bi bi-check-circle"></i> Test
                                        </button>
                                        <a href="/enterprise/deletekey/<?= $key['id'] ?>" class="btn btn-sm btn-outline-danger"
                                           onclick="return confirm('Delete this API key? Boards using it will switch to local runner.')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- GitHub Connection -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="bi bi-github"></i> GitHub Integration</h5>
                </div>
                <div class="card-body">
                    <?php if ($githubConfigured): ?>
                    <p class="text-muted">
                        Connect your GitHub account to enable repository access for AI Developer.
                    </p>
                    <a href="/enterprise/github" class="btn btn-dark">
                        <i class="bi bi-github"></i> Connect GitHub
                    </a>
                    <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> GitHub OAuth is not configured on this server.
                        Contact the administrator to enable GitHub integration.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Security Information</h5>
                    <ul class="list-unstyled">
                        <li class="mb-2">
                            <i class="bi bi-shield-check text-success"></i>
                            API keys are encrypted at rest
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-shield-check text-success"></i>
                            OAuth tokens use industry-standard encryption
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-shield-check text-success"></i>
                            Data is isolated per user
                        </li>
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
                        <a href="/enterprise/repos" class="list-group-item list-group-item-action">
                            <i class="bi bi-folder"></i> Manage Repositories
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
async function testKey(keyId) {
    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    try {
        const response = await fetch('/enterprise/testkey/' + keyId);
        const data = await response.json();

        if (data.success) {
            btn.innerHTML = '<i class="bi bi-check-circle"></i> OK';
            btn.classList.remove('btn-outline-success');
            btn.classList.add('btn-success');
        } else {
            btn.innerHTML = '<i class="bi bi-x-circle"></i> Failed';
            btn.classList.remove('btn-outline-success');
            btn.classList.add('btn-danger');
            alert('Test failed: ' + (data.error || 'Unknown error'));
        }
    } catch (err) {
        btn.innerHTML = '<i class="bi bi-x-circle"></i> Error';
        btn.classList.add('btn-danger');
        alert('Error: ' + err.message);
    }

    setTimeout(() => {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        btn.classList.remove('btn-success', 'btn-danger');
        btn.classList.add('btn-outline-success');
    }, 2000);
}
</script>
