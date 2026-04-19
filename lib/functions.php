<?php
/**
 * Global Utility Functions
 *
 * Loaded automatically by Bootstrap::initFlight() if this file exists.
 */

/**
 * Null-safe htmlspecialchars wrapper.
 *
 * PHP 8.1+ deprecates passing null to htmlspecialchars(). This helper
 * converts null to empty string automatically, avoiding the deprecation
 * warning that appears across all views.
 *
 * @param mixed  $value   The value to escape (null-safe)
 * @param int    $flags   Bitmask of ENT_* flags (default: ENT_QUOTES | ENT_SUBSTITUTE)
 * @param string $encoding Character encoding (default: UTF-8)
 * @return string Escaped string, or '' if input was null/empty
 */
function h(mixed $value, int $flags = ENT_QUOTES | ENT_SUBSTITUTE, string $encoding = 'UTF-8'): string
{
    return htmlspecialchars((string) ($value ?? ''), $flags, $encoding);
}
