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
 * Usage: php scripts/local-aidev-full.php --issue=SSI-1883 [--orchestrator] [--attach]
 */

error_reporting(E_ALL);
$baseDir = dirname(__FILE__, 2);
chdir($baseDir);

// Parse CLI args
$options = getopt('', [
    'issue:',
    'member:',
    'job-id:',
    'tenant:',
    'repo:',
    'attach',
    'repo-path:',
    'print',
    'dry-run',
    'skip-jira',
    'help'
]);

if (isset($options['help']) || empty($options['issue'])) {
    echo "Local AI Developer Runner - Uses YOUR Claude subscription\n\n";
    echo "Usage: php scripts/local-aidev-full.php --issue=SSI-1883 [options]\n\n";
    echo "Options:\n";
    echo "  --issue         Issue key (e.g., SSI-1883) [required]\n";
    echo "  --member        Member ID (default: 3)\n";
    echo "  --tenant        Tenant slug for multi-tenancy (e.g., gwt)\n";
    echo "  --job-id        Job ID for status tracking (auto-generated if not provided)\n";
    echo "  --repo          Repository connection ID (from ai-dev-{id} label)\n";
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

require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/bootstrap.php';
require_once $baseDir . '/lib/plugins/AtlassianAuth.php';
require_once $baseDir . '/lib/TmuxManager.php';
require_once $baseDir . '/lib/Bean.php';
require_once $baseDir . '/services/JiraClient.php';
require_once $baseDir . '/services/EncryptionService.php';
require_once $baseDir . '/services/AIDevAgentOrchestrator.php';
require_once $baseDir . '/services/AIDevStatusService.php';
require_once $baseDir . '/services/ShopifyClient.php';
require_once $baseDir . '/services/UserDatabaseService.php';

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\TmuxManager;
use \app\Bean;
use \app\services\JiraClient;
use \app\services\EncryptionService;
use \app\services\ShopifyClient;
use \app\services\AIDevStatusService;
use \app\services\UserDatabaseService;
use \app\plugins\AtlassianAuth;

// Determine config file based on tenant parameter
$tenant = $options['tenant'] ?? null;
if ($tenant) {
    $configFile = $baseDir . "/conf/config.{$tenant}.ini";
    if (!file_exists($configFile)) {
        echo "Error: Tenant config not found: {$configFile}\n";
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
$autoAttach = isset($options['attach']);
$usePrintMode = isset($options['print']);
$dryRun = isset($options['dry-run']);
$skipJira = isset($options['skip-jira']);
$repoPath = $options['repo-path'] ?? null;

// Get domain identifier and session names from TmuxManager (single source of truth)
$domainId = TmuxManager::getDomainId();

// Default cloudId - will be looked up from member's Atlassian connection
$cloudId = 'cb1fabf7-9018-49bb-90c7-afa23343dbe5';

// Use TmuxManager for consistent session naming across all components
$sessionName = TmuxManager::buildLocalRunnerSessionName($memberId, $issueKey);
$workDir = "/tmp/local-aidev-{$domainId}-{$memberId}-{$issueKey}";

echo "===========================================\n";
echo "Local AI Developer - Using YOUR Claude Account\n";
echo "===========================================\n\n";
echo "Domain: {$domainId}\n";
if ($tenant) echo "Tenant: {$tenant}\n";
echo "Issue: {$issueKey}\n";
echo "Member ID: {$memberId}\n";
if ($jobId) echo "Job ID: {$jobId}\n";
echo "Print Mode: " . ($usePrintMode ? "YES" : "NO") . "\n";
if ($dryRun) echo "DRY RUN: YES (testing DB queries only)\n";
echo "Session: {$sessionName}\n";
echo "Work Dir: {$workDir}\n\n";

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

if ($skipJira) {
    echo "Skipping Jira API calls (--skip-jira flag)\n";
    $summary = "Test ticket {$issueKey}";
    $description = "Test description for database query testing";
} else {
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
} // end else (!$skipJira)

// ============================================
// Get Repository Configuration
// ============================================
echo "Getting repository configuration...\n";
$member = R::load('member', $memberId);
if (!$member || !$member->id) {
    echo "Error: Member not found\n";
    exit(1);
}

// Connect to user's database context for repoconnections, jiraboards, aiagents
UserDatabaseService::connect($memberId);

// Query repoconnections using Bean (user SQLite database)
// Use specific repo ID if provided (from ai-dev-{id} label), otherwise use first enabled
if ($repoIdParam) {
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

// Load agent configuration for this repo
$agentConfig = null;
$agentId = $repoBean->agent_id ?? null;
if ($agentId) {
    // Load agent by ID - repo is already validated for this member
    $agentBean = R::load('aiagents', $agentId);
    if ($agentBean && $agentBean->id && $agentBean->is_active) {
        $agentConfig = [
            'id' => $agentBean->id,
            'name' => $agentBean->name,
            'runner_type' => $agentBean->runner_type,
            'runner_config' => json_decode($agentBean->runner_config ?: '{}', true),
            'mcp_servers' => json_decode($agentBean->mcp_servers ?: '[]', true),
            'hooks_config' => json_decode($agentBean->hooks_config ?: '{}', true)
        ];
        echo "  Agent: {$agentConfig['name']} ({$agentConfig['runner_type']})\n";
    }
}

// If no agent assigned, check for default agent
if (!$agentConfig) {
    $defaultAgent = R::findOne('aiagents', 'member_id = ? AND is_default = 1 AND is_active = 1', [$memberId]);
    if ($defaultAgent) {
        $agentConfig = [
            'id' => $defaultAgent->id,
            'name' => $defaultAgent->name,
            'runner_type' => $defaultAgent->runner_type,
            'runner_config' => json_decode($defaultAgent->runner_config ?: '{}', true),
            'mcp_servers' => json_decode($defaultAgent->mcp_servers ?: '[]', true),
            'hooks_config' => json_decode($defaultAgent->hooks_config ?: '{}', true)
        ];
        echo "  Agent: {$agentConfig['name']} (default)\n";
    } else {
        echo "  Agent: None (using built-in defaults)\n";
    }
}

$repoOwner = $repoBean->repo_owner;
$repoName = $repoBean->repo_name;
$defaultBranch = $repoBean->default_branch ?? 'main';
$cloneUrl = $repoBean->clone_url;

// Get GitHub token
$githubToken = '';
if (!empty($repoBean->access_token)) {
    try {
        $encryption = new EncryptionService();
        $githubToken = $encryption->decrypt($repoBean->access_token);
        echo "  GitHub Token: ****" . substr($githubToken, -4) . "\n";
    } catch (Exception $e) {
        echo "  Warning: Could not decrypt GitHub token\n";
    }
}

// ============================================
// Get Jira Credentials
// ============================================
$jiraHost = "https://api.atlassian.com/ex/jira/{$cloudId}";
$jiraEmail = '';
$jiraToken = '';
$jiraOAuthToken = '';
$jiraSiteUrl = '';

try {
    $atlassianToken = R::findOne('atlassiantoken', 'member_id = ? AND cloud_id = ?', [$memberId, $cloudId]);
    if ($atlassianToken) {
        $jiraEmail = $atlassianToken->email ?? '';
        // Get the site URL for ticket links
        $siteUrl = $atlassianToken->site_url ?? '';
        if ($siteUrl) {
            $jiraSiteUrl = rtrim($siteUrl, '/');
        }
        // Get OAuth token for API calls
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

// ============================================
// Pre-clone the repository
// ============================================
echo "Cloning repository...\n";
$repoDir = "{$workDir}/repo";
if (!empty($githubToken)) {
    $cloneCmd = "git clone https://{$githubToken}@github.com/{$repoOwner}/{$repoName}.git " . escapeshellarg($repoDir) . " 2>&1";
    exec($cloneCmd, $cloneOutput, $cloneExitCode);
    if ($cloneExitCode === 0) {
        echo "  Repository cloned successfully\n";
        // Checkout default branch
        exec("git -C " . escapeshellarg($repoDir) . " checkout " . escapeshellarg($defaultBranch) . " 2>&1");
    } else {
        echo "  Warning: Clone failed - " . implode("\n", $cloneOutput) . "\n";
    }
} else {
    echo "  Warning: No GitHub token, cannot pre-clone\n";
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
    $boardBean = Bean::findOne('jiraboards', 'project_key = ?', [$projectKey]);
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
    $urlsToCheck
);

// Build prompt - always use orchestrator pattern for better verification
$prompt = $orchestrator->buildOrchestratorPrompt();

// Save prompt
$promptFile = "{$workDir}/prompt.txt";
file_put_contents($promptFile, $prompt);
echo "  Prompt saved to: {$promptFile}\n";
echo "  Prompt length: " . strlen($prompt) . " chars\n\n";

// Create CLAUDE.md for the session
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

## Instructions
Follow the prompt in prompt.txt to implement the ticket.
MD;
file_put_contents("{$workDir}/CLAUDE.md", $claudeMd);

// ============================================
// Create .mcp.json for MCP servers
// ============================================
// Start with defaults (Jira HTTP + Playwright stdio)
// Use fixed main domain with tenant slug in URL path
// This ensures MCP works regardless of which subdomain context we're in
// URL pattern: https://myctobot.ai/mcp/{tenant}/jira
// Tenant comes from --tenant flag (e.g., 'gwt'), fall back to 'default' if not set
$mcpTenant = $tenant ?? 'default';
$mcpHttpUrl = "https://myctobot.ai/mcp/{$mcpTenant}/jira";
$mcpCredentials = base64_encode("{$memberId}:{$cloudId}");
$mcpServers = (object) [
    'playwright' => (object) [
        'type' => 'stdio',
        'command' => 'npx',
        'args' => ['@playwright/mcp@latest'],
        'env' => new \stdClass()
    ],
    'jira' => (object) [
        'type' => 'http',
        'url' => $mcpHttpUrl,
        'headers' => (object) [
            'Authorization' => "Basic {$mcpCredentials}"
        ]
    ]
];

// Merge agent's MCP servers if configured
$enabledMcpServers = ['jira', 'playwright'];
if ($agentConfig && !empty($agentConfig['mcp_servers'])) {
    echo "  Loading MCP servers from agent config...\n";
    foreach ($agentConfig['mcp_servers'] as $server) {
        $serverName = $server['name'] ?? 'unnamed';
        $serverType = $server['type'] ?? 'stdio';

        if ($serverType === 'http') {
            $mcpServers->$serverName = (object) [
                'type' => 'http',
                'url' => $server['url'] ?? '',
                'headers' => (object) ($server['headers'] ?? [])
            ];
        } else {
            // stdio type
            $mcpServers->$serverName = (object) [
                'type' => 'stdio',
                'command' => $server['command'] ?? '',
                'args' => $server['args'] ?? [],
                'env' => (object)($server['env'] ?? [])
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
echo "  MCP Jira: HTTP transport ({$mcpHttpUrl})\n";

// ============================================
// Create .claude/settings.json for hooks
// ============================================
if ($agentConfig && !empty($agentConfig['hooks_config'])) {
    echo "  Loading hooks from agent config...\n";
    $claudeSettingsDir = "{$workDir}/.claude";
    if (!is_dir($claudeSettingsDir)) {
        mkdir($claudeSettingsDir, 0755, true);
    }

    $hooksConfig = $agentConfig['hooks_config'];
    $hookCount = 0;
    foreach (['PreToolUse', 'PostToolUse', 'Stop'] as $event) {
        if (!empty($hooksConfig[$event])) {
            $hookCount += count($hooksConfig[$event]);
        }
    }
    echo "    {$hookCount} hooks configured\n";

    $claudeSettings = ['hooks' => $hooksConfig];
    file_put_contents("{$claudeSettingsDir}/settings.json", json_encode($claudeSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Also copy to repo directory
    if (is_dir($repoDir)) {
        $repoClaudeDir = "{$repoDir}/.claude";
        if (!is_dir($repoClaudeDir)) {
            mkdir($repoClaudeDir, 0755, true);
        }
        copy("{$claudeSettingsDir}/settings.json", "{$repoClaudeDir}/settings.json");
    }
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

$envScript = <<<BASH
#!/bin/bash
# Environment setup for AI Developer session
# Generated for issue: {$issueKey}

export GITHUB_TOKEN="{$githubToken}"
export GH_TOKEN="{$githubToken}"
export JIRA_HOST="{$jiraHost}"
export JIRA_EMAIL="{$jiraEmail}"
export JIRA_API_TOKEN="{$jiraOAuthToken}"
export GIT_TERMINAL_PROMPT="0"
export TERM="xterm-256color"
{$shopifyEnvSection}

# Verify tokens are set
echo "=== Environment Setup ==="
echo "Issue: {$issueKey}"
echo "Summary: {$summary}"
echo ""
echo "GITHUB_TOKEN: ****\${GITHUB_TOKEN: -4}"
echo "GH_TOKEN: ****\${GH_TOKEN: -4}"
echo "JIRA_HOST: \$JIRA_HOST"
echo "JIRA_API_TOKEN: ****\${JIRA_API_TOKEN: -4}"
{$shopifyEchoSection}
echo "========================="
echo ""

cd {$workDir}

BASH;

// Jira update function to be added to bash script
$jiraUpdateScript = <<<'JIRA_BASH'

# ===========================================
# Post-Execution Jira Update
# ===========================================
update_jira() {
    local output_file="$1"
    local log_file="$2"

    echo ""
    echo "=== Updating Jira ==="

    # First, check if Claude wrote a clean result.json file (preferred method)
    local json_result=""
    if [[ -f "result.json" ]]; then
        echo "Found result.json - using clean JSON output"
        json_result=$(cat result.json)
    elif [[ -f "${WORK_DIR}/result.json" ]]; then
        echo "Found result.json in work dir"
        json_result=$(cat "${WORK_DIR}/result.json")
    else
        # Fall back to grepping from output files
        echo "No result.json found, trying to extract from logs..."
        if [[ -f "$output_file" ]]; then
            json_result=$(grep -Pzo '(?s)\{[^{}]*"success"[^{}]*("files_changed"\s*:\s*\[[^\]]*\][^{}]*)?\}' "$output_file" 2>/dev/null | tr -d '\0' | tail -1)
        fi

        if [[ -z "$json_result" && -f "$log_file" ]]; then
            json_result=$(grep -Pzo '(?s)\{[^{}]*"success"[^{}]*("files_changed"\s*:\s*\[[^\]]*\][^{}]*)?\}' "$log_file" 2>/dev/null | tr -d '\0' | tail -1)
        fi
    fi

    if [[ -z "$json_result" ]]; then
        echo "Warning: Could not find result from Claude output"
        echo "Claude should have posted its own Jira update. Skipping automated update."
        return 1
    fi

    # Save JSON for debugging
    echo "$json_result" > "${WORK_DIR}/claude-output.json"
    echo "JSON output saved to: ${WORK_DIR}/claude-output.json"

    # Parse JSON fields
    local success=$(echo "$json_result" | jq -r '.success // "false"')
    local issue_key=$(echo "$json_result" | jq -r '.issue_key // ""')
    local branch_name=$(echo "$json_result" | jq -r '.branch_name // ""')
    local pr_url=$(echo "$json_result" | jq -r '.pr_url // ""')
    local verification_passed=$(echo "$json_result" | jq -r '.verification_passed // "false"')
    local summary=$(echo "$json_result" | jq -r '.summary // "No summary provided"' | sed 's/"/\\"/g')
    local files_changed=$(echo "$json_result" | jq -c '.files_changed // []')

    echo "  Issue: $issue_key"
    echo "  Success: $success"
    echo "  PR URL: $pr_url"

    if [[ -z "$issue_key" || "$issue_key" == "null" ]]; then
        echo "Warning: No issue key found in output"
        return 1
    fi

    # Build files list for comment
    local files_list=""
    for file in $(echo "$files_changed" | jq -r '.[]' 2>/dev/null); do
        files_list="${files_list}{\"type\":\"listItem\",\"content\":[{\"type\":\"paragraph\",\"content\":[{\"type\":\"text\",\"text\":\"$file\"}]}]},"
    done
    files_list="${files_list%,}"

    # Add comment to Jira
    local status_text="completed successfully"
    [[ "$success" != "true" ]] && status_text="completed with issues"

    local comment_body=$(cat <<COMMENT_EOF
{
    "body": {
        "type": "doc",
        "version": 1,
        "content": [
            {"type": "paragraph", "content": [{"type": "text", "text": "Implementation $status_text by Claude Code automation.", "marks": [{"type": "strong"}]}]},
            {"type": "paragraph", "content": [{"type": "text", "text": "Pull Request: "}, {"type": "text", "text": "$pr_url", "marks": [{"type": "link", "attrs": {"href": "$pr_url"}}]}]},
            {"type": "paragraph", "content": [{"type": "text", "text": "Branch: $branch_name"}]},
            {"type": "paragraph", "content": [{"type": "text", "text": "Files Changed:"}]},
            {"type": "bulletList", "content": [$files_list]},
            {"type": "paragraph", "content": [{"type": "text", "text": "Summary: $summary"}]}
        ]
    }
}
COMMENT_EOF
)

    echo "Adding comment to Jira..."
    local comment_response=$(curl -s -w "\n%{http_code}" -X POST \
        "$JIRA_HOST/rest/api/3/issue/$issue_key/comment" \
        -H "Authorization: Bearer $JIRA_API_TOKEN" \
        -H "Content-Type: application/json" \
        -d "$comment_body" 2>/dev/null)

    local http_code=$(echo "$comment_response" | tail -1)
    if [[ "$http_code" =~ ^2 ]]; then
        echo "  ✓ Comment added"
    else
        echo "  ✗ Failed to add comment (HTTP $http_code)"
    fi

    # Transition ticket status
    local transition_id
    if [[ "$success" == "true" && "$verification_passed" == "true" ]]; then
        transition_id="71"   # Push Request
        echo "Transitioning to 'Push Request'..."
    else
        transition_id="41"   # Needs More Work
        echo "Transitioning to 'Needs More Work'..."
    fi

    local transition_response=$(curl -s -w "\n%{http_code}" -X POST \
        "$JIRA_HOST/rest/api/3/issue/$issue_key/transitions" \
        -H "Authorization: Bearer $JIRA_API_TOKEN" \
        -H "Content-Type: application/json" \
        -d "{\"transition\": {\"id\": \"$transition_id\"}}" 2>/dev/null)

    http_code=$(echo "$transition_response" | tail -1)
    if [[ "$http_code" =~ ^2 ]]; then
        echo "  ✓ Status updated"
    else
        echo "  ✗ Failed to update status (HTTP $http_code)"
    fi

    echo "=== Jira Update Complete ==="
}

JIRA_BASH;

$jiraUpdateScriptWithVars = str_replace('${WORK_DIR}', $workDir, $jiraUpdateScript);
$envScript .= $jiraUpdateScriptWithVars;

if ($usePrintMode) {
    // Non-interactive mode with logging
    $logFile = "{$workDir}/session.log";
    $envScript .= <<<BASH

echo "Starting Claude in print mode..."
echo "Log file: {$logFile}"
echo ""
claude --print --dangerously-skip-permissions < prompt.txt 2>&1 | tee {$logFile}

# Update Jira after Claude completes
update_jira "{$logFile}" "{$logFile}"
BASH;
} else {
    // Interactive TUI mode - pass prompt as argument (not -p flag) to show TUI
    $logFile = "{$workDir}/session.log";
    $envScript .= <<<'BASH'

echo "Starting Claude with TUI..."
echo "Environment variables are set - use them for git/API operations."
echo ""

# Use script to capture output while preserving TUI
# Pass prompt content as argument (without -p) to start with message AND show TUI
script -q session.log -c 'claude --dangerously-skip-permissions "$(cat prompt.txt)"'

# Update Jira after Claude completes
BASH;
    $envScript .= "\nupdate_jira \"{$workDir}/session.log\" \"{$workDir}/session.log\"\n";
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
    echo "  - Domain: {$domainId}\n";
    echo "  - Repository: {$repoOwner}/{$repoName}\n";
    echo "  - Default Branch: {$defaultBranch}\n";
    echo "  - GitHub Token: " . (!empty($githubToken) ? "****" . substr($githubToken, -4) : "NOT SET") . "\n";
    echo "  - Jira Cloud ID: {$cloudId}\n";
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

// Manual mode - create our own tmux session
echo "Setting up tmux session...\n";
exec("tmux kill-session -t '{$sessionName}' 2>/dev/null");

// Create new tmux session
$outputFile = "{$workDir}/output.log";
exec("tmux new-session -d -s '{$sessionName}' -x 200 -y 50");

// Run the wrapper script which sets env and starts Claude
exec("tmux send-keys -t '{$sessionName}' '{$envScriptPath}' Enter");
sleep(3);  // Wait for env setup and Claude to start

if (!$usePrintMode) {
    // For interactive mode, accept the bypass permissions dialog
    sleep(2);
    exec("tmux send-keys -t '{$sessionName}' Down");  // Move to "Yes, I accept"
    sleep(1);
    exec("tmux send-keys -t '{$sessionName}' Enter");  // Confirm
    sleep(3);  // Wait for Claude to fully start

    // Send the prompt
    exec("tmux send-keys -t '{$sessionName}' 'Please read and execute the task in prompt.txt' Enter");
    sleep(1);
    exec("tmux send-keys -t '{$sessionName}' Enter");  // Submit
}

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
echo "  Kill session:  tmux kill-session -t {$sessionName}\n";
echo "\n";
echo "This uses YOUR Claude subscription, not API credits!\n";
echo "============================================\n";
echo "\n";

if ($autoAttach) {
    echo "Attaching to session... (Ctrl+B, D to detach)\n\n";
    passthru("tmux attach -t '{$sessionName}'");
} else {
    echo "Attach now? (y/N): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    if (trim(strtolower($line)) === 'y') {
        passthru("tmux attach -t '{$sessionName}'");
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
