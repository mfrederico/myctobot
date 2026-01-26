<?php
/**
 * Ollama Client Service
 *
 * Provides the same interface as ClaudeClient but uses Ollama for inference.
 * This allows analyzers to use Ollama without code changes.
 */

namespace app\services;

use \app\services\LLMProviders\OllamaProvider;

require_once __DIR__ . '/LLMProviders/OllamaProvider.php';
require_once __DIR__ . '/LLMClientInterface.php';

class OllamaClient implements LLMClientInterface {
    private string $host;
    private string $model;
    private OllamaProvider $provider;

    /**
     * @param string $host Ollama host URL (e.g., http://localhost:11434)
     * @param string $model Model name (e.g., qwen3-coder:30b)
     */
    public function __construct(string $host = 'http://localhost:11434', string $model = 'llama3') {
        $this->host = rtrim($host, '/');
        $this->model = $model;
        $this->provider = new OllamaProvider([
            'host' => $this->host,
            'model' => $this->model
        ]);
    }

    /**
     * Send a message and get a response (same interface as ClaudeClient::chat)
     */
    public function chat(string $systemPrompt, string $userMessage, int $maxTokens = 4096): string {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ];

        $result = $this->provider->chat($messages, [
            'model' => $this->model,
            'context_length' => $maxTokens
        ]);

        if (!$result['success']) {
            throw new \RuntimeException('Ollama request failed: ' . ($result['error'] ?? 'Unknown error'));
        }

        return $result['response'] ?? '';
    }

    /**
     * Analyze multiple items in a batch (same interface as ClaudeClient::analyzeBatch)
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
     * Get a JSON response (same interface as ClaudeClient::chatJson)
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
            throw new \RuntimeException("Failed to parse Ollama response as JSON: " . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Send a message with optional images (same interface as ClaudeClient::chatWithImages)
     */
    public function chatWithImages(string $systemPrompt, string $userMessage, array $images = [], int $maxTokens = 4096): string {
        // If no images, use regular chat
        if (empty($images)) {
            return $this->chat($systemPrompt, $userMessage, $maxTokens);
        }

        // Build messages with images for Ollama
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];

        // Ollama expects images as base64 in the 'images' array of the user message
        $userMsg = ['role' => 'user', 'content' => $userMessage];
        $imageData = [];

        foreach ($images as $image) {
            if (!empty($image['base64'])) {
                $imageData[] = $image['base64'];
            }
        }

        if (!empty($imageData)) {
            $userMsg['images'] = $imageData;
        }

        $messages[] = $userMsg;

        $result = $this->provider->chat($messages, [
            'model' => $this->model,
            'context_length' => $maxTokens
        ]);

        if (!$result['success']) {
            throw new \RuntimeException('Ollama request failed: ' . ($result['error'] ?? 'Unknown error'));
        }

        return $result['response'] ?? '';
    }

    /**
     * Check if Ollama is available
     */
    public function isAvailable(): bool {
        $result = $this->provider->testConnection();
        return $result['success'] ?? false;
    }

    /**
     * Get model info
     */
    public function getModel(): string {
        return $this->model;
    }

    /**
     * Get host
     */
    public function getHost(): string {
        return $this->host;
    }
}
