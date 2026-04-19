<?php
/**
 * Plugin Search Service
 *
 * Handles plugin search operations with relevance ranking.
 * Search scores are assigned based on match type:
 * - Exact name match: 100
 * - Name contains: 80
 * - Description contains: 50
 * - Tags contain: 30
 */

namespace app\services;

use \app\Bean;

class PluginSearchService {

    /**
     * Available plugin categories
     */
    public const CATEGORIES = [
        'general' => 'General',
        'automation' => 'Automation',
        'integration' => 'Integration',
        'analytics' => 'Analytics',
        'security' => 'Security',
        'development' => 'Development',
        'productivity' => 'Productivity',
        'communication' => 'Communication'
    ];

    /**
     * Search plugins with relevance ranking
     *
     * @param string $query Search query
     * @param array $options Search options (category, limit, offset)
     * @return array Results with relevance scores
     */
    public static function search(string $query, array $options = []): array {
        $query = trim($query);
        $category = $options['category'] ?? null;
        $limit = min((int)($options['limit'] ?? 20), 100);
        $offset = (int)($options['offset'] ?? 0);

        if (empty($query)) {
            return self::getDefaultResults($category, $limit, $offset);
        }

        // Sanitize and prepare query for LIKE searches
        $searchTerm = '%' . self::sanitizeSearchTerm($query) . '%';
        $exactTerm = self::sanitizeSearchTerm($query);

        // Build SQL with relevance scoring
        $sql = "
            SELECT
                p.*,
                CASE
                    WHEN LOWER(p.name) = LOWER(?) THEN 100
                    WHEN LOWER(p.name) LIKE LOWER(?) THEN 80
                    WHEN LOWER(p.short_description) LIKE LOWER(?) THEN 50
                    WHEN LOWER(p.description) LIKE LOWER(?) THEN 45
                    WHEN JSON_SEARCH(LOWER(p.tags), 'one', LOWER(?)) IS NOT NULL THEN 30
                    ELSE 0
                END AS relevance_score
            FROM plugins p
            WHERE p.is_active = 1
            AND (
                LOWER(p.name) LIKE LOWER(?)
                OR LOWER(p.short_description) LIKE LOWER(?)
                OR LOWER(p.description) LIKE LOWER(?)
                OR JSON_SEARCH(LOWER(p.tags), 'one', LOWER(?)) IS NOT NULL
            )
        ";

        $params = [
            $exactTerm,      // Exact name match
            $searchTerm,     // Name contains
            $searchTerm,     // Short description contains
            $searchTerm,     // Description contains
            '%' . $exactTerm . '%', // Tags contain
            $searchTerm,     // WHERE: Name contains
            $searchTerm,     // WHERE: Short description contains
            $searchTerm,     // WHERE: Description contains
            '%' . $exactTerm . '%'  // WHERE: Tags contain
        ];

        // Add category filter if specified
        if ($category && isset(self::CATEGORIES[$category])) {
            $sql .= " AND p.category = ?";
            $params[] = $category;
        }

        $sql .= " ORDER BY relevance_score DESC, p.download_count DESC, p.name ASC";
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $results = Bean::getAll($sql, $params);

        // Format results
        return array_map(function($row) {
            return self::formatPluginResult($row);
        }, $results);
    }

    /**
     * Get autocomplete suggestions for partial plugin names
     *
     * @param string $query Partial query
     * @param int $limit Maximum suggestions
     * @return array Suggestions
     */
    public static function autocomplete(string $query, int $limit = 8): array {
        $query = trim($query);

        if (strlen($query) < 2) {
            return [];
        }

        $searchTerm = self::sanitizeSearchTerm($query) . '%';

        $sql = "
            SELECT id, name, slug, category, short_description
            FROM plugins
            WHERE is_active = 1
            AND (
                LOWER(name) LIKE LOWER(?)
                OR LOWER(slug) LIKE LOWER(?)
            )
            ORDER BY
                CASE WHEN LOWER(name) LIKE LOWER(?) THEN 0 ELSE 1 END,
                download_count DESC,
                name ASC
            LIMIT ?
        ";

        $results = Bean::getAll($sql, [
            $searchTerm,
            $searchTerm,
            $searchTerm,
            $limit
        ]);

        return array_map(function($row) {
            return [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'category' => $row['category'],
                'short_description' => $row['short_description']
                    ? substr($row['short_description'], 0, 80)
                    : null
            ];
        }, $results);
    }

    /**
     * Get default results (featured/popular plugins)
     *
     * @param string|null $category Optional category filter
     * @param int $limit Limit
     * @param int $offset Offset
     * @return array
     */
    public static function getDefaultResults(?string $category = null, int $limit = 20, int $offset = 0): array {
        $sql = "
            SELECT p.*
            FROM plugins p
            WHERE p.is_active = 1
        ";
        $params = [];

        if ($category && isset(self::CATEGORIES[$category])) {
            $sql .= " AND p.category = ?";
            $params[] = $category;
        }

        $sql .= " ORDER BY p.is_featured DESC, p.download_count DESC, p.rating DESC, p.name ASC";
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $results = Bean::getAll($sql, $params);

        return array_map(function($row) {
            return self::formatPluginResult($row);
        }, $results);
    }

    /**
     * Get search suggestions when no results found
     *
     * @param string $query Original query
     * @return array Suggestions
     */
    public static function getNoResultsSuggestions(string $query): array {
        $suggestions = [];

        // Get popular categories
        $popularCategories = Bean::getAll("
            SELECT category, COUNT(*) as count
            FROM plugins
            WHERE is_active = 1
            GROUP BY category
            ORDER BY count DESC
            LIMIT 5
        ");

        // Get popular plugins
        $popularPlugins = Bean::getAll("
            SELECT id, name, slug, category
            FROM plugins
            WHERE is_active = 1
            ORDER BY download_count DESC
            LIMIT 5
        ");

        // Generate alternative search terms
        $alternativeTerms = self::generateAlternativeTerms($query);

        return [
            'message' => "No plugins found for \"{$query}\"",
            'tips' => [
                'Try using broader search terms',
                'Check the spelling of your search',
                'Browse by category instead'
            ],
            'popular_categories' => array_map(function($cat) {
                return [
                    'key' => $cat['category'],
                    'name' => self::CATEGORIES[$cat['category']] ?? ucfirst($cat['category']),
                    'count' => (int) $cat['count']
                ];
            }, $popularCategories),
            'popular_plugins' => array_map(function($plugin) {
                return [
                    'id' => (int) $plugin['id'],
                    'name' => $plugin['name'],
                    'slug' => $plugin['slug'],
                    'category' => $plugin['category']
                ];
            }, $popularPlugins),
            'alternative_terms' => $alternativeTerms
        ];
    }

    /**
     * Get available categories with plugin counts
     *
     * @return array Categories with counts
     */
    public static function getCategoriesWithCounts(): array {
        $results = Bean::getAll("
            SELECT category, COUNT(*) as count
            FROM plugins
            WHERE is_active = 1
            GROUP BY category
            ORDER BY count DESC
        ");

        $categories = [];
        foreach ($results as $row) {
            $key = $row['category'];
            $categories[$key] = [
                'key' => $key,
                'name' => self::CATEGORIES[$key] ?? ucfirst($key),
                'count' => (int) $row['count']
            ];
        }

        // Include categories with zero count
        foreach (self::CATEGORIES as $key => $name) {
            if (!isset($categories[$key])) {
                $categories[$key] = [
                    'key' => $key,
                    'name' => $name,
                    'count' => 0
                ];
            }
        }

        return array_values($categories);
    }

    /**
     * Format plugin result for API response
     *
     * @param array $row Database row
     * @return array Formatted result
     */
    private static function formatPluginResult(array $row): array {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'description' => $row['description'],
            'short_description' => $row['short_description'],
            'tags' => json_decode($row['tags'] ?: '[]', true),
            'category' => $row['category'],
            'category_name' => self::CATEGORIES[$row['category']] ?? ucfirst($row['category']),
            'version' => $row['version'],
            'author' => $row['author'],
            'author_url' => $row['author_url'],
            'icon_url' => $row['icon_url'],
            'homepage_url' => $row['homepage_url'],
            'documentation_url' => $row['documentation_url'],
            'download_count' => (int) $row['download_count'],
            'rating' => (float) $row['rating'],
            'rating_count' => (int) $row['rating_count'],
            'is_featured' => (bool) $row['is_featured'],
            'is_verified' => (bool) $row['is_verified'],
            'relevance_score' => isset($row['relevance_score']) ? (int) $row['relevance_score'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }

    /**
     * Sanitize search term to prevent SQL injection and normalize input
     *
     * @param string $term Raw search term
     * @return string Sanitized term
     */
    private static function sanitizeSearchTerm(string $term): string {
        // Remove special characters that could interfere with LIKE
        $term = preg_replace('/[%_\\\\]/', '', $term);
        // Remove excessive whitespace
        $term = preg_replace('/\s+/', ' ', $term);
        // Trim and limit length
        return substr(trim($term), 0, 100);
    }

    /**
     * Generate alternative search terms
     *
     * @param string $query Original query
     * @return array Alternative terms
     */
    private static function generateAlternativeTerms(string $query): array {
        $alternatives = [];
        $words = explode(' ', strtolower($query));

        // Suggest individual words if multi-word query
        if (count($words) > 1) {
            foreach ($words as $word) {
                if (strlen($word) >= 3) {
                    $alternatives[] = $word;
                }
            }
        }

        // Common synonyms and related terms
        $synonyms = [
            'auth' => ['authentication', 'login', 'security'],
            'db' => ['database', 'storage'],
            'api' => ['integration', 'rest', 'webhook'],
            'mail' => ['email', 'notification'],
            'chat' => ['messaging', 'communication'],
            'ai' => ['artificial intelligence', 'machine learning', 'automation'],
            'log' => ['logging', 'monitoring', 'analytics']
        ];

        foreach ($words as $word) {
            if (isset($synonyms[$word])) {
                $alternatives = array_merge($alternatives, $synonyms[$word]);
            }
        }

        return array_unique(array_slice($alternatives, 0, 5));
    }

    /**
     * Get a single plugin by ID
     *
     * @param int $id Plugin ID
     * @return array|null Plugin data or null
     */
    public static function getById(int $id): ?array {
        $row = Bean::getRow("
            SELECT * FROM plugins WHERE id = ? AND is_active = 1
        ", [$id]);

        return $row ? self::formatPluginResult($row) : null;
    }

    /**
     * Get a single plugin by slug
     *
     * @param string $slug Plugin slug
     * @return array|null Plugin data or null
     */
    public static function getBySlug(string $slug): ?array {
        $row = Bean::getRow("
            SELECT * FROM plugins WHERE slug = ? AND is_active = 1
        ", [$slug]);

        return $row ? self::formatPluginResult($row) : null;
    }

    /**
     * Get related plugins based on category and tags
     *
     * @param int $pluginId Current plugin ID
     * @param int $limit Maximum results
     * @return array Related plugins
     */
    public static function getRelated(int $pluginId, int $limit = 4): array {
        // Get the current plugin's info
        $plugin = Bean::getRow("SELECT category, tags FROM plugins WHERE id = ?", [$pluginId]);
        if (!$plugin) {
            return [];
        }

        $results = Bean::getAll("
            SELECT *
            FROM plugins
            WHERE id != ?
            AND is_active = 1
            AND category = ?
            ORDER BY download_count DESC, rating DESC
            LIMIT ?
        ", [$pluginId, $plugin['category'], $limit]);

        return array_map(function($row) {
            return self::formatPluginResult($row);
        }, $results);
    }
}
