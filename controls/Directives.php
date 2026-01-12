<?php
/**
 * Directives Controller
 * Manages CEO directives listing and detail views
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;
use \app\services\UserDatabaseService;

require_once __DIR__ . '/../lib/Bean.php';
require_once __DIR__ . '/../services/UserDatabaseService.php';

class Directives extends BaseControls\Control {

    private $userDbConnected = false;

    /**
     * Initialize user database connection
     */
    private function initUserDb() {
        if (!$this->userDbConnected && $this->member && !empty($this->member->ceobot_db)) {
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
}
