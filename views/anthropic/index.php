<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1"><i class="bi bi-robot text-warning me-2"></i>Anthropic API</h1>
            <p class="text-muted mb-0">Configure your Claude API key for AI-powered features</p>
        </div>
        <a href="/settings/connections" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Connections
        </a>
    </div>

    <?php if (!empty($creditBalanceError)): ?>
    <div class="alert alert-warning mb-4">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <strong>Credit Balance Warning:</strong> <?= htmlspecialchars($creditBalanceError) ?>
        <p class="mb-0 mt-2 small">Add credits at <a href="https://console.anthropic.com/settings/billing" target="_blank">console.anthropic.com</a></p>
    </div>
    <?php endif; ?>

    <?php if ($apiKeySet): ?>
    <!-- API Key Configured -->
    <div class="card border-success mb-4">
        <div class="card-header bg-success text-white">
            <i class="bi bi-check-circle-fill me-2"></i>API Key Configured
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-2">
                        <i class="bi bi-key me-1"></i>
                        Your Anthropic API key is securely stored and encrypted.
                    </p>
                    <p class="text-muted mb-0">
                        The AI Developer uses Claude to analyze Jira tickets and generate code implementations.
                    </p>
                </div>
                <div class="col-md-6 text-md-end">
                    <button type="button" class="btn btn-outline-success me-2" id="test-key-btn">
                        <i class="bi bi-check2-circle"></i> Test Key
                    </button>
                    <a href="https://console.anthropic.com/" target="_blank" class="btn btn-outline-primary">
                        <i class="bi bi-box-arrow-up-right"></i> Console
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Key Form -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-arrow-repeat"></i> Update API Key
        </div>
        <div class="card-body">
            <form method="POST" action="/anthropic">
                <?php if (!empty($csrf) && is_array($csrf)): ?>
                    <?php foreach ($csrf as $name => $value): ?>
                        <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="mb-3">
                    <label for="anthropic_api_key" class="form-label">New API Key</label>
                    <input type="password" class="form-control" id="anthropic_api_key" name="anthropic_api_key"
                           placeholder="sk-ant-api03-...">
                    <div class="form-text">Enter a new API key to replace the current one</div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Update Key
                </button>
            </form>
        </div>
    </div>

    <!-- Danger Zone -->
    <div class="card border-danger">
        <div class="card-header bg-danger text-white">
            <i class="bi bi-exclamation-triangle"></i> Danger Zone
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">
                Remove your API key from MyCTOBot. AI Developer features will stop working until a new key is configured.
            </p>
            <a href="/anthropic/remove" class="btn btn-outline-danger"
               onclick="return confirm('Remove your Anthropic API key? AI features will stop working.')">
                <i class="bi bi-trash"></i> Remove API Key
            </a>
        </div>
    </div>

    <?php else: ?>
    <!-- Not Configured - Setup Form -->
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <i class="bi bi-key"></i> Configure API Key
                </div>
                <div class="card-body">
                    <p class="text-muted mb-4">
                        The AI Developer uses Claude (via Anthropic's API) to analyze tickets, understand your codebase,
                        and generate code implementations. Enter your API key below to enable these features.
                    </p>

                    <form method="POST" action="/anthropic">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="anthropic_api_key" class="form-label">API Key <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="anthropic_api_key" name="anthropic_api_key"
                                   placeholder="sk-ant-api03-..." required>
                        </div>

                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-save"></i> Save API Key
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-info-circle"></i> Setup Instructions
                </div>
                <div class="card-body">
                    <ol class="mb-0">
                        <li class="mb-2">
                            Go to <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>
                        </li>
                        <li class="mb-2">
                            Sign in or create an account
                        </li>
                        <li class="mb-2">
                            Navigate to <strong>API Keys</strong>
                        </li>
                        <li class="mb-2">
                            Click <strong>Create Key</strong>
                        </li>
                        <li class="mb-2">
                            Copy your new API key (starts with <code>sk-ant-</code>)
                        </li>
                        <li>
                            Paste it above and save
                        </li>
                    </ol>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">
                    <i class="bi bi-shield-check"></i> Security
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li class="mb-2">
                            <i class="bi bi-lock text-success me-2"></i>
                            Encrypted at rest
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-person-lock text-success me-2"></i>
                            Isolated per user
                        </li>
                        <li>
                            <i class="bi bi-eye-slash text-success me-2"></i>
                            Never visible after saving
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const testBtn = document.getElementById('test-key-btn');
    if (testBtn) {
        testBtn.addEventListener('click', async function() {
            testBtn.disabled = true;
            testBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';

            try {
                const response = await fetch('/anthropic/test');
                const data = await response.json();

                if (data.success) {
                    alert('API key is valid and working!');
                } else {
                    alert('Test failed: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                testBtn.disabled = false;
                testBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Test Key';
            }
        });
    }
});
</script>
