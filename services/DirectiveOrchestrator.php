<?php
/**
 * Directive Orchestrator Service
 * The "heartbeat" loop that processes CEO directives through the autonomous CTO system
 *
 * Responsibilities:
 * - Process pending directives through CTO and PM agents
 * - Queue stories to dev agents
 * - Check progress and handle blockers
 * - Generate reports for completed items
 */

namespace app\services;

use \Flight as Flight;
use \app\Bean;

require_once __DIR__ . '/CTOAgent.php';
require_once __DIR__ . '/PMAgent.php';
require_once __DIR__ . '/UserDatabaseService.php';

class DirectiveOrchestrator {
    private int $memberId;
    private ?string $cloudId = null;
    private ?string $projectKey = null;
    private ?array $githubConfig = null;  // ['access_token', 'owner', 'repo']
    private $logger;

    /**
     * Configuration options
     */
    private array $config = [
        'max_concurrent_projects' => 3,
        'auto_start_stories' => true,
        'batch_size' => 5, // Stories to queue at a time
    ];

    public function __construct(int $memberId, array $config = []) {
        $this->memberId = $memberId;
        $this->logger = Flight::get('log');
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Set the Jira context for creating issues
     */
    public function setJiraContext(string $cloudId, string $projectKey): void {
        $this->cloudId = $cloudId;
        $this->projectKey = $projectKey;
    }

    /**
     * Set the GitHub context for creating issues
     */
    public function setGitHubContext(string $accessToken, string $owner, string $repo): void {
        $this->githubConfig = [
            'access_token' => $accessToken,
            'owner' => $owner,
            'repo' => $repo
        ];
    }

    /**
     * Main processing loop - call this from cron
     */
    public function processQueue(): array {
        $results = [
            'directives_processed' => 0,
            'projects_checked' => 0,
            'stories_queued' => 0,
            'blockers_handled' => 0,
            'errors' => []
        ];

        try {
            // 1. Process new directives
            $directiveResults = $this->processNewDirectives();
            $results['directives_processed'] = $directiveResults['processed'];
            $results['errors'] = array_merge($results['errors'], $directiveResults['errors']);

            // 2. Check in-progress projects and queue stories
            $projectResults = $this->checkProjects();
            $results['projects_checked'] = $projectResults['checked'];
            $results['stories_queued'] = $projectResults['stories_queued'];

            // 3. Handle blockers
            $blockerResults = $this->handleBlockers();
            $results['blockers_handled'] = $blockerResults['handled'];

        } catch (\Exception $e) {
            $this->logger->error('DirectiveOrchestrator: Fatal error in processQueue', [
                'error' => $e->getMessage()
            ]);
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Process new directives (status = 'received')
     */
    private function processNewDirectives(): array {
        $processed = 0;
        $errors = [];

        // Find pending directives
        $directives = Bean::find('ceodirectives',
            'status = ? ORDER BY created_at ASC',
            ['received']
        );

        foreach ($directives as $directive) {
            try {
                $this->processDirective($directive);
                $processed++;
            } catch (\Exception $e) {
                $this->logger->error('DirectiveOrchestrator: Error processing directive', [
                    'ceodirectives_id' => $directive->ceodirectives_id,
                    'error' => $e->getMessage()
                ]);
                $errors[] = "Directive {$directive->ceodirectives_id}: " . $e->getMessage();

                // Mark directive as failed
                $directive->status = 'failed';
                $directive->error_message = $e->getMessage();
                $directive->updated_at = date('Y-m-d H:i:s');
                Bean::store($directive);
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    /**
     * Process a single directive through CTO and PM agents
     */
    public function processDirective(object $directive): array {
        $this->logger->info('DirectiveOrchestrator: Processing directive', [
            'ceodirectives_id' => $directive->ceodirectives_id,
            'subject' => $directive->email_subject
        ]);

        // Update status
        $directive->status = 'parsing';
        $directive->current_phase = 'cto_analysis';
        $directive->updated_at = date('Y-m-d H:i:s');
        Bean::store($directive);

        $this->logDirective($directive->id, 'orchestration', 'info', 'Starting directive processing');

        // Phase 1: CTO Agent - Strategic planning
        $ctoAgent = new CTOAgent($this->memberId);
        $ctoResult = $ctoAgent->planProject($directive);

        if (!$ctoResult['success']) {
            throw new \Exception('CTO Agent failed: ' . ($ctoResult['error'] ?? 'Unknown error'));
        }

        $project = $ctoResult['project'];

        // Check if manual approval is needed
        if ($directive->approval_mode === 'manual') {
            $directive->status = 'planning';
            $directive->current_phase = 'awaiting_approval';
            $directive->updated_at = date('Y-m-d H:i:s');
            Bean::store($directive);

            $this->logDirective($directive->id, 'orchestration', 'info', 'Directive awaiting CEO approval');

            return [
                'success' => true,
                'status' => 'awaiting_approval',
                'project' => $project
            ];
        }

        // Phase 2: PM Agent - Tactical breakdown
        $hasJira = $this->cloudId && $this->projectKey;
        $hasGitHub = $this->githubConfig !== null;

        if ($project && ($hasJira || $hasGitHub)) {
            $directive->current_phase = 'pm_breakdown';
            Bean::store($directive);

            // Initialize PM Agent with available context
            $pmAgent = new PMAgent($this->memberId, $this->cloudId, $this->githubConfig);

            $pmOptions = [
                'create_jira_issues' => $hasJira,
                'create_github_issues' => $hasGitHub && !$hasJira, // Prefer Jira if both configured
            ];

            if ($hasJira) {
                $pmOptions['project_key'] = $this->projectKey;
            }

            $pmResult = $pmAgent->createStoriesForProject($project, $pmOptions);

            // Count total stories created
            $totalStories = 0;
            foreach ($pmResult as $epicResult) {
                if ($epicResult['success']) {
                    $totalStories += count($epicResult['stories'] ?? []);
                }
            }

            $issueType = $hasJira ? 'Jira' : 'GitHub';
            $this->logDirective($directive->id, 'orchestration', 'info',
                "PM breakdown complete: {$totalStories} stories created ({$issueType} issues)");
        } elseif ($project) {
            // No issue tracking configured - still create stories without external issues
            $directive->current_phase = 'pm_breakdown';
            Bean::store($directive);

            $pmAgent = new PMAgent($this->memberId, null, null);
            $pmResult = $pmAgent->createStoriesForProject($project, [
                'create_jira_issues' => false,
                'create_github_issues' => false,
            ]);

            $totalStories = 0;
            foreach ($pmResult as $epicResult) {
                if ($epicResult['success']) {
                    $totalStories += count($epicResult['stories'] ?? []);
                }
            }

            $this->logDirective($directive->id, 'orchestration', 'info',
                "PM breakdown complete: {$totalStories} stories created (no external issue tracker)");
        }

        // Phase 3: Start execution
        $directive->status = 'executing';
        $directive->current_phase = 'story_execution';
        $directive->updated_at = date('Y-m-d H:i:s');
        Bean::store($directive);

        // Queue first batch of stories to dev agents
        if ($this->config['auto_start_stories'] && $project) {
            $this->queueStoriesForProject($project);
        }

        $this->logDirective($directive->id, 'orchestration', 'info', 'Directive execution started');

        return [
            'success' => true,
            'status' => 'executing',
            'project' => $project
        ];
    }

    /**
     * Check in-progress projects and queue more stories
     */
    private function checkProjects(): array {
        $checked = 0;
        $storiesQueued = 0;

        // Find in-progress projects
        $projects = Bean::find('ctoprojects',
            'status = ? ORDER BY created_at ASC',
            ['in_progress']
        );

        foreach ($projects as $project) {
            $checked++;

            // Check if we can queue more stories
            $runningStories = Bean::count('ctostories',
                'ctoepics_id IN (SELECT id FROM ctoepics WHERE ctoprojects_id = ?) AND status = ?',
                [$project->id, 'in_progress']
            );

            if ($runningStories < $this->config['batch_size']) {
                $queued = $this->queueStoriesForProject($project);
                $storiesQueued += $queued;
            }

            // Update project completion percentage
            $this->updateProjectCompletion($project);
        }

        return ['checked' => $checked, 'stories_queued' => $storiesQueued];
    }

    /**
     * Queue stories from a project to dev agents
     */
    private function queueStoriesForProject(object $project): int {
        $queued = 0;
        $batchSize = $this->config['batch_size'];

        // Find ready stories that aren't blocked
        $stories = Bean::find('ctostories',
            'ctoepics_id IN (SELECT id FROM ctoepics WHERE ctoprojects_id = ?)
             AND status = ?
             AND (depends_on IS NULL OR depends_on = "[]")
             ORDER BY sequence ASC
             LIMIT ?',
            [$project->id, 'backlog', $batchSize]
        );

        foreach ($stories as $story) {
            // Check if dependencies are met
            if (!$this->areDependenciesMet($story)) {
                continue;
            }

            // Queue to dev agent
            $jobId = $this->queueStoryToDevAgent($story);

            if ($jobId) {
                $story->status = 'ready';
                $story->aidev_job_uid = $jobId;
                $story->updated_at = date('Y-m-d H:i:s');
                Bean::store($story);
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * Check if a story's dependencies are met
     */
    private function areDependenciesMet(object $story): bool {
        if (empty($story->depends_on)) {
            return true;
        }

        $dependencies = json_decode($story->depends_on, true);
        if (empty($dependencies)) {
            return true;
        }

        foreach ($dependencies as $depStoryId) {
            $depStory = Bean::findOne('ctostories', 'story_id = ?', [$depStoryId]);
            if ($depStory && $depStory->status !== 'done') {
                return false;
            }
        }

        return true;
    }

    /**
     * Queue a story to the dev agent system
     */
    private function queueStoryToDevAgent(object $story): ?string {
        // Check if story has an issue key (Jira or GitHub)
        if (empty($story->jira_issue_key)) {
            $this->logger->warning('DirectiveOrchestrator: Story has no issue reference', [
                'story_uid' => $story->story_uid
            ]);
            return null;
        }

        // Determine if this is a GitHub issue (format: owner/repo#123)
        $isGitHubIssue = strpos($story->jira_issue_key, '#') !== false;

        try {
            // Create an aidevjobs entry
            $jobId = 'directive-' . bin2hex(random_bytes(8));

            $job = Bean::dispense('aidevjobs');
            $job->job_uid = $jobId;
            $job->issue_key = $story->jira_issue_key;
            $job->member_id = $this->memberId;
            $job->status = 'pending';
            $job->agent_type = 'impl';
            $job->source = 'directive';
            $job->created_at = date('Y-m-d H:i:s');

            // Set context based on issue type
            if ($isGitHubIssue) {
                $job->cloud_uid = null;
                $job->boards_id = null;  // GitHub workflows don't have a board
                $job->issue_source = 'github';

                // Find repo connection for this GitHub repo
                if ($this->githubConfig) {
                    $repoFullName = $this->githubConfig['owner'] . '/' . $this->githubConfig['repo'];
                    $repoConn = Bean::findOne('repoconnections',
                        'repo_full_name = ? AND member_id = ?',
                        [$repoFullName, $this->memberId]
                    );
                    if ($repoConn) {
                        $job->repoconnections_id = $repoConn->id;
                    }
                }
            } else {
                $job->cloud_uid = $this->cloudId;
                $job->issue_source = 'jira';
                // board_id will be set by the processor when it picks up the job
            }

            Bean::store($job);

            $this->logger->info('DirectiveOrchestrator: Story queued to dev agent', [
                'story_uid' => $story->story_uid,
                'job_uid' => $jobId,
                'issue_key' => $story->jira_issue_key,
                'issue_source' => $isGitHubIssue ? 'github' : 'jira'
            ]);

            return $jobId;

        } catch (\Exception $e) {
            $this->logger->error('DirectiveOrchestrator: Failed to queue story', [
                'story_uid' => $story->story_uid,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Handle blocked stories
     */
    private function handleBlockers(): array {
        $handled = 0;

        // Find blocked stories
        $blockedStories = Bean::find('ctostories', 'status = ?', ['blocked']);

        foreach ($blockedStories as $story) {
            // Check if blocker is resolved (dependencies now met)
            if ($this->areDependenciesMet($story)) {
                $story->status = 'backlog';
                $story->blocker_reason = null;
                $story->updated_at = date('Y-m-d H:i:s');
                Bean::store($story);
                $handled++;
            }
        }

        return ['handled' => $handled];
    }

    /**
     * Update project completion percentage
     */
    private function updateProjectCompletion(object $project): void {
        // Count total and completed stories
        $totalStories = Bean::count('ctostories',
            'ctoepics_id IN (SELECT id FROM ctoepics WHERE ctoprojects_id = ?)',
            [$project->id]
        );

        $completedStories = Bean::count('ctostories',
            'ctoepics_id IN (SELECT id FROM ctoepics WHERE ctoprojects_id = ?) AND status = ?',
            [$project->id, 'done']
        );

        $percentage = $totalStories > 0
            ? round(($completedStories / $totalStories) * 100)
            : 0;

        $project->completion_percentage = $percentage;
        $project->updated_at = date('Y-m-d H:i:s');

        // Check if project is complete
        if ($percentage >= 100) {
            $project->status = 'completed';

            // Also complete the directive
            $directive = $project->ceodirectives;
            if ($directive && $directive->id) {
                $directive->status = 'completed';
                $directive->current_phase = 'complete';
                $directive->updated_at = date('Y-m-d H:i:s');
                Bean::store($directive);

                $this->logDirective($directive->id, 'completion', 'info',
                    'Directive completed: all stories done');
            }
        }

        Bean::store($project);
    }

    /**
     * Approve a directive that's waiting for manual approval
     */
    public function approveDirective(object $directive): array {
        if ($directive->status !== 'planning' || $directive->current_phase !== 'awaiting_approval') {
            return [
                'success' => false,
                'error' => 'Directive is not awaiting approval'
            ];
        }

        $project = Bean::load('ctoprojects', $directive->project_uid);
        if (!$project || !$project->id) {
            return [
                'success' => false,
                'error' => 'Project not found'
            ];
        }

        // Continue with PM breakdown
        $directive->current_phase = 'pm_breakdown';
        Bean::store($directive);

        $hasJira = $this->cloudId && $this->projectKey;
        $hasGitHub = $this->githubConfig !== null;

        if ($hasJira || $hasGitHub) {
            $pmAgent = new PMAgent($this->memberId, $this->cloudId, $this->githubConfig);
            $pmOptions = [
                'create_jira_issues' => $hasJira,
                'create_github_issues' => $hasGitHub && !$hasJira,
            ];
            if ($hasJira) {
                $pmOptions['project_key'] = $this->projectKey;
            }
            $pmResult = $pmAgent->createStoriesForProject($project, $pmOptions);
        }

        // Start execution
        $directive->status = 'executing';
        $directive->current_phase = 'story_execution';
        $directive->updated_at = date('Y-m-d H:i:s');
        Bean::store($directive);

        $project->status = 'in_progress';
        Bean::store($project);

        if ($this->config['auto_start_stories']) {
            $this->queueStoriesForProject($project);
        }

        $this->logDirective($directive->id, 'approval', 'info', 'Directive approved, execution started');

        return [
            'success' => true,
            'status' => 'executing'
        ];
    }

    /**
     * Log a directive processing event
     */
    private function logDirective(int $directiveId, string $phase, string $level, string $message, array $context = []): void {
        try {
            $log = Bean::dispense('directivelogs');
            $log->ceodirectives_id = $directiveId;
            $log->phase = $phase;
            $log->log_level = $level;
            $log->message = $message;
            $log->context_json = !empty($context) ? json_encode($context) : null;
            $log->created_at = date('Y-m-d H:i:s');
            Bean::store($log);
        } catch (\Exception $e) {
            $this->logger->error('Failed to log directive event', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
