<?php
/**
 * LLM Provider Factory
 *
 * Creates provider instances based on type.
 */

namespace app\services\LLMProviders;

class LLMProviderFactory
{
    /**
     * Available provider types
     */
    public const PROVIDERS = [
        'claude_cli' => [
            'name' => 'Claude CLI',
            'description' => 'Uses your Claude Code subscription (runs in tmux)',
            'class' => null, // Special case - not an API provider
            'can_orchestrate' => true,
            'requires_api_key' => false
        ],
        'claude_api' => [
            'name' => 'Claude API',
            'description' => 'Uses Anthropic API credits',
            'class' => 'ClaudeApiProvider',
            'can_orchestrate' => false,
            'requires_api_key' => true
        ],
        'ollama' => [
            'name' => 'Ollama (Local)',
            'description' => 'Local LLM inference via Ollama',
            'class' => 'OllamaProvider',
            'can_orchestrate' => false,
            'requires_api_key' => false
        ],
        'openai' => [
            'name' => 'OpenAI',
            'description' => 'OpenAI GPT models',
            'class' => 'OpenAIProvider',
            'can_orchestrate' => false,
            'requires_api_key' => true
        ],
        'custom_http' => [
            'name' => 'Custom HTTP',
            'description' => 'Any OpenAI-compatible API endpoint',
            'class' => 'OpenAIProvider', // Reuse OpenAI provider with custom endpoint
            'can_orchestrate' => false,
            'requires_api_key' => false
        ]
    ];

    /**
     * Create a provider instance
     *
     * @param string $type Provider type
     * @param array $config Provider configuration
     * @param int|null $memberId Member ID for API key lookup
     * @return LLMProviderInterface|null
     */
    public static function create(string $type, array $config, ?int $memberId = null): ?LLMProviderInterface
    {
        if (!isset(self::PROVIDERS[$type])) {
            return null;
        }

        $providerInfo = self::PROVIDERS[$type];
        $className = $providerInfo['class'];

        if (!$className) {
            // claude_cli is a special case - not instantiated as a provider
            return null;
        }

        $fullClassName = __NAMESPACE__ . '\\' . $className;

        if (!class_exists($fullClassName)) {
            return null;
        }

        // OpenAI and Claude API need member ID for API key lookup
        if (in_array($type, ['openai', 'claude_api', 'custom_http'])) {
            return new $fullClassName($config, $memberId);
        }

        return new $fullClassName($config);
    }

    /**
     * Get list of all available provider types
     *
     * @return array
     */
    public static function getProviderTypes(): array
    {
        return array_keys(self::PROVIDERS);
    }

    /**
     * Get provider info
     *
     * @param string $type
     * @return array|null
     */
    public static function getProviderInfo(string $type): ?array
    {
        return self::PROVIDERS[$type] ?? null;
    }

    /**
     * Get all providers info for UI
     *
     * @return array
     */
    public static function getAllProvidersInfo(): array
    {
        $result = [];
        foreach (self::PROVIDERS as $type => $info) {
            $result[] = [
                'type' => $type,
                'name' => $info['name'],
                'description' => $info['description'],
                'can_orchestrate' => $info['can_orchestrate'],
                'requires_api_key' => $info['requires_api_key']
            ];
        }
        return $result;
    }

    /**
     * Get config schema for a provider type
     *
     * @param string $type
     * @return array
     */
    public static function getConfigSchema(string $type): array
    {
        // For claude_cli, return a custom schema
        if ($type === 'claude_cli') {
            return [
                'type' => 'object',
                'properties' => [
                    'model' => [
                        'type' => 'string',
                        'title' => 'Model',
                        'description' => 'Claude model to use',
                        'default' => 'sonnet',
                        'enum' => ['haiku', 'sonnet', 'opus']
                    ],
                    'dangerously_skip_permissions' => [
                        'type' => 'boolean',
                        'title' => 'Skip Permission Prompts',
                        'description' => 'Skip interactive permission prompts',
                        'default' => true
                    ],
                    'max_turns' => [
                        'type' => 'integer',
                        'title' => 'Max Turns',
                        'description' => 'Maximum conversation turns',
                        'default' => 50,
                        'minimum' => 1,
                        'maximum' => 200
                    ]
                ]
            ];
        }

        // Try to instantiate and get schema
        $provider = self::create($type, []);
        if ($provider) {
            return $provider->getConfigSchema();
        }

        return ['type' => 'object', 'properties' => []];
    }

    /**
     * Get default config for a provider type
     *
     * @param string $type
     * @return array
     */
    public static function getDefaultConfig(string $type): array
    {
        if ($type === 'claude_cli') {
            return [
                'model' => 'sonnet',
                'dangerously_skip_permissions' => true,
                'max_turns' => 50
            ];
        }

        $provider = self::create($type, []);
        if ($provider) {
            return $provider->getDefaultConfig();
        }

        return [];
    }

    /**
     * Test a provider connection
     *
     * @param string $type
     * @param array $config
     * @param int|null $memberId
     * @return array
     */
    public static function testConnection(string $type, array $config, ?int $memberId = null): array
    {
        if ($type === 'claude_cli') {
            // Test if claude command exists
            exec('which claude 2>/dev/null', $output, $exitCode);
            if ($exitCode === 0) {
                return [
                    'success' => true,
                    'message' => 'Claude CLI found at: ' . trim($output[0] ?? 'claude'),
                    'details' => []
                ];
            }
            return [
                'success' => false,
                'message' => 'Claude CLI not found. Install with: npm install -g @anthropic-ai/claude-code',
                'details' => []
            ];
        }

        $provider = self::create($type, $config, $memberId);
        if (!$provider) {
            return [
                'success' => false,
                'message' => 'Unknown provider type: ' . $type,
                'details' => []
            ];
        }

        return $provider->testConnection();
    }
}
