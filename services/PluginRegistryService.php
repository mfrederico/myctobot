<?php
/**
 * Plugin Registry Service
 *
 * Handles plugin discovery, category browsing, and tag filtering.
 * Provides methods for retrieving plugins organized by categories and tags.
 */

namespace app\services;

use \RedBeanPHP\R as R;

class PluginRegistryService {

    // ==================== Categories ====================

    /**
     * Get all categories with plugin counts
     *
     * @param bool $includeEmpty Include categories with no plugins
     * @return array
     */
    public static function getCategories(bool $includeEmpty = false): array {
        $sql = ' ORDER BY display_order ASC, name ASC ';
        if (!$includeEmpty) {
            $sql = ' plugin_count > 0 ' . $sql;
        }
        return array_values(R::findAll('plugincategory', $sql));
    }

    /**
     * Get a category by slug
     *
     * @param string $slug Category slug
     * @return \RedBeanPHP\OODBBean|null
     */
    public static function getCategoryBySlug(string $slug) {
        return R::findOne('plugincategory', ' slug = ? ', [$slug]);
    }

    /**
     * Get a category by ID
     *
     * @param int $id Category ID
     * @return \RedBeanPHP\OODBBean|null
     */
    public static function getCategory(int $id) {
        $category = R::load('plugincategory', $id);
        return $category->id ? $category : null;
    }

    /**
     * Get subcategories for a parent category
     *
     * @param int $parentId Parent category ID
     * @return array
     */
    public static function getSubcategories(int $parentId): array {
        return array_values(R::find('plugincategory',
            ' parent_id = ? ORDER BY display_order ASC, name ASC ',
            [$parentId]
        ));
    }

    // ==================== Tags ====================

    /**
     * Get all tags with plugin counts
     *
     * @param bool $includeEmpty Include tags with no plugins
     * @return array
     */
    public static function getTags(bool $includeEmpty = false): array {
        $sql = ' ORDER BY plugin_count DESC, name ASC ';
        if (!$includeEmpty) {
            $sql = ' plugin_count > 0 ' . $sql;
        }
        return array_values(R::findAll('plugintag', $sql));
    }

    /**
     * Get a tag by slug
     *
     * @param string $slug Tag slug
     * @return \RedBeanPHP\OODBBean|null
     */
    public static function getTagBySlug(string $slug) {
        return R::findOne('plugintag', ' slug = ? ', [$slug]);
    }

    /**
     * Get tags for a specific plugin
     *
     * @param int $pluginId Plugin ID
     * @return array
     */
    public static function getPluginTags(int $pluginId): array {
        return R::getAll(
            'SELECT t.* FROM plugintag t ' .
            'INNER JOIN plugin_plugintag pt ON t.id = pt.plugintag_id ' .
            'WHERE pt.plugin_id = ? ORDER BY t.name ASC',
            [$pluginId]
        );
    }

    /**
     * Get multiple tags by their slugs
     *
     * @param array $slugs Tag slugs
     * @return array
     */
    public static function getTagsBySlugs(array $slugs): array {
        if (empty($slugs)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        return R::getAll(
            'SELECT * FROM plugintag WHERE slug IN (' . $placeholders . ') ORDER BY name ASC',
            $slugs
        );
    }

    // ==================== Plugins ====================

    /**
     * Get plugins with filtering options
     *
     * @param array $filters Filter options
     * @return array
     */
    public static function getPlugins(array $filters = []): array {
        // Build query components
        $queryData = self::buildPluginQuery($filters);

        // Count total results
        $countResult = R::getRow($queryData['countSql'], $queryData['countParams']);
        $total = (int) ($countResult['total'] ?? 0);

        // Get plugins
        $plugins = R::getAll($queryData['selectSql'], $queryData['selectParams']);

        // Enrich plugins with tags
        foreach ($plugins as $key => $plugin) {
            $plugins[$key]['tags'] = self::getPluginTags((int) $plugin['id']);
        }

        return [
            'plugins' => $plugins,
            'total' => $total,
            'limit' => $queryData['limit'],
            'offset' => $queryData['offset']
        ];
    }

    /**
     * Build plugin query components
     *
     * @param array $filters Filter options
     * @return array Query components
     */
    private static function buildPluginQuery(array $filters): array {
        $conditions = ['p.is_active = 1'];
        $params = [];
        $useTagJoin = false;
        $useCategoryJoin = false;

        // Filter by category ID
        if (!empty($filters['category_id'])) {
            $conditions[] = 'p.category_id = ?';
            $params[] = (int) $filters['category_id'];
        }

        // Filter by category slug
        if (!empty($filters['category_slug'])) {
            $useCategoryJoin = true;
            $conditions[] = 'cat.slug = ?';
            $params[] = $filters['category_slug'];
        }

        // Filter by tag slugs (must match ALL tags)
        $tagSubquery = '';
        $tagParams = [];
        if (!empty($filters['tag_slugs']) && is_array($filters['tag_slugs'])) {
            $tagSlugs = array_values($filters['tag_slugs']);
            $tagCount = count($tagSlugs);
            $tagPlaceholders = implode(',', array_fill(0, $tagCount, '?'));
            $tagParams = array_merge($tagSlugs, [$tagCount]);
            $useTagJoin = true;
        }

        // Search in name and description
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $conditions[] = '(p.name LIKE ? OR p.short_description LIKE ? OR p.description LIKE ?)';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        // Featured only
        if (!empty($filters['is_featured'])) {
            $conditions[] = 'p.is_featured = 1';
        }

        // Sorting - whitelist approach
        $sortOptions = [
            'downloads' => 'p.downloads DESC',
            'downloads_asc' => 'p.downloads ASC',
            'rating' => 'p.rating DESC',
            'rating_asc' => 'p.rating ASC',
            'name' => 'p.name ASC',
            'name_desc' => 'p.name DESC',
            'created_at' => 'p.created_at DESC',
            'created_at_asc' => 'p.created_at ASC'
        ];

        $sortKey = 'downloads';
        if (!empty($filters['sort'])) {
            $requestedSort = $filters['sort'];
            if (!empty($filters['order']) && strtolower($filters['order']) === 'asc') {
                $requestedSort .= '_asc';
            }
            if (isset($sortOptions[$requestedSort])) {
                $sortKey = $requestedSort;
            } elseif (isset($sortOptions[$filters['sort']])) {
                $sortKey = $filters['sort'];
            }
        }
        $orderBy = $sortOptions[$sortKey];

        // Pagination
        $limit = min(max((int) ($filters['limit'] ?? 20), 1), 100);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        // Build SQL parts
        $whereClause = implode(' AND ', $conditions);

        // Category join
        $categoryJoin = $useCategoryJoin
            ? 'INNER JOIN plugincategory cat ON p.category_id = cat.id'
            : 'LEFT JOIN plugincategory pc ON p.category_id = pc.id';

        // Tag filter join
        $tagJoin = '';
        if ($useTagJoin && !empty($tagParams)) {
            $tagPlaceholders = implode(',', array_fill(0, count($tagParams) - 1, '?'));
            $tagJoin = 'INNER JOIN (' .
                'SELECT pt.plugin_id FROM plugin_plugintag pt ' .
                'INNER JOIN plugintag t ON pt.plugintag_id = t.id ' .
                'WHERE t.slug IN (' . $tagPlaceholders . ') ' .
                'GROUP BY pt.plugin_id HAVING COUNT(DISTINCT pt.plugintag_id) = ?' .
                ') tf ON p.id = tf.plugin_id';
        }

        // Build count query
        $countParams = $useTagJoin ? array_merge($tagParams, $params) : $params;
        $countSql = 'SELECT COUNT(DISTINCT p.id) as total FROM plugin p ' .
            ($useTagJoin ? $tagJoin . ' ' : '') .
            ($useCategoryJoin ? $categoryJoin . ' ' : '') .
            'WHERE ' . $whereClause;

        // Build select query
        $selectParams = $useTagJoin ? array_merge($tagParams, $params, [$limit, $offset]) : array_merge($params, [$limit, $offset]);

        $selectSql = 'SELECT DISTINCT p.*, ' .
            'COALESCE(pc.name, cat.name) as category_name, ' .
            'COALESCE(pc.slug, cat.slug) as category_slug, ' .
            'COALESCE(pc.icon, cat.icon) as category_icon ' .
            'FROM plugin p ' .
            ($useTagJoin ? $tagJoin . ' ' : '') .
            $categoryJoin . ' ' .
            'WHERE ' . $whereClause . ' ' .
            'ORDER BY ' . $orderBy . ' ' .
            'LIMIT ? OFFSET ?';

        return [
            'countSql' => $countSql,
            'countParams' => $countParams,
            'selectSql' => $selectSql,
            'selectParams' => $selectParams,
            'limit' => $limit,
            'offset' => $offset
        ];
    }

    /**
     * Get a plugin by slug with full details
     *
     * @param string $slug Plugin slug
     * @return array|null
     */
    public static function getPluginBySlug(string $slug): ?array {
        $plugin = R::getRow(
            'SELECT p.*, pc.name as category_name, pc.slug as category_slug, pc.icon as category_icon ' .
            'FROM plugin p LEFT JOIN plugincategory pc ON p.category_id = pc.id ' .
            'WHERE p.slug = ? AND p.is_active = 1',
            [$slug]
        );

        if (!$plugin) {
            return null;
        }

        $plugin['tags'] = self::getPluginTags((int) $plugin['id']);
        return $plugin;
    }

    /**
     * Get a plugin by ID with full details
     *
     * @param int $id Plugin ID
     * @return array|null
     */
    public static function getPlugin(int $id): ?array {
        $plugin = R::getRow(
            'SELECT p.*, pc.name as category_name, pc.slug as category_slug, pc.icon as category_icon ' .
            'FROM plugin p LEFT JOIN plugincategory pc ON p.category_id = pc.id ' .
            'WHERE p.id = ? AND p.is_active = 1',
            [$id]
        );

        if (!$plugin) {
            return null;
        }

        $plugin['tags'] = self::getPluginTags($id);
        return $plugin;
    }

    /**
     * Get featured plugins
     *
     * @param int $limit Max plugins to return
     * @return array
     */
    public static function getFeaturedPlugins(int $limit = 6): array {
        $result = self::getPlugins([
            'is_featured' => true,
            'sort' => 'downloads',
            'limit' => $limit
        ]);
        return $result['plugins'];
    }

    /**
     * Get popular plugins (by downloads)
     *
     * @param int $limit Max plugins to return
     * @return array
     */
    public static function getPopularPlugins(int $limit = 6): array {
        $result = self::getPlugins([
            'sort' => 'downloads',
            'order' => 'desc',
            'limit' => $limit
        ]);
        return $result['plugins'];
    }

    /**
     * Get plugins in a category
     *
     * @param int $categoryId Category ID
     * @param array $filters Additional filters
     * @return array
     */
    public static function getPluginsByCategory(int $categoryId, array $filters = []): array {
        $filters['category_id'] = $categoryId;
        return self::getPlugins($filters);
    }

    /**
     * Get plugins by tag(s)
     *
     * @param array $tagSlugs Tag slugs
     * @param array $filters Additional filters
     * @return array
     */
    public static function getPluginsByTags(array $tagSlugs, array $filters = []): array {
        $filters['tag_slugs'] = $tagSlugs;
        return self::getPlugins($filters);
    }

    // ==================== Statistics ====================

    /**
     * Get plugin registry statistics
     *
     * @return array
     */
    public static function getStats(): array {
        return [
            'total_plugins' => R::count('plugin', ' is_active = 1 '),
            'total_categories' => R::count('plugincategory'),
            'total_tags' => R::count('plugintag'),
            'featured_count' => R::count('plugin', ' is_active = 1 AND is_featured = 1 '),
            'total_downloads' => (int) R::getCell('SELECT SUM(downloads) FROM plugin WHERE is_active = 1')
        ];
    }

    // ==================== Filter Counts ====================

    /**
     * Get tag counts for a set of plugins (for filter display)
     *
     * @param array $filters Current filters (excluding tags)
     * @return array Tag data with counts
     */
    public static function getTagCountsForFilters(array $filters = []): array {
        // Remove tag filters to get base plugin set
        unset($filters['tag_ids'], $filters['tag_slugs']);

        $conditions = ['p.is_active = 1'];
        $params = [];

        // Filter by category ID
        if (!empty($filters['category_id'])) {
            $conditions[] = 'p.category_id = ?';
            $params[] = (int) $filters['category_id'];
        }

        // Filter by category slug
        if (!empty($filters['category_slug'])) {
            $conditions[] = 'c.slug = ?';
            $params[] = $filters['category_slug'];
        }

        // Search
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $conditions[] = '(p.name LIKE ? OR p.short_description LIKE ? OR p.description LIKE ?)';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $whereClause = implode(' AND ', $conditions);

        $useCategoryJoin = !empty($filters['category_slug']);
        $categoryJoin = $useCategoryJoin ? 'INNER JOIN plugincategory c ON p.category_id = c.id ' : '';

        $sql = 'SELECT t.id, t.name, t.slug, COUNT(DISTINCT pt.plugin_id) as count ' .
            'FROM plugintag t ' .
            'INNER JOIN plugin_plugintag pt ON t.id = pt.plugintag_id ' .
            'INNER JOIN plugin p ON pt.plugin_id = p.id ' .
            $categoryJoin .
            'WHERE ' . $whereClause . ' ' .
            'GROUP BY t.id, t.name, t.slug ' .
            'HAVING count > 0 ' .
            'ORDER BY count DESC, t.name ASC';

        return R::getAll($sql, $params);
    }

    /**
     * Get category counts (plugins per category)
     *
     * @return array
     */
    public static function getCategoryCounts(): array {
        return R::getAll(
            'SELECT c.id, c.name, c.slug, c.icon, c.plugin_count as count ' .
            'FROM plugincategory c WHERE c.plugin_count > 0 ' .
            'ORDER BY c.display_order ASC, c.name ASC'
        );
    }

    // ==================== Cache Management ====================

    /**
     * Recalculate and update plugin counts for categories
     */
    public static function updateCategoryCounts(): void {
        R::exec(
            'UPDATE plugincategory pc SET plugin_count = (' .
            'SELECT COUNT(*) FROM plugin p WHERE p.category_id = pc.id AND p.is_active = 1)'
        );
    }

    /**
     * Recalculate and update plugin counts for tags
     */
    public static function updateTagCounts(): void {
        R::exec(
            'UPDATE plugintag pt SET plugin_count = (' .
            'SELECT COUNT(DISTINCT pp.plugin_id) FROM plugin_plugintag pp ' .
            'INNER JOIN plugin p ON pp.plugin_id = p.id ' .
            'WHERE pp.plugintag_id = pt.id AND p.is_active = 1)'
        );
    }

    /**
     * Recalculate all counts
     */
    public static function updateAllCounts(): void {
        self::updateCategoryCounts();
        self::updateTagCounts();
    }
}
