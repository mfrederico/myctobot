<?php
/**
 * StoreDiscoveryService - Core service for cold-lead store discovery.
 *
 * Handles Google Custom Search API queries, platform detection (Shopify/Miva),
 * page fetching, contact extraction, and AI-powered self-shipping analysis.
 */

namespace app\services;

use app\services\LLMProviders\OllamaProvider;

class StoreDiscoveryService
{
    private string $googleApiKey;
    private string $googleCseId;
    private string $serperApiKey;
    private string $searchProvider;
    private string $aiModel;
    private string $aiFallback;
    private float $qualifyThreshold;
    private float $disqualifyThreshold;
    private int $fetchDelay;
    private ?object $logger;

    public function __construct(array $config, ?object $logger = null)
    {
        $google = $config['google'] ?? [];
        $serper = $config['serper'] ?? [];
        $prospecting = $config['prospecting'] ?? [];

        $this->googleApiKey = $google['api_key'] ?? '';
        $this->googleCseId = $google['cse_id'] ?? '';
        $this->serperApiKey = $serper['api_key'] ?? '';
        $this->searchProvider = $prospecting['search_provider'] ?? 'serper';
        $this->aiModel = $prospecting['ai_model'] ?? 'kimi-k2.5:cloud';
        $this->aiFallback = $prospecting['ai_fallback'] ?? 'claude';
        $this->qualifyThreshold = (float)($prospecting['qualify_threshold'] ?? 0.6);
        $this->disqualifyThreshold = (float)($prospecting['disqualify_threshold'] ?? 0.3);
        $this->fetchDelay = (int)($prospecting['fetch_delay'] ?? 2);
        $this->logger = $logger;
    }

    /**
     * Search for store URLs using configured provider (Serper or Google CSE).
     *
     * @return array Array of {link, title, snippet}
     */
    public function searchGoogle(string $query, int $start = 1): array
    {
        if ($this->searchProvider === 'serper') {
            return $this->searchSerper($query, $start);
        }
        return $this->searchGoogleCSE($query, $start);
    }

    /**
     * Search via Serper.dev (Google Search Results API).
     */
    private function searchSerper(string $query, int $start = 1): array
    {
        $page = max(1, (int)ceil($start / 10));

        $ch = curl_init('https://google.serper.dev/search');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'X-API-KEY: ' . $this->serperApiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'q' => $query,
                'num' => 10,
                'page' => $page,
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            $this->log("Serper error: HTTP {$httpCode}, {$error}");
            return [];
        }

        $data = json_decode($response, true);
        $results = [];

        foreach (($data['organic'] ?? []) as $item) {
            $results[] = [
                'link' => $item['link'] ?? '',
                'title' => $item['title'] ?? '',
                'snippet' => $item['snippet'] ?? '',
            ];
        }

        return $results;
    }

    /**
     * Search via Google Custom Search Engine (fallback).
     */
    private function searchGoogleCSE(string $query, int $start = 1): array
    {
        $url = 'https://www.googleapis.com/customsearch/v1?' . http_build_query([
            'key' => $this->googleApiKey,
            'cx' => $this->googleCseId,
            'q' => $query,
            'num' => 10,
            'start' => $start,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            $this->log("Google CSE error: HTTP {$httpCode}, {$error}");
            return [];
        }

        $data = json_decode($response, true);
        if (empty($data['items'])) {
            return [];
        }

        $results = [];
        foreach ($data['items'] as $item) {
            $results[] = [
                'link' => $item['link'] ?? '',
                'title' => $item['title'] ?? '',
                'snippet' => $item['snippet'] ?? '',
            ];
        }

        return $results;
    }

    /**
     * Fetch a store page via cURL with browser-like headers.
     *
     * @return array {html, httpCode, finalUrl, headers, error}
     */
    public function fetchStorePage(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
            ],
            CURLOPT_HEADER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['html' => '', 'httpCode' => $httpCode, 'finalUrl' => $finalUrl, 'headers' => '', 'error' => $error];
        }

        $headers = substr($response, 0, $headerSize);
        $html = substr($response, $headerSize);

        return ['html' => $html, 'httpCode' => $httpCode, 'finalUrl' => $finalUrl, 'headers' => $headers, 'error' => null];
    }

    /**
     * Detect ecommerce platform from HTML content and URL.
     *
     * @return array {platform: string, confidence: float}
     */
    public function detectPlatform(string $html, string $url): array
    {
        $shopifySignals = 0;
        $mivaSignals = 0;

        // Shopify detection
        if (stripos($html, 'cdn.shopify.com') !== false) $shopifySignals += 3;
        if (stripos($html, 'Shopify.theme') !== false) $shopifySignals += 3;
        if (stripos($html, 'shopify-checkout-api-token') !== false) $shopifySignals += 3;
        if (stripos($html, 'myshopify.com') !== false) $shopifySignals += 2;
        if (stripos($html, 'shopify-section') !== false) $shopifySignals += 2;
        if (stripos($html, '/collections/') !== false) $shopifySignals += 1;
        if (stripos($html, 'shopify-features') !== false) $shopifySignals += 2;
        if (stripos($html, 'Shopify.shop') !== false) $shopifySignals += 2;

        // Miva detection
        if (stripos($url, '/mm5/') !== false) $mivaSignals += 3;
        if (stripos($html, 'Miva Merchant') !== false) $mivaSignals += 3;
        if (stripos($html, 'MivaScript') !== false) $mivaSignals += 3;
        if (stripos($html, 'mvt:') !== false) $mivaSignals += 2;
        if (stripos($html, 'miva_') !== false) $mivaSignals += 1;
        if (stripos($html, '/Merchant2/') !== false) $mivaSignals += 2;
        if (stripos($html, 'mivamerchant') !== false) $mivaSignals += 2;

        if ($shopifySignals > $mivaSignals && $shopifySignals >= 2) {
            $confidence = min(1.0, $shopifySignals / 10);
            return ['platform' => 'shopify', 'confidence' => round($confidence, 2)];
        }

        if ($mivaSignals > $shopifySignals && $mivaSignals >= 2) {
            $confidence = min(1.0, $mivaSignals / 8);
            return ['platform' => 'miva', 'confidence' => round($confidence, 2)];
        }

        return ['platform' => 'unknown', 'confidence' => 0.0];
    }

    /**
     * Find shipping/about/contact page URLs from HTML.
     *
     * @return array {shipping: ?string, about: ?string, contact: ?string}
     */
    public function extractSubpageUrls(string $html, string $baseUrl): array
    {
        $pages = ['shipping' => null, 'about' => null, 'contact' => null];
        $baseParsed = parse_url($baseUrl);
        $baseHost = ($baseParsed['scheme'] ?? 'https') . '://' . ($baseParsed['host'] ?? '');

        // Extract all href values
        preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches);
        $links = $matches[1] ?? [];

        $shippingPatterns = ['/shipping', '/shipping-policy', '/pages/shipping', '/policies/shipping'];
        $aboutPatterns = ['/about', '/about-us', '/pages/about', '/our-story'];
        $contactPatterns = ['/contact', '/contact-us', '/pages/contact'];

        foreach ($links as $link) {
            $lowerLink = strtolower($link);

            foreach ($shippingPatterns as $pattern) {
                if (!$pages['shipping'] && strpos($lowerLink, $pattern) !== false) {
                    $pages['shipping'] = $this->resolveUrl($link, $baseHost);
                }
            }
            foreach ($aboutPatterns as $pattern) {
                if (!$pages['about'] && strpos($lowerLink, $pattern) !== false) {
                    $pages['about'] = $this->resolveUrl($link, $baseHost);
                }
            }
            foreach ($contactPatterns as $pattern) {
                if (!$pages['contact'] && strpos($lowerLink, $pattern) !== false) {
                    $pages['contact'] = $this->resolveUrl($link, $baseHost);
                }
            }
        }

        return $pages;
    }

    /**
     * Extract contact info (emails, phones) from HTML.
     */
    public function extractContactInfo(string $html): array
    {
        $text = $this->stripHtmlToText($html);

        // Extract emails (skip common generic ones)
        $skipEmails = ['noreply@', 'no-reply@', 'support@shopify', 'help@shopify'];
        preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text, $emailMatches);
        $emails = [];
        foreach (array_unique($emailMatches[0] ?? []) as $email) {
            $skip = false;
            foreach ($skipEmails as $pattern) {
                if (stripos($email, $pattern) !== false) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $emails[] = $email;
            }
        }

        // Extract US phone numbers
        preg_match_all('/(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $text, $phoneMatches);
        $phones = array_unique($phoneMatches[0] ?? []);

        return [
            'emails' => array_values($emails),
            'phones' => array_values($phones),
        ];
    }

    /**
     * Normalize a URL to its base domain for deduplication.
     * "https://www.example.com/foo/bar" -> "example.com"
     */
    public function normalizeDomain(string $url): string
    {
        // Add protocol if missing
        if (!preg_match('#^https?://#', $url)) {
            $url = 'https://' . $url;
        }

        $parsed = parse_url($url);
        $host = strtolower($parsed['host'] ?? '');

        // Strip www.
        $host = preg_replace('/^www\./', '', $host);

        // Strip myshopify.com subdomain stores to their actual domain if redirected
        // But keep myshopify.com domains as-is since that's their identifier
        return $host;
    }

    /**
     * Run AI analysis on fetched page data to determine self-shipping likelihood.
     *
     * @param array $pageData {url, platform, homepage, shippingPage, aboutPage, contactInfo}
     * @return array Parsed AI response or error
     */
    public function analyzeWithAI(array $pageData): array
    {
        $prompt = $this->buildAnalysisPrompt($pageData);

        // Try Ollama first (direct cURL with 5min timeout for cloud models)
        try {
            $ch = curl_init('http://127.0.0.1:11434/api/chat');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode([
                    'model' => $this->aiModel,
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
                    $parsed = $this->parseAIResponse($content);
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

        // Fallback to Claude API if configured
        if ($this->aiFallback === 'claude') {
            try {
                return $this->analyzeWithClaude($prompt);
            } catch (\Exception $e) {
                $this->log("Claude fallback failed: " . $e->getMessage());
            }
        }

        return ['error' => 'All AI providers failed'];
    }

    /**
     * Determine status based on AI analysis results.
     */
    public function determineStatus(array $analysis): string
    {
        $confidence = (float)($analysis['self_shipping_confidence'] ?? 0);
        $selfShipping = $analysis['self_shipping'] ?? false;

        if ($selfShipping && $confidence >= $this->qualifyThreshold) {
            return 'qualified';
        }

        if (!$selfShipping || $confidence < $this->disqualifyThreshold) {
            return 'disqualified';
        }

        return 'analyzed'; // In between - needs manual review
    }

    /**
     * Strip HTML to readable text for AI analysis.
     */
    public function stripHtmlToText(string $html): string
    {
        // Remove script and style blocks
        $text = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $text = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $text);
        $text = preg_replace('/<noscript[^>]*>.*?<\/noscript>/is', '', $text);

        // Convert block elements to newlines
        $text = preg_replace('/<\/(p|div|h[1-6]|li|tr|br|hr)[^>]*>/i', "\n", $text);

        // Strip remaining tags
        $text = strip_tags($text);

        // Decode entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n+/', "\n\n", $text);

        return trim($text);
    }

    // --- Private helpers ---

    private function buildAnalysisPrompt(array $pageData): string
    {
        $homepage = $this->truncateText($pageData['homepage'] ?? '', 3000);
        $shippingPage = $this->truncateText($pageData['shippingPage'] ?? '', 3000);
        $aboutPage = $this->truncateText($pageData['aboutPage'] ?? '', 2000);

        $contactEmails = implode(', ', $pageData['contactInfo']['emails'] ?? []);
        $contactPhones = implode(', ', $pageData['contactInfo']['phones'] ?? []);

        return <<<PROMPT
You are analyzing an ecommerce store to determine if they ship orders themselves (self-fulfillment) or use a third-party logistics provider (3PL).

STORE URL: {$pageData['url']}
PLATFORM: {$pageData['platform']}
EMAILS FOUND: {$contactEmails}
PHONES FOUND: {$contactPhones}

HOMEPAGE CONTENT:
{$homepage}

SHIPPING PAGE CONTENT:
{$shippingPage}

ABOUT PAGE CONTENT:
{$aboutPage}

Analyze this store and respond in STRICT JSON format (no markdown, no explanation outside the JSON):

{
  "self_shipping": true or false,
  "self_shipping_confidence": 0.0 to 1.0,
  "reasoning": "Brief explanation of why you think they self-ship or use a 3PL",
  "signals": ["list", "of", "evidence", "found"],
  "contact_email": "best email found or null",
  "contact_phone": "phone number or null",
  "contact_name": "owner/manager name if visible or null",
  "estimated_size": "small or medium or large",
  "size_reasoning": "Why this size estimate",
  "wms_selling_points": ["specific pain points this store likely has", "that a WMS like CannonWMS could solve"]
}

SELF-SHIPPING INDICATORS (high confidence):
- "Ships from our warehouse" or similar language
- Specific warehouse address mentioned
- "We pack and ship every order"
- Shipping times that suggest single-origin (not distributed)
- Small team language ("family-owned", "our team of X")
- No mention of fulfillment partners

3PL/NOT SELF-SHIPPING INDICATORS (low confidence):
- "Fulfilled by Amazon" or FBA references
- Mentions ShipBob, ShipMonk, Deliverr, Red Stag, etc.
- Multiple warehouse locations (suggests 3PL network)
- "Ships from multiple locations"
- Very large catalog suggesting enterprise-level fulfillment

SIZE ESTIMATES:
- small: fewer than 100 products, niche store, limited presence
- medium: 100-1000 products, established brand, active social
- large: 1000+ products, multiple categories, well-funded

Respond with ONLY valid JSON. No markdown code fences. No explanation text.
PROMPT;
    }

    private function analyzeWithClaude(string $prompt): array
    {
        // Load anthropic config from main config
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

        $parsed = $this->parseAIResponse($content);
        if (!$parsed) {
            throw new \RuntimeException('Failed to parse Claude response');
        }

        return $parsed;
    }

    private function parseAIResponse(string $content): ?array
    {
        // Strip markdown code fences if present
        $content = preg_replace('/^```(?:json)?\s*/s', '', $content);
        $content = preg_replace('/\s*```$/s', '', $content);
        $content = trim($content);

        $parsed = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try to extract JSON from surrounding text
            if (preg_match('/\{[^{}]*"self_shipping"[^{}]*\}/s', $content, $match)) {
                $parsed = json_decode($match[0], true);
            }
        }

        if (!is_array($parsed) || !isset($parsed['self_shipping'])) {
            $this->log("Failed to parse AI response: " . substr($content, 0, 200));
            return null;
        }

        return $parsed;
    }

    private function resolveUrl(string $link, string $baseHost): string
    {
        if (preg_match('#^https?://#', $link)) {
            return $link;
        }
        if (strpos($link, '//') === 0) {
            return 'https:' . $link;
        }
        if (strpos($link, '/') === 0) {
            return $baseHost . $link;
        }
        return $baseHost . '/' . $link;
    }

    private function truncateText(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }
        return substr($text, 0, $maxLength) . "\n[...truncated]";
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            $this->logger->info("[StoreDiscovery] {$message}");
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] {$message}\n";
        }
    }
}
