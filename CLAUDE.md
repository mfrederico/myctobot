# Tiknix Development Standards

## RULE #1: CHECK THE LOGS FIRST

**When something isn't working, ALWAYS check the logs BEFORE trying fixes.**

```bash
tail -50 log/*-$(date +%Y-%m-%d).log
```

The logs will tell you exactly what's wrong. Don't guess. Don't try random fixes. Read the error message first.

---

## RULE #2: USE THE CLI TOOL FOR DATABASE OPERATIONS

**When you need to query or update database records, use `scripts/clitool.php` instead of writing inline PHP.**

```bash
# List all tables in a workspace
php scripts/clitool.php --workspace=dev --list

# Get a record as JSON
php scripts/clitool.php --workspace=dev --bean=mcpservers --data='{"id":10}' --getjson

# Update a record
php scripts/clitool.php --workspace=dev --bean=member --data='{"id":1,"firstname":"Updated"}'

# Create associations
php scripts/clitool.php --workspace=dev --bean=member --associate=jiraboard --data='{"id":1,"jiraboard_id":5}'
```

Don't write complex inline PHP scripts with `php -r '...'` - use the CLI tool. It handles bootstrap, workspace selection, and JSON I/O cleanly.

See `php scripts/clitool.php --help` for all options.

---

This project uses FlightPHP and RedBeanPHP. You MUST follow these conventions strictly.

## RedBeanPHP Rules (CRITICAL)

> **Official Documentation**: https://redbeanphp.com/
> Always refer to the official docs for the most accurate information.

### Bean Wrapper Class (REQUIRED)

**ALWAYS use the `Bean` wrapper class for database operations.**

The Bean class (`lib/Bean.php`) normalizes bean type names to all lowercase, which is required
by RedBeanPHP's R::dispense(). It wraps R:: calls internally while accepting camelCase,
snake_case, or lowercase table names and converting them.

```php
use \app\Bean;

// ALL database operations should use Bean::
$setting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['api_key']);
$job = Bean::dispense('aidevjobs');
Bean::store($job);
Bean::trash($job);

$member = Bean::load('member', $memberId);
$tokens = Bean::find('atlassiantoken', 'cloud_uid = ?', [$cloudId]);
```

**R:: is only needed for low-level operations:**
- `R::setup()` - Database connection
- `R::freeze()` - Schema freezing
- `R::close()` - Connection closing
- `R::begin()`, `R::commit()`, `R::rollback()` - Transaction management
- `R::addDatabase()`, `R::selectDatabase()`, `R::hasDatabase()` - Multi-database switching
- `R::getDatabaseAdapter()` - Low-level adapter access
- `R::exec()` - Raw SQL (use sparingly, see Bean Operations below)

### Naming Conventions (IMPORTANT)

All table names should be lowercase without underscores (handled by Bean::).

**Column names - use snake_case (these map directly to database columns):**
```php
$bean->setting_key = 'api_key';       // Column: setting_key
$bean->setting_value = 'encrypted';   // Column: setting_value
$bean->created_at = date('Y-m-d');    // Column: created_at
$bean->issue_key = 'PROJ-123';        // Column: issue_key
```

**WRONG - Don't use underscores in bean TYPE names with R::dispense:**
```php
// WRONG - R::dispense will fail with these!
$bean = R::dispense('order_item');    // WRONG! Use 'orderitem'
$bean = R::dispense('aiDevJobs');     // WRONG! Use 'aidevjobs'
$bean = R::dispense('EnterpriseSettings'); // WRONG! Use 'enterprisesettings'
```

### Relations - USE ASSOCIATIONS (PREFERRED METHOD)

**ALWAYS use RedBeanPHP associations instead of manual foreign key management.**

Associations provide:
- **Automatic FK creation** - RedBeanPHP creates the `parent_id` column for you
- **Lazy loading** - Related beans only loaded when accessed
- **Cleaner code** - No manual JOIN queries needed
- **Cascade options** - Use `xown` prefix for cascade delete

### One-to-Many: `ownBeanList`

```php
// Parent has many children - FK created automatically
$board = Bean::load('jiraboards', $boardId);

// Lazy load all analysis results for this board (queries DB on access)
foreach ($board->ownAnalysisresultsList as $result) {
    echo $result->analysis_type;
}

// Add a new child - board_id set automatically
$result = Bean::dispense('analysisresults');
$result->analysis_type = 'sprint';
$result->content_json = json_encode($data);
$board->ownAnalysisresultsList[] = $result;
Bean::store($board);  // Saves both board and new result

// CASCADE DELETE: Use xown prefix to delete children when parent deleted
$board->xownAnalysisresultsList;  // Children deleted when board is trashed
```

**Ordering and filtering associations with `with()` / `withCondition()`:**
```php
// Use with() for ORDER BY, LIMIT, etc.
$results = $board->with(' ORDER BY created_at DESC ')->ownAnalysisresultsList;
$recentJobs = $board->with(' ORDER BY created_at DESC LIMIT 10 ')->ownAidevjobsList;

// Use withCondition() for WHERE + ORDER BY
$pendingJobs = $board->withCondition(' status = ? ORDER BY created_at ASC ', ['pending'])->ownAidevjobsList;

// Counting with conditions
$activeCount = $board->withCondition(' status = ? ', ['running'])->countOwn('aidevjobs');
```

**Project examples:**
```php
// Board has many analysis results
$board->ownAnalysisresultsList;      // → analysisresults.board_id

// Board has many digest history entries
$board->ownDigesthistoryList;        // → digesthistory.board_id

// Job has many log entries
$job->ownAidevjoblogsList;           // → aidevjoblogs.job_id (if using job_id FK)
```

### Many-to-Many: `sharedBeanList`

```php
// Boards can have many repos, repos can be on many boards
$board = Bean::load('jiraboards', $boardId);
$repo = Bean::load('repoconnections', $repoId);

// Link them - creates jiraboards_repoconnections link table automatically
$board->sharedRepoconnectionsList[] = $repo;
Bean::store($board);

// Access from either side
$repos = $board->sharedRepoconnectionsList;
$boards = $repo->sharedJiraboardsList;
```

### Foreign Key Naming (Automatic)

RedBeanPHP automatically names FKs as `[parent_type]_id`:
- `boards_id` in analysisresults (result belongs to board)
- `repoconnections_id` in boardrepomapping (mapping belongs to repo)

**Note:** For lowercase table names, the FK is also lowercase:
- `boards` → `boards_id`
- `aidevjobs` → `aidevjobs_id`

### Why Associations Over Manual FKs

```php
// BAD - Manual FK management
$result = Bean::dispense('analysisresults');
$result->boards_id = $boardId;  // Manual FK assignment
Bean::store($result);

// GOOD - Use association
$board = Bean::load('jiraboards', $boardId);
$result = Bean::dispense('analysisresults');
$board->ownAnalysisresultsList[] = $result;
Bean::store($board);  // FK set automatically, both saved in transaction
```

Benefits of associations:
1. FK value set automatically
2. Both beans saved in single transaction
3. Lazy loading when retrieving
4. No need to define FK in schema - RedBeanPHP creates it
5. Works with FUSE models for validation hooks

### Bean Operations (CRITICAL)

**ALWAYS use bean operations for CRUD. R::exec/Bean::exec should ONLY be used for DDL (schema) or extreme situations.**

```php
// CORRECT - Use Bean:: for all CRUD operations
$job = Bean::dispense('aidevjobs');
$job->issue_key = 'PROJ-123';
$job->status = 'pending';
Bean::store($job);

$setting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['api_key']);
Bean::trash($setting);

$member = Bean::load('member', $id);
$member->lastLogin = date('Y-m-d H:i:s');
Bean::store($member);

// WRONG - NEVER use exec for simple CRUD
Bean::exec('INSERT INTO member (email) VALUES (?)', [$email]);  // WRONG!
Bean::exec('UPDATE aidevjobs SET status = ?', ['done']);     // WRONG!
```

**The ONLY acceptable uses for R::exec/Bean::exec:**
```php
// DDL (schema creation) - OK
R::exec('CREATE TABLE IF NOT EXISTS mytable (...)');

// Complex atomic operation that can't be done with beans - OK sparingly
R::exec('UPDATE member SET loginCount = loginCount + 1 WHERE id = ?', [$id]);
```

**If you think you need Bean::exec, ask yourself:**
1. Can this be done with Bean::load + Bean::store? → Use that instead
2. Can this be done with Bean::find + loop + Bean::store? → Use that instead
3. Is this a complex aggregate/batch that truly can't use beans? → Only then use Bean::exec

### Why Bean Operations Are Mandatory

RedBeanPHP models (FUSE) ONLY work with bean operations. Using R::exec bypasses:
- Model hooks (`update()`, `afterUpdate()`, `delete()`, etc.)
- Model validation
- Business logic in models
- The entire point of using an ORM

If you use R::exec for simple CRUD, the ORM becomes useless and models are ignored.

### Query Methods Reference

**Use Bean:: methods for all database operations:**

| Method | Returns | Use Case |
|--------|---------|----------|
| `Bean::load($type, $id)` | Single bean (empty if not found) | Get by ID |
| `Bean::findOne($type, $sql, $params)` | Single bean or NULL | Get first match |
| `Bean::find($type, $sql, $params)` | Array of beans | Get matching rows |
| `Bean::findAll($type, $sql, $params)` | Array of beans | Same as find |
| `Bean::count($type, $sql, $params)` | Integer | Count rows |
| `Bean::dispense($type)` | New bean | Create new bean |
| `Bean::store($bean)` | ID | Save bean |
| `Bean::trash($bean)` | void | Delete bean |
| `Bean::getAll($sql, $params)` | Array of arrays | Complex SELECT with joins |
| `Bean::getRow($sql, $params)` | Array or null | Single row as array |
| `Bean::getCell($sql, $params)` | Mixed | Single value |

### Quick Reference: PHP Property → Database Column

| PHP (camelCase) | Database (auto-converted) |
|-----------------|---------------------------|
| `createdAt`     | `created_at`              |
| `updatedAt`     | `updated_at`              |
| `firstName`     | `first_name`              |
| `lastName`      | `last_name`               |
| `userId`        | `user_id`                 |
| `orderTotal`    | `order_total`             |
| `isActive`      | `is_active`               |
| `ownProductList`| (relation, not a column)  |
| `sharedTagList` | (relation, not a column)  |

## FlightPHP Rules

### Controller Conventions

1. Controllers extend `BaseControls\Control`
2. Use `$this->render()` for views
3. Use `$this->getParam()` for request parameters
4. Use `$this->sanitize()` for input sanitization
5. Always validate CSRF with `$this->validateCSRF()` on POST requests

### Routing and Naming (IMPORTANT - Security Implications)

FlightPHP auto-routes URLs to controllers by converting URLs to lowercase class names.
This has **security implications** for naming:

**Controller Class Names - ALL LOWERCASE, NO HYPHENS:**
- URL `/pluginsources` maps to class `Pluginsources`
- **NEVER use CamelCase** for controller class names (e.g., `PluginSources`)
- **NEVER use hyphens** in controller names, URLs, or route files
- Hyphens in URLs like `/knowledge-base` require explicit route files - avoid this pattern

**WRONG:**
```php
class PluginSources { }     // CamelCase - won't auto-route
class Knowledge-Base { }    // Invalid PHP - hyphens not allowed in class names
// routes/knowledge-base.php  // Requires manual routing - avoid
```

**CORRECT:**
```php
class Pluginsources { }     // All lowercase - auto-routes from /pluginsources
class Knowledgebase { }     // All lowercase - auto-routes from /knowledgebase
```

**Method Names (SECURITY FEATURE):**
- URL `/controller/method` maps to lowercase method `method()`
- CamelCase methods like `internalHelper()` are **NOT routable** via URL
- This is an **implicit security feature** - use CamelCase for internal/private methods

```php
class Mycontroller extends BaseControls\Control {
    // PUBLIC - routable via /mycontroller/index
    public function index() { }

    // PUBLIC - routable via /mycontroller/search
    public function search() { }

    // PROTECTED from routing - CamelCase won't match URL /mycontroller/processdata
    private function processData() { }

    // PROTECTED from routing - internal helper, not exposed
    private function validateInput() { }
}
```

### DefaultRoute Auto-Routing (CRITICAL - NO EXPLICIT ROUTES NEEDED)

**The DefaultRoute handler automatically manages routing, auth, and permissions.**
Do NOT create explicit route files in `routes/` unless absolutely necessary.

**How it works:**
1. URL `/controller/method` → `controls/Controller.php` → `method()`
2. DefaultRoute handles authentication and permission checks via `authcontrol` table
3. All HTTP methods (GET, POST, OPTIONS, PUT, DELETE) route to the same method
4. The method decides what to do based on `$_SERVER['REQUEST_METHOD']`

**Example - Sub-endpoint delegation (lazy loading pattern):**

For URLs like `/mcp/jira`, `/mcp/jobs`, `/mcp/pipelines`:

```php
// controls/Mcp.php - ONE controller handles all /mcp/* routes
class Mcp extends Control {

    // /mcp -> index()
    public function index() {
        // Main endpoint logic
    }

    // /mcp/jira -> jira() - lazy loads Mcpjira controller
    public function jira(): void {
        $controller = new Mcpjira();
        $controller->index();
    }

    // /mcp/jobs -> jobs() - lazy loads Mcpjobs controller
    public function jobs(): void {
        $controller = new Mcpjobs();
        $controller->index();
    }

    // /mcp/pipelines -> pipelines() - lazy loads Mcppipelines controller
    public function pipelines(): void {
        $controller = new Mcppipelines();
        $controller->index();
    }
}
```

**Benefits of this pattern:**
- Reduces number of route files (often zero needed)
- Lazy/opportunistic loading - only instantiate what's needed
- Sandboxes related endpoints under one controller
- DefaultRoute handles auth/permissions automatically
- Cleaner URL structure

**NEVER do this:**
```php
// routes/mcp.php - DON'T create explicit routes!
Flight::route('POST /mcp/jira', function() {
    $controller = new \app\Mcpjira();
    $controller->index();
});
```

**Best Practice:**
- Controller class names: all lowercase, no hyphens (e.g., `Pluginsources`, `Knowledgebase`)
- Public method names: all lowercase (e.g., `index()`, `store()`, `delete()`)
- Internal methods: use CamelCase for implicit protection (e.g., `processData()`, `validateInput()`)

### Workspace from Subdomain (SIMPLE PATTERN)

The front controller (`public/index.php`) extracts workspace from subdomain:

```php
// gwt.myctobot.ai -> 'gwt'
// myctobot.ai -> null
$_SERVER['WORKSPACE'] = (count($parts) >= 3) ? $parts[0] : null;
```

**In any controller, just use:**
```php
$this->workspace = $_SERVER['WORKSPACE'] ?? null;
```

That's it. Don't extract it yourself. Don't check headers. It's already set.

### MCP Endpoints (WORKING PATTERNS)

MCP uses JSON-RPC 2.0 over HTTP. Here's what ACTUALLY WORKS:

**Working .mcp.json for AI Dev jobs:**
```json
{
  "mcpServers": {
    "jira": {
      "type": "http",
      "url": "https://gwt.myctobot.ai/mcp/jira",
      "headers": {
        "Authorization": "Basic BASE64(memberId:cloudId)",
        "X-MCP-Agent-Name": "AgentName"
      }
    },
    "myctobot": {
      "type": "http",
      "url": "https://gwt.myctobot.ai/mcp/jobs",
      "headers": {
        "Authorization": "Basic BASE64(memberId:jobId)"
      }
    }
  }
}
```

**Auth patterns that work:**
- `/mcp/jira` - Basic auth: `base64(memberId:cloudId)`
- `/mcp/jobs` - Basic auth: `base64(memberId:jobId)`
- `/mcp/pipelines` - Bearer token: `tk_xxx`

**Don't overcomplicate.** Copy working patterns from `scripts/job-dispatcher.php`.

**CRITICAL: PHP Arrays vs JSON Objects**

In JSON Schema and MCP, `properties` MUST be an object `{}`, never an array `[]`.
PHP's `json_encode()` outputs `[]` for empty arrays but `{}` for objects.

**This causes "Failed to parse JSON" errors in Claude Code and other strict JSON parsers.**

```php
// WRONG - json_encode outputs "properties": []
['properties' => []]

// CORRECT - json_encode outputs "properties": {}
['properties' => (object) []]
['properties' => new stdClass()]
```

**Always sanitize when reading from database or user input:**
```php
// Empty arrays become objects
if (is_array($data['properties']) && empty($data['properties'])) {
    $data['properties'] = (object) [];
}
```

**Common places this breaks:**
- MCP tool `inputSchema`
- JSON Schema definitions
- API responses where empty objects are expected
- Anywhere JavaScript/TypeScript expects `{}` not `[]`

**Use the JsonSchema helper class for automatic fixing:**
```php
use app\JsonSchema;

// Automatically fixes properties, definitions, etc. before encoding
$json = JsonSchema::encode($schema);

// Or fix data before manual encoding
$fixed = JsonSchema::fix($schema);

// Validate a schema for issues
$result = JsonSchema::validate($schema);
if (!$result['valid']) {
    foreach ($result['errors'] as $error) {
        $this->logger->warning("Schema issue: {$error}");
    }
}

// Create a properly-typed empty schema
$empty = JsonSchema::emptySchema();
```

### Response Methods

```php
// JSON responses
Flight::jsonSuccess($data, 'Success message');
Flight::jsonError('Error message', 400);

// Redirects
Flight::redirect('/path');

// Views
$this->render('view/name', ['data' => $data]);
```

### Permission Levels

```php
LEVELS['ROOT']   = 1    // Super admin
LEVELS['ADMIN']  = 50   // Administrator
LEVELS['MEMBER'] = 100  // Regular user
LEVELS['PUBLIC'] = 101  // Not logged in (guest)
```

Lower number = higher privilege. Check with `Flight::hasLevel(LEVELS['ADMIN'])`.

### Stateless API Controllers

For API endpoints without sessions (webhooks, MCP, external APIs):

```php
class Myapi extends Control {
    private ?int $memberId = null;
    private ?string $workspace = null;
    protected $logger;

    public function __construct() {
        // DON'T call parent - no session/CSRF for stateless requests
        $this->logger = Flight::get('log');
        $this->workspace = $_SERVER['WORKSPACE'] ?? null;  // Already set by front controller
    }

    public function index() {
        // Switch to workspace database
        WorkspaceResolver::switchDatabase($this->workspace);

        // Authenticate (choose one pattern):
        // Bearer token: ApiAuthService::authenticate('scope', 'action')
        // Basic auth: base64_decode + validate memberId:secretId

        // Handle request...
    }
}
```

**When to use this pattern:**
- External API endpoints (called by other services)
- Webhook receivers
- MCP/JSON-RPC endpoints
- Any endpoint using Bearer token instead of session cookies

**Key differences from session-based controllers:**
- Don't call `parent::__construct()` - no session, no CSRF, no menu loading
- Extract workspace from subdomain (gwt.myctobot.ai → gwt)
- Use `WorkspaceResolver::switchDatabase()` to switch DB
- Use `ApiAuthService::authenticate()` for Bearer token auth
- No `$this->member` - use `$authResult['member']` instead

## File Structure

```
/controls       - Controllers (auto-routed by URL)
/views          - PHP view templates
/lib            - Core libraries
/models         - RedBeanPHP FUSE models
/routes         - Custom route definitions
/conf           - Configuration files
```

## Creating New Models/Controllers/Views (MANDATORY)

**ALWAYS use the scaffolding CLI tool when creating new database models, controllers, or views.**

NEVER manually create `Model_*`, controller, or view files. The scaffolding tool ensures:
- Correct FUSE model hooks (dispense, update, afterUpdate, delete)
- Proper RedBeanPHP naming conventions
- Consistent controller patterns (CRUD or API)
- Bootstrap 5 form components with proper widgets
- Automatic timestamp fields (created_at, updated_at)

### Scaffolding Commands

```bash
# List existing tables and their relationships (always run first to understand structure)
php scripts/clitool.php --workspace={workspace} --list

# Interactive wizard for new models (PREFERRED for new entities)
php scripts/clitool.php --workspace={workspace} --wizard

# Scaffold from existing database table
php scripts/clitool.php --workspace={workspace} --scaffold --bean={tablename} --type={crud|api|both}

# Dry-run to preview without creating files
php scripts/clitool.php --workspace={workspace} --wizard --dry-run
```

### Field Definition Syntax

When using the wizard, define fields with this format: `field_name:type[:options]`

**Types:** `string`, `text`, `int`, `float`, `bool`, `datetime`, `date`, `json`, `enum`

**Options:** `required`, `unique`, `default=VALUE`, `widget=NAME`

**Examples:**
```
name:string:required
email:string:required,unique
description:text
price:float:required
is_active:bool:default=true
status:enum=pending|active|archived:required
integration_type:enum=jira|github|shopify:default=jira
appointment:datetime:widget=fancyDateSelector
metadata:json
```

### Relationship Types

The wizard supports these RedBeanPHP relationship types:
- **has-many** (ownBeanList) - Parent owns many children
- **has-one** (ownBeanList) - Parent owns one child
- **many-to-many** (sharedBeanList) - Bidirectional relationship
- **belongs-to** (parent) - Child belongs to parent

### Controller Types

- **CRUD** - Session-based controller with Bootstrap views (web UI)
- **API** - Stateless JSON endpoints (for external/MCP access)
- **BOTH** - Generates both CRUD and API controllers
- **NONE** - Model only, no controller

### Permission Levels

The wizard automatically creates `authcontrol` entries. Choose the appropriate level:

| Level | Value | Description |
|-------|-------|-------------|
| PUBLIC | 101 | Anyone can access, no login required |
| MEMBER | 100 | Logged-in users only (Recommended default) |
| ADMIN | 50 | Administrators only |
| ROOT | 1 | Super admin only |
| SKIP | - | Don't create authcontrol (manual setup later) |

### After Scaffolding

1. Review generated files in `/models`, `/controls`, `/views`
2. Test the new endpoint at `https://{workspace}.myctobot.ai/{beanname}`
3. (Optional) Adjust permission level in `authcontrol` table if needed

### Custom Widgets

The scaffolding system supports custom field widgets. To create a new widget:
1. Create template at `lib/Scaffold/Templates/fields/{widgetname}.php`
2. Reference it in field definition: `fieldname:datetime:widget={widgetname}`

Available widgets: `text`, `textarea`, `number`, `checkbox`, `email`, `password`, `datetime`, `date`, `json`, `select`, `enum`, `url`, `fancyDateSelector`

## Shard Infrastructure

Shards are remote servers that run AI Developer jobs (Claude Code CLI).

### SSH Access
```bash
# Connect to shard (use claudeuser, NOT root)
ssh claudeuser@173.231.12.84

# Logs location
/var/www/html/default/myctobot/log/shard-YYYY-MM-DD.log

# Job work directories
/tmp/aidev-job-{job_id}/

# Sync code to shards
./scripts/sync-to-shards.sh
```

### Shard Endpoints
- `POST /analysis/shardaidev` - Run AI Developer job
- `GET /health` - Health check

## Ollama + Claude Code Integration

AI agents can use local Ollama models instead of Anthropic's Claude API. **Ollama natively supports Anthropic API format** including tool calling, so no proxy is needed.

### Quick Start

```bash
# Use the claude-ollama wrapper script:
claude-ollama

# Or set environment variables manually:
export ANTHROPIC_BASE_URL=http://localhost:11434
export ANTHROPIC_API_KEY=ollama
claude --model qwen3-coder:30b
```

### Verified Working Models

| Model | Size | Tool Calling | Notes |
|-------|------|--------------|-------|
| `qwen3-coder:30b` | 18GB | ✅ Works | MoE model (30B params, 3.3B active). Requires 24GB+ VRAM. |
| `devstral:latest` | ~14GB | ✅ Works | Mistral's code model. Good alternative. |

### Models That Do NOT Work

| Model | Issue |
|-------|-------|
| `llama3-groq-tool-use` | Outputs JSON text that looks like tool calls but doesn't actually invoke MCP tools |
| `llama3.2` | Same issue - describes tool calls in text but doesn't execute them |

### Known Limitations

- **Internal haiku requests fail**: Claude CLI internally calls `claude-haiku-4-5-20251001` for summarization/token counting. These show 404 errors but are non-fatal.
- **No streaming translation needed**: Ollama handles Anthropic streaming format natively.

### Agent Configuration

In the agent's `provider_config`, set:
```json
{
  "model": "qwen3-coder:30b",
  "base_url": "http://localhost:11434"
}
```

## AI Developer Agent Types

The following specialized agent types are available for the Task tool when running AI Developer jobs.
Each agent has a focused purpose and starts with fresh context.

### impl-agent (Implementation Specialist)

Use this agent to implement code changes for a ticket.

**Capabilities**: Read, Write, Edit, Bash, Glob, Grep
**Model**: sonnet (for complex code generation)

**When to use**: After requirements are understood, spawn this agent to:
- Explore the codebase and understand architecture
- Plan the implementation approach
- Write the code changes
- Commit and push to a feature branch

**Returns JSON**:
```json
{
  "success": true,
  "branch_name": "fix/ISSUE-123-description",
  "files_changed": ["path/to/file1.js", "path/to/file2.liquid"],
  "commit_sha": "abc123def",
  "summary": "Brief description of what was implemented"
}
```

### verify-agent (QA Specialist)

Use this agent to verify implementation with browser testing.

**Capabilities**: Read, Bash, browser automation (Playwright/Puppeteer)
**Model**: sonnet (needs vision for screenshot analysis)

**When to use**: After impl-agent completes, spawn this agent to:
- Navigate to preview URL
- Test specific acceptance criteria
- Capture screenshots as evidence
- Report pass/fail with detailed issues

**Returns JSON**:
```json
{
  "passed": true,
  "issues": [],
  "screenshots": ["proof-1.png", "proof-2.png"]
}
```
Or if issues found:
```json
{
  "passed": false,
  "issues": [
    {
      "severity": "critical",
      "description": "Loyalty points not showing",
      "location": "PLP product cards",
      "expected": "Show 'Earn X points'",
      "actual": "Shows '+ loyalty points'",
      "screenshot": "issue-plp.png",
      "fix_hint": "LoyaltyLion SDK not rescanning after dynamic load"
    }
  ]
}
```

### fix-agent (Bug Fix Specialist)

Use this agent to fix specific issues found during verification.

**Capabilities**: Read, Edit, Bash
**Model**: haiku (simple, targeted fixes from clear descriptions)

**When to use**: After verify-agent reports issues, spawn this agent with:
- Specific issue descriptions (not full history)
- Files to modify
- Fix hints from verification

**Returns JSON**:
```json
{
  "success": true,
  "files_modified": ["assets/loyalty.js"],
  "changes_summary": "Added 500ms delay for SDK initialization"
}
```

### Orchestrator Pattern

The main session acts as an orchestrator:

```
1. Parse ticket requirements
2. Task(impl-agent) → get files_changed
3. Task(verify-agent) → get issues
4. If issues: Task(fix-agent) → apply fixes
5. Loop verify→fix (max 3 iterations)
6. Create PR with results
```

**Benefits**:
- Each agent has fresh, focused context
- Failed attempts don't pollute future iterations
- Can use cheaper/faster models for simple tasks
- Easier debugging (isolated transcripts)

## Debugging

### ALWAYS Check Logs First

When debugging issues (404s, 500s, unexpected behavior), **check the application logs FIRST** before trying other diagnostic approaches:

```bash
# Check recent app logs for errors
tail -50 log/app*.log | grep -i "error\|exception\|fatal"

# Or search for specific controller/feature
tail -100 log/app*.log | grep -i "boards\|edit"
```

The logs will usually show the exact error (e.g., `Class "app\Bean" not found`) immediately, saving time compared to:
- SSH commands to remote servers
- Cache clearing attempts
- Permission/route debugging
- Database queries

**Log location**: `log/app-YYYY-MM-DD.log`

## Multi-Tenancy

The application supports session-based multi-workspace. Each workspace has:
- Their own config file: `conf/config.{workspace}.ini`
- Their own database (SQLite or MySQL)
- Their own data (members, boards, settings, etc.)

### Background Scripts

Background scripts must be passed the `--workspace` parameter to load the correct database:

```bash
# Run analysis for a specific workspace
php scripts/cron-analysis.php --script --secret=KEY --member=3 --board=1 --workspace=gwt

# Run digest cron (processes all workspaces automatically)
php scripts/cron-digest.php --script --verbose

# Run AI Developer for specific workspace
php scripts/ai-dev-agent.php --secret=KEY --member=3 --job=ID --action=process --workspace=gwt

# Run local AI Developer
php scripts/local-aidev-full.php --issue=SSI-1883 --workspace=gwt
```

### Web Requests

For web requests, the workspace is stored in `$_SESSION['workspace_slug']` after login.
The bootstrap reads this and switches to the workspace database automatically.

When spawning background scripts from controllers, include the workspace:

```php
$workspaceSlug = $_SESSION['workspace_slug'] ?? null;
$workspaceParam = $workspaceSlug && $workspaceSlug !== 'default'
    ? sprintf(' --workspace=%s', escapeshellarg($workspaceSlug))
    : '';

$cmd = sprintf(
    'php scripts/cron-analysis.php --script --secret=%s --member=%d --board=%d%s',
    escapeshellarg($cronSecret),
    $memberId,
    $boardId,
    $workspaceParam
);
```

### Webhooks

Jira webhooks now support workspace-specific URLs. Each workspace should register their
webhook in Jira with their workspace slug in the URL:

```
https://myctobot.ai/webhook/jira/gwt        # For workspace "gwt"
https://myctobot.ai/webhook/jira/testcorp   # For workspace "testcorp"
```

The webhook controller will:
1. Extract the workspace from the URL
2. Load the workspace's config file (`conf/config.{workspace}.ini`)
3. Switch to the workspace's database
4. Process the webhook with the correct workspace context

**To update an existing Jira webhook:**
1. Go to Jira → Settings → System → Webhooks
2. Edit the webhook URL to include your workspace slug
3. Change: `https://myctobot.ai/webhook/jira`
4. To: `https://myctobot.ai/webhook/jira/your-workspace-slug`

## Pipeline Step Types

Pipelines support the following step types:

| Step Type | Description | Key Config Options |
|-----------|-------------|-------------------|
| `direct_exec` | Run shell command | `command`, `executor`, `working_dir`, `workstation_id` |
| `script` | Run repository script | `repo_id`, `script_path`, `args` |
| `ai_agent` | Call Claude API | `prompt`, `system_prompt`, `model`, `max_tokens` |
| `webhook_out` | POST to external URL | `url`, `method`, `headers`, `body` |
| `email_out` | Send email via Mailgun | `to`, `subject`, `body`, `template` |
| `parser` | Transform data (jq/regex) | `parser_type`, `expression` |
| `wait` | Blocking delay | `wait_type`, `duration` |
| `harvest` | Gather parallel results | `policy`, `on_incomplete`, `template` |
| `mcp_call` | Call external MCP tool | `transport`, `url`, `tool`, `arguments` |
| `schedule_task` | Non-blocking scheduled action | `task_type`, `delay_seconds`, `payload` |
| `shopify_graphql` | Shopify Admin GraphQL | `connection_id`, `query`, `variables` |

### Shopify GraphQL Step

Execute GraphQL queries against connected Shopify stores:

```json
{
  "step_type": "shopify_graphql",
  "config_json": {
    "connection_id": "{context.shop_connection_id}",
    "query": "query getProducts($first: Int!) { products(first: $first) { edges { node { id title handle priceRange { minVariantPrice { amount } } } } } }",
    "variables": { "first": 10 }
  }
}
```

**Config Options:**
- `connection_id` - ID from `shopifyconnections` table (supports variable substitution)
- `query` - GraphQL query string
- `variables` - Variables object for the query (values support substitution)

**Output:**
```json
{
  "success": true,
  "output": {
    "data": { "products": { "edges": [...] } },
    "extensions": { "cost": {...} },
    "shop": "mystore.myshopify.com"
  }
}
```

### Variable Substitution in Steps

All step configs support variable substitution:
- `{context.key}` - Access context variables
- `{step_name.output.key}` - Access previous step output (nested with dots)
- `{step_name.stdout}` - Get raw stdout from step

## Shopify Integration

Shopify connections are managed via `/shopify` UI. Credentials stored encrypted in `shopifyconnections` table.

### ShopifyClient Service

```php
use app\services\ShopifyClient;

// Load by connection ID (preferred)
$client = new ShopifyClient($connectionId);

// Check connection
if (!$client->isConnected()) {
    throw new Exception('Not connected');
}

// Execute GraphQL query
$result = $client->graphql($query, $variables);
if ($result['success']) {
    $data = $result['data'];
}

// Theme operations
$themes = $client->getThemes();
$devTheme = $client->getOrCreateDevTheme('SSI-1234', 'Fix header');
$client->updateThemeAsset($themeId, 'assets/custom.css', $cssContent);
```

### Shopify GraphQL Examples

**Get products:**
```graphql
query getProducts($first: Int!) {
  products(first: $first) {
    edges {
      node {
        id
        title
        handle
        status
        totalInventory
        priceRange {
          minVariantPrice { amount currencyCode }
        }
      }
    }
  }
}
```

**Get orders:**
```graphql
query getOrders($first: Int!) {
  orders(first: $first) {
    edges {
      node {
        id
        name
        totalPriceSet { shopMoney { amount } }
        customer { displayName email }
      }
    }
  }
}
```

**Update product:**
```graphql
mutation updateProduct($input: ProductInput!) {
  productUpdate(input: $input) {
    product { id title }
    userErrors { field message }
  }
}
```

## See Also

- `REDBEAN_README.md` - Detailed RedBeanPHP reference
- `FLIGHTPHP_README.md` - Detailed FlightPHP reference
- https://redbeanphp.com/ - Official RedBeanPHP documentation
- `docs/AGENT_ARCHITECTURE.md` - Full agent architecture documentation
- https://shopify.dev/docs/api/admin-graphql - Shopify Admin GraphQL API
