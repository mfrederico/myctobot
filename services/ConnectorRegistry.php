<?php
/**
 * Connector Registry
 *
 * Auto-discovers connector classes from services/connectors/*Connector.php.
 * Provides lookup by key and metadata aggregation for the connections page.
 *
 * Pattern mirrors StepTypeRegistry scanning views/pipelines/components/_config_*.php.
 */

namespace app\services;

class ConnectorRegistry {

    /** @var array|null [key => FQCN] cache */
    private static ?array $connectors = null;

    /**
     * Scan services/connectors/*Connector.php and register each one
     */
    public static function scan(): void {
        self::$connectors = [];

        $dir = __DIR__ . '/connectors';
        $pattern = $dir . '/*Connector.php';
        $files = glob($pattern);

        foreach ($files as $file) {
            $basename = basename($file, '.php');

            // Skip AbstractConnector and ConnectorInterface
            if ($basename === 'AbstractConnector' || $basename === 'ConnectorInterface') {
                continue;
            }

            $fqcn = 'app\\services\\connectors\\' . $basename;

            // Require the file
            require_once $file;

            // Verify class exists and implements interface
            if (!class_exists($fqcn)) {
                continue;
            }

            if (!is_subclass_of($fqcn, 'app\\services\\connectors\\ConnectorInterface')) {
                continue;
            }

            $key = $fqcn::getKey();
            self::$connectors[$key] = $fqcn;
        }
    }

    /**
     * Get a connector class by key
     *
     * @param string $key Connector key (e.g., 'shopify')
     * @return string|null FQCN of the connector class or null
     */
    public static function get(string $key): ?string {
        if (self::$connectors === null) {
            self::scan();
        }
        return self::$connectors[$key] ?? null;
    }

    /**
     * Get all registered connectors
     *
     * @return array [key => FQCN]
     */
    public static function getAll(): array {
        if (self::$connectors === null) {
            self::scan();
        }
        return self::$connectors;
    }

    /**
     * Get metadata for all connectors (for connections page rendering)
     *
     * @return array [key => meta array]
     */
    public static function getAllMeta(): array {
        $meta = [];
        foreach (self::getAll() as $key => $fqcn) {
            $meta[$key] = array_merge($fqcn::getMeta(), ['key' => $key]);
        }
        return $meta;
    }

    /**
     * Check if a connector is registered
     *
     * @param string $key Connector key
     * @return bool
     */
    public static function has(string $key): bool {
        if (self::$connectors === null) {
            self::scan();
        }
        return isset(self::$connectors[$key]);
    }

    /**
     * Clear the cache (useful for testing)
     */
    public static function clearCache(): void {
        self::$connectors = null;
    }
}
