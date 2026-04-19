#!/usr/bin/env php
<?php
/**
 * CRM LinkedIn Related Companies Discovery
 *
 * Uses enriched CRM contacts as seeds to discover similar companies via
 * LinkedIn Voyager API search. Groups contacts by industry, then searches
 * for more companies in the same space.
 *
 * Usage:
 *   php scripts/cron-crm-linkedin-related.php --workspace=shipcannon [options]
 *
 * Options:
 *   --workspace=NAME   Required. Workspace to operate on.
 *   --limit=N          Max search queries to run (default: 20)
 *   --results=N        Results per search (default: 10)
 *   --delay=N          Seconds between searches (default: 6)
 *   --tag=TAG          Tag for discovered contacts (default: "linkedin-discovery")
 *   --verbose          Show detailed output
 *   --dry-run          Show discoveries without creating contacts
 */

$opts = getopt('', ['workspace:', 'limit:', 'results:', 'delay:', 'tag:', 'verbose', 'dry-run', 'script']);
$workspace = $opts['workspace'] ?? null;
$limit = (int) ($opts['limit'] ?? 20);
$resultsPerSearch = (int) ($opts['results'] ?? 10);
$delay = (int) ($opts['delay'] ?? 6);
$tag = $opts['tag'] ?? 'linkedin-discovery';
$verbose = isset($opts['verbose']);
$dryRun = isset($opts['dry-run']);

if (!$workspace) {
    echo "Usage: php scripts/cron-crm-linkedin-related.php --workspace=NAME [--limit=20] [--results=10] [--verbose] [--dry-run]\n";
    exit(1);
}

$configFile = __DIR__ . "/../../conf/config.{$workspace}.ini";
if (!file_exists($configFile)) {
    echo "Config not found: {$configFile}\n";
    exit(1);
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/LinkedInBrowserService.php';

$app = new \app\Bootstrap($configFile);

use app\Bean;
use app\services\LinkedInBrowserService;

$svc = new LinkedInBrowserService($workspace);

$session = $svc->isSessionValid();
if (empty($session['valid'])) {
    echo "ERROR: LinkedIn session is not valid.\n";
    exit(1);
}
echo "LinkedIn session valid\n\n";

// Build set of existing companies to avoid duplicates
$existingUrls = [];
$existingNames = [];
$allContacts = Bean::findAll('crmcontact');
foreach ($allContacts as $c) {
    if (!empty($c->linkedinUrl)) {
        $existingUrls[strtolower(rtrim($c->linkedinUrl, '/'))] = (int) $c->id;
    }
    if (!empty($c->companyName)) {
        $existingNames[strtolower($c->companyName)] = (int) $c->id;
    }
}
echo "Existing contacts: " . count($existingNames) . " (with LinkedIn: " . count($existingUrls) . ")\n\n";

// Get enriched contacts grouped by industry
$enriched = Bean::find('crmcontact',
    'linkedin_url IS NOT NULL AND linkedin_url != ? AND enrichment_json IS NOT NULL ORDER BY lead_score DESC',
    ['']
);

// Group by industry and build search queries from the validated company names
$searchQueries = [];
foreach ($enriched as $c) {
    $e = json_decode($c->enrichmentJson ?: '{}', true);
    $liName = $e['linkedin']['company_name'] ?? '';
    $industry = $e['linkedin']['industry'] ?? '';
    $insights = $e['linkedin_insights'] ?? [];

    if (empty($liName)) continue;

    // Skip non-product companies (Miva is a platform, Kamehameha is a school, etc.)
    $skipIndustries = ['Software Development', 'Education', 'Non-profit', 'Financial Services',
        'Executive Search', 'Staffing', 'Marketing Services', 'Advertising'];
    if (!empty($industry) && in_array($industry, $skipIndustries)) continue;

    $specialties = $insights['specialties'] ?? '';
    $summary = $e['linkedin']['summary'] ?? '';
    $allText = strtolower($liName . ' ' . $summary . ' ' . $specialties);

    // Build a specific query using the company's ACTUAL product category
    // "Maverick Chocolate" -> "artisan chocolate brand"
    // "Di Stefano Cheese" -> "artisan cheese company"
    // "Hot Sauce Nashville" -> "hot sauce brand"
    $productMatches = [
        'chocolate' => 'artisan chocolate company ecommerce',
        'cheese' => 'artisan cheese company DTC',
        'jam|jelly|preserves' => 'small batch jam preserves company',
        'sauce|hot sauce|salsa' => 'hot sauce brand ecommerce',
        'coffee|roast' => 'craft coffee roasters ecommerce',
        'tea' => 'specialty tea brand ecommerce',
        'bakery|bread|pastry' => 'artisan bakery ecommerce',
        'pet food|pet treat|dog food|cat food' => 'pet food brand DTC',
        'pet|grooming' => 'pet supplies brand ecommerce',
        'skincare|skin care|beauty' => 'DTC skincare beauty brand',
        'candle|fragrance|scent' => 'artisan candle fragrance brand',
        'soap|bath' => 'artisan soap bath products brand',
        'jewelry|jewel' => 'handmade jewelry brand ecommerce',
        'apparel|clothing|wear|fashion' => 'DTC apparel clothing brand',
        'furniture|home decor|home goods' => 'DTC home goods furniture brand',
        'supplement|vitamin|nutrition' => 'supplement brand DTC ecommerce',
        'disc golf|golf disc' => 'disc golf brand',
        'glassware|glass' => 'artisan glassware brand',
        'pickle|ferment' => 'artisan pickles fermented foods brand',
        'insole|shoe' => 'footwear insole brand ecommerce',
        'outdoor|camping|hiking' => 'outdoor gear brand ecommerce',
        'wine|spirits|liquor' => 'wine spirits brand DTC',
    ];

    $query = null;
    foreach ($productMatches as $pattern => $searchTemplate) {
        if (preg_match('/(' . $pattern . ')/i', $allText)) {
            $query = $searchTemplate;
            break;
        }
    }

    // Fallback: use the company name itself as a seed for "similar to"
    if (!$query && !empty($liName)) {
        // Skip generic companies where we can't determine a product
        if (in_array($industry, ['Retail', ''])) continue;
        $query = $liName . ' similar';
    }

    if ($query && !isset($searchQueries[$query])) {
        $searchQueries[$query] = [
            'query' => $query,
            'source_id' => $c->id,
            'source_name' => $liName,
            'industry' => $industry,
        ];
    }
}

// Dedupe and limit queries
$queries = array_values($searchQueries);
$queries = array_slice($queries, 0, $limit);

echo "Generated " . count($queries) . " search queries from enriched contacts\n\n";

$stats = ['queries' => 0, 'results_found' => 0, 'new_contacts' => 0, 'duplicates_skipped' => 0];

foreach ($queries as $q) {
    $stats['queries']++;
    echo "[{$stats['queries']}/" . count($queries) . "] \"{$q['query']}\" (from {$q['source_name']})\n";

    $result = $svc->searchCompanies($q['query'], [
        'country' => 'US',
        'productOnly' => true,
        'count' => $resultsPerSearch,
    ]);

    if (isset($result['error'])) {
        echo "  ERROR: {$result['error']}\n";
        sleep($delay);
        continue;
    }

    $companies = $result['results'] ?? [];
    echo "  Found " . count($companies) . " results (total: " . ($result['total'] ?? 0) . ")\n";
    $stats['results_found'] += count($companies);

    foreach ($companies as $company) {
        $liUrl = strtolower(rtrim($company['linkedinUrl'] ?? '', '/'));
        $name = $company['name'] ?? '';

        if (empty($name) || empty($liUrl)) continue;

        // Skip if we already have this company
        if (isset($existingUrls[$liUrl])) {
            if ($verbose) echo "    SKIP (exists): {$name}\n";
            $stats['duplicates_skipped']++;
            continue;
        }
        $nameKey = strtolower($name);
        if (isset($existingNames[$nameKey])) {
            if ($verbose) echo "    SKIP (name): {$name}\n";
            $stats['duplicates_skipped']++;
            continue;
        }

        echo "    NEW: {$name} ({$company['industry']}, {$company['location']}) [{$company['followers']}]\n";

        if ($dryRun) {
            $existingUrls[$liUrl] = -1;
            $existingNames[$nameKey] = -1;
            $stats['new_contacts']++;
            continue;
        }

        // Create new CRM contact
        $newContact = Bean::dispense('crmcontact');
        $newContact->companyName = $name;
        $newContact->linkedinUrl = $company['linkedinUrl'];
        $newContact->statusCategory = 'prospect';
        $newContact->pipelineStage = 'cold';
        $newContact->enrichmentStatus = 'pending';
        $newContact->tags = $tag;
        $newContact->notes = "Discovered via LinkedIn industry search: \"{$q['query']}\" (seed: {$q['source_name']} #{$q['source_id']})";

        // Pre-fill enrichment data from search results
        $enrichment = [
            'linkedin' => [
                'company_name' => $name,
                'industry' => $company['industry'] ?? '',
                'location' => $company['location'] ?? '',
                'followers' => $company['followers'] ?? '',
                'summary' => $company['summary'] ?? '',
                'linkedin_url' => $company['linkedinUrl'] ?? '',
                'enriched_at' => date('Y-m-d H:i:s'),
                'source' => 'discovery',
            ],
        ];
        $newContact->enrichmentJson = json_encode($enrichment);
        $newContact->leadScore = 10; // Base score for discovered leads
        Bean::store($newContact);

        $existingUrls[$liUrl] = (int) $newContact->id;
        $existingNames[$nameKey] = (int) $newContact->id;
        $stats['new_contacts']++;
    }

    sleep($delay);
}

echo "\n=== Results ===\n";
echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
