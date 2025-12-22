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
    'orchestrator',
    'attach',
    'repo-path:',
    'print',
    'help'
]);

if (isset($options['help']) || empty($options['issue'])) {
    echo "Local AI Developer Runner - Uses YOUR Claude subscription\n\n";
    echo "Usage: php scripts/local-aidev-full.php --issue=SSI-1883 [options]\n\n";
    echo "Options:\n";
    echo "  --issue         Issue key (e.g., SSI-1883) [required]\n";
    echo "  --member        Member ID (default: 3)\n";
    echo "  --orchestrator  Use agent orchestrator pattern\n";
    echo "  --attach        Attach to tmux session immediately\n";
    echo "  --print         Use --print mode (non-interactive, outputs to log)\n";
    echo "  --repo-path     Path to existing repo clone\n";
    echo "  --help          Show this help\n\n";
    echo "This runs Claude Code using YOUR logged-in account,\n";
    echo "not the Anthropic API, saving API credits.\n";
    exit(1);
}

require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/bootstrap.php';
require_once $baseDir . '/lib/plugins/AtlassianAuth.php';
require_once $baseDir . '/services/JiraClient.php';
require_once $baseDir . '/services/EncryptionService.php';
require_once $baseDir . '/services/AIDevAgentOrchestrator.php';
require_once $baseDir . '/services/ShopifyClient.php';

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\services\JiraClient;
use \app\services\EncryptionService;
use \app\services\ShopifyClient;
use \app\plugins\AtlassianAuth;

$bootstrap = new \app\Bootstrap($baseDir . '/conf/config.ini');

$issueKey = $options['issue'];
$memberId = isset($options['member']) ? (int)$options['member'] : 3;
$useOrchestrator = isset($options['orchestrator']);
$autoAttach = isset($options['attach']);
$usePrintMode = isset($options['print']);
$repoPath = $options['repo-path'] ?? null;

// Default cloudId - will be looked up from member's Atlassian connection
$cloudId = 'cb1fabf7-9018-49bb-90c7-afa23343dbe5';

$sessionName = "aidev-{$memberId}-{$issueKey}";
$workDir = "/tmp/local-aidev-{$memberId}-{$issueKey}";

echo "===========================================\n";
echo "Local AI Developer - Using YOUR Claude Account\n";
echo "===========================================\n\n";
echo "Issue: {$issueKey}\n";
echo "Orchestrator: " . ($useOrchestrator ? "YES" : "NO") . "\n";
echo "Print Mode: " . ($usePrintMode ? "YES" : "NO") . "\n";
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

// ============================================
// Get Repository Configuration
// ============================================
echo "Getting repository configuration...\n";
$member = R::load('member', $memberId);
if (!$member || empty($member->ceobot_db)) {
    echo "Error: Member database not found\n";
    exit(1);
}

$dbPath = Flight::get('ceobot.user_db_path') ?? 'database/';
$dbFile = $dbPath . $member->ceobot_db . '.sqlite';
$db = new \SQLite3($dbFile);

$repoResult = $db->querySingle("SELECT * FROM repoconnections WHERE enabled = 1 LIMIT 1", true);
if (!$repoResult) {
    echo "Error: No enabled repository found\n";
    exit(1);
}

$repoOwner = $repoResult['repo_owner'];
$repoName = $repoResult['repo_name'];
$defaultBranch = $repoResult['default_branch'] ?? 'main';
$cloneUrl = $repoResult['clone_url'];

// Get GitHub token
$githubToken = '';
if (!empty($repoResult['access_token'])) {
    try {
        $encryption = new EncryptionService();
        $githubToken = $encryption->decrypt($repoResult['access_token']);
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
    $boardResult = $db->querySingle(
        "SELECT aidev_status_working, aidev_status_pr_created, aidev_status_clarification,
                aidev_status_failed, aidev_status_complete
         FROM jiraboards WHERE project_key = " . $db->escapeString($projectKey),
        true
    );
    if ($boardResult) {
        $statusSettings = [
            'working' => $boardResult['aidev_status_working'] ?? null,
            'pr_created' => $boardResult['aidev_status_pr_created'] ?? null,
            'clarification' => $boardResult['aidev_status_clarification'] ?? null,
            'failed' => $boardResult['aidev_status_failed'] ?? null,
            'complete' => $boardResult['aidev_status_complete'] ?? null
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

        // Get storefront password from user database
        $storefrontPwdResult = $db->querySingle(
            "SELECT setting_value FROM enterprisesettings WHERE setting_key = 'shopify_storefront_password'"
        );
        if ($storefrontPwdResult) {
            $shopifyStorefrontPassword = EncryptionService::decrypt($storefrontPwdResult);
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
// Build Prompt
// ============================================
echo "Building prompt...\n";
if ($useOrchestrator) {
    $ticket = [
        'key' => $issueKey,
        'summary' => $summary,
        'description' => $description,
        'requirements' => $description,
        'acceptance_criteria' => extractAcceptanceCriteria($description),
        'comments' => $commentText
    ];

    $repo = [
        'path' => './repo',
        'clone_url' => $cloneUrl,
        'default_branch' => $defaultBranch,
        'owner' => $repoOwner,
        'name' => $repoName
    ];

    $orchestrator = new \app\services\AIDevAgentOrchestrator($ticket, $repo, null, 3, $statusSettings);
    $prompt = $orchestrator->buildOrchestratorPrompt();
} else {
    // Build comprehensive prompt like the shard does
    $prompt = <<<PROMPT
You are an AI Developer implementing a Jira ticket. You have full access to:
- Git and GitHub (clone, branch, commit, push, create PR)
- Browser/web tools (to check URLs and verify your work)
- Jira API (to fetch details and post comments)
- Filesystem (to read and write code)

## Your Mission
Implement the requirements from Jira ticket **{$issueKey}** and create a Pull Request.

## Jira Ticket: {$issueKey}
{$ticketLink}
- Type: {$issueType}
- Priority: {$priority}
- Status: {$status}

### Summary
{$summary}

### Description
{$description}

### Comments/Clarifications
{$commentText}

{$attachmentInfo}
{$linkedIssuesInfo}
{$urlCheckInstructions}
{$shopifyInstructions}

## Repository
- Owner: {$repoOwner}
- Repo: {$repoName}
- Default Branch: {$defaultBranch}
- Clone URL: {$cloneUrl}

## Environment Variables Available
The following environment variables are set and ready to use:

- **GITHUB_TOKEN** / **GH_TOKEN**: GitHub access token for git operations
  ```bash
  git clone https://\$GITHUB_TOKEN@github.com/{$repoOwner}/{$repoName}.git repo
  ```

- **JIRA_API_TOKEN**: Jira OAuth token for API calls
  ```bash
  # Get issue details
  curl -s -H "Authorization: Bearer \$JIRA_API_TOKEN" "{$jiraHost}/rest/api/3/issue/{$issueKey}"

  # Post a comment (for clarifying questions)
  curl -X POST -H "Authorization: Bearer \$JIRA_API_TOKEN" -H "Content-Type: application/json" \\
    "{$jiraHost}/rest/api/3/issue/{$issueKey}/comment" \\
    -d '{"body":{"type":"doc","version":1,"content":[{"type":"paragraph","content":[{"type":"text","text":"Your question here"}]}]}}'
  ```

- **JIRA_HOST**: {$jiraHost}
- **JIRA_EMAIL**: {$jiraEmail}

## Your Workflow
1. **Understand & Clarify**: Read the ticket carefully. Check any URLs mentioned to understand the current state.
   - **IMPORTANT**: If requirements are unclear, ambiguous, or missing critical details, STOP and ask clarifying questions.
   - Post questions as a Jira comment using the API before proceeding with implementation.
   - Wait for a response (the user will send it via Jira and you'll receive updates).
   - Example clarifying questions: "Which specific element should be modified?", "Should this apply to all pages or just X?", "What should happen when Y occurs?"
2. **Fetch Attachments**: If there are image attachments, download and view them to understand visual requirements.
3. **Repository**: The repo is already cloned to `./repo` and checked out to `{$defaultBranch}`.
   **IMPORTANT: Do NOT `cd repo`** - stay in the current directory and reference files as `repo/path/to/file`. Use `git -C repo <command>` for git operations.
4. **Analyze the codebase**: Find relevant files for the implementation.
5. **Create a feature branch**: Use a descriptive name like `fix/{$issueKey}-description`.
6. **Implement the changes**: Write clean, well-tested code.
7. **Verify your work**: If URLs were provided, check them to verify (if applicable).
8. **Commit and push**: Write a good commit message referencing {$issueKey}.
9. **Create a PR**: Use the GitHub CLI (`gh pr create`). Include:
   - Title: [{$issueKey}] Brief description
   - Body: Summary of changes, link to ticket, testing notes

## Important Guidelines
- **Ask First**: If requirements are unclear or ambiguous, post a clarifying question to Jira BEFORE implementing.
- **Check URLs**: If URLs are mentioned, visit them to understand current state.
- **Download images**: If attachments exist, download and view them.
- **Iterate**: Don't just write code blindly. Verify your understanding first.
- **Be thorough**: Check your changes work correctly before creating the PR.

## Post Status Updates to Jira

**You MUST post comments to Jira at key milestones** so stakeholders can track progress:

1. **When starting**: Post "🤖 AI Developer starting work on this ticket..."
2. **If asking questions**: Post your clarifying questions (already covered above)
3. **When PR is created**: Post the PR URL and summary of changes
4. **If blocked/failed**: Post what went wrong and what's needed

Use this curl command to post updates:
```bash
curl -X POST -H "Authorization: Bearer \$JIRA_API_TOKEN" -H "Content-Type: application/json" \\
  "{$jiraHost}/rest/api/3/issue/{$issueKey}/comment" \\
  -d '{"body":{"type":"doc","version":1,"content":[{"type":"paragraph","content":[{"type":"text","text":"Your update message here"}]}]}}'
```

## Output Format
When complete, output a JSON summary:
```json
{
  "success": true,
  "issue_key": "{$issueKey}",
  "pr_url": "https://github.com/...",
  "pr_number": 123,
  "branch_name": "fix/...",
  "files_changed": ["path/to/file1.php", "path/to/file2.css"],
  "summary": "Brief description of what was implemented"
}
```

If you encounter issues or need clarification, output:
```json
{
  "success": false,
  "issue_key": "{$issueKey}",
  "needs_clarification": true,
  "questions": ["Question 1?", "Question 2?"],
  "reason": "Why clarification is needed"
}
```

Now, implement {$issueKey}!
PROMPT;
}

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

// Create .mcp.json for MCP servers (env vars inherited from wrapper script)
$mcpServerPath = $baseDir . '/scripts/mcp-jira-server.php';
$mcpConfig = [
    'mcpServers' => [
        'playwright' => [
            'type' => 'stdio',
            'command' => 'npx',
            'args' => ['@playwright/mcp@latest'],
            'env' => new \stdClass()
        ],
        'jira' => [
            'type' => 'stdio',
            'command' => 'php',
            'args' => [$mcpServerPath],
            'env' => [
                'JIRA_MEMBER_ID' => (string)$memberId,
                'JIRA_CLOUD_ID' => $cloudId
            ]
        ]
    ]
];
file_put_contents("{$workDir}/.mcp.json", json_encode($mcpConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Pre-approve MCP servers in ~/.claude.json so Claude doesn't prompt
$claudeConfigPath = getenv('HOME') . '/.claude.json';
if (file_exists($claudeConfigPath)) {
    $claudeConfig = json_decode(file_get_contents($claudeConfigPath), true);
    if ($claudeConfig && isset($claudeConfig['projects'])) {
        $claudeConfig['projects'][$workDir] = [
            'allowedTools' => [],
            'dontCrawlDirectory' => false,
            'mcpContextUris' => [],
            'mcpServers' => new \stdClass(),  // Empty object, servers defined in .mcp.json
            'enabledMcpjsonServers' => ['jira', 'playwright'],
            'disabledMcpjsonServers' => [],
            'hasTrustDialogAccepted' => true,
            'hasCompletedProjectOnboarding' => true,
            'projectOnboardingSeenCount' => 1,
            'hasClaudeMdExternalIncludesApproved' => false,
            'hasClaudeMdExternalIncludesWarningShown' => false
        ];
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
