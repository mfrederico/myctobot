<?php
/**
 * Job Executor Configuration Service
 *
 * Handles configuration hierarchy for job-executor service:
 * 1. Load base config from conf/jobexecutor.ini (public defaults)
 * 2. Override with tenant-specific values from conf/config.{tenant}.ini [jobexecutor] section
 * 3. Fall back to Flight::get() for legacy config support
 */

namespace app\services;

use \Flight as Flight;

class JobExecutorConfig {

    private static ?array $configCache = null;

    /**
     * Get job-executor configuration with hierarchy:
     * 1. Load base config from conf/jobexecutor.ini (public defaults)
     * 2. Override with tenant-specific values from conf/config.{tenant}.ini [jobexecutor] section
     * 3. Fall back to Flight::get() for legacy config support
     *
     * @param string|null $tenantSlug Optional tenant slug (uses session if not provided)
     * @return array Job-executor configuration
     */
    public static function getConfig(?string $tenantSlug = null): array {
        // Use provided tenant or get from session
        $tenant = $tenantSlug ?? ($_SESSION['tenant_slug'] ?? null);

        // Cache key includes tenant for multi-tenant support
        $cacheKey = $tenant ?? 'default';

        if (self::$configCache !== null && isset(self::$configCache[$cacheKey])) {
            return self::$configCache[$cacheKey];
        }

        $config = [
            'url' => null,
            'timeout' => 30,
            'enabled' => true,
        ];

        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);

        // 1. Load base config from conf/jobexecutor.ini (public defaults)
        $jobexecutorIni = $basePath . '/conf/jobexecutor.ini';
        if (file_exists($jobexecutorIni)) {
            $baseConfig = parse_ini_file($jobexecutorIni, true);
            if ($baseConfig && isset($baseConfig['jobexecutor'])) {
                foreach ($baseConfig['jobexecutor'] as $key => $value) {
                    if ($value !== '' && $value !== null) {
                        $config[$key] = $value;
                    }
                }
            }
        }

        // 2. Override with tenant-specific config (tenant values take priority)
        if ($tenant && $tenant !== 'default') {
            $tenantIni = $basePath . "/conf/config.{$tenant}.ini";
            if (file_exists($tenantIni)) {
                $tenantConfig = parse_ini_file($tenantIni, true);
                if ($tenantConfig && isset($tenantConfig['jobexecutor'])) {
                    foreach ($tenantConfig['jobexecutor'] as $key => $value) {
                        if ($value !== '' && $value !== null) {
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

        // Normalize boolean for enabled
        if (is_string($config['enabled'])) {
            $config['enabled'] = filter_var($config['enabled'], FILTER_VALIDATE_BOOLEAN);
        }

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
     * @param string|null $tenantSlug Optional tenant slug
     * @return string Job-executor URL
     */
    public static function getUrl(?string $tenantSlug = null): string {
        return self::getConfig($tenantSlug)['url'];
    }

    /**
     * Get the API timeout in seconds
     *
     * @param string|null $tenantSlug Optional tenant slug
     * @return int Timeout in seconds
     */
    public static function getTimeout(?string $tenantSlug = null): int {
        return self::getConfig($tenantSlug)['timeout'];
    }

    /**
     * Check if job-executor is enabled
     *
     * @param string|null $tenantSlug Optional tenant slug
     * @return bool Whether job-executor is enabled
     */
    public static function isEnabled(?string $tenantSlug = null): bool {
        return self::getConfig($tenantSlug)['enabled'];
    }

    /**
     * Clear the config cache (useful when switching tenants)
     */
    public static function clearCache(): void {
        self::$configCache = null;
    }
}
