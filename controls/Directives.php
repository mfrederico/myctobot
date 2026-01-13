<?php
/**
 * Directives Controller
 *
 * Handles both UI views for directive management and API endpoints for
 * receiving/validating CEO directive messages.
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;
use \app\services\UserDatabaseService;
use \app\services\CeoDirectiveService;

require_once __DIR__ . '/../services/UserDatabaseService.php';
require_once __DIR__ . '/../services/CeoDirectiveService.php';

class Directives extends BaseControls\Control {

    private bool $userDbConnected = false;
    private CeoDirectiveService $directiveService;

    public function __construct() {
        parent::__construct();
        $this->directiveService = new CeoDirectiveService();
    }

    /**
     * Initialize user database connection
     */
    private function initUserDb(): bool {
        if (!$this->userDbConnected && $this->member) {
            try {
                UserDatabaseService::connect($this->member->id);
                $this->userDbConnected = true;
            } catch (\Exception $e) {
                $this->logger->error('Failed to initialize user database: ' . $e->getMessage());
                return false;
            }
        }
        return $this->userDbConnected;
    }

    // ==================== UI Methods ====================

    /**
     * List all directives
     */
    public function index() {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->flash('error', 'User database not initialized');
            Flight::redirect('/settings/connections');
            return;
        }

        // Get filter parameters
        $status = $this->getParam('status');
        $page = max(1, (int)$this->getParam('page', 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        // Build query
        $where = '1=1';
        $params = [];

        if ($status) {
            $where .= ' AND status = ?';
            $params[] = $status;
        }

        // Get total count
        $total = Bean::count('ceodirectives', $where, $params);
        $totalPages = ceil($total / $perPage);

        // Get directives with pagination
        $directives = Bean::find('ceodirectives',
            $where . ' ORDER BY created_at DESC LIMIT ?, ?',
            array_merge($params, [$offset, $perPage])
        );

        // Get status counts for filters
        $statusCounts = [
            'received' => Bean::count('ceodirectives', 'status = ?', ['received']),
            'parsing' => Bean::count('ceodirectives', 'status = ?', ['parsing']),
            'planning' => Bean::count('ceodirectives', 'status = ?', ['planning']),
            'executing' => Bean::count('ceodirectives', 'status = ?', ['executing']),
            'completed' => Bean::count('ceodirectives', 'status = ?', ['completed']),
            'failed' => Bean::count('ceodirectives', 'status = ?', ['failed']),
        ];

        $this->render('directives/index', [
            'title' => 'CEO Directives',
            'directives' => $directives,
            'status' => $status,
            'statusCounts' => $statusCounts,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => $totalPages
        ]);
    }

    /**
     * View directive detail
     */
    public function view($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->flash('error', 'User database not initialized');
            Flight::redirect('/directives');
            return;
        }

        // Get directive ID from URL
        $directiveId = $params['operation']->name ?? $this->getParam('id');
        if (!$directiveId) {
            $this->flash('error', 'No directive specified');
            Flight::redirect('/directives');
            return;
        }

        // Find directive by ID or directive_id
        $directive = is_numeric($directiveId)
            ? Bean::load('ceodirectives', $directiveId)
            : Bean::findOne('ceodirectives', 'directive_id = ?', [$directiveId]);

        if (!$directive || !$directive->id) {
            $this->flash('error', 'Directive not found');
            Flight::redirect('/directives');
            return;
        }

        // Get processing logs
        $logs = Bean::find('directivelogs',
            'directive_id = ? ORDER BY created_at ASC',
            [$directive->id]
        );

        // Get linked project if any
        $project = null;
        if ($directive->project_id) {
            $project = Bean::load('ctoprojects', $directive->project_id);
        }

        // Get member info if available
        $member = null;
        if ($directive->member_id) {
            $member = R::load('member', $directive->member_id);
        }

        $this->render('directives/view', [
            'title' => 'Directive: ' . ($directive->email_subject ?: 'Untitled'),
            'directive' => $directive,
            'logs' => $logs,
            'project' => $project,
            'member' => $member
        ]);
    }

    /**
     * Retry a failed directive
     */
    public function retry($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            Flight::jsonError('User database not initialized');
            return;
        }

        // Get directive ID from URL
        $directiveId = $params['operation']->name ?? $this->getParam('id');
        if (!$directiveId) {
            Flight::jsonError('No directive specified');
            return;
        }

        // Find directive
        $directive = is_numeric($directiveId)
            ? Bean::load('ceodirectives', $directiveId)
            : Bean::findOne('ceodirectives', 'directive_id = ?', [$directiveId]);

        if (!$directive || !$directive->id) {
            Flight::jsonError('Directive not found');
            return;
        }

        // Can only retry failed or received directives
        if (!in_array($directive->status, ['failed', 'received'])) {
            Flight::jsonError('Can only retry failed or received directives');
            return;
        }

        // Reset status to received for reprocessing
        $directive->status = 'received';
        $directive->error_message = null;
        $directive->current_phase = null;
        $directive->updated_at = date('Y-m-d H:i:s');
        Bean::store($directive);

        // Log the retry
        $this->logDirective($directive->id, 'retry', 'info', 'Directive queued for retry', [
            'previous_status' => $directive->status,
            'retried_by' => $this->member->id
        ]);

        $this->logger->info('Directive queued for retry', [
            'directive_id' => $directive->directive_id,
            'member_id' => $this->member->id
        ]);

        Flight::jsonSuccess(['directive_id' => $directive->directive_id], 'Directive queued for retry');
    }

    /**
     * Cancel a directive
     */
    public function cancel($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            Flight::jsonError('User database not initialized');
            return;
        }

        // Get directive ID from URL
        $directiveId = $params['operation']->name ?? $this->getParam('id');
        if (!$directiveId) {
            Flight::jsonError('No directive specified');
            return;
        }

        // Find directive
        $directive = is_numeric($directiveId)
            ? Bean::load('ceodirectives', $directiveId)
            : Bean::findOne('ceodirectives', 'directive_id = ?', [$directiveId]);

        if (!$directive || !$directive->id) {
            Flight::jsonError('Directive not found');
            return;
        }

        // Can only cancel non-completed directives
        if ($directive->status === 'completed') {
            Flight::jsonError('Cannot cancel completed directives');
            return;
        }

        // Mark as failed with cancelled status
        $oldStatus = $directive->status;
        $directive->status = 'failed';
        $directive->error_message = 'Cancelled by user';
        $directive->updated_at = date('Y-m-d H:i:s');
        Bean::store($directive);

        // Log the cancellation
        $this->logDirective($directive->id, 'cancelled', 'info', 'Directive cancelled by user', [
            'previous_status' => $oldStatus,
            'cancelled_by' => $this->member->id
        ]);

        $this->logger->info('Directive cancelled', [
            'directive_id' => $directive->directive_id,
            'member_id' => $this->member->id,
            'previous_status' => $oldStatus
        ]);

        Flight::jsonSuccess(['directive_id' => $directive->directive_id], 'Directive cancelled');
    }

    /**
     * Delete a directive permanently
     */
    public function delete($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            Flight::jsonError('User database not initialized');
            return;
        }

        // Get directive ID from URL
        $directiveId = $params['operation']->name ?? $this->getParam('id');
        if (!$directiveId) {
            Flight::jsonError('No directive specified');
            return;
        }

        // Find directive
        $directive = is_numeric($directiveId)
            ? Bean::load('ceodirectives', $directiveId)
            : Bean::findOne('ceodirectives', 'directive_id = ?', [$directiveId]);

        if (!$directive || !$directive->id) {
            Flight::jsonError('Directive not found');
            return;
        }

        // Delete associated logs
        $logs = Bean::find('directivelogs', 'directive_id = ?', [$directive->id]);
        foreach ($logs as $log) {
            Bean::trash($log);
        }

        // Delete associated project if exists (cascade to epics and stories)
        $project = Bean::findOne('ctoprojects', 'directive_id = ?', [$directive->id]);
        if ($project) {
            // Delete stories in each epic
            $epics = Bean::find('ctoepics', 'project_id = ?', [$project->id]);
            foreach ($epics as $epic) {
                $stories = Bean::find('ctostories', 'epic_id = ?', [$epic->id]);
                foreach ($stories as $story) {
                    Bean::trash($story);
                }
                Bean::trash($epic);
            }
            Bean::trash($project);
        }

        $this->logger->info('Directive deleted', [
            'directive_id' => $directive->directive_id,
            'member_id' => $this->member->id
        ]);

        // Delete the directive
        Bean::trash($directive);

        Flight::jsonSuccess([], 'Directive deleted');
    }

    /**
     * Log a directive processing event
     */
    private function logDirective(int $directiveId, string $phase, string $level, string $message, array $context = []): void {
        try {
            $log = Bean::dispense('directivelogs');
            $log->directive_id = $directiveId;
            $log->phase = $phase;
            $log->log_level = $level;
            $log->message = $message;
            $log->context_json = !empty($context) ? json_encode($context) : null;
            $log->created_at = date('Y-m-d H:i:s');
            Bean::store($log);
        } catch (\Exception $e) {
            $this->logger->error('Failed to log directive event', [
                'error' => $e->getMessage(),
                'directive_id' => $directiveId,
                'phase' => $phase
            ]);
        }
    }

    // ==================== API Methods ====================

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

            // Use 400 for validation errors
            Flight::response()->status(400);
            Flight::json([
                'success' => false,
                'error' => 'Validation failed',
                'validation_errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
                'error_summary' => $this->buildValidationErrorSummary($validation['errors'])
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
            'error_summary' => $validation['valid'] ? null : $this->buildValidationErrorSummary($validation['errors'])
        ]);
    }

    /**
     * Build a human-readable validation error summary
     *
     * @param array $errors Array of error objects
     * @return string Human-readable error summary
     */
    private function buildValidationErrorSummary(array $errors): string {
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
