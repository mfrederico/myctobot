<?php
/**
 * Variable Substitution Utility
 *
 * Provides reusable variable substitution for templates.
 * Supports: {context.key}, {step_name.output.key}, {simple_key}
 *
 * Extracted from PipelineExecutor for use across the application.
 */

namespace app;

class VariableSubstitution {

    /**
     * Substitute variables in a string template
     *
     * Supports:
     * - {context.key} - Access context variables (with nested dot notation)
     * - {step_name.output.key} - Access step outputs (for pipeline context)
     * - {simple_key} - Direct lookup in context root
     *
     * @param string $template Template string with {var} placeholders
     * @param array $context Available context variables
     * @param array $stepOutputs Optional step outputs for pipeline context
     * @return string Substituted string
     */
    public static function substitute(string $template, array $context, array $stepOutputs = []): string {
        // Replace context variables {context.xxx}
        $template = preg_replace_callback('/\{context\.([^}]+)\}/', function($matches) use ($context) {
            $key = $matches[1];
            $value = self::getNestedValue($context, $key);
            return $value ?? '';
        }, $template);

        // Replace step output variables {step_name.xxx} (if stepOutputs provided)
        if (!empty($stepOutputs)) {
            $template = preg_replace_callback('/\{([a-z_][a-z0-9_]*)\.([^}]+)\}/', function($matches) use ($stepOutputs) {
                $stepName = $matches[1];
                $path = $matches[2];

                if (!isset($stepOutputs[$stepName])) {
                    return '';
                }

                $value = self::getNestedValue($stepOutputs[$stepName], $path);
                return $value ?? '';
            }, $template);
        }

        // Replace simple variables {xxx} - look up directly in context
        $template = preg_replace_callback('/\{([a-z_][a-z0-9_]*)\}/', function($matches) use ($context) {
            $key = $matches[1];
            if (isset($context[$key])) {
                $value = $context[$key];
                return is_array($value) ? json_encode($value) : $value;
            }
            return '';
        }, $template);

        return $template;
    }

    /**
     * Recursively substitute variables in arrays/objects
     *
     * @param mixed $data Data structure to process
     * @param array $context Available context variables
     * @param array $stepOutputs Optional step outputs for pipeline context
     * @return mixed Processed data structure
     */
    public static function substituteRecursive(mixed $data, array $context, array $stepOutputs = []): mixed {
        if (is_string($data)) {
            return self::substitute($data, $context, $stepOutputs);
        }

        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = self::substituteRecursive($value, $context, $stepOutputs);
            }
            return $result;
        }

        if (is_object($data)) {
            $result = clone $data;
            foreach (get_object_vars($data) as $key => $value) {
                $result->$key = self::substituteRecursive($value, $context, $stepOutputs);
            }
            return $result;
        }

        // Scalar values (int, float, bool, null) - return as-is
        return $data;
    }

    /**
     * Get nested value from array using dot notation
     *
     * @param array $data Source array
     * @param string $path Dot-notated path (e.g., "user.address.city")
     * @return mixed|null Value at path or null if not found
     */
    public static function getNestedValue(array $data, string $path): mixed {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (!is_array($value) || !isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }

        return is_array($value) ? json_encode($value) : $value;
    }

    /**
     * Check if a template contains any variable placeholders
     *
     * @param string $template Template string to check
     * @return bool True if template contains {xxx} placeholders
     */
    public static function hasVariables(string $template): bool {
        return preg_match('/\{[a-z_][a-z0-9_.]*\}/', $template) === 1;
    }

    /**
     * Extract all variable names from a template
     *
     * @param string $template Template string to parse
     * @return array List of variable paths found (e.g., ['context.user', 'step1.output.data'])
     */
    public static function extractVariables(string $template): array {
        $variables = [];
        if (preg_match_all('/\{([a-z_][a-z0-9_.]*)\}/', $template, $matches)) {
            $variables = array_unique($matches[1]);
        }
        return $variables;
    }
}
