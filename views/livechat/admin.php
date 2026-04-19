<?php
/**
 * Live Chat Admin (Overlord) View
 *
 * Shows all agents, full queue, all active chats across all agents.
 * Knowledge Base tab for RAG seeding (policies, FAQ).
 * Polls /livechat/admin (AJAX) every 3s.
 *
 * Variables: $member (array)
 */
?>
<div class="container-fluid py-4" data-assist-page="livechat/admin" data-assist-purpose="Admin overlord view of all live chats and agents">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-shield-check me-2"></i>Live Chat Admin</h4>
            <small class="text-muted">Manage agents, customer chats, and chatbots</small>
        </div>
        <a href="/livechat/chatbots" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-robot me-1"></i>Chatbots
        </a>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-dashboard" type="button">
                <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-chatbots" type="button" id="chatbots-tab-btn">
                <i class="bi bi-robot me-1"></i>Chatbots
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <!-- Dashboard Tab -->
        <div class="tab-pane fade show active" id="tab-dashboard">
            <!-- Agents Row -->
            <div class="mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="text-muted mb-0"><i class="bi bi-people me-2"></i>Agents</h5>
                    <button class="btn btn-sm btn-outline-secondary" id="btn-manage-agents">
                        <i class="bi bi-gear me-1"></i>Manage CS Reps
                    </button>
                </div>
                <div id="agents-container" class="d-flex flex-wrap gap-3">
                    <div class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Loading...</div>
                </div>
            </div>

            <!-- Queue -->
            <div class="mb-4">
                <h5 class="text-muted mb-3"><i class="bi bi-inbox me-2"></i>Queue (<span id="queue-count">0</span>)</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="queue-table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Reason</th>
                                <th>Wait</th>
                                <th>Assign</th>
                            </tr>
                        </thead>
                        <tbody id="queue-tbody">
                            <tr><td colspan="4" class="text-center text-muted py-3">Queue empty</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- All Active Chats -->
            <div>
                <h5 class="text-muted mb-3"><i class="bi bi-chat-left-text me-2"></i>All Active Chats (<span id="active-count">0</span>)</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Agent</th>
                                <th>Duration</th>
                                <th>Messages</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="active-tbody">
                            <tr><td colspan="5" class="text-center text-muted py-3">No active chats</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Chatbots Tab -->
        <div class="tab-pane fade" id="tab-chatbots">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <p class="text-muted mb-0">Configure AI chatbot instances for customer support or internal use.</p>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-new-chatbot">
                    <i class="bi bi-plus-lg me-1"></i>New Chatbot
                </button>
            </div>
            <div id="chatbots-list-container">
                <div class="text-center py-4 text-muted">
                    <span class="spinner-border spinner-border-sm me-1"></span>Loading chatbots...
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1080;">
    <div id="admin-toast" class="toast align-items-center border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="admin-toast-body"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
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
                                <small class="d-block text-muted">External customer support</small>
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

<!-- Manage Agents Modal -->
<div class="modal fade" id="agentsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Manage CS Agents</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="agents-list" class="mb-4"><span class="spinner-border spinner-border-sm me-1"></span>Loading...</div>
                <hr>
                <h6>Add Agent</h6>
                <form id="add-agent-form" class="row g-2">
                    <div class="col-md-4">
                        <select class="form-select form-select-sm" id="add-member-id" required>
                            <option value="">Select member...</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <input type="text" class="form-control form-control-sm" id="add-display-name" placeholder="Display name" required>
                    </div>
                    <div class="col-md-2">
                        <input type="number" class="form-control form-control-sm" id="add-max-concurrent" value="3" min="1" max="10">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-sm btn-primary w-100">Add Agent</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    const statusColors = { online: '#198754', away: '#ffc107', offline: '#6c757d' };
    let onlineAgents = [];

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    function showToast(message, type) {
        type = type || 'info';
        const colors = { success: 'bg-success text-white', error: 'bg-danger text-white', warning: 'bg-warning text-dark', info: 'bg-primary text-white' };
        const toast = document.getElementById('admin-toast');
        toast.className = 'toast align-items-center border-0 ' + (colors[type] || colors.info);
        document.getElementById('admin-toast-body').textContent = message;
        bootstrap.Toast.getOrCreateInstance(toast, { delay: 4000 }).show();
    }

    // =========================================================
    // Dashboard Tab
    // =========================================================

    function renderAgents(agents) {
        onlineAgents = agents.filter(a => a.status === 'online');
        const container = document.getElementById('agents-container');
        if (agents.length === 0) {
            container.innerHTML = '<div class="text-muted">No agents configured</div>';
            return;
        }
        let html = '';
        agents.forEach(function(a) {
            const color = statusColors[a.status] || '#6c757d';
            html += '<div class="card" style="min-width: 140px; border-top: 3px solid ' + color + ';">' +
                '<div class="card-body py-2 px-3 text-center">' +
                '<strong>' + escapeHtml(a.display_name) + '</strong><br>' +
                '<small class="text-muted">' + a.active_chats + '/' + a.max_concurrent + ' chats</small><br>' +
                '<small style="color:' + color + ';">' + escapeHtml(a.status) + '</small>' +
                '</div></div>';
        });
        container.innerHTML = html;
    }

    function renderQueue(queue) {
        document.getElementById('queue-count').textContent = queue.length;
        const tbody = document.getElementById('queue-tbody');
        if (queue.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">Queue empty</td></tr>';
            return;
        }
        let html = '';
        queue.forEach(function(item) {
            let assignDropdown = '<select class="form-select form-select-sm" onchange="assignChat(' + item.id + ', this.value)">' +
                '<option value="">Assign to...</option>';
            onlineAgents.forEach(function(a) {
                assignDropdown += '<option value="' + a.id + '">' + escapeHtml(a.display_name) + ' (' + a.active_chats + '/' + a.max_concurrent + ')</option>';
            });
            assignDropdown += '</select>';

            html += '<tr>' +
                '<td>' + escapeHtml(item.email) + '</td>' +
                '<td><small>' + escapeHtml(item.escalation_reason || '') + '</small></td>' +
                '<td>' + escapeHtml(item.wait_time || '') + '</td>' +
                '<td>' + assignDropdown + '</td>' +
                '</tr>';
        });
        tbody.innerHTML = html;
    }

    function renderActiveChats(chats) {
        document.getElementById('active-count').textContent = chats.length;
        const tbody = document.getElementById('active-tbody');
        if (chats.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No active chats</td></tr>';
            return;
        }
        let html = '';
        chats.forEach(function(item) {
            html += '<tr>' +
                '<td>' + escapeHtml(item.email) + '</td>' +
                '<td>' + escapeHtml(item.agent_name) + '</td>' +
                '<td>' + escapeHtml(item.duration || '') + '</td>' +
                '<td>' + (item.message_count || 0) + '</td>' +
                '<td><a href="/livechat/chat/' + item.id + '" class="btn btn-sm btn-outline-primary">View</a></td>' +
                '</tr>';
        });
        tbody.innerHTML = html;
    }

    window.assignChat = async function(sessionId, agentId) {
        if (!agentId) return;
        try {
            const resp = await fetch('/livechat/assign', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ session_id: sessionId, agent_id: parseInt(agentId) })
            });
            const data = await resp.json();
            if (!data.success) {
                showToast(data.error || 'Assignment failed', 'error');
            } else {
                showToast('Chat assigned', 'success');
            }
        } catch (err) {
            showToast('Error assigning chat', 'error');
        }
    };

    async function refreshAdmin() {
        try {
            const resp = await fetch('/livechat/admin', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await resp.json();
            if (data.success && data.data) {
                renderAgents(data.data.agents || []);
                renderQueue(data.data.queue || []);
                renderActiveChats(data.data.active_chats || []);
            }
        } catch (err) {}
    }

    refreshAdmin();
    setInterval(refreshAdmin, 3000);

    // =========================================================
    // Agent Management Modal
    // =========================================================

    document.getElementById('btn-manage-agents').addEventListener('click', function() {
        loadAgentManagement();
        new bootstrap.Modal(document.getElementById('agentsModal')).show();
    });

    async function loadAgentManagement() {
        try {
            const resp = await fetch('/livechat/agents');
            const data = await resp.json();
            if (data.success && data.data) {
                let html = '';
                (data.data.agents || []).forEach(function(a) {
                    html += '<div class="d-flex justify-content-between align-items-center mb-2 p-2 border rounded">' +
                        '<div><strong>' + escapeHtml(a.display_name) + '</strong> <small class="text-muted">(member #' + a.member_id + ')</small>' +
                        ' — Max: ' + a.max_concurrent + ', Status: ' + a.status + '</div>' +
                        '<button class="btn btn-sm btn-outline-danger" onclick="removeAgent(' + a.id + ')"><i class="bi bi-trash"></i></button>' +
                        '</div>';
                });
                document.getElementById('agents-list').innerHTML = html || '<div class="text-muted">No agents</div>';

                const select = document.getElementById('add-member-id');
                const existingIds = (data.data.agents || []).map(a => a.member_id);
                select.innerHTML = '<option value="">Select member...</option>';
                (data.data.members || []).forEach(function(m) {
                    if (!existingIds.includes(m.id)) {
                        select.innerHTML += '<option value="' + m.id + '">' + escapeHtml(m.name || m.email) + '</option>';
                    }
                });
            }
        } catch (err) {}
    }

    document.getElementById('add-agent-form').addEventListener('submit', async function(e) {
        e.preventDefault();
        const memberId = document.getElementById('add-member-id').value;
        const displayName = document.getElementById('add-display-name').value.trim();
        const maxConcurrent = document.getElementById('add-max-concurrent').value;
        if (!memberId || !displayName) return;

        try {
            const resp = await fetch('/livechat/agents', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'add', member_id: parseInt(memberId), display_name: displayName, max_concurrent: parseInt(maxConcurrent) })
            });
            const data = await resp.json();
            if (data.success) {
                loadAgentManagement();
                document.getElementById('add-display-name').value = '';
                showToast('Agent added', 'success');
            } else {
                showToast(data.error || 'Failed to add agent', 'error');
            }
        } catch (err) {
            showToast('Error adding agent', 'error');
        }
    });

    window.removeAgent = async function(agentId) {
        if (!confirm('Remove this agent?')) return;
        try {
            const resp = await fetch('/livechat/agents', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'remove', agent_id: agentId })
            });
            const data = await resp.json();
            if (data.success) {
                loadAgentManagement();
                showToast('Agent removed', 'success');
            } else {
                showToast(data.error || 'Failed to remove', 'error');
            }
        } catch (err) {
            showToast('Error removing agent', 'error');
        }
    };

    // =========================================================
    // Chatbots Tab
    // =========================================================

    let chatbotsLoaded = false;

    document.getElementById('chatbots-tab-btn').addEventListener('shown.bs.tab', function() {
        if (!chatbotsLoaded) {
            chatbotsLoaded = true;
            loadChatbotsList();
        }
    });

    async function loadChatbotsList() {
        const container = document.getElementById('chatbots-list-container');
        try {
            const resp = await fetch('/livechat/chatbots', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await resp.json();
            if (!data.success) {
                container.innerHTML = '<div class="alert alert-danger">Failed to load chatbots</div>';
                return;
            }

            const chatbots = data.data.chatbots || [];
            if (!chatbots.length) {
                container.innerHTML = '<div class="text-center py-4"><i class="bi bi-robot display-4 text-muted"></i><h5 class="mt-3 text-muted">No chatbots yet</h5><p class="text-muted">Create your first chatbot to get started.</p></div>';
                return;
            }

            let html = '<div class="row g-3">';
            chatbots.forEach(function(bot) {
                const modeIcon = bot.mode === 'builder' ? 'bi-tools' : 'bi-headset';
                const modeColor = bot.mode === 'builder' ? 'primary' : 'success';
                const modeLabel = bot.mode === 'builder' ? 'Builder' : 'Advisor';
                const statusColor = bot.status === 'active' ? 'success' : (bot.status === 'inactive' ? 'warning' : 'secondary');
                const isArchived = bot.status === 'archived';
                const connName = bot.connection ? bot.connection.name : (bot.mode === 'builder' ? 'Internal' : 'No connection');
                const pipeName = bot.pipeline ? bot.pipeline.name : 'Not set';
                const kbCount = (bot.knowledge_bases || []).length;
                const triggerCount = (bot.triggers || []).length;

                html += '<div class="col-md-6 col-lg-4">' +
                    '<div class="card h-100 border-0 shadow-sm ' + (isArchived ? 'opacity-50' : '') + '">' +
                    '<div class="card-body">' +
                    '<div class="d-flex justify-content-between align-items-start mb-2">' +
                    '<div><h6 class="card-title mb-1">' + escapeHtml(bot.name) + '</h6>' +
                    '<span class="badge bg-' + modeColor + ' bg-opacity-10 text-' + modeColor + '"><i class="bi ' + modeIcon + ' me-1"></i>' + modeLabel + '</span> ' +
                    '<span class="badge bg-' + statusColor + ' bg-opacity-10 text-' + statusColor + '">' + bot.status + '</span></div></div>' +
                    '<div class="small text-muted">' +
                    '<div class="mb-1"><i class="bi bi-plug me-2"></i>' + escapeHtml(connName) + '</div>' +
                    '<div class="mb-1"><i class="bi bi-diagram-3 me-2"></i>' + escapeHtml(pipeName) + '</div>' +
                    '<div><i class="bi bi-book me-2"></i>' + kbCount + ' KBs &middot; <i class="bi bi-lightning me-1"></i>' + triggerCount + ' triggers</div>' +
                    '</div></div>' +
                    '<div class="card-footer bg-transparent border-0 pt-0">' +
                    '<a href="/livechat/chatbots/' + bot.slug + '" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-gear me-1"></i>Configure</a>' +
                    '</div></div></div>';
            });
            html += '</div>';
            container.innerHTML = html;
        } catch (err) {
            container.innerHTML = '<div class="alert alert-danger">Error loading chatbots</div>';
        }
    }

    // Create chatbot
    document.getElementById('btn-create-chatbot').addEventListener('click', async function() {
        const btn = this;
        const name = document.getElementById('new-bot-name').value.trim();
        const mode = document.querySelector('input[name="new-bot-mode"]:checked').value;

        if (!name) { alert('Please enter a chatbot name'); return; }

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Creating...';

        try {
            const resp = await fetch('/livechat/chatbots', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: name, mode: mode })
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

})();
</script>
