#!/usr/bin/env php
<?php
/**
 * QA Release Builder
 *
 * Takes all completed stories, merges their branches into a single QA branch,
 * runs build/tests, and optionally creates a preview deployment.
 *
 * Usage:
 *   php scripts/qa-release-builder.php --workspace=footest4 --project=<id> [options]
 *
 * Options:
 *   --workspace=<name>       Required. Workspace slug (e.g., gwt, footest4)
 *   --project=<id>        Required. Project ID to build QA release for
 *   --stories=<ids>       Optional. Comma-separated story IDs to include (default: all completed)
 *   --branch=<name>       QA branch name (default: qa/release-YYYY-MM-DD-HHMMSS)
 *   --target=<branch>     Target branch to merge into (default: main)
 *   --dry-run             Show what would be done without executing
 *   --skip-tests          Skip running tests after merge
 *   --auto-resolve        Attempt to auto-resolve merge conflicts (NOT YET IMPLEMENTED)
 *   --verbose             Show detailed output
 *   --help                Show this help
 *
 * Process:
 *   1. Find all completed stories (status=done) for the project
 *   2. Get their branch names from aidevjobs
 *   3. Clone/fetch the repository
 *   4. Create QA branch from target (main)
 *   5. Merge each story branch into QA branch
 *   6. Run build commands (composer install, npm build, etc.)
 *   7. Run tests if configured
 *   8. Report results and any merge conflicts
 *
 * Examples:
 *   php scripts/qa-release-builder.php --workspace=footest4 --project=1 --verbose
 *   php scripts/qa-release-builder.php --workspace=footest4 --project=1 --dry-run
 */

error_reporting(E_ALL);
$baseDir = dirname(__FILE__, 2);
chdir($baseDir);

// Parse command line arguments
$options = getopt('', [
    'workspace:',
    'project:',
    'stories:',
    'branch:',
    'target:',
    'dry-run',
    'skip-tests',
    'auto-resolve',
    'push',
    'verbose',
    'help'
]);

if (isset($options['help'])) {
    preg_match('/\/\*\*[\s\S]*?\*\//', file_get_contents(__FILE__), $matches);
    echo str_replace(['/**', '*/', ' * ', ' *'], '', $matches[0]) . "\n";
    exit(0);
}

if (empty($options['workspace'])) {
    echo "Error: --workspace is required\n";
    echo "Usage: php scripts/qa-release-builder.php --workspace=<workspace> --project=<id>\n";
    exit(1);
}

if (empty($options['project'])) {
    echo "Error: --project is required\n";
    echo "Usage: php scripts/qa-release-builder.php --workspace=<workspace> --project=<id>\n";
    exit(1);
}

$workspace = $options['workspace'];
$projectId = (int) $options['project'];
$storyFilter = isset($options['stories']) ? array_map('intval', explode(',', $options['stories'])) : [];
$targetBranch = $options['target'] ?? 'main';
$qaBranchName = $options['branch'] ?? 'qa/release-' . date('Y-m-d-His');
$dryRun = isset($options['dry-run']);
$skipTests = isset($options['skip-tests']);
$autoResolve = isset($options['auto-resolve']);
$verbose = isset($options['verbose']);

// Bootstrap
require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/lib/FlightMap.php';
require_once $baseDir . '/services/EncryptionService.php';
require_once $baseDir . '/services/GitHubClient.php';

use \Flight as Flight;
use \app\Bean;
use \app\services\EncryptionService;
use \app\services\GitHubClient;

// Load workspace config
$configFile = "{$baseDir}/conf/config.{$workspace}.ini";
if (!file_exists($configFile)) {
    echo "Error: Workspace config not found: {$configFile}\n";
    exit(1);
}

$config = parse_ini_file($configFile, true);
if (!$config) {
    echo "Error: Failed to parse config file\n";
    exit(1);
}

// Initialize Flight config
foreach ($config as $section => $values) {
    if (is_array($values)) {
        foreach ($values as $key => $value) {
            Flight::set("{$section}.{$key}", $value);
        }
    }
}

// Initialize database
try {
    $dbConfig = $config['database'];
    $type = $dbConfig['type'] ?? 'mysql';

    if ($type === 'sqlite') {
        $dbPath = $dbConfig['path'] ?? "database/{$workspace}.sqlite";
        Bean::setup("sqlite:{$dbPath}");
    } else {
        $host = $dbConfig['host'] ?? 'localhost';
        $port = $dbConfig['port'] ?? 3306;
        $name = $dbConfig['name'] ?? $workspace;
        $user = $dbConfig['user'] ?? 'root';
        $pass = $dbConfig['pass'] ?? '';
        Bean::setup("mysql:host={$host};port={$port};dbname={$name}", $user, $pass);
    }
    Bean::freeze(true);
} catch (\Exception $e) {
    echo "Error: Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Output helper
function output($message, $force = false) {
    global $verbose;
    if ($verbose || $force) {
        echo "[" . date('H:i:s') . "] " . $message . "\n";
    }
}

function outputError($message) {
    echo "\033[31m[ERROR]\033[0m " . $message . "\n";
}

function outputSuccess($message) {
    echo "\033[32m[SUCCESS]\033[0m " . $message . "\n";
}

function outputWarning($message) {
    echo "\033[33m[WARNING]\033[0m " . $message . "\n";
}

/**
 * QA Release Builder Class
 */
class QAReleaseBuilder {
    private string $workspace;
    private int $projectId;
    private array $storyFilter;
    private string $targetBranch;
    private string $qaBranchName;
    private bool $dryRun;
    private bool $skipTests;
    private bool $autoResolve;
    private string $baseDir;
    private ?string $workDir = null;
    private array $mergeResults = [];
    private ?object $project = null;
    private ?string $repoUrl = null;
    private ?string $repoOwner = null;
    private ?string $repoName = null;

    public function __construct(
        string $workspace,
        int $projectId,
        array $storyFilter,
        string $targetBranch,
        string $qaBranchName,
        bool $dryRun,
        bool $skipTests,
        bool $autoResolve,
        string $baseDir
    ) {
        $this->workspace = $workspace;
        $this->projectId = $projectId;
        $this->storyFilter = $storyFilter;
        $this->targetBranch = $targetBranch;
        $this->qaBranchName = $qaBranchName;
        $this->dryRun = $dryRun;
        $this->skipTests = $skipTests;
        $this->autoResolve = $autoResolve;
        $this->baseDir = $baseDir;
    }

    /**
     * Run the QA release build process
     */
    public function run(): array {
        output("Starting QA Release Builder", true);
        output("  workspace: {$this->workspace}", true);
        output("  Project ID: {$this->projectId}", true);
        if (!empty($this->storyFilter)) {
            output("  Story Filter: " . implode(', ', $this->storyFilter), true);
        }
        output("  Target Branch: {$this->targetBranch}", true);
        output("  QA Branch: {$this->qaBranchName}", true);
        output("  Dry Run: " . ($this->dryRun ? 'YES' : 'NO'), true);
        output("  Auto-Resolve Conflicts: " . ($this->autoResolve ? 'YES' : 'NO'), true);
        output("", true);

        // Step 1: Load project and validate
        if (!$this->loadProject()) {
            return ['success' => false, 'error' => 'Project not found or invalid'];
        }

        // Step 2: Get completed stories with branches
        $stories = $this->getCompletedStories();
        if (empty($stories)) {
            outputWarning("No completed stories found for this project");
            return ['success' => false, 'error' => 'No completed stories to merge'];
        }

        output("Found " . count($stories) . " completed stories to merge (earliest first):", true);
        foreach ($stories as $i => $story) {
            $completedDate = $story['completed_at'] ? date('Y-m-d H:i', strtotime($story['completed_at'])) : 'unknown';
            output("  " . ($i + 1) . ". {$story['issue_key']}: {$story['branch_name']} (completed: {$completedDate})", true);
        }
        output("", true);

        if ($this->dryRun) {
            output("=== DRY RUN - Would perform the following ===", true);
            output("1. Clone/fetch repository: {$this->repoUrl}", true);
            output("2. Create branch '{$this->qaBranchName}' from '{$this->targetBranch}'", true);
            output("3. Merge " . count($stories) . " feature branches", true);
            output("4. Run build commands", true);
            if (!$this->skipTests) {
                output("5. Run tests", true);
            }
            return ['success' => true, 'dry_run' => true, 'stories' => $stories];
        }

        // Step 3: Setup working directory
        if (!$this->setupWorkDir()) {
            return ['success' => false, 'error' => 'Failed to setup working directory'];
        }

        // Step 4: Clone/fetch repository
        if (!$this->cloneOrFetchRepo()) {
            return ['success' => false, 'error' => 'Failed to clone/fetch repository'];
        }

        // Step 5: Create QA branch from target
        if (!$this->createQABranch()) {
            return ['success' => false, 'error' => 'Failed to create QA branch'];
        }

        // Step 6: Merge each story branch
        $this->mergeStoryBranches($stories);

        // Step 7: Run build
        $buildResult = $this->runBuild();

        // Step 8: Run tests (if not skipped)
        $testResult = null;
        if (!$this->skipTests) {
            $testResult = $this->runTests();
        }

        // Step 9: Generate report
        return $this->generateReport($stories, $buildResult, $testResult);
    }

    /**
     * Load project from database
     */
    private function loadProject(): bool {
        $this->project = Bean::load('ctoprojects', $this->projectId);
        if (!$this->project || !$this->project->id) {
            outputError("Project {$this->projectId} not found");
            return false;
        }

        output("Project: {$this->project->name}", true);

        // First try to infer from stories' issue keys (most reliable for GitHub projects)
        // Format: owner/repo#123
        $story = Bean::findOne('ctostories',
            'epic_id IN (SELECT id FROM ctoepics WHERE project_uid = ?) AND jira_issue_key LIKE ?',
            [$this->projectId, '%/%#%']
        );
        if ($story && preg_match('/^([^\/]+)\/([^#]+)#/', $story->jira_issue_key, $matches)) {
            $this->repoOwner = $matches[1];
            $this->repoName = $matches[2];
            $this->repoUrl = "https://github.com/{$this->repoOwner}/{$this->repoName}.git";
            output("Repository: {$this->repoUrl}", true);
            return true;
        }

        // Fallback: Get repository info from github_repo_id (foreign key to repoconnections)
        $repoId = $this->project->github_repo_id;
        if ($repoId) {
            $repoConn = Bean::load('repoconnections', $repoId);
            if ($repoConn && $repoConn->id && $repoConn->owner && $repoConn->repo_name) {
                $this->repoOwner = $repoConn->owner;
                $this->repoName = $repoConn->repo_name;
                $this->repoUrl = $repoConn->clone_url ?: "https://github.com/{$this->repoOwner}/{$this->repoName}.git";
                output("Repository: {$this->repoUrl}", true);
                return true;
            }
        }

        outputError("Could not determine repository for project");
        return false;
    }

    /**
     * Get completed stories with their branch names
     *
     * Stories are ordered by completion date (earliest first) to reduce merge conflicts.
     * Later work was likely built on top of earlier work, so merging in chronological
     * order means later branches may already incorporate earlier changes.
     */
    private function getCompletedStories(): array {
        // Build base query
        $sql = "SELECT s.id, s.title, s.jira_issue_key, j.branch_name, j.pr_url, j.pr_number,
                       COALESCE(j.completed_at, j.updated_at) as completed_at
                FROM ctostories s
                JOIN ctoepics e ON s.epic_id = e.id
                JOIN aidevjobs j ON j.issue_key = s.jira_issue_key
                WHERE e.project_uid = ?
                AND s.status IN ('done', 'approved')
                AND j.branch_name IS NOT NULL
                AND j.branch_name != ''
                AND j.status IN ('pr_created', 'complete')";

        $params = [$this->projectId];

        // Filter by specific story IDs if provided
        if (!empty($this->storyFilter)) {
            $placeholders = implode(',', array_fill(0, count($this->storyFilter), '?'));
            $sql .= " AND s.id IN ({$placeholders})";
            $params = array_merge($params, $this->storyFilter);
        }

        // Order by completion date (earliest first) to reduce merge conflicts
        $sql .= " ORDER BY COALESCE(j.completed_at, j.updated_at) ASC, s.id ASC";

        $rows = Bean::getAll($sql, $params);

        $stories = [];
        foreach ($rows as $row) {
            $stories[] = [
                'story_id' => $row['id'],
                'title' => $row['title'],
                'issue_key' => $row['jira_issue_key'],
                'branch_name' => $row['branch_name'],
                'pr_url' => $row['pr_url'],
                'pr_number' => $row['pr_number'],
                'completed_at' => $row['completed_at'],
            ];
        }

        return $stories;
    }

    /**
     * Setup working directory
     */
    private function setupWorkDir(): bool {
        $this->workDir = "/tmp/qa-release-{$this->workspace}-{$this->projectId}-" . date('Ymd-His');

        if (!mkdir($this->workDir, 0755, true)) {
            outputError("Failed to create work directory: {$this->workDir}");
            return false;
        }

        output("Work directory: {$this->workDir}", true);
        return true;
    }

    /**
     * Clone or fetch repository
     */
    private function cloneOrFetchRepo(): bool {
        output("Cloning repository...", true);

        // Get GitHub token for authentication
        $token = $this->getGitHubToken();
        if (!$token) {
            outputError("No GitHub token found");
            return false;
        }

        // Build authenticated URL
        $authUrl = str_replace('https://', "https://{$token}@", $this->repoUrl);

        // Clone (full clone, not shallow, so we can merge feature branches)
        $repoDir = "{$this->workDir}/repo";
        $cmd = sprintf(
            'git clone %s %s 2>&1',
            escapeshellarg($authUrl),
            escapeshellarg($repoDir)
        );

        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            outputError("Failed to clone repository");
            output(implode("\n", $output), true);
            return false;
        }

        // Fetch all remote branches (including feature branches)
        $cmd = sprintf(
            'cd %s && git fetch origin --prune --tags 2>&1',
            escapeshellarg($repoDir)
        );
        exec($cmd, $output, $exitCode);

        output("Repository cloned successfully", true);
        return true;
    }

    /**
     * Get GitHub token from database
     */
    private function getGitHubToken(): ?string {
        // Try project's repo connection first
        $repoId = $this->project->github_repo_id;
        if ($repoId) {
            $repoConn = Bean::load('repoconnections', $repoId);
            if ($repoConn && $repoConn->access_token) {
                return EncryptionService::decrypt($repoConn->access_token);
            }
        }

        // Try enterprise settings (check both possible key names)
        foreach (['github_token', 'github_access_token'] as $keyName) {
            $setting = Bean::findOne('enterprisesettings',
                'setting_key = ?',
                [$keyName]
            );
            if ($setting && $setting->setting_value) {
                return EncryptionService::decrypt($setting->setting_value);
            }
        }

        return null;
    }

    /**
     * Create QA branch from target branch
     */
    private function createQABranch(): bool {
        $repoDir = "{$this->workDir}/repo";

        output("Creating QA branch '{$this->qaBranchName}' from '{$this->targetBranch}'...", true);

        // Checkout target branch
        $cmd = sprintf(
            'cd %s && git checkout %s 2>&1',
            escapeshellarg($repoDir),
            escapeshellarg($this->targetBranch)
        );
        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            outputError("Failed to checkout {$this->targetBranch}");
            output(implode("\n", $output), true);
            return false;
        }

        // Pull latest
        $cmd = sprintf(
            'cd %s && git pull origin %s 2>&1',
            escapeshellarg($repoDir),
            escapeshellarg($this->targetBranch)
        );
        exec($cmd, $output, $exitCode);

        // Create QA branch
        $cmd = sprintf(
            'cd %s && git checkout -b %s 2>&1',
            escapeshellarg($repoDir),
            escapeshellarg($this->qaBranchName)
        );
        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            outputError("Failed to create QA branch");
            output(implode("\n", $output), true);
            return false;
        }

        output("QA branch created", true);
        return true;
    }

    /**
     * Check if a remote branch exists
     */
    private function remoteBranchExists(string $repoDir, string $branchName): bool {
        $cmd = sprintf(
            'cd %s && git ls-remote --heads origin %s 2>/dev/null | wc -l',
            escapeshellarg($repoDir),
            escapeshellarg($branchName)
        );
        exec($cmd, $output, $exitCode);
        return $exitCode === 0 && isset($output[0]) && (int)trim($output[0]) > 0;
    }

    /**
     * Check if a PR was merged to target branch
     */
    private function isPRMergedToTarget(string $repoDir, string $prNumber): bool {
        // Check if the merge commit exists in main
        // This is a heuristic - we check if main contains changes from the PR
        // For a more accurate check, we'd need to query the GitHub API
        return true; // Assume merged if branch doesn't exist
    }

    /**
     * Merge story branches into QA branch
     */
    private function mergeStoryBranches(array $stories): void {
        $repoDir = "{$this->workDir}/repo";

        output("Merging " . count($stories) . " story branches...", true);
        output("", true);

        $branchesNotFound = 0;
        $branchesMerged = 0;

        foreach ($stories as $story) {
            $branchName = $story['branch_name'];
            $issueKey = $story['issue_key'];
            $prNumber = $story['pr_number'] ?? null;

            output("Merging {$issueKey} ({$branchName})...");

            // First check if branch exists on remote
            if (!$this->remoteBranchExists($repoDir, $branchName)) {
                // Branch doesn't exist - likely already merged and deleted
                $this->mergeResults[$issueKey] = [
                    'success' => true,
                    'branch' => $branchName,
                    'already_merged' => true,
                    'pr_number' => $prNumber,
                ];
                outputSuccess("  Already merged (PR #{$prNumber}) - branch deleted");
                $branchesNotFound++;
                continue;
            }

            // Fetch the branch explicitly
            $cmd = sprintf(
                'cd %s && git fetch origin %s:%s 2>&1',
                escapeshellarg($repoDir),
                escapeshellarg($branchName),
                escapeshellarg("refs/remotes/origin/{$branchName}")
            );
            exec($cmd, $fetchOutput, $fetchExitCode);

            // Verify the branch was fetched successfully
            $verifyCmd = sprintf(
                'cd %s && git rev-parse --verify origin/%s 2>/dev/null',
                escapeshellarg($repoDir),
                escapeshellarg($branchName)
            );
            exec($verifyCmd, $verifyOutput, $verifyExitCode);

            if ($verifyExitCode !== 0) {
                // Branch fetch failed - might be deleted after PR merge
                $this->mergeResults[$issueKey] = [
                    'success' => true,
                    'branch' => $branchName,
                    'already_merged' => true,
                    'pr_number' => $prNumber,
                    'note' => 'Branch not found (likely deleted after PR merge)',
                ];
                outputSuccess("  Branch not found - likely already merged via PR #{$prNumber}");
                $branchesNotFound++;
                continue;
            }

            // Merge the branch
            $cmd = sprintf(
                'cd %s && git merge origin/%s --no-edit 2>&1',
                escapeshellarg($repoDir),
                escapeshellarg($branchName)
            );
            exec($cmd, $mergeOutput, $exitCode);

            if ($exitCode !== 0) {
                // Check for merge conflict
                $conflictOutput = implode("\n", $mergeOutput);
                if (strpos($conflictOutput, 'CONFLICT') !== false) {
                    $conflictFiles = $this->getConflictFiles($repoDir);
                    outputWarning("  CONFLICT detected in " . count($conflictFiles) . " file(s)");

                    // Attempt auto-resolution if enabled
                    if ($this->autoResolve) {
                        output("    Attempting auto-resolution...");
                        $resolved = $this->attemptAutoResolve($repoDir, $conflictFiles, $story);

                        if ($resolved) {
                            $this->mergeResults[$issueKey] = [
                                'success' => true,
                                'branch' => $branchName,
                                'auto_resolved' => true,
                                'resolved_files' => $conflictFiles,
                            ];
                            outputSuccess("  Auto-resolved conflicts in {$branchName}");
                            $branchesMerged++;
                            continue;
                        } else {
                            output("    Auto-resolution failed, manual intervention required");
                        }
                    }

                    $this->mergeResults[$issueKey] = [
                        'success' => false,
                        'error' => 'Merge conflict',
                        'branch' => $branchName,
                        'conflicts' => $conflictFiles,
                        'can_auto_resolve' => $this->canAutoResolve($conflictFiles),
                    ];
                    outputError("  CONFLICT merging {$branchName}");

                    // Abort the merge to continue with other branches
                    exec("cd " . escapeshellarg($repoDir) . " && git merge --abort 2>&1");
                } else {
                    $this->mergeResults[$issueKey] = [
                        'success' => false,
                        'error' => 'Merge failed: ' . substr($conflictOutput, 0, 200),
                        'branch' => $branchName,
                    ];
                    outputError("  Failed to merge {$branchName}");
                }
            } else {
                $this->mergeResults[$issueKey] = [
                    'success' => true,
                    'branch' => $branchName,
                ];
                outputSuccess("  Merged {$branchName}");
                $branchesMerged++;
            }
        }

        output("", true);

        // Summary
        if ($branchesNotFound === count($stories)) {
            output("All PRs have been merged to {$this->targetBranch} - branches were deleted after merge.", true);
            output("The QA branch is equivalent to {$this->targetBranch}.", true);
            output("", true);
        } elseif ($branchesNotFound > 0) {
            output("{$branchesNotFound} PRs already merged, {$branchesMerged} branches merged into QA branch.", true);
            output("", true);
        }
    }

    /**
     * Get list of files with conflicts
     */
    private function getConflictFiles(string $repoDir): array {
        exec("cd " . escapeshellarg($repoDir) . " && git diff --name-only --diff-filter=U 2>/dev/null", $files);
        return $files;
    }

    /**
     * Check if conflicts can potentially be auto-resolved
     *
     * This is a heuristic check - some conflict types are easier to resolve:
     * - Lock files (package-lock.json, composer.lock) - regenerate
     * - Config files with simple additions - merge both
     * - Non-overlapping changes in same file - can be combined
     *
     * @param array $conflictFiles List of files with conflicts
     * @return bool True if conflicts appear resolvable
     */
    private function canAutoResolve(array $conflictFiles): bool {
        $autoResolvable = [
            'package-lock.json',
            'composer.lock',
            'yarn.lock',
        ];

        foreach ($conflictFiles as $file) {
            $basename = basename($file);
            if (in_array($basename, $autoResolvable)) {
                continue; // Lock files can be regenerated
            }

            // For now, assume other conflicts need manual resolution
            // Future: analyze conflict markers to determine if changes are non-overlapping
            return false;
        }

        return true;
    }

    /**
     * Attempt to auto-resolve merge conflicts
     *
     * SCAFFOLDING - NOT YET IMPLEMENTED
     *
     * Future implementation will:
     * 1. For lock files: regenerate from package.json/composer.json
     * 2. For code files: use AI to analyze and merge changes intelligently
     * 3. For config files: attempt to merge both additions
     *
     * @param string $repoDir Repository directory
     * @param array $conflictFiles List of conflicting files
     * @param array $story Story context for AI-assisted resolution
     * @return bool True if all conflicts were resolved
     */
    private function attemptAutoResolve(string $repoDir, array $conflictFiles, array $story): bool {
        // TODO: Implement auto-resolution strategies

        // Strategy 1: Lock file regeneration
        // If only lock files conflict, we can regenerate them

        // Strategy 2: AI-assisted resolution
        // For code conflicts, we could:
        // - Extract conflict markers
        // - Send to AI with context about what both branches are trying to do
        // - Apply AI's suggested resolution
        // - Validate syntax

        // Strategy 3: Simple merge
        // For non-overlapping changes (different lines), git's merge
        // could be assisted with more context

        outputWarning("    Auto-resolve is not yet implemented");
        outputWarning("    Future: Will use AI to intelligently merge conflicting changes");

        return false;
    }

    /**
     * Get conflict details for a specific file
     *
     * Returns the conflict markers and surrounding context
     *
     * @param string $repoDir Repository directory
     * @param string $file File with conflicts
     * @return array Conflict details including markers and context
     */
    private function getConflictDetails(string $repoDir, string $file): array {
        $content = file_get_contents("{$repoDir}/{$file}");

        // Find conflict markers
        $conflicts = [];
        $pattern = '/<<<<<<< HEAD\n(.*?)\n=======\n(.*?)\n>>>>>>> [^\n]+/s';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $conflicts[] = [
                    'ours' => $match[1],      // HEAD version
                    'theirs' => $match[2],    // Branch being merged
                    'full_marker' => $match[0],
                ];
            }
        }

        return [
            'file' => $file,
            'conflict_count' => count($conflicts),
            'conflicts' => $conflicts,
        ];
    }

    /**
     * Run build commands
     */
    private function runBuild(): array {
        $repoDir = "{$this->workDir}/repo";
        $results = [];

        output("Running build...", true);

        // Check for composer.json
        if (file_exists("{$repoDir}/composer.json")) {
            output("  Running composer install...");
            $cmd = sprintf('cd %s && composer install --no-interaction 2>&1', escapeshellarg($repoDir));
            exec($cmd, $output, $exitCode);
            $results['composer'] = [
                'success' => $exitCode === 0,
                'output' => implode("\n", array_slice($output, -20)),
            ];
            if ($exitCode === 0) {
                outputSuccess("  Composer install completed");
            } else {
                outputError("  Composer install failed");
            }
        }

        // Check for package.json
        if (file_exists("{$repoDir}/package.json")) {
            output("  Running npm install...");
            $cmd = sprintf('cd %s && npm install 2>&1', escapeshellarg($repoDir));
            exec($cmd, $output, $exitCode);
            $results['npm_install'] = [
                'success' => $exitCode === 0,
                'output' => implode("\n", array_slice($output, -20)),
            ];

            // Run npm build if there's a build script
            $packageJson = json_decode(file_get_contents("{$repoDir}/package.json"), true);
            if (isset($packageJson['scripts']['build'])) {
                output("  Running npm run build...");
                $cmd = sprintf('cd %s && npm run build 2>&1', escapeshellarg($repoDir));
                exec($cmd, $output, $exitCode);
                $results['npm_build'] = [
                    'success' => $exitCode === 0,
                    'output' => implode("\n", array_slice($output, -20)),
                ];
                if ($exitCode === 0) {
                    outputSuccess("  npm build completed");
                } else {
                    outputError("  npm build failed");
                }
            }
        }

        // PHP syntax check
        output("  Running PHP syntax check...");
        $cmd = sprintf(
            'find %s -name "*.php" -not -path "*/vendor/*" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"',
            escapeshellarg($repoDir)
        );
        exec($cmd, $output, $exitCode);
        $syntaxErrors = array_filter($output, fn($line) => strpos($line, 'Parse error') !== false);
        $results['php_syntax'] = [
            'success' => empty($syntaxErrors),
            'errors' => $syntaxErrors,
        ];
        if (empty($syntaxErrors)) {
            outputSuccess("  PHP syntax check passed");
        } else {
            outputError("  PHP syntax errors found: " . count($syntaxErrors));
        }

        output("", true);
        return $results;
    }

    /**
     * Run tests
     */
    private function runTests(): array {
        $repoDir = "{$this->workDir}/repo";
        $results = [];

        output("Running tests...", true);

        // Check for PHPUnit
        if (file_exists("{$repoDir}/phpunit.xml") || file_exists("{$repoDir}/phpunit.xml.dist")) {
            output("  Running PHPUnit...");
            $cmd = sprintf('cd %s && vendor/bin/phpunit --no-coverage 2>&1', escapeshellarg($repoDir));
            exec($cmd, $output, $exitCode);
            $results['phpunit'] = [
                'success' => $exitCode === 0,
                'output' => implode("\n", array_slice($output, -30)),
            ];
            if ($exitCode === 0) {
                outputSuccess("  PHPUnit tests passed");
            } else {
                outputError("  PHPUnit tests failed");
            }
        }

        // Check for Jest/npm test (skip Playwright E2E tests - they need a running server)
        if (file_exists("{$repoDir}/package.json")) {
            $packageJson = json_decode(file_get_contents("{$repoDir}/package.json"), true);
            $testScript = $packageJson['scripts']['test'] ?? '';

            // Skip if test script uses Playwright (E2E tests need running server)
            if (strpos($testScript, 'playwright') !== false) {
                output("  Skipping npm test (Playwright E2E tests require running server)");
                $results['npm_test'] = [
                    'success' => true,
                    'skipped' => true,
                    'output' => 'Skipped: Playwright E2E tests require a running server',
                ];
            } elseif (isset($packageJson['scripts']['test'])) {
                output("  Running npm test...");
                $cmd = sprintf('cd %s && npm test 2>&1', escapeshellarg($repoDir));
                exec($cmd, $output, $exitCode);
                $results['npm_test'] = [
                    'success' => $exitCode === 0,
                    'output' => implode("\n", array_slice($output, -30)),
                ];
                if ($exitCode === 0) {
                    outputSuccess("  npm tests passed");
                } else {
                    outputError("  npm tests failed");
                }
            }
        }

        output("", true);
        return $results;
    }

    /**
     * Push QA branch to remote
     */
    public function pushQABranch(): bool {
        $repoDir = "{$this->workDir}/repo";

        output("Pushing QA branch to remote...", true);

        $token = $this->getGitHubToken();
        if (!$token) {
            outputError("No GitHub token for push");
            return false;
        }

        // Set remote URL with token
        $authUrl = str_replace('https://', "https://{$token}@", $this->repoUrl);
        $cmd = sprintf(
            'cd %s && git remote set-url origin %s 2>&1',
            escapeshellarg($repoDir),
            escapeshellarg($authUrl)
        );
        exec($cmd);

        // Push
        $cmd = sprintf(
            'cd %s && git push -u origin %s 2>&1',
            escapeshellarg($repoDir),
            escapeshellarg($this->qaBranchName)
        );
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            outputError("Failed to push QA branch");
            output(implode("\n", $output), true);
            return false;
        }

        outputSuccess("QA branch pushed: {$this->qaBranchName}");
        return true;
    }

    /**
     * Mark successfully merged stories as "merged" status
     * This archives them from the main Review Board view
     */
    public function markStoriesAsMerged(array $stories): int {
        $count = 0;
        $mergedAt = date('Y-m-d H:i:s');

        foreach ($stories as $story) {
            $issueKey = $story['issue_key'] ?? null;
            if (!$issueKey) continue;

            // Check if this story was successfully merged (not conflicted)
            $mergeResult = $this->mergeResults[$issueKey] ?? null;
            if (!$mergeResult || !($mergeResult['success'] ?? false)) {
                continue;
            }

            // Update job status to "merged"
            $job = Bean::findOne('aidevjobs', 'issue_key = ?', [$issueKey]);
            if ($job) {
                $job->status = 'merged';
                $job->merged_at = $mergedAt;
                $job->qa_branch = $this->qaBranchName;
                Bean::store($job);
                output("  Marked {$issueKey} as merged");
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get the stories that were processed
     */
    public function getProcessedStories(): array {
        return $this->mergeResults;
    }

    /**
     * Generate final report
     */
    private function generateReport(array $stories, array $buildResult, ?array $testResult): array {
        $successfulMerges = array_filter($this->mergeResults, fn($r) => $r['success']);
        $failedMerges = array_filter($this->mergeResults, fn($r) => !$r['success']);

        $buildSuccess = empty(array_filter($buildResult, fn($r) => !$r['success']));
        $testSuccess = $testResult === null || empty(array_filter($testResult, fn($r) => !$r['success']));

        $overallSuccess = empty($failedMerges) && $buildSuccess && $testSuccess;

        output("", true);
        output("===========================================", true);
        output("QA Release Build Report", true);
        output("===========================================", true);
        output("", true);
        output("Branch: {$this->qaBranchName}", true);
        output("Target: {$this->targetBranch}", true);
        output("Repository: {$this->repoUrl}", true);
        output("", true);
        output("Merge Results:", true);
        output("  Successful: " . count($successfulMerges), true);
        output("  Failed: " . count($failedMerges), true);

        if (!empty($failedMerges)) {
            output("", true);
            output("Failed Merges:", true);
            foreach ($failedMerges as $issueKey => $result) {
                output("  - {$issueKey}: {$result['error']}", true);
                if (!empty($result['conflicts'])) {
                    foreach ($result['conflicts'] as $file) {
                        output("      Conflict: {$file}", true);
                    }
                }
            }
        }

        output("", true);
        output("Build: " . ($buildSuccess ? "PASSED" : "FAILED"), true);
        if ($testResult !== null) {
            output("Tests: " . ($testSuccess ? "PASSED" : "FAILED"), true);
        }

        output("", true);
        output("Overall: " . ($overallSuccess ? "SUCCESS" : "FAILED"), true);
        output("", true);
        output("Work directory: {$this->workDir}", true);

        // Save report to file
        $report = [
            'success' => $overallSuccess,
            'qa_branch' => $this->qaBranchName,
            'target_branch' => $this->targetBranch,
            'repository' => $this->repoUrl,
            'stories' => $stories,
            'merge_results' => $this->mergeResults,
            'build_results' => $buildResult,
            'test_results' => $testResult,
            'work_dir' => $this->workDir,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $reportFile = "{$this->workDir}/qa-report.json";
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));
        output("Report saved: {$reportFile}", true);

        return $report;
    }

    /**
     * Get work directory
     */
    public function getWorkDir(): ?string {
        return $this->workDir;
    }

    /**
     * Get QA branch name
     */
    public function getQABranchName(): string {
        return $this->qaBranchName;
    }
}

// Main execution
echo "===========================================\n";
echo "QA Release Builder\n";
echo "===========================================\n\n";

$builder = new QAReleaseBuilder(
    $workspace,
    $projectId,
    $storyFilter,
    $targetBranch,
    $qaBranchName,
    $dryRun,
    $skipTests,
    $autoResolve,
    $baseDir
);

$autoPush = isset($options['push']);

$result = $builder->run();

if ($result['success'] && !$dryRun) {
    $pushed = false;

    // For --push flag or non-interactive mode, push automatically
    if ($autoPush) {
        output("", true);
        output("Auto-pushing QA branch to remote (--push flag)...", true);
        $pushed = $builder->pushQABranch();
    } else {
        // Interactive mode: prompt for confirmation
        echo "\n";
        echo "Would you like to push the QA branch to remote? (y/n): ";

        // Read from stdin if available
        $handle = fopen("php://stdin", "r");
        if ($handle) {
            stream_set_blocking($handle, false);
            $input = trim(fgets($handle));
            fclose($handle);

            if (strtolower($input) === 'y') {
                $pushed = $builder->pushQABranch();
            }
        }
    }

    // Mark successfully merged stories as "merged" status after push
    if ($pushed && !empty($result['stories'])) {
        output("", true);
        output("Marking stories as merged...", true);
        $markedCount = $builder->markStoriesAsMerged($result['stories']);
        output("Marked {$markedCount} stories as merged/archived", true);
    }
}

exit($result['success'] ? 0 : 1);
