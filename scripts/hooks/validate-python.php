#!/usr/bin/env php
<?php
/**
 * Python Code Validator Hook
 *
 * Validates Python code against security best practices:
 * 1. SQL injection (string formatting in queries)
 * 2. Command injection (subprocess, os.system)
 * 3. Path traversal (open, file operations)
 * 4. Pickle deserialization attacks
 * 5. YAML unsafe loading
 *
 * Usage: This script reads JSON from stdin and outputs JSON to stdout
 */

/**
 * Detect SQL injection vulnerabilities in Python.
 *
 * @param string $content Python code to check
 * @return array Critical security issues
 */
function findPySqlInjection(string $content): array
{
    $issues = [];

    $patterns = [
        ['/execute\s*\(\s*f["\']/', 'f-string in SQL execute() - Use parameterized queries: cursor.execute(sql, (param,))'],
        ['/execute\s*\(\s*["\'][^"\']*%\s*/', 'String formatting in SQL execute() - Use parameterized queries'],
        ['/execute\s*\(\s*["\'][^"\']*\.format\s*\(/', '.format() in SQL execute() - Use parameterized queries'],
        ['/execute\s*\(\s*[^,)]+\s*\+\s*/', 'String concatenation in SQL execute() - Use parameterized queries'],
        ['/raw\s*\(\s*f["\']/', 'f-string in raw SQL - Use parameterized queries'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "SQL INJECTION: {$message}";
        }
    }

    return $issues;
}

/**
 * Detect command injection vulnerabilities.
 *
 * @param string $content Python code to check
 * @return array Critical security issues
 */
function findPyCommandInjection(string $content): array
{
    $issues = [];

    $patterns = [
        ['/os\.system\s*\(\s*f["\']/', 'f-string in os.system() - Use subprocess.run with list args'],
        ['/os\.system\s*\([^)]*\+/', 'String concatenation in os.system() - Use subprocess.run with list args'],
        ['/os\.popen\s*\(\s*f["\']/', 'f-string in os.popen() - Use subprocess.run with list args'],
        ['/subprocess\.\w+\s*\([^)]*shell\s*=\s*True/', 'shell=True is dangerous - Use list args without shell'],
        ['/subprocess\.call\s*\(\s*f["\']/', 'f-string in subprocess - Use list args: subprocess.run([cmd, arg])'],
        ['/eval\s*\(/', 'eval() is extremely dangerous - Avoid entirely'],
        ['/exec\s*\(\s*[^)]*(?:request|input|user)/', 'exec() with user input - Never execute user-provided code'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "COMMAND INJECTION: {$message}";
        }
    }

    return $issues;
}

/**
 * Detect path traversal vulnerabilities.
 *
 * @param string $content Python code to check
 * @return array Critical security issues
 */
function findPyPathTraversal(string $content): array
{
    $issues = [];

    $patterns = [
        ['/open\s*\(\s*(?:request|user_input|filename)/', 'User input in open() - Validate path and check for ..'],
        ['/open\s*\(\s*f["\'][^"\']*\{(?:request|user|filename)/', 'User input in open() via f-string - Validate path'],
        ['/Path\s*\([^)]*(?:request|user_input)/', 'User input in Path() - Use Path.resolve() and validate'],
        ['/shutil\.(?:copy|move)\s*\([^)]*(?:request|user)/', 'User input in shutil operation - Validate paths'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "PATH TRAVERSAL: {$message}";
        }
    }

    return $issues;
}

/**
 * Detect unsafe deserialization.
 *
 * @param string $content Python code to check
 * @return array Critical security issues
 */
function findPyUnsafeDeserialization(string $content): array
{
    $issues = [];

    if (preg_match('/pickle\.loads?\s*\(/', $content)) {
        $issues[] = "DESERIALIZATION: pickle.load() on untrusted data allows arbitrary code execution";
    }

    if (preg_match('/yaml\.load\s*\([^)]*(?!Loader\s*=\s*yaml\.SafeLoader)/', $content)) {
        if (!preg_match('/yaml\.safe_load/', $content)) {
            $issues[] = "DESERIALIZATION: yaml.load() without SafeLoader allows code execution - Use yaml.safe_load()";
        }
    }

    if (preg_match('/marshal\.loads?\s*\(/', $content)) {
        $issues[] = "DESERIALIZATION: marshal.load() on untrusted data is dangerous";
    }

    return $issues;
}

/**
 * Detect XSS in templating.
 *
 * @param string $content Python code to check
 * @return array Warning security issues
 */
function findPyXssRisks(string $content): array
{
    $issues = [];

    if (preg_match('/\|\s*safe\b/', $content)) {
        $issues[] = "XSS RISK: |safe filter bypasses escaping - Ensure content is sanitized";
    }

    if (preg_match('/Markup\s*\(/', $content)) {
        $issues[] = "XSS RISK: Markup() marks content as safe - Ensure content is sanitized";
    }

    if (preg_match('/render_template_string\s*\([^)]*(?:request|user)/', $content)) {
        $issues[] = "SSTI RISK: User input in render_template_string allows Server-Side Template Injection";
    }

    return $issues;
}

/**
 * Detect insecure cryptography.
 *
 * @param string $content Python code to check
 * @return array Warning security issues
 */
function findPyInsecureCrypto(string $content): array
{
    $issues = [];

    if (preg_match('/hashlib\.md5\s*\(/', $content)) {
        $issues[] = "INSECURE CRYPTO: MD5 is weak - Use hashlib.sha256() or bcrypt for passwords";
    }

    if (preg_match('/hashlib\.sha1\s*\(/', $content)) {
        $issues[] = "INSECURE CRYPTO: SHA1 is weak - Use hashlib.sha256() or better";
    }

    if (preg_match('/random\.\w+.*(?:token|secret|key|password)/i', $content)) {
        if (!preg_match('/secrets\./', $content)) {
            $issues[] = "INSECURE RANDOM: random module is not cryptographically secure - Use secrets module";
        }
    }

    return $issues;
}

/**
 * Detect hardcoded secrets.
 *
 * @param string $content Python code to check
 * @return array Warning security issues
 */
function findPyHardcodedSecrets(string $content): array
{
    $issues = [];

    $patterns = [
        ['/(?:password|passwd|pwd)\s*=\s*["\'][^"\']{8,}[\'"]/i', 'Possible hardcoded password - Use environment variables'],
        ['/(?:api_key|apikey)\s*=\s*["\'][a-zA-Z0-9]{20,}[\'"]/i', 'Possible hardcoded API key - Use environment variables'],
        ['/(?:secret_key|secretkey)\s*=\s*["\'][^"\']{16,}[\'"]/i', 'Possible hardcoded secret - Use environment variables'],
        ['/AWS_(?:ACCESS|SECRET)[^=]*=\s*["\'][A-Z0-9]{16,}[\'"]/i', 'AWS credentials in code - Use environment variables or IAM roles'],
    ];

    foreach ($patterns as [$pattern, $message]) {
        if (preg_match($pattern, $content)) {
            $issues[] = "HARDCODED SECRET: {$message}";
        }
    }

    return $issues;
}

/**
 * Run all validations on Python content.
 *
 * @param string $content Python code to check
 * @return array [blocking_issues, warning_issues]
 */
function validatePyCode(string $content): array
{
    $blockingIssues = [];
    $warningIssues = [];

    // Critical - block these
    $blockingIssues = array_merge($blockingIssues, findPySqlInjection($content));
    $blockingIssues = array_merge($blockingIssues, findPyCommandInjection($content));
    $blockingIssues = array_merge($blockingIssues, findPyPathTraversal($content));
    $blockingIssues = array_merge($blockingIssues, findPyUnsafeDeserialization($content));

    // High risk - warn
    $warningIssues = array_merge($warningIssues, findPyXssRisks($content));
    $warningIssues = array_merge($warningIssues, findPyInsecureCrypto($content));
    $warningIssues = array_merge($warningIssues, findPyHardcodedSecrets($content));

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

        // Only validate Python files
        if (!str_ends_with($filePath, '.py') && !str_ends_with($filePath, '.pyw')) {
            exit(0);
        }

        $content = $toolName === 'Write'
            ? ($toolInput['content'] ?? '')
            : ($toolInput['new_string'] ?? '');

        [$blockingIssues, $warningIssues] = validatePyCode($content);

        if (!empty($blockingIssues)) {
            $feedback = "PYTHON SECURITY VIOLATION (BLOCKING):\n\n";
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
            $feedback = "PYTHON SECURITY SUGGESTION:\n\n";
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
