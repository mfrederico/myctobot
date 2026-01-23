<?php
/**
 * MyCTOBot GitHub MCP Tools
 *
 * This file defines GitHub tools that fastmcphp can discover and load.
 * Tools use the authenticated member's GitHub connection from MyCTOBot.
 *
 * @package MyCTOBot\MCP
 */

declare(strict_types=1);

use Fastmcphp\Fastmcphp;

return function (Fastmcphp $mcp, callable $getService): void {

    /**
     * Parse GitHub issue key format: owner/repo#123
     */
    $parseIssueKey = function (string $key): array {
        if (!preg_match('/^([^\/]+)\/([^#]+)#(\d+)$/', $key, $m)) {
            throw new \RuntimeException("Invalid issue key format. Expected 'owner/repo#123', got: {$key}");
        }
        return [$m[1], $m[2], (int) $m[3]];
    };

    // github_comment - Post a comment to a GitHub issue
    $mcp->tool(
        callable: function (string $issue_key, string $message) use ($getService, $parseIssueKey): string {
            $github = $getService('github');
            [$owner, $repo, $number] = $parseIssueKey($issue_key);
            $result = $github->addIssueComment($owner, $repo, $number, $message);
            return "Comment posted. ID: " . ($result['id'] ?? 'unknown');
        },
        name: 'github_comment',
        description: 'Post a comment to a GitHub issue. Issue key format: "owner/repo#123"',
    );

    // github_close_issue - Close a GitHub issue
    $mcp->tool(
        callable: function (string $issue_key, ?string $comment = null) use ($getService, $parseIssueKey): string {
            $github = $getService('github');
            [$owner, $repo, $number] = $parseIssueKey($issue_key);

            if ($comment) {
                $github->addIssueComment($owner, $repo, $number, $comment);
            }
            $github->closeIssue($owner, $repo, $number);

            return "Issue #{$number} closed.";
        },
        name: 'github_close_issue',
        description: 'Close a GitHub issue with optional comment',
    );

    // github_get_issue - Get details of a GitHub issue
    $mcp->tool(
        callable: function (string $issue_key) use ($getService, $parseIssueKey): string {
            $github = $getService('github');
            [$owner, $repo, $number] = $parseIssueKey($issue_key);
            $issue = $github->getIssue($owner, $repo, $number);

            return json_encode([
                'number' => $issue['number'],
                'title' => $issue['title'],
                'state' => $issue['state'],
                'body' => $issue['body'],
                'labels' => array_map(fn($l) => $l['name'], $issue['labels'] ?? []),
                'html_url' => $issue['html_url']
            ], JSON_PRETTY_PRINT);
        },
        name: 'github_get_issue',
        description: 'Get details of a GitHub issue',
    );

    // github_add_labels - Add labels to a GitHub issue
    $mcp->tool(
        callable: function (string $issue_key, array $labels) use ($getService, $parseIssueKey): string {
            $github = $getService('github');
            [$owner, $repo, $number] = $parseIssueKey($issue_key);
            $github->addLabels($owner, $repo, $number, $labels);
            return "Labels added: " . implode(', ', $labels);
        },
        name: 'github_add_labels',
        description: 'Add labels to a GitHub issue',
    );

    // github_create_pr - Create a pull request
    $mcp->tool(
        callable: function (
            string $repo,
            string $title,
            string $head,
            string $base = 'main',
            string $body = ''
        ) use ($getService): string {
            $github = $getService('github');
            [$owner, $repoName] = explode('/', $repo);
            $pr = $github->createPullRequest($owner, $repoName, $title, $body, $head, $base, false);

            return json_encode([
                'number' => $pr['number'],
                'html_url' => $pr['html_url'],
                'state' => $pr['state']
            ], JSON_PRETTY_PRINT);
        },
        name: 'github_create_pr',
        description: 'Create a pull request',
    );
};
