<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi bi-key"></i> API Keys
            </h1>
            <p class="text-muted mb-0">Manage programmatic access to the system</p>
        </div>
        <div>
            <a href="/admin" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left"></i> Back to Admin
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createKeyModal">
                <i class="bi bi-plus-lg"></i> Create API Key
            </button>
        </div>
    </div>

    <!-- Info Alert -->
    <div class="alert alert-info mb-4">
        <i class="bi bi-info-circle"></i>
        <strong>API Keys</strong> allow programmatic access to MyCTOBot. Each key runs as a specific member
        with their permissions. Use keys for webhooks, pipelines, and external integrations.
    </div>

    <?php if (empty($keys)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-key display-4 text-muted"></i>
            <h4 class="mt-3">No API keys yet</h4>
            <p class="text-muted mb-4">Create your first API key to enable programmatic access.</p>
            <button type="button" class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#createKeyModal">
                <i class="bi bi-plus-lg"></i> Create Your First Key
            </button>
        </div>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Your API Keys</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Token</th>
                        <th>Runs As</th>
                        <th>Scopes</th>
                        <th>Expires</th>
                        <th>Last Used</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($keys as $key): ?>
                    <tr class="<?= (!$key['is_active'] || $key['is_expired']) ? 'table-secondary' : '' ?>">
                        <td>
                            <strong><?= htmlspecialchars($key['name']) ?></strong>
                            <?php if ($key['description']): ?>
                            <br><small class="text-muted"><?= htmlspecialchars($key['description']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <code class="text-muted"><?= htmlspecialchars($key['token_preview']) ?></code>
                            <button class="btn btn-sm btn-link p-0 ms-1" onclick="regenerateToken(<?= $key['id'] ?>, '<?= htmlspecialchars($key['name'], ENT_QUOTES) ?>')" title="Regenerate token">
                                <i class="bi bi-arrow-clockwise"></i>
                            </button>
                        </td>
                        <td>
                            <span title="<?= htmlspecialchars($key['member_email'] ?? '') ?>">
                                <?= htmlspecialchars($key['member_username'] ?? '') ?>
                            </span>
                            <?php if ($isAdmin && $key['created_by_member_id'] != $key['member_id']): ?>
                            <br><small class="text-muted">by <?= htmlspecialchars($key['created_by_username'] ?? '') ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (empty($key['scopes'])): ?>
                            <span class="badge bg-warning">No scopes</span>
                            <?php elseif (in_array('*:*', $key['scopes']) || in_array('*', $key['scopes'])): ?>
                            <span class="badge bg-danger">Full Access</span>
                            <?php else: ?>
                            <?php
                            $displayScopes = array_slice($key['scopes'], 0, 3);
                            foreach ($displayScopes as $scope): ?>
                            <span class="badge bg-info"><?= htmlspecialchars($scope) ?></span>
                            <?php endforeach;
                            if (count($key['scopes']) > 3): ?>
                            <span class="badge bg-secondary">+<?= count($key['scopes']) - 3 ?> more</span>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$key['expires_at']): ?>
                            <span class="text-muted">Never</span>
                            <?php elseif ($key['is_expired']): ?>
                            <span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Expired</span>
                            <?php else: ?>
                            <?= date('M j, Y', strtotime($key['expires_at'])) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($key['last_used_at']): ?>
                            <span title="<?= htmlspecialchars($key['last_used_at']) ?>">
                                <?= date('M j', strtotime($key['last_used_at'])) ?>
                            </span>
                            <br><small class="text-muted"><?= number_format($key['usage_count']) ?> uses</small>
                            <?php else: ?>
                            <span class="text-muted">Never</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($key['is_expired']): ?>
                            <span class="badge bg-danger">Expired</span>
                            <?php elseif ($key['is_active']): ?>
                            <span class="badge bg-success">Active</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">Disabled</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-success" onclick="showUseKeyModal(<?= $key['id'] ?>, '<?= htmlspecialchars($key['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($key['token'], ENT_QUOTES) ?>')" title="Show usage">
                                    <i class="bi bi-plug"></i>
                                </button>
                                <button type="button" class="btn btn-outline-primary" onclick="editKey(<?= $key['id'] ?>)" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-outline-<?= $key['is_active'] ? 'warning' : 'success' ?>" onclick="toggleKey(<?= $key['id'] ?>)" title="<?= $key['is_active'] ? 'Disable' : 'Enable' ?>">
                                    <i class="bi bi-<?= $key['is_active'] ? 'pause' : 'play' ?>"></i>
                                </button>
                                <button type="button" class="btn btn-outline-danger" onclick="deleteKey(<?= $key['id'] ?>, '<?= htmlspecialchars($key['name'], ENT_QUOTES) ?>')" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Usage Instructions -->
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-terminal"></i> Using API Keys</h5>
        </div>
        <div class="card-body">
            <p>API keys can be passed in three ways:</p>
            <div class="row">
                <div class="col-md-4">
                    <h6>Header (Recommended)</h6>
                    <pre class="bg-dark text-light p-2 rounded small"><code>X-API-TOKEN: tk_xxx...</code></pre>
                </div>
                <div class="col-md-4">
                    <h6>Bearer Token</h6>
                    <pre class="bg-dark text-light p-2 rounded small"><code>Authorization: Bearer tk_xxx...</code></pre>
                </div>
                <div class="col-md-4">
                    <h6>Query Parameter</h6>
                    <pre class="bg-dark text-light p-2 rounded small"><code>?key=tk_xxx...</code></pre>
                </div>
            </div>
            <div class="alert alert-warning mt-3 mb-0">
                <i class="bi bi-shield-lock"></i> <strong>Security:</strong> Store API keys securely. Never commit them to version control or share them publicly.
            </div>
        </div>
    </div>
</div>

<!-- Create Key Modal -->
<div class="modal fade" id="createKeyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-lg"></i> Create API Key</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="createKeyForm">
                    <div class="mb-3">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="keyName" name="name" required minlength="2" maxlength="100" placeholder="e.g., Pipeline Webhook Key">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control" id="keyDescription" name="description" placeholder="Optional description">
                    </div>

                    <?php if ($isAdmin): ?>
                    <div class="mb-3">
                        <label class="form-label">Runs As Member</label>
                        <select class="form-select" id="keyMemberId" name="member_id">
                            <?php foreach ($members as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= $m['id'] == $member->id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m['username'] ?? '') ?> (<?= htmlspecialchars($m['email'] ?? '') ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">The key will execute with this member's permissions.</div>
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Scopes</label>
                        <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                            <?php foreach ($availableScopes as $scope => $desc): ?>
                            <div class="form-check">
                                <input class="form-check-input scope-checkbox" type="checkbox" value="<?= htmlspecialchars($scope) ?>" id="scope_<?= md5($scope) ?>">
                                <label class="form-check-label" for="scope_<?= md5($scope) ?>">
                                    <code><?= htmlspecialchars($scope) ?></code>
                                    <small class="text-muted d-block"><?= htmlspecialchars($desc) ?></small>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">Select the permissions this key should have.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Expires</label>
                        <select class="form-select" id="keyExpiresIn" name="expires_in">
                            <option value="never">Never</option>
                            <option value="7d">7 days</option>
                            <option value="30d">30 days</option>
                            <option value="90d">90 days</option>
                            <option value="1y">1 year</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="createKey()">
                    <i class="bi bi-plus-lg"></i> Create Key
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Token Display Modal -->
<div class="modal fade" id="tokenModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-check-circle"></i> API Key Created</h5>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i> <strong>Copy this token now!</strong> It will not be shown again.
                </div>
                <div class="input-group mb-3">
                    <input type="text" class="form-control font-monospace" id="newToken" readonly>
                    <button class="btn btn-outline-secondary" type="button" onclick="copyToken()">
                        <i class="bi bi-clipboard"></i> Copy
                    </button>
                </div>
                <p class="small text-muted mb-0">Store this token securely. You will need it to authenticate API requests.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal" onclick="location.reload()">Done</button>
            </div>
        </div>
    </div>
</div>

<!-- Use Key Modal -->
<div class="modal fade" id="useKeyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-plug"></i> Using API Key: <span id="useKeyName"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Use this key to authenticate API requests:</p>

                <h6><i class="bi bi-terminal"></i> cURL Example</h6>
                <pre class="bg-dark text-light p-3 rounded small" id="curlExample"></pre>

                <h6 class="mt-4"><i class="bi bi-braces"></i> Webhook URL for Pipelines</h6>
                <div class="input-group mb-3">
                    <input type="text" class="form-control font-monospace small" id="webhookUrl" readonly>
                    <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('webhookUrl')">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>

                <div class="alert alert-info mb-0">
                    <i class="bi bi-info-circle"></i> Replace <code>{pipeline-slug}</code> with your pipeline's slug.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Regenerate Confirm Modal -->
<div class="modal fade" id="regenerateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Regenerate Token</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Regenerate token for <strong id="regenerateKeyName"></strong>?</p>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i> The old token will stop working immediately.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" id="confirmRegenerateBtn">Regenerate</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Key Modal -->
<div class="modal fade" id="editKeyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit API Key</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editKeyForm">
                    <input type="hidden" id="editKeyId">
                    <div class="mb-3">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="editKeyName" name="name" required minlength="2" maxlength="100">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control" id="editKeyDescription" name="description">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Scopes</label>
                        <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                            <?php foreach ($availableScopes as $scope => $desc): ?>
                            <div class="form-check">
                                <input class="form-check-input edit-scope-checkbox" type="checkbox" value="<?= htmlspecialchars($scope) ?>" id="edit_scope_<?= md5($scope) ?>">
                                <label class="form-check-label" for="edit_scope_<?= md5($scope) ?>">
                                    <code><?= htmlspecialchars($scope) ?></code>
                                    <small class="text-muted d-block"><?= htmlspecialchars($desc) ?></small>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">Select the permissions this key should have.</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveKey()">
                    <i class="bi bi-check-lg"></i> Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?= Flight::csrf()->getToken() ?>';
const baseUrl = '<?= rtrim(Flight::get('app.baseurl') ?: '', '/') ?>';
const workspaceSlug = '<?= htmlspecialchars($_SESSION['workspace_slug'] ?? 'default') ?>';

let currentKeyId = null;
let createModal, tokenModal, useModal, regenerateModal, editModal;

// Store key data for editing
const keyData = <?= json_encode(array_column($keys ?? [], null, 'id')) ?>;

document.addEventListener('DOMContentLoaded', function() {
    createModal = new bootstrap.Modal(document.getElementById('createKeyModal'));
    tokenModal = new bootstrap.Modal(document.getElementById('tokenModal'));
    useModal = new bootstrap.Modal(document.getElementById('useKeyModal'));
    regenerateModal = new bootstrap.Modal(document.getElementById('regenerateModal'));
    editModal = new bootstrap.Modal(document.getElementById('editKeyModal'));
});

async function createKey() {
    const name = document.getElementById('keyName').value.trim();
    if (!name) {
        alert('Name is required');
        return;
    }

    const scopes = [];
    document.querySelectorAll('.scope-checkbox:checked').forEach(cb => {
        scopes.push(cb.value);
    });

    const data = {
        name: name,
        description: document.getElementById('keyDescription').value.trim(),
        scopes: scopes,
        expires_in: document.getElementById('keyExpiresIn').value
    };

    <?php if ($isAdmin): ?>
    data.member_id = document.getElementById('keyMemberId').value;
    <?php endif; ?>

    try {
        const response = await fetch('/apikeys/store', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({json: JSON.stringify(data), csrf_token: csrfToken})
        });

        const result = await response.json();

        if (result.success) {
            createModal.hide();
            document.getElementById('newToken').value = result.data.token;
            tokenModal.show();
        } else {
            alert('Error: ' + (result.error || result.message || 'Failed to create key'));
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

function copyToken() {
    const input = document.getElementById('newToken');
    input.select();
    navigator.clipboard.writeText(input.value);

    const btn = input.nextElementSibling;
    btn.innerHTML = '<i class="bi bi-check"></i> Copied!';
    btn.classList.remove('btn-outline-secondary');
    btn.classList.add('btn-success');
}

function copyToClipboard(elementId) {
    const input = document.getElementById(elementId);
    navigator.clipboard.writeText(input.value);

    const btn = input.nextElementSibling;
    const original = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check"></i>';
    setTimeout(() => btn.innerHTML = original, 1500);
}

function showUseKeyModal(keyId, keyName, token) {
    document.getElementById('useKeyName').textContent = keyName;

    document.getElementById('curlExample').textContent =
        `curl -X POST "${baseUrl}/pipein/${workspaceSlug}/{pipeline-slug}" \\\n  -H "X-API-TOKEN: ${token}"`;

    document.getElementById('webhookUrl').value =
        `${baseUrl}/pipein/${workspaceSlug}/{pipeline-slug}?key=${token}`;

    useModal.show();
}

function regenerateToken(keyId, keyName) {
    currentKeyId = keyId;
    document.getElementById('regenerateKeyName').textContent = keyName;
    regenerateModal.show();
}

document.getElementById('confirmRegenerateBtn').addEventListener('click', async function() {
    if (!currentKeyId) return;

    try {
        const response = await fetch('/apikeys/regenerate/' + currentKeyId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'csrf_token=' + encodeURIComponent(csrfToken)
        });

        const result = await response.json();

        if (result.success) {
            regenerateModal.hide();
            document.getElementById('newToken').value = result.data.token;
            tokenModal.show();
        } else {
            alert('Error: ' + (result.error || 'Failed to regenerate'));
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
});

async function toggleKey(keyId) {
    try {
        const response = await fetch('/apikeys/toggle/' + keyId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'csrf_token=' + encodeURIComponent(csrfToken)
        });

        const result = await response.json();

        if (result.success) {
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Failed to toggle'));
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function deleteKey(keyId, keyName) {
    if (!confirm(`Delete API key "${keyName}"? This cannot be undone.`)) {
        return;
    }

    try {
        const response = await fetch('/apikeys/delete/' + keyId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'csrf_token=' + encodeURIComponent(csrfToken)
        });

        const result = await response.json();

        if (result.success) {
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Failed to delete'));
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

function editKey(keyId) {
    const key = keyData[keyId];
    if (!key) {
        alert('Key not found');
        return;
    }

    currentKeyId = keyId;
    document.getElementById('editKeyId').value = keyId;
    document.getElementById('editKeyName').value = key.name;
    document.getElementById('editKeyDescription').value = key.description || '';

    // Uncheck all scopes first
    document.querySelectorAll('.edit-scope-checkbox').forEach(cb => cb.checked = false);

    // Check the scopes this key has
    if (key.scopes && Array.isArray(key.scopes)) {
        key.scopes.forEach(scope => {
            const cb = document.querySelector(`.edit-scope-checkbox[value="${scope}"]`);
            if (cb) cb.checked = true;
        });
    }

    editModal.show();
}

async function saveKey() {
    const keyId = document.getElementById('editKeyId').value;
    const name = document.getElementById('editKeyName').value.trim();
    if (!name) {
        alert('Name is required');
        return;
    }

    const scopes = [];
    document.querySelectorAll('.edit-scope-checkbox:checked').forEach(cb => {
        scopes.push(cb.value);
    });

    const data = {
        name: name,
        description: document.getElementById('editKeyDescription').value.trim(),
        scopes: scopes
    };

    try {
        const response = await fetch('/apikeys/update/' + keyId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({json: JSON.stringify(data), csrf_token: csrfToken})
        });

        const result = await response.json();

        if (result.success) {
            editModal.hide();
            location.reload();
        } else {
            alert('Error: ' + (result.error || result.message || 'Failed to update key'));
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}
</script>
