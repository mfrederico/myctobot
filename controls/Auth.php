<?php
/**
 * Authentication Controller
 * Handles login, logout, registration, password reset, and Google OAuth
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\plugins\GoogleAuth;

// Load Google Auth plugin
require_once __DIR__ . '/../lib/plugins/GoogleAuth.php';

class Auth extends BaseControls\Control {

    /**
     * Show login form
     * Canonical URL: /login or /login/{workspace}
     */
    public function login($params = null) {
        // Redirect /auth/login to /login (clean URLs)
        $isAuthRoute = !isset($params['workspace']) && isset($params['operation']);
        if ($isAuthRoute) {
            $workspace = $params['operation']->name ?? '';
            $redirect = Flight::request()->query->redirect ?? '';
            $url = $workspace ? "/login/{$workspace}" : '/login';
            if ($redirect) {
                $url .= '?redirect=' . urlencode($redirect);
            }
            Flight::redirect($url);
            return;
        }

        // Get workspace from URL params (/login/gwt)
        $workspace = $params['workspace'] ?? '';

        // Also check query string (e.g., /login?workspace=gwt)
        if (empty($workspace)) {
            $workspace = Flight::request()->query->workspace ?? '';
        }

        // If still no workspace, try to extract from subdomain
        // e.g., clicksimple-inc.myctobot.ai -> clicksimple-inc
        if (empty($workspace)) {
            $workspace = $this->extractSubdomainFromHost();
        }

        // Don't redirect if coming from a permission denied scenario
        $redirect = Flight::request()->query->redirect ?? '';

        // If already logged in and NOT coming from a permission denied redirect
        if (Flight::isLoggedIn() && empty($redirect)) {
            Flight::redirect('/settings/connections');
            return;
        }

        // If logged in but redirected here due to permission issues, show a message
        if (Flight::isLoggedIn() && !empty($redirect)) {
            $this->flash('error', 'You do not have permission to access that page.');
        }

        // Validate workspace if provided
        $workspaceError = null;
        if (!empty($workspace) && !TenantResolver::tenantExists($workspace)) {
            $workspaceError = "Workspace '{$workspace}' not found";
            $workspace = '';
        }

        $this->render('auth/login', [
            'title' => 'Login',
            'redirect' => $redirect,
            'workspace' => $workspace,
            'workspaceError' => $workspaceError,
            'googleEnabled' => GoogleAuth::isConfigured() && TenantResolver::isDefault()
        ]);
    }

    /**
     * Process login
     * Supports workspace-based multi-tenant authentication
     */
    public function dologin() {
        try {
            // CSRF validation enabled for security
            if (!$this->validateCSRF()) {
                $this->flash('error', 'Security validation failed. Please try again.');
                Flight::redirect('/auth/login');
                return;
            }

            // Accept either username or email
            $request = Flight::request();
            $username = $request->data->username ?? '';
            $email = $request->data->email ?? '';
            $password = $request->data->password ?? '';
            $workspace = strtolower(trim($request->data->workspace ?? ''));
            $redirect = $request->data->redirect ?? '/settings/connections';

            // Use username if provided, otherwise use email
            $login = $username ?: $email;

            // Validate input
            if (empty($login) || empty($password)) {
                $this->flash('error', 'Email and password are required');
                $this->redirectToLogin($workspace);
                return;
            }

            // If workspace provided, switch to tenant database
            if (!empty($workspace)) {
                if (!TenantResolver::tenantExists($workspace)) {
                    $this->flash('error', "Workspace '{$workspace}' not found");
                    Flight::redirect('/auth/login');
                    return;
                }

                // Switch to tenant database
                if (!$this->switchToTenantDatabase($workspace)) {
                    $this->flash('error', 'Could not connect to workspace. Please try again.');
                    Flight::redirect('/auth/login');
                    return;
                }
            }

            // Find member by username or email (in current database - tenant or default)
            $member = R::findOne('member', '(username = ? OR email = ?) AND status = ?', [$login, $login, 'active']);

            if (!$member || !password_verify($password, $member->password)) {
                $this->logger->warning('Failed login attempt', ['login' => $login, 'workspace' => $workspace ?: 'default']);
                $this->flash('error', 'Invalid credentials');
                $this->redirectToLogin($workspace);
                return;
            }

            // For workspace logins, user database is already the tenant DB
            // For default logins (no workspace), ensure user has their SQLite set up
            if (empty($workspace)) {
                $this->ensureUserDatabase($member);
            }

            // Update last login
            $member->last_login = date('Y-m-d H:i:s');
            $member->login_count = ($member->login_count ?? 0) + 1;
            R::store($member);

            // Regenerate session ID to prevent session fixation attacks
            session_regenerate_id(true);

            // Set session
            $_SESSION['member'] = $member->export();

            // Store tenant in session for workspace logins
            if (!empty($workspace)) {
                TenantResolver::setTenant($workspace);
                $this->logger->info('User logged in to workspace', [
                    'id' => $member->id,
                    'username' => $member->username,
                    'workspace' => $workspace
                ]);
            } else {
                $this->logger->info('User logged in', ['id' => $member->id, 'username' => $member->username]);
            }

            $this->flash('success', 'Welcome back, ' . ($member->display_name ?? $member->username ?? $member->email) . '!');

            Flight::redirect($redirect);

        } catch (Exception $e) {
            $this->handleException($e, 'Login failed');
        }
    }

    /**
     * Switch to a tenant's database
     *
     * @param string $workspace Tenant workspace code
     * @return bool True on success
     */
    private function switchToTenantDatabase(string $workspace): bool {
        $configFile = TenantResolver::getConfigFile($workspace);

        if (!file_exists($configFile)) {
            $this->logger->error('Tenant config not found', ['workspace' => $workspace, 'config' => $configFile]);
            return false;
        }

        try {
            $config = parse_ini_file($configFile, true);
            $dbConfig = $config['database'] ?? [];

            if (empty($dbConfig)) {
                $this->logger->error('Tenant database config missing', ['workspace' => $workspace]);
                return false;
            }

            // Build DSN and connect
            $type = $dbConfig['type'] ?? 'mysql';
            $host = $dbConfig['host'] ?? 'localhost';
            $port = $dbConfig['port'] ?? 3306;
            $name = $dbConfig['name'] ?? '';
            $user = $dbConfig['user'] ?? '';
            $pass = $dbConfig['pass'] ?? '';

            $dsn = "{$type}:host={$host};port={$port};dbname={$name}";

            // Add this connection as a secondary database and switch to it
            R::addDatabase($workspace, $dsn, $user, $pass);
            R::selectDatabase($workspace);

            $this->logger->debug('Switched to tenant database', ['workspace' => $workspace, 'database' => $name]);
            return true;

        } catch (Exception $e) {
            $this->logger->error('Failed to switch tenant database', [
                'workspace' => $workspace,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Redirect back to login with workspace preserved
     */
    private function redirectToLogin(string $workspace = ''): void {
        if (!empty($workspace)) {
            Flight::redirect("/login/{$workspace}");
        } else {
            Flight::redirect('/auth/login');
        }
    }

    /**
     * Extract subdomain from HTTP host
     * e.g., clicksimple-inc.myctobot.ai -> clicksimple-inc
     * Returns empty string for main domain or localhost
     */
    private function extractSubdomainFromHost(): string {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $host = explode(':', $host)[0]; // Remove port
        $host = strtolower($host);

        // Skip localhost and IP addresses
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return '';
        }

        // Extract subdomain (first part if 3+ parts)
        // clicksimple-inc.myctobot.ai -> clicksimple-inc
        // myctobot.ai -> '' (no subdomain)
        $parts = explode('.', $host);
        if (count($parts) >= 3) {
            $subdomain = $parts[0];
            // Verify tenant config exists
            if (TenantResolver::tenantExists($subdomain)) {
                return $subdomain;
            }
        }

        return '';
    }

    /**
     * Logout user
     */
    public function logout() {
        $workspace = TenantResolver::getSessionTenant();

        if (isset($_SESSION['member'])) {
            $this->logger->info('User logged out', [
                'id' => $_SESSION['member']['id'],
                'workspace' => $workspace ?: 'default'
            ]);
        }

        // Clear tenant from session
        TenantResolver::clearTenant();

        // Properly clear session data
        $_SESSION = array();

        // Destroy session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        // Destroy the session
        session_destroy();

        // Start a new session for flash messages
        session_start();
        $this->flash('success', 'You have been logged out');

        // If was in a workspace, redirect to workspace login
        if (!empty($workspace)) {
            Flight::redirect("/login/{$workspace}");
        } else {
            Flight::redirect('/');
        }
    }

    /**
     * Show registration form
     */
    public function register() {
        // Non-tenant (main site) should use /signup for tenant registration
        if (!Flight::get('tenant.active')) {
            Flight::redirect('/signup');
            return;
        }

        // Redirect if already logged in
        if (Flight::isLoggedIn()) {
            Flight::redirect('/settings/connections');
            return;
        }

        $this->render('auth/register', [
            'title' => 'Register',
            'googleEnabled' => GoogleAuth::isConfigured()
        ]);
    }

    /**
     * Process registration (simple version - no email verification)
     */
    public function doregister() {
        // Non-tenant (main site) should use /signup for tenant registration
        if (!Flight::get('tenant.active')) {
            Flight::redirect('/signup');
            return;
        }

        $request = Flight::request();

        // Handle both GET and POST for easier testing
        if ($request->method === 'GET') {
            $this->register();
            return;
        }

        // Get input
        $username = $this->sanitize($request->data->username);
        $email = $this->sanitize($request->data->email, 'email');
        $password = $request->data->password;
        $password_confirm = $request->data->password_confirm;

        // Simple validation
        $errors = [];

        if (empty($username) || strlen($username) < 3) {
            $errors[] = 'Username must be at least 3 characters';
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required';
        }

        if (empty($password) || strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters';
        }

        if ($password !== $password_confirm) {
            $errors[] = 'Passwords do not match';
        }

        // Check if email exists
        if (R::count('member', 'email = ?', [$email]) > 0) {
            $errors[] = 'Email already registered';
        }

        // Check if username exists
        if (R::count('member', 'username = ?', [$username]) > 0) {
            $errors[] = 'Username already taken';
        }

        if (!empty($errors)) {
            $this->render('auth/register', [
                'title' => 'Register',
                'errors' => $errors,
                'data' => $request->data->getData(),
                'googleEnabled' => GoogleAuth::isConfigured()
            ]);
            return;
        }

        try {
            // Create member
            $member = R::dispense('member');
            $member->email = $email;
            $member->username = $username;
            $member->password = password_hash($password, PASSWORD_DEFAULT);
            $member->level = LEVELS['MEMBER'];
            $member->status = 'active'; // Active immediately - no email verification
            $member->created_at = date('Y-m-d H:i:s');

            $id = R::store($member);
            $member->id = $id;

            // Create user's database
            $this->ensureUserDatabase($member);

            Flight::get('log')->info('New user registered', ['id' => $id, 'username' => $username]);

            // Regenerate session ID to prevent session fixation attacks
            session_regenerate_id(true);

            // Auto-login after registration
            $_SESSION['member'] = $member->export();

            $this->flash('success', 'Welcome to ' . Flight::get('app.name') . '! Your account has been created.');
            Flight::redirect('/settings/connections');

        } catch (\Exception $e) {
            Flight::get('log')->error('Registration failed: ' . $e->getMessage());
            $this->render('auth/register', [
                'title' => 'Register',
                'errors' => ['Registration failed. Please try again.'],
                'data' => $request->data->getData(),
                'googleEnabled' => GoogleAuth::isConfigured()
            ]);
        }
    }

    /**
     * Show forgot password form
     */
    public function forgot() {
        $workspace = $this->getParam('workspace') ?? '';
        $this->render('auth/forgot', [
            'title' => 'Forgot Password',
            'workspace' => $workspace
        ]);
    }

    /**
     * Process forgot password
     */
    public function doforgot() {
        try {
            // Validate CSRF
            if (!$this->validateCSRF()) {
                return;
            }

            $email = $this->sanitize($this->getParam('email'), 'email');
            $workspace = $this->sanitize($this->getParam('workspace'), 'string');

            $this->logger->debug('Password reset requested', ['email' => $email, 'workspace' => $workspace]);

            if (empty($email)) {
                $this->flash('error', 'Email is required');
                $redirectUrl = '/auth/forgot' . ($workspace ? '?workspace=' . urlencode($workspace) : '');
                Flight::redirect($redirectUrl);
                return;
            }

            // If workspace provided, switch to tenant database
            $member = null;
            $connectedToTenant = false;
            if (!empty($workspace)) {
                if ($this->switchToTenantDatabase($workspace)) {
                    $connectedToTenant = true;
                    $member = R::findOne('member', 'email = ? AND status = ?', [$email, 'active']);
                    $this->logger->debug('Member lookup in tenant DB', ['found' => $member ? true : false, 'workspace' => $workspace]);
                }
            } else {
                $member = R::findOne('member', 'email = ? AND status = ?', [$email, 'active']);
            }

            if ($member) {
                // Generate reset token and store (still connected to tenant DB if workspace was provided)
                $token = bin2hex(random_bytes(32));
                $member->reset_token = $token;
                $member->reset_expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                R::store($member);

                $this->logger->debug('Reset token stored', ['member_id' => $member->id, 'workspace' => $workspace]);

                // Now switch back to default DB
                if ($connectedToTenant) {
                    R::selectDatabase('default');
                }

                // Send reset email via Mailgun - include workspace in URL
                $resetUrl = Flight::get('app.baseurl') . "/auth/reset?token={$token}";
                if (!empty($workspace)) {
                    $resetUrl .= "&workspace=" . urlencode($workspace);
                }

                $mailgun = new \app\services\MailgunService();
                if ($mailgun->isEnabled()) {
                    $subject = 'Reset Your Password - MyCTOBot';
                    $html = <<<HTML
<h2>Password Reset Request</h2>
<p>You requested to reset your password. Click the button below to create a new password:</p>
<p style="text-align: center; margin: 30px 0;">
    <a href="{$resetUrl}" style="background-color: #0d6efd; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">Reset Password</a>
</p>
<p>Or copy and paste this link into your browser:</p>
<p><a href="{$resetUrl}">{$resetUrl}</a></p>
<p style="color: #666; font-size: 14px;">This link expires in 1 hour. If you didn't request this, you can ignore this email.</p>
HTML;
                    $sent = $mailgun->send($email, $subject, $html);
                    $this->logger->info('Password reset email sent', ['email' => $email, 'sent' => $sent]);
                } else {
                    $this->logger->warning('Mailgun not configured, password reset email not sent', ['email' => $email]);
                }
            }

            // Always show success to prevent email enumeration
            $this->flash('success', 'If the email exists, a reset link has been sent');
            $loginUrl = $workspace ? "/login/{$workspace}" : '/auth/login';
            Flight::redirect($loginUrl);

        } catch (Exception $e) {
            $this->handleException($e, 'Password reset failed');
        }
    }

    /**
     * Show reset password form
     */
    public function reset() {
        $token = $this->getParam('token');
        $workspace = $this->getParam('workspace') ?? '';

        if (empty($token)) {
            $this->flash('error', 'Invalid reset link');
            Flight::redirect('/auth/login');
            return;
        }

        // If workspace provided, switch to tenant database
        if (!empty($workspace)) {
            if (!$this->switchToTenantDatabase($workspace)) {
                $this->flash('error', 'Invalid workspace');
                Flight::redirect('/auth/login');
                return;
            }
        }

        $member = R::findOne('member', 'reset_token = ? AND reset_expires > ?',
            [$token, date('Y-m-d H:i:s')]);

        if (!empty($workspace)) {
            R::selectDatabase('default');
        }

        if (!$member) {
            $this->flash('error', 'Invalid or expired reset link');
            $loginUrl = $workspace ? "/login/{$workspace}" : '/auth/login';
            Flight::redirect($loginUrl);
            return;
        }

        $this->render('auth/reset', [
            'title' => 'Reset Password',
            'token' => $token,
            'workspace' => $workspace
        ]);
    }

    /**
     * Process password reset
     */
    public function doreset() {
        try {
            // Validate CSRF
            if (!$this->validateCSRF()) {
                return;
            }

            $token = $this->getParam('token');
            $password = $this->getParam('password');
            $password_confirm = $this->getParam('password_confirm');
            $workspace = $this->getParam('workspace') ?? '';

            // Build reset URL for redirects
            $resetUrl = "/auth/reset?token={$token}";
            if (!empty($workspace)) {
                $resetUrl .= "&workspace=" . urlencode($workspace);
            }

            // Validate input
            if (empty($token) || empty($password)) {
                $this->flash('error', 'Invalid request');
                Flight::redirect('/auth/login');
                return;
            }

            if (strlen($password) < 8) {
                $this->flash('error', 'Password must be at least 8 characters');
                Flight::redirect($resetUrl);
                return;
            }

            if ($password !== $password_confirm) {
                $this->flash('error', 'Passwords do not match');
                Flight::redirect($resetUrl);
                return;
            }

            // If workspace provided, switch to tenant database
            if (!empty($workspace)) {
                if (!$this->switchToTenantDatabase($workspace)) {
                    $this->flash('error', 'Invalid workspace');
                    Flight::redirect('/auth/login');
                    return;
                }
            }

            // Find member
            $member = R::findOne('member', 'reset_token = ? AND reset_expires > ?',
                [$token, date('Y-m-d H:i:s')]);

            if (!$member) {
                if (!empty($workspace)) {
                    R::selectDatabase('default');
                }
                $this->flash('error', 'Invalid or expired reset link');
                $loginUrl = $workspace ? "/login/{$workspace}" : '/auth/login';
                Flight::redirect($loginUrl);
                return;
            }

            // Update password
            $member->password = password_hash($password, PASSWORD_DEFAULT);
            $member->reset_token = null;
            $member->reset_expires = null;
            R::store($member);

            if (!empty($workspace)) {
                R::selectDatabase('default');
            }

            $this->logger->info('Password reset completed', ['id' => $member->id, 'workspace' => $workspace]);

            $this->flash('success', 'Password reset successful! Please login with your new password');
            $loginUrl = $workspace ? "/login/{$workspace}" : '/auth/login';
            Flight::redirect($loginUrl);

        } catch (Exception $e) {
            $this->handleException($e, 'Password reset failed');
        }
    }

    /**
     * Redirect to Google OAuth login
     */
    public function google() {
        try {
            if (!GoogleAuth::isConfigured()) {
                $this->flash('error', 'Google login is not configured');
                Flight::redirect('/auth/login');
                return;
            }

            // Store workspace in session for after callback
            $workspace = Flight::request()->query->workspace ?? '';
            if (!empty($workspace)) {
                $_SESSION['oauth_workspace'] = strtolower(trim($workspace));
            }

            // Store redirect URL if provided
            $redirect = Flight::request()->query->redirect ?? '';
            if (!empty($redirect)) {
                $_SESSION['oauth_redirect'] = $redirect;
            }

            $loginUrl = GoogleAuth::getLoginUrl();
            Flight::redirect($loginUrl);

        } catch (Exception $e) {
            $this->logger->error('Google auth redirect failed: ' . $e->getMessage());
            $this->flash('error', 'Could not connect to Google. Please try again.');
            Flight::redirect('/auth/login');
        }
    }

    /**
     * Handle Google OAuth callback
     */
    public function googlecallback() {
        try {
            $code = $this->getParam('code');
            $state = $this->getParam('state');
            $error = $this->getParam('error');

            // Check for errors from Google
            if ($error) {
                $this->logger->warning('Google OAuth error', ['error' => $error]);
                $this->flash('error', 'Google login was cancelled or failed');
                Flight::redirect('/auth/login');
                return;
            }

            if (empty($code)) {
                $this->flash('error', 'Invalid Google login response');
                Flight::redirect('/auth/login');
                return;
            }

            // Handle the callback and get/create user
            $member = GoogleAuth::handleCallback($code, $state);

            if (!$member) {
                $this->flash('error', 'Could not authenticate with Google. Please try again.');
                Flight::redirect('/auth/login');
                return;
            }

            // Check if account is active
            if ($member->status !== 'active') {
                $this->flash('error', 'Your account is not active. Please contact support.');
                Flight::redirect('/auth/login');
                return;
            }

            // Check for workspace from OAuth flow
            $workspace = $_SESSION['oauth_workspace'] ?? '';
            $redirect = $_SESSION['oauth_redirect'] ?? '/settings/connections';
            unset($_SESSION['oauth_workspace'], $_SESSION['oauth_redirect']);

            // If workspace specified, switch to tenant database
            if (!empty($workspace)) {
                if (!TenantResolver::tenantExists($workspace)) {
                    $this->flash('error', "Workspace '{$workspace}' not found");
                    Flight::redirect('/auth/login');
                    return;
                }

                if (!$this->switchToTenantDatabase($workspace)) {
                    $this->flash('error', 'Could not connect to workspace');
                    Flight::redirect('/auth/login');
                    return;
                }

                // Re-find member in tenant database
                $tenantMember = R::findOne('member', '(email = ? OR google_id = ?) AND status = ?',
                    [$member->email, $member->google_id, 'active']);

                if (!$tenantMember) {
                    $this->flash('error', 'Your Google account is not registered in this workspace');
                    Flight::redirect("/login/{$workspace}");
                    return;
                }
                $member = $tenantMember;

                // Set tenant in session
                TenantResolver::setTenant($workspace);
            } else {
                // Ensure user has their database set up (main site login)
                $this->ensureUserDatabase($member);
            }

            // Regenerate session ID to prevent session fixation attacks
            session_regenerate_id(true);

            // Set session
            $_SESSION['member'] = $member->export();

            $this->logger->info('User logged in via Google', [
                'id' => $member->id,
                'email' => $member->email,
                'workspace' => $workspace ?: 'default'
            ]);

            $this->flash('success', 'Welcome, ' . ($member->display_name ?? $member->username ?? $member->email) . '!');
            Flight::redirect($redirect);

        } catch (Exception $e) {
            $this->handleException($e, 'Google login failed');
            Flight::redirect('/auth/login');
        }
    }

    /**
     * Ensure user has their SQLite database set up
     */
    private function ensureUserDatabase($member) {
        if (empty($member->ceobot_db)) {
            // Generate unique database name using SHA256 hash
            $dbHash = hash('sha256', $member->id . $member->email . $member->created_at);
            $dbPath = Flight::get('ceobot.user_db_path') ?? 'database/';
            $dbFile = $dbPath . $dbHash . '.sqlite';

            // Store the database reference on the member
            $member->ceobot_db = $dbHash;
            R::store($member);

            // Create the SQLite database directory if needed
            if (!is_dir($dbPath)) {
                mkdir($dbPath, 0755, true);
            }

            // Initialize the user's database with MyCTOBot schema
            $userDb = new \SQLite3($dbFile);

            // Create jiraboards table
            $userDb->exec("
                CREATE TABLE IF NOT EXISTS jiraboards (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    board_id INTEGER NOT NULL,
                    board_name TEXT NOT NULL,
                    project_key TEXT NOT NULL,
                    cloud_id TEXT NOT NULL,
                    board_type TEXT DEFAULT 'scrum',
                    enabled INTEGER DEFAULT 1,
                    digest_enabled INTEGER DEFAULT 0,
                    digest_time TEXT DEFAULT '08:00',
                    timezone TEXT DEFAULT 'UTC',
                    status_filter TEXT DEFAULT 'To Do',
                    last_analysis_at TEXT,
                    last_digest_at TEXT,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT,
                    UNIQUE(board_id, cloud_id)
                )
            ");

            // Create analysisresults table
            $userDb->exec("
                CREATE TABLE IF NOT EXISTS analysisresults (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    board_id INTEGER NOT NULL,
                    analysis_type TEXT NOT NULL,
                    content_json TEXT NOT NULL,
                    content_markdown TEXT,
                    issue_count INTEGER,
                    status_filter TEXT,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (board_id) REFERENCES jiraboards(id) ON DELETE CASCADE
                )
            ");

            // Create digesthistory table
            $userDb->exec("
                CREATE TABLE IF NOT EXISTS digesthistory (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    board_id INTEGER NOT NULL,
                    sent_to TEXT NOT NULL,
                    subject TEXT,
                    content_preview TEXT,
                    status TEXT DEFAULT 'sent',
                    error_message TEXT,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (board_id) REFERENCES jiraboards(id) ON DELETE CASCADE
                )
            ");

            // Create usersettings table
            $userDb->exec("
                CREATE TABLE IF NOT EXISTS usersettings (
                    key TEXT PRIMARY KEY,
                    value TEXT,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            ");

            // Create indexes
            $userDb->exec("CREATE INDEX IF NOT EXISTS idx_boards_cloud ON jiraboards(cloud_id)");
            $userDb->exec("CREATE INDEX IF NOT EXISTS idx_boards_enabled ON jiraboards(enabled)");
            $userDb->exec("CREATE INDEX IF NOT EXISTS idx_boards_digest ON jiraboards(digest_enabled)");
            $userDb->exec("CREATE INDEX IF NOT EXISTS idx_analysis_board ON analysisresults(board_id)");
            $userDb->exec("CREATE INDEX IF NOT EXISTS idx_analysis_created ON analysisresults(created_at DESC)");
            $userDb->exec("CREATE INDEX IF NOT EXISTS idx_digest_board ON digesthistory(board_id)");

            // Insert default settings
            $userDb->exec("INSERT OR IGNORE INTO usersettings (key, value) VALUES ('digest_email', '')");
            $userDb->exec("INSERT OR IGNORE INTO usersettings (key, value) VALUES ('default_status_filter', 'To Do')");
            $userDb->exec("INSERT OR IGNORE INTO usersettings (key, value) VALUES ('default_digest_time', '08:00')");

            // Enterprise: Settings table for encrypted API keys and configuration
            $userDb->exec("
                CREATE TABLE IF NOT EXISTS enterprisesettings (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    setting_key TEXT NOT NULL UNIQUE,
                    setting_value TEXT NOT NULL,
                    is_encrypted INTEGER DEFAULT 1,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT
                )
            ");

            // Enterprise: Git repository connections
            $userDb->exec("
                CREATE TABLE IF NOT EXISTS repoconnections (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    provider TEXT NOT NULL,
                    repo_owner TEXT NOT NULL,
                    repo_name TEXT NOT NULL,
                    default_branch TEXT DEFAULT 'main',
                    clone_url TEXT NOT NULL,
                    access_token TEXT,
                    enabled INTEGER DEFAULT 1,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT,
                    UNIQUE(provider, repo_owner, repo_name)
                )
            ");

            // Enterprise: Board to repository mappings
            $userDb->exec("
                CREATE TABLE IF NOT EXISTS boardrepomappings (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    board_id INTEGER NOT NULL,
                    repo_connection_id INTEGER NOT NULL,
                    is_default INTEGER DEFAULT 0,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(board_id, repo_connection_id),
                    FOREIGN KEY (repo_connection_id) REFERENCES repoconnections(id) ON DELETE CASCADE
                )
            ");

            // Enterprise: AI Developer jobs (one record per Jira issue)
            $userDb->exec("
                CREATE TABLE IF NOT EXISTS aidevjobs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    issue_key TEXT NOT NULL UNIQUE,
                    board_id INTEGER NOT NULL,
                    repo_connection_id INTEGER,
                    cloud_id TEXT,
                    status TEXT DEFAULT 'pending',
                    current_shard_job_id TEXT,
                    branch_name TEXT,
                    pr_url TEXT,
                    pr_number INTEGER,
                    clarification_comment_id TEXT,
                    clarification_questions TEXT,
                    error_message TEXT,
                    run_count INTEGER DEFAULT 0,
                    last_output TEXT,
                    last_result_json TEXT,
                    files_changed TEXT,
                    commit_sha TEXT,
                    started_at TEXT,
                    completed_at TEXT,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT,
                    FOREIGN KEY (repo_connection_id) REFERENCES repoconnections(id) ON DELETE SET NULL
                )
            ");

            // Enterprise: AI Developer job logs
            $userDb->exec("
                CREATE TABLE IF NOT EXISTS aidevjoblogs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    job_id TEXT NOT NULL,
                    log_level TEXT DEFAULT 'info',
                    message TEXT NOT NULL,
                    context_json TEXT,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            ");

            // Enterprise indexes
            $userDb->exec("CREATE INDEX IF NOT EXISTS idx_repo_provider ON repoconnections(provider)");
            $userDb->exec("CREATE INDEX IF NOT EXISTS idx_repo_enabled ON repoconnections(enabled)");
            $userDb->exec("CREATE INDEX IF NOT EXISTS idx_mapping_board ON boardrepomappings(board_id)");
            $userDb->exec("CREATE INDEX IF NOT EXISTS idx_ai_job_status ON aidevjobs(status)");
            $userDb->exec("CREATE INDEX IF NOT EXISTS idx_ai_job_issue ON aidevjobs(issue_key)");
            $userDb->exec("CREATE INDEX IF NOT EXISTS idx_ai_job_board ON aidevjobs(board_id)");
            $userDb->exec("CREATE INDEX IF NOT EXISTS idx_ai_log_job ON aidevjoblogs(job_id)");

            $userDb->close();

            $this->logger->info('Created user database', [
                'member_id' => $member->id,
                'db_hash' => $dbHash
            ]);
        }
    }
}
