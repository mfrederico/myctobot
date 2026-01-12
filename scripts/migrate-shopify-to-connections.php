#!/usr/bin/env php
<?php
/**
 * Migrate Shopify Settings to Multi-Store Connections
 *
 * Migrates existing Shopify configurations from enterprisesettings table
 * to the new shopifyconnections table for multi-store support.
 *
 * Usage:
 *   php scripts/migrate-shopify-to-connections.php --script [options]
 *
 * Options:
 *   --script     REQUIRED for CLI execution
 *   --tenant     Process specific tenant (omit for all tenants)
 *   --verbose    Show detailed output
 *   --dry-run    Show what would be migrated without making changes
 *   --help       Show this help message
 */

// Determine paths
$scriptDir = dirname(__FILE__);
$baseDir = dirname($scriptDir);

// Change to base directory
chdir($baseDir);

// Parse command line options
$options = getopt('', ['verbose', 'dry-run', 'script', 'tenant:', 'help']);

if (isset($options['help'])) {
    echo "Migrate Shopify Settings to Multi-Store Connections\n\n";
    echo "Usage: php scripts/migrate-shopify-to-connections.php --script [options]\n\n";
    echo "Options:\n";
    echo "  --script     REQUIRED for standalone script mode\n";
    echo "  --tenant     Process specific tenant (omit to process all tenants)\n";
    echo "  --verbose    Show detailed output\n";
    echo "  --dry-run    Show what would be migrated without making changes\n";
    echo "  --help       Show this help message\n";
    exit(0);
}

if (!isset($options['script'])) {
    echo "Error: --script flag required for CLI execution\n";
    exit(1);
}

$verbose = isset($options['verbose']);
$dryRun = isset($options['dry-run']);
$specificTenant = $options['tenant'] ?? null;

if ($verbose) {
    echo "Shopify Multi-Store Migration\n";
    echo "==============================\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n";
    echo "Base: {$baseDir}\n";
    if ($specificTenant) echo "Tenant: {$specificTenant}\n";
    if ($dryRun) echo "Mode: DRY RUN (no changes will be made)\n";
    echo "\n";
}

// Load vendor autoload first
require_once $baseDir . '/vendor/autoload.php';

// Load bootstrap class
require_once $baseDir . '/bootstrap.php';

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;

// Load required services
require_once $baseDir . '/lib/Bean.php';
require_once $baseDir . '/services/EncryptionService.php';

use \app\services\EncryptionService;

try {
    // Discover tenants to process
    $tenantsToProcess = [];
    if ($specificTenant) {
        $tenantConfig = $baseDir . "/conf/config.{$specificTenant}.ini";
        if (!file_exists($tenantConfig)) {
            echo "Error: Tenant config not found: {$tenantConfig}\n";
            exit(1);
        }
        $tenantsToProcess[$specificTenant] = $tenantConfig;
    } else {
        // Discover all tenant configs
        $configFiles = glob($baseDir . '/conf/config.*.ini');
        foreach ($configFiles as $configFile) {
            $basename = basename($configFile);
            if (preg_match('/^config\.([a-z0-9]+)\.ini$/', $basename, $matches)) {
                $tenantSlug = $matches[1];
                if ($tenantSlug !== 'example') {
                    $tenantsToProcess[$tenantSlug] = $configFile;
                }
            }
        }
    }

    if (empty($tenantsToProcess)) {
        if ($verbose) {
            echo "No tenant configs found. Nothing to process.\n";
        }
        exit(0);
    }

    if ($verbose) {
        echo "Tenants to process: " . implode(', ', array_keys($tenantsToProcess)) . "\n\n";
    }

    $totalMigrated = 0;
    $totalSkipped = 0;
    $totalErrors = 0;

    // Process each tenant
    foreach ($tenantsToProcess as $tenantSlug => $configFile) {
        if ($verbose) {
            echo "=== Processing tenant: {$tenantSlug} ===\n";
        }

        try {
            // Initialize fresh bootstrap for this tenant
            $bootstrap = new \app\Bootstrap($configFile);

            // Ensure shopifyconnections table exists
            if (!$dryRun) {
                $migrationFile = $baseDir . '/database/migrations/2026_01_12_shopifyconnections.sql';
                if (file_exists($migrationFile)) {
                    $sql = file_get_contents($migrationFile);
                    Bean::exec($sql);
                }
            }

            // Find all members with Shopify shop configured in enterprisesettings
            $shopSettings = Bean::find('enterprisesettings',
                'setting_key = ? AND setting_value IS NOT NULL AND setting_value != ""',
                ['shopify_shop']
            );

            if (empty($shopSettings)) {
                if ($verbose) {
                    echo "  No Shopify shops found in enterprisesettings\n\n";
                }
                continue;
            }

            foreach ($shopSettings as $shopSetting) {
                $memberId = $shopSetting->member_id;
                $shopDomain = $shopSetting->setting_value;

                // Normalize shop domain (add .myshopify.com if not present)
                if (!str_contains($shopDomain, '.myshopify.com')) {
                    $shopDomain = $shopDomain . '.myshopify.com';
                }

                // Get member info
                $member = R::load('member', $memberId);
                $memberName = $member->id ? ($member->display_name ?? $member->email ?? "Member #{$memberId}") : "Member #{$memberId}";

                if ($verbose) {
                    echo "  Found: {$shopDomain} (member: {$memberName})\n";
                }

                // Check if already migrated (shop_domain is unique)
                $existing = Bean::findOne('shopifyconnections', 'shop_domain = ?', [$shopDomain]);
                if ($existing) {
                    if ($verbose) {
                        echo "    -> SKIPPED: Already exists in shopifyconnections\n";
                    }
                    $totalSkipped++;
                    continue;
                }

                // Get access token
                $tokenSetting = Bean::findOne('enterprisesettings',
                    'setting_key = ? AND member_id = ?',
                    ['shopify_access_token', $memberId]
                );

                if (!$tokenSetting || empty($tokenSetting->setting_value)) {
                    if ($verbose) {
                        echo "    -> SKIPPED: No access token found\n";
                    }
                    $totalSkipped++;
                    continue;
                }

                // Get optional settings
                $storefrontPwSetting = Bean::findOne('enterprisesettings',
                    'setting_key = ? AND member_id = ?',
                    ['shopify_storefront_password', $memberId]
                );
                $storefrontPassword = $storefrontPwSetting ? $storefrontPwSetting->setting_value : null;

                $verifyPlaywrightSetting = Bean::findOne('enterprisesettings',
                    'setting_key = ? AND member_id = ?',
                    ['shopify_verify_playwright', $memberId]
                );
                $verifyPlaywright = $verifyPlaywrightSetting
                    ? ($verifyPlaywrightSetting->setting_value === '1' || $verifyPlaywrightSetting->setting_value === 'true')
                    : false;

                if ($dryRun) {
                    echo "    -> WOULD MIGRATE to shopifyconnections\n";
                    $totalMigrated++;
                    continue;
                }

                // Create new shopifyconnections record
                $conn = Bean::dispense('shopifyconnections');
                $conn->created_by_member_id = $memberId;
                $conn->created_by_name = $memberName;
                $conn->shop_domain = $shopDomain;
                $conn->access_token = $tokenSetting->setting_value; // Already encrypted
                $conn->storefront_password = $storefrontPassword; // Already encrypted or null
                $conn->verify_with_playwright = $verifyPlaywright ? 1 : 0;
                $conn->enabled = 1;
                $conn->created_at = date('Y-m-d H:i:s');
                $conn->updated_at = date('Y-m-d H:i:s');

                try {
                    Bean::store($conn);
                    if ($verbose) {
                        echo "    -> MIGRATED: Created shopifyconnections id={$conn->id}\n";
                    }
                    $totalMigrated++;
                } catch (\Exception $e) {
                    echo "    -> ERROR: " . $e->getMessage() . "\n";
                    $totalErrors++;
                }
            }

            if ($verbose) {
                echo "\n";
            }

        } catch (\Exception $e) {
            echo "Error processing tenant {$tenantSlug}: " . $e->getMessage() . "\n";
            $totalErrors++;
        }
    }

    // Summary
    echo "=== Migration Summary ===\n";
    echo "Migrated: {$totalMigrated}\n";
    echo "Skipped:  {$totalSkipped}\n";
    echo "Errors:   {$totalErrors}\n";

    if ($dryRun) {
        echo "\nDRY RUN - No changes were made. Run without --dry-run to apply.\n";
    }

} catch (\Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}
