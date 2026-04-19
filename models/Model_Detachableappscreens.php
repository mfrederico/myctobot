<?php
/**
 * Detachableappscreens Model
 * FUSE model for detachableappscreens table
 *
 * Tracks screens with route mappings and cached HTML.
 * Belongs to detachableapps via detachableapps_id.
 * Supports both Stitch-sourced screens and block editor screens.
 */

class Model_Detachableappscreens extends \RedBeanPHP\SimpleModel
{
    private const VALID_LAYOUTS = ['single', 'sidebar', 'dashboard'];

    public function dispense()
    {
        $this->bean->is_default = 0;
        $this->bean->sort_order = 0;
        $this->bean->blocks_json = '[]';
        $this->bean->page_config_json = '{}';
        $this->bean->layout_type = 'single';
        $this->bean->created_at = date('Y-m-d H:i:s');
    }

    public function update()
    {
        $this->bean->updated_at = date('Y-m-d H:i:s');

        // Auto-generate route_path from screen_title if empty
        if (empty($this->bean->route_path) && !empty($this->bean->screen_title)) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($this->bean->screen_title)));
            $this->bean->route_path = '/' . trim($slug, '-');
        }

        // Default screen gets root route
        if ($this->bean->is_default) {
            $this->bean->route_path = '/';
        }

        // Validate layout_type
        if ($this->bean->layout_type && !in_array($this->bean->layout_type, self::VALID_LAYOUTS)) {
            $this->bean->layout_type = 'single';
        }

        // Validate blocks_json
        if ($this->bean->blocks_json) {
            $decoded = json_decode($this->bean->blocks_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->bean->blocks_json = '[]';
            }
        }

        // Validate page_config_json
        if ($this->bean->page_config_json) {
            $decoded = json_decode($this->bean->page_config_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->bean->page_config_json = '{}';
            }
        }
    }

    /**
     * Check if the cached HTML is stale (older than 24 hours)
     */
    public function isCacheStale(): bool
    {
        if (empty($this->bean->html_cached_at)) return true;
        $cachedAt = new \DateTime($this->bean->html_cached_at);
        $threshold = new \DateTime('-24 hours');
        return $cachedAt < $threshold;
    }

    /**
     * Get the filename for the screen HTML (based on route_path)
     */
    public function getFilename(): string
    {
        $route = trim($this->bean->route_path ?: '/', '/');
        if (empty($route)) return 'index.html';
        return str_replace('/', '-', $route) . '.html';
    }

    // =========================================================================
    // BLOCK HELPERS (mirrors Model_Appscreens)
    // =========================================================================

    /**
     * Get blocks as array
     */
    public function getBlocks(): array
    {
        return json_decode($this->bean->blocks_json ?: '[]', true) ?: [];
    }

    /**
     * Set blocks from array
     */
    public function setBlocks(array $blocks): void
    {
        $this->bean->blocks_json = json_encode($blocks);
    }

    /**
     * Reorder blocks by ID array
     */
    public function reorderBlocks(array $blockIds): void
    {
        $blocks = $this->getBlocks();
        $indexed = [];
        foreach ($blocks as $block) {
            $indexed[$block['id'] ?? ''] = $block;
        }

        $reordered = [];
        foreach ($blockIds as $id) {
            if (isset($indexed[$id])) {
                $reordered[] = $indexed[$id];
                unset($indexed[$id]);
            }
        }

        // Append any blocks not in the order list
        foreach ($indexed as $block) {
            $reordered[] = $block;
        }

        $this->setBlocks($reordered);
    }

    /**
     * Get page config as array
     */
    public function getPageConfig(): array
    {
        return json_decode($this->bean->page_config_json ?: '{}', true) ?: [];
    }

    /**
     * Set page config from array
     */
    public function setPageConfig(array $config): void
    {
        $this->bean->page_config_json = json_encode($config);
    }

    public function toArray(): array
    {
        return [
            'id' => (int) $this->bean->id,
            'detachableapps_id' => (int) $this->bean->detachableapps_id,
            'screen_title' => $this->bean->screen_title,
            'route_path' => $this->bean->route_path,
            'layout_type' => $this->bean->layout_type ?: 'single',
            'is_default' => (bool) $this->bean->is_default,
            'sort_order' => (int) $this->bean->sort_order,
            'stitch_screen_eid' => $this->bean->stitch_screen_eid,
            'blocks' => $this->getBlocks(),
            'page_config' => $this->getPageConfig(),
            'has_cached_html' => !empty($this->bean->html_cache),
            'html_cached_at' => $this->bean->html_cached_at,
            'created_at' => $this->bean->created_at,
        ];
    }
}
