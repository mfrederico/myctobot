<?php
/**
 * CEO Directive Service
 *
 * Handles validation and processing of incoming CEO directive messages.
 * Ensures directives contain required fields and are in the expected format
 * before queuing them for processing.
 */

namespace app\services;

use \Flight as Flight;
use \app\Bean;

class CeoDirectiveService {

    private $logger;

    /**
     * Required fields for a CEO directive with their expected types
     * Types: 'string', 'integer', 'array', 'boolean'
     */
    private const REQUIRED_FIELDS = [
        'directive_type' => 'string',
        'content' => 'string',
        'priority' => 'string',
        'timestamp' => 'string'
    ];

    /**
     * Optional fields with their expected types
     */
    private const OPTIONAL_FIELDS = [
        'subject' => 'string',
        'metadata' => 'array',
        'expires_at' => 'string',
        'source' => 'string',
        'target_team' => 'string',
        'requires_acknowledgment' => 'boolean'
    ];

    /**
     * Valid directive types
     */
    private const VALID_DIRECTIVE_TYPES = [
        'strategic',
        'operational',
        'urgent',
        'informational',
        'action_required'
    ];

    /**
     * Valid priority levels
     */
    private const VALID_PRIORITIES = [
        'critical',
        'high',
        'medium',
        'low'
    ];

    public function __construct() {
        $this->logger = Flight::get('log');
    }

    /**
     * Validate a CEO directive message
     *
     * @param array $directive The directive data to validate
     * @return array Validation result with 'valid', 'errors', and 'warnings' keys
     */
    public function validate(array $directive): array {
        $errors = [];
        $warnings = [];

        // Check for missing required fields
        foreach (self::REQUIRED_FIELDS as $field => $expectedType) {
            if (!array_key_exists($field, $directive)) {
                $errors[] = [
                    'field' => $field,
                    'error' => 'missing_field',
                    'message' => "Required field '{$field}' is missing"
                ];
            } elseif ($directive[$field] === null || $directive[$field] === '') {
                $errors[] = [
                    'field' => $field,
                    'error' => 'empty_field',
                    'message' => "Required field '{$field}' cannot be empty"
                ];
            } else {
                // Check type
                $typeError = $this->validateType($field, $directive[$field], $expectedType);
                if ($typeError) {
                    $errors[] = $typeError;
                }
            }
        }

        // Validate optional fields if present
        foreach (self::OPTIONAL_FIELDS as $field => $expectedType) {
            if (array_key_exists($field, $directive) && $directive[$field] !== null) {
                $typeError = $this->validateType($field, $directive[$field], $expectedType);
                if ($typeError) {
                    $errors[] = $typeError;
                }
            }
        }

        // Validate directive_type if present and valid type
        if (isset($directive['directive_type']) && is_string($directive['directive_type'])) {
            if (!in_array($directive['directive_type'], self::VALID_DIRECTIVE_TYPES, true)) {
                $errors[] = [
                    'field' => 'directive_type',
                    'error' => 'invalid_value',
                    'message' => "Invalid directive_type '{$directive['directive_type']}'. Valid types: " . implode(', ', self::VALID_DIRECTIVE_TYPES)
                ];
            }
        }

        // Validate priority if present and valid type
        if (isset($directive['priority']) && is_string($directive['priority'])) {
            if (!in_array($directive['priority'], self::VALID_PRIORITIES, true)) {
                $errors[] = [
                    'field' => 'priority',
                    'error' => 'invalid_value',
                    'message' => "Invalid priority '{$directive['priority']}'. Valid priorities: " . implode(', ', self::VALID_PRIORITIES)
                ];
            }
        }

        // Validate timestamp format (ISO 8601)
        if (isset($directive['timestamp']) && is_string($directive['timestamp'])) {
            $timestampValid = $this->validateTimestamp($directive['timestamp']);
            if (!$timestampValid) {
                $errors[] = [
                    'field' => 'timestamp',
                    'error' => 'invalid_format',
                    'message' => "Invalid timestamp format. Expected ISO 8601 format (e.g., '2024-01-15T10:30:00Z')"
                ];
            }
        }

        // Validate expires_at format if present
        if (isset($directive['expires_at']) && is_string($directive['expires_at']) && !empty($directive['expires_at'])) {
            $expiresValid = $this->validateTimestamp($directive['expires_at']);
            if (!$expiresValid) {
                $warnings[] = [
                    'field' => 'expires_at',
                    'warning' => 'invalid_format',
                    'message' => "Invalid expires_at format. Expected ISO 8601 format"
                ];
            }
        }

        // Validate content length
        if (isset($directive['content']) && is_string($directive['content'])) {
            if (strlen($directive['content']) > 50000) {
                $errors[] = [
                    'field' => 'content',
                    'error' => 'content_too_long',
                    'message' => 'Content exceeds maximum length of 50000 characters'
                ];
            }
            if (strlen($directive['content']) < 10) {
                $warnings[] = [
                    'field' => 'content',
                    'warning' => 'content_too_short',
                    'message' => 'Content is very short (less than 10 characters)'
                ];
            }
        }

        // Log validation attempt
        if (!empty($errors)) {
            $this->logger->warning('CEO directive validation failed', [
                'error_count' => count($errors),
                'errors' => $errors,
                'directive_type' => $directive['directive_type'] ?? 'unknown'
            ]);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Validate the type of a field value
     *
     * @param string $field Field name
     * @param mixed $value The value to check
     * @param string $expectedType Expected type
     * @return array|null Error array if type mismatch, null if valid
     */
    private function validateType(string $field, $value, string $expectedType): ?array {
        $actualType = gettype($value);

        $typeMap = [
            'string' => 'string',
            'integer' => 'integer',
            'array' => 'array',
            'boolean' => 'boolean'
        ];

        $expectedPhpType = $typeMap[$expectedType] ?? $expectedType;

        // Handle integer that might come as string from JSON
        if ($expectedType === 'integer' && is_string($value) && ctype_digit($value)) {
            return null; // Accept numeric strings for integers
        }

        // Handle boolean that might come as string
        if ($expectedType === 'boolean' && is_string($value)) {
            if (in_array(strtolower($value), ['true', 'false', '1', '0'], true)) {
                return null; // Accept string representations of boolean
            }
        }

        if ($actualType !== $expectedPhpType) {
            return [
                'field' => $field,
                'error' => 'type_mismatch',
                'message' => "Field '{$field}' expected type '{$expectedType}', got '{$actualType}'",
                'expected_type' => $expectedType,
                'actual_type' => $actualType
            ];
        }

        return null;
    }

    /**
     * Validate ISO 8601 timestamp format
     *
     * @param string $timestamp The timestamp string to validate
     * @return bool True if valid ISO 8601 format
     */
    private function validateTimestamp(string $timestamp): bool {
        // Try to parse as DateTime
        $parsed = \DateTime::createFromFormat(\DateTime::ATOM, $timestamp);
        if ($parsed !== false) {
            return true;
        }

        // Try alternate ISO 8601 formats
        $formats = [
            'Y-m-d\TH:i:sP',      // 2024-01-15T10:30:00+00:00
            'Y-m-d\TH:i:s.uP',    // 2024-01-15T10:30:00.000+00:00
            'Y-m-d\TH:i:s\Z',     // 2024-01-15T10:30:00Z
            'Y-m-d\TH:i:sO',      // 2024-01-15T10:30:00+0000
            'Y-m-d H:i:s',        // 2024-01-15 10:30:00
        ];

        foreach ($formats as $format) {
            $parsed = \DateTime::createFromFormat($format, $timestamp);
            if ($parsed !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse and queue a valid directive for processing
     *
     * @param array $directive The validated directive data
     * @param int|null $memberId Optional member ID for association
     * @return array Result with 'success', 'directive_id', and 'error' keys
     */
    public function parseAndQueue(array $directive, ?int $memberId = null): array {
        // Validate first
        $validation = $this->validate($directive);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => 'Validation failed',
                'validation_errors' => $validation['errors']
            ];
        }

        try {
            // Create new CEO directive record
            $directiveBean = Bean::dispense('ceodirectives');

            // Set required fields
            $directiveBean->directive_type = $directive['directive_type'];
            $directiveBean->content = $directive['content'];
            $directiveBean->priority = $directive['priority'];
            $directiveBean->received_at = $directive['timestamp'];

            // Set optional fields if present
            if (isset($directive['subject'])) {
                $directiveBean->subject = $directive['subject'];
            }
            if (isset($directive['metadata']) && is_array($directive['metadata'])) {
                $directiveBean->metadata_json = json_encode($directive['metadata']);
            }
            if (isset($directive['expires_at'])) {
                $directiveBean->expires_at = $directive['expires_at'];
            }
            if (isset($directive['source'])) {
                $directiveBean->source = $directive['source'];
            }
            if (isset($directive['target_team'])) {
                $directiveBean->target_team = $directive['target_team'];
            }
            if (isset($directive['requires_acknowledgment'])) {
                $directiveBean->requires_acknowledgment = $directive['requires_acknowledgment'] ? 1 : 0;
            }

            // Set processing metadata
            $directiveBean->status = 'queued';
            $directiveBean->created_at = date('Y-m-d H:i:s');
            $directiveBean->updated_at = date('Y-m-d H:i:s');

            if ($memberId !== null) {
                $directiveBean->member_id = $memberId;
            }

            // Store the directive
            $directiveId = Bean::store($directiveBean);

            $this->logger->info('CEO directive queued for processing', [
                'directive_id' => $directiveId,
                'directive_type' => $directive['directive_type'],
                'priority' => $directive['priority'],
                'member_id' => $memberId
            ]);

            return [
                'success' => true,
                'directive_id' => $directiveId,
                'status' => 'queued',
                'warnings' => $validation['warnings']
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to queue CEO directive', [
                'error' => $e->getMessage(),
                'directive_type' => $directive['directive_type'] ?? 'unknown'
            ]);

            return [
                'success' => false,
                'error' => 'Failed to store directive: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get valid directive types
     *
     * @return array List of valid directive types
     */
    public static function getValidDirectiveTypes(): array {
        return self::VALID_DIRECTIVE_TYPES;
    }

    /**
     * Get valid priority levels
     *
     * @return array List of valid priority levels
     */
    public static function getValidPriorities(): array {
        return self::VALID_PRIORITIES;
    }

    /**
     * Get required fields schema
     *
     * @return array Required fields with their types
     */
    public static function getRequiredFields(): array {
        return self::REQUIRED_FIELDS;
    }

    /**
     * Log a directive event to the directivelogs table
     *
     * @param int $directiveId The directive ID
     * @param string $phase Processing phase (e.g., 'received', 'retry', 'cancelled')
     * @param string $level Log level ('info', 'warning', 'error')
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public static function logDirectiveEvent(int $directiveId, string $phase, string $level, string $message, array $context = []): void {
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
            $logger = Flight::get('log');
            $logger->error('Failed to log directive event', [
                'error' => $e->getMessage(),
                'directive_id' => $directiveId,
                'phase' => $phase
            ]);
        }
    }
}
