<?php
/**
 * Dapp Chat Widget View
 *
 * Chat interface for Shopify Chat Orchestrator.
 * Supports standalone and embed mode (iframe).
 *
 * Variables from controller:
 *   $slug       - Dapp slug
 *   $shop_name  - Shop display name
 *   $embed_mode - Boolean for iframe embedding
 *   $app_name   - App display name
 */

$embedMode = $embed_mode ?? false;
$shopName = $shop_name ?? 'Store';
$appSlug = $slug ?? '';
$botName = $bot_name ?? null;
$botGreeting = $bot_greeting ?? null;
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat - <?= htmlspecialchars($shopName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0d6efd 0%, #6610f2 100%);
            --chat-bg: #1a1a2e;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; padding: 0; }
        body {
            background: <?= $embedMode ? 'var(--chat-bg)' : 'linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%)' ?>;
            display: flex;
            flex-direction: column;
            <?php if (!$embedMode): ?>
            padding: 1rem;
            <?php endif; ?>
        }
        .chat-container {
            <?php if ($embedMode): ?>
            height: 100vh;
            <?php else: ?>
            max-width: 500px;
            height: calc(100vh - 2rem);
            margin: 0 auto;
            <?php endif; ?>
            display: flex;
            flex-direction: column;
            width: 100%;
        }
        .chat-header {
            background: var(--primary-gradient);
            padding: 1rem;
            flex-shrink: 0;
            <?php if (!$embedMode): ?>
            border-radius: 16px 16px 0 0;
            <?php endif; ?>
        }
        .chat-header h5 { margin: 0; font-weight: 600; }
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
            background: rgba(33, 37, 41, 0.95);
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .message {
            max-width: 85%;
            padding: 0.75rem 1rem;
            border-radius: 16px;
            animation: messageIn 0.2s ease-out;
        }
        @keyframes messageIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .message-user {
            background: var(--primary-gradient);
            color: white;
            align-self: flex-end;
            border-bottom-right-radius: 4px;
        }
        .message-assistant {
            background: rgba(255, 255, 255, 0.1);
            color: #e9ecef;
            align-self: flex-start;
            border-bottom-left-radius: 4px;
        }
        .message-system {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
            align-self: center;
            text-align: center;
            font-size: 0.9rem;
            border-radius: 8px;
        }
        .message-time {
            font-size: 0.7rem;
            opacity: 0.6;
            margin-top: 0.25rem;
        }
        .message-assistant p { margin: 0 0 0.5rem 0; }
        .message-assistant p:last-child { margin-bottom: 0; }
        .message-assistant ul, .message-assistant ol { margin: 0.25rem 0; padding-left: 1.25rem; }
        .message-assistant li { margin-bottom: 0.15rem; }
        .message-assistant strong { color: #fff; }
        .message-assistant a { color: #6ea8fe; }
        .chat-input-area {
            background: rgba(33, 37, 41, 0.98);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding: 1rem;
            flex-shrink: 0;
            <?php if (!$embedMode): ?>
            border-radius: 0 0 16px 16px;
            <?php endif; ?>
        }
        .chat-textarea {
            flex: 1;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            color: white;
            resize: none;
            min-height: 44px;
            max-height: 120px;
            font-size: 1rem;
            line-height: 1.4;
        }
        .chat-textarea:focus {
            outline: none;
            border-color: #0d6efd;
            background: rgba(255, 255, 255, 0.08);
        }
        .btn-send {
            background: var(--primary-gradient);
            border: none;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: transform 0.15s ease;
            color: white;
        }
        .btn-send:hover:not(:disabled) { transform: scale(1.05); }
        .btn-send:disabled { opacity: 0.5; }
        .btn-send i { font-size: 1.1rem; }
        .typing-indicator {
            display: none;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            align-self: flex-start;
            border-bottom-left-radius: 4px;
        }
        .typing-indicator.show { display: flex; }
        .typing-dots { display: flex; gap: 4px; }
        .typing-dot {
            width: 8px;
            height: 8px;
            background: #6c757d;
            border-radius: 50%;
            animation: typingDot 1.4s infinite;
        }
        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }
        @keyframes typingDot {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-8px); }
        }
        .quick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            padding: 0.75rem 0;
        }
        .quick-action {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #adb5bd;
            font-size: 0.85rem;
            padding: 0.4rem 0.75rem;
            border-radius: 1rem;
            cursor: pointer;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .quick-action:hover {
            background: rgba(13, 110, 253, 0.2);
            border-color: #0d6efd;
            color: white;
        }
        .suggested-actions {
            justify-content: flex-start;
            padding: 0.25rem 0 0.5rem 0;
        }
        .suggested-actions .quick-action {
            font-size: 0.8rem;
            padding: 0.3rem 0.6rem;
        }
        .welcome-message {
            text-align: center;
            padding: 2rem 1rem;
            color: #adb5bd;
        }
        .welcome-message i { font-size: 3rem; color: #0d6efd; margin-bottom: 1rem; }
        .escalation-banner {
            background: rgba(255, 193, 7, 0.15);
            border: 1px solid rgba(255, 193, 7, 0.3);
            padding: 0.75rem 1rem;
            text-align: center;
            display: none;
        }
        .escalation-banner.show { display: block; }

        /* Rich response cards */
        .rich-products {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }
        .product-card {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 12px;
            padding: 0.75rem;
            display: flex;
            gap: 0.75rem;
            align-items: center;
            transition: border-color 0.15s;
        }
        .product-card:hover { border-color: #0d6efd; }
        .product-card img {
            width: 56px;
            height: 56px;
            object-fit: cover;
            border-radius: 8px;
            background: rgba(255,255,255,0.05);
        }
        .product-card .product-info { flex: 1; min-width: 0; }
        .product-card .product-title {
            font-weight: 600;
            font-size: 0.9rem;
            color: #e9ecef;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .product-card .product-price {
            color: #6ea8fe;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .product-card .product-stock {
            font-size: 0.75rem;
            color: #6c757d;
        }
        .product-card .btn-view {
            background: rgba(13, 110, 253, 0.2);
            border: 1px solid rgba(13, 110, 253, 0.4);
            color: #6ea8fe;
            padding: 0.3rem 0.75rem;
            border-radius: 8px;
            font-size: 0.8rem;
            text-decoration: none;
            white-space: nowrap;
        }
        .product-card .btn-view:hover { background: rgba(13, 110, 253, 0.35); }

        .order-card {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 12px;
            padding: 1rem;
            margin-top: 0.75rem;
        }
        .order-card .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        .order-card .order-name { font-weight: 600; color: #e9ecef; }
        .order-card .order-status {
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-fulfilled { background: rgba(25, 135, 84, 0.25); color: #75b798; }
        .status-unfulfilled { background: rgba(255, 193, 7, 0.25); color: #ffda6a; }
        .status-paid { background: rgba(25, 135, 84, 0.25); color: #75b798; }
        .status-pending { background: rgba(255, 193, 7, 0.25); color: #ffda6a; }
        .order-card .order-detail {
            font-size: 0.85rem;
            color: #adb5bd;
            margin-bottom: 0.25rem;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 0.75rem;
        }
        .action-btn {
            background: rgba(13, 110, 253, 0.15);
            border: 1px solid rgba(13, 110, 253, 0.3);
            color: #6ea8fe;
            padding: 0.4rem 0.75rem;
            border-radius: 8px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.15s;
        }
        .action-btn:hover {
            background: rgba(13, 110, 253, 0.3);
            border-color: #0d6efd;
            color: white;
        }

        /* Intent form inputs */
        .intent-form input:focus,
        .intent-form select:focus,
        .intent-form textarea:focus {
            outline: none;
            border-color: #0d6efd !important;
            background: rgba(255,255,255,0.12) !important;
        }
        .intent-form input::placeholder,
        .intent-form textarea::placeholder { color: #6c757d; }
        .intent-form select { cursor: pointer; }
        .intent-form select option { background: #212529; color: #e9ecef; }
        .intent-form textarea { min-height: 60px; resize: vertical; }
        .intent-form .field-required::after { content: ' *'; color: #dc3545; font-size: 0.75rem; }

        /* Toast */
        .toast-container {
            position: fixed;
            bottom: 80px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 10001;
        }
        .chat-toast {
            background: rgba(33, 37, 41, 0.95);
            color: #e9ecef;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 10px;
            padding: 0.75rem 1.25rem;
            font-size: 0.9rem;
            animation: toastIn 0.3s ease-out;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        .chat-toast.error { border-left: 4px solid #dc3545; }
        @keyframes toastIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="chat-container">
        <!-- Header -->
        <div class="chat-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h5><i class="bi bi-chat-dots-fill me-2"></i><?= htmlspecialchars($shopName) ?></h5>
                    <small class="opacity-75"><?= $botName ? htmlspecialchars($botName) . ' - Your personal shopping assistant' : 'Ask me anything about our products and services' ?></small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-sm" style="background: rgba(255,255,255,0.15); border:none; color:white; border-radius: 50%; width:32px; height:32px; display:flex; align-items:center; justify-content:center;" id="btn-new-chat" title="Start new conversation">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Escalation Banner -->
        <div class="escalation-banner" id="escalation-banner">
            <i class="bi bi-person-fill-gear me-2"></i>
            <strong>Connecting to human support.</strong> A team member will respond shortly.
        </div>

        <!-- Messages -->
        <div class="chat-messages" id="chat-messages">
            <div class="welcome-message" id="welcome-message">
                <i class="bi bi-robot d-block"></i>
                <?php if ($botGreeting): ?>
                <p class="mb-0"><?= htmlspecialchars($botGreeting) ?></p>
                <?php else: ?>
                <h5>Welcome!</h5>
                <p class="mb-0">I'm here to help. Ask me about products, orders, returns, or anything else.</p>
                <?php endif; ?>
            </div>

            <!-- Typing indicator -->
            <div class="typing-indicator" id="typing-indicator">
                <div class="typing-dots">
                    <div class="typing-dot"></div>
                    <div class="typing-dot"></div>
                    <div class="typing-dot"></div>
                </div>
                <span class="text-muted small"><?= $botName ? htmlspecialchars($botName) . ' is thinking...' : 'Thinking...' ?></span>
            </div>
        </div>

        <!-- Input Area -->
        <div class="chat-input-area">
            <div class="quick-actions" id="quick-actions">
                <button class="quick-action" data-message="What products do you have?">
                    <i class="bi bi-search me-1"></i>Browse products
                </button>
                <button class="quick-action" data-message="I want to check my order status">
                    <i class="bi bi-box-seam me-1"></i>Track order
                </button>
                <button class="quick-action" data-message="I need help with a return">
                    <i class="bi bi-arrow-return-left me-1"></i>Returns
                </button>
                <button class="quick-action" data-message="I'd like to speak with a human agent">
                    <i class="bi bi-person me-1"></i>Talk to human
                </button>
            </div>
            <form id="chat-form" class="d-flex gap-2 align-items-end">
                <textarea
                    class="chat-textarea"
                    id="message-input"
                    placeholder="Type your message..."
                    rows="1"
                    maxlength="2000"
                ></textarea>
                <button type="submit" class="btn-send" id="btn-send">
                    <i class="bi bi-send-fill"></i>
                </button>
            </form>
        </div>
    </div>

    <div class="toast-container" id="toast-container"></div>

    <script>
    (function() {
        'use strict';

        const SLUG = '<?= htmlspecialchars($appSlug, ENT_QUOTES) ?>';
        const API_URL = '/dapp/' + SLUG + '/api/chat';
        const STORAGE_KEY = 'dapp_chat_' + SLUG;
        const embedMode = <?= $embedMode ? 'true' : 'false' ?>;
        const BOT_NAME = <?= $botName ? "'" . htmlspecialchars($botName, ENT_QUOTES) . "'" : 'null' ?>;
        const TYPING_TEXT = BOT_NAME ? BOT_NAME + ' is thinking...' : 'Thinking...';
        const SLOW_TEXT = BOT_NAME ? BOT_NAME + ' is still working on it...' : 'Still working on it...';

        const messagesEl = document.getElementById('chat-messages');
        const chatForm = document.getElementById('chat-form');
        const messageInput = document.getElementById('message-input');
        const sendBtn = document.getElementById('btn-send');
        const typingIndicator = document.getElementById('typing-indicator');
        const quickActionsEl = document.getElementById('quick-actions');
        const welcomeEl = document.getElementById('welcome-message');
        const escalationBanner = document.getElementById('escalation-banner');
        const newChatBtn = document.getElementById('btn-new-chat');

        let sessionToken = localStorage.getItem(STORAGE_KEY) || null;
        let isProcessing = false;
        let isEscalated = false;
        let pollInterval = null;
        let lastMessageCount = 0;

        // Sound notification for live agent messages
        let playNotifSound = null;
        try {
            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            playNotifSound = function() {
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

        // Configure marked for safe rendering
        if (typeof marked !== 'undefined') {
            marked.setOptions({ breaks: true, gfm: true });
        }

        // Auto-resize textarea
        messageInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });

        // Enter to send (shift+enter for new line)
        messageInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                chatForm.dispatchEvent(new Event('submit'));
            }
        });

        // Quick action buttons — call sendMessage directly
        quickActionsEl.addEventListener('click', function(e) {
            const btn = e.target.closest('.quick-action');
            if (!btn) return;
            const msg = btn.dataset.message;
            if (msg) {
                sendMessage(msg);
            }
        });

        // New chat button
        newChatBtn.addEventListener('click', function() {
            if (!confirm('Start a new conversation?')) return;
            sessionToken = null;
            localStorage.removeItem(STORAGE_KEY);
            isEscalated = false;
            if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
            lastMessageCount = 0;
            escalationBanner.classList.remove('show');
            escalationBanner.innerHTML = '<i class="bi bi-person-fill-gear me-2"></i><strong>Connecting to human support.</strong> A team member will respond shortly.';
            messageInput.disabled = false;
            sendBtn.disabled = false;
            messageInput.placeholder = 'Type your message...';

            // Clear messages
            const msgs = messagesEl.querySelectorAll('.message, .rich-products, .order-card, .action-buttons');
            msgs.forEach(function(m) { m.remove(); });

            // Show welcome + quick actions
            if (welcomeEl) welcomeEl.style.display = '';
            quickActionsEl.style.display = '';
            messageInput.focus();
        });

        // Form submit
        chatForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const message = messageInput.value.trim();
            if (message) {
                sendMessage(message);
            }
        });

        // Core send logic
        async function sendMessage(message) {
            if (!message || isProcessing) return;

            // Hide welcome + quick actions
            if (welcomeEl) welcomeEl.style.display = 'none';
            quickActionsEl.style.display = 'none';

            // Show user message
            addMessage('user', message);
            messageInput.value = '';
            messageInput.style.height = 'auto';

            // Show typing indicator
            isProcessing = true;
            sendBtn.disabled = true;
            messageInput.disabled = true;
            showTyping(true);

            // Slow-request hint after 10s
            const slowTimer = setTimeout(function() {
                const hint = document.getElementById('typing-indicator');
                if (hint) {
                    const span = hint.querySelector('span');
                    if (span) span.textContent = SLOW_TEXT;
                }
            }, 10000);

            // Abort controller with 90s timeout
            const controller = new AbortController();
            const timeout = setTimeout(function() { controller.abort(); }, 90000);

            try {
                const resp = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        message: message,
                        session_token: sessionToken
                    }),
                    signal: controller.signal
                });

                clearTimeout(timeout);
                clearTimeout(slowTimer);

                if (!resp.ok) {
                    console.error('Chat HTTP error:', resp.status, resp.statusText);
                    showTyping(false);
                    addMessage('system', 'Oops, I hit a snag! Let me try again in a moment.');
                    isProcessing = false;
                    sendBtn.disabled = false;
                    messageInput.disabled = false;
                    messageInput.focus();
                    return;
                }

                const text = await resp.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseErr) {
                    console.error('Chat JSON parse error:', parseErr, 'Raw:', text.substring(0, 500));
                    showTyping(false);
                    addMessage('system', 'Something got mixed up. Could you try that again?');
                    isProcessing = false;
                    sendBtn.disabled = false;
                    messageInput.disabled = false;
                    messageInput.focus();
                    return;
                }

                if (data.success) {
                    // Save session token
                    sessionToken = data.session_token;
                    localStorage.setItem(STORAGE_KEY, sessionToken);

                    showTyping(false);

                    // Show assistant response (may be empty during live chat)
                    if (data.response && data.response.text) {
                        addMessage('assistant', data.response.text);
                    }

                    // Render rich data if present
                    if (data.response && data.response.rich) {
                        renderRichData(data.response.rich);
                    }

                    // Render suggested actions as clickable chips
                    if (data.suggested_actions && data.suggested_actions.length > 0) {
                        renderSuggestedActions(data.suggested_actions);
                    }

                    // Handle escalation
                    if (data.escalated && !isEscalated) {
                        handleEscalation();
                    }

                    // Notify parent window in embed mode
                    if (embedMode && window.parent !== window) {
                        window.parent.postMessage({
                            type: 'myctobot-chat-message',
                            intent: data.meta?.intent,
                            sentiment: data.meta?.sentiment,
                            escalated: data.escalated
                        }, '*');
                    }
                } else {
                    console.error('Chat API error:', data.error || 'unknown', data);
                    showTyping(false);
                    addMessage('system', data.error || 'Sorry, something went wrong. Please try again.');
                }
            } catch (err) {
                clearTimeout(timeout);
                clearTimeout(slowTimer);
                console.error('Chat fetch error:', err);
                showTyping(false);
                if (err.name === 'AbortError') {
                    addMessage('system', 'That took too long — let me try again for you.');
                } else {
                    addMessage('system', 'Hmm, I lost the connection. Could you try again?');
                }
            }

            // Reset typing hint text
            const hint = document.getElementById('typing-indicator');
            if (hint) {
                const span = hint.querySelector('span');
                if (span) span.textContent = TYPING_TEXT;
            }

            isProcessing = false;
            sendBtn.disabled = false;
            messageInput.disabled = false;
            messageInput.focus();
        }

        function addMessage(role, content) {
            const div = document.createElement('div');
            div.className = 'message message-' + role;

            if (role === 'assistant' && typeof marked !== 'undefined') {
                div.innerHTML = marked.parse(content || '');
            } else if (role === 'system') {
                div.textContent = content;
            } else {
                div.innerHTML = escapeHtml(content).replace(/\n/g, '<br>');
            }

            const time = document.createElement('div');
            time.className = 'message-time';
            time.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            div.appendChild(time);

            messagesEl.insertBefore(div, typingIndicator);
            scrollToBottom();
        }

        function renderRichData(rich) {
            if (!rich || !rich.type || !rich.data) return;

            if (rich.type === 'products' && rich.data.items && rich.data.items.length > 0) {
                const container = document.createElement('div');
                container.className = 'rich-products';

                rich.data.items.forEach(function(item) {
                    const card = document.createElement('div');
                    card.className = 'product-card';

                    let imgHtml = '';
                    if (item.image_url) {
                        imgHtml = '<img src="' + escapeHtml(item.image_url) + '" alt="' + escapeHtml(item.title || '') + '" loading="lazy">';
                    }

                    const price = item.price || '';
                    const stockText = item.available === false ? 'Out of stock' : (item.available === true ? 'In stock' : '');
                    const viewUrl = item.url || (item.handle ? ('/products/' + item.handle) : '#');

                    let cartHtml = '';
                    if (item.variant_id && item.available !== false) {
                        const cartUrl = item.store_url
                            ? item.store_url + '/cart/add?id=' + item.variant_id
                            : '/cart/add?id=' + item.variant_id;
                        cartHtml = '<a href="' + escapeHtml(cartUrl) + '" target="_blank" class="btn-view" style="background:rgba(25,135,84,0.2); border-color:rgba(25,135,84,0.4); color:#75b798;">Add to Cart</a>';
                    }

                    card.innerHTML =
                        imgHtml +
                        '<div class="product-info">' +
                            '<div class="product-title">' + escapeHtml(item.title || 'Product') + '</div>' +
                            (price ? '<div class="product-price">' + escapeHtml(price) + '</div>' : '') +
                            (stockText ? '<div class="product-stock">' + escapeHtml(stockText) + '</div>' : '') +
                        '</div>' +
                        '<div style="display:flex; flex-direction:column; gap:4px;">' +
                            '<a href="' + escapeHtml(viewUrl) + '" target="_blank" class="btn-view">View</a>' +
                            cartHtml +
                        '</div>';

                    container.appendChild(card);
                });

                messagesEl.insertBefore(container, typingIndicator);
                scrollToBottom();
            }

            if (rich.type === 'order' && rich.data) {
                const d = rich.data;
                const card = document.createElement('div');
                card.className = 'order-card';

                let statusClass = 'status-pending';
                const fulfillment = (d.fulfillment || d.fulfillment_status || '').toLowerCase();
                if (fulfillment.includes('fulfilled') || fulfillment.includes('delivered')) {
                    statusClass = 'status-fulfilled';
                }

                let html = '<div class="order-header">' +
                    '<span class="order-name">' + escapeHtml(d.name || 'Order') + '</span>' +
                    '<span class="order-status ' + statusClass + '">' + escapeHtml(d.status || fulfillment || 'Processing') + '</span>' +
                    '</div>';

                if (d.tracking) {
                    html += '<div class="order-detail"><i class="bi bi-truck me-1"></i>Tracking: ' + escapeHtml(d.tracking) + '</div>';
                }
                if (d.items && d.items.length > 0) {
                    html += '<div class="order-detail mt-2"><strong>Items:</strong></div>';
                    d.items.forEach(function(item) {
                        html += '<div class="order-detail">&bull; ' + escapeHtml(item.title || item) + (item.quantity ? ' x' + item.quantity : '') + '</div>';
                    });
                }

                card.innerHTML = html;
                messagesEl.insertBefore(card, typingIndicator);
                scrollToBottom();
            }

            // Generic intent form (order lookup, return, repair, transcript, etc.)
            if (rich.type === 'intent_form' && rich.data) {
                const formAction = rich.data.form_action || 'unknown';
                const formCard = document.createElement('div');
                formCard.className = 'order-card';
                formCard.style.borderColor = 'rgba(13, 110, 253, 0.3)';

                let formHtml = '<form class="intent-form">';
                // Pass next_intent as hidden field for chained flows (e.g. order lookup → return)
                if (rich.data.next_intent) {
                    formHtml += '<input type="hidden" name="next_intent" value="' + escapeHtml(rich.data.next_intent) + '">';
                }
                const fieldStyle = 'width:100%; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.2); border-radius: 8px; padding: 0.5rem 0.75rem; color: white; font-size: 0.9rem;';
                const prefill = rich.data.prefill || {};

                (rich.data.fields || []).forEach(function(field) {
                    const reqClass = field.required ? ' field-required' : '';
                    const reqAttr = field.required ? ' required' : '';
                    const preVal = prefill[field.name] || '';
                    formHtml += '<div style="margin-bottom: 0.75rem;">' +
                        '<label class="' + reqClass + '" style="font-size: 0.8rem; color: #adb5bd; display: block; margin-bottom: 0.25rem;">' + escapeHtml(field.label) + '</label>';

                    if (field.type === 'select' && field.options) {
                        formHtml += '<select name="' + escapeHtml(field.name) + '" style="' + fieldStyle + '"' + reqAttr + '>' +
                            '<option value="">— Select —</option>';
                        field.options.forEach(function(opt) {
                            const sel = (preVal === opt) ? ' selected' : '';
                            formHtml += '<option value="' + escapeHtml(opt) + '"' + sel + '>' + escapeHtml(opt) + '</option>';
                        });
                        formHtml += '</select>';
                    } else if (field.type === 'textarea') {
                        formHtml += '<textarea name="' + escapeHtml(field.name) + '" ' +
                            'placeholder="' + escapeHtml(field.placeholder || '') + '" ' +
                            'rows="3" style="' + fieldStyle + '"' + reqAttr + '>' + escapeHtml(preVal) + '</textarea>';
                    } else {
                        formHtml += '<input type="' + (field.type || 'text') + '" name="' + escapeHtml(field.name) + '" ' +
                            'value="' + escapeHtml(preVal) + '" ' +
                            'placeholder="' + escapeHtml(field.placeholder || '') + '" ' +
                            'style="' + fieldStyle + '"' + reqAttr + '>';
                    }
                    formHtml += '</div>';
                });

                if (rich.data.hint) {
                    formHtml += '<div style="font-size: 0.75rem; color: #6c757d; margin-bottom: 0.75rem;">' + escapeHtml(rich.data.hint) + '</div>';
                }
                formHtml += '<button type="submit" class="action-btn" style="width: 100%; text-align: center; padding: 0.6rem; font-weight: 600;">' +
                    '<i class="bi bi-send-fill me-1"></i>' + escapeHtml(rich.data.submit_label || 'Submit') +
                    '</button>';
                formHtml += '</form>';

                formCard.innerHTML = formHtml;

                // Handle form submission
                const form = formCard.querySelector('form');
                form.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const formData = Object.fromEntries(new FormData(form));

                    // Validate required fields
                    const fields = rich.data.fields || [];
                    let hasAny = false;
                    for (const field of fields) {
                        if (formData[field.name]) hasAny = true;
                        if (field.required && !formData[field.name]) {
                            showToast('Please fill in: ' + field.label, 'error');
                            return;
                        }
                    }
                    if (!hasAny) {
                        showToast('Please fill in at least one field.', 'error');
                        return;
                    }

                    // Disable form
                    const submitBtn = form.querySelector('button[type="submit"]');
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Processing...';

                    // Show summary of what was submitted
                    let parts = [];
                    for (const [k, v] of Object.entries(formData)) {
                        if (v) parts.push(v);
                    }
                    const userSummary = (rich.data.submit_label || formAction).replace(/_/g, ' ') + ': ' + parts.join(', ');
                    addMessage('user', userSummary);

                    showTyping(true);

                    try {
                        const payload = Object.assign({}, formData, {
                            form_action: formAction,
                            message: userSummary,
                            session_token: sessionToken
                        });

                        const resp = await fetch(API_URL, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload)
                        });

                        const data = await resp.json();
                        showTyping(false);

                        if (data.success && data.response && data.response.text) {
                            sessionToken = data.session_token;
                            localStorage.setItem(STORAGE_KEY, sessionToken);
                            addMessage('assistant', data.response.text);
                            if (data.response.rich) {
                                renderRichData(data.response.rich);
                            }
                        } else {
                            addMessage('system', data.error || 'Something went wrong. Please try again.');
                        }
                    } catch (err) {
                        console.error('Form action error:', err);
                        showTyping(false);
                        addMessage('system', 'Connection error. Please try again.');
                    }

                    // Remove form card after submission
                    formCard.remove();
                });

                messagesEl.insertBefore(formCard, typingIndicator);
                scrollToBottom();
                // Focus first input/select/textarea
                const firstField = formCard.querySelector('input, select, textarea');
                if (firstField) firstField.focus();
            }

            if (rich.type === 'actions' && rich.data.items && rich.data.items.length > 0) {
                const container = document.createElement('div');
                container.className = 'action-buttons';

                rich.data.items.forEach(function(item) {
                    const btn = document.createElement('button');
                    btn.className = 'action-btn';
                    btn.textContent = item.label || 'Action';
                    btn.addEventListener('click', function() {
                        if (item.action === 'escalate') {
                            handleEscalation();
                        } else if (item.message) {
                            sendMessage(item.message);
                        }
                    });
                    container.appendChild(btn);
                });

                messagesEl.insertBefore(container, typingIndicator);
                scrollToBottom();
            }
        }

        function renderSuggestedActions(actions) {
            // Remove any existing suggested actions
            const existing = messagesEl.querySelectorAll('.suggested-actions');
            existing.forEach(function(el) { el.remove(); });

            const container = document.createElement('div');
            container.className = 'quick-actions suggested-actions';
            container.style.padding = '0.5rem 0';

            actions.forEach(function(action) {
                const btn = document.createElement('button');
                btn.className = 'quick-action';
                const label = typeof action === 'string' ? action : (action.label || action);
                const msg = typeof action === 'string' ? action : (action.message || action.label || action);
                btn.textContent = label;
                btn.dataset.message = msg;
                btn.addEventListener('click', function() {
                    container.remove();
                    sendMessage(msg);
                });
                container.appendChild(btn);
            });

            messagesEl.insertBefore(container, typingIndicator);
            scrollToBottom();
        }

        function showTyping(show) {
            typingIndicator.classList.toggle('show', show);
            if (show) scrollToBottom();
        }

        function handleEscalation() {
            isEscalated = true;
            escalationBanner.classList.add('show');
            quickActionsEl.style.display = 'none';
            messageInput.placeholder = 'Type a message for the agent...';
            messageInput.disabled = false;
            sendBtn.disabled = false;
            showTyping(false);

            // Start polling for agent messages
            startAgentPoll();

            if (embedMode && window.parent !== window) {
                window.parent.postMessage({ type: 'myctobot-escalated' }, '*');
            }
        }

        function startAgentPoll() {
            if (pollInterval) return; // already polling

            // Count existing messages for delta tracking
            const existingMsgs = messagesEl.querySelectorAll('.message');
            lastMessageCount = existingMsgs.length;
            // Use server-side count from first poll response
            let serverMessageCount = 0;
            let firstPoll = true;

            const POLL_URL = '/dapp/' + SLUG + '/api/poll';

            pollInterval = setInterval(async function() {
                if (!sessionToken) return;

                try {
                    const url = POLL_URL + '?session_token=' + encodeURIComponent(sessionToken) + '&last_count=' + serverMessageCount;
                    const resp = await fetch(url);
                    if (!resp.ok) return;

                    const data = await resp.json();
                    if (!data.success) return;

                    // On first poll, sync the server-side count
                    if (firstPoll) {
                        serverMessageCount = data.total_count;
                        firstPoll = false;
                        return;
                    }

                    // Render new messages
                    if (data.messages && data.messages.length > 0) {
                        let hasAgentMsg = false;
                        data.messages.forEach(function(msg) {
                            const role = msg.role === 'user' ? null : msg.role; // skip our own messages
                            if (role) {
                                addMessage(role === 'agent' ? 'assistant' : role, msg.content);
                                if (msg.rich) {
                                    renderRichData(msg.rich);
                                }
                                if (role === 'agent') hasAgentMsg = true;
                            }
                        });
                        serverMessageCount = data.total_count;
                        // Play sound for live agent messages
                        if (hasAgentMsg && playNotifSound) {
                            try { playNotifSound(); } catch(e) {}
                        }
                    }

                    // Show/hide typing indicator based on agent_typing
                    if (data.agent_typing) {
                        typingIndicator.style.display = '';
                        scrollToBottom();
                    } else {
                        typingIndicator.style.display = 'none';
                    }

                    // Update escalation banner
                    if (data.agent_name) {
                        escalationBanner.innerHTML = '<i class="bi bi-person-fill me-2"></i><strong>Connected with ' + escapeHtml(data.agent_name) + '</strong>';
                    } else if (data.queue_position > 0) {
                        let waitText = data.estimated_wait ? ' Estimated wait: ~' + data.estimated_wait + ' min.' : '';
                        escalationBanner.innerHTML = '<i class="bi bi-hourglass-split me-2"></i><strong>#' + data.queue_position + ' in queue.</strong>' + waitText;
                    }

                    // Handle resolved
                    if (data.status === 'resolved') {
                        clearInterval(pollInterval);
                        pollInterval = null;
                        messageInput.disabled = true;
                        sendBtn.disabled = true;
                        messageInput.placeholder = 'Chat has been resolved.';
                        escalationBanner.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i><strong>Chat resolved.</strong> Thank you!';
                    }
                } catch (err) {
                    // Silent fail — will retry on next interval
                }
            }, 3000);
        }

        function scrollToBottom() {
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }

        function showToast(message, type) {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = 'chat-toast ' + (type || '');
            toast.textContent = message;
            container.appendChild(toast);
            setTimeout(function() {
                toast.style.opacity = '0';
                toast.style.transition = 'opacity 0.3s';
                setTimeout(function() { toast.remove(); }, 300);
            }, 3000);
        }

        // Listen for close request from parent (iframe embed)
        window.addEventListener('message', function(e) {
            if (!e.data || typeof e.data !== 'object') return;
            if (e.data.type === 'myctobot-request-close') {
                window.parent.postMessage({ type: 'myctobot-close' }, '*');
            }
        });

        // Notify parent of session start
        if (embedMode && window.parent !== window) {
            window.parent.postMessage({
                type: 'myctobot-session-started',
                sessionToken: sessionToken
            }, '*');
        }

        messageInput.focus();
    })();
    </script>
</body>
</html>
