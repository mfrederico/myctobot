<?php
$csrfToken = $_SESSION['csrf_token'] ?? '';
$appId = $app->id;
$screensJson = json_encode($screens);
$blockTypesJson = json_encode($allBlockTypes);
$pipelinesJson = json_encode($pipelines);
$securityJson = json_encode($security ?? []);
$actionsJson = json_encode($actions);

// Builder URL config — overridden by Detachableappbuilder
$cfg = $builderConfig ?? [];
$saveScreenUrl   = $cfg['saveScreenUrl']   ?? "/appbuilder/screen/{$appId}";
$deleteScreenUrl = $cfg['deleteScreenUrl'] ?? "/appbuilder/deletescreen";
$saveSecurityUrl = $cfg['saveSecurityUrl'] ?? "/appbuilder/savesecurity/{$appId}";
$previewUrl      = $cfg['previewUrl']      ?? "/appbuilder/preview/{$appId}";
$deployUrl       = $cfg['deployUrl']       ?? "/appbuilder/deploy/{$appId}";
$backUrl         = $cfg['backUrl']         ?? "/apps/form/{$appId}";
$backLabel       = $cfg['backLabel']       ?? "App Settings";
$channelName     = $cfg['channelName']     ?? "appbuilder_preview_{$appId}";
$showDeploy      = $cfg['showDeploy']      ?? true;
$showSecurity    = $cfg['showSecurity']    ?? true;
?>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<style>
    /* Builder layout - matches MyCTOBot dark theme */
    .builder-layout { display: flex; height: calc(100vh - 80px); overflow: hidden; }
    .builder-sidebar {
        width: 220px; min-width: 220px; overflow-y: auto;
        background: var(--bg-surface, #1e1e1e);
        border-right: 1px solid var(--border-color, #373b3e);
    }
    .builder-main {
        flex: 1; overflow-y: auto; padding: 1rem;
        background: var(--bg-body, #121212);
    }
    .builder-config {
        width: 340px; min-width: 340px; overflow-y: auto;
        background: var(--bg-surface, #1e1e1e);
        border-left: 1px solid var(--border-color, #373b3e);
    }

    /* Header bar */
    .builder-header {
        background: var(--bg-surface, #1e1e1e);
        border-bottom: 1px solid var(--border-color, #373b3e);
    }

    /* Screen list items */
    .screen-item {
        cursor: pointer; padding: 0.5rem 1rem;
        border-bottom: 1px solid var(--border-color, #373b3e);
        color: var(--text-secondary, #adb5bd);
        transition: all 0.15s ease;
    }
    .screen-item:hover {
        background: var(--bg-surface-raised, #2d2d2d);
        color: var(--text-primary, #e9ecef);
    }
    .screen-item.active {
        background: rgba(13, 110, 253, 0.15);
        color: var(--primary-color, #6ea8fe);
        border-left: 3px solid var(--primary-color, #6ea8fe);
    }

    /* Block cards in center panel */
    .block-card {
        border: 1px solid var(--border-color, #373b3e);
        border-radius: 0.5rem;
        margin-bottom: 0.75rem;
        cursor: pointer;
        background: var(--bg-surface, #1e1e1e);
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .block-card:hover {
        border-color: var(--border-color-light, #495057);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }
    .block-card.selected {
        border-color: var(--primary-color, #6ea8fe);
        box-shadow: 0 0 0 2px rgba(110, 168, 254, 0.25);
    }
    .block-card .block-header {
        display: flex; align-items: center;
        padding: 0.5rem 0.75rem;
        background: var(--card-header-bg, #252525);
        border-radius: 0.5rem 0.5rem 0 0;
        border-bottom: 1px solid var(--border-color, #373b3e);
        color: var(--text-primary, #e9ecef);
    }
    .block-card .block-body {
        padding: 0.5rem 0.75rem;
        font-size: 0.85rem;
        color: var(--text-muted, #8b949e);
    }

    /* Block action buttons */
    .block-actions { display: flex; gap: 0.25rem; margin-left: auto; }
    .block-actions button {
        border: none; background: none;
        padding: 0.125rem 0.375rem;
        cursor: pointer; opacity: 0.4;
        font-size: 0.8rem;
        color: var(--text-secondary, #adb5bd);
        transition: opacity 0.15s;
    }
    .block-actions button:hover { opacity: 1; }
    .block-actions button.text-danger { color: var(--danger-color, #ea868f); }

    /* Block picker modal */
    .picker-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; }
    .picker-item {
        border: 1px solid var(--border-color, #373b3e);
        border-radius: 0.5rem;
        padding: 1rem;
        text-align: center;
        cursor: pointer;
        background: var(--bg-surface-raised, #2d2d2d);
        transition: all 0.2s ease;
    }
    .picker-item:hover {
        border-color: var(--primary-color, #6ea8fe);
        background: rgba(13, 110, 253, 0.1);
        transform: translateY(-2px);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
    }
    .picker-item i { font-size: 1.5rem; display: block; margin-bottom: 0.5rem; color: var(--primary-color, #6ea8fe); }
    .picker-item .fw-semibold { color: var(--text-primary, #e9ecef); }
    .picker-item .text-muted { color: var(--text-muted, #8b949e); }

    /* Config panel sections */
    .config-section { margin-bottom: 1rem; }
    .config-section h6 {
        font-size: 0.8rem;
        color: var(--text-muted, #8b949e);
        margin-bottom: 0.5rem;
        border-bottom: 1px solid var(--border-color, #373b3e);
        padding-bottom: 0.25rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    /* Sidebar sections */
    .sidebar-section {
        padding: 0.75rem 1rem;
        border-bottom: 1px solid var(--border-color, #373b3e);
    }
    .sidebar-section h6 {
        font-size: 0.7rem;
        text-transform: uppercase;
        color: var(--text-muted, #8b949e);
        margin-bottom: 0.5rem;
        letter-spacing: 0.05em;
    }
    .sidebar-section a {
        color: var(--text-secondary, #adb5bd);
        transition: color 0.15s;
    }
    .sidebar-section a:hover {
        color: var(--primary-color, #6ea8fe);
    }

    /* Config panel empty state */
    #configEmpty {
        color: var(--text-muted, #8b949e);
    }
    #configEmpty i {
        color: var(--border-color-light, #495057);
    }

    /* Config panel form overrides */
    #configContent h6 {
        color: var(--text-primary, #e9ecef);
    }
    #configContent .form-label {
        color: var(--text-secondary, #adb5bd);
    }

    /* Badge overrides for block type labels */
    .block-card .badge.bg-light {
        background: var(--bg-surface-raised, #2d2d2d) !important;
        color: var(--text-muted, #8b949e) !important;
    }

    /* Drag handle for block reordering */
    .drag-handle {
        cursor: grab;
        opacity: 0.4;
        padding: 0.125rem 0.375rem;
        color: var(--text-secondary, #adb5bd);
        transition: opacity 0.15s;
    }
    .drag-handle:hover { opacity: 1; }
    .block-card.sortable-ghost {
        opacity: 0.4;
        border-color: var(--primary-color, #6ea8fe);
    }
    .block-card.sortable-chosen {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
    }

    /* Row container block */
    .row-block-container {
        border: 1px dashed var(--border-color-light, #495057);
        border-radius: 0.5rem;
        padding: 0.5rem;
        background: var(--bg-body, #121212);
    }
    .row-columns-flex {
        display: flex;
        gap: 0.5rem;
        min-height: 60px;
    }
    .row-column-slot {
        flex: 1;
        border: 1px dashed var(--border-color, #373b3e);
        border-radius: 0.375rem;
        padding: 0.375rem;
        min-height: 50px;
        background: var(--bg-surface-raised, #2d2d2d);
        transition: border-color 0.2s;
    }
    .row-column-slot:hover {
        border-color: var(--primary-color, #6ea8fe);
    }
    .row-column-slot .col-label {
        font-size: 0.65rem;
        color: var(--text-muted, #8b949e);
        text-align: center;
        margin-bottom: 0.25rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    .row-column-slot .mini-block {
        display: flex;
        align-items: center;
        padding: 0.25rem 0.5rem;
        margin-bottom: 0.25rem;
        border-radius: 0.25rem;
        background: var(--bg-surface, #1e1e1e);
        border: 1px solid var(--border-color, #373b3e);
        cursor: pointer;
        font-size: 0.75rem;
        color: var(--text-secondary, #adb5bd);
        transition: border-color 0.15s;
    }
    .row-column-slot .mini-block:hover {
        border-color: var(--primary-color, #6ea8fe);
        color: var(--text-primary, #e9ecef);
    }
    .row-column-slot .mini-block.selected {
        border-color: var(--primary-color, #6ea8fe);
        box-shadow: 0 0 0 1px rgba(110, 168, 254, 0.25);
    }
    .row-column-slot .mini-block .drag-handle {
        padding: 0 0.125rem;
        font-size: 0.7rem;
    }
    .row-column-slot .mini-block-actions {
        margin-left: auto;
        display: flex;
        gap: 0.125rem;
    }
    .row-column-slot .mini-block-actions button {
        border: none;
        background: none;
        padding: 0;
        cursor: pointer;
        opacity: 0.4;
        font-size: 0.7rem;
        color: var(--text-secondary, #adb5bd);
    }
    .row-column-slot .mini-block-actions button:hover { opacity: 1; }
    .row-column-slot .mini-block-actions button.text-danger { color: var(--danger-color, #ea868f); }
    .row-column-slot .empty-slot {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 40px;
        border: 1px dashed var(--border-color, #373b3e);
        border-radius: 0.25rem;
        color: var(--text-muted, #8b949e);
        font-size: 0.75rem;
        cursor: pointer;
        transition: border-color 0.15s, color 0.15s;
    }
    .row-column-slot .empty-slot:hover {
        border-color: var(--primary-color, #6ea8fe);
        color: var(--primary-color, #6ea8fe);
    }
    .row-column-slot .add-to-col-btn {
        display: block;
        width: 100%;
        border: none;
        background: none;
        padding: 0.125rem;
        margin-top: 0.25rem;
        cursor: pointer;
        font-size: 0.7rem;
        color: var(--text-muted, #8b949e);
        text-align: center;
        border-radius: 0.25rem;
        transition: color 0.15s, background 0.15s;
    }
    .row-column-slot .add-to-col-btn:hover {
        color: var(--primary-color, #6ea8fe);
        background: rgba(13, 110, 253, 0.05);
    }
    .row-column-slot.sortable-ghost-col {
        opacity: 0.4;
    }

    /* Tabs container block */
    .tabs-block-container {
        border: 1px dashed var(--border-color-light, #495057);
        border-radius: 0.5rem;
        padding: 0.5rem;
        background: var(--bg-body, #121212);
    }
    .tabs-nav-bar {
        display: flex;
        gap: 0.25rem;
        margin-bottom: 0.5rem;
        border-bottom: 1px solid var(--border-color, #373b3e);
        padding-bottom: 0.25rem;
    }
    .tabs-nav-btn {
        border: 1px solid var(--border-color, #373b3e);
        background: var(--bg-surface-raised, #2d2d2d);
        color: var(--text-secondary, #adb5bd);
        padding: 0.25rem 0.75rem;
        border-radius: 0.375rem 0.375rem 0 0;
        cursor: pointer;
        font-size: 0.75rem;
        transition: border-color 0.15s, color 0.15s, background 0.15s;
    }
    .tabs-nav-btn:hover {
        border-color: var(--primary-color, #6ea8fe);
        color: var(--primary-color, #6ea8fe);
    }
    .tabs-nav-btn.active {
        background: var(--bg-surface, #1e1e1e);
        color: var(--primary-color, #6ea8fe);
        border-color: var(--primary-color, #6ea8fe);
        border-bottom-color: var(--bg-surface, #1e1e1e);
    }
    .tab-content-slot {
        border: 1px dashed var(--border-color, #373b3e);
        border-radius: 0.375rem;
        padding: 0.375rem;
        min-height: 50px;
        background: var(--bg-surface-raised, #2d2d2d);
        transition: border-color 0.2s;
    }
    .tab-content-slot:hover {
        border-color: var(--primary-color, #6ea8fe);
    }
    /* Reuse mini-block styles from row columns for tab content */
    .tab-content-slot .mini-block,
    .tab-content-slot .empty-slot,
    .tab-content-slot .add-to-col-btn,
    .tab-content-slot .mini-block-actions {
        /* Inherited from .row-column-slot equivalents */
    }

    /* Config panel breadcrumb */
    .config-breadcrumb {
        font-size: 0.75rem;
        color: var(--text-muted, #8b949e);
        margin-bottom: 0.5rem;
    }
    .config-breadcrumb a {
        color: var(--primary-color, #6ea8fe);
        text-decoration: none;
    }
    .config-breadcrumb a:hover { text-decoration: underline; }

    /* Row preset buttons */
    .preset-btn {
        border: 1px solid var(--border-color, #373b3e);
        background: var(--bg-surface-raised, #2d2d2d);
        color: var(--text-secondary, #adb5bd);
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        cursor: pointer;
        font-size: 0.7rem;
        transition: border-color 0.15s, color 0.15s;
    }
    .preset-btn:hover {
        border-color: var(--primary-color, #6ea8fe);
        color: var(--primary-color, #6ea8fe);
    }

    /* Column mapper */
    .column-mapper-row {
        display: flex;
        align-items: center;
        gap: 0.25rem;
        margin-bottom: 0.375rem;
        padding: 0.375rem;
        background: var(--bg-surface-raised, #2d2d2d);
        border: 1px solid var(--border-color, #373b3e);
        border-radius: 0.375rem;
        font-size: 0.8rem;
    }
    .column-mapper-row .form-control-sm,
    .column-mapper-row .form-select-sm {
        font-size: 0.75rem;
        padding: 0.15rem 0.35rem;
    }
    .column-mapper-row .cm-field { flex: 1; min-width: 0; }
    .column-mapper-row .cm-label { flex: 1; min-width: 0; }
    .column-mapper-row .cm-sortable { flex: 0 0 auto; }
    .column-mapper-row .cm-remove {
        flex: 0 0 auto;
        border: none;
        background: none;
        color: var(--danger-color, #ea868f);
        cursor: pointer;
        padding: 0 0.25rem;
        opacity: 0.6;
        font-size: 0.85rem;
    }
    .column-mapper-row .cm-remove:hover { opacity: 1; }
    .column-mapper-actions {
        display: flex;
        gap: 0.5rem;
        margin-top: 0.25rem;
    }
    .column-mapper-actions button {
        font-size: 0.75rem;
        padding: 0.2rem 0.5rem;
    }

    /* Add block button */
    #addBlockBtn {
        border-style: dashed;
        border-color: var(--border-color-light, #495057);
        color: var(--text-muted, #8b949e);
        background: transparent;
    }
    #addBlockBtn:hover {
        border-color: var(--primary-color, #6ea8fe);
        color: var(--primary-color, #6ea8fe);
        background: rgba(13, 110, 253, 0.05);
    }
</style>

<!-- Header Bar -->
<div class="d-flex justify-content-between align-items-center px-3 py-2 builder-header"
     data-assist-page="appbuilder"
     data-assist-purpose="Build app pages by adding, configuring, and arranging blocks"
     data-assist-context="appbuilder">
    <div class="d-flex align-items-center gap-3">
        <a href="<?= h($backUrl) ?>" class="btn btn-sm btn-outline-secondary" title="<?= h($backLabel) ?>">
            <i class="bi bi-arrow-left"></i>
        </a>
        <div>
            <h5 class="mb-0"><?= h($app->name) ?></h5>
            <small class="text-muted">App Builder</small>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-primary" onclick="openPreview()">
            <i class="bi bi-eye"></i> Preview
        </button>
        <?php if ($showDeploy): ?>
        <button class="btn btn-sm btn-primary" onclick="deployApp()">
            <i class="bi bi-rocket"></i> Deploy
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- 3-Column Builder Layout -->
<div class="builder-layout">
    <!-- LEFT: Screen List + Config -->
    <div class="builder-sidebar">
        <div class="sidebar-section">
            <h6>Screens</h6>
            <div id="screenList"></div>
            <button class="btn btn-sm btn-outline-primary w-100 mt-2" onclick="addScreen()">
                <i class="bi bi-plus"></i> Add Screen
            </button>
        </div>

        <div class="sidebar-section">
            <h6>App Config</h6>
            <?php if ($showSecurity): ?>
            <a href="#" class="d-block small text-decoration-none mb-1" onclick="showSecurityPanel(); return false;">
                <i class="bi bi-shield-lock me-1"></i> Security
            </a>
            <?php endif; ?>
            <a href="#" class="d-block small text-decoration-none mb-1" onclick="showThemePanel(); return false;">
                <i class="bi bi-palette me-1"></i> Theme
            </a>
            <a href="#" class="d-block small text-decoration-none mb-1" onclick="showNavPanel(); return false;">
                <i class="bi bi-list me-1"></i> Navigation
            </a>
        </div>
    </div>

    <!-- CENTER: Block List -->
    <div class="builder-main" id="builderMain">
        <div id="blockList"></div>
        <button class="btn btn-outline-primary w-100" onclick="openBlockPicker()" id="addBlockBtn">
            <i class="bi bi-plus-lg me-1"></i> Add Block
        </button>

    </div>

    <!-- RIGHT: Block Config Panel -->
    <div class="builder-config" id="configPanel">
        <div class="p-3 text-center text-muted" id="configEmpty">
            <i class="bi bi-cursor-fill d-block" style="font-size:2rem;"></i>
            <p class="mt-2 mb-0 small">Select a block to configure it</p>
        </div>
        <div class="p-3" id="configContent" style="display:none;"></div>
    </div>
</div>

<!-- Block Picker Modal -->
<div class="modal fade" id="blockPickerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-square me-2"></i>Add Block</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php foreach ($blockTypes as $catKey => $cat): ?>
                <h6 class="text-muted mb-2 mt-3">
                    <i class="bi <?= $cat['icon'] ?> me-1"></i> <?= $cat['label'] ?>
                </h6>
                <div class="picker-grid mb-3">
                    <?php foreach ($cat['blocks'] as $type => $block): ?>
                    <div class="picker-item" onclick="addBlock('<?= $type ?>')">
                        <i class="bi <?= $block['icon'] ?> text-primary"></i>
                        <div class="fw-semibold small"><?= $block['label'] ?></div>
                        <div class="text-muted" style="font-size:0.75rem;"><?= $block['description'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add Screen Modal -->
<div class="modal fade" id="addScreenModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Screen</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Screen Name</label>
                    <input type="text" class="form-control" id="newScreenName" placeholder="e.g. Orders">
                </div>
                <div class="mb-3">
                    <label class="form-label">Route Path</label>
                    <input type="text" class="form-control" id="newScreenRoute" placeholder="/orders">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="createScreen()">Create</button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden builder state for Page Assistant context -->
<div id="assist-builder-state" style="display:none"
     data-assist-builder-screens=""
     data-assist-builder-block-types=""
     data-assist-builder-pipelines=""
     data-assist-builder-current-screen="0"
     data-assist-builder-selected-block=""
     data-assist-builder-dapp-context="<?= h($cfg['dappContext'] ?? '') ?>">
</div>

<script>
// ─── State ─────────────────────────────────────────────
const APP_ID = <?= $appId ?>;
const CSRF = '<?= $csrfToken ?>';
let screens = <?= $screensJson ?>;
let blockTypes = <?= $blockTypesJson ?>;
let pipelines = <?= $pipelinesJson ?>;
let securityConfig = <?= $securityJson ?>;

// ─── Configurable URLs (overridden by Detachableappbuilder) ───
const SAVE_SCREEN_URL = <?= json_encode($saveScreenUrl) ?>;
const DELETE_SCREEN_URL = <?= json_encode($deleteScreenUrl) ?>;
const SAVE_SECURITY_URL = <?= json_encode($saveSecurityUrl) ?>;
const PREVIEW_URL = <?= json_encode($previewUrl) ?>;
const DEPLOY_URL = <?= json_encode($deployUrl) ?>;
const CHANNEL_NAME = <?= json_encode($channelName) ?>;

let currentScreenIndex = 0;
let selectedBlockIndex = -1;
let selectedColIndex = -1;      // Which column inside a row (-1 = none)
let selectedColBlockIndex = -1; // Which block inside that column (-1 = none)
let selectedTabIndex = -1;      // Which tab inside a tabs block (-1 = none)
let selectedTabBlockIndex = -1; // Which block inside that tab (-1 = none)
let activeTabView = {};         // Track which tab is visually active per tabs block {blockIndex: tabIndex}

// ─── Assist State Sync ────────────────────────────────
function updateAssistState() {
    const el = document.getElementById('assist-builder-state');
    if (!el) return;

    // Build a compact summary of screens with blocks
    const screensSummary = screens.map((s, i) => ({
        index: i,
        id: s.id,
        name: s.name,
        route_path: s.route_path,
        is_default: s.is_default || false,
        blocks: (s.blocks || []).map(b => ({
            id: b.id,
            type: b.type,
            title: b.config?.title || blockTypes[b.type]?.label || b.type,
            config: b.config || {},
            data_binding: (b.data_binding && !Array.isArray(b.data_binding)) ? b.data_binding : {},
        })),
    }));

    // Block types: just type -> label + description
    const blockTypesSummary = {};
    for (const [type, def] of Object.entries(blockTypes)) {
        blockTypesSummary[type] = {
            label: def.label || type,
            description: def.description || '',
            category: def.category || '',
            supports_data_binding: def.supports_data_binding || false,
        };
    }

    // Pipelines: slug + name + description + output schema
    const pipelinesSummary = pipelines.map(p => ({
        slug: p.slug,
        name: p.name,
        description: p.description || '',
        output_schema: p.output_schema || null,
    }));

    el.dataset.assistBuilderScreens = JSON.stringify(screensSummary);
    el.dataset.assistBuilderBlockTypes = JSON.stringify(blockTypesSummary);
    el.dataset.assistBuilderPipelines = JSON.stringify(pipelinesSummary);
    el.dataset.assistBuilderCurrentScreen = String(currentScreenIndex);
    el.dataset.assistBuilderSelectedBlock = JSON.stringify({
        blockIndex: selectedBlockIndex,
        colIndex: selectedColIndex,
        colBlockIndex: selectedColBlockIndex,
        tabIndex: selectedTabIndex,
        tabBlockIndex: selectedTabBlockIndex,
    });
}

// ─── BuilderAssist API (for Page Assistant actions) ───
window.BuilderAssist = {
    addBlock(type, config, position) {
        const block = createBlockData(type);
        if (!block) return null;

        // Merge provided config
        if (config && typeof config === 'object') {
            Object.assign(block.config, config);
        }

        const screen = screens[currentScreenIndex];
        if (!screen.blocks) screen.blocks = [];

        // Insert at position or append
        if (typeof position === 'number' && position >= 0 && position <= screen.blocks.length) {
            screen.blocks.splice(position, 0, block);
            selectedBlockIndex = position;
        } else {
            screen.blocks.push(block);
            selectedBlockIndex = screen.blocks.length - 1;
        }
        selectedColIndex = -1;
        selectedColBlockIndex = -1;

        saveCurrentScreen();
        renderBlockList();
        showBlockConfig(selectedBlockIndex);
        return block.id;
    },

    updateBlock(blockId, config) {
        const screen = screens[currentScreenIndex];
        if (!screen?.blocks) return false;

        // Find block by ID (top-level or nested in rows/tabs)
        for (let i = 0; i < screen.blocks.length; i++) {
            const b = screen.blocks[i];
            if (b.id === blockId) {
                Object.assign(b.config, config);
                saveCurrentScreen();
                renderBlockList();
                return true;
            }
            // Search inside row columns
            if (b.type === 'row' && b.config?.columns) {
                for (const col of b.config.columns) {
                    for (const cb of (col.blocks || [])) {
                        if (cb.id === blockId) {
                            Object.assign(cb.config, config);
                            saveCurrentScreen();
                            renderBlockList();
                            return true;
                        }
                    }
                }
            }
            // Search inside tabs
            if (b.type === 'tabs' && b.config?.tabs) {
                for (const tab of b.config.tabs) {
                    for (const tb of (tab.blocks || [])) {
                        if (tb.id === blockId) {
                            Object.assign(tb.config, config);
                            saveCurrentScreen();
                            renderBlockList();
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    },

    removeBlock(blockId) {
        const screen = screens[currentScreenIndex];
        if (!screen?.blocks) return false;

        for (let i = 0; i < screen.blocks.length; i++) {
            if (screen.blocks[i].id === blockId) {
                screen.blocks.splice(i, 1);
                if (selectedBlockIndex >= screen.blocks.length) selectedBlockIndex = -1;
                selectedColIndex = -1;
                selectedColBlockIndex = -1;
                selectedTabIndex = -1;
                selectedTabBlockIndex = -1;
                saveCurrentScreen();
                renderBlockList();
                hideConfig();
                return true;
            }
            // Search inside row columns
            const b = screen.blocks[i];
            if (b.type === 'row' && b.config?.columns) {
                for (let ci = 0; ci < b.config.columns.length; ci++) {
                    const colBlocks = b.config.columns[ci].blocks || [];
                    for (let bi = 0; bi < colBlocks.length; bi++) {
                        if (colBlocks[bi].id === blockId) {
                            colBlocks.splice(bi, 1);
                            saveCurrentScreen();
                            renderBlockList();
                            return true;
                        }
                    }
                }
            }
            // Search inside tabs
            if (b.type === 'tabs' && b.config?.tabs) {
                for (let ti = 0; ti < b.config.tabs.length; ti++) {
                    const tabBlocks = b.config.tabs[ti].blocks || [];
                    for (let bi = 0; bi < tabBlocks.length; bi++) {
                        if (tabBlocks[bi].id === blockId) {
                            tabBlocks.splice(bi, 1);
                            saveCurrentScreen();
                            renderBlockList();
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    },

    selectBlock(blockId) {
        const screen = screens[currentScreenIndex];
        if (!screen?.blocks) return false;

        for (let i = 0; i < screen.blocks.length; i++) {
            if (screen.blocks[i].id === blockId) {
                selectBlock(i);
                // Scroll the block card into view
                const cards = document.querySelectorAll('.block-card');
                if (cards[i]) cards[i].scrollIntoView({ behavior: 'smooth', block: 'center' });
                return true;
            }
            // Search inside row columns
            const b = screen.blocks[i];
            if (b.type === 'row' && b.config?.columns) {
                for (let ci = 0; ci < b.config.columns.length; ci++) {
                    const colBlocks = b.config.columns[ci].blocks || [];
                    for (let bi = 0; bi < colBlocks.length; bi++) {
                        if (colBlocks[bi].id === blockId) {
                            selectColumnBlock(i, ci, bi);
                            return true;
                        }
                    }
                }
            }
            // Search inside tabs
            if (b.type === 'tabs' && b.config?.tabs) {
                for (let ti = 0; ti < b.config.tabs.length; ti++) {
                    const tabBlocks = b.config.tabs[ti].blocks || [];
                    for (let bi = 0; bi < tabBlocks.length; bi++) {
                        if (tabBlocks[bi].id === blockId) {
                            selectTabBlock(i, ti, bi);
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    },

    bindPipeline(blockId, pipelineSlug) {
        const screen = screens[currentScreenIndex];
        if (!screen?.blocks) return false;

        // Find block by ID (top-level or nested in rows/tabs)
        for (let i = 0; i < screen.blocks.length; i++) {
            const b = screen.blocks[i];
            if (b.id === blockId) {
                if (!b.data_binding || Array.isArray(b.data_binding)) b.data_binding = {};
                b.data_binding.pipeline_slug = pipelineSlug || '';
                saveCurrentScreen();
                selectBlock(i);
                this._flashPipelineDropdown(pipelineSlug);
                return true;
            }
            if (b.type === 'row' && b.config?.columns) {
                for (let ci = 0; ci < b.config.columns.length; ci++) {
                    const colBlocks = b.config.columns[ci].blocks || [];
                    for (let bi = 0; bi < colBlocks.length; bi++) {
                        if (colBlocks[bi].id === blockId) {
                            if (!colBlocks[bi].data_binding || Array.isArray(colBlocks[bi].data_binding)) colBlocks[bi].data_binding = {};
                            colBlocks[bi].data_binding.pipeline_slug = pipelineSlug || '';
                            saveCurrentScreen();
                            selectColumnBlock(i, ci, bi);
                            this._flashPipelineDropdown(pipelineSlug);
                            return true;
                        }
                    }
                }
            }
            if (b.type === 'tabs' && b.config?.tabs) {
                for (let ti = 0; ti < b.config.tabs.length; ti++) {
                    const tabBlocks = b.config.tabs[ti].blocks || [];
                    for (let bi = 0; bi < tabBlocks.length; bi++) {
                        if (tabBlocks[bi].id === blockId) {
                            if (!tabBlocks[bi].data_binding || Array.isArray(tabBlocks[bi].data_binding)) tabBlocks[bi].data_binding = {};
                            tabBlocks[bi].data_binding.pipeline_slug = pipelineSlug || '';
                            saveCurrentScreen();
                            selectTabBlock(i, ti, bi);
                            this._flashPipelineDropdown(pipelineSlug);
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    },

    _flashPipelineDropdown(pipelineSlug) {
        setTimeout(() => {
            const sel = document.getElementById('binding-pipeline-select');
            if (!sel) return;
            // Try to set the value if it matches an existing option
            if (pipelineSlug) {
                for (const opt of sel.options) {
                    if (opt.value === pipelineSlug) { sel.value = pipelineSlug; break; }
                }
            }
            // Scroll the data source section into view
            const section = document.getElementById('config-data-source');
            if (section) section.scrollIntoView({ behavior: 'smooth', block: 'center' });
            // Flash highlight
            sel.style.transition = 'box-shadow 0.3s, border-color 0.3s';
            sel.style.boxShadow = '0 0 0 3px rgba(13,110,253,0.5)';
            sel.style.borderColor = '#0d6efd';
            setTimeout(() => {
                sel.style.boxShadow = '';
                sel.style.borderColor = '';
            }, 2500);
        }, 150);
    },

    getState() {
        return {
            screens,
            currentScreenIndex,
            blockTypes,
            pipelines,
            currentScreen: screens[currentScreenIndex] || null,
        };
    },

    addScreen(name, slug) {
        const route = '/' + (slug || name.toLowerCase().replace(/[^a-z0-9]+/g, '-'));
        // Trigger async screen creation
        apiPost(SAVE_SCREEN_URL, {
            name,
            route_path: route,
            slug: slug || name.toLowerCase().replace(/[^a-z0-9]+/g, '-'),
            sort_order: screens.length,
        }).then(resp => {
            if (resp.success) {
                screens.push(resp.data);
                currentScreenIndex = screens.length - 1;
                renderScreenList();
                renderBlockList();
                updateAssistState();
            }
        });
    },

    addBlockToContainer(type, config, containerId, slotIndex) {
        const screen = screens[currentScreenIndex];
        if (!screen?.blocks) return null;

        // Don't allow nesting layout blocks inside containers
        if (type === 'row' || type === 'tabs') {
            console.warn('[BuilderAssist] Layout blocks cannot be nested in containers');
            return null;
        }

        const block = createBlockData(type);
        if (!block) return null;
        if (config && typeof config === 'object') {
            Object.assign(block.config, config);
        }

        // Find the container block by ID
        for (let i = 0; i < screen.blocks.length; i++) {
            const b = screen.blocks[i];
            if (b.id === containerId) {
                const slot = parseInt(slotIndex) || 0;
                if (b.type === 'row' && b.config?.columns?.[slot]) {
                    if (!b.config.columns[slot].blocks) b.config.columns[slot].blocks = [];
                    b.config.columns[slot].blocks.push(block);
                    selectedBlockIndex = i;
                    selectedColIndex = slot;
                    selectedColBlockIndex = b.config.columns[slot].blocks.length - 1;
                    selectedTabIndex = -1;
                    selectedTabBlockIndex = -1;
                } else if (b.type === 'tabs' && b.config?.tabs?.[slot]) {
                    if (!b.config.tabs[slot].blocks) b.config.tabs[slot].blocks = [];
                    b.config.tabs[slot].blocks.push(block);
                    selectedBlockIndex = i;
                    selectedColIndex = -1;
                    selectedColBlockIndex = -1;
                    selectedTabIndex = slot;
                    selectedTabBlockIndex = b.config.tabs[slot].blocks.length - 1;
                    activeTabView[i] = slot;
                } else {
                    console.warn('[BuilderAssist] Container slot not found:', containerId, slot);
                    return null;
                }
                saveCurrentScreen();
                renderBlockList();
                return block.id;
            }
        }
        console.warn('[BuilderAssist] Container not found:', containerId);
        return null;
    },

    moveBlock(blockId, targetPosition) {
        const screen = screens[currentScreenIndex];
        if (!screen?.blocks) return false;

        // Find and remove from current position (top-level only)
        let movedBlock = null;
        for (let i = 0; i < screen.blocks.length; i++) {
            if (screen.blocks[i].id === blockId) {
                [movedBlock] = screen.blocks.splice(i, 1);
                break;
            }
        }
        if (!movedBlock) return false;

        // Insert at target position
        const pos = Math.max(0, Math.min(parseInt(targetPosition) || 0, screen.blocks.length));
        screen.blocks.splice(pos, 0, movedBlock);

        selectedBlockIndex = pos;
        selectedColIndex = -1;
        selectedColBlockIndex = -1;
        selectedTabIndex = -1;
        selectedTabBlockIndex = -1;

        saveCurrentScreen();
        renderBlockList();
        showBlockConfig(pos);
        return true;
    },

    switchScreen(indexOrName) {
        // Try as index first
        const idx = parseInt(indexOrName);
        if (!isNaN(idx) && idx >= 0 && idx < screens.length) {
            selectScreen(idx);
            return true;
        }
        // Try as name (case-insensitive)
        const name = String(indexOrName).toLowerCase();
        for (let i = 0; i < screens.length; i++) {
            if ((screens[i].name || '').toLowerCase() === name) {
                selectScreen(i);
                return true;
            }
        }
        return false;
    },

    addTabToBlock(blockId, label, icon) {
        const screen = screens[currentScreenIndex];
        if (!screen?.blocks) return false;

        for (let i = 0; i < screen.blocks.length; i++) {
            if (screen.blocks[i].id === blockId && screen.blocks[i].type === 'tabs') {
                const tabsBlock = screen.blocks[i];
                tabsBlock.config.tabs.push({ label: label || 'New Tab', icon: icon || '', blocks: [] });
                saveCurrentScreen();
                renderBlockList();
                selectBlock(i);
                return true;
            }
        }
        return false;
    },

    addColumnToBlock(blockId, colClass) {
        const screen = screens[currentScreenIndex];
        if (!screen?.blocks) return false;

        for (let i = 0; i < screen.blocks.length; i++) {
            if (screen.blocks[i].id === blockId && screen.blocks[i].type === 'row') {
                screen.blocks[i].config.columns.push({ col_class: colClass || 'col-md-6', blocks: [] });
                saveCurrentScreen();
                renderBlockList();
                selectBlock(i);
                return true;
            }
        }
        return false;
    },

    scaffold(templateName, config) {
        config = config || {};
        const templates = {
            dashboard: () => {
                const title = config.title || 'Dashboard';
                // Header
                this.addBlock('header', { title, subtitle: config.subtitle || 'Overview', show_breadcrumb: true });
                // KPI Row
                this.addBlock('kpi_row', {
                    cards: [
                        { label: 'Total', value_key: 'total', icon: 'bi-bar-chart', color: 'primary' },
                        { label: 'Active', value_key: 'active', icon: 'bi-check-circle', color: 'success' },
                        { label: 'Pending', value_key: 'pending', icon: 'bi-clock', color: 'warning' },
                        { label: 'Issues', value_key: 'issues', icon: 'bi-exclamation-triangle', color: 'danger' },
                    ]
                });
                // Row with chart and list
                const rowId = this.addBlock('row', {
                    columns: [
                        { col_class: 'col-md-8', blocks: [] },
                        { col_class: 'col-md-4', blocks: [] },
                    ]
                });
                if (rowId) {
                    this.addBlockToContainer('chart', { title: config.chart_title || 'Trends', chart_type: 'line' }, rowId, 0);
                    this.addBlockToContainer('list_group', { title: config.list_title || 'Recent Activity' }, rowId, 1);
                }
            },
            detail_page: () => {
                const title = config.title || 'Details';
                this.addBlock('header', { title, show_breadcrumb: true });
                const tabsId = this.addBlock('tabs', {
                    tabs: [
                        { label: config.tab1 || 'Overview', icon: 'bi-info-circle', blocks: [] },
                        { label: config.tab2 || 'Data', icon: 'bi-table', blocks: [] },
                        { label: config.tab3 || 'Activity', icon: 'bi-clock-history', blocks: [] },
                    ]
                });
                if (tabsId) {
                    this.addBlockToContainer('card', { title: 'Summary', content: 'Overview content goes here' }, tabsId, 0);
                    this.addBlockToContainer('data_table', { title: 'Records', columns: [] }, tabsId, 1);
                    this.addBlockToContainer('list_group', { title: 'Recent Activity' }, tabsId, 2);
                }
            },
            form_page: () => {
                const title = config.title || 'Submit';
                this.addBlock('header', { title, show_breadcrumb: true });
                this.addBlock('card', { title: config.form_title || 'Form', content: '' });
                this.addBlock('form', {
                    fields: config.fields || [
                        { name: 'name', label: 'Name', type: 'text', required: true },
                        { name: 'email', label: 'Email', type: 'email', required: true },
                        { name: 'message', label: 'Message', type: 'textarea' },
                    ],
                    submit_label: config.submit_label || 'Submit',
                });
                this.addBlock('alert', { type: 'success', content: config.success_message || 'Form submitted successfully!', title: 'Success' });
            },
            crud_page: () => {
                const title = config.title || 'Manage Items';
                this.addBlock('header', { title, show_breadcrumb: true, actions: [{ label: 'Add New', icon: 'bi-plus', variant: 'primary' }] });
                this.addBlock('data_table', {
                    title: config.table_title || 'Items',
                    columns: config.columns || [
                        { key: 'name', label: 'Name' },
                        { key: 'status', label: 'Status' },
                        { key: 'updated_at', label: 'Updated' },
                    ],
                    show_search: true,
                    show_pagination: true,
                });
            },
            landing_page: () => {
                const title = config.title || 'Welcome';
                this.addBlock('header', { title, subtitle: config.subtitle || 'Get started today', show_breadcrumb: false });
                this.addBlock('card', { title: config.hero_title || 'About', content: config.hero_content || 'Describe your product or service here.' });
                const rowId = this.addBlock('row', {
                    columns: [
                        { col_class: 'col-md-4', blocks: [] },
                        { col_class: 'col-md-4', blocks: [] },
                        { col_class: 'col-md-4', blocks: [] },
                    ]
                });
                if (rowId) {
                    this.addBlockToContainer('card', { title: config.feature1 || 'Feature 1', content: 'Description' }, rowId, 0);
                    this.addBlockToContainer('card', { title: config.feature2 || 'Feature 2', content: 'Description' }, rowId, 1);
                    this.addBlockToContainer('card', { title: config.feature3 || 'Feature 3', content: 'Description' }, rowId, 2);
                }
                this.addBlock('form', {
                    fields: [
                        { name: 'email', label: 'Email', type: 'email', required: true },
                    ],
                    submit_label: config.cta || 'Get Started',
                });
            },
            support_portal: () => {
                const title = config.title || 'Support';
                this.addBlock('header', { title, subtitle: config.subtitle || 'How can we help?', show_breadcrumb: true });
                this.addBlock('form', {
                    fields: [
                        { name: 'search', label: 'Search for help...', type: 'text' },
                    ],
                    submit_label: 'Search',
                });
                const tabsId = this.addBlock('tabs', {
                    tabs: [
                        { label: 'FAQs', icon: 'bi-question-circle', blocks: [] },
                        { label: 'Contact', icon: 'bi-envelope', blocks: [] },
                        { label: 'Status', icon: 'bi-activity', blocks: [] },
                    ]
                });
                if (tabsId) {
                    this.addBlockToContainer('list_group', { title: 'Frequently Asked Questions' }, tabsId, 0);
                    this.addBlockToContainer('form', {
                        fields: [
                            { name: 'name', label: 'Name', type: 'text', required: true },
                            { name: 'email', label: 'Email', type: 'email', required: true },
                            { name: 'subject', label: 'Subject', type: 'text', required: true },
                            { name: 'message', label: 'Message', type: 'textarea', required: true },
                        ],
                        submit_label: 'Send Message',
                    }, tabsId, 1);
                    this.addBlockToContainer('kpi_row', {
                        cards: [
                            { label: 'Uptime', value_key: 'uptime', icon: 'bi-arrow-up-circle', color: 'success' },
                            { label: 'Response Time', value_key: 'response_time', icon: 'bi-speedometer2', color: 'info' },
                        ]
                    }, tabsId, 2);
                }
            },
            analytics_dashboard: () => {
                const title = config.title || 'Analytics';
                this.addBlock('header', { title, subtitle: config.subtitle || 'Performance overview', show_breadcrumb: true });
                this.addBlock('kpi_row', {
                    cards: [
                        { label: config.kpi1 || 'Revenue', value_key: 'revenue', icon: 'bi-currency-dollar', color: 'success', prefix: '$' },
                        { label: config.kpi2 || 'Orders', value_key: 'orders', icon: 'bi-cart', color: 'primary' },
                        { label: config.kpi3 || 'Visitors', value_key: 'visitors', icon: 'bi-people', color: 'info' },
                        { label: config.kpi4 || 'Conversion', value_key: 'conversion', icon: 'bi-percent', color: 'warning', suffix: '%' },
                    ]
                });
                const row1 = this.addBlock('row', {
                    columns: [
                        { col_class: 'col-md-8', blocks: [] },
                        { col_class: 'col-md-4', blocks: [] },
                    ]
                });
                if (row1) {
                    this.addBlockToContainer('chart', { title: config.chart_title || 'Revenue Trend', chart_type: 'line' }, row1, 0);
                    this.addBlockToContainer('chart', { title: config.pie_title || 'Traffic Sources', chart_type: 'doughnut' }, row1, 1);
                }
                this.addBlock('data_table', { title: config.table_title || 'Top Products', columns: [
                    { key: 'name', label: 'Product' }, { key: 'sales', label: 'Sales' }, { key: 'revenue', label: 'Revenue' },
                ], show_search: true, show_pagination: true });
            },
            settings_page: () => {
                const title = config.title || 'Settings';
                this.addBlock('header', { title, show_breadcrumb: true });
                const tabsId = this.addBlock('tabs', {
                    tabs: [
                        { label: config.tab1 || 'General', icon: 'bi-gear', blocks: [] },
                        { label: config.tab2 || 'Notifications', icon: 'bi-bell', blocks: [] },
                        { label: config.tab3 || 'Integrations', icon: 'bi-plug', blocks: [] },
                    ]
                });
                if (tabsId) {
                    this.addBlockToContainer('form', {
                        fields: config.general_fields || [
                            { name: 'site_name', label: 'Site Name', type: 'text', required: true },
                            { name: 'description', label: 'Description', type: 'textarea' },
                            { name: 'timezone', label: 'Timezone', type: 'text' },
                        ],
                        submit_label: 'Save Settings',
                    }, tabsId, 0);
                    this.addBlockToContainer('form', {
                        fields: [
                            { name: 'email_notifications', label: 'Email Notifications', type: 'checkbox' },
                            { name: 'sms_notifications', label: 'SMS Notifications', type: 'checkbox' },
                            { name: 'notification_email', label: 'Notification Email', type: 'email' },
                        ],
                        submit_label: 'Save Notifications',
                    }, tabsId, 1);
                    this.addBlockToContainer('list_group', { title: 'Connected Services' }, tabsId, 2);
                }
            },
            product_catalog: () => {
                const title = config.title || 'Products';
                this.addBlock('header', { title, subtitle: config.subtitle || 'Browse our catalog', show_breadcrumb: true,
                    actions: [{ label: 'Add Product', icon: 'bi-plus', variant: 'primary' }] });
                this.addBlock('form', {
                    fields: [
                        { name: 'search', label: 'Search products...', type: 'text' },
                    ],
                    submit_label: 'Search',
                });
                this.addBlock('data_table', {
                    title: config.table_title || 'Product Catalog',
                    columns: config.columns || [
                        { key: 'image', label: 'Image' },
                        { key: 'name', label: 'Product Name' },
                        { key: 'price', label: 'Price' },
                        { key: 'stock', label: 'Stock' },
                        { key: 'status', label: 'Status' },
                    ],
                    show_search: true,
                    show_pagination: true,
                });
            },
        };

        const builder = templates[templateName];
        if (!builder) {
            console.warn('[BuilderAssist] Unknown template:', templateName);
            return false;
        }
        builder();
        return true;
    },
};

// ─── Initialization ────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    renderScreenList();
    renderBlockList();
    updateAssistState();
});

// ─── Screen Management ─────────────────────────────────
function renderScreenList() {
    const el = document.getElementById('screenList');
    el.innerHTML = screens.map((s, i) => `
        <div class="screen-item${i === currentScreenIndex ? ' active' : ''}" onclick="selectScreen(${i})">
            <div class="d-flex align-items-center justify-content-between">
                <span><i class="bi ${s.is_default ? 'bi-house' : 'bi-file-earmark'} me-1"></i>${esc(s.name)}</span>
                ${!s.is_default ? `<button class="btn btn-sm p-0" style="color:inherit;opacity:0.5" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.5" onclick="deleteScreen(${s.id}, event)" title="Delete"><i class="bi bi-x"></i></button>` : ''}
            </div>
            <small style="opacity:0.5;font-size:0.75rem">${esc(s.route_path)}</small>
        </div>
    `).join('');
}

function selectScreen(index) {
    currentScreenIndex = index;
    selectedBlockIndex = -1;
    selectedColIndex = -1;
    selectedColBlockIndex = -1;
    selectedTabIndex = -1;
    selectedTabBlockIndex = -1;
    renderScreenList();
    renderBlockList();
    hideConfig();
    updateAssistState();
}

function addScreen() {
    document.getElementById('newScreenName').value = '';
    document.getElementById('newScreenRoute').value = '';
    new bootstrap.Modal(document.getElementById('addScreenModal')).show();
}

async function createScreen() {
    const name = document.getElementById('newScreenName').value.trim();
    const route = document.getElementById('newScreenRoute').value.trim() || '/' + name.toLowerCase().replace(/\s+/g, '-');

    if (!name) return;

    const resp = await apiPost(SAVE_SCREEN_URL, {
        name, route_path: route,
        slug: name.toLowerCase().replace(/[^a-z0-9]+/g, '-'),
        sort_order: screens.length,
    });

    if (resp.success) {
        screens.push(resp.data);
        currentScreenIndex = screens.length - 1;
        renderScreenList();
        renderBlockList();
        bootstrap.Modal.getInstance(document.getElementById('addScreenModal')).hide();
    }
}

async function deleteScreen(id, e) {
    e.stopPropagation();
    if (!confirm('Delete this screen?')) return;

    const resp = await apiPost(`${DELETE_SCREEN_URL}/${id}`, {});
    if (resp.success) {
        screens = screens.filter(s => s.id !== id);
        if (currentScreenIndex >= screens.length) currentScreenIndex = Math.max(0, screens.length - 1);
        selectedBlockIndex = -1;
        renderScreenList();
        renderBlockList();
        hideConfig();
    }
}

// ─── Block Management ──────────────────────────────────
function renderBlockList() {
    const screen = screens[currentScreenIndex];
    if (!screen) { document.getElementById('blockList').innerHTML = ''; return; }

    const blocks = screen.blocks || [];
    const el = document.getElementById('blockList');

    if (blocks.length === 0) {
        el.innerHTML = '<div class="text-center py-5" style="color:var(--text-muted,#8b949e)"><i class="bi bi-bricks d-block" style="font-size:2rem;color:var(--border-color-light,#495057)"></i><p class="mt-2">No blocks yet. Click "Add Block" to get started.</p></div>';
        return;
    }

    el.innerHTML = blocks.map((block, i) => {
        if (block.type === 'row') {
            return renderRowBlock(block, i);
        }
        if (block.type === 'tabs') {
            return renderTabsBlock(block, i);
        }
        return renderFlatBlock(block, i, blocks.length);
    }).join('');

    initSortable();
    initColumnSortables();
    initTabSortables();
}

function renderFlatBlock(block, i, totalBlocks) {
    const def = blockTypes[block.type] || {};
    const title = block.config?.title || def.label || block.type;
    const icon = def.icon || 'bi-square';
    const selected = i === selectedBlockIndex && selectedColIndex === -1 && selectedTabIndex === -1 ? ' selected' : '';
    return `
    <div class="block-card${selected}" onclick="selectBlock(${i})">
        <div class="block-header">
            <span class="drag-handle top-drag-handle" title="Drag to reorder"><i class="bi bi-grip-vertical"></i></span>
            <i class="bi ${icon} me-2 text-primary"></i>
            <span class="fw-semibold small">${esc(title)}</span>
            <span class="badge bg-light text-muted ms-2" style="font-size:0.65rem;">${block.type}</span>
            <div class="block-actions">
                <button onclick="moveBlock(${i}, -1, event)" title="Move up" ${i === 0 ? 'disabled' : ''}><i class="bi bi-chevron-up"></i></button>
                <button onclick="moveBlock(${i}, 1, event)" title="Move down" ${i === totalBlocks - 1 ? 'disabled' : ''}><i class="bi bi-chevron-down"></i></button>
                <button onclick="removeBlock(${i}, event)" title="Delete" class="text-danger"><i class="bi bi-trash"></i></button>
            </div>
        </div>
        <div class="block-body">
            ${getBlockSummary(block)}
        </div>
    </div>`;
}

function renderRowBlock(block, rowIndex) {
    const def = blockTypes['row'] || {};
    const columns = block.config?.columns || [];
    const selected = rowIndex === selectedBlockIndex && selectedColIndex === -1 && selectedColBlockIndex === -1 ? ' selected' : '';

    let columnsHtml = '';
    columns.forEach((col, colIdx) => {
        const colBlocks = col.blocks || [];
        let blocksHtml = '';
        colBlocks.forEach((cb, cbIdx) => {
            const cbDef = blockTypes[cb.type] || {};
            const cbTitle = cb.config?.title || cbDef.label || cb.type;
            const cbIcon = cbDef.icon || 'bi-square';
            const isSel = rowIndex === selectedBlockIndex && colIdx === selectedColIndex && cbIdx === selectedColBlockIndex;
            blocksHtml += `
            <div class="mini-block${isSel ? ' selected' : ''}" onclick="selectColumnBlock(${rowIndex}, ${colIdx}, ${cbIdx}); event.stopPropagation();" data-row="${rowIndex}" data-col="${colIdx}" data-cidx="${cbIdx}">
                <span class="drag-handle" title="Drag"><i class="bi bi-grip-vertical"></i></span>
                <i class="bi ${cbIcon} me-1 text-primary"></i>
                <span>${esc(cbTitle)}</span>
                <div class="mini-block-actions">
                    <button onclick="removeBlockFromColumn(${rowIndex}, ${colIdx}, ${cbIdx}); event.stopPropagation();" title="Remove" class="text-danger"><i class="bi bi-x"></i></button>
                </div>
            </div>`;
        });

        if (colBlocks.length === 0) {
            blocksHtml = `<div class="empty-slot" onclick="openBlockPickerForColumn(${rowIndex}, ${colIdx}); event.stopPropagation();"><i class="bi bi-plus me-1"></i>Drop here</div>`;
        }

        columnsHtml += `
        <div class="row-column-slot" data-row-idx="${rowIndex}" data-col-idx="${colIdx}">
            <div class="col-label">${esc(col.col_class || 'col')}</div>
            <div class="col-blocks-list" data-row="${rowIndex}" data-col="${colIdx}">
                ${blocksHtml}
            </div>
            <button class="add-to-col-btn" onclick="openBlockPickerForColumn(${rowIndex}, ${colIdx}); event.stopPropagation();" title="Add block to this column"><i class="bi bi-plus"></i></button>
        </div>`;
    });

    return `
    <div class="block-card${selected}" onclick="selectBlock(${rowIndex})">
        <div class="block-header">
            <span class="drag-handle top-drag-handle" title="Drag to reorder"><i class="bi bi-grip-vertical"></i></span>
            <i class="bi ${def.icon || 'bi-columns-gap'} me-2 text-primary"></i>
            <span class="fw-semibold small">${def.label || 'Row'}</span>
            <span class="badge bg-light text-muted ms-2" style="font-size:0.65rem;">row</span>
            <div class="block-actions">
                <button onclick="moveBlock(${rowIndex}, -1, event)" title="Move up"><i class="bi bi-chevron-up"></i></button>
                <button onclick="moveBlock(${rowIndex}, 1, event)" title="Move down"><i class="bi bi-chevron-down"></i></button>
                <button onclick="removeBlock(${rowIndex}, event)" title="Delete" class="text-danger"><i class="bi bi-trash"></i></button>
            </div>
        </div>
        <div class="block-body p-0">
            <div class="row-block-container">
                <div class="row-columns-flex">
                    ${columnsHtml}
                </div>
            </div>
        </div>
    </div>`;
}

function renderTabsBlock(block, blockIndex) {
    const def = blockTypes['tabs'] || {};
    const tabs = block.config?.tabs || [];
    const selected = blockIndex === selectedBlockIndex && selectedTabIndex === -1 && selectedTabBlockIndex === -1 ? ' selected' : '';
    const viewTabIdx = activeTabView[blockIndex] ?? 0;

    // Tab navigation buttons
    let tabNavHtml = '';
    tabs.forEach((tab, tabIdx) => {
        const isActive = tabIdx === viewTabIdx ? ' active' : '';
        const label = tab.label || 'Tab ' + (tabIdx + 1);
        const icon = tab.icon ? `<i class="bi ${esc(tab.icon)} me-1"></i>` : '';
        tabNavHtml += `<button class="tabs-nav-btn${isActive}" onclick="switchTabView(${blockIndex}, ${tabIdx}); event.stopPropagation();">${icon}${esc(label)}</button>`;
    });

    // Only show the active tab's content
    const activeTab = tabs[viewTabIdx];
    let tabContentHtml = '';
    if (activeTab) {
        const tabBlocks = activeTab.blocks || [];
        let blocksHtml = '';
        tabBlocks.forEach((cb, cbIdx) => {
            const cbDef = blockTypes[cb.type] || {};
            const cbTitle = cb.config?.title || cbDef.label || cb.type;
            const cbIcon = cbDef.icon || 'bi-square';
            const isSel = blockIndex === selectedBlockIndex && viewTabIdx === selectedTabIndex && cbIdx === selectedTabBlockIndex;
            blocksHtml += `
            <div class="mini-block${isSel ? ' selected' : ''}" onclick="selectTabBlock(${blockIndex}, ${viewTabIdx}, ${cbIdx}); event.stopPropagation();" data-block-idx="${blockIndex}" data-tab="${viewTabIdx}" data-tidx="${cbIdx}">
                <span class="drag-handle" title="Drag"><i class="bi bi-grip-vertical"></i></span>
                <i class="bi ${cbIcon} me-1 text-primary"></i>
                <span>${esc(cbTitle)}</span>
                <div class="mini-block-actions">
                    <button onclick="removeBlockFromTab(${blockIndex}, ${viewTabIdx}, ${cbIdx}); event.stopPropagation();" title="Remove" class="text-danger"><i class="bi bi-x"></i></button>
                </div>
            </div>`;
        });

        if (tabBlocks.length === 0) {
            blocksHtml = `<div class="empty-slot" onclick="openBlockPickerForTab(${blockIndex}, ${viewTabIdx}); event.stopPropagation();"><i class="bi bi-plus me-1"></i>Drop here</div>`;
        }

        tabContentHtml = `
        <div class="tab-content-slot" data-block-idx="${blockIndex}" data-tab-idx="${viewTabIdx}">
            <div class="tab-blocks-list" data-block-idx="${blockIndex}" data-tab="${viewTabIdx}">
                ${blocksHtml}
            </div>
            <button class="add-to-col-btn" onclick="openBlockPickerForTab(${blockIndex}, ${viewTabIdx}); event.stopPropagation();" title="Add block to this tab"><i class="bi bi-plus"></i></button>
        </div>`;
    }

    return `
    <div class="block-card${selected}" onclick="selectBlock(${blockIndex})">
        <div class="block-header">
            <span class="drag-handle top-drag-handle" title="Drag to reorder"><i class="bi bi-grip-vertical"></i></span>
            <i class="bi ${def.icon || 'bi-segmented-nav'} me-2 text-primary"></i>
            <span class="fw-semibold small">${def.label || 'Tabs'}</span>
            <span class="badge bg-light text-muted ms-2" style="font-size:0.65rem;">tabs</span>
            <div class="block-actions">
                <button onclick="moveBlock(${blockIndex}, -1, event)" title="Move up"><i class="bi bi-chevron-up"></i></button>
                <button onclick="moveBlock(${blockIndex}, 1, event)" title="Move down"><i class="bi bi-chevron-down"></i></button>
                <button onclick="removeBlock(${blockIndex}, event)" title="Delete" class="text-danger"><i class="bi bi-trash"></i></button>
            </div>
        </div>
        <div class="block-body p-0">
            <div class="tabs-block-container">
                <div class="tabs-nav-bar">
                    ${tabNavHtml}
                </div>
                ${tabContentHtml}
            </div>
        </div>
    </div>`;
}

function switchTabView(blockIndex, tabIndex) {
    activeTabView[blockIndex] = tabIndex;
    renderBlockList();
    // If a tab block is selected at the top level, re-show its config
    if (selectedBlockIndex === blockIndex && selectedTabIndex === -1) {
        showBlockConfig(blockIndex);
    }
}

function selectTabBlock(blockIndex, tabIndex, blockInTabIndex) {
    selectedBlockIndex = blockIndex;
    selectedColIndex = -1;
    selectedColBlockIndex = -1;
    selectedTabIndex = tabIndex;
    selectedTabBlockIndex = blockInTabIndex;
    activeTabView[blockIndex] = tabIndex;
    renderBlockList();
    showTabBlockConfig(blockIndex, tabIndex, blockInTabIndex);
    updateAssistState();
}

function showTabBlockConfig(blockIndex, tabIndex, blockInTabIndex) {
    const screen = screens[currentScreenIndex];
    const tabsBlock = screen?.blocks?.[blockIndex];
    if (!tabsBlock || tabsBlock.type !== 'tabs') { hideConfig(); return; }

    const tab = tabsBlock.config.tabs[tabIndex];
    if (!tab) { hideConfig(); return; }

    const block = tab.blocks?.[blockInTabIndex];
    if (!block) { hideConfig(); return; }

    const def = blockTypes[block.type] || {};
    const schema = def.config_schema || {};

    // Set pipeline slug for column mapper before rendering fields
    const tabBinding = (block.data_binding && !Array.isArray(block.data_binding)) ? block.data_binding : {};
    window._cmCurrentPipelineSlug = tabBinding.pipeline_slug || '';

    document.getElementById('configEmpty').style.display = 'none';
    const el = document.getElementById('configContent');
    el.style.display = 'block';

    // Breadcrumb
    let html = `<div class="config-breadcrumb">
        <a href="#" onclick="selectBlock(${blockIndex}); return false;">Tabs</a>
        <i class="bi bi-chevron-right mx-1"></i>
        <span>${esc(tab.label || 'Tab ' + (tabIndex + 1))}</span>
        <i class="bi bi-chevron-right mx-1"></i>
        <span>${esc(def.label || block.type)}</span>
    </div>`;

    html += `<h6 class="mb-3"><i class="bi ${def.icon || 'bi-square'} me-1"></i> ${def.label || block.type}</h6>`;

    // Config fields
    html += '<div class="config-section"><h6>Configuration</h6>';
    for (const [key, fieldDef] of Object.entries(schema)) {
        html += renderConfigFieldForTab(key, fieldDef, block.config[key] ?? fieldDef.default ?? '', blockIndex, tabIndex, blockInTabIndex);
    }
    html += '</div>';

    // Data binding
    if (def.supports_data_binding) {
        const binding = block.data_binding || {};
        html += '<div class="config-section" id="config-data-source"><h6>Data Source</h6>';
        html += `<div class="mb-2">
            <label class="form-label small">Pipeline</label>
            <select class="form-select form-select-sm" id="binding-pipeline-select" onchange="updateTabBlockBinding(${blockIndex}, ${tabIndex}, ${blockInTabIndex}, 'pipeline_slug', this.value)">
                <option value="">-- None --</option>
                ${pipelines.map(p => `<option value="${p.slug}" ${binding.pipeline_slug === p.slug ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}
            </select>
        </div>`;
        html += '</div>';
    }

    el.innerHTML = html;
}

// ─── Column Mapper Helpers ─────────────────────────────
function getPipelineFields(pipelineSlug) {
    if (!pipelineSlug) return [];
    const p = pipelines.find(p => p.slug === pipelineSlug);
    if (!p || !p.output_schema) return [];
    for (const [key, type] of Object.entries(p.output_schema)) {
        const match = String(type).match(/^array\[(.+)\]$/);
        if (match) return match[1].split(',').map(f => f.trim());
    }
    return [];
}

function getBoundPipelineSlug(block) {
    if (!block) return '';
    const binding = block.data_binding;
    if (binding && !Array.isArray(binding)) return binding.pipeline_slug || '';
    return '';
}

// Set by showBlockConfig/showColumnBlockConfig/showTabBlockConfig before rendering fields
window._cmCurrentPipelineSlug = '';

function renderColumnMapper(key, currentValue, updateFnStr) {
    const columns = Array.isArray(currentValue) ? currentValue : [];
    const fields = getPipelineFields(window._cmCurrentPipelineSlug || '');
    const hasFields = fields.length > 0;

    let html = '<div class="mb-2"><label class="form-label small">Columns</label>';
    html += '<div id="column-mapper-rows">';

    columns.forEach((col, i) => {
        html += renderColumnMapperRow(col, i, fields, hasFields, updateFnStr);
    });

    html += '</div>';

    // Action buttons
    html += '<div class="column-mapper-actions">';
    html += `<button class="btn btn-sm btn-outline-secondary" onclick="addColumnMapperRow('${updateFnStr}')"><i class="bi bi-plus me-1"></i>Add Column</button>`;
    if (hasFields) {
        html += `<button class="btn btn-sm btn-outline-primary" onclick="autoFillColumns('${updateFnStr}')"><i class="bi bi-lightning me-1"></i>Auto-fill</button>`;
    }
    html += '</div></div>';

    return html;
}

function renderColumnMapperRow(col, index, fields, hasFields, updateFnStr) {
    const field = col.field || '';
    const label = col.label || '';
    const sortable = col.sortable || false;
    const render = col.render || 'text';

    let fieldInput;
    if (hasFields) {
        fieldInput = `<select class="form-select form-select-sm cm-field" data-cm-idx="${index}" data-cm-key="field" onchange="updateColumnMapperField(this, '${updateFnStr}')">
            <option value="">-- field --</option>
            ${fields.map(f => `<option value="${f}" ${field === f ? 'selected' : ''}>${f}</option>`).join('')}
            ${field && !fields.includes(field) ? `<option value="${esc(field)}" selected>${esc(field)}</option>` : ''}
        </select>`;
    } else {
        fieldInput = `<input type="text" class="form-control form-control-sm cm-field" value="${esc(field)}" placeholder="field" data-cm-idx="${index}" data-cm-key="field" onchange="updateColumnMapperField(this, '${updateFnStr}')">`;
    }

    return `<div class="column-mapper-row" data-cm-row="${index}">
        ${fieldInput}
        <input type="text" class="form-control form-control-sm cm-label" value="${esc(label)}" placeholder="Label" data-cm-idx="${index}" data-cm-key="label" onchange="updateColumnMapperField(this, '${updateFnStr}')">
        <div class="cm-sortable" title="Sortable">
            <input type="checkbox" class="form-check-input" ${sortable ? 'checked' : ''} data-cm-idx="${index}" data-cm-key="sortable" onchange="updateColumnMapperField(this, '${updateFnStr}')">
        </div>
        <button class="cm-remove" title="Remove column" onclick="removeColumnMapperRow(${index}, '${updateFnStr}')"><i class="bi bi-x-lg"></i></button>
    </div>`;
}

function getColumnMapperValue() {
    const rows = document.querySelectorAll('#column-mapper-rows .column-mapper-row');
    const columns = [];
    rows.forEach(row => {
        const fieldEl = row.querySelector('[data-cm-key="field"]');
        const labelEl = row.querySelector('[data-cm-key="label"]');
        const sortableEl = row.querySelector('[data-cm-key="sortable"]');
        if (!fieldEl) return;
        const col = {
            field: fieldEl.value || '',
            label: labelEl ? labelEl.value : '',
            sortable: sortableEl ? sortableEl.checked : false,
        };
        if (col.field || col.label) columns.push(col);
    });
    return columns;
}

function updateColumnMapperField(el, updateFnStr) {
    const columns = getColumnMapperValue();
    // Call the appropriate update function
    const fn = window[updateFnStr];
    if (typeof fn === 'function') {
        fn('columns', columns);
    }
}

function addColumnMapperRow(updateFnStr) {
    const columns = getColumnMapperValue();
    columns.push({ field: '', label: '', sortable: false });
    const fn = window[updateFnStr];
    if (typeof fn === 'function') {
        fn('columns', columns);
    }
    // Re-render the config panel to show the new row
    if (selectedBlockIndex >= 0) {
        showBlockConfig(selectedBlockIndex);
    }
}

function removeColumnMapperRow(index, updateFnStr) {
    const columns = getColumnMapperValue();
    columns.splice(index, 1);
    const fn = window[updateFnStr];
    if (typeof fn === 'function') {
        fn('columns', columns);
    }
    if (selectedBlockIndex >= 0) {
        showBlockConfig(selectedBlockIndex);
    }
}

function autoFillColumns(updateFnStr) {
    // Try the live DOM select first (user may have changed it), then fall back to stored value
    const pipelineSelect = document.getElementById('binding-pipeline-select');
    const slug = pipelineSelect ? pipelineSelect.value : (window._cmCurrentPipelineSlug || '');
    const fields = getPipelineFields(slug);
    if (!fields.length) return;
    const columns = fields.map(f => ({
        field: f,
        label: f.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()),
        sortable: true
    }));
    const fn = window[updateFnStr];
    if (typeof fn === 'function') {
        fn('columns', columns);
    }
    if (selectedBlockIndex >= 0) {
        showBlockConfig(selectedBlockIndex);
    }
}

// Column mapper update bridge functions for each context
// Top-level: already uses updateConfig(key, value)
// Column block: needs bridge
window._cmUpdateColumnBlock = function(key, value) {
    if (typeof window._cmColCtx === 'undefined') return;
    const ctx = window._cmColCtx;
    updateColumnBlockConfig(ctx.row, ctx.col, ctx.block, key, value);
};
// Tab block: needs bridge
window._cmUpdateTabBlock = function(key, value) {
    if (typeof window._cmTabCtx === 'undefined') return;
    const ctx = window._cmTabCtx;
    updateTabBlockConfig(ctx.block, ctx.tab, ctx.blockInTab, key, value);
};

// ─── Config Field Renderers ───────────────────────────
function renderConfigFieldForTab(key, def, value, blockIndex, tabIndex, blockInTabIndex) {
    const label = def.label || key;

    switch (def.type) {
        case 'string':
            return `<div class="mb-2">
                <label class="form-label small">${label}</label>
                <input type="text" class="form-control form-control-sm" value="${esc(value || '')}" onchange="updateTabBlockConfig(${blockIndex}, ${tabIndex}, ${blockInTabIndex}, '${key}', this.value)">
            </div>`;
        case 'text':
            return `<div class="mb-2">
                <label class="form-label small">${label}</label>
                <textarea class="form-control form-control-sm" rows="3" onchange="updateTabBlockConfig(${blockIndex}, ${tabIndex}, ${blockInTabIndex}, '${key}', this.value)">${esc(value || '')}</textarea>
            </div>`;
        case 'integer':
            return `<div class="mb-2">
                <label class="form-label small">${label}</label>
                <input type="number" class="form-control form-control-sm" value="${value || ''}" onchange="updateTabBlockConfig(${blockIndex}, ${tabIndex}, ${blockInTabIndex}, '${key}', parseInt(this.value))">
            </div>`;
        case 'boolean':
            return `<div class="mb-2 form-check">
                <input type="checkbox" class="form-check-input" id="tcfg_${key}" ${value ? 'checked' : ''} onchange="updateTabBlockConfig(${blockIndex}, ${tabIndex}, ${blockInTabIndex}, '${key}', this.checked)">
                <label class="form-check-label small" for="tcfg_${key}">${label}</label>
            </div>`;
        case 'enum':
            const options = def.options || [];
            return `<div class="mb-2">
                <label class="form-label small">${label}</label>
                <select class="form-select form-select-sm" onchange="updateTabBlockConfig(${blockIndex}, ${tabIndex}, ${blockInTabIndex}, '${key}', this.value)">
                    ${options.map(o => `<option value="${o}" ${value === o ? 'selected' : ''}>${o}</option>`).join('')}
                </select>
            </div>`;
        case 'column_mapper':
            window._cmTabCtx = { block: blockIndex, tab: tabIndex, blockInTab: blockInTabIndex };
            return renderColumnMapper(key, value, '_cmUpdateTabBlock');
        case 'json':
            const jsonVal = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
            return `<div class="mb-2">
                <label class="form-label small">${label}</label>
                <textarea class="form-control form-control-sm font-monospace" rows="4" onchange="updateTabBlockConfigJson(${blockIndex}, ${tabIndex}, ${blockInTabIndex}, '${key}', this.value)" style="font-size:0.75rem;">${esc(jsonVal)}</textarea>
                ${def.description ? `<div class="form-text" style="font-size:0.7rem;">${def.description}</div>` : ''}
            </div>`;
        default:
            return `<div class="mb-2">
                <label class="form-label small">${label}</label>
                <input type="text" class="form-control form-control-sm" value="${esc(String(value || ''))}" onchange="updateTabBlockConfig(${blockIndex}, ${tabIndex}, ${blockInTabIndex}, '${key}', this.value)">
            </div>`;
    }
}

function updateTabBlockConfig(blockIndex, tabIndex, blockInTabIndex, key, value) {
    const screen = screens[currentScreenIndex];
    const block = screen?.blocks?.[blockIndex]?.config?.tabs?.[tabIndex]?.blocks?.[blockInTabIndex];
    if (!block) return;
    block.config[key] = value;
    saveCurrentScreen();
    renderBlockList();
}

function updateTabBlockConfigJson(blockIndex, tabIndex, blockInTabIndex, key, value) {
    try {
        const parsed = JSON.parse(value);
        updateTabBlockConfig(blockIndex, tabIndex, blockInTabIndex, key, parsed);
    } catch (e) {
        // Invalid JSON, ignore
    }
}

function updateTabBlockBinding(blockIndex, tabIndex, blockInTabIndex, key, value) {
    const screen = screens[currentScreenIndex];
    const block = screen?.blocks?.[blockIndex]?.config?.tabs?.[tabIndex]?.blocks?.[blockInTabIndex];
    if (!block) return;
    if (!block.data_binding || Array.isArray(block.data_binding)) block.data_binding = {};
    block.data_binding[key] = value;
    saveCurrentScreen();
    if (key === 'pipeline_slug') showBlockConfig(blockIndex);
}

function removeBlockFromTab(blockIndex, tabIndex, blockInTabIndex) {
    if (!confirm('Remove this block?')) return;
    const screen = screens[currentScreenIndex];
    const tabsBlock = screen.blocks[blockIndex];
    if (!tabsBlock || tabsBlock.type !== 'tabs') return;
    tabsBlock.config.tabs[tabIndex].blocks.splice(blockInTabIndex, 1);
    if (selectedTabIndex === tabIndex && selectedTabBlockIndex === blockInTabIndex) {
        selectedTabIndex = -1;
        selectedTabBlockIndex = -1;
    }
    saveCurrentScreen();
    renderBlockList();
    showBlockConfig(blockIndex);
}

function openBlockPickerForTab(blockIndex, tabIndex) {
    pickerTarget = { blockIndex, tabIndex };
    new bootstrap.Modal(document.getElementById('blockPickerModal')).show();
}

function initTabSortables() {
    // Create Sortable for each tab block list
    document.querySelectorAll('.tab-blocks-list').forEach(listEl => {
        const blockIdx = parseInt(listEl.dataset.blockIdx);
        const tabIdx = parseInt(listEl.dataset.tab);

        const s = new Sortable(listEl, {
            group: 'tab-blocks-' + blockIdx,
            handle: '.drag-handle',
            draggable: '.mini-block',
            animation: 150,
            ghostClass: 'sortable-ghost-col',
            onEnd: function(evt) {
                const screen = screens[currentScreenIndex];
                const tabsBlock = screen?.blocks?.[blockIdx];
                if (!tabsBlock || tabsBlock.type !== 'tabs') return;

                const fromTabIdx = parseInt(evt.from.dataset.tab);
                const toTabIdx = parseInt(evt.to.dataset.tab);
                const oldIndex = evt.oldIndex;
                const newIndex = evt.newIndex;

                const fromBlocks = tabsBlock.config.tabs[fromTabIdx].blocks;
                const [moved] = fromBlocks.splice(oldIndex, 1);

                if (fromTabIdx === toTabIdx) {
                    fromBlocks.splice(newIndex, 0, moved);
                } else {
                    if (!tabsBlock.config.tabs[toTabIdx].blocks) tabsBlock.config.tabs[toTabIdx].blocks = [];
                    tabsBlock.config.tabs[toTabIdx].blocks.splice(newIndex, 0, moved);
                }

                saveCurrentScreen();
            }
        });
        columnSortableInstances.push(s);
    });
}

function getBlockSummary(block) {
    const c = block.config || {};
    switch (block.type) {
        case 'header': return c.subtitle ? esc(c.subtitle) : 'Page header';
        case 'data_table': return `${(c.columns || []).length} columns` + (block.data_binding?.pipeline_slug ? ` &middot; ${block.data_binding.pipeline_slug}` : '');
        case 'form': return `${(c.fields || []).length} fields`;
        case 'kpi_row': return `${(c.cards || []).length} KPI cards`;
        case 'chat_widget': return c.welcome_message ? esc(c.welcome_message.substring(0, 40)) : 'Chat interface';
        case 'alert': return esc((c.content || '').substring(0, 50));
        case 'button_group': return `${(c.buttons || []).length} buttons`;
        case 'markdown': return esc((c.content || '').substring(0, 50));
        case 'card': return c.content ? esc(c.content.substring(0, 50)) : 'Card';
        case 'list_group': return block.data_binding?.pipeline_slug || 'List';
        case 'chart': return c.chart_type || 'Chart';
        case 'tabs': return `${(c.tabs || []).length} tabs`;
        case 'row': return `${(c.columns || []).length} columns`;
        default: return block.type;
    }
}

// Target for block picker: null = top-level, {rowIndex, colIndex} = inside a column
let pickerTarget = null;

function openBlockPicker() {
    pickerTarget = null;
    new bootstrap.Modal(document.getElementById('blockPickerModal')).show();
}

function openBlockPickerForColumn(rowIndex, colIndex) {
    pickerTarget = { rowIndex, colIndex };
    new bootstrap.Modal(document.getElementById('blockPickerModal')).show();
}

function createBlockData(type) {
    const def = blockTypes[type];
    if (!def) return null;
    return {
        id: 'block_' + Math.random().toString(36).substr(2, 8),
        type: type,
        config: JSON.parse(JSON.stringify(def.default_config)),
        data_binding: {},
        events: {},
        visibility: {},
    };
}

function addBlock(type) {
    // Don't allow nesting layout blocks inside containers
    if ((type === 'row' || type === 'tabs') && pickerTarget) {
        showToast('Layout blocks cannot be nested inside columns or tabs', 'warning');
        bootstrap.Modal.getInstance(document.getElementById('blockPickerModal'))?.hide();
        return;
    }

    const block = createBlockData(type);
    if (!block) return;

    const screen = screens[currentScreenIndex];
    if (!screen.blocks) screen.blocks = [];

    if (pickerTarget && pickerTarget.tabIndex !== undefined) {
        // Add to tab
        const { blockIndex, tabIndex } = pickerTarget;
        const tabsBlock = screen.blocks[blockIndex];
        if (tabsBlock && tabsBlock.type === 'tabs') {
            if (!tabsBlock.config.tabs[tabIndex].blocks) tabsBlock.config.tabs[tabIndex].blocks = [];
            tabsBlock.config.tabs[tabIndex].blocks.push(block);
            selectedBlockIndex = blockIndex;
            selectedColIndex = -1;
            selectedColBlockIndex = -1;
            selectedTabIndex = tabIndex;
            selectedTabBlockIndex = tabsBlock.config.tabs[tabIndex].blocks.length - 1;
            activeTabView[blockIndex] = tabIndex;
        }
    } else if (pickerTarget && pickerTarget.rowIndex !== undefined) {
        // Add to column
        const { rowIndex, colIndex } = pickerTarget;
        const rowBlock = screen.blocks[rowIndex];
        if (rowBlock && rowBlock.type === 'row') {
            if (!rowBlock.config.columns[colIndex].blocks) rowBlock.config.columns[colIndex].blocks = [];
            rowBlock.config.columns[colIndex].blocks.push(block);
            selectedBlockIndex = rowIndex;
            selectedColIndex = colIndex;
            selectedColBlockIndex = rowBlock.config.columns[colIndex].blocks.length - 1;
            selectedTabIndex = -1;
            selectedTabBlockIndex = -1;
        }
    } else {
        // Add to top level
        screen.blocks.push(block);
        selectedBlockIndex = screen.blocks.length - 1;
        selectedColIndex = -1;
        selectedColBlockIndex = -1;
        selectedTabIndex = -1;
        selectedTabBlockIndex = -1;
    }

    saveCurrentScreen();
    renderBlockList();
    showBlockConfig(selectedBlockIndex);

    bootstrap.Modal.getInstance(document.getElementById('blockPickerModal'))?.hide();
    pickerTarget = null;
}

function selectBlock(index) {
    selectedBlockIndex = index;
    selectedColIndex = -1;
    selectedColBlockIndex = -1;
    selectedTabIndex = -1;
    selectedTabBlockIndex = -1;
    renderBlockList();
    showBlockConfig(index);
    updateAssistState();
}

function selectColumnBlock(rowIndex, colIndex, blockIndex) {
    selectedBlockIndex = rowIndex;
    selectedColIndex = colIndex;
    selectedColBlockIndex = blockIndex;
    renderBlockList();
    showColumnBlockConfig(rowIndex, colIndex, blockIndex);
    updateAssistState();
}

function removeBlockFromColumn(rowIndex, colIndex, blockIndex) {
    if (!confirm('Remove this block?')) return;
    const screen = screens[currentScreenIndex];
    const rowBlock = screen.blocks[rowIndex];
    if (!rowBlock || rowBlock.type !== 'row') return;
    rowBlock.config.columns[colIndex].blocks.splice(blockIndex, 1);
    if (selectedColIndex === colIndex && selectedColBlockIndex === blockIndex) {
        selectedColIndex = -1;
        selectedColBlockIndex = -1;
    }
    saveCurrentScreen();
    renderBlockList();
    showBlockConfig(rowIndex);
}

function moveBlock(index, direction, e) {
    e.stopPropagation();
    const screen = screens[currentScreenIndex];
    const blocks = screen.blocks || [];
    const newIndex = index + direction;
    if (newIndex < 0 || newIndex >= blocks.length) return;

    [blocks[index], blocks[newIndex]] = [blocks[newIndex], blocks[index]];
    if (selectedBlockIndex === index) selectedBlockIndex = newIndex;
    else if (selectedBlockIndex === newIndex) selectedBlockIndex = index;

    saveCurrentScreen();
    renderBlockList();
}

// ─── Drag-and-Drop Reordering ──────────────────────────
let sortableInstance = null;
let columnSortableInstances = [];

function initSortable() {
    const el = document.getElementById('blockList');
    if (!el) return;

    if (sortableInstance) sortableInstance.destroy();

    sortableInstance = new Sortable(el, {
        handle: '.top-drag-handle',
        draggable: '.block-card',
        animation: 150,
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        onEnd: function(evt) {
            const screen = screens[currentScreenIndex];
            if (!screen || !screen.blocks) return;

            const oldIndex = evt.oldIndex;
            const newIndex = evt.newIndex;
            if (oldIndex === newIndex) return;

            const [moved] = screen.blocks.splice(oldIndex, 1);
            screen.blocks.splice(newIndex, 0, moved);

            if (selectedBlockIndex === oldIndex) {
                selectedBlockIndex = newIndex;
            } else if (oldIndex < selectedBlockIndex && newIndex >= selectedBlockIndex) {
                selectedBlockIndex--;
            } else if (oldIndex > selectedBlockIndex && newIndex <= selectedBlockIndex) {
                selectedBlockIndex++;
            }

            saveCurrentScreen();
        }
    });
}

function initColumnSortables() {
    // Destroy old instances
    columnSortableInstances.forEach(s => s.destroy());
    columnSortableInstances = [];

    // Create Sortable for each column block list
    document.querySelectorAll('.col-blocks-list').forEach(listEl => {
        const rowIdx = parseInt(listEl.dataset.row);
        const colIdx = parseInt(listEl.dataset.col);

        const s = new Sortable(listEl, {
            group: 'row-blocks-' + rowIdx,
            handle: '.drag-handle',
            draggable: '.mini-block',
            animation: 150,
            ghostClass: 'sortable-ghost-col',
            onEnd: function(evt) {
                const screen = screens[currentScreenIndex];
                const rowBlock = screen?.blocks?.[rowIdx];
                if (!rowBlock || rowBlock.type !== 'row') return;

                const fromColIdx = parseInt(evt.from.dataset.col);
                const toColIdx = parseInt(evt.to.dataset.col);
                const oldIndex = evt.oldIndex;
                const newIndex = evt.newIndex;

                const fromBlocks = rowBlock.config.columns[fromColIdx].blocks;
                const [moved] = fromBlocks.splice(oldIndex, 1);

                if (fromColIdx === toColIdx) {
                    fromBlocks.splice(newIndex, 0, moved);
                } else {
                    const toBlocks = rowBlock.config.columns[toColIdx].blocks;
                    if (!toBlocks) rowBlock.config.columns[toColIdx].blocks = [];
                    rowBlock.config.columns[toColIdx].blocks.splice(newIndex, 0, moved);
                }

                saveCurrentScreen();
            }
        });
        columnSortableInstances.push(s);
    });
}

function removeBlock(index, e) {
    e.stopPropagation();
    if (!confirm('Remove this block?')) return;

    const screen = screens[currentScreenIndex];
    screen.blocks.splice(index, 1);
    if (selectedBlockIndex >= screen.blocks.length) selectedBlockIndex = -1;
    selectedColIndex = -1;
    selectedColBlockIndex = -1;
    selectedTabIndex = -1;
    selectedTabBlockIndex = -1;

    saveCurrentScreen();
    renderBlockList();
    hideConfig();
}

// ─── Block Configuration Panel ─────────────────────────
function showBlockConfig(index) {
    const screen = screens[currentScreenIndex];
    const block = screen?.blocks?.[index];
    if (!block) { hideConfig(); return; }

    // If a column block is selected, show that instead
    if (selectedColIndex >= 0 && selectedColBlockIndex >= 0) {
        showColumnBlockConfig(index, selectedColIndex, selectedColBlockIndex);
        return;
    }
    // If a tab block is selected, show that instead
    if (selectedTabIndex >= 0 && selectedTabBlockIndex >= 0) {
        showTabBlockConfig(index, selectedTabIndex, selectedTabBlockIndex);
        return;
    }

    const def = blockTypes[block.type] || {};
    const schema = def.config_schema || {};

    // Set pipeline slug for column mapper before rendering fields
    const binding = (block.data_binding && !Array.isArray(block.data_binding)) ? block.data_binding : {};
    window._cmCurrentPipelineSlug = binding.pipeline_slug || '';

    document.getElementById('configEmpty').style.display = 'none';
    const el = document.getElementById('configContent');
    el.style.display = 'block';

    let html = `<h6 class="mb-3"><i class="bi ${def.icon || 'bi-square'} me-1"></i> ${def.label || block.type}</h6>`;

    // Row-specific config
    if (block.type === 'row') {
        html += renderRowConfig(block, index);
    } else if (block.type === 'tabs') {
        html += renderTabsConfig(block, index);
    } else {
        // Standard config fields
        html += '<div class="config-section"><h6>Configuration</h6>';
        for (const [key, fieldDef] of Object.entries(schema)) {
            html += renderConfigField(key, fieldDef, block.config[key] ?? fieldDef.default ?? '');
        }
        html += '</div>';
    }

    // Data binding (if supported)
    if (def.supports_data_binding) {
        const binding = block.data_binding || {};
        html += '<div class="config-section" id="config-data-source"><h6>Data Source</h6>';
        html += `<div class="mb-2">
            <label class="form-label small">Pipeline</label>
            <select class="form-select form-select-sm" id="binding-pipeline-select" onchange="updateBinding(${index}, 'pipeline_slug', this.value)">
                <option value="">-- None --</option>
                ${pipelines.map(p => `<option value="${p.slug}" ${binding.pipeline_slug === p.slug ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}
            </select>
        </div>`;
        html += '</div>';
    }

    // Events (if available)
    const events = getBlockEvents(block.type);
    if (events.length) {
        html += '<div class="config-section"><h6>Events</h6>';
        for (const evt of events) {
            const eventConfig = block.events?.[evt] || {};
            html += `<div class="mb-2">
                <label class="form-label small">${evt}</label>
                <select class="form-select form-select-sm" onchange="updateEvent(${index}, '${evt}', 'action', this.value)">
                    <option value="">-- No action --</option>
                    <option value="run_pipeline" ${eventConfig.action === 'run_pipeline' ? 'selected' : ''}>Run Pipeline</option>
                    <option value="navigate" ${eventConfig.action === 'navigate' ? 'selected' : ''}>Navigate</option>
                    <option value="show_alert" ${eventConfig.action === 'show_alert' ? 'selected' : ''}>Show Alert</option>
                    <option value="refresh_block" ${eventConfig.action === 'refresh_block' ? 'selected' : ''}>Refresh Block</option>
                </select>
            </div>`;
            if (eventConfig.action === 'run_pipeline') {
                html += `<div class="mb-2 ms-2">
                    <label class="form-label small">Pipeline</label>
                    <select class="form-select form-select-sm" onchange="updateEvent(${index}, '${evt}', 'pipeline_slug', this.value)">
                        ${pipelines.map(p => `<option value="${p.slug}" ${eventConfig.pipeline_slug === p.slug ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}
                    </select>
                </div>`;
            }
            if (eventConfig.action === 'navigate') {
                html += `<div class="mb-2 ms-2">
                    <label class="form-label small">Target URL</label>
                    <input type="text" class="form-control form-control-sm" value="${esc(eventConfig.target || '')}" onchange="updateEvent(${index}, '${evt}', 'target', this.value)">
                </div>`;
            }
        }
        html += '</div>';
    }

    el.innerHTML = html;
}

function renderRowConfig(block, rowIndex) {
    const columns = block.config?.columns || [];
    const colOptions = [
        { value: 'col',      label: 'Auto' },
        { value: 'col-md-6', label: 'Half (6/12)' },
        { value: 'col-md-4', label: 'Third (4/12)' },
        { value: 'col-md-3', label: 'Quarter (3/12)' },
        { value: 'col-md-8', label: 'Two-thirds (8/12)' },
        { value: 'col-md-2', label: 'Sixth (2/12)' },
        { value: 'col-lg-6', label: 'Half lg (6/12)' },
        { value: 'col-lg-4', label: 'Third lg (4/12)' },
        { value: 'col-lg-3', label: 'Quarter lg (3/12)' },
    ];

    let html = '<div class="config-section"><h6>Preset Layouts</h6>';
    html += '<div class="d-flex flex-wrap gap-1 mb-2">';
    html += `<button class="preset-btn" onclick="applyRowPreset(${rowIndex}, ['col-md-6','col-md-6'])">2 Equal</button>`;
    html += `<button class="preset-btn" onclick="applyRowPreset(${rowIndex}, ['col-md-4','col-md-4','col-md-4'])">3 Equal</button>`;
    html += `<button class="preset-btn" onclick="applyRowPreset(${rowIndex}, ['col-md-3','col-md-3','col-md-3','col-md-3'])">4 Equal</button>`;
    html += `<button class="preset-btn" onclick="applyRowPreset(${rowIndex}, ['col-md-4','col-md-8'])">1/3 + 2/3</button>`;
    html += `<button class="preset-btn" onclick="applyRowPreset(${rowIndex}, ['col-md-8','col-md-4'])">2/3 + 1/3</button>`;
    html += '</div></div>';

    html += '<div class="config-section"><h6>Columns</h6>';
    columns.forEach((col, colIdx) => {
        const blockCount = (col.blocks || []).length;
        html += `<div class="d-flex align-items-center gap-1 mb-2">
            <span class="small" style="min-width:1.5rem;color:var(--text-muted,#8b949e)">${colIdx + 1}.</span>
            <select class="form-select form-select-sm" onchange="updateColumnClass(${rowIndex}, ${colIdx}, this.value)">
                ${colOptions.map(o => `<option value="${o.value}" ${col.col_class === o.value ? 'selected' : ''}>${o.label}</option>`).join('')}
            </select>
            <button class="btn btn-sm btn-outline-danger" onclick="removeColumn(${rowIndex}, ${colIdx})" title="Remove column" ${columns.length <= 1 ? 'disabled' : ''}>
                <i class="bi bi-x"></i>
            </button>
        </div>`;
        if (blockCount > 0) {
            html += `<div class="small ms-4 mb-1" style="color:var(--text-muted,#8b949e)">${blockCount} block${blockCount !== 1 ? 's' : ''}</div>`;
        }
    });
    html += `<button class="btn btn-sm btn-outline-secondary w-100 mt-1" onclick="addColumn(${rowIndex})"><i class="bi bi-plus me-1"></i>Add Column</button>`;
    html += '</div>';

    return html;
}

function renderTabsConfig(block, blockIndex) {
    const tabs = block.config?.tabs || [];

    let html = '<div class="config-section"><h6>Tabs</h6>';
    tabs.forEach((tab, tabIdx) => {
        const blockCount = (tab.blocks || []).length;
        html += `<div class="d-flex align-items-center gap-1 mb-2">
            <span class="small" style="min-width:1.5rem;color:var(--text-muted,#8b949e)">${tabIdx + 1}.</span>
            <input type="text" class="form-control form-control-sm" value="${esc(tab.label || '')}" onchange="updateTabLabel(${blockIndex}, ${tabIdx}, this.value)" placeholder="Tab label">
            <input type="text" class="form-control form-control-sm" value="${esc(tab.icon || '')}" onchange="updateTabIcon(${blockIndex}, ${tabIdx}, this.value)" placeholder="bi-icon" style="max-width:80px;">
            <button class="btn btn-sm btn-outline-danger" onclick="removeTab(${blockIndex}, ${tabIdx})" title="Remove tab" ${tabs.length <= 1 ? 'disabled' : ''}>
                <i class="bi bi-x"></i>
            </button>
        </div>`;
        if (blockCount > 0) {
            html += `<div class="small ms-4 mb-1" style="color:var(--text-muted,#8b949e)">${blockCount} block${blockCount !== 1 ? 's' : ''}</div>`;
        }
    });
    html += `<button class="btn btn-sm btn-outline-secondary w-100 mt-1" onclick="addTab(${blockIndex})"><i class="bi bi-plus me-1"></i>Add Tab</button>`;
    html += '</div>';

    return html;
}

function updateTabLabel(blockIndex, tabIndex, value) {
    const screen = screens[currentScreenIndex];
    const tabsBlock = screen?.blocks?.[blockIndex];
    if (!tabsBlock || tabsBlock.type !== 'tabs') return;
    tabsBlock.config.tabs[tabIndex].label = value;
    saveCurrentScreen();
    renderBlockList();
    selectBlock(blockIndex);
}

function updateTabIcon(blockIndex, tabIndex, value) {
    const screen = screens[currentScreenIndex];
    const tabsBlock = screen?.blocks?.[blockIndex];
    if (!tabsBlock || tabsBlock.type !== 'tabs') return;
    tabsBlock.config.tabs[tabIndex].icon = value;
    saveCurrentScreen();
    renderBlockList();
    selectBlock(blockIndex);
}

function addTab(blockIndex) {
    const screen = screens[currentScreenIndex];
    const tabsBlock = screen?.blocks?.[blockIndex];
    if (!tabsBlock || tabsBlock.type !== 'tabs') return;
    tabsBlock.config.tabs.push({ label: 'Tab ' + (tabsBlock.config.tabs.length + 1), icon: '', blocks: [] });
    saveCurrentScreen();
    renderBlockList();
    selectBlock(blockIndex);
}

function removeTab(blockIndex, tabIndex) {
    const screen = screens[currentScreenIndex];
    const tabsBlock = screen?.blocks?.[blockIndex];
    if (!tabsBlock || tabsBlock.type !== 'tabs') return;
    const tab = tabsBlock.config.tabs[tabIndex];
    if (tab.blocks && tab.blocks.length > 0) {
        if (!confirm('This tab has blocks. Remove it?')) return;
    }
    tabsBlock.config.tabs.splice(tabIndex, 1);
    // Adjust active view
    if ((activeTabView[blockIndex] ?? 0) >= tabsBlock.config.tabs.length) {
        activeTabView[blockIndex] = Math.max(0, tabsBlock.config.tabs.length - 1);
    }
    saveCurrentScreen();
    renderBlockList();
    selectBlock(blockIndex);
}

function showColumnBlockConfig(rowIndex, colIndex, blockIndex) {
    const screen = screens[currentScreenIndex];
    const rowBlock = screen?.blocks?.[rowIndex];
    if (!rowBlock || rowBlock.type !== 'row') { hideConfig(); return; }

    const col = rowBlock.config.columns[colIndex];
    if (!col) { hideConfig(); return; }

    const block = col.blocks?.[blockIndex];
    if (!block) { hideConfig(); return; }

    const def = blockTypes[block.type] || {};
    const schema = def.config_schema || {};

    // Set pipeline slug for column mapper before rendering fields
    const colBinding = (block.data_binding && !Array.isArray(block.data_binding)) ? block.data_binding : {};
    window._cmCurrentPipelineSlug = colBinding.pipeline_slug || '';

    document.getElementById('configEmpty').style.display = 'none';
    const el = document.getElementById('configContent');
    el.style.display = 'block';

    // Breadcrumb
    let html = `<div class="config-breadcrumb">
        <a href="#" onclick="selectBlock(${rowIndex}); return false;">Row</a>
        <i class="bi bi-chevron-right mx-1"></i>
        <span>Col ${colIndex + 1}</span>
        <i class="bi bi-chevron-right mx-1"></i>
        <span>${esc(def.label || block.type)}</span>
    </div>`;

    html += `<h6 class="mb-3"><i class="bi ${def.icon || 'bi-square'} me-1"></i> ${def.label || block.type}</h6>`;

    // Config fields
    html += '<div class="config-section"><h6>Configuration</h6>';
    for (const [key, fieldDef] of Object.entries(schema)) {
        html += renderConfigFieldForColumn(key, fieldDef, block.config[key] ?? fieldDef.default ?? '', rowIndex, colIndex, blockIndex);
    }
    html += '</div>';

    // Data binding
    if (def.supports_data_binding) {
        const binding = block.data_binding || {};
        html += '<div class="config-section" id="config-data-source"><h6>Data Source</h6>';
        html += `<div class="mb-2">
            <label class="form-label small">Pipeline</label>
            <select class="form-select form-select-sm" id="binding-pipeline-select" onchange="updateColumnBlockBinding(${rowIndex}, ${colIndex}, ${blockIndex}, 'pipeline_slug', this.value)">
                <option value="">-- None --</option>
                ${pipelines.map(p => `<option value="${p.slug}" ${binding.pipeline_slug === p.slug ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}
            </select>
        </div>`;
        html += '</div>';
    }

    el.innerHTML = html;
}

function renderConfigFieldForColumn(key, def, value, rowIndex, colIndex, blockIndex) {
    const label = def.label || key;

    switch (def.type) {
        case 'string':
            return `<div class="mb-2">
                <label class="form-label small">${label}</label>
                <input type="text" class="form-control form-control-sm" value="${esc(value || '')}" onchange="updateColumnBlockConfig(${rowIndex}, ${colIndex}, ${blockIndex}, '${key}', this.value)">
            </div>`;
        case 'text':
            return `<div class="mb-2">
                <label class="form-label small">${label}</label>
                <textarea class="form-control form-control-sm" rows="3" onchange="updateColumnBlockConfig(${rowIndex}, ${colIndex}, ${blockIndex}, '${key}', this.value)">${esc(value || '')}</textarea>
            </div>`;
        case 'integer':
            return `<div class="mb-2">
                <label class="form-label small">${label}</label>
                <input type="number" class="form-control form-control-sm" value="${value || ''}" onchange="updateColumnBlockConfig(${rowIndex}, ${colIndex}, ${blockIndex}, '${key}', parseInt(this.value))">
            </div>`;
        case 'boolean':
            return `<div class="mb-2 form-check">
                <input type="checkbox" class="form-check-input" id="ccfg_${key}" ${value ? 'checked' : ''} onchange="updateColumnBlockConfig(${rowIndex}, ${colIndex}, ${blockIndex}, '${key}', this.checked)">
                <label class="form-check-label small" for="ccfg_${key}">${label}</label>
            </div>`;
        case 'enum':
            const options = def.options || [];
            return `<div class="mb-2">
                <label class="form-label small">${label}</label>
                <select class="form-select form-select-sm" onchange="updateColumnBlockConfig(${rowIndex}, ${colIndex}, ${blockIndex}, '${key}', this.value)">
                    ${options.map(o => `<option value="${o}" ${value === o ? 'selected' : ''}>${o}</option>`).join('')}
                </select>
            </div>`;
        case 'column_mapper':
            window._cmColCtx = { row: rowIndex, col: colIndex, block: blockIndex };
            return renderColumnMapper(key, value, '_cmUpdateColumnBlock');
        case 'json':
            const jsonVal = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
            return `<div class="mb-2">
                <label class="form-label small">${label}</label>
                <textarea class="form-control form-control-sm font-monospace" rows="4" onchange="updateColumnBlockConfigJson(${rowIndex}, ${colIndex}, ${blockIndex}, '${key}', this.value)" style="font-size:0.75rem;">${esc(jsonVal)}</textarea>
                ${def.description ? `<div class="form-text" style="font-size:0.7rem;">${def.description}</div>` : ''}
            </div>`;
        default:
            return `<div class="mb-2">
                <label class="form-label small">${label}</label>
                <input type="text" class="form-control form-control-sm" value="${esc(String(value || ''))}" onchange="updateColumnBlockConfig(${rowIndex}, ${colIndex}, ${blockIndex}, '${key}', this.value)">
            </div>`;
    }
}

function renderConfigField(key, def, value) {
    const label = def.label || key;
    const id = `cfg_${key}`;

    switch (def.type) {
        case 'string':
            return `<div class="mb-2">
                <label class="form-label small">${label}</label>
                <input type="text" class="form-control form-control-sm" value="${esc(value || '')}" onchange="updateConfig('${key}', this.value)">
            </div>`;
        case 'text':
            return `<div class="mb-2">
                <label class="form-label small">${label}</label>
                <textarea class="form-control form-control-sm" rows="3" onchange="updateConfig('${key}', this.value)">${esc(value || '')}</textarea>
            </div>`;
        case 'integer':
            return `<div class="mb-2">
                <label class="form-label small">${label}</label>
                <input type="number" class="form-control form-control-sm" value="${value || ''}" onchange="updateConfig('${key}', parseInt(this.value))">
            </div>`;
        case 'boolean':
            return `<div class="mb-2 form-check">
                <input type="checkbox" class="form-check-input" id="${id}" ${value ? 'checked' : ''} onchange="updateConfig('${key}', this.checked)">
                <label class="form-check-label small" for="${id}">${label}</label>
            </div>`;
        case 'enum':
            const options = def.options || [];
            return `<div class="mb-2">
                <label class="form-label small">${label}</label>
                <select class="form-select form-select-sm" onchange="updateConfig('${key}', this.value)">
                    ${options.map(o => `<option value="${o}" ${value === o ? 'selected' : ''}>${o}</option>`).join('')}
                </select>
            </div>`;
        case 'column_mapper':
            return renderColumnMapper(key, value, 'updateConfig');
        case 'json':
            const jsonVal = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
            return `<div class="mb-2">
                <label class="form-label small">${label}</label>
                <textarea class="form-control form-control-sm font-monospace" rows="4" onchange="updateConfigJson('${key}', this.value)" style="font-size:0.75rem;">${esc(jsonVal)}</textarea>
                ${def.description ? `<div class="form-text" style="font-size:0.7rem;">${def.description}</div>` : ''}
            </div>`;
        default:
            return `<div class="mb-2">
                <label class="form-label small">${label}</label>
                <input type="text" class="form-control form-control-sm" value="${esc(String(value || ''))}" onchange="updateConfig('${key}', this.value)">
            </div>`;
    }
}

function getBlockEvents(type) {
    const map = {
        form: ['onSubmit'],
        button_group: ['onClick'],
        data_table: ['onClick', 'onLoad'],
        kpi_row: ['onLoad'],
        list_group: ['onClick', 'onLoad'],
        chat_widget: ['onMessage'],
        chart: ['onLoad'],
    };
    return map[type] || [];
}

function hideConfig() {
    document.getElementById('configEmpty').style.display = 'block';
    document.getElementById('configContent').style.display = 'none';
    updateAssistState();
}

// ─── Config Update Handlers ────────────────────────────
function updateConfig(key, value) {
    const block = screens[currentScreenIndex]?.blocks?.[selectedBlockIndex];
    if (!block) return;
    block.config[key] = value;
    saveCurrentScreen();
    renderBlockList();
}

function updateConfigJson(key, value) {
    try {
        const parsed = JSON.parse(value);
        updateConfig(key, parsed);
    } catch (e) {
        // Invalid JSON, ignore
    }
}

function updateBinding(index, key, value) {
    const block = screens[currentScreenIndex]?.blocks?.[index];
    if (!block) return;
    if (!block.data_binding || Array.isArray(block.data_binding)) block.data_binding = {};
    block.data_binding[key] = value;
    saveCurrentScreen();
    // Re-render config panel so column mapper picks up new pipeline fields
    if (key === 'pipeline_slug') showBlockConfig(index);
}

function updateEvent(index, eventName, key, value) {
    const block = screens[currentScreenIndex]?.blocks?.[index];
    if (!block) return;
    if (!block.events) block.events = {};
    if (!block.events[eventName]) block.events[eventName] = {};
    block.events[eventName][key] = value;
    saveCurrentScreen();
    showBlockConfig(index);
}

// ─── Row / Column Config Handlers ──────────────────────
function applyRowPreset(rowIndex, colClasses) {
    const screen = screens[currentScreenIndex];
    const rowBlock = screen?.blocks?.[rowIndex];
    if (!rowBlock || rowBlock.type !== 'row') return;

    const existing = rowBlock.config.columns || [];
    const newCols = colClasses.map((cls, i) => ({
        col_class: cls,
        blocks: existing[i]?.blocks || [],
    }));
    rowBlock.config.columns = newCols;

    saveCurrentScreen();
    renderBlockList();
    selectBlock(rowIndex);
}

function updateColumnClass(rowIndex, colIndex, value) {
    const screen = screens[currentScreenIndex];
    const rowBlock = screen?.blocks?.[rowIndex];
    if (!rowBlock || rowBlock.type !== 'row') return;
    rowBlock.config.columns[colIndex].col_class = value;
    saveCurrentScreen();
    renderBlockList();
    selectBlock(rowIndex);
}

function addColumn(rowIndex) {
    const screen = screens[currentScreenIndex];
    const rowBlock = screen?.blocks?.[rowIndex];
    if (!rowBlock || rowBlock.type !== 'row') return;
    rowBlock.config.columns.push({ col_class: 'col-md-6', blocks: [] });
    saveCurrentScreen();
    renderBlockList();
    selectBlock(rowIndex);
}

function removeColumn(rowIndex, colIndex) {
    const screen = screens[currentScreenIndex];
    const rowBlock = screen?.blocks?.[rowIndex];
    if (!rowBlock || rowBlock.type !== 'row') return;
    const col = rowBlock.config.columns[colIndex];
    if (col.blocks && col.blocks.length > 0) {
        if (!confirm('This column has blocks. Remove it?')) return;
    }
    rowBlock.config.columns.splice(colIndex, 1);
    saveCurrentScreen();
    renderBlockList();
    selectBlock(rowIndex);
}

function updateColumnBlockConfig(rowIndex, colIndex, blockIndex, key, value) {
    const screen = screens[currentScreenIndex];
    const block = screen?.blocks?.[rowIndex]?.config?.columns?.[colIndex]?.blocks?.[blockIndex];
    if (!block) return;
    block.config[key] = value;
    saveCurrentScreen();
    renderBlockList();
}

function updateColumnBlockConfigJson(rowIndex, colIndex, blockIndex, key, value) {
    try {
        const parsed = JSON.parse(value);
        updateColumnBlockConfig(rowIndex, colIndex, blockIndex, key, parsed);
    } catch (e) {
        // Invalid JSON, ignore
    }
}

function updateColumnBlockBinding(rowIndex, colIndex, blockIndex, key, value) {
    const screen = screens[currentScreenIndex];
    const block = screen?.blocks?.[rowIndex]?.config?.columns?.[colIndex]?.blocks?.[blockIndex];
    if (!block) return;
    if (!block.data_binding || Array.isArray(block.data_binding)) block.data_binding = {};
    block.data_binding[key] = value;
    saveCurrentScreen();
    if (key === 'pipeline_slug') showBlockConfig(rowIndex);
}

// ─── Security Panel ────────────────────────────────────
function showSecurityPanel() {
    selectedBlockIndex = -1;
    renderBlockList();

    document.getElementById('configEmpty').style.display = 'none';
    const el = document.getElementById('configContent');
    el.style.display = 'block';

    const sec = securityConfig;
    let html = '<h6 class="mb-3"><i class="bi bi-shield-lock me-1"></i> Security</h6>';

    // Auth type
    html += '<div class="config-section"><h6>Authentication</h6>';
    const authOptions = [
        <?php foreach ($authTypes as $at => $info): ?>
        { value: '<?= $at ?>', label: '<?= $info['label'] ?>' },
        <?php endforeach; ?>
    ];
    html += '<div class="mb-2"><label class="form-label small">Auth Type</label><select class="form-select form-select-sm" id="secAuthType" onchange="updateSecurityAuthType(this.value)">';
    for (const opt of authOptions) {
        const sel = (sec.auth_type === opt.value) ? ' selected' : '';
        html += '<option value="' + opt.value + '"' + sel + '>' + esc(opt.label) + '</option>';
    }
    html += '</select></div>';
    html += '</div>';

    // Pipeline whitelist
    html += '<div class="config-section"><h6>Allowed Pipelines</h6>';
    const allowed = sec.allowed_pipelines || [];
    for (const p of pipelines) {
        const checked = allowed.includes(p.slug) ? 'checked' : '';
        html += `<div class="form-check">
            <input class="form-check-input" type="checkbox" id="sec_p_${p.slug}" ${checked} onchange="toggleAllowedPipeline('${p.slug}', this.checked)">
            <label class="form-check-label small" for="sec_p_${p.slug}">${esc(p.name)}</label>
        </div>`;
    }
    html += '</div>';

    // Data scoping
    html += '<div class="config-section"><h6>Data Scoping</h6>';
    html += '<p class="small text-muted">Fields injected server-side (cannot be overridden by client)</p>';
    const injected = sec.pipeline_scoping?.injected_fields || {};
    html += '<div id="scopingFields">';
    for (const [field, source] of Object.entries(injected)) {
        html += renderScopingField(field, source);
    }
    html += '</div>';
    html += '<button class="btn btn-sm btn-outline-secondary mt-2" onclick="addScopingField()"><i class="bi bi-plus"></i> Add Field</button>';
    html += '</div>';

    html += '<button class="btn btn-primary btn-sm w-100 mt-3" onclick="saveSecurity()"><i class="bi bi-check-lg me-1"></i>Save Security</button>';

    el.innerHTML = html;
}

function renderScopingField(field, source) {
    return `<div class="input-group input-group-sm mb-1" data-scoping>
        <input type="text" class="form-control" value="${esc(field)}" placeholder="field_name" data-scoping-field>
        <span class="input-group-text">=</span>
        <input type="text" class="form-control" value="${esc(source)}" placeholder="{auth.email}" data-scoping-source>
        <button class="btn btn-outline-danger" onclick="this.closest('[data-scoping]').remove()"><i class="bi bi-x"></i></button>
    </div>`;
}

function addScopingField() {
    document.getElementById('scopingFields').insertAdjacentHTML('beforeend', renderScopingField('', ''));
}

function updateSecurityAuthType(value) {
    securityConfig.auth_type = value;
}

function toggleAllowedPipeline(slug, checked) {
    if (!securityConfig.allowed_pipelines) securityConfig.allowed_pipelines = [];
    if (checked && !securityConfig.allowed_pipelines.includes(slug)) {
        securityConfig.allowed_pipelines.push(slug);
    } else if (!checked) {
        securityConfig.allowed_pipelines = securityConfig.allowed_pipelines.filter(s => s !== slug);
    }
}

async function saveSecurity() {
    // Collect scoping fields from DOM
    const injected = {};
    document.querySelectorAll('[data-scoping]').forEach(row => {
        const field = row.querySelector('[data-scoping-field]').value.trim();
        const source = row.querySelector('[data-scoping-source]').value.trim();
        if (field && source) injected[field] = source;
    });
    securityConfig.pipeline_scoping = { injected_fields: injected };

    const resp = SAVE_SECURITY_URL ? await apiPost(SAVE_SECURITY_URL, { security: securityConfig }) : {success: false, message: 'Not available'};
    if (resp.success) {
        showToast('Security config saved');
    } else {
        showToast(resp.message || 'Save failed', 'danger');
    }
}

// ─── Theme Panel ───────────────────────────────────────
function showThemePanel() {
    selectedBlockIndex = -1;
    renderBlockList();

    document.getElementById('configEmpty').style.display = 'none';
    const el = document.getElementById('configContent');
    el.style.display = 'block';

    el.innerHTML = `
        <h6 class="mb-3"><i class="bi bi-palette me-1"></i> Theme</h6>
        <div class="mb-3">
            <label class="form-label small">Primary Color</label>
            <input type="color" class="form-control form-control-sm form-control-color" value="#6366f1" id="themeColor">
        </div>
        <p class="small text-muted">Theme settings are applied when the app is deployed.</p>
    `;
}

function showNavPanel() {
    selectedBlockIndex = -1;
    renderBlockList();

    document.getElementById('configEmpty').style.display = 'none';
    const el = document.getElementById('configContent');
    el.style.display = 'block';

    el.innerHTML = `
        <h6 class="mb-3"><i class="bi bi-list me-1"></i> Navigation</h6>
        <p class="small text-muted">Navigation is auto-generated from your screens. Reorder screens in the left panel to change nav order.</p>
        <ul class="list-group list-group-sm">
            ${screens.map(s => `<li class="list-group-item d-flex justify-content-between small">
                ${esc(s.name)} <code>${esc(s.route_path)}</code>
            </li>`).join('')}
        </ul>
    `;
}

// ─── Preview ───────────────────────────────────────────
async function openPreview() {
    const screen = screens[currentScreenIndex];
    if (!screen) return;

    // Flush any pending debounced save before opening preview
    clearTimeout(saveTimeout);
    await apiPost(SAVE_SCREEN_URL, {
        id: screen.id,
        blocks: screen.blocks || [],
    });

    window.open(`${PREVIEW_URL}?screen_id=${screen.id}&_=${Date.now()}`, '_blank');
}

// ─── Deploy ────────────────────────────────────────────
async function deployApp() {
    if (!confirm('Build and deploy this app?')) return;

    const btn = event.target.closest('.btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Deploying...';

    const resp = DEPLOY_URL ? await apiPost(DEPLOY_URL, {}) : {success: false, message: 'Deploy not available'};
    if (resp.success) {
        showToast('App deployed successfully!', 'success');
    } else {
        showToast(resp.message || 'Deploy failed', 'danger');
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-rocket"></i> Deploy';
}

// ─── Persistence ───────────────────────────────────────
let saveTimeout = null;
const previewChannel = new BroadcastChannel(CHANNEL_NAME);

function saveCurrentScreen() {
    clearTimeout(saveTimeout);
    updateAssistState();
    saveTimeout = setTimeout(async () => {
        const screen = screens[currentScreenIndex];
        if (!screen) return;

        const resp = await apiPost(SAVE_SCREEN_URL, {
            id: screen.id,
            blocks: screen.blocks || [],
        });
        if (resp.success) {
            previewChannel.postMessage('refresh');
            // Show hosted URL toast on first save (when hosted mode gets auto-enabled)
            if (resp.data?.hosted_url && !window._hostedToastShown) {
                window._hostedToastShown = true;
                showToast(`Live at <a href="${resp.data.hosted_url}" target="_blank">${resp.data.hosted_url}</a>`, 'success');
            }
        }
    }, 500); // Debounce 500ms
}

// ─── API Helpers ───────────────────────────────────────
async function apiPost(url, data) {
    try {
        const resp = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF,
            },
            body: JSON.stringify({ ...data, csrf_token: CSRF }),
        });
        return await resp.json();
    } catch (e) {
        return { success: false, message: e.message };
    }
}

function esc(s) {
    if (!s) return '';
    const div = document.createElement('div');
    div.textContent = String(s);
    return div.innerHTML;
}

function showToast(message, variant = 'success') {
    const toast = document.createElement('div');
    toast.className = `alert alert-${variant} alert-dismissible fade show position-fixed`;
    toast.style.cssText = 'top: 1rem; right: 1rem; z-index: 9999; min-width: 250px;';
    toast.innerHTML = `${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}
</script>
<?php include __DIR__ . '/../partials/pageassist.php'; ?>
