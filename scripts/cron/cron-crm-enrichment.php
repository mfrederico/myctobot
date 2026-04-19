#!/usr/bin/env php
<?php
/**
 * CRM Lead Enrichment Cron
 *
 * Enriches unenriched cold CRM leads with web search + AI analysis.
 * Processes batch of contacts, fills in missing details, calculates lead scores.
 *
 * Usage:
 *   php scripts/cron-crm-enrichment.php --script --workspace=shipcannon [--limit=20] [--verbose] [--dry-run]
 *
 * Cron:
 *   0 6 * * * cd /home/ubuntu/production/myctobot && php scripts/cron-crm-enrichment.php --script --workspace=shipcannon
 */

$opts = getopt('', ['script', 'workspace:', 'limit:', 'verbose', 'dry-run', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php scripts/cron-crm-enrichment.php --script --workspace=WORKSPACE [--limit=20] [--verbose] [--dry-run]\n";
    exit(0);
}

if (!isset($opts['script'])) {
    echo "Error: --script flag required\n";
    exit(1);
}

$workspace = $opts['workspace'] ?? 'shipcannon';
$limit = (int)($opts['limit'] ?? 20);
$verbose = isset($opts['verbose']);
$dryRun = isset($opts['dry-run']);

// Bootstrap
$_SERVER['WORKSPACE'] = $workspace;
define('BASE_PATH', dirname(__DIR__, 2));
chdir(BASE_PATH);
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/bootstrap.php';

$configFile = "conf/config.{$workspace}.ini";
$app = new \app\Bootstrap($configFile);

use app\Bean;
use app\services\CrmEnrichmentService;
use app\services\ProspectSettingsService;

require_once BASE_PATH . '/services/CrmEnrichmentService.php';
require_once BASE_PATH . '/services/ProspectSettingsService.php';

// Load config with settings service for UI-configurable params
$config = parse_ini_file(BASE_PATH . '/' . $configFile, true);
$settings = new ProspectSettingsService($config);

$limit = (int)($opts['limit'] ?? $settings->get('enrichment_batch_size', 20));

echo "[" . date('Y-m-d H:i:s') . "] Starting CRM enrichment (workspace={$workspace}, limit={$limit})\n";

if ($dryRun) {
    // Just show what would be enriched
    $contacts = Bean::find('crmcontact',
        "(enrichment_status IS NULL OR enrichment_status = '' OR enrichment_status = 'pending')" .
        " AND status_category = 'prospect'" .
        " AND company_name IS NOT NULL AND company_name != ''" .
        " ORDER BY created_at ASC LIMIT ?",
        [$limit]
    );

    echo "DRY RUN: Would enrich " . count($contacts) . " contacts:\n";
    foreach ($contacts as $c) {
        echo "  - #{$c->id}: {$c->company_name} ({$c->company_email})\n";
    }
    exit(0);
}

$service = new CrmEnrichmentService($config);
$results = $service->enrichBatch($limit);

echo "\n[" . date('Y-m-d H:i:s') . "] Enrichment complete:\n";
echo "  Processed: {$results['processed']}\n";
echo "  Enriched:  {$results['enriched']}\n";
echo "  Failed:    {$results['failed']}\n";

if ($verbose && !empty($results['details'])) {
    echo "\nDetails:\n";
    foreach ($results['details'] as $detail) {
        $status = $detail['success'] ? "OK (score={$detail['lead_score']})" : "FAIL: {$detail['error']}";
        echo "  #{$detail['contact_id']} {$detail['company']}: {$status}\n";
    }
}

// Output JSON for pipeline consumption
echo "\n" . json_encode($results);
