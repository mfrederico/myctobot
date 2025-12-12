<?php
/**
 * Google OAuth Plugin for MyCTOBot
 * Handles Google Sign-In authentication
 *
 * Usage:
 * 1. Set google_client_id, google_client_secret, google_redirect_uri in config.ini
 * 2. Call GoogleAuth::getLoginUrl() to get the authorization URL
 * 3. Handle callback in your auth controller using GoogleAuth::handleCallback()
 */

namespace app\plugins;

use \Flight as Flight;
use \RedBeanPHP\R as R;

class GoogleAuth {

    private static $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth';
    private static $tokenUrl = 'https://oauth2.googleapis.com/token';
    private static $userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo';

    /**
     * Get Google OAuth authorization URL
     *
     * @param string $state Optional state parameter for CSRF protection
     * @return string The authorization URL
     */
    public static function getLoginUrl($state = null) {
        $clientId = Flight::get('social.google_client_id');
        $redirectUri = Flight::get('social.google_redirect_uri');

        if (empty($clientId) || empty($redirectUri)) {
            throw new \Exception('Google OAuth not configured. Set google_client_id and google_redirect_uri in config.ini');
        }

        // Generate state for CSRF protection if not provided
        if ($state === null) {
            $state = bin2hex(random_bytes(16));
            $_SESSION['google_oauth_state'] = $state;
        }

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account'
        ];

        return self::$authUrl . '?' . http_build_query($params);
    }

    /**
     * Handle OAuth callback and authenticate/create user
     *
     * @param string $code Authorization code from Google
     * @param string $state State parameter for CSRF verification
     * @return array|false User data on success, false on failure
     */
    public static function handleCallback($code, $state = null) {
        $logger = Flight::get('log');

        // Verify state parameter (CSRF protection)
        if ($state !== null && isset($_SESSION['google_oauth_state'])) {
            if ($state !== $_SESSION['google_oauth_state']) {
                $logger->warning('Google OAuth state mismatch');
                return false;
            }
            unset($_SESSION['google_oauth_state']);
        }

        // Exchange code for access token
        $tokens = self::getAccessToken($code);
        if (!$tokens || !isset($tokens['access_token'])) {
            $logger->error('Failed to get Google access token');
            return false;
        }

        // Get user info from Google
        $googleUser = self::getUserInfo($tokens['access_token']);
        if (!$googleUser || !isset($googleUser['email'])) {
            $logger->error('Failed to get Google user info');
            return false;
        }

        $logger->info('Google user authenticated', ['email' => $googleUser['email']]);

        // Find or create user
        $member = self::findOrCreateUser($googleUser);

        return $member;
    }

    /**
     * Exchange authorization code for access token
     *
     * @param string $code Authorization code
     * @return array|false Token data or false on failure
     */
    private static function getAccessToken($code) {
        $clientId = Flight::get('social.google_client_id');
        $clientSecret = Flight::get('social.google_client_secret');
        $redirectUri = Flight::get('social.google_redirect_uri');

        $postData = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri
        ];

        $ch = curl_init(self::$tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            Flight::get('log')->error('Google token request failed', [
                'http_code' => $httpCode,
                'response' => $response
            ]);
            return false;
        }

        return json_decode($response, true);
    }

    /**
     * Get user info from Google using access token
     *
     * @param string $accessToken Google access token
     * @return array|false User info or false on failure
     */
    private static function getUserInfo($accessToken) {
        $ch = curl_init(self::$userInfoUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            Flight::get('log')->error('Google user info request failed', [
                'http_code' => $httpCode,
                'response' => $response
            ]);
            return false;
        }

        return json_decode($response, true);
    }

    /**
     * Find existing user or create new one from Google data
     *
     * @param array $googleUser Google user data
     * @return object|false Member bean or false on failure
     */
    private static function findOrCreateUser($googleUser) {
        $logger = Flight::get('log');
        $email = $googleUser['email'];
        $googleId = $googleUser['id'];

        // First try to find by Google ID
        $member = R::findOne('member', 'google_id = ?', [$googleId]);

        if (!$member) {
            // Try to find by email
            $member = R::findOne('member', 'email = ?', [$email]);

            if ($member) {
                // Link existing account with Google ID
                $member->google_id = $googleId;
                R::store($member);
                $logger->info('Linked existing account to Google', ['id' => $member->id]);
            }
        }

        if (!$member) {
            // Create new user - no email verification needed for Google auth
            $member = R::dispense('member');
            $member->email = $email;
            $member->username = self::generateUsername($googleUser);
            $member->password = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT); // Random password
            $member->google_id = $googleId;
            $member->level = LEVELS['MEMBER'];
            $member->status = 'active'; // Auto-activate - Google verified the email
            $member->created_at = date('Y-m-d H:i:s');
            $member->email_verified = 1; // Google verified

            // Store profile picture URL if available
            if (isset($googleUser['picture'])) {
                $member->avatar_url = $googleUser['picture'];
            }

            // Store name if available
            if (isset($googleUser['name'])) {
                $member->display_name = $googleUser['name'];
            }

            $id = R::store($member);
            $member->id = $id;

            $logger->info('Created new user from Google OAuth', [
                'id' => $id,
                'email' => $email
            ]);

            // Create user's unique SQLite database
            self::createUserDatabase($member);
        }

        // Update last login
        $member->last_login = date('Y-m-d H:i:s');
        $member->login_count = ($member->login_count ?? 0) + 1;
        R::store($member);

        return $member;
    }

    /**
     * Generate unique username from Google data
     *
     * @param array $googleUser Google user data
     * @return string Unique username
     */
    private static function generateUsername($googleUser) {
        // Try to use name first
        if (isset($googleUser['name'])) {
            $base = preg_replace('/[^a-zA-Z0-9]/', '', $googleUser['name']);
        } else {
            // Fall back to email prefix
            $base = explode('@', $googleUser['email'])[0];
            $base = preg_replace('/[^a-zA-Z0-9]/', '', $base);
        }

        $base = strtolower(substr($base, 0, 20));
        if (empty($base)) {
            $base = 'user';
        }

        // Check if username exists, append number if needed
        $username = $base;
        $counter = 1;

        while (R::count('member', 'username = ?', [$username]) > 0) {
            $username = $base . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Create user's unique SQLite database for Jira data
     *
     * @param object $member Member bean
     */
    private static function createUserDatabase($member) {
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
                digest_cc TEXT DEFAULT '',
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

        Flight::get('log')->info('Created user database', [
            'member_id' => $member->id,
            'db_hash' => $dbHash
        ]);
    }

    /**
     * Check if Google OAuth is configured
     *
     * @return bool
     */
    public static function isConfigured() {
        $clientId = Flight::get('social.google_client_id');
        $clientSecret = Flight::get('social.google_client_secret');
        $redirectUri = Flight::get('social.google_redirect_uri');

        return !empty($clientId) && !empty($clientSecret) && !empty($redirectUri);
    }
}
