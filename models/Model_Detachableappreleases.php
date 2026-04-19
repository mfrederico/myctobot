<?php
/**
 * Detachableappreleases Model
 * FUSE model for detachableappreleases table
 *
 * Tracks release history for detachable apps (version, git commit, notes).
 * Belongs to detachableapps via detachableapps_id.
 */

class Model_Detachableappreleases extends \RedBeanPHP\SimpleModel
{
    public function dispense()
    {
        $this->bean->screens_snapshot_json = '[]';
        $this->bean->created_at = date('Y-m-d H:i:s');
    }

    public function update()
    {
        // Validate screens_snapshot_json
        if ($this->bean->screens_snapshot_json) {
            $decoded = json_decode($this->bean->screens_snapshot_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->bean->screens_snapshot_json = '[]';
            }
        }
    }

    /**
     * Get screen snapshot as array
     */
    public function getScreensSnapshot(): array
    {
        return json_decode($this->bean->screens_snapshot_json ?: '[]', true) ?: [];
    }

    public function toArray(): array
    {
        return [
            'id' => (int) $this->bean->id,
            'detachableapps_id' => (int) $this->bean->detachableapps_id,
            'version' => $this->bean->version,
            'git_commit_sha' => $this->bean->git_commit_sha,
            'git_tag' => $this->bean->git_tag,
            'release_notes' => $this->bean->release_notes,
            'built_by_member_id' => $this->bean->built_by_member_id ? (int) $this->bean->built_by_member_id : null,
            'created_at' => $this->bean->created_at,
        ];
    }
}
