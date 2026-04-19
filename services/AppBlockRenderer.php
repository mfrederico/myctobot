<?php
/**
 * AppBlockRenderer - Converts block JSON definitions into Bootstrap 5 PHP views
 *
 * Takes block configurations from appscreens and generates renderable PHP/HTML.
 * Used both for live preview (in-request rendering) and code generation (deploy-time).
 */

namespace app\services;

class AppBlockRenderer
{
    /**
     * Sanitize a URL to prevent javascript: protocol injection
     */
    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        // Block javascript:, data:, vbscript: and other dangerous protocols
        if (preg_match('/^\s*(javascript|data|vbscript)\s*:/i', $url)) {
            return '#';
        }
        return h($url);
    }

    /**
     * Get the runtime JavaScript for data bindings, forms, charts, chat, etc.
     * Used by both preview and deployed apps.
     */
    public function getRuntimeJs(string $pipelineEndpoint = '/api/pipeline'): string
    {
        $js = <<<'JSBLOCK'
    // App Builder Runtime
    document.addEventListener('DOMContentLoaded', function() {
        initDataBindings();
        initForms();
        initCharts();
        initMarkdown();
        initChat();
    });

    const appState = {};

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = String(s ?? '');
        return d.innerHTML;
    }

    async function callPipeline(slug, input = {}) {
        const resp = await fetch('/api/pipeline/' + encodeURIComponent(slug), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ pipeline: slug, input })
        });
        return resp.json();
    }

    function initDataBindings() {
        document.querySelectorAll('[data-pipeline]').forEach(async el => {
            const slug = el.dataset.pipeline;
            if (!slug) return;
            try {
                const input = el.dataset.pipelineInput ? JSON.parse(el.dataset.pipelineInput) : {};
                const result = await callPipeline(slug, input);
                if (result.success) {
                    const d = result.data || result.output || {};
                    updateBlockData(el, d.output || d.data || d);
                }
            } catch (e) { console.error('Pipeline load failed:', slug, e); }
        });
    }

    function updateBlockData(el, data) {
        // KPI cards
        el.querySelectorAll('[data-kpi]').forEach(kpi => {
            const key = kpi.dataset.kpi;
            const fmt = kpi.dataset.format;
            let val = data[key] ?? '-';
            if (fmt === 'currency' && typeof val === 'number') val = '$' + val.toLocaleString();
            else if (fmt === 'number' && typeof val === 'number') val = val.toLocaleString();
            kpi.textContent = val;
        });

        // Tables
        const colDefs = el.dataset.columns ? JSON.parse(el.dataset.columns) : null;
        if (colDefs) {
            const tbody = el.querySelector('tbody');
            const rows = Array.isArray(data) ? data : (data.rows || data.items || data.edges?.map(e => e.node) || []);
            if (tbody && rows.length) {
                tbody.innerHTML = rows.map(row => {
                    return '<tr>' + colDefs.map(col => {
                        let val = row[col.field] ?? '';
                        if (col.render === 'badge' && col.badge_map) {
                            const color = esc(col.badge_map[val] || 'secondary').replace(/[^a-z0-9-]/gi, '');
                            return '<td><span class="badge bg-' + color + '">' + esc(val) + '</span></td>';
                        }
                        if (col.render === 'currency') return '<td>$' + Number(val).toLocaleString() + '</td>';
                        if (col.render === 'date') return '<td>' + esc(new Date(val).toLocaleDateString()) + '</td>';
                        return '<td>' + esc(val) + '</td>';
                    }).join('') + '</tr>';
                }).join('');
            }
        }

        // List groups
        const listGroup = el.querySelector('.list-group');
        if (listGroup && !colDefs) {
            const items = Array.isArray(data) ? data : (data.items || []);
            if (items.length) {
                listGroup.innerHTML = items.map(item => {
                    const text = typeof item === 'string' ? item : (item.name || item.title || JSON.stringify(item));
                    return '<li class="list-group-item">' + esc(text) + '</li>';
                }).join('');
            }
        }

        // Charts
        if (el._chart && el.dataset.chartConfig) {
            const config = JSON.parse(el.dataset.chartConfig);
            const items = Array.isArray(data) ? data : (data.items || data.rows || []);
            if (items.length) {
                const labelsField = config.labels_field || 'label';
                el._chart.data.labels = items.map(item => item[labelsField] || '');
                el._chart.data.datasets = (config.datasets || []).map(ds => ({
                    label: ds.label || '',
                    data: items.map(item => Number(item[ds.data_field] || 0)),
                    backgroundColor: ds.color || '#0d6efd',
                    borderColor: ds.color || '#0d6efd',
                }));
                el._chart.update();
            }
        }
    }

    function initForms() {
        document.querySelectorAll('[data-block-form]').forEach(form => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const btn = form.querySelector('button[type=submit]');
                btn.querySelector('.btn-text').classList.add('d-none');
                btn.querySelector('.btn-loading').classList.remove('d-none');
                btn.disabled = true;

                const formData = Object.fromEntries(new FormData(form));
                const eventConfig = form.dataset.onSubmit ? JSON.parse(form.dataset.onSubmit) : null;

                try {
                    if (eventConfig && eventConfig.action === 'run_pipeline') {
                        const result = await callPipeline(eventConfig.pipeline_slug, formData);
                        if (result.success && eventConfig.on_success) {
                            handleAction(eventConfig.on_success, result);
                        } else if (!result.success && eventConfig.on_error) {
                            handleAction(eventConfig.on_error, result);
                        }
                    }
                } catch (err) {
                    console.error('Form submit error:', err);
                } finally {
                    btn.querySelector('.btn-text').classList.remove('d-none');
                    btn.querySelector('.btn-loading').classList.add('d-none');
                    btn.disabled = false;
                }
            });
        });

        // Button actions
        document.querySelectorAll('[data-action="run_pipeline"]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const slug = btn.dataset.pipeline;
                if (!slug) return;
                btn.disabled = true;
                try {
                    await callPipeline(slug);
                } finally {
                    btn.disabled = false;
                }
            });
        });
    }

    function handleAction(action, data) {
        if (!action) return;
        if (action.action === 'navigate') {
            let target = action.target || '/';
            target = target.replace(/\{(\w+)\}/g, (_, k) => encodeURIComponent(data[k] || data?.data?.[k] || ''));
            if (/^\s*(javascript|data|vbscript)\s*:/i.test(target)) target = '/';
            window.location.href = target;
        } else if (action.action === 'show_alert') {
            const msg = (action.message || '').replace(/\{error\}/g, esc(data.error || data.message || ''));
            const variant = (action.variant || 'danger').replace(/[^a-z0-9-]/gi, '');
            const alertEl = document.createElement('div');
            alertEl.className = 'alert alert-' + variant + ' alert-dismissible fade show';
            alertEl.innerHTML = esc(msg) + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
            document.querySelector('.container').prepend(alertEl);
        } else if (action.action === 'refresh_block') {
            const el = document.getElementById(action.block_id);
            if (el && el.dataset.pipeline) {
                callPipeline(el.dataset.pipeline).then(r => { if (r.success) updateBlockData(el, r.data || r.output); });
            }
        }
    }

    function initCharts() {
        document.querySelectorAll('[data-chart-config]').forEach(el => {
            const config = JSON.parse(el.dataset.chartConfig);
            const canvas = el.querySelector('canvas');
            if (!canvas) return;
            el._chart = new Chart(canvas, {
                type: config.chart_type || 'bar',
                data: { labels: [], datasets: [] },
                options: { responsive: true, plugins: { title: { display: false } } }
            });
        });
    }

    function initMarkdown() {
        if (typeof marked !== 'undefined') {
            marked.setOptions({ breaks: true, gfm: true });
            const renderer = new marked.Renderer();
            const origLink = renderer.link.bind(renderer);
            renderer.link = function(href, title, text) {
                if (/^\s*(javascript|data|vbscript)\s*:/i.test(href || '')) href = '#';
                return origLink(href, title, text);
            };
            marked.use({ renderer });
        }
        document.querySelectorAll('[data-markdown]').forEach(el => {
            if (typeof marked !== 'undefined') {
                let html = marked.parse(el.dataset.markdown);
                html = html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
                html = html.replace(/on\w+\s*=\s*["'][^"']*["']/gi, '');
                el.innerHTML = html;
            } else {
                el.textContent = el.dataset.markdown;
            }
        });
    }

    function initChat() {
        document.querySelectorAll('[data-chat-form]').forEach(form => {
            const widgetId = form.dataset.chatForm;
            const widget = document.getElementById(widgetId);
            if (!widget) return;

            const slug = widget.dataset.pipeline;
            if (!slug) return;

            const messagesEl = widget.querySelector('.chat-messages');
            const input = form.querySelector('[data-chat-input]');
            if (!messagesEl || !input) return;

            if (!appState.chatHistory) appState.chatHistory = {};
            appState.chatHistory[widgetId] = [];

            function appendBubble(role, content) {
                const bubble = document.createElement('div');
                bubble.className = 'chat-message ' + role + ' mb-2';
                const inner = document.createElement('div');
                inner.className = role === 'user'
                    ? 'bg-primary text-white rounded p-2 px-3 small'
                    : 'bg-light rounded p-2 px-3 small';
                if (role === 'assistant' && typeof marked !== 'undefined') {
                    let html = marked.parse(String(content));
                    html = html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
                    html = html.replace(/on\w+\s*=\s*["'][^"']*["']/gi, '');
                    inner.innerHTML = html;
                } else {
                    inner.textContent = content;
                }
                bubble.appendChild(inner);
                messagesEl.appendChild(bubble);
                messagesEl.scrollTop = messagesEl.scrollHeight;
                return bubble;
            }

            function showTyping() {
                const el = document.createElement('div');
                el.className = 'chat-message assistant mb-2';
                el.id = widgetId + '_typing';
                el.innerHTML = '<div class="bg-light rounded p-2 px-3 small"><span class="spinner-border spinner-border-sm me-1"></span>Thinking...</div>';
                messagesEl.appendChild(el);
                messagesEl.scrollTop = messagesEl.scrollHeight;
            }

            function removeTyping() {
                const el = document.getElementById(widgetId + '_typing');
                if (el) el.remove();
            }

            async function sendMessage(text) {
                const msg = text.trim();
                if (!msg) return;

                appendBubble('user', msg);
                appState.chatHistory[widgetId].push({ role: 'user', content: msg });

                input.value = '';
                input.disabled = true;
                form.querySelector('button[type=submit]').disabled = true;
                showTyping();

                try {
                    const result = await callPipeline(slug, {
                        message: msg,
                        history: appState.chatHistory[widgetId]
                    });
                    removeTyping();

                    const reply = result.success
                        ? (result.data?.reply || result.data?.message || result.data?.text || result.output?.reply || result.output?.message || result.output?.text || JSON.stringify(result.data || result.output || ''))
                        : (result.error || 'Sorry, something went wrong.');

                    appendBubble('assistant', reply);
                    appState.chatHistory[widgetId].push({ role: 'assistant', content: reply });
                } catch (e) {
                    removeTyping();
                    appendBubble('assistant', 'Connection error. Please try again.');
                    console.error('Chat pipeline error:', e);
                } finally {
                    input.disabled = false;
                    form.querySelector('button[type=submit]').disabled = false;
                    input.focus();
                }
            }

            form.addEventListener('submit', e => {
                e.preventDefault();
                sendMessage(input.value);
            });

            widget.querySelectorAll('[data-quick-message]').forEach(btn => {
                btn.addEventListener('click', () => {
                    sendMessage(btn.dataset.quickMessage);
                });
            });
        });
    }
JSBLOCK;
        return str_replace('/api/pipeline', $pipelineEndpoint, $js);
    }

    /**
     * Render a complete page from a screen definition
     */
    public function renderPage(array $screen, array $appConfig = []): string
    {
        $blocks = $screen['blocks'] ?? [];

        $blocksHtml = '';
        foreach ($blocks as $block) {
            $blocksHtml .= $this->renderBlock($block) . "\n";
        }

        return $blocksHtml;
    }

    /**
     * Render a single block to HTML
     */
    public function renderBlock(array $block): string
    {
        $type = $block['type'] ?? '';
        $config = $block['config'] ?? [];
        $binding = $block['data_binding'] ?? [];
        $events = $block['events'] ?? [];
        $blockId = h($block['id'] ?? 'block_' . md5(json_encode($block)));

        $method = 'render' . str_replace('_', '', ucwords($type, '_'));
        if (method_exists($this, $method)) {
            return $this->$method($blockId, $config, $binding, $events);
        }

        return '<!-- Unknown block type: ' . h($type) . ' -->';
    }

    // ─── Block Renderers ─────────────────────────────────────────

    private function renderHeader(string $id, array $config, array $binding, array $events): string
    {
        $title = h($config['title'] ?? 'Page Title');
        $subtitle = h($config['subtitle'] ?? '');

        $breadcrumbHtml = '';
        if (!empty($config['show_breadcrumb']) && !empty($config['breadcrumb'])) {
            $breadcrumbHtml = '<nav aria-label="breadcrumb"><ol class="breadcrumb mb-1">';
            foreach ($config['breadcrumb'] as $crumb) {
                $label = h($crumb['label'] ?? '');
                if (!empty($crumb['url'])) {
                    $url = $this->sanitizeUrl($crumb['url']);
                    $breadcrumbHtml .= "<li class=\"breadcrumb-item\"><a href=\"{$url}\">{$label}</a></li>";
                } else {
                    $breadcrumbHtml .= "<li class=\"breadcrumb-item active\">{$label}</li>";
                }
            }
            $breadcrumbHtml .= '</ol></nav>';
        }

        $actionsHtml = '';
        if (!empty($config['actions'])) {
            $actionsHtml = '<div class="d-flex gap-2">';
            foreach ($config['actions'] as $action) {
                $label = h($action['label'] ?? '');
                $icon = h($action['icon'] ?? '');
                $variant = h($action['variant'] ?? 'primary');
                $target = $this->sanitizeUrl($action['target'] ?? '#');
                $iconHtml = $icon ? "<i class=\"bi {$icon} me-1\"></i>" : '';
                $actionsHtml .= "<a href=\"{$target}\" class=\"btn btn-{$variant}\">{$iconHtml}{$label}</a>";
            }
            $actionsHtml .= '</div>';
        }

        $subtitleHtml = $subtitle ? "<p class=\"text-muted mb-0\">{$subtitle}</p>" : '';

        return <<<HTML
<div id="{$id}" class="d-flex justify-content-between align-items-center mb-4">
    <div>
        {$breadcrumbHtml}
        <h1 class="h2 mb-0">{$title}</h1>
        {$subtitleHtml}
    </div>
    {$actionsHtml}
</div>
HTML;
    }

    private function renderCard(string $id, array $config, array $binding, array $events): string
    {
        $title = h($config['title'] ?? '');
        $icon = h($config['icon'] ?? '');
        $content = h($config['content'] ?? '');
        $footer = h($config['footer'] ?? '');
        $variant = preg_replace('/[^a-z0-9-]/i', '', $config['variant'] ?? 'default');

        $headerClass = $variant !== 'default' ? " text-bg-{$variant}" : '';
        $iconHtml = $icon ? "<i class=\"bi {$icon} me-2\"></i>" : '';

        $headerHtml = $title ? "<div class=\"card-header{$headerClass}\">{$iconHtml}{$title}</div>" : '';
        $footerHtml = $footer ? "<div class=\"card-footer text-muted\">{$footer}</div>" : '';

        $bodyHtml = $content;
        if (($config['body_type'] ?? 'text') === 'text') {
            $bodyHtml = "<p class=\"card-text\">{$content}</p>";
        }

        $dataAttr = '';
        if (!empty($binding['pipeline_slug'])) {
            $dataAttr = ' data-pipeline="' . h($binding['pipeline_slug']) . '"';
            if (!empty($binding['input'])) {
                $dataAttr .= " data-pipeline-input='" . h(json_encode($binding['input']), ENT_QUOTES) . "'";
            }
        }

        return <<<HTML
<div id="{$id}" class="card mb-4"{$dataAttr}>
    {$headerHtml}
    <div class="card-body">
        {$bodyHtml}
    </div>
    {$footerHtml}
</div>
HTML;
    }

    private function renderKpiRow(string $id, array $config, array $binding, array $events): string
    {
        $columns = (int) ($config['columns'] ?? 4);
        $colClass = 'col-md-' . (int) (12 / $columns);
        $cards = $config['cards'] ?? [];

        $cardsHtml = '';
        foreach ($cards as $card) {
            $label = h($card['label'] ?? '');
            $icon = h($card['icon'] ?? 'bi-circle');
            $color = h($card['color'] ?? 'primary');
            $valueKey = h($card['value_key'] ?? '');
            $format = $card['format'] ?? 'number';

            $cardsHtml .= <<<HTML
    <div class="{$colClass} mb-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="rounded bg-{$color} bg-opacity-10 p-3 me-3">
                        <i class="bi {$icon} fs-4 text-{$color}"></i>
                    </div>
                    <div>
                        <div class="text-muted small">{$label}</div>
                        <div class="h4 mb-0" data-kpi="{$valueKey}" data-format="{$format}">-</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
HTML;
        }

        $dataAttr = '';
        if (!empty($binding['pipeline_slug'])) {
            $dataAttr = ' data-pipeline="' . h($binding['pipeline_slug']) . '"';
            if (!empty($binding['input'])) {
                $dataAttr .= " data-pipeline-input='" . h(json_encode($binding['input']), ENT_QUOTES) . "'";
            }
        }

        return <<<HTML
<div id="{$id}" class="row"{$dataAttr}>
{$cardsHtml}
</div>
HTML;
    }

    private function renderDataTable(string $id, array $config, array $binding, array $events): string
    {
        $title = h($config['title'] ?? 'Data');
        $columns = $config['columns'] ?? [];
        $searchable = $config['searchable'] ?? true;
        $emptyMsg = h($config['empty_message'] ?? 'No records found');
        $pagination = (int) ($config['pagination'] ?? 10);

        $theadHtml = '<tr>';
        foreach ($columns as $col) {
            $label = h($col['label'] ?? $col['field'] ?? '');
            $sortable = !empty($col['sortable']) ? ' style="cursor:pointer" data-sort="' . h($col['field'] ?? '') . '"' : '';
            $theadHtml .= "<th{$sortable}>{$label}</th>";
        }
        $theadHtml .= '</tr>';

        $colCount = count($columns);
        $colDefsJson = h(json_encode($columns));

        $searchHtml = '';
        if ($searchable) {
            $searchHtml = <<<HTML
    <div class="mb-3">
        <input type="text" class="form-control" placeholder="Search..." data-table-search="{$id}">
    </div>
HTML;
        }

        $dataAttr = '';
        if (!empty($binding['pipeline_slug'])) {
            $dataAttr = ' data-pipeline="' . h($binding['pipeline_slug']) . '"';
            if (!empty($binding['input'])) {
                $dataAttr .= " data-pipeline-input='" . h(json_encode($binding['input']), ENT_QUOTES) . "'";
            }
        }

        return <<<HTML
<div id="{$id}" class="card mb-4"{$dataAttr} data-columns='{$colDefsJson}' data-page-size="{$pagination}">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">{$title}</h5>
        <button class="btn btn-sm btn-outline-secondary" data-refresh="{$id}">
            <i class="bi bi-arrow-clockwise"></i>
        </button>
    </div>
    <div class="card-body">
        {$searchHtml}
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">{$theadHtml}</thead>
                <tbody>
                    <tr><td colspan="{$colCount}" class="text-center text-muted py-4">{$emptyMsg}</td></tr>
                </tbody>
            </table>
        </div>
        <nav class="mt-3" data-pagination="{$id}"></nav>
    </div>
</div>
HTML;
    }

    private function renderForm(string $id, array $config, array $binding, array $events): string
    {
        $title = h($config['title'] ?? '');
        $fields = $config['fields'] ?? [];
        $submitLabel = h($config['submit_label'] ?? 'Submit');
        $submitIcon = h($config['submit_icon'] ?? 'bi-check-lg');
        $layout = $config['layout'] ?? 'vertical';

        $fieldsHtml = '';
        foreach ($fields as $field) {
            $name = h($field['name'] ?? '');
            $label = h($field['label'] ?? ucfirst($name));
            $type = h($field['type'] ?? 'text');
            $required = !empty($field['required']) ? 'required' : '';
            $placeholder = h($field['placeholder'] ?? '');
            $icon = $field['icon'] ?? '';
            $requiredBadge = $required ? '<span class="text-danger">*</span>' : '';

            if ($type === 'textarea') {
                $fieldsHtml .= <<<HTML
    <div class="mb-3">
        <label for="{$id}_{$name}" class="form-label">{$label} {$requiredBadge}</label>
        <textarea class="form-control" id="{$id}_{$name}" name="{$name}" rows="3" placeholder="{$placeholder}" {$required}></textarea>
    </div>
HTML;
            } elseif ($type === 'select' && !empty($field['options'])) {
                $optionsHtml = '<option value="">-- Select --</option>';
                foreach ($field['options'] as $opt) {
                    $optVal = h(is_array($opt) ? ($opt['value'] ?? '') : $opt);
                    $optLabel = h(is_array($opt) ? ($opt['label'] ?? $optVal) : $opt);
                    $optionsHtml .= "<option value=\"{$optVal}\">{$optLabel}</option>";
                }
                $fieldsHtml .= <<<HTML
    <div class="mb-3">
        <label for="{$id}_{$name}" class="form-label">{$label} {$requiredBadge}</label>
        <select class="form-select" id="{$id}_{$name}" name="{$name}" {$required}>{$optionsHtml}</select>
    </div>
HTML;
            } elseif ($type === 'checkbox') {
                $fieldsHtml .= <<<HTML
    <div class="mb-3 form-check">
        <input type="checkbox" class="form-check-input" id="{$id}_{$name}" name="{$name}">
        <label class="form-check-label" for="{$id}_{$name}">{$label}</label>
    </div>
HTML;
            } else {
                $iconHtml = '';
                if ($icon) {
                    $iconHtml = "<span class=\"input-group-text\"><i class=\"bi {$icon}\"></i></span>";
                }
                $inputHtml = "<input type=\"{$type}\" class=\"form-control\" id=\"{$id}_{$name}\" name=\"{$name}\" placeholder=\"{$placeholder}\" {$required}>";

                if ($iconHtml) {
                    $fieldsHtml .= <<<HTML
    <div class="mb-3">
        <label for="{$id}_{$name}" class="form-label">{$label} {$requiredBadge}</label>
        <div class="input-group">{$iconHtml}{$inputHtml}</div>
    </div>
HTML;
                } else {
                    $fieldsHtml .= <<<HTML
    <div class="mb-3">
        <label for="{$id}_{$name}" class="form-label">{$label} {$requiredBadge}</label>
        {$inputHtml}
    </div>
HTML;
                }
            }
        }

        $titleHtml = $title ? "<h5 class=\"card-title mb-3\">{$title}</h5>" : '';

        // Event handling data attributes
        $eventAttr = '';
        if (!empty($events['onSubmit'])) {
            $eventAttr = " data-on-submit='" . h(json_encode($events['onSubmit'])) . "'";
        }

        return <<<HTML
<div id="{$id}" class="card mb-4">
    <div class="card-body">
        {$titleHtml}
        <form data-block-form="{$id}"{$eventAttr}>
            {$fieldsHtml}
            <div class="d-grid">
                <button type="submit" class="btn btn-primary">
                    <i class="bi {$submitIcon} me-1"></i>
                    <span class="btn-text">{$submitLabel}</span>
                    <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-1"></span>Processing...</span>
                </button>
            </div>
        </form>
    </div>
</div>
HTML;
    }

    private function renderChatWidget(string $id, array $config, array $binding, array $events): string
    {
        $title = h($config['title'] ?? 'Chat');
        $welcomeMsg = h($config['welcome_message'] ?? '');
        $display = $config['display'] ?? 'inline';
        $quickActions = $config['quick_actions'] ?? [];

        $quickHtml = '';
        if (!empty($quickActions)) {
            $quickHtml = '<div class="d-flex flex-wrap gap-2 mb-3">';
            foreach ($quickActions as $qa) {
                $qaLabel = h($qa['label'] ?? '');
                $qaMsg = h($qa['message'] ?? '');
                $quickHtml .= "<button class=\"btn btn-sm btn-outline-primary\" data-quick-message=\"{$qaMsg}\">{$qaLabel}</button>";
            }
            $quickHtml .= '</div>';
        }

        $welcomeHtml = $welcomeMsg ? <<<HTML
        <div class="chat-message system mb-3">
            <div class="bg-light rounded p-3 small">{$welcomeMsg}</div>
        </div>
HTML : '';

        $dataAttr = '';
        if (!empty($binding['pipeline_slug'])) {
            $dataAttr = ' data-pipeline="' . h($binding['pipeline_slug']) . '"';
            if (!empty($binding['input'])) {
                $dataAttr .= " data-pipeline-input='" . h(json_encode($binding['input']), ENT_QUOTES) . "'";
            }
        }

        $heightStyle = $display === 'fullscreen' ? 'height: calc(100vh - 200px)' : 'height: 400px';

        return <<<HTML
<div id="{$id}" class="card mb-4"{$dataAttr}>
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-chat-dots me-2"></i>{$title}</h5>
    </div>
    <div class="card-body p-0">
        <div class="chat-messages p-3" style="{$heightStyle}; overflow-y: auto;">
            {$welcomeHtml}
        </div>
        {$quickHtml}
        <div class="border-top p-3">
            <form data-chat-form="{$id}">
                <div class="input-group">
                    <input type="text" class="form-control" placeholder="Type a message..." data-chat-input>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
HTML;
    }

    private function renderAlert(string $id, array $config, array $binding, array $events): string
    {
        $variant = preg_replace('/[^a-z0-9-]/i', '', $config['variant'] ?? 'info');
        $icon = h($config['icon'] ?? '');
        $content = h($config['content'] ?? '');
        $dismissible = !empty($config['dismissible']);

        $dismissClass = $dismissible ? ' alert-dismissible fade show' : '';
        $dismissBtn = $dismissible ? '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' : '';
        $iconHtml = $icon ? "<i class=\"bi {$icon} me-2\"></i>" : '';

        return <<<HTML
<div id="{$id}" class="alert alert-{$variant}{$dismissClass} mb-4" role="alert">
    {$iconHtml}{$content}
    {$dismissBtn}
</div>
HTML;
    }

    private function renderButtonGroup(string $id, array $config, array $binding, array $events): string
    {
        $layout = $config['layout'] ?? 'horizontal';
        $buttons = $config['buttons'] ?? [];

        $containerClass = $layout === 'vertical' ? 'd-grid gap-2' : 'd-flex flex-wrap gap-2';

        $buttonsHtml = '';
        foreach ($buttons as $btn) {
            $label = h($btn['label'] ?? '');
            $icon = h($btn['icon'] ?? '');
            $variant = h($btn['variant'] ?? 'primary');
            $event = h($btn['event'] ?? '');
            $pipelineSlug = h($btn['pipeline_slug'] ?? '');
            $target = $this->sanitizeUrl($btn['target'] ?? '');
            $iconHtml = $icon ? "<i class=\"bi {$icon} me-1\"></i>" : '';

            $dataAttrs = '';
            if ($event === 'navigate' && $target) {
                $buttonsHtml .= "<a href=\"{$target}\" class=\"btn btn-{$variant}\">{$iconHtml}{$label}</a>";
            } else {
                if ($event === 'run_pipeline' && $pipelineSlug) {
                    $dataAttrs = " data-action=\"run_pipeline\" data-pipeline=\"{$pipelineSlug}\"";
                }
                $buttonsHtml .= "<button class=\"btn btn-{$variant}\"{$dataAttrs}>{$iconHtml}{$label}</button>";
            }
        }

        return <<<HTML
<div id="{$id}" class="{$containerClass} mb-4">
    {$buttonsHtml}
</div>
HTML;
    }

    private function renderListGroup(string $id, array $config, array $binding, array $events): string
    {
        $title = h($config['title'] ?? '');

        $dataAttr = '';
        if (!empty($binding['pipeline_slug'])) {
            $dataAttr = ' data-pipeline="' . h($binding['pipeline_slug']) . '"';
            if (!empty($binding['input'])) {
                $dataAttr .= " data-pipeline-input='" . h(json_encode($binding['input']), ENT_QUOTES) . "'";
            }
        }

        $headerHtml = $title ? "<div class=\"card-header\"><h5 class=\"mb-0\">{$title}</h5></div>" : '';

        return <<<HTML
<div id="{$id}" class="card mb-4"{$dataAttr}>
    {$headerHtml}
    <ul class="list-group list-group-flush">
        <li class="list-group-item text-center text-muted py-3">Loading...</li>
    </ul>
</div>
HTML;
    }

    private function renderMarkdown(string $id, array $config, array $binding, array $events): string
    {
        $content = $config['content'] ?? '';
        $escapedContent = h($content);

        return <<<HTML
<div id="{$id}" class="mb-4">
    <div class="markdown-content" data-markdown="{$escapedContent}"></div>
</div>
HTML;
    }

    private function renderRow(string $id, array $config, array $binding, array $events): string
    {
        $columns = $config['columns'] ?? [];
        $inner = '';
        foreach ($columns as $col) {
            $colClass = h($col['col_class'] ?? 'col');
            $colScreen = ['blocks' => $col['blocks'] ?? []];
            $colHtml = $this->renderPage($colScreen);
            $inner .= "<div class=\"{$colClass}\">\n{$colHtml}</div>\n";
        }
        return "<div id=\"{$id}\" class=\"row mb-4\">\n{$inner}</div>\n";
    }

    private function renderTabs(string $id, array $config, array $binding, array $events): string
    {
        $tabs = $config['tabs'] ?? [];

        $navHtml = '<ul class="nav nav-tabs" role="tablist">';
        $contentHtml = '<div class="tab-content">';

        foreach ($tabs as $i => $tab) {
            $tabId = $id . '_tab_' . $i;
            $label = h($tab['label'] ?? 'Tab ' . ($i + 1));
            $icon = h($tab['icon'] ?? '');
            $active = $i === 0 ? ' active' : '';
            $selected = $i === 0 ? 'true' : 'false';
            $iconHtml = $icon ? "<i class=\"bi {$icon} me-1\"></i>" : '';

            $navHtml .= <<<HTML
    <li class="nav-item" role="presentation">
        <button class="nav-link{$active}" id="{$tabId}-btn" data-bs-toggle="tab" data-bs-target="#{$tabId}" type="button" role="tab" aria-selected="{$selected}">
            {$iconHtml}{$label}
        </button>
    </li>
HTML;

            // Render nested blocks for this tab (with column layout support)
            $tabBlocksHtml = '';
            if (!empty($tab['blocks'])) {
                $tabScreen = ['blocks' => $tab['blocks']];
                $tabBlocksHtml = $this->renderPage($tabScreen);
            }

            $showActive = $i === 0 ? ' show active' : '';
            $contentHtml .= <<<HTML
    <div class="tab-pane fade{$showActive} p-3" id="{$tabId}" role="tabpanel">
        {$tabBlocksHtml}
    </div>
HTML;
        }

        $navHtml .= '</ul>';
        $contentHtml .= '</div>';

        return <<<HTML
<div id="{$id}" class="card mb-4">
    {$navHtml}
    {$contentHtml}
</div>
HTML;
    }

    private function renderChart(string $id, array $config, array $binding, array $events): string
    {
        $title = h($config['title'] ?? 'Chart');
        $chartType = h($config['chart_type'] ?? 'bar');
        $height = (int) ($config['height'] ?? 300);
        $configJson = h(json_encode($config));

        $dataAttr = '';
        if (!empty($binding['pipeline_slug'])) {
            $dataAttr = ' data-pipeline="' . h($binding['pipeline_slug']) . '"';
            if (!empty($binding['input'])) {
                $dataAttr .= " data-pipeline-input='" . h(json_encode($binding['input']), ENT_QUOTES) . "'";
            }
        }

        return <<<HTML
<div id="{$id}" class="card mb-4"{$dataAttr} data-chart-config='{$configJson}'>
    <div class="card-header"><h5 class="mb-0">{$title}</h5></div>
    <div class="card-body">
        <canvas id="{$id}_canvas" height="{$height}"></canvas>
    </div>
</div>
HTML;
    }

    // ─── Full Page Layout Generation ─────────────────────────────

    /**
     * Generate a complete HTML page layout wrapping blocks
     */
    public function generateLayout(array $screens, array $appConfig = []): string
    {
        $appName = h($appConfig['name'] ?? 'App');
        $themeColor = h($appConfig['theme_color'] ?? '#6366f1');

        // Build navigation from screens
        $navHtml = '';
        foreach ($screens as $screen) {
            $screenName = h($screen['name'] ?? '');
            $routePath = $this->sanitizeUrl($screen['route_path'] ?? '/');
            $navHtml .= "<li class=\"nav-item\"><a class=\"nav-link\" href=\"{$routePath}\">{$screenName}</a></li>";
        }

        $runtimeJs = $this->getRuntimeJs();

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$appName}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
        :root { --app-primary: {$themeColor}; }
        .navbar { background-color: var(--app-primary) !important; }
        .btn-primary { background-color: var(--app-primary); border-color: var(--app-primary); }
        .chat-messages { display: flex; flex-direction: column; }
        .chat-message { max-width: 80%; margin-bottom: 0.5rem; }
        .chat-message.user { align-self: flex-end; }
        .chat-message.assistant { align-self: flex-start; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="/">{$appName}</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#appNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="appNav">
                <ul class="navbar-nav me-auto">{$navHtml}</ul>
            </div>
        </div>
    </nav>
    <div class="container">
        <?php echo \$pageContent; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    {$runtimeJs}
    // getRuntimeJs() provides: callPipeline, initDataBindings, updateBlockData,
    // initForms, handleAction, initCharts, initMarkdown, initChat
    </script>
</body>
</html>
HTML;
    }
    /*
    function initDataBindings() {
        document.querySelectorAll('[data-pipeline]').forEach(async el => {
            const slug = el.dataset.pipeline;
            if (!slug) return;
            try {
                const input = el.dataset.pipelineInput ? JSON.parse(el.dataset.pipelineInput) : {};
                const result = await callPipeline(slug, input);
                if (result.success) {
                    const d = result.data || result.output || {};
                    updateBlockData(el, d.output || d.data || d);
                }
            } catch (e) { console.error('Pipeline load failed:', slug, e); }
        });
    }

    function updateBlockData(el, data) {
        // KPI cards
        el.querySelectorAll('[data-kpi]').forEach(kpi => {
            const key = kpi.dataset.kpi;
            const fmt = kpi.dataset.format;
            let val = data[key] ?? '-';
            if (fmt === 'currency' && typeof val === 'number') val = '$' + val.toLocaleString();
            else if (fmt === 'number' && typeof val === 'number') val = val.toLocaleString();
            kpi.textContent = val;
        });

        // Tables
        const colDefs = el.dataset.columns ? JSON.parse(el.dataset.columns) : null;
        if (colDefs) {
            const tbody = el.querySelector('tbody');
            const rows = Array.isArray(data) ? data : (data.rows || data.items || data.edges?.map(e => e.node) || []);
            if (tbody && rows.length) {
                tbody.innerHTML = rows.map(row => {
                    return '<tr>' + colDefs.map(col => {
                        let val = row[col.field] ?? '';
                        if (col.render === 'badge' && col.badge_map) {
                            const color = esc(col.badge_map[val] || 'secondary').replace(/[^a-z0-9-]/gi, '');
                            return '<td><span class="badge bg-' + color + '">' + esc(val) + '</span></td>';
                        }
                        if (col.render === 'currency') return '<td>$' + Number(val).toLocaleString() + '</td>';
                        if (col.render === 'date') return '<td>' + esc(new Date(val).toLocaleDateString()) + '</td>';
                        return '<td>' + esc(val) + '</td>';
                    }).join('') + '</tr>';
                }).join('');
            }
        }

        // List groups
        const listGroup = el.querySelector('.list-group');
        if (listGroup && !colDefs) {
            const items = Array.isArray(data) ? data : (data.items || []);
            if (items.length) {
                listGroup.innerHTML = items.map(item => {
                    const text = typeof item === 'string' ? item : (item.name || item.title || JSON.stringify(item));
                    return '<li class="list-group-item">' + esc(text) + '</li>';
                }).join('');
            }
        }

        // Charts
        if (el._chart && el.dataset.chartConfig) {
            const config = JSON.parse(el.dataset.chartConfig);
            const items = Array.isArray(data) ? data : (data.items || data.rows || []);
            if (items.length) {
                const labelsField = config.labels_field || 'label';
                el._chart.data.labels = items.map(item => item[labelsField] || '');
                el._chart.data.datasets = (config.datasets || []).map(ds => ({
                    label: ds.label || '',
                    data: items.map(item => Number(item[ds.data_field] || 0)),
                    backgroundColor: ds.color || '#0d6efd',
                    borderColor: ds.color || '#0d6efd',
                }));
                el._chart.update();
            }
        }
    }

    function initForms() {
        document.querySelectorAll('[data-block-form]').forEach(form => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const btn = form.querySelector('button[type=submit]');
                btn.querySelector('.btn-text').classList.add('d-none');
                btn.querySelector('.btn-loading').classList.remove('d-none');
                btn.disabled = true;

                const formData = Object.fromEntries(new FormData(form));
                const eventConfig = form.dataset.onSubmit ? JSON.parse(form.dataset.onSubmit) : null;

                try {
                    if (eventConfig && eventConfig.action === 'run_pipeline') {
                        const result = await callPipeline(eventConfig.pipeline_slug, formData);
                        if (result.success && eventConfig.on_success) {
                            handleAction(eventConfig.on_success, result);
                        } else if (!result.success && eventConfig.on_error) {
                            handleAction(eventConfig.on_error, result);
                        }
                    }
                } catch (err) {
                    console.error('Form submit error:', err);
                } finally {
                    btn.querySelector('.btn-text').classList.remove('d-none');
                    btn.querySelector('.btn-loading').classList.add('d-none');
                    btn.disabled = false;
                }
            });
        });

        // Button actions
        document.querySelectorAll('[data-action="run_pipeline"]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const slug = btn.dataset.pipeline;
                if (!slug) return;
                btn.disabled = true;
                try {
                    await callPipeline(slug);
                } finally {
                    btn.disabled = false;
                }
            });
        });
    }

    function handleAction(action, data) {
        if (!action) return;
        if (action.action === 'navigate') {
            let target = action.target || '/';
            // Substitute {field} placeholders
            target = target.replace(/\{(\w+)\}/g, (_, k) => encodeURIComponent(data[k] || data?.data?.[k] || ''));
            // Block javascript: protocol
            if (/^\s*(javascript|data|vbscript)\s*:/i.test(target)) target = '/';
            window.location.href = target;
        } else if (action.action === 'show_alert') {
            const msg = (action.message || '').replace(/\{error\}/g, esc(data.error || data.message || ''));
            const variant = (action.variant || 'danger').replace(/[^a-z0-9-]/gi, '');
            const alertEl = document.createElement('div');
            alertEl.className = 'alert alert-' + variant + ' alert-dismissible fade show';
            alertEl.innerHTML = esc(msg) + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
            document.querySelector('.container').prepend(alertEl);
        } else if (action.action === 'refresh_block') {
            const el = document.getElementById(action.block_id);
            if (el && el.dataset.pipeline) {
                callPipeline(el.dataset.pipeline).then(r => { if (r.success) updateBlockData(el, r.data || r.output); });
            }
        }
    }

    function initCharts() {
        document.querySelectorAll('[data-chart-config]').forEach(el => {
            const config = JSON.parse(el.dataset.chartConfig);
            const canvas = el.querySelector('canvas');
            if (!canvas) return;
            // Chart will be populated when pipeline data arrives
            el._chart = new Chart(canvas, {
                type: config.chart_type || 'bar',
                data: { labels: [], datasets: [] },
                options: { responsive: true, plugins: { title: { display: false } } }
            });
        });
    }

    function initMarkdown() {
        if (typeof marked !== 'undefined') {
            marked.setOptions({
                breaks: true,
                gfm: true
            });
            // Custom renderer to strip dangerous HTML from links
            const renderer = new marked.Renderer();
            const origLink = renderer.link.bind(renderer);
            renderer.link = function(href, title, text) {
                if (/^\s*(javascript|data|vbscript)\s*:/i.test(href || '')) href = '#';
                return origLink(href, title, text);
            };
            marked.use({ renderer });
        }
        document.querySelectorAll('[data-markdown]').forEach(el => {
            if (typeof marked !== 'undefined') {
                // Sanitize: strip raw script tags after rendering
                let html = marked.parse(el.dataset.markdown);
                html = html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
                html = html.replace(/on\w+\s*=\s*["'][^"']*["']/gi, '');
                el.innerHTML = html;
            } else {
                el.textContent = el.dataset.markdown;
            }
        });
    }

    function initChat() {
        document.querySelectorAll('[data-chat-form]').forEach(form => {
            const widgetId = form.dataset.chatForm;
            const widget = document.getElementById(widgetId);
            if (!widget) return;

            const slug = widget.dataset.pipeline;
            if (!slug) return;

            const messagesEl = widget.querySelector('.chat-messages');
            const input = form.querySelector('[data-chat-input]');
            if (!messagesEl || !input) return;

            // Track history per widget
            if (!appState.chatHistory) appState.chatHistory = {};
            appState.chatHistory[widgetId] = [];

            function appendBubble(role, content) {
                const bubble = document.createElement('div');
                bubble.className = 'chat-message ' + role + ' mb-2';
                const inner = document.createElement('div');
                inner.className = role === 'user'
                    ? 'bg-primary text-white rounded p-2 px-3 small'
                    : 'bg-light rounded p-2 px-3 small';
                if (role === 'assistant' && typeof marked !== 'undefined') {
                    let html = marked.parse(String(content));
                    html = html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
                    html = html.replace(/on\w+\s*=\s*["'][^"']*["']/gi, '');
                    inner.innerHTML = html;
                } else {
                    inner.textContent = content;
                }
                bubble.appendChild(inner);
                messagesEl.appendChild(bubble);
                messagesEl.scrollTop = messagesEl.scrollHeight;
                return bubble;
            }

            function showTyping() {
                const el = document.createElement('div');
                el.className = 'chat-message assistant mb-2';
                el.id = widgetId + '_typing';
                el.innerHTML = '<div class="bg-light rounded p-2 px-3 small"><span class="spinner-border spinner-border-sm me-1"></span>Thinking...</div>';
                messagesEl.appendChild(el);
                messagesEl.scrollTop = messagesEl.scrollHeight;
            }

            function removeTyping() {
                const el = document.getElementById(widgetId + '_typing');
                if (el) el.remove();
            }

            async function sendMessage(text) {
                const msg = text.trim();
                if (!msg) return;

                appendBubble('user', msg);
                appState.chatHistory[widgetId].push({ role: 'user', content: msg });

                input.value = '';
                input.disabled = true;
                form.querySelector('button[type=submit]').disabled = true;
                showTyping();

                try {
                    const result = await callPipeline(slug, {
                        message: msg,
                        history: appState.chatHistory[widgetId]
                    });
                    removeTyping();

                    const reply = result.success
                        ? (result.data?.reply || result.data?.message || result.data?.text || result.output?.reply || result.output?.message || result.output?.text || JSON.stringify(result.data || result.output || ''))
                        : (result.error || 'Sorry, something went wrong.');

                    appendBubble('assistant', reply);
                    appState.chatHistory[widgetId].push({ role: 'assistant', content: reply });
                } catch (e) {
                    removeTyping();
                    appendBubble('assistant', 'Connection error. Please try again.');
                    console.error('Chat pipeline error:', e);
                } finally {
                    input.disabled = false;
                    form.querySelector('button[type=submit]').disabled = false;
                    input.focus();
                }
            }

            form.addEventListener('submit', e => {
                e.preventDefault();
                sendMessage(input.value);
            });

            // Wire quick-action buttons
            widget.querySelectorAll('[data-quick-message]').forEach(btn => {
                btn.addEventListener('click', () => {
                    sendMessage(btn.dataset.quickMessage);
                });
            });
        });
    }
    -- old generateLayout inline JS removed, now uses getRuntimeJs() --
    */

    /**
     * Generate the auth page HTML for builder apps with authentication
     */
    public function generateAuthPage(array $securityConfig): string
    {
        $authType = $securityConfig['auth_type'] ?? 'none';
        $authConfig = $securityConfig['auth_config'] ?? [];

        if ($authType === 'knowledge') {
            return $this->generateKnowledgeAuthPage($authConfig);
        }
        if ($authType === 'email_link') {
            return $this->generateEmailLinkAuthPage($authConfig);
        }
        if ($authType === 'password') {
            return $this->generatePasswordAuthPage($authConfig);
        }

        return '';
    }

    private function generateKnowledgeAuthPage(array $config): string
    {
        $fields = $config['knowledge_fields'] ?? ['email', 'order_number'];

        $fieldsHtml = '';
        foreach ($fields as $field) {
            $label = ucwords(str_replace('_', ' ', $field));
            $type = stripos($field, 'email') !== false ? 'email' : 'text';
            $icon = stripos($field, 'email') !== false ? 'bi-envelope' : 'bi-hash';
            $placeholder = stripos($field, 'email') !== false ? 'you@example.com' : '#1001';
            $fieldsHtml .= <<<HTML
            <div class="mb-3">
                <label for="{$field}" class="form-label">{$label}</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi {$icon}"></i></span>
                    <input type="{$type}" class="form-control" id="{$field}" name="{$field}" placeholder="{$placeholder}" required>
                </div>
            </div>
HTML;
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Identity</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; }
        .verify-card { max-width: 420px; margin: 0 auto; }
    </style>
</head>
<body>
    <div class="container">
        <div class="verify-card">
            <div class="card shadow">
                <div class="card-body p-4">
                    <h4 class="text-center mb-4"><i class="bi bi-shield-check"></i> Verify Your Identity</h4>
                    <div id="errorAlert" class="alert alert-danger d-none"></div>
                    <form id="verifyForm">
                        {$fieldsHtml}
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary" id="verifyBtn">
                                <span class="btn-text"><i class="bi bi-check-lg me-1"></i>Verify</span>
                                <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-1"></span>Verifying...</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.getElementById('verifyForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('verifyBtn');
        const err = document.getElementById('errorAlert');
        btn.querySelector('.btn-text').classList.add('d-none');
        btn.querySelector('.btn-loading').classList.remove('d-none');
        btn.disabled = true;
        err.classList.add('d-none');

        const data = Object.fromEntries(new FormData(e.target));
        try {
            const resp = await fetch('/auth/verify', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const result = await resp.json();
            if (result.success) {
                window.location.href = result.redirect || '/';
            } else {
                err.textContent = result.error || 'Verification failed';
                err.classList.remove('d-none');
            }
        } catch (ex) {
            err.textContent = 'Connection error. Please try again.';
            err.classList.remove('d-none');
        }
        btn.querySelector('.btn-text').classList.remove('d-none');
        btn.querySelector('.btn-loading').classList.add('d-none');
        btn.disabled = false;
    });
    </script>
</body>
</html>
HTML;
    }

    private function generateEmailLinkAuthPage(array $config): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; }
    </style>
</head>
<body>
    <div class="container" style="max-width: 420px;">
        <div class="card shadow">
            <div class="card-body p-4">
                <h4 class="text-center mb-4"><i class="bi bi-envelope-check"></i> Login via Email</h4>
                <div id="errorAlert" class="alert alert-danger d-none"></div>
                <div id="successAlert" class="alert alert-success d-none"></div>
                <form id="emailForm">
                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control" id="email" name="email" placeholder="you@example.com" required>
                        </div>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send me-1"></i>Send Login Link
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }

    private function generatePasswordAuthPage(array $config): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; }
    </style>
</head>
<body>
    <div class="container" style="max-width: 420px;">
        <div class="card shadow">
            <div class="card-body p-4">
                <h4 class="text-center mb-4"><i class="bi bi-key"></i> Login</h4>
                <div id="errorAlert" class="alert alert-danger d-none"></div>
                <form id="loginForm">
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right me-1"></i>Login
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
