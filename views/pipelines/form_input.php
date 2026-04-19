<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Input Required - <?= h($pipeline_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- CodeMirror for editor -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/dracula.min.css">
    <!-- Marked.js for markdown rendering -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
        }
        .form-card {
            max-width: 900px;
            margin: 0 auto;
        }
        .countdown {
            font-family: monospace;
        }
        .card {
            background: rgba(33, 37, 41, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .CodeMirror {
            height: 300px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 0.375rem;
            font-size: 14px;
        }
        .accordion-button {
            background: rgba(33, 37, 41, 0.8);
            color: #adb5bd;
        }
        .accordion-button:not(.collapsed) {
            background: rgba(33, 37, 41, 0.95);
            color: #fff;
        }
        .accordion-body {
            background: rgba(0, 0, 0, 0.3);
            max-height: 300px;
            overflow-y: auto;
        }
        .context-key {
            color: #6ea8fe;
        }
        .context-value {
            color: #a3cfbb;
        }
        .upload-zone {
            border: 2px dashed rgba(255, 255, 255, 0.3);
            border-radius: 0.5rem;
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .upload-zone:hover {
            border-color: rgba(255, 255, 255, 0.5);
            background: rgba(255, 255, 255, 0.05);
        }
        .upload-zone.dragover {
            border-color: #0d6efd;
            background: rgba(13, 110, 253, 0.1);
        }
        .attachment-preview {
            max-width: 100px;
            max-height: 100px;
            object-fit: cover;
            border-radius: 0.25rem;
        }
        .attachment-item {
            position: relative;
            display: inline-block;
            margin: 0.25rem;
        }
        .attachment-remove {
            position: absolute;
            top: -8px;
            right: -8px;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            padding: 0;
            font-size: 12px;
            line-height: 1;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="form-card">
            <div class="card shadow-lg">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">
                            <i class="bi bi-input-cursor-text"></i>
                            <?= h($pipeline_name) ?>
                        </h5>
                        <small class="opacity-75">Step: <?= h($step_name) ?></small>
                    </div>
                    <?php if ($timeout_at): ?>
                    <div class="text-end">
                        <small class="opacity-75">Expires:</small>
                        <span class="countdown badge bg-dark text-light" data-timeout="<?= h($timeout_at) ?>">
                            <?= date('M j, g:i A', strtotime($timeout_at)) ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <!-- Prompt -->
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-chat-left-text me-2"></i>
                        <?= nl2br(h($prompt)) ?>
                    </div>

                    <!-- Chat History -->
                    <?php $messages = $context['_messages'] ?? []; ?>
                    <div id="chatHistory" class="mb-4" style="max-height: 400px; overflow-y: auto;">
                        <?php if (!empty($messages)): ?>
                        <?php foreach ($messages as $msg): ?>
                        <div class="d-flex mb-2 <?= $msg['role'] === 'user' ? 'justify-content-end' : 'justify-content-start' ?>">
                            <div class="p-2 rounded-3 <?= $msg['role'] === 'user' ? 'bg-primary text-white' : 'bg-secondary bg-opacity-25' ?>" style="max-width: 85%;">
                                <div class="small opacity-75 mb-1">
                                    <?= $msg['role'] === 'user' ? '<i class="bi bi-person"></i> You' : '<i class="bi bi-robot"></i> Agent' ?>
                                    <span class="ms-2"><?= date('g:i A', strtotime($msg['timestamp'])) ?></span>
                                </div>
                                <div style="white-space: pre-wrap;"><?= nl2br(h($msg['message'])) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div class="text-center text-muted py-3" id="noMessages">
                            <i class="bi bi-chat-dots display-6"></i>
                            <p class="mb-0 mt-2">No messages yet. Send instructions to start.</p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Context (Collapsible Accordion) -->
                    <?php if (!empty($context) && is_array($context)): ?>
                    <?php
                        // Filter context to show only relevant user-facing data
                        $displayContext = array_filter($context, function($key) {
                            return !str_starts_with($key, '_') && !in_array($key, ['clone_repo', 'spawn_agent', 'send_ready_email', 'schedule_status_15m', 'wait_for_instructions', 'check_if_complete', 'send_to_agent', 'send_ack_email', 'send_status_email', 'schedule_next_status']);
                        }, ARRAY_FILTER_USE_KEY);
                    ?>
                    <?php if (!empty($displayContext)): ?>
                    <div class="accordion mb-4" id="contextAccordion">
                        <div class="accordion-item bg-transparent border-secondary">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#contextCollapse">
                                    <i class="bi bi-info-circle me-2"></i> Pipeline Context
                                    <span class="badge bg-secondary ms-2"><?= count($displayContext) ?> items</span>
                                </button>
                            </h2>
                            <div id="contextCollapse" class="accordion-collapse collapse" data-bs-parent="#contextAccordion">
                                <div class="accordion-body font-monospace small">
                                    <?php foreach ($displayContext as $key => $value): ?>
                                    <div class="mb-1">
                                        <span class="context-key"><?= h($key) ?>:</span>
                                        <span class="context-value">
                                            <?php if (is_array($value) || is_object($value)): ?>
                                                <pre class="mb-0 text-muted small"><?= h(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                                            <?php else: ?>
                                                <?= h((string) $value) ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <!-- Form -->
                    <?php $tokenParam = !empty($token) ? '?token=' . urlencode($token) : ''; ?>
                    <form action="/pipelines/formsubmit/<?= $run_id ?><?= $tokenParam ?>" method="POST" id="inputForm" enctype="multipart/form-data">
                        <?php if (!empty($token)): ?>
                        <input type="hidden" name="token" value="<?= h($token) ?>">
                        <?php endif; ?>

                        <?php if (!empty($schema['properties'])): ?>
                            <?php foreach ($schema['properties'] as $fieldName => $fieldDef): ?>
                            <?php
                            $type = $fieldDef['type'] ?? 'string';
                            $description = $fieldDef['description'] ?? '';
                            $enum = $fieldDef['enum'] ?? null;
                            $required = in_array($fieldName, $schema['required'] ?? []);
                            $isMessageField = ($fieldName === 'message' || $fieldName === 'instructions' || str_contains(strtolower($description), 'instruction'));
                            $isActionField = ($fieldName === 'action');
                            ?>

                            <?php if ($isActionField): ?>
                                <!-- Action field with status buttons -->
                                <input type="hidden" name="action" id="actionField" value="instructions">
                            <?php elseif ($enum && !$isActionField): ?>
                                <!-- Enum/Select (non-action) -->
                                <div class="mb-3">
                                    <label for="<?= h($fieldName) ?>" class="form-label">
                                        <?= h(ucwords(str_replace('_', ' ', $fieldName))) ?>
                                        <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                                    </label>
                                    <select
                                        class="form-select"
                                        name="<?= h($fieldName) ?>"
                                        id="<?= h($fieldName) ?>"
                                        <?= $required ? 'required' : '' ?>
                                    >
                                        <option value="">-- Select --</option>
                                        <?php foreach ($enum as $option): ?>
                                        <option value="<?= h($option) ?>"><?= h(ucfirst($option)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($description): ?>
                                    <div class="form-text"><?= h($description) ?></div>
                                    <?php endif; ?>
                                </div>

                            <?php elseif ($type === 'boolean'): ?>
                                <!-- Boolean/Checkbox -->
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            name="<?= h($fieldName) ?>"
                                            id="<?= h($fieldName) ?>"
                                            value="true"
                                        >
                                        <label class="form-check-label" for="<?= h($fieldName) ?>">
                                            <?= h(ucwords(str_replace('_', ' ', $fieldName))) ?>
                                        </label>
                                    </div>
                                    <?php if ($description): ?>
                                    <div class="form-text"><?= h($description) ?></div>
                                    <?php endif; ?>
                                </div>

                            <?php elseif ($type === 'integer' || $type === 'number'): ?>
                                <!-- Number -->
                                <div class="mb-3">
                                    <label for="<?= h($fieldName) ?>" class="form-label">
                                        <?= h(ucwords(str_replace('_', ' ', $fieldName))) ?>
                                        <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                                    </label>
                                    <input
                                        type="number"
                                        class="form-control"
                                        name="<?= h($fieldName) ?>"
                                        id="<?= h($fieldName) ?>"
                                        <?= $required ? 'required' : '' ?>
                                        <?= $type === 'integer' ? 'step="1"' : 'step="any"' ?>
                                    >
                                    <?php if ($description): ?>
                                    <div class="form-text"><?= h($description) ?></div>
                                    <?php endif; ?>
                                </div>

                            <?php elseif ($isMessageField): ?>
                                <!-- Message field with CodeMirror editor -->
                                <div class="mb-4">
                                    <label for="<?= h($fieldName) ?>" class="form-label">
                                        <i class="bi bi-pencil-square me-1"></i>
                                        <?= h(ucwords(str_replace('_', ' ', $fieldName))) ?>
                                        <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                                    </label>
                                    <textarea
                                        class="form-control d-none"
                                        name="<?= h($fieldName) ?>"
                                        id="<?= h($fieldName) ?>"
                                        <?= $required ? 'required' : '' ?>
                                    ></textarea>
                                    <div id="editor"></div>
                                    <?php if ($description): ?>
                                    <div class="form-text"><?= h($description) ?></div>
                                    <?php endif; ?>
                                </div>

                            <?php elseif (strlen($description) > 100 || str_contains($type, 'text')): ?>
                                <!-- Textarea for long text -->
                                <div class="mb-3">
                                    <label for="<?= h($fieldName) ?>" class="form-label">
                                        <?= h(ucwords(str_replace('_', ' ', $fieldName))) ?>
                                        <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                                    </label>
                                    <textarea
                                        class="form-control"
                                        name="<?= h($fieldName) ?>"
                                        id="<?= h($fieldName) ?>"
                                        rows="4"
                                        <?= $required ? 'required' : '' ?>
                                    ></textarea>
                                    <?php if ($description): ?>
                                    <div class="form-text"><?= h($description) ?></div>
                                    <?php endif; ?>
                                </div>

                            <?php else: ?>
                                <!-- Default text input -->
                                <div class="mb-3">
                                    <label for="<?= h($fieldName) ?>" class="form-label">
                                        <?= h(ucwords(str_replace('_', ' ', $fieldName))) ?>
                                        <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                                    </label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        name="<?= h($fieldName) ?>"
                                        id="<?= h($fieldName) ?>"
                                        <?= $required ? 'required' : '' ?>
                                    >
                                    <?php if ($description): ?>
                                    <div class="form-text"><?= h($description) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php endforeach; ?>

                            <!-- Attachments upload -->
                            <div class="mb-4">
                                <label class="form-label">
                                    <i class="bi bi-paperclip me-1"></i> Attachments
                                    <span class="text-muted small">(optional - screenshots, files)</span>
                                </label>
                                <div class="upload-zone" id="uploadZone">
                                    <i class="bi bi-cloud-upload display-6 text-muted"></i>
                                    <p class="mb-0 mt-2 text-muted">Drop files here or click to upload</p>
                                    <small class="text-muted">PNG, JPG, GIF, PDF up to 5MB</small>
                                    <input type="file" name="attachments[]" id="fileInput" multiple accept="image/*,.pdf" class="d-none">
                                </div>
                                <div id="attachmentPreviews" class="mt-2"></div>
                            </div>

                        <?php else: ?>
                            <!-- No schema - freeform input with editor -->
                            <div class="mb-4">
                                <label for="response" class="form-label">
                                    <i class="bi bi-pencil-square me-1"></i>
                                    Your Response <span class="text-danger">*</span>
                                </label>
                                <textarea class="form-control d-none" name="response" id="response" required></textarea>
                                <div id="editor"></div>
                            </div>
                        <?php endif; ?>

                        <!-- Action Buttons -->
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="submit" class="btn btn-primary btn-lg flex-grow-1" id="submitBtn" onclick="setAction('instructions')">
                                <i class="bi bi-send"></i> Send Instructions
                            </button>
                            <button type="button" class="btn btn-outline-success btn-lg" id="completeBtn" onclick="markComplete()">
                                <i class="bi bi-check-circle"></i> Mark Complete
                            </button>
                        </div>
                        <div class="form-text text-center mt-2">
                            <small>Use "Mark Complete" when you're done with this agent session</small>
                        </div>
                    </form>
                </div>

                <div class="card-footer text-muted small d-flex justify-content-between">
                    <span>
                        <i class="bi bi-tag"></i>
                        Run: <code class="text-white"><?= h(substr($run_uid, 0, 16)) ?>...</code>
                    </span>
                    <span>
                        <i class="bi bi-clock"></i>
                        <?= date('M j, g:i A') ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/markdown/markdown.min.js"></script>
    <script>
        // Initialize CodeMirror editor
        const editorContainer = document.getElementById('editor');
        const textarea = document.querySelector('textarea[name="message"], textarea[name="response"], textarea[name="instructions"]');
        let editor = null;

        if (editorContainer && textarea) {
            editor = CodeMirror(editorContainer, {
                mode: 'markdown',
                theme: 'dracula',
                lineNumbers: true,
                lineWrapping: true,
                placeholder: 'Enter your instructions or message here...',
                autofocus: true
            });

            // Sync editor content to hidden textarea on change
            editor.on('change', function() {
                textarea.value = editor.getValue();
            });
        }

        // Action handling
        function setAction(action) {
            const actionField = document.getElementById('actionField');
            if (actionField) {
                actionField.value = action;
            }
        }

        async function markComplete() {
            if (!confirm('Are you sure you want to mark this session as complete? The agent will stop working.')) {
                return;
            }

            const form = document.getElementById('inputForm');
            const submitBtn = document.getElementById('submitBtn');
            const completeBtn = document.getElementById('completeBtn');

            submitBtn.disabled = true;
            completeBtn.disabled = true;
            completeBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Completing...';

            try {
                const formData = new FormData(form);
                formData.set('action', 'complete');
                formData.set('ajax', '1');

                // Use getAttribute to avoid conflict with input named "action"
                const formAction = form.getAttribute('action');
                const response = await fetch(formAction, {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    // Show completion message
                    const chatHistory = document.getElementById('chatHistory');
                    chatHistory.innerHTML += `
                        <div class="alert alert-success mt-3">
                            <i class="bi bi-check-circle me-2"></i>
                            Session marked as complete. You can close this window.
                        </div>`;
                    clearInterval(pollInterval);
                } else {
                    showError(result.error || 'Failed to complete session');
                    completeBtn.disabled = false;
                    completeBtn.innerHTML = '<i class="bi bi-check-circle"></i> Mark Complete';
                }
            } catch (err) {
                showError('Failed to complete: ' + err.message);
                completeBtn.disabled = false;
                completeBtn.innerHTML = '<i class="bi bi-check-circle"></i> Mark Complete';
            }
        }

        // AJAX form submission - stay on page
        document.getElementById('inputForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            // Sync editor content before submit
            if (editor) {
                textarea.value = editor.getValue();
            }

            const message = textarea.value.trim();
            if (!message) {
                alert('Please enter a message');
                return;
            }

            const submitBtn = document.getElementById('submitBtn');
            const completeBtn = document.getElementById('completeBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sending...';
            if (completeBtn) completeBtn.disabled = true;

            // Add message to chat immediately (optimistic UI)
            addMessageToChat('user', message);

            try {
                // Create FormData BEFORE clearing the editor
                const formData = new FormData(this);
                formData.set('ajax', '1'); // Tell server this is AJAX

                // Now clear the editor
                if (editor) {
                    editor.setValue('');
                }
                textarea.value = '';

                // Use getAttribute to avoid conflict with input named "action"
                const formAction = this.getAttribute('action');
                const response = await fetch(formAction, {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (!result.success) {
                    showError(result.error || 'Failed to send message');
                }

                // Poll immediately for any quick responses
                setTimeout(pollMessages, 1000);

            } catch (err) {
                console.error('Submit error:', err);
                showError('Failed to send message: ' + err.message);
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-send"></i> Send Instructions';
                if (completeBtn) completeBtn.disabled = false;
            }
        });

        function addMessageToChat(role, message) {
            const chatHistory = document.getElementById('chatHistory');
            const noMessages = document.getElementById('noMessages');
            if (noMessages) noMessages.remove();

            const isUser = role === 'user';
            const align = isUser ? 'justify-content-end' : 'justify-content-start';
            const bgClass = isUser ? 'bg-primary text-white' : 'bg-secondary bg-opacity-25';
            const icon = isUser ? '<i class="bi bi-person"></i> You' : '<i class="bi bi-robot"></i> Agent';
            const time = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            const escapedMessage = message.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');

            const div = document.createElement('div');
            div.className = `d-flex mb-2 ${align}`;
            div.innerHTML = `
                <div class="p-2 rounded-3 ${bgClass}" style="max-width: 85%;">
                    <div class="small opacity-75 mb-1">
                        ${icon}
                        <span class="ms-2">${time}</span>
                    </div>
                    <div style="white-space: pre-wrap;">${escapedMessage}</div>
                </div>`;
            chatHistory.appendChild(div);
            chatHistory.scrollTop = chatHistory.scrollHeight;
            lastMessageCount++;
        }

        function showError(message) {
            const chatHistory = document.getElementById('chatHistory');
            const div = document.createElement('div');
            div.className = 'alert alert-danger';
            div.innerHTML = `<i class="bi bi-exclamation-triangle"></i> ${message}`;
            chatHistory.appendChild(div);
            chatHistory.scrollTop = chatHistory.scrollHeight;
        }

        // File upload handling
        const uploadZone = document.getElementById('uploadZone');
        const fileInput = document.getElementById('fileInput');
        const previewsContainer = document.getElementById('attachmentPreviews');

        if (uploadZone && fileInput) {
            uploadZone.addEventListener('click', () => fileInput.click());

            uploadZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                uploadZone.classList.add('dragover');
            });

            uploadZone.addEventListener('dragleave', () => {
                uploadZone.classList.remove('dragover');
            });

            uploadZone.addEventListener('drop', (e) => {
                e.preventDefault();
                uploadZone.classList.remove('dragover');
                handleFiles(e.dataTransfer.files);
            });

            fileInput.addEventListener('change', () => {
                handleFiles(fileInput.files);
            });
        }

        const attachedFiles = [];
        function handleFiles(files) {
            for (const file of files) {
                if (file.size > 5 * 1024 * 1024) {
                    alert('File too large: ' + file.name + ' (max 5MB)');
                    continue;
                }
                attachedFiles.push(file);
                renderPreviews();
            }
        }

        function renderPreviews() {
            previewsContainer.innerHTML = '';
            attachedFiles.forEach((file, index) => {
                const item = document.createElement('div');
                item.className = 'attachment-item';

                if (file.type.startsWith('image/')) {
                    const img = document.createElement('img');
                    img.className = 'attachment-preview';
                    img.src = URL.createObjectURL(file);
                    item.appendChild(img);
                } else {
                    const icon = document.createElement('div');
                    icon.className = 'attachment-preview bg-secondary d-flex align-items-center justify-content-center';
                    icon.innerHTML = '<i class="bi bi-file-earmark text-white"></i>';
                    item.appendChild(icon);
                }

                const removeBtn = document.createElement('button');
                removeBtn.className = 'btn btn-danger attachment-remove';
                removeBtn.innerHTML = '&times;';
                removeBtn.onclick = () => {
                    attachedFiles.splice(index, 1);
                    renderPreviews();
                };
                item.appendChild(removeBtn);

                previewsContainer.appendChild(item);
            });

            // Update the file input (for form submission)
            const dataTransfer = new DataTransfer();
            attachedFiles.forEach(file => dataTransfer.items.add(file));
            fileInput.files = dataTransfer.files;
        }

        // Countdown timer
        const countdownEl = document.querySelector('.countdown');
        if (countdownEl) {
            const timeout = new Date(countdownEl.dataset.timeout);
            const updateCountdown = () => {
                const now = new Date();
                const diff = timeout - now;
                if (diff <= 0) {
                    countdownEl.innerHTML = '<span class="text-danger">Expired</span>';
                    countdownEl.classList.remove('bg-dark', 'text-light');
                    countdownEl.classList.add('bg-danger', 'text-white');
                    document.getElementById('submitBtn').disabled = true;
                    document.getElementById('completeBtn').disabled = true;
                } else {
                    const hours = Math.floor(diff / 3600000);
                    const mins = Math.floor((diff % 3600000) / 60000);
                    if (hours > 0) {
                        countdownEl.textContent = `${hours}h ${mins}m left`;
                    } else {
                        countdownEl.textContent = `${mins}m left`;
                    }
                }
            };
            updateCountdown();
            setInterval(updateCountdown, 60000);
        }

        // Poll for new messages
        const runId = <?= $run_id ?>;
        const tokenParam = '<?= !empty($token) ? '&token=' . urlencode($token) : '' ?>';
        let lastMessageCount = <?= count($context['_messages'] ?? []) ?>;
        let pollInterval = null;

        function formatTime(timestamp) {
            const date = new Date(timestamp.replace(' ', 'T'));
            return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        }

        function renderMessages(messages) {
            const chatHistory = document.getElementById('chatHistory');
            const noMessages = document.getElementById('noMessages');

            if (messages.length === 0) {
                if (!noMessages) {
                    chatHistory.innerHTML = `
                        <div class="text-center text-muted py-3" id="noMessages">
                            <i class="bi bi-chat-dots display-6"></i>
                            <p class="mb-0 mt-2">No messages yet. Send instructions to start.</p>
                        </div>`;
                }
                return;
            }

            let html = '';
            messages.forEach(msg => {
                const isUser = msg.role === 'user';
                const align = isUser ? 'justify-content-end' : 'justify-content-start';
                const bgClass = isUser ? 'bg-primary text-white' : 'bg-secondary bg-opacity-25';
                const icon = isUser ? '<i class="bi bi-person"></i> You' : '<i class="bi bi-robot"></i> Agent';
                const escapedMessage = msg.message.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');

                html += `
                    <div class="d-flex mb-2 ${align}">
                        <div class="p-2 rounded-3 ${bgClass}" style="max-width: 85%;">
                            <div class="small opacity-75 mb-1">
                                ${icon}
                                <span class="ms-2">${formatTime(msg.timestamp)}</span>
                            </div>
                            <div style="white-space: pre-wrap;">${escapedMessage}</div>
                        </div>
                    </div>`;
            });
            chatHistory.innerHTML = html;
            chatHistory.scrollTop = chatHistory.scrollHeight;
        }

        let lastAgentMessage = '';

        function updateAgentStatus(agentStatus) {
            const statusBar = document.getElementById('agentStatusBar');
            if (!agentStatus || !agentStatus.message) {
                if (statusBar) statusBar.style.display = 'none';
                return;
            }

            // Only update if message changed
            if (agentStatus.message === lastAgentMessage) return;
            lastAgentMessage = agentStatus.message;

            // Show status bar
            if (!statusBar) {
                const chatHistory = document.getElementById('chatHistory');
                const bar = document.createElement('div');
                bar.id = 'agentStatusBar';
                bar.className = 'alert alert-info d-flex align-items-center mb-3';
                bar.innerHTML = `
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                    <div>
                        <strong>Agent:</strong> <span id="agentStatusMessage"></span>
                        <div class="progress mt-1" style="height: 4px;">
                            <div class="progress-bar" id="agentProgress" style="width: 0%"></div>
                        </div>
                    </div>`;
                chatHistory.parentNode.insertBefore(bar, chatHistory);
            }

            document.getElementById('agentStatusMessage').textContent = agentStatus.message;
            document.getElementById('agentProgress').style.width = (agentStatus.progress || 0) + '%';
            document.getElementById('agentStatusBar').style.display = 'flex';
        }

        async function pollMessages() {
            try {
                const response = await fetch(`/pipelines/messages/${runId}?_=${Date.now()}${tokenParam}`);
                if (!response.ok) return;

                const data = await response.json();
                if (data.success && data.data) {
                    const messages = data.data.messages || [];
                    if (messages.length !== lastMessageCount) {
                        lastMessageCount = messages.length;
                        renderMessages(messages);
                    }

                    // Update agent status bar
                    updateAgentStatus(data.data.agent_status);

                    // If status changed to completed, stop polling and show message
                    if (data.data.status === 'completed') {
                        clearInterval(pollInterval);
                        const chatHistory = document.getElementById('chatHistory');
                        chatHistory.innerHTML += `
                            <div class="alert alert-success mt-3">
                                <i class="bi bi-check-circle me-2"></i>
                                Session completed. You can close this window.
                            </div>`;
                    }
                }
            } catch (e) {
                console.error('Poll error:', e);
            }
        }

        // Start polling every 5 seconds
        pollInterval = setInterval(pollMessages, 5000);
        // Also poll immediately on page load
        setTimeout(pollMessages, 1000);
    </script>
</body>
</html>
