<?php
/**
 * PM Chatbox Partial
 *
 * Floating chat widget for PM Assistant conversations.
 * Include this partial on pages where PM chat should be available.
 *
 * Required variables:
 *   $ragServiceUrl - WebSocket URL for RAG service (e.g., 'ws://localhost:9501')
 *   $tenantSlug - Current tenant slug from session
 *   $projectId - (optional) Current project ID for context filtering
 */

$ragServiceUrl = $ragServiceUrl ?? (getenv('RAG_SERVICE_URL') ?: 'http://localhost:9501');
$tenantSlug = $tenantSlug ?? ($_SESSION['tenant_slug'] ?? 'default');
$projectId = $projectId ?? null;
?>

<!-- PM Chat Toggle Button -->
<button id="pm-chat-toggle" class="btn btn-warning rounded-circle shadow-lg"
        style="position: fixed; bottom: 20px; right: 20px; z-index: 1050; width: 60px; height: 60px;"
        title="PM Assistant">
    <i class="bi bi-chat-dots fs-4"></i>
</button>

<!-- PM Chat Panel -->
<div id="pm-chat-panel" class="card shadow-lg"
     style="position: fixed; bottom: 90px; right: 20px; width: 420px; max-height: 600px; z-index: 1049; display: none;">
    <div class="card-header bg-warning d-flex justify-content-between align-items-center py-2">
        <span class="fw-semibold">
            <i class="bi bi-robot me-2"></i>PM Assistant
        </span>
        <div>
            <button class="btn btn-sm btn-link text-dark p-0 me-2" id="pm-chat-clear" title="Clear chat">
                <i class="bi bi-trash"></i>
            </button>
            <button class="btn btn-sm btn-link text-dark p-0" id="pm-chat-close" title="Minimize">
                <i class="bi bi-dash-lg"></i>
            </button>
        </div>
    </div>

    <div class="card-body p-0 d-flex flex-column" style="height: 450px;">
        <!-- Messages Area -->
        <div id="pm-chat-messages" class="flex-grow-1 overflow-auto p-3">
            <div class="text-center text-muted py-4" id="pm-chat-welcome">
                <i class="bi bi-robot fs-1 text-warning"></i>
                <p class="mt-2 mb-1">Ask me about project priorities!</p>
                <small class="text-muted">
                    Try: "Which epic should I work on first?" or<br>
                    "What stories are pending review?"
                </small>
            </div>
        </div>
    </div>

    <!-- Input Area -->
    <div class="card-footer bg-light">
        <div class="input-group">
            <input type="text" id="pm-chat-input" class="form-control"
                   placeholder="Ask about priorities, tasks..." autocomplete="off">
            <button class="btn btn-warning" id="pm-chat-send" disabled>
                <i class="bi bi-send"></i>
            </button>
        </div>
        <small class="text-muted mt-1 d-block">
            <span id="pm-chat-status" class="badge bg-secondary">Disconnected</span>
        </small>
    </div>
</div>

<style>
#pm-chat-panel {
    display: none;
    flex-direction: column;
    border-radius: 12px;
    overflow: hidden;
}

#pm-chat-messages {
    background: #f8f9fa;
}

.pm-message {
    margin-bottom: 12px;
    max-width: 90%;
}

.pm-message-user {
    margin-left: auto;
}

.pm-message-user .pm-message-content {
    background: #0d6efd;
    color: white;
    border-radius: 12px 12px 0 12px;
}

.pm-message-assistant .pm-message-content {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 12px 12px 12px 0;
}

.pm-message-content {
    padding: 10px 14px;
    word-wrap: break-word;
}

.pm-message-content code {
    background: rgba(0,0,0,0.1);
    padding: 2px 4px;
    border-radius: 3px;
    font-size: 0.85em;
}

.pm-thinking {
    color: #6c757d;
    font-style: italic;
}

.pm-thinking i {
    animation: pm-pulse 1.5s infinite;
}

@keyframes pm-pulse {
    0%, 100% { opacity: 0.4; }
    50% { opacity: 1; }
}

#pm-chat-toggle:hover {
    transform: scale(1.05);
}

#pm-chat-input:focus {
    box-shadow: none;
    border-color: #ffc107;
}

@media (max-width: 576px) {
    #pm-chat-panel {
        width: calc(100vw - 20px);
        right: 10px;
        bottom: 80px;
        max-height: calc(100vh - 100px);
    }
}
</style>

<script>
(function() {
    // Configuration
    const PM_CONFIG = {
        tenant: <?= json_encode($tenantSlug) ?>,
        projectId: <?= json_encode($projectId) ?>,
        ragServiceUrl: <?= json_encode($ragServiceUrl) ?>,
        reconnectDelay: 3000
    };

    // State
    let ws = null;
    let connected = false;
    let currentResponse = '';

    // Elements
    const elements = {
        toggle: document.getElementById('pm-chat-toggle'),
        panel: document.getElementById('pm-chat-panel'),
        messages: document.getElementById('pm-chat-messages'),
        welcome: document.getElementById('pm-chat-welcome'),
        input: document.getElementById('pm-chat-input'),
        send: document.getElementById('pm-chat-send'),
        close: document.getElementById('pm-chat-close'),
        clear: document.getElementById('pm-chat-clear'),
        status: document.getElementById('pm-chat-status')
    };

    // Initialize event listeners
    function init() {
        elements.toggle.addEventListener('click', togglePanel);
        elements.close.addEventListener('click', hidePanel);
        elements.clear.addEventListener('click', clearChat);
        elements.send.addEventListener('click', sendMessage);

        elements.input.addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        elements.input.addEventListener('input', () => {
            elements.send.disabled = !elements.input.value.trim() || !connected;
        });
    }

    function togglePanel() {
        if (elements.panel.style.display === 'none') {
            showPanel();
        } else {
            hidePanel();
        }
    }

    function showPanel() {
        elements.panel.style.display = 'flex';
        elements.toggle.innerHTML = '<i class="bi bi-x-lg fs-4"></i>';
        connect();
        elements.input.focus();
    }

    function hidePanel() {
        elements.panel.style.display = 'none';
        elements.toggle.innerHTML = '<i class="bi bi-chat-dots fs-4"></i>';
    }

    function connect() {
        if (ws && ws.readyState === WebSocket.OPEN) return;

        setStatus('Connecting...', 'secondary');

        // Convert http/https to ws/wss
        let wsUrl = PM_CONFIG.ragServiceUrl.replace(/^http/, 'ws');
        ws = new WebSocket(`${wsUrl}/ws/pm/${PM_CONFIG.tenant}`);

        ws.onopen = () => {
            connected = true;
            setStatus('Connected', 'success');
            elements.send.disabled = !elements.input.value.trim();
        };

        ws.onmessage = (event) => {
            const data = JSON.parse(event.data);
            handleMessage(data);
        };

        ws.onclose = () => {
            connected = false;
            setStatus('Disconnected', 'secondary');
            elements.send.disabled = true;

            // Reconnect if panel is open
            setTimeout(() => {
                if (elements.panel.style.display !== 'none') {
                    connect();
                }
            }, PM_CONFIG.reconnectDelay);
        };

        ws.onerror = () => {
            setStatus('Connection error', 'danger');
        };
    }

    function setStatus(text, variant) {
        elements.status.textContent = text;
        elements.status.className = `badge bg-${variant}`;
    }

    function sendMessage() {
        const message = elements.input.value.trim();
        if (!message || !connected) return;

        addMessage('user', message);
        elements.input.value = '';
        elements.send.disabled = true;

        ws.send(JSON.stringify({
            type: 'pm_chat',
            message: message,
            project_id: PM_CONFIG.projectId
        }));
    }

    function handleMessage(data) {
        switch (data.type) {
            case 'connected':
                // Welcome handled by onopen
                break;
            case 'thinking':
                showThinking(data.message);
                break;
            case 'chunk':
                appendChunk(data.content);
                break;
            case 'done':
                finishResponse();
                break;
            case 'error':
                showError(data.message);
                break;
        }
    }

    function addMessage(role, content) {
        // Hide welcome message
        if (elements.welcome) {
            elements.welcome.style.display = 'none';
        }

        const div = document.createElement('div');
        div.className = `pm-message pm-message-${role}`;
        div.innerHTML = `<div class="pm-message-content">${escapeHtml(content)}</div>`;
        elements.messages.appendChild(div);
        scrollToBottom();
    }

    function showThinking(message) {
        // Hide welcome message
        if (elements.welcome) {
            elements.welcome.style.display = 'none';
        }

        currentResponse = '';
        const div = document.createElement('div');
        div.className = 'pm-message pm-message-assistant';
        div.id = 'pm-current-response';
        div.innerHTML = `
            <div class="pm-message-content">
                <span class="pm-thinking">
                    <i class="bi bi-hourglass-split me-1"></i>${escapeHtml(message)}
                </span>
                <span class="pm-response-text"></span>
            </div>
        `;
        elements.messages.appendChild(div);
        scrollToBottom();
    }

    function appendChunk(content) {
        const responseDiv = document.getElementById('pm-current-response');
        if (!responseDiv) return;

        // Hide thinking indicator on first chunk
        const thinking = responseDiv.querySelector('.pm-thinking');
        if (thinking) thinking.style.display = 'none';

        currentResponse += content;
        const textEl = responseDiv.querySelector('.pm-response-text');
        textEl.innerHTML = formatMarkdown(currentResponse);
        scrollToBottom();
    }

    function finishResponse() {
        const responseDiv = document.getElementById('pm-current-response');
        if (responseDiv) {
            responseDiv.removeAttribute('id');
        }
        currentResponse = '';
        elements.send.disabled = !elements.input.value.trim();
    }

    function showError(message) {
        const div = document.createElement('div');
        div.className = 'pm-message pm-message-assistant';
        div.innerHTML = `
            <div class="pm-message-content bg-danger-subtle text-danger">
                <i class="bi bi-exclamation-triangle me-1"></i>${escapeHtml(message)}
            </div>
        `;
        elements.messages.appendChild(div);
        scrollToBottom();
        elements.send.disabled = !elements.input.value.trim();
    }

    function clearChat() {
        elements.messages.innerHTML = '';
        if (elements.welcome) {
            elements.welcome.style.display = 'block';
            elements.messages.appendChild(elements.welcome);
        }
    }

    function scrollToBottom() {
        elements.messages.scrollTop = elements.messages.scrollHeight;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatMarkdown(text) {
        // Basic markdown formatting
        return text
            // Code blocks
            .replace(/```(\w*)\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>')
            // Inline code
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            // Bold
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            // Italic
            .replace(/\*([^*]+)\*/g, '<em>$1</em>')
            // Story/Epic IDs as badges
            .replace(/\[([A-Z0-9_-]+)\]/g, '<span class="badge bg-secondary">$1</span>')
            // Line breaks
            .replace(/\n/g, '<br>');
    }

    // Initialize
    init();
})();
</script>
