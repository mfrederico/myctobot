<?php
/**
 * SemVer - Semantic Versioning Utility Class
 *
 * Provides utilities for parsing, comparing, and validating semantic version strings.
 * Follows the Semantic Versioning 2.0.0 specification (https://semver.org/)
 *
 * @see https://semver.org/
 * @see GitHub Issue #104
 */

namespace app\lib;

class SemVer {

    /**
     * Regular expression pattern for semantic versioning
     * Matches: major.minor.patch[-prerelease][+build]
     */
    private const SEMVER_PATTERN = '/^v?(?P<major>0|[1-9]\d*)\.(?P<minor>0|[1-9]\d*)\.(?P<patch>0|[1-9]\d*)(?:-(?P<prerelease>(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+(?P<build>[0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

    /**
     * Simple version pattern for loose matching (allows incomplete versions)
     */
    private const LOOSE_PATTERN = '/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:[.-]?(.+))?$/i';

    /**
     * Parse a semantic version string into components
     *
     * @param string $version Version string to parse
     * @return array|null Parsed version components or null if invalid
     *                    Keys: major, minor, patch, prerelease, build
     */
    public static function parse(string $version): ?array {
        $version = trim($version);

        // Try strict semver parsing first
        if (preg_match(self::SEMVER_PATTERN, $version, $matches)) {
            // Handle empty strings from optional named groups as null
            $prerelease = isset($matches['prerelease']) && $matches['prerelease'] !== ''
                ? $matches['prerelease']
                : null;
            $build = isset($matches['build']) && $matches['build'] !== ''
                ? $matches['build']
                : null;

            return [
                'major' => (int) $matches['major'],
                'minor' => (int) $matches['minor'],
                'patch' => (int) $matches['patch'],
                'prerelease' => $prerelease,
                'build' => $build,
            ];
        }

        // Try loose parsing for common formats like "1.0" or "v2"
        if (preg_match(self::LOOSE_PATTERN, $version, $matches)) {
            $prerelease = null;
            $build = null;

            // Check if the fourth group contains prerelease or build info
            if (!empty($matches[4])) {
                $extra = $matches[4];
                // Check for build metadata first (starts with +)
                if (strpos($extra, '+') === 0) {
                    // Only build metadata, no prerelease
                    $build = substr($extra, 1);
                } elseif (strpos($extra, '+') !== false) {
                    // Both prerelease and build
                    [$prerelease, $build] = explode('+', $extra, 2);
                    if (empty($prerelease)) {
                        $prerelease = null;
                    }
                } else {
                    // Only prerelease
                    $prerelease = $extra;
                }
            }

            return [
                'major' => (int) $matches[1],
                'minor' => isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0,
                'patch' => isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : 0,
                'prerelease' => $prerelease,
                'build' => $build,
            ];
        }

        return null;
    }

    /**
     * Compare two version strings
     *
     * @param string $v1 First version
     * @param string $v2 Second version
     * @return int -1 if v1 < v2, 0 if equal, 1 if v1 > v2
     */
    public static function compare(string $v1, string $v2): int {
        $parsed1 = self::parse($v1);
        $parsed2 = self::parse($v2);

        // If either version is invalid, treat invalid versions as less than valid
        if ($parsed1 === null && $parsed2 === null) {
            return 0;
        }
        if ($parsed1 === null) {
            return -1;
        }
        if ($parsed2 === null) {
            return 1;
        }

        // Compare major.minor.patch
        foreach (['major', 'minor', 'patch'] as $part) {
            if ($parsed1[$part] < $parsed2[$part]) {
                return -1;
            }
            if ($parsed1[$part] > $parsed2[$part]) {
                return 1;
            }
        }

        // Compare pre-release (a version without pre-release is greater)
        return self::comparePrerelease($parsed1['prerelease'], $parsed2['prerelease']);
    }

    /**
     * Compare pre-release identifiers
     *
     * @param string|null $pr1 First pre-release
     * @param string|null $pr2 Second pre-release
     * @return int Comparison result
     */
    private static function comparePrerelease(?string $pr1, ?string $pr2): int {
        // No pre-release is greater than any pre-release
        if ($pr1 === null && $pr2 === null) {
            return 0;
        }
        if ($pr1 === null) {
            return 1;
        }
        if ($pr2 === null) {
            return -1;
        }

        // Split pre-release into identifiers
        $ids1 = explode('.', $pr1);
        $ids2 = explode('.', $pr2);

        $len = min(count($ids1), count($ids2));

        for ($i = 0; $i < $len; $i++) {
            $id1 = $ids1[$i];
            $id2 = $ids2[$i];

            // Numeric identifiers are compared as integers
            $isNum1 = ctype_digit($id1);
            $isNum2 = ctype_digit($id2);

            if ($isNum1 && $isNum2) {
                $diff = (int) $id1 - (int) $id2;
                if ($diff !== 0) {
                    return $diff > 0 ? 1 : -1;
                }
            } elseif ($isNum1) {
                // Numeric identifiers have lower precedence than alphanumeric
                return -1;
            } elseif ($isNum2) {
                return 1;
            } else {
                // Alphanumeric identifiers are compared lexically
                $cmp = strcmp($id1, $id2);
                if ($cmp !== 0) {
                    return $cmp > 0 ? 1 : -1;
                }
            }
        }

        // Longer pre-release wins if all preceding identifiers are equal
        if (count($ids1) < count($ids2)) {
            return -1;
        }
        if (count($ids1) > count($ids2)) {
            return 1;
        }

        return 0;
    }

    /**
     * Determine the type of update between two versions
     *
     * @param string $currentVersion Current installed version
     * @param string $newVersion New available version
     * @return string|null 'major', 'minor', 'patch', or null if no update or invalid
     */
    public static function getUpdateType(string $currentVersion, string $newVersion): ?string {
        $current = self::parse($currentVersion);
        $new = self::parse($newVersion);

        if ($current === null || $new === null) {
            return null;
        }

        // Not an update if new is not greater
        if (self::compare($currentVersion, $newVersion) >= 0) {
            return null;
        }

        // Determine update type
        if ($new['major'] > $current['major']) {
            return 'major';
        }
        if ($new['minor'] > $current['minor']) {
            return 'minor';
        }
        if ($new['patch'] > $current['patch']) {
            return 'patch';
        }

        // Pre-release to stable is considered a patch update
        if ($current['prerelease'] !== null && $new['prerelease'] === null) {
            return 'patch';
        }

        // Pre-release update
        if ($current['prerelease'] !== null && $new['prerelease'] !== null) {
            return 'patch';
        }

        return null;
    }

    /**
     * Validate if a string is a valid semantic version
     *
     * @param string $version Version string to validate
     * @param bool $strict If true, requires strict semver format (x.y.z)
     * @return bool True if valid
     */
    public static function isValid(string $version, bool $strict = false): bool {
        $version = trim($version);

        if ($strict) {
            return preg_match(self::SEMVER_PATTERN, $version) === 1;
        }

        return self::parse($version) !== null;
    }

    /**
     * Check if a version satisfies a constraint
     *
     * Supports constraints:
     * - Exact: "1.0.0"
     * - Greater than: ">1.0.0"
     * - Greater than or equal: ">=1.0.0"
     * - Less than: "<2.0.0"
     * - Less than or equal: "<=2.0.0"
     * - Caret (compatible with): "^1.2.3" (>=1.2.3 <2.0.0)
     * - Tilde (approximately): "~1.2.3" (>=1.2.3 <1.3.0)
     * - Range: ">=1.0.0 <2.0.0"
     * - Wildcard: "1.x", "1.2.x", "1.*"
     *
     * @param string $version Version to check
     * @param string $constraint Version constraint
     * @return bool True if version satisfies the constraint
     */
    public static function satisfies(string $version, string $constraint): bool {
        $version = trim($version);
        $constraint = trim($constraint);

        // Handle compound constraints (space separated means AND)
        if (strpos($constraint, ' ') !== false) {
            $parts = preg_split('/\s+/', $constraint);
            foreach ($parts as $part) {
                if (!self::satisfies($version, $part)) {
                    return false;
                }
            }
            return true;
        }

        // Handle pipe (OR) constraints
        if (strpos($constraint, '||') !== false) {
            $parts = explode('||', $constraint);
            foreach ($parts as $part) {
                if (self::satisfies($version, trim($part))) {
                    return true;
                }
            }
            return false;
        }

        // Handle wildcard constraints (1.x, 1.*, 1.2.x, 1.2.*)
        if (preg_match('/^(\d+)(?:\.(\d+|x|\*))?(?:\.(\d+|x|\*))?$/', $constraint, $matches)) {
            $parsed = self::parse($version);
            if ($parsed === null) {
                return false;
            }

            $constraintMajor = (int) $matches[1];
            $constraintMinor = isset($matches[2]) && $matches[2] !== 'x' && $matches[2] !== '*' ? (int) $matches[2] : null;
            $constraintPatch = isset($matches[3]) && $matches[3] !== 'x' && $matches[3] !== '*' ? (int) $matches[3] : null;

            if ($parsed['major'] !== $constraintMajor) {
                return false;
            }
            if ($constraintMinor !== null && $parsed['minor'] !== $constraintMinor) {
                return false;
            }
            if ($constraintPatch !== null && $parsed['patch'] !== $constraintPatch) {
                return false;
            }

            return true;
        }

        // Handle caret constraint (^1.2.3)
        if (strpos($constraint, '^') === 0) {
            return self::satisfiesCaret($version, substr($constraint, 1));
        }

        // Handle tilde constraint (~1.2.3)
        if (strpos($constraint, '~') === 0) {
            return self::satisfiesTilde($version, substr($constraint, 1));
        }

        // Handle comparison operators
        if (preg_match('/^(>=?|<=?|=)?\s*(.+)$/', $constraint, $matches)) {
            $operator = $matches[1] ?: '=';
            $constraintVersion = $matches[2];

            $cmp = self::compare($version, $constraintVersion);

            switch ($operator) {
                case '=':
                case '==':
                    return $cmp === 0;
                case '>':
                    return $cmp > 0;
                case '>=':
                    return $cmp >= 0;
                case '<':
                    return $cmp < 0;
                case '<=':
                    return $cmp <= 0;
            }
        }

        return false;
    }

    /**
     * Check if version satisfies caret constraint
     * ^1.2.3 means >=1.2.3 <2.0.0
     * ^0.2.3 means >=0.2.3 <0.3.0
     * ^0.0.3 means >=0.0.3 <0.0.4
     */
    private static function satisfiesCaret(string $version, string $constraint): bool {
        $parsed = self::parse($constraint);
        if ($parsed === null) {
            return false;
        }

        // Version must be >= constraint
        if (self::compare($version, $constraint) < 0) {
            return false;
        }

        // Determine upper bound
        if ($parsed['major'] > 0) {
            $upper = ($parsed['major'] + 1) . '.0.0';
        } elseif ($parsed['minor'] > 0) {
            $upper = '0.' . ($parsed['minor'] + 1) . '.0';
        } else {
            $upper = '0.0.' . ($parsed['patch'] + 1);
        }

        return self::compare($version, $upper) < 0;
    }

    /**
     * Check if version satisfies tilde constraint
     * ~1.2.3 means >=1.2.3 <1.3.0
     */
    private static function satisfiesTilde(string $version, string $constraint): bool {
        $parsed = self::parse($constraint);
        if ($parsed === null) {
            return false;
        }

        // Version must be >= constraint
        if (self::compare($version, $constraint) < 0) {
            return false;
        }

        // Upper bound is next minor version
        $upper = $parsed['major'] . '.' . ($parsed['minor'] + 1) . '.0';

        return self::compare($version, $upper) < 0;
    }

    /**
     * Get the normalized version string (without leading 'v')
     *
     * @param string $version Version string
     * @return string|null Normalized version or null if invalid
     */
    public static function normalize(string $version): ?string {
        $parsed = self::parse($version);
        if ($parsed === null) {
            return null;
        }

        $normalized = sprintf('%d.%d.%d', $parsed['major'], $parsed['minor'], $parsed['patch']);

        if ($parsed['prerelease'] !== null) {
            $normalized .= '-' . $parsed['prerelease'];
        }

        if ($parsed['build'] !== null) {
            $normalized .= '+' . $parsed['build'];
        }

        return $normalized;
    }

    /**
     * Increment a version number by the specified type
     *
     * @param string $version Current version
     * @param string $type Increment type: 'major', 'minor', or 'patch'
     * @return string|null Incremented version or null if invalid
     */
    public static function increment(string $version, string $type = 'patch'): ?string {
        $parsed = self::parse($version);
        if ($parsed === null) {
            return null;
        }

        switch ($type) {
            case 'major':
                $parsed['major']++;
                $parsed['minor'] = 0;
                $parsed['patch'] = 0;
                break;
            case 'minor':
                $parsed['minor']++;
                $parsed['patch'] = 0;
                break;
            case 'patch':
                $parsed['patch']++;
                break;
            default:
                return null;
        }

        // Clear prerelease and build on increment
        $parsed['prerelease'] = null;
        $parsed['build'] = null;

        return self::normalize(
            sprintf('%d.%d.%d', $parsed['major'], $parsed['minor'], $parsed['patch'])
        );
    }

    /**
     * Check if a version is a pre-release version
     *
     * @param string $version Version to check
     * @return bool True if version has a pre-release identifier
     */
    public static function isPrerelease(string $version): bool {
        $parsed = self::parse($version);
        return $parsed !== null && $parsed['prerelease'] !== null;
    }

    /**
     * Sort an array of version strings in ascending order
     *
     * @param array $versions Array of version strings
     * @return array Sorted array of versions
     */
    public static function sort(array $versions): array {
        usort($versions, [self::class, 'compare']);
        return $versions;
    }

    /**
     * Sort an array of version strings in descending order
     *
     * @param array $versions Array of version strings
     * @return array Sorted array of versions (newest first)
     */
    public static function rsort(array $versions): array {
        usort($versions, function ($a, $b) {
            return self::compare($b, $a);
        });
        return $versions;
    }

    /**
     * Find the maximum version in an array
     *
     * @param array $versions Array of version strings
     * @return string|null Maximum version or null if empty
     */
    public static function max(array $versions): ?string {
        if (empty($versions)) {
            return null;
        }

        $sorted = self::rsort($versions);
        return $sorted[0];
    }

    /**
     * Find the minimum version in an array
     *
     * @param array $versions Array of version strings
     * @return string|null Minimum version or null if empty
     */
    public static function min(array $versions): ?string {
        if (empty($versions)) {
            return null;
        }

        $sorted = self::sort($versions);
        return $sorted[0];
    }
}
