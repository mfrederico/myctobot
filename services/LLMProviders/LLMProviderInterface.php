<?php
/**
 * LLM Provider Interface
 *
 * Defines the contract for all LLM providers (Claude, Ollama, OpenAI, etc.)
 */

namespace app\services\LLMProviders;

interface LLMProviderInterface
{
    /**
     * Get the provider type identifier
     * @return string e.g., 'claude_cli', 'ollama', 'openai'
     */
    public function getType(): string;

    /**
     * Get human-readable provider name
     * @return string e.g., 'Claude CLI', 'Ollama (Local)'
     */
    public function getName(): string;

    /**
     * Test the connection/configuration
     * @return array ['success' => bool, 'message' => string, 'details' => array]
     */
    public function testConnection(): array;

    /**
     * Get available models for this provider
     * @return array List of model identifiers
     */
    public function getAvailableModels(): array;

    /**
     * Send a completion request
     * @param string $prompt The prompt/message to send
     * @param array $options Additional options (temperature, max_tokens, etc.)
     * @return array ['success' => bool, 'response' => string, 'usage' => array]
     */
    public function complete(string $prompt, array $options = []): array;

    /**
     * Send a chat completion request (multi-turn)
     * @param array $messages Array of ['role' => 'user'|'assistant', 'content' => '...']
     * @param array $options Additional options
     * @return array ['success' => bool, 'response' => string, 'usage' => array]
     */
    public function chat(array $messages, array $options = []): array;

    /**
     * Check if this provider supports streaming
     * @return bool
     */
    public function supportsStreaming(): bool;

    /**
     * Check if this provider can be exposed as an MCP tool
     * @return bool
     */
    public function canExposeAsMcp(): bool;

    /**
     * Get the configuration schema for this provider
     * @return array JSON Schema for the provider config
     */
    public function getConfigSchema(): array;

    /**
     * Validate provider configuration
     * @param array $config The configuration to validate
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateConfig(array $config): array;

    /**
     * Get default configuration values
     * @return array
     */
    public function getDefaultConfig(): array;
}
