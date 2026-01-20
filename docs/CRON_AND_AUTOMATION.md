# MyCTOBot Cron Jobs & Automation Scripts

This document covers all background processes, cron jobs, and automation scripts in the MyCTOBot system.

---

## Table of Contents

1. [Quick Reference](#quick-reference)
2. [CEO Directive System](#ceo-directive-system)
3. [AI Developer Scripts](#ai-developer-scripts)
4. [Analysis & Digest Scripts](#analysis--digest-scripts)
5. [MCP Servers](#mcp-servers)
6. [Utility Scripts](#utility-scripts)
7. [Crontab Setup](#crontab-setup)

---

## Quick Reference

| Script | Purpose | Frequency |
|--------|---------|-----------|
| `cron-directive-processor.php` | Process CEO directives | Every 5 min |
| `story-build-orchestrator.php` | Run AI dev agents for stories | Continuous/manual |
| `cron-digest.php` | Send scheduled board digests | Every 15 min |
| `cron-analysis.php` | Run board analysis | On-demand |
| `local-aidev-full.php` | Local AI dev runner | Manual |
| `ai-dev-agent.php` | Shard AI dev runner | Called by shards |

---

## CEO Directive System

### cron-directive-processor.php

Processes incoming CEO directives through the autonomous CTO system. Handles the full pipeline: directive parsing → CTO agent → PM agent → story creation.

```bash
# Basic usage
php scripts/cron-directive-processor.php --workspace=gwt

# With verbose output
php scripts/cron-directive-processor.php --workspace=gwt --verbose

# Dry run (see what would be processed)
php scripts/cron-directive-processor.php --workspace=gwt --dry-run
```

**Options:**
| Option | Required | Description |
|--------|----------|-------------|
| `--workspace=<name>` | Yes | Workspace slug (e.g., `gwt`, `footest4`) |
| `--verbose` | No | Show detailed processing output |
| `--dry-run` | No | Check without actually processing |

**Crontab:**
```bash
*/5 * * * * php /path/to/scripts/cron-directive-processor.php --workspace=gwt >> /path/to/log/directive-processor.log 2>&1
```

---

### story-build-orchestrator.php

Processes the `ctostories` queue, spawning AI dev agents for each story. Supports both Jira and GitHub issue sources. Can run multiple builds in parallel.

```bash
# Basic usage (processes stories until queue is empty)
php scripts/story-build-orchestrator.php --workspace=footest4

# With verbose output
php scripts/story-build-orchestrator.php --workspace=footest4 --verbose

# Run 2 parallel builds
php scripts/story-build-orchestrator.php --workspace=gwt --max-concurrent=2

# Process one batch and exit (for cron)
php scripts/story-build-orchestrator.php --workspace=gwt --once

# Dry run
php scripts/story-build-orchestrator.php --workspace=footest4 --dry-run
```

**Options:**
| Option | Required | Description |
|--------|----------|-------------|
| `--workspace=<name>` | Yes | Workspace slug |
| `--member=<id>` | No | Member ID to run as (default: first admin) |
| `--max-concurrent=<n>` | No | Max parallel builds (default: 1) |
| `--once` | No | Process one batch and exit |
| `--dry-run` | No | Show what would be processed |
| `--verbose` | No | Show detailed output |

**How it works:**
1. Queries `ctostories` table for stories with status `ready`
2. Spawns tmux sessions running `local-aidev-full.php` for each story
3. Monitors sessions for completion
4. Updates story status and rolls up to epic/project completion
5. Handles GitHub/Jira label updates

**Monitoring active sessions:**
```bash
# List all story/aidev sessions
tmux list-sessions | grep -E "(story-|aidev-)"

# Attach to a session
tmux attach -t aidev-footest4-2-mfrederico-myctobot-24

# View session output without attaching
tmux capture-pane -t <session-name> -p | tail -50
```

---

## AI Developer Scripts

### local-aidev-full.php

Runs AI Developer using your local Claude Code subscription (not API credits). Creates a tmux session that you can monitor.

```bash
# Basic usage with Jira issue
php scripts/local-aidev-full.php --issue=SSI-1883 --workspace=gwt

# With GitHub issue (owner/repo#number format)
php scripts/local-aidev-full.php --issue=mfrederico/myctobot#26 --workspace=footest4 --provider=github

# Attach to tmux session immediately
php scripts/local-aidev-full.php --issue=SSI-1883 --attach

# Use print mode (non-interactive, for automation)
php scripts/local-aidev-full.php --issue=SSI-1883 --print

# Specify member and repo
php scripts/local-aidev-full.php --issue=SSI-1883 --member=3 --repo=5 --workspace=gwt

# Dry run (test without spawning Claude)
php scripts/local-aidev-full.php --issue=SSI-1883 --dry-run
```

**Options:**
| Option | Required | Description |
|--------|----------|-------------|
| `--issue=<key>` | Yes | Issue key (e.g., `SSI-1883` or `owner/repo#123`) |
| `--workspace=<name>` | No | Workspace slug |
| `--member=<id>` | No | Member ID (default: 3) |
| `--provider=<type>` | No | `jira` or `github` (default: jira) |
| `--repo=<id>` | No | Repository connection ID |
| `--job-id=<id>` | No | Job ID for tracking (auto-generated) |
| `--attach` | No | Attach to tmux session immediately |
| `--print` | No | Use non-interactive print mode |
| `--repo-path=<path>` | No | Path to existing repo clone |
| `--dry-run` | No | Test without spawning Claude |
| `--skip-jira` | No | Skip Jira API calls (testing only) |

**Work directory structure:**
```
/tmp/local-aidev-{workspace}-{member}-{issueKey}/
├── repo/           # Cloned repository
├── prompt.txt      # Generated prompt for Claude
├── run-claude.sh   # Execution script
├── session.log     # Claude output log
└── result.json     # Structured result (if successful)
```

---

### ai-dev-agent.php

Shard-side AI Developer runner. Called by remote shards to process jobs using API credits.

```bash
# Process a new job
php scripts/ai-dev-agent.php --secret=KEY --member=3 --job=JOB_ID \
    --issue=SSI-1883 --cloud=CLOUD_ID --repo=5 --action=process --workspace=gwt

# Resume a job after clarification
php scripts/ai-dev-agent.php --secret=KEY --member=3 --job=JOB_ID \
    --action=resume --comment=COMMENT_ID --workspace=gwt
```

**Note:** This script is typically not run manually. It's invoked by the shard infrastructure when jobs are dispatched.

---

## Analysis & Digest Scripts

### cron-analysis.php

Runs board analysis (sprint analysis, velocity, trends, etc.).

```bash
# Run analysis for a specific board
php scripts/cron-analysis.php --script --secret=KEY --member=3 --board=1 --workspace=gwt

# With verbose output
php scripts/cron-analysis.php --script --secret=KEY --member=3 --board=1 --verbose

# Run and send email digest
php scripts/cron-analysis.php --script --secret=KEY --member=3 --board=1 --email

# With job ID for progress tracking
php scripts/cron-analysis.php --script --secret=KEY --member=3 --board=1 --job=abc123
```

**Options:**
| Option | Required | Description |
|--------|----------|-------------|
| `--script` | Yes | Required for CLI execution |
| `--secret=<key>` | Yes | Auth key (from `cron.api_key` in config) |
| `--member=<id>` | Yes | Member ID |
| `--board=<id>` | Yes | Board ID to analyze |
| `--workspace=<name>` | No | Workspace slug |
| `--job=<id>` | No | Job ID for progress tracking |
| `--status-filter=<list>` | No | Comma-separated status list |
| `--email` | No | Send digest email after analysis |
| `--verbose` | No | Show detailed output |

---

### cron-digest.php

Processes scheduled digests for all workspaces/boards based on configured times.

```bash
# Process all workspaces
php scripts/cron-digest.php --script --verbose

# Process specific workspace
php scripts/cron-digest.php --script --workspace=gwt --verbose

# Dry run (see what would be sent)
php scripts/cron-digest.php --script --dry-run

# Force send (ignore time windows)
php scripts/cron-digest.php --script --force
```

**Options:**
| Option | Required | Description |
|--------|----------|-------------|
| `--script` | Yes | Required for CLI execution |
| `--workspace=<name>` | No | Process specific workspace only |
| `--verbose` | No | Show detailed output |
| `--dry-run` | No | Check without sending |
| `--force` | No | Send now, ignore time windows |

**Crontab:**
```bash
0,15,30,45 * * * * php /path/to/scripts/cron-digest.php --script >> /path/to/log/digest.log 2>&1
```

---

## MCP Servers

MCP (Model Context Protocol) servers provide tool interfaces for AI agents.

### mcp-jira-server.php

Provides Jira API access to Claude agents.

```bash
php scripts/mcp-jira-server.php
```

### mcp-github-server.php

Provides GitHub API access to Claude agents.

```bash
php scripts/mcp-github-server.php
```

### mcp-ollama-server.php

Provides local Ollama LLM access to Claude agents.

```bash
php scripts/mcp-ollama-server.php
```

### mcp-agent-server.php

Provides custom agent tools defined in the database.

```bash
php scripts/mcp-agent-server.php
```

**Note:** MCP servers are typically started automatically by Claude Code based on `claude_desktop_config.json` or project settings.

---

## Utility Scripts

### sync-to-shards.sh

Syncs code to remote shard servers.

```bash
./scripts/sync-to-shards.sh
```

### monitor-job.sh

Monitors a running AI dev job on a shard.

```bash
./scripts/monitor-job.sh <job-id>
```

### aidev-monitor.sh

Real-time monitoring dashboard for AI dev jobs.

```bash
./scripts/aidev-monitor.sh
```

### schema-dump.sh

Dumps database schema for documentation.

```bash
./scripts/schema-dump.sh
```

### resetcache.php

Clears application caches.

```bash
php scripts/resetcache.php
```

---

## Crontab Setup

### Recommended Production Crontab

```bash
# MyCTOBot Cron Jobs
# Edit with: crontab -e

# ============================================
# CEO Directive Processor (every 5 minutes)
# ============================================
*/5 * * * * php /var/www/myctobot/scripts/cron-directive-processor.php --workspace=gwt >> /var/www/myctobot/log/directive-processor.log 2>&1

# ============================================
# Daily Digest Scheduler (every 15 minutes)
# ============================================
0,15,30,45 * * * * php /var/www/myctobot/scripts/cron-digest.php --script >> /var/www/myctobot/log/digest.log 2>&1

# ============================================
# Story Build Orchestrator (every 10 minutes, one batch)
# ============================================
*/10 * * * * php /var/www/myctobot/scripts/story-build-orchestrator.php --workspace=gwt --once >> /var/www/myctobot/log/orchestrator.log 2>&1

# ============================================
# Log rotation (daily at midnight)
# ============================================
0 0 * * * find /var/www/myctobot/log -name "*.log" -mtime +30 -delete
```

### Multi-Workspace Setup

For multiple workspaces, add separate cron entries:

```bash
# Workspace: gwt
*/5 * * * * php /var/www/myctobot/scripts/cron-directive-processor.php --workspace=gwt >> /var/www/myctobot/log/directive-gwt.log 2>&1

# Workspace: footest4
*/5 * * * * php /var/www/myctobot/scripts/cron-directive-processor.php --workspace=footest4 >> /var/www/myctobot/log/directive-footest4.log 2>&1
```

---

## Troubleshooting

### Check Running Sessions

```bash
# List all tmux sessions
tmux list-sessions

# List only AI dev sessions
tmux list-sessions | grep -E "(story-|aidev-)"

# Kill a stuck session
tmux kill-session -t <session-name>
```

### Check Logs

```bash
# Application logs
tail -f log/app-$(date +%Y-%m-%d).log

# Cron logs
tail -f log/directive-processor.log
tail -f log/orchestrator.log
tail -f log/digest.log

# Specific job work directory
ls -la /tmp/local-aidev-*/
cat /tmp/local-aidev-*/session.log
```

### Common Issues

**Session appears stuck:**
- Check if Claude is actively using CPU: `ps aux | grep claude`
- The `--print` mode buffers all output until completion
- Check CPU time is increasing to verify activity

**Labels not cleaned up:**
- Fixed in commit a969b4b
- Labels `ai-dev`, `myctobot-working`, `in-progress` are now removed on completion

**Job not found in database:**
- Ensure correct `--workspace` parameter
- Check workspace config file exists: `conf/config.{workspace}.ini`

**Permission denied:**
- Scripts should be run as web server user or with appropriate permissions
- Check file ownership in work directories

---

## Environment Variables

Scripts may use these environment variables:

| Variable | Description |
|----------|-------------|
| `MYCTOBOT_APP_ROOT` | Application root directory |
| `MYCTOBOT_WORKSPACE` | Current workspace/workspace |
| `MYCTOBOT_JOB_ID` | Current job ID |
| `MYCTOBOT_MEMBER_ID` | Current member ID |
| `MYCTOBOT_PROJECT_ROOT` | Repository clone path |
| `GITHUB_TOKEN` | GitHub API token |
| `GH_TOKEN` | GitHub CLI token |
| `MYCTOBOT_PROVIDER` | Issue provider (jira/github) |
