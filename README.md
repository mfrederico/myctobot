# MyCTOBot

An AI-powered development platform for running automated pipelines, AI-assisted development jobs, and multi-tenant SaaS workloads. Built on [FlightPHP](https://flightphp.com/) and [RedBeanPHP](https://redbeanphp.com/), with a Bootstrap 5 UI and deep integration with Claude, Ollama, Shopify, Jira/Atlassian, and GitHub.

> If you're an AI assistant: see [`CLAUDE.md`](CLAUDE.md). It has the project's non-negotiable conventions.

## What's in here

- **AI Developer** — dispatches Claude Code (or Ollama) to close Jira/GitHub tickets autonomously
- **Pipelines** — step-graph workflow engine (direct_exec, ai_agent, webhook, mailgun, shopify_graphql, parser, wait, harvest, mcp_call, schedule_task, etc.)
- **Multi-tenant workspaces** — subdomain-routed (`{workspace}.myctobot.ai`), one database per tenant
- **Knowledge base** — document ingestion, embeddings, RAG chat
- **Live chat + customer support** — embeddable widget, connected to pipelines or direct LLM
- **CRM + prospect discovery** — lead capture, enrichment, outreach
- **Shopify integration** — OAuth, GraphQL client, theme builder, tenant apps
- **MCP servers** — JSON-RPC HTTP endpoints for AI agents to call into the platform
- **Connector registry** — unified OAuth/API connection store (Anthropic, GitHub, Google, Shopify, Atlassian, Mailgun, ...)

---

## Requirements

- PHP **8.1+** (8.2+ recommended)
- MySQL 8 / MariaDB 10.4+ (SQLite works for dev-only)
- Composer
- Node.js 20+ (for Shopify themes + WASM builder)
- Optional: [Ollama](https://ollama.com/) for local LLM, APCu for caching, Redis for sessions

## Quick Start

```bash
git clone https://github.com/mfrederico/myctobot.git
cd myctobot
composer install

# 1. Copy the example configs you need
cp conf/config.example.ini conf/config.ini
# For any integration you want to use:
cp conf/github.example.ini     conf/github.ini
cp conf/shopify.example.ini    conf/shopify.ini
cp conf/stitch.example.ini     conf/stitch.ini        # Google / Stitch OAuth
cp conf/mailgun.example.ini    conf/mailgun.ini
cp conf/stripe.example.ini     conf/stripe.ini
cp conf/atlassian.example.ini  conf/atlassian.ini
cp conf/jobexecutor.example.ini conf/jobexecutor.ini

# 2. Edit conf/config.ini with DB credentials and baseurl
#    Leave the other conf/*.ini files alone unless you're wiring that integration.

# 3. Run migrations (creates tables + seeds permissions/pipelines)
php scripts/run-migration.php --sync --workspace=default

# 4. Start the dev server
php -S localhost:8000 -t public/
```

Open `http://localhost:8000`, register an account — the first user becomes admin.

### Multi-workspace setup

Each tenant gets its own config and database:

```bash
cp conf/config.example.ini conf/config.demo.ini
# edit DB creds in conf/config.demo.ini
php scripts/run-migration.php --sync --workspace=demo
```

Route `demo.yourdomain.com` at the app and the bootstrap auto-selects the workspace from the subdomain.

---

## The `scripts/` toolkit

This is the biggest part of the project that isn't obvious from the source tree. Treat `scripts/` as the project's operational toolbox.

### `scripts/clitool.php` — the Swiss-army knife

**Use this first.** It's the fastest way to poke at the database, scaffold new models/controllers/views, and inspect bean state without writing one-off PHP.

```bash
# Help
php scripts/clitool.php --help

# List all tables in a workspace
php scripts/clitool.php --workspace=default --list

# Find / fetch
php scripts/clitool.php --workspace=default --bean=member --getall --limit=20
php scripts/clitool.php --workspace=default --bean=member --data='{"id":1}' --getjson
php scripts/clitool.php --workspace=default --bean=pipelineruns \
    --data='{"status":"running","_limit":10,"_order":"created_at DESC"}' --find

# Create / update (by id)
php scripts/clitool.php --workspace=default --bean=member \
    --data='{"id":1,"firstname":"Alice"}'

# Find-or-create (idempotent)
php scripts/clitool.php --workspace=default --bean=authcontrol \
    --findorcreate --match=control,method \
    --data='{"control":"shopify","method":"callback","level":101}'

# Delete
php scripts/clitool.php --workspace=default --bean=mcpservers --data='{"id":43}' --trash

# Associate (many-to-many)
php scripts/clitool.php --workspace=default --bean=member \
    --associate=jiraboard --data='{"id":1,"jiraboard_id":5}'

# Interactive wizard — creates model + controller + views + authcontrol rows
php scripts/clitool.php --workspace=default --wizard

# Scaffold from an existing table
php scripts/clitool.php --workspace=default --bean=product --scaffold=all
php scripts/clitool.php --workspace=default --bean=product --scaffold=model,control,view,api
```

Rule of thumb: **if you're tempted to write `php -r '...'` against the database, use clitool instead.** See [`CLAUDE.md`](CLAUDE.md) for the full rationale.

### Core scripts

| Script | What it does |
|---|---|
| `scripts/clitool.php` | Bean CRUD, scaffolding, wizard (see above) |
| `scripts/run-migration.php` | Idempotent migrations from `services/Schema/Seeds/`. Supports `--sync`, `--list`, `--status`, `--migration=NAME`, `--force`, `--mark` |
| `scripts/ingest-document.php` | Ingest a document into a workspace's knowledge base (chunk + embed) |
| `scripts/scan-plugins.php` | Discover and register plugins from `lib/plugins/*.json` |
| `scripts/resetcache.php` | Clear APCu/query cache for a workspace |
| `scripts/schema-dump.sh` | Dump current DB schema for diff/review |
| `scripts/setup-jira-pipeline.php` | One-time Jira pipeline bootstrap |

### AI Developer (runs Claude/Ollama on tickets)

| Script | What it does |
|---|---|
| `scripts/trigger-job.php` | Manually kick off an AI Developer job |
| `scripts/job-dispatcher.php` | Dispatch a job to a workstation (tmux-based) |
| `scripts/ai-dev-agent.php` | The agent itself — clones repo, runs Claude Code, pushes PR |
| `scripts/story-build-orchestrator.php` | Multi-agent orchestrator (impl → verify → fix loop) |
| `scripts/agent-new.sh` | Scaffold a new Claude agent config |
| `scripts/agent-test-runner.php` | Run automated agent test suites |
| `scripts/claude-ollama` | Wrapper to launch Claude CLI pointing at a local Ollama endpoint |
| `scripts/ollama-forward.sh` | SSH port-forward to a remote Ollama server |
| `scripts/sync-to-shards.sh` | Rsync code to a remote worker shard |
| `scripts/monitor-job.sh` | tmux dashboard for a running AI dev job |
| `scripts/monitor-pipeline.sh` | Live tail of a pipeline run |

### Pipelines & tenant apps

| Script | What it does |
|---|---|
| `scripts/runpipe.php` | Run a pipeline by slug — used as fallback when the engine isn't running |
| `scripts/tenantapp-manager.php` | Start/stop/restart tenant apps and engine services |
| `scripts/tenantapp-watchdog.php` | Restart tenant apps that crash or drift |
| `scripts/assist-websocket-server.php` | WebSocket server for the in-app AI assistant widget |
| `scripts/assistant-start.sh` | Bring up the assistant websocket + related services |
| `scripts/send-checkpoint.sh` | Emit a pipeline progress checkpoint |

### CRM / prospect pipeline

Defaults to `--workspace=default` — pass your own workspace.

| Script | What it does |
|---|---|
| `scripts/cron/cron-crm-sync.php` | Sync contacts from external sources |
| `scripts/cron/cron-crm-enrichment.php` | Enrich contact records (company, size, etc.) |
| `scripts/cron/cron-crm-linkedin-enrichment.php` | LinkedIn profile enrichment |
| `scripts/cron/cron-crm-linkedin-related.php` | Related-company discovery via LinkedIn |
| `scripts/cron/cron-crm-outreach.php` | Send outreach emails |
| `scripts/cron/cron-prospect-discover.php` | Discover new prospects from Google queries |
| `scripts/cron/cron-prospect-analyze.php` | Analyze discovered domains |
| `scripts/cron/cron-prospect-push.php` | Push qualified prospects into CRM |
| `scripts/daily-prospect-pipeline.sh` | Chains the above into one daily run |
| `scripts/linkedin-browser.sh` + `linkedin-cdp.js` | Chrome-via-CDP LinkedIn scraping helpers |

### Scheduled / cron

All scripts in `scripts/cron/` are designed to run from `cron`. Example crontab:

```cron
*/5 * * * * cd /path/to/myctobot && php scripts/cron/cron-pipeline-triggers.php --script
*/10 * * * * cd /path/to/myctobot && php scripts/cron/cron-await-timeouts.php --script
0    * * * * cd /path/to/myctobot && php scripts/cron/cron-scheduled-tasks.php --script
0    7 * * * cd /path/to/myctobot && php scripts/cron/cron-digest.php --script
0    4 * * * cd /path/to/myctobot && php scripts/cron/cron-plugin-registry.php --script
```

Other cron scripts: `cron-analysis`, `cron-directive-processor`, `cron-inactivity-check`, `cron-magic-links`.

### Git hooks (Claude Code pre-tool-use)

`scripts/hooks/` contains PHP scripts that are invoked by Claude Code's `PreToolUse` hook (see `.claude/settings.json`). They validate PHP syntax, block Claude-authored commit cruft, and enforce the "check the logs first" rule from `CLAUDE.md`. None of them are required if you're not using Claude Code — they just disable themselves.

### Test scripts

`scripts/test/` has standalone test harnesses (MCP tools, workstations, Shopify CLI, FUSE models). These don't need phpunit — they print directly.

---

## Architecture (the short version)

- **Controllers** live in `controls/`. URLs auto-route to lowercase class + method names via `lib/FlightMap.php`. **No explicit route files needed** — see `CLAUDE.md` for why.
- **Models** live in `models/` (RedBeanPHP FUSE). Use the `\app\Bean` wrapper (`lib/Bean.php`) — it normalizes type names so `R::dispense` never chokes on camelCase.
- **Views** live in `views/`, Bootstrap 5 sandwich layout.
- **Migrations** are idempotent PHP files in `services/Schema/Seeds/`, numbered for order. Run with `scripts/run-migration.php`.
- **Pipelines** are defined by rows in `pipelines` + `pipelinesteps` and exported as JSON to `seeds/pipelines/` for version control.
- **Permissions** are stored in the `authcontrol` table. Levels: `1=ROOT`, `50=ADMIN`, `100=MEMBER`, `101=PUBLIC`.

For the full conventions (RedBean associations, FUSE hooks, FlightPHP routing gotchas, auth patterns, WASM/OpenSwoole tenant apps), read:

- [`CLAUDE.md`](CLAUDE.md) — project rules, required patterns
- [`FLIGHTPHP_README.md`](FLIGHTPHP_README.md) — FlightPHP routing patterns
- [`REDBEAN_README.md`](REDBEAN_README.md) — RedBeanPHP CRUD/associations
- [`docs/`](docs/) — feature-specific deep dives

---

## Configuration

Every `conf/*.ini` file has a `*.example.ini` sibling. Copy + fill in. All real `conf/*.ini` files are gitignored.

| File | When you need it |
|---|---|
| `conf/config.ini` | Always — app + DB + logging |
| `conf/config.{workspace}.ini` | Each extra tenant |
| `conf/github.ini` | GitHub OAuth + webhook |
| `conf/shopify.ini` | Shopify OAuth + app CLI |
| `conf/stitch.ini` | Google / Stitch OAuth |
| `conf/atlassian.ini` | Jira/Confluence OAuth (3LO) |
| `conf/mailgun.ini` | Outbound + inbound email |
| `conf/stripe.ini` | Subscriptions/billing |
| `conf/jobexecutor.ini` | Remote job executor service |
| `conf/assistant.ini` | In-app AI assistant widget (Ollama + RAG) |
| `conf/pricing.ini` | Platform pricing tiers (UI only) |

## Permission levels

| Const | Value | Meaning |
|---|---|---|
| `LEVELS['ROOT']` | 1 | Super admin |
| `LEVELS['ADMIN']` | 50 | Administrator |
| `LEVELS['MEMBER']` | 100 | Logged-in user |
| `LEVELS['PUBLIC']` | 101 | Guest / not logged in |

Lower number = more privileged. Set on the `authcontrol` row for each controller/method pair.

---

## Contributing

1. **Read `CLAUDE.md` first.** It has hard rules (never create explicit route files, always use `Bean::` for CRUD, never use `R::exec` for CRUD, etc.). Violating them will fail review.
2. **Always check logs first** when debugging. `tail -50 log/app-$(date +%Y-%m-%d).log` usually tells you the answer.
3. **Use `scripts/clitool.php`** for anything touching the DB — don't write `php -r '...'` one-offs.
4. **Use the scaffolding wizard** for new models: `php scripts/clitool.php --wizard`. It wires up authcontrol, hooks, views.
5. **Migrations are idempotent.** If you add a migration, run it twice and make sure it doesn't error.
6. **Don't commit `conf/*.ini`** except the `*.example.ini` files. The `.gitignore` already enforces this.
7. **Commit messages.** Short, imperative, explain *why* not *what*. The commit hook blocks AI-authored cruft lines.

### Running the test suites

```bash
# Playwright browser tests
npx playwright test

# Individual PHP test scripts
php scripts/test/test-fuse-models.php
php scripts/test/test-mcp-tools.php
php scripts/agent-test-runner.php
```

### Reporting issues

File at https://github.com/mfrederico/myctobot/issues. Include:
- Which workspace (or `default`)
- Steps to reproduce
- Relevant log snippet from `log/app-YYYY-MM-DD.log`

## License

MIT. See [`LICENSE`](LICENSE).

## Credits

Built with [FlightPHP](https://flightphp.com/), [RedBeanPHP](https://redbeanphp.com/), [Bootstrap 5](https://getbootstrap.com/), [Monolog](https://github.com/Seldaek/monolog), and [Claude Code](https://claude.ai/claude-code).
