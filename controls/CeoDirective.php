<?php
/**
 * CEO Directive Controller
 * Provides secure message reception endpoint for CEO directives
 *
 * Endpoints:
 * - POST /api/ceo/directive - Receive new CEO directive
 * - GET /api/ceo/directive/@id - Get specific directive by ID
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;

require_once __DIR__ . '/../lib/Bean.php';

class CeoDirective extends BaseControls\Control {

    /**
     * Valid priority values for directives
     */
    private const VALID_PRIORITIES = ['high', 'medium', 'low'];

    /**
     * Authenticate via API key
     * Returns member bean if valid, null otherwise
     *
     * @return \RedBeanPHP\OODBBean|null
     */
    private function authenticateApiKey() {
        // Check X-API-Key header
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (empty($apiKey)) {
            // Check Authorization header (Bearer token)
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
                $apiKey = $matches[1];
            }
        }

        if (empty($apiKey)) {
            return null;
        }

        // Look up member by API token in main database
        $member = R::findOne('member', 'api_token = ? AND status = ?', [$apiKey, 'active']);
        return $member ?: null;
    }

    /**
     * Validate directive payload
     *
     * @param array $data Request data
     * @return array Array with 'valid' (bool) and 'errors' (array) keys
     */
    private function validateDirective(array $data): array {
        $errors = [];

        // Validate message (required, non-empty string)
        if (!isset($data['message']) || !is_string($data['message'])) {
            $errors[] = 'message is required and must be a string';
        } elseif (trim($data['message']) === '') {
            $errors[] = 'message cannot be empty';
        }

        // Validate priority (required, must be one of: high, medium, low)
        if (!isset($data['priority']) || !is_string($data['priority'])) {
            $errors[] = 'priority is required and must be a string';
        } elseif (!in_array(strtolower($data['priority']), self::VALID_PRIORITIES, true)) {
            $errors[] = 'priority must be one of: ' . implode(', ', self::VALID_PRIORITIES);
        }

        // Validate sender_id (required, non-empty string)
        if (!isset($data['sender_id']) || !is_string($data['sender_id'])) {
            $errors[] = 'sender_id is required and must be a string';
        } elseif (trim($data['sender_id']) === '') {
            $errors[] = 'sender_id cannot be empty';
        }

        // Optional: validate subject if provided
        if (isset($data['subject']) && !is_string($data['subject'])) {
            $errors[] = 'subject must be a string if provided';
        }

        // Optional: validate metadata if provided
        if (isset($data['metadata']) && !is_array($data['metadata'])) {
            $errors[] = 'metadata must be an object if provided';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Receive a new CEO directive
     * POST /api/ceo/directive
     *
     * Expected JSON payload:
     * {
     *   "message": "string - the directive content (required)",
     *   "priority": "string - high/medium/low (required)",
     *   "sender_id": "string - identifier of sender (required)",
     *   "subject": "string - directive subject (optional)",
     *   "metadata": "object - additional data (optional)"
     * }
     *
     * @param array $params Route parameters
     */
    public function receive($params = []) {
        // Authenticate via API key
        $member = $this->authenticateApiKey();
        if (!$member) {
            $this->logger->warning('CEO directive: unauthorized access attempt');
            Flight::jsonError('Unauthorized - valid API key required', 401);
            return;
        }

        $this->logger->info('CEO directive endpoint accessed', ['member_id' => $member->id]);

        // Get request body
        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true);

        // Check for valid JSON
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('CEO directive: invalid JSON payload');
            Flight::jsonError('Invalid JSON payload: ' . json_last_error_msg(), 400);
            return;
        }

        // Ensure data is an array
        if (!is_array($data)) {
            $this->logger->warning('CEO directive: payload must be a JSON object');
            Flight::jsonError('Request body must be a JSON object', 400);
            return;
        }

        // Validate payload
        $validation = $this->validateDirective($data);
        if (!$validation['valid']) {
            $this->logger->warning('CEO directive: validation failed', ['errors' => $validation['errors']]);
            Flight::json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validation['errors']
            ], 400);
            return;
        }

        try {
            // Store directive using Bean wrapper (for user database compatibility)
            $directive = Bean::dispense('ceodirectives');
            $directive->message = trim($data['message']);
            $directive->priority = strtolower($data['priority']);
            $directive->sender_id = trim($data['sender_id']);
            $directive->subject = isset($data['subject']) ? trim($data['subject']) : null;
            $directive->metadata = isset($data['metadata']) ? json_encode($data['metadata']) : null;
            $directive->member_id = $member->id;
            $directive->status = 'received';
            $directive->created_at = date('Y-m-d H:i:s');
            $directive->updated_at = date('Y-m-d H:i:s');

            $directiveId = Bean::store($directive);

            $this->logger->info('CEO directive stored successfully', [
                'directive_id' => $directiveId,
                'member_id' => $member->id,
                'priority' => $directive->priority
            ]);

            Flight::jsonSuccess([
                'directive_id' => $directiveId,
                'status' => 'received',
                'received_at' => $directive->created_at
            ], 'Directive received successfully');

        } catch (Exception $e) {
            $this->logger->error('CEO directive: storage failed', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to store directive', 500);
        }
    }

    /**
     * Get a specific directive by ID
     * GET /api/ceo/directive/@id
     *
     * @param array $params Route parameters (contains 'id' from URL)
     */
    public function get($params = []) {
        // Authenticate via API key
        $member = $this->authenticateApiKey();
        if (!$member) {
            $this->logger->warning('CEO directive get: unauthorized access attempt');
            Flight::jsonError('Unauthorized - valid API key required', 401);
            return;
        }

        // Get directive ID from route parameters
        $directiveId = isset($params['operation']) ? (int) $params['operation']->name : 0;

        if ($directiveId <= 0) {
            Flight::jsonError('Invalid directive ID', 400);
            return;
        }

        try {
            // Find directive - only allow access to directives belonging to authenticated member
            $directive = Bean::findOne('ceodirectives', 'id = ? AND member_id = ?', [$directiveId, $member->id]);

            if (!$directive) {
                Flight::jsonError('Directive not found', 404);
                return;
            }

            Flight::jsonSuccess([
                'id' => (int) $directive->id,
                'message' => $directive->message,
                'priority' => $directive->priority,
                'sender_id' => $directive->sender_id,
                'subject' => $directive->subject,
                'metadata' => $directive->metadata ? json_decode($directive->metadata, true) : null,
                'status' => $directive->status,
                'created_at' => $directive->created_at,
                'updated_at' => $directive->updated_at
            ]);

        } catch (Exception $e) {
            $this->logger->error('CEO directive get: failed', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to retrieve directive', 500);
        }
    }
}
