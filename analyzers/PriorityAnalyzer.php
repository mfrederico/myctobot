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
     *
     * @param array $issues Jira issues to analyze
     * @param array|null $estimations Optional estimation data
     * @param array|null $clarifications Optional clarification data
     * @param array|null $similarities Optional similarity groupings
     * @param array|null $weights Priority weight configuration (Pro feature)
     * @param array|null $goals Engineering goals (Pro feature)
     */
    public function generateDailyPriorities(
        array $issues,
        ?array $estimations = null,
        ?array $clarifications = null,
        ?array $similarities = null,
        ?array $weights = null,
        ?array $goals = null
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
                    if (($clar['key'] ?? '') === $key && ($clar['success'] ?? false)) {
                        $data['needs_clarification'] = $clar['needs_clarification'] ?? false;
                        $data['clarity_score'] = $clar['clarification']['clarity_score'] ?? null;
                        break;
                    }
                }
            }

            $issueData[$key] = $data;
        }

        // Build dynamic weight instructions
        $weightInstructions = "";
        if ($weights && is_array($weights)) {
            $activeWeights = [];
            if (!empty($weights['quick_wins']['enabled'])) {
                $activeWeights[] = "- **Quick Wins** ({$weights['quick_wins']['value']}% weight): Favor low-effort, high-impact tasks that can be completed quickly";
            }
            if (!empty($weights['synergy']['enabled'])) {
                $activeWeights[] = "- **Task Synergy** ({$weights['synergy']['value']}% weight): Group related tasks together to reduce context switching";
            }
            if (!empty($weights['customer']['enabled'])) {
                $activeWeights[] = "- **Customer Directed** ({$weights['customer']['value']}% weight): Prioritize customer-facing work and features";
            }
            if (!empty($weights['design']['enabled'])) {
                $activeWeights[] = "- **Design Directed** ({$weights['design']['value']}% weight): Prioritize design/UX improvements and polish";
            }
            if (!empty($weights['tech_debt']['enabled'])) {
                $activeWeights[] = "- **Technical Debt** ({$weights['tech_debt']['value']}% weight): Allocate time for paying down technical debt";
            }
            if (!empty($weights['risk']['enabled'])) {
                $activeWeights[] = "- **Risk Mitigation** ({$weights['risk']['value']}% weight): Prioritize tasks that reduce project risk or blockers";
            }

            if (!empty($activeWeights)) {
                $weightInstructions = "\n\n## CUSTOM PRIORITY WEIGHTS (Apply these when ranking tasks)\n" . implode("\n", $activeWeights);
            }
        }

        // Build goals context
        $goalsContext = "";
        if ($goals && is_array($goals)) {
            $goalsList = [];
            if (!empty($goals['velocity'])) {
                $goalsList[] = "- Sprint Velocity Target: {$goals['velocity']} story points";
            }
            if (!empty($goals['debt_reduction'])) {
                $goalsList[] = "- Tech Debt Allocation: {$goals['debt_reduction']}% of sprint capacity";
            }
            if (!empty($goals['predictability'])) {
                $goalsList[] = "- Delivery Predictability Target: {$goals['predictability']}%";
            }

            // Team capacity details
            if (!empty($goals['fte_count'])) {
                $fteCount = $goals['fte_count'];
                $sprintDays = $goals['sprint_days'] ?? 10;
                $hoursPerDay = $goals['hours_per_day'] ?? 8;
                $productivity = $goals['productivity'] ?? 70;
                $capacity = $goals['capacity'] ?? 0;

                $goalsList[] = "- Team Size: {$fteCount} FTEs (full-time equivalent engineers)";
                $goalsList[] = "- Sprint Length: {$sprintDays} working days";
                $goalsList[] = "- Available Capacity: {$capacity} productive hours this sprint ({$productivity}% of {$fteCount} × {$hoursPerDay}hrs × {$sprintDays} days)";

                // Add capacity guidance
                $hoursPerPoint = !empty($goals['velocity']) && $goals['velocity'] > 0
                    ? round($capacity / $goals['velocity'], 1)
                    : null;

                if ($hoursPerPoint) {
                    $goalsList[] = "- Estimated: ~{$hoursPerPoint} hours per story point based on velocity target";
                }
            } elseif (!empty($goals['capacity'])) {
                $goalsList[] = "- Team Capacity: {$goals['capacity']} hours/sprint";
            }

            if (!empty($goals['clarity_threshold'])) {
                $goalsList[] = "- Clarity Threshold: {$goals['clarity_threshold']}/10 (tickets below this need clarification)";
            }

            if (!empty($goalsList)) {
                $goalsContext = "\n\n## ENGINEERING GOALS (Consider these when making recommendations)\n" . implode("\n", $goalsList);

                // Add capacity warning if over-committed
                if (!empty($goals['velocity']) && !empty($goals['capacity'])) {
                    $goalsContext .= "\n\nWhen analyzing capacity, warn if the planned work appears to exceed available hours.";
                }
            }
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
{$weightInstructions}{$goalsContext}
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

            // Build configuration context for transparency
            $configContext = [
                'weights_applied' => [],
                'goals_applied' => [],
                'system_prompt_used' => $systemPrompt
            ];

            // Document which weights were active
            if ($weights && is_array($weights)) {
                foreach (['quick_wins', 'synergy', 'customer', 'design', 'tech_debt', 'risk'] as $key) {
                    if (!empty($weights[$key]['enabled'])) {
                        $configContext['weights_applied'][$key] = $weights[$key]['value'] . '%';
                    }
                }
            }

            // Document which goals were set
            if ($goals && is_array($goals)) {
                if (!empty($goals['velocity'])) {
                    $configContext['goals_applied']['velocity'] = $goals['velocity'] . ' story points';
                }
                if (!empty($goals['debt_reduction'])) {
                    $configContext['goals_applied']['debt_reduction'] = $goals['debt_reduction'] . '%';
                }
                if (!empty($goals['predictability'])) {
                    $configContext['goals_applied']['predictability'] = $goals['predictability'] . '%';
                }
                if (!empty($goals['fte_count'])) {
                    $configContext['goals_applied']['team_size'] = $goals['fte_count'] . ' FTEs';
                }
                if (!empty($goals['capacity'])) {
                    $configContext['goals_applied']['capacity'] = $goals['capacity'] . ' hours';
                }
                if (!empty($goals['clarity_threshold'])) {
                    $configContext['goals_applied']['clarity_threshold'] = $goals['clarity_threshold'] . '/10';
                }
            }

            return [
                'success' => true,
                'date' => date('Y-m-d'),
                'analysis' => $analysis,
                'issue_count' => count($issues),
                'config_context' => $configContext,
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
     * Format a Jira ticket key as a markdown link
     *
     * @param string $ticketKey The ticket key (e.g., "PROJ-123")
     * @param string|null $jiraBaseUrl The Jira site URL (e.g., "https://yoursite.atlassian.net")
     * @return string Formatted ticket (link or plain text)
     */
    private function formatTicketLink(string $ticketKey, ?string $jiraBaseUrl): string {
        if ($jiraBaseUrl) {
            $url = rtrim($jiraBaseUrl, '/') . '/browse/' . $ticketKey;
            return "[{$ticketKey}]({$url})";
        }
        return $ticketKey;
    }

    /**
     * Generate a markdown daily log
     *
     * @param array $priorityResult The result from generateDailyPriorities()
     * @param string|null $jiraBaseUrl Optional Jira site URL for creating ticket links
     */
    public function generateDailyLog(array $priorityResult, ?string $jiraBaseUrl = null): string {
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
                $ticketLink = $this->formatTicketLink($task['key'], $jiraBaseUrl);
                $log .= "### #{$rank}: {$ticketLink} - {$task['summary']}\n\n";
                $log .= "| Attribute | Value |\n";
                $log .= "|-----------|-------|\n";
                $log .= "| Customer Impact | **{$impact}** |\n";
                $log .= "| Effort | {$task['estimated_effort']} |\n";
                $log .= "| Action | {$task['suggested_action']} |\n\n";

                $log .= "**Why this priority**: {$task['reason']}\n\n";

                if (!empty($task['batch_with'])) {
                    $batchLinks = array_map(fn($key) => $this->formatTicketLink($key, $jiraBaseUrl), $task['batch_with']);
                    $batch = implode(', ', $batchLinks);
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
                $blockedLink = $this->formatTicketLink($blocked['key'], $jiraBaseUrl);
                $log .= "### {$blockedLink}\n";
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
