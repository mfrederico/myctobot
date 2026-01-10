<?php
/**
 * OpenAI LLM Provider
 *
 * Connects to OpenAI API or any OpenAI-compatible endpoint.
 */

namespace app\services\LLMProviders;

use app\services\EncryptionService;
use app\Bean;

class OpenAIProvider implements LLMProviderInterface
{
    private string $apiKey;
    private string $model;
    private string $endpoint;
    private ?string $organization;
    private array $config;

    public function __construct(array $config, ?int $memberId = null)
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);

        // Get API key - either direct or from settings
        if (!empty($config['api_key'])) {
            $this->apiKey = $config['api_key'];
        } elseif (!empty($config['api_key_setting']) && $memberId) {
            $this->apiKey = $this->getApiKeyFromSettings($config['api_key_setting'], $memberId);
        } else {
            $this->apiKey = '';
        }

        $this->model = $this->config['model'];
        $this->endpoint = rtrim($this->config['endpoint'] ?? 'https://api.openai.com/v1', '/');
        $this->organization = $this->config['organization'] ?? null;
    }

    private function getApiKeyFromSettings(string $settingKey, int $memberId): string
    {
        try {
            \app\services\UserDatabaseService::connect($memberId);
            $setting = Bean::findOne('enterprisesettings', 'setting_key = ?', [$settingKey]);
            if ($setting && $setting->setting_value) {
                return EncryptionService::decrypt($setting->setting_value);
            }
        } catch (\Exception $e) {
            // Ignore
        }
        return '';
    }

    public function getType(): string
    {
        return 'openai';
    }

    public function getName(): string
    {
        if ($this->endpoint !== 'https://api.openai.com/v1') {
            return 'OpenAI Compatible';
        }
        return 'OpenAI';
    }

    public function testConnection(): array
    {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'API key not configured',
                'details' => []
            ];
        }

        try {
            $response = $this->httpGet('/models');

            if ($response['success']) {
                $data = json_decode($response['body'], true);
                $models = $data['data'] ?? [];
                $modelCount = count($models);
                return [
                    'success' => true,
                    'message' => "Connected - {$modelCount} models available",
                    'details' => [
                        'endpoint' => $this->endpoint,
                        'models' => array_slice(array_map(fn($m) => $m['id'], $models), 0, 20)
                    ]
                ];
            }

            $error = json_decode($response['body'], true);
            return [
                'success' => false,
                'message' => 'API error: ' . ($error['error']['message'] ?? 'Unknown'),
                'details' => []
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection error: ' . $e->getMessage(),
                'details' => []
            ];
        }
    }

    public function getAvailableModels(): array
    {
        if (empty($this->apiKey)) {
            return ['gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo']; // Defaults
        }

        try {
            $response = $this->httpGet('/models');
            if ($response['success']) {
                $data = json_decode($response['body'], true);
                $models = array_map(fn($m) => $m['id'], $data['data'] ?? []);
                // Filter to chat models
                return array_filter($models, fn($m) => str_contains($m, 'gpt'));
            }
        } catch (\Exception $e) {
            // Ignore
        }
        return ['gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo'];
    }

    public function complete(string $prompt, array $options = []): array
    {
        // Convert to chat format
        return $this->chat([
            ['role' => 'user', 'content' => $prompt]
        ], $options);
    }

    public function chat(array $messages, array $options = []): array
    {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'response' => '',
                'error' => 'API key not configured',
                'usage' => []
            ];
        }

        $payload = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? $this->config['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? $this->config['max_tokens'] ?? 4096,
        ];

        if (isset($options['system'])) {
            array_unshift($payload['messages'], [
                'role' => 'system',
                'content' => $options['system']
            ]);
        }

        $response = $this->httpPost('/chat/completions', $payload);

        if (!$response['success']) {
            $error = json_decode($response['body'], true);
            return [
                'success' => false,
                'response' => '',
                'error' => $error['error']['message'] ?? 'Request failed',
                'usage' => []
            ];
        }

        $data = json_decode($response['body'], true);

        return [
            'success' => true,
            'response' => $data['choices'][0]['message']['content'] ?? '',
            'usage' => [
                'prompt_tokens' => $data['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $data['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $data['usage']['total_tokens'] ?? 0
            ]
        ];
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function canExposeAsMcp(): bool
    {
        return true;
    }

    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'endpoint' => [
                    'type' => 'string',
                    'title' => 'API Endpoint',
                    'description' => 'OpenAI API endpoint (or compatible)',
                    'default' => 'https://api.openai.com/v1'
                ],
                'api_key_setting' => [
                    'type' => 'string',
                    'title' => 'API Key Setting',
                    'description' => 'Name of the setting containing the encrypted API key',
                    'default' => 'openai_api_key'
                ],
                'model' => [
                    'type' => 'string',
                    'title' => 'Model',
                    'description' => 'The model to use',
                    'default' => 'gpt-4-turbo',
                    'enum' => ['gpt-4-turbo', 'gpt-4', 'gpt-4o', 'gpt-3.5-turbo', 'gpt-4o-mini']
                ],
                'max_tokens' => [
                    'type' => 'integer',
                    'title' => 'Max Tokens',
                    'description' => 'Maximum tokens in response',
                    'default' => 4096,
                    'minimum' => 1,
                    'maximum' => 128000
                ],
                'temperature' => [
                    'type' => 'number',
                    'title' => 'Temperature',
                    'description' => 'Sampling temperature (0.0 - 2.0)',
                    'default' => 0.7,
                    'minimum' => 0,
                    'maximum' => 2
                ],
                'organization' => [
                    'type' => 'string',
                    'title' => 'Organization ID',
                    'description' => 'Optional OpenAI organization ID'
                ]
            ],
            'required' => ['model']
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (empty($config['model'])) {
            $errors[] = 'Model is required';
        }

        if (empty($config['api_key']) && empty($config['api_key_setting'])) {
            $errors[] = 'Either api_key or api_key_setting is required';
        }

        if (isset($config['temperature']) && ($config['temperature'] < 0 || $config['temperature'] > 2)) {
            $errors[] = 'Temperature must be between 0 and 2';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'endpoint' => 'https://api.openai.com/v1',
            'model' => 'gpt-4-turbo',
            'max_tokens' => 4096,
            'temperature' => 0.7,
            'api_key_setting' => 'openai_api_key'
        ];
    }

    // ========================================
    // HTTP Helpers
    // ========================================

    private function httpGet(string $path): array
    {
        $url = $this->endpoint . $path;

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ];

        if ($this->organization) {
            $headers[] = 'OpenAI-Organization: ' . $this->organization;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => $error, 'body' => ''];
        }

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'body' => $body,
            'http_code' => $httpCode
        ];
    }

    private function httpPost(string $path, array $payload): array
    {
        $url = $this->endpoint . $path;

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ];

        if ($this->organization) {
            $headers[] = 'OpenAI-Organization: ' . $this->organization;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => $headers
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => $error, 'body' => ''];
        }

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'body' => $body,
            'http_code' => $httpCode
        ];
    }
}
