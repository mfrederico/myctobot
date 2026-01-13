<?php
/**
 * Knowledge Base Chat Interface
 * Embedded RAG chatbot
 */

$this->layout('layouts/app', [
    'title' => $title,
    'bodyClass' => 'knowledge-base-chat-page'
]);
?>

<div class="container-fluid px-4">
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
            <button type="button" class="btn btn-outline-primary btn-sm ms-2" onclick="openFullScreen()">
                <i class="bi bi-arrows-fullscreen me-1"></i> Full Screen
            </button>
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="height: calc(100vh - 180px); min-height: 500px;">
        <div class="card-body p-0">
            <iframe
                id="chatFrame"
                src="<?= htmlspecialchars($chatUrl) ?>"
                style="width: 100%; height: 100%; border: none; border-radius: 0.375rem;"
                allow="microphone"
            ></iframe>
        </div>
    </div>
</div>

<style>
.knowledge-base-chat-page .card-body {
    overflow: hidden;
}
</style>

<script>
function openFullScreen() {
    const iframe = document.getElementById('chatFrame');
    if (iframe.requestFullscreen) {
        iframe.requestFullscreen();
    } else if (iframe.webkitRequestFullscreen) {
        iframe.webkitRequestFullscreen();
    } else if (iframe.msRequestFullscreen) {
        iframe.msRequestFullscreen();
    }
}

// Listen for messages from iframe (for potential future features)
window.addEventListener('message', function(event) {
    // Verify origin matches RAG service
    const ragOrigin = <?= json_encode(parse_url($ragServiceUrl, PHP_URL_SCHEME) . '://' . parse_url($ragServiceUrl, PHP_URL_HOST) . ':' . (parse_url($ragServiceUrl, PHP_URL_PORT) ?? 80)) ?>;
    if (event.origin !== ragOrigin) return;

    // Handle messages from chat iframe
    if (event.data.type === 'chat_ready') {
        console.log('Chat interface ready');
    }
});
</script>
