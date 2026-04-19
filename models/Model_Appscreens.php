<?php
/**
 * Appscreens Model
 * FUSE model for appscreens table
 *
 * Each screen belongs to a tenant app (builder type) and stores
 * an ordered array of block definitions as JSON.
 */

use \RedBeanPHP\R as R;

class Model_Appscreens extends \RedBeanPHP\SimpleModel
{
    private const VALID_LAYOUTS = ['single', 'sidebar', 'dashboard'];

    /**
     * Called after dispense (when creating a new bean)
     */
    public function dispense()
    {
        $this->bean->layout_type = 'single';
        $this->bean->blocks_json = '[]';
        $this->bean->page_config_json = '{}';
        $this->bean->is_default = 0;
        $this->bean->sort_order = 0;
        $this->bean->created_at = date('Y-m-d H:i:s');
    }

    /**
     * Called before storing the bean
     */
    public function update()
    {
        $this->bean->updated_at = date('Y-m-d H:i:s');

        // Auto-generate slug from name if empty
        if (empty($this->bean->slug) && !empty($this->bean->name)) {
            $this->bean->slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($this->bean->name)));
            $this->bean->slug = trim($this->bean->slug, '-');
        }

        // Auto-generate route_path from slug if empty
        if (empty($this->bean->route_path) && !empty($this->bean->slug)) {
            $this->bean->route_path = '/' . $this->bean->slug;
        }

        // Default screen gets root route
        if ($this->bean->is_default) {
            $this->bean->route_path = '/';
        }

        // Validate layout_type
        if (!in_array($this->bean->layout_type, self::VALID_LAYOUTS)) {
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
     * Add a block to the screen
     */
    public function addBlock(array $block): void
    {
        $blocks = $this->getBlocks();

        // Assign an ID if not present
        if (empty($block['id'])) {
            $block['id'] = 'block_' . bin2hex(random_bytes(4));
        }

        $blocks[] = $block;
        $this->setBlocks($blocks);
    }

    /**
     * Update a specific block by ID
     */
    public function updateBlock(string $blockId, array $blockData): bool
    {
        $blocks = $this->getBlocks();
        foreach ($blocks as $i => $block) {
            if (($block['id'] ?? '') === $blockId) {
                $blocks[$i] = array_merge($block, $blockData);
                $this->setBlocks($blocks);
                return true;
            }
        }
        return false;
    }

    /**
     * Remove a block by ID
     */
    public function removeBlock(string $blockId): bool
    {
        $blocks = $this->getBlocks();
        $filtered = array_values(array_filter($blocks, fn($b) => ($b['id'] ?? '') !== $blockId));
        if (count($filtered) !== count($blocks)) {
            $this->setBlocks($filtered);
            return true;
        }
        return false;
    }

    /**
     * Reorder blocks
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

    /**
     * Export as array for API responses
     */
    public function toArray(): array
    {
        return [
            'id' => (int) $this->bean->id,
            'tenantapps_id' => (int) $this->bean->tenantapps_id,
            'name' => $this->bean->name,
            'slug' => $this->bean->slug,
            'route_path' => $this->bean->route_path,
            'layout_type' => $this->bean->layout_type,
            'blocks' => $this->getBlocks(),
            'page_config' => $this->getPageConfig(),
            'is_default' => (bool) $this->bean->is_default,
            'sort_order' => (int) $this->bean->sort_order,
            'created_at' => $this->bean->created_at,
            'updated_at' => $this->bean->updated_at,
        ];
    }
}
