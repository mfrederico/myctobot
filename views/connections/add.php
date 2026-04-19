<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi <?= h($meta['icon'] ?? 'bi-plug') ?> text-<?= h($meta['color'] ?? 'secondary') ?> me-2"></i>
                Add <?= h($meta['name'] ?? 'Connection') ?>
            </h1>
            <p class="text-muted mb-0"><?= h($meta['description'] ?? '') ?></p>
        </div>
        <a href="/settings/connections" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>

    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="/connections/add/<?= h($type) ?>">
                        <input type="hidden" name="csrf_token" value="<?= $csrf['csrf_token'] ?? '' ?>">

                        <div class="mb-3">
                            <label for="name" class="form-label">Connection Name</label>
                            <input type="text" class="form-control" id="name" name="name"
                                   value="<?= h($meta['name'] ?? '') ?>"
                                   placeholder="My <?= h($meta['name'] ?? 'Connection') ?>">
                            <div class="form-text">A friendly name to identify this connection.</div>
                        </div>

                        <?php if ($type === 'anthropic'): ?>
                        <div class="mb-3">
                            <label for="api_key" class="form-label">API Key <span class="text-danger">*</span></label>
                            <input type="password" class="form-control font-monospace" id="api_key" name="api_key"
                                   placeholder="sk-ant-api03-..." required>
                            <div class="form-text">Your Anthropic API key. It will be encrypted before storage.</div>
                        </div>
                        <div class="mb-3">
                            <label for="model" class="form-label">Preferred Model</label>
                            <select class="form-select" id="model" name="model">
                                <option value="claude-sonnet-4-5-20250929">Claude Sonnet 4.5</option>
                                <option value="claude-opus-4-6">Claude Opus 4.6</option>
                                <option value="claude-haiku-4-5-20251001">Claude Haiku 4.5</option>
                            </select>
                        </div>

                        <?php elseif ($type === 'mailgun'): ?>
                        <div class="mb-3">
                            <label for="api_key" class="form-label">API Key <span class="text-danger">*</span></label>
                            <input type="password" class="form-control font-monospace" id="api_key" name="api_key"
                                   placeholder="key-..." required>
                            <div class="form-text">Your Mailgun API key. It will be encrypted before storage.</div>
                        </div>
                        <div class="mb-3">
                            <label for="domain" class="form-label">Domain <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="domain" name="domain"
                                   placeholder="mg.example.com" required>
                        </div>
                        <div class="mb-3">
                            <label for="from_email" class="form-label">From Email</label>
                            <input type="email" class="form-control" id="from_email" name="from_email"
                                   placeholder="noreply@example.com">
                            <div class="form-text">Default sender email address.</div>
                        </div>

                        <?php else: ?>
                        <!-- Generic API key/token field -->
                        <div class="mb-3">
                            <label for="api_key" class="form-label">API Key / Token <span class="text-danger">*</span></label>
                            <input type="password" class="form-control font-monospace" id="api_key" name="api_key"
                                   placeholder="Enter your API key or token" required>
                            <div class="form-text">Your credentials will be encrypted before storage.</div>
                        </div>
                        <?php endif; ?>

                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="shared" name="shared" value="1" checked>
                            <label class="form-check-label" for="shared">
                                Share with workspace members
                            </label>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-<?= h($meta['color'] ?? 'primary') ?>">
                                <i class="bi bi-plus-circle"></i> Add Connection
                            </button>
                            <a href="/settings/connections" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
