<?php
/**
 * Directives Controller
 *
 * Handles incoming CEO directive messages.
 * Validates directives and queues them for processing.
 */

namespace app;

use \Flight as Flight;
use \app\services\CeoDirectiveService;

require_once __DIR__ . '/../services/CeoDirectiveService.php';

class Directives extends BaseControls\Control {

    private CeoDirectiveService $directiveService;

    public function __construct() {
        parent::__construct();
        $this->directiveService = new CeoDirectiveService();
    }

    /**
     * Receive a CEO directive
     * Endpoint: POST /directives/receive
     *
     * Expected JSON body:
     * {
     *   "directive_type": "strategic|operational|urgent|informational|action_required",
     *   "content": "The directive content...",
     *   "priority": "critical|high|medium|low",
     *   "timestamp": "2024-01-15T10:30:00Z",
     *   "subject": "Optional subject line",
     *   "metadata": {"key": "value"},
     *   "expires_at": "2024-01-20T10:30:00Z",
     *   "source": "ceo@example.com",
     *   "target_team": "engineering",
     *   "requires_acknowledgment": true
     * }
     */
    public function receive() {
        // Only accept POST requests
        if (Flight::request()->method !== 'POST') {
            $this->logger->warning('Directives endpoint called with non-POST method', [
                'method' => Flight::request()->method
            ]);
            Flight::response()->status(405);
            Flight::json([
                'success' => false,
                'error' => 'Method not allowed. Use POST.'
            ]);
            return;
        }

        // Get raw payload
        $payload = file_get_contents('php://input');

        if (empty($payload)) {
            $this->logger->warning('Directives receive: empty payload');
            Flight::response()->status(400);
            Flight::json([
                'success' => false,
                'error' => 'Empty request body'
            ]);
            return;
        }

        // Parse JSON
        $directive = json_decode($payload, true);
        if ($directive === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Directives receive: invalid JSON', [
                'json_error' => json_last_error_msg()
            ]);
            Flight::response()->status(400);
            Flight::json([
                'success' => false,
                'error' => 'Invalid JSON: ' . json_last_error_msg()
            ]);
            return;
        }

        // Validate the directive
        $validation = $this->directiveService->validate($directive);

        if (!$validation['valid']) {
            $this->logger->info('Directive validation failed', [
                'error_count' => count($validation['errors'])
            ]);

            // Determine specific error type for status code
            $hasMissingFields = false;
            $hasTypeMismatch = false;

            foreach ($validation['errors'] as $error) {
                if ($error['error'] === 'missing_field' || $error['error'] === 'empty_field') {
                    $hasMissingFields = true;
                }
                if ($error['error'] === 'type_mismatch') {
                    $hasTypeMismatch = true;
                }
            }

            // Use 400 for validation errors
            Flight::response()->status(400);
            Flight::json([
                'success' => false,
                'error' => 'Validation failed',
                'validation_errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
                'error_summary' => $this->buildErrorSummary($validation['errors'])
            ]);
            return;
        }

        // Get member ID if authenticated (optional - directives can come from external sources)
        $memberId = null;
        if (Flight::isLoggedIn()) {
            $memberId = $this->member->id ?? null;
        }

        // Parse and queue the directive
        $result = $this->directiveService->parseAndQueue($directive, $memberId);

        if (!$result['success']) {
            $this->logger->error('Failed to queue directive', [
                'error' => $result['error']
            ]);
            Flight::response()->status(500);
            Flight::json([
                'success' => false,
                'error' => $result['error'],
                'validation_errors' => $result['validation_errors'] ?? []
            ]);
            return;
        }

        // Success - directive queued
        $this->logger->info('Directive received and queued', [
            'directive_id' => $result['directive_id'],
            'directive_type' => $directive['directive_type'],
            'priority' => $directive['priority']
        ]);

        Flight::response()->status(201);
        Flight::json([
            'success' => true,
            'message' => 'Directive received and queued for processing',
            'directive_id' => $result['directive_id'],
            'status' => $result['status'],
            'warnings' => $result['warnings'] ?? []
        ]);
    }

    /**
     * Get directive schema information
     * Endpoint: GET /directives/schema
     *
     * Returns the expected schema for CEO directives
     */
    public function schema() {
        Flight::json([
            'success' => true,
            'schema' => [
                'required_fields' => CeoDirectiveService::getRequiredFields(),
                'valid_directive_types' => CeoDirectiveService::getValidDirectiveTypes(),
                'valid_priorities' => CeoDirectiveService::getValidPriorities(),
                'optional_fields' => [
                    'subject' => 'string',
                    'metadata' => 'object',
                    'expires_at' => 'string (ISO 8601)',
                    'source' => 'string',
                    'target_team' => 'string',
                    'requires_acknowledgment' => 'boolean'
                ],
                'example' => [
                    'directive_type' => 'strategic',
                    'content' => 'We need to prioritize customer retention metrics for Q1 2024.',
                    'priority' => 'high',
                    'timestamp' => date('c'),
                    'subject' => 'Q1 Priority Focus',
                    'source' => 'ceo@company.com',
                    'target_team' => 'engineering',
                    'requires_acknowledgment' => true
                ]
            ]
        ]);
    }

    /**
     * Validate a directive without queueing it
     * Endpoint: POST /directives/validate
     *
     * Useful for testing directive format before sending
     */
    public function validateOnly() {
        // Only accept POST requests
        if (Flight::request()->method !== 'POST') {
            Flight::response()->status(405);
            Flight::json([
                'success' => false,
                'error' => 'Method not allowed. Use POST.'
            ]);
            return;
        }

        // Get raw payload
        $payload = file_get_contents('php://input');

        if (empty($payload)) {
            Flight::response()->status(400);
            Flight::json([
                'success' => false,
                'error' => 'Empty request body'
            ]);
            return;
        }

        // Parse JSON
        $directive = json_decode($payload, true);
        if ($directive === null && json_last_error() !== JSON_ERROR_NONE) {
            Flight::response()->status(400);
            Flight::json([
                'success' => false,
                'error' => 'Invalid JSON: ' . json_last_error_msg()
            ]);
            return;
        }

        // Validate the directive
        $validation = $this->directiveService->validate($directive);

        $statusCode = $validation['valid'] ? 200 : 400;
        Flight::response()->status($statusCode);

        Flight::json([
            'success' => $validation['valid'],
            'valid' => $validation['valid'],
            'errors' => $validation['errors'],
            'warnings' => $validation['warnings'],
            'error_summary' => $validation['valid'] ? null : $this->buildErrorSummary($validation['errors'])
        ]);
    }

    /**
     * Build a human-readable error summary
     *
     * @param array $errors Array of error objects
     * @return string Human-readable error summary
     */
    private function buildErrorSummary(array $errors): string {
        $missingFields = [];
        $typeMismatches = [];
        $otherErrors = [];

        foreach ($errors as $error) {
            switch ($error['error']) {
                case 'missing_field':
                case 'empty_field':
                    $missingFields[] = $error['field'];
                    break;
                case 'type_mismatch':
                    $typeMismatches[] = "{$error['field']} (expected {$error['expected_type']}, got {$error['actual_type']})";
                    break;
                default:
                    $otherErrors[] = $error['message'];
            }
        }

        $parts = [];

        if (!empty($missingFields)) {
            $parts[] = 'Missing required fields: ' . implode(', ', $missingFields);
        }

        if (!empty($typeMismatches)) {
            $parts[] = 'Type mismatches: ' . implode('; ', $typeMismatches);
        }

        if (!empty($otherErrors)) {
            $parts[] = implode('. ', $otherErrors);
        }

        return implode('. ', $parts);
    }
}
