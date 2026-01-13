<?php
/**
 * Knowledge Base Query Interface
 * RAG query builder with customizable options
 */
?>

<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Query Knowledge Base</h1>
            <p class="text-muted mb-0">Search and query your documents using RAG</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="/knowledgebase<?= $selectedKbId ? '?kb=' . $selectedKbId : '' ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to Documents
            </a>
        </div>
    </div>

    <div class="row">
        <!-- Query Builder Panel -->
        <div class="col-lg-4 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-sliders me-2"></i>Query Builder</h5>
                </div>
                <div class="card-body">
                    <form id="queryForm">
                        <!-- Knowledge Base Selection -->
                        <div class="mb-3">
                            <label class="form-label fw-medium">Knowledge Base</label>
                            <select class="form-select" id="queryKbId" name="kb_id" required>
                                <option value="">-- Select KB --</option>
                                <?php foreach ($knowledgeBases as $kb): ?>
                                <option value="<?= $kb->id ?>" <?= ($selectedKbId == $kb->id) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($kb->name) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Query Input -->
                        <div class="mb-3">
                            <label class="form-label fw-medium">Query</label>
                            <textarea class="form-control" id="queryText" name="query" rows="3"
                                      placeholder="Enter your question or search query..."
                                      required></textarea>
                        </div>

                        <!-- Advanced Options -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label fw-medium mb-0">Options</label>
                                <button type="button" class="btn btn-link btn-sm p-0" data-bs-toggle="collapse" data-bs-target="#advancedOptions">
                                    <i class="bi bi-gear"></i> Advanced
                                </button>
                            </div>

                            <div class="collapse" id="advancedOptions">
                                <div class="card card-body bg-light border-0 small">
                                    <!-- Similarity Threshold -->
                                    <div class="mb-3">
                                        <label class="form-label">Similarity Threshold</label>
                                        <input type="range" class="form-range" id="similarityThreshold"
                                               min="0" max="1" step="0.05" value="0.4"
                                               oninput="document.getElementById('thresholdValue').textContent = this.value">
                                        <div class="d-flex justify-content-between">
                                            <small class="text-muted">Less strict</small>
                                            <small id="thresholdValue" class="fw-medium">0.4</small>
                                            <small class="text-muted">More strict</small>
                                        </div>
                                    </div>

                                    <!-- Max Results -->
                                    <div class="mb-3">
                                        <label class="form-label">Max Results</label>
                                        <select class="form-select form-select-sm" id="maxResults">
                                            <option value="3">3 results</option>
                                            <option value="5" selected>5 results</option>
                                            <option value="10">10 results</option>
                                            <option value="20">20 results</option>
                                        </select>
                                    </div>

                                    <!-- Include Metadata -->
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="includeMetadata" checked>
                                        <label class="form-check-label" for="includeMetadata">
                                            Include source metadata
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100" id="queryBtn">
                            <i class="bi bi-search me-1"></i> Execute Query
                        </button>
                    </form>
                </div>
            </div>

            <!-- Selected KB Info -->
            <?php if ($selectedKb && $selectedAgent): ?>
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-robot fs-4 text-primary me-3"></i>
                        <div>
                            <div class="fw-medium"><?= htmlspecialchars($selectedAgent['name']) ?></div>
                            <div class="small text-muted">
                                <?= htmlspecialchars($selectedAgent['provider_label']) ?>
                                <?php if ($selectedAgent['model']): ?>
                                / <?= htmlspecialchars($selectedAgent['model']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Results Panel -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Results</h5>
                    <span class="badge bg-secondary" id="resultCount">0 results</span>
                </div>
                <div class="card-body" id="resultsContainer">
                    <div class="text-center text-muted py-5" id="emptyState">
                        <i class="bi bi-search display-4"></i>
                        <p class="mt-3">Enter a query and click "Execute Query" to search your knowledge base.</p>
                    </div>

                    <!-- Loading State -->
                    <div class="text-center py-5 d-none" id="loadingState">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-3 text-muted">Searching knowledge base...</p>
                    </div>

                    <!-- Generated Answer -->
                    <div id="generatedAnswer" class="d-none">
                        <div class="card bg-light border-0 mb-4">
                            <div class="card-body">
                                <h6 class="text-primary mb-3"><i class="bi bi-lightbulb me-2"></i>Generated Answer</h6>
                                <div id="answerContent" class="mb-0"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Results List -->
                    <div id="resultsList" class="d-none"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.result-card {
    border-left: 3px solid #0d6efd;
    transition: all 0.2s;
}
.result-card:hover {
    background-color: #f8f9fa;
}
.similarity-badge {
    font-size: 0.75rem;
}
.source-highlight {
    background-color: rgba(255, 235, 59, 0.3);
    padding: 0 2px;
    border-radius: 2px;
}
</style>

<script>
document.getElementById('queryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    executeQuery();
});

function executeQuery() {
    const kbId = document.getElementById('queryKbId').value;
    const query = document.getElementById('queryText').value.trim();

    if (!kbId) {
        alert('Please select a knowledge base');
        return;
    }

    if (!query) {
        alert('Please enter a query');
        return;
    }

    const options = {
        kb_id: kbId,
        query: query,
        similarity_threshold: parseFloat(document.getElementById('similarityThreshold').value),
        max_results: parseInt(document.getElementById('maxResults').value),
        include_metadata: document.getElementById('includeMetadata').checked
    };

    // Show loading state
    document.getElementById('emptyState').classList.add('d-none');
    document.getElementById('loadingState').classList.remove('d-none');
    document.getElementById('resultsList').classList.add('d-none');
    document.getElementById('generatedAnswer').classList.add('d-none');
    document.getElementById('queryBtn').disabled = true;
    document.getElementById('queryBtn').innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Searching...';

    fetch('/knowledgebase/executequery', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(options)
    })
    .then(res => res.json())
    .then(data => {
        document.getElementById('loadingState').classList.add('d-none');
        document.getElementById('queryBtn').disabled = false;
        document.getElementById('queryBtn').innerHTML = '<i class="bi bi-search me-1"></i> Execute Query';

        if (data.success && data.data) {
            displayResults(data.data);
        } else {
            displayError(data.message || data.error || 'Query failed');
        }
    })
    .catch(err => {
        document.getElementById('loadingState').classList.add('d-none');
        document.getElementById('queryBtn').disabled = false;
        document.getElementById('queryBtn').innerHTML = '<i class="bi bi-search me-1"></i> Execute Query';
        displayError('Error: ' + err.message);
    });
}

function displayResults(data) {
    const results = data.results || [];
    const answer = data.answer;
    const sources = data.sources || [];

    document.getElementById('resultCount').textContent = results.length + ' result' + (results.length !== 1 ? 's' : '');

    // Display generated answer if available
    if (answer) {
        document.getElementById('answerContent').innerHTML = marked.parse(answer);
        document.getElementById('generatedAnswer').classList.remove('d-none');
    }

    // Display results
    const resultsList = document.getElementById('resultsList');
    resultsList.innerHTML = '';

    if (results.length === 0) {
        resultsList.innerHTML = `
            <div class="text-center text-muted py-4">
                <i class="bi bi-inbox display-4"></i>
                <p class="mt-3">No matching results found. Try adjusting your query or lowering the similarity threshold.</p>
            </div>
        `;
    } else {
        results.forEach((result, index) => {
            const similarity = (result.similarity * 100).toFixed(1);
            const badgeClass = similarity >= 80 ? 'bg-success' : (similarity >= 60 ? 'bg-warning text-dark' : 'bg-secondary');

            resultsList.innerHTML += `
                <div class="result-card card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="d-flex align-items-center">
                                <span class="badge bg-primary me-2">#${index + 1}</span>
                                <strong>${escapeHtml(result.filename || 'Unknown source')}</strong>
                            </div>
                            <span class="badge similarity-badge ${badgeClass}">${similarity}% match</span>
                        </div>
                        <p class="mb-2 text-muted small">${escapeHtml(result.content || '')}</p>
                        ${result.metadata ? `
                        <div class="small text-muted border-top pt-2 mt-2">
                            <i class="bi bi-info-circle me-1"></i>
                            Chunk ${result.metadata.chunk_index || '?'}
                            ${result.metadata.document_type ? '| Type: ' + result.metadata.document_type : ''}
                        </div>
                        ` : ''}
                    </div>
                </div>
            `;
        });
    }

    resultsList.classList.remove('d-none');
}

function displayError(message) {
    const resultsList = document.getElementById('resultsList');
    resultsList.innerHTML = `
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>
            ${escapeHtml(message)}
        </div>
    `;
    resultsList.classList.remove('d-none');
    document.getElementById('resultCount').textContent = 'Error';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Load marked.js for markdown rendering (if not already loaded)
if (typeof marked === 'undefined') {
    const script = document.createElement('script');
    script.src = 'https://cdn.jsdelivr.net/npm/marked/marked.min.js';
    document.head.appendChild(script);
}
</script>
