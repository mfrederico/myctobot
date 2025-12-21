# AI Developer Pipeline

This document describes the processing pipeline for the AI Developer feature, which automatically implements Jira tickets using Claude Code CLI.

## Pipeline Overview

```mermaid
flowchart TD
    subgraph Trigger["1. TRIGGER"]
        A1[Jira Webhook<br/>issue_updated]
        A2[Manual UI Trigger<br/>Enterprise.php]
        A3[Script Trigger<br/>trigger-job.php]
    end

    subgraph Dispatch["2. JOB DISPATCH"]
        B1[AIDevJobService<br/>runForIssue]
        B2[Fetch Jira Issue<br/>summary, description, attachments]
        B3[Get Credentials<br/>Jira, GitHub, Anthropic]
        B4[Create Job Record<br/>AIDevStatusService::createJob]
        B5[Select Shard<br/>ShardRouter]
        B6[POST to Shard<br/>/analysis/shardaidev]
    end

    subgraph Shard["3. SHARD PROCESSING"]
        C1[shardaidev<br/>Analysis.php:869]
        C2[Return 202 Accepted<br/>Background processing]
        C3[preprocessAttachmentImages<br/>Download & resize with ImageMagick]
        C4[buildAIDevPrompt<br/>Construct Claude prompt]
        C5[runClaudeAIDev<br/>Execute Claude Code CLI]
    end

    subgraph Claude["4. CLAUDE CODE CLI"]
        D1[Clone Repository]
        D2[Read Jira Ticket]
        D3[View Attachments<br/>Pre-processed images]
        D4[Analyze Codebase]
        D5[Implement Changes]
        D6[Create Branch & PR]
    end

    subgraph PostProcess["5. POST-PROCESSING"]
        E1[pushThemeWithCLI<br/>Shopify theme push]
        E2{Playwright<br/>Verification?}
        E3[runPlaywrightVerification<br/>Visit preview URL]
        E4{Issues<br/>Found?}
        E5[runFixCycle<br/>Fix issues]
        E6[repushTheme<br/>Re-push to Shopify]
        E7[Post Jira Comment<br/>PR link, preview URL]
        E8[Callback to Main Server<br/>/webhook/aidev]
    end

    subgraph Callback["6. CALLBACK HANDLING"]
        F1[Webhook::aidev<br/>Webhook.php:779]
        F2[Update Job Status<br/>AIDevStatusService]
        F3[AIDevJobManager<br/>complete/fail]
        F4[Post Final Jira Comment]
        F5[Update Jira Status<br/>Remove label, transition]
    end

    A1 --> B1
    A2 --> B1
    A3 --> B1
    B1 --> B2 --> B3 --> B4 --> B5 --> B6
    B6 --> C1
    C1 --> C2
    C2 --> C3 --> C4 --> C5
    C5 --> D1 --> D2 --> D3 --> D4 --> D5 --> D6
    D6 --> E1
    E1 --> E2
    E2 -->|Yes| E3
    E2 -->|No| E7
    E3 --> E4
    E4 -->|Yes| E5
    E4 -->|No| E7
    E5 --> E6 --> E3
    E7 --> E8
    E8 --> F1 --> F2 --> F3 --> F4 --> F5
```

## Component Details

### 1. Trigger Layer

| Component | File | Description |
|-----------|------|-------------|
| Jira Webhook | `controls/Webhook.php` | Receives `issue_updated` events when `ai-developer` label is added |
| Manual Trigger | `controls/Enterprise.php` | UI button to manually start AI Dev job |
| Script Trigger | `scripts/trigger-job.php` | CLI script for testing/debugging |

### 2. Job Dispatch Layer

| Component | File | Description |
|-----------|------|-------------|
| AIDevJobService | `services/AIDevJobService.php:24` | Main orchestrator for job dispatch |
| runForIssue() | `services/AIDevJobService.php` | Fetches issue, builds payload, sends to shard |
| AIDevStatusService | `services/AIDevStatusService.php:10` | Manages job status in filesystem |
| ShardRouter | `services/ShardRouter.php` | Selects appropriate shard server |

**Payload sent to shard:**
```php
[
    'anthropic_api_key' => $apiKey,
    'job_id' => $jobId,
    'issue_key' => 'PROJ-123',
    'issue_data' => [
        'summary' => '...',
        'description' => '...',
        'comments' => '...',
        'attachment_info' => '...',
        'urls_to_check' => [...]
    ],
    'repo_config' => [
        'repo_owner' => '...',
        'repo_name' => '...',
        'clone_url' => '...'
    ],
    'jira_host' => '...',
    'jira_oauth_token' => '...',
    'github_token' => '...',
    'callback_url' => '/webhook/aidev',
    'shopify' => [...],
    'existing_branch' => null
]
```

### 3. Shard Processing Layer

| Component | File:Line | Description |
|-----------|-----------|-------------|
| shardaidev() | `controls/Analysis.php:869` | Entry point on shard |
| preprocessAttachmentImages() | `controls/Analysis.php:2009` | Downloads & resizes images with ImageMagick |
| buildAIDevPrompt() | `controls/Analysis.php:955` | Constructs the Claude prompt |
| runClaudeAIDev() | `controls/Analysis.php:1105` | Executes Claude Code CLI via proc_open |

**Image Preprocessing:**
- Downloads images from Jira using OAuth token
- Resizes images >500KB to max 1200px width
- Stores in `/tmp/aidev-job-{id}/attachments/`
- Updates prompt to reference local paths

### 4. Claude Code CLI Layer

Claude Code runs in `/tmp/aidev-job-{id}/repo/` with:
- `--print` for stdout logging
- `--dangerously-skip-permissions` for automation
- `--session-id` (UUID v5 from issue key) for context persistence

**Environment variables:**
```bash
ANTHROPIC_API_KEY=...
JIRA_API_TOKEN=...
GITHUB_TOKEN=...
HOME=/tmp/aidev-job-{id}
```

### 5. Post-Processing Layer

| Component | File:Line | Description |
|-----------|-----------|-------------|
| pushThemeWithCLI() | `controls/Analysis.php:1770` | Pushes Shopify theme via CLI |
| runPlaywrightVerification() | `controls/Analysis.php:1882` | Visual QA verification loop |
| runFixCycle() | `controls/Analysis.php:2115` | Fixes issues found during verification |
| repushThemeForVerification() | `controls/Analysis.php:2231` | Re-pushes theme after fixes |
| postJiraComment() | (inline) | Posts PR/preview links to Jira |
| Callback | `controls/Analysis.php:1341` | POSTs result to main server |

**Playwright Verification Loop:**

When `shopify_verify_playwright` is enabled in enterprise settings:

1. After theme push, Claude visits the preview URL using dev-browser (Playwright)
2. Claude compares what it sees against the original Jira requirements
3. If issues are found, Claude fixes the theme code
4. Theme is re-pushed and verified again
5. Loop continues for up to 3 iterations

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Push Theme     │ --> │  Verify with     │ --> │  Issues Found?  │
│  to Shopify     │     │  Playwright      │     │                 │
└─────────────────┘     └──────────────────┘     └────────┬────────┘
                                                          │
                              ┌───────────────────────────┤
                              │ Yes                       │ No
                              ▼                           ▼
                        ┌─────────────┐           ┌─────────────────┐
                        │ Fix Issues  │           │  Continue to    │
                        │ with Claude │           │  Callback       │
                        └──────┬──────┘           └─────────────────┘
                               │
                               ▼
                        ┌─────────────┐
                        │ Re-push     │ ──────────────┐
                        │ Theme       │               │
                        └─────────────┘               │
                               ▲                      │
                               └──────────────────────┘
                                    (max 3 iterations)
```

**Enabling Playwright Verification:**
```sql
INSERT INTO enterprisesettings (setting_key, setting_value)
VALUES ('shopify_verify_playwright', '1');
```

### 6. Callback Layer

| Component | File:Line | Description |
|-----------|-----------|-------------|
| Webhook::aidev() | `controls/Webhook.php:779` | Receives completion callback |
| AIDevJobManager | `services/AIDevJobManager.php` | Updates job records |
| Jira Integration | (inline) | Posts final comment, removes label |

## Status Flow

```mermaid
stateDiagram-v2
    [*] --> pending: Job Created
    pending --> running: Shard Accepts
    running --> completed: PR Created
    running --> failed: Error
    running --> needs_clarification: Blocked
    needs_clarification --> running: User Responds
    completed --> [*]
    failed --> [*]
```

## File Locations

### Main Server
```
/home/mfrederico/development/myctobot/
├── controls/
│   ├── Webhook.php          # Jira webhooks, callback handler
│   ├── Enterprise.php       # UI triggers
│   └── Analysis.php         # Shared, also used by shard
├── services/
│   ├── AIDevJobService.php  # Job dispatch
│   ├── AIDevStatusService.php # Status management
│   ├── AIDevJobManager.php  # Job completion
│   └── ShardRouter.php      # Shard selection
└── data/aidev/{member_id}/  # Job status files
```

### Shard Server (173.231.12.84)
```
/var/www/html/default/myctobot/
├── controls/
│   └── Analysis.php         # shardaidev(), Claude execution
└── log/
    └── shard-YYYY-MM-DD.log # Shard logs

/tmp/aidev-job-{job_id}/
├── prompt.txt               # Claude prompt
├── session.log              # Real-time output
├── session-info.json        # Job metadata
├── attachments/             # Pre-processed images
│   └── screenshot.png
├── repo/                    # Cloned repository
└── .claude/
    └── plugins -> /home/claudeuser/.claude/plugins
```

## Monitoring

```bash
# Watch shard log
ssh claudeuser@173.231.12.84 "tail -f /var/www/html/default/myctobot/log/shard-$(date +%Y-%m-%d).log"

# Watch specific job
ssh claudeuser@173.231.12.84 "tail -f /tmp/aidev-job-{JOB_ID}/session.log"

# List running jobs
ssh claudeuser@173.231.12.84 "ls -la /tmp/aidev-job-*/"
```

## Key Processing Points

1. **Image Optimization** (`Analysis.php:2009`)
   - Reduces context window usage
   - ImageMagick resize: `convert -resize "1200x>" -quality 85`

2. **Session Persistence** (`Analysis.php:1174`)
   - UUID v5 from issue key ensures same session across reruns
   - Enables "continue from where you left off" behavior

3. **Branch Affinity** (`AIDevJobService.php`)
   - Checks for existing branch for issue
   - Reuses branch instead of creating new one

4. **Shopify Theme Push** (`Analysis.php:1761`)
   - Uses `shopify theme push --unpublished`
   - Creates preview URL for QA
