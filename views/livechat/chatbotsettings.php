<?php
/**
 * Chatbot Settings - Individual chatbot configuration
 *
 * Tabbed interface: General, Widget, Triggers, Knowledge
 *
 * Variables: $member, $chatbot (array from toArray), $connections, $pipelines, $knowledgebases
 */
$bot = $chatbot;
$config = $bot['config'] ?? [];
$isAdvisor = $bot['mode'] === 'advisor';
$workspace = $_SESSION['workspace_slug'] ?? 'default';
$domain = $workspace . '.myctobot.ai';
?>
<div class="container-fluid py-4" data-assist-page="livechat/chatbotsettings" data-assist-purpose="Configure a chatbot instance">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="/livechat/chatbots" class="text-muted text-decoration-none small">
                <i class="bi bi-arrow-left me-1"></i>All Chatbots
            </a>
            <h4 class="mb-0 mt-1">
                <i class="bi bi-robot me-2"></i><?= h($bot['name']) ?>
                <span class="badge bg-<?= $isAdvisor ? 'success' : 'primary' ?> bg-opacity-10 text-<?= $isAdvisor ? 'success' : 'primary' ?> ms-2 fs-6">
                    <?= $isAdvisor ? 'Advisor' : 'Builder' ?>
                </span>
                <span class="badge bg-<?= $bot['status'] === 'active' ? 'success' : 'warning' ?> bg-opacity-10 text-<?= $bot['status'] === 'active' ? 'success' : 'warning' ?> ms-1 fs-6">
                    <?= h($bot['status']) ?>
                </span>
            </h4>
        </div>
        <button class="btn btn-sm btn-outline-danger" onclick="archiveChatbot()">
            <i class="bi bi-archive me-1"></i>Archive
        </button>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-general" type="button">
                <i class="bi bi-sliders me-1"></i>General
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-widget" type="button">
                <i class="bi bi-code-slash me-1"></i>Widget
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-triggers" type="button">
                <i class="bi bi-lightning me-1"></i>Triggers
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-knowledge" type="button">
                <i class="bi bi-book me-1"></i>Knowledge
            </button>
        </li>
    </ul>

    <div class="tab-content">

        <!-- ═══ General Tab ═══ -->
        <div class="tab-pane fade show active" id="tab-general">
            <div class="row">
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title mb-3">General Settings</h5>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Name</label>
                                <input type="text" class="form-control" id="cfg-name" value="<?= h($bot['name']) ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Mode</label>
                                <select class="form-select" id="cfg-mode">
                                    <option value="advisor" <?= $isAdvisor ? 'selected' : '' ?>>Advisor - External customer support</option>
                                    <option value="builder" <?= !$isAdvisor ? 'selected' : '' ?>>Builder - Internal MyCTOBot assistant</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Status</label>
                                <select class="form-select" id="cfg-status">
                                    <option value="active" <?= $bot['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= $bot['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="mb-3" id="connection-group">
                                <label class="form-label fw-semibold">Connection</label>
                                <select class="form-select" id="cfg-connection">
                                    <option value="">-- None --</option>
                                    <?php foreach ($connections as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= ($bot['connections_id'] == $c['id']) ? 'selected' : '' ?>>
                                        <?= h($c['name']) ?> (<?= h($c['type']) ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Which data source this chatbot connects to</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Main Chat Pipeline</label>
                                <select class="form-select" id="cfg-pipeline">
                                    <option value="">-- None --</option>
                                    <?php foreach ($pipelines as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= ($bot['pipelines_id'] == $p['id']) ? 'selected' : '' ?>>
                                        <?= h($p['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">The pipeline that powers this chatbot's AI responses</small>
                            </div>

                            <button class="btn btn-primary" id="btn-save-general">
                                <i class="bi bi-check-lg me-1"></i>Save
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h6 class="text-muted mb-3">Info</h6>
                            <div class="small">
                                <div class="mb-2"><strong>Slug:</strong> <code><?= h($bot['slug']) ?></code></div>
                                <div class="mb-2"><strong>Created:</strong> <?= h($bot['created_at']) ?></div>
                                <div class="mb-2"><strong>Updated:</strong> <?= h($bot['updated_at']) ?></div>
                                <?php if ($bot['connection']): ?>
                                <div class="mb-2"><strong>Connection:</strong> <?= h($bot['connection']['name']) ?></div>
                                <?php endif; ?>
                                <?php if ($bot['pipeline']): ?>
                                <div class="mb-2"><strong>Pipeline:</strong> <?= h($bot['pipeline']['name']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Widget Tab ═══ -->
        <div class="tab-pane fade" id="tab-widget">
            <div class="row">
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <h5 class="card-title mb-3">Widget Token</h5>
                            <div class="input-group mb-3">
                                <input type="text" class="form-control font-monospace" id="widget-token"
                                    value="<?= h($bot['widget_token'] ?? '') ?>" readonly>
                                <button class="btn btn-outline-secondary" onclick="copyToClipboard('widget-token')" title="Copy">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                                <button class="btn btn-outline-warning" id="btn-regen-token" title="Regenerate">
                                    <i class="bi bi-arrow-repeat"></i>
                                </button>
                            </div>
                            <small class="text-muted">This token identifies your chatbot when embedded on external sites.</small>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <h5 class="card-title mb-3">Embed Code</h5>
                            <p class="text-muted small">Copy this code into your website to embed the chatbot.</p>

                            <label class="form-label small fw-semibold">iFrame Embed</label>
                            <div class="input-group mb-3">
                                <textarea class="form-control font-monospace small" id="embed-iframe" rows="3" readonly><?= h('<iframe src="https://' . $domain . '/customersupport?token=' . ($bot['widget_token'] ?? '') . '&embed=1" style="position:fixed;bottom:20px;right:20px;width:400px;height:600px;border:none;z-index:9999;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,0.15);" allow="clipboard-write"></iframe>') ?></textarea>
                                <button class="btn btn-outline-secondary" onclick="copyToClipboard('embed-iframe')" title="Copy">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title mb-3">Chat Settings</h5>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Welcome Message</label>
                                <textarea class="form-control" id="cfg-welcome" rows="2"><?= h($config['welcome_message'] ?? 'Hi! How can I help you today?') ?></textarea>
                            </div>

                            <div class="mb-3 form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="cfg-require-verification"
                                    <?= ($config['require_order_verification'] ?? true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="cfg-require-verification">
                                    Require order verification before chat
                                </label>
                                <small class="d-block text-muted">When enabled, customers must enter an order number and email before chatting.</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Session Expiry (seconds)</label>
                                <input type="number" class="form-control" id="cfg-expiry"
                                    value="<?= (int)($config['session_expiry_seconds'] ?? 3600) ?>">
                            </div>

                            <button class="btn btn-primary" id="btn-save-widget">
                                <i class="bi bi-check-lg me-1"></i>Save Widget Settings
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Triggers Tab ═══ -->
        <div class="tab-pane fade" id="tab-triggers">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title mb-0">Pipeline Triggers</h5>
                        <button class="btn btn-sm btn-primary" id="btn-add-trigger">
                            <i class="bi bi-plus-lg me-1"></i>Add Trigger
                        </button>
                    </div>
                    <p class="text-muted small mb-3">
                        When a customer message matches a keyword, the specified action fires. First match wins.
                    </p>

                    <div id="triggers-container"></div>

                    <button class="btn btn-primary mt-3" id="btn-save-triggers">
                        <i class="bi bi-check-lg me-1"></i>Save Triggers
                    </button>
                </div>
            </div>
        </div>

        <!-- ═══ Knowledge Tab ═══ -->
        <div class="tab-pane fade" id="tab-knowledge">
            <div class="row">
                <div class="col-lg-8">
                    <!-- Scanner Pipeline -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="card-title mb-0">Site Scanner</h5>
                                <div class="d-flex gap-2 align-items-center">
                                    <select class="form-select form-select-sm" id="scan-connection" style="max-width:200px;">
                                        <?php
                                        $shopifyConns = array_filter($connections, fn($c) => $c['type'] === 'shopify');
                                        foreach ($shopifyConns as $c): ?>
                                        <option value="<?= $c['id'] ?>" <?= ($bot['connections_id'] == $c['id']) ? 'selected' : '' ?>>
                                            <?= h($c['name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                        <?php if (empty($shopifyConns)): ?>
                                        <option value="">No Shopify connections</option>
                                        <?php endif; ?>
                                    </select>
                                    <button class="btn btn-primary btn-sm text-nowrap" id="btn-scan-now" <?= empty($config['scanner_pipeline_id']) ? 'disabled' : '' ?>>
                                        <i class="bi bi-radar me-1"></i>Scan Site Now
                                    </button>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Scanner Pipeline</label>
                                <div class="input-group">
                                    <select class="form-select" id="cfg-scanner-pipeline">
                                        <option value="">-- No scanner pipeline --</option>
                                        <?php foreach ($pipelines as $p): ?>
                                        <option value="<?= $p['id'] ?>" <?= (($config['scanner_pipeline_id'] ?? '') == $p['id']) ? 'selected' : '' ?>>
                                            <?= h($p['name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-outline-primary" id="btn-save-scanner">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </div>
                                <small class="text-muted">Pipeline that crawls/indexes content into the knowledge base.</small>
                            </div>

                            <div id="scan-status" class="small"></div>
                        </div>
                    </div>

                    <!-- Linked KBs with inline docs -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <h5 class="card-title mb-3">Linked Knowledge Bases</h5>
                            <p class="text-muted small">Knowledge bases this chatbot can search for context. <a href="/knowledgebase" class="text-decoration-none">Manage all KBs</a></p>

                            <div id="kb-docs-container" class="mb-3">
                                <div class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>Loading...</div>
                            </div>

                            <div class="input-group">
                                <select class="form-select" id="kb-add-select">
                                    <option value="">-- Add a knowledge base --</option>
                                    <?php foreach ($knowledgebases as $kb): ?>
                                    <option value="<?= $kb['id'] ?>"><?= h($kb['name']) ?> (<?= h($kb['slug']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-outline-primary" id="btn-link-kb">
                                    <i class="bi bi-link-45deg me-1"></i>Link
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- end tab-content -->
</div>

<script>
(function() {
    const CHATBOT_ID = <?= (int)$bot['id'] ?>;
    const PIPELINES = <?= json_encode($pipelines) ?>;
    let triggers = <?= json_encode($bot['triggers'] ?? []) ?>;
    let linkedKbs = <?= json_encode($bot['knowledge_bases'] ?? []) ?>;

    // ── Helpers ──────────────────────────────────────────────────────

    function showToast(msg, type) {
        if (type === 'error') { alert('Error: ' + msg); return; }
        // Show a brief floating toast
        let toast = document.getElementById('chatbot-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'chatbot-toast';
            toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;min-width:200px;';
            document.body.appendChild(toast);
        }
        const color = type === 'success' ? 'success' : 'info';
        const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-info-circle-fill';
        toast.innerHTML = `<div class="alert alert-${color} d-flex align-items-center shadow-sm py-2 px-3 mb-0 fade show" role="alert">
            <i class="bi ${icon} me-2"></i>${msg}
        </div>`;
        setTimeout(() => { toast.innerHTML = ''; }, 3000);
    }

    window.copyToClipboard = function(elementId) {
        const el = document.getElementById(elementId);
        const text = el.value || el.textContent;
        navigator.clipboard.writeText(text).then(() => {
            // Brief visual feedback on the copy button
            const btn = el.nextElementSibling;
            if (btn) {
                const origHtml = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check-lg text-success"></i>';
                setTimeout(() => { btn.innerHTML = origHtml; }, 1500);
            }
        });
    };

    async function postJson(url, data) {
        const resp = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        return resp.json();
    }

    // ── General Tab ─────────────────────────────────────────────────

    document.getElementById('btn-save-general').addEventListener('click', async function() {
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

        try {
            const data = await postJson('/livechat/chatbotsave', {
                chatbot_id: CHATBOT_ID,
                name: document.getElementById('cfg-name').value.trim(),
                mode: document.getElementById('cfg-mode').value,
                status: document.getElementById('cfg-status').value,
                connections_id: document.getElementById('cfg-connection').value || null,
                pipelines_id: document.getElementById('cfg-pipeline').value || null,
            });
            if (data.success) {
                // Update page heading with new name
                const newName = document.getElementById('cfg-name').value.trim();
                const heading = document.querySelector('h4 .bi-robot')?.parentElement;
                if (heading) {
                    const badges = heading.querySelectorAll('.badge');
                    let badgeHtml = '';
                    badges.forEach(b => badgeHtml += b.outerHTML);
                    heading.innerHTML = '<i class="bi bi-robot me-2"></i>' + newName + ' ' + badgeHtml;
                }
                showToast('Saved', 'success');
            } else {
                showToast(data.message || 'Save failed', 'error');
            }
        } catch (err) {
            showToast('Error saving', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save';
        }
    });

    // ── Widget Tab ──────────────────────────────────────────────────

    document.getElementById('btn-regen-token').addEventListener('click', async function() {
        if (!confirm('Regenerate widget token? Existing embeds will stop working.')) return;
        const btn = this;
        btn.disabled = true;

        try {
            const data = await postJson('/livechat/chatbottoken', { chatbot_id: CHATBOT_ID });
            if (data.success) {
                document.getElementById('widget-token').value = data.data.widget_token;
                // Update embed code
                const domain = '<?= $domain ?>';
                const iframeSrc = `https://${domain}/customersupport?token=${data.data.widget_token}&embed=1`;
                document.getElementById('embed-iframe').value =
                    `<iframe src="${iframeSrc}" style="position:fixed;bottom:20px;right:20px;width:400px;height:600px;border:none;z-index:9999;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,0.15);" allow="clipboard-write"></iframe>`;
            } else {
                showToast(data.message, 'error');
            }
        } catch (err) {
            showToast('Error regenerating token', 'error');
        } finally {
            btn.disabled = false;
        }
    });

    document.getElementById('btn-save-widget').addEventListener('click', async function() {
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

        try {
            const data = await postJson('/livechat/chatbotsave', {
                chatbot_id: CHATBOT_ID,
                config: {
                    welcome_message: document.getElementById('cfg-welcome').value,
                    require_order_verification: document.getElementById('cfg-require-verification').checked,
                    session_expiry_seconds: parseInt(document.getElementById('cfg-expiry').value) || 3600,
                }
            });
            if (data.success) {
                showToast('Widget settings saved', 'success');
            } else {
                showToast(data.message || 'Save failed', 'error');
            }
        } catch (err) {
            showToast('Error saving', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Widget Settings';
        }
    });

    // ── Triggers Tab ────────────────────────────────────────────────

    function renderTriggers() {
        const container = document.getElementById('triggers-container');
        if (!triggers.length) {
            container.innerHTML = '<div class="text-muted small">No triggers configured. Add one to route customer messages to specific pipelines.</div>';
            return;
        }

        let html = '<div class="table-responsive"><table class="table table-sm align-middle">';
        html += '<thead><tr><th>Name</th><th>Keywords</th><th>Action</th><th>Pipeline</th><th>Enabled</th><th></th></tr></thead><tbody>';

        triggers.forEach((t, i) => {
            const keywords = Array.isArray(t.keywords) ? t.keywords.join(', ') : t.keywords;
            const pipeOpts = PIPELINES.map(p =>
                `<option value="${p.id}" ${p.id == t.pipeline_id ? 'selected' : ''}>${p.name}</option>`
            ).join('');

            html += `<tr data-idx="${i}">
                <td><input type="text" class="form-control form-control-sm trigger-name" value="${t.name || ''}" style="min-width:120px"></td>
                <td><input type="text" class="form-control form-control-sm trigger-keywords" value="${keywords}" style="min-width:180px" placeholder="comma-separated"></td>
                <td>
                    <select class="form-select form-select-sm trigger-action" style="min-width:140px">
                        <option value="escalate" ${t.action === 'escalate' ? 'selected' : ''}>Escalate</option>
                        <option value="run_pipeline" ${t.action === 'run_pipeline' ? 'selected' : ''}>Run Pipeline</option>
                        <option value="escalate_and_run" ${t.action === 'escalate_and_run' ? 'selected' : ''}>Escalate + Run</option>
                    </select>
                </td>
                <td>
                    <select class="form-select form-select-sm trigger-pipeline" style="min-width:140px">
                        <option value="">-- None --</option>
                        ${pipeOpts}
                    </select>
                </td>
                <td class="text-center">
                    <input type="checkbox" class="form-check-input trigger-enabled" ${t.enabled !== false ? 'checked' : ''}>
                </td>
                <td>
                    <button class="btn btn-sm btn-outline-danger" onclick="removeTrigger(${i})" title="Remove">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>`;
        });

        html += '</tbody></table></div>';
        container.innerHTML = html;
    }

    document.getElementById('btn-add-trigger').addEventListener('click', function() {
        triggers.push({ name: '', keywords: [], action: 'escalate', pipeline_id: null, enabled: true });
        renderTriggers();
    });

    window.removeTrigger = function(idx) {
        triggers.splice(idx, 1);
        renderTriggers();
    };

    function collectTriggersFromDOM() {
        const rows = document.querySelectorAll('#triggers-container tr[data-idx]');
        const result = [];
        rows.forEach(row => {
            result.push({
                name: row.querySelector('.trigger-name').value.trim(),
                keywords: row.querySelector('.trigger-keywords').value,
                action: row.querySelector('.trigger-action').value,
                pipeline_id: row.querySelector('.trigger-pipeline').value || null,
                enabled: row.querySelector('.trigger-enabled').checked,
            });
        });
        return result.filter(t => t.name || t.keywords);
    }

    document.getElementById('btn-save-triggers').addEventListener('click', async function() {
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

        triggers = collectTriggersFromDOM();

        try {
            const data = await postJson('/livechat/chatbottriggers', {
                chatbot_id: CHATBOT_ID,
                triggers: triggers,
            });
            if (data.success) {
                triggers = data.data.triggers;
                renderTriggers();
                showToast('Triggers saved', 'success');
            } else {
                showToast(data.message || 'Save failed', 'error');
            }
        } catch (err) {
            showToast('Error saving triggers', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Triggers';
        }
    });

    renderTriggers();

    // ── Knowledge Tab ───────────────────────────────────────────────

    async function loadKbDocs() {
        const container = document.getElementById('kb-docs-container');
        try {
            const resp = await fetch(`/livechat/chatbotkbdocs?chatbot_id=${CHATBOT_ID}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await resp.json();
            if (!data.success) {
                container.innerHTML = '<div class="text-muted small">Failed to load.</div>';
                return;
            }

            const kbs = data.data.knowledge_bases || [];
            linkedKbs = kbs.map(kb => ({ kb_id: kb.id, name: kb.name, slug: kb.slug }));

            if (!kbs.length) {
                container.innerHTML = '<div class="text-muted small">No knowledge bases linked. Link one below.</div>';
                return;
            }

            let html = '';
            kbs.forEach(kb => {
                const docs = kb.documents || [];
                html += `<div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <i class="bi bi-book me-1 text-primary"></i>
                            <strong>${kb.name}</strong>
                            <small class="text-muted ms-1">(${kb.slug})</small>
                            <span class="badge bg-secondary bg-opacity-10 text-secondary ms-1">${docs.length} docs</span>
                        </div>
                        <div>
                            <a href="/knowledgebase?kb=${kb.id}" class="btn btn-sm btn-outline-secondary me-1" title="Manage in full KB page">
                                <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                            <button class="btn btn-sm btn-outline-danger" onclick="unlinkKb(${kb.id})" title="Unlink">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    </div>`;

                if (docs.length) {
                    html += '<table class="table table-sm small mb-0"><thead><tr><th>Document</th><th>Status</th><th>Chunks</th><th>Added</th></tr></thead><tbody>';
                    docs.forEach(d => {
                        const statusColor = d.status === 'ready' ? 'success' : (d.status === 'error' ? 'danger' : 'warning');
                        html += `<tr>
                            <td>${d.filename}</td>
                            <td><span class="badge bg-${statusColor} bg-opacity-10 text-${statusColor}">${d.status}</span></td>
                            <td>${d.chunk_count}</td>
                            <td>${d.created_at ? d.created_at.substring(0, 10) : '-'}</td>
                        </tr>`;
                    });
                    html += '</tbody></table>';
                } else {
                    html += '<div class="text-muted small ms-4 mb-2">No documents in this KB yet.</div>';
                }

                html += '</div><hr class="my-2">';
            });

            container.innerHTML = html;

            // Update scan status if available
            const scan = data.data.latest_scan;
            renderScanStatus(scan);

        } catch (err) {
            container.innerHTML = '<div class="text-muted small">Error loading KB documents.</div>';
        }
    }

    function renderScanStatus(scan) {
        const el = document.getElementById('scan-status');
        if (!scan) {
            el.innerHTML = '<span class="text-muted">No scans run yet.</span>';
            return;
        }
        const statusColor = scan.status === 'completed' ? 'success' : (scan.status === 'failed' ? 'danger' : (scan.status === 'running' ? 'info' : 'secondary'));
        el.innerHTML = `<div class="d-flex align-items-center gap-2">
            <span class="badge bg-${statusColor}">${scan.status}</span>
            <span>Last scan: ${scan.created_at}</span>
            ${scan.status === 'running' ? '<span class="spinner-border spinner-border-sm"></span>' : ''}
        </div>`;
    }

    // Link KB
    document.getElementById('btn-link-kb').addEventListener('click', async function() {
        const select = document.getElementById('kb-add-select');
        const kbId = select.value;
        if (!kbId) return;

        try {
            const data = await postJson('/livechat/chatbotkbs', {
                chatbot_id: CHATBOT_ID,
                action: 'link',
                kb_id: parseInt(kbId),
            });
            if (data.success) {
                loadKbDocs();
                select.value = '';
                showToast('Knowledge base linked', 'success');
            } else {
                showToast(data.message, 'error');
            }
        } catch (err) {
            showToast('Error linking KB', 'error');
        }
    });

    window.unlinkKb = async function(kbId) {
        try {
            const data = await postJson('/livechat/chatbotkbs', {
                chatbot_id: CHATBOT_ID,
                action: 'unlink',
                kb_id: kbId,
            });
            if (data.success) {
                loadKbDocs();
                showToast('Knowledge base unlinked', 'success');
            } else {
                showToast(data.message, 'error');
            }
        } catch (err) {
            showToast('Error unlinking KB', 'error');
        }
    };

    // Scanner pipeline
    document.getElementById('btn-save-scanner').addEventListener('click', async function() {
        const pipelineId = document.getElementById('cfg-scanner-pipeline').value || null;
        try {
            const data = await postJson('/livechat/chatbotsave', {
                chatbot_id: CHATBOT_ID,
                config: { scanner_pipeline_id: pipelineId ? parseInt(pipelineId) : null }
            });
            if (data.success) {
                document.getElementById('btn-scan-now').disabled = !pipelineId;
                showToast('Scanner pipeline saved', 'success');
            } else {
                showToast(data.message, 'error');
            }
        } catch (err) {
            showToast('Error saving', 'error');
        }
    });

    // Scan Site Now
    document.getElementById('btn-scan-now').addEventListener('click', async function() {
        const btn = this;
        const origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Starting scan...';

        try {
            const connId = document.getElementById('scan-connection').value || null;
            const data = await postJson('/livechat/chatbotcrawl', {
                chatbot_id: CHATBOT_ID,
                crawl_action: 'scan',
                connections_id: connId ? parseInt(connId) : null
            });
            if (data.success) {
                showToast('Scanner pipeline started', 'success');
                // Poll for status
                setTimeout(() => refreshScanStatus(), 3000);
            } else {
                showToast(data.message || 'Scan failed', 'error');
            }
        } catch (err) {
            showToast('Error starting scan', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    });

    async function refreshScanStatus() {
        try {
            const data = await postJson('/livechat/chatbotcrawl', {
                chatbot_id: CHATBOT_ID,
                crawl_action: 'status'
            });
            if (data.success) {
                renderScanStatus(data.data.latest_scan);
                // Keep polling if still running
                if (data.data.latest_scan && data.data.latest_scan.status === 'running') {
                    setTimeout(() => refreshScanStatus(), 5000);
                }
            }
        } catch (err) { /* ignore */ }
    }

    loadKbDocs();

    // ── Archive ─────────────────────────────────────────────────────

    window.archiveChatbot = async function() {
        if (!confirm('Archive this chatbot? It will no longer accept traffic.')) return;
        try {
            const data = await postJson('/livechat/chatbotdelete', { chatbot_id: CHATBOT_ID });
            if (data.success) {
                window.location.href = '/livechat/chatbots';
            } else {
                showToast(data.message, 'error');
            }
        } catch (err) {
            showToast('Error archiving', 'error');
        }
    };

})();
</script>
