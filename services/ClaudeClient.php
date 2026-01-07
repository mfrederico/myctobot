<?php
/**
 * Claude AI Client Service
 * Handles communication with Anthropic Claude API
 */

namespace app\services;

use \Flight as Flight;
use GuzzleHttp\Client;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

class ClaudeClient {
    private Client $client;
    private string $apiKey;
    private string $model;
    private ?Logger $apiLogger = null;

    /**
     * @param string|null $apiKey Optional API key override (uses Flight config if null)
     * @param string|null $model Optional model override (uses Flight config if null)
     */
    public function __construct(?string $apiKey = null, ?string $model = null) {
        $this->apiKey = $apiKey ?? Flight::get('anthropic.api_key');
        $this->model = $model ?? Flight::get('anthropic.model') ?? 'claude-sonnet-4-20250514';

        if (empty($this->apiKey)) {
            throw new \Exception('Anthropic API key not configured');
        }

        $this->client = new Client([
            'base_uri' => 'https://api.anthropic.com',
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
        ]);

        $this->initApiLogger();
    }

    /**
     * Initialize dedicated API logger
     */
    private function initApiLogger(): void {
        try {
            $logDir = dirname(__DIR__) . '/log';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            $this->apiLogger = new Logger('claude_api');
            $handler = new RotatingFileHandler($logDir . '/claude_api.log', 30, Logger::DEBUG);
            $formatter = new LineFormatter(
                "[%datetime%] %message% %context%\n",
                "Y-m-d H:i:s"
            );
            $handler->setFormatter($formatter);
            $this->apiLogger->pushHandler($handler);
        } catch (\Exception $e) {
            // Silently fail if logging can't be initialized
            $this->apiLogger = null;
        }
    }

    /**
     * Log API call details
     */
    private function logApiCall(string $method, array $context): void {
        if ($this->apiLogger) {
            $this->apiLogger->info($method, $context);
        }
    }

    /**
     * Log full prompt details (for debugging)
     */
    private function logPrompt(string $systemPrompt, string $userMessage, ?string $response = null): void {
        if (!$this->apiLogger) {
            return;
        }

        $separator = str_repeat('=', 80);
        $logEntry = "\n{$separator}\n";
        $logEntry .= "TIMESTAMP: " . date('Y-m-d H:i:s') . "\n";
        $logEntry .= "MODEL: {$this->model}\n";
        $logEntry .= "{$separator}\n";
        $logEntry .= "SYSTEM PROMPT:\n{$separator}\n{$systemPrompt}\n";
        $logEntry .= "{$separator}\n";
        $logEntry .= "USER MESSAGE:\n{$separator}\n{$userMessage}\n";

        if ($response !== null) {
            $logEntry .= "{$separator}\n";
            $logEntry .= "RESPONSE:\n{$separator}\n{$response}\n";
        }

        $logEntry .= "{$separator}\n";

        $this->apiLogger->debug('FULL_PROMPT', ['details' => $logEntry]);
    }

    /**
     * Send a message to Claude and get a response
     */
    public function chat(string $systemPrompt, string $userMessage, int $maxTokens = 4096): string {
        // Sanitize UTF-8 to prevent json_encode errors
        $systemPrompt = $this->sanitizeUtf8($systemPrompt);
        $userMessage = $this->sanitizeUtf8($userMessage);

        $startTime = microtime(true);
        $inputChars = strlen($systemPrompt) + strlen($userMessage);

        try {
            $response = $this->client->post('/v1/messages', [
                'json' => [
                    'model' => $this->model,
                    'max_tokens' => $maxTokens,
                    'system' => $systemPrompt,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $userMessage,
                        ],
                    ],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $responseText = $data['content'][0]['text'] ?? '';
            $duration = round((microtime(true) - $startTime) * 1000);

            // Log successful API call
            $this->logApiCall('chat', [
                'model' => $this->model,
                'input_tokens' => $data['usage']['input_tokens'] ?? null,
                'output_tokens' => $data['usage']['output_tokens'] ?? null,
                'input_chars' => $inputChars,
                'output_chars' => strlen($responseText),
                'duration_ms' => $duration,
                'status' => 'success'
            ]);

            // Log full prompt and response for debugging
            $this->logPrompt($systemPrompt, $userMessage, $responseText);

            return $responseText;

        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000);

            // Log failed API call
            $this->logApiCall('chat', [
                'model' => $this->model,
                'input_chars' => $inputChars,
                'duration_ms' => $duration,
                'status' => 'error',
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Sanitize string to valid UTF-8
     */
    private function sanitizeUtf8(string $text): string {
        // Convert to UTF-8 and remove invalid sequences
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        // Remove control characters except newline/tab
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    }

    /**
     * Analyze multiple items in a batch with a single prompt
     */
    public function analyzeBatch(string $systemPrompt, array $items, string $instruction): string {
        $formattedItems = "";
        foreach ($items as $index => $item) {
            $formattedItems .= "--- ITEM " . ($index + 1) . " ---\n";
            $formattedItems .= $item . "\n\n";
        }

        $userMessage = $instruction . "\n\n" . $formattedItems;

        return $this->chat($systemPrompt, $userMessage, 8192);
    }

    /**
     * Get a JSON response from Claude
     */
    public function chatJson(string $systemPrompt, string $userMessage, array $images = []): array {
        $response = $this->chatWithImages(
            $systemPrompt . "\n\nIMPORTANT: Respond ONLY with valid JSON. No markdown, no explanation, just the JSON object.",
            $userMessage,
            $images
        );

        // Try to extract JSON from the response
        $response = trim($response);

        // Remove markdown code blocks if present
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $response, $matches)) {
            $response = $matches[1];
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to parse Claude response as JSON: " . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Send a message to Claude with optional images
     *
     * @param string $systemPrompt System prompt
     * @param string $userMessage User message text
     * @param array $images Array of images: [['mimeType' => 'image/png', 'base64' => '...'], ...]
     * @param int $maxTokens Maximum response tokens
     * @return string Response text
     */
    public function chatWithImages(string $systemPrompt, string $userMessage, array $images = [], int $maxTokens = 4096): string {
        // If no images, use regular chat
        if (empty($images)) {
            return $this->chat($systemPrompt, $userMessage, $maxTokens);
        }

        // Sanitize UTF-8
        $systemPrompt = $this->sanitizeUtf8($systemPrompt);
        $userMessage = $this->sanitizeUtf8($userMessage);

        $startTime = microtime(true);
        $inputChars = strlen($systemPrompt) + strlen($userMessage);

        // Build content array with text and images
        $content = [];

        // Add images first
        foreach ($images as $image) {
            if (!empty($image['base64']) && !empty($image['mimeType'])) {
                $content[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $image['mimeType'],
                        'data' => $image['base64']
                    ]
                ];
            }
        }

        // Add text message
        $content[] = [
            'type' => 'text',
            'text' => $userMessage
        ];

        try {
            $response = $this->client->post('/v1/messages', [
                'json' => [
                    'model' => $this->model,
                    'max_tokens' => $maxTokens,
                    'system' => $systemPrompt,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $content,
                        ],
                    ],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $responseText = $data['content'][0]['text'] ?? '';
            $duration = round((microtime(true) - $startTime) * 1000);

            // Log successful API call
            $this->logApiCall('chatWithImages', [
                'model' => $this->model,
                'input_tokens' => $data['usage']['input_tokens'] ?? null,
                'output_tokens' => $data['usage']['output_tokens'] ?? null,
                'input_chars' => $inputChars,
                'output_chars' => strlen($responseText),
                'image_count' => count($images),
                'duration_ms' => $duration,
                'status' => 'success'
            ]);

            // Log full prompt (without base64 data for images)
            $imageInfo = array_map(fn($img) => $img['filename'] ?? $img['mimeType'] ?? 'image', $images);
            $this->logPrompt($systemPrompt, $userMessage . "\n\n[IMAGES ATTACHED: " . implode(', ', $imageInfo) . "]", $responseText);

            return $responseText;

        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000);

            // Log failed API call
            $this->logApiCall('chatWithImages', [
                'model' => $this->model,
                'input_chars' => $inputChars,
                'image_count' => count($images),
                'duration_ms' => $duration,
                'status' => 'error',
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Check if Claude is configured
     */
    public static function isConfigured(): bool {
        return !empty(Flight::get('anthropic.api_key'));
    }
}
