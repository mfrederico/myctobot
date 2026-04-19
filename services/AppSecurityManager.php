<?php
/**
 * AppSecurityManager - Handles auth, pipeline whitelisting, and input scoping for builder apps
 *
 * Three security layers:
 * 1. Authentication - Who are you? (none, knowledge, email_link, password, shopify_customer)
 * 2. Pipeline Authorization - What can you call? (whitelist enforcement)
 * 3. Data Scoping - What data can the pipeline see? (injected fields override client input)
 */

namespace app\services;

class AppSecurityManager
{
    /**
     * Available auth types and their descriptions
     */
    public const AUTH_TYPES = [
        'none' => [
            'label' => 'Public Access',
            'description' => 'No authentication required',
            'icon' => 'bi-unlock',
        ],
        'knowledge' => [
            'label' => 'Knowledge Verification',
            'description' => 'Verify identity with order # + email',
            'icon' => 'bi-shield-check',
        ],
        'email_link' => [
            'label' => 'Magic Link',
            'description' => 'Email-based login via magic link',
            'icon' => 'bi-envelope-check',
        ],
        'password' => [
            'label' => 'Password Login',
            'description' => 'Traditional username/password authentication',
            'icon' => 'bi-key',
        ],
        'shopify_customer' => [
            'label' => 'Shopify Customer',
            'description' => 'Authenticate via Shopify Customer Accounts',
            'icon' => 'bi-shop',
        ],
    ];

    /**
     * Input classification types
     */
    public const INPUT_SOURCES = [
        'auth' => ['label' => 'Auth Session', 'icon' => 'bi-lock-fill', 'overridable' => false],
        'app' => ['label' => 'App Config', 'icon' => 'bi-gear-fill', 'overridable' => false],
        'request' => ['label' => 'User Input', 'icon' => 'bi-pencil', 'overridable' => true],
        'state' => ['label' => 'Client State', 'icon' => 'bi-hdd', 'overridable' => true],
    ];

    /**
     * Get default security config for a new builder app
     */
    public static function getDefaultConfig(): array
    {
        return [
            'auth_type' => 'none',
            'auth_config' => [
                'verification_pipeline' => '',
                'identity_fields' => ['email'],
                'knowledge_fields' => ['order_number', 'email'],
                'session_ttl' => 3600,
            ],
            'allowed_pipelines' => [],
            'pipeline_scoping' => [
                'injected_fields' => [],
            ],
        ];
    }

    /**
     * Validate a security config
     */
    public static function validateConfig(array $config): array
    {
        $errors = [];

        $authType = $config['auth_type'] ?? 'none';
        if (!isset(self::AUTH_TYPES[$authType])) {
            $errors[] = "Invalid auth_type: {$authType}";
        }

        if ($authType === 'knowledge') {
            $authConfig = $config['auth_config'] ?? [];
            if (empty($authConfig['knowledge_fields'])) {
                $errors[] = 'Knowledge auth requires at least one knowledge_field';
            }
        }

        if ($authType === 'email_link') {
            $authConfig = $config['auth_config'] ?? [];
            if (empty($authConfig['session_ttl'])) {
                $errors[] = 'Email link auth requires session_ttl';
            }
        }

        // Validate pipeline scoping
        $scoping = $config['pipeline_scoping']['injected_fields'] ?? [];
        foreach ($scoping as $field => $source) {
            if (!preg_match('/^\{(auth|app|request|state)\.\w+\}$/', $source)) {
                $errors[] = "Invalid scoping source for '{$field}': {$source}. Must be {auth.field}, {app.field}, {request.field}, or {state.field}";
            }
        }

        return $errors;
    }

    /**
     * Apply input scoping to pipeline input data
     *
     * Server-side injection: auth and app fields OVERRIDE any client values.
     * This prevents users from accessing other users' data.
     */
    public static function scopeInput(array $clientInput, array $securityConfig, array $authSession, array $appConfig): array
    {
        $scoped = $clientInput;
        $injectedFields = $securityConfig['pipeline_scoping']['injected_fields'] ?? [];

        foreach ($injectedFields as $field => $source) {
            if (preg_match('/^\{(auth|app)\.(\w+)\}$/', $source, $matches)) {
                $sourceType = $matches[1];
                $sourceKey = $matches[2];

                if ($sourceType === 'auth') {
                    $scoped[$field] = $authSession[$sourceKey] ?? null;
                } elseif ($sourceType === 'app') {
                    $scoped[$field] = $appConfig[$sourceKey] ?? null;
                }
            }
            // request and state sources are user-controlled, don't override
        }

        return $scoped;
    }

    /**
     * Check if a pipeline slug is allowed for this app
     */
    public static function isPipelineAllowed(string $pipelineSlug, array $securityConfig): bool
    {
        $allowed = $securityConfig['allowed_pipelines'] ?? [];
        return in_array($pipelineSlug, $allowed, true);
    }

    /**
     * Generate PHP code for auth middleware in the generated app server
     */
    public static function generateAuthMiddleware(array $securityConfig): string
    {
        $authType = $securityConfig['auth_type'] ?? 'none';
        $authConfig = $securityConfig['auth_config'] ?? [];
        $sessionTtl = (int) ($authConfig['session_ttl'] ?? 3600);

        if ($authType === 'none') {
            return <<<'PHP'
    // No authentication required
    $authSession = [];
PHP;
        }

        return <<<PHP
    // Authentication: {$authType}
    \$authSession = [];
    \$sessionToken = \$request->cookie['app_session'] ?? \$request->get['session_token'] ?? null;

    if (\$sessionToken) {
        \$session = \\app\\Bean::findOne('customersessions', 'session_token = ? AND verification_status = ?', [\$sessionToken, 'verified']);
        if (\$session && \$session->expires_at && strtotime(\$session->expires_at) > time()) {
            \$context = json_decode(\$session->context_json ?: '{}', true) ?: [];
            \$authSession = [
                'session_id' => \$session->id,
                'email' => \$session->email,
                'customer_id' => \$context['customer_id'] ?? null,
                'verified_at' => \$session->verified_at,
                'context' => \$context,
            ];
        }
    }

    // Protected routes require authentication
    \$publicPaths = ['/health', '/auth/verify', '/auth/login', '/auth/callback', '/auth/check'];
    if (empty(\$authSession) && !in_array(\$path, \$publicPaths) && strpos(\$path, '/assets/') !== 0) {
        if (strpos(\$request->header['accept'] ?? '', 'application/json') !== false) {
            \$response->status(401);
            \$response->end(json_encode(['error' => 'Authentication required']));
            return;
        }
        \$response->status(302);
        \$response->header('Location', '/auth/verify');
        \$response->end();
        return;
    }
PHP;
    }

    /**
     * Generate PHP code for the pipeline proxy with scoping
     */
    public static function generatePipelineProxy(array $securityConfig, string $workspace, string $apiToken): string
    {
        $allowedJson = json_encode($securityConfig['allowed_pipelines'] ?? []);
        $injectedJson = json_encode($securityConfig['pipeline_scoping']['injected_fields'] ?? []);

        return <<<PHP
    // Pipeline proxy endpoint with security scoping
    if (\$path === '/api/pipeline' && \$method === 'POST') {
        \$body = parseJsonBody(\$request);
        \$pipelineSlug = \$body['pipeline'] ?? '';
        \$input = \$body['input'] ?? [];

        // Layer 2: Pipeline whitelist
        \$allowed = json_decode('{$allowedJson}', true);
        if (!empty(\$allowed) && !in_array(\$pipelineSlug, \$allowed, true)) {
            \$response->status(403);
            \$response->end(json_encode(['error' => 'Pipeline not available']));
            return;
        }

        // Layer 3: Inject scoped fields (OVERRIDE client values)
        \$injected = json_decode('{$injectedJson}', true);
        foreach (\$injected as \$field => \$source) {
            if (preg_match('/^\{auth\\.(\w+)\}$/', \$source, \$m)) {
                \$input[\$field] = \$authSession[\$m[1]] ?? null;
            } elseif (preg_match('/^\{app\\.(\w+)\}$/', \$source, \$m)) {
                \$input[\$field] = \$config[\$m[1]] ?? null;
            }
        }

        // Make the API call
        \$apiUrl = "https://{$workspace}.myctobot.ai/api/pipelines/" . urlencode(\$pipelineSlug) . "/run?sync=true";
        \$ch = curl_init(\$apiUrl);
        curl_setopt_array(\$ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(\$input),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer {$apiToken}',
                'X-Workspace: {$workspace}',
            ],
            CURLOPT_TIMEOUT => 300,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        \$apiResponse = curl_exec(\$ch);
        \$httpCode = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);
        curl_close(\$ch);

        \$response->status(\$httpCode ?: 502);
        \$response->end(\$apiResponse ?: json_encode(['error' => 'Pipeline call failed']));
        return;
    }
PHP;
    }

    /**
     * Generate PHP code for auth verification endpoint (knowledge-based)
     */
    public static function generateVerifyEndpoint(array $securityConfig, string $workspace): string
    {
        $authType = $securityConfig['auth_type'] ?? 'none';

        if ($authType === 'none') {
            return '';
        }

        $verifyPipeline = $securityConfig['auth_config']['verification_pipeline'] ?? 'verify-customer';
        $sessionTtl = (int) ($securityConfig['auth_config']['session_ttl'] ?? 3600);

        return <<<PHP
    // Auth verification endpoint
    if (\$path === '/auth/verify' && \$method === 'GET') {
        \$response->header('Content-Type', 'text/html; charset=utf-8');
        \$response->end(renderAuthPage(\$config));
        return;
    }

    if (\$path === '/auth/verify' && \$method === 'POST') {
        \$body = parseJsonBody(\$request);

        // Call verification pipeline
        \$apiUrl = "https://{$workspace}.myctobot.ai/api/pipelines/{$verifyPipeline}/run?sync=true";
        \$ch = curl_init(\$apiUrl);
        curl_setopt_array(\$ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(\$body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . (\$config['api_token'] ?? ''),
                'X-Workspace: {$workspace}',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        \$apiResponse = curl_exec(\$ch);
        \$httpCode = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);
        curl_close(\$ch);

        \$result = json_decode(\$apiResponse, true);

        if (\$httpCode === 200 && (\$result['success'] ?? false)) {
            // Create verified session
            \$session = \\app\\Bean::dispense('customersessions');
            \$session->email = \$body['email'] ?? '';
            \$session->verification_status = 'verified';
            \$session->verified_at = date('Y-m-d H:i:s');
            \$session->expires_at = date('Y-m-d H:i:s', time() + {$sessionTtl});
            \$context = \$result['data'] ?? [];
            \$context['auth_type'] = '{$authType}';
            \$session->context_json = json_encode(\$context);
            \\app\\Bean::store(\$session);

            \$response->cookie('app_session', \$session->session_token, time() + {$sessionTtl}, '/');
            \$response->end(json_encode(['success' => true, 'redirect' => '/']));
        } else {
            \$response->status(401);
            \$response->end(json_encode([
                'success' => false,
                'error' => \$result['error'] ?? 'Verification failed',
            ]));
        }
        return;
    }

    // Check auth status
    if (\$path === '/auth/check') {
        \$response->end(json_encode([
            'authenticated' => !empty(\$authSession),
            'email' => \$authSession['email'] ?? null,
        ]));
        return;
    }

    // Logout
    if (\$path === '/auth/logout') {
        \$response->cookie('app_session', '', time() - 3600, '/');
        \$response->status(302);
        \$response->header('Location', '/auth/verify');
        \$response->end();
        return;
    }
PHP;
    }

    /**
     * Get config schema for a specific auth type (for builder UI)
     */
    public static function getAuthConfigSchema(string $authType): array
    {
        $schemas = [
            'none' => [],
            'knowledge' => [
                'verification_pipeline' => ['type' => 'string', 'label' => 'Verification Pipeline', 'required' => true, 'description' => 'Pipeline slug that verifies customer identity'],
                'knowledge_fields' => ['type' => 'json', 'label' => 'Verification Fields', 'description' => 'Fields the customer must provide (e.g., email, order_number)'],
                'identity_fields' => ['type' => 'json', 'label' => 'Identity Fields', 'description' => 'Fields stored in session after verification'],
                'session_ttl' => ['type' => 'integer', 'label' => 'Session Duration (seconds)', 'default' => 3600],
            ],
            'email_link' => [
                'session_ttl' => ['type' => 'integer', 'label' => 'Session Duration (seconds)', 'default' => 3600],
                'token_ttl' => ['type' => 'integer', 'label' => 'Link Expiry (seconds)', 'default' => 900],
            ],
            'password' => [
                'session_ttl' => ['type' => 'integer', 'label' => 'Session Duration (seconds)', 'default' => 3600],
            ],
            'shopify_customer' => [
                'connection_id' => ['type' => 'integer', 'label' => 'Shopify Connection', 'required' => true],
                'session_ttl' => ['type' => 'integer', 'label' => 'Session Duration (seconds)', 'default' => 3600],
            ],
        ];

        return $schemas[$authType] ?? [];
    }
}
