<?php
/**
 * LinkedInBrowserService - LinkedIn data access via Playwright CDP
 *
 * Uses a persistent Chrome browser session (authenticated via VNC)
 * and the Voyager internal API to search and enrich CRM data.
 *
 * Browser management: scripts/linkedin-browser.sh
 * CDP helper: scripts/linkedin-cdp.js
 *
 * Usage:
 *   $svc = new LinkedInBrowserService('dev', $logger);
 *   $result = $svc->searchCompanies('DTC skincare brand', ['country' => 'US']);
 *   $profile = $svc->getCompanyProfile('https://www.linkedin.com/company/bonobos/');
 */

namespace app\services;

class LinkedInBrowserService
{
    private string $workspace;
    private $logger;
    private string $scriptPath;
    private int $dailyLimit;
    private int $callCount = 0;
    private string $cdpUrl;

    public function __construct(string $workspace, $logger = null, array $options = [])
    {
        $this->workspace = $workspace;
        $this->logger = $logger;
        $this->scriptPath = dirname(__DIR__) . '/scripts/linkedin-cdp.js';
        $this->dailyLimit = $options['daily_limit'] ?? 100;
        $this->cdpUrl = $options['cdp_url'] ?? 'http://127.0.0.1:9222';
    }

    /**
     * Check if the LinkedIn browser session is valid
     */
    public function isSessionValid(): array
    {
        return $this->exec('session_check');
    }

    /**
     * Search for companies on LinkedIn
     *
     * @param string $keywords Search query
     * @param array $options {
     *   country: string (US, UK, CA, AU, DE) default US,
     *   count: int (1-10) default 10,
     *   start: int (pagination offset) default 0,
     *   productOnly: bool (filter to product companies) default true,
     * }
     */
    public function searchCompanies(string $keywords, array $options = []): array
    {
        $params = array_merge([
            'keywords' => $keywords,
            'country' => 'US',
            'count' => 10,
            'start' => 0,
            'productOnly' => true,
        ], $options);

        return $this->exec('search_companies', $params);
    }

    /**
     * Search for people on LinkedIn
     *
     * @param string $keywords Search query (name, title, etc.)
     * @param array $options {
     *   company: string (company name to narrow search),
     *   country: string default US,
     *   count: int default 10,
     *   start: int default 0,
     * }
     */
    public function searchPeople(string $keywords, array $options = []): array
    {
        $params = array_merge([
            'keywords' => $keywords,
            'country' => 'US',
            'count' => 10,
            'start' => 0,
        ], $options);

        return $this->exec('search_people', $params);
    }

    /**
     * Get detailed company profile
     *
     * @param string $linkedinUrl Full LinkedIn company URL
     */
    public function getCompanyProfile(string $linkedinUrl): array
    {
        return $this->exec('get_company_profile', ['url' => $linkedinUrl]);
    }

    /**
     * Get detailed person profile
     *
     * @param string $linkedinUrl Full LinkedIn person URL
     */
    public function getPersonProfile(string $linkedinUrl): array
    {
        return $this->exec('get_person_profile', ['url' => $linkedinUrl]);
    }

    /**
     * Multi-query search: run multiple keyword searches and dedupe results
     *
     * @param array $queries List of keyword strings
     * @param array $options Same as searchCompanies options
     * @param int $delayMs Delay between queries in milliseconds
     */
    public function searchCompaniesMulti(array $queries, array $options = [], int $delayMs = 3000): array
    {
        $allResults = [];
        $seen = [];

        foreach ($queries as $keywords) {
            $result = $this->searchCompanies($keywords, $options);

            if (isset($result['error'])) {
                $this->log('warning', "Search failed for '$keywords': " . $result['error']);
                continue;
            }

            foreach ($result['results'] ?? [] as $company) {
                $key = $company['linkedinUrl'] ?? $company['name'];
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $company['_query'] = $keywords;
                    $allResults[] = $company;
                }
            }

            // Rate limit between queries
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        return [
            'total' => count($allResults),
            'queries' => count($queries),
            'results' => $allResults,
        ];
    }

    /**
     * Enrich a CRM contact with LinkedIn data
     *
     * Searches for the person by name + company, then fetches their profile.
     *
     * @param string $name Person's full name
     * @param string|null $company Company name to narrow search
     * @return array Enrichment data or error
     */
    public function enrichPerson(string $name, ?string $company = null): array
    {
        // Search for the person
        $searchResult = $this->searchPeople($name, [
            'company' => $company,
            'count' => 5,
        ]);

        if (isset($searchResult['error'])) {
            return $searchResult;
        }

        $matches = $searchResult['results'] ?? [];
        if (empty($matches)) {
            return ['found' => false, 'reason' => 'No results for search'];
        }

        // Return the top match with search context
        $best = $matches[0];

        return [
            'found' => true,
            'match' => $best,
            'alternates' => array_slice($matches, 1, 3),
            'searchTotal' => $searchResult['total'] ?? 0,
        ];
    }

    /**
     * Enrich a CRM contact's company with LinkedIn data
     *
     * @param string $companyName Company name
     * @return array Enrichment data or error
     */
    /**
     * Enrich a CRM contact's company with LinkedIn data
     *
     * @param string $companyName Company name
     * @param string|null $websiteDomain Website domain for validation (e.g., "maverickchocolate.com")
     * @return array Enrichment data or error
     */
    public function enrichCompany(string $companyName, ?string $websiteDomain = null): array
    {
        $searchResult = $this->searchCompanies($companyName, [
            'productOnly' => false,
            'count' => 5,
        ]);

        if (isset($searchResult['error'])) {
            return $searchResult;
        }

        $matches = $searchResult['results'] ?? [];
        if (empty($matches)) {
            return ['found' => false, 'reason' => 'No results for search'];
        }

        // If we have a domain, try to find the best match by checking
        // if the LinkedIn company name relates to the domain
        $best = $matches[0];
        if ($websiteDomain) {
            // Strip common TLDs and prefixes for comparison
            $domainClean = preg_replace('/^(www\.|shop\.|blog\.)/', '', strtolower($websiteDomain));
            $domainClean = preg_replace('/\.(com|net|org|shop|us|co|io|ai|design|la)$/', '', $domainClean);
            $domainClean = str_replace(['-', '_', '.'], '', $domainClean);

            // Score each match by how well the name matches the domain
            $scored = [];
            foreach ($matches as $i => $m) {
                $nameClean = strtolower(preg_replace('/[^a-z0-9]/', '', $m['name'] ?? ''));
                $slugClean = strtolower(preg_replace('/[^a-z0-9]/', '', basename(rtrim($m['linkedinUrl'] ?? '', '/'))));

                $score = 0;
                if (str_contains($nameClean, $domainClean)) $score += 10;
                if (str_contains($domainClean, $nameClean) && strlen($nameClean) > 3) $score += 10;
                if (str_contains($slugClean, $domainClean)) $score += 8;
                if (str_contains($domainClean, $slugClean) && strlen($slugClean) > 3) $score += 8;
                // Penalize if name is very different from domain
                if ($score === 0 && similar_text($nameClean, $domainClean) < 3) $score -= 5;

                $scored[] = ['match' => $m, 'score' => $score, 'index' => $i];
            }

            usort($scored, fn($a, $b) => $b['score'] - $a['score']);
            $best = $scored[0]['match'];

            // If the best scored match has a negative or zero score, flag as uncertain
            if ($scored[0]['score'] <= 0) {
                return [
                    'found' => true,
                    'match' => $best,
                    'uncertain' => true,
                    'alternates' => array_map(fn($s) => $s['match'], array_slice($scored, 1, 3)),
                    'searchTotal' => $searchResult['total'] ?? 0,
                ];
            }
        }

        $alternates = array_filter($matches, fn($m) => ($m['linkedinUrl'] ?? '') !== ($best['linkedinUrl'] ?? ''));

        return [
            'found' => true,
            'match' => $best,
            'alternates' => array_values(array_slice($alternates, 0, 3)),
            'searchTotal' => $searchResult['total'] ?? 0,
        ];
    }

    /**
     * Search for employees at a company on LinkedIn
     *
     * @param string $companyName Company name
     * @param array $options {
     *   company_linkedin_url: string (helps narrow results),
     *   country: string default US,
     *   count: int default 10,
     * }
     */
    public function searchEmployees(string $companyName, array $options = []): array
    {
        $params = array_merge([
            'company_name' => $companyName,
            'country' => 'US',
            'count' => 10,
        ], $options);

        return $this->exec('search_employees', $params);
    }

    /**
     * Get job listings for a company (hiring signals)
     *
     * @param string $companyLinkedInUrl Full LinkedIn company URL
     */
    public function getCompanyJobs(string $companyLinkedInUrl): array
    {
        return $this->exec('get_company_jobs', ['company_linkedin_url' => $companyLinkedInUrl]);
    }

    /**
     * Get company insights (headcount, industry, founded, specialties)
     *
     * @param string $companyLinkedInUrl Full LinkedIn company URL
     */
    public function getCompanyInsights(string $companyLinkedInUrl): array
    {
        return $this->exec('get_company_insights', ['company_linkedin_url' => $companyLinkedInUrl]);
    }

    /**
     * Full employee discovery + hiring signals for a company
     *
     * Combines employee search, job listings, and company insights
     * into a single enrichment result.
     *
     * @param string $companyName Company name
     * @param string|null $companyLinkedInUrl LinkedIn URL (if known)
     * @param array $options Additional options
     */
    /**
     * Get related/similar companies from a company's LinkedIn page
     *
     * @param string $companyLinkedInUrl Full LinkedIn company URL
     */
    public function getRelatedCompanies(string $companyLinkedInUrl): array
    {
        return $this->exec('get_related_companies', ['company_linkedin_url' => $companyLinkedInUrl]);
    }

    public function discoverEmployees(string $companyName, ?string $companyLinkedInUrl = null, array $options = []): array
    {
        $result = [
            'company' => $companyName,
            'employees' => [],
            'hiring' => null,
            'insights' => null,
        ];

        // Search for employees — only keep those confirmed at the company
        $employeeResult = $this->searchEmployees($companyName, $options);
        if (!isset($employeeResult['error'])) {
            $all = $employeeResult['results'] ?? [];
            $confirmed = array_values(array_filter($all, fn($e) => !empty($e['worksHere'])));
            $result['employees'] = $confirmed;
            $result['employees_unconfirmed'] = array_values(array_filter($all, fn($e) => empty($e['worksHere'])));
            $result['employee_total'] = $employeeResult['total'] ?? 0;
        }

        // If we have a LinkedIn URL, get jobs and insights
        if ($companyLinkedInUrl) {
            usleep(2000000); // 2s delay

            $jobResult = $this->getCompanyJobs($companyLinkedInUrl);
            if (!isset($jobResult['error'])) {
                $result['hiring'] = [
                    'job_count' => $jobResult['jobCount'] ?? 0,
                    'jobs' => array_slice($jobResult['jobs'] ?? [], 0, 10),
                    'signals' => $jobResult['hiringSignals'] ?? [],
                ];
            }

            usleep(2000000); // 2s delay

            $insightResult = $this->getCompanyInsights($companyLinkedInUrl);
            if (!isset($insightResult['error'])) {
                $result['insights'] = $insightResult['details'] ?? [];
            }
        }

        return $result;
    }

    /**
     * Execute a CDP helper action
     */
    private function exec(string $action, array $params = []): array
    {
        // Rate limit check
        $this->callCount++;
        if ($this->callCount > $this->dailyLimit) {
            return ['error' => "Daily API limit reached ({$this->dailyLimit})"];
        }

        $paramsJson = json_encode($params);
        $cmd = sprintf(
            'LINKEDIN_CDP_URL=%s node %s %s %s 2>&1',
            escapeshellarg($this->cdpUrl),
            escapeshellarg($this->scriptPath),
            escapeshellarg($action),
            escapeshellarg($paramsJson)
        );

        $this->log('debug', "LinkedIn CDP: {$action}", ['params' => $params]);

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        $rawOutput = implode("\n", $output);

        // Try parsing JSON from stdout (last line that's valid JSON)
        $result = null;
        foreach (array_reverse($output) as $line) {
            $decoded = json_decode($line, true);
            if ($decoded !== null) {
                $result = $decoded;
                break;
            }
        }

        if ($result === null) {
            $this->log('error', "LinkedIn CDP failed: no valid JSON", ['output' => $rawOutput]);
            return ['error' => 'CDP helper returned no valid JSON', 'raw' => substr($rawOutput, 0, 500)];
        }

        if (isset($result['error'])) {
            $this->log('warning', "LinkedIn CDP error: " . $result['error']);
        }

        return $result;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger && method_exists($this->logger, $level)) {
            $this->logger->$level("LinkedInBrowserService: {$message}", $context);
        }
    }
}
