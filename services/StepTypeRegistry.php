<?php
/**
 * StepTypeRegistry - Scans and parses pipeline step type components
 *
 * Discovers step types from component partial files in views/pipelines/components/
 * Each component file should have a metadata header with annotations.
 */

namespace app\services;

class StepTypeRegistry
{
    private static ?array $cache = null;
    private static string $componentsPath = __DIR__ . '/../views/pipelines/components';

    /**
     * Category definitions with display order and labels
     */
    private static array $categories = [
        'core' => [
            'label' => 'Core',
            'icon' => 'bi-gear',
            'order' => 1
        ],
        'ai' => [
            'label' => 'AI & Agents',
            'icon' => 'bi-robot',
            'order' => 2
        ],
        'ecommerce' => [
            'label' => 'E-Commerce',
            'icon' => 'bi-cart',
            'order' => 3
        ],
        'communication' => [
            'label' => 'Communication',
            'icon' => 'bi-envelope',
            'order' => 4
        ],
        'integration' => [
            'label' => 'Integrations',
            'icon' => 'bi-plug',
            'order' => 5
        ],
        'flow' => [
            'label' => 'Flow Control',
            'icon' => 'bi-diagram-2',
            'order' => 6
        ],
    ];

    /**
     * Get all step types, optionally grouped by category
     *
     * @param bool $grouped Whether to group by category
     * @return array
     */
    public static function getAll(bool $grouped = false): array
    {
        if (self::$cache === null) {
            self::scan();
        }

        if (!$grouped) {
            return self::$cache;
        }

        return self::groupByCategory(self::$cache);
    }

    /**
     * Get a specific step type by its key
     *
     * @param string $stepType
     * @return array|null
     */
    public static function get(string $stepType): ?array
    {
        if (self::$cache === null) {
            self::scan();
        }

        return self::$cache[$stepType] ?? null;
    }

    /**
     * Get category definitions
     *
     * @return array
     */
    public static function getCategories(): array
    {
        return self::$categories;
    }

    /**
     * Get the partial file path for a step type
     *
     * @param string $stepType
     * @return string|null
     */
    public static function getPartialPath(string $stepType): ?string
    {
        $info = self::get($stepType);
        return $info['partial_path'] ?? null;
    }

    /**
     * Clear the cache (useful for development/testing)
     */
    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /**
     * Scan component files and parse metadata
     */
    private static function scan(): void
    {
        self::$cache = [];

        $pattern = self::$componentsPath . '/_config_*.php';
        $files = glob($pattern);

        foreach ($files as $file) {
            $metadata = self::parseMetadata($file);
            if ($metadata && isset($metadata['step_type'])) {
                $stepType = $metadata['step_type'];
                self::$cache[$stepType] = [
                    'label' => $metadata['label'] ?? ucwords(str_replace('_', ' ', $stepType)),
                    'description' => $metadata['description'] ?? '',
                    'icon' => $metadata['icon'] ?? 'bi-square',
                    'color' => $metadata['color'] ?? 'secondary',
                    'category' => $metadata['category'] ?? 'core',
                    'partial_path' => $file,
                    'partial_name' => basename($file),
                ];
            }
        }

        // Sort by category order, then by label
        uasort(self::$cache, function($a, $b) {
            $catOrderA = self::$categories[$a['category']]['order'] ?? 99;
            $catOrderB = self::$categories[$b['category']]['order'] ?? 99;

            if ($catOrderA !== $catOrderB) {
                return $catOrderA <=> $catOrderB;
            }

            return strcasecmp($a['label'], $b['label']);
        });
    }

    /**
     * Parse metadata from a component file's header comment
     *
     * Expected format:
     * <?php
     * /**
     *  * @step_type: shopify_graphql
     *  * @category: ecommerce
     *  * @label: Shopify GraphQL
     *  * @icon: bi-shop
     *  * @color: success
     *  * @description: Execute GraphQL query against a Shopify store
     *  * /
     *
     * @param string $filePath
     * @return array|null
     */
    private static function parseMetadata(string $filePath): ?array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        // Look for the first docblock comment
        if (!preg_match('/\/\*\*(.*?)\*\//s', $content, $matches)) {
            return null;
        }

        $docblock = $matches[1];
        $metadata = [];

        // Parse @annotations
        $annotations = [
            'step_type',
            'category',
            'label',
            'icon',
            'color',
            'description'
        ];

        foreach ($annotations as $annotation) {
            if (preg_match('/@' . $annotation . ':\s*(.+?)(?:\n|\*\/)/i', $docblock, $match)) {
                $metadata[$annotation] = trim($match[1]);
                // Remove trailing asterisks from multi-line comments
                $metadata[$annotation] = rtrim($metadata[$annotation], ' *');
            }
        }

        return !empty($metadata) ? $metadata : null;
    }

    /**
     * Group step types by category
     *
     * @param array $stepTypes
     * @return array
     */
    private static function groupByCategory(array $stepTypes): array
    {
        $grouped = [];

        // Initialize all categories
        foreach (self::$categories as $catKey => $catInfo) {
            $grouped[$catKey] = [
                'label' => $catInfo['label'],
                'icon' => $catInfo['icon'],
                'order' => $catInfo['order'],
                'types' => []
            ];
        }

        // Group step types
        foreach ($stepTypes as $typeKey => $typeInfo) {
            $category = $typeInfo['category'] ?? 'core';
            if (!isset($grouped[$category])) {
                $grouped[$category] = [
                    'label' => ucfirst($category),
                    'icon' => 'bi-folder',
                    'order' => 99,
                    'types' => []
                ];
            }
            $grouped[$category]['types'][$typeKey] = $typeInfo;
        }

        // Remove empty categories and sort by order
        $grouped = array_filter($grouped, fn($cat) => !empty($cat['types']));
        uasort($grouped, fn($a, $b) => $a['order'] <=> $b['order']);

        return $grouped;
    }
}
