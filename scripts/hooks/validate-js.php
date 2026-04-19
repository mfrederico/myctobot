#!/usr/bin/env php
<?php
/**
 * JavaScript/TypeScript Code Validator Hook
 *
 * Validates JS/TS code against security best practices:
 * 1. XSS vulnerabilities (innerHTML, document.write, eval)
 * 2. SQL injection patterns in query builders
 * 3. Insecure dependencies and patterns
 * 4. React/Vue security issues
 *
 * Usage: This script reads JSON from stdin and outputs JSON to stdout
 */

/**
 * Detect XSS vulnerabilities in JavaScript code.
 *
 * @param string $content JavaScript code to check
 * @return array Critical security issues
 */
function findJsXssRisks(string $content): array
{
    $issues = [];

    $patterns = [
        ['/\.innerHTML\s*=\s*[^"\'`][^;]*\$/', 'Variable directly assigned to innerHTML - Use textContent or sanitize with DOMPurify'],
        ['/\.innerHTML\s*=\s*`[^`]*\$\{/', 'Template literal with variable in innerHTML - Use textContent or sanitize with DOMPurify'],
        ['/document\.write\s*\(/', 'document.write() is dangerous - Use DOM methods instead'],
        ['/\.outerHTML\s*=\s*[^"\'`]/', 'Variable assigned to outerHTML - Sanitize input first'],
        ['/\.insertAdjacentHTML\s*\([^)]*,[^"\'`][^)]*\$/', 'Variable in insertAdjacentHTML - Sanitize input first'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "XSS RISK: {$message}";
        }
    }

    return $issues;
}

/**
 * Detect dangerous eval usage.
 *
 * @param string $content JavaScript code to check
 * @return array Critical security issues
 */
function findEvalRisks(string $content): array
{
    $issues = [];

    $patterns = [
        ['/\beval\s*\(/', 'eval() is extremely dangerous - Avoid using eval entirely'],
        ['/new\s+Function\s*\([^)]*\+/', 'new Function() with concatenation is dangerous - Use safer alternatives'],
        ['/setTimeout\s*\(\s*["\'][^"\']*\$/', 'String in setTimeout is eval-like - Use function reference instead'],
        ['/setInterval\s*\(\s*["\'][^"\']*\$/', 'String in setInterval is eval-like - Use function reference instead'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "EVAL RISK: {$message}";
        }
    }

    return $issues;
}

/**
 * Detect prototype pollution risks.
 *
 * @param string $content JavaScript code to check
 * @return array Warning security issues
 */
function findPrototypePollution(string $content): array
{
    $issues = [];

    $patterns = [
        ['/Object\.assign\s*\([^,]+,\s*(?:req\.body|req\.query|req\.params)/', 'Object.assign with user input may cause prototype pollution'],
        ['/\{\s*\.\.\.(?:req\.body|req\.query|props)\s*\}/', 'Spread operator with user input may cause prototype pollution'],
        ['/\[(?:key|prop|name)\]\s*=/', 'Dynamic property assignment - Validate key is not __proto__ or constructor'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "PROTOTYPE POLLUTION: {$message}";
        }
    }

    return $issues;
}

/**
 * Detect React-specific security issues.
 *
 * @param string $content JavaScript code to check
 * @return array Security issues
 */
function findReactSecurityIssues(string $content): array
{
    $issues = [];

    if (preg_match('/dangerouslySetInnerHTML\s*=\s*\{\s*\{\s*__html:\s*[^}]*(?:\$|props\.|state\.)/', $content)) {
        $issues[] = "REACT XSS: dangerouslySetInnerHTML with dynamic content - Sanitize with DOMPurify first";
    }

    if (preg_match('/href\s*=\s*\{[^}]*(?:javascript:|data:)/', $content)) {
        $issues[] = "REACT XSS: Dangerous href protocol - Validate URLs before use";
    }

    return $issues;
}

/**
 * Detect SQL injection in query builders.
 *
 * @param string $content JavaScript code to check
 * @return array Critical security issues
 */
function findJsSqlInjection(string $content): array
{
    $issues = [];

    $patterns = [
        ['/\.query\s*\(\s*`[^`]*\$\{/', 'Template literal in SQL query - Use parameterized queries'],
        ['/\.query\s*\(\s*["\'][^"\']*\s*\+\s*/', 'String concatenation in SQL query - Use parameterized queries'],
        ['/\.raw\s*\(\s*`[^`]*\$\{/', 'Template literal in raw query - Use parameterized queries'],
        ['/execute\s*\(\s*`[^`]*\$\{/', 'Template literal in execute - Use parameterized queries'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "SQL INJECTION: {$message}";
        }
    }

    return $issues;
}

/**
 * Detect command injection in Node.js.
 *
 * @param string $content JavaScript code to check
 * @return array Critical security issues
 */
function findNodeCommandInjection(string $content): array
{
    $issues = [];

    $patterns = [
        ['/exec\s*\(\s*`[^`]*\$\{/', 'Template literal in exec() - Use execFile with array args instead'],
        ['/exec\s*\([^)]*\+\s*(?:req\.|input|user)/', 'User input in exec() - Use execFile with array args'],
        ['/spawn\s*\([^,]+,\s*\[[^\]]*`[^`]*\$\{/', 'Template literal in spawn args - Validate input'],
        ['/child_process.*exec\s*\(/', 'child_process.exec is dangerous - Prefer execFile or spawn with array'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "COMMAND INJECTION: {$message}";
        }
    }

    return $issues;
}

/**
 * Detect path traversal in Node.js.
 *
 * @param string $content JavaScript code to check
 * @return array Critical security issues
 */
function findNodePathTraversal(string $content): array
{
    $issues = [];

    $patterns = [
        ['/readFile(?:Sync)?\s*\([^)]*(?:req\.|input|params)/', 'User input in readFile - Validate and sanitize path'],
        ['/createReadStream\s*\([^)]*(?:req\.|input|params)/', 'User input in createReadStream - Validate path'],
        ['/path\.join\s*\([^)]*(?:req\.|input|params)[^)]*\)(?!\s*\.\s*replace)/', 'User input in path.join without sanitization - Check for ..'],
        ['/require\s*\([^)]*(?:req\.|input|params)/', 'Dynamic require with user input - Extremely dangerous!'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "PATH TRAVERSAL: {$message}";
        }
    }

    return $issues;
}

/**
 * Detect insecure cryptography.
 *
 * @param string $content JavaScript code to check
 * @return array Warning security issues
 */
function findJsInsecureCrypto(string $content): array
{
    $issues = [];

    if (preg_match('/createHash\s*\(\s*["\']md5["\']/', $content)) {
        $issues[] = "INSECURE CRYPTO: MD5 is weak - Use SHA-256 or bcrypt for passwords";
    }

    if (preg_match('/createHash\s*\(\s*["\']sha1["\']/', $content)) {
        $issues[] = "INSECURE CRYPTO: SHA1 is weak - Use SHA-256 or better";
    }

    if (preg_match('/Math\.random\s*\(\).*(?:token|secret|key|password|auth)/i', $content)) {
        $issues[] = "INSECURE RANDOM: Math.random() is not cryptographically secure - Use crypto.randomBytes()";
    }

    return $issues;
}

/**
 * Run all validations on JavaScript content.
 *
 * @param string $content JavaScript code to check
 * @return array [blocking_issues, warning_issues]
 */
function validateJsCode(string $content): array
{
    $blockingIssues = [];
    $warningIssues = [];

    // Critical - block these
    $blockingIssues = array_merge($blockingIssues, findJsSqlInjection($content));
    $blockingIssues = array_merge($blockingIssues, findNodeCommandInjection($content));
    $blockingIssues = array_merge($blockingIssues, findNodePathTraversal($content));
    $blockingIssues = array_merge($blockingIssues, findEvalRisks($content));

    // High risk - warn
    $warningIssues = array_merge($warningIssues, findJsXssRisks($content));
    $warningIssues = array_merge($warningIssues, findReactSecurityIssues($content));
    $warningIssues = array_merge($warningIssues, findPrototypePollution($content));
    $warningIssues = array_merge($warningIssues, findJsInsecureCrypto($content));

    return [$blockingIssues, $warningIssues];
}

/**
 * Main entry point
 */
function main(): void
{
    try {
        $inputJson = file_get_contents('php://stdin');
        $inputData = json_decode($inputJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            exit(0);
        }

        $toolName = $inputData['tool_name'] ?? '';
        $toolInput = $inputData['tool_input'] ?? [];

        if (!in_array($toolName, ['Write', 'Edit'])) {
            exit(0);
        }

        $filePath = $toolInput['file_path'] ?? '';

        // Only validate JS/TS files
        $jsExtensions = ['.js', '.jsx', '.ts', '.tsx', '.mjs', '.cjs'];
        $isJsFile = false;
        foreach ($jsExtensions as $ext) {
            if (str_ends_with($filePath, $ext)) {
                $isJsFile = true;
                break;
            }
        }

        if (!$isJsFile) {
            exit(0);
        }

        $content = $toolName === 'Write'
            ? ($toolInput['content'] ?? '')
            : ($toolInput['new_string'] ?? '');

        [$blockingIssues, $warningIssues] = validateJsCode($content);

        if (!empty($blockingIssues)) {
            $feedback = "JAVASCRIPT SECURITY VIOLATION (BLOCKING):\n\n";
            foreach ($blockingIssues as $i => $issue) {
                $feedback .= ($i + 1) . ". {$issue}\n";
            }
            $feedback .= "\nThese issues are critical security risks. Fix before proceeding.";

            echo json_encode([
                'decision' => 'block',
                'reason' => $feedback
            ]);
            exit(0);
        }

        if (!empty($warningIssues)) {
            $feedback = "JAVASCRIPT SECURITY SUGGESTION:\n\n";
            foreach ($warningIssues as $i => $issue) {
                $feedback .= ($i + 1) . ". {$issue}\n";
            }
            $feedback .= "\nThese are security suggestions. Operation allowed.";

            echo json_encode([
                'decision' => 'allow',
                'reason' => $feedback
            ]);
        }

        exit(0);

    } catch (Exception $e) {
        fwrite(STDERR, "Hook error: " . $e->getMessage() . "\n");
        exit(0);
    }
}

main();
