<?php
/**
 * Claude AI Client Service
 * Handles communication with Anthropic Claude API
 */

namespace app\services;

use \Flight as Flight;
use GuzzleHttp\Client;

class ClaudeClient {
    private Client $client;
    private string $apiKey;
    private string $model;

    public function __construct() {
        $this->apiKey = Flight::get('anthropic.api_key');
        $this->model = Flight::get('anthropic.model') ?? 'claude-sonnet-4-20250514';

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
    }

    /**
     * Send a message to Claude and get a response
     */
    public function chat(string $systemPrompt, string $userMessage, int $maxTokens = 4096): string {
        // Sanitize UTF-8 to prevent json_encode errors
        $systemPrompt = $this->sanitizeUtf8($systemPrompt);
        $userMessage = $this->sanitizeUtf8($userMessage);

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
        return $data['content'][0]['text'] ?? '';
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
    public function chatJson(string $systemPrompt, string $userMessage): array {
        $response = $this->chat(
            $systemPrompt . "\n\nIMPORTANT: Respond ONLY with valid JSON. No markdown, no explanation, just the JSON object.",
            $userMessage
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
     * Check if Claude is configured
     */
    public static function isConfigured(): bool {
        return !empty(Flight::get('anthropic.api_key'));
    }
}
