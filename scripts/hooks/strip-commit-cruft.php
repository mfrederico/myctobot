#!/usr/bin/env php
<?php
/**
 * Strip Claude Code Signature from Git Commits
 *
 * PreToolUse hook that removes Claude Code footer, emojis, and
 * Co-Authored-By lines from git commit messages.
 *
 * This ensures clean commit messages without "Claude cruft".
 *
 * Hook Input (JSON via stdin):
 * {
 *   "tool_name": "Bash",
 *   "tool_input": { "command": "git commit -m ..." }
 * }
 *
 * Hook Output (JSON to stdout):
 * - { "decision": "approve" } - Allow unchanged
 * - { "decision": "approve", "tool_input": { "command": "..." } } - Modify command
 */

$input = json_decode(file_get_contents('php://stdin'), true);

if (!$input || ($input['tool_name'] ?? '') !== 'Bash') {
    echo json_encode(['decision' => 'approve']);
    exit(0);
}

$command = $input['tool_input']['command'] ?? '';

// Check if this is a git commit command
if (!preg_match('/git\s+commit/', $command)) {
    echo json_encode(['decision' => 'approve']);
    exit(0);
}

// Patterns to remove from commit messages
$patterns = [
    // Remove robot emoji
    '/🤖\s*/u' => '',

    // Remove "Generated with Claude Code" line and URL
    '/Generated with \[Claude Code\]\(https:\/\/claude\.com\/claude-code\)\s*/u' => '',
    '/Generated with Claude Code\s*/u' => '',

    // Remove Co-Authored-By Claude lines
    '/Co-Authored-By:\s*Claude[^\n]*\n?/i' => '',
    '/Co-Authored-By:\s*Claude Opus[^\n]*\n?/i' => '',
    '/Co-Authored-By:\s*.*<noreply@anthropic\.com>[^\n]*\n?/i' => '',
];

$modified = $command;
foreach ($patterns as $pattern => $replacement) {
    $modified = preg_replace($pattern, $replacement, $modified);
}

// Clean up excess newlines before EOF
$modified = preg_replace('/\n{3,}/', "\n\n", $modified);
$modified = preg_replace('/\n{2,}(EOF\n)/', "\n$1", $modified);

// Clean up trailing whitespace in commit message
$modified = preg_replace('/[ \t]+\n/', "\n", $modified);

if ($modified !== $command) {
    echo json_encode([
        'decision' => 'approve',
        'tool_input' => [
            'command' => $modified
        ]
    ]);
} else {
    echo json_encode(['decision' => 'approve']);
}
