#!/usr/bin/env php
<?php
/**
 * Log Activity Hook for AI Developer Jobs
 *
 * PostToolUse hook that logs file changes to the aidevjobs record.
 * This tracks which files were modified during a job for reporting.
 *
 * Environment Variables (set by local-aidev-full.php):
 *   MYCTOBOT_APP_ROOT     - Path to myctobot application
 *   MYCTOBOT_WORKSPACE    - Tenant workspace name
 *   MYCTOBOT_JOB_ID       - Current aidevjobs.id
 *   MYCTOBOT_MEMBER_ID    - Member ID running the job
 *   MYCTOBOT_PROJECT_ROOT - Repo clone directory
 *
 * Hook Input (JSON via stdin):
 * {
 *   "tool_name": "Write|Edit",
 *   "tool_input": { "file_path": "/path/to/file" },
 *   "tool_response": { "success": true }
 * }
 *
 * Hook Output: Always exits 0 (never blocks Claude)
 */

require_once __DIR__ . '/TenantHookHelper.php';

// Read hook input from stdin
$input = json_decode(file_get_contents('php://stdin'), true);

if (!$input) {
    exit(0);
}

$toolName = $input['tool_name'] ?? '';
$toolInput = $input['tool_input'] ?? [];
$toolResponse = $input['tool_response'] ?? [];

// Only track Write and Edit operations
if (!in_array($toolName, ['Write', 'Edit'])) {
    exit(0);
}

// Get file path
$filePath = $toolInput['file_path'] ?? '';
if (empty($filePath)) {
    exit(0);
}

// Check if we have job context
$helper = new TenantHookHelper();
if (!$helper->hasContext()) {
    // Not running in aidev job context, exit silently
    exit(0);
}

// Connect to tenant database
if (!$helper->connect()) {
    $helper->log('WARNING', 'Could not connect to tenant database for activity logging');
    exit(0);
}

// Determine action based on tool and success
$success = $toolResponse['success'] ?? true;
if ($success === false) {
    $action = 'failed';
} elseif ($toolName === 'Write') {
    $action = 'created';
} else {
    $action = 'modified';
}

// Add file to files_changed
$helper->addFileChanged($filePath, $action);

// Also add a log entry
$relativePath = $filePath;
$projectRoot = $helper->getProjectRoot();
if ($projectRoot && str_starts_with($filePath, $projectRoot)) {
    $relativePath = substr($filePath, strlen($projectRoot) + 1);
}

$logMessage = "{$toolName}: {$relativePath}";
if ($action === 'failed') {
    $logMessage .= ' (FAILED)';
}

$helper->addJobLog(
    $action === 'failed' ? 'warning' : 'info',
    'file_change',
    $logMessage
);

$helper->log('DEBUG', "Logged file change", [
    'file' => $relativePath,
    'action' => $action,
    'tool' => $toolName
]);

// Always exit 0 - never block Claude
exit(0);
