<?php
/**
 * CEO Directive Validation Unit Tests
 *
 * Tests the CeoDirectiveService validation logic.
 * Run with: php tests/CeoDirectiveValidationTest.php
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

require_once __DIR__ . '/../services/CeoDirectiveService.php';

use app\services\CeoDirectiveService;

class CeoDirectiveValidationTest {

    private static int $passed = 0;
    private static int $failed = 0;
    private static array $errors = [];

    /**
     * Run all tests
     */
    public static function run(): void {
        echo "=== CEO Directive Validation Unit Tests ===\n\n";

        // Run validation tests
        self::testValidDirectivePasses();
        self::testMissingRequiredFields();
        self::testEmptyRequiredFields();
        self::testTypeMismatchString();
        self::testTypeMismatchArray();
        self::testTypeMismatchBoolean();
        self::testInvalidDirectiveType();
        self::testInvalidPriority();
        self::testInvalidTimestampFormat();
        self::testValidTimestampFormats();
        self::testContentTooLong();
        self::testContentTooShortWarning();
        self::testOptionalFieldsAccepted();
        self::testUnknownFieldsIgnored();
        self::testMultipleErrors();

        self::printResults();
    }

    /**
     * Test that a properly formatted directive passes validation
     */
    private static function testValidDirectivePasses(): void {
        echo "Testing valid directive passes validation...\n";

        $service = new CeoDirectiveService();

        $validDirective = [
            'directive_type' => 'strategic',
            'content' => 'We need to prioritize customer retention metrics for Q1 2024. This is a strategic initiative.',
            'priority' => 'high',
            'timestamp' => '2024-01-15T10:30:00Z'
        ];

        $result = $service->validate($validDirective);

        self::assertTrue($result['valid'], 'Valid directive should pass validation');
        self::assertEqual(count($result['errors']), 0, 'No errors for valid directive');

        echo "\n";
    }

    /**
     * Test that missing required fields are detected
     */
    private static function testMissingRequiredFields(): void {
        echo "Testing missing required fields detection...\n";

        $service = new CeoDirectiveService();

        // Missing all required fields
        $emptyDirective = [];
        $result = $service->validate($emptyDirective);

        self::assertFalse($result['valid'], 'Empty directive should fail validation');
        self::assertEqual(count($result['errors']), 4, 'Should have 4 missing field errors');

        // Check each missing field is reported
        $missingFields = array_column($result['errors'], 'field');
        self::assertTrue(in_array('directive_type', $missingFields), 'Missing directive_type reported');
        self::assertTrue(in_array('content', $missingFields), 'Missing content reported');
        self::assertTrue(in_array('priority', $missingFields), 'Missing priority reported');
        self::assertTrue(in_array('timestamp', $missingFields), 'Missing timestamp reported');

        // Verify error type
        foreach ($result['errors'] as $error) {
            self::assertEqual($error['error'], 'missing_field', "Error type is 'missing_field'");
        }

        echo "\n";
    }

    /**
     * Test that empty required fields are detected
     */
    private static function testEmptyRequiredFields(): void {
        echo "Testing empty required fields detection...\n";

        $service = new CeoDirectiveService();

        $directive = [
            'directive_type' => '',
            'content' => '',
            'priority' => '',
            'timestamp' => ''
        ];

        $result = $service->validate($directive);

        self::assertFalse($result['valid'], 'Empty fields should fail validation');
        self::assertTrue(count($result['errors']) >= 4, 'Should have at least 4 errors');

        // Count empty_field errors specifically
        $emptyFieldErrors = array_filter($result['errors'], fn($e) => $e['error'] === 'empty_field');
        self::assertEqual(count($emptyFieldErrors), 4, 'Should have 4 empty_field errors');

        // Verify all required fields have empty_field errors
        $emptyFields = array_column($emptyFieldErrors, 'field');
        self::assertTrue(in_array('directive_type', $emptyFields), 'directive_type has empty_field error');
        self::assertTrue(in_array('content', $emptyFields), 'content has empty_field error');
        self::assertTrue(in_array('priority', $emptyFields), 'priority has empty_field error');
        self::assertTrue(in_array('timestamp', $emptyFields), 'timestamp has empty_field error');

        echo "\n";
    }

    /**
     * Test type mismatch for string fields
     */
    private static function testTypeMismatchString(): void {
        echo "Testing type mismatch for string fields...\n";

        $service = new CeoDirectiveService();

        // Pass array instead of string
        $directive = [
            'directive_type' => ['not', 'a', 'string'],
            'content' => 'Valid content here that is long enough.',
            'priority' => 'high',
            'timestamp' => '2024-01-15T10:30:00Z'
        ];

        $result = $service->validate($directive);

        self::assertFalse($result['valid'], 'Array in string field should fail');

        $typeErrors = array_filter($result['errors'], fn($e) => $e['error'] === 'type_mismatch');
        self::assertTrue(count($typeErrors) >= 1, 'Should have type mismatch error');

        $firstError = reset($typeErrors);
        self::assertEqual($firstError['field'], 'directive_type', 'Error on directive_type field');
        self::assertEqual($firstError['expected_type'], 'string', 'Expected type is string');
        self::assertEqual($firstError['actual_type'], 'array', 'Actual type is array');

        echo "\n";
    }

    /**
     * Test type mismatch for array fields
     */
    private static function testTypeMismatchArray(): void {
        echo "Testing type mismatch for array fields (metadata)...\n";

        $service = new CeoDirectiveService();

        // Pass string instead of array for metadata
        $directive = [
            'directive_type' => 'strategic',
            'content' => 'Valid content here that is long enough.',
            'priority' => 'high',
            'timestamp' => '2024-01-15T10:30:00Z',
            'metadata' => 'not an array'
        ];

        $result = $service->validate($directive);

        self::assertFalse($result['valid'], 'String in array field should fail');

        $typeErrors = array_filter($result['errors'], fn($e) => $e['error'] === 'type_mismatch' && $e['field'] === 'metadata');
        self::assertTrue(count($typeErrors) >= 1, 'Should have type mismatch error for metadata');

        $error = reset($typeErrors);
        self::assertEqual($error['expected_type'], 'array', 'Expected type is array');
        self::assertEqual($error['actual_type'], 'string', 'Actual type is string');

        echo "\n";
    }

    /**
     * Test type mismatch for boolean fields
     */
    private static function testTypeMismatchBoolean(): void {
        echo "Testing type mismatch for boolean fields...\n";

        $service = new CeoDirectiveService();

        // Pass integer instead of boolean
        $directive = [
            'directive_type' => 'strategic',
            'content' => 'Valid content here that is long enough.',
            'priority' => 'high',
            'timestamp' => '2024-01-15T10:30:00Z',
            'requires_acknowledgment' => 123
        ];

        $result = $service->validate($directive);

        self::assertFalse($result['valid'], 'Integer in boolean field should fail');

        $typeErrors = array_filter($result['errors'], fn($e) => $e['error'] === 'type_mismatch' && $e['field'] === 'requires_acknowledgment');
        self::assertTrue(count($typeErrors) >= 1, 'Should have type mismatch error');

        // Test that string "true" is accepted
        $directive['requires_acknowledgment'] = 'true';
        $result2 = $service->validate($directive);
        self::assertTrue($result2['valid'], 'String "true" should be accepted for boolean');

        echo "\n";
    }

    /**
     * Test invalid directive type
     */
    private static function testInvalidDirectiveType(): void {
        echo "Testing invalid directive type...\n";

        $service = new CeoDirectiveService();

        $directive = [
            'directive_type' => 'invalid_type',
            'content' => 'Valid content here that is long enough.',
            'priority' => 'high',
            'timestamp' => '2024-01-15T10:30:00Z'
        ];

        $result = $service->validate($directive);

        self::assertFalse($result['valid'], 'Invalid directive type should fail');

        $valueErrors = array_filter($result['errors'], fn($e) => $e['error'] === 'invalid_value' && $e['field'] === 'directive_type');
        self::assertTrue(count($valueErrors) >= 1, 'Should have invalid value error');

        echo "\n";
    }

    /**
     * Test invalid priority
     */
    private static function testInvalidPriority(): void {
        echo "Testing invalid priority...\n";

        $service = new CeoDirectiveService();

        $directive = [
            'directive_type' => 'strategic',
            'content' => 'Valid content here that is long enough.',
            'priority' => 'super_urgent',
            'timestamp' => '2024-01-15T10:30:00Z'
        ];

        $result = $service->validate($directive);

        self::assertFalse($result['valid'], 'Invalid priority should fail');

        $valueErrors = array_filter($result['errors'], fn($e) => $e['error'] === 'invalid_value' && $e['field'] === 'priority');
        self::assertTrue(count($valueErrors) >= 1, 'Should have invalid value error for priority');

        echo "\n";
    }

    /**
     * Test invalid timestamp format
     */
    private static function testInvalidTimestampFormat(): void {
        echo "Testing invalid timestamp format...\n";

        $service = new CeoDirectiveService();

        $directive = [
            'directive_type' => 'strategic',
            'content' => 'Valid content here that is long enough.',
            'priority' => 'high',
            'timestamp' => 'not a timestamp'
        ];

        $result = $service->validate($directive);

        self::assertFalse($result['valid'], 'Invalid timestamp should fail');

        $formatErrors = array_filter($result['errors'], fn($e) => $e['error'] === 'invalid_format' && $e['field'] === 'timestamp');
        self::assertTrue(count($formatErrors) >= 1, 'Should have invalid format error');

        echo "\n";
    }

    /**
     * Test various valid timestamp formats
     */
    private static function testValidTimestampFormats(): void {
        echo "Testing valid timestamp formats...\n";

        $service = new CeoDirectiveService();

        $validTimestamps = [
            '2024-01-15T10:30:00Z',
            '2024-01-15T10:30:00+00:00',
            '2024-01-15T10:30:00-05:00',
            '2024-01-15 10:30:00',
        ];

        foreach ($validTimestamps as $timestamp) {
            $directive = [
                'directive_type' => 'strategic',
                'content' => 'Valid content here that is long enough.',
                'priority' => 'high',
                'timestamp' => $timestamp
            ];

            $result = $service->validate($directive);
            self::assertTrue($result['valid'], "Timestamp '{$timestamp}' should be valid");
        }

        echo "\n";
    }

    /**
     * Test content too long
     */
    private static function testContentTooLong(): void {
        echo "Testing content too long...\n";

        $service = new CeoDirectiveService();

        $directive = [
            'directive_type' => 'strategic',
            'content' => str_repeat('x', 60000), // 60k chars, over 50k limit
            'priority' => 'high',
            'timestamp' => '2024-01-15T10:30:00Z'
        ];

        $result = $service->validate($directive);

        self::assertFalse($result['valid'], 'Content over 50k should fail');

        $lengthErrors = array_filter($result['errors'], fn($e) => $e['error'] === 'content_too_long');
        self::assertTrue(count($lengthErrors) >= 1, 'Should have content_too_long error');

        echo "\n";
    }

    /**
     * Test content too short generates warning
     */
    private static function testContentTooShortWarning(): void {
        echo "Testing content too short warning...\n";

        $service = new CeoDirectiveService();

        $directive = [
            'directive_type' => 'strategic',
            'content' => 'Short',  // Less than 10 chars
            'priority' => 'high',
            'timestamp' => '2024-01-15T10:30:00Z'
        ];

        $result = $service->validate($directive);

        // Should still be valid, but have warning
        self::assertTrue($result['valid'], 'Short content should still be valid');
        self::assertTrue(count($result['warnings']) > 0, 'Should have warning for short content');

        $shortWarnings = array_filter($result['warnings'], fn($w) => $w['warning'] === 'content_too_short');
        self::assertTrue(count($shortWarnings) >= 1, 'Should have content_too_short warning');

        echo "\n";
    }

    /**
     * Test optional fields are accepted
     */
    private static function testOptionalFieldsAccepted(): void {
        echo "Testing optional fields are accepted...\n";

        $service = new CeoDirectiveService();

        $directive = [
            'directive_type' => 'strategic',
            'content' => 'Valid content here that is long enough.',
            'priority' => 'high',
            'timestamp' => '2024-01-15T10:30:00Z',
            'subject' => 'Q1 Priorities',
            'metadata' => ['department' => 'engineering', 'category' => 'roadmap'],
            'expires_at' => '2024-02-15T10:30:00Z',
            'source' => 'ceo@company.com',
            'target_team' => 'engineering',
            'requires_acknowledgment' => true
        ];

        $result = $service->validate($directive);

        self::assertTrue($result['valid'], 'Directive with all optional fields should be valid');
        self::assertEqual(count($result['errors']), 0, 'No errors with optional fields');

        echo "\n";
    }

    /**
     * Test unknown fields are ignored
     */
    private static function testUnknownFieldsIgnored(): void {
        echo "Testing unknown fields are ignored...\n";

        $service = new CeoDirectiveService();

        $directive = [
            'directive_type' => 'strategic',
            'content' => 'Valid content here that is long enough.',
            'priority' => 'high',
            'timestamp' => '2024-01-15T10:30:00Z',
            'unknown_field' => 'some value',
            'another_unknown' => 123
        ];

        $result = $service->validate($directive);

        self::assertTrue($result['valid'], 'Unknown fields should be ignored');
        self::assertEqual(count($result['errors']), 0, 'No errors for unknown fields');

        echo "\n";
    }

    /**
     * Test multiple errors are all reported
     */
    private static function testMultipleErrors(): void {
        echo "Testing multiple errors are all reported...\n";

        $service = new CeoDirectiveService();

        $directive = [
            'directive_type' => 'invalid_type',  // Invalid value
            'content' => ['not', 'string'],       // Type mismatch
            'priority' => 'super_urgent',         // Invalid value
            // timestamp missing
        ];

        $result = $service->validate($directive);

        self::assertFalse($result['valid'], 'Multiple errors should fail validation');
        self::assertTrue(count($result['errors']) >= 3, 'Should have multiple errors');

        // Should have at least: missing timestamp, invalid directive_type, type mismatch on content, invalid priority
        $errorTypes = array_column($result['errors'], 'error');
        self::assertTrue(in_array('missing_field', $errorTypes), 'Should have missing_field error');
        self::assertTrue(in_array('type_mismatch', $errorTypes), 'Should have type_mismatch error');
        self::assertTrue(in_array('invalid_value', $errorTypes), 'Should have invalid_value error');

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
CeoDirectiveValidationTest::run();
