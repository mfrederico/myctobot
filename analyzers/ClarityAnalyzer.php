<?php
/**
 * Clarity Analyzer
 * Analyzes Jira tickets for clarity and identifies those needing stakeholder input
 */

namespace app\analyzers;

use app\services\ClaudeClient;
use app\services\JiraClient;
use app\services\UserDatabaseService;

class ClarityAnalyzer {
    private ClaudeClient $claude;
    private ?JiraClient $jira = null;
    private bool $includeImages = false;

    public function __construct(ClaudeClient $claude, ?JiraClient $jira = null, bool $includeImages = false) {
        $this->claude = $claude;
        $this->jira = $jira;
        $this->includeImages = $includeImages && ($jira !== null);
    }

    /**
     * Enable/disable image analysis (Pro feature)
     */
    public function setIncludeImages(bool $include): void {
        $this->includeImages = $include && ($this->jira !== null);
    }

    /**
     * Analyze tickets for clarity, using cache when possible
     *
     * IMPORTANT: Caller must have user database connected via UserDatabaseService::connect()
     *
     * @param array $issues Jira issues to analyze
     * @param int $boardId Board ID for caching
     * @param int $clarityThreshold Minimum clarity score (tickets below need clarification)
     * @return array Analysis results with clarifications_needed array
     */
    public function analyzeWithCache(
        array $issues,
        int $boardId,
        int $clarityThreshold = 6
    ): array {
        $results = [
            'clarifications_needed' => [],
            'all_scores' => [],
            'analyzed_count' => 0,
            'cached_count' => 0
        ];

        $issuesToAnalyze = [];

        // Check cache for each issue
        foreach ($issues as $issue) {
            $key = $issue['key'];
            $hash = UserDatabaseService::generateTicketHash($issue);

            // Check if we need to re-analyze (uses static method)
            if (!UserDatabaseService::shouldReanalyzeTicket($boardId, $key, $hash)) {
                // Use cached result
                $cached = UserDatabaseService::getTicketAnalysisCache($boardId, $key);
                if ($cached) {
                    $results['cached_count']++;
                    $results['all_scores'][$key] = [
                        'clarity_score' => $cached['clarity_score'],
                        'analysis' => $cached['clarity_analysis'],
                        'reporter_name' => $cached['reporter_name'],
                        'reporter_email' => $cached['reporter_email'],
                        'from_cache' => true
                    ];

                    // Add to clarifications_needed if below threshold
                    if ($cached['clarity_score'] < $clarityThreshold) {
                        $results['clarifications_needed'][] = $this->formatClarificationResult(
                            $issue,
                            $cached['clarity_score'],
                            $cached['clarity_analysis'],
                            $cached['reporter_name'],
                            $cached['reporter_email']
                        );
                    }
                    continue;
                }
            }

            // Need to analyze this ticket
            $issuesToAnalyze[] = $issue;
        }

        // Batch analyze tickets that need it
        if (!empty($issuesToAnalyze)) {
            $analyzed = $this->analyzeTickets($issuesToAnalyze);

            foreach ($analyzed as $key => $analysis) {
                $results['analyzed_count']++;
                $results['all_scores'][$key] = $analysis;
                $results['all_scores'][$key]['from_cache'] = false;

                // Find the original issue to get the hash
                $issue = null;
                foreach ($issuesToAnalyze as $i) {
                    if ($i['key'] === $key) {
                        $issue = $i;
                        break;
                    }
                }

                if ($issue) {
                    $hash = UserDatabaseService::generateTicketHash($issue);

                    // Only cache successful results (not errors)
                    $assessment = $analysis['analysis']['assessment'] ?? '';
                    if (strpos($assessment, 'Analysis error:') === false && strpos($assessment, 'Unable to analyze') === false) {
                        UserDatabaseService::setTicketAnalysisCache($boardId, $key, $hash, [
                            'clarity_score' => $analysis['clarity_score'],
                            'clarity_analysis' => $analysis['analysis'],
                            'reporter_name' => $analysis['reporter_name'],
                            'reporter_email' => $analysis['reporter_email']
                        ]);
                    }

                    // Add to clarifications_needed if below threshold
                    if ($analysis['clarity_score'] < $clarityThreshold) {
                        $results['clarifications_needed'][] = $this->formatClarificationResult(
                            $issue,
                            $analysis['clarity_score'],
                            $analysis['analysis'],
                            $analysis['reporter_name'],
                            $analysis['reporter_email']
                        );
                    }
                }
            }
        }

        // Sort clarifications by score (lowest first)
        usort($results['clarifications_needed'], function($a, $b) {
            return $a['clarity_score'] <=> $b['clarity_score'];
        });

        return $results;
    }

    /**
     * Analyze tickets for clarity (no caching)
     * Analyzes each ticket individually for reliability
     *
     * @param array $issues Jira issues to analyze
     * @return array Analysis results keyed by issue key
     */
    public function analyzeTickets(array $issues): array {
        if (empty($issues)) {
            return [];
        }

        $results = [];
        foreach ($issues as $issue) {
            $key = $issue['key'];
            $results[$key] = $this->analyzeTicket($issue);
        }

        return $results;
    }

    /**
     * Analyze a single ticket for clarity
     *
     * @param array $issue Single Jira issue to analyze
     * @return array Analysis result
     */
    private function analyzeTicket(array $issue): array {
        $fields = $issue['fields'];
        $key = $issue['key'];
        $summary = $fields['summary'] ?? 'No summary';
        $description = JiraClient::extractTextFromAdf($fields['description'] ?? null);
        $type = $fields['issuetype']['name'] ?? 'Task';
        $reporter = $fields['reporter']['displayName'] ?? 'Unknown';
        $reporterEmail = $fields['reporter']['emailAddress'] ?? null;
        $labels = $fields['labels'] ?? [];

        // Extract comments (may contain clarifications and additional context)
        $comments = $this->extractComments($fields['comment'] ?? null);

        // Fetch images if enabled (Pro feature)
        $images = [];
        $imageContext = '';
        if ($this->includeImages && $this->jira) {
            $images = $this->jira->getIssueImages($issue, 3, 1024); // Max 3 images, 1MB each
            if (!empty($images)) {
                $imageCount = count($images);
                $imageContext = "\n\n[{$imageCount} image(s) attached - please examine them for UI mockups, screenshots, or visual context that may affect clarity assessment]";
            }
        }

        $systemPrompt = <<<PROMPT
You are analyzing a Jira ticket to determine if it has sufficient detail for implementation.

Evaluate these criteria:
1. **Problem/Requirement Clarity**: Is the problem or requirement clearly stated?
2. **Acceptance Criteria**: Are there clear acceptance criteria or definition of done?
3. **Scope Definition**: Is the scope well-defined with clear boundaries?
4. **Edge Cases**: Are edge cases and error scenarios considered?
5. **Technical Context**: Is there enough technical context for implementation?

Score the ticket 1-10 on overall clarity:
- 9-10: Crystal clear, ready for immediate work
- 7-8: Good clarity, minor details might need clarification
- 5-6: Moderate clarity, some questions but workable
- 3-4: Poor clarity, significant gaps in requirements
- 1-2: Very unclear, needs substantial clarification before work can begin

If images are provided, consider whether they add clarity (mockups, screenshots showing the issue, wireframes) or if they need more context/explanation.

If comments are provided, consider whether they add clarifications, answer questions, or provide additional context that improves the ticket's clarity.
PROMPT;

        // Build comments section if there are any
        $commentsSection = '';
        if (!empty($comments)) {
            $commentsSection = "\n\nComments/Discussion:\n{$comments}";
        }

        $userMessage = <<<MSG
Analyze this ticket for clarity:

Key: {$key}
Summary: {$summary}
Type: {$type}
Reporter: {$reporter}
Labels: {$this->formatLabels($labels)}

Description:
{$description}{$imageContext}{$commentsSection}

Respond with JSON:
{
    "clarity_score": <1-10>,
    "assessment": "<brief assessment of ticket clarity>",
    "missing_elements": ["<missing element 1>", "<missing element 2>"],
    "suggested_questions": ["<question for stakeholder 1>", "<question 2>"]
}
MSG;

        try {
            $response = $this->claude->chatJson($systemPrompt, $userMessage, $images);

            return [
                'clarity_score' => (int)($response['clarity_score'] ?? 5),
                'analysis' => [
                    'assessment' => $response['assessment'] ?? '',
                    'missing_elements' => $response['missing_elements'] ?? [],
                    'suggested_questions' => $response['suggested_questions'] ?? []
                ],
                'reporter_name' => $reporter,
                'reporter_email' => $reporterEmail,
                'images_analyzed' => count($images)
            ];

        } catch (\Exception $e) {
            // Return default score on error - don't cache this
            return [
                'clarity_score' => 5,
                'analysis' => [
                    'assessment' => 'Analysis error: ' . $e->getMessage(),
                    'missing_elements' => [],
                    'suggested_questions' => []
                ],
                'reporter_name' => $reporter,
                'reporter_email' => $reporterEmail,
                'images_analyzed' => 0
            ];
        }
    }

    /**
     * Format labels array as string
     */
    private function formatLabels(array $labels): string {
        return empty($labels) ? 'None' : implode(', ', $labels);
    }

    /**
     * Extract comments from Jira comment field
     * Returns formatted string with recent comments (max 5, most recent first)
     *
     * @param array|null $commentData Jira comment field data
     * @return string Formatted comments or empty string
     */
    private function extractComments(?array $commentData): string {
        if (empty($commentData) || empty($commentData['comments'])) {
            return '';
        }

        $comments = $commentData['comments'];
        // Take last 5 comments (most recent)
        $recentComments = array_slice($comments, -5);
        // Reverse to show most recent first
        $recentComments = array_reverse($recentComments);

        $formatted = [];
        foreach ($recentComments as $comment) {
            $author = $comment['author']['displayName'] ?? 'Unknown';
            $created = isset($comment['created']) ? date('Y-m-d', strtotime($comment['created'])) : '';
            $body = JiraClient::extractTextFromAdf($comment['body'] ?? null);

            // Truncate very long comments
            if (strlen($body) > 500) {
                $body = substr($body, 0, 500) . '...';
            }

            if (!empty($body)) {
                $formatted[] = "[{$author} on {$created}]: {$body}";
            }
        }

        return implode("\n\n", $formatted);
    }

    /**
     * Format a clarification result for display
     */
    private function formatClarificationResult(
        array $issue,
        int $clarityScore,
        ?array $analysis,
        ?string $reporterName,
        ?string $reporterEmail
    ): array {
        $fields = $issue['fields'];

        return [
            'key' => $issue['key'],
            'summary' => $fields['summary'] ?? 'No summary',
            'clarity_score' => $clarityScore,
            'reporter_name' => $reporterName ?? $fields['reporter']['displayName'] ?? 'Unknown',
            'reporter_email' => $reporterEmail ?? $fields['reporter']['emailAddress'] ?? null,
            'assessment' => $analysis['assessment'] ?? '',
            'missing_elements' => $analysis['missing_elements'] ?? [],
            'suggested_questions' => $analysis['suggested_questions'] ?? [],
            'type' => $fields['issuetype']['name'] ?? 'Task',
            'priority' => $fields['priority']['name'] ?? 'Medium'
        ];
    }

    /**
     * Get clarity badge class based on score
     */
    public static function getClarityBadgeClass(int $score): string {
        if ($score >= 8) return 'bg-success';
        if ($score >= 6) return 'bg-warning text-dark';
        if ($score >= 4) return 'bg-orange';
        return 'bg-danger';
    }

    /**
     * Get clarity level text based on score
     */
    public static function getClarityLevel(int $score): string {
        if ($score >= 9) return 'Excellent';
        if ($score >= 7) return 'Good';
        if ($score >= 5) return 'Moderate';
        if ($score >= 3) return 'Poor';
        return 'Very Poor';
    }
}
