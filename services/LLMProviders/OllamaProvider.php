<?php
/**
 * Ollama LLM Provider
 *
 * Connects to a local or remote Ollama instance for inference.
 * https://ollama.ai/
 */

namespace app\services\LLMProviders;

class OllamaProvider implements LLMProviderInterface
{
    private string $host;
    private string $model;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->host = rtrim($this->config['host'], '/');
        $this->model = $this->config['model'];
    }

    public function getType(): string
    {
        return 'ollama';
    }

    public function getName(): string
    {
        return 'Ollama (Local)';
    }

    public function testConnection(): array
    {
        try {
            $response = $this->httpGet('/api/tags');

            if ($response['success']) {
                $data = json_decode($response['body'], true);
                $modelCount = count($data['models'] ?? []);
                return [
                    'success' => true,
                    'message' => "Connected - {$modelCount} models available",
                    'details' => [
                        'host' => $this->host,
                        'models' => array_map(fn($m) => $m['name'], $data['models'] ?? [])
                    ]
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to connect: ' . ($response['error'] ?? 'Unknown error'),
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
        try {
            $response = $this->httpGet('/api/tags');
            if ($response['success']) {
                $data = json_decode($response['body'], true);
                return array_map(fn($m) => $m['name'], $data['models'] ?? []);
            }
        } catch (\Exception $e) {
            // Ignore
        }
        return [];
    }

    public function complete(string $prompt, array $options = []): array
    {
        $payload = [
            'model' => $options['model'] ?? $this->model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => $options['temperature'] ?? $this->config['temperature'] ?? 0.7,
                'num_ctx' => $options['context_length'] ?? $this->config['context_length'] ?? 8192,
            ]
        ];

        if (isset($options['system'])) {
            $payload['system'] = $options['system'];
        }

        $response = $this->httpPost('/api/generate', $payload);

        if (!$response['success']) {
            return [
                'success' => false,
                'response' => '',
                'error' => $response['error'] ?? 'Request failed',
                'usage' => []
            ];
        }

        $data = json_decode($response['body'], true);

        return [
            'success' => true,
            'response' => $data['response'] ?? '',
            'usage' => [
                'prompt_tokens' => $data['prompt_eval_count'] ?? 0,
                'completion_tokens' => $data['eval_count'] ?? 0,
                'total_tokens' => ($data['prompt_eval_count'] ?? 0) + ($data['eval_count'] ?? 0),
                'eval_duration_ms' => ($data['eval_duration'] ?? 0) / 1000000
            ]
        ];
    }

    public function chat(array $messages, array $options = []): array
    {
        $payload = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $messages,
            'stream' => false,
            'options' => [
                'temperature' => $options['temperature'] ?? $this->config['temperature'] ?? 0.7,
                'num_ctx' => $options['context_length'] ?? $this->config['context_length'] ?? 8192,
            ]
        ];

        $response = $this->httpPost('/api/chat', $payload);

        if (!$response['success']) {
            return [
                'success' => false,
                'response' => '',
                'error' => $response['error'] ?? 'Request failed',
                'usage' => []
            ];
        }

        $data = json_decode($response['body'], true);

        return [
            'success' => true,
            'response' => $data['message']['content'] ?? '',
            'usage' => [
                'prompt_tokens' => $data['prompt_eval_count'] ?? 0,
                'completion_tokens' => $data['eval_count'] ?? 0,
                'total_tokens' => ($data['prompt_eval_count'] ?? 0) + ($data['eval_count'] ?? 0)
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
                'host' => [
                    'type' => 'string',
                    'title' => 'Ollama Host',
                    'description' => 'URL of the Ollama server',
                    'default' => 'http://localhost:11434'
                ],
                'model' => [
                    'type' => 'string',
                    'title' => 'Model',
                    'description' => 'The Ollama model to use',
                    'default' => 'llama3'
                ],
                'context_length' => [
                    'type' => 'integer',
                    'title' => 'Context Length',
                    'description' => 'Maximum context window size',
                    'default' => 8192,
                    'minimum' => 1024,
                    'maximum' => 131072
                ],
                'temperature' => [
                    'type' => 'number',
                    'title' => 'Temperature',
                    'description' => 'Sampling temperature (0.0 - 2.0)',
                    'default' => 0.7,
                    'minimum' => 0,
                    'maximum' => 2
                ]
            ],
            'required' => ['host', 'model']
        ];
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (empty($config['host'])) {
            $errors[] = 'Host is required';
        } elseif (!filter_var($config['host'], FILTER_VALIDATE_URL)) {
            $errors[] = 'Host must be a valid URL';
        }

        if (empty($config['model'])) {
            $errors[] = 'Model is required';
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
            'host' => 'http://localhost:11434',
            'model' => 'llama3',
            'context_length' => 8192,
            'temperature' => 0.7
        ];
    }

    // ========================================
    // HTTP Helpers
    // ========================================

    private function httpGet(string $path): array
    {
        $url = $this->host . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json']
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
        $url = $this->host . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 300, // 5 minutes for inference
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ]
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
