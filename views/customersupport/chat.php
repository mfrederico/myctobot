<?php
/**
 * Customer Support Chat View
 *
 * Chat interface for customer support.
 * Supports embed mode for iframe integration.
 */

$embedMode = $embed ?? false;
$shopName = $shop_info['shop_name'] ?? 'Store';
$shopDomain = $shop_info['shop_domain'] ?? '';
$customerName = $customer_name ?? 'Customer';
$orderData = $order ?? [];
$existingMessages = $messages ?? [];
$orderNumber = $orderData['name'] ?? 'your order';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Support - <?= h($shopName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- Pinned versions + DOMPurify to sanitize rendered markdown (HIGH-5 XSS) -->
    <script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0d6efd 0%, #6610f2 100%);
            --chat-bg: #1a1a2e;
        }
        * {
            box-sizing: border-box;
        }
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
        }
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
        .chat-header h5 {
            margin: 0;
            font-weight: 600;
        }
        .order-badge {
            background: rgba(255, 255, 255, 0.15);
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.8rem;
            display: inline-block;
        }
        .btn-header-action {
            background: rgba(255, 255, 255, 0.15);
            border: none;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.2s;
            font-size: 0.9rem;
        }
        .btn-header-action:hover {
            background: rgba(255, 255, 255, 0.25);
        }
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
        .message-customer, .message-user {
            background: var(--primary-gradient);
            color: white;
            align-self: flex-end;
            border-bottom-right-radius: 4px;
        }
        .message-agent, .message-assistant {
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
        .chat-input-area {
            background: rgba(33, 37, 41, 0.98);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding: 1rem;
            flex-shrink: 0;
            <?php if (!$embedMode): ?>
            border-radius: 0 0 16px 16px;
            <?php endif; ?>
        }
        .chat-input-wrapper {
            display: flex;
            gap: 0.75rem;
            align-items: flex-end;
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
        }
        .btn-send:hover:not(:disabled) {
            transform: scale(1.05);
        }
        .btn-send:disabled {
            opacity: 0.5;
        }
        .btn-send i {
            font-size: 1.1rem;
        }
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
        .typing-indicator.show {
            display: flex;
        }
        .typing-dots {
            display: flex;
            gap: 4px;
        }
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
        .escalation-banner {
            background: rgba(255, 193, 7, 0.15);
            border: 1px solid rgba(255, 193, 7, 0.3);
            padding: 0.75rem 1rem;
            text-align: center;
            display: none;
        }
        .escalation-banner.show {
            display: block;
        }
        .btn-escalate {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #adb5bd;
            font-size: 0.8rem;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
        }
        .btn-escalate:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        .welcome-message {
            text-align: center;
            padding: 2rem 1rem;
            color: #adb5bd;
        }
        .welcome-message i {
            font-size: 3rem;
            color: #0d6efd;
            margin-bottom: 1rem;
        }
        .message-assistant ul, .message-assistant ol {
            margin: 0.25rem 0;
            padding-left: 1.25rem;
        }
        .message-assistant li {
            margin-bottom: 0.15rem;
        }
        .message-assistant p {
            margin: 0 0 0.5rem 0;
        }
        .message-assistant p:last-child {
            margin-bottom: 0;
        }
        .message-assistant strong {
            color: #fff;
        }
        /* Quick action buttons */
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
        /* Toast notification */
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
        .chat-toast.success { border-left: 4px solid #198754; }
        .chat-toast.error { border-left: 4px solid #dc3545; }
        @keyframes toastIn {
            from { opacity: 0; transform: translateX(-50%) translateY(10px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }
    </style>
</head>
<body>
    <div class="chat-container">
        <!-- Header -->
        <div class="chat-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h5><i class="bi bi-chat-dots-fill me-2"></i>Support Chat</h5>
                    <small class="opacity-75"><?= h($shopName) ?></small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button class="btn-header-action" id="btn-transcript" title="Email this conversation">
                        <i class="bi bi-envelope"></i>
                    </button>
                    <span class="order-badge">
                        <i class="bi bi-box-seam me-1"></i>
                        <?= h($orderData['name'] ?? 'Order') ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Escalation Banner (hidden by default) -->
        <div class="escalation-banner" id="escalation-banner">
            <i class="bi bi-person-fill-gear me-2"></i>
            <strong>Escalated to human support.</strong> A team member will respond shortly.
        </div>

        <!-- Messages Area -->
        <div class="chat-messages" id="chat-messages">
            <?php if (empty($existingMessages)): ?>
            <!-- Welcome message -->
            <div class="welcome-message">
                <i class="bi bi-robot"></i>
                <h5>Hi<?= $customerName ? ', ' . h(explode(' ', $customerName)[0]) : '' ?>!</h5>
                <p class="mb-0">I'm here to help with your order. What can I assist you with today?</p>
            </div>
            <?php else: ?>
            <?php foreach ($existingMessages as $msg): ?>
            <div class="message message-<?= h($msg['role']) ?>">
                <?php if ($msg['role'] === 'assistant'): ?>
                <div class="markdown-content"><?= nl2br(h($msg['content'])) ?></div>
                <?php else: ?>
                <?= nl2br(h($msg['content'])) ?>
                <?php endif; ?>
                <div class="message-time"><?= h($msg['timestamp'] ?? '') ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <!-- Typing indicator -->
            <div class="typing-indicator" id="typing-indicator">
                <div class="typing-dots">
                    <div class="typing-dot"></div>
                    <div class="typing-dot"></div>
                    <div class="typing-dot"></div>
                </div>
                <span class="text-muted small">Agent is typing...</span>
            </div>
        </div>

        <!-- Input Area -->
        <div class="chat-input-area">
            <?php if (empty($existingMessages)): ?>
            <!-- Quick action buttons - shown only on fresh sessions -->
            <div class="quick-actions" id="quick-actions">
                <button class="quick-action" data-message="Where is my order <?= h($orderNumber) ?>?">
                    <i class="bi bi-geo-alt me-1"></i>Where is my order?
                </button>
                <button class="quick-action" data-message="What items are in my order <?= h($orderNumber) ?>?">
                    <i class="bi bi-bag me-1"></i>What did I order?
                </button>
                <button class="quick-action" data-message="I need help finding a product">
                    <i class="bi bi-search me-1"></i>Find a product
                </button>
                <button class="quick-action" data-message="I'd like to speak with a human agent">
                    <i class="bi bi-person me-1"></i>Talk to a human
                </button>
            </div>
            <?php endif; ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <small class="text-muted">
                    <i class="bi bi-shield-check me-1"></i>Secure chat
                </small>
                <button class="btn btn-escalate" id="btn-escalate" title="Talk to a human">
                    <i class="bi bi-person-fill me-1"></i>Talk to Human
                </button>
            </div>
            <form id="chat-form" class="chat-input-wrapper">
                <textarea
                    class="chat-textarea"
                    id="message-input"
                    placeholder="Type your message..."
                    rows="1"
                    maxlength="2000"
                ></textarea>
                <button type="submit" class="btn btn-send" id="btn-send">
                    <i class="bi bi-send-fill"></i>
                </button>
            </form>
        </div>
    </div>

    <!-- Toast container -->
    <div class="toast-container" id="toast-container"></div>

    <script>
        const sessionToken = '<?= h($session_token) ?>';
        const embedMode = <?= $embedMode ? 'true' : 'false' ?>;
        const messagesContainer = document.getElementById('chat-messages');
        const chatForm = document.getElementById('chat-form');
        const messageInput = document.getElementById('message-input');
        const sendBtn = document.getElementById('btn-send');
        const escalateBtn = document.getElementById('btn-escalate');
        const typingIndicator = document.getElementById('typing-indicator');
        const escalationBanner = document.getElementById('escalation-banner');
        const quickActionsContainer = document.getElementById('quick-actions');
        const transcriptBtn = document.getElementById('btn-transcript');

        let lastMessageId = <?= count($existingMessages) ?>;
        let isEscalated = false;
        let pollInterval = null;

        // Notify parent window of session start (for widget persistence)
        if (embedMode && window.parent !== window) {
            window.parent.postMessage({
                type: 'myctobot-session-started',
                sessionToken: sessionToken
            }, '*');
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

        // Quick action buttons
        if (quickActionsContainer) {
            quickActionsContainer.addEventListener('click', function(e) {
                var btn = e.target.closest('.quick-action');
                if (!btn) return;
                var message = btn.dataset.message;
                if (message) {
                    messageInput.value = message;
                    chatForm.dispatchEvent(new Event('submit'));
                }
            });
        }

        function hideQuickActions() {
            if (quickActionsContainer) {
                quickActionsContainer.style.display = 'none';
            }
        }

        // Send message
        chatForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const message = messageInput.value.trim();
            if (!message || isEscalated) return;

            // Hide quick actions after first message
            hideQuickActions();

            // Add message to UI immediately
            addMessageToUI('user', message);
            messageInput.value = '';
            messageInput.style.height = 'auto';

            // Show thinking state while waiting for AI response
            sendBtn.disabled = true;
            messageInput.disabled = true;
            showTyping(true);

            // Pause polling while waiting for synchronous response
            stopPolling();

            try {
                const response = await fetch('/customersupport/message/' + sessionToken, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ message })
                });

                const result = await response.json();

                if (result.success) {
                    lastMessageId = result.data.message_id;
                    showTyping(false);

                    // Display AI response immediately (endpoint is synchronous)
                    if (result.data.ai_response) {
                        addMessageToUI('assistant', result.data.ai_response);
                    }

                    // Check if escalated
                    if (result.data.escalated) {
                        handleEscalation();
                    }
                } else {
                    showTyping(false);
                    addMessageToUI('system', 'Failed to send message: ' + (result.error || 'Unknown error'));
                }
            } catch (err) {
                console.error('Send error:', err);
                showTyping(false);
                addMessageToUI('system', 'Connection error. Please try again.');
            }

            sendBtn.disabled = false;
            messageInput.disabled = false;
            messageInput.focus();

            // Resume polling
            startPolling();
        });

        // Escalate to human
        escalateBtn.addEventListener('click', async function() {
            if (isEscalated) return;

            if (!confirm('Would you like to speak with a human support agent?')) {
                return;
            }

            try {
                const response = await fetch('/customersupport/escalate/' + sessionToken, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ reason: 'Customer requested human support' })
                });

                const result = await response.json();

                if (result.success) {
                    handleEscalation();
                }
            } catch (err) {
                console.error('Escalation error:', err);
            }
        });

        // Transcript email button
        transcriptBtn.addEventListener('click', async function() {
            if (lastMessageId === 0) {
                showToast('No messages to send yet', 'error');
                return;
            }

            transcriptBtn.disabled = true;
            try {
                const response = await fetch('/customersupport/transcript/' + sessionToken, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message || 'Transcript sent to your email', 'success');
                } else {
                    showToast(result.error || 'Failed to send transcript', 'error');
                }
            } catch (err) {
                console.error('Transcript error:', err);
                showToast('Failed to send transcript', 'error');
            }
            transcriptBtn.disabled = false;
        });

        function addMessageToUI(role, content) {
            // Remove welcome message if present
            const welcome = messagesContainer.querySelector('.welcome-message');
            if (welcome) welcome.remove();

            const div = document.createElement('div');
            div.className = 'message message-' + role;

            // Render markdown for assistant messages, plain text for user.
            // SECURITY (HIGH-5): AI/agent output is attacker-influenceable (prompt
            // injection) and this widget is public + iframe-embeddable, so always
            // sanitize rendered HTML with DOMPurify.
            if (role === 'assistant' && typeof marked !== 'undefined' && typeof DOMPurify !== 'undefined') {
                div.innerHTML = DOMPurify.sanitize(marked.parse(content));
            } else {
                div.innerHTML = escapeHtml(content).replace(/\n/g, '<br>');
            }

            const time = document.createElement('div');
            time.className = 'message-time';
            time.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            div.appendChild(time);

            // Insert before typing indicator
            messagesContainer.insertBefore(div, typingIndicator);
            scrollToBottom();
        }

        function showTyping(show) {
            typingIndicator.classList.toggle('show', show);
            if (show) scrollToBottom();
        }

        function handleEscalation() {
            isEscalated = true;
            escalationBanner.classList.add('show');
            escalateBtn.style.display = 'none';
            hideQuickActions();
            messageInput.placeholder = 'Waiting for human support...';
            messageInput.disabled = true;
            sendBtn.disabled = true;
            showTyping(false);

            // Notify parent window if in iframe
            if (embedMode && window.parent !== window) {
                window.parent.postMessage({ type: 'myctobot-escalated' }, '*');
            }
        }

        function scrollToBottom() {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function showToast(message, type) {
            var container = document.getElementById('toast-container');
            var toast = document.createElement('div');
            toast.className = 'chat-toast ' + (type || '');
            toast.textContent = message;
            container.appendChild(toast);
            setTimeout(function() {
                toast.style.opacity = '0';
                toast.style.transition = 'opacity 0.3s';
                setTimeout(function() { toast.remove(); }, 300);
            }, 3000);
        }

        // Poll for new messages (backup - primary display is from message response)
        async function pollMessages() {
            if (isEscalated && !pollInterval) return;

            try {
                const response = await fetch('/customersupport/poll/' + sessionToken + '?last_id=' + lastMessageId);
                const result = await response.json();

                if (result.success && result.data.messages.length > 0) {
                    // Only add messages we haven't seen yet
                    // This mainly catches system messages or externally-added messages
                    result.data.messages.forEach(msg => {
                        if (msg.role !== 'customer' && msg.role !== 'user') {
                            addMessageToUI(msg.role, msg.content);
                        }
                    });

                    lastMessageId = result.data.total_count;
                    showTyping(false);
                }

                // Check status changes
                if (result.data && result.data.status === 'escalated' && !isEscalated) {
                    handleEscalation();
                }
                if (result.data && result.data.status === 'expired') {
                    addMessageToUI('system', 'This session has expired. Please start a new chat.');
                    stopPolling();
                    // Notify parent window
                    if (embedMode && window.parent !== window) {
                        window.parent.postMessage({ type: 'myctobot-session-expired' }, '*');
                    }
                }
            } catch (err) {
                console.error('Poll error:', err);
            }
        }

        function startPolling() {
            pollInterval = setInterval(pollMessages, 3000);
        }

        function stopPolling() {
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
        }

        // Listen for close request from parent (for transcript-on-close flow)
        window.addEventListener('message', function(e) {
            if (!e.data || typeof e.data !== 'object') return;
            if (e.data.type === 'myctobot-request-close') {
                // Parent is requesting close - offer transcript option if there are messages
                if (lastMessageId > 0) {
                    if (confirm('Would you like to email a copy of this conversation?')) {
                        fetch('/customersupport/transcript/' + sessionToken, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' }
                        }).finally(function() {
                            window.parent.postMessage({ type: 'myctobot-close' }, '*');
                        });
                    } else {
                        window.parent.postMessage({ type: 'myctobot-close' }, '*');
                    }
                } else {
                    window.parent.postMessage({ type: 'myctobot-close' }, '*');
                }
            }
        });

        // Render any server-side markdown content (sanitized — HIGH-5)
        if (typeof marked !== 'undefined' && typeof DOMPurify !== 'undefined') {
            document.querySelectorAll('.markdown-content').forEach(el => {
                el.innerHTML = DOMPurify.sanitize(marked.parse(el.textContent));
            });
        }

        // Start polling
        startPolling();

        // Focus input
        messageInput.focus();

        // Scroll to bottom on load
        scrollToBottom();

        // Cleanup on unload
        window.addEventListener('beforeunload', stopPolling);
    </script>
</body>
</html>
