<?php
/**
 * Job Executor Configuration Service
 *
 * Handles configuration hierarchy for job-executor service:
 * 1. Load base config from conf/jobexecutor.ini (public defaults)
 * 2. Override with workspace-specific values from conf/config.{workspace}.ini [jobexecutor] section
 * 3. Fall back to Flight::get() for legacy config support
 */

namespace app\services;

use \Flight as Flight;

class JobExecutorConfig {

    private static ?array $configCache = null;

    /**
     * Get job-executor configuration with hierarchy:
     * 1. Load base config from conf/jobexecutor.ini (public defaults)
     * 2. Override with workspace-specific values from conf/config.{workspace}.ini [jobexecutor] section
     * 3. Fall back to Flight::get() for legacy config support
     *
     * @param string|null $workspaceSlug Optional workspace slug (uses session if not provided)
     * @return array Job-executor configuration
     */
    public static function getConfig(?string $workspaceSlug = null): array {
        // Use provided workspace or get from session
        $workspace = $workspaceSlug ?? ($_SESSION['workspace_slug'] ?? null);

        // Cache key includes workspace for multi-workspace support
        $cacheKey = $workspace ?? 'default';

        if (self::$configCache !== null && isset(self::$configCache[$cacheKey])) {
            return self::$configCache[$cacheKey];
        }

        $config = [
            'url' => null,
            'timeout' => 30,
            'enabled' => true,
            'verify_ssl' => true,
        ];

        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);

        // 1. Load base config from conf/jobexecutor.ini (public defaults)
        $jobexecutorIni = $basePath . '/conf/jobexecutor.ini';
        if (file_exists($jobexecutorIni)) {
            $baseConfig = parse_ini_file($jobexecutorIni, true);
            if ($baseConfig && isset($baseConfig['jobexecutor'])) {
                foreach ($baseConfig['jobexecutor'] as $key => $value) {
                    // Note: For booleans, INI 'false' becomes '' - handled by Flight::isOn() later
                    if ($value !== null) {
                        $config[$key] = $value;
                    }
                }
            }
        }

        // 2. Override with workspace-specific config (workspace values take priority)
        if ($workspace && $workspace !== 'default') {
            $workspaceIni = $basePath . "/conf/config.{$workspace}.ini";
            if (file_exists($workspaceIni)) {
                $workspaceConfig = parse_ini_file($workspaceIni, true);
                if ($workspaceConfig && isset($workspaceConfig['jobexecutor'])) {
                    foreach ($workspaceConfig['jobexecutor'] as $key => $value) {
                        // Note: For booleans, INI 'false' becomes '' - handled by Flight::isOn() later
                        if ($value !== null) {
                            $config[$key] = $value;
                        }
                    }
                }
            }
        }

        // 3. Fall back to Flight::get() for legacy config support
        if (empty($config['url'])) {
            $config['url'] = Flight::get('job_executor_url') ?? 'http://localhost:8081';
        }

        // Normalize booleans using Flight::isOn() for consistent INI parsing
        $config['enabled'] = Flight::isOn($config['enabled']);
        $config['verify_ssl'] = Flight::isOn($config['verify_ssl']);

        // Normalize timeout to int
        $config['timeout'] = (int) $config['timeout'];

        // Cache the config
        if (self::$configCache === null) {
            self::$configCache = [];
        }
        self::$configCache[$cacheKey] = $config;

        return $config;
    }

    /**
     * Get the job-executor URL
     *
     * @param string|null $workspaceSlug Optional workspace slug
     * @return string Job-executor URL
     */
    public static function getUrl(?string $workspaceSlug = null): string {
        return self::getConfig($workspaceSlug)['url'];
    }

    /**
     * Get the API timeout in seconds
     *
     * @param string|null $workspaceSlug Optional workspace slug
     * @return int Timeout in seconds
     */
    public static function getTimeout(?string $workspaceSlug = null): int {
        return self::getConfig($workspaceSlug)['timeout'];
    }

    /**
     * Check if job-executor is enabled
     *
     * @param string|null $workspaceSlug Optional workspace slug
     * @return bool Whether job-executor is enabled
     */
    public static function isEnabled(?string $workspaceSlug = null): bool {
        return self::getConfig($workspaceSlug)['enabled'];
    }

    /**
     * Check if SSL verification should be performed
     *
     * @param string|null $workspaceSlug Optional workspace slug
     * @return bool Whether to verify SSL certificates
     */
    public static function shouldVerifySsl(?string $workspaceSlug = null): bool {
        return self::getConfig($workspaceSlug)['verify_ssl'];
    }

    /**
     * Clear the config cache (useful when switching workspaces)
     */
    public static function clearCache(): void {
        self::$configCache = null;
    }
}
