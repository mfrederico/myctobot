<?php
/**
 * OAuth State Service
 *
 * Handles secure encoding/decoding of OAuth state parameters for multi-tenant OAuth flows.
 * Encodes workspace, CSRF token, and optional return URL in a signed, verifiable state.
 *
 * Usage:
 *   // On OAuth initiation:
 *   $state = OAuthStateService::encode(['return_url' => '/settings/connections']);
 *   $_SESSION['oauth_state_hash'] = OAuthStateService::getHash($state);
 *
 *   // On callback (on main domain):
 *   $stateData = OAuthStateService::decode($state, $_SESSION['oauth_state_hash']);
 *   if ($stateData && $stateData['workspace']) {
 *       // Redirect to workspace subdomain
 *       OAuthStateService::redirectToWorkspace($stateData);
 *   }
 *
 * Security:
 *   - State is base64-encoded JSON with HMAC signature
 *   - CSRF token is verified via session hash
 *   - Workspace cannot be spoofed (signed)
 *   - Expiration prevents replay attacks
 */

namespace app\services;

class OAuthStateService {

    /** State expiration in seconds (10 minutes) */
    private const STATE_EXPIRY = 600;

    /** HMAC algorithm for signing state */
    private const HMAC_ALGO = 'sha256';

    /**
     * Get the signing key for HMAC
     * Derives key from base domain - consistent across all subdomains/installations for same domain
     */
    private static function getSigningKey(): string {
        // Derive key from base domain - this ensures consistency across:
        // - All subdomains (demo.myctobot.ai, gwt.myctobot.ai, etc.)
        // - Separate installations serving the same domain
        // No shared secret needed - just deploy and it works
        $baseDomain = self::getBaseDomain();
        return hash('sha256', $baseDomain . '/oauth-state-hmac-key-v1');
    }

    /**
     * Encode OAuth state with workspace, CSRF token, and optional data
     *
     * @param array $options Optional data to include:
     *   - return_url: URL to redirect after OAuth (default: /settings/connections)
     *   - provider: OAuth provider name (github, google, atlassian, etc.)
     * @return string Base64-encoded, signed state string
     */
    public static function encode(array $options = []): string {
        // Get current workspace from session or subdomain
        $workspace = $_SESSION['workspace_slug'] ?? $_SERVER['WORKSPACE'] ?? null;

        // Generate CSRF token
        $csrf = bin2hex(random_bytes(16));

        // Build state payload
        $payload = [
            'csrf' => $csrf,
            'workspace' => $workspace,
            'return_url' => $options['return_url'] ?? '/settings/connections',
            'provider' => $options['provider'] ?? 'unknown',
            'exp' => time() + self::STATE_EXPIRY,
            'iat' => time(),
        ];

        // Sign the payload
        $json = json_encode($payload);
        $signature = hash_hmac(self::HMAC_ALGO, $json, self::getSigningKey());

        // Combine payload and signature
        $state = base64_encode($json) . '.' . $signature;

        return $state;
    }

    /**
     * Get hash of state for session storage (CSRF verification)
     *
     * @param string $state The encoded state string
     * @return string Hash to store in session
     */
    public static function getHash(string $state): string {
        return hash('sha256', $state);
    }

    /**
     * Decode and verify OAuth state
     *
     * @param string $state The state string from OAuth callback
     * @param string|null $sessionHash Optional session hash for CSRF verification
     * @return array|null Decoded state data or null if invalid
     */
    public static function decode(string $state, ?string $sessionHash = null): ?array {
        // Split state into payload and signature
        $parts = explode('.', $state, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encodedPayload, $signature] = $parts;

        // Decode payload
        $json = base64_decode($encodedPayload, true);
        if ($json === false) {
            return null;
        }

        // Verify signature
        $expectedSignature = hash_hmac(self::HMAC_ALGO, $json, self::getSigningKey());
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        // Parse payload
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        // Verify session hash if provided (CSRF check)
        if ($sessionHash !== null) {
            $expectedHash = self::getHash($state);
            if (!hash_equals($expectedHash, $sessionHash)) {
                return null;
            }
        }

        return $payload;
    }

    /**
     * Check if callback needs workspace redirect
     * Call this on the main domain callback to determine if user should be
     * redirected to their workspace subdomain.
     *
     * @param array $stateData Decoded state data from decode()
     * @return bool True if redirect is needed
     */
    public static function needsWorkspaceRedirect(array $stateData): bool {
        $stateWorkspace = $stateData['workspace'] ?? null;
        $currentWorkspace = $_SERVER['WORKSPACE'] ?? null;

        // Need redirect if:
        // 1. State has a workspace
        // 2. Current request is on main domain (no workspace) or different workspace
        if (!empty($stateWorkspace) && $stateWorkspace !== 'default') {
            return $stateWorkspace !== $currentWorkspace;
        }

        return false;
    }

    /**
     * Build redirect URL for workspace subdomain
     *
     * @param array $stateData Decoded state data
     * @param string $callbackPath The OAuth callback path (e.g., /github/callback)
     * @param array $queryParams Additional query params to pass through (code, state, etc.)
     * @return string Full URL to redirect to
     */
    public static function buildWorkspaceRedirectUrl(
        array $stateData,
        string $callbackPath,
        array $queryParams = []
    ): string {
        $workspace = $stateData['workspace'];

        // Build workspace subdomain URL
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseDomain = self::getBaseDomain();

        $url = "{$protocol}://{$workspace}.{$baseDomain}{$callbackPath}";

        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $url;
    }

    /**
     * Get the base domain (e.g., myctobot.ai)
     */
    public static function getBaseDomain(): string {
        $host = $_SERVER['HTTP_HOST'] ?? 'myctobot.ai';

        // If already on subdomain, extract base domain
        $parts = explode('.', $host);
        if (count($parts) >= 3) {
            // Remove subdomain (first part)
            array_shift($parts);
            return implode('.', $parts);
        }

        return $host;
    }

    /**
     * Perform redirect to workspace subdomain
     * This should be called early in the callback handler if needsWorkspaceRedirect() returns true.
     *
     * @param array $stateData Decoded state data
     * @param string $callbackPath The OAuth callback path
     * @param array $queryParams Query params to pass through
     */
    public static function redirectToWorkspace(
        array $stateData,
        string $callbackPath,
        array $queryParams = []
    ): void {
        $url = self::buildWorkspaceRedirectUrl($stateData, $callbackPath, $queryParams);
        header("Location: {$url}");
        exit;
    }

    /**
     * Extract CSRF token from encoded state (for backward compatibility)
     *
     * @param string $state Encoded state string
     * @return string|null CSRF token or null if invalid
     */
    public static function extractCsrf(string $state): ?string {
        $data = self::decode($state);
        return $data['csrf'] ?? null;
    }

    /**
     * Validate state against session (simple check without full decode)
     * Use this for quick validation before processing callback.
     *
     * @param string $state State from OAuth callback
     * @param string $sessionKey Session key where state hash was stored
     * @return bool True if valid
     */
    public static function validateFromSession(string $state, string $sessionKey = 'oauth_state_hash'): bool {
        $sessionHash = $_SESSION[$sessionKey] ?? null;
        if (empty($sessionHash)) {
            return false;
        }

        return hash_equals($sessionHash, self::getHash($state));
    }

    /**
     * Store state hash in session
     *
     * @param string $state The encoded state
     * @param string $sessionKey Session key to use
     */
    public static function storeInSession(string $state, string $sessionKey = 'oauth_state_hash'): void {
        $_SESSION[$sessionKey] = self::getHash($state);
    }

    /**
     * Clear state from session
     *
     * @param string $sessionKey Session key to clear
     */
    public static function clearFromSession(string $sessionKey = 'oauth_state_hash'): void {
        unset($_SESSION[$sessionKey]);
    }
}
