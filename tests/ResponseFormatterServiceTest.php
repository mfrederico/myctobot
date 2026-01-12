<?php
/**
 * ResponseFormatterService Unit Tests
 *
 * Tests the ResponseFormatterService for generating standardized CEO directive responses.
 * Run with: php tests/ResponseFormatterServiceTest.php
 *
 * @see GitHub Issue #23
 */

namespace app\tests;

require_once __DIR__ . '/../services/ResponseFormatterService.php';

use app\services\ResponseFormatterService;

class ResponseFormatterServiceTest {

    private static int $passed = 0;
    private static int $failed = 0;
    private static array $errors = [];

    /**
     * Run all tests
     */
    public static function run(): void {
        echo "=== ResponseFormatterService Unit Tests ===\n\n";

        // Run tests for acceptance criteria
        self::testSuccessResponse();
        self::testErrorResponse();
        self::testResponseSchema();
        self::testPendingResponse();
        self::testValidationErrorResponse();
        self::testNotFoundResponse();
        self::testJsonConversion();
        self::testResponseValidation();

        self::printResults();
    }

    /**
     * Test Scenario 1: Success response generation
     * Given: A CEO directive has been successfully processed
     * When: The system generates a response
     * Then: The response contains success status, timestamp, directive ID, and confirmation message
     */
    private static function testSuccessResponse(): void {
        echo "Testing success response (Scenario 1)...\n";

        $directiveId = 'DIR-2024-001';
        $message = 'Directive successfully processed and acknowledged';
        $additionalData = ['processingTime' => '1.5s'];

        $response = ResponseFormatterService::success($directiveId, $message, $additionalData);

        // Check required fields
        self::assertEqual($response['status'], ResponseFormatterService::STATUS_SUCCESS, 'Success status');
        self::assertTrue(isset($response['timestamp']), 'Timestamp present');
        self::assertEqual($response['directiveId'], $directiveId, 'Directive ID');
        self::assertEqual($response['message'], $message, 'Confirmation message');
        self::assertEqual($response['data'], $additionalData, 'Additional data');
        self::assertEqual($response['version'], '1.0', 'Version number');

        // Validate timestamp format (ISO 8601)
        self::assertTimestampFormat($response['timestamp'], 'ISO 8601 timestamp format');

        echo "\n";
    }

    /**
     * Test Scenario 2: Error response generation
     * Given: A CEO directive processing fails
     * When: The system generates an error response
     * Then: The response contains error status, error code, descriptive message, and suggested next steps
     */
    private static function testErrorResponse(): void {
        echo "Testing error response (Scenario 2)...\n";

        $errorCode = ResponseFormatterService::ERROR_PROCESSING;
        $message = 'Unable to process directive due to system constraints';
        $suggestedNextSteps = [
            'Retry the operation after 5 minutes',
            'Contact technical support if issue persists',
            'Check system status page for outages'
        ];
        $directiveId = 'DIR-2024-002';

        $response = ResponseFormatterService::error($errorCode, $message, $suggestedNextSteps, $directiveId);

        // Check required fields
        self::assertEqual($response['status'], ResponseFormatterService::STATUS_ERROR, 'Error status');
        self::assertTrue(isset($response['timestamp']), 'Timestamp present');
        self::assertTrue(isset($response['error']), 'Error object present');
        self::assertEqual($response['error']['code'], $errorCode, 'Error code');
        self::assertEqual($response['error']['message'], $message, 'Error message');
        self::assertEqual($response['error']['suggestedNextSteps'], $suggestedNextSteps, 'Suggested next steps');
        self::assertEqual($response['directiveId'], $directiveId, 'Directive ID');

        echo "\n";
    }

    /**
     * Test Scenario 3: Response schema conformance
     * Given: The response is generated
     * When: The system formats the response
     * Then: The response follows the predefined JSON schema with all required fields
     */
    private static function testResponseSchema(): void {
        echo "Testing response schema conformance (Scenario 3)...\n";

        // Get schema
        $schema = ResponseFormatterService::getSchema();
        self::assertTrue(isset($schema['$schema']), 'Schema has $schema property');
        self::assertTrue(isset($schema['required']), 'Schema has required fields');
        self::assertTrue(in_array('version', $schema['required']), 'Schema requires version');
        self::assertTrue(in_array('status', $schema['required']), 'Schema requires status');
        self::assertTrue(in_array('timestamp', $schema['required']), 'Schema requires timestamp');

        // Test success response conforms to schema
        $successResponse = ResponseFormatterService::success('DIR-001', 'Test message');
        self::assertTrue(ResponseFormatterService::isValidResponse($successResponse), 'Success response validates');

        // Test error response conforms to schema
        $errorResponse = ResponseFormatterService::error(
            ResponseFormatterService::ERROR_INTERNAL,
            'Test error'
        );
        self::assertTrue(ResponseFormatterService::isValidResponse($errorResponse), 'Error response validates');

        // Test pending response conforms to schema
        $pendingResponse = ResponseFormatterService::pending('DIR-001', 'Processing');
        self::assertTrue(ResponseFormatterService::isValidResponse($pendingResponse), 'Pending response validates');

        echo "\n";
    }

    /**
     * Test pending response generation
     */
    private static function testPendingResponse(): void {
        echo "Testing pending response...\n";

        $directiveId = 'DIR-2024-003';
        $message = 'Directive is being processed';
        $progress = 45;

        $response = ResponseFormatterService::pending($directiveId, $message, $progress);

        self::assertEqual($response['status'], ResponseFormatterService::STATUS_PENDING, 'Pending status');
        self::assertEqual($response['directiveId'], $directiveId, 'Directive ID');
        self::assertEqual($response['message'], $message, 'Status message');
        self::assertEqual($response['progress'], $progress, 'Progress percentage');

        // Test progress clamping
        $responseHighProgress = ResponseFormatterService::pending($directiveId, $message, 150);
        self::assertEqual($responseHighProgress['progress'], 100, 'Progress clamped to max 100');

        $responseLowProgress = ResponseFormatterService::pending($directiveId, $message, -10);
        self::assertEqual($responseLowProgress['progress'], 0, 'Progress clamped to min 0');

        echo "\n";
    }

    /**
     * Test validation error response
     */
    private static function testValidationErrorResponse(): void {
        echo "Testing validation error response...\n";

        $message = 'Invalid directive format';
        $validationErrors = [
            'subject' => 'Subject is required',
            'priority' => 'Priority must be between 1 and 5'
        ];

        $response = ResponseFormatterService::validationError($message, $validationErrors, 'DIR-004');

        self::assertEqual($response['status'], ResponseFormatterService::STATUS_ERROR, 'Error status');
        self::assertEqual($response['error']['code'], ResponseFormatterService::ERROR_VALIDATION, 'Validation error code');
        self::assertTrue(count($response['error']['suggestedNextSteps']) > 0, 'Has suggested next steps');
        self::assertTrue(isset($response['validationErrors']), 'Has validation errors');

        echo "\n";
    }

    /**
     * Test not found response
     */
    private static function testNotFoundResponse(): void {
        echo "Testing not found response...\n";

        $response = ResponseFormatterService::notFound('directive', 'DIR-999');

        self::assertEqual($response['status'], ResponseFormatterService::STATUS_ERROR, 'Error status');
        self::assertEqual($response['error']['code'], ResponseFormatterService::ERROR_NOT_FOUND, 'Not found error code');
        self::assertTrue(strpos($response['error']['message'], 'directive') !== false, 'Message mentions resource');
        self::assertTrue(count($response['error']['suggestedNextSteps']) > 0, 'Has suggested next steps');

        echo "\n";
    }

    /**
     * Test JSON conversion
     */
    private static function testJsonConversion(): void {
        echo "Testing JSON conversion...\n";

        $response = ResponseFormatterService::success('DIR-005', 'Test message');
        $json = ResponseFormatterService::toJson($response);

        self::assertTrue(is_string($json), 'JSON is string');
        self::assertTrue(json_decode($json) !== null, 'Valid JSON');

        // Test pretty print
        $prettyJson = ResponseFormatterService::toJson($response, true);
        self::assertTrue(strpos($prettyJson, "\n") !== false, 'Pretty print has newlines');

        echo "\n";
    }

    /**
     * Test response validation
     */
    private static function testResponseValidation(): void {
        echo "Testing response validation...\n";

        // Valid success response
        $validSuccess = ['version' => '1.0', 'status' => 'success', 'timestamp' => date('c'), 'message' => 'OK'];
        self::assertTrue(ResponseFormatterService::isValidResponse($validSuccess), 'Valid success response');

        // Valid error response
        $validError = ['version' => '1.0', 'status' => 'error', 'timestamp' => date('c'), 'error' => ['code' => 'ERR', 'message' => 'Error']];
        self::assertTrue(ResponseFormatterService::isValidResponse($validError), 'Valid error response');

        // Invalid - missing required fields
        $missingVersion = ['status' => 'success', 'timestamp' => date('c')];
        self::assertFalse(ResponseFormatterService::isValidResponse($missingVersion), 'Invalid - missing version');

        // Invalid - wrong status
        $wrongStatus = ['version' => '1.0', 'status' => 'invalid', 'timestamp' => date('c')];
        self::assertFalse(ResponseFormatterService::isValidResponse($wrongStatus), 'Invalid - wrong status');

        // Invalid - success without message
        $successNoMessage = ['version' => '1.0', 'status' => 'success', 'timestamp' => date('c')];
        self::assertFalse(ResponseFormatterService::isValidResponse($successNoMessage), 'Invalid - success without message');

        // Invalid - error without error details
        $errorNoDetails = ['version' => '1.0', 'status' => 'error', 'timestamp' => date('c')];
        self::assertFalse(ResponseFormatterService::isValidResponse($errorNoDetails), 'Invalid - error without details');

        echo "\n";
    }

    /**
     * Assert two values are equal
     */
    private static function assertEqual($actual, $expected, string $description): void {
        if ($actual === $expected) {
            self::$passed++;
            echo "  [PASS] {$description}\n";
        } else {
            self::$failed++;
            self::$errors[] = "{$description}: expected " . var_export($expected, true) . ", got " . var_export($actual, true);
            echo "  [FAIL] {$description}\n";
        }
    }

    /**
     * Assert a condition is true
     */
    private static function assertTrue($condition, string $description): void {
        if ($condition) {
            self::$passed++;
            echo "  [PASS] {$description}\n";
        } else {
            self::$failed++;
            self::$errors[] = "{$description}: expected true";
            echo "  [FAIL] {$description}\n";
        }
    }

    /**
     * Assert a condition is false
     */
    private static function assertFalse($condition, string $description): void {
        if (!$condition) {
            self::$passed++;
            echo "  [PASS] {$description}\n";
        } else {
            self::$failed++;
            self::$errors[] = "{$description}: expected false";
            echo "  [FAIL] {$description}\n";
        }
    }

    /**
     * Assert timestamp is in ISO 8601 format
     */
    private static function assertTimestampFormat(string $timestamp, string $description): void {
        $parsed = \DateTime::createFromFormat(\DateTime::ATOM, $timestamp);
        if ($parsed !== false || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $timestamp)) {
            self::$passed++;
            echo "  [PASS] {$description}\n";
        } else {
            self::$failed++;
            self::$errors[] = "{$description}: '{$timestamp}' is not a valid ISO 8601 timestamp";
            echo "  [FAIL] {$description}\n";
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

        if (!empty(self::$errors)) {
            echo "\nErrors:\n";
            foreach (self::$errors as $error) {
                echo "  - {$error}\n";
            }
        }

        if (self::$failed > 0) {
            exit(1);
        }
    }
}

// Run tests if executed directly
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    ResponseFormatterServiceTest::run();
}
