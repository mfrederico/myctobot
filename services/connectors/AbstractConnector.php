<?php
/**
 * Abstract Connector
 *
 * Provides shared logic for all connectors:
 * - 3-step config cascade: conf/{key}.ini -> conf/config.{workspace}.ini [{key}] -> Flight::get()
 * - Default status generation from connection beans
 * - Default validate() / refreshToken() implementations
 */

namespace app\services\connectors;

use \Flight as Flight;

abstract class AbstractConnector implements ConnectorInterface {

    /**
     * Load connector configuration with 3-step cascade:
     * 1. conf/{key}.ini (or conf/{key}.ini [{section}])
     * 2. conf/config.{workspace}.ini [{key}] section
     * 3. Flight::get('{key}.xxx') fallback
     *
     * @param string      $key        Connector key (e.g., 'shopify', 'github')
     * @param array       $configKeys Keys to load (e.g., ['client_id', 'client_secret'])
     * @param string|null $section    Optional INI section name (null = use {key} or flat)
     * @return array Loaded config values
     */
    protected static function loadConfig(string $key, array $configKeys, ?string $section = null): array {
        $config = array_fill_keys($configKeys, null);
        $basePath = dirname(__DIR__, 2);

        // Step 1: Load from conf/{key}.ini
        $iniFile = $basePath . "/conf/{$key}.ini";
        if (file_exists($iniFile)) {
            $iniData = parse_ini_file($iniFile, true);
            // Try section first, then flat
            $sectionName = $section ?? $key;
            $iniSection = $iniData[$sectionName] ?? $iniData;
            foreach ($configKeys as $k) {
                if (!empty($iniSection[$k])) {
                    $config[$k] = $iniSection[$k];
                }
            }
        }

        // Step 2: Fill from conf/config.{workspace}.ini [{key}] section
        $workspaceSlug = $_SESSION['workspace_slug'] ?? $_SERVER['WORKSPACE'] ?? null;
        if ($workspaceSlug) {
            $wsFile = $basePath . "/conf/config.{$workspaceSlug}.ini";
            if (file_exists($wsFile)) {
                $wsData = parse_ini_file($wsFile, true);
                $wsSection = $wsData[$key] ?? [];
                foreach ($configKeys as $k) {
                    if (empty($config[$k]) && !empty($wsSection[$k])) {
                        $config[$k] = $wsSection[$k];
                    }
                }
            }
        }

        // Step 3: Fall back to Flight::get() for any missing values
        foreach ($configKeys as $k) {
            if (empty($config[$k])) {
                $config[$k] = Flight::get("{$key}.{$k}");
            }
        }

        return $config;
    }

    /**
     * Check if OAuth is configured (has client_id and client_secret)
     *
     * @param array $config Config array from loadConfig()
     * @return bool
     */
    protected static function isOAuthConfigured(array $config): bool {
        return !empty($config['client_id']) && !empty($config['client_secret']);
    }

    /**
     * Default status: builds from connection beans list
     * Connectors can override for custom status logic
     */
    public static function getStatus(int $memberId, array $connections): array {
        if (empty($connections)) {
            $meta = static::getMeta();
            $connectUrl = '/connections/connect/' . static::getKey();

            if ($meta['auth_type'] === 'api_key') {
                $connectUrl = '/connections/add/' . static::getKey();
            }

            return [
                'connected' => false,
                'status' => 'Not connected',
                'details' => null,
                'actions' => [
                    ['label' => 'Connect', 'url' => $connectUrl, 'class' => 'btn-' . $meta['color']]
                ],
            ];
        }

        $ownedCount = 0;
        $sharedCount = 0;
        $connList = [];
        foreach ($connections as $conn) {
            $isOwner = ($conn->member_id == $memberId);
            $connList[] = [
                'id' => $conn->id,
                'connection_name' => $conn->connection_name ?: $conn->external_name ?: static::getKey(),
                'external_name' => $conn->external_name,
                'external_eid' => $conn->external_eid,
                'enabled' => (bool)$conn->enabled,
                'shared' => (bool)$conn->shared,
                'is_owner' => $isOwner,
            ];
            if ($isOwner) {
                $ownedCount++;
            } else {
                $sharedCount++;
            }
        }

        $statusParts = [];
        if ($ownedCount > 0) $statusParts[] = $ownedCount . ' owned';
        if ($sharedCount > 0) $statusParts[] = $sharedCount . ' shared';

        $key = static::getKey();
        $meta = static::getMeta();

        return [
            'connected' => true,
            'status' => count($connList) . ' connection(s) - ' . implode(', ', $statusParts),
            'details' => [
                'connections' => $connList,
                'connection_count' => count($connList),
                'owned_count' => $ownedCount,
                'shared_count' => $sharedCount,
            ],
            'actions' => [
                ['label' => 'Manage', 'url' => '/' . $key, 'class' => 'btn-outline-' . $meta['color']],
                ['label' => 'Add', 'url' => '/connections/connect/' . $key, 'class' => 'btn-outline-secondary'],
            ],
        ];
    }

    /**
     * Default validate: always passes
     */
    public static function validate(array $data): array {
        return ['valid' => true, 'errors' => []];
    }

    /**
     * Default refreshToken: returns null (no refresh support)
     */
    public static function refreshToken(string $refreshToken, array $config): ?array {
        return null;
    }

    /**
     * Default getOAuthUrl: returns null (non-OAuth connector)
     */
    public static function getOAuthUrl(string $state, array $config, array $extra = []): ?string {
        return null;
    }

    /**
     * Default exchangeCode: returns null (non-OAuth connector)
     */
    public static function exchangeCode(string $code, array $config, array $extra = []): ?array {
        return null;
    }

    /**
     * Default testConnection: basic check
     */
    public static function testConnection(object $connectionBean): array {
        if (empty($connectionBean->access_token)) {
            return ['success' => false, 'message' => 'No credentials configured'];
        }
        return ['success' => true, 'message' => 'Connection exists'];
    }
}
