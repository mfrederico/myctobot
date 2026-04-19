<?php
/**
 * Pluginversion Model
 * FUSE model for pluginversion table
 *
 * Stores version history with release notes for each plugin.
 *
 * @see GitHub Issue #104
 */

use \RedBeanPHP\R as R;
use \app\lib\SemVer;

class Model_Pluginversion extends \RedBeanPHP\SimpleModel {

    /**
     * Called before storing the bean
     * Validates data and sets created_at on new beans
     */
    public function update() {
        // Normalize version string if valid
        if ($this->bean->version) {
            $normalized = SemVer::normalize($this->bean->version);
            if ($normalized !== null) {
                $this->bean->version = $normalized;
            }
        }

        // Ensure is_prerelease matches the version string
        if ($this->bean->version) {
            $this->bean->is_prerelease = SemVer::isPrerelease($this->bean->version) ? 1 : 0;
        }
    }

    /**
     * Called after dispense (when creating a new bean)
     * Sets default values
     */
    public function dispense() {
        $this->bean->is_prerelease = false;
        $this->bean->created_at = date('Y-m-d H:i:s');
    }

    /**
     * Get the parent plugin from the registry
     * Uses RedBeanPHP association
     *
     * @return \RedBeanPHP\OODBBean|null Parent pluginregistry bean
     */
    public function getPlugin(): ?\RedBeanPHP\OODBBean {
        return $this->bean->pluginregistry;
    }

    /**
     * Check if this is a pre-release version
     *
     * @return bool True if this is a pre-release (alpha, beta, rc, etc.)
     */
    public function isPrerelease(): bool {
        return (bool) $this->bean->is_prerelease;
    }

    /**
     * Check if this version is newer than another version
     *
     * @param string $otherVersion Version to compare against
     * @return bool True if this version is newer
     */
    public function isNewerThan(string $otherVersion): bool {
        return SemVer::compare($this->bean->version, $otherVersion) > 0;
    }

    /**
     * Check if this version is older than another version
     *
     * @param string $otherVersion Version to compare against
     * @return bool True if this version is older
     */
    public function isOlderThan(string $otherVersion): bool {
        return SemVer::compare($this->bean->version, $otherVersion) < 0;
    }

    /**
     * Get the parsed version components
     *
     * @return array|null Parsed version (major, minor, patch, prerelease, build) or null
     */
    public function getParsedVersion(): ?array {
        return SemVer::parse($this->bean->version);
    }

    /**
     * Get formatted release date
     *
     * @param string $format PHP date format string
     * @return string|null Formatted date or null if not set
     */
    public function getFormattedReleaseDate(string $format = 'F j, Y'): ?string {
        if (!$this->bean->released_at) {
            return null;
        }

        $date = new \DateTime($this->bean->released_at);
        return $date->format($format);
    }

    /**
     * Get time since release
     *
     * @return string Human-readable time difference (e.g., "3 days ago")
     */
    public function getTimeSinceRelease(): string {
        if (!$this->bean->released_at) {
            return 'unknown';
        }

        $released = new \DateTime($this->bean->released_at);
        $now = new \DateTime();
        $diff = $now->diff($released);

        if ($diff->y > 0) {
            return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
        }
        if ($diff->m > 0) {
            return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
        }
        if ($diff->d > 0) {
            return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
        }
        if ($diff->h > 0) {
            return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
        }
        if ($diff->i > 0) {
            return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
        }

        return 'just now';
    }

    /**
     * Get summary of release notes (first paragraph or truncated)
     *
     * @param int $maxLength Maximum length of summary
     * @return string|null Summary or null if no release notes
     */
    public function getReleaseNotesSummary(int $maxLength = 200): ?string {
        if (!$this->bean->release_notes) {
            return null;
        }

        $notes = $this->bean->release_notes;

        // Get first paragraph
        $paragraphs = preg_split('/\n\n+/', $notes, 2);
        $summary = $paragraphs[0];

        // Truncate if too long
        if (strlen($summary) > $maxLength) {
            $summary = substr($summary, 0, $maxLength - 3) . '...';
        }

        return $summary;
    }

    /**
     * Check if this version has release notes
     *
     * @return bool True if release notes exist
     */
    public function hasReleaseNotes(): bool {
        return !empty($this->bean->release_notes);
    }

    /**
     * Check if this version has a download URL
     *
     * @return bool True if download URL exists
     */
    public function hasDownloadUrl(): bool {
        return !empty($this->bean->download_url);
    }

    /**
     * Get the update type from the previously installed version to this one
     *
     * @param string $fromVersion The version being updated from
     * @return string|null 'major', 'minor', 'patch', or null
     */
    public function getUpdateTypeFrom(string $fromVersion): ?string {
        return SemVer::getUpdateType($fromVersion, $this->bean->version);
    }

    /**
     * Export version info as an array (for API responses)
     *
     * @return array Version information
     */
    public function toArray(): array {
        return [
            'id' => $this->bean->id,
            'version' => $this->bean->version,
            'release_notes' => $this->bean->release_notes,
            'release_notes_summary' => $this->getReleaseNotesSummary(),
            'download_url' => $this->bean->download_url,
            'is_prerelease' => $this->isPrerelease(),
            'released_at' => $this->bean->released_at,
            'time_since_release' => $this->getTimeSinceRelease(),
            'created_at' => $this->bean->created_at,
        ];
    }
}
