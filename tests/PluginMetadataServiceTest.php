<?php
/**
 * Plugin Metadata Service Unit Tests
 *
 * Tests the PluginMetadataService extraction, validation, and dependency parsing.
 * Run with: php tests/PluginMetadataServiceTest.php
 */

namespace app\tests;

// Minimal Flight mock for testing without full bootstrap
if (!class_exists('\Flight')) {
    class FlightMock {
        private static $values = [];

        public static function get($key) {
            return self::$values[$key] ?? null;
        }

        public static function set($key, $value) {
            self::$values[$key] = $value;
        }
    }
    class_alias('app\tests\FlightMock', 'Flight');
}

// Minimal logger mock
class LoggerMock {
    public function debug($msg, $ctx = []) {}
    public function info($msg, $ctx = []) {}
    public function warning($msg, $ctx = []) {}
    public function error($msg, $ctx = []) {}
}

// Set up mock logger
\Flight::set('log', new LoggerMock());

require_once __DIR__ . '/../services/PluginMetadataService.php';

use app\services\PluginMetadataService;

class PluginMetadataServiceTest {

    private static int $passed = 0;
    private static int $failed = 0;
    private static array $errors = [];

    /**
     * Run all tests
     */
    public static function run(): void {
        echo "=== Plugin Metadata Service Unit Tests ===\n\n";

        // Extraction tests
        self::testValidPluginJsonExtraction();
        self::testInvalidJsonHandling();
        self::testEmptyJsonObject();
        self::testPartialMetadataExtraction();

        // Validation tests
        self::testValidMetadataPasses();
        self::testMissingRequiredFields();
        self::testEmptyRequiredFields();
        self::testTypeMismatchOnRequiredFields();
        self::testTagsValidation();
        self::testTagsMustBeArray();
        self::testInvalidTagType();
        self::testVersionFormatWarning();
        self::testNameLengthValidation();
        self::testDescriptionLengthValidation();
        self::testUrlFieldValidation();

        // Dependency parsing tests
        self::testDependencyParsingAssociativeArray();
        self::testDependencyParsingIndexedArray();
        self::testDependencyParsingCaretConstraint();
        self::testDependencyParsingTildeConstraint();
        self::testDependencyParsingRangeConstraints();
        self::testDependencyParsingXRange();
        self::testDependencyParsingWildcard();
        self::testDependencyParsingAtSymbol();

        // Combined tests
        self::testMultipleErrors();
        self::testOptionalFieldsAccepted();

        self::printResults();
    }

    // === Extraction Tests ===

    /**
     * Test valid plugin.json extraction
     */
    private static function testValidPluginJsonExtraction(): void {
        echo "Testing valid plugin.json extraction...\n";

        $service = new PluginMetadataService();

        $validJson = json_encode([
            'name' => 'my-awesome-plugin',
            'version' => '1.0.0',
            'description' => 'An awesome plugin for doing awesome things',
            'author' => 'John Doe',
            'tags' => ['utility', 'awesome'],
            'dependencies' => [
                'some-package' => '^1.0.0',
                'other-package' => '>=2.0.0'
            ],
            'license' => 'MIT',
            'homepage' => 'https://example.com/my-plugin',
            'repository' => 'https://github.com/example/my-plugin'
        ]);

        $result = $service->extractMetadata($validJson);

        self::assertTrue($result['success'], 'Extraction should succeed for valid JSON');
        self::assertEqual($result['data']['name'], 'my-awesome-plugin', 'Name extracted correctly');
        self::assertEqual($result['data']['version'], '1.0.0', 'Version extracted correctly');
        self::assertEqual($result['data']['author'], 'John Doe', 'Author extracted correctly');
        self::assertTrue(is_array($result['data']['tags']), 'Tags extracted as array');
        self::assertTrue(is_array($result['data']['dependencies']), 'Dependencies extracted as array');
        self::assertTrue(isset($result['data']['parsed_dependencies']), 'Parsed dependencies generated');

        echo "\n";
    }

    /**
     * Test invalid JSON handling
     */
    private static function testInvalidJsonHandling(): void {
        echo "Testing invalid JSON handling...\n";

        $service = new PluginMetadataService();

        // Malformed JSON
        $invalidJson = '{"name": "test", "version": }';

        $result = $service->extractMetadata($invalidJson);

        self::assertFalse($result['success'], 'Extraction should fail for invalid JSON');
        self::assertNotNull($result['error'], 'Error should be present');
        self::assertEqual($result['error']['error'], 'invalid_json', 'Error type is invalid_json');

        // Another invalid format
        $notJson = 'This is not JSON at all';
        $result2 = $service->extractMetadata($notJson);

        self::assertFalse($result2['success'], 'Extraction should fail for non-JSON');

        echo "\n";
    }

    /**
     * Test empty JSON object
     */
    private static function testEmptyJsonObject(): void {
        echo "Testing empty JSON object extraction...\n";

        $service = new PluginMetadataService();

        $emptyJson = '{}';
        $result = $service->extractMetadata($emptyJson);

        self::assertTrue($result['success'], 'Extraction should succeed for empty object');
        self::assertEqual(count($result['data']), 0, 'No fields extracted from empty object');

        echo "\n";
    }

    /**
     * Test partial metadata extraction
     */
    private static function testPartialMetadataExtraction(): void {
        echo "Testing partial metadata extraction...\n";

        $service = new PluginMetadataService();

        $partialJson = json_encode([
            'name' => 'partial-plugin',
            'version' => '0.1.0',
            'unknown_field' => 'should be ignored'
        ]);

        $result = $service->extractMetadata($partialJson);

        self::assertTrue($result['success'], 'Extraction should succeed');
        self::assertEqual($result['data']['name'], 'partial-plugin', 'Name extracted');
        self::assertEqual($result['data']['version'], '0.1.0', 'Version extracted');
        self::assertFalse(isset($result['data']['unknown_field']), 'Unknown fields should not be extracted');
        self::assertFalse(isset($result['data']['description']), 'Missing optional fields not present');

        echo "\n";
    }

    // === Validation Tests ===

    /**
     * Test that valid metadata passes validation
     */
    private static function testValidMetadataPasses(): void {
        echo "Testing valid metadata passes validation...\n";

        $service = new PluginMetadataService();

        $validMetadata = [
            'name' => 'valid-plugin',
            'version' => '1.0.0',
            'description' => 'A valid plugin description',
            'author' => 'Jane Developer'
        ];

        $result = $service->validateMetadata($validMetadata);

        self::assertTrue($result['valid'], 'Valid metadata should pass validation');
        self::assertEqual(count($result['errors']), 0, 'No errors for valid metadata');

        echo "\n";
    }

    /**
     * Test missing required fields detection
     */
    private static function testMissingRequiredFields(): void {
        echo "Testing missing required fields detection...\n";

        $service = new PluginMetadataService();

        // Missing all required fields
        $emptyMetadata = [];
        $result = $service->validateMetadata($emptyMetadata);

        self::assertFalse($result['valid'], 'Empty metadata should fail validation');
        self::assertEqual(count($result['errors']), 4, 'Should have 4 missing field errors');

        // Check each missing field is reported
        $missingFields = array_column($result['errors'], 'field');
        self::assertTrue(in_array('name', $missingFields), 'Missing name reported');
        self::assertTrue(in_array('version', $missingFields), 'Missing version reported');
        self::assertTrue(in_array('description', $missingFields), 'Missing description reported');
        self::assertTrue(in_array('author', $missingFields), 'Missing author reported');

        // Verify error type
        foreach ($result['errors'] as $error) {
            self::assertEqual($error['error'], 'missing_field', "Error type is 'missing_field'");
        }

        echo "\n";
    }

    /**
     * Test empty required fields detection
     */
    private static function testEmptyRequiredFields(): void {
        echo "Testing empty required fields detection...\n";

        $service = new PluginMetadataService();

        $metadata = [
            'name' => '',
            'version' => '',
            'description' => '',
            'author' => ''
        ];

        $result = $service->validateMetadata($metadata);

        self::assertFalse($result['valid'], 'Empty fields should fail validation');

        $emptyFieldErrors = array_filter($result['errors'], fn($e) => $e['error'] === 'empty_field');
        self::assertEqual(count($emptyFieldErrors), 4, 'Should have 4 empty_field errors');

        echo "\n";
    }

    /**
     * Test type mismatch on required fields
     */
    private static function testTypeMismatchOnRequiredFields(): void {
        echo "Testing type mismatch on required fields...\n";

        $service = new PluginMetadataService();

        // Pass array instead of string for name
        $metadata = [
            'name' => ['not', 'a', 'string'],
            'version' => '1.0.0',
            'description' => 'Valid description',
            'author' => 'Author Name'
        ];

        $result = $service->validateMetadata($metadata);

        self::assertFalse($result['valid'], 'Array in string field should fail');

        $typeErrors = array_filter($result['errors'], fn($e) => $e['error'] === 'type_mismatch');
        self::assertTrue(count($typeErrors) >= 1, 'Should have type mismatch error');

        $firstError = reset($typeErrors);
        self::assertEqual($firstError['field'], 'name', 'Error on name field');
        self::assertEqual($firstError['expected_type'], 'string', 'Expected type is string');
        self::assertEqual($firstError['actual_type'], 'array', 'Actual type is array');

        echo "\n";
    }

    /**
     * Test valid tags array
     */
    private static function testTagsValidation(): void {
        echo "Testing valid tags validation...\n";

        $service = new PluginMetadataService();

        $metadata = [
            'name' => 'tag-test-plugin',
            'version' => '1.0.0',
            'description' => 'Testing tags',
            'author' => 'Test Author',
            'tags' => ['tag1', 'tag2', 'utility']
        ];

        $result = $service->validateMetadata($metadata);

        self::assertTrue($result['valid'], 'Valid tags should pass');
        self::assertEqual(count($result['errors']), 0, 'No errors for valid tags');

        echo "\n";
    }

    /**
     * Test tags must be array
     */
    private static function testTagsMustBeArray(): void {
        echo "Testing tags must be array...\n";

        $service = new PluginMetadataService();

        $metadata = [
            'name' => 'tag-test-plugin',
            'version' => '1.0.0',
            'description' => 'Testing tags',
            'author' => 'Test Author',
            'tags' => 'not-an-array'
        ];

        $result = $service->validateMetadata($metadata);

        self::assertFalse($result['valid'], 'String tags should fail');

        $typeErrors = array_filter($result['errors'], fn($e) => $e['field'] === 'tags' && $e['error'] === 'type_mismatch');
        self::assertTrue(count($typeErrors) >= 1, 'Should have type mismatch error for tags');

        echo "\n";
    }

    /**
     * Test invalid tag type within array
     */
    private static function testInvalidTagType(): void {
        echo "Testing invalid tag type within array...\n";

        $service = new PluginMetadataService();

        $metadata = [
            'name' => 'tag-test-plugin',
            'version' => '1.0.0',
            'description' => 'Testing tags',
            'author' => 'Test Author',
            'tags' => ['valid-tag', 123, 'another-valid']
        ];

        $result = $service->validateMetadata($metadata);

        self::assertFalse($result['valid'], 'Non-string tag should fail');

        $tagErrors = array_filter($result['errors'], fn($e) => $e['error'] === 'invalid_tag_type');
        self::assertTrue(count($tagErrors) >= 1, 'Should have invalid_tag_type error');

        echo "\n";
    }

    /**
     * Test version format warning
     */
    private static function testVersionFormatWarning(): void {
        echo "Testing version format warning...\n";

        $service = new PluginMetadataService();

        $metadata = [
            'name' => 'version-test',
            'version' => 'not-semver',
            'description' => 'Testing version',
            'author' => 'Test Author'
        ];

        $result = $service->validateMetadata($metadata);

        // Should still be valid, but have warning
        self::assertTrue($result['valid'], 'Invalid version format is a warning, not error');
        self::assertTrue(count($result['warnings']) > 0, 'Should have warning for version format');

        $versionWarnings = array_filter($result['warnings'], fn($w) => $w['field'] === 'version');
        self::assertTrue(count($versionWarnings) >= 1, 'Should have version format warning');

        echo "\n";
    }

    /**
     * Test name length validation
     */
    private static function testNameLengthValidation(): void {
        echo "Testing name length validation...\n";

        $service = new PluginMetadataService();

        $metadata = [
            'name' => str_repeat('a', 150), // Over 100 char limit
            'version' => '1.0.0',
            'description' => 'Testing name length',
            'author' => 'Test Author'
        ];

        $result = $service->validateMetadata($metadata);

        self::assertFalse($result['valid'], 'Long name should fail');

        $nameErrors = array_filter($result['errors'], fn($e) => $e['error'] === 'name_too_long');
        self::assertTrue(count($nameErrors) >= 1, 'Should have name_too_long error');

        echo "\n";
    }

    /**
     * Test description length validation
     */
    private static function testDescriptionLengthValidation(): void {
        echo "Testing description length validation...\n";

        $service = new PluginMetadataService();

        $metadata = [
            'name' => 'desc-test',
            'version' => '1.0.0',
            'description' => str_repeat('x', 6000), // Over 5000 char limit
            'author' => 'Test Author'
        ];

        $result = $service->validateMetadata($metadata);

        self::assertFalse($result['valid'], 'Long description should fail');

        $descErrors = array_filter($result['errors'], fn($e) => $e['error'] === 'description_too_long');
        self::assertTrue(count($descErrors) >= 1, 'Should have description_too_long error');

        echo "\n";
    }

    /**
     * Test URL field validation
     */
    private static function testUrlFieldValidation(): void {
        echo "Testing URL field validation...\n";

        $service = new PluginMetadataService();

        $metadata = [
            'name' => 'url-test',
            'version' => '1.0.0',
            'description' => 'Testing URLs',
            'author' => 'Test Author',
            'homepage' => 'not-a-valid-url',
            'repository' => 'also-not-valid'
        ];

        $result = $service->validateMetadata($metadata);

        // Invalid URLs are warnings, not errors
        self::assertTrue($result['valid'], 'Invalid URLs should be warnings');
        self::assertTrue(count($result['warnings']) >= 2, 'Should have warnings for invalid URLs');

        $urlWarnings = array_filter($result['warnings'], fn($w) => $w['warning'] === 'invalid_url');
        self::assertEqual(count($urlWarnings), 2, 'Should have 2 invalid_url warnings');

        echo "\n";
    }

    // === Dependency Parsing Tests ===

    /**
     * Test dependency parsing with associative array format
     */
    private static function testDependencyParsingAssociativeArray(): void {
        echo "Testing dependency parsing with associative array...\n";

        $service = new PluginMetadataService();

        $dependencies = [
            'package-one' => '1.0.0',
            'package-two' => '^2.0.0'
        ];

        $parsed = $service->parseDependencies($dependencies);

        self::assertEqual(count($parsed), 2, 'Should parse 2 dependencies');
        self::assertEqual($parsed[0]['package'], 'package-one', 'First package name correct');
        self::assertEqual($parsed[0]['constraint'], '1.0.0', 'First constraint correct');
        self::assertEqual($parsed[1]['package'], 'package-two', 'Second package name correct');
        self::assertEqual($parsed[1]['constraint'], '^2.0.0', 'Second constraint correct');

        echo "\n";
    }

    /**
     * Test dependency parsing with indexed array format
     */
    private static function testDependencyParsingIndexedArray(): void {
        echo "Testing dependency parsing with indexed array...\n";

        $service = new PluginMetadataService();

        $dependencies = ['simple-package', 'another-package'];

        $parsed = $service->parseDependencies($dependencies);

        self::assertEqual(count($parsed), 2, 'Should parse 2 dependencies');
        self::assertEqual($parsed[0]['package'], 'simple-package', 'First package name correct');
        self::assertEqual($parsed[0]['constraint'], '*', 'Default constraint is wildcard');

        echo "\n";
    }

    /**
     * Test caret constraint parsing (^1.0.0)
     */
    private static function testDependencyParsingCaretConstraint(): void {
        echo "Testing caret constraint parsing...\n";

        $service = new PluginMetadataService();

        $dependencies = ['my-package' => '^1.2.3'];
        $parsed = $service->parseDependencies($dependencies);

        self::assertEqual($parsed[0]['parsed']['operator'], '^', 'Operator is caret');
        self::assertEqual($parsed[0]['parsed']['major'], 1, 'Major version is 1');
        self::assertEqual($parsed[0]['parsed']['minor'], 2, 'Minor version is 2');
        self::assertEqual($parsed[0]['parsed']['patch'], 3, 'Patch version is 3');

        echo "\n";
    }

    /**
     * Test tilde constraint parsing (~1.0.0)
     */
    private static function testDependencyParsingTildeConstraint(): void {
        echo "Testing tilde constraint parsing...\n";

        $service = new PluginMetadataService();

        $dependencies = ['my-package' => '~2.1.0'];
        $parsed = $service->parseDependencies($dependencies);

        self::assertEqual($parsed[0]['parsed']['operator'], '~', 'Operator is tilde');
        self::assertEqual($parsed[0]['parsed']['major'], 2, 'Major version is 2');
        self::assertEqual($parsed[0]['parsed']['minor'], 1, 'Minor version is 1');
        self::assertEqual($parsed[0]['parsed']['patch'], 0, 'Patch version is 0');

        echo "\n";
    }

    /**
     * Test range constraint parsing (>=, <=, >, <)
     */
    private static function testDependencyParsingRangeConstraints(): void {
        echo "Testing range constraint parsing...\n";

        $service = new PluginMetadataService();

        $dependencies = [
            'pkg1' => '>=1.0.0',
            'pkg2' => '<=2.0.0',
            'pkg3' => '>3.0.0',
            'pkg4' => '<4.0.0'
        ];

        $parsed = $service->parseDependencies($dependencies);

        self::assertEqual($parsed[0]['parsed']['operator'], '>=', 'First operator is >=');
        self::assertEqual($parsed[1]['parsed']['operator'], '<=', 'Second operator is <=');
        self::assertEqual($parsed[2]['parsed']['operator'], '>', 'Third operator is >');
        self::assertEqual($parsed[3]['parsed']['operator'], '<', 'Fourth operator is <');

        echo "\n";
    }

    /**
     * Test x-range parsing (1.x, 2.0.x)
     */
    private static function testDependencyParsingXRange(): void {
        echo "Testing x-range parsing...\n";

        $service = new PluginMetadataService();

        $dependencies = ['my-package' => '1.x'];
        $parsed = $service->parseDependencies($dependencies);

        self::assertEqual($parsed[0]['parsed']['major'], 1, 'Major version is 1');
        self::assertTrue($parsed[0]['parsed']['is_wildcard'], 'Should be marked as wildcard');

        echo "\n";
    }

    /**
     * Test wildcard parsing (*)
     */
    private static function testDependencyParsingWildcard(): void {
        echo "Testing wildcard parsing...\n";

        $service = new PluginMetadataService();

        $dependencies = ['my-package' => '*'];
        $parsed = $service->parseDependencies($dependencies);

        self::assertEqual($parsed[0]['parsed']['operator'], '*', 'Operator is wildcard');
        self::assertTrue($parsed[0]['parsed']['is_wildcard'], 'Is marked as wildcard');

        echo "\n";
    }

    /**
     * Test @ symbol format parsing (package@version)
     */
    private static function testDependencyParsingAtSymbol(): void {
        echo "Testing @ symbol format parsing...\n";

        $service = new PluginMetadataService();

        $dependencies = ['my-package@^1.0.0'];
        $parsed = $service->parseDependencies($dependencies);

        self::assertEqual($parsed[0]['package'], 'my-package', 'Package name extracted');
        self::assertEqual($parsed[0]['constraint'], '^1.0.0', 'Constraint extracted');
        self::assertEqual($parsed[0]['parsed']['operator'], '^', 'Operator is caret');

        echo "\n";
    }

    // === Combined Tests ===

    /**
     * Test multiple errors are all reported
     */
    private static function testMultipleErrors(): void {
        echo "Testing multiple errors are all reported...\n";

        $service = new PluginMetadataService();

        $metadata = [
            'name' => str_repeat('a', 150), // Too long
            'version' => ['not', 'string'], // Type mismatch
            'description' => str_repeat('x', 6000), // Too long
            // author missing
        ];

        $result = $service->validateMetadata($metadata);

        self::assertFalse($result['valid'], 'Multiple errors should fail validation');
        self::assertTrue(count($result['errors']) >= 3, 'Should have multiple errors');

        $errorTypes = array_column($result['errors'], 'error');
        self::assertTrue(in_array('missing_field', $errorTypes), 'Should have missing_field error');
        self::assertTrue(in_array('type_mismatch', $errorTypes), 'Should have type_mismatch error');
        self::assertTrue(in_array('name_too_long', $errorTypes), 'Should have name_too_long error');

        echo "\n";
    }

    /**
     * Test optional fields are accepted
     */
    private static function testOptionalFieldsAccepted(): void {
        echo "Testing optional fields are accepted...\n";

        $service = new PluginMetadataService();

        $metadata = [
            'name' => 'full-plugin',
            'version' => '1.0.0',
            'description' => 'A plugin with all optional fields',
            'author' => 'Complete Author',
            'tags' => ['tag1', 'tag2'],
            'dependencies' => ['dep1' => '^1.0'],
            'license' => 'MIT',
            'homepage' => 'https://example.com',
            'repository' => 'https://github.com/example/repo'
        ];

        $result = $service->validateMetadata($metadata);

        self::assertTrue($result['valid'], 'Metadata with all optional fields should be valid');
        self::assertEqual(count($result['errors']), 0, 'No errors with optional fields');

        echo "\n";
    }

    // === Helper Methods ===

    /**
     * Assert two values are equal
     */
    private static function assertEqual($actual, $expected, string $message): void {
        if ($actual === $expected) {
            self::$passed++;
            echo "  [PASS] {$message}\n";
        } else {
            self::$failed++;
            self::$errors[] = "{$message}: expected " . var_export($expected, true) . ", got " . var_export($actual, true);
            echo "  [FAIL] {$message}\n";
        }
    }

    /**
     * Assert value is true
     */
    private static function assertTrue($value, string $message): void {
        if ($value === true) {
            self::$passed++;
            echo "  [PASS] {$message}\n";
        } else {
            self::$failed++;
            self::$errors[] = "{$message}: expected true, got " . var_export($value, true);
            echo "  [FAIL] {$message}\n";
        }
    }

    /**
     * Assert value is false
     */
    private static function assertFalse($value, string $message): void {
        if ($value === false) {
            self::$passed++;
            echo "  [PASS] {$message}\n";
        } else {
            self::$failed++;
            self::$errors[] = "{$message}: expected false, got " . var_export($value, true);
            echo "  [FAIL] {$message}\n";
        }
    }

    /**
     * Assert value is not null
     */
    private static function assertNotNull($value, string $message): void {
        if ($value !== null) {
            self::$passed++;
            echo "  [PASS] {$message}\n";
        } else {
            self::$failed++;
            self::$errors[] = "{$message}: expected non-null value";
            echo "  [FAIL] {$message}\n";
        }
    }

    /**
     * Print test results summary
     */
    private static function printResults(): void {
        $total = self::$passed + self::$failed;
        echo "\n=== Test Results ===\n";
        echo "Passed: " . self::$passed . "/{$total}\n";
        echo "Failed: " . self::$failed . "/{$total}\n";

        if (count(self::$errors) > 0) {
            echo "\nErrors:\n";
            foreach (self::$errors as $error) {
                echo "  - {$error}\n";
            }
        }

        echo "\n";
        exit(self::$failed > 0 ? 1 : 0);
    }
}

// Run tests
PluginMetadataServiceTest::run();
