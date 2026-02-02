#!/usr/bin/env php
<?php
/**
 * Add Apps permissions to an existing workspace
 *
 * Usage: php scripts/add-apps-permissions.php --workspace=gwt
 */

require_once __DIR__ . '/../vendor/autoload.php';

$options = getopt('', ['workspace:']);
$workspace = $options['workspace'] ?? null;

if (!$workspace) {
    echo "Usage: php scripts/add-apps-permissions.php --workspace=SLUG\n";
    exit(1);
}

// Initialize database
\app\services\TenantAppDatabase::init($workspace);

$permissions = [
    ['apps', 'index', 100, 'List tenant apps'],
    ['apps', 'form', 100, 'Create/edit app form'],
    ['apps', 'store', 100, 'Create new app'],
    ['apps', 'update', 100, 'Update app'],
    ['apps', 'delete', 100, 'Delete app'],
    ['apps', 'start', 100, 'Start app'],
    ['apps', 'stop', 100, 'Stop app'],
    ['apps', 'restart', 100, 'Restart app'],
    ['apps', 'status', 100, 'Get app status'],
    ['apps', 'logs', 100, 'Get app logs'],
    ['apps', 'generatekey', 100, 'Generate API key'],
    ['apps', 'api', 100, 'List apps API'],
    ['apps', 'ports', 100, 'Get port statistics'],
];

$added = 0;
$skipped = 0;

foreach ($permissions as $perm) {
    $existing = \RedBeanPHP\R::findOne('authcontrol', 'control = ? AND method = ?', [$perm[0], $perm[1]]);
    if ($existing) {
        echo "Skipping (exists): {$perm[0]}/{$perm[1]}\n";
        $skipped++;
        continue;
    }

    $bean = \RedBeanPHP\R::dispense('authcontrol');
    $bean->control = $perm[0];
    $bean->method = $perm[1];
    $bean->level = $perm[2];
    $bean->description = $perm[3];
    $bean->created_at = date('Y-m-d H:i:s');
    \RedBeanPHP\R::store($bean);

    echo "Added: {$perm[0]}/{$perm[1]} (level {$perm[2]})\n";
    $added++;
}

echo "\nDone. Added: {$added}, Skipped: {$skipped}\n";
