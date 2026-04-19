<div class="container py-4" data-assist-page="codereview" data-assist-purpose="View code review summaries with severity counts">
    <div class="row">
        <div class="col-12">
            <h1 class="mb-4">
                <i class="bi bi-code-square me-2"></i>Code Review
            </h1>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4" id="summary-cards" style="display: none;">
        <div class="col-md-3">
            <div class="card bg-danger text-white h-100">
                <div class="card-body">
                    <h5 class="card-title">Critical</h5>
                    <h2 id="critical-count">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning h-100">
                <div class="card-body">
                    <h5 class="card-title">High</h5>
                    <h2 id="high-count">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white h-100">
                <div class="card-body">
                    <h5 class="card-title">Duplicate Blocks</h5>
                    <h2 id="duplicate-count">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">Status</h5>
                    <h2 id="status-badge"><span class="badge bg-secondary">Not Scanned</span></h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex gap-3 align-items-center">
                        <button class="btn btn-primary" id="run-scan" onclick="runScan()">
                            <i class="bi bi-play-fill me-1"></i>Run Scan
                        </button>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="quick-mode" checked>
                            <label class="form-check-label" for="quick-mode">Quick mode (controls + services only)</label>
                        </div>
                        <button class="btn btn-outline-secondary" onclick="generateReport()">
                            <i class="bi bi-file-text me-1"></i>Generate Report
                        </button>
                        <button class="btn btn-outline-secondary" onclick="editPatterns()">
                            <i class="bi bi-gear me-1"></i>Edit Patterns
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Results -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-bug me-2"></i>Pattern Violations</span>
                    <span class="badge bg-secondary" id="pattern-count">0</span>
                </div>
                <div class="card-body">
                    <div id="patterns-loading" class="text-center py-4" style="display: none;">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2 text-muted">Scanning codebase...</p>
                    </div>
                    <div id="patterns-results">
                        <?php if ($cachedReview): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            Showing cached results from <?= h($cachedReview['timestamp'] ?? 'unknown') ?>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-search fs-1"></i>
                            <p class="mt-2">Click "Run Scan" to analyze the codebase</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Pattern Edit Modal -->
<div class="modal fade" id="patternsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Detection Patterns</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="patterns-editor">
                    <p class="text-muted">Loading patterns...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="savePatterns()">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Report Modal -->
<div class="modal fade" id="reportModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Code Review Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre id="report-content" style="white-space: pre-wrap; font-size: 0.9rem;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="copyReport()">
                    <i class="bi bi-clipboard me-1"></i>Copy to Clipboard
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentResults = <?= json_encode($cachedReview ?? null) ?>;

async function runScan() {
    const btn = document.getElementById('run-scan');
    const loading = document.getElementById('patterns-loading');
    const results = document.getElementById('patterns-results');
    const quick = document.getElementById('quick-mode').checked;

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Scanning...';
    loading.style.display = 'block';
    results.innerHTML = '';

    try {
        const response = await fetch('/codereview/run', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                '<?= $csrf['name'] ?>': '<?= $csrf['token'] ?>'
            },
            body: JSON.stringify({ quick })
        });

        const data = await response.json();

        if (data.success) {
            currentResults = data.data;
            displayResults(data.data);
        } else {
            results.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
        }
    } catch (err) {
        results.innerHTML = `<div class="alert alert-danger">Scan failed: ${err.message}</div>`;
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Run Scan';
        loading.style.display = 'none';
    }
}

function displayResults(data) {
    const summaryCards = document.getElementById('summary-cards');
    const results = document.getElementById('patterns-results');

    // Update summary
    summaryCards.style.display = 'flex';
    document.getElementById('critical-count').textContent = data.summary.critical_violations;
    document.getElementById('high-count').textContent = data.summary.high_violations;
    document.getElementById('duplicate-count').textContent = data.summary.duplicate_blocks;

    const statusBadge = document.getElementById('status-badge');
    const status = data.summary.status;
    const statusClass = status === 'passed' ? 'success' : (status === 'warning' ? 'warning' : 'danger');
    const statusIcon = status === 'passed' ? 'check-circle' : (status === 'warning' ? 'exclamation-triangle' : 'x-circle');
    statusBadge.innerHTML = `<span class="badge bg-${statusClass}"><i class="bi bi-${statusIcon} me-1"></i>${status.toUpperCase()}</span>`;

    document.getElementById('pattern-count').textContent = data.summary.total_patterns;

    // Build pattern results
    let html = '';
    const severityColors = {
        critical: 'danger',
        high: 'warning',
        medium: 'info',
        low: 'secondary'
    };

    for (const [name, pattern] of Object.entries(data.patterns)) {
        const count = pattern.locations.length;
        const color = severityColors[pattern.severity] || 'secondary';

        html += `
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>
                        <span class="badge bg-${color} me-2">${pattern.severity.toUpperCase()}</span>
                        ${escapeHtml(name)}
                    </span>
                    <span class="badge bg-dark">${count}x</span>
                </div>
                <div class="card-body">
                    <p class="mb-2 text-muted"><strong>Suggestion:</strong> ${escapeHtml(pattern.suggestion)}</p>
                    <div class="small">
                        ${pattern.locations.slice(0, 5).map(loc =>
                            `<code>${escapeHtml(loc.file)}:${loc.line}</code>`
                        ).join('<br>')}
                        ${count > 5 ? `<br><span class="text-muted">...and ${count - 5} more</span>` : ''}
                    </div>
                </div>
            </div>
        `;
    }

    if (Object.keys(data.patterns).length === 0) {
        html = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>No pattern violations found!</div>';
    }

    results.innerHTML = html;
}

async function generateReport() {
    if (!currentResults) {
        alert('Please run a scan first');
        return;
    }

    try {
        const response = await fetch('/codereview/report', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                '<?= $csrf['name'] ?>': '<?= $csrf['token'] ?>'
            },
            body: JSON.stringify({ results: currentResults })
        });

        const data = await response.json();
        if (data.success) {
            document.getElementById('report-content').textContent = data.data.markdown;
            new bootstrap.Modal(document.getElementById('reportModal')).show();
        }
    } catch (err) {
        alert('Failed to generate report: ' + err.message);
    }
}

function copyReport() {
    const content = document.getElementById('report-content').textContent;
    navigator.clipboard.writeText(content).then(() => {
        alert('Report copied to clipboard!');
    });
}

async function editPatterns() {
    try {
        const response = await fetch('/codereview/patterns');
        const data = await response.json();

        if (data.success) {
            const editor = document.getElementById('patterns-editor');
            editor.innerHTML = `
                <textarea id="patterns-json" class="form-control font-monospace" rows="20">${JSON.stringify(data.data.patterns, null, 2)}</textarea>
                <small class="text-muted mt-2 d-block">Edit the patterns JSON above. Each pattern needs: pattern (regex), suggestion, severity (critical/high/medium/low)</small>
            `;
            new bootstrap.Modal(document.getElementById('patternsModal')).show();
        }
    } catch (err) {
        alert('Failed to load patterns: ' + err.message);
    }
}

async function savePatterns() {
    const textarea = document.getElementById('patterns-json');
    try {
        const patterns = JSON.parse(textarea.value);

        const response = await fetch('/codereview/patterns', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                '<?= $csrf['name'] ?>': '<?= $csrf['token'] ?>'
            },
            body: JSON.stringify({ patterns })
        });

        const data = await response.json();
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('patternsModal')).hide();
            alert('Patterns saved successfully!');
        } else {
            alert('Failed to save: ' + data.message);
        }
    } catch (err) {
        alert('Invalid JSON: ' + err.message);
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Display cached results on load if available
<?php if ($cachedReview): ?>
displayResults(currentResults);
<?php endif; ?>
</script>
