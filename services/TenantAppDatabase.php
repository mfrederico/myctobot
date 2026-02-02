<?php
/**
 * TenantAppDatabase - Direct database initialization for tenant apps
 *
 * This class provides direct RedBeanPHP initialization without Flight framework.
 * Used by generated app servers that run as standalone processes.
 */

namespace app\services;

use RedBeanPHP\R;

class TenantAppDatabase
{
    private static bool $initialized = false;
    private static ?string $currentWorkspace = null;
    private static ?string $basePath = null;

    /**
     * Get the base path for MyCTOBot installation
     * Dynamically detected from this file's location
     */
    private static function getBasePath(): string
    {
        if (self::$basePath === null) {
            // This file is in /services/, so go up one directory
            self::$basePath = dirname(__DIR__);
        }
        return self::$basePath;
    }

    /**
     * Initialize RedBeanPHP for a workspace
     *
     * @param string $workspace The workspace slug
     * @throws \RuntimeException If configuration file not found or database connection fails
     */
    public static function init(string $workspace): void
    {
        // Skip if already initialized for this workspace
        if (self::$initialized && self::$currentWorkspace === $workspace) {
            return;
        }

        // Load workspace config, fall back to default
        $configFile = self::getBasePath() . "/conf/config.{$workspace}.ini";
        if (!file_exists($configFile)) {
            $configFile = self::getBasePath() . '/conf/config.ini';
        }

        if (!file_exists($configFile)) {
            throw new \RuntimeException("Configuration file not found: {$configFile}");
        }

        $config = parse_ini_file($configFile, true);
        if ($config === false || !isset($config['database'])) {
            throw new \RuntimeException("Invalid configuration file or missing database section");
        }

        $db = $config['database'];

        // Close existing connection if switching workspaces
        if (self::$initialized && self::$currentWorkspace !== $workspace) {
            R::close();
        }

        // Connect based on database type
        if ($db['type'] === 'sqlite') {
            $dbPath = $db['name'];
            // Handle relative paths
            if (!str_starts_with($dbPath, '/')) {
                $dbPath = self::getBasePath() . '/' . $dbPath;
            }
            R::setup("sqlite:{$dbPath}");
        } else {
            $dsn = sprintf(
                '%s:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $db['type'],
                $db['host'] ?? 'localhost',
                $db['port'] ?? '3306',
                $db['name']
            );
            R::setup($dsn, $db['user'] ?? '', $db['pass'] ?? '');
        }

        // Freeze schema in production
        if (!empty($config['app']['production'])) {
            R::freeze(true);
        }

        self::$initialized = true;
        self::$currentWorkspace = $workspace;
    }

    /**
     * Check if database is initialized
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    /**
     * Get current workspace
     */
    public static function getCurrentWorkspace(): ?string
    {
        return self::$currentWorkspace;
    }

    /**
     * Close database connection
     */
    public static function close(): void
    {
        if (self::$initialized) {
            R::close();
            self::$initialized = false;
            self::$currentWorkspace = null;
        }
    }
}
