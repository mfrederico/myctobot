<?php
/**
 * JsonSchema - Helper for encoding JSON Schema-compliant JSON
 *
 * Ensures fields that must be objects (like `properties`) are never
 * serialized as empty arrays `[]` instead of empty objects `{}`.
 *
 * Usage:
 *   use app\JsonSchema;
 *
 *   // Instead of json_encode($schema)
 *   $json = JsonSchema::encode($schema);
 *
 *   // Or fix an array before encoding
 *   $fixed = JsonSchema::fix($schema);
 *   $json = json_encode($fixed);
 */

namespace app;

class JsonSchema
{
    /**
     * Fields in JSON Schema that MUST be objects, never arrays
     */
    private const OBJECT_FIELDS = [
        'properties',
        'patternProperties',
        'additionalProperties',
        'definitions',
        '$defs',
        'dependencies',
        'dependentSchemas',
    ];

    /**
     * Encode data as JSON, ensuring JSON Schema compliance
     *
     * @param mixed $data The data to encode
     * @param int $flags json_encode flags (default: 0)
     * @param int $depth Maximum depth (default: 512)
     * @return string|false JSON string or false on failure
     */
    public static function encode(mixed $data, int $flags = 0, int $depth = 512): string|false
    {
        if (is_array($data) || is_object($data)) {
            $data = self::fix($data);
        }
        return json_encode($data, $flags, $depth);
    }

    /**
     * Fix an array/object to ensure JSON Schema compliance
     *
     * Recursively converts empty arrays to objects for known schema fields.
     *
     * @param mixed $data The data to fix
     * @return mixed Fixed data
     */
    public static function fix(mixed $data): mixed
    {
        if (!is_array($data) && !is_object($data)) {
            return $data;
        }

        $isObject = is_object($data);
        $arr = (array) $data;

        foreach ($arr as $key => $value) {
            // Check if this key must be an object
            if (in_array($key, self::OBJECT_FIELDS, true)) {
                if (is_array($value) && empty($value)) {
                    // Empty array -> empty object
                    $arr[$key] = new \stdClass();
                } elseif (is_array($value)) {
                    // Non-empty: recursively fix, then ensure it's an object
                    $arr[$key] = (object) self::fix($value);
                } else {
                    $arr[$key] = self::fix($value);
                }
            } elseif (is_array($value) || is_object($value)) {
                // Recurse into nested structures
                $arr[$key] = self::fix($value);
            }
        }

        return $isObject ? (object) $arr : $arr;
    }

    /**
     * Create a valid empty JSON Schema object
     *
     * @return array Schema with properly typed empty properties
     */
    public static function emptySchema(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
            'required' => []
        ];
    }

    /**
     * Validate that a schema has correct types for known fields
     *
     * @param array|object $schema The schema to validate
     * @return array ['valid' => bool, 'errors' => string[]]
     */
    public static function validate(array|object $schema): array
    {
        $errors = [];
        self::validateRecursive($schema, '', $errors);

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Recursive validation helper
     */
    private static function validateRecursive(mixed $data, string $path, array &$errors): void
    {
        if (!is_array($data) && !is_object($data)) {
            return;
        }

        $arr = (array) $data;

        foreach ($arr as $key => $value) {
            $currentPath = $path ? "{$path}.{$key}" : $key;

            if (in_array($key, self::OBJECT_FIELDS, true)) {
                if (is_array($value) && array_is_list($value)) {
                    $errors[] = "'{$currentPath}' must be an object, not an array";
                }
            }

            if (is_array($value) || is_object($value)) {
                self::validateRecursive($value, $currentPath, $errors);
            }
        }
    }
}
