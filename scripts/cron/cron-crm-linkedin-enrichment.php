#!/usr/bin/env php
<?php
/**
 * CRM LinkedIn Enrichment Batch Script
 *
 * Enriches CRM contacts with LinkedIn data:
 * 1. Company search + match
 * 2. Company insights (size, industry, founded, HQ)
 * 3. Employee discovery (confirmed employees only)
 * 4. Hiring signals (open roles, growth indicators)
 *
 * Usage:
 *   php scripts/cron-crm-linkedin-enrichment.php --workspace=shipcannon [options]
 *
 * Options:
 *   --workspace=NAME   Required. Workspace to operate on.
 *   --limit=N          Max contacts to process (default: 50)
 *   --delay=N          Seconds between companies (default: 6)
 *   --skip-employees   Skip employee discovery (faster, company data only)
 *   --verbose          Show detailed output
 *   --dry-run          Show what would be done without writing
 */

// Parse CLI args
$opts = getopt('', ['workspace:', 'limit:', 'delay:', 'skip-employees', 'verbose', 'dry-run', 'script']);
$workspace = $opts['workspace'] ?? null;
$limit = (int) ($opts['limit'] ?? 50);
$delay = (int) ($opts['delay'] ?? 6);
$skipEmployees = isset($opts['skip-employees']);
$verbose = isset($opts['verbose']);
$dryRun = isset($opts['dry-run']);

if (!$workspace) {
    echo "Usage: php scripts/cron-crm-linkedin-enrichment.php --workspace=NAME [--limit=50] [--delay=6] [--verbose] [--dry-run]\n";
    exit(1);
}

// Bootstrap
$configFile = __DIR__ . "/../../conf/config.{$workspace}.ini";
if (!file_exists($configFile)) {
    echo "Config not found: {$configFile}\n";
    exit(1);
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/LinkedInBrowserService.php';
require_once __DIR__ . '/../../models/Model_Crmemployee.php';

$app = new \app\Bootstrap($configFile);

use app\Bean;
use app\services\LinkedInBrowserService;

$svc = new LinkedInBrowserService($workspace);

// Check session first
$session = $svc->isSessionValid();
if (empty($session['valid'])) {
    echo "ERROR: LinkedIn session is not valid. Run: ./scripts/linkedin-browser.sh login {$workspace}\n";
    echo json_encode($session, JSON_PRETTY_PRINT) . "\n";
    exit(1);
}
echo "LinkedIn session valid (expires: " . date('Y-m-d', (int) ($session['cookieExpiry'] ?? 0)) . ")\n\n";

// Find contacts needing LinkedIn enrichment
$contacts = Bean::find('crmcontact',
    '(linkedin_url IS NULL OR linkedin_url = ?) AND company_name IS NOT NULL AND company_name != ? ORDER BY lead_score DESC LIMIT ?',
    ['', '', $limit]
);

$total = count($contacts);
echo "Found {$total} contacts needing LinkedIn enrichment\n\n";

if ($total === 0) {
    echo json_encode(['processed' => 0, 'enriched' => 0, 'employees_found' => 0]);
    exit(0);
}

$stats = ['processed' => 0, 'enriched' => 0, 'failed' => 0, 'employees_found' => 0, 'with_hiring' => 0];

foreach ($contacts as $contact) {
    $stats['processed']++;
    $pct = round($stats['processed'] / $total * 100);

    // Clean up domain-style company names for search
    $companyName = $contact->companyName;
    $searchName = preg_replace('/\.(com|net|org|shop|us|la|design|co|io|ai)$/i', '', $companyName);
    $searchName = preg_replace('/^(shop|blog|www)\./', '', $searchName);
    $searchName = preg_replace('/\.myshopify$/', '', $searchName);
    // Replace hyphens/underscores with spaces
    $searchName = str_replace(['-', '_'], ' ', $searchName);
    // Convert camelCase to spaces
    $searchName = preg_replace('/([a-z])([A-Z])/', '$1 $2', $searchName);

    // If still one concatenated word, try to split using common business words
    if (!str_contains($searchName, ' ') && strlen($searchName) > 6) {
        $words = ['shop','store','boutique','brand','market','kitchen','coffee','chocolate',
                  'candle','soap','bath','beauty','glow','skin','pet','pets','food','foods',
                  'tea','sauce','hot','small','batch','jam','craft','home','house','works',
                  'nation','press','heat','queen','king','gold','silver','star','moon','sun',
                  'valley','river','mountain','lake','ocean','island','north','south','east','west',
                  'new','old','big','little','red','blue','green','black','white','wild',
                  'makers','maker','farm','farms','garden','bee','honey','fish','meat',
                  'cheese','bread','bakery','grill','roast','roasters','brew','distill',
                  'swim','knot','rope','filter','disc','golf','pro','plus','art','atelier',
                  'domain','depot','co','llc','inc','usa','lab','labs','studio',
                  'outdoors','outdoor','gear','wear','workwear','insole','insoles',
                  'warrior','veda','yoga','zen','gourmet','artisan','artisanal','organic',
                  'natural','pure','fresh','raw','whole','grain','flour','seed','nut',
                  'mermaid','cove','anchor','harbor','pier','dock',
                  'puzzle','toy','toys','game','doll','dolls'];
        $lower = strtolower($searchName);
        // Greedy match: find longest words first
        usort($words, fn($a, $b) => strlen($b) - strlen($a));
        $remaining = $lower;
        $found = [];
        while (strlen($remaining) > 0) {
            $matched = false;
            foreach ($words as $w) {
                if (str_starts_with($remaining, $w)) {
                    $found[] = $w;
                    $remaining = substr($remaining, strlen($w));
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                // No dictionary match — take the rest as one chunk
                $found[] = $remaining;
                break;
            }
        }
        if (count($found) > 1) {
            $searchName = implode(' ', $found);
        }
    }

    $searchName = trim($searchName);
    echo "[{$pct}%] #{$contact->id} {$companyName} (search: {$searchName})";

    if ($dryRun) {
        echo " [DRY RUN]\n";
        continue;
    }

    // Step 1: Find company on LinkedIn (pass domain for validation)
    $domain = $contact->companyName; // Often a domain like "sbakery.com"
    $companyResult = $svc->enrichCompany($searchName, $domain);
    if (empty($companyResult['found'])) {
        echo " — NO COMPANY";

        // Fallback: no company page, but try people search
        if (!$skipEmployees) {
            sleep(2);
            $peopleResult = $svc->searchEmployees($searchName, ['country' => 'US', 'count' => 10]);
            $confirmedPeople = array_values(array_filter($peopleResult['results'] ?? [], fn($e) => !empty($e['worksHere'])));

            if (!empty($confirmedPeople)) {
                echo " — found " . count($confirmedPeople) . " people\n";

                $existing = json_decode($contact->enrichmentJson ?: '{}', true);
                $existing['linkedin'] = [
                    'company_name' => null,
                    'source' => 'people_only',
                    'enriched_at' => date('Y-m-d H:i:s'),
                    'note' => 'No company page found, employees discovered via people search',
                ];
                $contact->enrichmentJson = json_encode($existing);
                $contact->leadScore = min(100, (int) $contact->leadScore + 10);

                foreach ($confirmedPeople as $emp) {
                    $name = $emp['name'] ?? '';
                    if (empty($name)) continue;
                    $employee = Bean::dispense('crmemployee');
                    $employee->name = $name;
                    $employee->title = $emp['headline'] ?? '';
                    $employee->linkedin_url = $emp['linkedinUrl'] ?? '';
                    $employee->location = $emp['location'] ?? '';
                    $employee->role_type = \Model_Crmemployee::classifyRole($emp['headline'] ?? '');
                    $employee->source = 'linkedin';
                    $contact->ownCrmemployeeList[] = $employee;
                    $stats['employees_found']++;
                    if ($verbose) {
                        $role = \Model_Crmemployee::classifyRole($emp['headline'] ?? '');
                        echo "    [{$role}] {$name} - {$emp['headline']}\n";
                    }
                }

                $touch = Bean::dispense('crmtouch');
                $touch->touchType = 'linkedin';
                $touch->summary = count($confirmedPeople) . " employees found via LinkedIn (no company page)";
                $touch->source = 'batch';
                $touch->touchDate = date('Y-m-d H:i:s');
                $contact->ownCrmtouchList[] = $touch;
                Bean::store($contact);
                $stats['enriched']++;
                sleep($delay);
                continue;
            }
        }

        echo "\n";
        $stats['failed']++;
        sleep(2);
        continue;
    }

    $match = $companyResult['match'];
    $uncertain = !empty($companyResult['uncertain']);
    echo " — {$match['name']}";
    if ($uncertain) echo " [UNCERTAIN]";

    // Step 2: Validate by checking LinkedIn company's website against our domain
    // Get insights first to check website
    $discovery = null;
    $websiteValid = true;
    if (!$skipEmployees && !empty($match['linkedinUrl'])) {
        sleep(3);
        $discovery = $svc->discoverEmployees($match['name'], $match['linkedinUrl']);

        $liWebsite = $discovery['insights']['website'] ?? '';
        if (!empty($liWebsite)) {
            // Extract domain from LinkedIn's website field
            $liDomain = strtolower(parse_url($liWebsite, PHP_URL_HOST) ?: $liWebsite);
            $liDomain = preg_replace('/^www\./', '', $liDomain);

            // Extract domain from our contact's company name
            $ourDomain = strtolower($contact->companyName);
            $ourDomain = preg_replace('/^(www\.|shop\.|blog\.)/', '', $ourDomain);

            // Strip TLDs and common suffixes for fuzzy comparison
            $liCore = preg_replace('/\.(com|net|org|co|io|shop)$/', '', $liDomain);
            $liCore = str_replace(['-', '.'], '', $liCore);
            $liCore = preg_replace('/(llc|inc|corp|co)$/', '', $liCore);
            $ourCore = preg_replace('/\.(com|net|org|co|io|shop)$/', '', $ourDomain);
            $ourCore = str_replace(['-', '.'], '', $ourCore);

            // Check if domains match (fuzzy: strip LLC/TLD, check containment)
            $domainMatch = str_contains($liDomain, $ourDomain) || str_contains($ourDomain, $liDomain)
                || str_contains($liCore, $ourCore) || str_contains($ourCore, $liCore);

            if ($liDomain && $ourDomain && !$domainMatch) {
                echo " [WEBSITE MISMATCH: {$liDomain} ≠ {$ourDomain}]";

                // Fallback: company page is wrong, but search for PEOPLE who work there
                sleep(3);
                $peopleResult = $svc->searchEmployees($searchName, ['country' => 'US', 'count' => 10]);
                $confirmedPeople = array_filter($peopleResult['results'] ?? [], fn($e) => !empty($e['worksHere']));
                $confirmedPeople = array_values($confirmedPeople);

                if (!empty($confirmedPeople)) {
                    echo " — found " . count($confirmedPeople) . " people (no company page)\n";

                    // Store employees without company LinkedIn data
                    $existing = json_decode($contact->enrichmentJson ?: '{}', true);
                    $existing['linkedin'] = [
                        'company_name' => null,
                        'source' => 'people_only',
                        'enriched_at' => date('Y-m-d H:i:s'),
                        'note' => "Company page mismatch ({$match['name']}), but employees found via people search",
                    ];
                    $contact->enrichmentJson = json_encode($existing);
                    $contact->leadScore = min(100, (int) $contact->leadScore + 10);

                    foreach ($confirmedPeople as $emp) {
                        $name = $emp['name'] ?? '';
                        if (empty($name)) continue;
                        $employee = Bean::dispense('crmemployee');
                        $employee->name = $name;
                        $employee->title = $emp['headline'] ?? '';
                        $employee->linkedin_url = $emp['linkedinUrl'] ?? '';
                        $employee->location = $emp['location'] ?? '';
                        $employee->role_type = \Model_Crmemployee::classifyRole($emp['headline'] ?? '');
                        $employee->source = 'linkedin';
                        $contact->ownCrmemployeeList[] = $employee;
                        $stats['employees_found']++;
                        if ($verbose) {
                            $role = \Model_Crmemployee::classifyRole($emp['headline'] ?? '');
                            echo "    [{$role}] {$name} - {$emp['headline']}\n";
                        }
                    }

                    // Log touch
                    $touch = Bean::dispense('crmtouch');
                    $touch->touchType = 'linkedin';
                    $touch->summary = count($confirmedPeople) . " employees found via LinkedIn (no company page match)";
                    $touch->source = 'batch';
                    $touch->touchDate = date('Y-m-d H:i:s');
                    $contact->ownCrmtouchList[] = $touch;
                    Bean::store($contact);
                    $stats['enriched']++;
                } else {
                    echo " SKIPPED\n";
                    $stats['failed']++;
                }

                sleep($delay);
                continue;
            }
            echo " [website: {$liDomain} ✓]";
        }
    }

    // Step 3: Update contact with LinkedIn data
    $contact->linkedinUrl = $match['linkedinUrl'] ?? '';
    $existing = json_decode($contact->enrichmentJson ?: '{}', true);
    $existing['linkedin'] = [
        'company_name' => $match['name'] ?? '',
        'industry' => $match['industry'] ?? '',
        'location' => $match['location'] ?? '',
        'followers' => $match['followers'] ?? '',
        'summary' => $match['summary'] ?? '',
        'linkedin_url' => $match['linkedinUrl'] ?? '',
        'enriched_at' => date('Y-m-d H:i:s'),
    ];
    $contact->leadScore = min(100, (int) $contact->leadScore + 15);

    // Step 4: Process insights + employees + hiring from discovery (already fetched)
    $employeeCount = 0;
    if ($discovery) {

        if (!empty($discovery['insights'])) {
            $existing['linkedin_insights'] = $discovery['insights'];
        }
        if (!empty($discovery['hiring'])) {
            $existing['hiring_signals'] = $discovery['hiring'];
            $jobCount = $discovery['hiring']['job_count'] ?? 0;
            if ($jobCount > 0) {
                $stats['with_hiring']++;
                $contact->leadScore = min(100, (int) $contact->leadScore + min(10, $jobCount * 2));
            }
        }

        // Store confirmed employees
        foreach ($discovery['employees'] ?? [] as $emp) {
            $name = $emp['name'] ?? '';
            if (empty($name)) continue;

            $employee = Bean::dispense('crmemployee');
            $employee->name = $name;
            $employee->title = $emp['headline'] ?? '';
            $employee->linkedin_url = $emp['linkedinUrl'] ?? '';
            $employee->location = $emp['location'] ?? '';
            $employee->role_type = \Model_Crmemployee::classifyRole($emp['headline'] ?? '');
            $employee->source = 'linkedin';
            $contact->ownCrmemployeeList[] = $employee;
            $employeeCount++;
        }
        $stats['employees_found'] += $employeeCount;
    }

    $contact->enrichmentJson = json_encode($existing);
    Bean::store($contact);

    // Log touch
    $touch = Bean::dispense('crmtouch');
    $touch->touchType = 'linkedin';
    $touch->summary = "LinkedIn enrichment: {$match['name']} ({$match['followers']})"
        . ($employeeCount > 0 ? " + {$employeeCount} employees" : '');
    $touch->source = 'batch';
    $touch->touchDate = date('Y-m-d H:i:s');
    $contact->ownCrmtouchList[] = $touch;
    Bean::store($contact);

    $stats['enriched']++;
    echo " ({$match['followers']}, {$employeeCount} employees, score: {$contact->leadScore})\n";

    if ($verbose && $employeeCount > 0) {
        foreach ($discovery['employees'] ?? [] as $emp) {
            $role = \Model_Crmemployee::classifyRole($emp['headline'] ?? '');
            echo "    [{$role}] {$emp['name']}\n";
        }
    }

    sleep($delay);
}

echo "\n=== Results ===\n";
echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
