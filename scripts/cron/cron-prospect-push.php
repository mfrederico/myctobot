#!/usr/bin/env php
<?php
/**
 * Prospect Push Cron - Pushes qualified prospects into the CRM as cold leads.
 * Creates crmcontact beans with tags, notes, and activity logging.
 *
 * Usage:
 *   php scripts/cron-prospect-push.php --script [--verbose] [--dry-run] [--limit=50]
 *
 * Cron:
 *   0 8 * * * cd /home/ubuntu/production/myctobot && php scripts/cron-prospect-push.php --script
 */

$opts = getopt('', ['script', 'verbose', 'dry-run', 'limit:', 'workspace:', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php scripts/cron-prospect-push.php --script [--workspace=shipcannon] [--verbose] [--dry-run] [--limit=50]\n";
    exit(0);
}

$verbose = isset($opts['verbose']);
$dryRun = isset($opts['dry-run']);
$workspace = $opts['workspace'] ?? 'shipcannon';

// Bootstrap with workspace
$_SERVER['WORKSPACE'] = $workspace;
define('BASE_PATH', dirname(__DIR__, 2));
chdir(BASE_PATH);
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/bootstrap.php';

$app = new \app\Bootstrap("conf/config.{$workspace}.ini");

use app\Bean;
use app\services\ProspectSettingsService;

require_once BASE_PATH . '/services/ProspectSettingsService.php';

// Load settings from DB with INI fallback
$configFile = __DIR__ . "/../../conf/config.{$workspace}.ini";
$config = parse_ini_file($configFile, true);
$settings = new ProspectSettingsService($config);

$limit = (int)($opts['limit'] ?? $settings->get('push_batch_limit', 50));

echo "[" . date('Y-m-d H:i:s') . "] Starting prospect push to CRM\n";

// Load qualified prospects
$prospects = Bean::find('prospectstore',
    'status = ? ORDER BY self_ship_confidence DESC LIMIT ?',
    ['qualified', $limit]
);

if (empty($prospects)) {
    echo "No qualified prospects to push\n";
    exit(0);
}

echo "Found " . count($prospects) . " qualified prospects\n";

$stats = ['pushed' => 0, 'duplicates' => 0, 'errors' => 0];

foreach ($prospects as $prospect) {
    $domain = $prospect->domain;
    $email = $prospect->contactEmail ?: '';

    if ($verbose) echo "\n  Processing: {$domain}\n";

    try {
        // Dedup check against existing CRM contacts
        $existing = null;
        if ($email) {
            $existing = Bean::findOne('crmcontact', 'company_email = ?', [$email]);
        }
        if (!$existing) {
            $existing = Bean::findOne('crmcontact', 'company_name = ?', [$domain]);
        }

        if ($existing) {
            if ($verbose) echo "    Duplicate (CRM ID: {$existing->id})\n";
            $prospect->status = 'pushed';
            $prospect->crmcontactId = $existing->id;
            $prospect->pushedAt = date('Y-m-d H:i:s');
            Bean::store($prospect);
            $stats['duplicates']++;
            continue;
        }

        // Parse contact name into first/last
        $firstName = '';
        $lastName = '';
        $contactName = trim($prospect->contactName ?? '');
        if ($contactName) {
            $parts = explode(' ', $contactName, 2);
            $firstName = $parts[0] ?? '';
            $lastName = $parts[1] ?? '';
        }

        // Build notes from AI analysis
        $analysis = json_decode($prospect->aiAnalysis ?: '{}', true);
        $sellingPoints = json_decode($prospect->sellingPoints ?: '[]', true);

        $noteLines = [];
        $noteLines[] = "Auto-discovered via Google CSE ({$prospect->platform} store)";
        $noteLines[] = "URL: {$prospect->storeUrl}";
        $noteLines[] = "Self-shipping confidence: " . round(($prospect->selfShipConfidence ?? 0) * 100) . "%";

        if (!empty($analysis['reasoning'])) {
            $noteLines[] = "";
            $noteLines[] = "AI Analysis: {$analysis['reasoning']}";
        }

        if (!empty($analysis['signals'])) {
            $noteLines[] = "";
            $noteLines[] = "Signals: " . implode(', ', $analysis['signals']);
        }

        if (!empty($sellingPoints)) {
            $noteLines[] = "";
            $noteLines[] = "WMS Selling Points:";
            foreach ($sellingPoints as $point) {
                $noteLines[] = "- {$point}";
            }
        }

        if (!empty($analysis['size_reasoning'])) {
            $noteLines[] = "";
            $noteLines[] = "Size: {$prospect->estimatedSize} - {$analysis['size_reasoning']}";
        }

        // Build tags
        $tags = [$prospect->platform, 'auto-discovered', 'self-shipping'];
        if ($prospect->estimatedSize) {
            $tags[] = "size-{$prospect->estimatedSize}";
        }

        if ($dryRun) {
            echo "    [DRY RUN] Would create CRM contact:\n";
            echo "      Company: {$domain}\n";
            echo "      Email: {$email}\n";
            echo "      Name: {$firstName} {$lastName}\n";
            echo "      Tags: " . implode(', ', $tags) . "\n";
            $stats['pushed']++;
            continue;
        }

        // Calculate lead score from configurable weights
        $leadScore = 0;
        if (!empty($email)) $leadScore += (int)$settings->get('lead_score_email', 20);
        if (!empty($prospect->contactPhone)) $leadScore += (int)$settings->get('lead_score_phone', 10);
        if (!empty($contactName)) $leadScore += (int)$settings->get('lead_score_name', 20);
        if (!empty($prospect->estimatedSize)) $leadScore += (int)$settings->get('lead_score_size', 10);
        if (!empty($analysis['signals'])) $leadScore += (int)$settings->get('lead_score_signals', 15);
        $confidence = (float)($prospect->selfShipConfidence ?? 0);
        $confidenceMax = (int)$settings->get('lead_score_confidence_max', 25);
        if ($confidence > 0) $leadScore += (int)round($confidence * $confidenceMax);

        // Build enrichment JSON for the CRM view
        $enrichmentJson = json_encode([
            'enrichment' => [
                'confidence' => $confidence,
                'shipping_signals' => !empty($analysis['signals']) ? implode('; ', $analysis['signals']) : '',
                'industry' => $prospect->platform === 'miva' ? 'Miva Merchant eCommerce' : 'Shopify eCommerce',
                'summary' => $analysis['reasoning'] ?? '',
                'wms_selling_points' => $sellingPoints,
                'estimated_size' => $prospect->estimatedSize ?? '',
            ],
        ]);

        // Create CRM contact
        $contact = Bean::dispense('crmcontact');
        $contact->firstName = $firstName;
        $contact->lastName = $lastName;
        $contact->companyEmail = $email;
        $contact->companyName = $domain;
        $contact->phone = $prospect->contactPhone ?: '';
        $contact->accountType = 'brand';
        $contact->execType = 'sales';
        $contact->statusCategory = 'prospect';
        $contact->pipelineStage = $settings->get('push_default_stage', 'cold');
        $contact->isActive = 1;
        $contact->memberId = 1; // System/unassigned
        $contact->estimatedShipments = $prospect->estimatedSize ?: '';
        $contact->tags = implode(',', $tags);
        $contact->notes = implode("\n", $noteLines);
        $contact->websiteUrl = $prospect->storeUrl;
        $contact->decisionMakerName = $contactName ?: '';
        $contact->leadScore = $leadScore;
        $contact->enrichmentStatus = 'completed';
        $contact->enrichmentJson = $enrichmentJson;
        $contact->enrichmentSource = 'auto-discovery';
        $contact->enrichedAt = date('Y-m-d H:i:s');
        Bean::store($contact);

        // Log activity
        $activity = Bean::dispense('crmactivity');
        $activity->memberId = 1;
        $activity->crmcontactId = $contact->id;
        $activity->activityType = 'contact_created';
        $activity->description = "Auto-discovered {$prospect->platform} store (self-ship confidence: " .
            round(($prospect->selfShipConfidence ?? 0) * 100) . "%)";
        Bean::store($activity);

        // Update prospect
        $prospect->status = 'pushed';
        $prospect->crmcontactId = $contact->id;
        $prospect->pushedAt = date('Y-m-d H:i:s');
        Bean::store($prospect);

        $stats['pushed']++;
        if ($verbose) echo "    Created CRM contact ID: {$contact->id}\n";

    } catch (\Exception $e) {
        echo "    Error: " . $e->getMessage() . "\n";
        $prospect->status = 'error';
        $prospect->errorMessage = "Push failed: " . $e->getMessage();
        Bean::store($prospect);
        $stats['errors']++;
    }
}

echo "\n[" . date('Y-m-d H:i:s') . "] Done. Pushed: {$stats['pushed']}, Duplicates: {$stats['duplicates']}, Errors: {$stats['errors']}\n";
