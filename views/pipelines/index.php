<?php
// Build assistant context data
$activeCount = count(array_filter($pipelines, fn($p) => $p['is_active']));
$inactiveCount = count($pipelines) - $activeCount;
$triggerCounts = [];
foreach ($pipelines as $p) {
    $t = $p['trigger_type'] ?? 'manual';
    $triggerCounts[$t] = ($triggerCounts[$t] ?? 0) + 1;
}
$assistPipelines = array_map(fn($p) => [
    'name' => $p['name'],
    'slug' => $p['slug'],
    'tags' => $p['tags'],
    'trigger' => $p['trigger_type'],
    'active' => $p['is_active'],
    'runs' => $p['run_count'],
    'success_rate' => $p['success_rate'],
], $pipelines);
$assistContext = json_encode([
    'total' => count($pipelines),
    'active' => $activeCount,
    'inactive' => $inactiveCount,
    'tags' => $allTags ?? [],
    'triggers' => $triggerCounts,
    'pipelines' => $assistPipelines,
], JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<div class="container py-4"
     data-assist-page="pipelines"
     data-assist-purpose="List, search, and filter automation pipelines by name, tags, trigger type, and status"
     data-assist-context='<?= $assistContext ?>'>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi bi-diagram-3"></i> Pipelines
            </h1>
            <p class="text-muted mb-0">Automate workflows with spreadsheet-like execution grids</p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <div class="form-check form-switch" title="Auto-redirect to handoff target when viewing completed runs">
                <input class="form-check-input" type="checkbox" id="followHandoffs" onchange="toggleFollowHandoffs()">
                <label class="form-check-label small text-muted" for="followHandoffs">Follow Handoffs</label>
            </div>
            <button type="button" class="btn btn-outline-secondary me-2" data-bs-toggle="modal" data-bs-target="#importModal" title="Import pipeline from JSON file">
                <i class="bi bi-upload"></i> Import
            </button>
            <a href="/pipelines/create" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> New Pipeline
            </a>
        </div>
    </div>

    <script>
    // Initialize follow handoffs toggle from localStorage
    document.addEventListener('DOMContentLoaded', function() {
        const followHandoffs = localStorage.getItem('pipeline_follow_handoffs') === 'true';
        document.getElementById('followHandoffs').checked = followHandoffs;
    });

    function toggleFollowHandoffs() {
        const checked = document.getElementById('followHandoffs').checked;
        localStorage.setItem('pipeline_follow_handoffs', checked ? 'true' : 'false');
    }
    </script>

    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-4" id="pipelineTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="pipelines-tab" data-bs-toggle="tab" data-bs-target="#pipelines-content" type="button" role="tab">
                <i class="bi bi-diagram-3"></i> All Pipelines
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="templates-tab" data-bs-toggle="tab" data-bs-target="#templates-content" type="button" role="tab">
                <i class="bi bi-grid-3x3-gap"></i> Templates
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="schedules-tab" data-bs-toggle="tab" data-bs-target="#schedules-content" type="button" role="tab">
                <i class="bi bi-clock-history"></i> Schedules
            </button>
        </li>
    </ul>

    <div class="tab-content" id="pipelineTabContent">
    <!-- All Pipelines Tab -->
    <div class="tab-pane fade show active" id="pipelines-content" role="tabpanel" aria-labelledby="pipelines-tab">

    <!-- Explanation Section -->
    <div class="card bg-light border-0 mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h5 class="card-title mb-3">
                        <i class="bi bi-question-circle text-primary"></i> What are Pipelines?
                    </h5>
                    <p class="mb-2">
                        <strong>Pipelines are automation workflows organized like a spreadsheet.</strong>
                        Each pipeline has columns (phases) and rows (iterations) with steps that:
                    </p>
                    <ul class="mb-2">
                        <li><strong>Execute AI agents</strong> - Run impl, verify, or custom agents</li>
                        <li><strong>Run shell commands</strong> - Execute scripts with stdin/stdout</li>
                        <li><strong>Call webhooks</strong> - POST to external services</li>
                        <li><strong>Transform data</strong> - Parse and modify output between steps</li>
                    </ul>
                    <div class="alert alert-info py-2 px-3 mb-0 small">
                        <i class="bi bi-plug"></i> <strong>MCP Integration:</strong>
                        Pipelines can be exposed as MCP tools, allowing AI agents (like Claude) to trigger them and receive structured results.
                        Configure this in <a href="/mcpservers">MCP Server Library</a> or per-pipeline settings.
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="bg-white rounded p-3 border">
                        <h6 class="text-muted mb-3"><i class="bi bi-lightning-charge"></i> Trigger Options</h6>
                        <?php foreach ($triggerTypes as $type => $info): ?>
                        <div class="d-flex align-items-start mb-2">
                            <i class="bi <?= $info['icon'] ?> text-primary me-2 mt-1"></i>
                            <div>
                                <strong><?= $info['label'] ?></strong>
                                <small class="d-block text-muted"><?= $info['description'] ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($pipelines)): ?>
    <!-- Search & Filter Toolbar -->
    <div class="card mb-4" id="filterToolbar">
        <div class="card-body py-3">
            <!-- Search -->
            <div class="input-group mb-3">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" id="pipelineSearch" placeholder="Search pipelines by name, slug, description, or tag..." autocomplete="off">
                <button class="btn btn-outline-secondary" type="button" id="clearSearch" title="Clear search and filters">
                    <i class="bi bi-x-lg"></i> Clear
                </button>
            </div>

            <!-- Filter Chips -->
            <div class="d-flex flex-wrap gap-3 align-items-start">
                <!-- Status Filter -->
                <div>
                    <small class="text-muted d-block mb-1">Status</small>
                    <div class="btn-group btn-group-sm" role="group" id="statusFilter">
                        <button type="button" class="btn btn-outline-secondary active" data-filter-status="all">All</button>
                        <button type="button" class="btn btn-outline-success" data-filter-status="active">
                            Active <span class="badge bg-success-subtle text-success ms-1"><?= $activeCount ?></span>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-filter-status="inactive">
                            Inactive <span class="badge bg-secondary-subtle text-secondary ms-1"><?= $inactiveCount ?></span>
                        </button>
                    </div>
                </div>

                <!-- Trigger Filter -->
                <div>
                    <small class="text-muted d-block mb-1">Trigger</small>
                    <div class="btn-group btn-group-sm" role="group" id="triggerFilter">
                        <button type="button" class="btn btn-outline-secondary active" data-filter-trigger="all">All</button>
                        <?php foreach ($triggerTypes as $type => $info): ?>
                        <?php $count = $triggerCounts[$type] ?? 0; ?>
                        <?php if ($count > 0): ?>
                        <button type="button" class="btn btn-outline-info" data-filter-trigger="<?= $type ?>">
                            <i class="bi <?= $info['icon'] ?> me-1"></i><?= $info['label'] ?>
                            <span class="badge bg-info-subtle text-info ms-1"><?= $count ?></span>
                        </button>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Tag Filter -->
                <?php if (!empty($allTags)): ?>
                <div>
                    <small class="text-muted d-block mb-1">Tags</small>
                    <div class="d-flex flex-wrap gap-1" id="tagFilter">
                        <?php foreach ($allTags as $tag => $count): ?>
                        <button type="button" class="btn btn-sm btn-outline-dark tag-filter-btn" data-filter-tag="<?= h($tag) ?>">
                            <?= h($tag) ?>
                            <span class="badge bg-dark-subtle text-dark ms-1"><?= $count ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Results Counter -->
            <div class="mt-2 small text-muted" id="filterCounter">
                Showing <strong id="visibleCount"><?= count($pipelines) ?></strong> of <strong><?= count($pipelines) ?></strong> pipelines
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($pipelines)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-diagram-3 display-4 text-muted"></i>
            <h4 class="mt-3">No pipelines yet</h4>
            <p class="text-muted mb-4" style="max-width: 500px; margin: 0 auto;">
                Create your first pipeline to automate CI/CD, deployments, or any multi-step workflow.
            </p>
            <a href="/pipelines/create" class="btn btn-primary btn-lg">
                <i class="bi bi-plus-lg"></i> Create Your First Pipeline
            </a>
        </div>
    </div>
    <?php else: ?>
    <!-- No Results Message -->
    <div class="card d-none" id="noResults">
        <div class="card-body text-center py-4">
            <i class="bi bi-funnel display-4 text-muted"></i>
            <h5 class="mt-3 text-muted">No pipelines match your filters</h5>
            <button class="btn btn-outline-primary mt-2" onclick="clearAllFilters()">Clear All Filters</button>
        </div>
    </div>

    <div class="row" id="pipelineGrid">
        <?php foreach ($pipelines as $pipeline): ?>
        <?php $tagsStr = implode(',', $pipeline['tags']); ?>
        <div class="col-md-6 col-lg-4 mb-4 pipeline-card"
             data-name="<?= h(strtolower($pipeline['name'] ?? '')) ?>"
             data-slug="<?= h(strtolower($pipeline['slug'] ?? '')) ?>"
             data-description="<?= h(strtolower($pipeline['description'] ?? '')) ?>"
             data-tags="<?= h(strtolower($tagsStr)) ?>"
             data-trigger="<?= h($pipeline['trigger_type'] ?? 'manual') ?>"
             data-active="<?= $pipeline['is_active'] ? '1' : '0' ?>">
            <div class="card h-100 <?= !$pipeline['is_active'] ? 'border-secondary' : '' ?>"
                 data-pipeline-id="<?= $pipeline['id'] ?>"
                 data-assist-title="<?= h($pipeline['name']) ?>"
                 data-assist-hint="<?= h($pipeline['trigger_type'] ?? 'manual') ?> trigger<?= $tagsStr ? ', tags: ' . h($tagsStr) : '' ?>">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>
                        <i class="bi bi-<?= $pipeline['trigger_info']['icon'] ?? 'diagram-3' ?> text-primary"></i>
                        <strong><?= h($pipeline['name']) ?></strong>
                        <?php if (!empty($pipeline['is_system'])): ?>
                        <i class="bi bi-lock-fill text-secondary ms-1" title="System Pipeline - Protected from deletion"></i>
                        <?php endif; ?>
                    </span>
                    <span class="badge <?= $pipeline['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                        <?= $pipeline['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-2">
                        <?= h($pipeline['description'] ?: 'No description') ?>
                    </p>

                    <div class="mb-2">
                        <code class="small"><?= h($pipeline['slug']) ?></code>
                    </div>

                    <?php if (!empty($pipeline['tags'])): ?>
                    <div class="mb-2">
                        <?php foreach ($pipeline['tags'] as $tag): ?>
                        <span class="badge bg-secondary-subtle text-secondary me-1 pipeline-tag-badge" role="button" onclick="activateTagFilter('<?= h(addslashes($tag)) ?>')" title="Filter by tag: <?= h($tag) ?>">
                            <i class="bi bi-tag-fill me-1"></i><?= h($tag) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <table class="table table-sm table-borderless mb-3">
                        <tr>
                            <td class="text-muted" style="width: 40%">Trigger:</td>
                            <td>
                                <span class="badge bg-info">
                                    <i class="bi <?= $pipeline['trigger_info']['icon'] ?? 'bi-play' ?>"></i>
                                    <?= $pipeline['trigger_info']['label'] ?? ucfirst($pipeline['trigger_type'] ?? 'manual') ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Columns:</td>
                            <td><?= $pipeline['column_count'] ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Steps:</td>
                            <td><?= $pipeline['step_count'] ?></td>
                        </tr>
                    </table>

                    <!-- Stats -->
                    <div class="row text-center border-top pt-2">
                        <div class="col-4">
                            <div class="small text-muted">Runs</div>
                            <div class="fw-bold"><?= $pipeline['run_count'] ?></div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Success</div>
                            <div class="fw-bold <?= $pipeline['success_rate'] !== null ? ($pipeline['success_rate'] >= 80 ? 'text-success' : ($pipeline['success_rate'] >= 50 ? 'text-warning' : 'text-danger')) : 'text-muted' ?>">
                                <?= $pipeline['success_rate'] !== null ? $pipeline['success_rate'] . '%' : '-' ?>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Last Run</div>
                            <div class="fw-bold small">
                                <?= $pipeline['last_run_at'] ? date('M j', strtotime($pipeline['last_run_at'])) : '-' ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent">
                    <div class="btn-group w-100">
                        <a href="/pipelines/edit/<?= $pipeline['id'] ?>" class="btn btn-outline-primary btn-sm" title="Edit">
                            <i class="bi bi-pencil"></i> Edit
                        </a>
                        <a href="/pipelines/runs/<?= $pipeline['id'] ?>" class="btn btn-outline-secondary btn-sm" title="View Runs">
                            <i class="bi bi-list-task"></i> Runs
                        </a>
                        <?php if ($pipeline['is_active']): ?>
                        <button class="btn btn-outline-success btn-sm" onclick="triggerPipeline(<?= $pipeline['id'] ?>, '<?= h(addslashes($pipeline['name'])) ?>')" title="Run">
                            <i class="bi bi-play-fill"></i>
                        </button>
                        <?php endif; ?>
                        <?php if (!empty($pipeline['is_system'])): ?>
                        <button class="btn btn-outline-secondary btn-sm" disabled title="System pipelines cannot be deleted">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php else: ?>
                        <button class="btn btn-outline-danger btn-sm" onclick="deletePipeline(<?= $pipeline['id'] ?>, '<?= h(addslashes($pipeline['name'])) ?>')" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    </div><!-- /pipelines-content tab-pane -->

    <!-- Templates Tab -->
    <div class="tab-pane fade" id="templates-content" role="tabpanel" aria-labelledby="templates-tab">
        <div class="text-center py-5" id="templates-loading">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="text-muted mt-2">Loading templates...</p>
        </div>
    </div>

    <!-- Schedules Tab -->
    <div class="tab-pane fade" id="schedules-content" role="tabpanel" aria-labelledby="schedules-tab">
        <div class="text-center py-5" id="schedules-loading">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="text-muted mt-2">Loading schedules...</p>
        </div>
    </div>

    </div><!-- /tab-content -->
</div>

<!-- Trigger Modal -->
<div class="modal fade" id="triggerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-play-fill"></i> Run Pipeline</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Run pipeline: <strong id="triggerPipelineName"></strong></p>
                <div class="mb-3">
                    <label class="form-label">Context (JSON, optional)</label>
                    <textarea class="form-control font-monospace" id="triggerContext" rows="4" placeholder='{&#10;  "key": "value"&#10;}'></textarea>
                    <small class="text-muted">Pass initial context/variables to the pipeline</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="triggerConfirmBtn">
                    <i class="bi bi-play-fill"></i> Run
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload"></i> Import Pipeline</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="importForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= Flight::csrf()->getToken() ?>">
                    <div class="mb-3">
                        <label class="form-label">Pipeline JSON File</label>
                        <input type="file" class="form-control" name="pipeline_file" id="pipelineFile" accept=".json,application/json" required>
                        <small class="text-muted">Select a pipeline JSON file exported from another workspace</small>
                    </div>
                    <div id="importPreview" class="d-none">
                        <hr>
                        <h6><i class="bi bi-info-circle"></i> Preview</h6>
                        <table class="table table-sm">
                            <tr>
                                <td class="text-muted" style="width: 35%">Name:</td>
                                <td id="previewName"></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Slug:</td>
                                <td><code id="previewSlug"></code></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Steps:</td>
                                <td id="previewSteps"></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Source Workspace:</td>
                                <td id="previewSource"></td>
                            </tr>
                        </table>
                    </div>
                    <div id="importWarnings" class="d-none">
                        <div class="alert alert-warning py-2">
                            <strong><i class="bi bi-exclamation-triangle"></i> Warnings</strong>
                            <ul class="mb-0 mt-2" id="warningsList"></ul>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="importBtn" disabled>
                    <i class="bi bi-upload"></i> Import
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?= Flight::csrf()->getToken() ?>';
let currentPipelineId = null;
let importFileData = null;

// ============================================================
// Search & Filter Logic
// ============================================================
const filterState = {
    search: '',
    status: 'all',
    trigger: 'all',
    tags: new Set()
};

function applyFilters() {
    const cards = document.querySelectorAll('.pipeline-card');
    const noResults = document.getElementById('noResults');
    const grid = document.getElementById('pipelineGrid');
    let visible = 0;

    cards.forEach(card => {
        const name = card.dataset.name || '';
        const slug = card.dataset.slug || '';
        const desc = card.dataset.description || '';
        const tags = card.dataset.tags || '';
        const trigger = card.dataset.trigger || '';
        const active = card.dataset.active || '';

        // Text search (match any of name, slug, description, tags)
        let matchSearch = true;
        if (filterState.search) {
            const q = filterState.search.toLowerCase();
            matchSearch = name.includes(q) || slug.includes(q) || desc.includes(q) || tags.includes(q);
        }

        // Status filter
        let matchStatus = true;
        if (filterState.status === 'active') matchStatus = active === '1';
        else if (filterState.status === 'inactive') matchStatus = active === '0';

        // Trigger filter
        let matchTrigger = true;
        if (filterState.trigger !== 'all') matchTrigger = trigger === filterState.trigger;

        // Tag filter (AND: all selected tags must be present)
        let matchTags = true;
        if (filterState.tags.size > 0) {
            const cardTags = tags ? tags.split(',') : [];
            for (const t of filterState.tags) {
                if (!cardTags.includes(t)) {
                    matchTags = false;
                    break;
                }
            }
        }

        const show = matchSearch && matchStatus && matchTrigger && matchTags;
        card.classList.toggle('d-none', !show);
        if (show) visible++;
    });

    // Update counter
    const counter = document.getElementById('visibleCount');
    if (counter) counter.textContent = visible;

    // Show/hide no results
    if (noResults && grid) {
        noResults.classList.toggle('d-none', visible > 0);
        grid.classList.toggle('d-none', visible === 0);
    }

    // Update assistant context with current filter state
    updateAssistantContext(visible);
}

function updateAssistantContext(visibleCount) {
    const container = document.querySelector('[data-assist-page="pipelines"]');
    if (!container) return;
    try {
        const ctx = JSON.parse(container.dataset.assistContext || '{}');
        ctx.filter = {
            search: filterState.search,
            status: filterState.status,
            trigger: filterState.trigger,
            tags: Array.from(filterState.tags),
            visible_count: visibleCount
        };
        container.dataset.assistContext = JSON.stringify(ctx);
    } catch (e) { /* ignore */ }
}

// Search input
document.getElementById('pipelineSearch')?.addEventListener('input', function() {
    filterState.search = this.value.trim();
    applyFilters();
});

// Clear button
document.getElementById('clearSearch')?.addEventListener('click', clearAllFilters);

function clearAllFilters() {
    filterState.search = '';
    filterState.status = 'all';
    filterState.trigger = 'all';
    filterState.tags.clear();

    const searchInput = document.getElementById('pipelineSearch');
    if (searchInput) searchInput.value = '';

    // Reset status buttons
    document.querySelectorAll('#statusFilter button').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.filterStatus === 'all');
    });

    // Reset trigger buttons
    document.querySelectorAll('#triggerFilter button').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.filterTrigger === 'all');
    });

    // Reset tag buttons
    document.querySelectorAll('.tag-filter-btn').forEach(btn => {
        btn.classList.remove('active', 'btn-dark');
        btn.classList.add('btn-outline-dark');
    });

    applyFilters();
}

// Status filter buttons
document.querySelectorAll('#statusFilter button').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('#statusFilter button').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        filterState.status = this.dataset.filterStatus;
        applyFilters();
    });
});

// Trigger filter buttons
document.querySelectorAll('#triggerFilter button').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('#triggerFilter button').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        filterState.trigger = this.dataset.filterTrigger;
        applyFilters();
    });
});

// Tag filter buttons (toggle on/off)
document.querySelectorAll('.tag-filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const tag = this.dataset.filterTag;
        if (filterState.tags.has(tag)) {
            filterState.tags.delete(tag);
            this.classList.remove('active', 'btn-dark');
            this.classList.add('btn-outline-dark');
        } else {
            filterState.tags.add(tag);
            this.classList.add('active', 'btn-dark');
            this.classList.remove('btn-outline-dark');
        }
        applyFilters();
    });
});

// Activate tag filter from card badge click
function activateTagFilter(tag) {
    tag = tag.toLowerCase();
    const btn = document.querySelector(`.tag-filter-btn[data-filter-tag="${tag}"]`);
    if (btn) {
        if (!filterState.tags.has(tag)) {
            filterState.tags.add(tag);
            btn.classList.add('active', 'btn-dark');
            btn.classList.remove('btn-outline-dark');
            applyFilters();
        }
    }
}

// ============================================================
// File preview handler
// ============================================================
document.getElementById('pipelineFile')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    const importBtn = document.getElementById('importBtn');
    const previewDiv = document.getElementById('importPreview');
    const warningsDiv = document.getElementById('importWarnings');

    importBtn.disabled = true;
    previewDiv.classList.add('d-none');
    warningsDiv.classList.add('d-none');
    importFileData = null;

    if (!file) return;

    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = JSON.parse(e.target.result);
            importFileData = data;

            if (!data.version || !data.pipeline || !data.steps) {
                alert('Invalid pipeline file format');
                return;
            }

            // Show preview
            document.getElementById('previewName').textContent = data.pipeline.name || '(no name)';
            document.getElementById('previewSlug').textContent = data.pipeline.slug || '(no slug)';
            document.getElementById('previewSteps').textContent = data.steps.length + ' steps';
            document.getElementById('previewSource').textContent = data.source_workspace || '(unknown)';
            previewDiv.classList.remove('d-none');

            // Show dependency warnings if any
            const deps = data.dependencies || {};
            const allDeps = [
                ...(deps.ai_agents || []).map(d => 'AI Agent: ' + d),
                ...(deps.mcp_servers || []).map(d => 'MCP Server: ' + d),
                ...(deps.repo_connections || []).map(d => 'Repository: ' + d),
                ...(deps.shopify_connections || []).map(d => 'Shopify: ' + d),
                ...(deps.workstations || []).map(d => 'Workstation: ' + d)
            ];

            if (allDeps.length > 0) {
                const warningsList = document.getElementById('warningsList');
                warningsList.innerHTML = allDeps.map(d =>
                    '<li class="small">Requires: ' + d + '</li>'
                ).join('');
                warningsDiv.classList.remove('d-none');
            }

            importBtn.disabled = false;
        } catch (err) {
            alert('Error reading file: ' + err.message);
        }
    };
    reader.readAsText(file);
});

// Import handler
document.getElementById('importBtn')?.addEventListener('click', async function() {
    const btn = this;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Importing...';

    try {
        const formData = new FormData(document.getElementById('importForm'));

        const response = await fetch('/pipelines/import', {
            method: 'POST',
            headers: {
                'X-CSRF-Token': csrfToken
            },
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('importModal')).hide();

            // Show success with any warnings
            let message = 'Pipeline "' + data.data.name + '" imported successfully!';
            if (data.data.warnings && data.data.warnings.length > 0) {
                message += '\n\nWarnings:\n- ' + data.data.warnings.join('\n- ');
            }
            alert(message);

            // Redirect to edit the new pipeline
            window.location.href = '/pipelines/edit/' + data.data.pipeline_id;
        } else {
            alert('Error: ' + (data.error || 'Failed to import pipeline'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
});

function triggerPipeline(pipelineId, pipelineName) {
    currentPipelineId = pipelineId;
    document.getElementById('triggerPipelineName').textContent = pipelineName;
    document.getElementById('triggerContext').value = '';
    new bootstrap.Modal(document.getElementById('triggerModal')).show();
}

document.getElementById('triggerConfirmBtn')?.addEventListener('click', async function() {
    const btn = this;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Starting...';

    try {
        const context = document.getElementById('triggerContext').value || '{}';

        const response = await fetch('/pipelines/trigger/' + currentPipelineId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&context=' + encodeURIComponent(context)
        });

        const data = await response.json();

        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('triggerModal')).hide();
            // Redirect to run view
            window.location.href = '/pipelines/viewrun/' + data.data.run_id;
        } else {
            alert('Error: ' + (data.error || 'Failed to start pipeline'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
});

function deletePipeline(pipelineId, pipelineName) {
    if (!confirm(`Are you sure you want to delete pipeline "${pipelineName}"?\n\nThis will also delete all runs and step history.`)) {
        return;
    }

    fetch('/pipelines/delete/' + pipelineId, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrfToken
        },
        body: 'csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Failed to delete pipeline'));
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
    });
}

// ============================================================
// Tab Management: AJAX lazy-load + URL param support
// ============================================================
const tabLoadState = { templates: false, schedules: false };

function loadTabContent(tabName) {
    if (tabLoadState[tabName]) return; // Already loaded

    const endpointMap = {
        templates: '/pipelines/templategallery',
        schedules: '/pipelines/schedulestab'
    };

    const container = document.getElementById(tabName + '-content');
    if (!container) return;

    fetch(endpointMap[tabName])
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(html => {
            container.innerHTML = html;
            tabLoadState[tabName] = true;
            // Re-initialize any scripts in the loaded content
            container.querySelectorAll('script').forEach(oldScript => {
                const newScript = document.createElement('script');
                if (oldScript.src) {
                    newScript.src = oldScript.src;
                } else {
                    newScript.textContent = oldScript.textContent;
                }
                oldScript.parentNode.replaceChild(newScript, oldScript);
            });
        })
        .catch(err => {
            container.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Failed to load ' + tabName + ': ' + err.message + '</div>';
        });
}

// Listen for tab activation to lazy-load
document.querySelectorAll('#pipelineTabs button[data-bs-toggle="tab"]').forEach(btn => {
    btn.addEventListener('shown.bs.tab', function(e) {
        const target = e.target.getAttribute('data-bs-target');
        if (target === '#templates-content') loadTabContent('templates');
        else if (target === '#schedules-content') loadTabContent('schedules');

        // Update URL param without reload
        const tabName = target.replace('#', '').replace('-content', '');
        const url = new URL(window.location);
        if (tabName === 'pipelines') {
            url.searchParams.delete('tab');
        } else {
            url.searchParams.set('tab', tabName);
        }
        history.replaceState(null, '', url);
    });
});

// On page load: activate tab from URL param ?tab=templates or ?tab=schedules
document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab');
    if (tab === 'templates' || tab === 'schedules') {
        const tabBtn = document.getElementById(tab + '-tab');
        if (tabBtn) {
            const bsTab = new bootstrap.Tab(tabBtn);
            bsTab.show();
        }
    }
});
</script>
