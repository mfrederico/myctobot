# Story Build Orchestrator

The orchestrator processes approved stories from the Review Board, spawning AI dev agents to implement each one.

## Quick Start

```bash
# Dry run - see what would be processed
php scripts/story-build-orchestrator.php --workspace=footest4 --dry-run --verbose

# Process one story and exit
php scripts/story-build-orchestrator.php --workspace=footest4 --verbose --once

# Run continuously (daemon mode)
php scripts/story-build-orchestrator.php --workspace=footest4 --verbose

# Run with parallel processing (2 stories at a time)
php scripts/story-build-orchestrator.php --workspace=footest4 --verbose --max-concurrent=2
```

## Options

| Option | Description |
|--------|-------------|
| `--workspace=<name>` | **Required.** Workspace slug (e.g., `footest4`, `gwt`) |
| `--member=<id>` | Member ID to run as (default: first admin) |
| `--max-concurrent=<n>` | Max parallel builds (default: 1, max: 4) |
| `--once` | Process one batch and exit (don't loop) |
| `--dry-run` | Show what would be processed without running |
| `--verbose` | Show detailed output |
| `--help` | Show help |

## How It Works

1. **Finds eligible stories** - Queries for `approved` stories that either:
   - Have no job record (never run)
   - Have a `failed` or `cancelled` job (retry eligible)

2. **Spawns tmux sessions** - Each story gets its own tmux session named:
   ```
   story-{workspace}-{storyId}-{sanitized-issue-key}
   ```
   Example: `story-footest4-73-mfrederico-myctobot-99`

3. **Runs local-aidev-full.php** - The actual AI agent that:
   - Clones the repo
   - Reads the GitHub issue
   - Implements the changes
   - Creates a PR

4. **Monitors progress** - Watches tmux sessions for completion/failure

## Story Flow

```
pending_review  →  approved  →  [orchestrator picks up]  →  in_progress  →  done
                      ↑                                          ↓
                      └──────────── [on failure] ←───────────────┘
```

## Monitoring

### View active sessions
```bash
tmux list-sessions | grep story-
```

### Attach to a session
```bash
tmux attach -t story-footest4-73-mfrederico-myctobot-99
```

### View session output (without attaching)
```bash
tmux capture-pane -t story-footest4-73-mfrederico-myctobot-99 -p
```

## Retry Failed Jobs

Failed jobs are automatically eligible for retry. The orchestrator will pick them up on the next run.

To manually retry a specific story:
```bash
php scripts/local-aidev-full.php --issue=mfrederico/myctobot#99 --workspace=footest4 --print
```

## Workspace Runner Limits

The orchestrator respects workspace-level runner limits set in the Review Board UI. Default is 2 concurrent local runners, max 4.

## Logs

- Orchestrator logs to stdout (use `--verbose` for details)
- Individual job logs: `log/workspace.log`
- Job status tracked in `aidevjobs` table

## Cron Setup

To run the orchestrator as a background daemon:

```bash
# Add to crontab - checks every 5 minutes
*/5 * * * * cd /path/to/myctobot && php scripts/story-build-orchestrator.php --workspace=footest4 --once >> log/orchestrator.log 2>&1
```

Or run in a screen/tmux session:
```bash
screen -dmS orchestrator php scripts/story-build-orchestrator.php --workspace=footest4 --verbose
```
