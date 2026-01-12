<?php
/**
 * Response Formatter Service
 * Generates consistent, structured responses for CEO directives with proper status indicators
 *
 * @see GitHub Issue #23
 */

namespace app\services;

class ResponseFormatterService {

    /** Response status constants */
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';
    public const STATUS_PENDING = 'pending';

    /** Error code constants */
    public const ERROR_VALIDATION = 'VALIDATION_ERROR';
    public const ERROR_PROCESSING = 'PROCESSING_ERROR';
    public const ERROR_NOT_FOUND = 'NOT_FOUND';
    public const ERROR_UNAUTHORIZED = 'UNAUTHORIZED';
    public const ERROR_INTERNAL = 'INTERNAL_ERROR';

    /**
     * Generate a success response for a processed CEO directive
     *
     * @param string $directiveId The unique identifier for the directive
     * @param string $message Confirmation message
     * @param array $additionalData Optional additional data to include
     * @return array Formatted success response
     */
    public static function success(string $directiveId, string $message, array $additionalData = []): array {
        return self::formatResponse([
            'status' => self::STATUS_SUCCESS,
            'timestamp' => self::getTimestamp(),
            'directiveId' => $directiveId,
            'message' => $message,
            'data' => $additionalData
        ]);
    }

    /**
     * Generate an error response for a failed CEO directive
     *
     * @param string $errorCode Error code identifier
     * @param string $message Descriptive error message
     * @param array $suggestedNextSteps Array of suggested actions to resolve the error
     * @param string|null $directiveId Optional directive ID if available
     * @return array Formatted error response
     */
    public static function error(
        string $errorCode,
        string $message,
        array $suggestedNextSteps = [],
        ?string $directiveId = null
    ): array {
        return self::formatResponse([
            'status' => self::STATUS_ERROR,
            'timestamp' => self::getTimestamp(),
            'directiveId' => $directiveId,
            'error' => [
                'code' => $errorCode,
                'message' => $message,
                'suggestedNextSteps' => $suggestedNextSteps
            ]
        ]);
    }

    /**
     * Generate a pending response for a directive in progress
     *
     * @param string $directiveId The unique identifier for the directive
     * @param string $message Status message
     * @param int|null $progressPercent Optional progress percentage (0-100)
     * @return array Formatted pending response
     */
    public static function pending(string $directiveId, string $message, ?int $progressPercent = null): array {
        $response = [
            'status' => self::STATUS_PENDING,
            'timestamp' => self::getTimestamp(),
            'directiveId' => $directiveId,
            'message' => $message
        ];

        if ($progressPercent !== null) {
            $response['progress'] = max(0, min(100, $progressPercent));
        }

        return self::formatResponse($response);
    }

    /**
     * Generate a validation error response
     *
     * @param string $message Error message
     * @param array $validationErrors Specific field validation errors
     * @param string|null $directiveId Optional directive ID
     * @return array Formatted validation error response
     */
    public static function validationError(string $message, array $validationErrors = [], ?string $directiveId = null): array {
        return self::error(
            self::ERROR_VALIDATION,
            $message,
            ['Review and correct the invalid fields', 'Resubmit the directive with valid data'],
            $directiveId
        ) + ['validationErrors' => $validationErrors];
    }

    /**
     * Generate a not found error response
     *
     * @param string $resource The resource that was not found
     * @param string|null $directiveId Optional directive ID
     * @return array Formatted not found error response
     */
    public static function notFound(string $resource, ?string $directiveId = null): array {
        return self::error(
            self::ERROR_NOT_FOUND,
            "The requested {$resource} was not found",
            ['Verify the identifier is correct', 'Check if the resource exists', 'Contact support if the issue persists'],
            $directiveId
        );
    }

    /**
     * Format response to ensure it conforms to the JSON schema
     *
     * @param array $response Raw response data
     * @return array Formatted response conforming to schema
     */
    private static function formatResponse(array $response): array {
        // Ensure required fields are present
        $formatted = [
            'version' => '1.0',
            'status' => $response['status'] ?? self::STATUS_ERROR,
            'timestamp' => $response['timestamp'] ?? self::getTimestamp(),
        ];

        // Add directiveId if present
        if (isset($response['directiveId'])) {
            $formatted['directiveId'] = $response['directiveId'];
        }

        // Add message if present
        if (isset($response['message'])) {
            $formatted['message'] = $response['message'];
        }

        // Add data if present and not empty
        if (!empty($response['data'])) {
            $formatted['data'] = $response['data'];
        }

        // Add error details if present
        if (isset($response['error'])) {
            $formatted['error'] = $response['error'];
        }

        // Add progress if present
        if (isset($response['progress'])) {
            $formatted['progress'] = $response['progress'];
        }

        return $formatted;
    }

    /**
     * Get current timestamp in ISO 8601 format
     *
     * @return string ISO 8601 formatted timestamp
     */
    private static function getTimestamp(): string {
        return date('c');
    }

    /**
     * Convert response to JSON string
     *
     * @param array $response Response array
     * @param bool $prettyPrint Whether to format JSON for readability
     * @return string JSON encoded response
     */
    public static function toJson(array $response, bool $prettyPrint = false): string {
        $options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($prettyPrint) {
            $options |= JSON_PRETTY_PRINT;
        }
        return json_encode($response, $options);
    }

    /**
     * Validate that a response conforms to the expected schema
     *
     * @param array $response Response to validate
     * @return bool True if valid, false otherwise
     */
    public static function isValidResponse(array $response): bool {
        // Required fields
        $requiredFields = ['version', 'status', 'timestamp'];

        foreach ($requiredFields as $field) {
            if (!isset($response[$field])) {
                return false;
            }
        }

        // Validate status is one of the allowed values
        $validStatuses = [self::STATUS_SUCCESS, self::STATUS_ERROR, self::STATUS_PENDING];
        if (!in_array($response['status'], $validStatuses, true)) {
            return false;
        }

        // If status is success, message should be present
        if ($response['status'] === self::STATUS_SUCCESS && !isset($response['message'])) {
            return false;
        }

        // If status is error, error details should be present
        if ($response['status'] === self::STATUS_ERROR && !isset($response['error'])) {
            return false;
        }

        return true;
    }

    /**
     * Get the JSON schema definition for CEO directive responses
     *
     * @return array JSON schema as associative array
     */
    public static function getSchema(): array {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'CEO Directive Response',
            'description' => 'Standardized response format for CEO directive acknowledgments',
            'type' => 'object',
            'required' => ['version', 'status', 'timestamp'],
            'properties' => [
                'version' => [
                    'type' => 'string',
                    'description' => 'Response format version',
                    'const' => '1.0'
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Response status indicator',
                    'enum' => [self::STATUS_SUCCESS, self::STATUS_ERROR, self::STATUS_PENDING]
                ],
                'timestamp' => [
                    'type' => 'string',
                    'format' => 'date-time',
                    'description' => 'ISO 8601 formatted timestamp of the response'
                ],
                'directiveId' => [
                    'type' => 'string',
                    'description' => 'Unique identifier for the CEO directive'
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'Human-readable confirmation or status message'
                ],
                'data' => [
                    'type' => 'object',
                    'description' => 'Additional data related to the response'
                ],
                'progress' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                    'description' => 'Progress percentage for pending operations'
                ],
                'error' => [
                    'type' => 'object',
                    'description' => 'Error details when status is error',
                    'required' => ['code', 'message'],
                    'properties' => [
                        'code' => [
                            'type' => 'string',
                            'description' => 'Machine-readable error code'
                        ],
                        'message' => [
                            'type' => 'string',
                            'description' => 'Human-readable error description'
                        ],
                        'suggestedNextSteps' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Suggested actions to resolve the error'
                        ]
                    ]
                ]
            ],
            'if' => [
                'properties' => ['status' => ['const' => self::STATUS_SUCCESS]]
            ],
            'then' => [
                'required' => ['version', 'status', 'timestamp', 'message']
            ],
            'else' => [
                'if' => [
                    'properties' => ['status' => ['const' => self::STATUS_ERROR]]
                ],
                'then' => [
                    'required' => ['version', 'status', 'timestamp', 'error']
                ]
            ]
        ];
    }
}
