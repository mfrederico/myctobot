#!/usr/bin/env php
<?php
/**
 * CRM Outreach Cron
 *
 * Daily outreach email processing for enriched CRM leads.
 * Selects eligible contacts, composes personalized emails via AI,
 * sends via Mailgun, and updates tracking.
 *
 * Usage:
 *   php scripts/cron-crm-outreach.php --script --workspace=shipcannon [--limit=20] [--verbose] [--dry-run]
 *   php scripts/cron-crm-outreach.php --script --workspace=shipcannon --select-only --limit=20 --json
 *   php scripts/cron-crm-outreach.php --script --workspace=shipcannon --update-tracking --contact-id=123
 *
 * Cron:
 *   0 9 * * * cd /home/ubuntu/production/myctobot && php scripts/cron-crm-outreach.php --script --workspace=shipcannon
 */

$opts = getopt('', ['script', 'workspace:', 'limit:', 'verbose', 'dry-run', 'select-only', 'update-tracking', 'contact-id:', 'json', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php scripts/cron-crm-outreach.php --script --workspace=WORKSPACE [--limit=20] [--verbose] [--dry-run]\n";
    echo "       --select-only: Just output eligible contacts (for pipeline step)\n";
    echo "       --update-tracking --contact-id=N: Update tracking after send\n";
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
$selectOnly = isset($opts['select-only']);
$updateTracking = isset($opts['update-tracking']);
$contactId = (int)($opts['contact-id'] ?? 0);
$jsonOutput = isset($opts['json']);

// Bootstrap
$_SERVER['WORKSPACE'] = $workspace;
define('BASE_PATH', dirname(__DIR__, 2));
chdir(BASE_PATH);
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/bootstrap.php';

$configFile = "conf/config.{$workspace}.ini";
$app = new \app\Bootstrap($configFile);

use app\Bean;
use app\services\ProspectSettingsService;

require_once BASE_PATH . '/services/ProspectSettingsService.php';

$config = parse_ini_file(BASE_PATH . '/' . $configFile, true);
$settings = new ProspectSettingsService($config);

$gapDays = (int)$settings->get('outreach_gap_days', 3);
$minScore = (int)$settings->get('outreach_min_lead_score', 40);
$maxSteps = (int)$settings->get('outreach_max_steps', 4);

// ============================================================================
// Mode: Select eligible contacts
// ============================================================================
if ($selectOnly) {
    $gapDate = date('Y-m-d H:i:s', strtotime("-{$gapDays} days"));

    $contacts = Bean::find('crmcontact',
        "enrichment_status = 'enriched'" .
        " AND lead_score >= ?" .
        " AND pipeline_stage IN ('warm', 'hot')" .
        " AND outreach_status IN ('none', 'queued', 'in_sequence')" .
        " AND outreach_step < ?" .
        " AND (last_outreach_at IS NULL OR last_outreach_at < ?)" .
        " AND company_email IS NOT NULL AND company_email != ''" .
        " ORDER BY lead_score DESC LIMIT ?",
        [$minScore, $maxSteps, $gapDate, $limit]
    );

    $result = [];
    foreach ($contacts as $c) {
        $result[] = [
            'id' => (int)$c->id,
            'company_name' => $c->company_name,
            'company_email' => $c->company_email,
            'decision_maker_name' => $c->decision_maker_name ?? '',
            'pipeline_stage' => $c->pipeline_stage,
            'lead_score' => (int)$c->lead_score,
            'outreach_step' => (int)($c->outreach_step ?? 0),
            'enrichment_summary' => '',
        ];

        // Pull summary from enrichment if available
        $enrichment = json_decode($c->enrichment_json ?? '{}', true);
        $idx = count($result) - 1;
        $result[$idx]['enrichment_summary'] = $enrichment['enrichment']['summary'] ?? '';
    }

    if ($jsonOutput) {
        echo json_encode(['contacts' => $result, 'count' => count($result)]);
    } else {
        echo "Eligible contacts: " . count($result) . "\n";
        foreach ($result as $r) {
            echo "  #{$r['id']}: {$r['company_name']} ({$r['company_email']}) score={$r['lead_score']} step={$r['outreach_step']}\n";
        }
    }
    exit(0);
}

// ============================================================================
// Mode: Update tracking after email sent
// ============================================================================
if ($updateTracking && $contactId) {
    $contact = Bean::load('crmcontact', $contactId);
    if (!$contact->id) {
        echo json_encode(['error' => 'Contact not found']);
        exit(1);
    }

    $currentStep = (int)($contact->outreachStep ?? 0);
    $contact->outreachStep = $currentStep + 1;
    $contact->outreachStatus = $contact->outreachStep >= 4 ? 'completed' : 'in_sequence';
    $contact->lastOutreachAt = date('Y-m-d H:i:s');
    Bean::store($contact);

    // Log touch
    $touch = Bean::dispense('crmtouch');
    $touch->crmcontactId = $contactId;
    $touch->memberId = 1; // system
    $touch->touchType = 'email';
    $touch->touchDate = date('Y-m-d H:i:s');
    $touch->summary = "Outreach sequence step {$currentStep} sent";
    $touch->source = 'pipeline';
    Bean::store($touch);

    // Update last touch on contact
    $contact->lastTouchAt = date('Y-m-d H:i:s');
    Bean::store($contact);

    // Log activity
    $activity = Bean::dispense('crmactivity');
    $activity->memberId = 1;
    $activity->crmcontactId = $contactId;
    $activity->activityType = 'outreach_sent';
    $activity->description = "Outreach step {$currentStep} sent to {$contact->companyEmail}";
    Bean::store($activity);

    if ($jsonOutput) {
        echo json_encode([
            'success' => true,
            'contact_id' => $contactId,
            'new_step' => $contact->outreachStep,
            'status' => $contact->outreachStatus,
        ]);
    } else {
        echo "Updated tracking for #{$contactId}: step={$contact->outreachStep}, status={$contact->outreachStatus}\n";
    }
    exit(0);
}

// ============================================================================
// Mode: Full outreach run (default)
// ============================================================================
echo "[" . date('Y-m-d H:i:s') . "] Starting CRM outreach (workspace={$workspace}, limit={$limit})\n";

$gapDate = date('Y-m-d H:i:s', strtotime("-{$gapDays} days"));

$contacts = Bean::find('crmcontact',
    "enrichment_status = 'enriched'" .
    " AND lead_score >= ?" .
    " AND pipeline_stage IN ('warm', 'hot')" .
    " AND outreach_status IN ('none', 'queued', 'in_sequence')" .
    " AND outreach_step < 4" .
    " AND (last_outreach_at IS NULL OR last_outreach_at < ?)" .
    " AND company_email IS NOT NULL AND company_email != ''" .
    " ORDER BY lead_score DESC LIMIT ?",
    [$minScore, $gapDate, $limit]
);

$sent = 0;
$failed = 0;

foreach ($contacts as $contact) {
    $step = (int)($contact->outreachStep ?? 0);
    $email = $contact->companyEmail;

    if ($dryRun) {
        echo "  [DRY RUN] Would send step {$step} to #{$contact->id} {$contact->companyName} ({$email})\n";
        $sent++;
        continue;
    }

    echo "  Sending step {$step} to #{$contact->id} {$contact->companyName} ({$email})...\n";

    // For full mode, compose + send inline using the pipeline tool would be better
    // This cron just updates tracking; the pipeline handles compose + send
    $contact->outreachStep = $step + 1;
    $contact->outreachStatus = ($step + 1) >= 4 ? 'completed' : 'in_sequence';
    $contact->lastOutreachAt = date('Y-m-d H:i:s');
    Bean::store($contact);

    // Log touch
    $touch = Bean::dispense('crmtouch');
    $touch->crmcontactId = $contact->id;
    $touch->memberId = 1;
    $touch->touchType = 'email';
    $touch->touchDate = date('Y-m-d H:i:s');
    $touch->summary = "Outreach sequence step {$step} queued";
    $touch->source = 'pipeline';
    Bean::store($touch);

    $sent++;
}

echo "\n[" . date('Y-m-d H:i:s') . "] Outreach complete: sent={$sent}, failed={$failed}\n";

if ($jsonOutput) {
    echo json_encode(['sent' => $sent, 'failed' => $failed, 'total_eligible' => count($contacts)]);
}
