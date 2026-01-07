<?php
/**
 * Jiraboards Model
 * FUSE model for jiraboards table
 *
 * All data is now in a single MySQL database per tenant.
 */

use \RedBeanPHP\R as R;

class Model_Jiraboards extends \RedBeanPHP\SimpleModel {

    /**
     * Check if board has AI Developer enabled
     *
     * @return bool
     */
    public function isAiDevEnabled(): bool {
        return !empty($this->bean->aidev_enabled);
    }

    /**
     * Check if board uses local runner (vs shard/API)
     *
     * @return bool
     */
    public function usesLocalRunner(): bool {
        return empty($this->bean->aidev_anthropic_key_id);
    }

    /**
     * Get the board's project key
     *
     * @return string|null
     */
    public function getProjectKey(): ?string {
        return $this->bean->project_key ?? null;
    }
}
