<?php
/**
 * Knowledge Base Index
 * Document management for RAG chatbot
 */
?>

<div class="container py-4" data-assist-page="knowledgebase" data-assist-purpose="Upload and manage knowledge base documents for RAG">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Knowledge Base</h1>
            <p class="text-muted mb-0">Upload documents to train your AI assistant</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <!-- KB Selector -->
            <div class="input-group" style="width: auto;">
                <select class="form-select" id="kbSelector" onchange="switchKnowledgeBase(this.value)" style="min-width: 180px;">
                    <?php if (empty($knowledgeBases)): ?>
                    <option value="">-- Create a KB first --</option>
                    <?php else: ?>
                    <?php foreach ($knowledgeBases as $kb): ?>
                    <option value="<?= $kb->id ?>" <?= ($selectedKbId == $kb->id) ? 'selected' : '' ?>>
                        <?= h($kb->name) ?>
                    </option>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#createKbModal" title="Create new KB">
                    <i class="bi bi-plus-lg"></i>
                </button>
                <?php if ($selectedKb && $selectedKb->id): ?>
                <button class="btn btn-outline-danger" type="button" onclick="deleteKnowledgeBase(<?= $selectedKb->id ?>, '<?= h($selectedKb->name) ?>')" title="Delete this KB">
                    <i class="bi bi-trash"></i>
                </button>
                <?php endif; ?>
            </div>
            <?php if ($selectedKbId): ?>
            <a href="/knowledgebase/query?kb=<?= $selectedKbId ?>" class="btn btn-outline-info">
                <i class="bi bi-search me-1"></i> Query
            </a>
            <?php endif; ?>
            <?php if ($chatAvailable && $selectedKbId): ?>
            <a href="/knowledgebase/chat?kb=<?= $selectedKbId ?>" class="btn btn-outline-primary">
                <i class="bi bi-chat-dots me-1"></i> Chat
            </a>
            <?php endif; ?>
            <?php if ($selectedKbId): ?>
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editKbModal" title="Edit KB settings">
                <i class="bi bi-gear"></i>
            </button>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                <i class="bi bi-upload me-1"></i> Upload Document
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($selectedKb): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <?php if ($selectedKb->description): ?>
                    <div class="mb-2">
                        <i class="bi bi-info-circle me-2 text-muted"></i>
                        <?= h($selectedKb->description) ?>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex align-items-center gap-3 small">
                        <?php if ($selectedKb->slug): ?>
                        <span class="text-muted" title="API/MCP slug">
                            <i class="bi bi-link-45deg"></i> slug: <code><?= h($selectedKb->slug) ?></code>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-end">
                    <?php if ($selectedAgent): ?>
                    <div class="d-flex align-items-center gap-2">
                        <div class="text-muted small">Powered by:</div>
                        <button type="button" class="btn btn-link text-decoration-none p-0" data-bs-toggle="modal" data-bs-target="#editKbModal">
                            <div class="d-flex align-items-center bg-light rounded px-3 py-2">
                                <i class="bi bi-robot me-2"></i>
                                <div class="text-start">
                                    <div class="fw-medium text-dark"><?= h($selectedAgent['name']) ?></div>
                                    <div class="small text-muted">
                                        <?= h($selectedAgent['provider_label']) ?>
                                        <?php if ($selectedAgent['model']): ?>
                                        / <?= h($selectedAgent['model']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <i class="bi bi-pencil ms-2 text-muted"></i>
                            </div>
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="text-warning small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        No agent assigned
                        <button class="btn btn-sm btn-link p-0 ms-1" data-bs-toggle="modal" data-bs-target="#editKbModal">
                            Assign one
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Storage Usage -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="d-flex align-items-center mb-2">
                        <span class="text-muted me-2">Storage Used:</span>
                        <strong><?= number_format($usage['total_mb'], 1) ?> MB</strong>
                        <span class="text-muted mx-1">of</span>
                        <strong><?= $maxStorageMB ?> MB</strong>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <?php
                        $usagePercent = min(100, ($usage['total_mb'] / $maxStorageMB) * 100);
                        $progressClass = $usagePercent > 90 ? 'bg-danger' : ($usagePercent > 70 ? 'bg-warning' : 'bg-primary');
                        ?>
                        <div class="progress-bar <?= $progressClass ?>" role="progressbar"
                             style="width: <?= $usagePercent ?>%"
                             aria-valuenow="<?= $usagePercent ?>" aria-valuemin="0" aria-valuemax="100">
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                    <span class="badge bg-secondary fs-6">
                        <?= $usage['document_count'] ?> document<?= $usage['document_count'] !== 1 ? 's' : '' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Documents List -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Documents</h5>
            <div class="input-group" style="max-width: 250px;">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control border-start-0" id="searchDocs" placeholder="Search...">
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($documents)): ?>
            <div class="text-center py-5">
                <i class="bi bi-file-earmark-text display-1 text-muted"></i>
                <h5 class="mt-3">No Documents Yet</h5>
                <p class="text-muted">Upload PDFs, Word docs, images, or import web pages to build your knowledge base.</p>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                    <i class="bi bi-upload me-1"></i> Upload Your First Document
                </button>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="documentsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Document</th>
                            <th>Type</th>
                            <th>Size</th>
                            <th>Chunks</th>
                            <th>Status</th>
                            <th>Added</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $doc): ?>
                        <?php
                            $isProcessing = in_array($doc->status, ['pending', 'processing']);
                            $jobId = $doc->job_uid ?? '';
                        ?>
                        <tr data-doc-id="<?= $doc->id ?>" data-job-id="<?= h($jobId) ?>" data-status="<?= $doc->status ?>">
                            <td>
                                <div class="d-flex align-items-center">
                                    <?= getFileIcon($doc->file_extension) ?>
                                    <div class="ms-2">
                                        <div class="fw-medium"><?= h($doc->filename) ?></div>
                                        <?php if (!empty($doc->source_url)): ?>
                                        <small class="text-muted"><?= h(parse_url($doc->source_url, PHP_URL_HOST)) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge bg-light text-dark"><?= strtoupper($doc->file_extension) ?></span></td>
                            <td><?= formatFileSize($doc->file_size) ?></td>
                            <td class="chunk-count"><?= $doc->chunk_count ?? '-' ?></td>
                            <td class="status-cell">
                                <?php if ($doc->status === 'ready'): ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Ready</span>
                                <?php elseif ($isProcessing): ?>
                                <div class="d-flex align-items-center">
                                    <span class="badge bg-warning text-dark me-2">
                                        <span class="spinner-border spinner-border-sm me-1" style="width: 0.7rem; height: 0.7rem;"></span>
                                        Processing
                                    </span>
                                </div>
                                <?php if ($doc->vision_model): ?>
                                <div class="small text-muted mt-1" style="font-size: 0.65rem;">
                                    <i class="bi bi-eye me-1"></i><?= h($doc->vision_model) ?>
                                </div>
                                <?php endif; ?>
                                <?php else: ?>
                                <?php
                                    $errorMsg = $doc->error_message ?? 'Unknown error';
                                    // Simplify common error messages for display
                                    if (strpos($errorMsg, 'cURL error 7') !== false || strpos($errorMsg, "Couldn't connect") !== false) {
                                        $shortError = 'RAG service unavailable';
                                    } elseif (strpos($errorMsg, 'timeout') !== false) {
                                        $shortError = 'Processing timeout';
                                    } else {
                                        $shortError = strlen($errorMsg) > 30 ? substr($errorMsg, 0, 30) . '...' : $errorMsg;
                                    }
                                ?>
                                <span class="badge bg-danger"
                                      data-bs-toggle="tooltip"
                                      data-bs-placement="top"
                                      title="<?= h($errorMsg) ?>">
                                    <i class="bi bi-exclamation-circle me-1"></i>Error
                                </span>
                                <div class="small text-danger mt-1" style="font-size: 0.7rem; max-width: 120px;">
                                    <?= h($shortError) ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td><small class="text-muted"><?= date('M j, Y', strtotime($doc->created_at)) ?></small></td>
                            <td>
                                <div class="dropup">
                                    <button class="btn btn-link text-muted p-0" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <button class="dropdown-item text-danger" onclick="deleteDocument(<?= $doc->id ?>)">
                                                <i class="bi bi-trash me-2"></i>Delete
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Upload Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title">Upload Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Upload Tabs -->
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#uploadFile" type="button">
                            <i class="bi bi-file-earmark-arrow-up me-1"></i>File
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#uploadUrl" type="button">
                            <i class="bi bi-link-45deg me-1"></i>URL
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- File Upload -->
                    <div class="tab-pane fade show active" id="uploadFile">
                        <form id="fileUploadForm" enctype="multipart/form-data">
                            <div class="upload-zone border-2 border-dashed rounded p-4 text-center" id="dropZone">
                                <i class="bi bi-cloud-arrow-up display-4 text-muted"></i>
                                <p class="mt-2 mb-1">Drag and drop files here or click to browse</p>
                                <small class="text-muted">PDF, DOCX, DOC, TXT, PNG, JPG (max <?= ini_get('upload_max_filesize') ?> each) - Multiple files supported</small>
                                <input type="file" class="d-none" id="fileInput" name="document" multiple
                                       accept=".pdf,.docx,.doc,.txt,.png,.jpg,.jpeg,.gif,.html,.htm">
                            </div>
                            <div id="selectedFiles" class="mt-3 d-none">
                                <div id="fileList" class="list-group list-group-flush"></div>
                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <small class="text-muted"><span id="fileCount">0</span> file(s) selected</small>
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="clearFiles()">
                                        Clear all
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- URL Import -->
                    <div class="tab-pane fade" id="uploadUrl">
                        <form id="urlUploadForm">
                            <div class="mb-3">
                                <label class="form-label">Web Page URL</label>
                                <input type="url" class="form-control" id="urlInput" name="url"
                                       placeholder="https://example.com/article">
                                <div class="form-text">Import content from any public web page</div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Progress -->
                <div id="uploadProgress" class="d-none mt-3">
                    <div class="progress" style="height: 4px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%"></div>
                    </div>
                    <small id="uploadProgressText" class="text-muted mt-1 d-block">Uploading document...</small>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="uploadBtn" onclick="handleUpload()">
                    <i class="bi bi-upload me-1"></i>Upload
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Create Knowledge Base Modal -->
<div class="modal fade" id="createKbModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title">Create Knowledge Base</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="createKbForm">
                    <div class="mb-3">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="kbName" name="name" required
                               placeholder="e.g., FAQ, Product Docs, API Reference">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" id="kbDescription" name="description" rows="2"
                                  placeholder="What kind of documents will this KB contain?"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Agent Profile (LLM)</label>
                        <select class="form-select" id="kbAgentProfile" name="agent_profile_id">
                            <option value="">-- Select Agent --</option>
                            <?php foreach ($agentProfiles as $agent): ?>
                            <option value="<?= $agent['id'] ?>">
                                <?= h($agent['name']) ?>
                                (<?= h($agent['provider_label']) ?><?= $agent['model'] ? ' / ' . h($agent['model']) : '' ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Select which AI agent will power this knowledge base</div>
                    </div>
                    <div class="alert alert-info small mb-0">
                        <i class="bi bi-lightbulb me-1"></i>
                        Each knowledge base gets a unique slug for API/MCP tool access.
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="createKnowledgeBase()">
                    <i class="bi bi-plus-lg me-1"></i>Create
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Knowledge Base Modal -->
<?php if ($selectedKb): ?>
<div class="modal fade" id="editKbModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title">Edit Knowledge Base</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editKbForm">
                    <input type="hidden" id="editKbId" value="<?= $selectedKb->id ?>">
                    <div class="mb-3">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="editKbName" name="name" required
                               value="<?= h($selectedKb->name) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" id="editKbDescription" name="description" rows="2"
                        ><?= h($selectedKb->description ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Agent Profile (LLM)</label>
                        <select class="form-select" id="editKbAgentProfile" name="agent_profile_id">
                            <option value="">-- Select Agent --</option>
                            <?php foreach ($agentProfiles as $agent): ?>
                            <option value="<?= $agent['id'] ?>" <?= ($selectedKb->agent_profile_id == $agent['id']) ? 'selected' : '' ?>>
                                <?= h($agent['name']) ?>
                                (<?= h($agent['provider_label']) ?><?= $agent['model'] ? ' / ' . h($agent['model']) : '' ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Select which AI agent will power this knowledge base</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Slug (read-only)</label>
                        <input type="text" class="form-control" value="<?= h($selectedKb->slug) ?>" readonly disabled>
                        <div class="form-text">Used for API/MCP access</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="updateKnowledgeBase()">
                    <i class="bi bi-check-lg me-1"></i>Save Changes
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
.upload-zone {
    cursor: pointer;
    transition: all 0.2s;
    border-color: #dee2e6 !important;
}
.upload-zone:hover,
.upload-zone.dragover {
    border-color: #0d6efd !important;
    background-color: rgba(13, 110, 253, 0.05);
}
.border-dashed {
    border-style: dashed !important;
}
.agent-link-box {
    transition: all 0.2s;
}
.agent-link-box:hover {
    background-color: #e9ecef !important;
    transform: translateX(3px);
}
</style>

<script>
// Pass selected KB ID from PHP to JavaScript
const selectedKbId = <?= json_encode($selectedKbId) ?>;
let selectedFiles = []; // Store selected files for multi-upload
let uploadQueue = []; // Queue for sequential processing
let isUploading = false;

document.addEventListener('DOMContentLoaded', function() {
    // Initialize Bootstrap tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');

    // Click to browse
    dropZone.addEventListener('click', () => fileInput.click());

    // Drag and drop
    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('dragover');
    });

    dropZone.addEventListener('dragleave', () => {
        dropZone.classList.remove('dragover');
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        if (e.dataTransfer.files.length > 0) {
            addFiles(e.dataTransfer.files);
        }
    });

    // File selected
    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) {
            addFiles(fileInput.files);
        }
    });

    // Search filter
    document.getElementById('searchDocs')?.addEventListener('input', function() {
        const search = this.value.toLowerCase();
        document.querySelectorAll('#documentsTable tbody tr').forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(search) ? '' : 'none';
        });
    });

    // Start polling for any documents already processing
    startProcessingDocumentPolling();
});

// Add multiple files to selection
function addFiles(files) {
    for (let i = 0; i < files.length; i++) {
        // Avoid duplicates
        if (!selectedFiles.find(f => f.name === files[i].name && f.size === files[i].size)) {
            selectedFiles.push(files[i]);
        }
    }
    updateFileList();
}

// Update the file list display
function updateFileList() {
    const container = document.getElementById('selectedFiles');
    const listEl = document.getElementById('fileList');
    const countEl = document.getElementById('fileCount');

    if (selectedFiles.length === 0) {
        container.classList.add('d-none');
        return;
    }

    container.classList.remove('d-none');
    countEl.textContent = selectedFiles.length;

    listEl.innerHTML = selectedFiles.map((file, index) => `
        <div class="list-group-item d-flex justify-content-between align-items-center py-1 px-2">
            <span class="small text-truncate" style="max-width: 200px;">${escapeHtml(file.name)}</span>
            <button type="button" class="btn btn-link text-danger p-0 btn-sm" onclick="removeFile(${index})">
                <i class="bi bi-x"></i>
            </button>
        </div>
    `).join('');
}

// Remove a file from selection
function removeFile(index) {
    selectedFiles.splice(index, 1);
    updateFileList();
}

// Clear all files
function clearFiles() {
    selectedFiles = [];
    document.getElementById('fileInput').value = '';
    updateFileList();
}

// Escape HTML for safe display
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function handleUpload() {
    const activeTab = document.querySelector('.tab-pane.active').id;
    const uploadBtn = document.getElementById('uploadBtn');
    const progress = document.getElementById('uploadProgress');
    const progressText = document.getElementById('uploadProgressText');

    if (activeTab === 'uploadFile') {
        if (selectedFiles.length === 0) {
            alert('Please select at least one file');
            return;
        }

        if (!selectedKbId) {
            alert('Please select or create a knowledge base first');
            return;
        }

        // Show progress briefly then close modal
        uploadBtn.disabled = true;
        progress.classList.remove('d-none');
        progressText.textContent = `Uploading ${selectedFiles.length} file(s)...`;

        // Start uploads and close modal
        startMultiUpload();
    } else {
        uploadUrl();
    }
}

// Start multi-file upload with sequential processing
async function startMultiUpload() {
    const filesToUpload = [...selectedFiles];
    const progressText = document.getElementById('uploadProgressText');
    let uploadedCount = 0;
    let errorCount = 0;
    let errorMessages = [];

    // Queue all files for upload
    for (let i = 0; i < filesToUpload.length; i++) {
        const file = filesToUpload[i];
        progressText.textContent = `Uploading ${i + 1}/${filesToUpload.length}: ${file.name}...`;

        try {
            const result = await uploadSingleFile(file);
            if (result.success && result.data) {
                uploadedCount++;
                // Add a row for this document immediately
                if (result.data.id) {
                    addDocumentRow(result.data);
                }
            } else {
                errorCount++;
                const errMsg = result.message || result.error || 'Unknown error';
                errorMessages.push(`${file.name}: ${errMsg}`);
                console.error('Upload failed for', file.name, errMsg);
            }
        } catch (err) {
            errorCount++;
            errorMessages.push(`${file.name}: ${err.message || err}`);
            console.error('Upload failed for', file.name, err);
        }
    }

    // Close modal and reset
    const modal = bootstrap.Modal.getInstance(document.getElementById('uploadModal'));
    if (modal) modal.hide();
    resetUpload();
    clearFiles();

    // Show toast notification instead of reload
    let toastMessage = '';
    if (uploadedCount > 0) {
        toastMessage = `${uploadedCount} file(s) uploaded and processing in background.`;
    }
    if (errorCount > 0) {
        toastMessage += (toastMessage ? ' ' : '') + `${errorCount} failed: ${errorMessages.join('; ')}`;
    }
    if (!toastMessage) {
        toastMessage = 'No files were uploaded.';
    }

    showToast(toastMessage, errorCount > 0 ? 'warning' : 'success');

    // Start polling for newly added processing documents
    if (uploadedCount > 0) {
        startProcessingDocumentPolling();
    }
}

// Show a Bootstrap toast notification
function showToast(message, type = 'info') {
    // Create toast container if it doesn't exist
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        container.style.zIndex = '1100';
        document.body.appendChild(container);
    }

    const toastId = 'toast-' + Date.now();
    const bgClass = {
        'success': 'bg-success',
        'warning': 'bg-warning',
        'error': 'bg-danger',
        'info': 'bg-primary'
    }[type] || 'bg-primary';

    const textClass = type === 'warning' ? 'text-dark' : 'text-white';

    const toastHtml = `
        <div id="${toastId}" class="toast ${bgClass} ${textClass}" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">${escapeHtml(message)}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    `;

    container.insertAdjacentHTML('beforeend', toastHtml);
    const toastEl = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastEl, { delay: 6000 });
    toast.show();

    // Remove element after hidden
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}

// Upload a single file (returns promise)
function uploadSingleFile(file) {
    return new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.append('document', file);
        formData.append('kb_id', selectedKbId);

        fetch('/knowledgebase/upload', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => resolve(data))
        .catch(err => reject(err));
    });
}

// Add a document row to the table (for newly uploaded docs)
function addDocumentRow(doc) {
    const tbody = document.querySelector('#documentsTable tbody');
    if (!tbody) return;

    const row = document.createElement('tr');
    row.setAttribute('data-doc-id', doc.id);
    row.setAttribute('data-job-id', doc.job_uid || '');
    row.setAttribute('data-status', doc.status || 'processing');

    const ext = doc.filename.split('.').pop().toLowerCase();
    const icon = getFileIconHtml(ext);
    const visionInfo = doc.vision_model ? `<div class="small text-muted mt-1" style="font-size: 0.65rem;"><i class="bi bi-eye me-1"></i>${escapeHtml(doc.vision_model)}</div>` : '';

    row.innerHTML = `
        <td>
            <div class="d-flex align-items-center">
                ${icon}
                <div class="ms-2">
                    <div class="fw-medium">${escapeHtml(doc.filename)}</div>
                </div>
            </div>
        </td>
        <td><span class="badge bg-light text-dark">${ext.toUpperCase()}</span></td>
        <td>-</td>
        <td class="chunk-count">-</td>
        <td class="status-cell">
            <div class="d-flex align-items-center">
                <span class="badge bg-warning text-dark me-2">
                    <span class="spinner-border spinner-border-sm me-1" style="width: 0.7rem; height: 0.7rem;"></span>
                    Processing
                </span>
            </div>
            ${visionInfo}
        </td>
        <td><small class="text-muted">${new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</small></td>
        <td>
            <div class="dropup">
                <button class="btn btn-link text-muted p-0" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-three-dots-vertical"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><button class="dropdown-item text-danger" onclick="deleteDocument(${doc.id})"><i class="bi bi-trash me-2"></i>Delete</button></li>
                </ul>
            </div>
        </td>
    `;

    // Insert at top of table
    tbody.insertBefore(row, tbody.firstChild);
}

function getFileIconHtml(ext) {
    const icons = {
        'pdf': '<i class="bi bi-file-pdf text-danger fs-4"></i>',
        'docx': '<i class="bi bi-file-word text-primary fs-4"></i>',
        'doc': '<i class="bi bi-file-word text-primary fs-4"></i>',
        'txt': '<i class="bi bi-file-text text-secondary fs-4"></i>',
        'png': '<i class="bi bi-file-image text-success fs-4"></i>',
        'jpg': '<i class="bi bi-file-image text-success fs-4"></i>',
        'jpeg': '<i class="bi bi-file-image text-success fs-4"></i>',
        'gif': '<i class="bi bi-file-image text-success fs-4"></i>',
        'html': '<i class="bi bi-globe text-info fs-4"></i>',
        'htm': '<i class="bi bi-globe text-info fs-4"></i>',
    };
    return icons[ext] || '<i class="bi bi-file-earmark fs-4"></i>';
}

// Poll processing documents in the table with different intervals
let pollTimers = {}; // Track poll timers per job

function startProcessingDocumentPolling() {
    const processingRows = document.querySelectorAll('tr[data-status="processing"], tr[data-status="pending"]');
    if (processingRows.length === 0) return;

    // Poll immediately, then schedule based on status
    pollProcessingDocuments();
}

function scheduleNextPoll(jobId, status) {
    // Clear existing timer
    if (pollTimers[jobId]) {
        clearTimeout(pollTimers[jobId]);
    }

    // Different intervals: 5s for processing, 30s for queued
    const interval = (status === 'queued') ? 30000 : 5000;

    pollTimers[jobId] = setTimeout(() => {
        const row = document.querySelector(`tr[data-job-id="${jobId}"]`);
        if (row) {
            const currentStatus = row.getAttribute('data-status');
            if (currentStatus === 'processing' || currentStatus === 'pending') {
                pollSingleJob(jobId, row);
            }
        }
    }, interval);
}

function pollProcessingDocuments() {
    const processingRows = document.querySelectorAll('tr[data-status="processing"], tr[data-status="pending"]');
    processingRows.forEach(row => {
        const jobId = row.getAttribute('data-job-id');
        const docId = row.getAttribute('data-doc-id');

        if (jobId) {
            pollSingleJob(jobId, row);
        } else if (docId) {
            pollDocumentStatus(docId, row);
        }
    });
}

function pollSingleJob(jobId, row) {
    fetch('/knowledgebase/polljob/' + jobId)
    .then(res => res.json())
    .then(data => {
        if (data.success && data.data) {
            updateRowStatus(row, data.data);
            // Schedule next poll based on status (unless completed/error)
            const status = data.data.status;
            if (status === 'processing' || status === 'queued') {
                scheduleNextPoll(jobId, status);
            }
        } else {
            // Error - retry in 10 seconds
            scheduleNextPoll(jobId, 'processing');
        }
    })
    .catch(err => {
        console.error('Poll error:', err);
        // Retry in 10 seconds on error
        scheduleNextPoll(jobId, 'processing');
    });
}

function pollDocumentStatus(docId, row) {
    fetch('/knowledgebase/status/' + docId)
    .then(res => res.json())
    .then(data => {
        if (data.success && data.data) {
            updateRowStatus(row, data.data);
        }
    })
    .catch(err => console.error('Status error:', err));
}

function updateRowStatus(row, data) {
    const statusCell = row.querySelector('.status-cell');
    const chunkCell = row.querySelector('.chunk-count');

    if (data.status === 'ready') {
        row.setAttribute('data-status', 'ready');
        statusCell.innerHTML = '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Ready</span>';
        if (data.chunk_count !== undefined) {
            chunkCell.textContent = data.chunk_count || '-';
        }
    } else if (data.status === 'error') {
        row.setAttribute('data-status', 'error');
        const errorMsg = data.error || data.error_message || 'Unknown error';
        statusCell.innerHTML = `
            <span class="badge bg-danger" title="${escapeHtml(errorMsg)}">
                <i class="bi bi-exclamation-circle me-1"></i>Error
            </span>
            <div class="small text-danger mt-1" style="font-size: 0.7rem; max-width: 120px;">
                ${escapeHtml(errorMsg.substring(0, 30))}${errorMsg.length > 30 ? '...' : ''}
            </div>
        `;
    } else if (data.status === 'queued') {
        row.setAttribute('data-status', 'processing'); // Keep polling
        const queuePos = data.queue_position || '?';
        statusCell.innerHTML = `
            <div class="d-flex align-items-center">
                <span class="badge bg-info text-dark me-2">
                    <i class="bi bi-hourglass me-1"></i>
                    Queued (#${queuePos})
                </span>
            </div>
            <div class="small text-muted mt-1" style="font-size: 0.65rem;">
                Waiting for other documents
            </div>
        `;
    } else if (data.status === 'processing') {
        row.setAttribute('data-status', 'processing');
        statusCell.innerHTML = `
            <div class="d-flex align-items-center">
                <span class="badge bg-warning text-dark me-2">
                    <span class="spinner-border spinner-border-sm me-1" style="width: 0.7rem; height: 0.7rem;"></span>
                    Processing
                </span>
            </div>
        `;
    }
    // Keep polling for queued/processing status
}

function uploadUrl() {
    const url = document.getElementById('urlInput').value;

    if (!url) {
        alert('Please enter a URL');
        resetUpload();
        return;
    }

    if (!selectedKbId) {
        alert('Please select or create a knowledge base first');
        resetUpload();
        return;
    }

    fetch('/knowledgebase/uploadurl', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ url: url, kb_id: selectedKbId })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.error || 'Import failed');
            resetUpload();
        }
    })
    .catch(err => {
        alert('Import error: ' + err.message);
        resetUpload();
    });
}

function resetUpload() {
    document.getElementById('uploadBtn').disabled = false;
    document.getElementById('uploadProgress').classList.add('d-none');
}

function deleteDocument(id) {
    if (!confirm('Are you sure you want to delete this document? This will remove it from the knowledge base.')) {
        return;
    }

    fetch('/knowledgebase/delete', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id: id })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            document.querySelector(`tr[data-doc-id="${id}"]`)?.remove();
        } else {
            alert(data.error || 'Delete failed');
        }
    })
    .catch(err => alert('Error: ' + err.message));
}

// Knowledge Base Management Functions
function switchKnowledgeBase(kbId) {
    if (kbId) {
        window.location.href = '/knowledgebase?kb=' + kbId;
    }
}

function createKnowledgeBase() {
    const name = document.getElementById('kbName').value.trim();
    const description = document.getElementById('kbDescription').value.trim();
    const agentProfileId = document.getElementById('kbAgentProfile').value;

    if (!name) {
        alert('Please enter a name for the knowledge base');
        return;
    }

    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Creating...';

    fetch('/knowledgebase/createkb', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ name: name, description: description, agent_profile_id: agentProfileId || null })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success && data.data && data.data.id) {
            window.location.href = '/knowledgebase?kb=' + data.data.id;
        } else {
            alert(data.message || data.error || 'Failed to create knowledge base');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Create';
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Create';
    });
}

function deleteKnowledgeBase(id, name) {
    if (!confirm('Are you sure you want to delete the knowledge base "' + name + '"?\n\nThis will permanently delete all documents in this KB.')) {
        return;
    }

    fetch('/knowledgebase/deletekb', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id: id })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            window.location.href = '/knowledgebase';
        } else {
            alert(data.error || 'Failed to delete knowledge base');
        }
    })
    .catch(err => alert('Error: ' + err.message));
}

function updateKnowledgeBase() {
    const id = document.getElementById('editKbId').value;
    const name = document.getElementById('editKbName').value.trim();
    const description = document.getElementById('editKbDescription').value.trim();
    const agentProfileId = document.getElementById('editKbAgentProfile').value;

    if (!name) {
        alert('Please enter a name for the knowledge base');
        return;
    }

    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

    fetch('/knowledgebase/updatekb', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            id: id,
            name: name,
            description: description,
            agent_profile_id: agentProfileId || null
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || data.error || 'Failed to update knowledge base');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Changes';
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Changes';
    });
}
</script>

<?php
// Helper functions for the view
function getFileIcon($ext) {
    $icons = [
        'pdf' => '<i class="bi bi-file-pdf text-danger fs-4"></i>',
        'docx' => '<i class="bi bi-file-word text-primary fs-4"></i>',
        'doc' => '<i class="bi bi-file-word text-primary fs-4"></i>',
        'txt' => '<i class="bi bi-file-text text-secondary fs-4"></i>',
        'png' => '<i class="bi bi-file-image text-success fs-4"></i>',
        'jpg' => '<i class="bi bi-file-image text-success fs-4"></i>',
        'jpeg' => '<i class="bi bi-file-image text-success fs-4"></i>',
        'gif' => '<i class="bi bi-file-image text-success fs-4"></i>',
        'html' => '<i class="bi bi-globe text-info fs-4"></i>',
        'htm' => '<i class="bi bi-globe text-info fs-4"></i>',
    ];
    return $icons[strtolower($ext)] ?? '<i class="bi bi-file-earmark fs-4"></i>';
}

function formatFileSize($bytes) {
    if ($bytes == 0) return '-';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 1) . ' MB';
}
?>
