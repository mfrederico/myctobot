<?php
/**
 * Chatbot Management - List View
 *
 * Lists all chatbot instances with mode badges, status, and connection info.
 * "New Chatbot" button opens creation modal.
 *
 * Variables: $member
 */
?>
<div class="container-fluid py-4" data-assist-page="livechat/chatbots" data-assist-purpose="Manage chatbot instances">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-robot me-2"></i>Chatbots</h4>
            <small class="text-muted">Configure AI chatbot instances for customer support or internal use</small>
        </div>
        <div>
            <a href="/livechat/admin" class="btn btn-sm btn-outline-secondary me-2">
                <i class="bi bi-arrow-left me-1"></i>Back to Admin
            </a>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-new-chatbot">
                <i class="bi bi-plus-lg me-1"></i>New Chatbot
            </button>
        </div>
    </div>

    <!-- Chatbot Cards -->
    <div id="chatbots-container">
        <div class="text-center py-5 text-muted">
            <span class="spinner-border spinner-border-sm me-2"></span>Loading chatbots...
        </div>
    </div>
</div>

<!-- New Chatbot Modal -->
<div class="modal fade" id="modal-new-chatbot" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>New Chatbot</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Name</label>
                    <input type="text" class="form-control" id="new-bot-name" placeholder="e.g., The Glove Store Support">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Mode</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="new-bot-mode" value="advisor" id="mode-advisor" checked>
                            <label class="form-check-label" for="mode-advisor">
                                <i class="bi bi-headset me-1 text-success"></i>Advisor
                                <small class="d-block text-muted">External customer support chatbot</small>
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="new-bot-mode" value="builder" id="mode-builder">
                            <label class="form-check-label" for="mode-builder">
                                <i class="bi bi-tools me-1 text-primary"></i>Builder
                                <small class="d-block text-muted">Internal MyCTOBot assistant</small>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btn-create-chatbot">
                    <i class="bi bi-plus-lg me-1"></i>Create
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const container = document.getElementById('chatbots-container');

    function renderChatbots(chatbots) {
        if (!chatbots.length) {
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="bi bi-robot display-4 text-muted"></i>
                    <h5 class="mt-3 text-muted">No chatbots yet</h5>
                    <p class="text-muted">Create your first chatbot to get started.</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-new-chatbot">
                        <i class="bi bi-plus-lg me-1"></i>New Chatbot
                    </button>
                </div>`;
            return;
        }

        let html = '<div class="row g-4">';
        chatbots.forEach(bot => {
            const modeIcon = bot.mode === 'builder' ? 'bi-tools' : 'bi-headset';
            const modeColor = bot.mode === 'builder' ? 'primary' : 'success';
            const modeLabel = bot.mode === 'builder' ? 'Builder' : 'Advisor';
            const statusColor = bot.status === 'active' ? 'success' : (bot.status === 'inactive' ? 'warning' : 'secondary');
            // SECURITY (LOW XSS): escape DB/external-derived strings (bot + Shopify
            // connection/store names) before interpolating into innerHTML.
            const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            const connName = esc(bot.connection ? bot.connection.name : (bot.mode === 'builder' ? 'Internal' : 'No connection'));
            const pipeName = esc(bot.pipeline ? bot.pipeline.name : 'Not set');
            const kbCount = (bot.knowledge_bases || []).length;
            const triggerCount = (bot.triggers || []).length;

            const isArchived = bot.status === 'archived';
            html += `
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm ${isArchived ? 'opacity-50' : ''}">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h5 class="card-title mb-1">${esc(bot.name)}</h5>
                                <span class="badge bg-${modeColor} bg-opacity-10 text-${modeColor}">
                                    <i class="bi ${modeIcon} me-1"></i>${modeLabel}
                                </span>
                                <span class="badge bg-${statusColor} bg-opacity-10 text-${statusColor} ms-1">
                                    ${bot.status}
                                </span>
                            </div>
                        </div>
                        <div class="small text-muted mb-2">
                            <div class="mb-1"><i class="bi bi-plug me-2"></i><strong>Connection:</strong> ${connName}</div>
                            <div class="mb-1"><i class="bi bi-diagram-3 me-2"></i><strong>Pipeline:</strong> ${pipeName}</div>
                            <div class="mb-1"><i class="bi bi-book me-2"></i><strong>Knowledge Bases:</strong> ${kbCount}</div>
                            <div><i class="bi bi-lightning me-2"></i><strong>Triggers:</strong> ${triggerCount} rules</div>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent border-0 pt-0">
                        <a href="/livechat/chatbots/${encodeURIComponent(bot.slug)}" class="btn btn-outline-primary btn-sm w-100">
                            <i class="bi bi-gear me-1"></i>Configure
                        </a>
                    </div>
                </div>
            </div>`;
        });
        html += '</div>';
        container.innerHTML = html;
    }

    async function loadChatbots() {
        try {
            const resp = await fetch('/livechat/chatbots', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await resp.json();
            if (data.success) {
                renderChatbots(data.data.chatbots);
            } else {
                container.innerHTML = '<div class="alert alert-danger">Failed to load chatbots</div>';
            }
        } catch (err) {
            container.innerHTML = '<div class="alert alert-danger">Error loading chatbots</div>';
        }
    }

    // Create chatbot
    document.getElementById('btn-create-chatbot').addEventListener('click', async function() {
        const btn = this;
        const name = document.getElementById('new-bot-name').value.trim();
        const mode = document.querySelector('input[name="new-bot-mode"]:checked').value;

        if (!name) {
            alert('Please enter a chatbot name');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Creating...';

        try {
            const resp = await fetch('/livechat/chatbots', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, mode })
            });
            const data = await resp.json();
            if (data.success && data.data.redirect) {
                window.location.href = data.data.redirect;
            } else {
                alert(data.message || 'Failed to create chatbot');
            }
        } catch (err) {
            alert('Error creating chatbot');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Create';
        }
    });

    loadChatbots();
})();
</script>
