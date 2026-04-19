<?php
/**
 * CrmEnrichmentService
 *
 * AI-powered contact enrichment. Web search + page fetch + AI analysis.
 * Callable from MCP tool (crm_enrich_contact) and cron batch.
 *
 * Uses Serper API for web search, StoreDiscoveryService for page fetching,
 * and Ollama/Claude for AI analysis.
 */

namespace app\services;

use app\Bean;

require_once __DIR__ . '/StoreDiscoveryService.php';

class CrmEnrichmentService
{
    private array $config;
    private ?object $logger;
    private ?StoreDiscoveryService $discovery = null;

    public function __construct(array $config, ?object $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    private function getDiscovery(): StoreDiscoveryService
    {
        if (!$this->discovery) {
            $this->discovery = new StoreDiscoveryService($this->config, $this->logger);
        }
        return $this->discovery;
    }

    private function log(string $msg): void
    {
        if ($this->logger) {
            $this->logger->info("[CrmEnrichment] {$msg}");
        }
    }

    /**
     * Enrich a single contact by ID
     */
    public function enrichContact(int $contactId): array
    {
        $contact = Bean::load('crmcontact', $contactId);
        if (!$contact->id) {
            return ['success' => false, 'error' => 'Contact not found'];
        }

        $companyName = $contact->company_name ?? '';
        if (empty($companyName)) {
            return ['success' => false, 'error' => 'No company name to search for'];
        }

        $this->log("Enriching contact #{$contactId}: {$companyName}");

        $contact->enrichmentStatus = 'pending';
        Bean::store($contact);

        try {
            // Step 1: Web search
            $searchResults = $this->findContactInfo($companyName, $contact->website_url ?? '');

            // Step 2: AI analysis
            $enrichment = $this->analyzeWithAI($companyName, $searchResults);

            // Step 3: Score
            $score = $this->scoreContact($enrichment, $contact);

            // Step 4: Write back
            $this->applyEnrichment($contact, $enrichment, $score);

            $this->log("Enriched contact #{$contactId}: score={$score}");

            return [
                'success' => true,
                'contact_id' => $contactId,
                'lead_score' => $score,
                'enrichment' => $enrichment,
            ];
        } catch (\Exception $e) {
            $this->log("Enrichment failed for #{$contactId}: " . $e->getMessage());

            $contact->enrichmentStatus = 'failed';
            $contact->enrichmentJson = json_encode([
                'error' => $e->getMessage(),
                'attempted_at' => date('Y-m-d H:i:s'),
            ]);
            Bean::store($contact);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Batch enrich unenriched cold leads
     */
    public function enrichBatch(int $limit = 20): array
    {
        $contacts = Bean::find('crmcontact',
            "(enrichment_status IS NULL OR enrichment_status = '' OR enrichment_status = 'pending')" .
            " AND status_category = 'prospect'" .
            " AND company_name IS NOT NULL AND company_name != ''" .
            " ORDER BY created_at ASC LIMIT ?",
            [$limit]
        );

        $results = ['processed' => 0, 'enriched' => 0, 'failed' => 0, 'details' => []];

        foreach ($contacts as $contact) {
            $results['processed']++;
            $result = $this->enrichContact((int)$contact->id);
            $results['details'][] = [
                'contact_id' => (int)$contact->id,
                'company' => $contact->company_name,
                'success' => $result['success'],
                'lead_score' => $result['lead_score'] ?? null,
                'error' => $result['error'] ?? null,
            ];

            if ($result['success']) {
                $results['enriched']++;
            } else {
                $results['failed']++;
            }

            // Rate limit between contacts
            $delay = (int)($this->config['prospecting']['fetch_delay'] ?? 2);
            if ($delay > 0) {
                sleep($delay);
            }
        }

        return $results;
    }

    /**
     * Search the web for company contact information
     */
    private function findContactInfo(string $companyName, string $domain = ''): array
    {
        $discovery = $this->getDiscovery();
        $data = [
            'search_results' => [],
            'pages' => [],
        ];

        // If company_name looks like a domain, use it as the domain too
        if (empty($domain) && preg_match('/\.(com|net|org|io|co|ai|us|ca|uk)$/i', $companyName)) {
            $domain = $companyName;
        }

        // Strip TLD from company name for search (Serper blocks quoted domain queries)
        $searchName = preg_replace('/\.(com|net|org|io|co|ai|us|ca|uk)$/i', '', $companyName);
        $searchName = str_replace(['-', '_', '.'], ' ', $searchName);

        // Search for company contact info
        $query = "{$searchName} company contact email";
        $searchResults = $discovery->searchGoogle($query);
        $data['search_results'] = array_slice($searchResults, 0, 5);

        // If we have a domain, fetch key pages
        if (!empty($domain)) {
            // Normalize domain to URL
            $baseUrl = $domain;
            if (!preg_match('#^https?://#', $baseUrl)) {
                $baseUrl = 'https://' . $baseUrl;
            }

            $pagesToFetch = [
                'homepage' => $baseUrl,
                'about' => rtrim($baseUrl, '/') . '/about',
                'contact' => rtrim($baseUrl, '/') . '/contact',
            ];

            foreach ($pagesToFetch as $pageType => $url) {
                $page = $discovery->fetchStorePage($url);
                if (!$page['error'] && $page['httpCode'] === 200 && !empty($page['html'])) {
                    // Extract contact info and truncate HTML for AI
                    $contactInfo = $discovery->extractContactInfo($page['html']);
                    $text = $this->truncateHtml($page['html'], 3000);
                    $data['pages'][$pageType] = [
                        'url' => $url,
                        'text' => $text,
                        'emails' => $contactInfo['emails'],
                        'phones' => $contactInfo['phones'],
                    ];
                }
                usleep(500000); // 0.5s between fetches
            }
        } else {
            // Try to find website from search results
            foreach ($searchResults as $result) {
                $link = $result['link'] ?? '';
                if (!empty($link) && count($data['pages']) < 2) {
                    $page = $discovery->fetchStorePage($link);
                    if (!$page['error'] && $page['httpCode'] === 200 && !empty($page['html'])) {
                        $contactInfo = $discovery->extractContactInfo($page['html']);
                        $text = $this->truncateHtml($page['html'], 2000);
                        $data['pages'][] = [
                            'url' => $link,
                            'text' => $text,
                            'emails' => $contactInfo['emails'],
                            'phones' => $contactInfo['phones'],
                        ];
                    }
                    usleep(500000);
                }
            }
        }

        return $data;
    }

    /**
     * Use AI to analyze collected page data and extract contact details
     */
    private function analyzeWithAI(string $companyName, array $searchData): array
    {
        $prompt = $this->buildEnrichmentPrompt($companyName, $searchData);

        // Try Ollama first
        $aiModel = $this->config['prospecting']['ai_model'] ?? 'kimi-k2.5:cloud';
        $aiFallback = $this->config['prospecting']['ai_fallback'] ?? 'claude';

        try {
            $ch = curl_init('http://127.0.0.1:11434/api/chat');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode([
                    'model' => $aiModel,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'stream' => false,
                    'format' => 'json',
                ]),
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if (!$error && $httpCode === 200) {
                $data = json_decode($response, true);
                $content = $data['message']['content'] ?? '';
                if ($content) {
                    $parsed = $this->parseJsonResponse($content);
                    if ($parsed) {
                        return $parsed;
                    }
                }
            } else {
                $this->log("Ollama error: HTTP {$httpCode}, {$error}");
            }
        } catch (\Exception $e) {
            $this->log("Ollama failed: " . $e->getMessage());
        }

        // Fallback to Claude
        if ($aiFallback === 'claude') {
            return $this->analyzeWithClaude($prompt);
        }

        return ['error' => 'All AI providers failed'];
    }

    private function analyzeWithClaude(string $prompt): array
    {
        $mainConfig = parse_ini_file(__DIR__ . '/../conf/config.ini', true);
        $apiKey = $mainConfig['anthropic']['api_key'] ?? '';
        $model = $mainConfig['anthropic']['model'] ?? 'claude-sonnet-4-20250514';

        if (empty($apiKey)) {
            throw new \RuntimeException('No Anthropic API key configured');
        }

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $model,
                'max_tokens' => 1024,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException("Claude API returned HTTP {$httpCode}");
        }

        $data = json_decode($response, true);
        $content = $data['content'][0]['text'] ?? '';

        $parsed = $this->parseJsonResponse($content);
        if ($parsed) {
            return $parsed;
        }

        throw new \RuntimeException('Failed to parse Claude response');
    }

    /**
     * Parse JSON from AI response, handling markdown code blocks and raw JSON
     */
    private function parseJsonResponse(string $content): ?array
    {
        // Try direct parse first
        $parsed = json_decode($content, true);
        if ($parsed && is_array($parsed)) {
            return $parsed;
        }

        // Strip markdown code blocks (```json ... ``` or ``` ... ```)
        $stripped = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $stripped = preg_replace('/\s*```\s*$/', '', $stripped);
        $parsed = json_decode($stripped, true);
        if ($parsed && is_array($parsed)) {
            return $parsed;
        }

        // Extract first JSON object from mixed content
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $parsed = json_decode($matches[0], true);
            if ($parsed && is_array($parsed)) {
                return $parsed;
            }
        }

        return null;
    }

    private function buildEnrichmentPrompt(string $companyName, array $searchData): string
    {
        $prompt = "Analyze the following web data about \"{$companyName}\" and extract contact information.\n\n";

        // Add search snippets
        if (!empty($searchData['search_results'])) {
            $prompt .= "**Search Results:**\n";
            foreach ($searchData['search_results'] as $i => $r) {
                $prompt .= ($i + 1) . ". {$r['title']}\n   {$r['link']}\n   {$r['snippet']}\n";
            }
            $prompt .= "\n";
        }

        // Add page content
        if (!empty($searchData['pages'])) {
            $prompt .= "**Page Content:**\n";
            foreach ($searchData['pages'] as $type => $page) {
                $prompt .= "--- {$type} ({$page['url']}) ---\n";
                if (!empty($page['emails'])) {
                    $prompt .= "Emails found: " . implode(', ', $page['emails']) . "\n";
                }
                if (!empty($page['phones'])) {
                    $prompt .= "Phones found: " . implode(', ', $page['phones']) . "\n";
                }
                $prompt .= $page['text'] . "\n\n";
            }
        }

        $prompt .= <<<'PROMPT'
**Extract and return ONLY valid JSON with these fields:**
{
    "decision_maker_name": "Name of key decision-maker (CEO, owner, VP logistics) or null",
    "decision_maker_title": "Their role/title or null",
    "email": "Best contact email found or null",
    "phone": "Best phone number found or null",
    "website_url": "Company website URL or null",
    "linkedin_url": "LinkedIn profile URL or null",
    "company_size": "Estimated company size (e.g., '10-50 employees') or null",
    "industry": "Primary industry or null",
    "shipping_signals": "Any mentions of shipping, fulfillment, warehousing, 3PL, logistics (describe briefly) or null",
    "confidence": 0.0-1.0,
    "summary": "Brief 1-2 sentence summary of what was found"
}

Rules:
- Only include information you found in the provided data. Do not fabricate.
- For email, prefer business emails over generic (info@, support@).
- For decision-maker, look for founder, CEO, owner, VP of operations/logistics.
- confidence should reflect how much useful data was found (0.1 = almost nothing, 0.9 = very complete).
PROMPT;

        return $prompt;
    }

    /**
     * Lead score rubric (out of 100):
     *
     *   Has email .............. +20
     *   Has phone .............. +10
     *   Has decision-maker ..... +20
     *   Company size known ..... +10
     *   Self-shipping signals .. +15
     *   AI confidence (0-1) .... +25 (scaled: confidence × 25)
     *                            ───
     *   Maximum ............... 100
     *
     * @see self::SCORE_RUBRIC for machine-readable version
     */
    public const SCORE_RUBRIC = [
        ['signal' => 'has_email',          'points' => 20, 'label' => 'Has contact email'],
        ['signal' => 'has_phone',          'points' => 10, 'label' => 'Has phone number'],
        ['signal' => 'has_decision_maker', 'points' => 20, 'label' => 'Has decision-maker name'],
        ['signal' => 'has_company_size',   'points' => 10, 'label' => 'Company size known'],
        ['signal' => 'shipping_signals',   'points' => 15, 'label' => 'Self-shipping/fulfillment signals found'],
        ['signal' => 'ai_confidence',      'points' => 25, 'label' => 'AI enrichment confidence (scaled 0-25)'],
    ];

    private function scoreContact(array $enrichment, object $contact): int
    {
        $score = 0;

        // Has email (+20)
        $email = $enrichment['email'] ?? $contact->company_email ?? '';
        if (!empty($email)) $score += 20;

        // Has phone (+10)
        $phone = $enrichment['phone'] ?? $contact->phone ?? '';
        if (!empty($phone)) $score += 10;

        // Has decision-maker (+20)
        if (!empty($enrichment['decision_maker_name'])) $score += 20;

        // Company size known (+10)
        if (!empty($enrichment['company_size'])) $score += 10;

        // Self-shipping signals (+15)
        if (!empty($enrichment['shipping_signals'])) $score += 15;

        // AI confidence (+25 scaled)
        $confidence = (float)($enrichment['confidence'] ?? 0);
        $score += (int)round($confidence * 25);

        return min(100, max(1, $score));
    }

    /**
     * Apply enrichment results to contact bean
     */
    private function applyEnrichment(object $contact, array $enrichment, int $score): void
    {
        // Update fields from enrichment (only if we found new data)
        if (!empty($enrichment['email']) && empty($contact->companyEmail)) {
            $contact->companyEmail = $enrichment['email'];
        }
        if (!empty($enrichment['phone']) && empty($contact->phone)) {
            $contact->phone = $enrichment['phone'];
        }
        if (!empty($enrichment['website_url'])) {
            $contact->websiteUrl = $enrichment['website_url'];
        }
        if (!empty($enrichment['linkedin_url'])) {
            $contact->linkedinUrl = $enrichment['linkedin_url'];
        }
        if (!empty($enrichment['decision_maker_name'])) {
            $contact->decisionMakerName = $enrichment['decision_maker_name'];
        }
        if (!empty($enrichment['decision_maker_title'])) {
            $contact->decisionMakerTitle = $enrichment['decision_maker_title'];
        }
        if (!empty($enrichment['company_size']) && empty($contact->companySize)) {
            $contact->companySize = $enrichment['company_size'];
        }

        $contact->leadScore = $score;
        $contact->enrichmentStatus = 'enriched';
        $contact->enrichmentSource = 'ai';
        $contact->enrichedAt = date('Y-m-d H:i:s');
        $contact->enrichmentJson = json_encode([
            'enrichment' => $enrichment,
            'score' => $score,
            'enriched_at' => date('Y-m-d H:i:s'),
        ]);

        Bean::store($contact);
    }

    /**
     * Strip HTML to plain text and truncate
     */
    private function truncateHtml(string $html, int $maxLength): string
    {
        // Remove scripts and styles
        $text = preg_replace('/<script[^>]*>[\s\S]*?<\/script>/i', '', $html);
        $text = preg_replace('/<style[^>]*>[\s\S]*?<\/style>/i', '', $text);
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if (strlen($text) > $maxLength) {
            $text = substr($text, 0, $maxLength) . '...';
        }

        return $text;
    }
}
