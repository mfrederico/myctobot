<?php
/**
 * MyCTOBOT Assistant Widget Partial
 *
 * Floating AI assistant widget that helps users interact with the current page.
 * Include this partial on pages where the assistant should be available.
 *
 * Features:
 * - Reads page context via data-assist-* attributes
 * - Auto-detects forms and fields
 * - Streams responses via WebSocket
 * - Executes actions: highlight, fill, suggest, navigate
 * - Stores conversation in localStorage
 * - RAG-based knowledge retrieval for accurate context
 *
 * Required variables:
 *   None (auto-configures based on environment)
 *
 * Optional variables:
 *   $assistWsUrl - WebSocket URL (default: auto-detect)
 *   $assistEnabled - Enable/disable widget (default: true)
 *   $workspace - Workspace slug for knowledge store (default: from session)
 *
 * Configuration file: conf/assistant.ini
 */

// Load assistant configuration
$assistConfigFile = dirname(__DIR__, 2) . '/conf/assistant.ini';
$assistConfig = file_exists($assistConfigFile) ? parse_ini_file($assistConfigFile, true) : [];

// Get config values with defaults
$assistName = $assistConfig['general']['name'] ?? 'MyCTOBOT Assistant';
$assistPosition = $assistConfig['general']['position'] ?? 'bottom-left';
$assistWsPort = $assistConfig['websocket']['port'] ?? 9510;

// Auto-detect WebSocket URL based on current request
// Treat localhost, 127.0.0.1, and dev.* subdomains as local development
$isLocalhost = isset($_SERVER['HTTP_HOST']) && (
    strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ||
    strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false ||
    strpos($_SERVER['HTTP_HOST'], 'dev.') === 0
);

// Get workspace from session or subdomain
$assistWorkspace = $workspace ?? $_SESSION['workspace_slug'] ?? null;
if (!$assistWorkspace) {
    // Extract from subdomain
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $parts = explode('.', $host);
    if (count($parts) >= 3) {
        $assistWorkspace = $parts[0];
    }
}

if ($isLocalhost) {
    $assistWsUrl = $assistWsUrl ?? "ws://localhost:{$assistWsPort}";
} else {
    // Production: use current domain with /assist path (nginx proxies to WS server)
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'wss' : 'ws';
    $assistWsUrl = $assistWsUrl ?? "{$scheme}://{$_SERVER['HTTP_HOST']}/assist";
}

// Append workspace to URL as query param
if ($assistWorkspace) {
    $assistWsUrl .= (strpos($assistWsUrl, '?') === false ? '?' : '&') . 'workspace=' . urlencode($assistWorkspace);
}

$assistEnabled = $assistEnabled ?? true;

if (!$assistEnabled) {
    return;
}
?>

<!-- <?= h($assistName) ?> Toggle Button -->
<button id="assist-toggle" class="btn btn-info rounded-circle shadow-lg assist-unavailable"
        style="position: fixed; bottom: 20px; left: 20px; z-index: 1070; width: 56px; height: 56px;"
        title="<?= h($assistName) ?> (Connecting...)">
    <i class="bi bi-moon-stars fs-4" id="assist-toggle-icon"></i>
</button>

<!-- Unavailable Toast (shown when clicking while disconnected) -->
<div id="assist-unavailable-toast" class="position-fixed"
     style="bottom: 85px; left: 20px; z-index: 1071; display: none;">
    <div class="alert alert-secondary py-2 px-3 mb-0 shadow-sm d-flex align-items-center" style="max-width: 280px;">
        <i class="bi bi-moon-stars me-2"></i>
        <small>Assistant is sleeping. Trying to wake up...</small>
    </div>
</div>

<!-- <?= h($assistName) ?> Panel -->
<div id="assist-panel" class="card shadow-lg"
     style="position: fixed; bottom: 90px; left: 20px; width: 380px; max-height: 550px; z-index: 1069; display: none;">
    <div class="card-header bg-info text-white py-2">
        <div class="d-flex justify-content-between align-items-center">
            <span class="fw-semibold">
                <i class="bi bi-stars me-2"></i><?= h($assistName) ?>
            </span>
            <div>
                <button class="btn btn-sm btn-link text-white p-0 me-2" id="assist-detach" title="Open in new window">
                    <i class="bi bi-box-arrow-up-right"></i>
                </button>
                <button class="btn btn-sm btn-link text-white p-0 me-2" id="assist-clear" title="Clear chat">
                    <i class="bi bi-trash"></i>
                </button>
                <button class="btn btn-sm btn-link text-white p-0" id="assist-close" title="Minimize">
                    <i class="bi bi-dash-lg"></i>
                </button>
            </div>
        </div>
    </div>

    <div class="card-body p-0 d-flex flex-column" style="height: 400px;">
        <!-- Messages Area -->
        <div id="assist-messages" class="flex-grow-1 overflow-auto p-3">
            <div class="text-center text-muted py-4" id="assist-welcome">
                <i class="bi bi-stars fs-1 text-info"></i>
                <p class="mt-2 mb-1">How can I help?</p>
                <small class="text-muted">
                    Ask me about this page, form fields,<br>
                    or what you're trying to accomplish.
                </small>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="px-3 pb-2" id="assist-quick-actions">
        <div class="d-flex flex-wrap gap-1">
            <button class="btn btn-sm btn-outline-secondary assist-quick" data-message="What is this page for?">
                <i class="bi bi-question-circle"></i> What's this?
            </button>
            <button class="btn btn-sm btn-outline-secondary assist-quick" data-message="What fields are required?">
                <i class="bi bi-list-check"></i> Required fields
            </button>
            <button class="btn btn-sm btn-outline-secondary assist-quick" data-message="Suggest values for empty fields">
                <i class="bi bi-lightbulb"></i> Suggest values
            </button>
        </div>
    </div>

    <!-- Input Area -->
    <div class="card-footer bg-light">
        <div class="input-group">
            <input type="text" id="assist-input" class="form-control"
                   placeholder="Ask about this page..." autocomplete="off">
            <button class="btn btn-info" id="assist-send" disabled>
                <i class="bi bi-send"></i>
            </button>
        </div>
        <small class="text-muted mt-1 d-block">
            <span id="assist-status" class="badge bg-secondary">Disconnected</span>
        </small>
    </div>
</div>

<style>
/* Toggle button states */
#assist-toggle {
    transition: all 0.3s ease;
}

#assist-toggle.assist-unavailable {
    background-color: var(--bs-secondary) !important;
    border-color: var(--bs-secondary) !important;
    opacity: 0.7;
}

#assist-toggle.assist-unavailable:hover {
    opacity: 0.85;
}

#assist-toggle.assist-connecting {
    background-color: var(--bs-warning) !important;
    border-color: var(--bs-warning) !important;
}

#assist-toggle.assist-connecting #assist-toggle-icon {
    animation: assist-connecting-pulse 1s infinite;
}

@keyframes assist-connecting-pulse {
    0%, 100% { opacity: 0.5; }
    50% { opacity: 1; }
}

#assist-toggle.assist-connected {
    background-color: var(--bs-info) !important;
    border-color: var(--bs-info) !important;
    opacity: 1;
}

/* Unavailable toast animation */
#assist-unavailable-toast {
    animation: assist-toast-slide 0.3s ease;
}

@keyframes assist-toast-slide {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

#assist-panel {
    display: none;
    flex-direction: column;
    border-radius: 12px;
    overflow: hidden;
}

#assist-messages {
    background: var(--bs-body-bg, #f8f9fa);
}

.assist-message {
    margin-bottom: 12px;
    max-width: 90%;
}

.assist-message-user {
    margin-left: auto;
}

.assist-message-user .assist-message-content {
    background: var(--bs-info);
    color: white;
    border-radius: 12px 12px 0 12px;
}

.assist-message-assistant .assist-message-content {
    background: var(--bs-body-bg, white);
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
    cursor: default;
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
    line-height: 1;
    transition: color 0.15s;
}
.assist-code-copy:hover { color: rgba(255,255,255,0.8); }
.assist-code-copy.copied { color: #198754; }

.assist-code-block pre {
    border: none;
    border-radius: 0;
    margin: 0;
    background: transparent;
}

.assist-thinking {
    color: var(--bs-secondary);
    font-style: italic;
}

.assist-thinking i {
    animation: assist-pulse 1.5s infinite;
}

@keyframes assist-pulse {
    0%, 100% { opacity: 0.4; }
    50% { opacity: 1; }
}

#assist-toggle:hover {
    transform: scale(1.05);
}

#assist-input:focus {
    box-shadow: none;
    border-color: var(--bs-info);
}

/* Highlight animation for page elements - more visible */
.assist-highlight {
    animation: assist-highlight-pulse 0.8s ease-in-out infinite;
    position: relative;
    z-index: 1000;
}

.assist-highlight::before {
    content: '';
    position: absolute;
    inset: -8px;
    background: rgba(13, 202, 240, 0.15);
    border-radius: 8px;
    pointer-events: none;
    z-index: -1;
    animation: assist-highlight-blink 0.6s ease-in-out infinite;
}

.assist-highlight::after {
    content: '';
    position: absolute;
    inset: -8px;
    border: 3px solid var(--bs-info);
    border-radius: 8px;
    pointer-events: none;
    animation: assist-highlight-border 0.8s ease-in-out infinite;
    box-shadow: 0 0 20px rgba(13, 202, 240, 0.5), inset 0 0 20px rgba(13, 202, 240, 0.1);
}

@keyframes assist-highlight-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(13, 202, 240, 0), 0 0 30px rgba(13, 202, 240, 0.3); }
    50% { box-shadow: 0 0 0 15px rgba(13, 202, 240, 0.5), 0 0 50px rgba(13, 202, 240, 0.6); }
}

@keyframes assist-highlight-border {
    0%, 100% { opacity: 0.4; transform: scale(1); border-color: rgba(13, 202, 240, 0.5); }
    50% { opacity: 1; transform: scale(1.03); border-color: rgba(13, 202, 240, 1); }
}

@keyframes assist-highlight-blink {
    0%, 100% { background-color: rgba(13, 202, 240, 0.1); }
    50% { background-color: rgba(13, 202, 240, 0.3); }
}

/* Callout popover for highlighted elements */
.assist-callout {
    position: absolute;
    z-index: 1071;
    max-width: 300px;
    padding: 12px 16px;
    background: linear-gradient(135deg, #1a1d21 0%, #2d3238 100%);
    border: 1px solid var(--bs-info);
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4), 0 0 20px rgba(13, 202, 240, 0.2);
    color: #fff;
    font-size: 0.875rem;
    line-height: 1.5;
    animation: assist-callout-appear 0.3s ease-out;
}

.assist-callout::before {
    content: '';
    position: absolute;
    width: 12px;
    height: 12px;
    background: linear-gradient(135deg, #1a1d21 0%, #2d3238 100%);
    border-left: 1px solid var(--bs-info);
    border-top: 1px solid var(--bs-info);
    transform: rotate(45deg);
}

.assist-callout.callout-top::before {
    bottom: -7px;
    left: 20px;
    transform: rotate(-135deg);
}

.assist-callout.callout-bottom::before {
    top: -7px;
    left: 20px;
    transform: rotate(45deg);
}

.assist-callout-icon {
    display: inline-block;
    margin-right: 8px;
    color: var(--bs-info);
}

.assist-callout-close {
    position: absolute;
    top: 4px;
    right: 6px;
    background: none;
    border: none;
    color: rgba(255,255,255,0.6);
    font-size: 1.1rem;
    cursor: pointer;
    padding: 0 4px;
    line-height: 1;
}

.assist-callout-close:hover {
    color: #fff;
}

@keyframes assist-callout-appear {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Suggestion badge */
.assist-suggestion {
    position: absolute;
    top: -8px;
    right: -8px;
    z-index: 1000;
}

.assist-suggestion-badge {
    background: var(--bs-info);
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    cursor: pointer;
    white-space: nowrap;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.assist-suggestion-badge:hover {
    background: var(--bs-info-bg-subtle);
    color: var(--bs-info);
}

/* Quick action buttons */
.assist-quick {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
}

@media (max-width: 576px) {
    #assist-panel {
        width: calc(100vw - 20px);
        left: 10px;
        bottom: 80px;
        max-height: calc(100vh - 100px);
    }

    #assist-toggle {
        width: 48px;
        height: 48px;
    }
}
</style>

<script>
(function() {
    'use strict';

    // Configuration
    const ASSIST_CONFIG = {
        name: <?= json_encode($assistName) ?>,
        wsUrl: <?= json_encode($assistWsUrl) ?>,
        reconnectDelay: 3000,
        maxReconnectAttempts: 5,
        conversationTimeout: 24 * 60 * 60 * 1000, // 24 hours
        storageKey: 'assist_conversation',
        settingsKey: 'assist_settings'
    };

    // State
    let ws = null;
    let connected = false;
    let reconnectAttempts = 0;
    let currentResponse = '';
    let conversationId = null;

    // Elements
    const elements = {
        toggle: document.getElementById('assist-toggle'),
        toggleIcon: document.getElementById('assist-toggle-icon'),
        unavailableToast: document.getElementById('assist-unavailable-toast'),
        panel: document.getElementById('assist-panel'),
        messages: document.getElementById('assist-messages'),
        welcome: document.getElementById('assist-welcome'),
        input: document.getElementById('assist-input'),
        send: document.getElementById('assist-send'),
        close: document.getElementById('assist-close'),
        clear: document.getElementById('assist-clear'),
        detach: document.getElementById('assist-detach'),
        status: document.getElementById('assist-status'),
        quickActions: document.getElementById('assist-quick-actions')
    };

    // Connection state management
    let connectionState = 'unavailable'; // 'unavailable', 'connecting', 'connected'
    let unavailableToastTimeout = null;

    function setConnectionState(state) {
        connectionState = state;
        elements.toggle.classList.remove('assist-unavailable', 'assist-connecting', 'assist-connected');
        elements.toggle.classList.add(`assist-${state}`);

        switch (state) {
            case 'unavailable':
                elements.toggleIcon.className = 'bi bi-moon-stars fs-4';
                elements.toggle.title = ASSIST_CONFIG.name + ' (Unavailable)';
                break;
            case 'connecting':
                elements.toggleIcon.className = 'bi bi-stars fs-4';
                elements.toggle.title = ASSIST_CONFIG.name + ' (Connecting...)';
                break;
            case 'connected':
                elements.toggleIcon.className = 'bi bi-stars fs-4';
                elements.toggle.title = ASSIST_CONFIG.name;
                break;
        }
    }

    function showUnavailableToast() {
        if (unavailableToastTimeout) {
            clearTimeout(unavailableToastTimeout);
        }

        elements.unavailableToast.style.display = 'block';

        unavailableToastTimeout = setTimeout(() => {
            elements.unavailableToast.style.display = 'none';
        }, 3000);
    }

    function hideUnavailableToast() {
        if (unavailableToastTimeout) {
            clearTimeout(unavailableToastTimeout);
        }
        elements.unavailableToast.style.display = 'none';
    }

    // ========================================
    // DOM Context Scanner
    // ========================================

    function scanPageContext() {
        const context = {
            page: document.querySelector('[data-assist-page]')?.dataset.assistPage || getPageFromUrl(),
            url: window.location.pathname,
            purpose: document.querySelector('[data-assist-purpose]')?.dataset.assistPurpose || '',
            studio: document.querySelector('[data-assist-context]')?.dataset.assistContext || '',
            forms: [],
            cards: [],
            tables: []
        };

        // Try to parse JSON context
        if (context.studio) {
            try {
                const parsed = JSON.parse(context.studio);
                context.studio = parsed.studio || 'general';
            } catch (e) {
                // Keep as string
            }
        }

        // Scan cards (Bootstrap .card elements) - useful for list pages
        document.querySelectorAll('.card').forEach((card, index) => {
            // Skip the assist widget itself and very small cards
            if (card.closest('#assist-widget') || card.offsetHeight < 50) return;

            const cardData = {
                index: index,
                title: getCardTitle(card),
                content: getCardContent(card),
                actions: getCardActions(card),
                selector: getCardSelector(card, index),
                section: card.dataset.assistSection || null,
                hint: card.dataset.assistHint || null
            };

            // Only include cards with meaningful content
            if (cardData.title || cardData.content) {
                context.cards.push(cardData);
            }
        });

        // Scan tables - useful for data list pages
        document.querySelectorAll('table').forEach((table, tableIndex) => {
            // Skip tiny tables or those inside the assist widget
            if (table.closest('#assist-widget') || table.rows.length < 2) return;

            const tableData = {
                index: tableIndex,
                headers: getTableHeaders(table),
                rows: getTableRows(table),
                selector: table.id ? `#${table.id}` : `table:nth-of-type(${tableIndex + 1})`
            };

            if (tableData.rows.length > 0) {
                context.tables.push(tableData);
            }
        });

        // Scan all forms
        document.querySelectorAll('form').forEach(form => {
            const formData = {
                id: form.id || form.dataset.assistForm || 'form-' + Math.random().toString(36).substr(2, 6),
                purpose: form.dataset.assistPurpose || '',
                fields: []
            };

            // Scan form fields
            form.querySelectorAll('input, select, textarea').forEach(field => {
                // Skip hidden and submit fields
                if (field.type === 'hidden' || field.type === 'submit' || field.type === 'button') {
                    return;
                }

                const fieldData = {
                    name: field.name || field.id,
                    type: getFieldType(field),
                    value: getFieldValue(field),
                    label: getFieldLabel(field),
                    hint: field.dataset.assistHint || '',
                    required: field.required || field.dataset.assistRequired === 'true'
                };

                // Get options for select fields
                if (field.tagName === 'SELECT') {
                    fieldData.options = Array.from(field.options).map(o => o.text);
                } else if (field.dataset.assistOptions) {
                    fieldData.options = field.dataset.assistOptions.split('|');
                }

                formData.fields.push(fieldData);
            });

            if (formData.fields.length > 0) {
                context.forms.push(formData);
            }
        });

        // Detect app builder state
        const builderState = document.getElementById('assist-builder-state');
        if (builderState) {
            try {
                context.builder = {
                    screens: JSON.parse(builderState.dataset.assistBuilderScreens || '[]'),
                    blockTypes: JSON.parse(builderState.dataset.assistBuilderBlockTypes || '{}'),
                    pipelines: JSON.parse(builderState.dataset.assistBuilderPipelines || '[]'),
                    currentScreen: parseInt(builderState.dataset.assistBuilderCurrentScreen || '0'),
                    selectedBlock: JSON.parse(builderState.dataset.assistBuilderSelectedBlock || '{}'),
                };
                // Dapp context (detachable app metadata for assistant)
                const dappCtxRaw = builderState.dataset.assistBuilderDappContext;
                if (dappCtxRaw) {
                    try {
                        context.builder.dappContext = JSON.parse(dappCtxRaw);
                    } catch (e) {}
                }
            } catch (e) {
                console.warn('[Assist] Failed to parse builder state:', e);
            }
        }

        return context;
    }

    function getPageFromUrl() {
        const path = window.location.pathname;
        return path.replace(/^\//, '').replace(/\//g, '/') || 'home';
    }

    function getPageKey() {
        return document.querySelector('[data-assist-page]')?.dataset.assistPage
            || window.location.pathname;
    }

    function getPageLabel() {
        const key = getPageKey();
        return key.replace(/[/_-]/g, ' ').replace(/\b\w/g, c => c.toUpperCase()).trim();
    }

    function getFieldType(field) {
        if (field.tagName === 'SELECT') return 'select';
        if (field.tagName === 'TEXTAREA') return 'textarea';
        return field.type || 'text';
    }

    function getFieldValue(field) {
        if (field.type === 'checkbox') {
            return field.checked ? 'checked' : 'unchecked';
        }
        if (field.type === 'radio') {
            const checked = document.querySelector(`input[name="${field.name}"]:checked`);
            return checked ? checked.value : '';
        }
        return field.value || '';
    }

    function getFieldLabel(field) {
        // Check for data-assist-label
        if (field.dataset.assistLabel) {
            return field.dataset.assistLabel;
        }

        // Check for associated label
        const label = document.querySelector(`label[for="${field.id}"]`);
        if (label) {
            return label.textContent.trim();
        }

        // Check for parent label
        const parentLabel = field.closest('label');
        if (parentLabel) {
            return parentLabel.textContent.replace(field.value, '').trim();
        }

        // Check for placeholder
        if (field.placeholder) {
            return field.placeholder;
        }

        // Fall back to name
        return field.name || field.id || 'unnamed';
    }

    // ========================================
    // Card & Table Scanner Helpers
    // ========================================

    function getCardTitle(card) {
        // Try common title locations
        const titleEl = card.querySelector('.card-header, .card-title, h5, h4, h3, [data-assist-title]');
        if (titleEl) {
            // Get text but exclude badges and buttons
            const clone = titleEl.cloneNode(true);
            clone.querySelectorAll('.badge, button, .btn').forEach(el => el.remove());
            return clone.textContent.trim().substring(0, 100);
        }
        return '';
    }

    function getCardContent(card) {
        // Get card body text, truncated
        const body = card.querySelector('.card-body');
        if (body) {
            // Clone to avoid modifying DOM
            const clone = body.cloneNode(true);
            // Remove scripts and styles
            clone.querySelectorAll('script, style, .btn, button').forEach(el => el.remove());
            const text = clone.textContent.trim().replace(/\s+/g, ' ');
            return text.substring(0, 200);
        }
        return '';
    }

    function getCardActions(card) {
        const actions = [];
        // Find action buttons/links in the card
        card.querySelectorAll('a[href], button[onclick], .btn').forEach(btn => {
            const text = btn.textContent.trim();
            const href = btn.getAttribute('href');
            const onclick = btn.getAttribute('onclick');

            if (text && (href || onclick)) {
                actions.push({
                    text: text.substring(0, 50),
                    href: href || null,
                    selector: btn.id ? `#${btn.id}` : null
                });
            }
        });
        return actions.slice(0, 5); // Limit to 5 actions per card
    }

    function getCardSelector(card, index) {
        // Try to build a unique selector
        if (card.id) return `#${card.id}`;
        if (card.dataset.assistSection) return `[data-assist-section="${card.dataset.assistSection}"]`;
        if (card.dataset.id) return `[data-id="${card.dataset.id}"]`;
        if (card.dataset.pipelineId) return `[data-pipeline-id="${card.dataset.pipelineId}"]`;
        // Fall back to nth-child
        return `.card:nth-of-type(${index + 1})`;
    }

    function getTableHeaders(table) {
        const headers = [];
        const headerRow = table.querySelector('thead tr, tr:first-child');
        if (headerRow) {
            headerRow.querySelectorAll('th, td').forEach(cell => {
                headers.push(cell.textContent.trim().substring(0, 50));
            });
        }
        return headers;
    }

    function getTableRows(table, maxRows = 20) {
        const rows = [];
        const dataRows = table.querySelectorAll('tbody tr, tr:not(:first-child)');

        dataRows.forEach((row, rowIndex) => {
            if (rowIndex >= maxRows) return; // Limit rows to avoid huge context

            const rowData = {
                index: rowIndex,
                cells: [],
                actions: [],
                selector: row.id ? `#${row.id}` : (row.dataset.id ? `[data-id="${row.dataset.id}"]` : null)
            };

            // Get cell content
            row.querySelectorAll('td').forEach(cell => {
                const text = cell.textContent.trim().replace(/\s+/g, ' ').substring(0, 100);
                if (text) rowData.cells.push(text);
            });

            // Get row actions (buttons/links)
            row.querySelectorAll('a[href], button, .btn').forEach(btn => {
                const text = btn.textContent.trim();
                const href = btn.getAttribute('href');
                if (text) {
                    rowData.actions.push({
                        text: text.substring(0, 30),
                        href: href || null
                    });
                }
            });

            if (rowData.cells.length > 0) {
                rows.push(rowData);
            }
        });

        return rows;
    }

    // ========================================
    // Action Executor
    // ========================================

    function executeAction(action) {
        console.log('[Assist] Executing action:', action);

        switch (action.action) {
            case 'highlight':
                highlightElement(action.target, action.duration || 3000, action.color, action.message);
                break;

            case 'scroll':
                scrollToElement(action.target, action.behavior || 'smooth');
                break;

            case 'focus':
                focusElement(action.target);
                break;

            case 'fill':
                fillField(action.target, action.value, action.animate);
                break;

            case 'suggest':
                showSuggestion(action.target, action.value, action.reason);
                break;

            case 'select':
                selectOption(action.target, action.value);
                break;

            case 'navigate':
                navigateToUrl(action.url, action.confirm);
                break;

            case 'compose_email':
                // Stash form data in sessionStorage, then navigate to compose page
                if (action.subject || action.body) {
                    sessionStorage.setItem('assist_compose', JSON.stringify({
                        subject: action.subject || '',
                        body: action.body || ''
                    }));
                }
                if (action.navigate_url) {
                    navigateToUrl(action.navigate_url);
                }
                break;

            case 'expand':
                expandElement(action.target);
                break;

            case 'click':
                clickElement(action.target, action.confirm);
                break;

            case 'toast':
                showToastNotification(action.type || 'info', action.message);
                break;

            case 'builder_add_block':
                if (window.BuilderAssist) {
                    let config = action.config || {};
                    if (typeof config === 'string') {
                        try { config = JSON.parse(config); } catch(e) { config = {}; }
                    }
                    const newId = window.BuilderAssist.addBlock(action.block_type, config, action.position != null ? parseInt(action.position) : undefined);
                    if (newId) console.log('[Assist] Added block:', newId);
                }
                break;

            case 'builder_update_block':
                if (window.BuilderAssist) {
                    let uConfig = action.config || {};
                    if (typeof uConfig === 'string') {
                        try { uConfig = JSON.parse(uConfig); } catch(e) { uConfig = {}; }
                    }
                    window.BuilderAssist.updateBlock(action.block_id, uConfig);
                }
                break;

            case 'builder_remove_block':
                if (window.BuilderAssist) {
                    window.BuilderAssist.removeBlock(action.block_id);
                }
                break;

            case 'builder_select_block':
                if (window.BuilderAssist) {
                    window.BuilderAssist.selectBlock(action.block_id);
                }
                break;

            case 'builder_bind_pipeline':
                if (window.BuilderAssist) {
                    window.BuilderAssist.bindPipeline(action.block_id, action.pipeline_slug);
                    console.log('[Assist] Bound pipeline:', action.pipeline_slug, 'to block:', action.block_id);
                }
                break;

            case 'builder_highlight_field':
                if (action.field) {
                    // Map field names to selectors in the config panel
                    const fieldMap = {
                        'pipeline': '#binding-pipeline-select',
                        'data_source': '#config-data-source',
                        'title': '.config-section input[type="text"]:first-of-type',
                        'cards': '.config-section textarea',
                        'columns': '.config-section input[type="number"]',
                        'content': '.config-section textarea',
                        'events': '.config-section:last-child select',
                    };
                    const selector = fieldMap[action.field] || fieldMap['pipeline'];
                    const el = document.querySelector(selector);
                    if (el) {
                        const section = el.closest('.config-section') || el;
                        section.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        el.style.transition = 'box-shadow 0.3s, border-color 0.3s';
                        el.style.boxShadow = '0 0 0 3px rgba(13,110,253,0.5)';
                        el.style.borderColor = '#0d6efd';
                        setTimeout(() => { el.style.boxShadow = ''; el.style.borderColor = ''; }, 2500);
                    }
                }
                break;

            case 'builder_add_block_to_container':
                if (window.BuilderAssist) {
                    let cConfig = action.config || {};
                    if (typeof cConfig === 'string') {
                        try { cConfig = JSON.parse(cConfig); } catch(e) { cConfig = {}; }
                    }
                    const slot = action.container_slot != null ? parseInt(action.container_slot) : 0;
                    const cId = window.BuilderAssist.addBlockToContainer(action.block_type, cConfig, action.container_id, slot);
                    if (cId) console.log('[Assist] Added block to container:', cId);
                }
                break;

            case 'builder_move_block':
                if (window.BuilderAssist) {
                    window.BuilderAssist.moveBlock(action.block_id, parseInt(action.target_position) || 0);
                }
                break;

            case 'builder_switch_screen':
                if (window.BuilderAssist) {
                    window.BuilderAssist.switchScreen(action.screen_name || action.screen_index || '0');
                }
                break;

            case 'builder_add_tab':
                if (window.BuilderAssist) {
                    window.BuilderAssist.addTabToBlock(action.block_id, action.label, action.icon);
                }
                break;

            case 'builder_add_column':
                if (window.BuilderAssist) {
                    window.BuilderAssist.addColumnToBlock(action.block_id, action.col_class);
                }
                break;

            case 'builder_scaffold':
                if (window.BuilderAssist) {
                    let sConfig = action.config || {};
                    if (typeof sConfig === 'string') {
                        try { sConfig = JSON.parse(sConfig); } catch(e) { sConfig = {}; }
                    }
                    window.BuilderAssist.scaffold(action.template_name, sConfig);
                }
                break;

            default:
                console.warn('[Assist] Unknown action:', action.action);
        }
    }

    function highlightElement(selector, duration, color, message) {
        const el = document.querySelector(selector);
        if (!el) {
            console.warn('[Assist] Element not found:', selector);
            return;
        }

        // Scroll into view first
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });

        // Add highlight class
        el.classList.add('assist-highlight');

        // Custom color if provided
        if (color) {
            el.style.setProperty('--assist-highlight-color', color);
        }

        // Show callout if message provided - wait for scroll to complete
        let callout = null;
        if (message) {
            // Wait for smooth scroll animation to complete before positioning callout
            setTimeout(() => {
                callout = showCallout(el, message);
                setupCalloutBehavior(el, callout, duration);
            }, 400);
        } else {
            setupCalloutBehavior(el, null, duration);
        }
    }

    function setupCalloutBehavior(el, callout, duration) {

        // Remove highlight blink on mouseover (user acknowledged it), but keep callout
        const removeHighlight = () => {
            el.classList.remove('assist-highlight');
            el.removeEventListener('mouseenter', removeHighlight);
        };
        el.addEventListener('mouseenter', removeHighlight);

        // Callout stays until user clicks the X or clicks elsewhere
        if (callout) {
            // Add close button to callout
            const closeBtn = document.createElement('button');
            closeBtn.className = 'assist-callout-close';
            closeBtn.innerHTML = '&times;';
            closeBtn.onclick = (e) => {
                e.stopPropagation();
                callout.style.animation = 'assist-callout-appear 0.2s ease-out reverse';
                setTimeout(() => callout.remove(), 200);
            };
            callout.appendChild(closeBtn);

            // Also close on click outside
            const closeOnOutsideClick = (e) => {
                if (!callout.contains(e.target) && e.target !== el) {
                    callout.style.animation = 'assist-callout-appear 0.2s ease-out reverse';
                    setTimeout(() => callout.remove(), 200);
                    document.removeEventListener('click', closeOnOutsideClick);
                }
            };
            setTimeout(() => document.addEventListener('click', closeOnOutsideClick), 100);
        }

        // Fallback: remove highlight blink after 30 seconds if not hovered
        setTimeout(() => {
            if (el.classList.contains('assist-highlight')) {
                el.classList.remove('assist-highlight');
            }
        }, 30000);
    }

    function showCallout(targetEl, message) {
        // Remove any existing callouts
        document.querySelectorAll('.assist-callout').forEach(c => c.remove());

        // Create callout element
        const callout = document.createElement('div');
        callout.className = 'assist-callout';
        callout.innerHTML = `<span class="assist-callout-icon"><i class="bi bi-stars"></i></span>${escapeHtml(message)}`;
        document.body.appendChild(callout);

        // Position the callout
        const targetRect = targetEl.getBoundingClientRect();
        const calloutRect = callout.getBoundingClientRect();
        const viewportHeight = window.innerHeight;

        // Check if target element is visible (has dimensions)
        if (targetRect.width === 0 || targetRect.height === 0) {
            console.warn('[Assist] Target element is hidden/collapsed, positioning callout at top of main content');
            // Position near top of main content area instead
            const mainContent = document.querySelector('main') || document.querySelector('.container') || document.body;
            const mainRect = mainContent.getBoundingClientRect();
            callout.classList.add('callout-bottom');
            callout.style.top = (mainRect.top + window.scrollY + 60) + 'px';
            callout.style.left = (mainRect.left + 20) + 'px';
            return callout;
        }

        // Determine if callout should go above or below
        const spaceBelow = viewportHeight - targetRect.bottom;
        const spaceAbove = targetRect.top;

        if (spaceBelow > calloutRect.height + 20 || spaceBelow > spaceAbove) {
            // Position below
            callout.classList.add('callout-bottom');
            callout.style.top = (targetRect.bottom + window.scrollY + 15) + 'px';
        } else {
            // Position above
            callout.classList.add('callout-top');
            callout.style.top = (targetRect.top + window.scrollY - calloutRect.height - 15) + 'px';
        }

        // Horizontal positioning - align to left of target, but keep on screen
        let left = targetRect.left + window.scrollX;
        const maxLeft = window.innerWidth - calloutRect.width - 20;
        if (left > maxLeft) left = maxLeft;
        if (left < 20) left = 20;
        callout.style.left = left + 'px';

        return callout;
    }

    function scrollToElement(selector, behavior) {
        const el = document.querySelector(selector);
        if (el) {
            el.scrollIntoView({ behavior: behavior, block: 'center' });
        }
    }

    function focusElement(selector) {
        const el = document.querySelector(selector);
        if (el) {
            el.focus();
            highlightElement(selector, 2000);
        }
    }

    function fillField(selector, value, animate) {
        const el = document.querySelector(selector);
        if (!el) return;

        if (animate) {
            // Animate typing
            el.focus();
            el.value = '';
            let i = 0;
            const interval = setInterval(() => {
                if (i < value.length) {
                    el.value += value[i];
                    i++;
                } else {
                    clearInterval(interval);
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }, 30);
        } else {
            el.value = value;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }

        highlightElement(selector, 2000);
    }

    function showSuggestion(selector, value, reason) {
        const el = document.querySelector(selector);
        if (!el) return;

        // Remove any existing suggestion
        const existing = el.parentElement.querySelector('.assist-suggestion');
        if (existing) existing.remove();

        // Create suggestion badge
        const wrapper = el.parentElement;
        wrapper.style.position = 'relative';

        const badge = document.createElement('div');
        badge.className = 'assist-suggestion';
        badge.innerHTML = `
            <span class="assist-suggestion-badge" title="${reason || 'Click to apply'}">
                <i class="bi bi-lightbulb me-1"></i>${escapeHtml(value.substring(0, 20))}${value.length > 20 ? '...' : ''}
            </span>
        `;

        badge.querySelector('.assist-suggestion-badge').addEventListener('click', () => {
            fillField(selector, value, true);
            badge.remove();
        });

        wrapper.appendChild(badge);

        // Auto-remove after 30 seconds
        setTimeout(() => badge.remove(), 30000);
    }

    function selectOption(selector, value) {
        const el = document.querySelector(selector);
        if (!el || el.tagName !== 'SELECT') return;

        // Find option by value or text
        for (const option of el.options) {
            if (option.value === value || option.text === value) {
                el.value = option.value;
                el.dispatchEvent(new Event('change', { bubbles: true }));
                highlightElement(selector, 2000);
                return;
            }
        }
    }

    function navigateToUrl(url, confirm) {
        function doNavigate() {
            // Save the current assistant response before navigating
            if (currentResponse) {
                saveMessage('assistant', currentResponse);
                currentResponse = '';
            }
            // Mark conversation as assistant-navigated so we auto-continue on the new page
            try {
                const conv = JSON.parse(localStorage.getItem(ASSIST_CONFIG.storageKey) || 'null');
                if (conv) {
                    conv.navigated_by_assist = true;
                    localStorage.setItem(ASSIST_CONFIG.storageKey, JSON.stringify(conv));
                }
            } catch (e) {}
            window.location.href = url;
        }

        if (confirm) {
            if (window.confirm(`Navigate to ${url}?`)) {
                doNavigate();
            }
        } else {
            doNavigate();
        }
    }

    function expandElement(selector) {
        const el = document.querySelector(selector);
        if (!el) return;

        // Handle Bootstrap collapse
        if (el.classList.contains('collapse')) {
            const bsCollapse = new bootstrap.Collapse(el, { show: true });
        }

        // Handle details element
        if (el.tagName === 'DETAILS') {
            el.open = true;
        }

        // Handle accordion
        const accordionButton = el.querySelector('.accordion-button');
        if (accordionButton && accordionButton.classList.contains('collapsed')) {
            accordionButton.click();
        }
    }

    function clickElement(selector, confirm) {
        const el = document.querySelector(selector);
        if (!el) return;

        if (confirm) {
            if (window.confirm('Proceed with this action?')) {
                el.click();
            }
        } else {
            el.click();
        }
    }

    function showToastNotification(type, message) {
        // Use existing showToast if available
        if (typeof showToast === 'function') {
            showToast(type, message);
        } else {
            // Fallback to alert
            alert(`[${type.toUpperCase()}] ${message}`);
        }
    }

    // ========================================
    // WebSocket Connection
    // ========================================

    function connect() {
        if (ws && ws.readyState === WebSocket.OPEN) {
            // Already connected - just update the panel status
            setStatus('Connected', 'success');
            elements.send.disabled = !elements.input.value.trim();
            return;
        }

        setStatus('Connecting...', 'secondary');
        setConnectionState('connecting');

        ws = new WebSocket(ASSIST_CONFIG.wsUrl);

        ws.onopen = () => {
            connected = true;
            reconnectAttempts = 0;
            setStatus('Connected', 'success');
            setConnectionState('connected');
            hideUnavailableToast();
            elements.send.disabled = !elements.input.value.trim();
        };

        ws.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                handleMessage(data);
            } catch (e) {
                console.error('[Assist] Failed to parse message:', e);
            }
        };

        ws.onclose = () => {
            connected = false;
            setStatus('Disconnected', 'secondary');
            setConnectionState('unavailable');
            elements.send.disabled = true;

            // Reconnect if panel is open
            if (elements.panel.style.display !== 'none' && reconnectAttempts < ASSIST_CONFIG.maxReconnectAttempts) {
                reconnectAttempts++;
                setConnectionState('connecting');
                setTimeout(connect, ASSIST_CONFIG.reconnectDelay);
            }
        };

        ws.onerror = (error) => {
            console.error('[Assist] WebSocket error:', error);
            setStatus('Connection error', 'danger');
            setConnectionState('unavailable');
        };
    }

    function setStatus(text, variant) {
        elements.status.textContent = text;
        elements.status.className = `badge bg-${variant}`;
    }

    // ========================================
    // Message Handling
    // ========================================

    function sendMessage(message) {
        if (!message || !connected) return;

        // If page-change prompt is showing, treat typing as "continue"
        if (pageChangeConversation) {
            const banner = document.getElementById('assist-page-change-prompt');
            if (banner) banner.remove();
            // Update stored page and render old messages before new one
            pageChangeConversation.page = getPageKey();
            localStorage.setItem(ASSIST_CONFIG.storageKey, JSON.stringify(pageChangeConversation));
            renderStoredMessages(pageChangeConversation.messages);
            pageChangeConversation = null;
        }

        // Get current page context
        const context = scanPageContext();

        // Load or create conversation ID
        conversationId = conversationId || loadConversation()?.id || generateConversationId();

        addMessage('user', message);
        elements.input.value = '';
        elements.send.disabled = true;

        ws.send(JSON.stringify({
            type: 'chat',
            message: message,
            context: context,
            conversation_id: conversationId
        }));

        // Save to localStorage
        saveMessage('user', message);
    }

    function handleMessage(data) {
        switch (data.type) {
            case 'connected':
                // Welcome handled by onopen
                break;

            case 'thinking':
                showThinking(data.content);
                break;

            case 'chunk':
                appendChunk(data.content);
                break;

            case 'action':
                executeAction(data);
                break;

            case 'done':
                finishResponse(data.conversation_id);
                break;

            case 'error':
                showError(data.message);
                break;

            case 'pong':
                // Heartbeat response
                break;
        }
    }

    function addMessage(role, content) {
        // Hide welcome message
        if (elements.welcome) {
            elements.welcome.style.display = 'none';
        }

        const div = document.createElement('div');
        div.className = `assist-message assist-message-${role}`;
        div.innerHTML = `<div class="assist-message-content">${formatContent(content)}</div>`;
        elements.messages.appendChild(div);
        scrollToBottom();
    }

    function showThinking(message) {
        // Hide welcome message
        if (elements.welcome) {
            elements.welcome.style.display = 'none';
        }

        // Remove any existing in-progress response (prevents duplicates)
        const existing = document.getElementById('assist-current-response');
        if (existing) {
            existing.remove();
        }

        currentResponse = '';
        const div = document.createElement('div');
        div.className = 'assist-message assist-message-assistant';
        div.id = 'assist-current-response';
        div.innerHTML = `
            <div class="assist-message-content">
                <span class="assist-thinking">
                    <i class="bi bi-hourglass-split me-1"></i>${escapeHtml(message)}
                </span>
                <span class="assist-response-text"></span>
            </div>
        `;
        elements.messages.appendChild(div);
        scrollToBottom();
    }

    function appendChunk(content) {
        const responseDiv = document.getElementById('assist-current-response');
        if (!responseDiv) return;

        // Hide thinking indicator on first chunk
        const thinking = responseDiv.querySelector('.assist-thinking');
        if (thinking) thinking.style.display = 'none';

        currentResponse += content;
        const textEl = responseDiv.querySelector('.assist-response-text');
        textEl.innerHTML = formatContent(currentResponse);
        scrollToBottom();
    }

    function finishResponse(convId) {
        const responseDiv = document.getElementById('assist-current-response');
        if (responseDiv) {
            // Hide thinking indicator in case no chunks were received
            const thinking = responseDiv.querySelector('.assist-thinking');
            if (thinking) thinking.style.display = 'none';

            // If no text response but we had actions, show a minimal message
            const textEl = responseDiv.querySelector('.assist-response-text');
            if (textEl && !currentResponse.trim()) {
                textEl.innerHTML = '<em class="text-muted">Done.</em>';
            }

            responseDiv.removeAttribute('id');

            // Save assistant response
            if (currentResponse) {
                saveMessage('assistant', currentResponse);
            }
        }

        currentResponse = '';
        conversationId = convId || conversationId;
        elements.send.disabled = !elements.input.value.trim();

        // Hide quick actions after first message
        if (elements.quickActions) {
            elements.quickActions.style.display = 'none';
        }
    }

    function showError(message) {
        const div = document.createElement('div');
        div.className = 'assist-message assist-message-assistant';
        div.innerHTML = `
            <div class="assist-message-content bg-danger-subtle text-danger">
                <i class="bi bi-exclamation-triangle me-1"></i>${escapeHtml(message)}
            </div>
        `;
        elements.messages.appendChild(div);
        scrollToBottom();
        elements.send.disabled = !elements.input.value.trim();
    }

    // ========================================
    // Conversation Persistence
    // ========================================

    function saveMessage(role, content) {
        const conversation = loadConversation() || {
            id: conversationId || generateConversationId(),
            page: getPageKey(),
            messages: [],
            created_at: Date.now(),
            updated_at: Date.now()
        };

        conversation.messages.push({
            role: role,
            content: content,
            timestamp: Date.now()
        });
        conversation.updated_at = Date.now();

        localStorage.setItem(ASSIST_CONFIG.storageKey, JSON.stringify(conversation));
    }

    function loadConversation() {
        try {
            const stored = localStorage.getItem(ASSIST_CONFIG.storageKey);
            if (!stored) return null;

            const conversation = JSON.parse(stored);

            // Check if conversation is too old
            if (Date.now() - conversation.updated_at > ASSIST_CONFIG.conversationTimeout) {
                localStorage.removeItem(ASSIST_CONFIG.storageKey);
                return null;
            }

            return conversation;
        } catch (e) {
            return null;
        }
    }

    function renderStoredMessages(messages) {
        if (elements.welcome) elements.welcome.style.display = 'none';
        messages.forEach(msg => addMessage(msg.role, msg.content));
        if (elements.quickActions && messages.length > 0) {
            elements.quickActions.style.display = 'none';
        }
    }

    function showPageChangePrompt(conversation) {
        // Remove any existing prompt
        const existing = document.getElementById('assist-page-change-prompt');
        if (existing) existing.remove();

        const prevLabel = (conversation.page || '').replace(/[/_-]/g, ' ').replace(/\b\w/g, c => c.toUpperCase()).trim() || 'another page';

        const banner = document.createElement('div');
        banner.id = 'assist-page-change-prompt';
        banner.className = 'p-3 text-center';
        banner.innerHTML = `
            <div class="mb-2 text-muted small">
                <i class="bi bi-chat-dots me-1"></i>You were chatting on <strong>${escapeHtml(prevLabel)}</strong>
            </div>
            <div class="d-flex justify-content-center gap-2">
                <button class="btn btn-sm btn-outline-info" id="assist-continue-chat">
                    <i class="bi bi-arrow-right-circle me-1"></i>Continue chat
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="assist-new-chat">
                    <i class="bi bi-plus-circle me-1"></i>New conversation
                </button>
            </div>
        `;

        if (elements.welcome) elements.welcome.style.display = 'none';
        elements.messages.appendChild(banner);
        scrollToBottom();

        // Hide quick actions while prompt is shown
        if (elements.quickActions) elements.quickActions.style.display = 'none';

        document.getElementById('assist-continue-chat').addEventListener('click', () => {
            banner.remove();
            // Update stored page to current and render messages
            conversation.page = getPageKey();
            localStorage.setItem(ASSIST_CONFIG.storageKey, JSON.stringify(conversation));
            renderStoredMessages(conversation.messages);
        });

        document.getElementById('assist-new-chat').addEventListener('click', () => {
            banner.remove();
            clearConversation();
        });
    }

    // Track whether page-change prompt is active (so typing dismisses it)
    let pageChangeConversation = null;

    function restoreConversation() {
        const conversation = loadConversation();
        if (!conversation || !conversation.messages.length) return;

        const currentPage = getPageKey();

        if (conversation.page === currentPage) {
            // Same page - restore normally
            conversationId = conversation.id;
            renderStoredMessages(conversation.messages);
            return;
        }

        // If the assistant triggered the navigation, auto-continue without prompting
        if (conversation.navigated_by_assist) {
            delete conversation.navigated_by_assist;
            conversation.page = currentPage;
            localStorage.setItem(ASSIST_CONFIG.storageKey, JSON.stringify(conversation));
            conversationId = conversation.id;
            renderStoredMessages(conversation.messages);
            // Auto-open panel so user sees the conversation continued
            showPanel();
            return;
        }

        // Different page - show chips asking user what to do
        conversationId = conversation.id;
        pageChangeConversation = conversation;
        showPageChangePrompt(conversation);
    }

    function clearConversation() {
        localStorage.removeItem(ASSIST_CONFIG.storageKey);
        conversationId = null;
        pageChangeConversation = null;

        elements.messages.innerHTML = '';
        if (elements.welcome) {
            elements.welcome.style.display = 'block';
            elements.messages.appendChild(elements.welcome);
        }

        // Show quick actions again
        if (elements.quickActions) {
            elements.quickActions.style.display = 'block';
        }

        // Notify server
        if (connected) {
            ws.send(JSON.stringify({ type: 'clear', conversation_id: conversationId }));
        }
    }

    function generateConversationId() {
        return 'local_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6);
    }

    // ========================================
    // UI Helpers
    // ========================================

    function togglePanel() {
        if (elements.panel.style.display === 'none') {
            showPanel();
        } else {
            hidePanel();
        }
    }

    function showPanel() {
        // If not connected and not currently connecting, show toast and try to connect
        if (connectionState === 'unavailable') {
            showUnavailableToast();
        }

        elements.panel.style.display = 'flex';
        elements.toggleIcon.className = 'bi bi-x-lg fs-4';
        elements.toggle.classList.remove('assist-unavailable', 'assist-connecting', 'assist-connected');

        // Save open state
        localStorage.setItem('assist_panel_open', 'true');

        connect();
        restoreConversation();
        elements.input.focus();
    }

    function hidePanel() {
        elements.panel.style.display = 'none';
        hideUnavailableToast();

        // Save closed state
        localStorage.setItem('assist_panel_open', 'false');

        // Restore appropriate icon based on connection state
        if (connectionState === 'connected') {
            elements.toggleIcon.className = 'bi bi-stars fs-4';
            elements.toggle.classList.add('assist-connected');
        } else if (connectionState === 'connecting') {
            elements.toggleIcon.className = 'bi bi-stars fs-4';
            elements.toggle.classList.add('assist-connecting');
        } else {
            elements.toggleIcon.className = 'bi bi-moon-stars fs-4';
            elements.toggle.classList.add('assist-unavailable');
        }
    }

    // ========================================
    // Detach to Popup Window
    // ========================================

    let detachedWindow = null;

    function detachToWindow() {
        // Save current conversation state before detaching
        if (currentResponse) {
            saveMessage('assistant', currentResponse);
        }

        // Mark conversation for the popup to pick up
        try {
            const conv = JSON.parse(localStorage.getItem(ASSIST_CONFIG.storageKey) || 'null');
            if (conv) {
                conv.detached = true;
                localStorage.setItem(ASSIST_CONFIG.storageKey, JSON.stringify(conv));
            }
        } catch (e) {}

        // Open popup window with the assist chat route
        const width = 420, height = 650;
        const left = window.screenX + window.outerWidth - width - 30;
        const top = window.screenY + 80;
        detachedWindow = window.open(
            '/assist/chat',
            'myctobot_assist',
            `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`
        );

        // Hide the inline panel
        hidePanel();
        elements.toggle.style.display = 'none';

        // Listen for messages from the popup (actions to execute on this page)
        window.addEventListener('message', handleDetachedMessage);

        // Detect when popup closes so we can re-show the inline widget
        const checkClosed = setInterval(() => {
            if (detachedWindow && detachedWindow.closed) {
                clearInterval(checkClosed);
                detachedWindow = null;
                elements.toggle.style.display = '';
                window.removeEventListener('message', handleDetachedMessage);
                // Restore conversation from localStorage (popup may have updated it)
                restoreConversation();
            }
        }, 500);
    }

    function handleDetachedMessage(event) {
        // Only accept messages from our popup
        if (event.source !== detachedWindow) return;
        const data = event.data;
        if (!data || data.source !== 'myctobot_assist') return;

        // Execute action on this page (highlight, scroll, fill, navigate, etc.)
        if (data.type === 'action') {
            executeAction(data.action);
        }
    }

    // Expose helpers for the detached popup window
    window.assistExecuteAction = function(action) {
        executeAction(action);
    };
    window.assistGetPageContext = function() {
        return scanPageContext();
    };

    function scrollToBottom() {
        elements.messages.scrollTop = elements.messages.scrollHeight;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatContent(text) {
        // Basic markdown formatting
        let result = text.trim()
            // Collapse multiple newlines into max 2
            .replace(/\n{3,}/g, '\n\n');

        // Step 1: Extract code blocks (2-3 backticks) and replace with placeholders
        const codeBlocks = [];
        result = result.replace(/`{2,3}(\w*)\s*\n?([\s\S]*?)`{2,3}/g, (match, lang, code) => {
            const trimmed = code.trim();
            // Auto-detect JSON if no lang specified
            const effectiveLang = lang || (trimmed.startsWith('{') || trimmed.startsWith('[') ? 'json' : '');
            const langLabel = effectiveLang ? `<span class="assist-code-lang">${escapeHtml(effectiveLang.toUpperCase())}</span>` : '';
            // Pretty-print JSON
            let formatted = trimmed;
            if (effectiveLang === 'json') {
                try { formatted = JSON.stringify(JSON.parse(formatted), null, 2); } catch(e) {}
            }
            const placeholder = `\x00CB${codeBlocks.length}\x00`;
            const copyBtn = `<button class="assist-code-copy" title="Copy"><i class="bi bi-clipboard"></i></button>`;
            codeBlocks.push(`<div class="assist-code-block">${langLabel}${copyBtn}<pre><code>${escapeHtml(formatted)}</code></pre></div>`);
            return placeholder;
        });

        // Step 2: Inline code (safe now - code blocks already extracted)
        result = result.replace(/`([^`]+)`/g, '<code>$1</code>');

        // Step 3: Lists (unordered: - item, ordered: 1. item)
        // Convert consecutive list lines into <ul>/<ol> blocks
        result = result.replace(/(^|\n)((?:- .+(?:\n|$))+)/gm, (match, prefix, block) => {
            const items = block.trim().split('\n')
                .map(line => `<li>${line.replace(/^- /, '')}</li>`).join('');
            return `${prefix}<ul>${items}</ul>`;
        });
        result = result.replace(/(^|\n)((?:\d+\. .+(?:\n|$))+)/gm, (match, prefix, block) => {
            const items = block.trim().split('\n')
                .map(line => `<li>${line.replace(/^\d+\. /, '')}</li>`).join('');
            return `${prefix}<ol>${items}</ol>`;
        });

        // Step 4: Bold, italic, paragraphs
        result = result
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/\*([^*]+)\*/g, '<em>$1</em>')
            .replace(/\n\n/g, '</p><p>')
            .replace(/\n/g, '<br>');

        if (result.includes('</p>')) result = '<p>' + result + '</p>';

        // Step 5: Restore code blocks from placeholders
        for (let i = 0; i < codeBlocks.length; i++) {
            result = result.replace(`\x00CB${i}\x00`, codeBlocks[i]);
        }

        return result;
    }

    // ========================================
    // Event Listeners
    // ========================================

    function init() {
        elements.toggle.addEventListener('click', togglePanel);
        elements.close.addEventListener('click', hidePanel);
        elements.clear.addEventListener('click', clearConversation);
        elements.detach.addEventListener('click', detachToWindow);
        elements.send.addEventListener('click', () => sendMessage(elements.input.value.trim()));

        elements.input.addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage(elements.input.value.trim());
            }
        });

        elements.input.addEventListener('input', () => {
            elements.send.disabled = !elements.input.value.trim() || !connected;
        });

        // Copy button on code blocks (delegated)
        elements.messages.addEventListener('click', (e) => {
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
                }, 1500);
            });
        });

        // Quick action buttons
        document.querySelectorAll('.assist-quick').forEach(btn => {
            btn.addEventListener('click', () => {
                const message = btn.dataset.message;
                if (message) {
                    sendMessage(message);
                }
            });
        });

        // Keyboard shortcut to toggle (Ctrl+Shift+A)
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey && e.shiftKey && e.key === 'A') {
                e.preventDefault();
                togglePanel();
            }
        });

        // Restore panel state from previous session
        if (localStorage.getItem('assist_panel_open') === 'true') {
            showPanel();
        }

        // Proactive connection check after page loads
        // This allows the button to show connected state before user clicks
        setTimeout(() => {
            console.log('[Assist] Attempting proactive connection to:', ASSIST_CONFIG.wsUrl);
            if (!ws || ws.readyState !== WebSocket.OPEN) {
                proactiveConnect();
            }
        }, 500); // Reduced from 1000ms for faster feedback
    }

    // Proactive connection retry counter
    let proactiveRetries = 0;
    const MAX_PROACTIVE_RETRIES = 3;

    // Proactive connection (in background with retry)
    function proactiveConnect() {
        if (ws && ws.readyState === WebSocket.OPEN) return;

        setConnectionState('connecting');
        console.log('[Assist] Connecting... (attempt ' + (proactiveRetries + 1) + ')');

        let testWs;
        try {
            testWs = new WebSocket(ASSIST_CONFIG.wsUrl);
        } catch (e) {
            console.error('[Assist] Failed to create WebSocket:', e);
            setConnectionState('unavailable');
            scheduleRetry();
            return;
        }

        testWs.onopen = () => {
            console.log('[Assist] Connected successfully!');
            // Connection successful - keep it for later use
            ws = testWs;
            connected = true;
            reconnectAttempts = 0;
            proactiveRetries = 0;
            setConnectionState('connected');

            testWs.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    handleMessage(data);
                } catch (e) {
                    console.error('[Assist] Failed to parse message:', e);
                }
            };

            testWs.onclose = () => {
                console.log('[Assist] Connection closed');
                connected = false;
                setConnectionState('unavailable');
                ws = null;
                // Try to reconnect after a delay
                scheduleRetry();
            };

            testWs.onerror = (e) => {
                console.error('[Assist] WebSocket error:', e);
                setConnectionState('unavailable');
            };
        };

        testWs.onerror = (e) => {
            console.error('[Assist] Connection failed:', e);
            setConnectionState('unavailable');
            try { testWs.close(); } catch(ex) {}
            scheduleRetry();
        };

        // Timeout after 5 seconds
        setTimeout(() => {
            if (testWs.readyState === WebSocket.CONNECTING) {
                console.log('[Assist] Connection timeout');
                testWs.close();
                setConnectionState('unavailable');
                scheduleRetry();
            }
        }, 5000);
    }

    function scheduleRetry() {
        if (proactiveRetries < MAX_PROACTIVE_RETRIES) {
            proactiveRetries++;
            const delay = proactiveRetries * 3000; // 3s, 6s, 9s
            console.log('[Assist] Retrying in ' + (delay/1000) + 's...');
            setTimeout(proactiveConnect, delay);
        } else {
            console.log('[Assist] Max retries reached, staying unavailable');
        }
    }

    // Initialize
    init();

    // Expose for debugging
    window.PageAssist = {
        toggle: togglePanel,
        scan: scanPageContext,
        state: () => connectionState,
        connect: connect
    };
})();
</script>
