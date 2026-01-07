<?php
/**
 * AI Developer Agent Orchestrator
 *
 * Builds prompts for the orchestrator pattern where a main Claude session
 * coordinates specialized subagents (impl-agent, verify-agent, fix-agent).
 *
 * This reduces context bloat by giving each agent fresh context focused
 * on its specific task.
 */

namespace app\services;

class AIDevAgentOrchestrator {

    private array $ticket;
    private array $repo;
    private ?string $previewUrl;
    private int $maxVerifyIterations;
    private array $statusSettings;
    private array $shopify;
    private ?string $existingBranch;
    private array $urls;

    /**
     * Create orchestrator for a ticket
     *
     * @param array $ticket Ticket data with keys: key, summary, description, requirements, acceptance_criteria,
     *                      comments, attachments, linkedIssues, issueType, priority, status, ticketUrl
     * @param array $repo Repository data with keys: path, clone_url, default_branch, owner, name
     * @param string|null $previewUrl Shopify preview URL if applicable
     * @param int $maxVerifyIterations Max verify→fix loops (default 3)
     * @param array $statusSettings Status transition settings (working, pr_created, clarification, failed, complete)
     * @param array $shopify Shopify config with keys: enabled, domain, storefront_password
     * @param string|null $existingBranch Existing branch name for branch affinity
     * @param array $urls URLs found in ticket to check
     */
    public function __construct(
        array $ticket,
        array $repo,
        ?string $previewUrl = null,
        int $maxVerifyIterations = 3,
        array $statusSettings = [],
        array $shopify = [],
        ?string $existingBranch = null,
        array $urls = []
    ) {
        $this->ticket = $ticket;
        $this->repo = $repo;
        $this->previewUrl = $previewUrl;
        $this->maxVerifyIterations = $maxVerifyIterations;
        $this->statusSettings = $statusSettings;
        $this->shopify = $shopify;
        $this->existingBranch = $existingBranch;
        $this->urls = $urls;
    }

    /**
     * Build the main orchestrator prompt
     *
     * This prompt is given to the main Claude session which will use
     * the Task tool to spawn specialized agents.
     *
     * @return string The orchestrator prompt
     */
    public function buildOrchestratorPrompt(): string {
        $issueKey = $this->ticket['key'];
        $summary = $this->ticket['summary'];
        $description = $this->ticket['description'] ?? '';
        $comments = $this->ticket['comments'] ?? '';
        $requirements = $this->formatRequirements();
        $acceptanceCriteria = $this->formatAcceptanceCriteria();
        $repoPath = $this->repo['path'];
        $defaultBranch = $this->repo['default_branch'] ?? 'main';
        $previewSection = $this->previewUrl ? $this->buildPreviewSection() : '';

        // Build comments section if present
        $commentsSection = '';
        if (!empty($comments)) {
            $commentsSection = "\n**Comments/Clarifications**:\n{$comments}\n";
        }

        return <<<PROMPT
# AI Developer Orchestrator

You are orchestrating the implementation and verification of Jira ticket **{$issueKey}**.

## Ticket Information

**Summary**: {$summary}

**Description**:
{$description}
{$commentsSection}
**Requirements**:
{$requirements}

**Acceptance Criteria**:
{$acceptanceCriteria}

## Repository

- **Owner**: {$this->repo['owner']}
- **Name**: {$this->repo['name']}
- **Clone URL**: {$this->repo['clone_url']}
- **Default Branch**: {$defaultBranch}
- **Working Path**: {$repoPath}
{$previewSection}

## Repository (ALREADY CLONED)

The repository has been pre-cloned to `{$repoPath}/` and checked out to `{$defaultBranch}`.

**IMPORTANT: Do NOT `cd {$repoPath}`** - stay in the current directory and reference all files as `{$repoPath}/path/to/file`. This ensures MCP tools (Jira, Playwright) work correctly.

You can run git commands using `git -C {$repoPath} <command>` to stay in the work directory.

## Your Workflow

You will coordinate specialized agents to implement and verify this ticket.
Each agent has fresh context - pass only the essential information it needs.

### Phase 0: Clarification (if needed)

**Before implementation**, review the ticket requirements carefully.
- If requirements are unclear, ambiguous, or missing critical details, STOP.
- Post clarifying questions using the MCP tool: `jira_comment("{$issueKey}", "Your question here")`
- Wait for a response (you'll receive Jira updates via [JIRA UPDATE] messages).
- Example questions: "Which specific element should be modified?", "Should this apply to all pages or just X?"

**Only proceed to Phase 1 after you have clear requirements.**

### Phase 1: Implementation

Use the Task tool to spawn an implementation agent:

```
Task(subagent_type="impl-agent", prompt="...")
```

**Prompt for impl-agent**:
- Include: ticket summary, requirements, repo path
- Ask for: branch name, files changed, commit sha, summary

### Phase 2: Verification

After impl-agent returns, spawn a verification agent:

```
Task(subagent_type="verify-agent", prompt="...")
```

**Prompt for verify-agent**:
- Include: files changed, acceptance criteria, preview URL (if any)
- Ask for: pass/fail, issues list with details

### Phase 3: Fix (if needed)

If verify-agent reports issues, spawn a fix agent:

```
Task(subagent_type="fix-agent", model="haiku", prompt="...")
```

**Prompt for fix-agent**:
- Include: ONLY the specific issues (not full history)
- Include: file paths and fix hints from verify-agent
- Ask for: files modified, changes summary

### Phase 4: Loop

Repeat verify → fix until:
- Verification passes, OR
- Max {$this->maxVerifyIterations} iterations reached

### Phase 5: Create PR and Report

When done:

1. **Write result to file** - Save your final result to `result.json`:
```bash
cat > result.json << 'RESULT_EOF'
{
  "success": true,
  "issue_key": "{$issueKey}",
  "branch_name": "feature/SSI-123-description",
  "files_changed": ["path/to/file1.js"],
  "pr_url": "https://github.com/...",
  "verification_passed": true,
  "iterations": 2,
  "summary": "Brief description of what was done"
}
RESULT_EOF
```

2. **Post final summary to Jira** - Use the MCP tool:
   `jira_comment("{$issueKey}", "AI Developer completed work.\n\nPR: [your PR URL]\nSummary: [what was done]")`

## Jira MCP Tools

You have access to Jira tools via MCP. **ALWAYS use these tools for Jira operations:**

- `jira_comment(issue_key, message)` - Post a comment to the ticket
- `jira_transition(issue_key, status_name)` - Transition ticket to a new status
- `jira_upload_attachment(issue_key, file_path)` - Upload a screenshot or file
- `jira_get_transitions(issue_key)` - Get available status transitions

## Post Status Updates to Jira

**You MUST post comments at key milestones** using `jira_comment`:

1. **When starting**: "AI Developer starting work on this ticket..."
2. **After implementation**: "Implementation complete, running verification..."
3. **When PR is created**: Include PR URL, branch name, and summary
4. **If blocked/failed**: Explain what went wrong and what's needed
5. **When complete**: Post final summary with PR link

## Upload Screenshots

**When you take screenshots during verification**, upload them using `jira_upload_attachment`:
- Save the screenshot to a file (e.g., `screenshot.png`)
- Upload it: `jira_upload_attachment("{$issueKey}", "screenshot.png")`
- Reference it in a follow-up comment

{$this->buildStatusTransitionSection($issueKey)}

## Important Rules

1. **Pass minimal context** - Each agent starts fresh. Include only what it needs.
2. **Don't repeat history** - Don't include previous agent outputs in new agent prompts.
3. **Track iterations** - Stop after {$this->maxVerifyIterations} verify→fix loops.
4. **Output JSON** - Final output must be valid JSON for parsing.
5. **No emojis** - Do NOT use emojis in Jira comments or any communication. Keep messages professional and plain text.

## Start Now

Begin by spawning the impl-agent to implement the changes.
PROMPT;
    }

    /**
     * Build status transition section with MCP tool examples
     */
    private function buildStatusTransitionSection(string $issueKey): string {
        if (empty($this->statusSettings)) {
            return '';
        }

        $hasAnyStatus = false;
        foreach ($this->statusSettings as $status) {
            if (!empty($status)) {
                $hasAnyStatus = true;
                break;
            }
        }

        if (!$hasAnyStatus) {
            return '';
        }

        $section = "## Transition Ticket Status\n\n";
        $section .= "**You MUST transition the ticket status at these milestones** using `jira_transition`:\n\n";

        if (!empty($this->statusSettings['working'])) {
            $section .= "- **When starting work**: `jira_transition(\"{$issueKey}\", \"{$this->statusSettings['working']}\")`\n";
        }
        if (!empty($this->statusSettings['pr_created'])) {
            $section .= "- **When PR is created**: `jira_transition(\"{$issueKey}\", \"{$this->statusSettings['pr_created']}\")`\n";
        }
        if (!empty($this->statusSettings['clarification'])) {
            $section .= "- **When clarification needed**: `jira_transition(\"{$issueKey}\", \"{$this->statusSettings['clarification']}\")`\n";
        }
        if (!empty($this->statusSettings['failed'])) {
            $section .= "- **If job fails**: `jira_transition(\"{$issueKey}\", \"{$this->statusSettings['failed']}\")`\n";
        }

        $section .= "\nUse `jira_get_transitions(\"{$issueKey}\")` to see available transitions if needed.\n";

        return $section;
    }

    /**
     * Build prompt for the implementation agent
     *
     * @return string
     */
    public function buildImplAgentPrompt(): string {
        $issueKey = $this->ticket['key'];
        $summary = $this->ticket['summary'];
        $description = $this->ticket['description'] ?? '';
        $requirements = $this->formatRequirements();
        $repoPath = $this->repo['path'];
        $defaultBranch = $this->repo['default_branch'] ?? 'main';

        return <<<PROMPT
# Implementation Task: {$issueKey}

## Summary
{$summary}

## Description
{$description}

## Requirements
{$requirements}

## Repository
Working directory: {$repoPath}
Base branch: {$defaultBranch}

## Your Task

1. **Explore** the codebase to understand the architecture
2. **Plan** your implementation approach
3. **Implement** the changes with clean, minimal code
4. **Create branch** named: fix/{$issueKey}-short-description
5. **Commit** with a descriptive message
6. **Push** to origin

## Output

When complete, output ONLY this JSON (no other text):

```json
{
  "success": true,
  "branch_name": "fix/{$issueKey}-...",
  "files_changed": ["path/to/file1", "path/to/file2"],
  "commit_sha": "abc123...",
  "summary": "Brief description of what was implemented"
}
```

If you encounter an error:
```json
{
  "success": false,
  "error": "Description of what went wrong"
}
```
PROMPT;
    }

    /**
     * Build prompt for the verification agent
     *
     * @param array $implResult Result from impl-agent
     * @return string
     */
    public function buildVerifyAgentPrompt(array $implResult): string {
        $issueKey = $this->ticket['key'];
        $acceptanceCriteria = $this->formatAcceptanceCriteria();
        $filesChanged = implode("\n- ", $implResult['files_changed'] ?? []);
        $branchName = $implResult['branch_name'] ?? '';
        $implSummary = $implResult['summary'] ?? '';

        $previewSection = '';
        if ($this->previewUrl) {
            $previewSection = <<<SECTION

## Preview URL
{$this->previewUrl}

Navigate to this URL to test the changes visually.
SECTION;
        }

        return <<<PROMPT
# Verification Task: {$issueKey}

## What Was Implemented
{$implSummary}

## Files Changed
- {$filesChanged}

## Branch
{$branchName}
{$previewSection}

## Acceptance Criteria to Verify
{$acceptanceCriteria}

## Your Task

1. **Navigate** to the preview URL (if provided) or test locally
2. **Test** each acceptance criterion
3. **Capture screenshots** as evidence (before/after if applicable)
4. **Report** pass or fail with detailed issues

## Output

If all criteria pass:
```json
{
  "passed": true,
  "issues": [],
  "screenshots": ["proof-1.png", "proof-2.png"]
}
```

If issues found:
```json
{
  "passed": false,
  "issues": [
    {
      "severity": "critical|major|minor",
      "description": "Clear description of the issue",
      "location": "Where in the UI/code",
      "expected": "What should happen",
      "actual": "What actually happens",
      "screenshot": "issue-screenshot.png",
      "fix_hint": "Suggestion for how to fix"
    }
  ],
  "screenshots": ["issue-1.png"]
}
```
PROMPT;
    }

    /**
     * Build prompt for the fix agent
     *
     * @param array $verifyResult Result from verify-agent with issues
     * @return string
     */
    public function buildFixAgentPrompt(array $verifyResult): string {
        $issueKey = $this->ticket['key'];
        $issues = $verifyResult['issues'] ?? [];

        $issuesText = '';
        foreach ($issues as $i => $issue) {
            $num = $i + 1;
            $issuesText .= <<<ISSUE

### Issue {$num}: {$issue['description']}
- **Severity**: {$issue['severity']}
- **Location**: {$issue['location']}
- **Expected**: {$issue['expected']}
- **Actual**: {$issue['actual']}
- **Fix Hint**: {$issue['fix_hint']}

ISSUE;
        }

        return <<<PROMPT
# Fix Task: {$issueKey}

You need to fix the following issues found during verification.

## Issues to Fix
{$issuesText}

## Your Task

1. **Read** the affected files
2. **Apply** minimal, targeted fixes for each issue
3. **Commit** the fixes
4. **Push** to the same branch

Focus ONLY on fixing these specific issues. Do not refactor or improve unrelated code.

## Output

When complete:
```json
{
  "success": true,
  "files_modified": ["path/to/file1"],
  "changes_summary": "Brief description of fixes applied"
}
```

If unable to fix:
```json
{
  "success": false,
  "error": "Description of why fixes couldn't be applied"
}
```
PROMPT;
    }

    /**
     * Format requirements as bullet list
     */
    private function formatRequirements(): string {
        $reqs = $this->ticket['requirements'] ?? [];
        if (empty($reqs)) {
            return 'See description above.';
        }
        if (is_string($reqs)) {
            return $reqs;
        }
        return '- ' . implode("\n- ", $reqs);
    }

    /**
     * Format acceptance criteria as bullet list
     */
    private function formatAcceptanceCriteria(): string {
        $criteria = $this->ticket['acceptance_criteria'] ?? [];
        if (empty($criteria)) {
            return 'Verify the implementation matches the requirements.';
        }
        if (is_string($criteria)) {
            return $criteria;
        }
        return '- ' . implode("\n- ", $criteria);
    }

    /**
     * Build preview URL section
     */
    private function buildPreviewSection(): string {
        return <<<SECTION

## Shopify Preview
- **Preview URL**: {$this->previewUrl}
- Use this URL for visual verification testing
SECTION;
    }

    /**
     * Build a direct prompt for Claude (non-orchestrator mode)
     *
     * This prompt is for direct interaction where Claude implements the ticket
     * without spawning subagents.
     *
     * @return string The direct implementation prompt
     */
    public function buildDirectPrompt(): string {
        $issueKey = $this->ticket['key'];
        $summary = $this->ticket['summary'];
        $description = $this->ticket['description'] ?? '';
        $comments = $this->ticket['comments'] ?? '';
        $attachments = $this->ticket['attachments'] ?? '';
        $linkedIssues = $this->ticket['linkedIssues'] ?? '';
        $issueType = $this->ticket['issueType'] ?? 'Task';
        $priority = $this->ticket['priority'] ?? 'Medium';
        $status = $this->ticket['status'] ?? 'Unknown';
        $ticketUrl = $this->ticket['ticketUrl'] ?? '';
        $repoPath = $this->repo['path'];
        $defaultBranch = $this->repo['default_branch'] ?? 'main';

        // Build ticket link
        $ticketLink = $ticketUrl ? "- Ticket URL: {$ticketUrl}" : '';

        // Build comments section
        $commentsSection = '';
        if (!empty($comments)) {
            $commentsSection = "### Comments/Clarifications\n{$comments}\n";
        }

        // Build URL check section
        $urlSection = '';
        if (!empty($this->urls)) {
            $urlList = implode("\n", array_map(fn($u) => "- {$u}", $this->urls));
            $urlSection = <<<URLS

## URLs to Check
The following URLs were mentioned in the ticket. Check these to understand the current state:
{$urlList}

Use web fetch or browser tools to visit these URLs and analyze what you see.
URLS;
        }

        // Build Shopify section
        $shopifySection = '';
        if (!empty($this->shopify['enabled'])) {
            $domain = $this->shopify['domain'] ?? '';
            $shopifySection = <<<SHOPIFY

## Shopify Theme Development

This repository is connected to a Shopify store. You can push changes and preview them.

**Store**: {$domain}

### Environment Variables
- **SHOPIFY_CLI_THEME_TOKEN**: Access token for Shopify CLI
- **SHOPIFY_FLAG_STORE**: Store domain ({$domain})
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
`https://{$domain}/?preview_theme_id=<THEME_ID>`

### Verifying Changes
1. Push the theme with `shopify theme push --development`
2. Note the theme ID from the output
3. Visit the preview URL to verify your changes
4. If the store is password-protected, use the storefront password

SHOPIFY;
        }

        // Build branch instruction
        $branchInstruction = '';
        if ($this->existingBranch) {
            $branchInstruction = <<<BRANCH
5. **Checkout existing branch**: A branch already exists for this ticket. Checkout and pull the latest:
   ```bash
   git -C {$repoPath} fetch origin
   git -C {$repoPath} checkout {$this->existingBranch}
   git -C {$repoPath} pull origin {$this->existingBranch}
   ```
   Continue the work from where the previous run left off. Do NOT create a new branch.
BRANCH;
        } else {
            $branchInstruction = "5. **Create a feature branch**: Use a descriptive name like `fix/{$issueKey}-description`.";
        }

        return <<<PROMPT
You are an AI Developer implementing a Jira ticket. You have full access to:
- Git and GitHub (clone, branch, commit, push, create PR)
- Browser/web tools (to check URLs and verify your work)
- Jira MCP tools (to post comments, upload screenshots, transition status)
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

{$commentsSection}
{$attachments}
{$linkedIssues}
{$urlSection}
{$shopifySection}

## Repository
- Owner: {$this->repo['owner']}
- Repo: {$this->repo['name']}
- Default Branch: {$defaultBranch}
- Clone URL: {$this->repo['clone_url']}

## Environment Variables Available
The following environment variables are set and ready to use:

- **GITHUB_TOKEN** / **GH_TOKEN**: GitHub access token for git operations
  ```bash
  git clone https://\$GITHUB_TOKEN@github.com/{$this->repo['owner']}/{$this->repo['name']}.git repo
  ```

## Jira MCP Tools

You have access to Jira tools via MCP. **ALWAYS use these tools for Jira operations:**

- `jira_comment(issue_key, message)` - Post a comment to the ticket
- `jira_transition(issue_key, status_name)` - Transition ticket to a new status
- `jira_get_transitions(issue_key)` - Get available status transitions
- `jira_get_issue(issue_key)` - Get issue details including attachments list
- `jira_get_attachment(attachment_id)` - View an image attachment
- `jira_upload_attachment(issue_key, file_path)` - Upload a screenshot or file
- `jira_comment_with_image(issue_key, message, file_path)` - Post comment with inline screenshot

**Examples:**
```
# Post a clarifying question
jira_comment(issue_key="{$issueKey}", message="Could you clarify which element should be modified?")

# Upload a Playwright screenshot
jira_upload_attachment(issue_key="{$issueKey}", file_path=".playwright-mcp/screenshot.png")

# Post completion comment with screenshot
jira_comment_with_image(
  issue_key="{$issueKey}",
  message="Implementation complete. Screenshot attached showing the fix.",
  file_path=".playwright-mcp/verification.png"
)
```

## Your Workflow
1. **Understand & Clarify**: Read the ticket carefully. Check any URLs mentioned to understand the current state.
   - **IMPORTANT**: If requirements are unclear, ambiguous, or missing critical details, STOP and ask clarifying questions.
   - Post questions using `jira_comment(issue_key, message)` before proceeding with implementation.
   - Wait for a response (the user will send it via Jira and you'll receive updates).
   - Example clarifying questions: "Which specific element should be modified?", "Should this apply to all pages or just X?", "What should happen when Y occurs?"
2. **Fetch Attachments**: If there are image attachments, use `jira_get_issue` to list them, then `jira_get_attachment(attachment_id)` to view them.
3. **Repository**: The repo is already cloned to `{$repoPath}` and checked out to `{$defaultBranch}`.
   **IMPORTANT: Do NOT `cd {$repoPath}`** - stay in the current directory and reference files as `{$repoPath}/path/to/file`. Use `git -C {$repoPath} <command>` for git operations.
4. **Analyze the codebase**: Find relevant files for the implementation.
{$branchInstruction}
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
- **No emojis**: Do NOT use emojis in Jira comments or any communication. Keep messages professional and plain text.

## Post Status Updates to Jira

**You MUST post comments to Jira at key milestones** so stakeholders can track progress:

1. **When starting**: Post "AI Developer starting work on this ticket..."
2. **If asking questions**: Post your clarifying questions
3. **When PR is created**: Post the PR URL and summary of changes
4. **If blocked/failed**: Post what went wrong and what's needed

Use the `jira_comment` MCP tool:
```
jira_comment(issue_key="{$issueKey}", message="AI Developer starting work...")
jira_comment(issue_key="{$issueKey}", message="PR created: https://github.com/.../pull/123")
```

For screenshots/verification, use `jira_comment_with_image`:
```
jira_comment_with_image(
  issue_key="{$issueKey}",
  message="Verification screenshot attached",
  file_path=".playwright-mcp/screenshot.png"
)
```

{$this->buildStatusTransitionSection($issueKey)}

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

    /**
     * Parse JSON result from agent output
     *
     * Agents may include markdown or extra text - this extracts the JSON.
     *
     * @param string $output Agent output
     * @return array|null Parsed JSON or null if not found
     */
    public static function parseAgentResult(string $output): ?array {
        // Try to find JSON in the output
        if (preg_match('/\{[\s\S]*\}/m', $output, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json !== null) {
                return $json;
            }
        }

        // Try parsing the whole output
        $json = json_decode(trim($output), true);
        return $json ?: null;
    }
}
