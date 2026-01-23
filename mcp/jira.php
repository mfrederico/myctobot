<?php
/**
 * MyCTOBot Jira MCP Tools
 *
 * This file defines Jira tools that fastmcphp can discover and load.
 * Tools use the authenticated member's Jira connection from MyCTOBot.
 *
 * @package MyCTOBot\MCP
 */

declare(strict_types=1);

use Fastmcphp\Fastmcphp;
use Fastmcphp\Server\Auth\AuthenticatedUser;

return function (Fastmcphp $mcp, callable $getService): void {

    // jira_comment - Post a comment to a Jira ticket
    $mcp->tool(
        callable: function (string $issue_key, string $message) use ($getService): string {
            $jira = $getService('jira');
            $jira->addComment($issue_key, $message);
            return "Comment posted successfully to {$issue_key}";
        },
        name: 'jira_comment',
        description: 'Post a comment to a Jira ticket',
    );

    // jira_transition - Transition a Jira ticket to a new status
    $mcp->tool(
        callable: function (string $issue_key, string $status_name) use ($getService): string {
            $jira = $getService('jira');
            $transitions = $jira->getTransitions($issue_key);

            $transitionId = null;
            foreach ($transitions as $t) {
                if (strcasecmp($t['name'], $status_name) === 0 ||
                    strcasecmp($t['to']['name'] ?? '', $status_name) === 0) {
                    $transitionId = $t['id'];
                    break;
                }
            }

            if (!$transitionId) {
                $available = array_map(fn($t) => $t['name'], $transitions);
                throw new \RuntimeException(
                    "Status '{$status_name}' not available. Available: " . implode(', ', $available)
                );
            }

            $jira->transitionIssue($issue_key, $transitionId);
            return "Transitioned {$issue_key} to '{$status_name}'";
        },
        name: 'jira_transition',
        description: 'Transition a Jira ticket to a new status',
    );

    // jira_get_transitions - Get available status transitions
    $mcp->tool(
        callable: function (string $issue_key) use ($getService): string {
            $jira = $getService('jira');
            $transitions = $jira->getTransitions($issue_key);

            $formatted = array_map(fn($t) => [
                'id' => $t['id'],
                'name' => $t['name'],
                'to_status' => $t['to']['name'] ?? $t['name']
            ], $transitions);

            return json_encode($formatted, JSON_PRETTY_PRINT);
        },
        name: 'jira_get_transitions',
        description: 'Get available status transitions for a Jira ticket',
    );

    // jira_get_issue - Get details of a Jira issue
    $mcp->tool(
        callable: function (string $issue_key) use ($getService): string {
            $jira = $getService('jira');
            $issue = $jira->getIssue($issue_key);

            return json_encode([
                'key' => $issue['key'] ?? $issue_key,
                'summary' => $issue['fields']['summary'] ?? '',
                'status' => $issue['fields']['status']['name'] ?? '',
                'assignee' => $issue['fields']['assignee']['displayName'] ?? 'Unassigned',
                'priority' => $issue['fields']['priority']['name'] ?? '',
                'description' => $issue['fields']['description'] ?? ''
            ], JSON_PRETTY_PRINT);
        },
        name: 'jira_get_issue',
        description: 'Get details of a Jira issue',
    );
};
