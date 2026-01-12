<?php
/**
 * Review Board Controller
 *
 * Provides UI for reviewing, editing, and approving stories before
 * they become GitHub/Jira issues.
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;
use \app\services\UserDatabaseService;
use \app\services\PMAgent;
use \app\services\GitHubClient;
use \app\services\JiraClient;
use \app\services\EncryptionService;

require_once __DIR__ . '/../lib/Bean.php';
require_once __DIR__ . '/../services/UserDatabaseService.php';
require_once __DIR__ . '/../services/PMAgent.php';
require_once __DIR__ . '/../services/GitHubClient.php';
require_once __DIR__ . '/../services/EncryptionService.php';

class ReviewBoard extends BaseControls\Control {

    private bool $userDbConnected = false;

    /**
     * Initialize user database connection
     * Note: UserDatabaseService is now a legacy no-op layer - all data is in single MySQL DB per tenant
     */
    private function initUserDb(): bool {
        if (!$this->userDbConnected && $this->member) {
            try {
                UserDatabaseService::connect($this->member->id);
                $this->userDbConnected = true;
            } catch (\Exception $e) {
                $this->logger->error('Failed to initialize user database: ' . $e->getMessage());
                return false;
            }
        }
        return $this->userDbConnected;
    }

    /**
     * Main review board - shows all pending stories grouped by directive/project
     */
    public function index() {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->flash('error', 'User database not initialized');
            Flight::redirect('/settings/connections');
            return;
        }

        // Get all projects with pending stories
        $projects = Bean::find('ctoprojects', 'status IN (?, ?) ORDER BY created_at DESC', ['planning', 'in_progress']);

        $projectData = [];
        foreach ($projects as $project) {
            // Get directive for this project
            $directive = $project->directive_id
                ? Bean::findOne('ceodirectives', 'id = ?', [$project->directive_id])
                : null;

            // Get epics for this project
            $epics = Bean::find('ctoepics', 'project_id = ? ORDER BY sequence ASC', [$project->id]);

            $epicData = [];
            $pendingCount = 0;
            $approvedCount = 0;

            foreach ($epics as $epic) {
                // Get stories for this epic
                $stories = Bean::find('ctostories', 'epic_id = ? ORDER BY sequence ASC', [$epic->id]);

                $storyData = [];
                foreach ($stories as $story) {
                    if ($story->status === 'pending_review') {
                        $pendingCount++;
                    } elseif ($story->status === 'approved') {
                        $approvedCount++;
                    }
                    $storyData[] = $story;
                }

                $epicData[] = [
                    'epic' => $epic,
                    'stories' => $storyData,
                    'pending_count' => count(array_filter($storyData, fn($s) => $s->status === 'pending_review')),
                ];
            }

            $projectData[] = [
                'project' => $project,
                'directive' => $directive,
                'epics' => $epicData,
                'pending_count' => $pendingCount,
                'approved_count' => $approvedCount,
            ];
        }

        // Summary stats
        $totalPending = Bean::count('ctostories', 'status = ?', ['pending_review']);
        $totalApproved = Bean::count('ctostories', 'status = ?', ['approved']);

        $this->render('reviewboard/index', [
            'projects' => $projectData,
            'totalPending' => $totalPending,
            'totalApproved' => $totalApproved,
        ]);
    }

    /**
     * View a single project's stories for review
     */
    public function project($projectId) {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            Flight::jsonError('User database not initialized', 500);
            return;
        }

        $project = Bean::findOne('ctoprojects', 'project_id = ?', [$projectId]);
        if (!$project) {
            $this->flash('error', 'Project not found');
            Flight::redirect('/reviewboard');
            return;
        }

        // Get directive
        $directive = $project->directive_id
            ? Bean::findOne('ceodirectives', 'id = ?', [$project->directive_id])
            : null;

        // Get epics with stories
        $epics = Bean::find('ctoepics', 'project_id = ? ORDER BY sequence ASC', [$project->id]);

        $epicData = [];
        foreach ($epics as $epic) {
            $stories = Bean::find('ctostories', 'epic_id = ? ORDER BY sequence ASC', [$epic->id]);
            $epicData[] = [
                'epic' => $epic,
                'stories' => $stories,
            ];
        }

        $this->render('reviewboard/project', [
            'project' => $project,
            'directive' => $directive,
            'epics' => $epicData,
        ]);
    }

    /**
     * AJAX: Update a story
     */
    public function updateStory() {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            Flight::jsonError('User database not initialized', 500);
            return;
        }

        $storyId = $this->getParam('story_id');
        $title = $this->getParam('title');
        $description = $this->getParam('description');
        $acceptanceCriteria = $this->getParam('acceptance_criteria');
        $storyPoints = $this->getParam('story_points');

        $story = Bean::findOne('ctostories', 'story_id = ?', [$storyId]);
        if (!$story) {
            Flight::jsonError('Story not found', 404);
            return;
        }

        // Update fields if provided
        if ($title !== null) {
            $story->title = $title;
        }
        if ($description !== null) {
            $story->description = $description;
        }
        if ($acceptanceCriteria !== null) {
            $story->acceptance_criteria = is_array($acceptanceCriteria)
                ? json_encode($acceptanceCriteria)
                : $acceptanceCriteria;
        }
        if ($storyPoints !== null) {
            $story->story_points = (int)$storyPoints;
        }

        $story->updated_at = date('Y-m-d H:i:s');
        Bean::store($story);

        Flight::jsonSuccess(['story' => $this->storyToArray($story)], 'Story updated');
    }

    /**
     * AJAX: Delete a story
     */
    public function deleteStory() {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            Flight::jsonError('User database not initialized', 500);
            return;
        }

        $storyId = $this->getParam('story_id');

        $story = Bean::findOne('ctostories', 'story_id = ?', [$storyId]);
        if (!$story) {
            Flight::jsonError('Story not found', 404);
            return;
        }

        // Only allow deleting pending_review stories
        if ($story->status !== 'pending_review') {
            Flight::jsonError('Can only delete stories pending review', 400);
            return;
        }

        // Update epic story count
        $epic = Bean::load('ctoepics', $story->epic_id);
        if ($epic && $epic->story_count > 0) {
            $epic->story_count--;
            Bean::store($epic);
        }

        Bean::trash($story);

        Flight::jsonSuccess(null, 'Story deleted');
    }

    /**
     * AJAX: Approve stories and create issues
     */
    public function approveStories() {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            Flight::jsonError('User database not initialized', 500);
            return;
        }

        $storyIds = $this->getParam('story_ids');
        if (!is_array($storyIds) || empty($storyIds)) {
            Flight::jsonError('No stories provided', 400);
            return;
        }

        // Get GitHub/Jira configuration
        $githubConfig = $this->getGitHubConfig();
        $jiraConfig = $this->getJiraConfig();

        if (!$githubConfig && !$jiraConfig) {
            Flight::jsonError('No GitHub or Jira configuration found', 400);
            return;
        }

        $results = [
            'approved' => 0,
            'issues_created' => 0,
            'errors' => [],
        ];

        foreach ($storyIds as $storyId) {
            $story = Bean::findOne('ctostories', 'story_id = ?', [$storyId]);
            if (!$story) {
                $results['errors'][] = "Story not found: {$storyId}";
                continue;
            }

            if ($story->status !== 'pending_review') {
                $results['errors'][] = "Story already processed: {$story->title}";
                continue;
            }

            try {
                // Get epic for labels/context
                $epic = Bean::load('ctoepics', $story->epic_id);

                // Create GitHub issue if configured
                if ($githubConfig) {
                    $issueKey = $this->createGitHubIssue($story, $epic, $githubConfig);
                    if ($issueKey) {
                        $story->jira_issue_key = $issueKey; // Using same field for GitHub
                        $results['issues_created']++;
                    }
                }
                // Create Jira issue if configured (and no GitHub)
                elseif ($jiraConfig) {
                    $issueKey = $this->createJiraIssue($story, $epic, $jiraConfig);
                    if ($issueKey) {
                        $story->jira_issue_key = $issueKey;
                        $results['issues_created']++;
                    }
                }

                // Mark as approved
                $story->status = 'approved';
                $story->updated_at = date('Y-m-d H:i:s');
                Bean::store($story);

                $results['approved']++;

            } catch (\Exception $e) {
                $results['errors'][] = "Error processing {$story->title}: " . $e->getMessage();
                $this->logger->error('Error approving story', [
                    'story_id' => $storyId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Flight::jsonSuccess($results, "{$results['approved']} stories approved, {$results['issues_created']} issues created");
    }

    /**
     * AJAX: Approve all pending stories for a project
     */
    public function approveProject() {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            Flight::jsonError('User database not initialized', 500);
            return;
        }

        $projectId = $this->getParam('project_id');

        $project = Bean::findOne('ctoprojects', 'project_id = ?', [$projectId]);
        if (!$project) {
            Flight::jsonError('Project not found', 404);
            return;
        }

        // Get all pending stories for this project
        $epics = Bean::find('ctoepics', 'project_id = ?', [$project->id]);
        $storyIds = [];

        foreach ($epics as $epic) {
            $stories = Bean::find('ctostories', 'epic_id = ? AND status = ?', [$epic->id, 'pending_review']);
            foreach ($stories as $story) {
                $storyIds[] = $story->story_id;
            }
        }

        if (empty($storyIds)) {
            Flight::jsonSuccess(['approved' => 0], 'No pending stories to approve');
            return;
        }

        // Reuse the approveStories logic
        $_POST['story_ids'] = $storyIds;
        $this->approveStories();
    }

    /**
     * AJAX: Delete all pending stories for a project
     */
    public function deleteProject() {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            Flight::jsonError('User database not initialized', 500);
            return;
        }

        $projectId = $this->getParam('project_id');

        $project = Bean::findOne('ctoprojects', 'project_id = ?', [$projectId]);
        if (!$project) {
            Flight::jsonError('Project not found', 404);
            return;
        }

        $deleted = 0;

        // Delete all pending stories for this project
        $epics = Bean::find('ctoepics', 'project_id = ?', [$project->id]);

        foreach ($epics as $epic) {
            $stories = Bean::find('ctostories', 'epic_id = ? AND status = ?', [$epic->id, 'pending_review']);
            foreach ($stories as $story) {
                Bean::trash($story);
                $deleted++;
            }

            // Update epic count
            $remaining = Bean::count('ctostories', 'epic_id = ?', [$epic->id]);
            $epic->story_count = $remaining;
            Bean::store($epic);

            // Delete epic if no stories remain
            if ($remaining === 0) {
                Bean::trash($epic);
            }
        }

        // Check if project should be deleted
        $remainingEpics = Bean::count('ctoepics', 'project_id = ?', [$project->id]);
        if ($remainingEpics === 0) {
            $project->status = 'cancelled';
            Bean::store($project);
        }

        Flight::jsonSuccess(['deleted' => $deleted], "{$deleted} stories deleted");
    }

    // ==================== Helper Methods ====================

    /**
     * Get GitHub configuration for the current user
     */
    private function getGitHubConfig(): ?array {
        // Check for repo connections
        $repo = Bean::findOne('repoconnections', 'member_id = ? AND provider = ?', [$this->member->id, 'github']);
        if (!$repo) {
            return null;
        }

        $encryption = new EncryptionService();
        $accessToken = $encryption->decrypt($repo->access_token);

        // Parse owner/repo from full name
        $parts = explode('/', $repo->repo_full_name);
        if (count($parts) !== 2) {
            return null;
        }

        return [
            'access_token' => $accessToken,
            'owner' => $parts[0],
            'repo' => $parts[1],
            'repo_id' => $repo->id,
        ];
    }

    /**
     * Get Jira configuration for the current user
     */
    private function getJiraConfig(): ?array {
        // Check for enabled Jira board
        $board = Bean::findOne('jiraboards', 'member_id = ? AND enabled = ?', [$this->member->id, 1]);
        if (!$board) {
            return null;
        }

        return [
            'cloud_id' => $board->cloud_id,
            'project_key' => $board->project_key,
            'board_id' => $board->id,
        ];
    }

    /**
     * Create a GitHub issue for a story
     */
    private function createGitHubIssue(object $story, ?object $epic, array $config): ?string {
        $github = new GitHubClient($config['access_token']);

        // Build issue body
        $body = $story->description . "\n\n";

        $criteria = json_decode($story->acceptance_criteria, true);
        if (!empty($criteria)) {
            $body .= "## Acceptance Criteria\n\n";
            foreach ($criteria as $criterion) {
                $body .= "- [ ] {$criterion}\n";
            }
            $body .= "\n";
        }

        if ($story->story_points) {
            $body .= "**Story Points:** {$story->story_points}\n";
        }

        if ($epic) {
            $body .= "**Epic:** {$epic->title}\n";
        }

        // Labels
        $labels = ['ctobot-generated', 'ai-dev'];
        if ($epic) {
            // Sanitize epic title for label
            $epicLabel = preg_replace('/[^a-zA-Z0-9-]/', '-', strtolower($epic->title));
            $epicLabel = substr($epicLabel, 0, 50);
            $labels[] = "epic:{$epicLabel}";
        }

        $result = $github->createIssue(
            $config['owner'],
            $config['repo'],
            $story->title,
            $body,
            $labels
        );

        if ($result && isset($result['number'])) {
            $issueKey = "{$config['owner']}/{$config['repo']}#{$result['number']}";

            $this->logger->info('GitHub issue created', [
                'story_id' => $story->story_id,
                'issue' => $issueKey,
            ]);

            return $issueKey;
        }

        return null;
    }

    /**
     * Create a Jira issue for a story
     */
    private function createJiraIssue(object $story, ?object $epic, array $config): ?string {
        // Note: Would need JiraClient implementation
        // For now, return null - Jira support can be added later
        $this->logger->warning('Jira issue creation not yet implemented');
        return null;
    }

    /**
     * Convert story bean to array for JSON response
     */
    private function storyToArray(object $story): array {
        return [
            'story_id' => $story->story_id,
            'title' => $story->title,
            'description' => $story->description,
            'acceptance_criteria' => json_decode($story->acceptance_criteria, true),
            'story_points' => $story->story_points,
            'status' => $story->status,
            'jira_issue_key' => $story->jira_issue_key,
            'created_at' => $story->created_at,
            'updated_at' => $story->updated_at,
        ];
    }
}
