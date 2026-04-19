<?php
/**
 * Live Chat Agent Dashboard
 *
 * Tab 1: Dashboard — queue of unassigned escalated chats + agent's active chats.
 * Tab 2: All Sessions — browse/filter ALL customer sessions (bot + human + resolved).
 * Polls /livechat/dashboard every 3s for real-time updates.
 *
 * Variables: $agent (array), $member (array)
 */
$agentData = $agent ?? [];
$agentStatus = $agentData['status'] ?? 'offline';
?>
<div class="container-fluid py-4" data-assist-page="livechat" data-assist-purpose="CS Agent live chat dashboard">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-headset me-2"></i>Live Chat</h4>
            <small class="text-muted">Customer service dashboard</small>
        </div>
        <div class="d-flex align-items-center gap-3">
            <!-- Stats -->
            <span class="badge bg-primary" id="stat-active">Active: 0/<?= (int)($agentData['max_concurrent'] ?? 3) ?></span>
            <span class="badge bg-warning text-dark" id="stat-queue">Queue: 0</span>
            <span class="badge bg-success" id="stat-resolved">Resolved: 0</span>

            <!-- Status toggle -->
            <div class="dropdown">
                <button class="btn btn-sm dropdown-toggle" id="status-btn" type="button" data-bs-toggle="dropdown"
                    style="border: 1px solid rgba(255,255,255,0.2);">
                    <span id="status-indicator" class="me-1" style="display:inline-block;width:10px;height:10px;border-radius:50%;background-color:#6c757d;vertical-align:middle;"></span>
                    <span id="status-label"><?= ucfirst($agentStatus) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="#" data-status="online"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background-color:#198754;vertical-align:middle;" class="me-1"></span> Online</a></li>
                    <li><a class="dropdown-item" href="#" data-status="away"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background-color:#ffc107;vertical-align:middle;" class="me-1"></span> Away</a></li>
                    <li><a class="dropdown-item" href="#" data-status="offline"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background-color:#6c757d;vertical-align:middle;" class="me-1"></span> Offline</a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-dashboard" type="button">
                <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-sessions" type="button" id="tab-sessions-btn">
                <i class="bi bi-clock-history me-1"></i>All Sessions
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <!-- TAB: Dashboard -->
        <div class="tab-pane fade show active" id="tab-dashboard">
            <!-- Queue Section -->
            <div class="mb-4">
                <h5 class="text-muted mb-3"><i class="bi bi-inbox me-2"></i>Queue (unassigned)</h5>
                <div id="queue-container" class="row g-3">
                    <div class="col-12 text-muted" id="queue-empty">
                        <div class="card card-body text-center py-4 bg-transparent border-dashed" style="border-style: dashed;">
                            <i class="bi bi-check-circle text-success" style="font-size: 2rem;"></i>
                            <p class="mb-0 mt-2">Queue is empty</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Active Chats -->
            <div>
                <h5 class="text-muted mb-3"><i class="bi bi-chat-left-text me-2"></i>My Active Chats</h5>
                <div id="active-container" class="row g-3">
                    <div class="col-12 text-muted" id="active-empty">
                        <div class="card card-body text-center py-4 bg-transparent border-dashed" style="border-style: dashed;">
                            <i class="bi bi-chat-left text-muted" style="font-size: 2rem;"></i>
                            <p class="mb-0 mt-2">No active chats</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB: All Sessions -->
        <div class="tab-pane fade" id="tab-sessions">
            <!-- Filters -->
            <div class="row g-2 mb-3">
                <div class="col-md-3">
                    <select class="form-select form-select-sm" id="filter-status">
                        <option value="all">All Statuses</option>
                        <option value="pending">Pending (new)</option>
                        <option value="verified">Bot Active</option>
                        <option value="escalated">Escalated</option>
                        <option value="resolved">Resolved</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="text" class="form-control form-control-sm" id="filter-email" placeholder="Search email...">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control form-control-sm" id="filter-date-from" title="From date">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control form-control-sm" id="filter-date-to" title="To date">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-primary w-100" id="btn-filter-sessions">
                        <i class="bi bi-search me-1"></i>Search
                    </button>
                </div>
            </div>

            <!-- Sessions table -->
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Sentiment</th>
                            <th>Agent</th>
                            <th>Messages</th>
                            <th>Last Activity</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="sessions-tbody">
                        <tr><td colspan="8" class="text-center text-muted py-4">
                            <div class="spinner-border spinner-border-sm me-2"></div>Loading sessions...
                        </td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted" id="sessions-info">Showing 0 sessions</small>
                <nav>
                    <ul class="pagination pagination-sm mb-0" id="sessions-pagination"></ul>
                </nav>
            </div>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toast-container"></div>

<script>
(function() {
    'use strict';

    const maxConcurrent = <?= (int)($agentData['max_concurrent'] ?? 3) ?>;
    let currentStatus = '<?= htmlspecialchars($agentStatus, ENT_QUOTES) ?>';

    const statusColors = { online: '#198754', away: '#ffc107', offline: '#6c757d' };

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    function showToast(message, type) {
        type = type || 'info';
        const container = document.getElementById('toast-container');
        if (!container) return;
        const id = 'toast-' + Date.now();
        const colors = { success: 'text-bg-success', danger: 'text-bg-danger', warning: 'text-bg-warning', info: 'text-bg-info' };
        const cls = colors[type] || 'text-bg-secondary';
        container.insertAdjacentHTML('beforeend',
            '<div id="' + id + '" class="toast ' + cls + '" role="alert"><div class="d-flex">' +
            '<div class="toast-body">' + escapeHtml(message) + '</div>' +
            '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
            '</div></div>');
        const el = document.getElementById(id);
        new bootstrap.Toast(el, { delay: 4000 }).show();
        el.addEventListener('hidden.bs.toast', function() { el.remove(); });
    }

    // ======================================================================
    // STATUS TOGGLE
    // ======================================================================
    document.querySelectorAll('[data-status]').forEach(function(el) {
        el.addEventListener('click', async function(e) {
            e.preventDefault();
            const newStatus = this.dataset.status;
            try {
                const resp = await fetch('/livechat/status', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ status: newStatus })
                });
                const data = await resp.json();
                if (data.success) {
                    currentStatus = newStatus;
                    updateStatusUI(newStatus);
                    showToast('Status changed to ' + newStatus, 'success');
                }
            } catch (err) {
                showToast('Failed to update status', 'danger');
            }
        });
    });

    function updateStatusUI(status) {
        document.getElementById('status-label').textContent = status.charAt(0).toUpperCase() + status.slice(1);
        document.getElementById('status-indicator').style.backgroundColor = statusColors[status] || '#6c757d';
    }
    updateStatusUI(currentStatus);

    // ======================================================================
    // DASHBOARD TAB
    // ======================================================================
    async function pickupChat(sessionId) {
        try {
            const resp = await fetch('/livechat/pickup', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ session_id: sessionId })
            });
            const data = await resp.json();
            if (data.success) {
                window.location.href = '/livechat/chat/' + sessionId;
            } else {
                showToast(data.error || 'Failed to pick up chat', 'danger');
            }
        } catch (err) {
            showToast('Error picking up chat', 'danger');
        }
    }
    window.pickupChat = pickupChat;

    function renderQueue(queue) {
        const container = document.getElementById('queue-container');
        const empty = document.getElementById('queue-empty');
        document.getElementById('stat-queue').textContent = 'Queue: ' + queue.length;

        if (queue.length === 0) {
            container.innerHTML = '';
            container.appendChild(empty);
            empty.style.display = '';
            return;
        }

        empty.style.display = 'none';
        let html = '';
        queue.forEach(function(item) {
            html += '<div class="col-md-6 col-lg-4">' +
                '<div class="card h-100" style="border-left: 3px solid #ffc107;">' +
                '<div class="card-body">' +
                '<div class="d-flex justify-content-between align-items-start mb-2">' +
                '<strong class="text-truncate" style="max-width: 70%;">' + escapeHtml(item.email) + '</strong>' +
                '<small class="text-warning">' + escapeHtml(item.wait_time || '') + '</small>' +
                '</div>' +
                (item.order_name ? '<div class="text-muted small mb-1"><i class="bi bi-box-seam me-1"></i>' + escapeHtml(item.order_name) + '</div>' : '') +
                (item.escalation_reason ? '<div class="text-muted small mb-2">' + escapeHtml(item.escalation_reason) + '</div>' : '') +
                '<div class="small text-muted mb-2 text-truncate">"' + escapeHtml(item.last_message || '') + '"</div>' +
                '<button class="btn btn-sm btn-warning w-100" onclick="pickupChat(' + item.id + ')">' +
                '<i class="bi bi-hand-index me-1"></i>Pick Up</button>' +
                '</div></div></div>';
        });
        container.innerHTML = html;
    }

    function renderActive(chats) {
        const container = document.getElementById('active-container');
        const empty = document.getElementById('active-empty');
        document.getElementById('stat-active').textContent = 'Active: ' + chats.length + '/' + maxConcurrent;

        if (chats.length === 0) {
            container.innerHTML = '';
            container.appendChild(empty);
            empty.style.display = '';
            return;
        }

        empty.style.display = 'none';
        let html = '';
        chats.forEach(function(item) {
            const unread = item.last_message_role === 'user' ? '<span class="badge bg-danger ms-2">New</span>' : '';
            html += '<div class="col-md-6 col-lg-4">' +
                '<div class="card h-100" style="border-left: 3px solid #0d6efd;">' +
                '<div class="card-body">' +
                '<div class="d-flex justify-content-between align-items-start mb-2">' +
                '<strong class="text-truncate" style="max-width: 70%;">' + escapeHtml(item.email) + unread + '</strong>' +
                '<small class="text-muted">' + escapeHtml(item.duration || '') + '</small>' +
                '</div>' +
                (item.order_name ? '<div class="text-muted small mb-1"><i class="bi bi-box-seam me-1"></i>' + escapeHtml(item.order_name) + '</div>' : '') +
                '<div class="small text-muted mb-2 text-truncate">"' + escapeHtml(item.last_message || '') + '"</div>' +
                '<div class="d-flex gap-2">' +
                '<a href="/livechat/chat/' + item.id + '" class="btn btn-sm btn-primary flex-fill">' +
                '<i class="bi bi-chat-left-text me-1"></i>Open Chat</a>' +
                '</div>' +
                '</div></div></div>';
        });
        container.innerHTML = html;
    }

    // Dashboard polling
    async function refreshDashboard() {
        try {
            const resp = await fetch('/livechat/dashboard');
            const data = await resp.json();
            if (data.success && data.data) {
                renderQueue(data.data.queue || []);
                renderActive(data.data.active || []);
                document.getElementById('stat-resolved').textContent = 'Resolved: ' + (data.data.resolved_today || 0);
                if (data.data.agent_status && data.data.agent_status !== currentStatus) {
                    currentStatus = data.data.agent_status;
                    updateStatusUI(currentStatus);
                }
            }
        } catch (err) {}
    }

    refreshDashboard();
    setInterval(refreshDashboard, 3000);

    // ======================================================================
    // ALL SESSIONS TAB
    // ======================================================================
    let sessionsPage = 1;
    let sessionsLoaded = false;

    const statusBadges = {
        pending: '<span class="badge bg-secondary">Pending</span>',
        verified: '<span class="badge bg-info">Bot Active</span>',
        escalated: '<span class="badge bg-warning text-dark">Escalated</span>',
        resolved: '<span class="badge bg-success">Resolved</span>',
        expired: '<span class="badge bg-dark">Expired</span>'
    };

    const sentimentBadges = {
        positive: '<span class="badge" style="background-color:#198754;">Positive</span>',
        neutral: '<span class="badge bg-secondary">Neutral</span>',
        negative: '<span class="badge" style="background-color:#fd7e14;">Negative</span>',
        frustrated: '<span class="badge bg-danger">Frustrated</span>',
        hostile: '<span class="badge" style="background-color:#842029;">Hostile</span>'
    };

    document.getElementById('tab-sessions-btn').addEventListener('shown.bs.tab', function() {
        if (!sessionsLoaded) {
            loadSessions();
            sessionsLoaded = true;
        }
    });

    document.getElementById('btn-filter-sessions').addEventListener('click', function() {
        sessionsPage = 1;
        loadSessions();
    });

    // Enter to search
    document.getElementById('filter-email').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { sessionsPage = 1; loadSessions(); }
    });

    async function loadSessions() {
        const status = document.getElementById('filter-status').value;
        const email = document.getElementById('filter-email').value.trim();
        const dateFrom = document.getElementById('filter-date-from').value;
        const dateTo = document.getElementById('filter-date-to').value;

        const params = new URLSearchParams({ status, page: sessionsPage, per_page: 20 });
        if (email) params.set('email', email);
        if (dateFrom) params.set('date_from', dateFrom);
        if (dateTo) params.set('date_to', dateTo);

        const tbody = document.getElementById('sessions-tbody');
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm me-2"></div>Loading...</td></tr>';

        try {
            const resp = await fetch('/livechat/sessions?' + params.toString());
            const data = await resp.json();
            if (data.success && data.data) {
                renderSessions(data.data);
            } else {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">Error loading sessions</td></tr>';
            }
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">Error loading sessions</td></tr>';
        }
    }

    function renderSessions(data) {
        const tbody = document.getElementById('sessions-tbody');
        const sessions = data.sessions || [];
        const total = data.total || 0;
        const pages = data.pages || 1;

        if (sessions.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4"><i class="bi bi-inbox" style="font-size:1.5rem;"></i><p class="mt-2 mb-0">No sessions found</p></td></tr>';
            document.getElementById('sessions-info').textContent = '0 sessions';
            document.getElementById('sessions-pagination').innerHTML = '';
            return;
        }

        let html = '';
        sessions.forEach(function(s) {
            const badge = statusBadges[s.status] || '<span class="badge bg-secondary">' + escapeHtml(s.status) + '</span>';
            const sentimentBadge = sentimentBadges[s.sentiment] || sentimentBadges['neutral'];
            const agentCol = s.agent_name ? escapeHtml(s.agent_name) : '<span class="text-muted">-</span>';
            const subjectCol = s.subject ? escapeHtml(s.subject) : '<span class="text-muted">-</span>';
            const viewUrl = s.has_agent ? '/livechat/chat/' + s.id : '/livechat/viewsession/' + s.id;

            html += '<tr>' +
                '<td><div>' + escapeHtml(s.email) + '</div>' +
                (s.order_name ? '<small class="text-muted"><i class="bi bi-box-seam me-1"></i>' + escapeHtml(s.order_name) + '</small>' : '') + '</td>' +
                '<td><small>' + subjectCol + '</small></td>' +
                '<td>' + badge + '</td>' +
                '<td>' + sentimentBadge + '</td>' +
                '<td>' + agentCol + '</td>' +
                '<td>' + s.message_count + '</td>' +
                '<td><small>' + escapeHtml(s.last_message_at || s.created_at || '') + '</small></td>' +
                '<td><a href="' + viewUrl + '" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye me-1"></i>View</a></td>' +
                '</tr>';
        });
        tbody.innerHTML = html;

        document.getElementById('sessions-info').textContent = 'Showing ' + sessions.length + ' of ' + total + ' sessions';

        // Pagination
        const pag = document.getElementById('sessions-pagination');
        if (pages <= 1) { pag.innerHTML = ''; return; }
        let pagHtml = '';
        pagHtml += '<li class="page-item ' + (sessionsPage <= 1 ? 'disabled' : '') + '">' +
            '<a class="page-link" href="#" data-page="' + (sessionsPage - 1) + '">&laquo;</a></li>';
        for (let i = 1; i <= Math.min(pages, 10); i++) {
            pagHtml += '<li class="page-item ' + (i === sessionsPage ? 'active' : '') + '">' +
                '<a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
        }
        pagHtml += '<li class="page-item ' + (sessionsPage >= pages ? 'disabled' : '') + '">' +
            '<a class="page-link" href="#" data-page="' + (sessionsPage + 1) + '">&raquo;</a></li>';
        pag.innerHTML = pagHtml;

        pag.querySelectorAll('a[data-page]').forEach(function(a) {
            a.addEventListener('click', function(e) {
                e.preventDefault();
                const p = parseInt(this.dataset.page);
                if (p >= 1 && p <= pages) {
                    sessionsPage = p;
                    loadSessions();
                }
            });
        });
    }
})();
</script>
