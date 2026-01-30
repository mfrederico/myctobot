#!/usr/bin/env php
<?php
/**
 * Local AI Developer Runner
 *
 * Uses YOUR Claude Code subscription instead of API credits.
 * Runs Claude in a tmux session you can monitor.
 *
 * Sets up the environment similar to the shard (GITHUB_TOKEN, JIRA_*, etc.)
 * and builds the same comprehensive prompt with full Jira ticket content.
 *
 * Usage: php scripts/job-dispatcher.php --issue=SSI-1883 [--orchestrator] [--attach]
 */

error_reporting(E_ALL);
$baseDir = dirname(__FILE__, 2);
chdir($baseDir);

// Parse CLI args
$options = getopt('', [
    'issue:',
    'member:',
    'job-id:',
    'workspace:',
    'repo:',
    'provider:',  // jira or github
    'aoe-session:',  // AOE session ID (passed from TmuxService)
    'workstation:',  // JSON workstation config: {"host":"...", "user":"...", "port":22}
    'prompt-file:',  // Pre-built prompt file (for pipeline triggers)
    'attach',
    'repo-path:',
    'print',
    'dry-run',
    'skip-jira',
    'help'
]);

if (isset($options['help']) || empty($options['issue'])) {
    echo "Local AI Developer Runner - Uses YOUR Claude subscription\n\n";
    echo "Usage: php scripts/job-dispatcher.php --issue=SSI-1883 [options]\n\n";
    echo "Options:\n";
    echo "  --issue         Issue key (e.g., SSI-1883) [required]\n";
    echo "  --member        Member ID (default: 3)\n";
    echo "  --workspace        Workspace slug for multi-workspace (e.g., gwt)\n";
    echo "  --job-id        Job ID for status tracking (auto-generated if not provided)\n";
    echo "  --repo          Repository connection ID (from repo-{slug} label)\n";
    echo "  --workstation   JSON workstation config to run Claude via SSH\n";
    echo "                  e.g., '{\"host\":\"127.0.0.1\",\"user\":\"mfrederico\",\"port\":22}'\n";
    echo "  --attach        Attach to tmux session immediately\n";
    echo "  --print         Use --print mode (non-interactive, outputs to log)\n";
    echo "  --repo-path     Path to existing repo clone\n";
    echo "  --dry-run       Test database queries without spawning Claude\n";
    echo "  --skip-jira     Skip Jira API calls (for DB testing only)\n";
    echo "  --help          Show this help\n\n";
    echo "This runs Claude Code using YOUR logged-in account,\n";
    echo "not the Anthropic API, saving API credits.\n";
    exit(1);
}

require_once $baseDir . '/vendor/autoload.php';  // Includes AOE-PHP via composer
require_once $baseDir . '/bootstrap.php';
require_once $baseDir . '/lib/plugins/AtlassianAuth.php';
require_once $baseDir . '/services/JiraClient.php';
require_once $baseDir . '/services/GitHubClient.php';
require_once $baseDir . '/services/EncryptionService.php';
require_once $baseDir . '/services/AIDevAgentOrchestrator.php';
require_once $baseDir . '/services/AIDevStatusService.php';
require_once $baseDir . '/services/ShopifyClient.php';
require_once $baseDir . '/services/UserDatabaseService.php';

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;
use Aoe\Session\Instance as AoeInstance;
use Aoe\Session\Storage as AoeStorage;
use Aoe\Session\Status as AoeStatus;
use Aoe\Tmux\TmuxService as AoeTmux;
use Aoe\Workspace\WorkspaceContext;
use \app\services\JiraClient;
use \app\services\GitHubClient;
use \app\services\EncryptionService;
use \app\services\ShopifyClient;
use \app\services\AIDevStatusService;
use \app\services\UserDatabaseService;
use \app\plugins\AtlassianAuth;

// Determine config file based on workspace parameter
$workspace = $options['workspace'] ?? null;
if ($workspace) {
    $configFile = $baseDir . "/conf/config.{$workspace}.ini";
    if (!file_exists($configFile)) {
        echo "Error: Workspace config not found: {$configFile}\n";
        exit(1);
    }
} else {
    $configFile = $baseDir . '/conf/config.ini';
}

$bootstrap = new \app\Bootstrap($configFile);

$issueKey = $options['issue'];
$memberId = isset($options['member']) ? (int)$options['member'] : 3;
$jobId = $options['job-id'] ?? null;
$repoIdParam = isset($options['repo']) ? (int)$options['repo'] : null;
$provider = $options['provider'] ?? 'jira';  // jira, github, or pipeline
$autoAttach = isset($options['attach']);
$usePrintMode = isset($options['print']);
$dryRun = isset($options['dry-run']);
$residentMode = false;  // Set true for pipeline jobs with resident Claude
$pipelineStepName = null;  // For session naming: aoe-{workspace}-PIPE-{run_id}-{step_name}

// Detect pipeline-triggered jobs by PIPE- prefix in issue_key
$isPipelineJob = str_starts_with($issueKey, 'PIPE-');
if ($isPipelineJob) {
    $provider = 'pipeline';
}

$skipJira = isset($options['skip-jira']) || $provider === 'github' || $provider === 'pipeline';
$repoPath = $options['repo-path'] ?? null;

// Parse workstation config (for running Claude on remote workstation via SSH)
$workstationConfig = null;
if (!empty($options['workstation'])) {
    $workstationConfig = json_decode($options['workstation'], true);
    if (!$workstationConfig || empty($workstationConfig['host']) || empty($workstationConfig['user'])) {
        echo "Error: Invalid workstation config. Expected JSON with host and user.\n";
        exit(1);
    }
    $workstationConfig['port'] = $workstationConfig['port'] ?? 22;
    echo "Running on workstation: {$workstationConfig['user']}@{$workstationConfig['host']}:{$workstationConfig['port']}\n";
}

// Get domain/workspace identifier using AOE (single source of truth)
$workspaceSlug = $workspace ?: AoeTmux::getDomainId();
workspaceContext::set($workspaceSlug);

// Initialize AOE storage for session tracking (/tmp/.aoe-php for CLI/web consistency)
$aoeBasePath = '/tmp/.aoe-php';
$aoeStorage = new AoeStorage($workspaceSlug, $aoeBasePath);

// Check if AOE session ID was passed (from TmuxService)
$aoeSessionId = $options['aoe-session'] ?? null;

if ($aoeSessionId) {
    // Load existing session created by TmuxService
    $aoeSession = $aoeStorage->find($aoeSessionId);
    if (!$aoeSession) {
        echo "Warning: Could not load AOE session {$aoeSessionId}, creating new one\n";
        $aoeSession = AoeInstance::create(
            workspaceId: $workspaceSlug,
            title: $issueKey,
            projectPath: '/tmp',
            tool: 'claude',
            reference: $issueKey
        );
    }
} else {
    // Create new AOE session (manual run without TmuxService)
    $aoeSession = AoeInstance::create(
        workspaceId: $workspaceSlug,
        title: $issueKey,
        projectPath: '/tmp',
        tool: 'claude',
        reference: $issueKey
    );
}

// Session name format: aoe-{workspace}-{issue}-{shortId}
$sessionName = $aoeSession->getTmuxName();
$safeIssueKey = AoeTmux::sanitize($issueKey);
$workDir = "/tmp/{$sessionName}";

// Update session with actual work directory
$aoeSession->projectPath = $workDir;

// Look up cloudId from member's Atlassian connection (or shared)
$cloudId = null;
if ($provider === 'jira') {
    $atlassianTokenCheck = Bean::findOne('atlassiantoken', '(member_id = ? OR is_shared = 1)', [$memberId]);
    if ($atlassianTokenCheck) {
        $cloudId = $atlassianTokenCheck->cloud_uid;
    } else {
        echo "ERROR: No Atlassian token found for member {$memberId}\n";
        echo "Please connect your Atlassian account first.\n";
        exit(1);
    }
}

// Minimal PHP output - full banner is in generated bash script
echo "Preparing AI Developer session for {$issueKey}...\n";
if ($dryRun) echo "DRY RUN: YES (testing DB queries only)\n";

// Create work directory structure (like shard does)
if (!is_dir($workDir)) {
    mkdir($workDir, 0755, true);
}
// Clean repo dir if exists (for re-runs) - let git clone create it fresh
if (is_dir("{$workDir}/repo")) {
    exec("rm -rf " . escapeshellarg("{$workDir}/repo"));
}
@mkdir("{$workDir}/attachments", 0755, true);

// ============================================
// Fetch Full Jira Issue Details
// ============================================
// Initialize variables that might be set by Jira
$summary = '';
$description = '';
$issueType = 'Task';
$priority = 'Medium';
$status = 'Unknown';
$commentText = '';
$attachmentInfo = '';
$linkedIssuesInfo = '';
$urlsToCheck = [];

if ($provider === 'github') {
    // ============================================
    // Fetch GitHub Issue Details
    // ============================================
    echo "Fetching issue details from GitHub...\n";
    try {
        // Parse GitHub issue key: owner/repo#number
        if (!preg_match('/^([^\/]+)\/([^#]+)#(\d+)$/', $issueKey, $matches)) {
            echo "Error: Invalid GitHub issue format. Expected: owner/repo#number\n";
            exit(1);
        }
        $ghOwner = $matches[1];
        $ghRepo = $matches[2];
        $ghIssueNumber = (int)$matches[3];

        // Get GitHub token from enterprisesettings
        UserDatabaseService::connect($memberId);
        $tokenSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);
        if (!$tokenSetting || empty($tokenSetting->setting_value)) {
            echo "Error: No GitHub token found for member {$memberId}\n";
            exit(1);
        }
        $githubToken = EncryptionService::decrypt($tokenSetting->setting_value);
        $githubClient = new GitHubClient($githubToken);

        // Fetch issue details
        $issue = $githubClient->getIssue($ghOwner, $ghRepo, $ghIssueNumber);

        $summary = $issue['title'] ?? '';
        $description = $issue['body'] ?? '';
        $issueType = 'Issue';
        $status = $issue['state'] ?? 'open';
        $priority = 'Medium';

        // Get labels
        $labels = array_map(fn($l) => $l['name'], $issue['labels'] ?? []);
        if (in_array('bug', $labels)) $issueType = 'Bug';
        if (in_array('enhancement', $labels)) $issueType = 'Enhancement';
        if (in_array('feature', $labels)) $issueType = 'Feature';

        echo "  Title: {$summary}\n";
        echo "  Type: {$issueType}\n";
        echo "  State: {$status}\n";
        echo "  Labels: " . implode(', ', $labels) . "\n";

        // Fetch comments
        try {
            $comments = $githubClient->getIssueComments($ghOwner, $ghRepo, $ghIssueNumber);
            $commentCount = count($comments);
            echo "  Comments: {$commentCount}\n";

            foreach (array_slice($comments, -10) as $comment) {
                $author = $comment['user']['login'] ?? 'Unknown';
                $created = $comment['created_at'] ?? '';
                $body = $comment['body'] ?? '';
                $commentText .= "**{$author}** ({$created}):\n{$body}\n\n";
            }
        } catch (Exception $e) {
            echo "  Warning: Could not fetch comments: " . $e->getMessage() . "\n";
        }

        // Extract URLs from description and comments
        $urlsToCheck = extractUrls($description . ' ' . $commentText);
        if (!empty($urlsToCheck)) {
            echo "  URLs found: " . count($urlsToCheck) . "\n";
        }

        echo "\n";

    } catch (Exception $e) {
        echo "Error fetching GitHub issue: " . $e->getMessage() . "\n";
        exit(1);
    }
} elseif ($isPipelineJob) {
    echo "Pipeline-triggered job: {$issueKey}\n";
    $summary = "Pipeline Task {$issueKey}";
    $description = "Task triggered by pipeline execution";
    // For pipeline jobs, the full context is in the prompt-file
} elseif ($skipJira) {
    echo "Skipping issue API calls (--skip-jira flag)\n";
    $summary = "Test ticket {$issueKey}";
    $description = "Test description for database query testing";
} else {
    // ============================================
    // Fetch Jira Issue Details
    // ============================================
    echo "Fetching issue details from Jira...\n";
    try {
        $jiraClient = new JiraClient($memberId, $cloudId);
        $issue = $jiraClient->getIssue($issueKey);

        // Extract basic fields
        $summary = $issue['fields']['summary'] ?? '';
        $description = JiraClient::extractTextFromAdf($issue['fields']['description'] ?? null);
        $issueType = $issue['fields']['issuetype']['name'] ?? 'Task';
        $priority = $issue['fields']['priority']['name'] ?? 'Medium';
        $status = $issue['fields']['status']['name'] ?? 'Unknown';

        echo "  Summary: {$summary}\n";
        echo "  Type: {$issueType}\n";
        echo "  Priority: {$priority}\n";
        echo "  Status: {$status}\n";

        // Extract comments (last 10)
        $comments = $issue['fields']['comment']['comments'] ?? [];
        $commentText = '';
        $commentCount = count($comments);
        echo "  Comments: {$commentCount}\n";
        foreach (array_slice($comments, -10) as $comment) {
            $author = $comment['author']['displayName'] ?? 'Unknown';
            $created = $comment['created'] ?? '';
            $body = JiraClient::extractTextFromAdf($comment['body']);
            $commentText .= "**{$author}** ({$created}):\n{$body}\n\n";
        }

        // Extract attachments
        $attachments = $issue['fields']['attachment'] ?? [];
        $attachmentInfo = '';
        if (!empty($attachments)) {
            echo "  Attachments: " . count($attachments) . "\n";
            $attachmentInfo = "## Attachments\n";
            $attachmentInfo .= "The following files are attached to this ticket. Download and examine them as needed:\n\n";
            foreach ($attachments as $att) {
                $attachmentInfo .= "- **{$att['filename']}** ({$att['mimeType']}, " . number_format($att['size']) . " bytes)\n";
                $attachmentInfo .= "  Download URL: {$att['content']}\n";
            }
        }

        // Extract URLs from description and comments
        $urlsToCheck = extractUrls($description . ' ' . $commentText);
        if (!empty($urlsToCheck)) {
            echo "  URLs found: " . count($urlsToCheck) . "\n";
        }

        // Get linked issues
        $linkedIssues = $issue['fields']['issuelinks'] ?? [];
        $linkedIssuesInfo = '';
        if (!empty($linkedIssues)) {
            echo "  Linked issues: " . count($linkedIssues) . "\n";
            $linkedIssuesInfo = "## Linked Issues\n";
            foreach ($linkedIssues as $link) {
                $linkType = $link['type']['name'] ?? 'Related';
                if (isset($link['outwardIssue'])) {
                    $linkedKey = $link['outwardIssue']['key'];
                    $linkedSummary = $link['outwardIssue']['fields']['summary'] ?? '';
                    $linkedIssuesInfo .= "- {$linkType}: [{$linkedKey}] {$linkedSummary}\n";
                } elseif (isset($link['inwardIssue'])) {
                    $linkedKey = $link['inwardIssue']['key'];
                    $linkedSummary = $link['inwardIssue']['fields']['summary'] ?? '';
                    $linkedIssuesInfo .= "- {$linkType}: [{$linkedKey}] {$linkedSummary}\n";
                }
            }
        }

        echo "\n";

    } catch (Exception $e) {
        echo "Error fetching Jira issue: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// ============================================
// Get Repository Configuration
// ============================================
echo "Getting repository configuration...\n";
$member = Bean::load('member', $memberId);
if (!$member || !$member->id) {
    echo "Error: Member not found\n";
    exit(1);
}

// Connect to user's database context for repoconnections, jiraboards, aiagents
UserDatabaseService::connect($memberId);

// Pipeline jobs don't require a repo - they get agent directly from aidevjobs
$repoBean = null;
$agentConfig = null;
$agentBean = null;
$githubToken = '';
$repoOwner = '';
$repoName = '';
$defaultBranch = 'main';
$cloneUrl = '';

// Initialize job_uid for later use
$jobUid = '';

if ($isPipelineJob) {
    echo "Pipeline job - loading agent from job record...\n";

    // Load the existing job record to get agent_id and job_uid
    $existingJob = Bean::findOne('aidevjobs', 'issue_key = ?', [$issueKey]);
    if ($existingJob) {
        $jobUid = $existingJob->job_uid ?? '';  // Store for API auth later

        if ($existingJob->aiagents_id) {
            $agentBean = Bean::load('aiagents', $existingJob->aiagents_id);
            if ($agentBean && $agentBean->id && $agentBean->is_active) {
                $agentConfig = [
                    'id' => $agentBean->id,
                    'name' => $agentBean->name,
                    'provider' => $agentBean->provider ?: 'claude_cli',
                    'provider_config' => json_decode($agentBean->provider_config ?: '{}', true),
                    'mcp_servers' => json_decode($agentBean->mcp_servers ?: '[]', true),
                    'hooks_config' => json_decode($agentBean->hooks_config ?: '{}', true),
                    'runners_id' => $agentBean->runners_id ?? null
                ];
                echo "  Agent: {$agentConfig['name']}\n";
            }
        }

        // Check for resident mode - Claude stays alive for pipeline duration
        $queueMeta = json_decode($existingJob->queue_metadata ?? '{}', true);
        $residentMode = $queueMeta['resident_mode'] ?? false;
        $pipelineStepName = $queueMeta['step_name'] ?? null;

        if ($residentMode) {
            // Resident mode: override --print flag to keep Claude interactive
            $usePrintMode = false;
            echo "  Mode: Resident (Claude stays alive for pipeline)\n";
        } else {
            echo "  Mode: Task (single execution)\n";
        }
        if ($pipelineStepName) {
            echo "  Step: {$pipelineStepName}\n";
        }
    }

    // Try to get GitHub token from settings anyway (might be needed for some MCP servers)
    $githubSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);
    if ($githubSetting && !empty($githubSetting->setting_value)) {
        try {
            $encryption = new EncryptionService();
            $githubToken = $encryption->decrypt($githubSetting->setting_value);
        } catch (Exception $e) {
            // Not critical for pipeline jobs
        }
    }
} elseif ($repoIdParam) {
    echo "  Using specific repo ID: {$repoIdParam}\n";
    $repoBean = Bean::load('repoconnections', $repoIdParam);
    if (!$repoBean || !$repoBean->id) {
        echo "Error: Repository not found\n";
        exit(1);
    }
} else {
    $repoBean = Bean::findOne('repoconnections', 'enabled = 1');
    if (!$repoBean) {
        echo "Error: No enabled repository found\n";
        exit(1);
    }
}

// For non-pipeline jobs, load agent from repo association and configure repo
if (!$isPipelineJob && $repoBean) {
    // Load agent configuration for this repo (via association)
    $agentBean = $repoBean->aiagents;
    if ($agentBean && $agentBean->id && $agentBean->is_active) {
        $agentConfig = [
            'id' => $agentBean->id,
            'name' => $agentBean->name,
            'provider' => $agentBean->provider ?: 'claude_cli',
            'provider_config' => json_decode($agentBean->provider_config ?: '{}', true),
            'mcp_servers' => json_decode($agentBean->mcp_servers ?: '[]', true),
            'hooks_config' => json_decode($agentBean->hooks_config ?: '{}', true),
            'runners_id' => $agentBean->runners_id ?? null
        ];
        $providerLabel = $agentConfig['provider'];
        if (!empty($agentConfig['provider_config']['use_ollama'])) {
            $providerLabel .= ' + Ollama';
        }
        echo "  Agent: {$agentConfig['name']} ({$providerLabel})\n";
    }

    // Extract repo settings
    $repoOwner = $repoBean->repo_owner;
    $repoName = $repoBean->repo_name;
    $defaultBranch = $repoBean->default_branch ?? 'main';
    $cloneUrl = $repoBean->clone_url;

    // Get GitHub token - check repoconnections first
    if (!empty($repoBean->access_token)) {
        try {
            $encryption = new EncryptionService();
            $githubToken = $encryption->decrypt($repoBean->access_token);
            echo "  GitHub Token (from repo): ****" . substr($githubToken, -4) . "\n";
        } catch (Exception $e) {
            echo "  Warning: Could not decrypt repo GitHub token\n";
        }
    }
}

// Fall back to enterprisesettings.github_token if no token yet
if (empty($githubToken)) {
    $githubSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);
    if ($githubSetting && !empty($githubSetting->setting_value)) {
        try {
            $encryption = new EncryptionService();
            $githubToken = $encryption->decrypt($githubSetting->setting_value);
            if (!$isPipelineJob) {
                echo "  GitHub Token (from settings): ****" . substr($githubToken, -4) . "\n";
            }
        } catch (Exception $e) {
            if (!$isPipelineJob) {
                echo "  Warning: Could not decrypt settings GitHub token\n";
            }
        }
    }
}

// If no agent assigned, check for default agent (workspace-level)
if (!$agentConfig) {
    $defaultAgent = Bean::findOne('aiagents', 'is_default = 1 AND is_active = 1');
    if ($defaultAgent) {
        $agentConfig = [
            'id' => $defaultAgent->id,
            'name' => $defaultAgent->name,
            'provider' => $defaultAgent->provider ?: 'claude_cli',
            'provider_config' => json_decode($defaultAgent->provider_config ?: '{}', true),
            'mcp_servers' => json_decode($defaultAgent->mcp_servers ?: '[]', true),
            'hooks_config' => json_decode($defaultAgent->hooks_config ?: '{}', true),
            'runners_id' => $defaultAgent->runners_id ?? null
        ];
        echo "  Agent: {$agentConfig['name']} (default)\n";
        $agentBean = $defaultAgent;
    } else {
        echo "  Agent: None (using built-in defaults)\n";
    }
}

// Jira credentials and repo cloning - only for non-pipeline jobs
$jiraHost = '';
$jiraEmail = '';
$jiraToken = '';
$jiraOAuthToken = '';
$jiraSiteUrl = '';
$repoDir = "{$workDir}/repo";

if (!$isPipelineJob) {
    // Get Jira Credentials
    $jiraHost = "https://api.atlassian.com/ex/jira/{$cloudId}";
    try {
        $atlassianToken = Bean::findOne('atlassiantoken', '(member_id = ? OR is_shared = 1) AND cloud_uid = ?', [$memberId, $cloudId]);
        if ($atlassianToken) {
            $jiraEmail = $atlassianToken->email ?? '';
            $siteUrl = $atlassianToken->site_url ?? '';
            if ($siteUrl) {
                $jiraSiteUrl = rtrim($siteUrl, '/');
            }
            $jiraOAuthToken = AtlassianAuth::getValidToken($memberId, $cloudId);
            echo "  Jira Host: {$jiraHost}\n";
            echo "  Jira Email: {$jiraEmail}\n";
            if ($jiraSiteUrl) {
                echo "  Jira Site: {$jiraSiteUrl}\n";
            }
        }
    } catch (Exception $e) {
        echo "  Warning: Could not get Jira credentials: " . $e->getMessage() . "\n";
    }

    echo "  Repo: {$repoOwner}/{$repoName}\n";
    echo "  Branch: {$defaultBranch}\n\n";

    // Pre-clone the repository
    echo "Cloning repository...\n";
    if (!empty($githubToken) && !empty($repoOwner) && !empty($repoName)) {
        $cloneCmd = "git clone https://{$githubToken}@github.com/{$repoOwner}/{$repoName}.git " . escapeshellarg($repoDir) . " 2>&1";
        exec($cloneCmd, $cloneOutput, $cloneExitCode);
        if ($cloneExitCode === 0) {
            echo "  Repository cloned successfully\n";
            exec("git -C " . escapeshellarg($repoDir) . " checkout " . escapeshellarg($defaultBranch) . " 2>&1");
        } else {
            echo "  Warning: Clone failed - " . implode("\n", $cloneOutput) . "\n";
        }
    } else {
        echo "  Warning: No GitHub token or repo info, cannot pre-clone\n";
    }

    // Add MyCTOBot generated files to .gitignore
    if (is_dir($repoDir)) {
        $gitignorePath = "{$repoDir}/.gitignore";
        $ignorePatterns = ['# MyCTOBot generated files', '.mcp.json', '.claude/', 'send-checkpoint.sh', 'ssh-claude.sh', ''];
        $existingIgnore = file_exists($gitignorePath) ? file_get_contents($gitignorePath) : '';
        $newPatterns = [];
        foreach ($ignorePatterns as $pattern) {
            if (!empty($pattern) && strpos($existingIgnore, $pattern) === false) {
                $newPatterns[] = $pattern;
            }
        }
        if (!empty($newPatterns)) {
            file_put_contents($gitignorePath, "\n" . implode("\n", $newPatterns) . "\n", FILE_APPEND);
            echo "  Added MyCTOBot patterns to .gitignore\n";
        }
    }
} else {
    echo "  Pipeline job - skipping repo clone\n";
}
echo "\n";

// ============================================
// Get Board Status Settings
// ============================================
$projectKey = explode('-', $issueKey)[0] ?? '';
$statusSettings = [
    'working' => null,
    'pr_created' => null,
    'clarification' => null,
    'failed' => null,
    'complete' => null
];

if (!empty($projectKey)) {
    // Query jiraboards using Bean (user SQLite database)
    $boardBean = Bean::findOne('boards', 'project_key = ?', [$projectKey]);
    if ($boardBean) {
        $statusSettings = [
            'working' => $boardBean->aidev_status_working ?? null,
            'pr_created' => $boardBean->aidev_status_pr_created ?? null,
            'clarification' => $boardBean->aidev_status_clarification ?? null,
            'failed' => $boardBean->aidev_status_failed ?? null,
            'complete' => $boardBean->aidev_status_complete ?? null
        ];
        echo "Board status settings found for project {$projectKey}:\n";
        foreach ($statusSettings as $key => $value) {
            if ($value) {
                echo "  - {$key}: {$value}\n";
            }
        }
        echo "\n";
    }
}

// ============================================
// Get Shopify Settings
// ============================================
$shopifyEnabled = false;
$shopifyDomain = '';
$shopifyAccessToken = '';
$shopifyStorefrontPassword = '';
$shopifyPreviewUrl = '';

echo "Getting Shopify configuration...\n";
try {
    $shopifyClient = new ShopifyClient($memberId);

    if ($shopifyClient->isConnected()) {
        $shopifyEnabled = true;
        $shopifyDomain = $shopifyClient->getShop();
        $shopifyAccessToken = $shopifyClient->getAccessToken();

        // Get storefront password from enterprisesettings using Bean (user SQLite database)
        $storefrontPwdSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['shopify_storefront_password']);
        if ($storefrontPwdSetting && $storefrontPwdSetting->setting_value) {
            $shopifyStorefrontPassword = EncryptionService::decrypt($storefrontPwdSetting->setting_value);
        }

        echo "  Shopify Store: {$shopifyDomain}\n";
        echo "  Shopify Token: ****" . substr($shopifyAccessToken, -4) . "\n";
        if ($shopifyStorefrontPassword) {
            echo "  Storefront Password: ****" . substr($shopifyStorefrontPassword, -4) . "\n";
        }
    } else {
        echo "  Shopify: Not connected\n";
    }
} catch (Exception $e) {
    echo "  Warning: Could not get Shopify settings: " . $e->getMessage() . "\n";
}
echo "\n";

// ============================================
// Build URL Check Instructions
// ============================================
$urlCheckInstructions = '';
if (!empty($urlsToCheck)) {
    $urlList = implode("\n", array_map(fn($u) => "- {$u}", $urlsToCheck));
    $urlCheckInstructions = <<<URLS

## URLs to Check
The following URLs were mentioned in the ticket. Check these to understand the current state:
{$urlList}

Use web fetch or browser tools to visit these URLs and analyze what you see.
URLS;
}

// ============================================
// Build Ticket URL
// ============================================
$ticketUrl = $jiraSiteUrl ? "{$jiraSiteUrl}/browse/{$issueKey}" : '';
$ticketLink = $ticketUrl ? "- Ticket URL: {$ticketUrl}" : '';

// ============================================
// Build Shopify Instructions
// ============================================
$shopifyInstructions = '';
if ($shopifyEnabled) {
    $shopifyInstructions = <<<SHOPIFY

## Shopify Theme Development

This repository is connected to a Shopify store. You can push changes and preview them.

**Store**: {$shopifyDomain}

### Environment Variables
- **SHOPIFY_CLI_THEME_TOKEN**: Access token for Shopify CLI
- **SHOPIFY_FLAG_STORE**: Store domain ({$shopifyDomain})
- **SHOPIFY_STOREFRONT_PASSWORD**: Password for preview access (if store is password-protected)

### Pushing Theme Changes
After making changes to Liquid/CSS/JS files, push to create a development theme:
```bash
cd repo
shopify theme push --development --json
```

This creates an unpublished development theme and returns a preview URL.

### Getting Preview URL
To get the preview URL for an existing development theme:
```bash
shopify theme list --json
```

Look for the theme with role "development" and construct the URL:
`https://{$shopifyDomain}/?preview_theme_id=<THEME_ID>`

### Verifying Changes
1. Push the theme with `shopify theme push --development`
2. Note the theme ID from the output
3. Visit the preview URL to verify your changes
4. If the store is password-protected, use the storefront password

SHOPIFY;
}

// ============================================
// Check for Existing Branch (Branch Affinity)
// ============================================
$existingBranch = AIDevStatusService::findBranchForIssueKey($memberId, $issueKey);
if ($existingBranch) {
    echo "Found existing branch: {$existingBranch}\n";
} else {
    echo "No existing branch found - will create new one\n";
}
echo "\n";

// ============================================
// Build Prompt using AIDevAgentOrchestrator
// ============================================
echo "Building prompt...\n";

// Build ticket data
$ticket = [
    'key' => $issueKey,
    'summary' => $summary,
    'description' => $description,
    'requirements' => $description,
    'acceptance_criteria' => extractAcceptanceCriteria($description),
    'comments' => $commentText,
    'attachments' => $attachmentInfo,
    'linkedIssues' => $linkedIssuesInfo,
    'issueType' => $issueType,
    'priority' => $priority,
    'status' => $status,
    'ticketUrl' => $ticketUrl
];

// Build repo data
$repo = [
    'path' => './repo',
    'clone_url' => $cloneUrl,
    'default_branch' => $defaultBranch,
    'owner' => $repoOwner,
    'name' => $repoName
];

// Build Shopify config
$shopifyConfig = [
    'enabled' => $shopifyEnabled,
    'domain' => $shopifyDomain
];

// Create orchestrator with all parameters
$orchestrator = new \app\services\AIDevAgentOrchestrator(
    $ticket,
    $repo,
    $shopifyPreviewUrl ?: null,  // previewUrl
    3,                            // maxVerifyIterations
    $statusSettings,
    $shopifyConfig,
    $existingBranch,
    $urlsToCheck,
    $provider,                    // jira or github
    $agentConfig['name'] ?? 'AI Developer'  // agent name
);

// Check if a pre-built prompt was provided (from pipeline execution)
$prebuiltPromptFile = $options['prompt-file'] ?? null;
if ($prebuiltPromptFile && file_exists($prebuiltPromptFile)) {
    echo "  Using pre-built prompt from: {$prebuiltPromptFile}\n";
    $prompt = file_get_contents($prebuiltPromptFile);
    // Clean up the temp file
    unlink($prebuiltPromptFile);
} else {
    // Build prompt - always use orchestrator pattern for better verification
    $prompt = $orchestrator->buildOrchestratorPrompt();
}

// Save prompt
$promptFile = "{$workDir}/prompt.txt";
file_put_contents($promptFile, $prompt);
echo "  Prompt saved to: {$promptFile}\n";
echo "  Prompt length: " . strlen($prompt) . " chars\n\n";

// Create CLAUDE.md for the session (provider-aware)
$ticketType = ($provider === 'github') ? 'GitHub issue' : (($provider === 'pipeline') ? 'Pipeline task' : 'Jira ticket');
if ($provider === 'pipeline') {
    $claudeMd = <<<MD
# Pipeline Agent Session

Task ID: {$issueKey}

## Task Description
{$summary}

## Environment
- Work directory: {$workDir}

## MCP Tools Available
- playwright MCP - For browser testing and automation
- myctobot MCP - For scheduling pipelines and accessing the pipeline API

## Instructions
Follow the prompt in prompt.txt to complete the task.

Your output will be captured and passed back to the pipeline as the step result.
MD;
} elseif ($provider === 'github') {
    $claudeMd = <<<MD
# Local AI Developer Session

Working on GitHub issue: {$issueKey}
{$ticketLink}

## Issue Summary
{$summary}

## Environment
- Work directory: {$workDir}
- Repository: {$repoOwner}/{$repoName}

## Environment Variables Available
- GITHUB_TOKEN - GitHub access token for git operations and GitHub API
- GH_TOKEN - Same token for gh CLI

## MCP Tools
- github MCP - For posting comments, updating issues, creating PRs
- playwright MCP - For browser testing

## CRITICAL: Label Update Rules
When updating issue labels, you MUST:
1. First call get_issue to fetch current labels
2. Merge your new labels with the existing ones
3. Always keep "ai-dev" label first in the list (it's the activation label)
4. Then call update_issue with the COMPLETE merged label list

Example: If issue has ["ai-dev", "bug"] and you want to add "in-progress":
- Merged list: ["ai-dev", "bug", "in-progress"]
- NEVER just pass ["in-progress"] - this replaces all labels!

If ai-dev is removed, your session will be terminated.

## Instructions
Follow the prompt in prompt.txt to implement the issue.
MD;
} else {
    $claudeMd = <<<MD
# Local AI Developer Session

Working on Jira ticket: {$issueKey}
{$ticketLink}

## Ticket Summary
{$summary}

## Environment
- Work directory: {$workDir}
- Repository: {$repoOwner}/{$repoName}

## Environment Variables Available
- GITHUB_TOKEN - GitHub access token for git operations
- GH_TOKEN - Same token for gh CLI
- JIRA_HOST - Jira API host: {$jiraHost}
- JIRA_EMAIL - Jira user email: {$jiraEmail}
- JIRA_API_TOKEN - Jira OAuth token for API calls

## MCP Tools
- jira MCP - For posting comments, transitions, attachments
- playwright MCP - For browser testing

## Instructions
Follow the prompt in prompt.txt to implement the ticket.
MD;
}
file_put_contents("{$workDir}/CLAUDE.md", $claudeMd);

// ============================================
// Create .mcp.json for MCP servers
// ============================================
// Base servers - Playwright is always included
$mcpServers = (object) [
    'playwright' => (object) [
        'type' => 'stdio',
        'command' => 'npx',
        'args' => ['@playwright/mcp@latest'],
        'env' => new \stdClass()
    ]
];
$enabledMcpServers = ['playwright'];
$mcpProviderInfo = '';

if ($provider === 'pipeline') {
    // Pipeline provider - use Pipelines MCP for scheduling/spawning other pipelines
    $mcpWorkspace = $workspace ?? 'default';
    $pipelinesUrl = "https://{$mcpWorkspace}.myctobot.ai/mcp/pipelines";

    // Use the member's API token for pipeline MCP auth
    $memberApiToken = $member->api_token ?? '';
    if ($memberApiToken) {
        $mcpServers->pipelines = (object) [
            'type' => 'http',
            'url' => $pipelinesUrl,
            'headers' => (object) [
                'Authorization' => "Bearer {$memberApiToken}"
            ]
        ];
        $enabledMcpServers[] = 'pipelines';
        $mcpProviderInfo = "Pipelines MCP: HTTP transport ({$pipelinesUrl})";
    } else {
        echo "  Warning: No API token for member, pipelines MCP not available\n";
    }
} elseif ($provider === 'github') {
    // GitHub provider - use GitHub MCP (stdio via gh CLI or npx)
    // The GitHub MCP server uses the GH_TOKEN from environment
    $mcpServers->github = (object) [
        'type' => 'stdio',
        'command' => 'npx',
        'args' => ['-y', '@modelcontextprotocol/server-github'],
        'env' => (object) [
            'GITHUB_PERSONAL_ACCESS_TOKEN' => $githubToken ?? ''
        ]
    ];
    $enabledMcpServers[] = 'github';
    $mcpProviderInfo = "GitHub MCP: stdio transport (npx @modelcontextprotocol/server-github)";
} else {
    // Jira provider - use Jira HTTP MCP
    // URL pattern: https://{workspace}.myctobot.ai/mcp/jira
    $mcpWorkspace = $workspace ?? 'default';
    $mcpHttpUrl = "https://{$mcpWorkspace}.myctobot.ai/mcp/jira";
    $mcpCredentials = base64_encode("{$memberId}:{$cloudId}");
    $mcpAgentName = $agentConfig['name'] ?? 'AI Assistant';
    $mcpServers->jira = (object) [
        'type' => 'http',
        'url' => $mcpHttpUrl,
        'headers' => (object) [
            'Authorization' => "Basic {$mcpCredentials}",
            'X-MCP-Agent-Name' => $mcpAgentName
        ]
    ];
    $enabledMcpServers[] = 'jira';
    $mcpProviderInfo = "MCP Jira: HTTP transport ({$mcpHttpUrl})";
}

// Add MyCTOBot Jobs MCP for completion callbacks
// Use $jobUid (from DB) if available, otherwise fall back to $jobId (command line)
$mcpWorkspace = $workspace ?? 'default';
$mcpJobsUrl = "https://{$mcpWorkspace}.myctobot.ai/mcp/jobs";
$mcpJobUidForAuth = $jobUid ?: $jobId ?: 'unknown';
$mcpJobsCredentials = base64_encode("{$memberId}:{$mcpJobUidForAuth}");
$mcpServers->myctobot = (object) [
    'type' => 'http',
    'url' => $mcpJobsUrl,
    'headers' => (object) [
        'Authorization' => "Basic {$mcpJobsCredentials}"
    ]
];
$enabledMcpServers[] = 'myctobot';
echo "  MyCTOBot Jobs MCP: {$mcpJobsUrl} (job_uid: {$mcpJobUidForAuth})\n";

// Merge agent's MCP servers if configured
if ($agentConfig && !empty($agentConfig['mcp_servers'])) {
    echo "  Loading MCP servers from agent config...\n";
    foreach ($agentConfig['mcp_servers'] as $server) {
        $serverName = $server['name'] ?? 'unnamed';
        $serverType = $server['type'] ?? 'stdio';

        // Substitute environment variable placeholders in env values
        $serverEnv = $server['env'] ?? [];
        foreach ($serverEnv as $key => $value) {
            if (is_string($value)) {
                // Replace ${GITHUB_TOKEN} or ${GH_TOKEN} with actual token
                $value = str_replace('${GITHUB_TOKEN}', $githubToken ?? '', $value);
                $value = str_replace('${GH_TOKEN}', $githubToken ?? '', $value);
                // Replace ${JIRA_API_TOKEN} with actual Jira token
                $value = str_replace('${JIRA_API_TOKEN}', $jiraOAuthToken ?? '', $value);
                $serverEnv[$key] = $value;
            }
        }

        if ($serverType === 'sse') {
            // SSE transport - for remote MCP servers via Server-Sent Events
            $serverUrl = $server['url'] ?? '';
            // Substitute placeholders in URL
            $serverUrl = str_replace('${WORKSPACE}', $workspace ?? 'default', $serverUrl);
            $serverUrl = str_replace('${MYCTOBOT_API_KEY}', $member->api_token ?? '', $serverUrl);

            $mcpServers->$serverName = (object) [
                'url' => $serverUrl,
                'transport' => 'sse'
            ];
        } elseif ($serverType === 'http') {
            // Substitute placeholders in headers
            $serverHeaders = $server['headers'] ?? [];
            foreach ($serverHeaders as $key => $value) {
                if (is_string($value)) {
                    // Replace ${MYCTOBOT_API_KEY} with member's API token
                    $value = str_replace('${MYCTOBOT_API_KEY}', $member->api_token ?? '', $value);
                    // Also support other tokens in headers
                    $value = str_replace('${GITHUB_TOKEN}', $githubToken ?? '', $value);
                    $value = str_replace('${GH_TOKEN}', $githubToken ?? '', $value);
                    $value = str_replace('${JIRA_API_TOKEN}', $jiraOAuthToken ?? '', $value);
                    $serverHeaders[$key] = $value;
                }
            }
            $mcpServers->$serverName = (object) [
                'type' => 'http',
                'url' => $server['url'] ?? '',
                'headers' => (object) $serverHeaders
            ];
        } else {
            // stdio type
            $mcpServers->$serverName = (object) [
                'type' => 'stdio',
                'command' => $server['command'] ?? '',
                'args' => $server['args'] ?? [],
                'env' => (object)$serverEnv
            ];
        }

        $enabledMcpServers[] = $serverName;
        echo "    + {$serverName} ({$serverType})\n";
    }
}

$mcpConfig = ['mcpServers' => $mcpServers];
file_put_contents("{$workDir}/.mcp.json", json_encode($mcpConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Also copy to repo directory since Claude runs from there (after cd repo)
if (is_dir($repoDir)) {
    copy("{$workDir}/.mcp.json", "{$repoDir}/.mcp.json");
    echo "  MCP config copied to repo directory\n";
}
echo "  {$mcpProviderInfo}\n";

// ============================================
// Update CLAUDE.md with actual MCP tools list
// ============================================
$mcpToolsList = "";
foreach ($enabledMcpServers as $serverName) {
    $mcpToolsList .= "- {$serverName} MCP\n";
}
// Read current CLAUDE.md and replace the MCP Tools section
$claudeMdContent = file_get_contents("{$workDir}/CLAUDE.md");
$claudeMdContent = preg_replace(
    '/## MCP Tools\n.*?(?=\n## |\z)/s',
    "## MCP Tools\n{$mcpToolsList}\n",
    $claudeMdContent
);
file_put_contents("{$workDir}/CLAUDE.md", $claudeMdContent);
echo "  Updated CLAUDE.md with " . count($enabledMcpServers) . " MCP tools\n";

// ============================================
// Create .claude/settings.json with attribution and hooks
// ============================================
echo "  Creating .claude/settings.json...\n";
$claudeSettingsDir = "{$workDir}/.claude";
if (!is_dir($claudeSettingsDir)) {
    mkdir($claudeSettingsDir, 0755, true);
}

// Start with attribution and MCP settings (always included)
$claudeSettings = [
    'enableAllProjectMcpServers' => true,  // Required to load .mcp.json servers
    'attribution' => [
        'commit' => "Generated with MyCTOBOT.ai\n\nCo-Authored-By: claude",
        'pr' => ''
    ]
];

// Add hooks if configured in agent
if ($agentConfig && !empty($agentConfig['hooks_config'])) {
    echo "  Loading hooks from agent config...\n";
    $hooksConfig = $agentConfig['hooks_config'];
    $hookCount = 0;
    foreach (['PreToolUse', 'PostToolUse', 'Stop'] as $event) {
        if (!empty($hooksConfig[$event])) {
            $hookCount += count($hooksConfig[$event]);
        }
    }
    echo "    {$hookCount} hooks configured\n";
    $claudeSettings['hooks'] = $hooksConfig;
}

file_put_contents("{$claudeSettingsDir}/settings.json", json_encode($claudeSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Also copy to repo directory
if (is_dir($repoDir)) {
    $repoClaudeDir = "{$repoDir}/.claude";
    if (!is_dir($repoClaudeDir)) {
        mkdir($repoClaudeDir, 0755, true);
    }
    copy("{$claudeSettingsDir}/settings.json", "{$repoClaudeDir}/settings.json");
}

// Pre-approve MCP servers in ~/.claude.json so Claude doesn't prompt
// Use $repoDir since Claude runs from there (after cd repo in the prompt)
$claudeConfigPath = getenv('HOME') . '/.claude.json';
if (file_exists($claudeConfigPath)) {
    $claudeConfig = json_decode(file_get_contents($claudeConfigPath), true);
    if ($claudeConfig && isset($claudeConfig['projects'])) {
        // Register both workDir and repoDir to cover both paths
        $projectConfig = [
            'allowedTools' => [],
            'dontCrawlDirectory' => false,
            'mcpContextUris' => [],
            'mcpServers' => new \stdClass(),  // Empty object, servers defined in .mcp.json
            'enabledMcpjsonServers' => array_unique($enabledMcpServers),
            'disabledMcpjsonServers' => [],
            'hasTrustDialogAccepted' => true,
            'hasCompletedProjectOnboarding' => true,
            'projectOnboardingSeenCount' => 1,
            'hasClaudeMdExternalIncludesApproved' => false,
            'hasClaudeMdExternalIncludesWarningShown' => false
        ];
        $claudeConfig['projects'][$workDir] = $projectConfig;
        if (is_dir($repoDir)) {
            $claudeConfig['projects'][$repoDir] = $projectConfig;
        }
        file_put_contents($claudeConfigPath, json_encode($claudeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "Pre-approved MCP servers for this session\n";
    }
}

// ============================================
// Create Wrapper Script with Environment
// ============================================
// Build Shopify env vars section
$shopifyEnvSection = '';
$shopifyEchoSection = '';
if ($shopifyEnabled) {
    $shopifyEnvSection = <<<SHOPIFY_ENV

# Shopify CLI credentials
export SHOPIFY_CLI_THEME_TOKEN="{$shopifyAccessToken}"
export SHOPIFY_FLAG_STORE="{$shopifyDomain}"
export SHOPIFY_STOREFRONT_PASSWORD="{$shopifyStorefrontPassword}"
SHOPIFY_ENV;

    $shopifyEchoSection = <<<SHOPIFY_ECHO
echo "SHOPIFY_FLAG_STORE: \$SHOPIFY_FLAG_STORE"
echo "SHOPIFY_CLI_THEME_TOKEN: ****\${SHOPIFY_CLI_THEME_TOKEN: -4}"
SHOPIFY_ECHO;
}

// Get base URL and API key from config
$baseUrl = Flight::get('app.baseurl') ?? Flight::get('baseurl') ?? "https://{$workspace}.myctobot.ai";
$apiKey = Flight::get('api.api_key') ?? '';
$webhookUrl = $baseUrl;

// Hook environment variables for multi-workspace support
$hookEnvSection = <<<HOOK_ENV

# MyCTOBot Hook Environment Variables (for multi-workspace hooks)
export MYCTOBOT_APP_ROOT="{$baseDir}"
export MYCTOBOT_WORKSPACE="{$workspace}"
export MYCTOBOT_BASEURL="{$baseUrl}"
export MYCTOBOT_JOB_ID="{$jobId}"
export MYCTOBOT_JOB_UID="{$jobUid}"
export MYCTOBOT_ISSUE_KEY="{$issueKey}"
export MYCTOBOT_MEMBER_ID="{$memberId}"
export MYCTOBOT_PROJECT_ROOT="{$repoDir}"
export MYCTOBOT_WEBHOOK_URL="{$webhookUrl}"
export MYCTOBOT_API_KEY="{$apiKey}"
HOOK_ENV;

// Copy send-checkpoint.sh to work directory (saves checkpoint, keeps session alive)
$finishJobSrc = "{$baseDir}/scripts/send-checkpoint.sh";
$finishJobDst = "{$workDir}/send-checkpoint.sh";
if (file_exists($finishJobSrc)) {
    copy($finishJobSrc, $finishJobDst);
    chmod($finishJobDst, 0755);
    echo "  Copied send-checkpoint.sh to work directory\n";

    // Also copy to repo directory since Claude runs from there (after cd repo)
    if (is_dir($repoDir)) {
        copy($finishJobSrc, "{$repoDir}/send-checkpoint.sh");
        chmod("{$repoDir}/send-checkpoint.sh", 0755);
        echo "  Copied send-checkpoint.sh to repo directory\n";
    }
}

// Build provider-specific environment section
$providerEnvSection = '';
$providerEchoSection = '';

if ($provider === 'pipeline') {
    // Pipeline provider - minimal environment, no Jira/GitHub specifics needed
    $providerEnvSection = <<<PROVIDER_ENV
export MYCTOBOT_PROVIDER="pipeline"
export GITHUB_TOKEN="{$githubToken}"
export GH_TOKEN="{$githubToken}"
PROVIDER_ENV;

    $providerEchoSection = <<<PROVIDER_ECHO
echo "Provider: Pipeline"
PROVIDER_ECHO;
} elseif ($provider === 'github') {
    // GitHub provider - only GitHub token needed
    $providerEnvSection = <<<PROVIDER_ENV
export GITHUB_TOKEN="{$githubToken}"
export GH_TOKEN="{$githubToken}"
export MYCTOBOT_PROVIDER="github"
PROVIDER_ENV;

    $providerEchoSection = <<<PROVIDER_ECHO
echo "GITHUB_TOKEN: ****\${GITHUB_TOKEN: -4}"
echo "GH_TOKEN: ****\${GH_TOKEN: -4}"
echo "Provider: GitHub"
PROVIDER_ECHO;
} else {
    // Jira provider - need both GitHub and Jira tokens
    $providerEnvSection = <<<PROVIDER_ENV
export GITHUB_TOKEN="{$githubToken}"
export GH_TOKEN="{$githubToken}"
export JIRA_HOST="{$jiraHost}"
export JIRA_EMAIL="{$jiraEmail}"
export JIRA_API_TOKEN="{$jiraOAuthToken}"
export MYCTOBOT_PROVIDER="jira"

# Board-configured status settings for Jira transitions
export AIDEV_STATUS_WORKING="{$statusSettings['working']}"
export AIDEV_STATUS_PR_CREATED="{$statusSettings['pr_created']}"
export AIDEV_STATUS_COMPLETE="{$statusSettings['complete']}"
export AIDEV_STATUS_FAILED="{$statusSettings['failed']}"
PROVIDER_ENV;

    $providerEchoSection = <<<PROVIDER_ECHO
echo "GITHUB_TOKEN: ****\${GITHUB_TOKEN: -4}"
echo "GH_TOKEN: ****\${GH_TOKEN: -4}"
echo "JIRA_HOST: \$JIRA_HOST"
echo "JIRA_API_TOKEN: ****\${JIRA_API_TOKEN: -4}"
echo "Provider: Jira"
PROVIDER_ECHO;
}

// ========================================
// Ollama Backend Configuration
// ========================================
// If agent is configured to use Ollama as Claude's backend, set the env vars
// See: https://docs.ollama.com/integrations/claude-code
$ollamaEnvSection = '';
$ollamaEchoSection = '';
$ollamaModel = '';

if ($agentConfig && !empty($agentConfig['provider_config']['use_ollama'])) {
    $ollamaHost = $agentConfig['provider_config']['ollama_host'] ?? 'http://localhost:11434';
    $ollamaModel = $agentConfig['provider_config']['ollama_model'] ?? 'qwen3-coder';

    $ollamaEnvSection = <<<OLLAMA_ENV

# Ollama Backend for Claude Code
# This makes Claude Code use local Ollama instead of Anthropic API
export ANTHROPIC_BASE_URL="{$ollamaHost}"
export ANTHROPIC_API_KEY="ollama"
OLLAMA_ENV;

    $ollamaEchoSection = <<<OLLAMA_ECHO
echo ""
echo "=== Ollama Backend ==="
echo "ANTHROPIC_BASE_URL: \$ANTHROPIC_BASE_URL"
echo "Model: {$ollamaModel}"
echo "Claude Code will use Ollama for inference"
OLLAMA_ECHO;

    echo "  Ollama Backend: {$ollamaHost} (model: {$ollamaModel})\n";
}

// Path to cleanup script (relative to myctobot root)
$cleanupScript = dirname(__DIR__) . '/scripts/aoe-session-cleanup.php';
$aoeSessionId = $aoeSession->id;

$envScript = <<<BASH
#!/bin/bash
# Environment setup for AI Developer session
# Generated for issue: {$issueKey}
# Provider: {$provider}

# Ensure PATH includes common binary locations (Claude CLI, npm global, etc.)
export PATH="\$HOME/.local/bin:\$HOME/.npm-global/bin:/usr/local/bin:\$PATH"

# AOE Session Tracking
export AOE_workspace="{$workspaceSlug}"
export AOE_SESSION_ID="{$aoeSessionId}"
export AOE_SESSION_NAME="{$sessionName}"

# AOE session cleanup function (updates session status in storage)
aoe_session_cleanup() {
    local exit_code=\${1:-0}
    echo "Updating AOE session status..."
    php "{$cleanupScript}" --workspace="\$AOE_workspace" --session-id="\$AOE_SESSION_ID" --exit-code="\$exit_code" || true
}

{$providerEnvSection}
{$ollamaEnvSection}
export GIT_TERMINAL_PROMPT="0"
export TERM="xterm-256color"
{$hookEnvSection}
{$shopifyEnvSection}

# ============================================
# Session Startup Banner
# ============================================
echo "==========================================="
echo "AI Developer Session - Using YOUR Claude"
echo "==========================================="
echo ""
echo "Workspace: {$workspaceSlug}"
echo "Provider: {$provider}"
echo "Issue: {$issueKey}"
echo "Summary: {$summary}"
echo "Session: {$sessionName}"
echo "Work Dir: {$workDir}"
echo "Member ID: {$memberId}"
echo "Job ID: \$MYCTOBOT_JOB_ID"
echo ""
{$providerEchoSection}
{$ollamaEchoSection}
{$shopifyEchoSection}
echo "==========================================="
echo ""

# For pipeline jobs, work directory is the root (no repo needed)
# For Jira/GitHub jobs, verify repo was cloned
WORK_DIR="{$workDir}"
if [[ "\$MYCTOBOT_PROVIDER" == "pipeline" ]]; then
    echo "Pipeline job - working in {$workDir}"
    # No repo needed for pipeline jobs
else
    if [[ ! -d "{$workDir}/repo" ]]; then
        echo "ERROR: Repository not found at {$workDir}/repo"
        echo "Clone may have failed. Check the startup logs."
        exit 1
    fi
    WORK_DIR="{$workDir}/repo"
    # Copy support files to repo directory (Claude runs from there after cd repo)
    cp -f "{$workDir}/.mcp.json" "{$workDir}/repo/.mcp.json" 2>/dev/null || true
    cp -f "{$workDir}/send-checkpoint.sh" "{$workDir}/repo/send-checkpoint.sh" 2>/dev/null || true
    chmod +x "{$workDir}/repo/send-checkpoint.sh" 2>/dev/null || true
fi
mkdir -p "{$workDir}/attachments" 2>/dev/null

cd "\$WORK_DIR"

BASH;

// Post-execution updates are now handled by Claude via MCP tools (job_complete, add_comment)
// No need for bash-based JSON parsing - Claude posts directly to Jira/GitHub

// Add cleanup function that calls the API to remove labels
$cleanupFunction = <<<'CLEANUP_BASH'

# Cleanup job labels via API (removes ai-dev, myctobot-working labels)
cleanup_labels() {
    echo ""
    echo "=== Removing Job Labels ==="

    if [[ -z "$MYCTOBOT_API_KEY" ]]; then
        echo "  ⚠ MYCTOBOT_API_KEY not set, skipping label cleanup"
        return
    fi

    # Use main domain to avoid subdomain redirect losing POST data
    local api_url="$MYCTOBOT_BASEURL/jobs/cleanup?workspace=$MYCTOBOT_WORKSPACE"
    local response=$(curl -s -w "\n%{http_code}" -X POST "$api_url" \
        -H "X-API-Key: $MYCTOBOT_API_KEY" \
        -H "Content-Type: application/x-www-form-urlencoded" \
        -d "workspace=$MYCTOBOT_WORKSPACE&issue_key=$MYCTOBOT_ISSUE_KEY&job_uid=$MYCTOBOT_JOB_ID" 2>/dev/null)

    local http_code=$(echo "$response" | tail -1)
    local body=$(echo "$response" | head -n -1)

    if [[ "$http_code" =~ ^2 ]]; then
        echo "  ✓ Labels removed successfully"
        # Parse and show removed labels if jq available
        if command -v jq &>/dev/null; then
            local removed=$(echo "$body" | jq -r '.data.labels_removed // [] | join(", ")' 2>/dev/null)
            [[ -n "$removed" ]] && echo "    Removed: $removed"
        fi
    else
        echo "  ✗ Failed to remove labels (HTTP $http_code)"
        echo "    $body" | head -c 200
    fi
}
CLEANUP_BASH;

$envScript .= $cleanupFunction;

// Add trap-based cleanup to ensure it runs even if script is interrupted
$trapCleanup = <<<'TRAP_BASH'

# Track if cleanup has already run to avoid double-execution
CLEANUP_DONE=0

# Main cleanup function called by trap
do_cleanup() {
    if [[ "$CLEANUP_DONE" == "1" ]]; then
        return
    fi
    CLEANUP_DONE=1

    echo ""
    echo "=== Session Cleanup ==="

    # For pipeline jobs, mark the job as complete via API
    if [[ "$MYCTOBOT_PROVIDER" == "pipeline" ]]; then
        echo "Marking pipeline job as complete..."
        # Use Basic auth with job_id:job_uid for verification
        local auth=$(echo -n "$MYCTOBOT_JOB_ID:$MYCTOBOT_JOB_UID" | base64)
        curl -s -X POST "$MYCTOBOT_BASEURL/api/jobs/complete" \
            -H "Authorization: Basic $auth" \
            -H "Content-Type: application/x-www-form-urlencoded" \
            -d "workspace=$MYCTOBOT_WORKSPACE&job_id=$MYCTOBOT_JOB_ID&status=complete" 2>/dev/null || true
    fi

    # Remove job labels via API
    # cleanup_labels

    # Update AOE session status
    aoe_session_cleanup $?

    # Cleanup orphaned Playwright processes
    echo "Cleaning up orphaned Playwright processes..."
    ps -eo pid,ppid,args 2>/dev/null | grep -E "playwright|mcp-server-playwright" | awk '$2 == 1 {print $1}' | while read pid; do
        echo "  Killing orphaned playwright (PID $pid)"
        kill $pid 2>/dev/null || true
    done

    echo "=== Cleanup Complete ==="
}

# Set trap to run cleanup on EXIT (catches normal exit, Ctrl+C, kill, etc.)
trap do_cleanup EXIT

TRAP_BASH;

$envScript .= $trapCleanup;

// Build Claude command with optional --model flag for Ollama
$modelFlag = '';
if (!empty($ollamaModel)) {
    $modelFlag = "--model {$ollamaModel}";
}

// Build SSH wrapper if workstation is configured
// When SSH is used, Claude runs on the remote host but the work directory must be accessible
// (via NFS, same path structure, or localhost)
$sshPrefix = '';
$sshSuffix = '';
$sshScriptFile = '';
if ($workstationConfig) {
    // Sanitize host/user (alphanumeric, dots, dashes, underscores only)
    $wsHost = preg_replace('/[^a-zA-Z0-9.\-_]/', '', $workstationConfig['host']);
    $wsUser = preg_replace('/[^a-zA-Z0-9.\-_]/', '', $workstationConfig['user']);
    $wsPort = (int)($workstationConfig['port'] ?? 22);

    if (empty($wsHost) || empty($wsUser)) {
        echo "Warning: Invalid workstation config, running locally\n";
        $workstationConfig = null;
    } else {
        // Create a wrapper script for SSH to avoid quoting issues with 'script -c'
        // This script will be executed inside 'script -c' command
        $sshScriptFile = "{$workDir}/ssh-claude.sh";
        echo "Workstation: {$wsUser}@{$wsHost}:{$wsPort}\n";
    }
}

if ($usePrintMode) {
    // Non-interactive mode with logging
    $logFile = "{$workDir}/session.log";

    // Build the claude command (with or without SSH wrapper)
    if ($workstationConfig && $sshScriptFile) {
        // Add common claude installation paths to PATH for SSH sessions
        // First create remote dir and sync prompt file
        $claudeCmd = "ssh -p {$wsPort} {$wsUser}@{$wsHost} 'mkdir -p {$workDir}' && scp -P {$wsPort} prompt.txt {$wsUser}@{$wsHost}:{$workDir}/prompt.txt && ssh -t -p {$wsPort} {$wsUser}@{$wsHost} 'export PATH=\$HOME/.local/bin:\$HOME/.claude/bin:/usr/local/bin:\$PATH && cd {$workDir} && claude --print --dangerously-skip-permissions {$modelFlag} < prompt.txt'";
    } else {
        $claudeCmd = "claude --print --dangerously-skip-permissions {$modelFlag} < prompt.txt";
    }

    $envScript .= <<<BASH

echo "Starting Claude in print mode..."
echo "Log file: {$logFile}"
echo ""
{$claudeCmd} 2>&1 | tee {$logFile}

# Claude handles Jira/GitHub updates via MCP tools (job_complete, add_comment)
# Remove job labels via API
# cleanup_labels

# Cleanup orphaned Playwright processes (PPID=1 means parent died)
echo "Cleaning up orphaned Playwright processes..."
ps -eo pid,ppid,args | grep -E "playwright|mcp-server-playwright" | awk '\$2 == 1 {print \$1}' | while read pid; do
    echo "  Killing orphaned playwright (PID \$pid)"
    kill \$pid 2>/dev/null || true
done
BASH;

    // Only kill tmux session if NOT in resident mode
    if (!$residentMode) {
        $envScript .= <<<'BASH'

# Kill the tmux session to clean up (works for both local and remote SSH)
echo "Terminating tmux session: $AOE_SESSION_NAME"
tmux kill-session -t "$AOE_SESSION_NAME" 2>/dev/null || exit 0
BASH;
    } else {
        $envScript .= <<<'BASH'

# RESIDENT MODE: Session stays alive for pipeline duration
echo "Resident mode - Claude session stays alive"
echo "Pipeline will send more commands as needed"
echo "Session: $AOE_SESSION_NAME"
BASH;
    }
} else {
    // Interactive TUI mode - pass prompt as argument (not -p flag) to show TUI
    $logFile = "{$workDir}/session.log";

    // Build the script command differently based on SSH config
    if ($workstationConfig && $sshScriptFile) {
        // Create a wrapper script to avoid quote-nesting issues with 'script -c'
        // Add common claude installation paths to PATH for SSH sessions
        // First create remote dir and sync prompt, then run claude there
        $sshScriptContent = <<<SSHSCRIPT
#!/bin/bash
cd {$workDir}

# Create work directory on remote and sync prompt
ssh -p {$wsPort} {$wsUser}@{$wsHost} "mkdir -p {$workDir}"
scp -P {$wsPort} prompt.txt {$wsUser}@{$wsHost}:{$workDir}/prompt.txt

# Run claude on remote workstation
ssh -t -p {$wsPort} {$wsUser}@{$wsHost} "export PATH=\\\$HOME/.local/bin:\\\$HOME/.claude/bin:/usr/local/bin:\\\$PATH && cd {$workDir} && claude --dangerously-skip-permissions {$modelFlag} \"\\\$(cat prompt.txt)\""
SSHSCRIPT;
        file_put_contents($sshScriptFile, $sshScriptContent);
        chmod($sshScriptFile, 0755);
        $scriptCommand = "script -q session.log -c '{$sshScriptFile}'";
    } else {
        $scriptCommand = "script -q session.log -c 'claude --dangerously-skip-permissions {$modelFlag} \"\$(cat prompt.txt)\"'";
    }

    $envScript .= <<<BASH

echo "Starting Claude with TUI..."
echo "Environment variables are set - use them for git/API operations."
echo ""

# Use script to capture output while preserving TUI
# Pass prompt content as argument (without -p) to start with message AND show TUI
{$scriptCommand}

# === Post-Session Cleanup (runs after Claude exits normally) ===
echo ""
echo "=== Claude Session Ended ==="

# Claude handles Jira/GitHub updates via MCP tools (job_complete, add_comment)
BASH;

    // Final cleanup - session ends immediately after Claude exits (unless resident mode)
    $envScript .= <<<CLEANUP_BASH

# Trigger cleanup (trap will handle it, but call explicitly for immediate feedback)
do_cleanup
CLEANUP_BASH;

    // Only kill tmux session if NOT in resident mode
    if (!$residentMode) {
        $envScript .= <<<'BASH'

# Kill the tmux session to clean up (works for both local and remote SSH)
echo "Terminating tmux session: $AOE_SESSION_NAME"
tmux kill-session -t "$AOE_SESSION_NAME" 2>/dev/null || exit 0
BASH;
    } else {
        $envScript .= <<<'BASH'

# RESIDENT MODE: Session stays alive for pipeline duration
echo ""
echo "=== Resident Claude Session Ready ==="
echo "Session: $AOE_SESSION_NAME"
echo "Pipeline will send additional commands as needed."
echo "Type commands or wait for pipeline instructions..."
BASH;
    }
}

$envScriptPath = "{$workDir}/run-claude.sh";
file_put_contents($envScriptPath, $envScript);
chmod($envScriptPath, 0755);
echo "  Run script saved to: {$envScriptPath}\n\n";

// ============================================
// Dry Run Exit Point
// ============================================
if ($dryRun) {
    echo "===========================================\n";
    echo "DRY RUN COMPLETE - All database queries successful!\n";
    echo "===========================================\n\n";
    echo "Summary:\n";
    echo "  - Member: {$memberId} ({$member->email})\n";
    echo "  - workspace: {$workspaceSlug}\n";
    echo "  - Repository: {$repoOwner}/{$repoName}\n";
    echo "  - Default Branch: {$defaultBranch}\n";
    echo "  - GitHub Token: " . (!empty($githubToken) ? "****" . substr($githubToken, -4) : "NOT SET") . "\n";
    if ($provider === 'jira') {
        echo "  - Jira Cloud ID: {$cloudId}\n";
    }
    echo "  - Session Name: {$sessionName}\n";
    echo "  - Work Dir: {$workDir}\n";
    echo "  - Prompt File: {$promptFile}\n";
    echo "  - Prompt Length: " . strlen($prompt) . " chars\n";
    if ($shopifyEnabled) {
        echo "  - Shopify Store: {$shopifyDomain}\n";
    }
    echo "\nTo run for real, remove the --dry-run flag.\n";
    exit(0);
}

// ============================================
// Job-Executor Integration
// ============================================
// When agent has a workstation (runner) configured, use job-executor
// This runs the job ON the remote workstation instead of SSHing from local
// EXCEPT: Pipeline jobs always use SSH wrapper (workstation config is passed via CLI)
$useJobExecutor = false;
$jobExecutorConfig = \app\services\JobExecutorConfig::getConfig($workspaceSlug);
$jobExecutorUrl = $jobExecutorConfig['url'];
$runnersId = null;

// Pipeline jobs always use SSH wrapper mode (no job-executor)
// Provider 'pipeline' is set when dispatched from PipelineExecutor
if ($provider === 'pipeline' || $workstationConfig) {
    echo "Pipeline/CLI workstation mode - using SSH wrapper (skipping job-executor)\n";
    $useJobExecutor = false;
} elseif ($agentConfig && !empty($agentConfig['runners_id'])) {
    // Check if agent has a runner configured (for non-pipeline jobs)
    $runnersId = (int) $agentConfig['runners_id'];
    $runnerBean = Bean::load('runners', $runnersId);
    if ($runnerBean && $runnerBean->id && $runnerBean->is_active) {
        $useJobExecutor = true;
        echo "Agent has workstation '{$runnerBean->name}' - using job-executor\n";
    }
}

if ($useJobExecutor) {
    echo "\n";
    echo "============================================\n";
    echo "Submitting to Job-Executor\n";
    echo "============================================\n";

    // Generate job_uid if not provided
    if (empty($jobId)) {
        $jobId = 'aoe-' . $workspaceSlug . '-' . bin2hex(random_bytes(8));
    }

    // Create/update aidevjobs record
    $existingJob = Bean::findOne('aidevjobs', 'job_uid = ?', [$jobId]);
    if ($existingJob) {
        $job = $existingJob;
        echo "  Using existing job record: {$jobId}\n";
    } else {
        $job = Bean::dispense('aidevjobs');
        $job->job_uid = $jobId;
        $job->created_at = date('Y-m-d H:i:s');
        echo "  Created job record: {$jobId}\n";
    }

    $job->member_id = $memberId;
    $job->boards_id = null;  // Could be linked if we have board context
    $job->repoconnections_id = $repoBean->id ?? null;
    $job->runners_id = $runnersId;
    $job->aiagents_id = $agentConfig['id'] ?? null;
    $job->issue_key = $issueKey;
    $job->status = 'pending';
    $job->phase = 'submitting';
    $job->prompt = $prompt;
    $job->branch_name = $defaultBranch;
    $job->updated_at = date('Y-m-d H:i:s');
    Bean::store($job);

    // Store prepared files content in job for job-executor to retrieve
    // (The validate endpoint will return these)
    // Note: We DON'T pass env_script because it has hardcoded local paths
    // Job-executor generates its own runner.sh with correct remote paths
    $job->claude_md = file_get_contents("{$workDir}/CLAUDE.md");
    $job->mcp_config_json = file_get_contents("{$workDir}/.mcp.json");
    // $job->env_script is intentionally not set - let job-executor generate runner.sh
    Bean::store($job);

    // Submit to job-executor (PING)
    $submitUrl = rtrim($jobExecutorUrl, '/') . '/api/jobs/submit';
    // Use main domain for callback since workspace is passed in body
    // (subdomain URLs may redirect which breaks the callback)
    $callbackUrl = 'https://myctobot.ai/api/jobexecutor';

    echo "  Job-Executor URL: {$submitUrl}\n";
    echo "  Callback URL: {$callbackUrl}\n";

    $ch = curl_init($submitUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'workspace' => $workspaceSlug,
            'job_uid' => $jobId,
            'callback_url' => $callbackUrl,
        ]),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo "\n";
        echo "ERROR: Failed to connect to job-executor: {$curlError}\n";
        echo "Falling back to local SSH wrapper mode...\n\n";
        $useJobExecutor = false;
        // Update job status
        $job->status = 'failed';
        $job->phase = 'connection_error';
        $job->error_message = $curlError;
        Bean::store($job);
    } elseif ($httpCode !== 200) {
        $result = json_decode($response, true);
        $errorMsg = $result['error'] ?? "HTTP {$httpCode}";
        echo "\n";
        echo "ERROR: Job-executor returned error: {$errorMsg}\n";
        echo "Falling back to local SSH wrapper mode...\n\n";
        $useJobExecutor = false;
        // Update job status
        $job->status = 'failed';
        $job->phase = 'submit_error';
        $job->error_message = $errorMsg;
        Bean::store($job);
    } else {
        $result = json_decode($response, true);
        echo "\n";
        echo "Job submitted successfully!\n";
        echo "  Status: " . ($result['status'] ?? 'submitted') . "\n";
        echo "  Workstation: " . ($result['workstation'] ?? 'unknown') . "\n";

        // Update AOE session
        $aoeSession->status = AoeStatus::Running;
        $aoeSession->metadata = array_merge($aoeSession->metadata ?? [], [
            'job_uid' => $jobId,
            'job_executor' => true,
            'workstation' => $result['workstation'] ?? null,
        ]);
        $aoeStorage->save($aoeSession);

        echo "\n";
        echo "============================================\n";
        echo "Job is running on remote workstation\n";
        echo "============================================\n";
        echo "\n";
        echo "Job UID: {$jobId}\n";
        echo "AOE Session: {$aoeSession->id} (short: {$aoeSession->getShortId()})\n";
        echo "\n";
        echo "Monitor commands:\n";
        echo "  Status:   bin/aoe --workspace={$workspaceSlug} session:status {$aoeSession->getShortId()}\n";
        echo "  Jobs:     bin/aoe --workspace={$workspaceSlug} job:list\n";
        echo "\n";
        echo "The job will run on the remote workstation.\n";
        echo "Use the MyCTOBot dashboard to monitor progress.\n";
        echo "============================================\n";

        exit(0);
    }
}

// ============================================
// Start Claude Session
// ============================================
// Check if we're already running inside a tmux session (spawned by webhook)
$alreadyInTmux = !empty(getenv('TMUX'));

if ($alreadyInTmux) {
    // We're inside a tmux session already - just exec the wrapper script directly
    echo "Running inside tmux session, starting Claude directly...\n\n";

    // Make the wrapper script executable and run it
    chmod($envScriptPath, 0755);

    // Replace this process with the Claude wrapper
    pcntl_exec('/bin/bash', [$envScriptPath]);

    // If pcntl_exec fails, fall back to passthru
    passthru($envScriptPath);
    exit(0);
}

// Manual mode - create our own tmux session using AOE
echo "Setting up tmux session...\n";
$aoeTmux = new AoeTmux($workspaceSlug);

// Kill existing session if any (use session name with ticket reference)
if ($aoeTmux->sessionExistsByName($sessionName)) {
    $aoeTmux->killSessionByName($sessionName);
}

// Save session to AOE storage for tracking
$aoeStorage->save($aoeSession);

// Create tmux session with 200x50 size (use session name with ticket reference)
$outputFile = "{$workDir}/output.log";
$aoeTmux->createSessionWithName($sessionName, $workDir, '');
$aoeTmux->resizePaneByName($sessionName, 200, 50);

// Run the wrapper script which sets env and starts Claude
$aoeTmux->sendTextByName($sessionName, $envScriptPath);
$aoeTmux->sendEnterByName($sessionName);
sleep(3);  // Wait for env setup and Claude to start

if (!$usePrintMode) {
    // For interactive mode, accept the bypass permissions dialog
    sleep(2);
    $aoeTmux->sendKeysByName($sessionName, 'Down');  // Move to "Yes, I accept"
    sleep(1);
    $aoeTmux->sendEnterByName($sessionName);  // Confirm
    sleep(3);  // Wait for Claude to fully start

    // Send the prompt
    $aoeTmux->sendTextByName($sessionName, 'Please read and execute the task in prompt.txt');
    $aoeTmux->sendEnterByName($sessionName);
    sleep(1);
    $aoeTmux->sendEnterByName($sessionName);  // Submit
}

// Update status in AOE
$aoeSession->status = AoeStatus::Starting;
$aoeStorage->save($aoeSession);

echo "\n";
echo "============================================\n";
echo "Claude is running in tmux session: {$sessionName}\n";
echo "\n";
if ($usePrintMode) {
    echo "Mode: Non-interactive (--print)\n";
    echo "Log: tail -f {$workDir}/session.log\n";
} else {
    echo "Mode: Interactive\n";
    echo "You can:\n";
    echo "  - See Claude's output in real-time\n";
    echo "  - Type responses if Claude needs clarification\n";
    echo "  - Approve or reject changes\n";
    echo "  - Type 'exit' when done\n";
}
echo "\n";
echo "Post-completion:\n";
echo "  - JSON output saved to: {$workDir}/claude-output.json\n";
echo "  - Jira comment added automatically\n";
echo "  - Jira status transitioned automatically\n";
echo "\n";
echo "Commands:\n";
echo "  Attach now:    tmux attach -t {$sessionName}\n";
echo "  Watch log:     tail -f {$workDir}/session.log\n";
echo "  Kill session:  bin/aoe --workspace={$workspaceSlug} session:stop {$aoeSession->getShortId()}\n";
echo "  Session info:  bin/aoe --workspace={$workspaceSlug} session:status {$aoeSession->getShortId()}\n";
echo "\n";
echo "AOE Session ID: {$aoeSession->id} (short: {$aoeSession->getShortId()})\n";
echo "\n";
echo "This uses YOUR Claude subscription, not API credits!\n";
echo "============================================\n";
echo "\n";

if ($autoAttach) {
    echo "Attaching to session... (Ctrl+B, D to detach)\n\n";
    $aoeTmux->attachSessionByName($sessionName);
} else {
    echo "Attach now? (y/N): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    if (trim(strtolower($line)) === 'y') {
        $aoeTmux->attachSessionByName($sessionName);
    }
}

// ============================================
// Helper Functions
// ============================================

/**
 * Extract URLs from text
 */
function extractUrls(string $text): array {
    $urls = [];
    // Match HTTP/HTTPS URLs
    if (preg_match_all('#https?://[^\s<>\[\]"\']+#i', $text, $matches)) {
        $urls = array_unique($matches[0]);
        // Clean up trailing punctuation
        $urls = array_map(function($url) {
            return rtrim($url, '.,;:!?)');
        }, $urls);
    }
    return array_values(array_unique($urls));
}

/**
 * Extract acceptance criteria from description
 */
function extractAcceptanceCriteria(string $description): array {
    $criteria = [];

    // Look for "Acceptance Criteria" section
    if (preg_match('/acceptance\s+criteria[:\s]*\n([\s\S]*?)(?:\n\n|\z)/i', $description, $matches)) {
        if (preg_match_all('/(?:^|\n)\s*[-*]\s*(.+)$/m', $matches[1], $subMatches)) {
            $criteria = $subMatches[1];
        }
    }

    // Default criteria if none found
    if (empty($criteria)) {
        $criteria[] = 'Verify the implementation matches the requirements in the description';
    }

    return $criteria;
}
