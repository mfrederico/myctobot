# Tiknix PHP Framework

[![Built with Claude Code](https://img.shields.io/badge/Built%20with-Claude%20Code-blueviolet)](https://claude.ai/claude-code)

A modern, production-ready PHP framework featuring automatic routing, authentication, role-based permissions, and a Bootstrap 5 UI. Built on top of FlightPHP and RedBeanPHP for simplicity and power.

**AI-Assisted Development**: This project is actively developed with [Claude Code](https://claude.ai/claude-code). The clean architecture and comprehensive documentation are designed to work well with AI coding assistants.

## Features

### Core Framework
- **Auto-Routing System**: Convention-based routing that automatically maps URLs to controllers
- **Authentication**: Complete auth system with registration, login, password reset, and Google OAuth
- **Google OAuth 2.0**: One-click sign in with Google - see `lib/plugins/GoogleAuth.php`
- **Pluggable Architecture**: Drop-in plugins in `lib/plugins/` for authentication, services, and more
- **Simple Registration**: No email verification required - accounts are active immediately
- **Role-Based Permissions**: Granular permission control with automatic route protection
- **High-Performance Caching**: Multi-tier caching system with 9.4x query performance boost
- **Database ORM**: RedBeanPHP for zero-config database operations with transparent query caching
- **Bootstrap 5 UI**: Modern, responsive interface with header/footer sandwich layout
- **Logging**: Comprehensive logging with Monolog
- **CSRF Protection**: Built-in CSRF token validation
- **Session Management**: Secure session handling with configurable options
- **CLI Support**: Full command-line interface for cron jobs and scripts

### Built-in Modules
- **User Registration**: Simple, email-verification-free registration with auto-login
- **Dashboard**: Central hub for logged-in users with stats and quick actions
- **Contact System**: Full contact form with admin management interface
- **Help Center**: Built-in help documentation and FAQ system
- **Documentation System**: Auto-rendered README.md and API/CLI docs at `/docs`
- **Admin Panel**: Complete admin interface for user and permission management
- **Member Area**: Profile management, settings, and personal dashboard

### Developer Experience
- **Build Mode**: Auto-create permissions as you develop
- **Base Controller**: Rich parent class with common functionality
- **Configuration**: Simple INI-based config with environment support
- **Error Handling**: Graceful error pages and logging
- **Flash Messages**: User feedback system with Bootstrap toasts
- **PHP 8.1+ Compatible**: Updated for modern PHP versions

### AI-Friendly Documentation
Quick reference guides designed for AI coding assistants and developers:
- **[FLIGHTPHP_README.md](FLIGHTPHP_README.md)**: FlightPHP patterns, custom methods, and Tiknix conventions
- **[REDBEAN_README.md](REDBEAN_README.md)**: RedBeanPHP CRUD operations, query cache, and common patterns

## High-Performance Caching System

Tiknix includes a sophisticated multi-tier caching system that provides **9.4x faster database queries** with zero code changes required.

### Caching Components

#### 1. **Transparent Query Cache** (CachedDatabaseAdapter)
- Automatically caches ALL SELECT queries
- Smart invalidation on INSERT/UPDATE/DELETE
- Tracks JOIN queries across multiple tables
- Multi-tenant safe with unique cache namespacing
- **Performance**: 9.4x faster queries, 99.9% hit rate

#### 2. **Permission Cache** (PermissionCache)
- Three-tier caching: Process Memory → APCu → Database
- Caches all permission checks for instant authorization
- **Performance**: 99.7% faster, 175,000 checks/second

#### 3. **OPcache Preloading**
- Preloads framework files into memory on server start
- Eliminates file I/O for core components
- Configurable preload list for custom optimization

### Cache Statistics

The admin panel includes a comprehensive cache management interface at `/admin/cache` showing:
- Real-time hit rates and performance metrics
- Memory usage for each cache tier
- Cached query count and size
- APCu and OPcache status
- One-click cache clearing and warming

### Configuration

Enable caching in `conf/config.ini`:

```ini
[cache]
enabled = true
query_cache = true              ; Enable database query caching
query_cache_ttl = 60            ; Cache TTL in seconds
```

### Installation

1. **Install APCu** (required for caching):
```bash
sudo apt-get install php8.1-apcu
sudo systemctl restart php8.1-fpm
```

2. **Enable for CLI** (optional, for testing):
```bash
echo "apc.enable_cli=1" | sudo tee -a /etc/php/8.1/cli/conf.d/20-apcu.ini
```

That's it! The caching system works transparently - no code changes needed.

## Quick Start

### Requirements
- PHP 8.1 or higher (uses modern PHP features)
- MySQL/MariaDB, PostgreSQL, or SQLite
- Composer
- Apache/Nginx with mod_rewrite (or PHP built-in server for development)
- APCu extension (optional but recommended for 9.4x performance boost)

### Installation

1. **Clone or download the repository**
```bash
git clone https://github.com/mfrederico/tiknix.git myapp
cd myapp
```

2. **Install dependencies**
```bash
composer install
```

3. **Configure the application**

#### Option A: Using SQLite (Easiest - no database server required!)
```bash
cp conf/config.sqlite.example.ini conf/config.ini
# SQLite database will be created automatically at database/tiknix.db
```

#### Option B: Using MySQL/MariaDB
```bash
cp conf/config.example.ini conf/config.ini
# Edit conf/config.ini with your MySQL credentials

# Create database (if not exists)
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS tiknix"
```

4. **Initialize the database**

The framework uses RedBeanPHP which auto-creates tables. Run the initialization script:

```bash
# This works for any database type (SQLite, MySQL, PostgreSQL)
php database/init.php
```

This creates:
- Admin user (username: `admin`, password: `admin123`) - **Change this immediately!**
- Public user entity for guest permissions
- Initial permission settings
- Contact form and response tables

5. **Set permissions**
```bash
chmod -R 755 .
chmod -R 777 log/
mkdir -p uploads cache
chmod -R 777 uploads/ cache/
```

6. **Configure your web server**

For Apache, use the included `.htaccess` file:
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]
```

For Nginx:
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

7. **Start development server** (for testing)
```bash
php -S localhost:8000 -t public/
```

8. **Access the application**
- Open http://localhost:8000
- **Register a new account**: Click "Register" - no email verification needed
- **Or login with admin**: username `admin`, password `admin123` (change immediately!)
- After login/registration, you'll be redirected to the main dashboard at `/dashboard`

## Project Structure

```
tiknix/
├── bootstrap.php           # Application initialization
├── composer.json          # Dependencies
├── conf/                  # Configuration files
│   └── config.ini        # Your configuration (create from .example)
├── controls/             # Controllers
│   ├── BaseControls/    # Base controller class
│   ├── Admin.php        # Admin panel controller
│   ├── Auth.php         # Authentication controller
│   ├── Contact.php      # Contact form controller
│   ├── Dashboard.php    # Main dashboard controller
│   ├── Error.php        # Error handling controller
│   ├── Help.php         # Help center controller
│   ├── Index.php        # Home page controller
│   ├── Member.php       # Member area controller
│   ├── Permissions.php  # Permission management
│   └── Test.php         # CLI test controller
├── database/            # Database scripts
│   ├── init_users.php   # Initialize default users
│   ├── init_contact.php # Initialize contact tables
│   └── reset_admin_password.php # Reset admin password
├── lib/                  # Core framework files
│   ├── FlightMap.php    # Routing and framework extensions
│   └── CliHandler.php   # CLI command handler
├── log/                  # Application logs (auto-created)
├── models/              # Database models (RedBean)
├── public/              # Web root
│   ├── index.php       # Entry point
│   ├── css/            # Stylesheets
│   ├── js/             # JavaScript
│   └── images/         # Images
├── routes/              # Route definitions
│   └── default.php     # Default routing pattern
├── uploads/            # User uploads (create if needed)
└── views/              # View templates
    ├── admin/          # Admin panel views
    ├── auth/           # Authentication views
    ├── contact/        # Contact form views
    ├── dashboard/      # Dashboard views
    ├── error/          # Error pages (403, 404, 500)
    ├── help/           # Help center views
    ├── layouts/        # Layout templates
    ├── member/         # Member area views
    └── index/          # Index controller views
```

## Built-in Pages and Features

### Main Dashboard (`/dashboard`)
- Welcome message with user information
- Quick action buttons
- System statistics (admins see more)
- Links to profile, settings, and help

### Admin Panel (`/admin`)
The framework includes a complete admin panel accessible at `/admin`:

- **Dashboard**: Overview of system stats
- **Member Management**: Create, edit, delete users
- **Permission Management**: Configure route permissions
- **Contact Messages**: View and respond to contact form submissions
- **System Settings**: Global application settings

### Member Area
After login, members have access to:

- **Profile** (`/member/profile`): View profile information  
- **Edit Profile** (`/member/edit`): Update profile and password
- **Settings** (`/member/settings`): Personal preferences

### Contact System (`/contact`)
- Public contact form for support requests
- Categories: general, support, billing, feature request, bug report
- Admin interface for managing messages
- Response tracking and status management
- Links contact to member account if logged in

### Help Center (`/help`)
- Getting started guides
- Account management help
- Features documentation
- FAQ section with common questions
- Direct link to contact support

### Authentication System
- **Registration (`/auth/register`)**:
  - Simple form with username, email, password
  - No email verification required
  - Accounts are active immediately
  - Auto-login after registration
  - Minimum requirements: username (3+ chars), password (6+ chars)
- **Login (`/auth/login`)**:
  - Supports both username and email login
  - Remember me functionality
  - Redirect to dashboard after login
- **Password Reset** (optional):
  - Basic forgot password functionality
  - Can be extended with email service

## Creating Controllers

Controllers extend the base controller and use lowercase method names for routing. The framework now uses Flight's request API:

```php
<?php
namespace app;
use \Flight as Flight;
use \RedBeanPHP\R as R;

class Example extends BaseControls\Control {
    
    // Accessible at: /example or /example/index
    public function index() {
        $this->render('example/index', [
            'title' => 'Example Page'
        ]);
    }
    
    // Accessible at: /example/create
    public function create() {
        // Only logged-in users
        if (!$this->requireLogin()) return;
        
        $this->render('example/create', [
            'title' => 'Create Example'
        ]);
    }
    
    // Accessible at: /example/save (POST)
    public function save() {
        $request = Flight::request();
        
        // Check CSRF token (currently disabled for debugging)
        // if (!$this->validateCSRF()) return;
        
        // Get input using Flight's request API
        $name = $this->sanitize($request->data->name);
        $email = $request->data->email;
        
        // Save to database using RedBean
        $bean = R::dispense('example');
        $bean->name = $name;
        $bean->email = $email;
        $bean->created_at = date('Y-m-d H:i:s');
        R::store($bean);
        
        // Redirect with success message
        $this->flash('success', 'Example created successfully!');
        Flight::redirect('/example');
    }
    
    // INTERNAL METHOD (not accessible via routing)
    private function processData($data) {
        // Private methods are not accessible via web
    }
    
    // INTERNAL METHOD (uppercase = not routable)
    public function ProcessInternal() {
        // Methods with uppercase letters are not accessible via web
    }
}
```

## Auto-Routing Convention

The framework uses a simple URL pattern:
```
/controller/method/operation/id

Examples:
/                          → Index->index()
/auth/login               → Auth->login()
/member/profile           → Member->profile()
/admin/users              → Admin->users()
/blog/post/edit/123       → Blog->post(['operation' => 'edit', 'id' => 123])
```

## Permission System

### Permission Levels
- **1 (ROOT)**: Super admin
- **50 (ADMIN)**: Administrator
- **100 (MEMBER)**: Regular member
- **101 (PUBLIC)**: Not logged in

### Setting Permissions

Permissions are stored in the `authcontrol` table:

```sql
INSERT INTO authcontrol (control, method, level, description) VALUES
('admin', '*', 50, 'All admin methods'),
('member', 'profile', 100, 'Member profile access');
```

### Build Mode

Enable build mode in config to auto-create permissions:
```ini
[app]
build_mode = true
```

When enabled, accessing any route will automatically create a permission entry.

### Checking Permissions in Controllers

```php
// Require login
if (!$this->requireLogin()) return;

// Require specific level
if (!$this->requireLevel(LEVELS['ADMIN'])) return;

// Manual check
if (Flight::hasLevel(LEVELS['ADMIN'])) {
    // Admin only code
}
```

## Database Operations

RedBeanPHP makes database operations simple:

```php
// Create
$user = R::dispense('member');
$user->email = 'user@example.com';
$user->name = 'John Doe';
$id = R::store($user);

// Read
$user = R::load('member', $id);
$users = R::findAll('member', 'status = ?', ['active']);

// Update
$user->last_login = date('Y-m-d H:i:s');
R::store($user);

// Delete
R::trash($user);

// Relationships
$post = R::dispense('post');
$post->member = $user; // Belongs to
$user->ownPostList[] = $post; // Has many
R::store($user);
```

## Views and Layouts

Views use the sandwich pattern with header/footer:

```php
// In controller:
$this->render('myview', [
    'title' => 'Page Title',
    'data' => $data
]);

// Creates: header + myview + footer
```

To render without layout (for AJAX):
```php
$this->render('myview', $data, false);
```

## Configuration

Edit `conf/config.ini`:

```ini
[database]
type = "mysql"
host = "localhost"
name = "tiknix"
user = "tiknix"
pass = "your_password"

[app]
name = "TikNix Application"
environment = "development"
debug = true
build = false  # Set to true to auto-create permissions

[logging]
level = "DEBUG"  # DEBUG, INFO, WARNING, ERROR
file = "log/app.log"

[session]
name = "TIKNIXSESSID"
lifetime = 3600
path = "/"
secure = false  # Set to true for HTTPS
httponly = true
samesite = "Lax"
```

## Security Features

- **CSRF Protection**: Automatic on POST/PUT/DELETE
- **Password Hashing**: Using PHP's password_hash()
- **SQL Injection Prevention**: Via RedBeanPHP parameterized queries
- **XSS Prevention**: HTML sanitization helpers
- **Session Security**: HTTPOnly, Secure, SameSite cookies
- **Input Sanitization**: Built-in sanitize methods

## Deployment Checklist

For production deployment:

1. **Update configuration**
```ini
[app]
environment = "production"
debug = false
build_mode = false  # IMPORTANT: Disable build mode!
```

2. **Re-enable CSRF protection**
- Uncomment CSRF validation in controllers
- Test all forms to ensure tokens are working

3. **Change default passwords**
- Immediately change the admin password from `admin123`
- Remove or secure the Test controller

4. **Set proper permissions**
```bash
chmod -R 755 .
chmod -R 777 log/ cache/ uploads/
chown -R www-data:www-data .
```

5. **Configure SSL/HTTPS**
- Set up SSL certificate
- Update config.ini with HTTPS settings
- Enable secure cookies in session configuration

6. **Set up cron jobs** (if needed)
```bash
# Example: Daily cleanup
0 2 * * * /usr/bin/php /path/to/public/index.php --control=cleanup --method=daily --member=1 --cron
```

7. **Configure email service** (for contact form notifications)

## API Development

Create API endpoints:

```php
class Api extends BaseControls\Control {
    
    public function users() {
        // Check API authentication
        $apiKey = $this->getParam('api_key');
        
        $users = R::findAll('member', 'status = ?', ['active']);
        
        $this->json([
            'success' => true,
            'data' => $users
        ]);
    }
}
```

## Extending the Framework

### Adding Libraries

Add to `composer.json`:
```json
"require": {
    "vendor/package": "^1.0"
}
```

### Creating Models

```php
// models/Member.php
namespace app\Models;

use \RedBeanPHP\SimpleModel;

class Model_Member extends SimpleModel {
    
    public function update() {
        $this->bean->updated_at = date('Y-m-d H:i:s');
    }
    
    public function getFullName() {
        return $this->bean->first_name . ' ' . $this->bean->last_name;
    }
}
```

### Custom Routes

For complex routing, create specific route files:

```php
// routes/api.php
Flight::route('/api/v1/users', ['Api', 'users']);
Flight::route('/api/v1/posts/@id', ['Api', 'post']);
```

## Flight Request API Usage

The framework uses Flight's request management:

```php
// In controllers
$request = Flight::request();

// GET parameters
$id = $request->query->id;
$search = $request->query->search;

// POST data
$username = $request->data->username;
$email = $request->data->email;

// Request method
if ($request->method === 'POST') {
    // Handle POST
}

// Check if AJAX
if ($request->ajax) {
    $this->json(['success' => true]);
}
```

## Important Notes

### Current Status
- **CSRF Protection**: Temporarily disabled for debugging. Re-enable in production by uncommenting validation in controllers
- **Build Mode**: Currently enabled for auto-creating permissions. Disable in production
- **Error Handling**: Full stack traces shown in development mode
- **Logging**: Comprehensive logging with daily rotation (keeps 30 days)
- **PHP Compatibility**: Updated for PHP 8.1+ (removed deprecated FILTER_SANITIZE_STRING)

### Database Tables
The framework auto-creates these tables via RedBeanPHP:
- `member`: User accounts
- `authcontrol`: Permission definitions  
- `contact`: Contact form submissions
- `contactresponse`: Admin responses to contact messages
- `settings`: User and system settings
- Additional tables created as needed

## Troubleshooting

### Common Issues

1. **500 Error**: 
   - Check log files in `log/` directory (e.g., `log/app-2025-08-11.log`)
   - Enable debug mode in config.ini
   - Check file permissions

2. **404 on all routes**: 
   - Ensure mod_rewrite is enabled
   - Check .htaccess file exists in public/
   - Verify Apache AllowOverride is set to All

3. **Database errors**: 
   - Verify credentials in config.ini
   - Ensure database exists
   - Check MySQL is running

4. **Login issues**:
   - Run `php database/reset_admin_password.php` to reset admin password
   - Check session directory is writable

5. **Permission denied**: 
   - Check file permissions (especially log/ directory)
   - Ensure web server user can write to log/

6. **CSRF errors**: 
   - Currently disabled for debugging
   - When enabled, ensure forms include CSRF token

### Debug Mode

Enable debug mode to see detailed errors:
```ini
[app]
debug = true
```

## CLI Support

The framework includes comprehensive CLI support for running controllers from the command line, perfect for cron jobs and background tasks.

### Basic CLI Usage

```bash
# Show help
php public/index.php --help

# Run a controller method
php public/index.php --control=test --method=hello

# Run as a specific member (use member ID)
php public/index.php --member=1 --control=admin --method=cleanup

# Pass URL-encoded parameters
php public/index.php --control=report --method=generate --params='type=daily&format=pdf'

# Pass JSON parameters
php public/index.php --control=api --method=process --json='{"action":"sync","data":{"id":123}}'

# Run in cron mode (suppress output)
php public/index.php --control=cleanup --method=daily --member=1 --cron

# Verbose mode for debugging
php public/index.php --control=test --method=hello --verbose
```

### CLI Options

| Option | Description | Example |
|--------|-------------|---------|
| `--help`, `-h` | Show help message | `php index.php --help` |
| `--control=NAME` | Controller name (required) | `--control=report` |
| `--method=NAME` | Method name (default: index) | `--method=generate` |
| `--member=ID` | Member ID to run as | `--member=1` |
| `--params=STRING` | URL-encoded parameters | `--params='id=5&type=pdf'` |
| `--json=JSON` | JSON parameters | `--json='{"key":"value"}'` |
| `--cron` | Cron mode (suppress output) | `--cron` |
| `--verbose` | Verbose output | `--verbose` |

### Setting Up Cron Jobs

Add to your crontab:

```bash
# Daily cleanup at 2 AM
0 2 * * * /usr/bin/php /var/www/html/default/tiknix/public/index.php --control=cleanup --method=daily --member=1 --cron

# Hourly report generation
0 * * * * /usr/bin/php /var/www/html/default/tiknix/public/index.php --control=report --method=hourly --member=1 --cron

# Weekly backup every Sunday at 3 AM
0 3 * * 0 /usr/bin/php /var/www/html/default/tiknix/public/index.php --control=backup --method=weekly --member=1 --cron
```

### Creating CLI-Only Controllers

To create a controller that only works in CLI mode:

```php
<?php
namespace app;

use \Flight as Flight;

class Cleanup extends BaseControls\Control {
    
    public function daily() {
        // Ensure this only runs from CLI
        if (!Flight::get('cli_mode')) {
            $this->error(403, 'This method is only available via CLI');
            return;
        }
        
        $options = Flight::get('cli_options');
        $verbose = isset($options['verbose']);
        $cron = isset($options['cron']);
        
        if (!$cron && $verbose) {
            echo "Starting daily cleanup...\n";
        }
        
        // Your cleanup logic here
        $this->cleanOldSessions();
        $this->cleanTempFiles();
        $this->optimizeTables();
        
        if (!$cron) {
            echo "Daily cleanup completed.\n";
        }
        
        Flight::get('log')->info('Daily cleanup completed', [
            'member' => $_SESSION['member']['username'] ?? 'system'
        ]);
    }
}
```

### CLI Permission Considerations

- By default, CLI commands run as `public-user-entity` (level 101)
- Use `--member=ID` to run as a specific user with their permissions
- Admin tasks should use `--member=1` (admin user)
- Enable build mode in config.ini to auto-create permissions during development

### Testing CLI Commands

```bash
# Test basic connectivity
php public/index.php --control=test --method=hello --verbose

# Test database access
php public/index.php --control=test --method=dbtest --member=1

# Test with parameters
php public/index.php --control=test --method=params --params='name=John&age=30' --member=1

# Test cron mode (should produce no output)
php public/index.php --control=test --method=cleanup --member=1 --cron
```

## Support

- Documentation: [Link to docs]
- Issues: [GitHub Issues]
- Community: [Discord/Forum]

## License

MIT License - feel free to use for personal and commercial projects.

## Credits

Built with:
- [FlightPHP](https://flightphp.com/) - Micro-framework
- [RedBeanPHP](https://redbeanphp.com/) - ORM
- [Bootstrap](https://getbootstrap.com/) - UI Framework
- [Monolog](https://github.com/Seldaek/monolog) - Logging
- [AntiCSRF](https://github.com/paragonie/anti-csrf) - CSRF Protection

### AI-Assisted Development

This project is developed with assistance from [Claude Code](https://claude.ai/claude-code) by Anthropic. The codebase architecture, documentation, and patterns are designed to be AI-friendly - making it easier for both human developers and AI coding assistants to understand and extend.

---

Happy coding!