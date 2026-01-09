#!/usr/bin/env php
<?php
/**
 * Capture Response Hook for AI Developer Jobs
 *
 * Stop hook that captures Claude's responses and logs them to the aidevjobs
 * record. Also updates job status to indicate Claude is waiting for input.
 *
 * This hook fires when Claude finishes responding (Stop event).
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
 *   "hook_event_name": "Stop",
 *   "stop_hook_response": {
 *     "message": {
 *       "content": [{"type": "text", "text": "..."}]
 *     }
 *   }
 * }
 *
 * Hook Output: Always outputs empty JSON object and exits 0
 */

require_once __DIR__ . '/TenantHookHelper.php';

// Read hook input from stdin
$input = json_decode(file_get_contents('php://stdin'), true);

// Always output valid JSON for Stop hooks
function exitClean(): void {
    echo json_encode(new stdClass());
    exit(0);
}

if (!$input) {
    exitClean();
}

// Check if we have job context
$helper = new TenantHookHelper();
if (!$helper->hasContext()) {
    // Not running in aidev job context
    exitClean();
}

// Extract the stop_hook_response (Claude's message)
$stopResponse = $input['stop_hook_response'] ?? [];
$message = $stopResponse['message'] ?? null;

if (!$message) {
    exitClean();
}

// Extract text content from the message
$textContent = extractTextContent($message);

if (!$textContent || strlen(trim($textContent)) < 10) {
    // Skip very short or empty responses
    exitClean();
}

// Skip if it's mostly JSON (tool output, not meaningful response)
$trimmed = trim($textContent);
if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
    exitClean();
}

// Connect to tenant database
if (!$helper->connect()) {
    $helper->log('WARNING', 'Could not connect to tenant database for response capture');
    exitClean();
}

// Log the response as a job log entry
$helper->addJobLog('info', 'claude_response', truncateMessage($textContent, 4000));

// Update job progress message with a summary of the response
$summary = summarizeResponse($textContent);
$helper->updateJobStatus('running', $summary);

$helper->log('DEBUG', 'Captured Claude response', [
    'length' => strlen($textContent),
    'summary' => substr($summary, 0, 100)
]);

exitClean();


/**
 * Extract text content from Claude's response message
 */
function extractTextContent(array $message): ?string {
    $content = $message['content'] ?? [];

    if (is_string($content)) {
        return $content;
    }

    // Content is an array of content blocks
    $textParts = [];
    foreach ($content as $block) {
        if (is_array($block) && ($block['type'] ?? '') === 'text') {
            $textParts[] = $block['text'] ?? '';
        } elseif (is_string($block)) {
            $textParts[] = $block;
        }
    }

    return $textParts ? implode("\n", $textParts) : null;
}

/**
 * Truncate message to avoid overwhelming the log system
 */
function truncateMessage(string $text, int $maxLength = 4000): string {
    if (strlen($text) <= $maxLength) {
        return $text;
    }
    return substr($text, 0, $maxLength - 15) . "\n... [truncated]";
}

/**
 * Create a brief summary of the response for progress display
 */
function summarizeResponse(string $text): string {
    // Get first meaningful line (skip empty lines)
    $lines = explode("\n", $text);
    $summary = '';

    foreach ($lines as $line) {
        $line = trim($line);
        if (!empty($line) && !str_starts_with($line, '#') && strlen($line) > 10) {
            $summary = $line;
            break;
        }
    }

    if (empty($summary)) {
        $summary = trim(substr($text, 0, 100));
    }

    // Truncate to reasonable length for progress message
    if (strlen($summary) > 100) {
        $summary = substr($summary, 0, 97) . '...';
    }

    return $summary;
}
