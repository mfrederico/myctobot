<?php
/**
 * Signup Controller - Tenant Registration
 *
 * Handles new business/tenant registration from the public site.
 * Creates a new subdomain, database, and admin user for the tenant.
 *
 * Flow: Form → Email Verification → Provision Tenant
 *
 * Only accessible from the default (public) site, not from tenant subdomains.
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\services\TenantProvisioner;
use \app\services\MailgunService;

class Signup extends BaseControls\Control {

    /**
     * Show the tenant signup form
     */
    public function index() {
        // Only allow signup from the main/public site
        if (!TenantResolver::isDefault()) {
            Flight::redirect('/auth/login');
            return;
        }

        $this->render('signup/index', [
            'title' => 'Get Started - Create Your Team',
            'data' => []
        ]);
    }

    /**
     * AJAX endpoint to check subdomain availability
     */
    public function checksubdomain() {
        $subdomain = $this->getParam('subdomain', '');

        if (empty($subdomain)) {
            Flight::json(['available' => false, 'error' => 'Subdomain is required']);
            return;
        }

        try {
            // Get provisioner credentials from config
            $adminHost = Flight::get('database.host') ?? 'localhost';
            $adminUser = Flight::get('provisioner.db_user') ?? Flight::get('database.user');
            $adminPass = Flight::get('provisioner.db_pass') ?? Flight::get('database.pass');

            $provisioner = new TenantProvisioner($adminHost, $adminUser, $adminPass);
            $result = $provisioner->validateSubdomain($subdomain);

            // Also check if subdomain is pending verification
            if ($result['valid']) {
                $pending = R::findOne('pendingsignup', 'subdomain = ?', [$subdomain]);
                if ($pending) {
                    $result = ['valid' => false, 'error' => 'This subdomain is reserved (pending verification)'];
                }
            }

            Flight::json([
                'available' => $result['valid'],
                'error' => $result['error'],
                'subdomain' => strtolower(trim($subdomain)),
                'url' => $result['valid'] ? "https://{$subdomain}.myctobot.ai" : null
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Subdomain check failed', ['error' => $e->getMessage()]);
            Flight::json(['available' => false, 'error' => 'Unable to check availability']);
        }
    }

    /**
     * Process tenant signup - stores pending signup and sends verification email
     */
    public function dosignup() {
        // Only allow signup from the main/public site
        if (!TenantResolver::isDefault()) {
            Flight::jsonError('Signup not available', 403);
            return;
        }

        // CSRF validation
        if (!$this->validateCSRF()) {
            $this->flash('error', 'Security validation failed. Please try again.');
            Flight::redirect('/signup');
            return;
        }

        // Get form data
        $businessName = trim($this->getParam('business_name', ''));
        $subdomain = strtolower(trim($this->getParam('subdomain', '')));
        $email = trim($this->getParam('email', ''));
        $password = $this->getParam('password', '');
        $passwordConfirm = $this->getParam('password_confirm', '');

        // Validate
        $errors = [];

        if (empty($businessName)) {
            $errors[] = 'Business name is required';
        }
        if (strlen($businessName) > 100) {
            $errors[] = 'Business name must be 100 characters or less';
        }

        if (empty($subdomain)) {
            $errors[] = 'Subdomain is required';
        }

        if (empty($email)) {
            $errors[] = 'Email is required';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address';
        }

        if (empty($password)) {
            $errors[] = 'Password is required';
        }
        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters';
        }
        if ($password !== $passwordConfirm) {
            $errors[] = 'Passwords do not match';
        }

        if (!empty($errors)) {
            $this->render('signup/index', [
                'title' => 'Get Started - Create Your Team',
                'errors' => $errors,
                'data' => [
                    'business_name' => $businessName,
                    'subdomain' => $subdomain,
                    'email' => $email
                ]
            ]);
            return;
        }

        try {
            // Validate subdomain with provisioner
            $adminHost = Flight::get('database.host') ?? 'localhost';
            $adminUser = Flight::get('provisioner.db_user') ?? Flight::get('database.user');
            $adminPass = Flight::get('provisioner.db_pass') ?? Flight::get('database.pass');

            $provisioner = new TenantProvisioner($adminHost, $adminUser, $adminPass);
            $validation = $provisioner->validateSubdomain($subdomain);

            if (!$validation['valid']) {
                $this->render('signup/index', [
                    'title' => 'Get Started - Create Your Team',
                    'errors' => [$validation['error']],
                    'data' => [
                        'business_name' => $businessName,
                        'subdomain' => $subdomain,
                        'email' => $email
                    ]
                ]);
                return;
            }

            // Check if subdomain is already pending
            $existing = R::findOne('pendingsignup', 'subdomain = ?', [$subdomain]);
            if ($existing) {
                // Delete old pending signup to allow retry
                R::trash($existing);
            }

            // Generate verification token
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

            // Store pending signup
            $pending = R::dispense('pendingsignup');
            $pending->subdomain = $subdomain;
            $pending->business_name = $businessName;
            $pending->email = $email;
            $pending->password_hash = password_hash($password, PASSWORD_DEFAULT);
            $pending->verification_token = $token;
            $pending->expires_at = $expiresAt;
            $pending->resend_count = 0;
            R::store($pending);

            // Send verification email
            $this->sendVerificationEmail($email, $businessName, $subdomain, $token);

            $this->logger->info('Pending signup created', [
                'subdomain' => $subdomain,
                'business' => $businessName,
                'email' => $email
            ]);

            // Redirect to pending page
            Flight::redirect('/signup/pending?email=' . urlencode($email));

        } catch (\Exception $e) {
            $this->logger->error('Signup failed', [
                'subdomain' => $subdomain,
                'error' => $e->getMessage()
            ]);

            $this->render('signup/index', [
                'title' => 'Get Started - Create Your Team',
                'errors' => ['An error occurred during signup. Please try again or contact support.'],
                'data' => [
                    'business_name' => $businessName,
                    'subdomain' => $subdomain,
                    'email' => $email
                ]
            ]);
        }
    }

    /**
     * Show the "check your email" page
     */
    public function pending() {
        $email = $this->getParam('email', '');

        // Mask email for display (m***@example.com)
        $maskedEmail = '';
        if ($email) {
            $parts = explode('@', $email);
            if (count($parts) === 2) {
                $local = $parts[0];
                $domain = $parts[1];
                $maskedEmail = substr($local, 0, 1) . str_repeat('*', max(3, strlen($local) - 1)) . '@' . $domain;
            }
        }

        $this->render('signup/pending', [
            'title' => 'Check Your Email',
            'email' => $email,
            'maskedEmail' => $maskedEmail
        ]);
    }

    /**
     * Verify email and provision tenant
     * URL: /signup/verify/{token}
     */
    public function verify($params) {
        $token = $params['operation']->name ?? '';

        if (empty($token)) {
            $this->render('signup/verify_error', [
                'title' => 'Invalid Link',
                'error' => 'Invalid verification link.'
            ]);
            return;
        }

        // Find pending signup by token
        $pending = R::findOne('pendingsignup', 'verification_token = ?', [$token]);

        if (!$pending) {
            $this->render('signup/verify_error', [
                'title' => 'Invalid Link',
                'error' => 'This verification link is invalid or has already been used.'
            ]);
            return;
        }

        // Check if expired
        if (strtotime($pending->expires_at) < time()) {
            $this->render('signup/verify_error', [
                'title' => 'Link Expired',
                'error' => 'This verification link has expired.',
                'email' => $pending->email,
                'canResend' => true
            ]);
            return;
        }

        try {
            // Get provisioner credentials from config
            $adminHost = Flight::get('database.host') ?? 'localhost';
            $adminUser = Flight::get('provisioner.db_user') ?? Flight::get('database.user');
            $adminPass = Flight::get('provisioner.db_pass') ?? Flight::get('database.pass');

            $provisioner = new TenantProvisioner($adminHost, $adminUser, $adminPass);

            // Provision the tenant (password is already hashed, need to pass plain for provisioner)
            // We stored the hash, so we need a workaround - provision with a temp password
            // then update it directly
            $tempPassword = bin2hex(random_bytes(16));
            $result = $provisioner->provision(
                $pending->subdomain,
                $pending->business_name,
                $pending->email,
                $tempPassword
            );

            if (!$result['success']) {
                $this->render('signup/verify_error', [
                    'title' => 'Provisioning Failed',
                    'error' => $result['error']
                ]);
                return;
            }

            // Update the password hash directly in the new tenant database
            $this->updatePasswordHash(
                $result['database'],
                $result['db_user'],
                $pending->password_hash,
                $pending->email
            );

            // Delete the pending signup
            R::trash($pending);

            $this->logger->info('Tenant provisioned after email verification', [
                'subdomain' => $pending->subdomain,
                'business' => $pending->business_name,
                'email' => $pending->email,
                'database' => $result['database']
            ]);

            // Show success page
            $this->render('signup/success', [
                'title' => 'Welcome to MyCTOBot!',
                'tenant' => $result
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Tenant provisioning failed after verification', [
                'subdomain' => $pending->subdomain,
                'error' => $e->getMessage()
            ]);

            $this->render('signup/verify_error', [
                'title' => 'Error',
                'error' => 'An error occurred while setting up your workspace. Please contact support.'
            ]);
        }
    }

    /**
     * Resend verification email
     */
    public function resend() {
        $email = trim($this->getParam('email', ''));

        if (empty($email)) {
            Flight::json(['success' => false, 'error' => 'Email is required']);
            return;
        }

        $pending = R::findOne('pendingsignup', 'email = ?', [$email]);

        if (!$pending) {
            Flight::json(['success' => false, 'error' => 'No pending signup found for this email']);
            return;
        }

        // Rate limit: max 3 resends per hour
        if ($pending->resend_count >= 3) {
            $lastResend = strtotime($pending->last_resend_at ?? '2000-01-01');
            if (time() - $lastResend < 3600) {
                Flight::json(['success' => false, 'error' => 'Too many resend requests. Please try again later.']);
                return;
            }
            // Reset count after an hour
            $pending->resend_count = 0;
        }

        // Generate new token and extend expiry
        $token = bin2hex(random_bytes(32));
        $pending->verification_token = $token;
        $pending->expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $pending->resend_count = $pending->resend_count + 1;
        $pending->last_resend_at = date('Y-m-d H:i:s');
        R::store($pending);

        // Send verification email
        $this->sendVerificationEmail($pending->email, $pending->business_name, $pending->subdomain, $token);

        $this->logger->info('Verification email resent', [
            'email' => $email,
            'subdomain' => $pending->subdomain,
            'resend_count' => $pending->resend_count
        ]);

        Flight::json(['success' => true, 'message' => 'Verification email sent']);
    }

    /**
     * Send the verification email
     */
    private function sendVerificationEmail(string $email, string $businessName, string $subdomain, string $token): void {
        $mailgun = new MailgunService();

        if (!$mailgun->isEnabled()) {
            $this->logger->warning('Mailgun not configured, skipping verification email', [
                'email' => $email,
                'token' => $token
            ]);
            return;
        }

        $verifyUrl = "https://myctobot.ai/signup/verify/{$token}";

        $html = <<<HTML
<div style="max-width: 600px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <div style="text-align: center; padding: 30px 0;">
        <h1 style="color: #2c3e50; margin-bottom: 10px;">Welcome to MyCTOBot!</h1>
        <p style="color: #7f8c8d; font-size: 16px;">Just one more step to get started</p>
    </div>

    <div style="background: #f8f9fa; border-radius: 8px; padding: 30px; margin-bottom: 20px;">
        <p style="font-size: 16px; color: #333; margin-bottom: 20px;">
            Hi there! Thanks for signing up <strong>{$businessName}</strong>.
        </p>
        <p style="font-size: 16px; color: #333; margin-bottom: 20px;">
            Your workspace will be available at:<br>
            <strong style="color: #3498db; font-size: 18px;">{$subdomain}.myctobot.ai</strong>
        </p>
        <p style="font-size: 16px; color: #333; margin-bottom: 30px;">
            Click the button below to verify your email and activate your workspace:
        </p>
        <div style="text-align: center;">
            <a href="{$verifyUrl}"
               style="display: inline-block; background: #3498db; color: white; padding: 15px 40px;
                      text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">
                Verify Email Address
            </a>
        </div>
    </div>

    <p style="color: #999; font-size: 14px; text-align: center;">
        This link will expire in 24 hours.<br>
        If you didn't create this account, you can safely ignore this email.
    </p>

    <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">

    <p style="color: #999; font-size: 12px; text-align: center;">
        If the button doesn't work, copy and paste this URL into your browser:<br>
        <a href="{$verifyUrl}" style="color: #3498db; word-break: break-all;">{$verifyUrl}</a>
    </p>
</div>
HTML;

        $plainText = <<<TEXT
Welcome to MyCTOBot!

Thanks for signing up {$businessName}.

Your workspace will be available at: {$subdomain}.myctobot.ai

Click the link below to verify your email and activate your workspace:

{$verifyUrl}

This link will expire in 24 hours.

If you didn't create this account, you can safely ignore this email.
TEXT;

        $mailgun->send($email, 'Verify your MyCTOBot account', $html, $plainText);
    }

    /**
     * Update password hash directly in tenant database
     * (Since TenantProvisioner hashes the password, we need to overwrite with our pre-hashed version)
     */
    private function updatePasswordHash(string $dbName, string $dbUser, string $passwordHash, string $email): void {
        // Extract subdomain from dbUser (mctb_subdomain -> subdomain)
        $subdomain = str_replace('mctb_', '', $dbUser);
        $configPath = dirname(__DIR__) . "/conf/config.{$subdomain}.ini";

        // Read the tenant's config file to get the database password
        if (!file_exists($configPath)) {
            throw new \Exception("Tenant config not found: {$configPath}");
        }

        $config = parse_ini_file($configPath, true);
        $dbPass = $config['database']['pass'] ?? '';

        if (empty($dbPass)) {
            throw new \Exception("Database password not found in tenant config");
        }

        // Connect to tenant database
        $dsn = "mysql:host=localhost;dbname={$dbName};charset=utf8mb4";
        $db = new \PDO($dsn, $dbUser, $dbPass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
        ]);

        // Update the password hash
        $stmt = $db->prepare("UPDATE member SET password = ? WHERE email = ?");
        $stmt->execute([$passwordHash, $email]);
    }
}
