<?php
/**
 * Google Stitch Client Service
 *
 * Handles API calls to Google Stitch AI design tool via its MCP server.
 * Loads credentials from unified `connections` table (connector_type='stitch').
 * Access token auto-refreshes before expiry (5 min buffer).
 */

namespace app\services;

use \Flight as Flight;
use \GuzzleHttp\Client;
use \app\Bean;
use \Exception;

require_once __DIR__ . '/EncryptionService.php';

class StitchClient {

    private $httpClient;
    private $connectionId;
    private $accessToken;
    private $refreshToken;
    private $expiresAt;
    private $gcpProjectId;
    private $mcpEndpoint;

    /**
     * Create StitchClient
     *
     * @param int|null $connectionId Connection ID from unified connections table
     */
    public function __construct(?int $connectionId = null) {
        $this->connectionId = $connectionId;
        $this->httpClient = new Client([
            'timeout' => 120,
            'http_errors' => false
        ]);

        $config = self::getApiConfig();
        $this->mcpEndpoint = $config['mcp_endpoint'] ?? 'https://stitch.googleapis.com/mcp';

        if ($connectionId) {
            $this->loadCredentials($connectionId);
        }
    }

    /**
     * Load credentials from unified connections table
     */
    private function loadCredentials(int $connectionId): void {
        try {
            $conn = Bean::load('connections', $connectionId);
            if (!$conn->id || $conn->connector_type !== 'stitch') {
                return;
            }
            $this->accessToken = EncryptionService::decrypt($conn->access_token);
            $this->refreshToken = !empty($conn->refresh_token) ? EncryptionService::decrypt($conn->refresh_token) : null;
            $this->expiresAt = $conn->expires_at;
            $metadata = json_decode($conn->metadata_json ?: '{}', true);
            $this->gcpProjectId = $metadata['gcp_project_id'] ?? '';
        } catch (Exception $e) {
            // Credentials not available
        }
    }

    /**
     * Check if connected (has tokens)
     */
    public function isConnected(): bool {
        return !empty($this->accessToken) && !empty($this->refreshToken);
    }

    /**
     * Get a valid access token, auto-refreshing if expired
     *
     * @return string|null Valid access token or null
     */
    public function getValidToken(): ?string {
        if (empty($this->accessToken) || empty($this->refreshToken)) {
            return null;
        }

        // Check if token expires within 5 minutes
        if (!empty($this->expiresAt) && strtotime($this->expiresAt) < time() + 300) {
            if (!$this->refreshAccessToken()) {
                return null;
            }
        }

        return $this->accessToken;
    }

    /**
     * Refresh the access token using the refresh token
     */
    private function refreshAccessToken(): bool {
        require_once __DIR__ . '/connectors/StitchConnector.php';
        $config = \app\services\connectors\StitchConnector::loadOAuthConfig();

        try {
            $response = $this->httpClient->post('https://oauth2.googleapis.com/token', [
                'form_params' => [
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'refresh_token' => $this->refreshToken,
                    'grant_type' => 'refresh_token',
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if ($response->getStatusCode() !== 200 || empty($body['access_token'])) {
                return false;
            }

            // Update stored credentials
            $this->accessToken = $body['access_token'];
            $expiresIn = $body['expires_in'] ?? 3600;
            $this->expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

            // Update database
            if ($this->connectionId) {
                $conn = Bean::load('connections', $this->connectionId);
                if ($conn->id && $conn->connector_type === 'stitch') {
                    $conn->access_token = EncryptionService::encrypt($this->accessToken);
                    $conn->expires_at = $this->expiresAt;
                    $conn->updated_at = date('Y-m-d H:i:s');
                    Bean::store($conn);
                }
            }

            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Test the connection by listing projects
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function testConnection(): array {
        if (!$this->isConnected()) {
            return ['success' => false, 'message' => 'Not connected'];
        }

        try {
            $result = $this->callMcpTool('list_projects');

            if (!empty($result['error'])) {
                return [
                    'success' => false,
                    'message' => 'API error: ' . ($result['error']['message'] ?? 'Unknown error')
                ];
            }

            $projectCount = count($result['result']['content'] ?? []);
            return [
                'success' => true,
                'message' => "Connected. {$projectCount} project(s) found.",
                'data' => $result['result'] ?? null
            ];

        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Call a Google Stitch MCP tool
     *
     * @param string $tool Tool name (e.g., list_projects, generate_screen_from_text)
     * @param array $arguments Tool arguments
     * @return array JSON-RPC response
     */
    public function callMcpTool(string $tool, array $arguments = []): array {
        $token = $this->getValidToken();
        if (!$token) {
            throw new Exception('No valid access token available. Please reconnect.');
        }

        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => $tool,
                'arguments' => !empty($arguments) ? $arguments : (object)[],
            ],
            'id' => uniqid('stitch_'),
        ];

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ];

        if (!empty($this->gcpProjectId)) {
            $headers['X-Goog-User-Project'] = $this->gcpProjectId;
        }

        $response = $this->httpClient->post($this->mcpEndpoint, [
            'headers' => $headers,
            'json' => $payload,
        ]);

        $statusCode = $response->getStatusCode();
        $body = json_decode($response->getBody()->getContents(), true);

        if ($statusCode !== 200) {
            throw new Exception('Stitch API error: HTTP ' . $statusCode . ' - ' . ($body['error']['message'] ?? 'Unknown'));
        }

        return $body;
    }

    /**
     * List available MCP tools from the Stitch server
     */
    public function listTools(): array {
        $token = $this->getValidToken();
        if (!$token) {
            throw new Exception('No valid access token available.');
        }

        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'params' => (object)[],
            'id' => uniqid('stitch_'),
        ];

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ];

        if (!empty($this->gcpProjectId)) {
            $headers['X-Goog-User-Project'] = $this->gcpProjectId;
        }

        $response = $this->httpClient->post($this->mcpEndpoint, [
            'headers' => $headers,
            'json' => $payload,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    // =========================================================================
    // AUTHENTICATED URL PROXY
    // =========================================================================

    /**
     * Download content from an authenticated Stitch URL
     *
     * Proxies requests to Google CDN/content URLs that require Bearer token auth.
     * Validates URL domain against allowlist to prevent SSRF.
     *
     * @param string $url The URL to download
     * @return array ['success' => bool, 'content' => string, 'content_type' => string]
     */
    public function downloadAuthenticatedUrl(string $url): array {
        $allowedDomains = [
            'contribution.usercontent.google.com',
            'lh3.googleusercontent.com',
            'stitch.googleapis.com',
        ];

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        $domainAllowed = false;
        foreach ($allowedDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                $domainAllowed = true;
                break;
            }
        }

        if (!$domainAllowed) {
            return ['success' => false, 'error' => 'URL domain not in allowlist: ' . $host];
        }

        $token = $this->getValidToken();
        if (!$token) {
            return ['success' => false, 'error' => 'No valid access token available'];
        }

        $hasQueryAuth = !empty($parsed['query']); // signed URLs have query params

        // Pre-signed URLs (with query params) work without Bearer token.
        // Bearer token actually causes 403 (insufficient_scope) on Google CDN.
        // Non-signed URLs need Bearer token.
        $attempts = $hasQueryAuth
            ? [[], ['Authorization' => 'Bearer ' . $token]]
            : [['Authorization' => 'Bearer ' . $token]];

        try {
            foreach ($attempts as $i => $headers) {
                $response = $this->httpClient->get($url, [
                    'headers' => $headers,
                    'allow_redirects' => true,
                ]);

                $statusCode = $response->getStatusCode();
                if ($statusCode === 200) {
                    return [
                        'success' => true,
                        'content' => $response->getBody()->getContents(),
                        'content_type' => $response->getHeaderLine('Content-Type') ?: 'text/html',
                    ];
                }

                // Consume body to allow retry
                $response->getBody()->getContents();
            }

            // All attempts failed - log the last failure
            $logger = \Flight::get('log');
            $logger->warning('CDN download failed', [
                'status' => $statusCode,
                'url_host' => $host,
                'attempts' => count($attempts),
            ]);

            return [
                'success' => false,
                'error' => 'HTTP ' . $statusCode . ' from CDN',
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // CONVENIENCE WRAPPERS (matches official Stitch MCP tools)
    // =========================================================================

    public function listProjects(?string $filter = null): array {
        $args = [];
        if ($filter !== null) {
            $args['filter'] = $filter;
        }
        return $this->callMcpTool('list_projects', $args);
    }

    public function createProject(?string $title = null): array {
        $args = [];
        if ($title !== null) {
            $args['title'] = $title;
        }
        return $this->callMcpTool('create_project', $args);
    }

    public function getProject(string $name): array {
        return $this->callMcpTool('get_project', ['name' => $name]);
    }

    public function listScreens(string $projectId): array {
        return $this->callMcpTool('list_screens', ['projectId' => $projectId]);
    }

    public function getScreen(string $name, string $projectId, string $screenId): array {
        return $this->callMcpTool('get_screen', [
            'name' => $name,
            'projectId' => $projectId,
            'screenId' => $screenId,
        ]);
    }

    /**
     * Generate a new screen from a text prompt
     *
     * @param string $projectId Project ID (numeric, no prefix)
     * @param string $prompt Text description of the UI to generate
     * @param string $deviceType MOBILE|DESKTOP|TABLET|AGNOSTIC
     * @param string $modelId GEMINI_3_PRO|GEMINI_3_FLASH
     */
    public function generateScreen(string $projectId, string $prompt, string $deviceType = 'DESKTOP', string $modelId = 'GEMINI_3_FLASH'): array {
        return $this->callMcpTool('generate_screen_from_text', [
            'projectId' => $projectId,
            'prompt' => $prompt,
            'deviceType' => $deviceType,
            'modelId' => $modelId,
        ]);
    }

    /**
     * Edit existing screens with a text prompt
     *
     * @param string $projectId Project ID
     * @param array $screenIds Array of screen IDs to edit
     * @param string $prompt Edit instructions
     * @param string $deviceType MOBILE|DESKTOP|TABLET|AGNOSTIC
     * @param string $modelId GEMINI_3_PRO|GEMINI_3_FLASH
     */
    public function editScreens(string $projectId, array $screenIds, string $prompt, string $deviceType = 'DESKTOP', string $modelId = 'GEMINI_3_FLASH'): array {
        return $this->callMcpTool('edit_screens', [
            'projectId' => $projectId,
            'selectedScreenIds' => $screenIds,
            'prompt' => $prompt,
            'deviceType' => $deviceType,
            'modelId' => $modelId,
        ]);
    }

    /**
     * Generate variants of existing screens
     *
     * @param string $projectId Project ID
     * @param array $screenIds Screen IDs to create variants of
     * @param string $prompt Variant instructions
     * @param array $variantOptions Options: variantCount (1-5), creativeRange (REFINE|EXPLORE|REIMAGINE), aspects[]
     */
    public function generateVariants(string $projectId, array $screenIds, string $prompt, array $variantOptions = []): array {
        if (empty($variantOptions)) {
            $variantOptions = ['variantCount' => 3, 'creativeRange' => 'EXPLORE'];
        }
        return $this->callMcpTool('generate_variants', [
            'projectId' => $projectId,
            'selectedScreenIds' => $screenIds,
            'prompt' => $prompt,
            'variantOptions' => $variantOptions,
        ]);
    }

    // =========================================================================
    // STATIC METHODS FOR CONNECTION MANAGEMENT
    // =========================================================================

    /**
     * Get all Stitch connections visible to a member
     * Queries unified connections table with connector_type='stitch'
     *
     * @param int|null $memberId Member ID (null = all connections)
     * @return array Array of connection beans
     */
    public static function getAllConnections(?int $memberId = null): array {
        if ($memberId === null) {
            return Bean::find('connections', ' connector_type = ? ORDER BY created_at DESC ', ['stitch']);
        }
        return Bean::find('connections',
            ' connector_type = ? AND (member_id = ? OR shared = 1) ORDER BY created_at DESC ',
            ['stitch', $memberId]
        );
    }

    /**
     * Get a connection by ID
     * Queries unified connections table with connector_type='stitch'
     *
     * @param int $id Connection ID
     * @return object|null Connection bean or null
     */
    public static function getConnection(int $id): ?object {
        $conn = Bean::load('connections', $id);
        return ($conn->id && $conn->connector_type === 'stitch') ? $conn : null;
    }

    /**
     * Create a new Stitch connection (writes to unified connections table)
     *
     * @param array $data Connection data
     * @return int New connection ID
     */
    public static function createConnection(array $data): int {
        $conn = Bean::dispense('connections');
        $conn->connector_type = 'stitch';
        $conn->member_id = $data['member_id'];
        $conn->connection_name = $data['connection_name'] ?? 'Google Stitch';
        $conn->auth_type = 'oauth';
        $conn->access_token = EncryptionService::encrypt($data['access_token']);
        $conn->refresh_token = EncryptionService::encrypt($data['refresh_token']);
        $conn->token_type = $data['token_type'] ?? 'Bearer';
        $conn->expires_at = $data['expires_at'] ?? date('Y-m-d H:i:s', time() + 3600);
        $conn->external_eid = $data['google_email'] ?? '';
        $conn->external_name = $data['google_email'] ?? 'Google Stitch';
        $conn->enabled = 1;
        $conn->shared = $data['shared'] ?? 0;
        $conn->metadata_json = json_encode([
            'gcp_project_id' => $data['gcp_project_id'] ?? '',
            'google_email' => $data['google_email'] ?? '',
        ]);
        $conn->created_at = date('Y-m-d H:i:s');
        $conn->updated_at = date('Y-m-d H:i:s');
        return Bean::store($conn);
    }

    /**
     * Delete a connection
     * Queries unified connections table with connector_type='stitch'
     *
     * @param int $id Connection ID
     * @return bool True if deleted
     */
    public static function deleteConnection(int $id): bool {
        $conn = Bean::load('connections', $id);
        if (!$conn->id || $conn->connector_type !== 'stitch') {
            return false;
        }
        Bean::trash($conn);
        return true;
    }

    /**
     * Get API configuration from conf/stitch.ini
     */
    private static function getApiConfig(): array {
        $config = [
            'mcp_endpoint' => 'https://stitch.googleapis.com/mcp',
        ];

        $basePath = dirname(__DIR__);
        $iniFile = $basePath . '/conf/stitch.ini';
        if (file_exists($iniFile)) {
            $iniData = parse_ini_file($iniFile, true);
            $apiSection = $iniData['api'] ?? [];
            if (!empty($apiSection['mcp_endpoint'])) {
                $config['mcp_endpoint'] = $apiSection['mcp_endpoint'];
            }
        }

        return $config;
    }
}
