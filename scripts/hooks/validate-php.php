#!/usr/bin/env php
<?php
/**
 * MyCTOBot PHP Code Validator Hook
 *
 * Validates PHP code against MyCTOBot/RedBeanPHP/FlightPHP coding standards:
 * 1. Bean type names must be all lowercase (no underscores) for R::dispense
 * 2. R::exec should almost NEVER be used - only in extreme situations
 * 3. Prefer RedBeanPHP associations (ownBeanList/sharedBeanList) over manual FK management
 * 4. Use with()/withCondition() for ordering and filtering associations
 * 5. Security scanning (OWASP Top 10 patterns)
 *
 * Usage: This script reads JSON from stdin and outputs JSON to stdout
 */

/**
 * Find R::dispense with invalid bean type names - these WILL FAIL at runtime!
 *
 * CRITICAL: R::dispense() bean type names must be:
 * - All lowercase (a-z)
 * - Only alphanumeric (no underscores, no uppercase)
 *
 * @param string $content PHP code to check
 * @return array List of blocking issues
 */
function findUnderscoreTableNames(string $content): array
{
    $issues = [];

    // Match R::dispense with any table name
    if (preg_match_all("/R::dispense\s*\(\s*['\"]([a-zA-Z0-9_]+)['\"]/", $content, $matches)) {
        foreach ($matches[1] as $tableName) {
            // Check for underscores
            if (strpos($tableName, '_') !== false) {
                $lowercase = strtolower(str_replace('_', '', $tableName));
                $issues[] = "R::dispense('{$tableName}') will FAIL! RedBeanPHP doesn't allow underscores in dispense(). "
                    . "Use R::dispense('{$lowercase}') instead.";
            }
            // Check for uppercase letters
            elseif ($tableName !== strtolower($tableName)) {
                $lowercase = strtolower($tableName);
                $issues[] = "R::dispense('{$tableName}') will FAIL! RedBeanPHP requires all lowercase bean types in dispense(). "
                    . "Use R::dispense('{$lowercase}') instead.";
            }
        }
    }

    return $issues;
}

/**
 * Find problematic use of R::exec and flag it for review.
 *
 * @param string $content PHP code to check
 * @return array List of warning issues
 */
function findExecUsage(string $content): array
{
    $issues = [];

    // Match R::exec with any SQL statement
    if (preg_match_all("/R::exec\s*\(\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
        foreach ($matches[1] as $sql) {
            $sqlUpper = strtoupper(trim($sql));

            // DDL operations are OK - these can't be done with beans
            if (preg_match('/^(CREATE|ALTER|DROP)\s/', $sqlUpper)) {
                continue;
            }

            if (strpos($sqlUpper, 'INSERT') === 0) {
                $issues[] = "R::exec() used for INSERT. This bypasses FUSE models! Use Bean::dispense() + Bean::store() instead.";
            } elseif (strpos($sqlUpper, 'UPDATE') === 0) {
                // Check if it's a simple update that should use beans
                if (strpos($sqlUpper, 'WHERE') !== false && (strpos($sql, '= ?') !== false || strpos($sql, '=?') !== false)) {
                    if (strpos($sql, '+ 1') === false && strpos($sql, '- 1') === false && strpos($sqlUpper, 'NOW()') === false) {
                        $issues[] = "R::exec() used for UPDATE. This bypasses FUSE models! Use Bean::load() + Bean::store() instead.";
                    } else {
                        $issues[] = "R::exec() for UPDATE detected. Verify this is truly necessary and cannot be done with beans.";
                    }
                } else {
                    $issues[] = "R::exec() for UPDATE detected. Verify this is truly necessary and cannot be done with beans.";
                }
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
 *
 * @param string $content PHP code to check
 * @return array List of warning issues
 */
function findManualFkAssignments(string $content): array
{
    $issues = [];
    $reported = false;

    // Known FK columns that should use associations
    $knownFks = [
        'board_id', 'boardId', 'jiraboards_id',
        'job_id', 'jobId', 'aidevjobs_id',
        'repo_id', 'repoId', 'repoconnections_id',
        'member_id', 'memberId',
        'parent_id', 'parentId',
        'team_id', 'teamId',
        'task_id', 'taskId',
    ];

    // Pattern: $bean->something_id = or $bean->somethingId =
    $fkPatterns = [
        '/\$\w+->(\\w+_id)\s*=/',
        '/\$\w+->(\\w+Id)\s*=/',
    ];

    foreach ($fkPatterns as $pattern) {
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $fkColumn) {
                $fkLower = strtolower($fkColumn);
                $isKnownFk = false;
                foreach ($knownFks as $known) {
                    if (strtolower($known) === $fkLower) {
                        $isKnownFk = true;
                        break;
                    }
                }

                if ($isKnownFk || preg_match('/_id$/i', $fkColumn) || preg_match('/Id$/', $fkColumn)) {
                    if (!$reported) {
                        $issues[] = "Manual FK assignment detected: \${$fkColumn}. "
                            . "Consider using RedBeanPHP associations instead: "
                            . "\$parent->ownChildList[] = \$child (auto-sets FK, lazy loading, cascade delete with xown). "
                            . "Use with(' ORDER BY col DESC ') for ordering, withCondition(' col = ? ', [\$val]) for filtering. "
                            . "See CLAUDE.md for examples.";
                        $reported = true;
                    }
                    break;
                }
            }
        }
        if ($reported) break;
    }

    // Detect find queries with FK WHERE clauses
    if (!$reported && preg_match("/(?:R|Bean)::(?:find|findOne|findAll)\s*\(\s*['\"](\w+)['\"],\s*['\"](\w+_id)\s*=/", $content, $match)) {
        $childTable = $match[1];
        $fkColumn = $match[2];

        $issues[] = "Manual FK query detected: Bean::find('{$childTable}', '{$fkColumn} = ?'). "
            . "Consider using associations: \$parent->own" . ucfirst($childTable) . "List (lazy loads, auto-cached). "
            . "For ordering: \$parent->with(' ORDER BY col DESC ')->own" . ucfirst($childTable) . "List. "
            . "See CLAUDE.md for examples.";
    }

    return $issues;
}

// =========================================
// Security Scanning (OWASP Top 10)
// =========================================

/**
 * Detect potential SQL injection vulnerabilities.
 *
 * @param string $content PHP code to check
 * @return array Critical security issues
 */
function findSqlInjectionRisks(string $content): array
{
    $issues = [];

    $patterns = [
        ['/R::exec\s*\(\s*["\'][^"\']*\$[a-zA-Z_]/', 'Direct variable in R::exec() - Use parameterized queries: R::exec($sql, [$param])'],
        ['/R::getAll\s*\(\s*["\'][^"\']*\$[a-zA-Z_]/', 'Direct variable in R::getAll() - Use parameterized queries'],
        ['/->query\s*\(\s*["\'][^"\']*\$/', 'Direct variable in query() - Use parameterized queries'],
        ['/->exec\s*\(\s*["\'][^"\']*\$/', 'Direct variable in exec() - Use parameterized queries'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "SQL INJECTION RISK: {$message}";
        }
    }

    return $issues;
}

/**
 * Detect potential XSS vulnerabilities.
 *
 * @param string $content PHP code to check
 * @return array Warning security issues
 */
function findXssRisks(string $content): array
{
    $issues = [];

    $patterns = [
        ['/echo\s+\$_(?:GET|POST|REQUEST|COOKIE)\[/', 'Direct echo of user input - Use htmlspecialchars($_GET["param"], ENT_QUOTES, "UTF-8")'],
        ['/print\s+\$_(?:GET|POST|REQUEST|COOKIE)\[/', 'Direct print of user input - Use htmlspecialchars()'],
        ['/<\?=\s*\$_(?:GET|POST|REQUEST)\[/', 'Direct output of user input in template - Use htmlspecialchars()'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "XSS RISK: {$message}";
        }
    }

    return $issues;
}

/**
 * Detect potential command injection vulnerabilities.
 *
 * @param string $content PHP code to check
 * @return array Critical security issues
 */
function findCommandInjectionRisks(string $content): array
{
    $issues = [];

    $patterns = [
        ['/exec\s*\([^)]*\$_(?:GET|POST|REQUEST)/', 'User input in exec() - Use escapeshellarg() and escapeshellcmd()'],
        ['/shell_exec\s*\([^)]*\$_(?:GET|POST|REQUEST)/', 'User input in shell_exec() - Use escapeshellarg()'],
        ['/system\s*\([^)]*\$_(?:GET|POST|REQUEST)/', 'User input in system() - Use escapeshellarg()'],
        ['/passthru\s*\([^)]*\$_(?:GET|POST|REQUEST)/', 'User input in passthru() - Use escapeshellarg()'],
        ['/`[^`]*\$_(?:GET|POST|REQUEST)/', 'User input in backtick operator - Avoid or use escapeshellarg()'],
        ['/proc_open\s*\([^)]*\$_(?:GET|POST|REQUEST)/', 'User input in proc_open() - Use escapeshellarg()'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "COMMAND INJECTION RISK: {$message}";
        }
    }

    return $issues;
}

/**
 * Detect potential path traversal vulnerabilities.
 *
 * @param string $content PHP code to check
 * @return array Critical security issues
 */
function findPathTraversalRisks(string $content): array
{
    $issues = [];

    $patterns = [
        ['/file_get_contents\s*\([^)]*\$_(?:GET|POST|REQUEST)/', 'User input in file_get_contents() - Validate and sanitize file paths'],
        ['/include\s*\(?[^;)]*\$_(?:GET|POST|REQUEST)/', 'User input in include - This is extremely dangerous!'],
        ['/require\s*\(?[^;)]*\$_(?:GET|POST|REQUEST)/', 'User input in require - This is extremely dangerous!'],
        ['/fopen\s*\([^)]*\$_(?:GET|POST|REQUEST)/', 'User input in fopen() - Validate and sanitize file paths'],
        ['/readfile\s*\([^)]*\$_(?:GET|POST|REQUEST)/', 'User input in readfile() - Validate and sanitize file paths'],
        ['/file\s*\([^)]*\$_(?:GET|POST|REQUEST)/', 'User input in file() - Validate and sanitize file paths'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "PATH TRAVERSAL RISK: {$message}";
        }
    }

    return $issues;
}

/**
 * Detect use of insecure cryptographic functions for passwords.
 *
 * @param string $content PHP code to check
 * @return array Warning security issues
 */
function findInsecureCrypto(string $content): array
{
    $issues = [];

    if (preg_match('/md5\s*\([^)]*\$.*password/i', $content)) {
        $issues[] = "INSECURE CRYPTO: MD5 used for password - Use password_hash() instead";
    }

    if (preg_match('/sha1\s*\([^)]*\$.*password/i', $content)) {
        $issues[] = "INSECURE CRYPTO: SHA1 used for password - Use password_hash() instead";
    }

    return $issues;
}

/**
 * Detect hardcoded secrets/credentials.
 *
 * @param string $content PHP code to check
 * @return array Warning security issues
 */
function findHardcodedSecrets(string $content): array
{
    $issues = [];

    $patterns = [
        ['/["\'](?:password|passwd|pwd)["\']?\s*(?:=>|=)\s*["\'][^"\']{8,}[\'"]/i', 'Possible hardcoded password - Use environment variables'],
        ['/["\']api[_-]?key["\']?\s*(?:=>|=)\s*["\'][a-zA-Z0-9]{20,}[\'"]/i', 'Possible hardcoded API key - Use environment variables'],
        ['/["\']secret[_-]?key["\']?\s*(?:=>|=)\s*["\'][^"\']{16,}[\'"]/i', 'Possible hardcoded secret - Use environment variables'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "HARDCODED SECRET: {$message}";
        }
    }

    return $issues;
}

/**
 * Detect POST handlers without CSRF protection.
 *
 * @param string $content PHP code to check
 * @return array Warning security issues
 */
function findCsrfMissing(string $content): array
{
    $issues = [];

    // Check for POST handlers
    $hasPostHandler = preg_match('/\$request->method\s*===?\s*["\']POST["\']/', $content)
        || preg_match('/\$_SERVER\[["\']REQUEST_METHOD["\']\]\s*===?\s*["\']POST["\']/', $content);

    if ($hasPostHandler) {
        // Check for CSRF validation
        if (!preg_match('/validateCSRF|csrf|_token/i', $content)) {
            $issues[] = "CSRF RISK: POST handler without CSRF token validation - Use \$this->validateCSRF()";
        }
    }

    return $issues;
}

/**
 * Detect potential open redirect vulnerabilities.
 *
 * @param string $content PHP code to check
 * @return array Warning security issues
 */
function findOpenRedirectRisks(string $content): array
{
    $issues = [];

    $patterns = [
        ['/header\s*\(\s*["\']Location:\s*["\']?\s*\.\s*\$_(?:GET|POST|REQUEST)/', 'User input in redirect header - Validate redirect URLs'],
        ['/Flight::redirect\s*\(\s*\$_(?:GET|POST|REQUEST)/', 'User input in Flight::redirect() - Validate redirect URLs'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "OPEN REDIRECT RISK: {$message}";
        }
    }

    return $issues;
}

/**
 * Run all security validations.
 *
 * @param string $content PHP code to check
 * @return array [critical_issues, warning_issues]
 */
function findSecurityIssues(string $content): array
{
    $criticalIssues = [];
    $warningIssues = [];

    // Critical - these should block
    $criticalIssues = array_merge($criticalIssues, findSqlInjectionRisks($content));
    $criticalIssues = array_merge($criticalIssues, findCommandInjectionRisks($content));
    $criticalIssues = array_merge($criticalIssues, findPathTraversalRisks($content));

    // High/Medium - warn but allow
    $warningIssues = array_merge($warningIssues, findXssRisks($content));
    $warningIssues = array_merge($warningIssues, findCsrfMissing($content));
    $warningIssues = array_merge($warningIssues, findInsecureCrypto($content));
    $warningIssues = array_merge($warningIssues, findHardcodedSecrets($content));
    $warningIssues = array_merge($warningIssues, findOpenRedirectRisks($content));

    return [$criticalIssues, $warningIssues];
}

/**
 * Run all validations on PHP content.
 *
 * @param string $content PHP code to check
 * @return array [blocking_issues, warning_issues]
 */
function validatePhpCode(string $content): array
{
    $blockingIssues = [];
    $warningIssues = [];

    // Skip if not PHP
    if (strpos($content, '<?php') === false && strpos($content, '<?=') === false) {
        // Check if it contains PHP-like RedBean code even without <?php tag
        if (strpos($content, 'R::') === false && strpos($content, 'Bean::') === false) {
            return [[], []];
        }
    }

    // RedBeanPHP Convention Issues
    // Blocking - these will cause runtime errors
    $blockingIssues = array_merge($blockingIssues, findUnderscoreTableNames($content));

    // Warning - suggestions for better practices
    $warningIssues = array_merge($warningIssues, findExecUsage($content));
    $warningIssues = array_merge($warningIssues, findManualFkAssignments($content));

    // Security Scanning (OWASP Top 10)
    [$securityCritical, $securityWarnings] = findSecurityIssues($content);
    $blockingIssues = array_merge($blockingIssues, $securityCritical);
    $warningIssues = array_merge($warningIssues, $securityWarnings);

    return [$blockingIssues, $warningIssues];
}

/**
 * Main entry point
 */
function main(): void
{
    try {
        // Read input from stdin (JSON format from Claude Code)
        $inputJson = file_get_contents('php://stdin');
        $inputData = json_decode($inputJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // If input isn't valid JSON, just pass through
            exit(0);
        }

        $toolName = $inputData['tool_name'] ?? '';
        $toolInput = $inputData['tool_input'] ?? [];

        // Only validate Write and Edit operations
        if (!in_array($toolName, ['Write', 'Edit'])) {
            exit(0);
        }

        // Get file path and content
        $filePath = $toolInput['file_path'] ?? '';

        // Only validate PHP files
        if (!str_ends_with($filePath, '.php')) {
            exit(0);
        }

        // Get the content being written/edited
        if ($toolName === 'Write') {
            $content = $toolInput['content'] ?? '';
        } elseif ($toolName === 'Edit') {
            $content = $toolInput['new_string'] ?? '';
        } else {
            exit(0);
        }

        // Run validations
        [$blockingIssues, $warningIssues] = validatePhpCode($content);

        // Blocking issues - will prevent the operation
        if (!empty($blockingIssues)) {
            $feedback = "MYCTOBOT CODE STANDARDS VIOLATION (BLOCKING):\n\n";
            foreach ($blockingIssues as $i => $issue) {
                $feedback .= ($i + 1) . ". {$issue}\n";
            }
            $feedback .= "\nThese issues will cause runtime errors. Fix before proceeding.\n";
            $feedback .= "See CLAUDE.md for MyCTOBot coding standards.";

            echo json_encode([
                'decision' => 'block',
                'reason' => $feedback
            ]);
            exit(0);
        }

        // Warning issues - allow but inform
        if (!empty($warningIssues)) {
            $feedback = "MYCTOBOT BEST PRACTICES SUGGESTION:\n\n";
            foreach ($warningIssues as $i => $issue) {
                $feedback .= ($i + 1) . ". {$issue}\n";
            }
            $feedback .= "\nThese are suggestions for better code. Operation allowed.\n";
            $feedback .= "See CLAUDE.md for RedBeanPHP association patterns.";

            echo json_encode([
                'decision' => 'allow',
                'reason' => $feedback
            ]);
        }

        exit(0);

    } catch (Exception $e) {
        // Log error but don't block
        fwrite(STDERR, "Hook error: " . $e->getMessage() . "\n");
        exit(0);
    }
}

main();
