#!/usr/bin/env php
<?php
/**
 * Tiknix PHP Code Validator Hook
 *
 * Validates PHP code against Tiknix/RedBeanPHP/FlightPHP coding standards:
 * 1. Bean type names must be all lowercase (no underscores) for R::dispense
 * 2. R::exec should almost NEVER be used - only in extreme situations
 * 3. Prefer RedBeanPHP associations (ownBeanList/sharedBeanList) over manual FK management
 * 4. Controller class names must be all lowercase - NO CamelCase, NO hyphens
 * 5. Route files must not contain hyphens
 */

/**
 * Find R::dispense with invalid bean type names - these WILL FAIL at runtime!
 */
function findUnderscoreTableNames(string $content): array {
    $issues = [];

    // Match R::dispense with any table name
    if (preg_match_all("/R::dispense\s*\(\s*['\"]([a-zA-Z0-9_]+)['\"]/", $content, $matches)) {
        foreach ($matches[1] as $tableName) {
            // Check for underscores
            if (strpos($tableName, '_') !== false) {
                $lowercase = strtolower(str_replace('_', '', $tableName));
                $issues[] = "R::dispense('{$tableName}') will FAIL! RedBeanPHP doesn't allow underscores in dispense(). Use R::dispense('{$lowercase}') instead.";
            }
            // Check for uppercase letters
            elseif ($tableName !== strtolower($tableName)) {
                $lowercase = strtolower($tableName);
                $issues[] = "R::dispense('{$tableName}') will FAIL! RedBeanPHP requires all lowercase bean types in dispense(). Use R::dispense('{$lowercase}') instead.";
            }
        }
    }

    return $issues;
}

/**
 * Find problematic use of R::exec and flag it for review.
 */
function findExecUsage(string $content): array {
    $issues = [];

    if (preg_match_all("/R::exec\s*\(\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
        foreach ($matches[1] as $sql) {
            $sqlUpper = strtoupper(trim($sql));

            // DDL operations are OK
            if (preg_match('/^(CREATE|ALTER|DROP)\s/', $sqlUpper)) {
                continue;
            }

            if (strpos($sqlUpper, 'INSERT') === 0) {
                $issues[] = "R::exec() used for INSERT. This bypasses FUSE models! Use Bean::dispense() + Bean::store() instead.";
            } elseif (strpos($sqlUpper, 'UPDATE') === 0) {
                $issues[] = "R::exec() for UPDATE detected. This bypasses FUSE models! Use Bean::load() + Bean::store() instead.";
            } elseif (strpos($sqlUpper, 'DELETE') === 0) {
                $issues[] = "R::exec() used for DELETE. This bypasses FUSE models! Use Bean::trash() instead.";
            } else {
                $issues[] = "R::exec() detected. R::exec should ONLY be used in extreme situations. Can this use bean methods instead?";
            }
        }
    }

    return $issues;
}

/**
 * Detect manual foreign key assignments and suggest using associations instead.
 */
function findManualFkAssignments(string $content): array {
    $issues = [];

    // Common FK column patterns
    $knownFks = ['board_id', 'job_id', 'repo_id', 'member_id', 'parent_id'];

    // Match $bean->xxx_id =
    if (preg_match('/\$\w+->\w+_id\s*=/', $content)) {
        $issues[] = "Manual FK assignment detected (->xxx_id =). Consider using RedBeanPHP associations: \$parent->ownChildList[] = \$child (auto-sets FK, lazy loading). See CLAUDE.md for examples.";
    }

    return $issues;
}

/**
 * Check controller naming - BLOCKS CamelCase and hyphens
 * Returns: [blockingIssues, warningIssues]
 */
function checkControllerNaming(string $filePath): array {
    $blocking = [];
    $warnings = [];

    // Only check files in controls/ directory
    if (strpos($filePath, '/controls/') === false) {
        return [[], []];
    }

    // Skip BaseControls subdirectory
    if (strpos($filePath, 'BaseControls') !== false) {
        return [[], []];
    }

    $filename = basename($filePath);
    if (!str_ends_with($filename, '.php')) {
        return [[], []];
    }

    $controllerName = substr($filename, 0, -4); // Remove .php

    // Count capital letters (excluding first letter)
    $capitalCount = 0;
    for ($i = 1; $i < strlen($controllerName); $i++) {
        if (ctype_upper($controllerName[$i])) {
            $capitalCount++;
        }
    }

    // BLOCKING: CamelCase controllers
    if ($capitalCount > 0) {
        $lowercase = strtolower($controllerName);
        $blocking[] = "CamelCase controller '{$controllerName}' is FORBIDDEN. " .
            "FlightPHP auto-routing requires all lowercase controller names. " .
            "Rename to '{$lowercase}' (file: {$lowercase}.php, class: {$lowercase}).";
    }

    return [$blocking, $warnings];
}

/**
 * Check route file naming - BLOCKS hyphens
 */
function checkRouteFileNaming(string $filePath): array {
    $blocking = [];

    // Only check files in routes/ directory
    if (strpos($filePath, '/routes/') === false) {
        return [];
    }

    $filename = basename($filePath);

    // BLOCKING: Hyphens in route file names
    if (strpos($filename, '-') !== false) {
        $fixed = str_replace('-', '', $filename);
        $blocking[] = "Hyphenated route file '{$filename}' is FORBIDDEN. " .
            "Hyphens in URLs require explicit route files, which defeats auto-routing. " .
            "Rename controller and route file to remove hyphens: '{$fixed}'.";
    }

    return $blocking;
}

/**
 * Run all validations on PHP content.
 */
function validatePhpCode(string $content, string $filePath): array {
    $blockingIssues = [];
    $warningIssues = [];

    // File path based checks (always run)
    [$controllerBlocking, $controllerWarnings] = checkControllerNaming($filePath);
    $blockingIssues = array_merge($blockingIssues, $controllerBlocking);
    $warningIssues = array_merge($warningIssues, $controllerWarnings);

    $blockingIssues = array_merge($blockingIssues, checkRouteFileNaming($filePath));

    // Skip content checks if not PHP
    if (strpos($content, '<?php') === false && strpos($content, '<?=') === false) {
        if (strpos($content, 'R::') === false && strpos($content, 'Bean::') === false) {
            return [$blockingIssues, $warningIssues];
        }
    }

    // Blocking issues - these will cause runtime errors
    $blockingIssues = array_merge($blockingIssues, findUnderscoreTableNames($content));

    // Warning issues - suggestions for better practices
    $warningIssues = array_merge($warningIssues, findExecUsage($content));
    $warningIssues = array_merge($warningIssues, findManualFkAssignments($content));

    return [$blockingIssues, $warningIssues];
}

// Main execution
$input = file_get_contents('php://stdin');
$data = json_decode($input, true);

if (!$data) {
    exit(0);
}

$toolName = $data['tool_name'] ?? '';
$toolInput = $data['tool_input'] ?? [];

// Only validate Write and Edit operations
if (!in_array($toolName, ['Write', 'Edit'])) {
    exit(0);
}

$filePath = $toolInput['file_path'] ?? '';

// Only validate PHP files
if (!str_ends_with($filePath, '.php')) {
    exit(0);
}

// Get content being written/edited
$content = '';
if ($toolName === 'Write') {
    $content = $toolInput['content'] ?? '';
} elseif ($toolName === 'Edit') {
    $content = $toolInput['new_string'] ?? '';
}

// Run validations
[$blockingIssues, $warningIssues] = validatePhpCode($content, $filePath);

// Blocking issues - will prevent the operation
if (!empty($blockingIssues)) {
    $feedback = "TIKNIX CODE STANDARDS VIOLATION (BLOCKING):\n\n";
    foreach ($blockingIssues as $i => $issue) {
        $feedback .= ($i + 1) . ". {$issue}\n";
    }
    $feedback .= "\nThese issues will cause runtime errors. Fix before proceeding.\n";
    $feedback .= "See CLAUDE.md for Tiknix coding standards.";

    echo json_encode([
        'decision' => 'block',
        'reason' => $feedback
    ]);
    exit(0);
}

// Warning issues - allow but inform
if (!empty($warningIssues)) {
    $feedback = "TIKNIX BEST PRACTICES SUGGESTION:\n\n";
    foreach ($warningIssues as $i => $issue) {
        $feedback .= ($i + 1) . ". {$issue}\n";
    }
    $feedback .= "\nThese are suggestions for better code. Operation allowed.\n";
    $feedback .= "See CLAUDE.md for patterns.";

    echo json_encode([
        'decision' => 'allow',
        'reason' => $feedback
    ]);
}

exit(0);
