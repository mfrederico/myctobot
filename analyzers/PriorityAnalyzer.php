<?php
/**
 * Priority Analyzer
 * Generates daily prioritized task lists using Claude AI
 */

namespace app\analyzers;

use app\services\ClaudeClient;
use app\services\JiraClient;

class PriorityAnalyzer {
    private ClaudeClient $claude;

    public function __construct(ClaudeClient $claude) {
        $this->claude = $claude;
    }

    /**
     * Generate daily prioritized task list
     */
    public function generateDailyPriorities(
        array $issues,
        ?array $estimations = null,
        ?array $clarifications = null,
        ?array $similarities = null
    ): array {
        // Prepare comprehensive issue data
        $issueData = [];
        foreach ($issues as $issue) {
            $key = $issue['key'];
            $fields = $issue['fields'];

            $data = [
                'key' => $key,
                'summary' => $fields['summary'] ?? 'No summary',
                'description' => JiraClient::extractTextFromAdf($fields['description'] ?? null),
                'type' => $fields['issuetype']['name'] ?? 'Task',
                'priority' => $fields['priority']['name'] ?? 'Medium',
                'status' => $fields['status']['name'] ?? 'To Do',
                'assignee' => $fields['assignee']['displayName'] ?? 'Unassigned',
                'labels' => $fields['labels'] ?? [],
            ];

            // Add estimation if available
            if ($estimations) {
                foreach ($estimations as $est) {
                    if ($est['key'] === $key && $est['success']) {
                        $data['estimation'] = $est['estimation'];
                        break;
                    }
                }
            }

            // Add clarification status if available
            if ($clarifications) {
                foreach ($clarifications as $clar) {
                    if ($clar['key'] === $key && $clar['success']) {
                        $data['needs_clarification'] = $clar['needs_clarification'];
                        $data['clarity_score'] = $clar['clarification']['clarity_score'] ?? null;
                        break;
                    }
                }
            }

            $issueData[$key] = $data;
        }

        $systemPrompt = <<<PROMPT
You are a senior engineering manager helping prioritize sprint tasks with a CUSTOMER-FIRST mindset.

When prioritizing, consider:
1. **Customer Impact** (HIGHEST WEIGHT): What directly affects customers should come first
   - Customer-facing bugs and issues
   - Features customers are waiting for
   - Performance issues affecting user experience
2. **Business Value**: Revenue impact, customer retention, competitive advantage
3. **Urgency**: Time-sensitive issues, deadlines, dependencies
4. **Complexity vs Clarity**: Well-defined tasks may be better to start with
5. **Dependencies**: What blocks other work
6. **Risk**: What could become worse if delayed

Also consider:
- Tasks that need clarification might need to be deprioritized until clarified
- Similar tasks could be batched together for efficiency
- Balance quick wins with important larger tasks
PROMPT;

        // Format issue data for Claude
        $formattedIssues = "";
        foreach ($issueData as $data) {
            $formattedIssues .= "--- TICKET ---\n";
            $formattedIssues .= "Key: {$data['key']}\n";
            $formattedIssues .= "Summary: {$data['summary']}\n";
            $formattedIssues .= "Type: {$data['type']}\n";
            $formattedIssues .= "Priority: {$data['priority']}\n";
            $formattedIssues .= "Status: {$data['status']}\n";
            $formattedIssues .= "Assignee: {$data['assignee']}\n";
            $formattedIssues .= "Labels: " . implode(', ', $data['labels']) . "\n";

            if (isset($data['estimation'])) {
                $formattedIssues .= "Estimated Points: {$data['estimation']['story_points']}\n";
                $formattedIssues .= "Complexity: {$data['estimation']['complexity']}\n";
            }

            if (isset($data['needs_clarification'])) {
                $formattedIssues .= "Needs Clarification: " . ($data['needs_clarification'] ? 'Yes' : 'No') . "\n";
                if ($data['clarity_score']) {
                    $formattedIssues .= "Clarity Score: {$data['clarity_score']}/10\n";
                }
            }

            $formattedIssues .= "Description: " . substr($data['description'], 0, 500) . "\n\n";
        }

        // Add similarity info if available
        $similarityContext = "";
        if ($similarities && $similarities['success'] && !empty($similarities['analysis']['similarity_groups'])) {
            $similarityContext = "\n\nSIMILAR TASK GROUPS (consider batching):\n";
            foreach ($similarities['analysis']['similarity_groups'] as $group) {
                $tickets = implode(', ', $group['tickets'] ?? []);
                $similarityContext .= "- {$tickets}: {$group['description']}\n";
            }
        }

        $userMessage = <<<MSG
Based on these sprint tickets, create a prioritized daily work plan focused on CUSTOMER VALUE:

{$formattedIssues}
{$similarityContext}

Respond with a JSON object:
{
    "date": "<today's date>",
    "priorities": [
        {
            "rank": 1,
            "key": "<ticket key>",
            "summary": "<ticket summary>",
            "customer_impact": "high|medium|low|none",
            "reason": "<why this priority>",
            "suggested_action": "<what to do>",
            "estimated_effort": "<from estimation or your assessment>",
            "blockers": ["<any blockers>"],
            "batch_with": ["<related tickets to work together>"]
        }
    ],
    "blocked_tickets": [
        {
            "key": "<ticket key>",
            "reason": "<why blocked>",
            "action_needed": "<what needs to happen>"
        }
    ],
    "recommendations": [
        "<strategic recommendation for the day>"
    ],
    "customer_focus_summary": "<summary of customer-impacting work>",
    "risk_alerts": ["<any risks to flag>"]
}
MSG;

        try {
            $analysis = $this->claude->chatJson($systemPrompt, $userMessage);
            return [
                'success' => true,
                'date' => date('Y-m-d'),
                'analysis' => $analysis,
                'issue_count' => count($issues),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'date' => date('Y-m-d'),
            ];
        }
    }

    /**
     * Generate a markdown daily log
     */
    public function generateDailyLog(array $priorityResult): string {
        $date = $priorityResult['date'];
        $log = "# Daily Priority Log - {$date}\n\n";
        $log .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";

        if (!$priorityResult['success']) {
            $log .= "**Error generating priorities**: " . ($priorityResult['error'] ?? 'Unknown error') . "\n";
            return $log;
        }

        $analysis = $priorityResult['analysis'];

        // Customer Focus Summary
        if (!empty($analysis['customer_focus_summary'])) {
            $log .= "## Customer Focus\n\n";
            $log .= "> {$analysis['customer_focus_summary']}\n\n";
        }

        // Risk Alerts
        if (!empty($analysis['risk_alerts'])) {
            $log .= "## Risk Alerts\n\n";
            foreach ($analysis['risk_alerts'] as $alert) {
                $log .= "- {$alert}\n";
            }
            $log .= "\n";
        }

        // Prioritized Tasks
        $log .= "## Today's Priorities\n\n";

        if (empty($analysis['priorities'])) {
            $log .= "No tasks prioritized.\n\n";
        } else {
            foreach ($analysis['priorities'] as $task) {
                $rank = $task['rank'];
                $impact = strtoupper($task['customer_impact'] ?? 'unknown');
                $log .= "### #{$rank}: {$task['key']} - {$task['summary']}\n\n";
                $log .= "| Attribute | Value |\n";
                $log .= "|-----------|-------|\n";
                $log .= "| Customer Impact | **{$impact}** |\n";
                $log .= "| Effort | {$task['estimated_effort']} |\n";
                $log .= "| Action | {$task['suggested_action']} |\n\n";

                $log .= "**Why this priority**: {$task['reason']}\n\n";

                if (!empty($task['batch_with'])) {
                    $batch = implode(', ', $task['batch_with']);
                    $log .= "**Consider batching with**: {$batch}\n\n";
                }

                if (!empty($task['blockers'])) {
                    $log .= "**Blockers**:\n";
                    foreach ($task['blockers'] as $blocker) {
                        $log .= "- {$blocker}\n";
                    }
                    $log .= "\n";
                }

                $log .= "---\n\n";
            }
        }

        // Blocked Tickets
        if (!empty($analysis['blocked_tickets'])) {
            $log .= "## Blocked Tickets\n\n";
            foreach ($analysis['blocked_tickets'] as $blocked) {
                $log .= "### {$blocked['key']}\n";
                $log .= "- **Reason**: {$blocked['reason']}\n";
                $log .= "- **Action Needed**: {$blocked['action_needed']}\n\n";
            }
        }

        // Strategic Recommendations
        if (!empty($analysis['recommendations'])) {
            $log .= "## Recommendations\n\n";
            foreach ($analysis['recommendations'] as $rec) {
                $log .= "- {$rec}\n";
            }
            $log .= "\n";
        }

        return $log;
    }
}
