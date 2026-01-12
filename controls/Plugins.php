<?php
/**
 * Plugins Controller
 *
 * Handles plugin registry browsing, category filtering, and tag-based discovery.
 * Public access (level 101) - no login required for browsing.
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;

require_once __DIR__ . '/../services/PluginRegistryService.php';

use \app\services\PluginRegistryService;

class Plugins extends BaseControls\Control {

    /**
     * Main plugin registry - browse all plugins
     * Route: /plugins
     */
    public function index() {
        // Get filter parameters from query string
        $categorySlug = $this->getParam('category');
        $tagSlugs = $this->getParam('tags');
        $search = $this->getParam('search');
        $sort = $this->getParam('sort', 'downloads');
        $page = max(1, (int) $this->getParam('page', 1));
        $perPage = 12;

        // Parse tag slugs from comma-separated string
        $selectedTags = [];
        if (!empty($tagSlugs)) {
            $selectedTags = array_filter(array_map('trim', explode(',', $tagSlugs)));
        }

        // Build filters
        $filters = [
            'sort' => $sort,
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage
        ];

        if (!empty($categorySlug)) {
            $filters['category_slug'] = $categorySlug;
        }

        if (!empty($selectedTags)) {
            $filters['tag_slugs'] = $selectedTags;
        }

        if (!empty($search)) {
            $filters['search'] = $search;
        }

        // Get plugins with filters
        $result = PluginRegistryService::getPlugins($filters);
        $plugins = $result['plugins'];
        $totalPlugins = $result['total'];
        $totalPages = ceil($totalPlugins / $perPage);

        // Get categories for sidebar
        $categories = PluginRegistryService::getCategories();

        // Get tag counts based on current category/search filters
        $tagFilters = [];
        if (!empty($categorySlug)) {
            $tagFilters['category_slug'] = $categorySlug;
        }
        if (!empty($search)) {
            $tagFilters['search'] = $search;
        }
        $tags = PluginRegistryService::getTagCountsForFilters($tagFilters);

        // Get featured plugins for sidebar (only on first page with no filters)
        $featuredPlugins = [];
        if ($page === 1 && empty($categorySlug) && empty($selectedTags) && empty($search)) {
            $featuredPlugins = PluginRegistryService::getFeaturedPlugins(3);
        }

        // Get current category details if filtering by category
        $currentCategory = null;
        if (!empty($categorySlug)) {
            $currentCategory = PluginRegistryService::getCategoryBySlug($categorySlug);
        }

        // Get statistics
        $stats = PluginRegistryService::getStats();

        $this->render('plugins/index', [
            'title' => $currentCategory ? 'Plugins - ' . $currentCategory->name : 'Plugin Registry',
            'plugins' => $plugins,
            'categories' => $categories,
            'tags' => $tags,
            'featuredPlugins' => $featuredPlugins,
            'currentCategory' => $currentCategory,
            'selectedTags' => $selectedTags,
            'search' => $search,
            'sort' => $sort,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalPlugins' => $totalPlugins,
            'perPage' => $perPage,
            'stats' => $stats
        ]);
    }

    /**
     * Browse plugins by category
     * Route: /plugins/category/{slug}
     *
     * @param array $params Route parameters
     */
    public function category($params = []) {
        // Get category slug from URL
        $slug = $params['operation']->name ?? $this->getParam('slug');

        if (empty($slug)) {
            Flight::redirect('/plugins');
            return;
        }

        // Get category
        $category = PluginRegistryService::getCategoryBySlug($slug);
        if (!$category) {
            $this->flash('error', 'Category not found');
            Flight::redirect('/plugins');
            return;
        }

        // Get filter parameters
        $tagSlugs = $this->getParam('tags');
        $search = $this->getParam('search');
        $sort = $this->getParam('sort', 'downloads');
        $page = max(1, (int) $this->getParam('page', 1));
        $perPage = 12;

        // Parse tag slugs
        $selectedTags = [];
        if (!empty($tagSlugs)) {
            $selectedTags = array_filter(array_map('trim', explode(',', $tagSlugs)));
        }

        // Build filters
        $filters = [
            'category_id' => $category->id,
            'sort' => $sort,
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage
        ];

        if (!empty($selectedTags)) {
            $filters['tag_slugs'] = $selectedTags;
        }

        if (!empty($search)) {
            $filters['search'] = $search;
        }

        // Get plugins
        $result = PluginRegistryService::getPlugins($filters);
        $plugins = $result['plugins'];
        $totalPlugins = $result['total'];
        $totalPages = ceil($totalPlugins / $perPage);

        // Get all categories for sidebar
        $categories = PluginRegistryService::getCategories();

        // Get subcategories if this is a parent category
        $subcategories = PluginRegistryService::getSubcategories($category->id);

        // Get tag counts for this category
        $tags = PluginRegistryService::getTagCountsForFilters([
            'category_id' => $category->id,
            'search' => $search
        ]);

        $this->render('plugins/category', [
            'title' => 'Plugins - ' . $category->name,
            'category' => $category,
            'subcategories' => $subcategories,
            'plugins' => $plugins,
            'categories' => $categories,
            'tags' => $tags,
            'selectedTags' => $selectedTags,
            'search' => $search,
            'sort' => $sort,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalPlugins' => $totalPlugins,
            'perPage' => $perPage
        ]);
    }

    /**
     * View plugin details
     * Route: /plugins/view/{slug}
     *
     * @param array $params Route parameters
     */
    public function view($params = []) {
        // Get plugin slug from URL
        $slug = $params['operation']->name ?? $this->getParam('slug');

        if (empty($slug)) {
            Flight::redirect('/plugins');
            return;
        }

        // Get plugin details
        $plugin = PluginRegistryService::getPluginBySlug($slug);
        if (!$plugin) {
            $this->flash('error', 'Plugin not found');
            Flight::redirect('/plugins');
            return;
        }

        // Get related plugins (same category)
        $relatedPlugins = [];
        if (!empty($plugin['category_id'])) {
            $result = PluginRegistryService::getPlugins([
                'category_id' => $plugin['category_id'],
                'limit' => 4
            ]);
            // Filter out current plugin
            $relatedPlugins = array_filter($result['plugins'], function($p) use ($plugin) {
                return $p['id'] !== $plugin['id'];
            });
            $relatedPlugins = array_slice($relatedPlugins, 0, 3);
        }

        $this->render('plugins/view', [
            'title' => $plugin['name'] . ' - Plugin Registry',
            'plugin' => $plugin,
            'relatedPlugins' => $relatedPlugins
        ]);
    }

    /**
     * Search plugins (AJAX endpoint)
     * Route: /plugins/search
     */
    public function search() {
        $search = $this->getParam('q', '');
        $categorySlug = $this->getParam('category');
        $tagSlugs = $this->getParam('tags');
        $limit = min(max((int) $this->getParam('limit', 10), 1), 50);

        // Parse tag slugs
        $selectedTags = [];
        if (!empty($tagSlugs)) {
            $selectedTags = array_filter(array_map('trim', explode(',', $tagSlugs)));
        }

        // Build filters
        $filters = [
            'search' => $search,
            'limit' => $limit
        ];

        if (!empty($categorySlug)) {
            $filters['category_slug'] = $categorySlug;
        }

        if (!empty($selectedTags)) {
            $filters['tag_slugs'] = $selectedTags;
        }

        $result = PluginRegistryService::getPlugins($filters);

        $this->jsonSuccess([
            'plugins' => $result['plugins'],
            'total' => $result['total']
        ]);
    }

    /**
     * Get filter counts (AJAX endpoint)
     * Route: /plugins/filters
     */
    public function filters() {
        $categorySlug = $this->getParam('category');
        $search = $this->getParam('search');

        $filters = [];
        if (!empty($categorySlug)) {
            $filters['category_slug'] = $categorySlug;
        }
        if (!empty($search)) {
            $filters['search'] = $search;
        }

        $tags = PluginRegistryService::getTagCountsForFilters($filters);
        $categories = PluginRegistryService::getCategoryCounts();

        $this->jsonSuccess([
            'tags' => $tags,
            'categories' => $categories
        ]);
    }
}
