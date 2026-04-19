<?php
/**
 * Shopify Theme Service
 *
 * Handles theme operations for Shopify stores including:
 * - Script tag injection (for widgets, tracking, etc.)
 * - Theme asset reading/updating
 * - Banner uploads for flash sales, promotions
 *
 * This service is designed to be used by pipelines and agents
 * for automated Shopify store customization.
 */

namespace app\services;

use \GuzzleHttp\Client;
use \app\Bean;
use \Exception;

require_once __DIR__ . '/EncryptionService.php';
require_once __DIR__ . '/ShopifyClient.php';

class ShopifyThemeService {

    private Client $httpClient;
    private string $shop;
    private string $accessToken;
    private int $connectionId;
    private string $apiVersion = '2024-01';

    /**
     * Create service for a Shopify connection
     *
     * @param int $connectionId Shopify connection ID
     * @throws Exception if connection not found
     */
    public function __construct(int $connectionId) {
        $this->connectionId = $connectionId;

        $conn = Bean::load('connections', $connectionId);
        if (!$conn->id || $conn->connector_type !== 'shopify') {
            throw new Exception('Shopify connection not found');
        }

        $this->shop = $conn->external_eid;
        $this->accessToken = EncryptionService::decrypt($conn->access_token);

        $this->httpClient = new Client([
            'timeout' => 30,
            'http_errors' => false
        ]);
    }

    /**
     * Static factory method
     */
    public static function forConnection(int $connectionId): self {
        return new self($connectionId);
    }

    // =========================================================================
    // SCRIPT TAGS - For injecting JavaScript without modifying theme
    // =========================================================================

    /**
     * List all script tags for this store
     *
     * @return array List of script tags
     */
    public function listScriptTags(): array {
        $url = $this->buildUrl('/script_tags.json');
        $response = $this->get($url);

        return $response['script_tags'] ?? [];
    }

    /**
     * Create a script tag
     *
     * @param string $src URL of the script to inject
     * @param string $event When to load: 'onload' (default) or 'DOMContentLoaded'
     * @return array Created script tag data
     */
    public function createScriptTag(string $src, string $event = 'onload'): array {
        $url = $this->buildUrl('/script_tags.json');

        $response = $this->post($url, [
            'script_tag' => [
                'event' => $event,
                'src' => $src
            ]
        ]);

        if (!isset($response['script_tag'])) {
            throw new Exception('Failed to create script tag: ' . json_encode($response));
        }

        return $response['script_tag'];
    }

    /**
     * Delete a script tag
     *
     * @param int $scriptTagId Script tag ID
     * @return bool Success
     */
    public function deleteScriptTag(int $scriptTagId): bool {
        $url = $this->buildUrl("/script_tags/{$scriptTagId}.json");
        $response = $this->delete($url);

        return true;
    }

    /**
     * Find script tag by URL pattern
     *
     * @param string $pattern URL pattern to search for
     * @return array|null Script tag if found
     */
    public function findScriptTagByUrl(string $pattern): ?array {
        $tags = $this->listScriptTags();

        foreach ($tags as $tag) {
            if (strpos($tag['src'], $pattern) !== false) {
                return $tag;
            }
        }

        return null;
    }

    /**
     * Install or update a widget script tag
     *
     * @param string $widgetUrl Full URL to widget script
     * @return array Script tag data (existing or newly created)
     */
    public function installWidget(string $widgetUrl): array {
        // Check if already exists
        $existing = $this->findScriptTagByUrl(parse_url($widgetUrl, PHP_URL_HOST));

        if ($existing) {
            // Update if URL changed
            if ($existing['src'] !== $widgetUrl) {
                $this->deleteScriptTag($existing['id']);
                return $this->createScriptTag($widgetUrl);
            }
            return $existing;
        }

        return $this->createScriptTag($widgetUrl);
    }

    /**
     * Remove a widget script tag
     *
     * @param string $urlPattern Pattern to match widget URL
     * @return bool True if removed, false if not found
     */
    public function removeWidget(string $urlPattern): bool {
        $tag = $this->findScriptTagByUrl($urlPattern);

        if ($tag) {
            $this->deleteScriptTag($tag['id']);
            return true;
        }

        return false;
    }

    // =========================================================================
    // THEMES - List and manage themes
    // =========================================================================

    /**
     * List all themes
     *
     * @return array List of themes
     */
    public function listThemes(): array {
        $url = $this->buildUrl('/themes.json');
        $response = $this->get($url);

        return $response['themes'] ?? [];
    }

    /**
     * Get the main (published) theme
     *
     * @return array|null Main theme or null
     */
    public function getMainTheme(): ?array {
        $themes = $this->listThemes();

        foreach ($themes as $theme) {
            if ($theme['role'] === 'main') {
                return $theme;
            }
        }

        return null;
    }

    /**
     * Get or create a development theme for a specific purpose
     *
     * @param string $identifier Unique identifier (e.g., ticket number)
     * @param string $purpose Description of the purpose
     * @return array Theme data
     */
    public function getOrCreateDevTheme(string $identifier, string $purpose): array {
        $themeName = "Dev: {$identifier} - {$purpose}";

        // Check if already exists
        $themes = $this->listThemes();
        foreach ($themes as $theme) {
            if ($theme['name'] === $themeName) {
                return $theme;
            }
        }

        // Create new theme by duplicating main theme
        // Note: This requires using the GraphQL API as REST doesn't support theme creation
        $client = new ShopifyClient($this->connectionId);
        return $client->getOrCreateDevTheme($identifier, $purpose);
    }

    // =========================================================================
    // THEME ASSETS - Read and update theme files
    // =========================================================================

    /**
     * Get a theme asset
     *
     * @param int $themeId Theme ID
     * @param string $key Asset key (e.g., 'layout/theme.liquid')
     * @return array Asset data including 'value' (content)
     */
    public function getAsset(int $themeId, string $key): array {
        $url = $this->buildUrl("/themes/{$themeId}/assets.json");

        $response = $this->httpClient->get($url, [
            'headers' => $this->getHeaders(),
            'query' => ['asset[key]' => $key]
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        if (!isset($data['asset'])) {
            throw new Exception("Asset not found: {$key}");
        }

        return $data['asset'];
    }

    /**
     * Update a theme asset
     *
     * Note: This may fail on some stores due to Shopify API restrictions.
     * Use script tags for JavaScript injection when possible.
     *
     * @param int $themeId Theme ID
     * @param string $key Asset key
     * @param string $value New content
     * @return array Updated asset data
     */
    public function updateAsset(int $themeId, string $key, string $value): array {
        $url = $this->buildUrl("/themes/{$themeId}/assets.json");

        $response = $this->httpClient->put($url, [
            'headers' => $this->getHeaders(),
            'json' => [
                'asset' => [
                    'key' => $key,
                    'value' => $value
                ]
            ]
        ]);

        $statusCode = $response->getStatusCode();
        $data = json_decode($response->getBody()->getContents(), true);

        if ($statusCode !== 200 || !isset($data['asset'])) {
            throw new Exception("Failed to update asset: " . json_encode($data));
        }

        return $data['asset'];
    }

    /**
     * Upload a file asset (image, etc.)
     *
     * @param int $themeId Theme ID
     * @param string $key Asset key (e.g., 'assets/banner.png')
     * @param string $filePath Local file path to upload
     * @return array Uploaded asset data
     */
    public function uploadAsset(int $themeId, string $key, string $filePath): array {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: {$filePath}");
        }

        $content = file_get_contents($filePath);
        $base64 = base64_encode($content);

        $url = $this->buildUrl("/themes/{$themeId}/assets.json");

        $response = $this->httpClient->put($url, [
            'headers' => $this->getHeaders(),
            'json' => [
                'asset' => [
                    'key' => $key,
                    'attachment' => $base64
                ]
            ]
        ]);

        $statusCode = $response->getStatusCode();
        $data = json_decode($response->getBody()->getContents(), true);

        if ($statusCode !== 200 || !isset($data['asset'])) {
            throw new Exception("Failed to upload asset: " . json_encode($data));
        }

        return $data['asset'];
    }

    /**
     * Delete a theme asset
     *
     * @param int $themeId Theme ID
     * @param string $key Asset key
     * @return bool Success
     */
    public function deleteAsset(int $themeId, string $key): bool {
        $url = $this->buildUrl("/themes/{$themeId}/assets.json");

        $response = $this->httpClient->delete($url, [
            'headers' => $this->getHeaders(),
            'query' => ['asset[key]' => $key]
        ]);

        return $response->getStatusCode() === 200;
    }

    // =========================================================================
    // BANNER / PROMO HELPERS - For flash sales, promotions, etc.
    // =========================================================================

    /**
     * Upload a promotional banner image
     *
     * @param string $filePath Local path to banner image
     * @param string $name Banner name (will be sanitized)
     * @param int|null $themeId Theme ID (defaults to main theme)
     * @return array Asset data with public URL
     */
    public function uploadBanner(string $filePath, string $name, ?int $themeId = null): array {
        if (!$themeId) {
            $mainTheme = $this->getMainTheme();
            if (!$mainTheme) {
                throw new Exception('No main theme found');
            }
            $themeId = $mainTheme['id'];
        }

        // Sanitize filename
        $ext = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'png';
        $safeName = preg_replace('/[^a-z0-9-]/', '-', strtolower($name));
        $key = "assets/promo-{$safeName}.{$ext}";

        $asset = $this->uploadAsset($themeId, $key, $filePath);

        // Add the public URL
        $asset['public_url'] = "https://{$this->shop}/cdn/shop/t/{$themeId}/{$key}";

        return $asset;
    }

    /**
     * Inject a flash sale announcement bar
     *
     * Uses script tags to inject a dynamic announcement bar
     *
     * @param array $config Announcement config:
     *   - message: string - The announcement text
     *   - background_color: string - Background color (default: #ff0000)
     *   - text_color: string - Text color (default: #ffffff)
     *   - link_url: string|null - Optional link URL
     *   - expires_at: string|null - ISO datetime when to stop showing
     * @return array Script tag data
     */
    public function injectAnnouncementBar(array $config): array {
        // Build a data URL with the config embedded
        // This is a simple approach - for production you'd host the script properly
        $message = urlencode($config['message'] ?? 'Sale!');
        $bgColor = urlencode($config['background_color'] ?? '#ff0000');
        $textColor = urlencode($config['text_color'] ?? '#ffffff');
        $link = urlencode($config['link_url'] ?? '');
        $expires = urlencode($config['expires_at'] ?? '');

        // Point to a hosted announcement script with parameters
        $scriptUrl = "https://dev.myctobot.ai/widget/announcement.js"
            . "?msg={$message}&bg={$bgColor}&color={$textColor}&link={$link}&expires={$expires}";

        return $this->installWidget($scriptUrl);
    }

    // =========================================================================
    // HTTP HELPERS
    // =========================================================================

    private function buildUrl(string $endpoint): string {
        return "https://{$this->shop}/admin/api/{$this->apiVersion}{$endpoint}";
    }

    private function getHeaders(): array {
        return [
            'X-Shopify-Access-Token' => $this->accessToken,
            'Content-Type' => 'application/json'
        ];
    }

    private function get(string $url, array $query = []): array {
        $options = ['headers' => $this->getHeaders()];
        if (!empty($query)) {
            $options['query'] = $query;
        }

        $response = $this->httpClient->get($url, $options);
        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    private function post(string $url, array $data): array {
        $response = $this->httpClient->post($url, [
            'headers' => $this->getHeaders(),
            'json' => $data
        ]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    private function delete(string $url): array {
        $response = $this->httpClient->delete($url, [
            'headers' => $this->getHeaders()
        ]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }
}
