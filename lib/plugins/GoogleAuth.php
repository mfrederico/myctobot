<?php
/**
 * Google OAuth 2.0 Authentication Plugin
 *
 * Provides Google Sign-In functionality for tiknix applications.
 *
 * Configuration (conf/config.ini):
 * [social]
 * google_client_id = "your-client-id.apps.googleusercontent.com"
 * google_client_secret = "your-client-secret"
 * google_redirect_uri = "https://yourapp.com/auth/googlecallback"
 *
 * Usage in Auth controller:
 *   public function google() {
 *       $url = \app\plugins\GoogleAuth::getLoginUrl();
 *       Flight::redirect($url);
 *   }
 *
 *   public function googlecallback() {
 *       $result = \app\plugins\GoogleAuth::handleCallback($_GET['code'] ?? null);
 *       if ($result['success']) {
 *           // $result['user'] contains Google user data
 *           // $result['member'] contains the database member (if found/created)
 *       }
 *   }
 */

namespace app\plugins;

use \Flight as Flight;
use \RedBeanPHP\R as R;

class GoogleAuth {

    private static $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth';
    private static $tokenUrl = 'https://oauth2.googleapis.com/token';
    private static $userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo';

    /**
     * Check if Google OAuth is configured
     */
    public static function isConfigured(): bool {
        $clientId = Flight::get('social.google_client_id');
        $clientSecret = Flight::get('social.google_client_secret');
        return !empty($clientId) && !empty($clientSecret);
    }

    /**
     * Get the Google OAuth login URL
     *
     * @param string|null $state Optional state parameter for CSRF protection
     * @return string The authorization URL
     */
    public static function getLoginUrl(?string $state = null): string {
        $clientId = Flight::get('social.google_client_id');
        $redirectUri = Flight::get('social.google_redirect_uri');

        if (empty($clientId) || empty($redirectUri)) {
            throw new \RuntimeException('Google OAuth not configured. Set social.google_client_id and social.google_redirect_uri in config.');
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
            'scope' => 'openid email profile',
            'access_type' => 'online',
            'state' => $state,
            'prompt' => 'select_account'
        ];

        return self::$authUrl . '?' . http_build_query($params);
    }

    /**
     * Handle the OAuth callback
     *
     * @param string|null $code The authorization code from Google
     * @param string|null $state The state parameter for CSRF validation
     * @return array Result with 'success', 'user', 'member', 'error' keys
     */
    public static function handleCallback(?string $code, ?string $state = null): array {
        try {
            // Validate state for CSRF protection
            if ($state !== null && isset($_SESSION['google_oauth_state'])) {
                if ($state !== $_SESSION['google_oauth_state']) {
                    throw new \RuntimeException('Invalid state parameter - possible CSRF attack');
                }
                unset($_SESSION['google_oauth_state']);
            }

            if (empty($code)) {
                throw new \RuntimeException('No authorization code received from Google');
            }

            // Exchange code for tokens
            $tokens = self::exchangeCode($code);

            if (empty($tokens['access_token'])) {
                throw new \RuntimeException('Failed to get access token from Google');
            }

            // Get user info
            $googleUser = self::getUserInfo($tokens['access_token']);

            if (empty($googleUser['id']) || empty($googleUser['email'])) {
                throw new \RuntimeException('Failed to get user info from Google');
            }

            // Find or create member
            $member = self::findOrCreateMember($googleUser);

            return [
                'success' => true,
                'user' => $googleUser,
                'member' => $member,
                'tokens' => $tokens
            ];

        } catch (\Exception $e) {
            Flight::get('log')->error('Google OAuth error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Exchange authorization code for tokens
     */
    private static function exchangeCode(string $code): array {
        $clientId = Flight::get('social.google_client_id');
        $clientSecret = Flight::get('social.google_client_secret');
        $redirectUri = Flight::get('social.google_redirect_uri');

        $postData = [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code'
        ];

        $ch = curl_init(self::$tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException("Token exchange failed with HTTP {$httpCode}");
        }

        return json_decode($response, true) ?: [];
    }

    /**
     * Get user info from Google
     */
    private static function getUserInfo(string $accessToken): array {
        $ch = curl_init(self::$userInfoUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}"]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException("Failed to get user info with HTTP {$httpCode}");
        }

        return json_decode($response, true) ?: [];
    }

    /**
     * Find existing member or create new one from Google user data
     *
     * @param array $googleUser Google user info
     * @return object RedBean member bean
     */
    public static function findOrCreateMember(array $googleUser): object {
        $googleId = $googleUser['id'];
        $email = $googleUser['email'];

        // Try to find by Google ID first
        $member = R::findOne('member', 'google_id = ?', [$googleId]);

        if ($member) {
            // Update last login
            $member->last_login = date('Y-m-d H:i:s');
            $member->login_count = ($member->login_count ?? 0) + 1;

            // Update avatar if changed
            if (!empty($googleUser['picture'])) {
                $member->avatar_url = $googleUser['picture'];
            }

            R::store($member);
            return $member;
        }

        // Try to find by email (link existing account)
        $member = R::findOne('member', 'email = ?', [$email]);

        if ($member) {
            // Link Google ID to existing account
            $member->google_id = $googleId;
            $member->last_login = date('Y-m-d H:i:s');
            $member->login_count = ($member->login_count ?? 0) + 1;

            if (!empty($googleUser['picture']) && empty($member->avatar_url)) {
                $member->avatar_url = $googleUser['picture'];
            }

            R::store($member);

            Flight::get('log')->info('Linked Google account to existing member', [
                'member_id' => $member->id,
                'google_id' => $googleId
            ]);

            return $member;
        }

        // Create new member
        $member = R::dispense('member');
        $member->google_id = $googleId;
        $member->email = $email;
        $member->username = self::generateUsername($googleUser);
        $member->display_name = $googleUser['name'] ?? '';
        $member->avatar_url = $googleUser['picture'] ?? '';
        $member->level = LEVELS['MEMBER'] ?? 100;
        $member->status = 'active';
        $member->created_at = date('Y-m-d H:i:s');
        $member->last_login = date('Y-m-d H:i:s');
        $member->login_count = 1;

        // No password for OAuth users
        $member->password = null;

        $id = R::store($member);
        $member->id = $id;

        Flight::get('log')->info('New member created via Google OAuth', [
            'member_id' => $id,
            'email' => $email,
            'google_id' => $googleId
        ]);

        return $member;
    }

    /**
     * Generate a unique username from Google user data
     */
    private static function generateUsername(array $googleUser): string {
        // Try email prefix first
        $email = $googleUser['email'] ?? '';
        $base = strstr($email, '@', true) ?: 'user';

        // Clean up
        $base = preg_replace('/[^a-zA-Z0-9]/', '', $base);
        $base = strtolower(substr($base, 0, 20));

        if (empty($base)) {
            $base = 'user';
        }

        // Check if username exists
        $username = $base;
        $counter = 1;

        while (R::count('member', 'username = ?', [$username]) > 0) {
            $username = $base . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Get Google user info for the currently logged in member
     * Useful for displaying profile info
     */
    public static function getMemberGoogleInfo(int $memberId): ?array {
        $member = R::load('member', $memberId);

        if (!$member || empty($member->google_id)) {
            return null;
        }

        return [
            'google_id' => $member->google_id,
            'email' => $member->email,
            'display_name' => $member->display_name,
            'avatar_url' => $member->avatar_url
        ];
    }
}
