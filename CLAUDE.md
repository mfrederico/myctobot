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

### Relations (One-to-Many)

Use `own[BeanType]List` for one-to-many relationships:

```php
// Parent has many children
$shop = R::dispense('shop');
$shop->name = 'My Shop';

$product = R::dispense('product');
$product->name = 'Vase';

// Add product to shop (creates shop_id foreign key in product table)
$shop->ownProductList[] = $product;
R::store($shop);

// Retrieve children
$products = $shop->ownProductList;

// Use xownProductList for CASCADE DELETE (deletes children when parent deleted)
$shop->xownProductList[] = $product;
```

### Relations (Many-to-Many)

Use `shared[BeanType]List` for many-to-many relationships:

```php
// Products can have many tags, tags can have many products
$product = R::dispense('product');
$product->name = 'Widget';

$tag = R::dispense('tag');
$tag->name = 'Featured';

// Add tag to product (creates product_tag link table automatically)
$product->sharedTagList[] = $tag;
R::store($product);

// Retrieve related beans
$tags = $product->sharedTagList;
$products = $tag->sharedProductList;
```

### Foreign Key Naming

Foreign keys are automatically named `[parent_type]_id`:
- `shop_id` in product table (product belongs to shop)
- `member_id` in order table (order belongs to member)

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

## See Also

- `REDBEAN_README.md` - Detailed RedBeanPHP reference
- `FLIGHTPHP_README.md` - Detailed FlightPHP reference
- https://redbeanphp.com/ - Official RedBeanPHP documentation
