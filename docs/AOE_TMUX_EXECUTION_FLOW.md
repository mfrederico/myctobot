# Claude Execution via AOE+TMUX Flow

This document describes how Claude Code CLI is executed via AOE-PHP and tmux, including remote workstation execution via SSH.

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                    CLAUDE EXECUTION VIA AOE+TMUX FLOW                           │
│                    (Remote Workstation: claudeuser@1.1.1.1)                      │
└─────────────────────────────────────────────────────────────────────────────────┘

═══════════════════════════════════════════════════════════════════════════════════
PHASE 1: JOB TRIGGER
═══════════════════════════════════════════════════════════════════════════════════

┌──────────────┐     Jira Webhook        ┌───────────────────────┐
│    JIRA      │ ───────────────────────▶│  controls/Webhook.php │
│  (ai-dev     │   POST /webhook/jira    │                       │
│   label)     │                         │  processJiraIssue()   │
└──────────────┘                         └───────────┬───────────┘
                                                     │
                                                     ▼
                                         ┌───────────────────────┐
                                         │ AIDevJobService.php   │
                                         │                       │
                                         │ - Creates aidevjobs   │
                                         │   record (status:     │
                                         │   'queued')           │
                                         │ - Gets agent config   │
                                         │ - Gets workstation    │
                                         └───────────┬───────────┘
                                                     │
═══════════════════════════════════════════════════════════════════════════════════
PHASE 2: SESSION CREATION
═══════════════════════════════════════════════════════════════════════════════════
                                                     │
                                                     ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                        services/TmuxService.php                                  │
├─────────────────────────────────────────────────────────────────────────────────┤
│                                                                                  │
│  __construct($memberId, $issueKey, $repoPath, $tenant)                          │
│     │                                                                            │
│     ├── AoeStorage::loadAllSynced()  ◄── Queries tmux first (source of truth)   │
│     │       │                                                                    │
│     │       └── Finds or creates AOE Session Instance                            │
│     │                                                                            │
│     └── Sets: $sessionName = "aoe-gwt-SSI-1234-ab12cd34"                        │
│               $workDir = "/tmp/aoe-gwt-SSI-1234-ab12cd34"                        │
│                                                                                  │
│  spawnWithScript($scriptPath, ..., $workstation)                                │
│     │                                                                            │
│     ├── mkdir($workDir)                                                          │
│     ├── AoeStorage::save($aoeSession)                                            │
│     └── TmuxManager::create($sessionName, $command)                              │
│                                                                                  │
└─────────────────────────────────────────────────────────────────────────────────┘
                                                     │
                                                     ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                          lib/TmuxManager.php                                     │
├─────────────────────────────────────────────────────────────────────────────────┤
│  tmux new-session -d -s "aoe-gwt-SSI-1234-ab12cd34" \                            │
│       -c "/tmp/aoe-gwt-SSI-1234-ab12cd34" \                                      │
│       "php scripts/local-aidev-full.php --issue=SSI-1234 --member=1 \            │
│        --tenant=gwt --workstation='{\"host\":\"1.1.1.1\",\"user\":\"claudeuser\"}'"│
└─────────────────────────────────────────────────────────────────────────────────┘
                                                     │
═══════════════════════════════════════════════════════════════════════════════════
PHASE 3: ENVIRONMENT SETUP (inside tmux)
═══════════════════════════════════════════════════════════════════════════════════
                                                     │
                                                     ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                     scripts/local-aidev-full.php                                 │
├─────────────────────────────────────────────────────────────────────────────────┤
│                                                                                  │
│  1. Parse CLI args (--issue, --tenant, --workstation, etc.)                     │
│                                                                                  │
│  2. Load/Create AOE Session                                                      │
│     - $aoeSession = AoeInstance::create(tenantId, title, projectPath, tool)     │
│     - $sessionName = $aoeSession->getTmuxName()                                  │
│     - $workDir = "/tmp/{$sessionName}"                                           │
│                                                                                  │
│  3. Create directory structure:                                                  │
│     mkdir -p /tmp/aoe-gwt-SSI-1234-ab12cd34/                                    │
│     mkdir -p /tmp/aoe-gwt-SSI-1234-ab12cd34/attachments/                        │
│                                                                                  │
│  4. Fetch Jira issue details (JiraClient)                                       │
│     - Summary, description, comments, attachments                                │
│     - Download attachments to attachments/                                       │
│                                                                                  │
│  5. Clone repository                                                             │
│     git clone <repo_url> /tmp/aoe-gwt-SSI-1234-ab12cd34/repo                    │
│                                                                                  │
│  6. Generate files (see FILE MAP below)                                          │
│                                                                                  │
│  7. Create run-claude.sh with SSH wrapper                                        │
│                                                                                  │
│  8. Create tmux session and send commands                                        │
│     - aoeTmux->createSessionWithName()                                           │
│     - aoeTmux->sendTextByName($sessionName, $envScriptPath)                     │
│                                                                                  │
└─────────────────────────────────────────────────────────────────────────────────┘
                                                     │
═══════════════════════════════════════════════════════════════════════════════════
FILE MAP: /tmp/aoe-gwt-SSI-1234-ab12cd34/
═══════════════════════════════════════════════════════════════════════════════════

/tmp/aoe-gwt-SSI-1234-ab12cd34/
├── prompt.txt              # Full task prompt with Jira details
├── CLAUDE.md               # Project instructions + context
├── .mcp.json               # MCP server config (Jira tools)
├── run-claude.sh           # Main execution script (sets env, runs claude)
├── ssh-claude.sh           # SSH wrapper for remote execution
├── finish_job.sh           # Called by Claude to signal completion
├── spawn.log               # PHP setup/spawn output
├── session.log             # Claude session output (via script -c)
├── output.log              # Captured output
├── attachments/            # Downloaded Jira attachments
│   └── screenshot.png
└── repo/                   # Cloned git repository
    ├── .git/
    ├── .mcp.json           # Copied from parent
    ├── .claude/
    │   └── settings.json   # Claude settings (allowed tools)
    ├── finish_job.sh       # Copied from parent
    ├── .gitignore          # Updated with MyCTOBot patterns
    └── <project files>

═══════════════════════════════════════════════════════════════════════════════════
PHASE 4: CLAUDE EXECUTION (SSH to Remote Workstation)
═══════════════════════════════════════════════════════════════════════════════════

┌─────────────────────────────────────────────────────────────────────────────────┐
│                         run-claude.sh (executed in tmux)                         │
├─────────────────────────────────────────────────────────────────────────────────┤
│                                                                                  │
│  #!/bin/bash                                                                     │
│  export GITHUB_TOKEN="..."                                                       │
│  export JIRA_BASE_URL="..."                                                      │
│  export MCP_HTTP_URL="..."                                                       │
│  export AIDEV_STATUS_WORKING="In Progress"                                       │
│  export AIDEV_STATUS_COMPLETE="Ready for QA"                                     │
│  ...                                                                             │
│                                                                                  │
│  cd /tmp/aoe-gwt-SSI-1234-ab12cd34/repo                                         │
│                                                                                  │
│  # Execute ssh-claude.sh (which runs Claude on remote)                           │
│  script -c "./ssh-claude.sh" ../session.log                                      │
│                                                                                  │
└─────────────────────────────────────────────────────────────────────────────────┘
                                                     │
                                                     ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                              ssh-claude.sh                                       │
├─────────────────────────────────────────────────────────────────────────────────┤
│                                                                                  │
│  #!/bin/bash                                                                     │
│  cd /tmp/aoe-gwt-SSI-1234-ab12cd34                                              │
│  ssh -t -p 22 claudeuser@1.1.1.1 \                                              │
│      "export PATH=\$HOME/.local/bin:\$HOME/.claude/bin:/usr/local/bin:\$PATH && \│
│       cd /tmp/aoe-gwt-SSI-1234-ab12cd34 && \                                    │
│       claude --dangerously-skip-permissions \"\$(cat prompt.txt)\""              │
│                                                                                  │
└─────────────────────────────────────────────────────────────────────────────────┘
                                                     │
                                                     │ SSH connection
                                                     ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                    REMOTE WORKSTATION (claudeuser@1.1.1.1)                       │
├─────────────────────────────────────────────────────────────────────────────────┤
│                                                                                  │
│  REQUIREMENT: Work directory must be accessible on remote host                   │
│               (NFS mount, rsync, or shared filesystem)                           │
│                                                                                  │
│  /tmp/aoe-gwt-SSI-1234-ab12cd34/   ◄── Same path as myctobot server             │
│  │                                                                               │
│  └── Claude Code CLI runs here                                                   │
│      - Reads prompt.txt                                                          │
│      - Reads CLAUDE.md for project context                                       │
│      - Uses .mcp.json for MCP tools (Jira comments)                             │
│      - Makes changes to repo/                                                    │
│      - Commits and pushes to git                                                 │
│                                                                                  │
│  MCP Server Connection:                                                          │
│  ┌─────────────────────────────────────────────────────────────────────────┐    │
│  │  .mcp.json:                                                              │    │
│  │  {                                                                       │    │
│  │    "mcpServers": {                                                       │    │
│  │      "jira": {                                                           │    │
│  │        "type": "http",                                                   │    │
│  │        "url": "https://myctobot.ai/mcp/jira/gwt",                       │    │
│  │        "headers": {                                                      │    │
│  │          "Authorization": "Basic <base64>",                              │    │
│  │          "X-MCP-Agent-Name": "Agent Smith"  ◄── For comment attribution │    │
│  │        }                                                                 │    │
│  │      }                                                                   │    │
│  │    }                                                                     │    │
│  │  }                                                                       │    │
│  └─────────────────────────────────────────────────────────────────────────┘    │
│                                                                                  │
└─────────────────────────────────────────────────────────────────────────────────┘
                                                     │
                                                     │ MCP HTTP calls
                                                     ▼
                                         ┌───────────────────────┐
                                         │ controls/Mcp.php      │
                                         │                       │
                                         │ - toolJiraComment()   │
                                         │ - toolJiraTransition()│
                                         │ - Appends [agent:X]   │
                                         │   signature           │
                                         └───────────────────────┘

═══════════════════════════════════════════════════════════════════════════════════
PHASE 5: POST-SESSION CLEANUP
═══════════════════════════════════════════════════════════════════════════════════

┌─────────────────────────────────────────────────────────────────────────────────┐
│                    run-claude.sh (after Claude exits)                            │
├─────────────────────────────────────────────────────────────────────────────────┤
│                                                                                  │
│  echo "=== Claude Session Ended ==="                                             │
│                                                                                  │
│  # update_issue_tracker() function:                                              │
│  # - Posts summary comment to Jira                                               │
│  # - Transitions ticket to configured status                                     │
│                                                                                  │
│  # cleanup_labels() function:                                                    │
│  # - Removes ai-dev label                                                        │
│  # - Removes myctobot-working label                                              │
│                                                                                  │
│  # aoe_session_cleanup() function:                                               │
│  # - Updates aidevjobs status to 'completed'                                     │
│  # - Updates AOE session status                                                  │
│  # - Notifies queue processor                                                    │
│                                                                                  │
│  echo "=== Final Cleanup ==="                                                    │
│  # Session ends immediately (no more 2-hour wait)                                │
│                                                                                  │
└─────────────────────────────────────────────────────────────────────────────────┘

═══════════════════════════════════════════════════════════════════════════════════
AOE-PHP STORAGE
═══════════════════════════════════════════════════════════════════════════════════

/tmp/.aoe-php/
└── tenants/
    └── gwt/
        └── sessions.json     # Tracks all sessions for tenant
            {
              "version": 1,
              "sessions": [
                {
                  "id": "ab12cd3400000000",
                  "tenant_id": "gwt",
                  "title": "SSI-1234",
                  "project_path": "/tmp/aoe-gwt-SSI-1234-ab12cd34",
                  "reference": "SSI-1234",
                  "status": "idle",
                  "tool": "claude"
                }
              ]
            }

═══════════════════════════════════════════════════════════════════════════════════
KEY COMMANDS
═══════════════════════════════════════════════════════════════════════════════════

# List active sessions (queries tmux first)
php ../aoe-php/bin/aoe --tenant=gwt sessions

# Attach to session
tmux attach -t aoe-gwt-SSI-1234-ab12cd34

# Watch session log
tail -f /tmp/aoe-gwt-SSI-1234-ab12cd34/session.log

# Kill session
tmux kill-session -t aoe-gwt-SSI-1234-ab12cd34
```

## Database Tables

### aidevjobs
Tracks job status and history:
- `id` - Primary key
- `issue_key` - Jira/GitHub issue reference
- `status` - queued, running, completed, failed
- `member_id` - Owner
- `created_at`, `updated_at` - Timestamps

### aiagents
Agent configuration:
- `id` - Primary key
- `name` - Agent display name (used in MCP signatures)
- `claudeshards_id` - Assigned workstation (nullable for local execution)

### claudeshards
Workstation configuration:
- `id` - Primary key
- `host` - SSH host
- `user` - SSH user
- `port` - SSH port (default 22)
