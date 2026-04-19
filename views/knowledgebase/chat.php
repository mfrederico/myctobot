<?php
/**
 * Knowledge Base Chat Interface
 * Direct HTTP-based RAG chat (no iframe to external service)
 */
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="/knowledgebase">Knowledge Base</a></li>
                    <li class="breadcrumb-item active">Chat</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="/knowledgebase" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Documents
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="height: calc(100vh - 180px); min-height: 500px;">
        <div class="card-header bg-white d-flex align-items-center">
            <i class="bi bi-chat-dots text-primary me-2"></i>
            <strong><?= h($selectedKb->name ?? 'Knowledge Base') ?> Chat</strong>
        </div>
        <div class="card-body d-flex flex-column p-0">
            <div id="chatMessages" class="flex-grow-1 overflow-auto p-3" style="min-height: 0;">
                <div class="text-center text-muted py-5">
                    <i class="bi bi-chat-dots" style="font-size: 2rem;"></i>
                    <p class="mt-2">Ask a question about your knowledge base documents.</p>
                </div>
            </div>
            <div class="border-top p-3">
                <form id="chatForm" class="d-flex gap-2">
                    <input type="text" id="chatInput" class="form-control"
                           placeholder="Ask a question..." autocomplete="off" autofocus>
                    <button type="submit" class="btn btn-primary" id="sendBtn">
                        <i class="bi bi-send"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.kb-msg { max-width: 80%; margin-bottom: 1rem; }
.kb-msg-user { margin-left: auto; }
.kb-msg-user .kb-bubble { background: #0d6efd; color: white; border-radius: 1rem 1rem 0.25rem 1rem; }
.kb-msg-assistant .kb-bubble { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 1rem 1rem 1rem 0.25rem; }
.kb-bubble { padding: 0.75rem 1rem; }
.kb-sources { font-size: 0.8rem; margin-top: 0.5rem; }
.kb-typing { opacity: 0.6; }
</style>

<script>
const kbId = <?= json_encode($selectedKb->id ?? null) ?>;
const messagesEl = document.getElementById('chatMessages');
const form = document.getElementById('chatForm');
const input = document.getElementById('chatInput');
const sendBtn = document.getElementById('sendBtn');

function addMessage(role, content, sources) {
    // Remove placeholder if present
    const placeholder = messagesEl.querySelector('.text-center.text-muted');
    if (placeholder) placeholder.remove();

    const div = document.createElement('div');
    div.className = `kb-msg kb-msg-${role}`;

    let html = `<div class="kb-bubble">${escapeHtml(content)}</div>`;
    if (sources && sources.length > 0) {
        html += `<div class="kb-sources text-muted">`;
        html += `<i class="bi bi-journal-text me-1"></i>Sources: `;
        html += sources.map(s => `<span class="badge bg-light text-dark me-1">${escapeHtml(s.filename || 'unknown')}</span>`).join('');
        html += `</div>`;
    }

    div.innerHTML = html;
    messagesEl.appendChild(div);
    messagesEl.scrollTop = messagesEl.scrollHeight;
}

function addTyping() {
    const div = document.createElement('div');
    div.className = 'kb-msg kb-msg-assistant kb-typing';
    div.id = 'typingIndicator';
    div.innerHTML = '<div class="kb-bubble"><i class="bi bi-three-dots"></i> Thinking...</div>';
    messagesEl.appendChild(div);
    messagesEl.scrollTop = messagesEl.scrollHeight;
}

function removeTyping() {
    const el = document.getElementById('typingIndicator');
    if (el) el.remove();
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const query = input.value.trim();
    if (!query || !kbId) return;

    input.value = '';
    sendBtn.disabled = true;
    addMessage('user', query);
    addTyping();

    try {
        const formData = new FormData();
        formData.append('kb_id', kbId);
        formData.append('query', query);

        const resp = await fetch('/knowledgebase/executequery', {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();

        removeTyping();

        if (data.success && data.data) {
            const answer = data.data.answer || 'No answer found for your question.';
            addMessage('assistant', answer, data.data.sources || []);
        } else {
            addMessage('assistant', data.message || 'Sorry, something went wrong.');
        }
    } catch (err) {
        removeTyping();
        addMessage('assistant', 'Error: ' + err.message);
    } finally {
        sendBtn.disabled = false;
        input.focus();
    }
});
</script>
