<?php
/**
 * CTO Agent Service
 * Strategic planning agent that decomposes CEO directives into projects and epics
 *
 * Responsibilities:
 * - Parse high-level directive into structured requirements
 * - Identify project scope and boundaries
 * - Break into epics/milestones
 * - Estimate effort and identify risks
 * - Select appropriate Jira project and GitHub repo
 */

namespace app\services;

use \Flight as Flight;
use \app\Bean;

require_once __DIR__ . '/ClaudeClient.php';
require_once __DIR__ . '/UserDatabaseService.php';

class CTOAgent {
    private int $memberId;
    private ?ClaudeClient $claude = null;
    private $logger;

    /**
     * System prompt for the CTO Agent
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a CTO agent responsible for strategic planning and project decomposition.

Your role is to analyze directives from the CEO and break them down into actionable projects and epics.

When analyzing a directive, you must:
1. Identify the core intent (new project, feature, bug fix, question, or report request)
2. Extract requirements - both explicit and implied
3. Define project scope and boundaries
4. Break the work into 3-7 epics (major feature areas)
5. Estimate effort (t-shirt sizes: XS, S, M, L, XL)
6. Identify technical risks and dependencies
7. Suggest a technical approach

IMPORTANT: You must respond with valid JSON only. No markdown, no explanations outside the JSON.

JSON Response Schema:
{
  "intent": "project|feature|bug|question|report",
  "summary": "One paragraph summary of what's being requested",
  "project": {
    "name": "Short project name (2-4 words)",
    "description": "Detailed project description",
    "goals": ["Goal 1", "Goal 2", "Goal 3"],
    "estimated_effort": "XS|S|M|L|XL",
    "tech_stack": ["Technology 1", "Technology 2"],
    "risks": [
      {"risk": "Risk description", "severity": "low|medium|high", "mitigation": "How to mitigate"}
    ]
  },
  "epics": [
    {
      "title": "Epic title",
      "description": "What this epic covers",
      "priority": 1,
      "acceptance_criteria": ["Criterion 1", "Criterion 2"],
      "estimated_effort": "XS|S|M|L|XL"
    }
  ],
  "questions": ["Question if clarification needed"],
  "suggestions": ["Optional suggestions for the CEO"]
}
PROMPT;

    public function __construct(int $memberId) {
        $this->memberId = $memberId;
        $this->logger = Flight::get('log');
    }

    /**
     * Initialize Claude client (lazy loading)
     */
    private function getClaude(): ClaudeClient {
        if (!$this->claude) {
            // Try to get board-specific API key or use default
            $this->claude = new ClaudeClient();
        }
        return $this->claude;
    }

    /**
     * Plan a project from a CEO directive
     *
     * @param object $directive The ceodirectives bean
     * @return array Result with project details or error
     */
    public function planProject(object $directive): array {
        $this->logger->info('CTOAgent: Planning project from directive', [
            'directive_uid' => $directive->directive_uid,
            'subject' => $directive->email_subject
        ]);

        // Build user message from directive
        $userMessage = $this->buildUserMessage($directive);

        try {
            // Call Claude for analysis
            $response = $this->getClaude()->chat(
                self::SYSTEM_PROMPT,
                $userMessage,
                8192 // Allow longer response for detailed planning
            );

            // Parse JSON response
            $parsed = $this->parseResponse($response);

            if (!$parsed) {
                $this->logger->error('CTOAgent: Failed to parse response', [
                    'directive_uid' => $directive->directive_uid,
                    'response_preview' => substr($response, 0, 500)
                ]);
                return [
                    'success' => false,
                    'error' => 'Failed to parse CTO agent response',
                    'raw_response' => $response
                ];
            }

            // Update directive with parsed data
            $directive->parsed_intent = $parsed['intent'] ?? 'project';
            $directive->parsed_summary = $parsed['summary'] ?? '';
            $directive->parsed_requirements = json_encode($parsed['project']['goals'] ?? []);
            $directive->status = 'planning';
            $directive->current_phase = 'cto_analysis_complete';
            $directive->updated_at = date('Y-m-d H:i:s');
            Bean::store($directive);

            // Log the analysis
            $this->logDirective($directive->id, 'cto_analysis', 'info', 'CTO analysis complete', [
                'intent' => $parsed['intent'],
                'epic_count' => count($parsed['epics'] ?? []),
                'estimated_effort' => $parsed['project']['estimated_effort'] ?? 'unknown'
            ]);

            // Create project if we have enough info
            $project = null;
            if (!empty($parsed['project']['name'])) {
                $project = $this->createProject($directive, $parsed);
            }

            $this->logger->info('CTOAgent: Planning complete', [
                'directive_uid' => $directive->directive_uid,
                'intent' => $parsed['intent'],
                'project_uid' => $project ? $project->project_uid : null,
                'epic_count' => count($parsed['epics'] ?? [])
            ]);

            return [
                'success' => true,
                'parsed' => $parsed,
                'project' => $project,
                'has_questions' => !empty($parsed['questions'])
            ];

        } catch (\Exception $e) {
            $this->logger->error('CTOAgent: Exception during planning', [
                'directive_uid' => $directive->directive_uid,
                'error' => $e->getMessage()
            ]);

            // Update directive with error
            $directive->status = 'failed';
            $directive->error_message = 'CTO Agent error: ' . $e->getMessage();
            $directive->updated_at = date('Y-m-d H:i:s');
            Bean::store($directive);

            $this->logDirective($directive->id, 'cto_analysis', 'error', 'CTO analysis failed: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Build user message from directive
     */
    private function buildUserMessage(object $directive): string {
        $message = "# CEO Directive\n\n";
        $message .= "**Subject:** " . ($directive->email_subject ?: '(No Subject)') . "\n\n";
        $message .= "**From:** " . ($directive->email_from ?: 'Unknown') . "\n\n";
        $message .= "## Directive Content\n\n";
        $message .= $directive->email_body ?: '(No content)';
        $message .= "\n\n---\n\n";
        $message .= "Analyze this directive and provide a structured project plan.";

        return $message;
    }

    /**
     * Parse JSON response from Claude
     */
    private function parseResponse(string $response): ?array {
        // Try to extract JSON from response (in case there's extra text)
        $jsonStart = strpos($response, '{');
        $jsonEnd = strrpos($response, '}');

        if ($jsonStart === false || $jsonEnd === false) {
            return null;
        }

        $jsonStr = substr($response, $jsonStart, $jsonEnd - $jsonStart + 1);
        $parsed = json_decode($jsonStr, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('CTOAgent: JSON parse error', [
                'error' => json_last_error_msg(),
                'json_preview' => substr($jsonStr, 0, 200)
            ]);
            return null;
        }

        return $parsed;
    }

    /**
     * Create a project from parsed directive
     */
    private function createProject(object $directive, array $parsed): object {
        $projectData = $parsed['project'];

        // Generate unique project ID
        $projectId = bin2hex(random_bytes(16));

        // Create project
        $project = Bean::dispense('ctoprojects');
        $project->project_uid = $projectId;
        $project->directive_uid = $directive->id;
        $project->member_id = $this->memberId;
        $project->name = $projectData['name'];
        $project->description = $projectData['description'] ?? '';
        $project->goals = json_encode($projectData['goals'] ?? []);
        $project->estimated_effort = $projectData['estimated_effort'] ?? 'M';
        $project->risk_assessment = json_encode($projectData['risks'] ?? []);
        $project->tech_stack = json_encode($projectData['tech_stack'] ?? []);
        $project->status = 'planning';
        $project->completion_percentage = 0;
        $project->created_at = date('Y-m-d H:i:s');

        Bean::store($project);

        // Link project to directive
        $directive->project_uid = $project->id;
        Bean::store($directive);

        // Create epics
        if (!empty($parsed['epics'])) {
            $this->createEpics($project, $parsed['epics']);
        }

        $this->logger->info('CTOAgent: Project created', [
            'project_uid' => $projectId,
            'name' => $project->name,
            'epic_count' => count($parsed['epics'] ?? [])
        ]);

        return $project;
    }

    /**
     * Create epics for a project
     */
    private function createEpics(object $project, array $epics): void {
        $sequence = 0;
        foreach ($epics as $epicData) {
            $epicId = bin2hex(random_bytes(16));

            $epic = Bean::dispense('ctoepics');
            $epic->epic_id = $epicId;
            $epic->project_uid = $project->id;
            $epic->title = $epicData['title'];
            $epic->description = $epicData['description'] ?? '';
            $epic->acceptance_criteria = json_encode($epicData['acceptance_criteria'] ?? []);
            $epic->status = 'backlog';
            $epic->priority = $epicData['priority'] ?? $sequence;
            $epic->sequence = $sequence;
            $epic->story_count = 0;
            $epic->stories_completed = 0;
            $epic->created_at = date('Y-m-d H:i:s');

            Bean::store($epic);
            $sequence++;

            $this->logger->debug('CTOAgent: Epic created', [
                'epic_id' => $epicId,
                'title' => $epic->title,
                'project_uid' => $project->project_uid
            ]);
        }
    }

    /**
     * Get project recommendations based on existing context
     */
    public function getRecommendations(string $context): array {
        $prompt = <<<PROMPT
Based on the following context, provide recommendations for project planning:

{$context}

Respond with JSON:
{
  "recommendations": ["Recommendation 1", "Recommendation 2"],
  "potential_blockers": ["Blocker 1"],
  "suggested_approach": "Brief description of suggested approach"
}
PROMPT;

        try {
            $response = $this->getClaude()->chat(
                "You are a CTO providing project recommendations. Respond with valid JSON only.",
                $prompt,
                2048
            );

            return $this->parseResponse($response) ?? [
                'recommendations' => [],
                'potential_blockers' => [],
                'suggested_approach' => ''
            ];
        } catch (\Exception $e) {
            $this->logger->error('CTOAgent: Failed to get recommendations', [
                'error' => $e->getMessage()
            ]);
            return [
                'recommendations' => [],
                'potential_blockers' => [],
                'suggested_approach' => ''
            ];
        }
    }

    /**
     * Log a directive processing event
     */
    private function logDirective(int $directiveId, string $phase, string $level, string $message, array $context = []): void {
        try {
            $log = Bean::dispense('directivelogs');
            $log->directive_uid = $directiveId;
            $log->phase = $phase;
            $log->log_level = $level;
            $log->message = $message;
            $log->context_json = !empty($context) ? json_encode($context) : null;
            $log->created_at = date('Y-m-d H:i:s');
            Bean::store($log);
        } catch (\Exception $e) {
            $this->logger->error('Failed to log directive event', [
                'error' => $e->getMessage(),
                'directive_uid' => $directiveId
            ]);
        }
    }
}
