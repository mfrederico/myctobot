#!/usr/bin/env php
<?php
/**
 * Sign GitHub Comment Hook for AI Developer Jobs
 *
 * PreToolUse hook that appends the [agent:NAME] signature to comments posted
 * through the EXTERNAL GitHub MCP server.
 *
 * Why this exists:
 *   Every comment the agent posts fires GitHub's issue_comment webhook, which
 *   myctobot forwards back into the running session. Without a marker saying
 *   "we wrote this", the agent is handed its own comment and replies to itself.
 *
 *   Comments posted through myctobot's own MCP gateway are signed server-side
 *   (McpGatewayToolService::signAgentComment). The external github MCP server
 *   is third-party code we do not control, so its writes are signed here
 *   instead - at the tool call, before it reaches GitHub.
 *
 *   This rewrites the argument rather than blocking the call, so the agent
 *   never has to know about it and no round trip is wasted. Hooks still run
 *   under --dangerously-skip-permissions, which is what makes this reliable
 *   rather than dependent on the model remembering an instruction.
 *
 * Environment Variables (set by job-dispatcher.php):
 *   MYCTOBOT_AGENT_NAME - Name used in the signature (optional)
 *
 * Hook Input (JSON via stdin):
 * {
 *   "hook_event_name": "PreToolUse",
 *   "tool_name": "mcp__github__add_issue_comment",
 *   "tool_input": { "owner": "...", "repo": "...", "issue_number": 1, "body": "..." }
 * }
 *
 * Hook Output: either {} (leave the call alone) or a PreToolUse decision
 * carrying updatedInput with the signature appended.
 */

// Tools whose body/message argument must carry the signature.
const SIGNABLE_TOOLS = [
    'mcp__github__add_issue_comment' => 'body',
    'mcp__github__create_issue_comment' => 'body',
    'mcp__github__update_issue_comment' => 'body',
];

$raw = stream_get_contents(STDIN);
if ($raw === false || trim($raw) === '') {
    echo "{}";
    exit(0);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    echo "{}";
    exit(0);
}

$toolName = (string) ($payload['tool_name'] ?? '');
if (!isset(SIGNABLE_TOOLS[$toolName])) {
    echo "{}";
    exit(0);
}

$field = SIGNABLE_TOOLS[$toolName];
$input = $payload['tool_input'] ?? [];
if (!is_array($input) || !isset($input[$field]) || !is_string($input[$field])) {
    echo "{}";
    exit(0);
}

$body = $input[$field];

// Already signed - the model sometimes copies a signature out of context, and
// the gateway tool may have signed it. Never double-sign.
if (preg_match('/\[agent:[^\]]+\]/', $body)) {
    echo "{}";
    exit(0);
}

$agentName = getenv('MYCTOBOT_AGENT_NAME') ?: 'AI Developer';
$input[$field] = $body . "\n\n[agent:{$agentName}]";

echo json_encode([
    'hookSpecificOutput' => [
        'hookEventName'     => 'PreToolUse',
        'permissionDecision' => 'allow',
        'updatedInput'      => $input,
    ],
]);
exit(0);
