<?php
/**
 * Plugins Controller
 *
 * Handles plugin marketplace listing with search and autocomplete functionality.
 * Search results are ranked by relevance:
 * - Exact name match: 100 points
 * - Name contains: 80 points
 * - Description contains: 50 points
 * - Tags contain: 30 points
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\services\PluginSearchService;

require_once __DIR__ . '/../services/PluginSearchService.php';

class Plugins extends BaseControls\Control {

    /**
     * Plugin marketplace index with search box
     */
    public function index($params = []) {
        if (!$this->requireLogin()) return;

        $query = trim($this->getParam('q', ''));
        $category = $this->getParam('category', '');
        $page = max(1, (int) $this->getParam('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        // Get plugins based on search or default listing
        if (!empty($query)) {
            $plugins = PluginSearchService::search($query, [
                'category' => $category,
                'limit' => $limit,
                'offset' => $offset
            ]);
            $noResultsSuggestions = empty($plugins)
                ? PluginSearchService::getNoResultsSuggestions($query)
                : null;
        } else {
            $plugins = PluginSearchService::getDefaultResults($category ?: null, $limit, $offset);
            $noResultsSuggestions = null;
        }

        // Get categories for filtering
        $categories = PluginSearchService::getCategoriesWithCounts();

        // Calculate pagination info
        $totalCount = $this->getPluginCount($query, $category);
        $totalPages = max(1, ceil($totalCount / $limit));

        $this->viewData['title'] = 'Plugin Marketplace';
        $this->viewData['plugins'] = $plugins;
        $this->viewData['query'] = $query;
        $this->viewData['category'] = $category;
        $this->viewData['categories'] = $categories;
        $this->viewData['noResultsSuggestions'] = $noResultsSuggestions;
        $this->viewData['pagination'] = [
            'page' => $page,
            'limit' => $limit,
            'total' => $totalCount,
            'totalPages' => $totalPages,
            'hasNext' => $page < $totalPages,
            'hasPrev' => $page > 1
        ];

        $this->render('plugins/index', $this->viewData);
    }

    /**
     * Search API endpoint - returns JSON
     */
    public function search($params = []) {
        if (!$this->requireLogin()) return;

        $query = trim($this->getParam('q', ''));
        $category = $this->getParam('category', '');
        $limit = min((int) $this->getParam('limit', 20), 100);
        $offset = (int) $this->getParam('offset', 0);

        try {
            if (empty($query)) {
                $results = PluginSearchService::getDefaultResults(
                    $category ?: null,
                    $limit,
                    $offset
                );
                $suggestions = null;
            } else {
                $results = PluginSearchService::search($query, [
                    'category' => $category,
                    'limit' => $limit,
                    'offset' => $offset
                ]);
                $suggestions = empty($results)
                    ? PluginSearchService::getNoResultsSuggestions($query)
                    : null;
            }

            Flight::jsonSuccess([
                'query' => $query,
                'category' => $category,
                'results' => $results,
                'count' => count($results),
                'suggestions' => $suggestions
            ]);

        } catch (Exception $e) {
            $this->logger->error('Plugin search failed: ' . $e->getMessage());
            Flight::jsonError('Search failed. Please try again.', 500);
        }
    }

    /**
     * Autocomplete API endpoint - returns JSON suggestions
     */
    public function autocomplete($params = []) {
        if (!$this->requireLogin()) return;

        $query = trim($this->getParam('q', ''));
        $limit = min((int) $this->getParam('limit', 8), 20);

        try {
            if (strlen($query) < 2) {
                Flight::jsonSuccess([
                    'query' => $query,
                    'suggestions' => []
                ]);
                return;
            }

            $suggestions = PluginSearchService::autocomplete($query, $limit);

            Flight::jsonSuccess([
                'query' => $query,
                'suggestions' => $suggestions
            ]);

        } catch (Exception $e) {
            $this->logger->error('Plugin autocomplete failed: ' . $e->getMessage());
            Flight::jsonSuccess([
                'query' => $query,
                'suggestions' => []
            ]);
        }
    }

    /**
     * View single plugin details
     */
    public function view($params = []) {
        if (!$this->requireLogin()) return;

        // Get plugin ID or slug from URL
        $idOrSlug = $params['operation']->name ?? $this->getParam('id') ?? null;

        if (empty($idOrSlug)) {
            $this->flash('error', 'Plugin not found');
            Flight::redirect('/plugins');
            return;
        }

        try {
            // Try to load by ID first, then by slug
            if (is_numeric($idOrSlug)) {
                $plugin = PluginSearchService::getById((int) $idOrSlug);
            } else {
                $plugin = PluginSearchService::getBySlug($idOrSlug);
            }

            if (!$plugin) {
                $this->flash('error', 'Plugin not found');
                Flight::redirect('/plugins');
                return;
            }

            // Get related plugins
            $relatedPlugins = PluginSearchService::getRelated($plugin['id'], 4);

            // Increment view count (async - don't block page load)
            $this->incrementViewCount($plugin['id']);

            $this->viewData['title'] = $plugin['name'] . ' - Plugin Marketplace';
            $this->viewData['plugin'] = $plugin;
            $this->viewData['relatedPlugins'] = $relatedPlugins;
            $this->viewData['categories'] = PluginSearchService::CATEGORIES;

            $this->render('plugins/view', $this->viewData);

        } catch (Exception $e) {
            $this->logger->error('Plugin view failed: ' . $e->getMessage());
            $this->flash('error', 'Failed to load plugin');
            Flight::redirect('/plugins');
        }
    }

    /**
     * Get categories list API
     */
    public function categories($params = []) {
        if (!$this->requireLogin()) return;

        try {
            $categories = PluginSearchService::getCategoriesWithCounts();

            Flight::jsonSuccess([
                'categories' => $categories
            ]);

        } catch (Exception $e) {
            $this->logger->error('Get categories failed: ' . $e->getMessage());
            Flight::jsonError('Failed to load categories', 500);
        }
    }

    /**
     * Get total plugin count for pagination
     *
     * @param string $query Search query
     * @param string $category Category filter
     * @return int Count
     */
    private function getPluginCount(string $query, string $category): int {
        try {
            $sql = "SELECT COUNT(*) FROM plugins WHERE is_active = 1";
            $params = [];

            if (!empty($query)) {
                $searchTerm = '%' . $this->sanitizeSearchTerm($query) . '%';
                $sql .= " AND (
                    LOWER(name) LIKE LOWER(?)
                    OR LOWER(short_description) LIKE LOWER(?)
                    OR LOWER(description) LIKE LOWER(?)
                )";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            if (!empty($category) && isset(PluginSearchService::CATEGORIES[$category])) {
                $sql .= " AND category = ?";
                $params[] = $category;
            }

            return (int) R::getCell($sql, $params);

        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Sanitize search term
     *
     * @param string $term Raw term
     * @return string Sanitized term
     */
    private function sanitizeSearchTerm(string $term): string {
        $term = preg_replace('/[%_\\\\]/', '', $term);
        $term = preg_replace('/\s+/', ' ', $term);
        return substr(trim($term), 0, 100);
    }

    /**
     * Increment plugin view count (silent failure)
     *
     * @param int $pluginId Plugin ID
     */
    private function incrementViewCount(int $pluginId): void {
        try {
            // NOTE: Future upgrade consideration:
            // Could track unique views per user/session for more accurate analytics
            R::exec("UPDATE plugins SET download_count = download_count + 1 WHERE id = ?", [$pluginId]);
        } catch (Exception $e) {
            // Silent failure - don't affect user experience
            $this->logger->debug('Failed to increment view count: ' . $e->getMessage());
        }
    }
}
