<?php
/**
 * Live Chat - Single Chat View
 *
 * Agent-side view of a single customer conversation.
 * Features: chat bubbles, message polling, form sending, soundboard, resolve modal with KB capture,
 * typing indicator, sound notifications, toast notifications.
 *
 * Variables: $session (array), $messages (array), $agent (array), $member (array)
 */
$sessionData = $session ?? [];
$sessionId = (int)($sessionData['id'] ?? 0);
$customerEmail = $sessionData['email'] ?? 'Unknown';
$context = $sessionData['context'] ?? [];
$order = $context['verified_order'] ?? null;
$escalationReason = $context['escalation_reason'] ?? '';
$chatMessages = $messages ?? [];
$agentData = $agent ?? [];
$isResolved = ($sessionData['verification_status'] ?? '') === 'resolved';
$readOnly = $readOnly ?? false;
$canTakeover = $canTakeover ?? false;
?>
<style>
.chat-bubble { max-width: 75%; border-radius: 12px; padding: 0.6rem 0.9rem; position: relative; }
.chat-bubble-user { background: rgba(13,110,253,0.08); border: 1px solid rgba(13,110,253,0.2); }
.chat-bubble-agent { background: rgba(25,135,84,0.08); border: 1px solid rgba(25,135,84,0.2); }
.chat-bubble-bot { background: rgba(111,66,193,0.08); border: 1px solid rgba(111,66,193,0.2); }
.chat-bubble .bubble-role { font-size: 0.75rem; font-weight: 600; }
.chat-bubble .bubble-time { font-size: 0.7rem; }
.chat-bubble .bubble-content { font-size: 0.9rem; line-height: 1.4; white-space: pre-wrap; word-break: break-word; }
.chat-system { font-size: 0.8rem; color: #6c757d; font-style: italic; }
.chat-avatar { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
.typing-indicator { display: none; }
.typing-indicator.active { display: flex; }
.typing-dots span { width: 6px; height: 6px; background: #6c757d; border-radius: 50%; display: inline-block; margin: 0 2px; animation: typingBounce 1.2s infinite; }
.typing-dots span:nth-child(2) { animation-delay: 0.2s; }
.typing-dots span:nth-child(3) { animation-delay: 0.4s; }
@keyframes typingBounce { 0%,80%,100% { transform: translateY(0); } 40% { transform: translateY(-6px); } }
</style>

<div class="container-fluid py-4" data-assist-page="livechat/chat" data-assist-purpose="Agent view of a single customer chat">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
            <a href="/livechat" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
            <div>
                <h5 class="mb-0"><?= htmlspecialchars($customerEmail) ?></h5>
                <small class="text-muted">Session #<?= $sessionId ?><?= $readOnly ? ' <span class="badge bg-secondary ms-1">Monitoring</span>' : '' ?></small>
            </div>
        </div>
        <div class="d-flex gap-2">
            <?php if ($canTakeover): ?>
            <button class="btn btn-sm btn-warning" id="btn-takeover">
                <i class="bi bi-hand-index me-1"></i>Take Over
            </button>
            <?php endif; ?>
            <?php if (!$isResolved && !$readOnly): ?>
            <button class="btn btn-sm btn-outline-success" id="btn-resolve">
                <i class="bi bi-check-circle me-1"></i>Resolve
            </button>
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="bi bi-arrow-left-right me-1"></i>Transfer
                </button>
                <ul class="dropdown-menu dropdown-menu-end" id="transfer-menu">
                    <li class="dropdown-item text-muted">Loading agents...</li>
                </ul>
            </div>
            <?php elseif ($isResolved): ?>
            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Resolved</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3">
        <!-- Conversation -->
        <div class="col-lg-8">
            <div class="card" style="height: calc(100vh - 200px); display: flex; flex-direction: column;">
                <div class="card-body px-3 py-2" style="flex: 1; overflow-y: auto;" id="messages-container">
                    <!-- Messages rendered here -->
                </div>
                <!-- Typing indicator -->
                <div class="typing-indicator align-items-center gap-2 px-3 py-2 border-top" id="typing-indicator">
                    <small class="text-muted">Customer is typing</small>
                    <div class="typing-dots"><span></span><span></span><span></span></div>
                </div>
                <?php if (!$isResolved && !$readOnly): ?>
                <div class="card-footer p-3">
                    <form id="reply-form" class="d-flex gap-2">
                        <textarea class="form-control" id="reply-input" rows="2"
                            placeholder="Type your reply..." style="resize: none;"></textarea>
                        <!-- Send Form dropdown -->
                        <div class="dropdown align-self-end">
                            <button type="button" class="btn btn-outline-info dropdown-toggle" data-bs-toggle="dropdown"
                                id="btn-send-form" title="Send a form to the customer">
                                <i class="bi bi-ui-checks"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" id="form-menu">
                                <li class="dropdown-item text-muted">Loading forms...</li>
                            </ul>
                        </div>
                        <button type="submit" class="btn btn-primary align-self-end">
                            <i class="bi bi-send-fill"></i>
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Customer Info -->
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-person me-2"></i>Customer Info</div>
                <div class="card-body">
                    <div class="mb-2">
                        <small class="text-muted d-block">Email</small>
                        <strong><?= htmlspecialchars($customerEmail) ?></strong>
                    </div>
                    <?php if ($order): ?>
                    <div class="mb-2">
                        <small class="text-muted d-block">Order</small>
                        <strong><?= htmlspecialchars($order['name'] ?? 'N/A') ?></strong>
                    </div>
                    <?php if (!empty($order['total'])): ?>
                    <div class="mb-2">
                        <small class="text-muted d-block">Total</small>
                        <strong><?= htmlspecialchars($order['total'] ?? '') ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($order['status'])): ?>
                    <div class="mb-2">
                        <small class="text-muted d-block">Status</small>
                        <span class="badge bg-<?= ($order['status'] ?? '') === 'FULFILLED' ? 'success' : 'warning' ?>">
                            <?= htmlspecialchars($order['status'] ?? 'Unknown') ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($escalationReason): ?>
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-exclamation-triangle me-2"></i>Escalation</div>
                <div class="card-body">
                    <p class="mb-0"><?= htmlspecialchars($escalationReason) ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Quick Replies / Soundboard -->
            <?php if (!$isResolved && !$readOnly): ?>
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-lightning me-2"></i>Quick Replies</span>
                    <button class="btn btn-sm btn-outline-secondary" id="btn-manage-snippets" title="Manage snippets">
                        <i class="bi bi-gear"></i>
                    </button>
                </div>
                <div class="card-body p-2" id="snippets-container" style="max-height: 300px; overflow-y: auto;">
                    <div class="text-center py-2"><div class="spinner-border spinner-border-sm text-muted"></div></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Session Info -->
            <div class="card">
                <div class="card-header"><i class="bi bi-info-circle me-2"></i>Session</div>
                <div class="card-body">
                    <div class="mb-2">
                        <small class="text-muted d-block">Created</small>
                        <?= htmlspecialchars($sessionData['created_at'] ?? '') ?>
                    </div>
                    <div class="mb-2">
                        <small class="text-muted d-block">Escalated</small>
                        <?= htmlspecialchars($sessionData['escalated_at'] ?? 'N/A') ?>
                    </div>
                    <?php if ($isResolved): ?>
                    <div class="mb-2">
                        <small class="text-muted d-block">Resolved</small>
                        <?= htmlspecialchars($sessionData['resolved_at'] ?? '') ?>
                    </div>
                    <?php endif; ?>
                    <div>
                        <small class="text-muted d-block">Messages</small>
                        <span id="message-count"><?= count($chatMessages) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toast-container"></div>

<!-- Resolve Modal -->
<?php if (!$isResolved && !$readOnly): ?>
<div class="modal fade" id="resolveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Resolve Chat</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="resolve-summary" class="form-label">Resolution Summary</label>
                    <textarea class="form-control" id="resolve-summary" rows="3"
                        placeholder="Briefly describe how this was resolved..."></textarea>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="resolve-capture-kb" checked>
                    <label class="form-check-label" for="resolve-capture-kb">
                        <i class="bi bi-book me-1"></i>Save to AI knowledge base
                    </label>
                    <div class="form-text">Resolution patterns help the AI chatbot learn and improve.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="btn-resolve-confirm">
                    <i class="bi bi-check-circle me-1"></i>Resolve
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Manage Snippets Modal -->
<div class="modal fade" id="snippetsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-lightning me-2"></i>Manage Personal Snippets</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="snippets-manage-list" class="mb-3">
                    <!-- Personal snippets listed here -->
                </div>
                <hr>
                <h6>Add New Snippet</h6>
                <div class="mb-2">
                    <input type="text" class="form-control form-control-sm" id="snippet-new-title" placeholder="Title (e.g. Shipping delay)">
                </div>
                <div class="mb-2">
                    <textarea class="form-control form-control-sm" id="snippet-new-content" rows="2" placeholder="Message content..."></textarea>
                </div>
                <div class="mb-2">
                    <input type="text" class="form-control form-control-sm" id="snippet-new-category" placeholder="Category (e.g. Order)" value="General">
                </div>
                <button class="btn btn-sm btn-primary" id="btn-snippet-add">
                    <i class="bi bi-plus-lg me-1"></i>Add Snippet
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function() {
    'use strict';

    const SESSION_ID = <?= $sessionId ?>;
    const IS_RESOLVED = <?= $isResolved ? 'true' : 'false' ?>;
    const READ_ONLY = <?= ($readOnly ?? false) ? 'true' : 'false' ?>;
    const messagesContainer = document.getElementById('messages-container');
    let serverMessageCount = 0;
    let initialMessages = <?= json_encode($chatMessages) ?>;
    let snippetsData = { system: [], personal: [] };

    // ======================================================================
    // TOAST NOTIFICATIONS
    // ======================================================================
    function showToast(message, type) {
        type = type || 'info';
        const container = document.getElementById('toast-container');
        if (!container) return;
        const id = 'toast-' + Date.now();
        const colors = { success: 'text-bg-success', danger: 'text-bg-danger', warning: 'text-bg-warning', info: 'text-bg-info' };
        const cls = colors[type] || 'text-bg-secondary';
        const html = '<div id="' + id + '" class="toast ' + cls + '" role="alert">' +
            '<div class="d-flex"><div class="toast-body">' + escapeHtml(message) + '</div>' +
            '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
            '</div></div>';
        container.insertAdjacentHTML('beforeend', html);
        const el = document.getElementById(id);
        const toast = new bootstrap.Toast(el, { delay: 4000 });
        toast.show();
        el.addEventListener('hidden.bs.toast', function() { el.remove(); });
    }

    // ======================================================================
    // SOUND NOTIFICATION
    // ======================================================================
    let notifSound = null;
    try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        notifSound = function() {
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.frequency.value = 800;
            osc.type = 'sine';
            gain.gain.setValueAtTime(0.3, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.3);
            osc.start(audioCtx.currentTime);
            osc.stop(audioCtx.currentTime + 0.3);
        };
    } catch(e) {}

    function playNotification() {
        if (notifSound) { try { notifSound(); } catch(e) {} }
    }

    // ======================================================================
    // UTILITIES
    // ======================================================================
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    function formatTime(ts) {
        if (!ts) return '';
        // Try to show just HH:MM if today
        try {
            const d = new Date(ts.replace(' ', 'T'));
            const now = new Date();
            if (d.toDateString() === now.toDateString()) {
                return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            }
            return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' +
                   d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } catch(e) { return ts; }
    }

    // ======================================================================
    // CHAT BUBBLE RENDERING
    // ======================================================================
    const roleConfig = {
        user:      { label: 'Customer', icon: 'bi-person-circle',  color: '#0d6efd', cls: 'chat-bubble-user',  align: 'start' },
        agent:     { label: 'Agent',    icon: 'bi-headset',        color: '#198754', cls: 'chat-bubble-agent', align: 'end' },
        assistant: { label: 'Ava (Bot)',icon: 'bi-robot',          color: '#6f42c1', cls: 'chat-bubble-bot',   align: 'end' },
        system:    { label: 'System',   icon: 'bi-info-circle',    color: '#6c757d', cls: '',                  align: 'center' }
    };

    function renderMessage(msg) {
        const role = msg.role || 'user';
        const cfg = roleConfig[role] || roleConfig.user;

        // System messages — centered, minimal
        if (role === 'system') {
            const div = document.createElement('div');
            div.className = 'text-center my-2 chat-system';
            div.innerHTML = '<i class="bi bi-info-circle me-1"></i>' + escapeHtml(msg.content);
            return div;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'd-flex mb-3 align-items-end gap-2 justify-content-' + cfg.align;

        const avatar = '<div class="chat-avatar" style="background:' + cfg.color + '20;color:' + cfg.color + ';"><i class="bi ' + cfg.icon + '"></i></div>';
        const bubble = document.createElement('div');
        bubble.className = 'chat-bubble ' + cfg.cls;

        let contentHtml = '<div class="d-flex justify-content-between align-items-center gap-2 mb-1">' +
            '<span class="bubble-role" style="color:' + cfg.color + ';">' + escapeHtml(cfg.label) + '</span>' +
            '<span class="bubble-time text-muted">' + formatTime(msg.timestamp) + '</span></div>' +
            '<div class="bubble-content">' + escapeHtml(msg.content) + '</div>';

        // Rich content rendering
        if (msg.rich && msg.rich.type && msg.rich.data) {
            if (msg.rich.type === 'intent_form') {
                const formAction = (msg.rich.data.form_action) || '';
                const label = formAction.replace(/_/g, ' ');
                contentHtml += '<span class="badge bg-info mt-1"><i class="bi bi-ui-checks me-1"></i>Sent form: ' + escapeHtml(label) + '</span>';
            }
            if (msg.rich.type === 'products' && msg.rich.data.items && msg.rich.data.items.length > 0) {
                let productsHtml = '<div class="mt-2" style="display:flex;flex-wrap:wrap;gap:8px;">';
                msg.rich.data.items.forEach(function(item) {
                    const imgHtml = item.image_url
                        ? '<img src="' + escapeHtml(item.image_url) + '" style="width:60px;height:60px;object-fit:cover;border-radius:4px;" alt="">'
                        : '<div style="width:60px;height:60px;background:#f0f0f0;border-radius:4px;display:flex;align-items:center;justify-content:center;"><i class="bi bi-box"></i></div>';
                    const price = item.price || '';
                    const viewUrl = item.url || (item.handle ? ('/products/' + item.handle) : '#');
                    productsHtml += '<div style="display:flex;gap:8px;padding:6px 8px;background:#f8f9fa;border-radius:6px;border:1px solid #dee2e6;min-width:200px;max-width:280px;">' +
                        imgHtml +
                        '<div style="flex:1;min-width:0;">' +
                            '<div style="font-weight:600;font-size:0.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(item.title || 'Product') + '</div>' +
                            (price ? '<div style="color:#198754;font-size:0.8rem;">' + escapeHtml(price) + '</div>' : '') +
                            '<a href="' + escapeHtml(viewUrl) + '" target="_blank" style="font-size:0.75rem;">View</a>' +
                        '</div>' +
                    '</div>';
                });
                productsHtml += '</div>';
                contentHtml += productsHtml;
            }
            if (msg.rich.type === 'order' && msg.rich.data) {
                const d = msg.rich.data;
                const fulfillment = (d.fulfillment || d.fulfillment_status || '').toLowerCase();
                const statusColor = fulfillment.includes('fulfilled') || fulfillment.includes('delivered') ? '#198754' : '#ffc107';
                let orderHtml = '<div class="mt-2 p-2" style="background:#f8f9fa;border-radius:6px;border:1px solid #dee2e6;">' +
                    '<div class="d-flex justify-content-between align-items-center">' +
                        '<strong>' + escapeHtml(d.name || 'Order') + '</strong>' +
                        '<span class="badge" style="background:' + statusColor + ';">' + escapeHtml(d.status || fulfillment || 'Processing') + '</span>' +
                    '</div>';
                if (d.tracking) orderHtml += '<div class="mt-1" style="font-size:0.85rem;"><i class="bi bi-truck me-1"></i>' + escapeHtml(d.tracking) + '</div>';
                if (d.items && d.items.length > 0) {
                    d.items.forEach(function(item) {
                        orderHtml += '<div style="font-size:0.85rem;">&bull; ' + escapeHtml(item.title || item) + (item.quantity ? ' x' + item.quantity : '') + '</div>';
                    });
                }
                orderHtml += '</div>';
                contentHtml += orderHtml;
            }
        }

        bubble.innerHTML = contentHtml;

        if (cfg.align === 'start') {
            wrapper.innerHTML = avatar;
            wrapper.appendChild(bubble);
        } else {
            wrapper.appendChild(bubble);
            wrapper.insertAdjacentHTML('beforeend', avatar);
        }

        return wrapper;
    }

    // Render initial messages
    function renderAllMessages(msgs) {
        messagesContainer.innerHTML = '';
        if (msgs.length === 0) {
            messagesContainer.innerHTML = '<div class="text-center text-muted py-5"><i class="bi bi-chat-left" style="font-size:2rem;"></i><p class="mt-2">No messages yet</p></div>';
        } else {
            msgs.forEach(function(msg) {
                messagesContainer.appendChild(renderMessage(msg));
            });
        }
        serverMessageCount = msgs.length;
        const countEl = document.getElementById('message-count');
        if (countEl) countEl.textContent = msgs.length;
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    renderAllMessages(initialMessages);

    // ======================================================================
    // REPLY FORM
    // ======================================================================
    const replyForm = document.getElementById('reply-form');
    const replyInput = document.getElementById('reply-input');

    if (replyForm) {
        replyForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const message = replyInput.value.trim();
            if (!message) return;

            replyInput.value = '';
            replyInput.disabled = true;

            try {
                const resp = await fetch('/livechat/reply', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ session_id: SESSION_ID, message: message })
                });
                const data = await resp.json();
                if (!data.success) {
                    showToast(data.error || 'Failed to send reply', 'danger');
                }
            } catch (err) {
                showToast('Error sending reply', 'danger');
            }

            replyInput.disabled = false;
            replyInput.focus();
        });

        // Enter to send, shift+enter for new line
        replyInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                replyForm.dispatchEvent(new Event('submit'));
            }
        });

        // Typing indicator — notify server when agent is typing
        let typingTimeout = null;
        replyInput.addEventListener('input', function() {
            fetch('/livechat/typing', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ session_id: SESSION_ID, is_typing: true })
            }).catch(function(){});
            clearTimeout(typingTimeout);
            typingTimeout = setTimeout(function() {
                fetch('/livechat/typing', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ session_id: SESSION_ID, is_typing: false })
                }).catch(function(){});
            }, 5000);
        });
    }

    // ======================================================================
    // TAKEOVER
    // ======================================================================
    const takeoverBtn = document.getElementById('btn-takeover');
    if (takeoverBtn) {
        takeoverBtn.addEventListener('click', async function() {
            if (!confirm('Take over this conversation from the bot? The customer will see you joined.')) return;
            takeoverBtn.disabled = true;
            takeoverBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Taking over...';
            try {
                const resp = await fetch('/livechat/takeover', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ session_id: SESSION_ID })
                });
                const data = await resp.json();
                if (data.success) {
                    showToast('You have taken over this chat!', 'success');
                    window.location.href = '/livechat/chat/' + SESSION_ID;
                } else {
                    showToast(data.error || 'Takeover failed', 'danger');
                    takeoverBtn.disabled = false;
                    takeoverBtn.innerHTML = '<i class="bi bi-hand-index me-1"></i>Take Over';
                }
            } catch (err) {
                showToast('Error taking over chat', 'danger');
                takeoverBtn.disabled = false;
                takeoverBtn.innerHTML = '<i class="bi bi-hand-index me-1"></i>Take Over';
            }
        });
    }

    // ======================================================================
    // RESOLVE MODAL
    // ======================================================================
    const resolveBtn = document.getElementById('btn-resolve');
    if (resolveBtn) {
        resolveBtn.addEventListener('click', function() {
            const modal = new bootstrap.Modal(document.getElementById('resolveModal'));
            modal.show();
        });
    }

    const resolveConfirmBtn = document.getElementById('btn-resolve-confirm');
    if (resolveConfirmBtn) {
        resolveConfirmBtn.addEventListener('click', async function() {
            const summary = document.getElementById('resolve-summary').value.trim();
            const captureKnowledge = document.getElementById('resolve-capture-kb').checked;

            resolveConfirmBtn.disabled = true;
            resolveConfirmBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Resolving...';

            try {
                const resp = await fetch('/livechat/resolve', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        session_id: SESSION_ID,
                        summary: summary,
                        capture_knowledge: captureKnowledge
                    })
                });
                const data = await resp.json();
                if (data.success) {
                    window.location.href = '/livechat';
                } else {
                    showToast(data.error || 'Failed to resolve', 'danger');
                    resolveConfirmBtn.disabled = false;
                    resolveConfirmBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Resolve';
                }
            } catch (err) {
                showToast('Error resolving chat', 'danger');
                resolveConfirmBtn.disabled = false;
                resolveConfirmBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Resolve';
            }
        });
    }

    // ======================================================================
    // SEND FORM DROPDOWN
    // ======================================================================
    async function loadFormMenu() {
        const menu = document.getElementById('form-menu');
        if (!menu) return;

        try {
            const resp = await fetch('/livechat/intents/' + SESSION_ID);
            const data = await resp.json();
            if (data.success && data.data && data.data.intents) {
                menu.innerHTML = '';
                if (data.data.intents.length === 0) {
                    menu.innerHTML = '<li class="dropdown-item text-muted">No forms available</li>';
                    return;
                }
                data.data.intents.forEach(function(intent) {
                    const li = document.createElement('li');
                    const a = document.createElement('a');
                    a.className = 'dropdown-item';
                    a.href = '#';
                    a.innerHTML = '<i class="bi bi-ui-checks me-2"></i>' + escapeHtml(intent.label) +
                        ' <small class="text-muted">(' + intent.field_count + ' fields)</small>';
                    a.addEventListener('click', function(e) {
                        e.preventDefault();
                        sendFormToCustomer(intent.key, intent.label);
                    });
                    li.appendChild(a);
                    menu.appendChild(li);
                });
            }
        } catch (err) {
            menu.innerHTML = '<li class="dropdown-item text-muted">Error loading forms</li>';
        }
    }

    async function sendFormToCustomer(intentKey, label) {
        if (!confirm('Send "' + label + '" form to the customer?')) return;

        try {
            const resp = await fetch('/livechat/sendform', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ session_id: SESSION_ID, intent_key: intentKey })
            });
            const data = await resp.json();
            if (data.success) {
                showToast('Form sent to customer', 'success');
            } else {
                showToast(data.error || 'Failed to send form', 'danger');
            }
        } catch (err) {
            showToast('Error sending form', 'danger');
        }
    }

    // ======================================================================
    // SOUNDBOARD / QUICK REPLIES
    // ======================================================================
    async function loadSnippets() {
        const container = document.getElementById('snippets-container');
        if (!container) return;

        try {
            const resp = await fetch('/livechat/snippets');
            const data = await resp.json();
            if (data.success && data.data) {
                snippetsData = data.data;
                renderSnippets();
            }
        } catch (err) {
            container.innerHTML = '<div class="text-muted text-center small py-2">Error loading snippets</div>';
        }
    }

    function renderSnippets() {
        const container = document.getElementById('snippets-container');
        if (!container) return;

        const all = [...(snippetsData.system || []), ...(snippetsData.personal || [])];
        if (all.length === 0) {
            container.innerHTML = '<div class="text-muted text-center small py-2">No snippets yet. Click <i class="bi bi-gear"></i> to add.</div>';
            return;
        }

        // Group by category
        const groups = {};
        all.forEach(function(s) {
            const cat = s.category || 'General';
            if (!groups[cat]) groups[cat] = [];
            groups[cat].push(s);
        });

        let html = '';
        for (const [cat, items] of Object.entries(groups)) {
            html += '<div class="mb-2"><small class="text-muted fw-bold text-uppercase">' + escapeHtml(cat) + '</small></div>';
            items.forEach(function(s) {
                const icon = s.is_system ? 'bi-building' : 'bi-person';
                html += '<button class="btn btn-sm btn-outline-secondary w-100 text-start mb-1 snippet-btn" ' +
                    'data-content="' + escapeHtml(s.content) + '" title="' + escapeHtml(s.content) + '">' +
                    '<i class="bi ' + icon + ' me-1"></i>' + escapeHtml(s.title) + '</button>';
            });
        }

        container.innerHTML = html;

        // Bind click handlers
        container.querySelectorAll('.snippet-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const content = btn.dataset.content;
                if (replyInput) {
                    replyInput.value = content;
                    replyInput.focus();
                    replyForm.dispatchEvent(new Event('submit'));
                }
            });
        });
    }

    // Manage snippets modal
    const manageBtn = document.getElementById('btn-manage-snippets');
    if (manageBtn) {
        manageBtn.addEventListener('click', function() {
            renderManageSnippets();
            const modal = new bootstrap.Modal(document.getElementById('snippetsModal'));
            modal.show();
        });
    }

    function renderManageSnippets() {
        const list = document.getElementById('snippets-manage-list');
        if (!list) return;

        const personal = snippetsData.personal || [];
        if (personal.length === 0) {
            list.innerHTML = '<div class="text-muted small">No personal snippets yet.</div>';
            return;
        }

        let html = '';
        personal.forEach(function(s) {
            html += '<div class="d-flex align-items-start gap-2 mb-2 p-2 border rounded" data-snippet-id="' + s.id + '">' +
                '<div class="flex-grow-1">' +
                '<strong class="small">' + escapeHtml(s.title) + '</strong>' +
                '<div class="small text-muted">' + escapeHtml(s.content.substring(0, 80)) + (s.content.length > 80 ? '...' : '') + '</div>' +
                '</div>' +
                '<button class="btn btn-sm btn-outline-danger snippet-delete-btn" data-id="' + s.id + '" title="Delete">' +
                '<i class="bi bi-trash"></i></button>' +
                '</div>';
        });
        list.innerHTML = html;

        list.querySelectorAll('.snippet-delete-btn').forEach(function(btn) {
            btn.addEventListener('click', async function() {
                const snippetId = parseInt(btn.dataset.id);
                if (!confirm('Delete this snippet?')) return;
                try {
                    await fetch('/livechat/snippets', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete', snippet_id: snippetId })
                    });
                    await loadSnippets();
                    renderManageSnippets();
                } catch (err) {
                    showToast('Error deleting snippet', 'danger');
                }
            });
        });
    }

    // Add new snippet
    const addSnippetBtn = document.getElementById('btn-snippet-add');
    if (addSnippetBtn) {
        addSnippetBtn.addEventListener('click', async function() {
            const title = document.getElementById('snippet-new-title').value.trim();
            const content = document.getElementById('snippet-new-content').value.trim();
            const category = document.getElementById('snippet-new-category').value.trim() || 'General';

            if (!title || !content) {
                showToast('Title and content are required.', 'warning');
                return;
            }

            addSnippetBtn.disabled = true;
            try {
                const resp = await fetch('/livechat/snippets', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'create', title, content, category })
                });
                const data = await resp.json();
                if (data.success) {
                    document.getElementById('snippet-new-title').value = '';
                    document.getElementById('snippet-new-content').value = '';
                    await loadSnippets();
                    renderManageSnippets();
                    showToast('Snippet created!', 'success');
                } else {
                    showToast(data.error || 'Failed to create snippet', 'danger');
                }
            } catch (err) {
                showToast('Error creating snippet', 'danger');
            }
            addSnippetBtn.disabled = false;
        });
    }

    // ======================================================================
    // TRANSFER
    // ======================================================================
    async function loadTransferTargets() {
        try {
            const resp = await fetch('/livechat/agents');
            const data = await resp.json();
            if (data.success && data.data && data.data.agents) {
                const menu = document.getElementById('transfer-menu');
                if (!menu) return;
                menu.innerHTML = '';
                data.data.agents.forEach(function(agent) {
                    if (agent.status === 'online') {
                        const li = document.createElement('li');
                        const a = document.createElement('a');
                        a.className = 'dropdown-item';
                        a.href = '#';
                        a.textContent = agent.display_name + ' (' + agent.active_chats + '/' + agent.max_concurrent + ')';
                        a.addEventListener('click', function(e) {
                            e.preventDefault();
                            transferTo(agent.id);
                        });
                        li.appendChild(a);
                        menu.appendChild(li);
                    }
                });
                if (menu.children.length === 0) {
                    menu.innerHTML = '<li class="dropdown-item text-muted">No online agents</li>';
                }
            }
        } catch (err) {}
    }

    async function transferTo(agentId) {
        if (!confirm('Transfer this chat?')) return;
        try {
            const resp = await fetch('/livechat/transfer', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ session_id: SESSION_ID, target_agent_id: agentId })
            });
            const data = await resp.json();
            if (data.success) {
                showToast('Chat transferred', 'success');
                setTimeout(function() { window.location.href = '/livechat'; }, 1000);
            } else {
                showToast(data.error || 'Transfer failed', 'danger');
            }
        } catch (err) {
            showToast('Error transferring chat', 'danger');
        }
    }

    // ======================================================================
    // INIT
    // ======================================================================
    if (!READ_ONLY) {
        loadTransferTargets();
        loadFormMenu();
        loadSnippets();
    }

    // Poll for new messages
    if (!IS_RESOLVED) {
        setInterval(async function() {
            try {
                const resp = await fetch('/livechat/poll/' + SESSION_ID + '?last_count=' + serverMessageCount);
                const data = await resp.json();
                if (data.success && data.data) {
                    if (data.data.messages && data.data.messages.length > 0) {
                        data.data.messages.forEach(function(msg) {
                            messagesContainer.appendChild(renderMessage(msg));
                            // Sound for new customer messages
                            if (msg.role === 'user') playNotification();
                        });
                        serverMessageCount = data.data.total_count;
                        const countEl = document.getElementById('message-count');
                        if (countEl) countEl.textContent = serverMessageCount;
                        messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    }
                    // If resolved externally, reload
                    if (data.data.status === 'resolved') {
                        showToast('Chat has been resolved', 'success');
                        setTimeout(function() { window.location.reload(); }, 1500);
                    }
                }
            } catch (err) {}
        }, 3000);
    }

    if (replyInput) replyInput.focus();
})();
</script>
