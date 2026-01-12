<?php
/**
 * Plugin Metadata Service
 *
 * Handles extraction and validation of plugin metadata from plugin.json files.
 * Validates required fields, parses dependencies with version constraints,
 * and stores plugin metadata in the database.
 */

namespace app\services;

use \Flight as Flight;
use \app\Bean;

class PluginMetadataService {

    private $logger;

    /**
     * Required fields for plugin metadata with their expected types
     * Types: 'string', 'integer', 'array', 'boolean'
     */
    private const REQUIRED_FIELDS = [
        'name' => 'string',
        'version' => 'string',
        'description' => 'string',
        'author' => 'string'
    ];

    /**
     * Optional fields with their expected types
     */
    private const OPTIONAL_FIELDS = [
        'tags' => 'array',
        'dependencies' => 'array',
        'license' => 'string',
        'homepage' => 'string',
        'repository' => 'string'
    ];

    /**
     * Valid version constraint operators
     */
    private const VERSION_OPERATORS = [
        '^',   // Compatible with version (semver caret)
        '~',   // Approximately equivalent (semver tilde)
        '>=',  // Greater than or equal
        '<=',  // Less than or equal
        '>',   // Greater than
        '<',   // Less than
        '=',   // Exact version
        ''     // Exact version (no operator)
    ];

    public function __construct() {
        $this->logger = Flight::get('log');
    }

    /**
     * Extract metadata from plugin.json content
     *
     * @param string $pluginJsonContent The raw JSON content from plugin.json
     * @return array Extracted metadata with 'success', 'data', and 'error' keys
     */
    public function extractMetadata(string $pluginJsonContent): array {
        // Attempt to decode JSON
        $decoded = json_decode($pluginJsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMessage = json_last_error_msg();
            $this->logger->warning('Failed to parse plugin.json', [
                'error' => $errorMessage,
                'content_preview' => substr($pluginJsonContent, 0, 200)
            ]);

            return [
                'success' => false,
                'data' => [],
                'error' => [
                    'field' => 'json',
                    'error' => 'invalid_json',
                    'message' => "Invalid JSON: {$errorMessage}"
                ]
            ];
        }

        if (!is_array($decoded)) {
            return [
                'success' => false,
                'data' => [],
                'error' => [
                    'field' => 'json',
                    'error' => 'invalid_structure',
                    'message' => 'Plugin metadata must be a JSON object'
                ]
            ];
        }

        // Extract all known fields
        $metadata = [];

        // Extract required fields
        foreach (self::REQUIRED_FIELDS as $field => $type) {
            if (array_key_exists($field, $decoded)) {
                $metadata[$field] = $decoded[$field];
            }
        }

        // Extract optional fields
        foreach (self::OPTIONAL_FIELDS as $field => $type) {
            if (array_key_exists($field, $decoded)) {
                $metadata[$field] = $decoded[$field];
            }
        }

        // Parse dependencies if present
        if (isset($metadata['dependencies']) && is_array($metadata['dependencies'])) {
            $metadata['parsed_dependencies'] = $this->parseDependencies($metadata['dependencies']);
        }

        $this->logger->info('Plugin metadata extracted', [
            'name' => $metadata['name'] ?? 'unknown',
            'version' => $metadata['version'] ?? 'unknown',
            'fields_extracted' => count($metadata)
        ]);

        return [
            'success' => true,
            'data' => $metadata,
            'error' => null
        ];
    }

    /**
     * Validate plugin metadata
     *
     * @param array $metadata The metadata to validate
     * @return array Validation result with 'valid', 'errors', and 'warnings' keys
     */
    public function validateMetadata(array $metadata): array {
        $errors = [];
        $warnings = [];

        // Check for missing required fields
        foreach (self::REQUIRED_FIELDS as $field => $expectedType) {
            if (!array_key_exists($field, $metadata)) {
                $errors[] = [
                    'field' => $field,
                    'error' => 'missing_field',
                    'message' => "Required field '{$field}' is missing"
                ];
            } elseif ($metadata[$field] === null || $metadata[$field] === '') {
                $errors[] = [
                    'field' => $field,
                    'error' => 'empty_field',
                    'message' => "Required field '{$field}' cannot be empty"
                ];
            } else {
                // Check type
                $typeError = $this->validateType($field, $metadata[$field], $expectedType);
                if ($typeError) {
                    $errors[] = $typeError;
                }
            }
        }

        // Validate optional fields if present
        foreach (self::OPTIONAL_FIELDS as $field => $expectedType) {
            if (array_key_exists($field, $metadata) && $metadata[$field] !== null) {
                $typeError = $this->validateType($field, $metadata[$field], $expectedType);
                if ($typeError) {
                    $errors[] = $typeError;
                }
            }
        }

        // Validate version format (semver-like)
        if (isset($metadata['version']) && is_string($metadata['version'])) {
            if (!$this->isValidVersion($metadata['version'])) {
                $warnings[] = [
                    'field' => 'version',
                    'warning' => 'invalid_format',
                    'message' => "Version '{$metadata['version']}' may not follow semantic versioning (e.g., '1.0.0')"
                ];
            }
        }

        // Validate name format (alphanumeric, dashes, underscores)
        if (isset($metadata['name']) && is_string($metadata['name'])) {
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $metadata['name'])) {
                $warnings[] = [
                    'field' => 'name',
                    'warning' => 'naming_convention',
                    'message' => "Plugin name should contain only alphanumeric characters, dashes, and underscores"
                ];
            }
            if (strlen($metadata['name']) > 100) {
                $errors[] = [
                    'field' => 'name',
                    'error' => 'name_too_long',
                    'message' => 'Plugin name exceeds maximum length of 100 characters'
                ];
            }
        }

        // Validate description length
        if (isset($metadata['description']) && is_string($metadata['description'])) {
            if (strlen($metadata['description']) > 5000) {
                $errors[] = [
                    'field' => 'description',
                    'error' => 'description_too_long',
                    'message' => 'Description exceeds maximum length of 5000 characters'
                ];
            }
        }

        // Validate tags array contents
        if (isset($metadata['tags']) && is_array($metadata['tags'])) {
            foreach ($metadata['tags'] as $index => $tag) {
                if (!is_string($tag)) {
                    $errors[] = [
                        'field' => 'tags',
                        'error' => 'invalid_tag_type',
                        'message' => "Tag at index {$index} must be a string"
                    ];
                } elseif (strlen($tag) > 50) {
                    $warnings[] = [
                        'field' => 'tags',
                        'warning' => 'tag_too_long',
                        'message' => "Tag '{$tag}' exceeds recommended length of 50 characters"
                    ];
                }
            }
        }

        // Validate URL fields
        $urlFields = ['homepage', 'repository'];
        foreach ($urlFields as $urlField) {
            if (isset($metadata[$urlField]) && is_string($metadata[$urlField]) && !empty($metadata[$urlField])) {
                if (!filter_var($metadata[$urlField], FILTER_VALIDATE_URL)) {
                    $warnings[] = [
                        'field' => $urlField,
                        'warning' => 'invalid_url',
                        'message' => "Field '{$urlField}' does not appear to be a valid URL"
                    ];
                }
            }
        }

        // Validate dependencies if present
        if (isset($metadata['dependencies']) && is_array($metadata['dependencies'])) {
            $depErrors = $this->validateDependencies($metadata['dependencies']);
            $errors = array_merge($errors, $depErrors);
        }

        // Log validation attempt
        if (!empty($errors)) {
            $this->logger->warning('Plugin metadata validation failed', [
                'error_count' => count($errors),
                'errors' => $errors,
                'plugin_name' => $metadata['name'] ?? 'unknown'
            ]);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Parse dependencies with version constraints
     *
     * @param array $dependencies Raw dependencies array (can be ['name' => 'version'] or ['name', ...])
     * @return array Parsed dependencies with constraint details
     */
    public function parseDependencies(array $dependencies): array {
        $parsed = [];

        foreach ($dependencies as $key => $value) {
            // Handle associative array: {'package-name': '^1.0.0'}
            if (is_string($key)) {
                $packageName = $key;
                $versionConstraint = is_string($value) ? $value : '*';
            }
            // Handle indexed array with just package names: ['package-name']
            elseif (is_string($value)) {
                // Check if value contains version constraint (e.g., 'package@^1.0.0')
                if (strpos($value, '@') !== false) {
                    list($packageName, $versionConstraint) = explode('@', $value, 2);
                } else {
                    $packageName = $value;
                    $versionConstraint = '*';
                }
            } else {
                // Skip invalid entries
                continue;
            }

            $parsed[] = [
                'package' => trim($packageName),
                'constraint' => trim($versionConstraint),
                'parsed' => $this->parseVersionConstraint($versionConstraint)
            ];
        }

        return $parsed;
    }

    /**
     * Parse a version constraint string into its components
     *
     * @param string $constraint Version constraint (e.g., '^1.0.0', '>=2.0', '1.x')
     * @return array Parsed constraint with 'operator', 'version', 'major', 'minor', 'patch'
     */
    private function parseVersionConstraint(string $constraint): array {
        $constraint = trim($constraint);

        // Handle wildcard
        if ($constraint === '*' || $constraint === '') {
            return [
                'operator' => '*',
                'version' => '*',
                'major' => null,
                'minor' => null,
                'patch' => null,
                'is_wildcard' => true
            ];
        }

        // Handle x-range (1.x, 1.2.x)
        if (preg_match('/^(\d+)(\.(\d+))?(\.x)?$/', $constraint, $matches)) {
            if (isset($matches[4]) || (isset($matches[2]) && !isset($matches[3]))) {
                // This is an x-range
                return [
                    'operator' => '~',
                    'version' => $constraint,
                    'major' => isset($matches[1]) ? (int)$matches[1] : null,
                    'minor' => isset($matches[3]) ? (int)$matches[3] : null,
                    'patch' => null,
                    'is_wildcard' => true
                ];
            }
        }

        // Extract operator
        $operator = '';
        $version = $constraint;

        foreach (['>=', '<=', '^', '~', '>', '<', '='] as $op) {
            if (strpos($constraint, $op) === 0) {
                $operator = $op;
                $version = substr($constraint, strlen($op));
                break;
            }
        }

        // Parse version parts
        $versionParts = explode('.', trim($version));
        $major = isset($versionParts[0]) && is_numeric($versionParts[0]) ? (int)$versionParts[0] : null;
        $minor = isset($versionParts[1]) && is_numeric($versionParts[1]) ? (int)$versionParts[1] : null;
        $patch = isset($versionParts[2]) && is_numeric($versionParts[2]) ? (int)$versionParts[2] : null;

        return [
            'operator' => $operator ?: '=',
            'version' => trim($version),
            'major' => $major,
            'minor' => $minor,
            'patch' => $patch,
            'is_wildcard' => false
        ];
    }

    /**
     * Store plugin metadata in the database
     *
     * @param int $repoConnectionId The repo connection ID
     * @param array $metadata The extracted metadata
     * @param array $validationResult The validation result
     * @return array Result with 'success', 'id', and 'error' keys
     */
    public function storePluginMetadata(int $repoConnectionId, array $metadata, array $validationResult): array {
        try {
            // Check if metadata already exists for this repo connection
            $existing = Bean::findOne('pluginmetadata', 'repo_connection_id = ?', [$repoConnectionId]);

            if ($existing) {
                $pluginMetadata = $existing;
            } else {
                $pluginMetadata = Bean::dispense('pluginmetadata');
                $pluginMetadata->repo_connection_id = $repoConnectionId;
                $pluginMetadata->created_at = date('Y-m-d H:i:s');
            }

            // Set metadata fields
            $pluginMetadata->plugin_name = $metadata['name'] ?? null;
            $pluginMetadata->plugin_version = $metadata['version'] ?? null;
            $pluginMetadata->description = $metadata['description'] ?? null;
            $pluginMetadata->author = $metadata['author'] ?? null;

            // Store arrays as JSON
            if (isset($metadata['dependencies'])) {
                $pluginMetadata->dependencies = json_encode($metadata['parsed_dependencies'] ?? $metadata['dependencies']);
            }
            if (isset($metadata['tags'])) {
                $pluginMetadata->tags = json_encode($metadata['tags']);
            }

            // Optional string fields
            $pluginMetadata->license = $metadata['license'] ?? null;
            $pluginMetadata->homepage = $metadata['homepage'] ?? null;
            $pluginMetadata->repository_url = $metadata['repository'] ?? null;

            // Validation status
            $pluginMetadata->is_valid = $validationResult['valid'] ? 1 : 0;
            if (!empty($validationResult['errors'])) {
                $pluginMetadata->validation_errors = json_encode($validationResult['errors']);
            } else {
                $pluginMetadata->validation_errors = null;
            }

            // Timestamps
            $pluginMetadata->extracted_at = date('Y-m-d H:i:s');
            $pluginMetadata->validated_at = date('Y-m-d H:i:s');
            $pluginMetadata->updated_at = date('Y-m-d H:i:s');

            // Store the metadata
            $metadataId = Bean::store($pluginMetadata);

            $this->logger->info('Plugin metadata stored', [
                'metadata_id' => $metadataId,
                'plugin_name' => $metadata['name'] ?? 'unknown',
                'is_valid' => $validationResult['valid'],
                'repo_connection_id' => $repoConnectionId
            ]);

            return [
                'success' => true,
                'id' => $metadataId,
                'is_update' => (bool)$existing,
                'error' => null
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to store plugin metadata', [
                'error' => $e->getMessage(),
                'plugin_name' => $metadata['name'] ?? 'unknown',
                'repo_connection_id' => $repoConnectionId
            ]);

            return [
                'success' => false,
                'id' => null,
                'error' => 'Failed to store metadata: ' . $e->getMessage()
            ];
        }
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
     * Validate version string format
     *
     * @param string $version The version string to validate
     * @return bool True if valid semver-like format
     */
    private function isValidVersion(string $version): bool {
        // Basic semver pattern: major.minor.patch with optional pre-release
        $pattern = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)(\.(0|[1-9]\d*))?(-[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?(\+[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?$/';
        return (bool)preg_match($pattern, $version);
    }

    /**
     * Validate dependencies structure
     *
     * @param array $dependencies Dependencies array to validate
     * @return array Array of errors
     */
    private function validateDependencies(array $dependencies): array {
        $errors = [];

        foreach ($dependencies as $key => $value) {
            // For associative array format
            if (is_string($key)) {
                if (!is_string($value) && $value !== null) {
                    $errors[] = [
                        'field' => 'dependencies',
                        'error' => 'invalid_dependency_version',
                        'message' => "Dependency '{$key}' has invalid version constraint type"
                    ];
                }
            }
            // For indexed array format
            elseif (!is_string($value)) {
                $errors[] = [
                    'field' => 'dependencies',
                    'error' => 'invalid_dependency_format',
                    'message' => "Dependency at index {$key} must be a string"
                ];
            }
        }

        return $errors;
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
     * Get optional fields schema
     *
     * @return array Optional fields with their types
     */
    public static function getOptionalFields(): array {
        return self::OPTIONAL_FIELDS;
    }

    /**
     * Extract and validate metadata in one operation
     *
     * @param string $pluginJsonContent The raw JSON content
     * @param int|null $repoConnectionId Optional repo connection ID for storage
     * @return array Complete result with extraction, validation, and storage results
     */
    public function processPluginMetadata(string $pluginJsonContent, ?int $repoConnectionId = null): array {
        // Extract metadata
        $extractResult = $this->extractMetadata($pluginJsonContent);

        if (!$extractResult['success']) {
            return [
                'success' => false,
                'extraction' => $extractResult,
                'validation' => null,
                'storage' => null
            ];
        }

        // Validate metadata
        $validationResult = $this->validateMetadata($extractResult['data']);

        // Store if repo connection ID provided
        $storageResult = null;
        if ($repoConnectionId !== null) {
            $storageResult = $this->storePluginMetadata(
                $repoConnectionId,
                $extractResult['data'],
                $validationResult
            );
        }

        return [
            'success' => true,
            'extraction' => $extractResult,
            'validation' => $validationResult,
            'storage' => $storageResult
        ];
    }
}
