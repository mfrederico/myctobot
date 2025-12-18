<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-<?= isset($shard) ? 'pencil' : 'plus-lg' ?>"></i>
                        <?= isset($shard) ? 'Edit Shard: ' . htmlspecialchars($shard['name']) : 'Add New Shard' ?>
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="/admin/<?= isset($shard) ? 'editshard/' . $shard['id'] : 'createshard' ?>" id="shardForm">

                        <!-- Basic Info -->
                        <div class="mb-4">
                            <h6 class="text-muted border-bottom pb-2">Basic Information</h6>

                            <div class="mb-3">
                                <label for="name" class="form-label">Shard Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name"
                                       value="<?= htmlspecialchars($shard['name'] ?? '') ?>" required
                                       placeholder="e.g., Production Shard 1">
                            </div>

                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="2"
                                          placeholder="Optional description of this shard's purpose"><?= htmlspecialchars($shard['description'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <!-- Connection Settings -->
                        <div class="mb-4">
                            <h6 class="text-muted border-bottom pb-2">Connection Settings</h6>

                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label for="host" class="form-label">Host <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="host" name="host"
                                           value="<?= htmlspecialchars($shard['host'] ?? '') ?>" required
                                           placeholder="e.g., 173.231.12.84 or shard1.example.com">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="port" class="form-label">Port</label>
                                    <input type="number" class="form-control" id="port" name="port"
                                           value="<?= $shard['port'] ?? 3500 ?>" min="1" max="65535">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="api_key" class="form-label">API Key <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="api_key" name="api_key"
                                           value="<?= htmlspecialchars($shard['api_key'] ?? '') ?>"
                                           <?= isset($shard) ? '' : 'required' ?>
                                           placeholder="<?= isset($shard) ? 'Leave blank to keep existing' : 'Shard API key' ?>">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('api_key')">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <?php if (isset($shard)): ?>
                                <div class="form-text">Leave blank to keep the existing API key.</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Shard Configuration -->
                        <div class="mb-4">
                            <h6 class="text-muted border-bottom pb-2">Shard Configuration</h6>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="shard_type" class="form-label">Shard Type</label>
                                    <select class="form-select" id="shard_type" name="shard_type">
                                        <option value="general" <?= ($shard['shard_type'] ?? 'general') === 'general' ? 'selected' : '' ?>>General Purpose</option>
                                        <option value="playwright" <?= ($shard['shard_type'] ?? '') === 'playwright' ? 'selected' : '' ?>>Playwright (Browser Testing)</option>
                                        <option value="database" <?= ($shard['shard_type'] ?? '') === 'database' ? 'selected' : '' ?>>Database Operations</option>
                                        <option value="full" <?= ($shard['shard_type'] ?? '') === 'full' ? 'selected' : '' ?>>Full Featured</option>
                                        <option value="custom" <?= ($shard['shard_type'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="max_concurrent_jobs" class="form-label">Max Concurrent Jobs</label>
                                    <input type="number" class="form-control" id="max_concurrent_jobs" name="max_concurrent_jobs"
                                           value="<?= $shard['max_concurrent_jobs'] ?? 2 ?>" min="1" max="10">
                                    <div class="form-text">How many jobs can run simultaneously on this shard.</div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Capabilities (MCP Servers)</label>
                                <div class="row">
                                    <?php
                                    $capabilities = isset($shard['capabilities'])
                                        ? (is_string($shard['capabilities']) ? json_decode($shard['capabilities'], true) : $shard['capabilities'])
                                        : ['git', 'filesystem'];
                                    $allCapabilities = [
                                        'git' => 'Git Operations',
                                        'filesystem' => 'Filesystem Access',
                                        'github' => 'GitHub API',
                                        'playwright' => 'Playwright Browser',
                                        'postgres' => 'PostgreSQL',
                                        'mysql' => 'MySQL/MariaDB',
                                        'sqlite' => 'SQLite',
                                        'fetch' => 'HTTP Fetch',
                                        'puppeteer' => 'Puppeteer Browser',
                                        'slack' => 'Slack Integration'
                                    ];
                                    foreach ($allCapabilities as $cap => $label):
                                    ?>
                                    <div class="col-md-4 col-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   id="cap_<?= $cap ?>" name="capabilities[]" value="<?= $cap ?>"
                                                   <?= in_array($cap, $capabilities ?? []) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="cap_<?= $cap ?>"><?= $label ?></label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Status Flags -->
                        <div class="mb-4">
                            <h6 class="text-muted border-bottom pb-2">Status</h6>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                                               <?= ($shard['is_active'] ?? 1) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="is_active">Active</label>
                                        <div class="form-text">Enable this shard for job routing.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="is_default" name="is_default" value="1"
                                               <?= ($shard['is_default'] ?? 0) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="is_default">Default Shard</label>
                                        <div class="form-text">Available to all members without specific assignment.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="d-flex justify-content-between">
                            <a href="/admin/shards" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Cancel
                            </a>
                            <div>
                                <?php if (isset($shard)): ?>
                                <button type="button" class="btn btn-outline-info me-2" onclick="testConnection()">
                                    <i class="bi bi-plug"></i> Test Connection
                                </button>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-lg"></i> <?= isset($shard) ? 'Update Shard' : 'Create Shard' ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const icon = event.target.closest('button').querySelector('i');

    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

<?php if (isset($shard)): ?>
async function testConnection() {
    const btn = event.target.closest('button');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';

    try {
        const response = await fetch('/admin/testshard/<?= $shard['id'] ?>');
        const data = await response.json();

        if (data.success) {
            alert('Connection successful!\n\nShard is responding and healthy.');
        } else {
            alert('Connection failed!\n\n' + (data.error || 'Unknown error'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = original;
    }
}
<?php endif; ?>

// Form validation
document.getElementById('shardForm').addEventListener('submit', function(e) {
    const capabilities = document.querySelectorAll('input[name="capabilities[]"]:checked');
    if (capabilities.length === 0) {
        e.preventDefault();
        alert('Please select at least one capability.');
        return false;
    }
});
</script>
