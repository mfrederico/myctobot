<?php
/**
 * Plugin Scanner Service
 *
 * Scans configured repository sources to discover plugins based on markers (plugin.json).
 * Implements exponential backoff for rate limit handling.
 */

namespace app\services;

use \Flight as Flight;
use \app\Bean;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

require_once __DIR__ . '/GitHubClient.php';
require_once __DIR__ . '/../lib/Bean.php';

class PluginScannerService {

    /**
     * Maximum retry attempts for rate-limited requests
     */
    const MAX_RETRIES = 3;

    /**
     * Initial backoff delay in milliseconds (1 second)
     */
    const INITIAL_BACKOFF_MS = 1000;

    /**
     * Expected plugin.json fields for validation
     */
    const REQUIRED_PLUGIN_FIELDS = ['name', 'version'];
    const OPTIONAL_PLUGIN_FIELDS = ['description', 'author', 'main', 'requires'];

    private $logger;
    private $verbose;

    public function __construct(bool $verbose = false) {
        $this->logger = Flight::get('log');
        $this->verbose = $verbose;
    }

    /**
     * Scan all enabled repository sources for plugins
     *
     * @return array Scan results with statistics
     */
    public function scanAllSources(): array {
        $scanId = $this->startScan();

        $this->log("Starting full plugin scan (scan_uid: {$scanId})");

        $stats = [
            'scan_uid' => $scanId,
            'repos_scanned' => 0,
            'plugins_found' => 0,
            'errors_encountered' => 0,
            'errors' => [],
            'plugins' => []
        ];

        try {
            // Get all enabled repository connections
            $repos = Bean::find('repoconnections', 'enabled = ?', [1]);

            if (empty($repos)) {
                $this->log("No enabled repositories found");
                $this->completeScan($scanId, $stats);
                return $stats;
            }

            foreach ($repos as $repo) {
                try {
                    $result = $this->scanSingleRepository($repo, $scanId);

                    $stats['repos_scanned']++;

                    if ($result['plugin_found']) {
                        $stats['plugins_found']++;
                        $stats['plugins'][] = $result['plugin_data'];
                    }
                } catch (\Exception $e) {
                    $stats['errors_encountered']++;
                    $stats['errors'][] = [
                        'repo' => "{$repo->repo_owner}/{$repo->repo_name}",
                        'error' => $e->getMessage()
                    ];

                    $this->logger->warning("Error scanning repository", [
                        'repo' => "{$repo->repo_owner}/{$repo->repo_name}",
                        'error' => $e->getMessage()
                    ]);
                }

                // Update scan progress
                $this->updateScan($scanId, [
                    'repos_scanned' => $stats['repos_scanned'],
                    'plugins_found' => $stats['plugins_found'],
                    'errors_encountered' => $stats['errors_encountered']
                ]);
            }

            $this->completeScan($scanId, $stats);

            $this->log("Scan completed: {$stats['repos_scanned']} repos, {$stats['plugins_found']} plugins found");

        } catch (\Exception $e) {
            $this->failScan($scanId, $e->getMessage());
            throw $e;
        }

        return $stats;
    }

    /**
     * Scan a single repository connection for plugins
     *
     * @param int $repoConnectionId Repository connection ID
     * @return array Scan results for this repository
     */
    public function scanRepository(int $repoConnectionId): array {
        $repo = Bean::load('repoconnections', $repoConnectionId);

        if (!$repo || !$repo->id) {
            throw new \Exception("Repository connection not found: {$repoConnectionId}");
        }

        if (!$repo->enabled) {
            throw new \Exception("Repository connection is disabled: {$repoConnectionId}");
        }

        $scanId = $this->startScan($repoConnectionId);

        $this->log("Starting single repo scan for {$repo->repo_owner}/{$repo->repo_name} (scan_uid: {$scanId})");

        $stats = [
            'scan_uid' => $scanId,
            'repos_scanned' => 0,
            'plugins_found' => 0,
            'errors_encountered' => 0,
            'errors' => [],
            'plugins' => []
        ];

        try {
            $result = $this->scanSingleRepository($repo, $scanId);

            $stats['repos_scanned'] = 1;

            if ($result['plugin_found']) {
                $stats['plugins_found'] = 1;
                $stats['plugins'][] = $result['plugin_data'];
            }

            $this->completeScan($scanId, $stats);

        } catch (\Exception $e) {
            $stats['errors_encountered'] = 1;
            $stats['errors'][] = [
                'repo' => "{$repo->repo_owner}/{$repo->repo_name}",
                'error' => $e->getMessage()
            ];

            $this->failScan($scanId, $e->getMessage());
        }

        return $stats;
    }

    /**
     * Scan a single repository bean for plugin markers
     *
     * @param \RedBeanPHP\OODBBean $repo Repository connection bean
     * @param string $scanId Current scan ID
     * @return array Result with plugin_found flag and plugin_data
     */
    private function scanSingleRepository($repo, string $scanId): array {
        $owner = $repo->repo_owner;
        $repoName = $repo->repo_name;
        $accessToken = $repo->access_token;

        $this->log("Scanning {$owner}/{$repoName}...");

        // Decrypt token if needed
        if (!empty($accessToken) && strpos($accessToken, 'sk-') !== 0) {
            try {
                require_once __DIR__ . '/EncryptionService.php';
                $accessToken = EncryptionService::decrypt($accessToken);
            } catch (\Exception $e) {
                // Token may not be encrypted, use as-is
            }
        }

        // Fall back to global GitHub token if repo token is empty
        if (empty($accessToken)) {
            $tokenSetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['github_token']);
            if ($tokenSetting) {
                $accessToken = $tokenSetting->setting_value;
            }
        }

        if (empty($accessToken)) {
            throw new \Exception("No access token available for {$owner}/{$repoName}");
        }

        // Check for plugin.json in repository root
        $pluginJson = $this->detectPluginMarkers($owner, $repoName, $accessToken);

        if ($pluginJson === null) {
            $this->log("No plugin.json found in {$owner}/{$repoName}");
            return ['plugin_found' => false, 'plugin_data' => null];
        }

        // Parse and validate plugin.json
        $pluginData = $this->parsePluginJson($pluginJson);
        $pluginData['repo_owner'] = $owner;
        $pluginData['repo_name'] = $repoName;
        $pluginData['repo_connection_id'] = $repo->id;

        // Save or update discovered plugin
        $pluginId = $this->saveDiscoveredPlugin($pluginData, $repo->id);
        $pluginData['id'] = $pluginId;

        $this->log("Plugin found in {$owner}/{$repoName}: {$pluginData['name']} v{$pluginData['version']}");

        return ['plugin_found' => true, 'plugin_data' => $pluginData];
    }

    /**
     * Check if repository has plugin.json in root
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param string $accessToken GitHub access token
     * @return string|null Plugin.json content or null if not found
     */
    public function detectPluginMarkers(string $owner, string $repo, string $accessToken): ?string {
        $attempt = 0;

        while ($attempt < self::MAX_RETRIES) {
            try {
                $client = new GitHubClient($accessToken);
                $content = $client->getFileContent($owner, $repo, 'plugin.json');
                return $content;

            } catch (ClientException $e) {
                $statusCode = $e->getResponse()->getStatusCode();

                if ($statusCode === 404) {
                    // No plugin.json - this is not an error, just means no plugin
                    return null;
                }

                if ($statusCode === 403 || $statusCode === 429) {
                    // Rate limited - apply exponential backoff
                    $attempt++;
                    if ($attempt < self::MAX_RETRIES) {
                        $this->handleRateLimit($attempt);
                        continue;
                    }
                }

                throw $e;

            } catch (ServerException $e) {
                // Server error - retry with backoff
                $attempt++;
                if ($attempt < self::MAX_RETRIES) {
                    $this->handleRateLimit($attempt);
                    continue;
                }
                throw $e;

            } catch (\Exception $e) {
                // Check if it's a 404 wrapped in GuzzleException
                if (strpos($e->getMessage(), '404') !== false) {
                    return null;
                }
                throw $e;
            }
        }

        return null;
    }

    /**
     * Parse and validate plugin.json content
     *
     * @param string $content Raw plugin.json content
     * @return array Parsed plugin data
     * @throws \Exception If JSON is invalid or missing required fields
     */
    public function parsePluginJson(string $content): array {
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON in plugin.json: " . json_last_error_msg());
        }

        // Validate required fields
        foreach (self::REQUIRED_PLUGIN_FIELDS as $field) {
            if (empty($data[$field])) {
                throw new \Exception("Missing required field in plugin.json: {$field}");
            }
        }

        // Build normalized plugin data
        return [
            'name' => $data['name'],
            'version' => $data['version'],
            'description' => $data['description'] ?? null,
            'author' => $data['author'] ?? null,
            'main' => $data['main'] ?? null,
            'requires' => $data['requires'] ?? null,
            'raw_json' => $data,
            'valid' => true
        ];
    }

    /**
     * Save or update a discovered plugin in the database
     *
     * @param array $pluginData Parsed plugin data
     * @param int $repoConnectionId Repository connection ID
     * @return int Plugin record ID
     */
    public function saveDiscoveredPlugin(array $pluginData, int $repoConnectionId): int {
        // Generate unique plugin ID based on repo
        $pluginId = md5("{$pluginData['repo_owner']}/{$pluginData['repo_name']}");

        // Check if plugin already exists
        $existing = Bean::findOne('discoveredplugins', 'plugin_uid = ?', [$pluginId]);

        if ($existing) {
            // Update existing record
            $plugin = $existing;
        } else {
            // Create new record
            $plugin = Bean::dispense('discoveredplugins');
            $plugin->plugin_uid = $pluginId;
            $plugin->discovered_at = date('Y-m-d H:i:s');
        }

        $plugin->repo_connection_id = $repoConnectionId;
        $plugin->repo_owner = $pluginData['repo_owner'];
        $plugin->repo_name = $pluginData['repo_name'];
        $plugin->plugin_name = $pluginData['name'];
        $plugin->plugin_description = $pluginData['description'];
        $plugin->plugin_version = $pluginData['version'];
        $plugin->plugin_author = $pluginData['author'];
        $plugin->plugin_main = $pluginData['main'];
        $plugin->plugin_requires = json_encode($pluginData['requires']);
        $plugin->plugin_json = json_encode($pluginData['raw_json']);
        $plugin->status = $pluginData['valid'] ? 'validated' : 'invalid';
        $plugin->last_scanned_at = date('Y-m-d H:i:s');

        Bean::store($plugin);

        return $plugin->id;
    }

    /**
     * Create a new scan record
     *
     * @param int|null $repoConnectionId Single repo ID or null for full scan
     * @return string Generated scan ID
     */
    public function startScan(?int $repoConnectionId = null): string {
        $scanId = bin2hex(random_bytes(16));

        $scan = Bean::dispense('pluginscans');
        $scan->scan_uid = $scanId;
        $scan->repo_connection_id = $repoConnectionId;
        $scan->status = 'running';
        $scan->started_at = date('Y-m-d H:i:s');
        $scan->repos_scanned = 0;
        $scan->plugins_found = 0;
        $scan->errors_encountered = 0;

        Bean::store($scan);

        $this->logger->info("Plugin scan started", [
            'scan_uid' => $scanId,
            'repo_connection_id' => $repoConnectionId
        ]);

        return $scanId;
    }

    /**
     * Update an in-progress scan record
     *
     * @param string $scanId Scan ID
     * @param array $data Data to update
     */
    public function updateScan(string $scanId, array $data): void {
        $scan = Bean::findOne('pluginscans', 'scan_uid = ?', [$scanId]);

        if (!$scan) {
            return;
        }

        foreach ($data as $key => $value) {
            $scan->$key = $value;
        }

        Bean::store($scan);
    }

    /**
     * Mark a scan as completed
     *
     * @param string $scanId Scan ID
     * @param array $stats Final statistics
     */
    public function completeScan(string $scanId, array $stats): void {
        $scan = Bean::findOne('pluginscans', 'scan_uid = ?', [$scanId]);

        if (!$scan) {
            return;
        }

        $scan->status = 'completed';
        $scan->completed_at = date('Y-m-d H:i:s');
        $scan->repos_scanned = $stats['repos_scanned'];
        $scan->plugins_found = $stats['plugins_found'];
        $scan->errors_encountered = $stats['errors_encountered'];

        Bean::store($scan);

        $this->logger->info("Plugin scan completed", [
            'scan_uid' => $scanId,
            'repos_scanned' => $stats['repos_scanned'],
            'plugins_found' => $stats['plugins_found'],
            'errors' => $stats['errors_encountered']
        ]);
    }

    /**
     * Mark a scan as failed
     *
     * @param string $scanId Scan ID
     * @param string $errorMessage Error message
     */
    public function failScan(string $scanId, string $errorMessage): void {
        $scan = Bean::findOne('pluginscans', 'scan_uid = ?', [$scanId]);

        if (!$scan) {
            return;
        }

        $scan->status = 'failed';
        $scan->completed_at = date('Y-m-d H:i:s');
        $scan->error_message = $errorMessage;

        Bean::store($scan);

        $this->logger->error("Plugin scan failed", [
            'scan_uid' => $scanId,
            'error' => $errorMessage
        ]);
    }

    /**
     * Get scan history
     *
     * @param int $limit Number of scans to return
     * @return array Scan records
     */
    public function getScanHistory(int $limit = 20): array {
        $scans = Bean::find('pluginscans', 'ORDER BY created_at DESC LIMIT ?', [$limit]);

        $result = [];
        foreach ($scans as $scan) {
            $result[] = $scan->export();
        }

        return $result;
    }

    /**
     * Get all discovered plugins
     *
     * @param string|null $status Filter by status
     * @return array Plugin records
     */
    public function getDiscoveredPlugins(?string $status = null): array {
        if ($status) {
            $plugins = Bean::find('discoveredplugins', 'status = ? ORDER BY discovered_at DESC', [$status]);
        } else {
            $plugins = Bean::find('discoveredplugins', 'ORDER BY discovered_at DESC');
        }

        $result = [];
        foreach ($plugins as $plugin) {
            $pluginData = $plugin->export();
            // Parse JSON fields
            $pluginData['plugin_requires'] = json_decode($plugin->plugin_requires, true);
            $pluginData['plugin_json'] = json_decode($plugin->plugin_json, true);
            $result[] = $pluginData;
        }

        return $result;
    }

    /**
     * Get a single plugin by ID
     *
     * @param int $id Plugin record ID
     * @return array|null Plugin data or null if not found
     */
    public function getPlugin(int $id): ?array {
        $plugin = Bean::load('discoveredplugins', $id);

        if (!$plugin || !$plugin->id) {
            return null;
        }

        $data = $plugin->export();
        $data['plugin_requires'] = json_decode($plugin->plugin_requires, true);
        $data['plugin_json'] = json_decode($plugin->plugin_json, true);

        return $data;
    }

    /**
     * Handle rate limit with exponential backoff
     *
     * @param int $attempt Current retry attempt (1-based)
     */
    private function handleRateLimit(int $attempt): void {
        $delayMs = self::INITIAL_BACKOFF_MS * pow(2, $attempt - 1);
        $delaySec = $delayMs / 1000;

        $this->logger->warning("Rate limit hit, backing off", [
            'attempt' => $attempt,
            'delay_seconds' => $delaySec
        ]);

        $this->log("Rate limit hit, waiting {$delaySec}s before retry...");

        usleep($delayMs * 1000); // usleep takes microseconds
    }

    /**
     * Log message to console if verbose mode is enabled
     *
     * @param string $message Message to log
     */
    private function log(string $message): void {
        if ($this->verbose) {
            echo "[" . date('Y-m-d H:i:s') . "] {$message}\n";
        }

        $this->logger->info($message);
    }
}
