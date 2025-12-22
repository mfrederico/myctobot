# Tiknix Development Standards

This project uses FlightPHP and RedBeanPHP. You MUST follow these conventions strictly.

## RedBeanPHP Rules (CRITICAL)

> **Official Documentation**: https://redbeanphp.com/
> Always refer to the official docs for the most accurate information.

### Bean Wrapper Class (REQUIRED for User Database)

**For user database operations, use the `Bean` wrapper class instead of direct R:: calls.**

The Bean class (`lib/Bean.php`) normalizes bean type names to all lowercase, which is required
by RedBeanPHP's R::dispense(). It accepts camelCase, snake_case, or lowercase and converts them.

```php
use \app\Bean;

// User database operations - use Bean::
$setting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['api_key']);
$job = Bean::dispense('aidevjobs');
Bean::store($job);
Bean::trash($job);

// Default database operations - R:: is still fine
$member = R::load('member', $memberId);
$tokens = R::find('atlassiantoken', 'cloud_id = ?', [$cloudId]);
```

**User database table names (all lowercase, no underscores):**
| Bean Type | Table Name |
|-----------|------------|
| `aidevjobs` | aidevjobs |
| `aidevjoblogs` | aidevjoblogs |
| `enterprisesettings` | enterprisesettings |
| `repoconnections` | repoconnections |
| `boardrepomapping` | boardrepomapping |
| `jiraboards` | jiraboards |

### Naming Conventions (IMPORTANT)

For **default database** tables (member, atlassiantoken, etc.), RedBeanPHP automatically
converts camelCase in PHP to underscore_case in the database.

For **user database** tables, always use all lowercase with no underscores (via Bean::).

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
- `jiraboards_id` in analysisresults (result belongs to board)
- `repoconnections_id` in boardrepomapping (mapping belongs to repo)

**Note:** For lowercase table names, the FK is also lowercase:
- `jiraboards` → `jiraboards_id`
- `aidevjobs` → `aidevjobs_id`

### Why Associations Over Manual FKs

```php
// BAD - Manual FK management
$result = Bean::dispense('analysisresults');
$result->board_id = $boardId;  // Manual FK assignment
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
// CORRECT - User database (use Bean::)
$job = Bean::dispense('aidevjobs');
$job->issue_key = 'PROJ-123';
$job->status = 'pending';
Bean::store($job);

$setting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['api_key']);
Bean::trash($setting);

// CORRECT - Default database (use R::)
$member = R::load('member', $id);
$member->lastLogin = date('Y-m-d H:i:s');
R::store($member);

// WRONG - NEVER use exec for simple CRUD
R::exec('INSERT INTO member (email) VALUES (?)', [$email]);  // WRONG!
Bean::exec('UPDATE aidevjobs SET status = ?', ['done']);     // WRONG!
```

**The ONLY acceptable uses for R::exec/Bean::exec:**
```php
// DDL (schema creation) - OK
R::exec('CREATE TABLE IF NOT EXISTS mytable (...)');

// Complex atomic operation that can't be done with beans - OK sparingly
R::exec('UPDATE member SET loginCount = loginCount + 1 WHERE id = ?', [$id]);
```

**If you think you need R::exec, ask yourself:**
1. Can this be done with R::load + R::store? → Use that instead
2. Can this be done with R::find + loop + R::store? → Use that instead
3. Is this a complex aggregate/batch that truly can't use beans? → Only then use R::exec

### Why Bean Operations Are Mandatory

RedBeanPHP models (FUSE) ONLY work with bean operations. Using R::exec bypasses:
- Model hooks (`update()`, `afterUpdate()`, `delete()`, etc.)
- Model validation
- Business logic in models
- The entire point of using an ORM

If you use R::exec for simple CRUD, the ORM becomes useless and models are ignored.

### Query Methods Reference

**For user database tables, use Bean:: methods (same API as R::):**

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

**For default database tables, use R:: methods:**

| Method | Returns | Use Case |
|--------|---------|----------|
| `R::load($type, $id)` | Single bean (empty if not found) | Get by ID |
| `R::findOne($type, $sql, $params)` | Single bean or NULL | Get first match |
| `R::find($type, $sql, $params)` | Array of beans | Get matching rows |
| `R::getAll($sql, $params)` | Array of arrays | Complex SELECT with joins |

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

## File Structure

```
/controls       - Controllers (auto-routed by URL)
/views          - PHP view templates
/lib            - Core libraries
/models         - RedBeanPHP FUSE models
/routes         - Custom route definitions
/conf           - Configuration files
```

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

## See Also

- `REDBEAN_README.md` - Detailed RedBeanPHP reference
- `FLIGHTPHP_README.md` - Detailed FlightPHP reference
- https://redbeanphp.com/ - Official RedBeanPHP documentation
- `docs/AGENT_ARCHITECTURE.md` - Full agent architecture documentation
