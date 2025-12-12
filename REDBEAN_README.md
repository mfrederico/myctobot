# RedBeanPHP Quick Reference for Tiknix

> **Official Documentation**: https://redbeanphp.com/
> **Quick Tour**: https://redbeanphp.com/index.php?p=/quick_tour
> **CRUD Reference**: https://redbeanphp.com/index.php?p=/crud

RedBeanPHP is a zero-config ORM. Tables and columns are created automatically when you use them.

## Core Concept

"Beans" are objects that map to database rows. The table name is the bean type.

```php
use \RedBeanPHP\R as R;
```

## CRUD Operations

### Create
```php
$user = R::dispense('member');  // Creates bean (and table if needed)
$user->email = 'test@example.com';
$user->username = 'testuser';
$user->level = 100;
$id = R::store($user);          // Save and get ID
```

### Read
```php
// By ID
$user = R::load('member', 1);

// Single row with condition
$user = R::findOne('member', 'email = ?', ['test@example.com']);

// Multiple rows
$users = R::findAll('member', 'status = ? ORDER BY created_at DESC', ['active']);

// With LIMIT
$users = R::findAll('member', 'level = ? LIMIT 10', [100]);
```

### Update
```php
$user = R::load('member', 1);
$user->username = 'newname';
R::store($user);
```

### Delete
```php
$user = R::load('member', 1);
R::trash($user);

// Or by ID
R::trash('member', 1);
```

## Query Methods

| Method | Returns | Use Case |
|--------|---------|----------|
| `R::load($type, $id)` | Single bean | Get by ID |
| `R::findOne($type, $sql, $params)` | Single bean or NULL | Get first match |
| `R::find($type, $sql, $params)` | Array of beans | Get matching rows |
| `R::findAll($type, $sql, $params)` | Array of beans | Same as find |
| `R::count($type, $sql, $params)` | Integer | Count rows |
| `R::exec($sql, $params)` | Affected rows | Raw UPDATE/DELETE |
| `R::getAll($sql, $params)` | Array of arrays | Raw SELECT |

## Parameter Binding

Always use `?` placeholders - never concatenate values:

```php
// CORRECT - Safe from SQL injection
$user = R::findOne('member', 'email = ? AND status = ?', [$email, 'active']);

// WRONG - SQL injection risk
$user = R::findOne('member', "email = '$email'");  // NEVER DO THIS
```

## Checking Results

```php
// Load returns empty bean if not found (id = 0)
$user = R::load('member', 999);
if (!$user->id) {
    // Not found
}

// FindOne returns NULL if not found
$user = R::findOne('member', 'email = ?', ['notfound@test.com']);
if (!$user) {
    // Not found
}
```

## Bean Properties

Beans are dynamic - any property you set becomes a column:

```php
$user = R::dispense('member');
$user->email = 'test@test.com';     // VARCHAR
$user->age = 25;                     // INTEGER
$user->balance = 99.50;              // DOUBLE
$user->bio = 'Long text here...';   // TEXT (if > 255 chars)
$user->created_at = date('Y-m-d H:i:s');  // DATETIME
R::store($user);
```

## Export to Array

```php
$user = R::load('member', 1);
$array = $user->export();  // Convert to associative array
$_SESSION['member'] = $user->export();
```

## Counting

```php
$total = R::count('member');
$active = R::count('member', 'status = ?', ['active']);
$admins = R::count('member', 'level <= ?', [50]);
```

## Raw SQL (when needed)

```php
// SELECT - returns array of arrays
$rows = R::getAll('SELECT m.*, COUNT(o.id) as order_count
                   FROM member m
                   LEFT JOIN orders o ON o.member_id = m.id
                   GROUP BY m.id');

// UPDATE/DELETE - returns affected row count
$affected = R::exec('UPDATE member SET login_count = login_count + 1 WHERE id = ?', [$id]);
```

## Common Patterns in Tiknix

### Find or Create
```php
$member = R::findOne('member', 'email = ?', [$email]);
if (!$member) {
    $member = R::dispense('member');
    $member->email = $email;
    $member->created_at = date('Y-m-d H:i:s');
}
$member->last_login = date('Y-m-d H:i:s');
R::store($member);
```

### Pagination
```php
$page = (int)($_GET['page'] ?? 1);
$perPage = 20;
$offset = ($page - 1) * $perPage;

$total = R::count('member', 'status = ?', ['active']);
$members = R::findAll('member', 'status = ? ORDER BY created_at DESC LIMIT ? OFFSET ?',
                       ['active', $perPage, $offset]);
```

### Update Single Field
```php
R::exec('UPDATE member SET login_count = login_count + 1 WHERE id = ?', [$id]);
```

## Important Notes

1. **Table names are lowercase** - `R::dispense('member')` not `R::dispense('Member')`
2. **Column names are lowercase** - `$bean->created_at` not `$bean->createdAt`
3. **ID is automatic** - Every table gets an `id` INT AUTO_INCREMENT PRIMARY KEY
4. **Freeze in production** - Call `R::freeze(true)` to prevent auto-schema changes

## Tiknix Database Setup

Database is configured in `conf/config.ini` and initialized in `public/index.php`:

```php
// SQLite
R::setup('sqlite:database/tiknix.db');

// MySQL
R::setup('mysql:host=localhost;dbname=tiknix', 'user', 'pass');
```

## APCu Query Cache (Tiknix Feature)

Tiknix includes a transparent query cache that stores SELECT results in APCu shared memory. This provides ~9x performance improvement for repeated queries.

### How It Works

- SELECT queries are automatically cached based on query + parameters
- Cache is automatically invalidated when tables are modified (INSERT/UPDATE/DELETE)
- No code changes required - it's transparent

### Configuration (conf/config.ini)

```ini
[cache]
query_cache = true      ; Enable/disable query caching
query_cache_ttl = 60    ; Cache lifetime in seconds
```

### Manual Cache Control

```php
// Get the cached database adapter
$db = R::getDatabaseAdapter();

// Clear cache for a specific table
$db->invalidateTable('member');

// Clear all query cache
$db->clearCache();

// Get cache statistics
$stats = $db->getCacheStats();
// Returns: hits, misses, hit_rate, cached_queries, etc.
```

### Cache Invalidation

The cache automatically invalidates when:
- `R::store($bean)` is called (invalidates that table)
- `R::trash($bean)` is called (invalidates that table)
- `R::exec()` runs INSERT/UPDATE/DELETE

### Requirements

- APCu PHP extension (`apt-get install php-apcu`)
- `apc.enabled=1` in php.ini

### Performance Tips

```ini
; High-traffic sites (longer cache)
query_cache_ttl = 300   ; 5 minutes

; Real-time data needs (shorter cache)
query_cache_ttl = 10    ; 10 seconds

; Disable for debugging
query_cache = false
```

## Further Reading

- Official RedBeanPHP: https://redbeanphp.com/
- Query caching details: See `docs/CACHING.md` in this project
