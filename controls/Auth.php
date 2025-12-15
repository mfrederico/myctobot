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
     */
    public function login() {
        // Don't redirect if coming from a permission denied scenario
        $redirect = Flight::request()->query->redirect ?? '';

        // If already logged in and NOT coming from a permission denied redirect
        if (Flight::isLoggedIn() && empty($redirect)) {
            Flight::redirect('/dashboard');
            return;
        }

        // If logged in but redirected here due to permission issues, show a message
        if (Flight::isLoggedIn() && !empty($redirect)) {
            $this->flash('error', 'You do not have permission to access that page.');
        }

        $this->render('auth/login', [
            'title' => 'Login',
            'redirect' => $redirect,
            'googleEnabled' => GoogleAuth::isConfigured()
        ]);
    }

    /**
     * Process login
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
            $redirect = $request->data->redirect ?? '/dashboard';

            // Use username if provided, otherwise use email
            $login = $username ?: $email;

            // Validate input
            if (empty($login) || empty($password)) {
                $this->flash('error', 'Username/Email and password are required');
                Flight::redirect('/auth/login');
                return;
            }

            // Find member by username or email
            $member = R::findOne('member', '(username = ? OR email = ?) AND status = ?', [$login, $login, 'active']);

            if (!$member || !password_verify($password, $member->password)) {
                $this->logger->warning('Failed login attempt', ['login' => $login]);
                $this->flash('error', 'Invalid credentials');
                Flight::redirect('/auth/login');
                return;
            }

            // Ensure user has their database set up
            $this->ensureUserDatabase($member);

            // Update last login
            $member->last_login = date('Y-m-d H:i:s');
            $member->login_count = ($member->login_count ?? 0) + 1;
            R::store($member);

            // Regenerate session ID to prevent session fixation attacks
            session_regenerate_id(true);

            // Set session
            $_SESSION['member'] = $member->export();

            $this->logger->info('User logged in', ['id' => $member->id, 'username' => $member->username]);
            $this->flash('success', 'Welcome back, ' . ($member->display_name ?? $member->username ?? $member->email) . '!');

            Flight::redirect($redirect);

        } catch (Exception $e) {
            $this->handleException($e, 'Login failed');
        }
    }

    /**
     * Logout user
     */
    public function logout() {
        if (isset($_SESSION['member'])) {
            $this->logger->info('User logged out', ['id' => $_SESSION['member']['id']]);
        }

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

        Flight::redirect('/');
    }

    /**
     * Show registration form
     */
    public function register() {
        // Redirect if already logged in
        if (Flight::isLoggedIn()) {
            Flight::redirect('/dashboard');
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
            Flight::redirect('/dashboard');

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
        $this->render('auth/forgot', [
            'title' => 'Forgot Password'
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

            if (empty($email)) {
                $this->flash('error', 'Email is required');
                Flight::redirect('/auth/forgot');
                return;
            }

            $member = R::findOne('member', 'email = ? AND status = ?', [$email, 'active']);

            if ($member) {
                // Generate reset token
                $token = bin2hex(random_bytes(32));
                $member->reset_token = $token;
                $member->reset_expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                R::store($member);

                // Send reset email (implement your email service)
                $resetUrl = Flight::get('app.baseurl') . "/auth/reset?token={$token}";

                // TODO: Send email with $resetUrl
                $this->logger->info('Password reset requested', ['email' => $email]);
            }

            // Always show success to prevent email enumeration
            $this->flash('success', 'If the email exists, a reset link has been sent');
            Flight::redirect('/auth/login');

        } catch (Exception $e) {
            $this->handleException($e, 'Password reset failed');
        }
    }

    /**
     * Show reset password form
     */
    public function reset() {
        $token = $this->getParam('token');

        if (empty($token)) {
            $this->flash('error', 'Invalid reset link');
            Flight::redirect('/auth/login');
            return;
        }

        $member = R::findOne('member', 'reset_token = ? AND reset_expires > ?',
            [$token, date('Y-m-d H:i:s')]);

        if (!$member) {
            $this->flash('error', 'Invalid or expired reset link');
            Flight::redirect('/auth/login');
            return;
        }

        $this->render('auth/reset', [
            'title' => 'Reset Password',
            'token' => $token
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

            // Validate input
            if (empty($token) || empty($password)) {
                $this->flash('error', 'Invalid request');
                Flight::redirect('/auth/login');
                return;
            }

            if (strlen($password) < 8) {
                $this->flash('error', 'Password must be at least 8 characters');
                Flight::redirect("/auth/reset?token={$token}");
                return;
            }

            if ($password !== $password_confirm) {
                $this->flash('error', 'Passwords do not match');
                Flight::redirect("/auth/reset?token={$token}");
                return;
            }

            // Find member
            $member = R::findOne('member', 'reset_token = ? AND reset_expires > ?',
                [$token, date('Y-m-d H:i:s')]);

            if (!$member) {
                $this->flash('error', 'Invalid or expired reset link');
                Flight::redirect('/auth/login');
                return;
            }

            // Update password
            $member->password = password_hash($password, PASSWORD_DEFAULT);
            $member->reset_token = null;
            $member->reset_expires = null;
            R::store($member);

            $this->logger->info('Password reset completed', ['id' => $member->id]);

            $this->flash('success', 'Password reset successful! Please login with your new password');
            Flight::redirect('/auth/login');

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

            // Ensure user has their database set up
            $this->ensureUserDatabase($member);

            // Regenerate session ID to prevent session fixation attacks
            session_regenerate_id(true);

            // Set session
            $_SESSION['member'] = $member->export();

            $this->logger->info('User logged in via Google', [
                'id' => $member->id,
                'email' => $member->email
            ]);

            $this->flash('success', 'Welcome, ' . ($member->display_name ?? $member->username ?? $member->email) . '!');
            Flight::redirect('/dashboard');

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

            // Create jira_boards table
            $userDb->exec("
                CREATE TABLE IF NOT EXISTS jira_boards (
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

            // Create analysis_results table
            $userDb->exec("
                CREATE TABLE IF NOT EXISTS analysis_results (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    board_id INTEGER NOT NULL,
                    analysis_type TEXT NOT NULL,
                    content_json TEXT NOT NULL,
                    content_markdown TEXT,
                    issue_count INTEGER,
                    status_filter TEXT,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (board_id) REFERENCES jira_boards(id) ON DELETE CASCADE
                )
            ");

            // Create digest_history table
            $userDb->exec("
                CREATE TABLE IF NOT EXISTS digest_history (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    board_id INTEGER NOT NULL,
                    sent_to TEXT NOT NULL,
                    subject TEXT,
                    content_preview TEXT,
                    status TEXT DEFAULT 'sent',
                    error_message TEXT,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (board_id) REFERENCES jira_boards(id) ON DELETE CASCADE
                )
            ");

            // Create user_settings table
            $userDb->exec("
                CREATE TABLE IF NOT EXISTS user_settings (
                    key TEXT PRIMARY KEY,
                    value TEXT,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            ");

            // Create indexes
            $userDb->exec("CREATE INDEX IF NOT EXISTS idx_boards_cloud ON jira_boards(cloud_id)");
            $userDb->exec("CREATE INDEX IF NOT EXISTS idx_boards_enabled ON jira_boards(enabled)");
            $userDb->exec("CREATE INDEX IF NOT EXISTS idx_boards_digest ON jira_boards(digest_enabled)");
            $userDb->exec("CREATE INDEX IF NOT EXISTS idx_analysis_board ON analysis_results(board_id)");
            $userDb->exec("CREATE INDEX IF NOT EXISTS idx_analysis_created ON analysis_results(created_at DESC)");
            $userDb->exec("CREATE INDEX IF NOT EXISTS idx_digest_board ON digest_history(board_id)");

            // Insert default settings
            $userDb->exec("INSERT OR IGNORE INTO user_settings (key, value) VALUES ('digest_email', '')");
            $userDb->exec("INSERT OR IGNORE INTO user_settings (key, value) VALUES ('default_status_filter', 'To Do')");
            $userDb->exec("INSERT OR IGNORE INTO user_settings (key, value) VALUES ('default_digest_time', '08:00')");

            $userDb->close();

            $this->logger->info('Created user database', [
                'member_id' => $member->id,
                'db_hash' => $dbHash
            ]);
        }
    }
}
