# Tiknix Development Standards

This project uses FlightPHP and RedBeanPHP. You MUST follow these conventions strictly.

## RedBeanPHP Rules (CRITICAL)

> **Official Documentation**: https://redbeanphp.com/
> Always refer to the official docs for the most accurate information.

### Naming Conventions (IMPORTANT)

RedBeanPHP automatically converts camelCase in PHP to underscore_case in the database.

**In PHP code, use camelCase:**
```php
// Table names - use camelCase (NO underscores)
$bean = R::dispense('orderItem');     // Creates table: order_item
$bean = R::dispense('userProfile');   // Creates table: user_profile
$bean = R::dispense('member');        // Creates table: member

// Column names - use camelCase
$bean->firstName = 'John';            // Creates column: first_name
$bean->createdAt = date('Y-m-d');     // Creates column: created_at
$bean->userId = 5;                    // Creates column: user_id
```

**WRONG - Don't use underscore_case in PHP:**
```php
// WRONG - Don't use underscores in PHP bean code
$bean = R::dispense('order_item');    // WRONG!
$bean->first_name = 'John';           // WRONG!
$bean->created_at = date('Y-m-d');    // WRONG!
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

**ALWAYS use bean operations for CRUD. R::exec should ONLY be used in extreme situations where there is no other way to get the data.**

```php
// CORRECT - Use beans for create
$member = R::dispense('member');
$member->email = 'test@example.com';
$member->createdAt = date('Y-m-d H:i:s');
R::store($member);

// CORRECT - Use R::load for updates
$member = R::load('member', $id);
$member->lastLogin = date('Y-m-d H:i:s');
R::store($member);

// CORRECT - Use R::findOne for lookups
$member = R::findOne('member', 'email = ?', [$email]);

// CORRECT - Use R::trash for deletes
$member = R::load('member', $id);
R::trash($member);
// Or: R::trash('member', $id);

// WRONG - NEVER use R::exec for simple CRUD
R::exec('INSERT INTO member (email) VALUES (?)', [$email]);  // WRONG!
R::exec('UPDATE member SET email = ? WHERE id = ?', [$email, $id]);  // WRONG!
R::exec('DELETE FROM member WHERE id = ?', [$id]);  // WRONG!
```

**The ONLY acceptable uses for R::exec:**
```php
// Complex atomic operation that can't be done with beans
R::exec('UPDATE member SET loginCount = loginCount + 1 WHERE id = ?', [$id]);

// Bulk operations on many records with complex conditions
R::exec('DELETE FROM session WHERE expiresAt < NOW() AND memberId IN (SELECT id FROM member WHERE isDeleted = 1)');
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

| Method | Returns | Use Case |
|--------|---------|----------|
| `R::load($type, $id)` | Single bean (empty if not found) | Get by ID |
| `R::findOne($type, $sql, $params)` | Single bean or NULL | Get first match |
| `R::find($type, $sql, $params)` | Array of beans | Get matching rows |
| `R::findAll($type, $sql, $params)` | Array of beans | Same as find |
| `R::count($type, $sql, $params)` | Integer | Count rows |
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

## See Also

- `REDBEAN_README.md` - Detailed RedBeanPHP reference
- `FLIGHTPHP_README.md` - Detailed FlightPHP reference
- https://redbeanphp.com/ - Official RedBeanPHP documentation
