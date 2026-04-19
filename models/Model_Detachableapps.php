<?php
/**
 * Detachableapps Model
 * FUSE model for detachableapps table
 *
 * Standalone Docker app packages built from Stitch screens + pipeline bindings.
 */

use \app\Bean;

class Model_Detachableapps extends \RedBeanPHP\SimpleModel
{
    private const VALID_BUILD_STATUSES = ['idle', 'building', 'failed'];

    public function dispense()
    {
        $this->bean->build_status = 'idle';
        $this->bean->config_json = '{}';
        $this->bean->is_hosted = 0;
        $this->bean->created_at = date('Y-m-d H:i:s');
    }

    public function update()
    {
        $this->bean->updated_at = date('Y-m-d H:i:s');

        // Auto-generate slug from name
        if (empty($this->bean->slug) && !empty($this->bean->name)) {
            $this->bean->slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($this->bean->name)));
            $this->bean->slug = trim($this->bean->slug, '-');
        }

        // Validate build_status
        if ($this->bean->build_status && !in_array($this->bean->build_status, self::VALID_BUILD_STATUSES)) {
            $this->bean->build_status = 'idle';
        }

        // Validate config_json
        if ($this->bean->config_json) {
            $decoded = json_decode($this->bean->config_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->bean->config_json = '{}';
            }
        }
    }

    /**
     * Get config as array
     */
    public function getConfig(): array
    {
        return json_decode($this->bean->config_json ?: '{}', true) ?: [];
    }

    /**
     * Get the output directory for built files
     */
    public function getOutputDir(): string
    {
        $workspace = $this->bean->workspace ?: 'default';
        return "storage/detachable/{$workspace}/{$this->bean->slug}";
    }

    /**
     * Get the linked API key bean
     */
    public function getApiKey(): ?\RedBeanPHP\OODBBean
    {
        if (!$this->bean->apikeys_id) return null;
        $key = Bean::load('apikeys', (int) $this->bean->apikeys_id);
        return ($key && $key->id) ? $key : null;
    }

    /**
     * Check if this app was created from a template
     */
    public function isTemplateBased(): bool
    {
        return !empty($this->bean->template_slug);
    }

    /**
     * Export as array for API responses
     */
    public function toArray(): array
    {
        return [
            'id' => (int) $this->bean->id,
            'name' => $this->bean->name,
            'slug' => $this->bean->slug,
            'description' => $this->bean->description,
            'template_slug' => $this->bean->template_slug,
            'stitch_connection_eid' => $this->bean->stitch_connection_eid ? (int) $this->bean->stitch_connection_eid : null,
            'stitch_project_eid' => $this->bean->stitch_project_eid,
            'current_version' => $this->bean->current_version,
            'build_status' => $this->bean->build_status,
            'git_repo_url' => $this->bean->git_repo_url,
            'is_hosted' => (bool) $this->bean->is_hosted,
            'config' => $this->getConfig(),
            'created_at' => $this->bean->created_at,
            'updated_at' => $this->bean->updated_at,
        ];
    }
}
