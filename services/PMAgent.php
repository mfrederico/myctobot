<?php
/**
 * PM Agent Service
 * Project Management agent that breaks epics into user stories with detailed acceptance criteria
 *
 * Responsibilities:
 * - Convert epics into user stories
 * - Write detailed acceptance criteria (Given/When/Then)
 * - Create Jira issues with proper formatting
 * - Set up dependencies between stories
 * - Prioritize and sequence work
 */

namespace app\services;

use \Flight as Flight;
use \app\Bean;

require_once __DIR__ . '/ClaudeClient.php';
require_once __DIR__ . '/JiraClient.php';
require_once __DIR__ . '/GitHubClient.php';
require_once __DIR__ . '/UserDatabaseService.php';

class PMAgent {
    private int $memberId;
    private ?string $cloudId;
    private ?ClaudeClient $claude = null;
    private ?JiraClient $jira = null;
    private ?GitHubClient $github = null;
    private ?array $githubRepo = null;  // ['owner' => '', 'repo' => '']
    private $logger;

    /**
     * System prompt for the PM Agent
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a PM agent responsible for creating detailed user stories from epics.

Your role is to break down epics into implementable user stories with clear acceptance criteria.

When creating stories, you must:
1. Write stories in user story format: "As a [user], I want [goal] so that [benefit]"
2. Include detailed acceptance criteria using Given/When/Then format
3. Estimate story points (1, 2, 3, 5, 8, 13 - Fibonacci)
4. Identify dependencies between stories
5. Consider edge cases and error handling
6. Keep stories small enough to complete in 1-3 days

IMPORTANT: You must respond with valid JSON only. No markdown, no explanations outside the JSON.

JSON Response Schema:
{
  "stories": [
    {
      "title": "User story title (As a... I want... So that...)",
      "description": "Detailed description of what needs to be implemented",
      "acceptance_criteria": [
        {
          "given": "Starting condition",
          "when": "Action taken",
          "then": "Expected result"
        }
      ],
      "story_points": 3,
      "priority": "high|medium|low",
      "depends_on": [],
      "labels": ["frontend", "backend", "database", "api", "ui"],
      "technical_notes": "Optional implementation hints"
    }
  ],
  "epic_notes": "Any clarifications or concerns about the epic"
}
PROMPT;

    /**
     * @param int $memberId Member ID
     * @param string|null $cloudId Jira Cloud ID for API calls (null if not using Jira)
     * @param array|null $githubConfig GitHub config ['access_token' => '', 'owner' => '', 'repo' => '']
     */
    public function __construct(int $memberId, ?string $cloudId = null, ?array $githubConfig = null) {
        $this->memberId = $memberId;
        $this->cloudId = $cloudId;
        $this->logger = Flight::get('log');

        // Initialize GitHub if config provided
        if ($githubConfig && !empty($githubConfig['access_token'])) {
            $this->github = new GitHubClient($githubConfig['access_token']);
            $this->githubRepo = [
                'owner' => $githubConfig['owner'] ?? '',
                'repo' => $githubConfig['repo'] ?? ''
            ];
        }
    }

    /**
     * Initialize Claude client (lazy loading)
     */
    private function getClaude(): ClaudeClient {
        if (!$this->claude) {
            $this->claude = new ClaudeClient();
        }
        return $this->claude;
    }

    /**
     * Initialize Jira client (lazy loading)
     */
    private function getJira(): JiraClient {
        if (!$this->jira) {
            $this->jira = new JiraClient($this->memberId, $this->cloudId);
        }
        return $this->jira;
    }

    /**
     * Create stories from an epic
     *
     * @param object $epic The ctoepics bean
     * @param array $options Options:
     *   - require_approval: If true, stories go to pending_review status (default: true)
     *   - create_jira_issues: Whether to create Jira issues (default: false if no cloudId)
     *   - create_github_issues: Whether to create GitHub issues (default: false)
     *   - project_key: Jira project key (required if create_jira_issues is true)
     *   - epic_key: Jira epic key to link stories to (optional)
     *   - github_labels: Additional labels for GitHub issues (optional)
     * @return array Result with stories or error
     */
    public function createStories(object $epic, array $options = []): array {
        // Default to requiring approval - stories won't create issues until approved
        $requireApproval = $options['require_approval'] ?? true;

        // If approval is required, don't auto-create issues
        $createJiraIssues = $requireApproval ? false : ($options['create_jira_issues'] ?? ($this->cloudId !== null));
        $createGitHubIssues = $requireApproval ? false : ($options['create_github_issues'] ?? ($this->github !== null));
        $projectKey = $options['project_key'] ?? null;
        $jiraEpicKey = $options['epic_key'] ?? null;
        $githubLabels = $options['github_labels'] ?? ['ctobot-generated'];

        $this->logger->info('PMAgent: Creating stories from epic', [
            'epic_uid' => $epic->epic_uid,
            'title' => $epic->title,
            'require_approval' => $requireApproval,
            'create_jira' => $createJiraIssues,
            'create_github' => $createGitHubIssues
        ]);

        // Build user message from epic
        $userMessage = $this->buildUserMessage($epic);

        try {
            // Call Claude for story breakdown
            $response = $this->getClaude()->chat(
                self::SYSTEM_PROMPT,
                $userMessage,
                8192
            );

            // Parse JSON response
            $parsed = $this->parseResponse($response);

            if (!$parsed || empty($parsed['stories'])) {
                $this->logger->error('PMAgent: Failed to parse response or no stories', [
                    'epic_uid' => $epic->epic_uid,
                    'response_preview' => substr($response, 0, 500)
                ]);
                return [
                    'success' => false,
                    'error' => 'Failed to parse PM agent response',
                    'raw_response' => $response
                ];
            }

            // Update epic status
            $epic->status = 'in_progress';
            $epic->story_count = count($parsed['stories']);
            $epic->updated_at = date('Y-m-d H:i:s');
            Bean::store($epic);

            // Create stories in database
            $createdStories = [];
            $storyDependencies = []; // Track for second pass

            foreach ($parsed['stories'] as $index => $storyData) {
                $story = $this->createStory($epic, $storyData, $index, $requireApproval);
                $createdStories[] = $story;

                // Track dependencies for later resolution
                if (!empty($storyData['depends_on'])) {
                    $storyDependencies[$story->story_uid] = $storyData['depends_on'];
                }

                // Create Jira issue if requested
                if ($createJiraIssues && $projectKey) {
                    $this->createJiraIssue($story, $projectKey, $jiraEpicKey);
                }

                // Create GitHub issue if requested
                if ($createGitHubIssues && $this->github && $this->githubRepo) {
                    $this->createGitHubIssue($story, $epic, $githubLabels);
                }
            }

            // Resolve dependencies (convert story titles to story_ids)
            $this->resolveDependencies($createdStories, $storyDependencies);

            $this->logger->info('PMAgent: Stories created', [
                'epic_uid' => $epic->epic_uid,
                'story_count' => count($createdStories)
            ]);

            return [
                'success' => true,
                'stories' => $createdStories,
                'epic_notes' => $parsed['epic_notes'] ?? null
            ];

        } catch (\Exception $e) {
            $this->logger->error('PMAgent: Exception during story creation', [
                'epic_uid' => $epic->epic_uid,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Build user message from epic
     */
    private function buildUserMessage(object $epic): string {
        $message = "# Epic to Break Down\n\n";
        $message .= "**Title:** " . $epic->title . "\n\n";
        $message .= "**Description:** " . ($epic->description ?: '(No description)') . "\n\n";

        // Include existing acceptance criteria if any
        $criteria = json_decode($epic->acceptance_criteria, true);
        if (!empty($criteria)) {
            $message .= "**Epic Acceptance Criteria:**\n";
            foreach ($criteria as $criterion) {
                $message .= "- " . $criterion . "\n";
            }
            $message .= "\n";
        }

        $message .= "---\n\n";
        $message .= "Break this epic into user stories with detailed acceptance criteria.\n";
        $message .= "Each story should be small enough to complete in 1-3 days.";

        return $message;
    }

    /**
     * Parse JSON response from Claude
     */
    private function parseResponse(string $response): ?array {
        // Try to extract JSON from response
        $jsonStart = strpos($response, '{');
        $jsonEnd = strrpos($response, '}');

        if ($jsonStart === false || $jsonEnd === false) {
            return null;
        }

        $jsonStr = substr($response, $jsonStart, $jsonEnd - $jsonStart + 1);
        $parsed = json_decode($jsonStr, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('PMAgent: JSON parse error', [
                'error' => json_last_error_msg()
            ]);
            return null;
        }

        return $parsed;
    }

    /**
     * Create a story in the database
     */
    private function createStory(object $epic, array $storyData, int $sequence, bool $requireApproval = true): object {
        $storyId = bin2hex(random_bytes(16));

        $story = Bean::dispense('ctostories');
        $story->story_uid = $storyId;
        $story->ctoepics = $epic;  // Association creates ctoepics_id FK
        $story->title = $storyData['title'];
        $story->description = $storyData['description'] ?? '';
        $story->acceptance_criteria = json_encode($storyData['acceptance_criteria'] ?? []);
        $story->story_points = $storyData['story_points'] ?? 3;
        // Stories go to pending_review by default, requiring approval before issue creation
        $story->status = $requireApproval ? 'pending_review' : 'backlog';
        $story->sequence = $sequence;
        $story->created_at = date('Y-m-d H:i:s');

        Bean::store($story);

        $this->logger->debug('PMAgent: Story created', [
            'story_id' => $storyId,
            'title' => substr($story->title, 0, 50)
        ]);

        return $story;
    }

    /**
     * Create a Jira issue for a story
     */
    private function createJiraIssue(object $story, string $projectKey, ?string $epicKey = null): void {
        try {
            $jira = $this->getJira();

            // Format acceptance criteria for Jira description
            $description = $story->description . "\n\n";
            $description .= "*Acceptance Criteria:*\n\n";

            $criteria = json_decode($story->acceptance_criteria, true);
            if (!empty($criteria)) {
                foreach ($criteria as $i => $ac) {
                    $description .= "*Scenario " . ($i + 1) . ":*\n";
                    $description .= "* *Given* " . ($ac['given'] ?? 'N/A') . "\n";
                    $description .= "* *When* " . ($ac['when'] ?? 'N/A') . "\n";
                    $description .= "* *Then* " . ($ac['then'] ?? 'N/A') . "\n\n";
                }
            }

            // Create the issue
            $issueData = [
                'fields' => [
                    'project' => ['key' => $projectKey],
                    'summary' => $story->title,
                    'description' => [
                        'type' => 'doc',
                        'version' => 1,
                        'content' => [
                            [
                                'type' => 'paragraph',
                                'content' => [
                                    ['type' => 'text', 'text' => $description]
                                ]
                            ]
                        ]
                    ],
                    'issuetype' => ['name' => 'Story'],
                    'labels' => ['ctobot-generated']
                ]
            ];

            // Add story points if available
            // Note: Custom field ID varies by Jira instance
            // $issueData['fields']['customfield_10016'] = $story->story_points;

            // Link to epic if provided
            if ($epicKey) {
                // Note: Epic link field varies by Jira instance
                // $issueData['fields']['customfield_10014'] = $epicKey;
            }

            $result = $jira->createIssue($issueData);

            if ($result && isset($result['key'])) {
                $story->jira_issue_key = $result['key'];
                $story->jira_issue_eid = $result['id'] ?? null;
                Bean::store($story);

                $this->logger->info('PMAgent: Jira issue created', [
                    'story_id' => $story->story_uid,
                    'jira_key' => $result['key']
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('PMAgent: Failed to create Jira issue', [
                'story_id' => $story->story_uid,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create a GitHub issue for a story
     */
    private function createGitHubIssue(object $story, object $epic, array $labels = []): void {
        if (!$this->github || !$this->githubRepo) {
            return;
        }

        try {
            $owner = $this->githubRepo['owner'];
            $repo = $this->githubRepo['repo'];

            // Format acceptance criteria for GitHub markdown
            $body = "## Description\n\n";
            $body .= $story->description . "\n\n";
            $body .= "## Acceptance Criteria\n\n";

            $criteria = json_decode($story->acceptance_criteria, true);
            if (!empty($criteria)) {
                foreach ($criteria as $i => $ac) {
                    $body .= "### Scenario " . ($i + 1) . "\n";
                    $body .= "- **Given** " . ($ac['given'] ?? 'N/A') . "\n";
                    $body .= "- **When** " . ($ac['when'] ?? 'N/A') . "\n";
                    $body .= "- **Then** " . ($ac['then'] ?? 'N/A') . "\n\n";
                }
            }

            // Add metadata
            $body .= "---\n\n";
            $body .= "**Story Points:** " . ($story->story_points ?? 'TBD') . "\n";
            $body .= "**Epic:** " . $epic->title . "\n";
            $body .= "**Story ID:** `" . $story->story_uid . "`\n";

            // Merge default label with any additional labels
            $allLabels = array_unique(array_merge(['ctobot-generated'], $labels));

            // Create the issue
            $result = $this->github->createIssue(
                $owner,
                $repo,
                $story->title,
                $body,
                $allLabels
            );

            if ($result && isset($result['number'])) {
                // Store GitHub issue reference (using jira fields for now, could add github-specific fields)
                $story->jira_issue_key = $owner . '/' . $repo . '#' . $result['number'];
                $story->jira_issue_eid = (string) $result['id'];
                Bean::store($story);

                $this->logger->info('PMAgent: GitHub issue created', [
                    'story_id' => $story->story_uid,
                    'github_issue' => $result['number'],
                    'url' => $result['html_url'] ?? ''
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('PMAgent: Failed to create GitHub issue', [
                'story_id' => $story->story_uid,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Resolve dependencies between stories
     */
    private function resolveDependencies(array $stories, array $dependencies): void {
        // Build title -> story_id map
        $titleMap = [];
        foreach ($stories as $story) {
            // Normalize title for matching
            $normalizedTitle = strtolower(trim($story->title));
            $titleMap[$normalizedTitle] = $story->story_uid;
        }

        // Resolve dependencies
        foreach ($dependencies as $storyId => $dependsOnTitles) {
            $resolvedIds = [];
            foreach ($dependsOnTitles as $title) {
                $normalized = strtolower(trim($title));
                if (isset($titleMap[$normalized])) {
                    $resolvedIds[] = $titleMap[$normalized];
                }
            }

            if (!empty($resolvedIds)) {
                $story = Bean::findOne('ctostories', 'story_uid = ?', [$storyId]);
                if ($story) {
                    $story->depends_on = json_encode($resolvedIds);
                    Bean::store($story);
                }
            }
        }
    }

    /**
     * Create stories for all epics in a project
     */
    public function createStoriesForProject(object $project, array $options = []): array {
        $results = [];

        foreach ($project->with(' ORDER BY sequence ASC ')->ownCtoepicsList as $epic) {
            $result = $this->createStories($epic, $options);
            $results[$epic->epic_uid] = $result;
        }

        return $results;
    }

    /**
     * Estimate story points for a story description
     */
    public function estimateStoryPoints(string $storyDescription): int {
        $prompt = <<<PROMPT
Estimate the story points for this user story using Fibonacci sequence (1, 2, 3, 5, 8, 13).

Story:
{$storyDescription}

Consider:
- Complexity
- Uncertainty
- Effort required

Respond with JSON only: {"points": 3, "reasoning": "Brief explanation"}
PROMPT;

        try {
            $response = $this->getClaude()->chat(
                "You are a PM estimating story points. Respond with valid JSON only.",
                $prompt,
                512
            );

            $parsed = $this->parseResponse($response);
            return $parsed['points'] ?? 3;
        } catch (\Exception $e) {
            return 3; // Default to medium
        }
    }
}
