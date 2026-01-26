<?php
/**
 * LLM Client Interface
 *
 * Common interface for LLM clients (Claude, Ollama, etc.)
 * Allows analyzers to work with any LLM provider.
 */

namespace app\services;

interface LLMClientInterface {
    /**
     * Send a message and get a response
     *
     * @param string $systemPrompt System prompt
     * @param string $userMessage User message
     * @param int $maxTokens Maximum tokens
     * @return string Response text
     */
    public function chat(string $systemPrompt, string $userMessage, int $maxTokens = 4096): string;

    /**
     * Analyze multiple items in a batch
     *
     * @param string $systemPrompt System prompt
     * @param array $items Items to analyze
     * @param string $instruction Instruction for analysis
     * @return string Response text
     */
    public function analyzeBatch(string $systemPrompt, array $items, string $instruction): string;

    /**
     * Get a JSON response
     *
     * @param string $systemPrompt System prompt
     * @param string $userMessage User message
     * @param array $images Optional images
     * @return array Decoded JSON response
     */
    public function chatJson(string $systemPrompt, string $userMessage, array $images = []): array;

    /**
     * Send a message with optional images
     *
     * @param string $systemPrompt System prompt
     * @param string $userMessage User message
     * @param array $images Optional images
     * @param int $maxTokens Maximum tokens
     * @return string Response text
     */
    public function chatWithImages(string $systemPrompt, string $userMessage, array $images = [], int $maxTokens = 4096): string;
}
