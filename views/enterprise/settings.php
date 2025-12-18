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
            <!-- API Key Configuration -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="bi bi-key"></i> Anthropic API Key</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        The AI Developer uses <strong>Claude</strong> (via Anthropic's API) to analyze tickets, understand your codebase,
                        and generate code. Enter your Anthropic API key below to enable these features.
                        Your key is stored securely using encryption.
                    </p>

                    <?php if ($apiKeySet): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle"></i> API key is configured.
                        <button type="button" class="btn btn-sm btn-outline-success ms-2" id="test-key-btn">
                            Test Key
                        </button>
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="/enterprise/settings">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="anthropic_api_key" class="form-label">
                                API Key <?= $apiKeySet ? '(enter new key to update)' : '' ?>
                            </label>
                            <input type="password" class="form-control" id="anthropic_api_key" name="anthropic_api_key"
                                   placeholder="sk-ant-api03-...">
                            <div class="form-text">
                                Get your API key from <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Save API Key
                        </button>
                    </form>
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
document.addEventListener('DOMContentLoaded', function() {
    const testBtn = document.getElementById('test-key-btn');
    if (testBtn) {
        testBtn.addEventListener('click', async function() {
            testBtn.disabled = true;
            testBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';

            try {
                const response = await fetch('/enterprise/testkey');
                const data = await response.json();

                if (data.success) {
                    alert('API key is valid!');
                } else {
                    alert('Test failed: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                testBtn.disabled = false;
                testBtn.innerHTML = 'Test Key';
            }
        });
    }
});
</script>
