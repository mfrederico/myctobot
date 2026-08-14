# Runner Environment Hardening Plan

Status: **planned** (not implemented). Current behaviour is the inline-export
approach described in "Where we are today".

## Background

When a job runs on a remote workstation, `job-dispatcher.php` stages files locally
and then starts Claude over SSH. `run-claude.sh` exports the `MYCTOBOT_*` variables,
but that script runs *locally* - the SSH command starts a fresh login shell on the
workstation, so none of those exports reach the runner.

This is what caused job 168 to fail its checkpoint: `send-checkpoint.sh` hit its
`if [[ -z "$MYCTOBOT_JOB_ID" ]]` guard and exited before posting.

## Where we are today

`$remoteEnvExportsSingle` / `$remoteEnvExportsDouble` in `job-dispatcher.php` replay
the variables inline in the SSH command:

```bash
ssh … "export MYCTOBOT_JOB_ID='…' && export MYCTOBOT_API_KEY='…' && … && claude …"
```

This works and is the smallest change. The trade-off is that **two secrets appear in
the remote process list** (`MYCTOBOT_API_KEY`, `MYCTOBOT_WEBHOOK_KEY`), readable via
`ps aux` by any other user with a shell on that workstation. On a single-operator box
that is an acceptable risk; it stops being acceptable as soon as a workstation is
shared, rented, or handed to a customer.

Two variants exist because the remote command is single-quoted in print mode and
double-quoted in the interactive heredoc. Value quoting must be the opposite of the
enclosing quote in each case - keep both in sync when adding a variable.

## Proposed change: job.env file

Replace the inline exports with a mode-0600 env file staged alongside the other job
files.

1. **Write** `{$workDir}/job.env` containing the same `export` lines that
   `$hookEnvSection` emits. `chmod(0600)` immediately after writing.
2. **Sync** it with the other scp'd files:
   ```bash
   scp … job.env {$wsUser}@{$wsHost}:{$remoteWorkDir}/job.env
   ```
   scp preserves mode with `-p`; otherwise `chmod 600 job.env` remotely before use.
3. **Source** it in the remote command instead of exporting inline:
   ```bash
   ssh … "cd {$remoteWorkDir} && set -a && . ./job.env && set +a && claude …"
   ```
4. **Delete** it on exit. The existing trap cleanup in `$trapCleanup` is the natural
   place: `rm -f {$remoteWorkDir}/job.env`.

### Why this is better

- Secrets never appear in `ps`, shell history, or the tmux scrollback that
  `send-checkpoint.sh` captures into `terminal_snapshot.txt`.
- One definition of the variable set, instead of the current three
  (`$hookEnvSection`, `$remoteEnvExportsSingle`, `$remoteEnvExportsDouble`).
- Eliminates the quoting hazard entirely - no nested-quote escaping needed.

### Cost

- One extra scp per job (negligible).
- Cleanup must be reliable, or a file with a live API key is left in `~/jobs/…`.
  This is the main risk and argues for the trap-based removal rather than an
  explicit `rm` at the end of the command chain, which is skipped when the runner
  is killed.

## Related follow-ups

Found while tracing job 168; each is independent of the above.

- **Repo is never synced to the remote workstation.** The scp list covers
  `prompt.txt`, `.mcp.json`, `CLAUDE.md`, `.claude/settings.json` and
  `send-checkpoint.sh` - but not `repo/`. This currently works only because the
  configured workstation (`ubuntu@myctobot.ai`) is the same host that did the
  staging, so the local clone is reachable by absolute path. It will break against
  a genuinely remote workstation. `MYCTOBOT_PROJECT_ROOT` has the same problem: it
  points at the local staging path.

- **`${MYCTOBOT_API_KEY}` substitution in MCP configs resolves to nothing.**
  `job-dispatcher.php` maps it to `$member->api_token`, but `member` has no
  `api_token` column - tokens live in the `apikeys` table. Any MCP server config
  relying on that placeholder gets an empty credential. The `$apiKey` lookup used
  for the env section is the correct source, but it is resolved *after* the MCP
  block runs, so the lookup needs to move earlier.

- **AOE session id divergence.** Job 168 logged
  `Could not load AOE session 6ba153fc…, creating new one`, then staged into
  `…-07c5c377` while the tmux session kept the `…-6ba153fc` name.
  `AIDevJobManager::isSessionActuallyRunning()` matches sessions by
  `reference === issueKey`, found nothing, and `getRealStatus()` marked a live job
  `failed` with "Session ended unexpectedly" one second after start.
