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
use \app\services\AIDevStatusService;
use \app\services\AIDevJobService;

require_once __DIR__ . '/../services/AIDevStatusService.php';
require_once __DIR__ . '/../services/AIDevJobService.php';
require_once __DIR__ . '/../services/UserDatabaseService.php';
require_once __DIR__ . '/../services/PMAgent.php';
require_once __DIR__ . '/../services/GitHubClient.php';
require_once __DIR__ . '/../services/EncryptionService.php';

class Reviewboard extends BaseControls\Control {

    /**
     * Main review board - shows all pending stories grouped by directive/project
     */
    public function index() {
        if (!$this->requireLogin()) return;

        // Auto-cleanup stale jobs (running in DB but no tmux session)
        $staleCleanup = AIDevJobService::cleanupStaleJobs();
        if ($staleCleanup['cleaned'] > 0) {
            $this->logger->info('Auto-cleaned stale jobs on Review Board load', [
                'found' => $staleCleanup['found'],
                'cleaned' => $staleCleanup['cleaned']
            ]);
        }

        // Get all active projects (planning, in_progress, and completed for visibility)
        $projects = Bean::find('ctoprojects', 'status IN (?, ?, ?) ORDER BY created_at DESC', ['planning', 'in_progress', 'completed']);

        $projectData = [];
        foreach ($projects as $project) {
            // Get directive for this project via association
            $directive = $project->ceodirectives;

            // Get epics for this project via association
            $epicData = [];
            $pendingCount = 0;
            $approvedCount = 0;

            foreach ($project->with(' ORDER BY sequence ASC ')->ownCtoepicsList as $epic) {
                // Get stories for this epic via association
                $stories = $epic->with(' ORDER BY sequence ASC ')->ownCtostoriesList;

                $storyData = [];
                foreach ($stories as $story) {
                    if ($story->status === 'pending_review') {
                        $pendingCount++;
                    } elseif ($story->status === 'approved') {
                        $approvedCount++;
                    }

                    // Attach job data for approved stories with issue keys
                    $story->_job = null;
                    if ($story->jira_issue_key) {
                        $jobs = AIDevStatusService::findAllJobsByIssueKey($this->member->id, $story->jira_issue_key);
                        if (!empty($jobs)) {
                            // Get the most recent job
                            $story->_job = $jobs[0];
                        }
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
        $totalInProgress = Bean::count('ctostories', 'status = ?', ['in_progress']);
        $totalDone = Bean::count('ctostories', 'status = ?', ['done']);
        $totalBlocked = Bean::count('ctostories', 'status = ?', ['blocked']);

        // Get knowledge bases for PM chatbox context
        $knowledgeBases = [];
        try {
            $kbs = Bean::find('knowledgebases', ' 1 ORDER BY name ASC ');
            foreach ($kbs as $kb) {
                $knowledgeBases[] = [
                    'id' => $kb->id,
                    'name' => $kb->name,
                    'slug' => $kb->slug,
                ];
            }
        } catch (\Exception $e) {
            // KB table may not exist yet
            $this->logger->debug('Could not load KBs for PM chat: ' . $e->getMessage());
        }

        $this->render('reviewboard/index', [
            'projects' => $projectData,
            'totalPending' => $totalPending,
            'totalApproved' => $totalApproved,
            'totalInProgress' => $totalInProgress,
            'totalDone' => $totalDone,
            'totalBlocked' => $totalBlocked,
            'knowledgeBases' => $knowledgeBases,
        ]);
    }

    /**
     * View merged/archived stories history
     */
    public function history() {
        if (!$this->requireLogin()) return;

        // Get all merged jobs
        $mergedJobs = Bean::find('aidevjobs', 'status = ? ORDER BY merged_at DESC', ['merged']);

        // Build merged stories data
        $mergedStories = [];
        foreach ($mergedJobs as $job) {
            // Find the story for this job
            $story = Bean::findOne('ctostories', 'jira_issue_key = ?', [$job->issue_key]);
            $project = null;
            $epic = null;

            if ($story) {
                // Navigate up via associations: story -> epic -> project
                $epic = $story->ctoepics;
                if ($epic && $epic->id) {
                    $project = $epic->ctoprojects;
                }
            }

            $mergedStories[] = [
                'job' => $job,
                'story' => $story,
                'project' => $project,
                'epic' => $epic,
            ];
        }

        $this->render('reviewboard/history', [
            'mergedStories' => $mergedStories,
        ]);
    }

    /**
     * View a single project's stories for review
     */
    public function project($projectId) {
        if (!$this->requireLogin()) return;

        // Auto-cleanup stale jobs (running in DB but no tmux session)
        AIDevJobService::cleanupStaleJobs();

        $project = Bean::findOne('ctoprojects', 'project_uid = ?', [$projectId]);
        if (!$project) {
            $this->flash('error', 'Project not found');
            Flight::redirect('/reviewboard');
            return;
        }

        // Get directive via association
        $directive = $project->ceodirectives;

        // Get epics with stories via associations
        $epicData = [];
        foreach ($project->with(' ORDER BY sequence ASC ')->ownCtoepicsList as $epic) {
            $epicData[] = [
                'epic' => $epic,
                'stories' => $epic->with(' ORDER BY sequence ASC ')->ownCtostoriesList,
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
    public function updatestory() {
        if (!$this->requireLogin()) return;

        $storyId = $this->getParam('story_id');
        $title = $this->getParam('title');
        $description = $this->getParam('description');
        $acceptanceCriteria = $this->getParam('acceptance_criteria');
        $storyPoints = $this->getParam('story_points');

        $story = Bean::findOne('ctostories', 'story_uid = ?', [$storyId]);
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
    public function deletestory() {
        if (!$this->requireLogin()) return;

        $storyId = $this->getParam('story_id');

        $story = Bean::findOne('ctostories', 'story_uid = ?', [$storyId]);
        if (!$story) {
            Flight::jsonError('Story not found', 404);
            return;
        }

        // Only allow deleting pending_review stories
        if ($story->status !== 'pending_review') {
            Flight::jsonError('Can only delete stories pending review', 400);
            return;
        }

        // Update epic story count via association
        $epic = $story->ctoepics;
        if ($epic && $epic->id && $epic->story_count > 0) {
            $epic->story_count--;
            Bean::store($epic);
        }

        Bean::trash($story);

        Flight::jsonSuccess(null, 'Story deleted');
    }

    /**
     * AJAX: Approve stories and create issues
     */
    public function approvestories() {
        if (!$this->requireLogin()) return;

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
            $story = Bean::findOne('ctostories', 'story_uid = ?', [$storyId]);
            if (!$story) {
                $results['errors'][] = "Story not found: {$storyId}";
                continue;
            }

            if ($story->status !== 'pending_review') {
                $results['errors'][] = "Story already processed: {$story->title}";
                continue;
            }

            try {
                // Get epic for labels/context via association
                $epic = $story->ctoepics;

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
                    'story_uid' => $storyId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Flight::jsonSuccess($results, "{$results['approved']} stories approved, {$results['issues_created']} issues created");
    }

    /**
     * AJAX: Approve all pending stories for a project
     */
    public function approveproject() {
        if (!$this->requireLogin()) return;

        $projectId = $this->getParam('project_uid');

        $project = Bean::findOne('ctoprojects', 'project_uid = ?', [$projectId]);
        if (!$project) {
            Flight::jsonError('Project not found', 404);
            return;
        }

        // Get all pending stories for this project via associations
        $storyIds = [];
        foreach ($project->ownCtoepicsList as $epic) {
            foreach ($epic->withCondition(' status = ? ', ['pending_review'])->ownCtostoriesList as $story) {
                $storyIds[] = $story->story_uid;
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
    public function deleteproject() {
        if (!$this->requireLogin()) return;

        $projectId = $this->getParam('project_uid');

        $project = Bean::findOne('ctoprojects', 'project_uid = ?', [$projectId]);
        if (!$project) {
            Flight::jsonError('Project not found', 404);
            return;
        }

        $deleted = 0;

        // Delete all pending stories for this project via associations
        foreach ($project->ownCtoepicsList as $epic) {
            foreach ($epic->withCondition(' status = ? ', ['pending_review'])->ownCtostoriesList as $story) {
                Bean::trash($story);
                $deleted++;
            }

            // Update epic count
            $epic->story_count = $epic->countOwn('ctostories');
            Bean::store($epic);

            // Delete epic if no stories remain
            if ($epic->story_count === 0) {
                Bean::trash($epic);
            }
        }

        // Check if project should be deleted
        if ($project->countOwn('ctoepics') === 0) {
            $project->status = 'cancelled';
            Bean::store($project);
        }

        Flight::jsonSuccess(['deleted' => $deleted], "{$deleted} stories deleted");
    }

    /**
     * AJAX: Get job details for a story
     */
    public function getjobdetails() {
        if (!$this->requireLogin()) return;

        $issueKey = $this->getParam('issue_key');
        if (!$issueKey) {
            Flight::jsonError('Issue key required', 400);
            return;
        }

        $jobs = AIDevStatusService::findAllJobsByIssueKey($this->member->id, $issueKey);
        if (empty($jobs)) {
            Flight::jsonError('No jobs found for this issue', 404);
            return;
        }

        // Get the most recent job with full details
        $job = $jobs[0];

        // Get logs for this job
        $logs = AIDevStatusService::getLogs($job['job_uid'], $this->member->id);

        Flight::jsonSuccess([
            'job' => $job,
            'logs' => $logs,
            'all_jobs' => $jobs,
        ]);
    }

    // ==================== Helper Methods ====================

    /**
     * Get GitHub configuration for the current user
     */
    private function getGitHubConfig(): ?array {
        // Check for repo connections (workspace-level - shared by all members)
        $repo = Bean::findOne('repoconnections', 'provider = ? AND enabled = ?', ['github', 1]);
        if (!$repo) {
            return null;
        }

        // Get token from enterprisesettings
        $tokenSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);
        if (!$tokenSetting || !$tokenSetting->setting_value) {
            return null;
        }

        $encryption = new EncryptionService();
        $accessToken = $encryption->decrypt($tokenSetting->setting_value);

        return [
            'access_token' => $accessToken,
            'owner' => $repo->repo_owner,
            'repo' => $repo->repo_name,
            'repo_id' => $repo->id,
        ];
    }

    /**
     * Get Jira configuration for the current user
     */
    private function getJiraConfig(): ?array {
        // Check for enabled Jira board
        $board = Bean::findOne('jiraboards', 'member_id = ? AND is_active = ?', [$this->member->id, 1]);
        if (!$board) {
            return null;
        }

        return [
            'cloud_uid' => $board->cloud_uid,
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
                if (is_array($criterion)) {
                    // BDD format: given/when/then
                    $given = $criterion['given'] ?? '';
                    $when = $criterion['when'] ?? '';
                    $then = $criterion['then'] ?? '';
                    $body .= "- [ ] **Given** {$given} **When** {$when} **Then** {$then}\n";
                } else {
                    $body .= "- [ ] {$criterion}\n";
                }
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
                'story_uid' => $story->story_uid,
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
            'story_uid' => $story->story_uid,
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

    /**
     * AJAX: Get story data
     */
    public function getstory() {
        if (!$this->requireLogin()) return;

        $storyId = $this->getParam('story_id');
        $story = Bean::findOne('ctostories', 'story_uid = ?', [$storyId]);

        if (!$story) {
            Flight::jsonError('Story not found', 404);
            return;
        }

        Flight::jsonSuccess($this->storyToArray($story));
    }

    /**
     * AJAX: Update project details
     */
    public function updateproject() {
        if (!$this->requireLogin()) return;

        $projectId = $this->getParam('project_uid');
        $name = $this->getParam('name');
        $description = $this->getParam('description');

        $project = Bean::findOne('ctoprojects', 'project_uid = ?', [$projectId]);
        if (!$project) {
            Flight::jsonError('Project not found', 404);
            return;
        }

        if ($name) {
            $project->name = $name;
        }
        $project->description = $description;
        $project->updated_at = date('Y-m-d H:i:s');
        Bean::store($project);

        Flight::jsonSuccess(['message' => 'Project updated']);
    }

    /**
     * AJAX: Create a new epic
     */
    public function createepic() {
        if (!$this->requireLogin()) return;

        $projectId = $this->getParam('project_uid');
        $title = $this->getParam('title');
        $description = $this->getParam('description');

        $project = Bean::findOne('ctoprojects', 'project_uid = ?', [$projectId]);
        if (!$project) {
            Flight::jsonError('Project not found', 404);
            return;
        }

        if (empty($title)) {
            Flight::jsonError('Title is required', 400);
            return;
        }

        // Get max sequence for ordering from existing epics
        $existingEpics = $project->ownCtoepicsList;
        $maxSeq = 0;
        foreach ($existingEpics as $existingEpic) {
            if (($existingEpic->sequence ?? 0) > $maxSeq) {
                $maxSeq = $existingEpic->sequence;
            }
        }

        $epic = Bean::dispense('ctoepics');
        $epic->epic_uid = bin2hex(random_bytes(16));
        $epic->ctoprojects = $project;  // Association creates ctoprojects_id FK
        $epic->title = $title;
        $epic->description = $description;
        $epic->status = 'backlog';
        $epic->sequence = $maxSeq + 1;
        $epic->created_at = date('Y-m-d H:i:s');
        Bean::store($epic);

        Flight::jsonSuccess(['message' => 'Epic created', 'epic_uid' => $epic->epic_uid]);
    }

    /**
     * AJAX: Update an epic
     */
    public function updateepic() {
        if (!$this->requireLogin()) return;

        $epicId = $this->getParam('epic_id');
        $title = $this->getParam('title');
        $description = $this->getParam('description');

        $epic = Bean::findOne('ctoepics', 'epic_uid = ?', [$epicId]);
        if (!$epic) {
            Flight::jsonError('Epic not found', 404);
            return;
        }

        if ($title) {
            $epic->title = $title;
        }
        $epic->description = $description;
        $epic->updated_at = date('Y-m-d H:i:s');
        Bean::store($epic);

        Flight::jsonSuccess(['message' => 'Epic updated']);
    }

    /**
     * AJAX: Create a new story
     */
    public function createstory() {
        if (!$this->requireLogin()) return;

        $epicId = $this->getParam('epic_id');
        $title = $this->getParam('title');
        $description = $this->getParam('description');
        $storyPoints = $this->getParam('story_points');
        $acceptanceCriteria = $this->getParam('acceptance_criteria');

        $epic = Bean::findOne('ctoepics', 'epic_uid = ?', [$epicId]);
        if (!$epic) {
            Flight::jsonError('Epic not found', 404);
            return;
        }

        if (empty($title)) {
            Flight::jsonError('Title is required', 400);
            return;
        }

        $story = Bean::dispense('ctostories');
        $story->story_uid = bin2hex(random_bytes(16));
        $story->ctoepics = $epic;  // Association creates ctoepics_id FK
        $story->title = $title;
        $story->description = $description;
        $story->story_points = $storyPoints ? (int)$storyPoints : null;
        $story->acceptance_criteria = json_encode($acceptanceCriteria ?: []);
        $story->status = 'pending_review';
        $story->created_at = date('Y-m-d H:i:s');
        Bean::store($story);

        // Update epic story count
        $epic->story_count = ($epic->story_count ?? 0) + 1;
        Bean::store($epic);

        Flight::jsonSuccess(['message' => 'Story created', 'story_uid' => $story->story_uid]);
    }

    /**
     * AJAX: Move story to different epic
     */
    public function movestory() {
        if (!$this->requireLogin()) return;

        $storyId = $this->getParam('story_id');
        $targetEpicId = $this->getParam('epic_id');

        $story = Bean::findOne('ctostories', 'story_uid = ?', [$storyId]);
        if (!$story) {
            Flight::jsonError('Story not found', 404);
            return;
        }

        $targetEpic = Bean::findOne('ctoepics', 'epic_uid = ?', [$targetEpicId]);
        if (!$targetEpic) {
            Flight::jsonError('Target epic not found', 404);
            return;
        }

        // Update old epic's story count via association
        $oldEpic = $story->ctoepics;
        if ($oldEpic && $oldEpic->id) {
            $oldEpic->story_count = max(0, ($oldEpic->story_count ?? 1) - 1);
            Bean::store($oldEpic);
        }

        // Move story via association
        $story->ctoepics = $targetEpic;  // Association updates ctoepics_id FK
        $story->updated_at = date('Y-m-d H:i:s');
        Bean::store($story);

        // Update new epic's story count
        $targetEpic->story_count = ($targetEpic->story_count ?? 0) + 1;
        Bean::store($targetEpic);

        Flight::jsonSuccess(['message' => 'Story moved']);
    }

    // ==================== Runner Limit Endpoints ====================

    /**
     * AJAX: Get current runner status (running count, max limit, queued jobs)
     */
    public function getrunnerstatus() {
        if (!$this->requireLogin()) return;

        $status = AIDevJobService::getRunnerStatus();
        Flight::jsonSuccess($status);
    }

    /**
     * AJAX: Update workspace runner limit
     */
    public function updaterunnerlimit() {
        if (!$this->requireLogin()) return;

        $newLimit = (int)$this->getParam('limit');
        if ($newLimit < 1) {
            Flight::jsonError('Limit must be at least 1', 400);
            return;
        }

        $result = AIDevJobService::updateRunnerLimit($newLimit);

        if ($result['success']) {
            $this->logger->info('Runner limit updated', [
                'member_id' => $this->member->id,
                'old_limit' => $result['old_limit'] ?? null,
                'new_limit' => $result['new_limit']
            ]);
            Flight::jsonSuccess($result, 'Runner limit updated');
        } else {
            Flight::jsonError($result['error'] ?? 'Failed to update runner limit', 400);
        }
    }

    /**
     * AJAX: Get branches from repository for QA release dropdown
     */
    public function getbranches() {
        if (!$this->requireLogin()) return;

        // Get the first enabled repo connection
        $repo = Bean::findOne('repoconnections', 'enabled = 1 ORDER BY id ASC');
        if (!$repo) {
            Flight::jsonSuccess(['branches' => ['main']], 'No repo connected');
            return;
        }

        try {
            // Get GitHub token from settings
            $tokenSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);
            if (!$tokenSetting) {
                Flight::jsonSuccess(['branches' => ['main']], 'No GitHub token');
                return;
            }

            $token = \app\services\EncryptionService::decrypt($tokenSetting->setting_value);

            // Use repo_owner and repo_name fields directly
            $owner = $repo->repo_owner ?? '';
            $repoName = $repo->repo_name ?? '';

            if (!$owner || !$repoName) {
                Flight::jsonSuccess(['branches' => ['main']], 'Repo owner/name not configured');
                return;
            }

            // Fetch branches from GitHub API
            $ch = curl_init("https://api.github.com/repos/{$owner}/{$repoName}/branches?per_page=100");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$token}",
                    "Accept: application/vnd.github.v3+json",
                    "User-Agent: MyCTOBot"
                ]
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $branchData = json_decode($response, true);
                $branches = array_map(fn($b) => $b['name'], $branchData);

                // Sort with main/master first, then qa branches, then others
                usort($branches, function($a, $b) {
                    $aScore = ($a === 'main' || $a === 'master') ? 0 : (strpos($a, 'qa') === 0 ? 1 : 2);
                    $bScore = ($b === 'main' || $b === 'master') ? 0 : (strpos($b, 'qa') === 0 ? 1 : 2);
                    if ($aScore !== $bScore) return $aScore - $bScore;
                    return strcmp($a, $b);
                });

                Flight::jsonSuccess(['branches' => $branches]);
                return;
            }

            Flight::jsonSuccess(['branches' => ['main']]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch branches', ['error' => $e->getMessage()]);
            Flight::jsonSuccess(['branches' => ['main']]);
        }
    }

    /**
     * AJAX: Abandon a PR - close it on GitHub and mark job as failed
     */
    public function abandonpr() {
        if (!$this->requireLogin()) return;

        $issueKey = $this->getParam('issue_key');
        $prUrl = $this->getParam('pr_url');

        if (!$issueKey) {
            Flight::jsonError('Issue key required', 400);
            return;
        }

        // Find the job
        $job = Bean::findOne('aidevjobs', 'issue_key = ?', [$issueKey]);
        if (!$job) {
            Flight::jsonError('Job not found for ' . $issueKey, 404);
            return;
        }

        // Close PR on GitHub if we have the URL
        $prClosed = false;
        if ($prUrl && preg_match('/github\.com\/([^\/]+)\/([^\/]+)\/pull\/(\d+)/', $prUrl, $m)) {
            $owner = $m[1];
            $repo = $m[2];
            $prNumber = $m[3];

            // Get GitHub token
            $tokenSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);
            if ($tokenSetting) {
                try {
                    $token = \app\services\EncryptionService::decrypt($tokenSetting->setting_value);

                    // Close the PR via GitHub API
                    $ch = curl_init("https://api.github.com/repos/{$owner}/{$repo}/pulls/{$prNumber}");
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_CUSTOMREQUEST => 'PATCH',
                        CURLOPT_POSTFIELDS => json_encode(['state' => 'closed']),
                        CURLOPT_HTTPHEADER => [
                            "Authorization: Bearer {$token}",
                            "Accept: application/vnd.github.v3+json",
                            "User-Agent: MyCTOBot",
                            "Content-Type: application/json"
                        ]
                    ]);
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    $prClosed = ($httpCode === 200);

                    $this->logger->info('Closed PR on GitHub', [
                        'issue_key' => $issueKey,
                        'pr_url' => $prUrl,
                        'http_code' => $httpCode
                    ]);
                } catch (\Exception $e) {
                    $this->logger->error('Failed to close PR', [
                        'issue_key' => $issueKey,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        // Mark job as failed/abandoned
        $job->status = 'failed';
        $job->error_message = 'Abandoned by user';
        $job->updated_at = date('Y-m-d H:i:s');
        Bean::store($job);

        // Also update the story status if it exists
        $story = Bean::findOne('ctostories', 'jira_issue_key = ?', [$issueKey]);
        if ($story) {
            $story->status = 'blocked';
            Bean::store($story);
        }

        $this->logger->info('PR abandoned', [
            'issue_key' => $issueKey,
            'pr_closed' => $prClosed,
            'member_id' => $this->member->id
        ]);

        Flight::jsonSuccess([
            'pr_closed' => $prClosed,
            'job_status' => 'failed'
        ], $prClosed ? 'PR closed and job marked as failed' : 'Job marked as failed (PR may need manual closure)');
    }

    // ==================== Stale Job Detection Endpoints ====================

    /**
     * AJAX: Find jobs that are marked as running but have no live tmux session
     */
    public function findstalejobs() {
        if (!$this->requireLogin()) return;

        try {
            $staleJobs = AIDevJobService::findStaleJobs();

            $this->logger->debug('Stale job check completed', [
                'member_id' => $this->member->id,
                'stale_count' => count($staleJobs)
            ]);

            Flight::jsonSuccess([
                'stale_jobs' => $staleJobs,
                'count' => count($staleJobs)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error finding stale jobs', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            Flight::jsonError('Error checking for stale jobs: ' . $e->getMessage(), 500);
        }
    }

    /**
     * AJAX: Clean up all stale jobs (mark as failed)
     */
    public function cleanupstalejobs() {
        if (!$this->requireLogin()) return;

        $result = AIDevJobService::cleanupStaleJobs();

        $this->logger->info('Stale jobs cleaned up', [
            'member_id' => $this->member->id,
            'found' => $result['found'],
            'cleaned' => $result['cleaned']
        ]);

        if ($result['cleaned'] > 0) {
            Flight::jsonSuccess($result, "{$result['cleaned']} stale job(s) marked as failed");
        } else if ($result['found'] === 0) {
            Flight::jsonSuccess($result, 'No stale jobs found');
        } else {
            Flight::jsonError('Failed to clean up some stale jobs', 500);
        }
    }

    /**
     * AJAX: Check if a specific job's tmux session is still alive
     */
    public function checkjobsession() {
        if (!$this->requireLogin()) return;

        $jobId = $this->getParam('job_uid');
        if (!$jobId) {
            Flight::jsonError('Job ID required', 400);
            return;
        }

        $status = AIDevJobService::checkJobSession($jobId);
        Flight::jsonSuccess($status);
    }

    /**
     * AJAX: Mark a specific job as stale/failed
     */
    public function markjobstale() {
        if (!$this->requireLogin()) return;

        $jobId = $this->getParam('job_uid');
        if (!$jobId) {
            Flight::jsonError('Job ID required', 400);
            return;
        }

        $reason = $this->getParam('reason') ?? 'Manually marked as crashed';
        $result = AIDevJobService::markJobAsStale($jobId, $reason);

        if ($result['success']) {
            $this->logger->info('Job marked as stale', [
                'member_id' => $this->member->id,
                'job_uid' => $jobId,
                'issue_key' => $result['issue_key'] ?? null
            ]);
            Flight::jsonSuccess($result, 'Job marked as failed');
        } else {
            Flight::jsonError($result['error'] ?? 'Failed to mark job as stale', 400);
        }
    }

    /**
     * AJAX: Retry a failed job
     */
    public function retryjob() {
        if (!$this->requireLogin()) return;

        $jobId = $this->getParam('job_uid');
        if (!$jobId) {
            Flight::jsonError('Job ID required', 400);
            return;
        }

        $tenant = $_SESSION['tenant_slug'] ?? null;
        $jobService = new AIDevJobService();
        $result = $jobService->retryJob($jobId, $this->member->id, $tenant);

        if ($result['success']) {
            $this->logger->info('Job retry initiated', [
                'member_id' => $this->member->id,
                'job_uid' => $jobId,
                'issue_key' => $result['issue_key'] ?? null
            ]);
            Flight::jsonSuccess($result, 'Job retry started');
        } else {
            Flight::jsonError($result['error'] ?? 'Failed to retry job', 400);
        }
    }

    /**
     * AJAX: Build QA release with selected stories
     */
    public function buildqarelease() {
        if (!$this->requireLogin()) return;

        $storyIds = $this->getParam('story_ids');
        $targetBranch = $this->getParam('target_branch') ?? 'main';

        if (!is_array($storyIds) || empty($storyIds)) {
            Flight::jsonError('No stories selected', 400);
            return;
        }

        // Validate story IDs exist and are completed
        $validStoryIds = [];
        foreach ($storyIds as $storyId) {
            $story = Bean::findOne('ctostories', 'id = ?', [$storyId]);
            if ($story && in_array($story->status, ['done', 'approved'])) {
                // Check for completed job
                $job = Bean::findOne('aidevjobs', 'issue_key = ? AND status IN (?, ?)',
                    [$story->jira_issue_key, 'pr_created', 'complete']);
                if ($job) {
                    $validStoryIds[] = $storyId;
                }
            }
        }

        if (empty($validStoryIds)) {
            Flight::jsonError('No valid completed stories found', 400);
            return;
        }

        // Determine project from first story via associations
        $firstStory = Bean::findOne('ctostories', 'id = ?', [$validStoryIds[0]]);
        $epic = $firstStory->ctoepics;
        $project = $epic ? $epic->ctoprojects : null;
        $projectId = $project ? $project->id : null;

        if (!$projectId) {
            Flight::jsonError('Could not determine project', 400);
            return;
        }

        // Get tenant
        $tenant = $_SESSION['tenant_slug'] ?? null;

        // Build the command to run qa-release-builder.php in background
        $storyIdList = implode(',', $validStoryIds);
        $tenantParam = $tenant ? sprintf(' --tenant=%s', escapeshellarg($tenant)) : '';

        // If user enters "main", generate a unique QA branch name
        // Otherwise use their custom branch name (e.g., "qa/rc1")
        if ($targetBranch === 'main') {
            $qaBranch = 'qa/release-' . date('Y-m-d-His');
        } else {
            $qaBranch = $targetBranch;
        }
        $logFile = "log/qa-release-" . date('Y-m-d') . ".log";

        // Add --push to auto-push and --verbose for logging
        $baseDir = dirname(__DIR__);
        $cmd = sprintf(
            'php %s/scripts/qa-release-builder.php --project=%d --stories=%s --target=main --branch=%s --push --verbose%s >> %s 2>&1 &',
            escapeshellarg($baseDir),
            $projectId,
            escapeshellarg($storyIdList),
            escapeshellarg($qaBranch),
            $tenantParam,
            escapeshellarg($baseDir . '/' . $logFile)
        );

        $this->logger->info('Starting QA release build', [
            'member_id' => $this->member->id,
            'project_uid' => $projectId,
            'story_ids' => $validStoryIds,
            'target_branch' => $targetBranch,
            'qa_branch' => $qaBranch,
            'command' => $cmd
        ]);

        // Generate a unique build ID for tracking
        $buildId = date('Ymd-His');
        $workDir = "/tmp/qa-release-{$tenant}-{$projectId}-{$buildId}";

        // Execute in background
        exec($cmd);

        Flight::jsonSuccess([
            'story_count' => count($validStoryIds),
            'project_uid' => $projectId,
            'target_branch' => $targetBranch,
            'qa_branch' => $qaBranch,
            'log_file' => $logFile,
            'build_id' => $buildId,
            'work_dir' => $workDir,
        ], 'QA release build started - check status for results');
    }

    /**
     * AJAX: Check QA release build status
     */
    public function qareleasestatus() {
        if (!$this->requireLogin()) return;

        $workDir = $this->getParam('work_dir');
        if (!$workDir || !is_dir($workDir)) {
            Flight::jsonSuccess(['status' => 'running', 'message' => 'Build in progress...']);
            return;
        }

        $reportFile = $workDir . '/qa-report.json';
        if (!file_exists($reportFile)) {
            Flight::jsonSuccess(['status' => 'running', 'message' => 'Build in progress...']);
            return;
        }

        $report = json_decode(file_get_contents($reportFile), true);
        if (!$report) {
            Flight::jsonSuccess(['status' => 'running', 'message' => 'Build in progress...']);
            return;
        }

        // Extract key info from report
        $mergeResults = $report['merge_results'] ?? [];
        $stories = $report['stories'] ?? [];
        $successful = count(array_filter($mergeResults, fn($r) => $r['success'] ?? false));
        $failed = count(array_filter($mergeResults, fn($r) => !($r['success'] ?? false)));

        // Build a map of issue_key => pr_url from stories
        $prUrls = [];
        foreach ($stories as $story) {
            if (!empty($story['issue_key']) && !empty($story['pr_url'])) {
                $prUrls[$story['issue_key']] = $story['pr_url'];
            }
        }

        $conflicts = [];
        foreach ($mergeResults as $issueKey => $result) {
            if (!($result['success'] ?? false)) {
                $conflicts[$issueKey] = [
                    'files' => $result['conflicts'] ?? [],
                    'error' => $result['error'] ?? 'Merge failed',
                    'pr_url' => $prUrls[$issueKey] ?? null,
                ];
            }
        }

        Flight::jsonSuccess([
            'status' => $report['success'] ? 'success' : 'failed',
            'qa_branch' => $report['qa_branch'] ?? null,
            'target_branch' => $report['target_branch'] ?? null,
            'merged' => $successful,
            'failed' => $failed,
            'conflicts' => $conflicts,
            'build_passed' => empty(array_filter($report['build_results'] ?? [], fn($r) => !$r['success'])),
            'tests_passed' => empty(array_filter($report['test_results'] ?? [], fn($r) => !$r['success'])),
            'message' => $report['success']
                ? "QA branch pushed to GitHub"
                : ($failed > 0 ? "{$failed} merge conflict(s)" : "Build or tests failed"),
        ]);
    }

    /**
     * AJAX: Batch approve selected pending stories
     */
    public function batchapprove() {
        if (!$this->requireLogin()) return;

        $storyIds = $this->getParam('story_ids');
        if (!is_array($storyIds) || empty($storyIds)) {
            Flight::jsonError('No stories selected', 400);
            return;
        }

        $approved = 0;
        $errors = [];

        foreach ($storyIds as $storyId) {
            $story = Bean::findOne('ctostories', 'story_uid = ?', [$storyId]);
            if (!$story) {
                $errors[] = "Story not found: {$storyId}";
                continue;
            }

            if ($story->status !== 'pending_review') {
                continue; // Skip already approved
            }

            $story->status = 'approved';
            $story->updated_at = date('Y-m-d H:i:s');
            Bean::store($story);
            $approved++;
        }

        Flight::jsonSuccess([
            'approved' => $approved,
            'errors' => $errors
        ], "{$approved} stories approved");
    }

    /**
     * AJAX: Batch approve and start jobs for selected pending stories
     */
    public function batchapproveandrun() {
        if (!$this->requireLogin()) return;

        $storyIds = $this->getParam('story_ids');
        if (!is_array($storyIds) || empty($storyIds)) {
            Flight::jsonError('No stories selected', 400);
            return;
        }

        $approved = 0;
        $jobsStarted = 0;
        $errors = [];

        // Get GitHub config for job creation
        $githubConfig = $this->getGitHubConfig();

        foreach ($storyIds as $storyId) {
            $story = Bean::findOne('ctostories', 'story_uid = ?', [$storyId]);
            if (!$story) {
                $errors[] = "Story not found: {$storyId}";
                continue;
            }

            // Approve if pending
            if ($story->status === 'pending_review') {
                $story->status = 'approved';
                $story->updated_at = date('Y-m-d H:i:s');
                Bean::store($story);
                $approved++;
            }

            // Create GitHub issue if needed
            if (empty($story->jira_issue_key) && $githubConfig) {
                try {
                    $issueKey = $this->createGitHubIssue($story, $githubConfig);
                    $story->jira_issue_key = $issueKey;
                    Bean::store($story);
                } catch (\Exception $e) {
                    $errors[] = "Failed to create issue for: {$story->title}";
                    continue;
                }
            }

            // Start job if story has an issue key
            if ($story->jira_issue_key) {
                try {
                    // Check if job already exists
                    $existingJob = Bean::findOne('aidevjobs', 'issue_key = ?', [$story->jira_issue_key]);
                    if ($existingJob && !in_array($existingJob->status, ['failed', 'complete', 'pr_created'])) {
                        continue; // Job already running
                    }

                    // Queue the job
                    $this->queueJobForStory($story);
                    $jobsStarted++;
                } catch (\Exception $e) {
                    $errors[] = "Failed to start job for: {$story->jira_issue_key}";
                }
            }
        }

        Flight::jsonSuccess([
            'approved' => $approved,
            'jobs_started' => $jobsStarted,
            'errors' => $errors
        ], "{$approved} approved, {$jobsStarted} jobs queued");
    }

    /**
     * Helper: Queue a job for a story
     */
    private function queueJobForStory($story) {
        $githubConfig = $this->getGitHubConfig();
        if (!$githubConfig) {
            throw new \Exception('GitHub not configured');
        }

        // Create job record
        $job = Bean::dispense('aidevjobs');
        $job->job_uid = bin2hex(random_bytes(16));
        $job->member_id = $this->member->id;
        $job->issue_key = $story->jira_issue_key;
        $job->project_type = 'github';
        $job->status = 'pending';
        $job->progress = 0;
        $job->current_step = 'Queued';
        $job->created_at = date('Y-m-d H:i:s');
        Bean::store($job);

        // Link job to story
        $story->aidev_job_uid = $job->job_uid;
        Bean::store($story);

        return $job;
    }

    /**
     * AJAX: Start a new job for an approved story that has an issue but no job yet
     */
    public function startjob() {
        if (!$this->requireLogin()) return;

        $issueKey = $this->getParam('issue_key');
        if (!$issueKey) {
            Flight::jsonError('Issue key required', 400);
            return;
        }

        // Find the story with this issue key
        $story = Bean::findOne('ctostories', 'jira_issue_key = ?', [$issueKey]);
        if (!$story) {
            Flight::jsonError('Story not found for issue: ' . $issueKey, 404);
            return;
        }

        // Verify story is approved
        if ($story->status !== 'approved') {
            Flight::jsonError('Story must be approved before starting a job', 400);
            return;
        }

        // Check if a job already exists
        $existingJob = Bean::findOne('aidevjobs', 'issue_key = ?', [$issueKey]);
        if ($existingJob && !in_array($existingJob->status, ['failed', 'complete', 'pr_created'])) {
            Flight::jsonError('A job already exists for this issue (status: ' . $existingJob->status . ')', 400);
            return;
        }

        // Get GitHub config for repo info
        $githubConfig = $this->getGitHubConfig();
        if (!$githubConfig) {
            Flight::jsonError('GitHub not configured', 400);
            return;
        }

        $tenant = $_SESSION['tenant_slug'] ?? null;
        $repoId = $githubConfig['repo_id'] ?? null;

        // Use AIDevJobService to trigger the job
        $jobService = new AIDevJobService();
        $result = $jobService->triggerJob(
            $this->member->id,
            $issueKey,
            '',        // cloudId (not used for GitHub)
            null,      // boardId (not used for GitHub)
            $repoId,   // repoId
            $tenant,
            false,     // useOrchestrator
            AIDevJobService::PROJECT_TYPE_GITHUB  // projectType
        );

        if ($result['success']) {
            $this->logger->info('New job started from Review Board', [
                'member_id' => $this->member->id,
                'issue_key' => $issueKey,
                'job_uid' => $result['job_uid'] ?? null
            ]);
            Flight::jsonSuccess([
                'job_uid' => $result['job_uid'] ?? null,
                'issue_key' => $issueKey
            ], 'Job started successfully');
        } else {
            Flight::jsonError($result['error'] ?? 'Failed to start job', 500);
        }
    }
}
