<?php
/**
 * Run TenantSchemaBuilder migrations across all tenants
 *
 * Usage:
 *   php scripts/run-migration.php --sync                         # Run all pending migrations on all tenants
 *   php scripts/run-migration.php --sync --tenant=gwt            # Run pending migrations on specific tenant
 *   php scripts/run-migration.php --migration=SSHKeys            # Run specific migration on all tenants
 *   php scripts/run-migration.php --migration=SSHKeys --tenant=gwt  # Run on specific tenant
 *   php scripts/run-migration.php --migration=SSHKeys --force    # Force re-run even if already applied
 *   php scripts/run-migration.php --status                       # Show migration status for all tenants
 *   php scripts/run-migration.php --status --tenant=gwt          # Show status for specific tenant
 *   php scripts/run-migration.php --list                         # List available migrations
 *   php scripts/run-migration.php --tenants                      # List all tenants
 */

// Ensure running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line\n");
}

$projectDir = dirname(__DIR__);
chdir($projectDir);

require_once $projectDir . '/vendor/autoload.php';
require_once $projectDir . '/services/TenantSchemaBuilder.php';

use app\services\TenantSchemaBuilder;

// Parse arguments
$options = getopt('', ['migration:', 'tenant:', 'list', 'tenants', 'help', 'sync', 'status', 'force', 'mark']);

// Colors for CLI output
function colorize($text, $color) {
    $colors = [
        'green' => "\033[32m",
        'red' => "\033[31m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'cyan' => "\033[36m",
        'reset' => "\033[0m"
    ];
    return ($colors[$color] ?? '') . $text . $colors['reset'];
}

function printHelp() {
    echo "TenantSchemaBuilder Migration Tool\n";
    echo "===================================\n\n";
    echo "Usage:\n";
    echo "  php scripts/run-migration.php --sync                      Run all pending migrations on all tenants\n";
    echo "  php scripts/run-migration.php --sync --tenant=<slug>      Run pending migrations on specific tenant\n";
    echo "  php scripts/run-migration.php --migration=<name>          Run migration on all tenants (skips if applied)\n";
    echo "  php scripts/run-migration.php --migration=Name1,Name2     Run multiple migrations (comma-separated)\n";
    echo "  php scripts/run-migration.php --migration=<name> --force  Force re-run even if already applied\n";
    echo "  php scripts/run-migration.php --migration=<name> --mark   Mark as applied without running (for existing tables)\n";
    echo "  php scripts/run-migration.php --status                    Show migration status for all tenants\n";
    echo "  php scripts/run-migration.php --status --tenant=<slug>    Show status for specific tenant\n";
    echo "  php scripts/run-migration.php --list                      List available migrations\n";
    echo "  php scripts/run-migration.php --tenants                   List all tenants\n";
    echo "  php scripts/run-migration.php --help                      Show this help\n\n";
    echo "Examples:\n";
    echo "  php scripts/run-migration.php --sync                      # Sync all tenants\n";
    echo "  php scripts/run-migration.php --sync --tenant=gwt         # Sync only gwt tenant\n";
    echo "  php scripts/run-migration.php --migration=SSHKeys         # Run SSHKeys on all tenants\n";
    echo "  php scripts/run-migration.php --migration=SSHKeys,AIAgents,Member  # Run multiple\n";
    echo "  php scripts/run-migration.php --migration=all --mark --tenant=gwt  # Mark all as applied for existing DB\n";
    echo "  php scripts/run-migration.php --status --tenant=tiknix    # Check tiknix status\n";
}

/** @var array<string, TenantSchemaBuilder> Cache of builder instances per tenant */
$builderCache = [];

/**
 * Get or create a TenantSchemaBuilder for a tenant (reuses same instance)
 */
function getBuilderForTenant(string $slug): ?TenantSchemaBuilder {
    global $builderCache;

    if (isset($builderCache[$slug])) {
        return $builderCache[$slug];
    }

    $projectDir = dirname(__DIR__);
    $configFile = $projectDir . '/conf/config.' . $slug . '.ini';

    if (!file_exists($configFile)) {
        return null;
    }

    $config = parse_ini_file($configFile, true);
    if (!isset($config['database'])) {
        return null;
    }

    $db = $config['database'];
    $builderCache[$slug] = new TenantSchemaBuilder(
        $db['name'],
        $db['user'],
        $db['pass'] ?? '',
        $db['host'] ?? 'localhost'
    );

    return $builderCache[$slug];
}

/**
 * Run a migration on a tenant using TenantSchemaBuilder
 */
function runMigrationOnTenant(string $migration, string $slug, bool $force = false): array {
    $builder = getBuilderForTenant($slug);

    if (!$builder) {
        return ['success' => false, 'error' => 'Config file not found or invalid'];
    }

    return $builder->runMigration($migration, $force);
}

// Handle --help
if (isset($options['help']) || (empty($options) && $argc === 1)) {
    printHelp();
    exit(0);
}

// Handle --list
if (isset($options['list'])) {
    echo "Available Migrations:\n";
    echo "=====================\n";
    $migrations = TenantSchemaBuilder::getAvailableMigrations();
    foreach ($migrations as $migration) {
        echo "  - $migration\n";
    }
    echo "\nTotal: " . count($migrations) . " migrations\n";
    exit(0);
}

// Handle --tenants
if (isset($options['tenants'])) {
    echo "Available Tenants:\n";
    echo "==================\n";
    $tenants = TenantSchemaBuilder::getAvailableTenants();
    foreach ($tenants as $slug => $tenant) {
        echo "  - $slug";
        if ($tenant['db_name']) {
            echo " (db: {$tenant['db_name']})";
        }
        echo "\n";
    }
    echo "\nTotal: " . count($tenants) . " tenants\n";
    exit(0);
}

// Handle --status
if (isset($options['status'])) {
    $tenants = TenantSchemaBuilder::getAvailableTenants();

    // Filter to specific tenant if provided
    if (isset($options['tenant'])) {
        $targetTenant = $options['tenant'];
        if (!isset($tenants[$targetTenant])) {
            echo colorize("Error: Tenant '$targetTenant' not found.\n", 'red');
            exit(1);
        }
        $tenants = [$targetTenant => $tenants[$targetTenant]];
    }

    echo "Migration Status\n";
    echo "================\n\n";

    foreach ($tenants as $slug => $tenant) {
        echo colorize("[$slug]", 'blue') . " ({$tenant['db_name']})\n";

        $status = TenantSchemaBuilder::getTenantStatus($slug);
        if (!$status['success']) {
            echo "  " . colorize("Error: " . $status['error'], 'red') . "\n\n";
            continue;
        }

        $pendingCount = count($status['pending']);
        $appliedCount = count($status['applied']);

        echo "  Applied: " . colorize($appliedCount, 'green') . ", ";
        echo "Pending: " . ($pendingCount > 0 ? colorize($pendingCount, 'yellow') : colorize('0', 'green')) . "\n";

        if ($pendingCount > 0) {
            echo "  Pending: " . colorize(implode(', ', $status['pending']), 'yellow') . "\n";
        }
        echo "\n";
    }
    exit(0);
}

// Handle --sync
if (isset($options['sync'])) {
    $tenants = TenantSchemaBuilder::getAvailableTenants();

    // Filter to specific tenant if provided
    if (isset($options['tenant'])) {
        $targetTenant = $options['tenant'];
        if (!isset($tenants[$targetTenant])) {
            echo colorize("Error: Tenant '$targetTenant' not found.\n", 'red');
            exit(1);
        }
        $tenants = [$targetTenant => $tenants[$targetTenant]];
    }

    echo "Syncing Migrations\n";
    echo "==================\n";
    echo "Tenants: " . count($tenants) . "\n";
    echo str_repeat('-', 50) . "\n";

    $totalRan = 0;
    $totalFailed = 0;

    foreach ($tenants as $slug => $tenant) {
        echo "\n" . colorize("[$slug]", 'blue') . " ";

        $result = TenantSchemaBuilder::syncTenant($slug);

        if (!$result['success']) {
            echo colorize("ERROR", 'red') . "\n";
            if (!empty($result['failed'])) {
                foreach ($result['failed'] as $fail) {
                    echo "  " . colorize("✗ {$fail['migration']}", 'red') . ": {$fail['error']}\n";
                    $totalFailed++;
                }
            } else {
                echo "  " . ($result['error'] ?? 'Unknown error') . "\n";
            }
            continue;
        }

        if (empty($result['ran'])) {
            echo colorize("UP TO DATE", 'green') . "\n";
        } else {
            echo colorize(count($result['ran']) . " APPLIED", 'green') . "\n";
            foreach ($result['ran'] as $migration) {
                echo "  " . colorize("✓ $migration", 'green') . "\n";
                $totalRan++;
            }
        }
    }

    echo "\n" . str_repeat('-', 50) . "\n";
    echo "Summary: " . colorize("$totalRan migrations applied", 'green');
    if ($totalFailed > 0) {
        echo ", " . colorize("$totalFailed failed", 'red');
    }
    echo "\n";

    exit($totalFailed > 0 ? 1 : 0);
}

// Handle --migration (supports CSV list and 'all')
if (isset($options['migration'])) {
    $migrationInput = $options['migration'];
    $force = isset($options['force']);
    $markOnly = isset($options['mark']);

    // Handle 'all' keyword
    $availableMigrations = TenantSchemaBuilder::getAvailableMigrations();
    if (strtolower($migrationInput) === 'all') {
        $migrations = $availableMigrations;
    } else {
        // Parse CSV list of migrations
        $migrations = array_map('trim', explode(',', $migrationInput));
        $migrations = array_filter($migrations); // Remove empty entries

        // Validate all migrations exist
        $invalidMigrations = array_diff($migrations, $availableMigrations);
        if (!empty($invalidMigrations)) {
            echo colorize("Error: Unknown migrations: " . implode(', ', $invalidMigrations) . "\n", 'red');
            echo "Available migrations: " . implode(', ', $availableMigrations) . "\n";
            exit(1);
        }
    }

    $tenants = TenantSchemaBuilder::getAvailableTenants();

    // Filter to specific tenant if provided
    if (isset($options['tenant'])) {
        $targetTenant = $options['tenant'];
        if (!isset($tenants[$targetTenant])) {
            echo colorize("Error: Tenant '$targetTenant' not found.\n", 'red');
            exit(1);
        }
        $tenants = [$targetTenant => $tenants[$targetTenant]];
    }

    if (empty($tenants)) {
        echo colorize("No tenants found.\n", 'yellow');
        exit(0);
    }

    $migrationLabel = count($migrations) === 1 ? $migrations[0] : count($migrations) . ' migrations';
    $action = $markOnly ? "Marking" : "Running";
    echo "$action: " . colorize($migrationLabel, 'blue');
    if ($force && !$markOnly) {
        echo " " . colorize("(FORCE)", 'yellow');
    }
    if ($markOnly) {
        echo " " . colorize("(MARK ONLY)", 'cyan');
    }
    echo "\n";
    echo "Tenants: " . count($tenants) . "\n";
    echo str_repeat('-', 50) . "\n";

    $success = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($tenants as $slug => $tenant) {
        echo "\n  " . colorize("[$slug]", 'cyan');

        if ($markOnly) {
            // Mark mode - just record without running
            $result = TenantSchemaBuilder::markForTenant($migrations, $slug);
            if ($result['success']) {
                $marked = count($result['marked'] ?? []);
                $tenantSkipped = count($result['skipped'] ?? []);
                if ($marked > 0) {
                    echo " " . colorize("$marked MARKED", 'green');
                    $success += $marked;
                }
                if ($tenantSkipped > 0) {
                    echo ($marked > 0 ? ", " : " ") . colorize("$tenantSkipped skipped", 'yellow');
                    $skipped += $tenantSkipped;
                }
                if ($marked === 0 && $tenantSkipped === 0) {
                    echo " " . colorize("NOTHING TO DO", 'yellow');
                }
            } else {
                echo " " . colorize("ERROR", 'red') . ": " . ($result['error'] ?? 'Unknown');
                $failed++;
            }
        } else {
            // Run mode
            $tenantSuccess = 0;
            $tenantSkipped = 0;
            $tenantFailed = 0;

            foreach ($migrations as $migration) {
                $result = runMigrationOnTenant($migration, $slug, $force);

                if ($result['success']) {
                    if (!empty($result['skipped'])) {
                        $tenantSkipped++;
                    } else {
                        $tenantSuccess++;
                    }
                } else {
                    $tenantFailed++;
                    echo "\n    " . colorize("✗ $migration", 'red') . ": " . ($result['error'] ?? 'Unknown error');
                }
            }

            // Show tenant summary
            if ($tenantFailed === 0) {
                if ($tenantSkipped === count($migrations)) {
                    echo " " . colorize("SKIPPED", 'yellow') . " (already applied)";
                } else {
                    echo " " . colorize("OK", 'green');
                    if ($tenantSkipped > 0) {
                        echo " ($tenantSkipped skipped)";
                    }
                }
            }

            $success += $tenantSuccess;
            $skipped += $tenantSkipped;
            $failed += $tenantFailed;
        }
    }

    echo "\n\n" . str_repeat('-', 50) . "\n";
    $label = $markOnly ? "marked" : "applied";
    echo "Results: " . colorize("$success $label", 'green');
    if ($skipped > 0) {
        echo ", " . colorize("$skipped skipped", 'yellow');
    }
    if ($failed > 0) {
        echo ", " . colorize("$failed failed", 'red');
    }
    echo "\n";

    exit($failed > 0 ? 1 : 0);
}

// No valid options provided
printHelp();
exit(1);
