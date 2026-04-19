#!/usr/bin/env php
<?php
/**
 * Store Discovery Cron - Queries Google Custom Search Engine for
 * Shopify and Miva Merchant stores, stores raw URLs in prospectstore.
 *
 * Usage:
 *   php scripts/cron-prospect-discover.php --script [--verbose] [--dry-run] [--query="specific query"]
 *
 * Cron:
 *   0 6 * * * cd /home/ubuntu/production/myctobot && php scripts/cron-prospect-discover.php --script
 */

$opts = getopt('', ['script', 'verbose', 'dry-run', 'query:', 'workspace:', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php scripts/cron-prospect-discover.php --script [--workspace=shipcannon] [--verbose] [--dry-run] [--query=\"...\"]\n";
    exit(0);
}

$verbose = isset($opts['verbose']);
$dryRun = isset($opts['dry-run']);
$specificQuery = $opts['query'] ?? null;
$workspace = $opts['workspace'] ?? 'shipcannon';

// Bootstrap with workspace
$_SERVER['WORKSPACE'] = $workspace;
define('BASE_PATH', dirname(__DIR__, 2));
chdir(BASE_PATH);
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/bootstrap.php';

$app = new \app\Bootstrap("conf/config.{$workspace}.ini");

use app\Bean;
use app\services\StoreDiscoveryService;
use app\services\ProspectSettingsService;

require_once BASE_PATH . '/services/StoreDiscoveryService.php';
require_once BASE_PATH . '/services/ProspectSettingsService.php';

// Load config (INI for StoreDiscoveryService, settings service for UI-configurable params)
$configFile = __DIR__ . "/../../conf/config.{$workspace}.ini";
$config = parse_ini_file($configFile, true);
$settings = new ProspectSettingsService($config);

$maxDailyQueries = (int)$settings->get('max_daily_queries', 10);

$service = new StoreDiscoveryService($config);

echo "[" . date('Y-m-d H:i:s') . "] Starting store discovery\n";

// Load search queries from settings service (DB -> file fallback)
if ($specificQuery) {
    $queries = [$specificQuery];
} else {
    $queryText = $settings->getSearchQueries();
    $lines = explode("\n", $queryText);
    $queries = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line && $line[0] !== '#') {
            $queries[] = $line;
        }
    }
}

if (empty($queries)) {
    echo "No queries to run\n";
    exit(0);
}

// State tracking: per-query pagination so we find NEW results each run
$stateFile = __DIR__ . "/../../cache/{$workspace}/prospect-discover-state.json";
$cacheDir = dirname($stateFile);
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
$state = [];
if (file_exists($stateFile)) {
    $state = json_decode(file_get_contents($stateFile), true) ?: [];
}

// Per-query page tracking: {"query_hash": {"page": 2, "exhausted": false, "last_run": "2026-03-21"}}
$queryPages = $state['query_pages'] ?? [];
$queryRotation = (int)($state['query_rotation'] ?? 0);

// Select today's batch of queries (rotate through the list)
$today = date('Y-m-d');
if (!$specificQuery) {
    // Build available queries: not exhausted, prefer ones not yet run today
    $notRunToday = [];
    $ranToday = [];
    foreach ($queries as $q) {
        $hash = md5($q);
        $info = $queryPages[$hash] ?? ['page' => 1, 'exhausted' => false, 'last_run' => ''];
        if ($info['exhausted']) continue;

        $entry = ['query' => $q, 'hash' => $hash, 'page' => $info['page'] ?? 1, 'last_run' => $info['last_run'] ?? ''];
        if (($info['last_run'] ?? '') === $today) {
            $ranToday[] = $entry;
        } else {
            $notRunToday[] = $entry;
        }
    }

    // Prioritize queries that haven't run today, then oldest-first within each group
    usort($notRunToday, fn($a, $b) => strcmp($a['last_run'], $b['last_run']));
    usort($ranToday, fn($a, $b) => strcmp($a['last_run'], $b['last_run']));
    $available = array_merge($notRunToday, $ranToday);
    $todayQueries = array_slice($available, 0, $maxDailyQueries);

    if (empty($todayQueries)) {
        // All queries exhausted - reset all pages to try deeper
        echo "All queries exhausted, resetting pages\n";
        $queryPages = [];
        foreach ($queries as $q) {
            $todayQueries[] = ['query' => $q, 'hash' => md5($q), 'page' => 1, 'last_run' => ''];
        }
        $todayQueries = array_slice($todayQueries, 0, $maxDailyQueries);
    }

    $freshCount = count($notRunToday);
    $staleCount = count($ranToday);
    if ($verbose) {
        echo "Query pool: {$freshCount} fresh, {$staleCount} already-ran-today, selecting " . count($todayQueries) . "\n";
    }

    // Stop if no fresh queries left — don't waste API credits on repeats
    if ($freshCount === 0 && !empty($todayQueries)) {
        echo "All queries already ran today. Stopping to conserve API credits.\n";
        echo "Next run will use the same queries at deeper page depths.\n";
        exit(0);
    }
} else {
    $hash = md5($specificQuery);
    $page = ($queryPages[$hash]['page'] ?? 1);
    $todayQueries = [['query' => $specificQuery, 'hash' => $hash, 'page' => $page, 'last_run' => '']];
}

// Skip domains from settings (configurable via Pipeline Settings UI)
$skipDomainsStr = $settings->get('skip_domains', '');
$skipDomains = array_filter(array_map('trim', explode(',', $skipDomainsStr)));

$totalDiscovered = 0;
$totalSkipped = 0;
$queriesRun = 0;

foreach ($todayQueries as $qi) {
    $query = $qi['query'];
    $hash = $qi['hash'];
    $page = $qi['page'];
    $startIndex = (($page - 1) * 10) + 1;

    $queriesRun++;
    if ($verbose) echo "  Query {$queriesRun} (page {$page}): {$query}\n";

    $results = $service->searchGoogle($query, $startIndex);

    if (empty($results)) {
        if ($verbose) echo "    No results - marking query as exhausted\n";
        $queryPages[$hash] = ['page' => $page, 'exhausted' => true, 'last_run' => date('Y-m-d')];
        continue;
    }

    if ($verbose) echo "    Got " . count($results) . " results\n";

    $newThisQuery = 0;
    foreach ($results as $result) {
        $url = $result['link'];
        $domain = $service->normalizeDomain($url);

        if (empty($domain)) continue;

        // Skip common non-store domains
        $skip = false;
        foreach ($skipDomains as $sd) {
            if ($domain === $sd || str_ends_with($domain, '.' . $sd)) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            $totalSkipped++;
            continue;
        }

        // Dedup check
        $existing = Bean::findOne('prospectstore', 'domain = ?', [$domain]);
        if ($existing) {
            $totalSkipped++;
            if ($verbose) echo "    Skip (exists): {$domain}\n";
            continue;
        }

        if ($dryRun) {
            echo "    [DRY RUN] Would store: {$domain} ({$url})\n";
            $totalDiscovered++;
            $newThisQuery++;
            continue;
        }

        // Store new prospect
        $prospect = Bean::dispense('prospectstore');
        $prospect->domain = $domain;
        $prospect->storeUrl = $url;
        $prospect->searchQuery = $query;
        $prospect->source = 'google-cse';
        $prospect->status = 'discovered';
        $prospect->platform = 'unknown';
        Bean::store($prospect);

        $totalDiscovered++;
        $newThisQuery++;
        if ($verbose) echo "    Stored: {$domain}\n";
    }

    // If zero new results from this page, advance to next page
    // If still zero after advancing, it'll be marked exhausted next run
    $nextPage = $page + 1;
    if ($newThisQuery === 0 && count($results) < 10) {
        // Short results page with no new finds = exhausted
        $queryPages[$hash] = ['page' => $nextPage, 'exhausted' => true, 'last_run' => date('Y-m-d')];
        if ($verbose) echo "    Query exhausted (no new results)\n";
    } else {
        // Advance to next page for next run
        $queryPages[$hash] = ['page' => $nextPage, 'exhausted' => false, 'last_run' => date('Y-m-d')];
    }

    // Rate limit between queries
    if ($queriesRun < count($todayQueries)) {
        sleep(1);
    }
}

// Save state
if (!$dryRun) {
    $newState = [
        'query_pages' => $queryPages,
        'query_rotation' => $queryRotation + 1,
        'last_run' => date('Y-m-d H:i:s'),
        'last_discovered' => $totalDiscovered,
    ];
    file_put_contents($stateFile, json_encode($newState, JSON_PRETTY_PRINT));
}

echo "[" . date('Y-m-d H:i:s') . "] Done. Queries: {$queriesRun}, Discovered: {$totalDiscovered}, Skipped: {$totalSkipped}\n";
