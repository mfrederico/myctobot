# Claude Execution via AOE+TMUX Flow

This document describes how Claude Code CLI is executed via AOE-PHP and tmux, including remote workstation execution.

## Architecture Overview

MyCTOBot supports two execution modes:

1. **Local Execution** - Claude runs on the same server as MyCTOBot
2. **Remote Execution** - Claude runs on a remote workstation via SSH push + API pull

```
═══════════════════════════════════════════════════════════════════════════════════
HIGH-LEVEL ARCHITECTURE
═══════════════════════════════════════════════════════════════════════════════════

                           ┌─────────────────────────────────────┐
                           │         MYCTOBOT (Control Plane)    │
                           │         {tenant}.myctobot.ai        │
                           ├─────────────────────────────────────┤
                           │                                     │
                           │  Database:                          │
                           │  ├── runners (workstations)         │
                           │  ├── aiagents (agent configs)       │
                           │  ├── aidevjobs (job queue)          │
                           │  └── member credentials             │
                           │                                     │
                           │  APIs:                              │
                           │  ├── /api/runner/* (runner API)     │
                           │  └── /mcp/* (MCP tools)             │
                           │                                     │
                           └──────────────┬──────────────────────┘
                                          │
                    ┌─────────────────────┼─────────────────────┐
                    │ SSH Push            │ SSH Push            │ SSH Push
                    ▼                     ▼                     ▼
         ┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐
         │ RUNNER (local)   │  │ RUNNER (onsite)  │  │ RUNNER (offsite) │
         │ clauderunner1    │  │ clauderunner2    │  │ clauderunner1    │
         │ localhost        │  │ 10.0.0.50        │  │ cloud VM         │
         └──────────────────┘  └──────────────────┘  └──────────────────┘

         Each runner pulls job config via HTTPS from MyCTOBot API
```

---

## PART 1: LOCAL EXECUTION FLOW

For jobs running on the MyCTOBot server itself (no remote workstation assigned).

```
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
                                         │ - Gets runner config  │
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
│  spawnWithScript($scriptPath, ...)                                              │
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
│       "php scripts/local-aidev-full.php --issue=SSI-1234 --member=1 --tenant=gwt"│
└─────────────────────────────────────────────────────────────────────────────────┘


═══════════════════════════════════════════════════════════════════════════════════
FILE MAP (LOCAL): /tmp/aoe-gwt-SSI-1234-ab12cd34/
═══════════════════════════════════════════════════════════════════════════════════

/tmp/aoe-gwt-SSI-1234-ab12cd34/
├── prompt.txt              # Full task prompt with Jira details
├── CLAUDE.md               # Project instructions + context
├── .mcp.json               # MCP server config (Jira tools)
├── run-claude.sh           # Main execution script (sets env, runs claude)
├── finish_job.sh           # Called by Claude to signal completion
├── spawn.log               # PHP setup/spawn output
├── session.log             # Claude session output (via script -c)
├── attachments/            # Downloaded Jira attachments
│   └── screenshot.png
└── repo/                   # Cloned git repository
    ├── .git/
    ├── .mcp.json           # Copied from parent
    ├── .claude/
    │   └── settings.json   # Claude settings (allowed tools)
    ├── .gitignore          # Updated with MyCTOBot patterns
    └── <project files>
```

---

## PART 2: REMOTE EXECUTION FLOW (SSH Push + API Pull)

For jobs running on remote workstations. This is the preferred architecture for:
- Isolating Claude execution from the control plane
- Scaling with multiple runners
- Running on dedicated hardware with GPU/resources

### Security Model

- **User Isolation**: Each runner is a separate Linux user (chmod 700 home directories)
- **SSH Push**: MyCTOBot initiates jobs via SSH (no polling, always fresh bootstrap)
- **API Pull**: Runner fetches all config/files via authenticated HTTPS
- **No Persistent State**: Runner is stateless, everything fetched per-job

```
═══════════════════════════════════════════════════════════════════════════════════
REMOTE EXECUTION ARCHITECTURE
═══════════════════════════════════════════════════════════════════════════════════

  MYCTOBOT                                    WORKSTATION
  ────────                                    ───────────

  1. SSH triggers bootstrap (always fresh, no polling)
     ┌─────────────────────────────────────────────────────────────────────┐
     │ ssh clauderunner1@workstation \                                     │
     │   "curl -sfL https://myctobot.ai/api/runner/boot | \               │
     │    bash -s -- --tenant=gwt --job=abc123 --token=XXXXX"             │
     └─────────────────────────────────────────────────────────────────────┘
                         │
                         │ (that's ALL that goes over SSH)
                         ▼

  2. Bootstrap creates job directory and fetches runner
     ┌─────────────────────────────────────────────────────────────────────┐
     │ #!/bin/bash                                                         │
     │ # boot.sh (fetched fresh every time via curl)                      │
     │                                                                     │
     │ TENANT=$1; JOB=$2; TOKEN=$3                                        │
     │ API="https://${TENANT}.myctobot.ai/api/runner"                     │
     │ WORK="$HOME/jobs/${TENANT}/${JOB}"                                 │
     │                                                                     │
     │ mkdir -p "$WORK" && cd "$WORK"                                     │
     │                                                                     │
     │ # Fetch job manifest                                                │
     │ curl -sf -H "X-Job-Token: ${TOKEN}" \                              │
     │   "${API}/jobs/${JOB}/manifest" -o manifest.json                   │
     │                                                                     │
     │ # Fetch runner script                                               │
     │ curl -sf -H "X-Job-Token: ${TOKEN}" \                              │
     │   "${API}/jobs/${JOB}/runner" -o runner.sh                         │
     │ chmod +x runner.sh                                                  │
     │                                                                     │
     │ # Hand off to runner                                                │
     │ exec ./runner.sh                                                    │
     └─────────────────────────────────────────────────────────────────────┘
                         │
                         ▼

  3. Runner fetches all job files and plugins
     ┌─────────────────────────────────────────────────────────────────────┐
     │ #!/bin/bash                                                         │
     │ # runner.sh (fetched fresh every time)                             │
     │                                                                     │
     │ # Parse manifest                                                    │
     │ REPO_URL=$(jq -r '.repo.url' manifest.json)                        │
     │ BRANCH=$(jq -r '.repo.branch' manifest.json)                       │
     │ PLUGINS=$(jq -r '.plugins[]' manifest.json)                        │
     │                                                                     │
     │ # Fetch workspace files                                             │
     │ curl ... "${API}/jobs/${JOB}/files/prompt.txt" -o prompt.txt       │
     │ curl ... "${API}/jobs/${JOB}/files/claude.md" -o CLAUDE.md         │
     │ curl ... "${API}/jobs/${JOB}/files/mcp.json" -o .mcp.json          │
     │ curl ... "${API}/jobs/${JOB}/files/env.sh" -o env.sh               │
     │                                                                     │
     │ # Fetch and setup plugins                                           │
     │ for plugin in $PLUGINS; do                                         │
     │   mkdir -p "plugins/${plugin}"                                     │
     │   curl ... "${API}/plugins/${plugin}" | tar -xz -C "plugins/${plugin}"│
     │   [[ -x "plugins/${plugin}/pre_run.sh" ]] && \                     │
     │     source "plugins/${plugin}/pre_run.sh"                          │
     │ done                                                                │
     │                                                                     │
     │ # Clone repository                                                  │
     │ source env.sh  # Sets GITHUB_TOKEN, etc.                           │
     │ git clone --depth 1 -b "$BRANCH" "$REPO_URL" repo/                 │
     │                                                                     │
     │ # Run Claude                                                        │
     │ cd repo/                                                            │
     │ claude --dangerously-skip-permissions "$(cat ../prompt.txt)"       │
     │                                                                     │
     │ # Post-run hooks                                                    │
     │ for plugin in $PLUGINS; do                                         │
     │   [[ -x "../plugins/${plugin}/post_run.sh" ]] && \                 │
     │     source "../plugins/${plugin}/post_run.sh"                      │
     │ done                                                                │
     │                                                                     │
     │ # Callback to MyCTOBot                                              │
     │ curl -X POST "${API}/jobs/${JOB}/complete" \                       │
     │   -H "X-Job-Token: ${TOKEN}" \                                     │
     │   -d '{"status":"complete"}'                                       │
     │                                                                     │
     │ # Cleanup                                                           │
     │ cd ~ && rm -rf "$WORK"                                             │
     └─────────────────────────────────────────────────────────────────────┘


═══════════════════════════════════════════════════════════════════════════════════
WORKSTATION DIRECTORY STRUCTURE (per user)
═══════════════════════════════════════════════════════════════════════════════════

/home/clauderunner1/                    # chmod 700 - fully isolated
├── .ssh/
│   └── authorized_keys                 # MyCTOBot's SSH public key
├── .claude/                            # Claude CLI config for this runner
│   └── settings.json
├── .gitconfig                          # Git config for this runner
│
├── jobs/                               # Active job workspaces
│   └── {tenant}/
│       └── {job-uuid}/                 # e.g., gwt/abc123-def456/
│           ├── manifest.json           # Job configuration (from API)
│           ├── runner.sh               # Runner script (from API)
│           ├── prompt.txt              # Task prompt (from API)
│           ├── CLAUDE.md               # Project context (from API)
│           ├── .mcp.json               # MCP config (from API)
│           ├── env.sh                  # Environment variables (from API)
│           ├── plugins/
│           │   ├── stripe/
│           │   │   ├── pre_run.sh
│           │   │   └── post_run.sh
│           │   └── mcp-jira/
│           ├── repo/                   # Git clone (done on runner)
│           │   └── <project files>
│           └── logs/
│               └── claude.log
│
└── cache/                              # Optional: cached plugin binaries
    └── plugins/
        └── stripe-v1.19.0/


═══════════════════════════════════════════════════════════════════════════════════
MULTIPLE RUNNERS = MULTIPLE LINUX USERS (security isolation)
═══════════════════════════════════════════════════════════════════════════════════

/home/clauderunner1/    # chmod 700 - Runner 1 (can't see runner 2's files)
/home/clauderunner2/    # chmod 700 - Runner 2 (can't see runner 1's files)
/home/clauderunner3/    # chmod 700 - Runner 3 (dedicated to specific tenant)

Benefits:
- Complete file isolation between runners
- Each runner can have different credentials
- Compromise of one runner doesn't affect others
- Can scale by adding more users on same host
```

---

## MyCTOBot API Endpoints (Runner API)

```
═══════════════════════════════════════════════════════════════════════════════════
RUNNER API: /api/runner/*
═══════════════════════════════════════════════════════════════════════════════════

# Bootstrap (no auth - minimal script)
GET  /api/runner/boot
     Returns: boot.sh script content

# Job Files (requires X-Job-Token header)
GET  /api/runner/jobs/{job_id}/manifest
     Returns: JSON job configuration

GET  /api/runner/jobs/{job_id}/runner
     Returns: runner.sh script content

GET  /api/runner/jobs/{job_id}/files/{filename}
     Returns: prompt.txt, claude.md, mcp.json, env.sh

# Plugins (requires X-Job-Token header)
GET  /api/runner/plugins/{plugin_name}
     Returns: plugin archive (tar.gz)

# Callbacks (requires X-Job-Token header)
POST /api/runner/jobs/{job_id}/status
     Body: {"status": "running", "phase": "cloning"}

POST /api/runner/jobs/{job_id}/log
     Body: {"lines": ["...", "..."]}

POST /api/runner/jobs/{job_id}/complete
     Body: {"status": "success", "summary": "..."}
```

---

## Database Tables

### runners (formerly claudeshards)
Remote workstation/runner configuration:
- `id` - Primary key
- `name` - Display name
- `host` - SSH host
- `user` - SSH username
- `port` - SSH port (default 22)
- `is_active` - Whether runner is available
- `is_local` - True if runner is localhost
- `max_concurrent` - Max simultaneous jobs
- `capabilities` - JSON array of capabilities (e.g., ["claude", "nodejs"])
- `runner_token` - API authentication token
- `last_heartbeat` - Last seen timestamp
- `tenant_id` - Restrict to specific tenant (null = any)

### aiagents
Agent configuration:
- `id` - Primary key
- `name` - Agent display name (used in MCP signatures)
- `runners_id` - Assigned runner (nullable for local execution)
- `plugins` - JSON array of required plugins
- `hooks_config` - JSON pre/post hooks

### aidevjobs
Job queue and history:
- `id` - Primary key
- `issue_key` - Jira/GitHub issue reference
- `status` - queued, running, completed, failed
- `runner_id` - Which runner is executing (null = local)
- `job_token` - Per-job API token (expires after job)
- `member_id` - Owner
- `created_at`, `updated_at` - Timestamps

---

## AOE-PHP Session Storage

```
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

Note: AOE storage is synced with tmux (source of truth).
      loadAllSynced() queries tmux first, then updates storage.
```

---

## Key Commands

```bash
# List active sessions (queries tmux first)
php ../aoe-php/bin/aoe --tenant=gwt sessions

# Attach to local session
tmux attach -t aoe-gwt-SSI-1234-ab12cd34

# Watch session log (local)
tail -f /tmp/aoe-gwt-SSI-1234-ab12cd34/session.log

# Kill local session
tmux kill-session -t aoe-gwt-SSI-1234-ab12cd34

# SSH to remote runner to debug
ssh clauderunner1@workstation
ls ~/jobs/gwt/          # See active jobs
tail -f ~/jobs/gwt/abc123/logs/claude.log
```

---

## Plugin System

Plugins are fetched per-job and provide extensible tooling:

```
plugins/
├── stripe/
│   ├── plugin.json         # Plugin manifest
│   ├── pre_run.sh          # Setup (install CLI, login, etc.)
│   ├── post_run.sh         # Cleanup (logout, remove creds)
│   └── bin/                # Optional binaries
│       └── stripe-mcp
├── shopify/
│   └── ...
├── nodejs/
│   └── ...
└── template/               # Example for creating new plugins
    └── ...

plugin.json example:
{
  "name": "stripe",
  "version": "1.0.0",
  "description": "Stripe CLI integration",
  "env_required": ["STRIPE_API_KEY"],
  "files": ["pre_run.sh", "post_run.sh"]
}
```
