<?php
/**
 * Authentication Controller
 * Handles login, logout, registration, password reset, and Google OAuth
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\Bean;
use \app\plugins\GoogleAuth;
use \app\WorkspaceResolver;

// Load Google Auth plugin
require_once __DIR__ . '/../lib/plugins/GoogleAuth.php';

// Load Mailer Service
require_once __DIR__ . '/../services/MailerService.php';

class Auth extends BaseControls\Control {

    /**
     * Show login form
     * Canonical URL: /login or /login/{workspace}
     */
    public function login($params = null) {
        // Redirect /auth/login to /login (clean URLs)
        $isAuthRoute = !isset($params['workspace']) && isset($params['operation']);
        if ($isAuthRoute) {
            $workspace = $this->opId() ?? '';
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

        // Sanitize workspace name - only allow alphanumeric, hyphens, underscores
        if (!empty($workspace)) {
            $workspace = preg_replace('/[^a-zA-Z0-9_-]/', '', $workspace);
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
            Flight::redirect('/studio');
            return;
        }

        // If logged in but redirected here due to permission issues, show a message
        if (Flight::isLoggedIn() && !empty($redirect)) {
            $this->flash('error', 'You do not have permission to access that page.');
        }

        // Validate workspace if provided
        $workspaceError = null;
        $requestedWorkspace = $workspace; // Save original before clearing
        if (!empty($workspace) && !WorkspaceResolver::workspaceExists($workspace)) {
            $workspaceError = "Workspace '{$workspace}' not found";
            $workspace = '';
        }

        $this->render('auth/login', [
            'title' => 'Login',
            'redirect' => $redirect,
            'workspace' => $workspace,
            'requestedWorkspace' => $requestedWorkspace,
            'workspaceError' => $workspaceError,
            'googleEnabled' => GoogleAuth::isConfigured() && WorkspaceResolver::isDefault()
        ]);
    }

    /**
     * Process login
     * Supports workspace-based multi-workspace authentication
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
            $redirect = $request->data->redirect ?? '/studio';

            // Use username if provided, otherwise use email
            $login = $username ?: $email;

            // Validate input
            if (empty($login) || empty($password)) {
                $this->flash('error', 'Email and password are required');
                $this->redirectToLogin($workspace);
                return;
            }

            if (strlen($password) > 128) {
                $this->flash('error', 'Invalid credentials');
                $this->redirectToLogin($workspace);
                return;
            }

            // If workspace provided, switch to workspace database
            if (!empty($workspace)) {
                if (!WorkspaceResolver::workspaceExists($workspace)) {
                    $this->flash('error', "Workspace '{$workspace}' not found");
                    Flight::redirect('/auth/login');
                    return;
                }

                // Switch to workspace database
                if (!$this->switchToWorkspaceDatabase($workspace)) {
                    $this->flash('error', 'Could not connect to workspace. Please try again.');
                    Flight::redirect('/auth/login');
                    return;
                }
            }

            // Find member by username or email (in current database - workspace or default)
            $member = Bean::findOne('member', '(username = ? OR email = ?)', [$login, $login]);

            if (!$member || !password_verify($password, $member->password)) {
                $this->logger->warning('Failed login attempt', ['login' => $login, 'workspace' => $workspace ?: 'default']);
                $this->flash('error', 'Invalid credentials');
                $this->redirectToLogin($workspace);
                return;
            }

            // Check if account is pending verification
            if ($member->status === 'pending') {
                $this->flash('error', 'Please verify your email address before logging in. Check your inbox for the verification link.');
                $this->redirectToLogin($workspace);
                return;
            }

            // Check if account is active
            if ($member->status !== 'active') {
                $this->flash('error', 'Your account is not active. Please contact support.');
                $this->redirectToLogin($workspace);
                return;
            }

            // Update last login
            $member->last_login = date('Y-m-d H:i:s');
            $member->login_count = ($member->login_count ?? 0) + 1;
            Bean::store($member);

            // Regenerate session ID to prevent session fixation attacks
            session_regenerate_id(true);

            // Set session
            $_SESSION['member'] = $member->export();

            // Store workspace in session for workspace logins
            if (!empty($workspace)) {
                WorkspaceResolver::setWorkspace($workspace);
                $this->logger->info('User logged in to workspace', [
                    'id' => $member->id,
                    'username' => $member->username,
                    'workspace' => $workspace
                ]);
            } else {
                $this->logger->info('User logged in', ['id' => $member->id, 'username' => $member->username]);
            }

            $this->flash('success', 'Welcome back, ' . ($member->display_name ?? $member->username ?? $member->email) . '!');

            // If workspace login, redirect to workspace subdomain
            if (!empty($workspace)) {
                $workspaceUrl = \app\services\SiteConfig::getWorkspaceUrl($workspace);
                Flight::redirect("{$workspaceUrl}{$redirect}");
            } else {
                Flight::redirect($redirect);
            }

        } catch (Exception $e) {
            $this->handleException($e, 'Login failed');
        }
    }

    /**
     * Switch to a workspace's database
     *
     * @param string $workspace Workspace workspace code
     * @return bool True on success
     */
    private function switchToWorkspaceDatabase(string $workspace): bool {
        return WorkspaceResolver::switchDatabase($workspace);
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
            // Verify workspace config exists
            if (WorkspaceResolver::workspaceExists($subdomain)) {
                return $subdomain;
            }
        }

        return '';
    }

    /**
     * Logout user
     */
    public function logout() {
        $workspace = WorkspaceResolver::getSessionWorkspace();

        if (isset($_SESSION['member'])) {
            $this->logger->info('User logged out', [
                'id' => $_SESSION['member']['id'],
                'workspace' => $workspace ?: 'default'
            ]);
        }

        // Clear workspace from session
        WorkspaceResolver::clearWorkspace();

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
        // Non-workspace (main site) should use /signup for workspace registration
        if (WorkspaceResolver::isDefault()) {
            Flight::redirect('/signup');
            return;
        }

        // Redirect if already logged in
        if (Flight::isLoggedIn()) {
            Flight::redirect('/studio');
            return;
        }

        $this->render('auth/register', [
            'title' => 'Register',
            'googleEnabled' => GoogleAuth::isConfigured()
        ]);
    }

    /**
     * Process registration - requires email verification
     */
    public function doregister() {
        // Non-workspace (main site) should use /signup for workspace registration
        if (WorkspaceResolver::isDefault()) {
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
        if (strlen($password) > 128) {
            $errors[] = 'Password must not exceed 128 characters';
        }

        if ($password !== $password_confirm) {
            $errors[] = 'Passwords do not match';
        }

        // Check if email exists (active members only)
        $existingMember = Bean::findOne('member', 'email = ?', [$email]);
        if ($existingMember) {
            if ($existingMember->status === 'pending') {
                $errors[] = 'Email already registered - please check your inbox for verification email';
            } else {
                $errors[] = 'Email already registered';
            }
        }

        // Check if username exists
        if (Bean::count('member', 'username = ?', [$username]) > 0) {
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
            // Generate verification token
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

            // Create member with pending status
            $member = Bean::dispense('member');
            $member->email = $email;
            $member->username = $username;
            $member->password = password_hash($password, PASSWORD_DEFAULT);
            $member->level = LEVELS['MEMBER'];
            $member->status = 'pending';  // Requires email verification
            $member->verification_token = $token;
            $member->verification_expires_at = $expiresAt;
            $member->created_at = date('Y-m-d H:i:s');

            $id = Bean::store($member);

            Flight::get('log')->info('Pending member registration', ['id' => $id, 'email' => $email]);

            // Send verification email
            $this->sendMemberVerificationEmail($email, $username, $token);

            // Redirect to pending page
            $this->render('auth/register_pending', [
                'title' => 'Verify Your Email',
                'email' => $email
            ]);

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
     * Verify member email and activate account
     */
    public function verifymember() {
        $token = $this->getParam('token');

        // Get workspace from query param (added by subdomain redirect) or session
        $workspace = $this->getParam('workspace') ?? WorkspaceResolver::getSessionWorkspace() ?? '';
        $loginUrl = $workspace ? "/login/{$workspace}" : '/login';
        $registerUrl = $workspace ? "/auth/register?workspace={$workspace}" : '/register';

        if (empty($token)) {
            $this->flash('error', 'Invalid verification link.');
            Flight::redirect($loginUrl);
            return;
        }

        // Find pending member by token
        $member = Bean::findOne('member', 'verification_token = ? AND status = ?', [$token, 'pending']);

        if (!$member) {
            $this->flash('error', 'Invalid or expired verification link. Please register again.');
            Flight::redirect($registerUrl);
            return;
        }

        // Check if token expired
        if (strtotime($member->verification_expires_at) < time()) {
            // Delete expired pending member
            Bean::trash($member);
            $this->flash('error', 'Verification link has expired. Please register again.');
            Flight::redirect($registerUrl);
            return;
        }

        try {
            // Activate the member
            $member->status = 'active';
            $member->verification_token = null;
            $member->verification_expires_at = null;
            $member->verified_at = date('Y-m-d H:i:s');
            Bean::store($member);

            Flight::get('log')->info('Member verified and activated', ['id' => $member->id, 'email' => $member->email]);

            // Auto-login after verification
            session_regenerate_id(true);
            $_SESSION['member'] = $member->export();

            // Set workspace in session for workspace logins
            if (!empty($workspace)) {
                WorkspaceResolver::setWorkspace($workspace);
            }

            $this->flash('success', 'Your email has been verified! Welcome to ' . Flight::get('app.name') . '!');
            Flight::redirect('/studio');

        } catch (\Exception $e) {
            Flight::get('log')->error('Member verification failed: ' . $e->getMessage());
            $this->flash('error', 'Verification failed. Please try again.');
            Flight::redirect($registerUrl);
        }
    }

    /**
     * Send member verification email
     */
    private function sendMemberVerificationEmail(string $email, string $username, string $token): void {
        $workspace = Flight::get('workspace.slug');
        $appName = Flight::get('app.name');

        // Build verification URL for this workspace
        if (!empty($workspace)) {
            // Use workspace subdomain URL
            $protocol = Flight::get('app.protocol') ?? 'https';
            $baseDomain = Flight::get('app.domain') ?? 'myctobot.ai';
            $baseUrl = "{$protocol}://{$workspace}.{$baseDomain}";
        } else {
            $baseUrl = Flight::get('app.base_url');
        }

        $verifyUrl = "{$baseUrl}/auth/verifymember?token={$token}";

        $subject = "Verify your email - {$appName}";

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .button { display: inline-block; padding: 12px 24px; background-color: #0d6efd; color: white !important; text-decoration: none; border-radius: 6px; margin: 20px 0; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Welcome to {$appName}!</h2>
        <p>Hi {$username},</p>
        <p>Thank you for registering. Please verify your email address to activate your account.</p>
        <p>
            <a href="{$verifyUrl}" class="button">Verify Email Address</a>
        </p>
        <p>Or copy and paste this link into your browser:</p>
        <p style="word-break: break-all; color: #666;">{$verifyUrl}</p>
        <p>This link will expire in 24 hours.</p>
        <div class="footer">
            <p>If you didn't create an account, you can safely ignore this email.</p>
        </div>
    </div>
</body>
</html>
HTML;

        $textBody = <<<TEXT
Welcome to {$appName}!

Hi {$username},

Thank you for registering. Please verify your email address to activate your account.

Click the link below to verify:
{$verifyUrl}

This link will expire in 24 hours.

If you didn't create an account, you can safely ignore this email.
TEXT;

        try {
            $mailer = new \app\services\MailerService();
            $mailer->send($email, $subject, $htmlBody, $textBody);
        } catch (\Exception $e) {
            Flight::get('log')->error('Failed to send verification email', [
                'email' => $email,
                'error' => $e->getMessage()
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

            // If workspace provided, switch to workspace database
            $member = null;
            $connectedToWorkspace = false;
            if (!empty($workspace)) {
                if ($this->switchToWorkspaceDatabase($workspace)) {
                    $connectedToWorkspace = true;
                    $member = Bean::findOne('member', 'email = ? AND status = ?', [$email, 'active']);
                    $this->logger->debug('Member lookup in workspace DB', ['found' => $member ? true : false, 'workspace' => $workspace]);
                }
            } else {
                $member = Bean::findOne('member', 'email = ? AND status = ?', [$email, 'active']);
            }

            if ($member) {
                // Generate reset token and store (still connected to workspace DB if workspace was provided)
                $token = bin2hex(random_bytes(32));
                $member->reset_token = $token;
                $member->reset_expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                Bean::store($member);

                $this->logger->debug('Reset token stored', ['member_id' => $member->id, 'workspace' => $workspace]);

                // Now switch back to default DB
                if ($connectedToWorkspace) {
                    Bean::selectDatabase('default');
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

        // If workspace provided, switch to workspace database
        if (!empty($workspace)) {
            if (!$this->switchToWorkspaceDatabase($workspace)) {
                $this->flash('error', 'Invalid workspace');
                Flight::redirect('/auth/login');
                return;
            }
        }

        $member = Bean::findOne('member', 'reset_token = ? AND reset_expires > ?',
            [$token, date('Y-m-d H:i:s')]);

        if (!empty($workspace)) {
            Bean::selectDatabase('default');
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

            if (strlen($password) > 128) {
                $this->flash('error', 'Password must not exceed 128 characters');
                Flight::redirect($resetUrl);
                return;
            }

            if ($password !== $password_confirm) {
                $this->flash('error', 'Passwords do not match');
                Flight::redirect($resetUrl);
                return;
            }

            // If workspace provided, switch to workspace database
            if (!empty($workspace)) {
                if (!$this->switchToWorkspaceDatabase($workspace)) {
                    $this->flash('error', 'Invalid workspace');
                    Flight::redirect('/auth/login');
                    return;
                }
            }

            // Find member
            $member = Bean::findOne('member', 'reset_token = ? AND reset_expires > ?',
                [$token, date('Y-m-d H:i:s')]);

            if (!$member) {
                if (!empty($workspace)) {
                    Bean::selectDatabase('default');
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
            Bean::store($member);

            if (!empty($workspace)) {
                Bean::selectDatabase('default');
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
            $redirect = $_SESSION['oauth_redirect'] ?? '/studio';
            unset($_SESSION['oauth_workspace'], $_SESSION['oauth_redirect']);

            // If workspace specified, switch to workspace database
            if (!empty($workspace)) {
                if (!WorkspaceResolver::workspaceExists($workspace)) {
                    $this->flash('error', "Workspace '{$workspace}' not found");
                    Flight::redirect('/auth/login');
                    return;
                }

                if (!$this->switchToWorkspaceDatabase($workspace)) {
                    $this->flash('error', 'Could not connect to workspace');
                    Flight::redirect('/auth/login');
                    return;
                }

                // Re-find member in workspace database
                $workspaceMember = Bean::findOne('member', '(email = ? OR google_eid = ?) AND status = ?',
                    [$member->email, $member->google_eid, 'active']);

                if (!$workspaceMember) {
                    $this->flash('error', 'Your Google account is not registered in this workspace');
                    Flight::redirect("/login/{$workspace}");
                    return;
                }
                $member = $workspaceMember;

                // Set workspace in session
                WorkspaceResolver::setWorkspace($workspace);
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
     * Accept invitation - handles both GET (show form) and POST (process)
     * URL: /auth/invite/{token}?workspace=xxx
     */
    public function invite($params = []) {
        require_once __DIR__ . '/../services/InviteService.php';

        $request = Flight::request();
        $token = $this->opId() ?? '';
        $workspace = $request->query->workspace ?? $request->data->workspace ?? '';

        if (empty($token)) {
            $this->render('auth/invite_error', [
                'title' => 'Invalid Invitation',
                'error' => 'No invitation token provided'
            ]);
            return;
        }

        // If workspace specified, switch database context
        if (!empty($workspace)) {
            if (!WorkspaceResolver::workspaceExists($workspace)) {
                $this->render('auth/invite_error', [
                    'title' => 'Invalid Workspace',
                    'error' => "Workspace '{$workspace}' not found"
                ]);
                return;
            }

            if (!$this->switchToWorkspaceDatabase($workspace)) {
                $this->render('auth/invite_error', [
                    'title' => 'Connection Error',
                    'error' => 'Could not connect to workspace database'
                ]);
                return;
            }
        }

        $inviteService = new \app\services\InviteService();

        // Handle POST - process the form
        if ($request->method === 'POST') {
            $password = $request->data->password ?? '';
            $confirmPassword = $request->data->confirm_password ?? '';
            $displayName = trim($request->data->display_name ?? '');

            if (empty($password) || strlen($password) < 8) {
                $this->flash('error', 'Password must be at least 8 characters');
                $redirectUrl = "/auth/invite/{$token}" . ($workspace ? "?workspace={$workspace}" : '');
                Flight::redirect($redirectUrl);
                return;
            }

            if (strlen($password) > 128) {
                $this->flash('error', 'Password must not exceed 128 characters');
                $redirectUrl = "/auth/invite/{$token}" . ($workspace ? "?workspace={$workspace}" : '');
                Flight::redirect($redirectUrl);
                return;
            }

            if ($password !== $confirmPassword) {
                $this->flash('error', 'Passwords do not match');
                $redirectUrl = "/auth/invite/{$token}" . ($workspace ? "?workspace={$workspace}" : '');
                Flight::redirect($redirectUrl);
                return;
            }

            // Accept the invitation
            $result = $inviteService->acceptInvite($token, $password, $displayName ?: null);

            if (!$result['success']) {
                $this->flash('error', $result['error']);
                $redirectUrl = "/auth/invite/{$token}" . ($workspace ? "?workspace={$workspace}" : '');
                Flight::redirect($redirectUrl);
                return;
            }

            $member = $result['member'];

            $this->logger->info('User accepted invitation', [
                'member_id' => $member->id,
                'email' => $member->email,
                'workspace' => $workspace ?: 'default'
            ]);

            // Regenerate session ID to prevent session fixation attacks
            session_regenerate_id(true);

            // Set workspace in session if applicable
            if (!empty($workspace)) {
                WorkspaceResolver::setWorkspace($workspace);
            }

            // Auto-login the user
            $_SESSION['member'] = $member->export();

            $this->flash('success', 'Welcome! Your account has been activated.');

            // Redirect to workspace-specific URL if applicable
            if (!empty($workspace)) {
                // Build workspace URL from base domain
                $baseDomain = Flight::get('app.domain') ?? 'myctobot.ai';
                $protocol = Flight::get('app.protocol') ?? 'https';
                Flight::redirect("{$protocol}://{$workspace}.{$baseDomain}/dashboard");
            } else {
                Flight::redirect('/dashboard');
            }
            return;
        }

        // Handle GET - show the form
        $validation = $inviteService->validateToken($token, $workspace);

        if (!$validation['valid']) {
            $this->render('auth/invite_error', [
                'title' => 'Invalid Invitation',
                'error' => $validation['error'],
                'workspace' => $workspace
            ]);
            return;
        }

        $member = $validation['member'];

        // Get workspace display name for the form
        $workspaceDisplayName = '';
        if (!empty($workspace)) {
            $setting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['company_name']);
            $workspaceDisplayName = $setting ? $setting->setting_value : ucwords(str_replace(['-', '_'], ' ', $workspace));
        }

        $this->render('auth/accept_invite', [
            'title' => 'Accept Invitation',
            'token' => $token,
            'workspace' => $workspace,
            'workspaceDisplayName' => $workspaceDisplayName,
            'email' => $member->email,
            'displayName' => $member->display_name
        ]);
    }
}
