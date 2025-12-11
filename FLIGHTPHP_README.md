# FlightPHP Quick Reference for Tiknix

> **Official Documentation**: https://docs.flightphp.com/
> **GitHub**: https://github.com/flightphp/core
> **Learn Guide**: https://docs.flightphp.com/learn

FlightPHP is an extensible micro-framework. Tiknix extends it with auto-routing, permissions, and helper methods.

## Core Concept

Flight uses static methods for everything. Routes map URLs to callbacks or controllers.

```php
use \Flight as Flight;
```

## Auto-Routing in Tiknix

Tiknix uses convention-based routing. URLs automatically map to controllers:

```
URL Pattern                    → Controller Method
─────────────────────────────────────────────────
/                              → Index::index()
/auth/login                    → Auth::login()
/member/profile                → Member::profile()
/admin/users/edit/5            → Admin::users($params)
                                 $params['operation']->name = 'edit'
                                 $params['operation']->type = '5'
```

**No route configuration needed** - just create a controller in `/controls/`.

## Creating a Controller

```php
// controls/Products.php
namespace app;

class Products extends BaseControls\Control {

    // GET /products
    public function index() {
        $products = R::findAll('product');
        $this->render('products/index', ['products' => $products]);
    }

    // GET /products/view/5
    public function view($params) {
        $id = $params['operation']->name;
        $product = R::load('product', $id);
        $this->render('products/view', ['product' => $product]);
    }

    // POST /products/save
    public function save() {
        $product = R::dispense('product');
        $product->name = $this->getParam('name');
        R::store($product);
        $this->flash('success', 'Product saved');
        Flight::redirect('/products');
    }
}
```

## Tiknix Custom Flight Methods

These are mapped in `lib/FlightMap.php`:

### Authentication & Authorization

```php
// Check if user is logged in
if (Flight::isLoggedIn()) {
    // User is authenticated
}

// Get current member (returns guest object if not logged in)
$member = Flight::getMember();
echo $member->id;        // 0 for guest
echo $member->level;     // 101 for guest (PUBLIC)
echo $member->username;  // 'Guest' for guest

// Check permission level (lower = more privileged)
if (Flight::hasLevel(LEVELS['ADMIN'])) {  // level <= 50
    // User is admin or higher
}

// Check specific permission
if (Flight::permissionFor('admin', 'users', $member->level)) {
    // User can access admin/users
}
```

### Permission Levels

```php
LEVELS['ROOT']   = 1    // Super admin
LEVELS['ADMIN']  = 50   // Administrator
LEVELS['MEMBER'] = 100  // Regular user
LEVELS['PUBLIC'] = 101  // Not logged in
```

### Rendering Views

```php
// Standard render (from controller)
$this->render('products/index', ['products' => $products]);

// Flight render with common data (member, isLoggedIn, csrf, etc.)
Flight::renderView('products/index', ['products' => $products]);

// Raw Flight render (no common data added)
Flight::render('products/index', ['products' => $products]);
```

### JSON Responses

```php
// Success response
Flight::jsonSuccess(['id' => 123], 'Product created');
// Returns: {"success": true, "message": "Product created", "data": {"id": 123}}

// Error response
Flight::jsonError('Invalid input', 400);
// Returns: {"success": false, "message": "Invalid input"} with HTTP 400
```

### Redirects

```php
Flight::redirect('/dashboard');
Flight::redirect('/auth/login?redirect=' . urlencode(Flight::request()->url));
```

### Request Data

```php
// Get request object
$request = Flight::request();

// POST/PUT data
$name = $request->data->name;
$email = $request->data->email;

// Query string (?foo=bar)
$page = $request->query->page;

// Current URL
$url = $request->url;

// HTTP method
$method = $request->method;  // GET, POST, PUT, DELETE
```

### Settings (Per-User Key-Value Store)

```php
// Get setting for current user
$theme = Flight::getSetting('theme');

// Set setting for current user
Flight::setSetting('theme', 'dark');

// Get/set for specific user
$theme = Flight::getSetting('theme', $memberId);
Flight::setSetting('theme', 'dark', $memberId);
```

### CSRF Protection

```php
// In controller - validate CSRF
if (!$this->validateCSRF()) {
    $this->flash('error', 'Invalid request');
    return;
}

// Get CSRF token for forms (auto-included in renderView)
$csrf = Flight::csrf()->getTokenArray();

// In view - add to form
<?= $csrf['input'] ?>
```

### Logging

```php
Flight::get('log')->debug('Debug message', ['context' => 'data']);
Flight::get('log')->info('Info message');
Flight::get('log')->warning('Warning message');
Flight::get('log')->error('Error message');
```

### Configuration Values

```php
// Get config value (from conf/config.ini)
$debug = Flight::get('debug');
$baseurl = Flight::get('baseurl');
$appName = Flight::get('app.name');
```

## BaseControls\Control Helper Methods

When extending `BaseControls\Control`, you get these helpers:

```php
class MyController extends BaseControls\Control {

    public function example() {
        // Get request parameter (POST, GET, or route)
        $id = $this->getParam('id');

        // Sanitize input
        $name = $this->sanitize($this->getParam('name'));
        $email = $this->sanitize($this->getParam('email'), 'email');

        // Flash messages (shown on next page load)
        $this->flash('success', 'Operation completed');
        $this->flash('error', 'Something went wrong');
        $this->flash('warning', 'Please check your input');

        // Render view
        $this->render('my/template', ['data' => $data]);

        // Access current member
        $this->member->id;
        $this->member->email;

        // Access logger
        $this->logger->info('Something happened');

        // Validate CSRF
        if (!$this->validateCSRF()) {
            return;
        }
    }
}
```

## Views

Views are PHP files in `/views/`. Use the variables passed to them:

```php
<!-- views/products/index.php -->
<h1>Products</h1>

<?php foreach ($products as $product): ?>
    <div class="product">
        <h2><?= htmlspecialchars($product->name) ?></h2>
        <p><?= htmlspecialchars($product->description) ?></p>
        <a href="/products/view/<?= $product->id ?>">View</a>
    </div>
<?php endforeach; ?>

<!-- Common variables available in all views (via renderView): -->
<!-- $member - Current user object -->
<!-- $isLoggedIn - Boolean -->
<!-- $levels - Permission levels array -->
<!-- $baseurl - Site base URL -->
<!-- $csrf - CSRF token array with $csrf['input'] for forms -->
```

## Adding Custom Routes

For special routes outside auto-routing, add to `routes/default.php`:

```php
// API endpoint with specific method
Flight::route('GET /api/products', function() {
    $products = R::findAll('product');
    Flight::json($products);
});

// Route with parameters
Flight::route('GET /api/products/@id', function($id) {
    $product = R::load('product', $id);
    Flight::json($product->export());
});

// Multiple methods
Flight::route('GET|POST /api/search', function() {
    // Handle both GET and POST
});
```

## Error Handling

Custom error handlers are in `lib/FlightMap.php`:

```php
// 404 - renders views/error/404.php
Flight::notFound();

// 500 - renders views/error/500.php (with stack trace in debug mode)
// Automatically triggered on exceptions
```

## Middleware Pattern

Use Flight's `before` filter for middleware:

```php
// In routes/default.php or bootstrap
Flight::before('start', function() {
    // Runs before every request
    // Check maintenance mode, log requests, etc.
});
```

## File Structure

```
/controls           Controllers (auto-routed)
/views              PHP view templates
/views/layouts      Header, footer, base templates
/lib                Core libraries and Flight extensions
/lib/FlightMap.php  Custom Flight methods
/routes             Custom route definitions
/conf               Configuration files
```

## Quick Patterns

### Create a simple page
```php
// controls/About.php
class About extends BaseControls\Control {
    public function index() {
        $this->render('about/index', ['title' => 'About Us']);
    }
}
// Access at: /about
```

### Form handling
```php
public function save() {
    if (!$this->validateCSRF()) return;

    $name = $this->sanitize($this->getParam('name'));
    if (empty($name)) {
        $this->flash('error', 'Name is required');
        Flight::redirect('/form');
        return;
    }

    $item = R::dispense('item');
    $item->name = $name;
    R::store($item);

    $this->flash('success', 'Saved!');
    Flight::redirect('/items');
}
```

### AJAX endpoint
```php
public function api() {
    $data = R::findAll('item', 'ORDER BY created_at DESC LIMIT 10');
    Flight::jsonSuccess(array_map(fn($i) => $i->export(), $data));
}
```

## Further Reading

- Official FlightPHP docs: https://docs.flightphp.com/
- Routing: https://docs.flightphp.com/learn/routing
- Middleware: https://docs.flightphp.com/learn/middleware
- Tiknix permission system: See `lib/PermissionCache.php`
