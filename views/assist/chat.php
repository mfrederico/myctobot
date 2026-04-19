<?php
/**
 * Detached Assist Chat Window
 *
 * Standalone chat page opened via the "detach" button on pageassist.
 * Connects to the same WebSocket, but forwards UI actions (highlight,
 * scroll, fill, navigate) back to the opener window via postMessage.
 *
 * Variables from controller:
 *   $assistName      - Bot display name
 *   $assistWsPort    - WebSocket port
 *   $isLocalhost     - Whether running locally
 *   $assistWorkspace - Current workspace slug
 */

$wsPort = $assistWsPort ?? 9510;
$name = $assistName ?? 'MyCTOBOT Assistant';
$workspace = $assistWorkspace ?? '';

if ($isLocalhost ?? false) {
    $wsUrl = "ws://localhost:{$wsPort}";
} else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'wss' : 'ws';
    $wsUrl = "{$scheme}://{$_SERVER['HTTP_HOST']}/assist";
}
if ($workspace) {
    $wsUrl .= (strpos($wsUrl, '?') === false ? '?' : '&') . 'workspace=' . urlencode($workspace);
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; }
        body { display: flex; flex-direction: column; background: var(--bs-body-bg); }

        .chat-header {
            background: var(--bs-info);
            color: white;
            padding: 10px 16px;
            flex-shrink: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .chat-header .title { font-weight: 600; }

        #chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
            background: var(--bs-body-bg);
        }

        .assist-message { margin-bottom: 12px; max-width: 90%; }
        .assist-message-user { margin-left: auto; }
        .assist-message-user .assist-message-content {
            background: var(--bs-info);
            color: white;
            border-radius: 12px 12px 0 12px;
        }
        .assist-message-assistant .assist-message-content {
            background: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color, #dee2e6);
            border-radius: 12px 12px 12px 0;
        }
        .assist-message-content {
            padding: 10px 14px;
            word-wrap: break-word;
        }
        .assist-message-content code {
            background: rgba(0,0,0,0.1);
            padding: 2px 4px;
            border-radius: 3px;
            font-size: 0.85em;
        }
        .assist-message-content pre {
            background: rgba(0,0,0,0.05);
            padding: 8px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 0.85em;
            margin: 0;
        }
        .assist-code-block {
            position: relative;
            margin: 6px 0;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 6px;
            overflow: hidden;
            background: rgba(0,0,0,0.15);
        }
        .assist-code-lang {
            display: inline-block;
            padding: 2px 8px;
            font-size: 0.7em;
            font-weight: 600;
            color: rgba(255,255,255,0.5);
            background: rgba(255,255,255,0.05);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            width: 100%;
            letter-spacing: 0.05em;
        }
        .assist-code-copy {
            position: absolute;
            top: 1px;
            right: 4px;
            border: none;
            background: none;
            color: rgba(255,255,255,0.4);
            cursor: pointer;
            padding: 2px 5px;
            font-size: 0.75em;
        }
        .assist-code-copy:hover { color: rgba(255,255,255,0.8); }
        .assist-code-copy.copied { color: #198754; }
        .assist-code-block pre { border: none; border-radius: 0; margin: 0; background: transparent; }
        .assist-thinking { color: var(--bs-secondary); font-style: italic; }
        .assist-thinking i { animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 0.4; } 50% { opacity: 1; } }

        .chat-input-area {
            flex-shrink: 0;
            padding: 8px 12px;
            border-top: 1px solid var(--bs-border-color);
            background: var(--bs-body-bg);
        }

        #status-badge {
            font-size: 0.7em;
            padding: 2px 8px;
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <div class="chat-header">
        <span class="title"><i class="bi bi-stars me-2"></i><?= htmlspecialchars($name) ?></span>
        <div>
            <span id="status-badge" class="badge bg-warning">Connecting...</span>
            <button class="btn btn-sm btn-link text-white p-0 ms-2" id="btn-clear" title="Clear chat">
                <i class="bi bi-trash"></i>
            </button>
            <button class="btn btn-sm btn-link text-white p-0 ms-2" id="btn-reattach" title="Reattach to page">
                <i class="bi bi-box-arrow-in-down-left"></i>
            </button>
        </div>
    </div>

    <div id="chat-messages"></div>

    <div class="chat-input-area">
        <div class="input-group">
            <input type="text" id="chat-input" class="form-control form-control-sm" placeholder="Ask anything..." autofocus>
            <button class="btn btn-sm btn-info" id="chat-send" disabled>
                <i class="bi bi-send"></i>
            </button>
        </div>
    </div>

<script>
(function() {
    const CONFIG = {
        name: <?= json_encode($name) ?>,
        wsUrl: <?= json_encode($wsUrl) ?>,
        storageKey: 'assist_conversation',
        conversationTimeout: 24 * 60 * 60 * 1000
    };

    // Elements
    const messagesEl = document.getElementById('chat-messages');
    const inputEl = document.getElementById('chat-input');
    const sendBtn = document.getElementById('chat-send');
    const statusBadge = document.getElementById('status-badge');

    // State
    let ws = null;
    let connected = false;
    let currentResponse = '';
    let conversationId = null;
    let reconnectAttempts = 0;

    // ============================
    // localStorage Conversation
    // ============================

    function loadConversation() {
        try {
            const stored = localStorage.getItem(CONFIG.storageKey);
            if (!stored) return null;
            const conv = JSON.parse(stored);
            if (Date.now() - conv.updated_at > CONFIG.conversationTimeout) {
                localStorage.removeItem(CONFIG.storageKey);
                return null;
            }
            return conv;
        } catch (e) { return null; }
    }

    function saveMessage(role, content) {
        const conv = loadConversation() || {
            id: conversationId || crypto.randomUUID(),
            page: 'detached',
            messages: [],
            created_at: Date.now(),
            updated_at: Date.now()
        };
        conv.messages.push({ role, content, timestamp: Date.now() });
        conv.updated_at = Date.now();
        // Keep detached flag so inline widget knows
        conv.detached = true;
        localStorage.setItem(CONFIG.storageKey, JSON.stringify(conv));
    }

    function clearConversation() {
        localStorage.removeItem(CONFIG.storageKey);
        conversationId = null;
        messagesEl.innerHTML = '';
    }

    // ============================
    // Rendering
    // ============================

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatContent(text) {
        let result = text.trim().replace(/\n{3,}/g, '\n\n');

        // Code blocks
        const codeBlocks = [];
        result = result.replace(/`{2,3}(\w*)\s*\n?([\s\S]*?)`{2,3}/g, (match, lang, code) => {
            const trimmed = code.trim();
            const effectiveLang = lang || (trimmed.startsWith('{') || trimmed.startsWith('[') ? 'json' : '');
            const langLabel = effectiveLang ? `<span class="assist-code-lang">${escapeHtml(effectiveLang.toUpperCase())}</span>` : '';
            let formatted = trimmed;
            if (effectiveLang === 'json') {
                try { formatted = JSON.stringify(JSON.parse(formatted), null, 2); } catch(e) {}
            }
            const placeholder = `\x00CB${codeBlocks.length}\x00`;
            const copyBtn = `<button class="assist-code-copy" title="Copy"><i class="bi bi-clipboard"></i></button>`;
            codeBlocks.push(`<div class="assist-code-block">${langLabel}${copyBtn}<pre><code>${escapeHtml(formatted)}</code></pre></div>`);
            return placeholder;
        });

        result = result.replace(/`([^`]+)`/g, '<code>$1</code>');

        // Lists
        result = result.replace(/(^|\n)((?:- .+(?:\n|$))+)/gm, (match, prefix, block) => {
            const items = block.trim().split('\n').map(l => `<li>${l.replace(/^- /, '')}</li>`).join('');
            return `${prefix}<ul>${items}</ul>`;
        });
        result = result.replace(/(^|\n)((?:\d+\. .+(?:\n|$))+)/gm, (match, prefix, block) => {
            const items = block.trim().split('\n').map(l => `<li>${l.replace(/^\d+\. /, '')}</li>`).join('');
            return `${prefix}<ol>${items}</ol>`;
        });

        result = result
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/\*([^*]+)\*/g, '<em>$1</em>')
            .replace(/\n\n/g, '</p><p>')
            .replace(/\n/g, '<br>');

        if (result.includes('</p>')) result = '<p>' + result + '</p>';

        codeBlocks.forEach((block, i) => {
            result = result.replace(`\x00CB${i}\x00`, block);
        });

        return result;
    }

    function addMessage(role, content) {
        const div = document.createElement('div');
        div.className = `assist-message assist-message-${role}`;
        div.innerHTML = `<div class="assist-message-content">${formatContent(content)}</div>`;
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function showThinking(message) {
        const existing = document.getElementById('current-response');
        if (existing) existing.remove();

        currentResponse = '';
        const div = document.createElement('div');
        div.className = 'assist-message assist-message-assistant';
        div.id = 'current-response';
        div.innerHTML = `
            <div class="assist-message-content">
                <span class="assist-thinking"><i class="bi bi-hourglass-split me-1"></i>${escapeHtml(message)}</span>
                <span class="response-text"></span>
            </div>
        `;
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function appendChunk(content) {
        const responseDiv = document.getElementById('current-response');
        if (!responseDiv) return;

        const thinking = responseDiv.querySelector('.assist-thinking');
        if (thinking) thinking.style.display = 'none';

        currentResponse += content;
        const textEl = responseDiv.querySelector('.response-text');
        textEl.innerHTML = formatContent(currentResponse);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function finishResponse(convId) {
        const responseDiv = document.getElementById('current-response');
        if (responseDiv) {
            const thinking = responseDiv.querySelector('.assist-thinking');
            if (thinking) thinking.style.display = 'none';
            const textEl = responseDiv.querySelector('.response-text');
            if (textEl && !currentResponse.trim()) {
                textEl.innerHTML = '<em class="text-muted">Done.</em>';
            }
            responseDiv.removeAttribute('id');
            if (currentResponse) saveMessage('assistant', currentResponse);
        }
        currentResponse = '';
        conversationId = convId || conversationId;
        sendBtn.disabled = !inputEl.value.trim();
    }

    // ============================
    // Actions — forward to opener
    // ============================

    function executeAction(action) {
        console.log('[Detached] Action:', action);

        // Forward UI actions to the opener page for execution
        if (window.opener && !window.opener.closed) {
            window.opener.postMessage({
                source: 'myctobot_assist',
                type: 'action',
                action: action
            }, '*');
        }

        // Navigate is special — also navigate the opener, not this window
        if (action.action === 'navigate') {
            if (window.opener && !window.opener.closed) {
                // Save conversation before opener navigates
                if (currentResponse) {
                    saveMessage('assistant', currentResponse);
                    currentResponse = '';
                }
                try {
                    const conv = JSON.parse(localStorage.getItem(CONFIG.storageKey) || 'null');
                    if (conv) {
                        conv.navigated_by_assist = true;
                        conv.detached = true;
                        localStorage.setItem(CONFIG.storageKey, JSON.stringify(conv));
                    }
                } catch (e) {}
            }
        }

        // Toast actions show locally too
        if (action.action === 'toast') {
            addMessage('assistant', `${action.type}: ${action.message}`);
        }
    }

    // ============================
    // WebSocket
    // ============================

    function connect() {
        statusBadge.className = 'badge bg-warning';
        statusBadge.textContent = 'Connecting...';

        try {
            ws = new WebSocket(CONFIG.wsUrl);
        } catch (e) {
            statusBadge.className = 'badge bg-danger';
            statusBadge.textContent = 'Offline';
            return;
        }

        ws.onopen = () => {
            connected = true;
            reconnectAttempts = 0;
            statusBadge.className = 'badge bg-success';
            statusBadge.textContent = 'Connected';
            sendBtn.disabled = !inputEl.value.trim();
        };

        ws.onclose = () => {
            connected = false;
            sendBtn.disabled = true;
            statusBadge.className = 'badge bg-danger';
            statusBadge.textContent = 'Disconnected';
            if (reconnectAttempts < 5) {
                reconnectAttempts++;
                setTimeout(connect, 3000 * reconnectAttempts);
            }
        };

        ws.onerror = () => {
            statusBadge.className = 'badge bg-danger';
            statusBadge.textContent = 'Error';
        };

        ws.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                switch (data.type) {
                    case 'thinking': showThinking(data.content); break;
                    case 'chunk': appendChunk(data.content); break;
                    case 'action': executeAction(data); break;
                    case 'done': finishResponse(data.conversation_id); break;
                    case 'error':
                        addMessage('assistant', `Error: ${data.message}`);
                        break;
                }
            } catch (e) {
                console.error('[Detached] Parse error:', e);
            }
        };
    }

    function sendMessage(message) {
        if (!message || !connected) return;

        // Get page context from opener if available
        let context = { page: 'detached', url: window.opener?.location?.href || '' };
        try {
            if (window.opener && !window.opener.closed && window.opener.assistGetPageContext) {
                context = window.opener.assistGetPageContext();
            }
        } catch (e) {
            // Cross-origin or closed — use minimal context
        }

        conversationId = conversationId || loadConversation()?.id || crypto.randomUUID();

        addMessage('user', message);
        inputEl.value = '';
        sendBtn.disabled = true;

        ws.send(JSON.stringify({
            type: 'chat',
            message: message,
            context: context,
            conversation_id: conversationId
        }));

        saveMessage('user', message);
    }

    // ============================
    // Init
    // ============================

    // Restore conversation from localStorage
    const conv = loadConversation();
    if (conv && conv.messages.length) {
        conversationId = conv.id;
        conv.messages.forEach(msg => addMessage(msg.role, msg.content));
    }

    // Event listeners
    sendBtn.addEventListener('click', () => sendMessage(inputEl.value.trim()));
    inputEl.addEventListener('keypress', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(inputEl.value.trim());
        }
    });
    inputEl.addEventListener('input', () => {
        sendBtn.disabled = !inputEl.value.trim() || !connected;
    });

    document.getElementById('btn-clear').addEventListener('click', clearConversation);
    document.getElementById('btn-reattach').addEventListener('click', () => {
        // Remove detached flag so inline widget picks up normally
        try {
            const c = loadConversation();
            if (c) {
                delete c.detached;
                localStorage.setItem(CONFIG.storageKey, JSON.stringify(c));
            }
        } catch (e) {}
        window.close();
    });

    // Copy code blocks
    messagesEl.addEventListener('click', (e) => {
        const btn = e.target.closest('.assist-code-copy');
        if (!btn) return;
        const code = btn.closest('.assist-code-block')?.querySelector('code');
        if (!code) return;
        navigator.clipboard.writeText(code.textContent.trim()).then(() => {
            btn.innerHTML = '<i class="bi bi-check"></i>';
            btn.classList.add('copied');
            setTimeout(() => {
                btn.innerHTML = '<i class="bi bi-clipboard"></i>';
                btn.classList.remove('copied');
            }, 2000);
        });
    });

    // Connect WebSocket
    connect();

    // Ping to keep alive
    setInterval(() => {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({ type: 'ping' }));
        }
    }, 30000);
})();
</script>
</body>
</html>
