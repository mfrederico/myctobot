<?php
/**
 * TenantAppBuilder - Generates app server code from templates
 *
 * Takes a tenantapps bean and generates the complete server code
 * by substituting variables into the template files.
 */

namespace app\services;

use app\Bean;

class TenantAppBuilder
{
    /**
     * Base paths - dynamically detected
     */
    private static ?string $rootPath = null;

    private static function getRootPath(): string
    {
        if (self::$rootPath === null) {
            self::$rootPath = dirname(__DIR__);
        }
        return self::$rootPath;
    }

    private static function getTemplateDir(): string
    {
        return self::getRootPath() . '/templates/tenantapp';
    }

    private static function getStorageDir(): string
    {
        return self::getRootPath() . '/storage/apps';
    }

    /**
     * Build complete app directory structure
     *
     * @param int $appId The app ID
     * @return string Path to the app directory
     * @throws \RuntimeException If app not found or build fails
     */
    public function build(int $appId): string
    {
        $app = Bean::load('tenantapps', $appId);
        if (!$app || !$app->id) {
            throw new \RuntimeException("App not found: {$appId}");
        }

        $workspace = $app->workspace;
        $slug = $app->slug;
        $appDir = self::getStorageDir() . "/{$workspace}/{$slug}";

        // Create directory structure
        $this->ensureDirectories($appDir);

        // Generate files from templates
        $this->generateFile($appDir . '/server.php', $this->generateServer($app));
        $this->generateFile($appDir . '/config.php', $this->generateConfig($app));
        $this->generateFile($appDir . '/bootstrap.php', $this->generateBootstrap($app));

        return $appDir;
    }

    /**
     * Ensure all required directories exist
     */
    private function ensureDirectories(string $appDir): void
    {
        $dirs = [
            $appDir,
            $appDir . '/storage',
            $appDir . '/storage/logs',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    throw new \RuntimeException("Failed to create directory: {$dir}");
                }
            }
        }
    }

    /**
     * Write content to file
     */
    private function generateFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("Failed to write file: {$path}");
        }
    }

    /**
     * Generate server.php content
     */
    public function generateServer($app): string
    {
        $template = file_get_contents(self::getTemplateDir() . '/server.php.tpl');
        if ($template === false) {
            throw new \RuntimeException('Server template not found');
        }

        $replacements = $this->getCommonReplacements($app);
        $replacements['{{AUTH_HANDLER}}'] = $this->generateAuthHandler($app->auth_type, $app->api_key);
        $replacements['{{ROUTE_HANDLERS}}'] = $this->generateRouteHandlers($app);
        $replacements['{{HELPER_FUNCTIONS}}'] = $this->generateHelperFunctions();

        return strtr($template, $replacements);
    }

    /**
     * Generate helper functions for the server (form rendering, etc.)
     */
    private function generateHelperFunctions(): string
    {
        return <<<'PHP'
/**
 * Render the SPA form HTML
 */
function renderFormHtml(string $appName, string $appSlug, string $description, array $fields, ?string $apiKey = null): string
{
    $fieldsHtml = '';
    foreach ($fields as $field) {
        $required = $field['required'] ? 'required' : '';
        $requiredBadge = $field['required'] ? '<span class="text-danger">*</span>' : '';
        $inputType = 'text';
        $inputTag = 'input';

        // Map JSON schema types to HTML input types
        switch ($field['type']) {
            case 'integer':
            case 'number':
                $inputType = 'number';
                break;
            case 'boolean':
                $inputType = 'checkbox';
                break;
            case 'string':
            default:
                // Check for email/url patterns in name or description
                if (stripos($field['name'], 'email') !== false) {
                    $inputType = 'email';
                } elseif (stripos($field['name'], 'url') !== false || stripos($field['name'], 'repo') !== false) {
                    $inputType = 'url';
                } elseif (stripos($field['name'], 'password') !== false || stripos($field['name'], 'secret') !== false) {
                    $inputType = 'password';
                } elseif (stripos($field['name'], 'instruction') !== false || stripos($field['name'], 'description') !== false || stripos($field['name'], 'message') !== false) {
                    $inputTag = 'textarea';
                }
                break;
        }

        $fieldId = htmlspecialchars($field['name']);
        $fieldLabel = ucwords(str_replace(['_', '-'], ' ', $field['name']));
        $fieldDesc = htmlspecialchars($field['description']);
        $defaultValue = htmlspecialchars($field['default'] ?? '');

        if ($inputTag === 'textarea') {
            $fieldsHtml .= <<<HTML
            <div class="mb-3">
                <label for="{$fieldId}" class="form-label">{$fieldLabel} {$requiredBadge}</label>
                <textarea class="form-control" id="{$fieldId}" name="{$fieldId}" rows="3" {$required} placeholder="{$fieldDesc}">{$defaultValue}</textarea>
                <div class="form-text">{$fieldDesc}</div>
            </div>
HTML;
        } elseif ($inputType === 'checkbox') {
            $checked = $field['default'] ? 'checked' : '';
            $fieldsHtml .= <<<HTML
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="{$fieldId}" name="{$fieldId}" {$checked}>
                <label class="form-check-label" for="{$fieldId}">{$fieldLabel}</label>
                <div class="form-text">{$fieldDesc}</div>
            </div>
HTML;
        } else {
            $fieldsHtml .= <<<HTML
            <div class="mb-3">
                <label for="{$fieldId}" class="form-label">{$fieldLabel} {$requiredBadge}</label>
                <input type="{$inputType}" class="form-control" id="{$fieldId}" name="{$fieldId}" value="{$defaultValue}" {$required} placeholder="{$fieldDesc}">
                <div class="form-text">{$fieldDesc}</div>
            </div>
HTML;
        }
    }

    // If no fields defined, show a generic JSON input
    if (empty($fieldsHtml)) {
        $fieldsHtml = <<<HTML
        <div class="mb-3">
            <label for="jsonInput" class="form-label">Request Data (JSON)</label>
            <textarea class="form-control font-monospace" id="jsonInput" name="jsonInput" rows="5" placeholder='{"key": "value"}'>{}</textarea>
            <div class="form-text">Enter your request data as JSON</div>
        </div>
HTML;
    }

    $appNameHtml = htmlspecialchars($appName);
    $descriptionHtml = htmlspecialchars($description);
    $apiKeyJs = $apiKey ? "'" . addslashes($apiKey) . "'" : 'null';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>{$appNameHtml}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #6366f1;
            --primary-hover: #4f46e5;
        }
        * { -webkit-tap-highlight-color: transparent; }
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            min-height: -webkit-fill-available;
            padding: 16px;
        }
        html { height: -webkit-fill-available; }
        .app-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
            max-width: 500px;
            margin: 0 auto;
        }
        .app-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #8b5cf6 100%);
            color: white;
            padding: 20px;
        }
        .app-header h1 {
            font-size: 1.25rem;
            margin: 0;
            font-weight: 600;
        }
        .app-header p {
            margin: 6px 0 0 0;
            opacity: 0.9;
            font-size: 0.85rem;
        }
        .app-body { padding: 20px; }
        .btn-primary {
            background: var(--primary-color);
            border-color: var(--primary-color);
            padding: 14px 24px;
            font-weight: 500;
            font-size: 1rem;
        }
        .btn-primary:hover, .btn-primary:active {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        .form-control, .form-check-input {
            font-size: 16px; /* Prevents iOS zoom */
            padding: 12px;
        }
        .form-control:focus, .form-check-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(99, 102, 241, 0.25);
        }
        .form-label { font-weight: 500; }
        .form-text { font-size: 0.8rem; }
        #resultArea { display: none; margin-top: 20px; }
        #resultArea.show { display: block; }
        .result-success {
            background: #ecfdf5;
            border: 1px solid #10b981;
            border-radius: 12px;
            padding: 16px;
        }
        .result-error {
            background: #fef2f2;
            border: 1px solid #ef4444;
            border-radius: 12px;
            padding: 16px;
        }
        .result-pending {
            background: #fffbeb;
            border: 1px solid #f59e0b;
            border-radius: 12px;
            padding: 16px;
        }
        .spinner-border { width: 1rem; height: 1rem; }
        pre {
            background: #f8fafc;
            border-radius: 8px;
            padding: 12px;
            font-size: 0.8rem;
            max-height: 200px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .powered-by {
            text-align: center;
            padding: 16px;
            color: rgba(255,255,255,0.8);
            font-size: 0.8rem;
        }
        .powered-by a { color: white; }
        @media (max-width: 576px) {
            body { padding: 12px; }
            .app-header { padding: 16px; }
            .app-body { padding: 16px; }
        }
    </style>
</head>
<body>
    <div class="app-card">
        <div class="app-header">
            <h1><i class="bi bi-app-indicator me-2"></i>{$appNameHtml}</h1>
            <p>{$descriptionHtml}</p>
        </div>
        <div class="app-body">
            <form id="appForm" autocomplete="off">
                {$fieldsHtml}
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <span class="btn-text"><i class="bi bi-play-fill me-2"></i>Run</span>
                        <span class="btn-loading d-none"><span class="spinner-border me-2"></span>Processing...</span>
                    </button>
                </div>
            </form>

            <div id="resultArea">
                <h6 class="mb-3"><i class="bi bi-terminal me-2"></i>Result</h6>
                <div id="resultContent"></div>
            </div>
        </div>
    </div>
    <div class="powered-by">
        Powered by <a href="https://myctobot.ai" target="_blank">MyCTOBot</a>
    </div>

    <script>
        const form = document.getElementById('appForm');
        const submitBtn = document.getElementById('submitBtn');
        const resultArea = document.getElementById('resultArea');
        const resultContent = document.getElementById('resultContent');
        const apiKey = {$apiKeyJs};

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            submitBtn.disabled = true;
            submitBtn.querySelector('.btn-text').classList.add('d-none');
            submitBtn.querySelector('.btn-loading').classList.remove('d-none');
            resultArea.classList.remove('show');

            let data = {};
            const jsonInput = document.getElementById('jsonInput');

            if (jsonInput) {
                try {
                    data = JSON.parse(jsonInput.value || '{}');
                } catch (err) {
                    showResult('error', 'Invalid JSON: ' + err.message);
                    resetButton();
                    return;
                }
            } else {
                const formData = new FormData(form);
                for (let [key, value] of formData.entries()) {
                    const input = document.getElementById(key);
                    if (input && input.type === 'checkbox') {
                        data[key] = input.checked;
                    } else if (input && input.type === 'number') {
                        data[key] = value ? Number(value) : null;
                    } else {
                        data[key] = value;
                    }
                }
            }

            try {
                const headers = { 'Content-Type': 'application/json' };
                if (apiKey) headers['X-API-Key'] = apiKey;

                const response = await fetch('/run', {
                    method: 'POST',
                    headers: headers,
                    body: JSON.stringify(data)
                });

                const result = await response.json();

                if (result.success) {
                    showResult('success', result);
                } else if (result.error) {
                    showResult('error', result);
                } else {
                    showResult('pending', result);
                }
            } catch (err) {
                showResult('error', { error: 'Request failed', message: err.message });
            }

            resetButton();
        });

        function showResult(type, data) {
            const icons = {
                success: '<i class="bi bi-check-circle-fill text-success me-2"></i>',
                error: '<i class="bi bi-x-circle-fill text-danger me-2"></i>',
                pending: '<i class="bi bi-hourglass-split text-warning me-2"></i>'
            };
            let html = '<div class="result-' + type + '">';

            if (typeof data === 'string') {
                html += icons[type] + escapeHtml(data);
            } else {
                const title = data.success ? 'Success' : (data.error || 'Result');
                html += '<strong>' + icons[type] + escapeHtml(title) + '</strong>';
                if (data.message) html += '<p class="mb-2 mt-2">' + escapeHtml(data.message) + '</p>';
                if (data.run_id) html += '<p class="mb-2"><small class="text-muted">Run ID: ' + data.run_id + '</small></p>';
                if (data.output !== undefined && data.output !== null && data.output !== true) {
                    html += '<pre>' + escapeHtml(JSON.stringify(data.output, null, 2)) + '</pre>';
                }
                if (data.missing_fields) html += '<p class="mb-0"><strong>Missing:</strong> ' + data.missing_fields.join(', ') + '</p>';
            }

            html += '</div>';
            resultContent.innerHTML = html;
            resultArea.classList.add('show');
            resultArea.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function resetButton() {
            submitBtn.disabled = false;
            submitBtn.querySelector('.btn-text').classList.remove('d-none');
            submitBtn.querySelector('.btn-loading').classList.add('d-none');
        }

        function escapeHtml(text) {
            if (typeof text !== 'string') return String(text);
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
HTML;
}
PHP;
    }

    /**
     * Generate config.php content
     */
    public function generateConfig($app): string
    {
        $template = file_get_contents(self::getTemplateDir() . '/config.php.tpl');
        if ($template === false) {
            throw new \RuntimeException('Config template not found');
        }

        $routes = json_decode($app->routes_json ?: '[]', true) ?: [];
        $mcpTools = json_decode($app->mcp_tools_json ?: '[]', true) ?: [];

        $replacements = $this->getCommonReplacements($app);
        $replacements['{{HOST}}'] = '127.0.0.1';
        $replacements['{{PORT}}'] = (string)($app->port ?: 9600);
        $replacements['{{WORKERS}}'] = '2';
        $replacements['{{APP_ID}}'] = (string)$app->id;
        $replacements['{{AUTH_TYPE}}'] = $app->auth_type ?: 'none';
        $replacements['{{API_KEY}}'] = $app->api_key ?: '';
        $replacements['{{PIPELINE_ID}}'] = $app->pipelines_id ? (string)$app->pipelines_id : 'null';
        $replacements['{{APP_TYPE}}'] = $app->app_type ?: 'pipeline';
        $replacements['{{DEBUG}}'] = 'true';  // TODO: Make configurable
        $replacements['{{ROUTES_JSON}}'] = var_export($routes, true);
        $replacements['{{EXPOSE_MCP}}'] = $app->expose_mcp ? 'true' : 'false';
        $replacements['{{MCP_TOOLS_JSON}}'] = var_export($mcpTools, true);

        return strtr($template, $replacements);
    }

    /**
     * Generate bootstrap.php content
     */
    public function generateBootstrap($app): string
    {
        $template = file_get_contents(self::getTemplateDir() . '/bootstrap.php.tpl');
        if ($template === false) {
            throw new \RuntimeException('Bootstrap template not found');
        }

        $replacements = $this->getCommonReplacements($app);
        return strtr($template, $replacements);
    }

    /**
     * Get common template replacements
     */
    private function getCommonReplacements($app): array
    {
        return [
            '{{APP_NAME}}' => $app->name,
            '{{APP_SLUG}}' => $app->slug,
            '{{WORKSPACE}}' => $app->workspace,
            '{{GENERATED_AT}}' => date('Y-m-d H:i:s'),
            '{{HEALTH_PATH}}' => $app->health_check_path ?: '/health',
            '{{MYCTOBOT_ROOT}}' => self::getRootPath(),
        ];
    }

    /**
     * Generate authentication handler code
     */
    public function generateAuthHandler(string $authType, ?string $apiKey): string
    {
        switch ($authType) {
            case 'none':
                return '// No authentication required';

            case 'api_key':
                $escapedKey = addslashes($apiKey ?? '');
                return <<<PHP
    // API Key Authentication
    \$providedKey = getApiKey(\$request);
    \$expectedKey = '{$escapedKey}';

    if (empty(\$expectedKey)) {
        // No API key configured - allow access
    } elseif (\$providedKey !== \$expectedKey) {
        \$logger->warning('Invalid API key', ['ip' => \$ip, 'path' => \$path]);
        \$response->status(401);
        \$response->end(json_encode([
            'error' => 'Unauthorized',
            'message' => 'Invalid or missing API key. Provide via X-API-Key header or api_key query parameter.',
        ]));
        return;
    }
PHP;

            case 'bearer':
                return <<<'PHP'
    // Bearer Token Authentication
    $token = getBearerToken($request);
    if (empty($token)) {
        $logger->warning('Missing bearer token', ['ip' => $ip, 'path' => $path]);
        $response->status(401);
        $response->end(json_encode([
            'error' => 'Unauthorized',
            'message' => 'Bearer token required.',
        ]));
        return;
    }

    // Validate token against apitokens table
    $tokenBean = \app\Bean::findOne('apitokens', ' token = ? AND is_active = 1 ', [$token]);
    if (!$tokenBean) {
        $logger->warning('Invalid bearer token', ['ip' => $ip, 'path' => $path]);
        $response->status(401);
        $response->end(json_encode([
            'error' => 'Unauthorized',
            'message' => 'Invalid bearer token.',
        ]));
        return;
    }
PHP;

            default:
                return '// Unknown auth type - no authentication';
        }
    }

    /**
     * Generate route handlers
     */
    public function generateRouteHandlers($app): string
    {
        $handlers = [];

        // Root endpoint - app info
        $handlers[] = $this->generateRootHandler($app);

        // Pipeline execution endpoint (if pipeline-backed)
        if ($app->app_type === 'pipeline' && $app->pipelines_id) {
            $handlers[] = $this->generatePipelineHandler($app);
        }

        // Custom routes from routes_json
        $customRoutes = json_decode($app->routes_json ?: '[]', true) ?: [];
        foreach ($customRoutes as $route) {
            if (!empty($route['path']) && !empty($route['handler'])) {
                $handlers[] = $this->generateCustomRouteHandler($route);
            }
        }

        // MCP endpoint (if exposed)
        if ($app->expose_mcp) {
            $handlers[] = $this->generateMcpHandler($app);
        }

        return implode("\n\n", $handlers);
    }

    /**
     * Generate root endpoint handler - SPA form UI
     */
    private function generateRootHandler($app): string
    {
        $name = addslashes($app->name);
        $slug = addslashes($app->slug);
        $description = addslashes($app->description ?: '');
        $pipelineId = $app->pipelines_id ? (int)$app->pipelines_id : 0;

        return <<<PHP
        // Root endpoint - SPA form UI
        if (\$path === '/' && \$method === 'GET') {
            // Check if JSON requested (API mode)
            \$acceptHeader = \$request->header['accept'] ?? '';
            if (strpos(\$acceptHeader, 'application/json') !== false) {
                \$response->end(json_encode([
                    'app' => '{$slug}',
                    'name' => '{$name}',
                    'description' => '{$description}',
                    'endpoints' => [
                        'GET /' => 'App form UI (or JSON with Accept: application/json)',
                        'GET /health' => 'Health check',
                        'POST /run' => 'Execute pipeline',
                    ],
                ]));
                return;
            }

            // Load pipeline to get input schema
            \$formFields = [];
            \$pipelineId = {$pipelineId};
            if (\$pipelineId) {
                \$pipeline = \\app\\Bean::load('pipelines', \$pipelineId);
                if (\$pipeline && \$pipeline->input_schema_json) {
                    \$schema = json_decode(\$pipeline->input_schema_json, true) ?: [];
                    \$properties = \$schema['properties'] ?? [];
                    \$required = \$schema['required'] ?? [];

                    foreach (\$properties as \$fieldName => \$fieldDef) {
                        \$formFields[] = [
                            'name' => \$fieldName,
                            'type' => \$fieldDef['type'] ?? 'string',
                            'description' => \$fieldDef['description'] ?? '',
                            'required' => in_array(\$fieldName, \$required),
                            'default' => \$fieldDef['default'] ?? '',
                        ];
                    }
                }
            }

            // Render HTML SPA
            \$response->header('Content-Type', 'text/html; charset=utf-8');
            \$html = renderFormHtml('{$name}', '{$slug}', '{$description}', \$formFields, \$config['api_key'] ?? null);
            \$response->end(\$html);
            return;
        }
PHP;
    }

    /**
     * Generate pipeline execution handler
     *
     * CRITICAL: PipelineExecutor builds context from trigger_data_json (not input_json).
     * User input must be placed in trigger_data_json for pipeline steps to access
     * via {context.key} substitution.
     */
    private function generatePipelineHandler($app): string
    {
        $pipelineId = (int)$app->pipelines_id;
        $slug = addslashes($app->slug);
        $workspace = addslashes($app->workspace);

        return <<<PHP
        // Pipeline execution endpoint
        if (\$path === '/run' && \$method === 'POST') {
            \$body = parseJsonBody(\$request);

            // Validate JSON (OpenSwoole uses getContent(), Swoole uses rawContent())
            \$rawBody = method_exists(\$request, 'getContent') ? \$request->getContent() : \$request->rawContent();
            if (\$body === null && !empty(\$rawBody)) {
                \$response->status(400);
                \$response->end(json_encode([
                    'error' => 'Invalid JSON',
                    'message' => 'Request body must be valid JSON',
                ]));
                return;
            }

            // Set workspace for PipelineExecutor (it checks \$_SERVER['WORKSPACE'])
            \$_SERVER['WORKSPACE'] = '{$workspace}';

            // Load pipeline to get default context and input schema
            \$pipeline = \\app\\Bean::load('pipelines', {$pipelineId});
            if (!\$pipeline || !\$pipeline->id) {
                \$response->status(500);
                \$response->end(json_encode([
                    'error' => 'Pipeline not found',
                    'message' => 'Pipeline ID {$pipelineId} does not exist',
                ]));
                return;
            }

            // Validate input against pipeline's input schema (if defined)
            \$inputSchema = json_decode(\$pipeline->input_schema_json ?: '{}', true) ?: [];
            if (!empty(\$inputSchema['required']) && is_array(\$inputSchema['required'])) {
                \$missingFields = [];
                foreach (\$inputSchema['required'] as \$field) {
                    if (!isset(\$body[\$field]) || \$body[\$field] === '') {
                        \$missingFields[] = \$field;
                    }
                }
                if (!empty(\$missingFields)) {
                    \$response->status(400);
                    \$response->end(json_encode([
                        'error' => 'Missing required fields',
                        'message' => 'The following fields are required: ' . implode(', ', \$missingFields),
                        'missing_fields' => \$missingFields,
                        'schema' => \$inputSchema,
                    ]));
                    return;
                }
            }

            // Create pipeline run record
            \$runUid = 'run-' . bin2hex(random_bytes(8));
            \$run = \\app\\Bean::dispense('pipelineruns');
            \$run->pipelines_id = {$pipelineId};
            \$run->run_uid = \$runUid;
            \$run->status = 'pending';
            \$run->trigger_type = 'app';
            \$run->trigger_source = 'tenant_app';

            // Store raw user input
            \$run->input_json = json_encode(\$body ?: []);

            // CRITICAL: Put user input in trigger_data_json
            // PipelineExecutor uses trigger_data_json to build context (not input_json)
            \$run->trigger_data_json = json_encode(\$body ?: []);

            // Build context: merge pipeline default context with user input and app metadata
            \$defaultContext = json_decode(\$pipeline->default_context_json ?: '{}', true) ?: [];
            \$userInput = \$body ?: [];
            \$appMeta = [
                'app_slug' => '{$slug}',
                'workspace' => '{$workspace}',
                'source_ip' => \$ip,
                'run_uid' => \$runUid,
            ];
            \$run->context_json = json_encode(array_merge(\$defaultContext, \$userInput, \$appMeta));
            \$run->created_at = date('Y-m-d H:i:s');
            \\app\\Bean::store(\$run);

            \$logger->info('Starting pipeline execution', [
                'run_id' => \$run->id,
                'pipeline_id' => {$pipelineId},
                'user_input_keys' => array_keys(\$body ?: []),
            ]);

            try {
                // Execute pipeline
                \$executor = new PipelineExecutor((int)\$run->id, \$logger);
                \$result = \$executor->execute();

                // Reload run to get final status
                \$run = \\app\\Bean::load('pipelineruns', (int)\$run->id);

                \$response->end(json_encode([
                    'success' => \$run->status === 'completed',
                    'run_id' => \$run->id,
                    'status' => \$run->status,
                    'output' => \$result,
                ]));
                return;

            } catch (\\Throwable \$e) {
                \$logger->error('Pipeline execution failed', [
                    'run_id' => \$run->id,
                    'error' => \$e->getMessage(),
                ]);

                // Update run status
                \$run->status = 'failed';
                \$run->error_message = \$e->getMessage();
                \$run->completed_at = date('Y-m-d H:i:s');
                \\app\\Bean::store(\$run);

                \$response->status(500);
                \$response->end(json_encode([
                    'success' => false,
                    'run_id' => \$run->id,
                    'error' => \$e->getMessage(),
                ]));
                return;
            }
        }
PHP;
    }

    /**
     * Generate custom route handler
     */
    private function generateCustomRouteHandler(array $route): string
    {
        $path = addslashes($route['path']);
        $methods = is_array($route['methods'] ?? null)
            ? $route['methods']
            : [$route['method'] ?? 'GET'];
        $methodsStr = "'" . implode("', '", $methods) . "'";
        $handler = $route['handler'];

        return <<<PHP
        // Custom route: {$path}
        if (\$path === '{$path}' && in_array(\$method, [{$methodsStr}])) {
            {$handler}
            return;
        }
PHP;
    }

    /**
     * Generate MCP endpoint handler
     */
    private function generateMcpHandler($app): string
    {
        return <<<'PHP'
        // MCP JSON-RPC endpoint
        if ($path === '/mcp' && $method === 'POST') {
            $body = parseJsonBody($request);

            if (!$body || !isset($body['jsonrpc']) || $body['jsonrpc'] !== '2.0') {
                $response->end(json_encode([
                    'jsonrpc' => '2.0',
                    'error' => [
                        'code' => -32600,
                        'message' => 'Invalid Request',
                    ],
                    'id' => $body['id'] ?? null,
                ]));
                return;
            }

            $rpcMethod = $body['method'] ?? '';
            $rpcParams = $body['params'] ?? [];
            $rpcId = $body['id'] ?? null;

            // Handle MCP methods
            switch ($rpcMethod) {
                case 'initialize':
                    $response->end(json_encode([
                        'jsonrpc' => '2.0',
                        'result' => [
                            'protocolVersion' => '2024-11-05',
                            'serverInfo' => [
                                'name' => $config['app_name'],
                                'version' => '1.0.0',
                            ],
                            'capabilities' => [
                                'tools' => new \stdClass(),
                            ],
                        ],
                        'id' => $rpcId,
                    ]));
                    return;

                case 'tools/list':
                    $tools = $config['mcp_tools'] ?? [];
                    $response->end(json_encode([
                        'jsonrpc' => '2.0',
                        'result' => [
                            'tools' => $tools,
                        ],
                        'id' => $rpcId,
                    ]));
                    return;

                case 'tools/call':
                    // TODO: Implement tool execution
                    $response->end(json_encode([
                        'jsonrpc' => '2.0',
                        'error' => [
                            'code' => -32601,
                            'message' => 'Tool execution not implemented',
                        ],
                        'id' => $rpcId,
                    ]));
                    return;

                default:
                    $response->end(json_encode([
                        'jsonrpc' => '2.0',
                        'error' => [
                            'code' => -32601,
                            'message' => 'Method not found',
                        ],
                        'id' => $rpcId,
                    ]));
                    return;
            }
        }
PHP;
    }

    /**
     * Get the app directory path
     */
    public function getAppDir(string $workspace, string $slug): string
    {
        return self::getStorageDir() . "/{$workspace}/{$slug}";
    }

    /**
     * Check if app has been built
     */
    public function isBuilt(string $workspace, string $slug): bool
    {
        $appDir = $this->getAppDir($workspace, $slug);
        return file_exists($appDir . '/server.php');
    }

    /**
     * Clean up app directory
     */
    public function cleanup(string $workspace, string $slug): bool
    {
        $appDir = $this->getAppDir($workspace, $slug);
        if (!is_dir($appDir)) {
            return true;
        }

        // Recursively delete directory
        $this->recursiveDelete($appDir);
        return !is_dir($appDir);
    }

    /**
     * Recursively delete a directory
     */
    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
