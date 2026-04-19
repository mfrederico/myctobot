#!/usr/bin/env php
<?php
/**
 * Store Analysis Cron - Fetches discovered store pages, detects platform,
 * runs AI analysis for self-shipping likelihood, and qualifies leads.
 *
 * Usage:
 *   php scripts/cron-prospect-analyze.php --script [--verbose] [--batch=20] [--retry-errors]
 *
 * Cron:
 *   0 7 * * * cd /home/ubuntu/production/myctobot && php scripts/cron-prospect-analyze.php --script
 */

$opts = getopt('', ['script', 'verbose', 'batch:', 'retry-errors', 'workspace:', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php scripts/cron-prospect-analyze.php --script [--workspace=shipcannon] [--verbose] [--batch=20] [--retry-errors]\n";
    exit(0);
}

$verbose = isset($opts['verbose']);
$retryErrors = isset($opts['retry-errors']);
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

// Load config
$configFile = __DIR__ . "/../../conf/config.{$workspace}.ini";
$config = parse_ini_file($configFile, true);
$settings = new ProspectSettingsService($config);

$batchSize = (int)($opts['batch'] ?? $settings->get('batch_size', 20));
$fetchDelay = (int)$settings->get('fetch_delay', 2);

$service = new StoreDiscoveryService($config);

echo "[" . date('Y-m-d H:i:s') . "] Starting store analysis (batch: {$batchSize})\n";

// Load unanalyzed stores
$statusCondition = '(status = ? OR status = ?)';
$statusParams = ['discovered', 'fetched'];

if ($retryErrors) {
    $statusCondition = '(status = ? OR status = ? OR status = ?)';
    $statusParams = ['discovered', 'fetched', 'error'];
}

$prospects = Bean::find('prospectstore',
    "{$statusCondition} ORDER BY id ASC LIMIT {$batchSize}",
    $statusParams
);

if (empty($prospects)) {
    echo "No stores to analyze\n";
    exit(0);
}

echo "Found " . count($prospects) . " stores to process\n";

$stats = ['fetched' => 0, 'analyzed' => 0, 'qualified' => 0, 'disqualified' => 0, 'errors' => 0];

foreach ($prospects as $prospect) {
    $domain = $prospect->domain;
    if ($verbose) echo "\n  Processing: {$domain}\n";

    try {
        // Step 1: Fetch homepage
        $url = $prospect->storeUrl;
        if (!preg_match('#^https?://#', $url)) {
            $url = 'https://' . $url;
        }

        $homepageResult = $service->fetchStorePage($url);

        if ($homepageResult['error'] || $homepageResult['httpCode'] >= 400) {
            $errorMsg = $homepageResult['error'] ?: "HTTP {$homepageResult['httpCode']}";
            if ($verbose) echo "    Fetch failed: {$errorMsg}\n";
            $prospect->status = 'error';
            $prospect->errorMessage = "Homepage fetch: {$errorMsg}";
            Bean::store($prospect);
            $stats['errors']++;
            sleep($fetchDelay);
            continue;
        }

        $html = $homepageResult['html'];
        $finalUrl = $homepageResult['finalUrl'];

        // Update domain if redirected to a different one
        $redirectedDomain = $service->normalizeDomain($finalUrl);
        if ($redirectedDomain && $redirectedDomain !== $domain) {
            // Check if redirected domain already exists
            $existingRedirect = Bean::findOne('prospectstore', 'domain = ? AND id != ?', [$redirectedDomain, $prospect->id]);
            if ($existingRedirect) {
                if ($verbose) echo "    Redirected to existing domain: {$redirectedDomain}, marking as disqualified\n";
                $prospect->status = 'disqualified';
                $prospect->errorMessage = "Redirects to already-tracked domain: {$redirectedDomain}";
                Bean::store($prospect);
                $stats['disqualified']++;
                sleep($fetchDelay);
                continue;
            }
            $prospect->domain = $redirectedDomain;
            $prospect->storeUrl = $finalUrl;
            if ($verbose) echo "    Redirected to: {$redirectedDomain}\n";
        }

        // Step 2: Platform detection
        $platform = $service->detectPlatform($html, $finalUrl);
        $prospect->platform = $platform['platform'];
        $prospect->platformConfidence = $platform['confidence'];

        if ($verbose) echo "    Platform: {$platform['platform']} (confidence: {$platform['confidence']})\n";

        // Skip if not Shopify or Miva
        if ($platform['platform'] === 'unknown') {
            if ($verbose) echo "    Not Shopify/Miva, disqualifying\n";
            $prospect->status = 'disqualified';
            $prospect->errorMessage = 'Platform not detected as Shopify or Miva';
            Bean::store($prospect);
            $stats['disqualified']++;
            sleep($fetchDelay);
            continue;
        }

        // Step 3: Find and fetch subpages
        $subpageUrls = $service->extractSubpageUrls($html, $finalUrl);
        $homepageText = $service->stripHtmlToText($html);
        $shippingText = '';
        $aboutText = '';

        if ($subpageUrls['shipping']) {
            if ($verbose) echo "    Fetching shipping page: {$subpageUrls['shipping']}\n";
            sleep(1); // polite delay
            $shipResult = $service->fetchStorePage($subpageUrls['shipping']);
            if (!$shipResult['error'] && $shipResult['httpCode'] < 400) {
                $shippingText = $service->stripHtmlToText($shipResult['html']);
            }
        }

        if ($subpageUrls['about']) {
            if ($verbose) echo "    Fetching about page: {$subpageUrls['about']}\n";
            sleep(1);
            $aboutResult = $service->fetchStorePage($subpageUrls['about']);
            if (!$aboutResult['error'] && $aboutResult['httpCode'] < 400) {
                $aboutText = $service->stripHtmlToText($aboutResult['html']);
            }
        }

        // Step 4: Extract contact info from all pages
        $contactInfo = $service->extractContactInfo($html);
        if ($subpageUrls['contact']) {
            sleep(1);
            $contactResult = $service->fetchStorePage($subpageUrls['contact']);
            if (!$contactResult['error'] && $contactResult['httpCode'] < 400) {
                $contactPageInfo = $service->extractContactInfo($contactResult['html']);
                $contactInfo['emails'] = array_unique(array_merge($contactInfo['emails'], $contactPageInfo['emails']));
                $contactInfo['phones'] = array_unique(array_merge($contactInfo['phones'], $contactPageInfo['phones']));
            }
        }

        // Store fetched content (sanitize for MySQL utf8mb4)
        $pageContent = substr($shippingText ?: $homepageText, 0, 10000);
        $pageContent = mb_convert_encoding($pageContent, 'UTF-8', 'UTF-8');
        $pageContent = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $pageContent);
        $prospect->shippingPageContent = $pageContent;
        $prospect->status = 'fetched';
        $prospect->fetchedAt = date('Y-m-d H:i:s');
        Bean::store($prospect);
        $stats['fetched']++;

        if ($verbose) echo "    Contact info: " . count($contactInfo['emails']) . " emails, " . count($contactInfo['phones']) . " phones\n";

        // Step 5: AI Analysis
        if ($verbose) echo "    Running AI analysis...\n";

        $pageData = [
            'url' => $finalUrl,
            'platform' => $platform['platform'],
            'homepage' => $homepageText,
            'shippingPage' => $shippingText,
            'aboutPage' => $aboutText,
            'contactInfo' => $contactInfo,
        ];

        $analysis = $service->analyzeWithAI($pageData);

        if (isset($analysis['error'])) {
            if ($verbose) echo "    AI analysis failed: {$analysis['error']}\n";
            $prospect->status = 'error';
            $prospect->errorMessage = "AI analysis: {$analysis['error']}";
            Bean::store($prospect);
            $stats['errors']++;
            sleep($fetchDelay);
            continue;
        }

        // Store AI results
        $prospect->selfShipping = ($analysis['self_shipping'] ?? false) ? 1 : 0;
        $prospect->selfShipConfidence = (float)($analysis['self_shipping_confidence'] ?? 0);
        $prospect->contactEmail = $analysis['contact_email'] ?? ($contactInfo['emails'][0] ?? '');
        $prospect->contactPhone = $analysis['contact_phone'] ?? ($contactInfo['phones'][0] ?? '');
        $prospect->contactName = $analysis['contact_name'] ?? '';
        $prospect->estimatedSize = $analysis['estimated_size'] ?? '';
        $prospect->sellingPoints = json_encode($analysis['wms_selling_points'] ?? []);
        $prospect->aiAnalysis = json_encode($analysis);
        $prospect->analyzedAt = date('Y-m-d H:i:s');

        // Determine final status
        $prospect->status = $service->determineStatus($analysis);
        Bean::store($prospect);

        $stats[$prospect->status === 'qualified' ? 'qualified' : ($prospect->status === 'disqualified' ? 'disqualified' : 'analyzed')]++;

        if ($verbose) {
            $selfShip = $analysis['self_shipping'] ? 'YES' : 'NO';
            echo "    Self-shipping: {$selfShip} (confidence: {$analysis['self_shipping_confidence']})\n";
            echo "    Status: {$prospect->status}\n";
            if (!empty($analysis['reasoning'])) {
                echo "    Reasoning: {$analysis['reasoning']}\n";
            }
        }

    } catch (\Exception $e) {
        echo "    Error: " . $e->getMessage() . "\n";
        $prospect->status = 'error';
        $prospect->errorMessage = $e->getMessage();
        Bean::store($prospect);
        $stats['errors']++;
    }

    sleep($fetchDelay);
}

echo "\n[" . date('Y-m-d H:i:s') . "] Done. ";
echo "Fetched: {$stats['fetched']}, Analyzed: {$stats['analyzed']}, ";
echo "Qualified: {$stats['qualified']}, Disqualified: {$stats['disqualified']}, ";
echo "Errors: {$stats['errors']}\n";
