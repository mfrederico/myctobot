<div class="container py-4">
    <div class="row">
        <div class="col-lg-3 col-md-4">
            <!-- Sidebar Navigation Component -->
            <?php
            $activeSection = 'api';
            $quickLinks = [
                ['href' => '#json-api', 'icon' => 'bi-code-square', 'text' => 'API Controller Pattern'],
                ['href' => '#response-helpers', 'icon' => 'bi-reply', 'text' => 'Response Helpers'],
                ['href' => '#request-handling', 'icon' => 'bi-arrow-down-up', 'text' => 'Request Handling'],
                ['href' => '#session-management', 'icon' => 'bi-key', 'text' => 'Session Management'],
                ['href' => '#error-handling', 'icon' => 'bi-exclamation-triangle', 'text' => 'Error Handling']
            ];
            $showPerformanceBadge = false;
            include __DIR__ . '/partials/sidebar.php';
            ?>
        </div>

        <div class="col-lg-9 col-md-8">
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb bg-light px-3 py-2 rounded shadow-sm">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item"><a href="/docs">Documentation</a></li>
                    <li class="breadcrumb-item active" aria-current="page">API Reference</li>
                </ol>
            </nav>

            <div class="documentation-content bg-white p-4 rounded shadow-sm">
                <h1><i class="bi bi-code-slash"></i> Building JSON APIs</h1>

                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> Learn how to create RESTful JSON APIs with TikNix. For framework basics, see the <a href="/docs">Getting Started</a> guide.
                </div>

                <h2 id="json-api">API Controller Pattern</h2>

                <h3>API Controller Example</h3>
                <pre><code class="language-php">&lt;?php
namespace app;

class Api extends BaseControls\Control {

    public function __construct() {
        parent::__construct();

        // Set JSON headers
        header('Content-Type: application/json');

        // Check API authentication
        if (!$this->checkApiAuth()) {
            $this->json(['error' => 'Unauthorized'], 401);
            exit;
        }
    }

    // GET /api/users
    public function users($params = []) {
        $operation = $params['operation']->name ?? 'list';

        switch ($operation) {
            case 'list':
                $this->listUsers();
                break;
            case 'search':
                $this->searchUsers();
                break;
            default:
                $this->json(['error' => 'Invalid operation'], 400);
        }
    }

    private function listUsers() {
        // Pagination
        $page = $this->getParam('page', 1);
        $limit = $this->getParam('limit', 20);
        $offset = ($page - 1) * $limit;

        // Get users (automatically cached!)
        $users = Bean::find('member',
            'status = ? ORDER BY created_at DESC LIMIT ? OFFSET ?',
            ['active', $limit, $offset]
        );

        $total = Bean::count('member', 'status = ?', ['active']);

        $this->json([
            'success' => true,
            'data' => array_values(Bean::exportAll($users)),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }

    private function checkApiAuth() {
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

        if (empty($apiKey)) {
            return false;
        }

        // Check API key (cached!)
        $member = Bean::findOne('member', 'api_key = ? AND status = ?',
            [$apiKey, 'active']
        );

        if ($member) {
            $this->member = $member;
            return true;
        }

        return false;
    }
}</code></pre>

                <h3>Testing API Endpoints</h3>
                <pre><code class="language-bash"># GET request with API key
curl -H "X-API-Key: your-key-here" https://site.com/api/users

# POST with JSON data
curl -X POST https://site.com/api/users/create \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-key-here" \
  -d '{"username":"john","email":"john@example.com"}'

# With pagination
curl "https://site.com/api/users?page=2&limit=10" \
  -H "X-API-Key: your-key-here"</code></pre>

                <h2 id="response-helpers">Response Helpers</h2>

                <h3>JSON Responses</h3>
                <pre><code class="language-php">// Success response
$this->json([
    'success' => true,
    'message' => 'User created',
    'data' => ['id' => $id]
], 201);

// Error response
$this->json([
    'success' => false,
    'error' => 'Validation failed',
    'errors' => [
        'email' => 'Email already exists'
    ]
], 422);

// Using Flight directly
Flight::json(['data' => $data], 200);</code></pre>

                <h3>Redirects</h3>
                <pre><code class="language-php">// Simple redirect
Flight::redirect('/dashboard');

// With query parameters
Flight::redirect('/auth/login?redirect=' . urlencode(Flight::request()->url));

// External redirect
Flight::redirect('https://example.com', 302);</code></pre>

                <h2 id="request-handling">Request Handling</h2>

                <h3>Accessing Request Data</h3>
                <pre><code class="language-php">// Get Flight request object
$request = Flight::request();

// URL and path info
$url = $request->url;           // Full URL path
$method = $request->method;     // GET, POST, etc.
$ajax = $request->ajax;         // Is AJAX request?
$secure = $request->secure;     // Is HTTPS?
$ip = $request->ip;             // Client IP

// Query parameters (GET)
$page = $request->query->page ?? 1;
$search = $request->query['search'] ?? '';

// Body parameters (POST)
$username = $request->data->username;
$email = $request->data['email'];

// Headers
$apiKey = $request->header('X-API-Key');
$contentType = $request->header('Content-Type');

// Files
$uploadedFile = $request->files['upload'] ?? null;</code></pre>

                <h2 id="session-management">Session Management</h2>

                <pre><code class="language-php">// Set session data
$_SESSION['user_preference'] = 'dark_mode';

// Get session data
$pref = $_SESSION['user_preference'] ?? 'light_mode';

// Login a user
$_SESSION['member'] = $member->export();

// Logout
unset($_SESSION['member']);
session_destroy();</code></pre>

                <h2 id="error-handling">Error Handling</h2>

                <pre><code class="language-php">// In controller constructor
set_exception_handler(function($e) {
    $this->logger->error($e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);

    if ($this->getParam('format') === 'json') {
        $this->json(['error' => 'Internal server error'], 500);
    } else {
        Flight::renderView('error/500');
    }
});

// Manual error handling
try {
    $result = $this->riskyOperation();
} catch (\Exception $e) {
    $this->logger->error('Operation failed: ' . $e->getMessage());
    $this->flash('error', 'Something went wrong');
    Flight::redirect('/dashboard');
}</code></pre>

                <div class="alert alert-success alert-dismissible fade show mt-5" role="alert">
                    <h4><i class="bi bi-lightning"></i> Performance Note</h4>
                    <p class="mb-0">All database queries are <strong>automatically cached</strong> by the CachedDatabaseAdapter, providing <strong>9.4x faster</strong> performance with zero code changes!</p>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Enhanced styles for API documentation */
.documentation-content {
    font-size: 16px;
    line-height: 1.8;
}

.documentation-content h1 {
    color: #2c3e50;
    border-bottom: 3px solid #007bff;
    padding-bottom: 15px;
    margin-bottom: 30px;
}

.documentation-content h2 {
    color: #34495e;
    margin-top: 40px;
    margin-bottom: 20px;
    padding-left: 15px;
    border-left: 5px solid #007bff;
}

.documentation-content h3 {
    color: #495057;
    margin-top: 25px;
    margin-bottom: 15px;
}

.documentation-content pre {
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 20px;
    overflow-x: auto;
    margin: 20px 0;
}

.documentation-content code {
    background-color: #fff3cd;
    padding: 3px 8px;
    border-radius: 4px;
    color: #d63384;
}

.documentation-content pre code {
    background-color: transparent;
    padding: 0;
    color: #212529;
}

.table code {
    background-color: #f8f9fa;
    color: #d63384;
}
</style>