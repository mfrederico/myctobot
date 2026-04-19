<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2 mb-0">
            <i class="bi bi-pc-display-horizontal"></i> Workstations & SSH Keys
        </h1>
        <div>
            <a href="/agents" class="btn btn-outline-info">
                <i class="bi bi-robot"></i> Agent Profiles
            </a>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" id="workstationTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="workstations-tab" data-bs-toggle="tab" data-bs-target="#workstations" type="button" role="tab">
                <i class="bi bi-pc-display-horizontal"></i> Workstations
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="sshkeys-tab" data-bs-toggle="tab" data-bs-target="#sshkeys" type="button" role="tab">
                <i class="bi bi-key"></i> SSH Keys
            </button>
        </li>
    </ul>

    <div class="tab-content" id="workstationTabsContent">
        <!-- Workstations Tab -->
        <div class="tab-pane fade show active" id="workstations" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div></div>
                <div>
                    <button class="btn btn-outline-secondary me-2" onclick="healthCheckAll()">
                        <i class="bi bi-heart-pulse"></i> Health Check All
                    </button>
                    <a href="/workstations/create" class="btn btn-primary">
                        <i class="bi bi-plus-lg"></i> Add Workstation
                    </a>
                </div>
            </div>

            <!-- Explanation Section -->
            <div class="card bg-light border-0 mb-4">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 class="card-title mb-3">
                                <i class="bi bi-question-circle text-primary"></i> What are Workstations?
                            </h5>
                            <p class="mb-2">
                                <strong>Workstations are servers where your AI agents do their work.</strong>
                                When you ask an AI agent to implement a feature or fix a bug, it needs a place to:
                            </p>
                            <ul class="mb-3">
                                <li><strong>Clone your repositories</strong> - Download and work with your code</li>
                                <li><strong>Run Claude Code CLI</strong> - Execute AI-powered coding tasks</li>
                                <li><strong>Create commits and branches</strong> - Make changes and push to GitHub</li>
                                <li><strong>Run tests and builds</strong> - Verify changes work correctly</li>
                            </ul>
                        </div>
                        <div class="col-md-4">
                            <div class="bg-white rounded p-3 border">
                                <h6 class="text-muted mb-3"><i class="bi bi-lightning-charge"></i> Workstation Options</h6>
                                <div class="d-flex align-items-start mb-2">
                                    <i class="bi bi-cloud-check text-success me-2 mt-1"></i>
                                    <div>
                                        <strong>Remote Server</strong>
                                        <small class="d-block text-muted">A VPS or dedicated server you control</small>
                                    </div>
                                </div>
                                <div class="d-flex align-items-start">
                                    <i class="bi bi-pc-display text-primary me-2 mt-1"></i>
                                    <div>
                                        <strong>Local Machine</strong>
                                        <small class="d-block text-muted">Your own computer or office server</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (empty($runners)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-pc-display-horizontal display-4 text-muted"></i>
                    <h4 class="mt-3">No workstations configured yet</h4>
                    <p class="text-muted mb-4" style="max-width: 500px; margin: 0 auto;">
                        To use AI agents with Claude Code CLI, you'll need at least one workstation.
                    </p>
                    <a href="/workstations/create" class="btn btn-primary btn-lg">
                        <i class="bi bi-plus-lg"></i> Add Your First Workstation
                    </a>
                </div>
            </div>
            <?php else: ?>
            <div class="row">
                <?php foreach ($runners as $runner): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span>
                                <i class="bi bi-<?= $runner['health_status'] === 'healthy' ? 'check-circle text-success' : ($runner['health_status'] === 'unhealthy' ? 'x-circle text-danger' : 'question-circle text-muted') ?>"></i>
                                <strong><?= h($runner['name']) ?></strong>
                            </span>
                            <span class="badge <?= $runner['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                <?= $runner['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-2"><?= h($runner['description'] ?: 'No description') ?></p>

                            <table class="table table-sm table-borderless mb-3">
                                <tr>
                                    <td class="text-muted" style="width: 40%">Mode:</td>
                                    <td>
                                        <?php $mode = $runner['execution_mode'] ?? 'http_api'; ?>
                                        <?php if ($mode === 'ssh_tmux'): ?>
                                        <span class="badge bg-primary"><i class="bi bi-terminal"></i> SSH+Tmux</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary"><i class="bi bi-cloud"></i> HTTP API</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Host:</td>
                                    <td>
                                        <?php if ($mode === 'ssh_tmux'): ?>
                                        <code><?= h($runner['ssh_user'] ?? 'claudeuser') ?>@<?= h($runner['host']) ?></code>
                                        <?php else: ?>
                                        <code><?= h($runner['host']) ?>:<?= $runner['port'] ?></code>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Max Jobs:</td>
                                    <td><?= $runner['max_concurrent_jobs'] ?></td>
                                </tr>
                            </table>

                            <!-- Stats -->
                            <div class="row text-center border-top pt-2">
                                <div class="col-3">
                                    <div class="small text-muted">Total</div>
                                    <div class="fw-bold"><?= $runner['stats']['total_jobs'] ?? 0 ?></div>
                                </div>
                                <div class="col-3">
                                    <div class="small text-muted">Running</div>
                                    <div class="fw-bold text-info"><?= $runner['stats']['running_jobs'] ?? 0 ?></div>
                                </div>
                                <div class="col-3">
                                    <div class="small text-muted">Done</div>
                                    <div class="fw-bold text-success"><?= $runner['stats']['completed_jobs'] ?? 0 ?></div>
                                </div>
                                <div class="col-3">
                                    <div class="small text-muted">Failed</div>
                                    <div class="fw-bold text-danger"><?= $runner['stats']['failed_jobs'] ?? 0 ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent">
                            <div class="btn-group w-100">
                                <button class="btn btn-outline-secondary btn-sm" onclick="testWorkstation(<?= $runner['id'] ?>)" title="Test Connection">
                                    <i class="bi bi-plug"></i> Test
                                </button>
                                <a href="/workstations/edit/<?= $runner['id'] ?>" class="btn btn-outline-primary btn-sm" title="Edit">
                                    <i class="bi bi-pencil"></i> Edit
                                </a>
                                <button class="btn btn-outline-danger btn-sm" onclick="deleteWorkstation(<?= $runner['id'] ?>, '<?= h(addslashes($runner['name'])) ?>')" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- SSH Keys Tab -->
        <div class="tab-pane fade" id="sshkeys" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div></div>
                <button class="btn btn-primary" onclick="showGenerateKeyModal()">
                    <i class="bi bi-plus-lg"></i> Generate New Key
                </button>
            </div>

            <!-- Explanation Section -->
            <div class="card bg-light border-0 mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3">
                        <i class="bi bi-key text-primary"></i> SSH Keys for Workstation Authentication
                    </h5>
                    <p class="mb-2">
                        SSH keys allow your workstations to securely connect to external services like GitHub or remote servers.
                    </p>
                    <ol class="mb-0">
                        <li><strong>Generate a key</strong> - Create an ECDSA, ED25519, or RSA key pair</li>
                        <li><strong>Copy the public key</strong> - Add it to GitHub deploy keys or remote server's <code>authorized_keys</code></li>
                        <li><strong>Deploy the private key</strong> - Manually copy it to your workstation's <code>~/.ssh/</code> directory</li>
                        <li><strong>Configure the workstation</strong> - Set the SSH key path in the workstation settings</li>
                    </ol>
                </div>
            </div>

            <div id="sshKeysContainer">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary"></div>
                    <p class="mt-2">Loading SSH keys...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Test Result Modal -->
<div class="modal fade" id="testResultModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Workstation Health Check</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="testResultContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Generate SSH Key Modal -->
<div class="modal fade" id="generateKeyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-key"></i> Generate SSH Key</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="generateKeyForm">
                    <div class="mb-3">
                        <label class="form-label">Key Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required placeholder="e.g., GitHub Deploy Key">
                        <small class="text-muted">A friendly name to identify this key</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control" name="description" placeholder="e.g., For accessing private repos">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Key Type</label>
                        <select class="form-select" name="key_type">
                            <option value="ecdsa" selected>ECDSA (Recommended)</option>
                            <option value="ed25519">ED25519 (Modern)</option>
                            <option value="rsa">RSA 4096 (Maximum Compatibility)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Comment</label>
                        <input type="text" class="form-control" name="comment" placeholder="e.g., deploy@myctobot.ai">
                        <small class="text-muted">Optional comment appended to public key</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="generateKey()">
                    <i class="bi bi-key"></i> Generate Key
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Key Generated Modal (shows keys to copy) -->
<div class="modal fade" id="keyGeneratedModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-check-circle"></i> SSH Key Generated</h5>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>Important:</strong> Save the private key now! It cannot be retrieved later.
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold">Public Key</label>
                    <p class="text-muted small mb-2">Add this to GitHub deploy keys or <code>~/.ssh/authorized_keys</code></p>
                    <div class="input-group">
                        <textarea class="form-control font-monospace" id="generatedPublicKey" rows="3" readonly></textarea>
                        <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('generatedPublicKey')">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Private Key</label>
                    <p class="text-muted small mb-2">Save this to your workstation's <code>~/.ssh/</code> directory (e.g., <code>~/.ssh/id_ecdsa_myctobot</code>)</p>
                    <div class="input-group">
                        <textarea class="form-control font-monospace" id="generatedPrivateKey" rows="6" readonly></textarea>
                        <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('generatedPrivateKey')">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Fingerprint</label>
                    <code id="generatedFingerprint"></code>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal" onclick="loadSSHKeys()">
                    I've Saved the Private Key
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?= Flight::csrf()->getToken() ?>';

// Load SSH keys when tab is shown
document.getElementById('sshkeys-tab').addEventListener('shown.bs.tab', function() {
    loadSSHKeys();
});

async function loadSSHKeys() {
    const container = document.getElementById('sshKeysContainer');

    try {
        const response = await fetch('/workstations/sshkeys');
        const data = await response.json();

        if (!data.success) {
            container.innerHTML = `<div class="alert alert-danger">${data.error || 'Failed to load keys'}</div>`;
            return;
        }

        if (data.keys.length === 0) {
            container.innerHTML = `
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-key display-4 text-muted"></i>
                        <h4 class="mt-3">No SSH keys yet</h4>
                        <p class="text-muted mb-4">Generate an SSH key to enable secure connections to GitHub and remote servers.</p>
                        <button class="btn btn-primary btn-lg" onclick="showGenerateKeyModal()">
                            <i class="bi bi-plus-lg"></i> Generate Your First Key
                        </button>
                    </div>
                </div>
            `;
            return;
        }

        let html = '<div class="table-responsive"><table class="table table-hover">';
        html += `
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Fingerprint</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
        `;

        for (const key of data.keys) {
            html += `
                <tr>
                    <td>
                        <strong>${escapeHtml(key.name)}</strong>
                        ${key.description ? `<br><small class="text-muted">${escapeHtml(key.description)}</small>` : ''}
                    </td>
                    <td><span class="badge bg-info">${key.key_type.toUpperCase()}</span></td>
                    <td><code class="small">${escapeHtml(key.fingerprint)}</code></td>
                    <td>${formatDate(key.created_at)}</td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" onclick="showPublicKey(${key.id})" title="View Public Key">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button class="btn btn-outline-warning" onclick="showPrivateKey(${key.id})" title="View Private Key">
                                <i class="bi bi-key"></i>
                            </button>
                            <button class="btn btn-outline-danger" onclick="deleteKey(${key.id}, '${escapeHtml(key.name)}')" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }

        html += '</tbody></table></div>';
        container.innerHTML = html;

    } catch (err) {
        container.innerHTML = `<div class="alert alert-danger">Error: ${err.message}</div>`;
    }
}

function showGenerateKeyModal() {
    document.getElementById('generateKeyForm').reset();
    new bootstrap.Modal(document.getElementById('generateKeyModal')).show();
}

async function generateKey() {
    const form = document.getElementById('generateKeyForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());

    // Add CSRF token to request body
    data.csrf_token = csrfToken;

    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Generating...';

    try {
        const response = await fetch('/workstations/generatesshkey', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            // Close generate modal
            bootstrap.Modal.getInstance(document.getElementById('generateKeyModal')).hide();

            // Show the generated keys
            document.getElementById('generatedPublicKey').value = result.key.public_key;
            document.getElementById('generatedPrivateKey').value = result.key.private_key;
            document.getElementById('generatedFingerprint').textContent = result.key.fingerprint;

            new bootstrap.Modal(document.getElementById('keyGeneratedModal')).show();
        } else {
            alert('Error: ' + (result.error || 'Failed to generate key'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

async function showPublicKey(keyId) {
    try {
        const response = await fetch(`/workstations/getsshkey/${keyId}`);
        const data = await response.json();

        if (data.success) {
            const key = data.key;
            document.getElementById('generatedPublicKey').value = key.public_key;
            document.getElementById('generatedPrivateKey').value = '(Private key hidden - click "View Private Key" to reveal)';
            document.getElementById('generatedFingerprint').textContent = key.fingerprint;

            document.querySelector('#keyGeneratedModal .modal-header').className = 'modal-header bg-info text-white';
            document.querySelector('#keyGeneratedModal .modal-title').innerHTML = '<i class="bi bi-key"></i> ' + escapeHtml(key.name);
            document.querySelector('#keyGeneratedModal .alert-warning').style.display = 'none';

            new bootstrap.Modal(document.getElementById('keyGeneratedModal')).show();
        } else {
            alert('Error: ' + (data.error || 'Failed to load key'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

async function showPrivateKey(keyId) {
    if (!confirm('Are you sure you want to view the private key?\n\nOnly do this if you need to deploy it to a workstation.')) {
        return;
    }

    try {
        const response = await fetch(`/workstations/getsshkey/${keyId}?include_private=1`);
        const data = await response.json();

        if (data.success) {
            const key = data.key;
            document.getElementById('generatedPublicKey').value = key.public_key;
            document.getElementById('generatedPrivateKey').value = key.private_key;
            document.getElementById('generatedFingerprint').textContent = key.fingerprint;

            document.querySelector('#keyGeneratedModal .modal-header').className = 'modal-header bg-warning';
            document.querySelector('#keyGeneratedModal .modal-title').innerHTML = '<i class="bi bi-key"></i> ' + escapeHtml(key.name) + ' (Private Key)';
            document.querySelector('#keyGeneratedModal .alert-warning').style.display = 'block';

            new bootstrap.Modal(document.getElementById('keyGeneratedModal')).show();
        } else {
            alert('Error: ' + (data.error || 'Failed to load key'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

async function deleteKey(keyId, keyName) {
    if (!confirm(`Are you sure you want to delete the SSH key "${keyName}"?\n\nThis action cannot be undone. Make sure no workstations are using this key.`)) {
        return;
    }

    try {
        const response = await fetch(`/workstations/deletesshkey/${keyId}`, { method: 'DELETE' });
        const data = await response.json();

        if (data.success) {
            loadSSHKeys();
        } else {
            alert('Error: ' + (data.error || 'Failed to delete key'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

function copyToClipboard(elementId) {
    const el = document.getElementById(elementId);
    el.select();
    document.execCommand('copy');

    // Show feedback
    const btn = el.nextElementSibling;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check"></i>';
    btn.classList.remove('btn-outline-secondary');
    btn.classList.add('btn-success');
    setTimeout(() => {
        btn.innerHTML = originalHtml;
        btn.classList.remove('btn-success');
        btn.classList.add('btn-outline-secondary');
    }, 1500);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
}

// Workstation functions
async function testWorkstation(workstationId) {
    const modal = new bootstrap.Modal(document.getElementById('testResultModal'));
    const content = document.getElementById('testResultContent');

    content.innerHTML = '<div class="text-center"><div class="spinner-border text-primary"></div><p class="mt-2">Testing connection...</p></div>';
    modal.show();

    try {
        const response = await fetch('/workstations/test/' + workstationId);
        const data = await response.json();

        if (data.success) {
            const d = data.data || {};
            content.innerHTML = `
                <div class="alert alert-success">
                    <i class="bi bi-check-circle"></i> <strong>Connection Successful!</strong>
                </div>
                <table class="table table-sm">
                    <tr><td>Host:</td><td><code>${d.host || 'N/A'}</code></td></tr>
                    <tr><td>Response Time:</td><td>${d.time_ms || 0}ms</td></tr>
                </table>
            `;
        } else {
            content.innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-x-circle"></i> <strong>Connection Failed</strong>
                    <p class="mb-0 mt-2">${data.error || 'Unknown error'}</p>
                </div>
            `;
        }
    } catch (err) {
        content.innerHTML = `
            <div class="alert alert-danger">
                <i class="bi bi-x-circle"></i> <strong>Error</strong>
                <p class="mb-0 mt-2">${err.message}</p>
            </div>
        `;
    }
}

async function healthCheckAll() {
    const btn = event.target.closest('button');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Checking...';

    try {
        await fetch('/workstations/health');
        location.reload();
    } catch (err) {
        alert('Error: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = original;
    }
}

function deleteWorkstation(workstationId, workstationName) {
    if (!confirm(`Are you sure you want to delete workstation "${workstationName}"?\n\nThis action cannot be undone.`)) {
        return;
    }
    window.location.href = '/workstations/delete/' + workstationId;
}
</script>
