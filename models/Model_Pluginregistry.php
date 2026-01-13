<?php
/**
 * Pluginregistry Model
 * FUSE model for pluginregistry table
 *
 * Tracks installed plugins with version information and update status.
 *
 * @see GitHub Issue #104
 */

use \RedBeanPHP\R as R;
use \app\lib\SemVer;

class Model_Pluginregistry extends \RedBeanPHP\SimpleModel {

    /**
     * Valid plugin statuses
     */
    private const VALID_STATUSES = ['active', 'inactive', 'disabled'];

    /**
     * Valid update types
     */
    private const VALID_UPDATE_TYPES = ['major', 'minor', 'patch'];

    /**
     * Called before storing the bean
     * Auto-sets updated_at timestamp and validates data
     */
    public function update() {
        $this->bean->updated_at = date('Y-m-d H:i:s');

        // Validate status
        if ($this->bean->status && !in_array($this->bean->status, self::VALID_STATUSES)) {
            $this->bean->status = 'active';
        }

        // Validate update_type
        if ($this->bean->update_type && !in_array($this->bean->update_type, self::VALID_UPDATE_TYPES)) {
            $this->bean->update_type = null;
        }

        // Ensure slug is lowercase and valid
        if ($this->bean->slug) {
            $this->bean->slug = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '-', $this->bean->slug));
        }

        // Validate installed_version is valid semver
        if ($this->bean->installed_version && !SemVer::isValid($this->bean->installed_version)) {
            // Attempt to normalize, or keep as-is for loose version formats
            $normalized = SemVer::normalize($this->bean->installed_version);
            if ($normalized !== null) {
                $this->bean->installed_version = $normalized;
            }
        }
    }

    /**
     * Called after dispense (when creating a new bean)
     * Sets default values
     */
    public function dispense() {
        $this->bean->status = 'active';
        $this->bean->update_available = false;
        $this->bean->installed_at = date('Y-m-d H:i:s');
        $this->bean->created_at = date('Y-m-d H:i:s');
    }

    /**
     * Check if the plugin has an available update
     *
     * @return bool True if an update is available
     */
    public function hasUpdate(): bool {
        return (bool) $this->bean->update_available;
    }

    /**
     * Get the latest available version
     *
     * @return string|null Latest version or null if not checked
     */
    public function getLatestVersion(): ?string {
        return $this->bean->latest_version ?: null;
    }

    /**
     * Mark that an update is available
     *
     * @param string $version The latest available version
     * @param string $type The update type: 'major', 'minor', or 'patch'
     * @return void
     */
    public function markUpdateAvailable(string $version, string $type): void {
        if (!in_array($type, self::VALID_UPDATE_TYPES)) {
            throw new \InvalidArgumentException("Invalid update type: {$type}");
        }

        $this->bean->latest_version = $version;
        $this->bean->update_available = true;
        $this->bean->update_type = $type;
        $this->bean->last_checked_at = date('Y-m-d H:i:s');
    }

    /**
     * Mark that no update is available (plugin is up to date)
     *
     * @return void
     */
    public function markUpToDate(): void {
        $this->bean->update_available = false;
        $this->bean->update_type = null;
        $this->bean->latest_version = $this->bean->installed_version;
        $this->bean->last_checked_at = date('Y-m-d H:i:s');
    }

    /**
     * Update the installed version after applying an update
     *
     * @param string $version The new installed version
     * @return void
     */
    public function updateInstalledVersion(string $version): void {
        $this->bean->installed_version = $version;
        $this->bean->update_available = false;
        $this->bean->update_type = null;
        $this->bean->latest_version = $version;
    }

    /**
     * Check if plugin is active
     *
     * @return bool True if plugin status is 'active'
     */
    public function isActive(): bool {
        return $this->bean->status === 'active';
    }

    /**
     * Activate the plugin
     *
     * @return void
     */
    public function activate(): void {
        $this->bean->status = 'active';
    }

    /**
     * Deactivate the plugin (can be reactivated)
     *
     * @return void
     */
    public function deactivate(): void {
        $this->bean->status = 'inactive';
    }

    /**
     * Disable the plugin (requires manual intervention to re-enable)
     *
     * @return void
     */
    public function disable(): void {
        $this->bean->status = 'disabled';
    }

    /**
     * Get the plugin's version history (related pluginversion beans)
     * Uses RedBeanPHP association with ordering
     *
     * @param int|null $limit Maximum number of versions to return
     * @return array Array of pluginversion beans
     */
    public function getVersionHistory(?int $limit = null): array {
        $sql = ' ORDER BY released_at DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        return $this->bean->with($sql)->ownPluginversionList;
    }

    /**
     * Get the most recent version record from history
     *
     * @return \RedBeanPHP\OODBBean|null Most recent version bean or null
     */
    public function getMostRecentVersion(): ?\RedBeanPHP\OODBBean {
        $versions = $this->getVersionHistory(1);
        return !empty($versions) ? reset($versions) : null;
    }

    /**
     * Check if this is a major update
     *
     * @return bool True if update_type is 'major'
     */
    public function isMajorUpdate(): bool {
        return $this->hasUpdate() && $this->bean->update_type === 'major';
    }

    /**
     * Check if this is a minor update
     *
     * @return bool True if update_type is 'minor'
     */
    public function isMinorUpdate(): bool {
        return $this->hasUpdate() && $this->bean->update_type === 'minor';
    }

    /**
     * Check if this is a patch update
     *
     * @return bool True if update_type is 'patch'
     */
    public function isPatchUpdate(): bool {
        return $this->hasUpdate() && $this->bean->update_type === 'patch';
    }

    /**
     * Get time since last update check
     *
     * @return \DateInterval|null Time difference or null if never checked
     */
    public function getTimeSinceLastCheck(): ?\DateInterval {
        if (!$this->bean->last_checked_at) {
            return null;
        }

        $lastCheck = new \DateTime($this->bean->last_checked_at);
        $now = new \DateTime();

        return $now->diff($lastCheck);
    }

    /**
     * Check if an update check is needed (older than specified interval)
     *
     * @param int $hours Hours since last check before re-check is needed
     * @return bool True if update check is needed
     */
    public function needsUpdateCheck(int $hours = 24): bool {
        if (!$this->bean->last_checked_at) {
            return true;
        }

        $lastCheck = new \DateTime($this->bean->last_checked_at);
        $threshold = new \DateTime("-{$hours} hours");

        return $lastCheck < $threshold;
    }

    /**
     * Export plugin info as an array (for API responses)
     *
     * @return array Plugin information
     */
    public function toArray(): array {
        return [
            'id' => $this->bean->id,
            'name' => $this->bean->name,
            'slug' => $this->bean->slug,
            'description' => $this->bean->description,
            'author' => $this->bean->author,
            'installed_version' => $this->bean->installed_version,
            'latest_version' => $this->bean->latest_version,
            'update_available' => $this->hasUpdate(),
            'update_type' => $this->bean->update_type,
            'status' => $this->bean->status,
            'repository_url' => $this->bean->repository_url,
            'homepage_url' => $this->bean->homepage_url,
            'installed_at' => $this->bean->installed_at,
            'last_checked_at' => $this->bean->last_checked_at,
        ];
    }
}
