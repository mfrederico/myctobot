<?php
/**
 * Plugin Version Service Unit Tests
 *
 * Tests the PluginVersionService and SemVer utility for version tracking functionality.
 * Run with: php tests/unit/PluginVersionServiceTest.php
 *
 * @see GitHub Issue #104
 */

namespace Tests\unit;

// Load required files
require_once __DIR__ . '/../../lib/SemVer.php';

use app\lib\SemVer;

class PluginVersionServiceTest {

    private static int $passed = 0;
    private static int $failed = 0;
    private static array $errors = [];

    /**
     * Run all tests
     */
    public static function run(): void {
        echo "=== Plugin Version Service Unit Tests ===\n\n";

        // SemVer tests
        self::testSemVerParsing();
        self::testSemVerComparison();
        self::testSemVerUpdateType();
        self::testSemVerValidation();
        self::testSemVerConstraints();
        self::testSemVerPrerelease();
        self::testSemVerSorting();
        self::testSemVerNormalization();
        self::testSemVerIncrement();

        self::printResults();
    }

    /**
     * Test semantic version parsing
     */
    private static function testSemVerParsing(): void {
        echo "Testing SemVer parsing...\n";

        // Standard semver
        $result = SemVer::parse('1.2.3');
        self::assertEqual($result['major'], 1, 'Parse major');
        self::assertEqual($result['minor'], 2, 'Parse minor');
        self::assertEqual($result['patch'], 3, 'Parse patch');
        self::assertNull($result['prerelease'], 'No prerelease');
        self::assertNull($result['build'], 'No build');

        // With v prefix
        $result = SemVer::parse('v2.0.0');
        self::assertEqual($result['major'], 2, 'Parse with v prefix');

        // With prerelease
        $result = SemVer::parse('1.0.0-alpha.1');
        self::assertEqual($result['prerelease'], 'alpha.1', 'Parse prerelease');

        // With build metadata
        $result = SemVer::parse('1.0.0+build.123');
        self::assertEqual($result['build'], 'build.123', 'Parse build metadata');

        // With both prerelease and build
        $result = SemVer::parse('1.0.0-beta.2+build.456');
        self::assertEqual($result['prerelease'], 'beta.2', 'Parse prerelease with build');
        self::assertEqual($result['build'], 'build.456', 'Parse build with prerelease');

        // Loose format (incomplete version)
        $result = SemVer::parse('1.0');
        self::assertNotNull($result, 'Parse loose version');
        self::assertEqual($result['patch'], 0, 'Default patch to 0');

        // Invalid version
        $result = SemVer::parse('not-a-version');
        self::assertNull($result, 'Invalid version returns null');

        echo "\n";
    }

    /**
     * Test version comparison
     */
    private static function testSemVerComparison(): void {
        echo "Testing SemVer comparison...\n";

        // Basic comparisons
        self::assertEqual(SemVer::compare('1.0.0', '2.0.0'), -1, '1.0.0 < 2.0.0');
        self::assertEqual(SemVer::compare('2.0.0', '1.0.0'), 1, '2.0.0 > 1.0.0');
        self::assertEqual(SemVer::compare('1.0.0', '1.0.0'), 0, '1.0.0 == 1.0.0');

        // Minor version comparison
        self::assertEqual(SemVer::compare('1.1.0', '1.2.0'), -1, '1.1.0 < 1.2.0');
        self::assertEqual(SemVer::compare('1.2.0', '1.1.0'), 1, '1.2.0 > 1.1.0');

        // Patch version comparison
        self::assertEqual(SemVer::compare('1.0.1', '1.0.2'), -1, '1.0.1 < 1.0.2');
        self::assertEqual(SemVer::compare('1.0.2', '1.0.1'), 1, '1.0.2 > 1.0.1');

        // Prerelease comparisons
        self::assertEqual(SemVer::compare('1.0.0-alpha', '1.0.0'), -1, 'Prerelease < stable');
        self::assertEqual(SemVer::compare('1.0.0', '1.0.0-alpha'), 1, 'Stable > prerelease');
        self::assertEqual(SemVer::compare('1.0.0-alpha', '1.0.0-beta'), -1, 'alpha < beta');
        self::assertEqual(SemVer::compare('1.0.0-alpha.1', '1.0.0-alpha.2'), -1, 'alpha.1 < alpha.2');

        // With v prefix
        self::assertEqual(SemVer::compare('v1.0.0', '1.0.0'), 0, 'v prefix ignored');

        echo "\n";
    }

    /**
     * Test update type detection
     */
    private static function testSemVerUpdateType(): void {
        echo "Testing SemVer update type detection...\n";

        // Major update
        self::assertEqual(SemVer::getUpdateType('1.0.0', '2.0.0'), 'major', 'Major update 1.0.0 -> 2.0.0');
        self::assertEqual(SemVer::getUpdateType('1.5.3', '2.0.0'), 'major', 'Major update 1.5.3 -> 2.0.0');

        // Minor update
        self::assertEqual(SemVer::getUpdateType('1.0.0', '1.1.0'), 'minor', 'Minor update 1.0.0 -> 1.1.0');
        self::assertEqual(SemVer::getUpdateType('1.2.5', '1.3.0'), 'minor', 'Minor update 1.2.5 -> 1.3.0');

        // Patch update
        self::assertEqual(SemVer::getUpdateType('1.0.0', '1.0.1'), 'patch', 'Patch update 1.0.0 -> 1.0.1');
        self::assertEqual(SemVer::getUpdateType('1.2.3', '1.2.4'), 'patch', 'Patch update 1.2.3 -> 1.2.4');

        // Prerelease to stable
        self::assertEqual(SemVer::getUpdateType('1.0.0-beta', '1.0.0'), 'patch', 'Prerelease to stable');

        // Not an update (same version)
        self::assertNull(SemVer::getUpdateType('1.0.0', '1.0.0'), 'Same version is not an update');

        // Not an update (downgrade)
        self::assertNull(SemVer::getUpdateType('2.0.0', '1.0.0'), 'Downgrade is not an update');

        echo "\n";
    }

    /**
     * Test version validation
     */
    private static function testSemVerValidation(): void {
        echo "Testing SemVer validation...\n";

        // Valid versions
        self::assertTrue(SemVer::isValid('1.0.0'), 'Valid: 1.0.0');
        self::assertTrue(SemVer::isValid('v1.0.0'), 'Valid: v1.0.0');
        self::assertTrue(SemVer::isValid('0.0.1'), 'Valid: 0.0.1');
        self::assertTrue(SemVer::isValid('1.0.0-alpha'), 'Valid: 1.0.0-alpha');
        self::assertTrue(SemVer::isValid('1.0.0+build'), 'Valid: 1.0.0+build');
        self::assertTrue(SemVer::isValid('1.0'), 'Valid loose: 1.0');

        // Invalid versions
        self::assertFalse(SemVer::isValid('not-valid'), 'Invalid: not-valid');
        self::assertFalse(SemVer::isValid(''), 'Invalid: empty string');
        self::assertFalse(SemVer::isValid('abc'), 'Invalid: abc');

        // Strict validation
        self::assertTrue(SemVer::isValid('1.0.0', true), 'Strict valid: 1.0.0');
        self::assertFalse(SemVer::isValid('1.0', true), 'Strict invalid: 1.0');

        echo "\n";
    }

    /**
     * Test version constraints
     */
    private static function testSemVerConstraints(): void {
        echo "Testing SemVer constraints...\n";

        // Exact match
        self::assertTrue(SemVer::satisfies('1.0.0', '1.0.0'), 'Exact match');
        self::assertFalse(SemVer::satisfies('1.0.1', '1.0.0'), 'Exact no match');

        // Greater than
        self::assertTrue(SemVer::satisfies('2.0.0', '>1.0.0'), 'Greater than');
        self::assertFalse(SemVer::satisfies('1.0.0', '>1.0.0'), 'Not greater than');

        // Greater than or equal
        self::assertTrue(SemVer::satisfies('1.0.0', '>=1.0.0'), 'Greater than or equal (equal)');
        self::assertTrue(SemVer::satisfies('2.0.0', '>=1.0.0'), 'Greater than or equal (greater)');
        self::assertFalse(SemVer::satisfies('0.9.0', '>=1.0.0'), 'Not greater than or equal');

        // Less than
        self::assertTrue(SemVer::satisfies('1.0.0', '<2.0.0'), 'Less than');
        self::assertFalse(SemVer::satisfies('2.0.0', '<2.0.0'), 'Not less than');

        // Caret constraint
        self::assertTrue(SemVer::satisfies('1.2.3', '^1.2.0'), 'Caret: 1.2.3 satisfies ^1.2.0');
        self::assertTrue(SemVer::satisfies('1.9.9', '^1.2.0'), 'Caret: 1.9.9 satisfies ^1.2.0');
        self::assertFalse(SemVer::satisfies('2.0.0', '^1.2.0'), 'Caret: 2.0.0 not satisfy ^1.2.0');
        self::assertFalse(SemVer::satisfies('1.1.0', '^1.2.0'), 'Caret: 1.1.0 not satisfy ^1.2.0');

        // Tilde constraint
        self::assertTrue(SemVer::satisfies('1.2.3', '~1.2.0'), 'Tilde: 1.2.3 satisfies ~1.2.0');
        self::assertTrue(SemVer::satisfies('1.2.9', '~1.2.0'), 'Tilde: 1.2.9 satisfies ~1.2.0');
        self::assertFalse(SemVer::satisfies('1.3.0', '~1.2.0'), 'Tilde: 1.3.0 not satisfy ~1.2.0');

        // Wildcard constraint
        self::assertTrue(SemVer::satisfies('1.0.0', '1.x'), 'Wildcard: 1.0.0 satisfies 1.x');
        self::assertTrue(SemVer::satisfies('1.5.3', '1.x'), 'Wildcard: 1.5.3 satisfies 1.x');
        self::assertFalse(SemVer::satisfies('2.0.0', '1.x'), 'Wildcard: 2.0.0 not satisfy 1.x');

        // Compound constraint (AND)
        self::assertTrue(SemVer::satisfies('1.5.0', '>=1.0.0 <2.0.0'), 'Compound: 1.5.0 satisfies range');
        self::assertFalse(SemVer::satisfies('2.0.0', '>=1.0.0 <2.0.0'), 'Compound: 2.0.0 not satisfy range');

        echo "\n";
    }

    /**
     * Test prerelease detection
     */
    private static function testSemVerPrerelease(): void {
        echo "Testing SemVer prerelease detection...\n";

        self::assertTrue(SemVer::isPrerelease('1.0.0-alpha'), 'alpha is prerelease');
        self::assertTrue(SemVer::isPrerelease('1.0.0-beta.1'), 'beta.1 is prerelease');
        self::assertTrue(SemVer::isPrerelease('1.0.0-rc.1'), 'rc.1 is prerelease');
        self::assertFalse(SemVer::isPrerelease('1.0.0'), 'Stable is not prerelease');
        self::assertFalse(SemVer::isPrerelease('1.0.0+build'), 'Build metadata only is not prerelease');

        echo "\n";
    }

    /**
     * Test version sorting
     */
    private static function testSemVerSorting(): void {
        echo "Testing SemVer sorting...\n";

        $versions = ['2.0.0', '1.0.0', '1.1.0', '1.0.1', '3.0.0'];
        $sorted = SemVer::sort($versions);

        self::assertEqual($sorted[0], '1.0.0', 'Sort: first is 1.0.0');
        self::assertEqual($sorted[1], '1.0.1', 'Sort: second is 1.0.1');
        self::assertEqual($sorted[2], '1.1.0', 'Sort: third is 1.1.0');
        self::assertEqual($sorted[3], '2.0.0', 'Sort: fourth is 2.0.0');
        self::assertEqual($sorted[4], '3.0.0', 'Sort: fifth is 3.0.0');

        // Reverse sort
        $rsorted = SemVer::rsort($versions);
        self::assertEqual($rsorted[0], '3.0.0', 'Rsort: first is 3.0.0');
        self::assertEqual($rsorted[4], '1.0.0', 'Rsort: last is 1.0.0');

        // Max and min
        self::assertEqual(SemVer::max($versions), '3.0.0', 'Max version');
        self::assertEqual(SemVer::min($versions), '1.0.0', 'Min version');

        // With prereleases
        $versionsWithPre = ['1.0.0', '1.0.0-alpha', '1.0.0-beta', '1.0.1'];
        $sortedPre = SemVer::sort($versionsWithPre);
        self::assertEqual($sortedPre[0], '1.0.0-alpha', 'Sort prerelease: alpha first');
        self::assertEqual($sortedPre[1], '1.0.0-beta', 'Sort prerelease: beta second');
        self::assertEqual($sortedPre[2], '1.0.0', 'Sort prerelease: stable third');

        echo "\n";
    }

    /**
     * Test version normalization
     */
    private static function testSemVerNormalization(): void {
        echo "Testing SemVer normalization...\n";

        self::assertEqual(SemVer::normalize('v1.0.0'), '1.0.0', 'Normalize removes v prefix');
        self::assertEqual(SemVer::normalize('1.0'), '1.0.0', 'Normalize adds missing patch');
        self::assertEqual(SemVer::normalize('1.0.0-alpha'), '1.0.0-alpha', 'Normalize keeps prerelease');
        self::assertEqual(SemVer::normalize('1.0.0+build'), '1.0.0+build', 'Normalize keeps build');
        self::assertNull(SemVer::normalize('invalid'), 'Normalize returns null for invalid');

        echo "\n";
    }

    /**
     * Test version increment
     */
    private static function testSemVerIncrement(): void {
        echo "Testing SemVer increment...\n";

        self::assertEqual(SemVer::increment('1.0.0', 'major'), '2.0.0', 'Increment major');
        self::assertEqual(SemVer::increment('1.0.0', 'minor'), '1.1.0', 'Increment minor');
        self::assertEqual(SemVer::increment('1.0.0', 'patch'), '1.0.1', 'Increment patch');

        // Increment resets lower versions
        self::assertEqual(SemVer::increment('1.2.3', 'major'), '2.0.0', 'Major resets minor and patch');
        self::assertEqual(SemVer::increment('1.2.3', 'minor'), '1.3.0', 'Minor resets patch');

        // Increment clears prerelease
        self::assertEqual(SemVer::increment('1.0.0-alpha', 'patch'), '1.0.1', 'Increment clears prerelease');

        echo "\n";
    }

    // ========================================
    // Assertion Helpers
    // ========================================

    private static function assertEqual($actual, $expected, string $message): void {
        if ($actual === $expected) {
            echo "  PASS: {$message}\n";
            self::$passed++;
        } else {
            echo "  FAIL: {$message} (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")\n";
            self::$failed++;
            self::$errors[] = $message;
        }
    }

    private static function assertTrue($value, string $message): void {
        self::assertEqual($value, true, $message);
    }

    private static function assertFalse($value, string $message): void {
        self::assertEqual($value, false, $message);
    }

    private static function assertNull($value, string $message): void {
        if ($value === null) {
            echo "  PASS: {$message}\n";
            self::$passed++;
        } else {
            echo "  FAIL: {$message} (expected null, got: " . var_export($value, true) . ")\n";
            self::$failed++;
            self::$errors[] = $message;
        }
    }

    private static function assertNotNull($value, string $message): void {
        if ($value !== null) {
            echo "  PASS: {$message}\n";
            self::$passed++;
        } else {
            echo "  FAIL: {$message} (expected not null)\n";
            self::$failed++;
            self::$errors[] = $message;
        }
    }

    private static function printResults(): void {
        echo "\n=== Test Results ===\n";
        echo "Passed: " . self::$passed . "\n";
        echo "Failed: " . self::$failed . "\n";

        if (!empty(self::$errors)) {
            echo "\nFailed tests:\n";
            foreach (self::$errors as $error) {
                echo "  - {$error}\n";
            }
        }

        echo "\n";

        // Exit with appropriate code
        exit(self::$failed > 0 ? 1 : 0);
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'] ?? '')) {
    PluginVersionServiceTest::run();
}
