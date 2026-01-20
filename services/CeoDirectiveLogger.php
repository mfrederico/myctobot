<?php
/**
 * CEO Directive Logger Service
 *
 * Provides comprehensive logging for all CEO directive communications including:
 * - Receipt of directives (from Jira/GitHub webhooks)
 * - Processing status and steps
 * - Error tracking with stack traces and recovery actions
 * - Response delivery status
 *
 * Usage:
 *   $logger = new CeoDirectiveLogger($memberId);
 *   $directiveId = $logger->logDirectiveReceived($issueKey, 'jira', ['event' => 'issue_updated']);
 *   $logger->logProcessingStep($directiveId, 'analyzing', 'Analyzing ticket requirements');
 *   $logger->logResponse($directiveId, 'PR created successfully', 'success');
 */

namespace app\services;

use \Flight as Flight;
use \app\Bean;

class CeoDirectiveLogger {

    // Action constants for consistent logging
    const ACTION_RECEIVED = 'received';
    const ACTION_PROCESSING = 'processing';
    const ACTION_COMPLETED = 'completed';
    const ACTION_ERROR = 'error';
    const ACTION_RESPONSE_SENT = 'response_sent';

    // Delivery status constants
    const DELIVERY_SUCCESS = 'success';
    const DELIVERY_FAILED = 'failed';
    const DELIVERY_PENDING = 'pending';

    private int $memberId;
    private $logger;

    /**
     * Constructor
     *
     * @param int $memberId Member ID for the current user/workspace
     */
    public function __construct(int $memberId) {
        $this->memberId = $memberId;
        $this->logger = Flight::get('log');
    }

    /**
     * Generate a unique directive ID for grouping related log entries
     *
     * @return string Unique directive ID (32 character hex string)
     */
    public function generateDirectiveId(): string {
        return bin2hex(random_bytes(16));
    }

    /**
     * Log receipt of a CEO directive
     *
     * @param string $issueKey Jira/GitHub issue key (e.g., "SSI-1234" or "owner/repo#123")
     * @param string $source Source of the directive ("jira", "github", "api")
     * @param array $contextData Additional context data (event type, webhook data, etc.)
     * @return string The generated directive ID for use in subsequent logs
     */
    public function logDirectiveReceived(string $issueKey, string $source, array $contextData = []): string {
        $directiveId = $this->generateDirectiveId();

        $message = sprintf(
            'CEO directive received from %s for %s',
            $source,
            $issueKey
        );

        $context = array_merge($contextData, [
            'source' => $source,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        $this->createLogEntry(
            $directiveId,
            $issueKey,
            self::ACTION_RECEIVED,
            $message,
            $context
        );

        // Also log to application logger for real-time monitoring
        $this->logger->info($message, [
            'directive_uid' => $directiveId,
            'member_id' => $this->memberId,
            'issue_key' => $issueKey,
            'source' => $source
        ]);

        return $directiveId;
    }

    /**
     * Log a processing step in the directive lifecycle
     *
     * @param string $directiveId Directive ID from logDirectiveReceived()
     * @param string $action Action being performed (e.g., "analyzing", "cloning", "implementing")
     * @param string $message Human-readable message describing the step
     * @param array $context Additional context data
     */
    public function logProcessingStep(string $directiveId, string $action, string $message, array $context = []): void {
        // Get issue_key from previous log entry for this directive
        $issueKey = $this->getIssueKeyForDirective($directiveId);

        $this->createLogEntry(
            $directiveId,
            $issueKey,
            self::ACTION_PROCESSING,
            $message,
            array_merge($context, ['step' => $action])
        );

        $this->logger->debug('CEO directive processing step', [
            'directive_uid' => $directiveId,
            'member_id' => $this->memberId,
            'action' => $action,
            'message' => $message
        ]);
    }

    /**
     * Log completion of a directive
     *
     * @param string $directiveId Directive ID from logDirectiveReceived()
     * @param string $message Completion message
     * @param array $context Additional context data (e.g., PR URL, files changed)
     */
    public function logCompleted(string $directiveId, string $message, array $context = []): void {
        $issueKey = $this->getIssueKeyForDirective($directiveId);

        $this->createLogEntry(
            $directiveId,
            $issueKey,
            self::ACTION_COMPLETED,
            $message,
            $context
        );

        $this->logger->info('CEO directive completed', [
            'directive_uid' => $directiveId,
            'member_id' => $this->memberId,
            'message' => $message
        ]);
    }

    /**
     * Log an error during directive processing
     *
     * @param string $directiveId Directive ID from logDirectiveReceived()
     * @param string $errorMessage Error description
     * @param \Throwable|null $exception Exception object for stack trace
     * @param string|null $recoveryAction Description of recovery action taken
     * @param array $context Additional context data
     */
    public function logError(
        string $directiveId,
        string $errorMessage,
        ?\Throwable $exception = null,
        ?string $recoveryAction = null,
        array $context = []
    ): void {
        $issueKey = $this->getIssueKeyForDirective($directiveId);
        $stackTrace = $exception ? $exception->getTraceAsString() : null;

        // Include exception details in context
        if ($exception) {
            $context['exception_class'] = get_class($exception);
            $context['exception_code'] = $exception->getCode();
            $context['exception_file'] = $exception->getFile();
            $context['exception_line'] = $exception->getLine();
        }

        $this->createLogEntry(
            $directiveId,
            $issueKey,
            self::ACTION_ERROR,
            $errorMessage,
            $context,
            $stackTrace,
            $recoveryAction
        );

        $this->logger->error('CEO directive error', [
            'directive_uid' => $directiveId,
            'member_id' => $this->memberId,
            'error' => $errorMessage,
            'recovery_action' => $recoveryAction,
            'exception' => $exception ? $exception->getMessage() : null
        ]);
    }

    /**
     * Log a response sent back to the CEO (via Jira comment, GitHub comment, etc.)
     *
     * @param string $directiveId Directive ID from logDirectiveReceived()
     * @param string $responseContent Content of the response sent
     * @param string $deliveryStatus Delivery status (success, failed, pending)
     * @param array $context Additional context data
     */
    public function logResponse(
        string $directiveId,
        string $responseContent,
        string $deliveryStatus,
        array $context = []
    ): void {
        $issueKey = $this->getIssueKeyForDirective($directiveId);

        $message = sprintf(
            'Response sent to %s with status: %s',
            $issueKey,
            $deliveryStatus
        );

        $this->createLogEntry(
            $directiveId,
            $issueKey,
            self::ACTION_RESPONSE_SENT,
            $message,
            $context,
            null,  // no stack trace
            null,  // no recovery action
            $responseContent,
            $deliveryStatus
        );

        $logLevel = ($deliveryStatus === self::DELIVERY_SUCCESS) ? 'info' : 'warning';
        $this->logger->$logLevel('CEO directive response sent', [
            'directive_uid' => $directiveId,
            'member_id' => $this->memberId,
            'delivery_status' => $deliveryStatus,
            'response_length' => strlen($responseContent)
        ]);
    }

    /**
     * Retrieve all logs for a specific directive
     *
     * @param string $directiveId Directive ID
     * @return array Array of log entries sorted by creation time
     */
    public function getDirectiveLogs(string $directiveId): array {
        $logs = Bean::find(
            'ceodirectivelogs',
            'directive_uid = ? ORDER BY created_at ASC',
            [$directiveId]
        );

        $result = [];
        foreach ($logs as $log) {
            $result[] = $this->formatLogEntry($log);
        }

        return $result;
    }

    /**
     * Retrieve logs for a specific issue
     *
     * @param string $issueKey Issue key (Jira or GitHub)
     * @param int|null $limit Maximum number of logs to return (null for all)
     * @return array Array of log entries sorted by creation time descending
     */
    public function getLogsForIssue(string $issueKey, ?int $limit = null): array {
        $sql = 'issue_key = ? AND member_id = ? ORDER BY created_at DESC';
        $params = [$issueKey, $this->memberId];

        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit;
        }

        $logs = Bean::find('ceodirectivelogs', $sql, $params);

        $result = [];
        foreach ($logs as $log) {
            $result[] = $this->formatLogEntry($log);
        }

        return $result;
    }

    /**
     * Get recent directive logs for the member
     *
     * @param int $limit Maximum number of logs to return
     * @param string|null $action Filter by action type
     * @return array Array of log entries
     */
    public function getRecentLogs(int $limit = 100, ?string $action = null): array {
        $sql = 'member_id = ?';
        $params = [$this->memberId];

        if ($action !== null) {
            $sql .= ' AND action = ?';
            $params[] = $action;
        }

        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int)$limit;

        $logs = Bean::find('ceodirectivelogs', $sql, $params);

        $result = [];
        foreach ($logs as $log) {
            $result[] = $this->formatLogEntry($log);
        }

        return $result;
    }

    /**
     * Get error logs for a time period
     *
     * @param string $since DateTime string for the start of the period
     * @return array Array of error log entries
     */
    public function getErrorLogsSince(string $since): array {
        $logs = Bean::find(
            'ceodirectivelogs',
            'member_id = ? AND action = ? AND created_at >= ? ORDER BY created_at DESC',
            [$this->memberId, self::ACTION_ERROR, $since]
        );

        $result = [];
        foreach ($logs as $log) {
            $result[] = $this->formatLogEntry($log);
        }

        return $result;
    }

    /**
     * Get the complete audit trail for a directive
     * Includes timing information between steps
     *
     * @param string $directiveId Directive ID
     * @return array Formatted audit trail with timing
     */
    public function getDirectiveAuditTrail(string $directiveId): array {
        $logs = $this->getDirectiveLogs($directiveId);

        if (empty($logs)) {
            return [];
        }

        $trail = [];
        $previousTime = null;

        foreach ($logs as $log) {
            $currentTime = strtotime($log['created_at']);
            $entry = $log;

            if ($previousTime !== null) {
                $entry['time_since_previous'] = $currentTime - $previousTime;
                $entry['time_since_previous_formatted'] = $this->formatDuration($currentTime - $previousTime);
            } else {
                $entry['time_since_previous'] = 0;
                $entry['time_since_previous_formatted'] = '0s';
            }

            // Calculate total time from start
            $startTime = strtotime($logs[0]['created_at']);
            $entry['total_time'] = $currentTime - $startTime;
            $entry['total_time_formatted'] = $this->formatDuration($currentTime - $startTime);

            $trail[] = $entry;
            $previousTime = $currentTime;
        }

        return $trail;
    }

    /**
     * Create a log entry in the database
     */
    private function createLogEntry(
        string $directiveId,
        ?string $issueKey,
        string $action,
        string $message,
        array $context = [],
        ?string $stackTrace = null,
        ?string $recoveryAction = null,
        ?string $responseContent = null,
        ?string $deliveryStatus = null
    ): void {
        try {
            $log = Bean::dispense('ceodirectivelogs');
            $log->directive_uid = $directiveId;
            $log->member_id = $this->memberId;
            $log->issue_key = $issueKey;
            $log->action = $action;
            $log->message = $this->truncate($message, 4000);
            $log->context_json = !empty($context) ? json_encode($context) : null;
            $log->stack_trace = $stackTrace ? $this->truncate($stackTrace, 8000) : null;
            $log->recovery_action = $recoveryAction ? $this->truncate($recoveryAction, 1000) : null;
            $log->response_content = $responseContent ? $this->truncate($responseContent, 8000) : null;
            $log->delivery_status = $deliveryStatus;
            $log->created_at = date('Y-m-d H:i:s');

            Bean::store($log);
        } catch (\Exception $e) {
            // Log to application logger if database write fails
            $this->logger->error('Failed to write CEO directive log to database', [
                'directive_uid' => $directiveId,
                'action' => $action,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get the issue key for a directive from existing logs
     */
    private function getIssueKeyForDirective(string $directiveId): ?string {
        $log = Bean::findOne(
            'ceodirectivelogs',
            'directive_uid = ? AND issue_key IS NOT NULL LIMIT 1',
            [$directiveId]
        );

        return $log ? $log->issue_key : null;
    }

    /**
     * Format a log bean into an array
     */
    private function formatLogEntry($log): array {
        return [
            'id' => (int)$log->id,
            'directive_uid' => $log->directive_uid,
            'member_id' => (int)$log->member_id,
            'issue_key' => $log->issue_key,
            'action' => $log->action,
            'message' => $log->message,
            'context' => $log->context_json ? json_decode($log->context_json, true) : [],
            'stack_trace' => $log->stack_trace,
            'recovery_action' => $log->recovery_action,
            'response_content' => $log->response_content,
            'delivery_status' => $log->delivery_status,
            'created_at' => $log->created_at
        ];
    }

    /**
     * Truncate a string to maximum length
     */
    private function truncate(string $text, int $maxLength): string {
        if (strlen($text) <= $maxLength) {
            return $text;
        }
        return substr($text, 0, $maxLength - 15) . "\n... [truncated]";
    }

    /**
     * Format a duration in seconds to a human-readable string
     */
    private function formatDuration(int $seconds): string {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $secs = $seconds % 60;
            return $minutes . 'm ' . $secs . 's';
        }
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return $hours . 'h ' . $minutes . 'm';
    }
}
