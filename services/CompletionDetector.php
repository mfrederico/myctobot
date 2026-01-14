<?php
/**
 * Completion Detector Service
 * Monitors AI dev job completion and rolls up status through stories -> epics -> projects
 *
 * Responsibilities:
 * - Monitor aidevjobs for completion
 * - Roll up story -> epic -> project completion
 * - Detect blockers and escalate
 * - Trigger next stories when dependencies met
 */

namespace app\services;

use \Flight as Flight;
use \app\Bean;

require_once __DIR__ . '/UserDatabaseService.php';

class CompletionDetector {
    private int $memberId;
    private $logger;

    public function __construct(int $memberId) {
        $this->memberId = $memberId;
        $this->logger = Flight::get('log');
    }

    /**
     * Main detection loop - call this from cron
     */
    public function checkAll(): array {
        $results = [
            'stories_updated' => 0,
            'epics_updated' => 0,
            'projects_completed' => 0,
            'stories_unblocked' => 0
        ];

        try {
            // 1. Check all in-progress stories against their aidevjobs
            $results['stories_updated'] = $this->checkStories();

            // 2. Roll up epic completion
            $results['epics_updated'] = $this->checkEpics();

            // 3. Roll up project completion
            $results['projects_completed'] = $this->checkProjects();

            // 4. Unblock dependent stories
            $results['stories_unblocked'] = $this->checkDependencies();

        } catch (\Exception $e) {
            $this->logger->error('CompletionDetector: Error in checkAll', [
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }

    /**
     * Check story completion by monitoring linked aidevjobs
     */
    private function checkStories(): int {
        $updated = 0;

        // Find stories with linked aidevjobs that are in_progress or ready
        $stories = Bean::find('ctostories',
            'aidev_job_uid IS NOT NULL AND status IN (?, ?)',
            ['ready', 'in_progress']
        );

        foreach ($stories as $story) {
            $result = $this->checkStoryCompletion($story);
            if ($result['changed']) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Check completion of a single story
     */
    public function checkStoryCompletion(object $story): array {
        $changed = false;
        $newStatus = null;

        // Find the linked aidevjob
        $job = Bean::findOne('aidevjobs', 'job_uid = ?', [$story->aidev_job_uid]);

        if (!$job) {
            $this->logger->warning('CompletionDetector: aidevjob not found', [
                'story_id' => $story->story_id,
                'job_uid' => $story->aidev_job_uid
            ]);
            return ['changed' => false];
        }

        // Map job status to story status
        switch ($job->status) {
            case 'running':
                if ($story->status !== 'in_progress') {
                    $story->status = 'in_progress';
                    $changed = true;
                    $newStatus = 'in_progress';
                }
                break;

            case 'pr_created':
                if ($story->status !== 'review') {
                    $story->status = 'review';
                    $changed = true;
                    $newStatus = 'review';
                }
                break;

            case 'complete':
                if ($story->status !== 'done') {
                    $story->status = 'done';
                    $story->verified_at = date('Y-m-d H:i:s');
                    $changed = true;
                    $newStatus = 'done';

                    // Update epic completion count
                    $this->incrementEpicCompletion($story->epic_id);
                }
                break;

            case 'failed':
            case 'error':
                if ($story->status !== 'blocked') {
                    $story->status = 'blocked';
                    $story->blocker_reason = $job->error_message ?? 'Job failed';
                    $changed = true;
                    $newStatus = 'blocked';
                }
                break;

            case 'waiting_clarification':
                if ($story->status !== 'blocked') {
                    $story->status = 'blocked';
                    $story->blocker_reason = 'Waiting for clarification';
                    $changed = true;
                    $newStatus = 'blocked';

                    // Escalate to CEO
                    $this->escalate($story, 'clarification_needed');
                }
                break;
        }

        if ($changed) {
            $story->updated_at = date('Y-m-d H:i:s');
            Bean::store($story);

            $this->logger->info('CompletionDetector: Story status updated', [
                'story_id' => $story->story_id,
                'new_status' => $newStatus,
                'job_status' => $job->status
            ]);
        }

        return [
            'changed' => $changed,
            'new_status' => $newStatus
        ];
    }

    /**
     * Increment epic completion count
     */
    private function incrementEpicCompletion(int $epicId): void {
        $epic = Bean::load('ctoepics', $epicId);
        if ($epic && $epic->id) {
            $epic->stories_completed = ($epic->stories_completed ?? 0) + 1;
            $epic->updated_at = date('Y-m-d H:i:s');
            Bean::store($epic);
        }
    }

    /**
     * Check epic completion and update status
     */
    private function checkEpics(): int {
        $updated = 0;

        // Find in-progress epics
        $epics = Bean::find('ctoepics', 'status = ?', ['in_progress']);

        foreach ($epics as $epic) {
            $result = $this->checkEpicCompletion($epic);
            if ($result['changed']) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Check completion of a single epic
     */
    public function checkEpicCompletion(object $epic): array {
        $stories = Bean::find('ctostories', 'epic_id = ?', [$epic->id]);
        $totalStories = count($stories);

        if ($totalStories === 0) {
            return ['changed' => false];
        }

        $completedStories = 0;
        $blockedStories = 0;

        foreach ($stories as $story) {
            if ($story->status === 'done') {
                $completedStories++;
            } elseif ($story->status === 'blocked') {
                $blockedStories++;
            }
        }

        $epic->story_count = $totalStories;
        $epic->stories_completed = $completedStories;

        $changed = false;
        $oldStatus = $epic->status;

        // Determine epic status
        if ($completedStories === $totalStories) {
            $epic->status = 'completed';
            $changed = ($oldStatus !== 'completed');
        } elseif ($blockedStories > 0 && $completedStories + $blockedStories === $totalStories) {
            // All non-completed stories are blocked
            $epic->status = 'blocked';
            $changed = ($oldStatus !== 'blocked');
        }

        if ($changed || $epic->stories_completed !== $completedStories) {
            $epic->updated_at = date('Y-m-d H:i:s');
            Bean::store($epic);
            $changed = true;

            $this->logger->info('CompletionDetector: Epic status updated', [
                'epic_id' => $epic->epic_id,
                'status' => $epic->status,
                'completion' => "{$completedStories}/{$totalStories}"
            ]);
        }

        return [
            'changed' => $changed,
            'status' => $epic->status,
            'completion' => "{$completedStories}/{$totalStories}"
        ];
    }

    /**
     * Check project completion
     */
    private function checkProjects(): int {
        $completed = 0;

        // Find in-progress projects
        $projects = Bean::find('ctoprojects', 'status = ?', ['in_progress']);

        foreach ($projects as $project) {
            $result = $this->checkProjectCompletion($project);
            if ($result['completed']) {
                $completed++;
            }
        }

        return $completed;
    }

    /**
     * Check completion of a single project
     */
    public function checkProjectCompletion(object $project): array {
        $epics = Bean::find('ctoepics', 'project_uid = ?', [$project->id]);
        $totalEpics = count($epics);

        if ($totalEpics === 0) {
            return ['completed' => false, 'percentage' => 0];
        }

        $completedEpics = 0;
        $totalStories = 0;
        $completedStories = 0;

        foreach ($epics as $epic) {
            if ($epic->status === 'completed') {
                $completedEpics++;
            }
            $totalStories += $epic->story_count ?? 0;
            $completedStories += $epic->stories_completed ?? 0;
        }

        // Calculate percentage based on stories
        $percentage = $totalStories > 0
            ? round(($completedStories / $totalStories) * 100)
            : 0;

        $project->completion_percentage = $percentage;
        $completed = false;

        if ($completedEpics === $totalEpics) {
            $project->status = 'completed';
            $completed = true;

            // Complete the directive
            if ($project->directive_uid) {
                $this->completeDirective($project->directive_uid);
            }

            $this->logger->info('CompletionDetector: Project completed', [
                'project_uid' => $project->project_uid,
                'name' => $project->name
            ]);
        }

        $project->updated_at = date('Y-m-d H:i:s');
        Bean::store($project);

        return [
            'completed' => $completed,
            'percentage' => $percentage
        ];
    }

    /**
     * Complete a directive
     */
    private function completeDirective(int $directiveId): void {
        $directive = Bean::load('ceodirectives', $directiveId);
        if ($directive && $directive->id) {
            $directive->status = 'completed';
            $directive->current_phase = 'complete';
            $directive->updated_at = date('Y-m-d H:i:s');
            Bean::store($directive);

            // Log completion
            $this->logDirective($directiveId, 'completion', 'info', 'All work complete');

            // TODO: Send completion email to CEO
            // TODO: Generate report
        }
    }

    /**
     * Check dependencies and unblock stories
     */
    private function checkDependencies(): int {
        $unblocked = 0;

        // Find stories that are waiting (backlog) with dependencies
        $stories = Bean::find('ctostories',
            'status = ? AND depends_on IS NOT NULL AND depends_on != "[]"',
            ['backlog']
        );

        foreach ($stories as $story) {
            if ($this->areDependenciesMet($story)) {
                // Dependencies are met - story can be queued
                $this->logger->info('CompletionDetector: Story dependencies met', [
                    'story_id' => $story->story_id
                ]);
                $unblocked++;
            }
        }

        return $unblocked;
    }

    /**
     * Check if a story's dependencies are met
     */
    private function areDependenciesMet(object $story): bool {
        $dependencies = json_decode($story->depends_on, true);
        if (empty($dependencies)) {
            return true;
        }

        foreach ($dependencies as $depStoryId) {
            $depStory = Bean::findOne('ctostories', 'story_id = ?', [$depStoryId]);
            if (!$depStory || $depStory->status !== 'done') {
                return false;
            }
        }

        return true;
    }

    /**
     * Escalate an issue to the CEO
     */
    private function escalate(object $story, string $reason): void {
        // Find the project's directive
        $epic = Bean::load('ctoepics', $story->epic_id);
        if (!$epic) return;

        $project = Bean::load('ctoprojects', $epic->project_uid);
        if (!$project) return;

        $directive = Bean::load('ceodirectives', $project->directive_uid);
        if (!$directive) return;

        $this->logDirective($directive->id, 'escalation', 'warning',
            "Escalation: Story '{$story->title}' - {$reason}", [
                'story_id' => $story->story_id,
                'jira_key' => $story->jira_issue_key,
                'reason' => $reason
            ]);

        // TODO: Send escalation email to CEO
        $this->logger->warning('CompletionDetector: Escalation created', [
            'directive_uid' => $directive->directive_uid,
            'story_id' => $story->story_id,
            'reason' => $reason
        ]);
    }

    /**
     * Get project progress summary
     */
    public function getProjectProgress(int $projectId): array {
        $project = Bean::load('ctoprojects', $projectId);
        if (!$project || !$project->id) {
            return ['error' => 'Project not found'];
        }

        $epics = Bean::find('ctoepics', 'project_uid = ? ORDER BY sequence ASC', [$projectId]);
        $epicProgress = [];

        foreach ($epics as $epic) {
            $stories = Bean::find('ctostories', 'epic_id = ?', [$epic->id]);
            $statusCounts = ['backlog' => 0, 'ready' => 0, 'in_progress' => 0, 'review' => 0, 'done' => 0, 'blocked' => 0];

            foreach ($stories as $story) {
                $statusCounts[$story->status] = ($statusCounts[$story->status] ?? 0) + 1;
            }

            $epicProgress[] = [
                'epic_id' => $epic->epic_id,
                'title' => $epic->title,
                'status' => $epic->status,
                'story_count' => $epic->story_count,
                'stories_completed' => $epic->stories_completed,
                'status_breakdown' => $statusCounts
            ];
        }

        return [
            'project_uid' => $project->project_uid,
            'name' => $project->name,
            'status' => $project->status,
            'completion_percentage' => $project->completion_percentage,
            'epics' => $epicProgress
        ];
    }

    /**
     * Log a directive event
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
                'error' => $e->getMessage()
            ]);
        }
    }
}
