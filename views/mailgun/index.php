<div class="container py-4" data-assist-page="mailgun" data-assist-purpose="Configure Mailgun email service for notifications">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi bi-envelope text-danger me-2"></i>Mailgun Configuration
            </h1>
            <p class="text-muted mb-0">Configure email service for pipelines and notifications</p>
        </div>
        <a href="/settings/connections" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Connections
        </a>
    </div>

    <?php if (!empty($settings['domain'])): ?>
    <div class="alert alert-success d-flex align-items-center mb-4">
        <i class="bi bi-check-circle-fill me-2"></i>
        <div>
            <strong>Connected</strong> - Domain: <?= h($settings['domain']) ?>
        </div>
        <div class="ms-auto">
            <button type="button" class="btn btn-sm btn-outline-success me-2" onclick="testConnection()">
                <i class="bi bi-lightning me-1"></i>Test Connection
            </button>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="sendTestEmail()">
                <i class="bi bi-envelope me-1"></i>Send Test Email
            </button>
        </div>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">API Configuration</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="/mailgun/save">
                        <?= $csrf['field'] ?>

                        <div class="mb-3">
                            <label for="api_key" class="form-label">API Key <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password"
                                       class="form-control"
                                       id="api_key"
                                       name="api_key"
                                       placeholder="<?= !empty($settings['api_key_masked']) ? $settings['api_key_masked'] : 'Enter your Mailgun API key' ?>"
                                       autocomplete="off">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('api_key')">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="form-text">
                                <?php if (!empty($settings['api_key_masked'])): ?>
                                    Current key: <?= h($settings['api_key_masked']) ?>. Leave blank to keep existing key.
                                <?php else: ?>
                                    Find your API key in the <a href="https://app.mailgun.com/app/account/security/api_keys" target="_blank">Mailgun dashboard</a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="domain" class="form-label">Domain <span class="text-danger">*</span></label>
                            <input type="text"
                                   class="form-control"
                                   id="domain"
                                   name="domain"
                                   value="<?= h($settings['domain']) ?>"
                                   placeholder="mg.yourdomain.com"
                                   required>
                            <div class="form-text">Your Mailgun sending domain (e.g., mg.example.com)</div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="from_email" class="form-label">From Email</label>
                                    <input type="email"
                                           class="form-control"
                                           id="from_email"
                                           name="from_email"
                                           value="<?= h($settings['from_email']) ?>"
                                           placeholder="noreply@yourdomain.com">
                                    <div class="form-text">Default sender email address</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="from_name" class="form-label">From Name</label>
                                    <input type="text"
                                           class="form-control"
                                           id="from_name"
                                           name="from_name"
                                           value="<?= h($settings['from_name'] ?: 'MyCTOBot') ?>"
                                           placeholder="MyCTOBot">
                                    <div class="form-text">Default sender name</div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="endpoint" class="form-label">API Endpoint</label>
                            <select class="form-select" id="endpoint" name="endpoint">
                                <option value="https://api.mailgun.net" <?= ($settings['endpoint'] ?? '') === 'https://api.mailgun.net' ? 'selected' : '' ?>>
                                    US Region (api.mailgun.net)
                                </option>
                                <option value="https://api.eu.mailgun.net" <?= ($settings['endpoint'] ?? '') === 'https://api.eu.mailgun.net' ? 'selected' : '' ?>>
                                    EU Region (api.eu.mailgun.net)
                                </option>
                            </select>
                            <div class="form-text">Select based on where your Mailgun domain is hosted</div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-check-lg me-1"></i>Save Configuration
                            </button>
                            <?php if (!empty($settings['domain'])): ?>
                            <a href="/mailgun/disconnect"
                               class="btn btn-outline-danger"
                               onclick="return confirm('Are you sure you want to remove Mailgun configuration?')">
                                <i class="bi bi-x-lg me-1"></i>Disconnect
                            </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-info-circle me-1"></i>About Mailgun</h6>
                </div>
                <div class="card-body">
                    <p class="small text-muted">
                        Mailgun is used by MyCTOBot to send emails for:
                    </p>
                    <ul class="small text-muted mb-0">
                        <li>Pipeline email notifications</li>
                        <li>Daily digest reports</li>
                        <li>Alert notifications</li>
                        <li>Delivery confirmations</li>
                    </ul>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-link-45deg me-1"></i>Pipeline Integration</h6>
                </div>
                <div class="card-body">
                    <p class="small text-muted">
                        Once configured, you can use the <strong>email_out</strong> step type in pipelines:
                    </p>
                    <pre class="bg-light p-2 rounded small mb-0"><code>{
  "type": "email_out",
  "config": {
    "to": "team@example.com",
    "subject": "Pipeline Alert",
    "template": "notification"
  }
}</code></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const btn = field.nextElementSibling;
    if (field.type === 'password') {
        field.type = 'text';
        btn.innerHTML = '<i class="bi bi-eye-slash"></i>';
    } else {
        field.type = 'password';
        btn.innerHTML = '<i class="bi bi-eye"></i>';
    }
}

function testConnection() {
    const btn = event.target.closest('button');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testing...';

    fetch('/mailgun/test', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Success';
            btn.classList.remove('btn-outline-success');
            btn.classList.add('btn-success');
            alert(data.message || 'Connection successful!');
        } else {
            btn.innerHTML = '<i class="bi bi-x-circle me-1"></i>Failed';
            btn.classList.remove('btn-outline-success');
            btn.classList.add('btn-danger');
            alert(data.message || 'Connection failed');
        }
    })
    .catch(err => {
        btn.innerHTML = '<i class="bi bi-x-circle me-1"></i>Error';
        btn.classList.add('btn-danger');
        alert('Connection test failed');
    })
    .finally(() => {
        setTimeout(() => {
            btn.innerHTML = originalText;
            btn.disabled = false;
            btn.classList.remove('btn-success', 'btn-danger');
            btn.classList.add('btn-outline-success');
        }, 3000);
    });
}

function sendTestEmail() {
    const email = prompt('Send test email to:', '<?= h($member->email ?? '') ?>');
    if (!email) return;

    const btn = event.target.closest('button');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending...';

    fetch('/mailgun/sendtest?to=' + encodeURIComponent(email), {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Sent';
            btn.classList.remove('btn-outline-primary');
            btn.classList.add('btn-success');
            alert(data.message || 'Test email sent!');
        } else {
            btn.innerHTML = '<i class="bi bi-x-circle me-1"></i>Failed';
            btn.classList.remove('btn-outline-primary');
            btn.classList.add('btn-danger');
            alert(data.message || 'Failed to send test email');
        }
    })
    .catch(err => {
        btn.innerHTML = '<i class="bi bi-x-circle me-1"></i>Error';
        btn.classList.add('btn-danger');
        alert('Failed to send test email');
    })
    .finally(() => {
        setTimeout(() => {
            btn.innerHTML = originalText;
            btn.disabled = false;
            btn.classList.remove('btn-success', 'btn-danger');
            btn.classList.add('btn-outline-primary');
        }, 3000);
    });
}
</script>
