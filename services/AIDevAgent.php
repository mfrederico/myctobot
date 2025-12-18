<?php
/**
 * AI Developer Agent Service
 * Core orchestrator for the Enterprise tier AI Developer feature
 *
 * Workflow:
 * 1. Fetch ticket from Jira
 * 2. Analyze requirements (clarity check)
 * 3. If unclear → post questions to Jira, wait for answer
 * 4. Clone repository
 * 5. Analyze codebase with Claude
 * 6. Generate implementation plan
 * 7. Write code changes
 * 8. Create PR with description
 */

namespace app\services;

use \Flight as Flight;
use GuzzleHttp\Client;

class AIDevAgent {
    private int $memberId;
    private string $cloudId;
    private int $repoConnectionId;
    private string $customerApiKey;
    private string $jobId;

    private ?JiraClient $jira = null;
    private ?GitHubClient $github = null;
    private ?GitOperations $git = null;

    private array $repoConfig = [];
    private string $model = 'claude-sonnet-4-20250514';

    /**
     * Create a new AI Developer Agent
     *
     * @param int $memberId Member ID
     * @param string $cloudId Atlassian Cloud ID
     * @param int $repoConnectionId Repository connection ID
     * @param string $customerApiKey Customer's Anthropic API key
     * @param string $jobId Job ID for status tracking
     */
    public function __construct(
        int $memberId,
        string $cloudId,
        int $repoConnectionId,
        string $customerApiKey,
        string $jobId
    ) {
        $this->memberId = $memberId;
        $this->cloudId = $cloudId;
        $this->repoConnectionId = $repoConnectionId;
        $this->customerApiKey = $customerApiKey;
        $this->jobId = $jobId;

        // Initialize Jira client
        $this->jira = new JiraClient($memberId, $cloudId);

        // Load repository configuration
        $this->loadRepoConfig();
    }

    /**
     * Load repository configuration from user's database
     */
    private function loadRepoConfig(): void {
        $db = $this->getUserDb();
        $result = $db->querySingle(
            "SELECT * FROM repo_connections WHERE id = " . (int)$this->repoConnectionId,
            true
        );

        if (!$result) {
            throw new \Exception("Repository connection not found: {$this->repoConnectionId}");
        }

        $this->repoConfig = $result;

        // Initialize Git client based on provider
        if ($result['provider'] === 'github') {
            // Decrypt access token
            $accessToken = EncryptionService::decrypt($result['access_token']);
            $this->github = new GitHubClient($accessToken);
        }

        $this->git = new GitOperations();
    }

    /**
     * Get user's SQLite database
     */
    private function getUserDb(): \SQLite3 {
        $member = \RedBeanPHP\R::load('member', $this->memberId);
        if (!$member || empty($member->ceobot_db)) {
            throw new \Exception("Member database not configured");
        }

        $dbPath = Flight::get('ceobot.user_db_path') ?? 'database/';
        $dbFile = $dbPath . $member->ceobot_db . '.sqlite';

        return new \SQLite3($dbFile);
    }

    // ========================================
    // Main Workflow
    // ========================================

    /**
     * Process a Jira ticket - main entry point
     *
     * @param string $issueKey Jira issue key (e.g., "PROJ-123")
     * @return array Result with status and details
     */
    public function processTicket(string $issueKey): array {
        $this->log('info', "Starting to process ticket: {$issueKey}");

        try {
            // Step 1: Fetch issue from Jira
            $this->updateStatus(AIDevStatusService::STEP_FETCHING_ISSUE, 5);
            $issue = $this->jira->getIssue($issueKey);

            if (!$issue) {
                throw new \Exception("Issue not found: {$issueKey}");
            }

            $this->log('info', 'Fetched issue', [
                'summary' => $issue['fields']['summary'] ?? 'No summary'
            ]);

            // Step 2: Analyze requirements
            $this->updateStatus(AIDevStatusService::STEP_ANALYZING_REQUIREMENTS, 10);
            $requirements = $this->analyzeRequirements($issue);

            // Step 3: Check if clarification needed
            $this->updateStatus(AIDevStatusService::STEP_CHECKING_CLARITY, 15);
            if ($this->needsClarification($requirements)) {
                $this->updateStatus(AIDevStatusService::STEP_POSTING_QUESTIONS, 20);
                return $this->postClarificationQuestions($issueKey, $requirements['questions']);
            }

            // Step 4: Clone repository
            $this->updateStatus(AIDevStatusService::STEP_CLONING_REPO, 25);
            $this->cloneRepository();

            // Step 5: Analyze codebase
            $this->updateStatus(AIDevStatusService::STEP_ANALYZING_CODEBASE, 35);
            $codebaseContext = $this->analyzeCodebase($requirements);

            // Step 6: Plan implementation
            $this->updateStatus(AIDevStatusService::STEP_PLANNING_IMPLEMENTATION, 45);
            $plan = $this->planImplementation($requirements, $codebaseContext);

            // Step 7: Implement changes
            $this->updateStatus(AIDevStatusService::STEP_IMPLEMENTING_CHANGES, 55);
            $changes = $this->implementChanges($plan);

            // Step 8: Create PR
            $this->updateStatus(AIDevStatusService::STEP_CREATING_PR, 85);
            $pr = $this->createPullRequest($issueKey, $issue, $changes, $plan);

            // Complete
            AIDevStatusService::prCreated(
                $this->memberId,
                $this->jobId,
                $pr['url'],
                $pr['number'],
                $pr['branch']
            );

            $this->log('info', 'Job completed successfully', ['pr_url' => $pr['url']]);

            return [
                'status' => 'success',
                'pr_url' => $pr['url'],
                'pr_number' => $pr['number'],
                'branch' => $pr['branch'],
                'files_changed' => count($changes['files'] ?? [])
            ];

        } catch (\Exception $e) {
            $this->log('error', 'Job failed', ['error' => $e->getMessage()]);
            AIDevStatusService::fail($this->memberId, $this->jobId, $e->getMessage());

            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        } finally {
            // Cleanup git working directory
            if ($this->git) {
                $this->git->cleanup();
            }
        }
    }

    /**
     * Resume after clarification has been answered
     *
     * @param string $issueKey Issue key
     * @param string $answerCommentId ID of the comment with the answer
     * @return array Result
     */
    public function resumeAfterClarification(string $issueKey, string $answerCommentId): array {
        $this->log('info', "Resuming after clarification for: {$issueKey}");

        try {
            // Add working label to indicate job is in progress
            try {
                $this->jira->addLabel($issueKey, 'myctobot-working');
                $this->log('info', 'Added working label');
            } catch (\Exception $e) {
                $this->log('warning', 'Failed to add working label: ' . $e->getMessage());
            }

            // Fetch the issue again with the new comment
            $this->updateStatus(AIDevStatusService::STEP_FETCHING_ISSUE, 5);
            $issue = $this->jira->getIssue($issueKey);

            // Get the comments to find the answer
            $comments = $this->jira->getComments($issueKey);
            $answer = null;

            // Find the answer comment and any subsequent comments
            $foundAnswer = false;
            $additionalContext = [];
            foreach ($comments as $comment) {
                if ($comment['id'] === $answerCommentId || $foundAnswer) {
                    $foundAnswer = true;
                    $additionalContext[] = JiraClient::extractTextFromAdf($comment['body']);
                }
            }

            // Re-analyze with the clarification
            $this->updateStatus(AIDevStatusService::STEP_ANALYZING_REQUIREMENTS, 10);
            $requirements = $this->analyzeRequirements($issue, implode("\n\n", $additionalContext));

            // Continue with implementation (skip clarification check since we just got the answer)
            // Clone repository
            $this->updateStatus(AIDevStatusService::STEP_CLONING_REPO, 25);
            $this->cloneRepository();

            // Analyze codebase
            $this->updateStatus(AIDevStatusService::STEP_ANALYZING_CODEBASE, 35);
            $codebaseContext = $this->analyzeCodebase($requirements);

            // Plan implementation
            $this->updateStatus(AIDevStatusService::STEP_PLANNING_IMPLEMENTATION, 45);
            $plan = $this->planImplementation($requirements, $codebaseContext);

            // Implement changes
            $this->updateStatus(AIDevStatusService::STEP_IMPLEMENTING_CHANGES, 55);
            $changes = $this->implementChanges($plan);

            // Create PR
            $this->updateStatus(AIDevStatusService::STEP_CREATING_PR, 85);
            $pr = $this->createPullRequest($issueKey, $issue, $changes, $plan);

            AIDevStatusService::prCreated(
                $this->memberId,
                $this->jobId,
                $pr['url'],
                $pr['number'],
                $pr['branch']
            );

            return [
                'status' => 'success',
                'pr_url' => $pr['url'],
                'pr_number' => $pr['number'],
                'branch' => $pr['branch']
            ];

        } catch (\Exception $e) {
            $this->log('error', 'Resume failed', ['error' => $e->getMessage()]);
            AIDevStatusService::fail($this->memberId, $this->jobId, $e->getMessage());

            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        } finally {
            if ($this->git) {
                $this->git->cleanup();
            }
        }
    }

    /**
     * Retry implementation on an existing branch/PR
     *
     * @param string $issueKey Issue key
     * @param string $branchName Existing branch to work on
     * @param int|null $prNumber Existing PR number (optional)
     * @return array Result
     */
    public function retryOnBranch(string $issueKey, string $branchName, ?int $prNumber = null): array {
        $this->log('info', "Retrying on existing branch: {$branchName} for {$issueKey}");

        try {
            // Add working label to indicate job is in progress
            try {
                $this->jira->addLabel($issueKey, 'myctobot-working');
                $this->log('info', 'Added working label');
            } catch (\Exception $e) {
                $this->log('warning', 'Failed to add working label: ' . $e->getMessage());
            }

            // Step 1: Fetch issue from Jira
            $this->updateStatus(AIDevStatusService::STEP_FETCHING_ISSUE, 5);
            $issue = $this->jira->getIssue($issueKey);

            if (!$issue) {
                throw new \Exception("Issue not found: {$issueKey}");
            }

            // Get all comments for additional context
            $comments = $this->jira->getComments($issueKey);
            $commentText = '';
            foreach ($comments as $comment) {
                $commentText .= JiraClient::extractTextFromAdf($comment['body']) . "\n\n";
            }

            // Step 2: Analyze requirements with full context
            $this->updateStatus(AIDevStatusService::STEP_ANALYZING_REQUIREMENTS, 10);
            $requirements = $this->analyzeRequirements($issue, $commentText);

            // Step 3: Clone repository and checkout existing branch
            $this->updateStatus(AIDevStatusService::STEP_CLONING_REPO, 25);
            $this->cloneRepository();

            // Checkout the existing branch
            $this->updateStatus('Checking out existing branch', 28);
            $this->git->checkout($branchName);
            $this->log('info', 'Checked out existing branch', ['branch' => $branchName]);

            // Step 4: Analyze codebase
            $this->updateStatus(AIDevStatusService::STEP_ANALYZING_CODEBASE, 35);
            $codebaseContext = $this->analyzeCodebase($requirements);

            // Step 5: Plan implementation
            $this->updateStatus(AIDevStatusService::STEP_PLANNING_IMPLEMENTATION, 45);
            $plan = $this->planImplementation($requirements, $codebaseContext);

            // Override branch name to use existing one
            $plan['branch_name'] = $branchName;

            // Step 6: Implement changes (on existing branch)
            $this->updateStatus(AIDevStatusService::STEP_IMPLEMENTING_CHANGES, 55);
            $changes = $this->implementChangesOnExistingBranch($plan, $branchName);

            // Step 7: If no PR exists, create one; otherwise just push updates
            if ($prNumber) {
                // Just update the PR with a comment
                $this->updateStatus('Updating pull request', 90);
                $this->updatePullRequestComment($prNumber, $changes, $plan);

                $prUrl = "https://github.com/{$this->repoConfig['repo_owner']}/{$this->repoConfig['repo_name']}/pull/{$prNumber}";

                AIDevStatusService::prCreated(
                    $this->memberId,
                    $this->jobId,
                    $prUrl,
                    $prNumber,
                    $branchName
                );

                return [
                    'status' => 'success',
                    'pr_url' => $prUrl,
                    'pr_number' => $prNumber,
                    'branch' => $branchName,
                    'files_changed' => count($changes['files'] ?? [])
                ];
            } else {
                // Create new PR
                $this->updateStatus(AIDevStatusService::STEP_CREATING_PR, 85);
                $pr = $this->createPullRequest($issueKey, $issue, $changes, $plan);

                AIDevStatusService::prCreated(
                    $this->memberId,
                    $this->jobId,
                    $pr['url'],
                    $pr['number'],
                    $branchName
                );

                return [
                    'status' => 'success',
                    'pr_url' => $pr['url'],
                    'pr_number' => $pr['number'],
                    'branch' => $branchName,
                    'files_changed' => count($changes['files'] ?? [])
                ];
            }

        } catch (\Exception $e) {
            $this->log('error', 'Retry failed', ['error' => $e->getMessage()]);
            AIDevStatusService::fail($this->memberId, $this->jobId, $e->getMessage());

            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        } finally {
            if ($this->git) {
                $this->git->cleanup();
            }
        }
    }

    /**
     * Implement changes on an existing branch (no branch creation)
     */
    private function implementChangesOnExistingBranch(array $plan, string $branchName): array {
        $changes = [
            'files' => [],
            'commits' => [],
            'branch' => $branchName
        ];

        // Process files to modify
        $progress = 60;
        $totalFiles = count($plan['files_to_modify'] ?? []) + count($plan['files_to_create'] ?? []);
        $progressPerFile = $totalFiles > 0 ? (20 / $totalFiles) : 0;

        foreach ($plan['files_to_modify'] ?? [] as $fileSpec) {
            $path = $fileSpec['path'];
            $action = $fileSpec['action'] ?? 'modify';

            if ($action === 'delete') {
                $this->git->deleteFile($path);
                $changes['files'][] = ['path' => $path, 'action' => 'deleted'];
            } else {
                $currentContent = '';
                try {
                    $currentContent = $this->git->readFile($path);
                } catch (\Exception $e) {
                    // File doesn't exist
                }

                $newContent = $this->generateFileContent($path, $currentContent, $fileSpec, $plan);
                $this->git->writeFile($path, $newContent);
                $changes['files'][] = ['path' => $path, 'action' => 'modified'];
            }

            $progress += $progressPerFile;
            $this->updateStatus("Implementing: {$path}", (int)$progress);
        }

        // Create new files
        foreach ($plan['files_to_create'] ?? [] as $fileSpec) {
            $path = $fileSpec['path'];
            $newContent = $this->generateNewFileContent($path, $fileSpec, $plan);
            $this->git->writeFile($path, $newContent);
            $changes['files'][] = ['path' => $path, 'action' => 'created'];

            $progress += $progressPerFile;
            $this->updateStatus("Creating: {$path}", (int)$progress);
        }

        // Commit changes
        $this->updateStatus(AIDevStatusService::STEP_COMMITTING_CHANGES, 80);
        $this->git->add('.');
        $commitMessage = "Retry: " . $this->generateCommitMessage($plan);
        $commitSha = $this->git->commit($commitMessage);
        $changes['commit_sha'] = $commitSha;

        // Push to remote (force push to update existing branch)
        $this->updateStatus(AIDevStatusService::STEP_PUSHING_CHANGES, 83);
        $this->git->push('origin', $branchName);

        $this->log('info', 'Retry changes committed and pushed', [
            'files_changed' => count($changes['files']),
            'commit' => $commitSha
        ]);

        return $changes;
    }

    /**
     * Add a comment to an existing PR about the retry
     */
    private function updatePullRequestComment(int $prNumber, array $changes, array $plan): void {
        $comment = "## Retry Update\n\n";
        $comment .= "This PR has been updated with a new implementation attempt.\n\n";
        $comment .= "### Changes\n\n";

        foreach ($changes['files'] ?? [] as $file) {
            $action = ucfirst($file['action']);
            $comment .= "- **{$action}**: `{$file['path']}`\n";
        }

        $comment .= "\n### Approach\n\n";
        $comment .= $plan['approach'] ?? 'Updated implementation';
        $comment .= "\n\n---\n*Updated by MyCTOBot AI Developer*\n";

        $this->github->addPRComment(
            $this->repoConfig['repo_owner'],
            $this->repoConfig['repo_name'],
            $prNumber,
            $comment
        );
    }

    // ========================================
    // Analysis Methods
    // ========================================

    /**
     * Analyze issue requirements using Claude
     */
    private function analyzeRequirements(array $issue, ?string $additionalContext = null): array {
        $summary = $issue['fields']['summary'] ?? '';
        $description = JiraClient::extractTextFromAdf($issue['fields']['description'] ?? null);
        $issueType = $issue['fields']['issuetype']['name'] ?? 'Task';
        $priority = $issue['fields']['priority']['name'] ?? 'Medium';
        $labelsArray = $issue['fields']['labels'] ?? [];
        $labels = !empty($labelsArray) ? implode(', ', $labelsArray) : 'None';

        // Get comments for additional context
        $comments = $issue['fields']['comment']['comments'] ?? [];
        $commentText = '';
        foreach (array_slice($comments, -5) as $comment) { // Last 5 comments
            $commentText .= JiraClient::extractTextFromAdf($comment['body']) . "\n\n";
        }

        // Get image attachments
        $images = $this->jira->getIssueImages($issue, 5, 2048); // Max 5 images, 2MB each
        $imageNote = '';
        if (!empty($images)) {
            $imageNote = "\n\nATTACHED IMAGES:\n";
            $imageNote .= count($images) . " screenshot(s) are attached to this ticket. ";
            $imageNote .= "Please analyze them carefully as they show the current issue or expected behavior.\n";
            foreach ($images as $img) {
                $imageNote .= "- {$img['filename']} ({$img['mimeType']})\n";
            }
        }

        $prompt = <<<PROMPT
Analyze this Jira ticket and extract the implementation requirements.

TICKET INFORMATION:
- Type: {$issueType}
- Priority: {$priority}
- Labels: {$labels}

SUMMARY:
{$summary}

DESCRIPTION:
{$description}

COMMENTS:
{$commentText}{$imageNote}
PROMPT;

        if ($additionalContext) {
            $prompt .= "\n\nADDITIONAL CLARIFICATION:\n{$additionalContext}";
        }

        $prompt .= <<<PROMPT


Provide your analysis in JSON format with these fields:
{
    "summary": "Brief summary of what needs to be implemented",
    "requirements": ["List of specific requirements"],
    "acceptance_criteria": ["List of acceptance criteria"],
    "technical_approach": "High-level technical approach",
    "affected_areas": ["List of likely affected code areas"],
    "is_clear": true/false,
    "unclear_aspects": ["List of aspects that need clarification, if any"],
    "questions": ["Specific questions to ask the reporter, if any"],
    "estimated_complexity": "low/medium/high",
    "image_insights": ["Any insights or requirements derived from the attached images"]
}
PROMPT;

        // Use vision-enabled call if we have images
        if (!empty($images)) {
            $this->log('info', 'Analyzing ticket with ' . count($images) . ' image(s)');
            $response = $this->callClaudeWithImages($prompt, $images, 'You are a senior software engineer analyzing requirements for implementation. Pay close attention to any attached screenshots as they show the current issue or expected behavior.');
        } else {
            $response = $this->callClaude($prompt, 'You are a senior software engineer analyzing requirements for implementation.');
        }

        // Parse JSON from response
        $json = $this->extractJson($response);
        if (!$json) {
            $this->log('warning', 'Could not parse requirements analysis, using defaults');
            return [
                'summary' => $summary,
                'requirements' => [$description],
                'is_clear' => true,
                'questions' => []
            ];
        }

        return $json;
    }

    /**
     * Check if clarification is needed
     */
    private function needsClarification(array $requirements): bool {
        return !($requirements['is_clear'] ?? true) && !empty($requirements['questions']);
    }

    /**
     * Post clarification questions to Jira
     */
    private function postClarificationQuestions(string $issueKey, array $questions): array {
        $questionText = "**MyCTOBot AI Developer - Clarification Needed**\n\n";
        $questionText .= "Before I can implement this ticket, I need some clarification:\n\n";

        foreach ($questions as $i => $question) {
            $questionText .= ($i + 1) . ". {$question}\n";
        }

        $questionText .= "\nPlease reply to this comment with your answers, and I'll resume implementation automatically.";

        $comment = $this->jira->addComment($issueKey, $questionText);

        AIDevStatusService::waitingClarification(
            $this->memberId,
            $this->jobId,
            $comment['id'],
            $questions
        );

        $this->log('info', 'Posted clarification questions', [
            'comment_id' => $comment['id'],
            'question_count' => count($questions)
        ]);

        return [
            'status' => 'waiting_clarification',
            'comment_id' => $comment['id'],
            'questions' => $questions
        ];
    }

    // ========================================
    // Repository Methods
    // ========================================

    /**
     * Clone the repository
     */
    private function cloneRepository(): void {
        $cloneUrl = $this->repoConfig['clone_url'];
        $accessToken = EncryptionService::decrypt($this->repoConfig['access_token']);

        $this->git->cloneRepositoryFull($cloneUrl, $accessToken);
        $this->log('info', 'Repository cloned', ['repo' => $this->repoConfig['repo_name']]);
    }

    /**
     * Analyze the codebase for context
     */
    private function analyzeCodebase(array $requirements): array {
        // Get list of files
        $files = $this->git->listFiles('', true);

        // Filter to relevant files based on requirements
        $relevantExtensions = ['.php', '.js', '.ts', '.py', '.java', '.go', '.rb', '.cs'];
        $relevantFiles = array_filter($files, function($file) use ($relevantExtensions) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return in_array('.' . $ext, $relevantExtensions);
        });

        // Build codebase summary
        $codebaseInfo = "# Codebase Structure\n\n";
        $codebaseInfo .= "## Files (" . count($relevantFiles) . " code files)\n\n";

        // Group by directory
        $byDir = [];
        foreach (array_slice($relevantFiles, 0, 100) as $file) { // Limit to 100 files
            $dir = dirname($file);
            $byDir[$dir][] = basename($file);
        }

        foreach ($byDir as $dir => $dirFiles) {
            $codebaseInfo .= "- {$dir}/\n";
            foreach (array_slice($dirFiles, 0, 5) as $f) { // Max 5 files per dir
                $codebaseInfo .= "  - {$f}\n";
            }
            if (count($dirFiles) > 5) {
                $remaining = count($dirFiles) - 5;
                $codebaseInfo .= "  - ... and {$remaining} more\n";
            }
        }

        // Try to find and read key files for context
        $keyFiles = $this->findKeyFiles($relevantFiles, $requirements);
        $fileContents = [];

        foreach ($keyFiles as $file) {
            try {
                $content = $this->git->readFile($file);
                // Truncate large files
                if (strlen($content) > 5000) {
                    $content = substr($content, 0, 5000) . "\n... (truncated)";
                }
                $fileContents[$file] = $content;
            } catch (\Exception $e) {
                $this->log('warning', "Could not read file: {$file}");
            }
        }

        return [
            'structure' => $codebaseInfo,
            'relevant_files' => $keyFiles,
            'file_contents' => $fileContents,
            'total_files' => count($relevantFiles)
        ];
    }

    /**
     * Find key files relevant to the requirements
     */
    private function findKeyFiles(array $files, array $requirements): array {
        $keyFiles = [];
        $affectedAreas = $requirements['affected_areas'] ?? [];

        // Look for files matching affected areas
        foreach ($files as $file) {
            $fileLower = strtolower($file);
            foreach ($affectedAreas as $area) {
                $areaLower = strtolower($area);
                if (strpos($fileLower, $areaLower) !== false) {
                    $keyFiles[] = $file;
                    break;
                }
            }
        }

        // Look for common entry points
        $entryPoints = ['index.php', 'app.php', 'main.php', 'routes.php', 'bootstrap.php'];
        foreach ($files as $file) {
            $basename = basename($file);
            if (in_array($basename, $entryPoints) && !in_array($file, $keyFiles)) {
                $keyFiles[] = $file;
            }
        }

        return array_slice($keyFiles, 0, 10); // Max 10 key files
    }

    // ========================================
    // Implementation Methods
    // ========================================

    /**
     * Plan the implementation with Claude
     */
    private function planImplementation(array $requirements, array $codebaseContext): array {
        $prompt = "# Implementation Planning\n\n";
        $prompt .= "## Requirements\n";
        $prompt .= json_encode($requirements, JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "## Codebase Context\n";
        $prompt .= $codebaseContext['structure'] . "\n\n";

        if (!empty($codebaseContext['file_contents'])) {
            $prompt .= "## Key File Contents\n\n";
            foreach ($codebaseContext['file_contents'] as $file => $content) {
                $prompt .= "### {$file}\n```\n{$content}\n```\n\n";
            }
        }

        $prompt .= <<<PROMPT

Based on the requirements and codebase analysis, create an implementation plan.

Provide your response in JSON format:
{
    "approach": "Description of the implementation approach",
    "files_to_modify": [
        {
            "path": "path/to/file.php",
            "action": "modify|create|delete",
            "changes": "Description of changes to make"
        }
    ],
    "files_to_create": [
        {
            "path": "path/to/newfile.php",
            "purpose": "Description of what this file does"
        }
    ],
    "implementation_steps": [
        "Step 1: ...",
        "Step 2: ..."
    ],
    "testing_notes": "How to test the changes",
    "branch_name": "suggested-branch-name"
}
PROMPT;

        $response = $this->callClaude($prompt, 'You are a senior software engineer planning an implementation. Be precise and thorough.');

        $plan = $this->extractJson($response);
        if (!$plan) {
            throw new \Exception('Could not generate implementation plan');
        }

        $this->log('info', 'Implementation plan created', [
            'files_to_modify' => count($plan['files_to_modify'] ?? []),
            'files_to_create' => count($plan['files_to_create'] ?? [])
        ]);

        return $plan;
    }

    /**
     * Implement the code changes with Claude
     */
    private function implementChanges(array $plan): array {
        $changes = [
            'files' => [],
            'commits' => []
        ];

        // Create feature branch
        $branchName = $plan['branch_name'] ?? 'feature/ai-dev-' . date('Ymd-His');
        $baseBranch = $this->repoConfig['default_branch'] ?? 'main';

        $this->updateStatus(AIDevStatusService::STEP_CREATING_BRANCH, 60);
        $this->git->createBranch($branchName, $baseBranch);
        $changes['branch'] = $branchName;

        $this->log('info', 'Created branch', ['branch' => $branchName]);

        // Process files to modify
        $progress = 60;
        $totalFiles = count($plan['files_to_modify'] ?? []) + count($plan['files_to_create'] ?? []);
        $progressPerFile = $totalFiles > 0 ? (20 / $totalFiles) : 0;

        foreach ($plan['files_to_modify'] ?? [] as $fileSpec) {
            $path = $fileSpec['path'];
            $action = $fileSpec['action'] ?? 'modify';

            if ($action === 'delete') {
                $this->git->deleteFile($path);
                $changes['files'][] = ['path' => $path, 'action' => 'deleted'];
            } else {
                // Get current content
                $currentContent = '';
                try {
                    $currentContent = $this->git->readFile($path);
                } catch (\Exception $e) {
                    // File doesn't exist, will be created
                }

                // Generate new content with Claude
                $newContent = $this->generateFileContent($path, $currentContent, $fileSpec, $plan);
                $this->git->writeFile($path, $newContent);
                $changes['files'][] = ['path' => $path, 'action' => 'modified'];
            }

            $progress += $progressPerFile;
            $this->updateStatus("Implementing: {$path}", (int)$progress);
        }

        // Create new files
        foreach ($plan['files_to_create'] ?? [] as $fileSpec) {
            $path = $fileSpec['path'];
            $newContent = $this->generateNewFileContent($path, $fileSpec, $plan);
            $this->git->writeFile($path, $newContent);
            $changes['files'][] = ['path' => $path, 'action' => 'created'];

            $progress += $progressPerFile;
            $this->updateStatus("Creating: {$path}", (int)$progress);
        }

        // Commit changes
        $this->updateStatus(AIDevStatusService::STEP_COMMITTING_CHANGES, 80);
        $this->git->add('.');
        $commitMessage = $this->generateCommitMessage($plan);
        $commitSha = $this->git->commit($commitMessage);
        $changes['commit_sha'] = $commitSha;

        // Push to remote
        $this->updateStatus(AIDevStatusService::STEP_PUSHING_CHANGES, 83);
        $this->git->push('origin', $branchName);

        $this->log('info', 'Changes committed and pushed', [
            'files_changed' => count($changes['files']),
            'commit' => $commitSha
        ]);

        return $changes;
    }

    /**
     * Generate modified file content using Claude
     */
    private function generateFileContent(string $path, string $currentContent, array $fileSpec, array $plan): string {
        $prompt = "# Code Modification Task\n\n";
        $prompt .= "## File: {$path}\n\n";
        $prompt .= "## Current Content:\n```\n{$currentContent}\n```\n\n";
        $prompt .= "## Required Changes:\n{$fileSpec['changes']}\n\n";
        $prompt .= "## Overall Plan Context:\n{$plan['approach']}\n\n";
        $prompt .= "Provide ONLY the complete modified file content, with no explanation. Start directly with the code.";

        $response = $this->callClaude($prompt, 'You are a senior software engineer. Output only code, no explanations.');

        // Extract code from response (remove markdown code blocks if present)
        $code = $this->extractCode($response);
        return $code;
    }

    /**
     * Generate new file content using Claude
     */
    private function generateNewFileContent(string $path, array $fileSpec, array $plan): string {
        $prompt = "# New File Creation Task\n\n";
        $prompt .= "## File Path: {$path}\n\n";
        $prompt .= "## Purpose: {$fileSpec['purpose']}\n\n";
        $prompt .= "## Overall Plan Context:\n{$plan['approach']}\n\n";
        $prompt .= "## Implementation Steps:\n" . implode("\n", $plan['implementation_steps'] ?? []) . "\n\n";
        $prompt .= "Provide ONLY the complete file content, with no explanation. Start directly with the code.";

        $response = $this->callClaude($prompt, 'You are a senior software engineer. Output only code, no explanations.');

        return $this->extractCode($response);
    }

    /**
     * Generate commit message
     */
    private function generateCommitMessage(array $plan): string {
        $approach = $plan['approach'] ?? 'Implementation';
        $filesCount = count($plan['files_to_modify'] ?? []) + count($plan['files_to_create'] ?? []);

        $message = substr($approach, 0, 50);
        if (strlen($approach) > 50) {
            $message .= '...';
        }

        $message .= "\n\nImplemented via MyCTOBot AI Developer\n";
        $message .= "Files changed: {$filesCount}\n";

        return $message;
    }

    // ========================================
    // Pull Request Methods
    // ========================================

    /**
     * Create a pull request
     */
    private function createPullRequest(string $issueKey, array $issue, array $changes, array $plan): array {
        $summary = $issue['fields']['summary'] ?? 'Implementation';
        $branchName = $changes['branch'];
        $baseBranch = $this->repoConfig['default_branch'] ?? 'main';

        // Generate PR title
        $title = "[{$issueKey}] {$summary}";
        if (strlen($title) > 100) {
            $title = substr($title, 0, 97) . '...';
        }

        // Generate PR body
        $body = $this->generatePRBody($issueKey, $issue, $changes, $plan);

        // Create PR via GitHub API
        $pr = $this->github->createPullRequest(
            $this->repoConfig['repo_owner'],
            $this->repoConfig['repo_name'],
            $title,
            $body,
            $branchName,
            $baseBranch
        );

        $this->log('info', 'Pull request created', [
            'pr_number' => $pr['number'],
            'url' => $pr['html_url']
        ]);

        return [
            'number' => $pr['number'],
            'url' => $pr['html_url'],
            'branch' => $branchName
        ];
    }

    /**
     * Generate pull request body
     */
    private function generatePRBody(string $issueKey, array $issue, array $changes, array $plan): string {
        $summary = $issue['fields']['summary'] ?? '';

        $body = "## Summary\n\n";
        $body .= $plan['approach'] ?? $summary;
        $body .= "\n\n";

        $body .= "## Jira Ticket\n\n";
        $body .= "**{$issueKey}**: {$summary}\n\n";

        $body .= "## Changes\n\n";
        foreach ($changes['files'] ?? [] as $file) {
            $action = ucfirst($file['action']);
            $body .= "- **{$action}**: `{$file['path']}`\n";
        }
        $body .= "\n";

        if (!empty($plan['testing_notes'])) {
            $body .= "## Testing\n\n";
            $body .= $plan['testing_notes'] . "\n\n";
        }

        $body .= "---\n";
        $body .= "*This PR was created automatically by [MyCTOBot AI Developer](https://myctobot.ai)*\n";

        return $body;
    }

    // ========================================
    // Claude API Methods
    // ========================================

    /**
     * Call Claude API with customer's API key
     */
    private function callClaude(string $prompt, string $systemPrompt = ''): string {
        $client = new Client([
            'base_uri' => 'https://api.anthropic.com',
            'headers' => [
                'x-api-key' => $this->customerApiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
        ]);

        $payload = [
            'model' => $this->model,
            'max_tokens' => 4096,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ];

        if ($systemPrompt) {
            $payload['system'] = $systemPrompt;
        }

        $response = $client->post('/v1/messages', [
            'json' => $payload
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        return $data['content'][0]['text'] ?? '';
    }

    /**
     * Call Claude API with images (vision)
     *
     * @param string $prompt Text prompt
     * @param array $images Array of images with ['filename', 'mimeType', 'base64']
     * @param string $systemPrompt Optional system prompt
     * @return string Claude's response
     */
    private function callClaudeWithImages(string $prompt, array $images, string $systemPrompt = ''): string {
        $client = new Client([
            'base_uri' => 'https://api.anthropic.com',
            'headers' => [
                'x-api-key' => $this->customerApiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
        ]);

        // Build content array with images first, then text
        $content = [];

        // Add images as content blocks
        foreach ($images as $image) {
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $image['mimeType'],
                    'data' => $image['base64']
                ]
            ];
        }

        // Add the text prompt
        $content[] = [
            'type' => 'text',
            'text' => $prompt
        ];

        $payload = [
            'model' => $this->model,
            'max_tokens' => 4096,
            'messages' => [
                ['role' => 'user', 'content' => $content]
            ]
        ];

        if ($systemPrompt) {
            $payload['system'] = $systemPrompt;
        }

        $response = $client->post('/v1/messages', [
            'json' => $payload
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        return $data['content'][0]['text'] ?? '';
    }

    /**
     * Extract JSON from Claude response
     */
    private function extractJson(string $response): ?array {
        // Try to find JSON in the response
        if (preg_match('/\{[\s\S]*\}/m', $response, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json !== null) {
                return $json;
            }
        }

        // Try parsing the whole response
        $json = json_decode($response, true);
        return $json ?: null;
    }

    /**
     * Extract code from Claude response (remove markdown blocks)
     */
    private function extractCode(string $response): string {
        // Remove markdown code blocks
        $code = preg_replace('/^```[\w]*\n/m', '', $response);
        $code = preg_replace('/\n```$/m', '', $code);
        return trim($code);
    }

    // ========================================
    // Utility Methods
    // ========================================

    /**
     * Update job status
     */
    private function updateStatus(string $step, int $progress): void {
        AIDevStatusService::updateStatus(
            $this->memberId,
            $this->jobId,
            $step,
            $progress,
            AIDevStatusService::STATUS_RUNNING
        );
    }

    /**
     * Log a message
     */
    private function log(string $level, string $message, array $context = []): void {
        AIDevStatusService::log($this->jobId, $this->memberId, $level, $message, $context);
    }
}
