<?php
/**
 * Shopify Crawler Service
 *
 * Crawls Shopify stores using their Bot Authentication headers.
 * Builds a RAG knowledge base for the MyCTOBot Assistant.
 *
 * Shopify Bot Auth:
 * - Signature: HMAC signature for request validation
 * - Signature-Input: Signed parameters and key info
 * - Signature-Agent: Always "https://shopify.com"
 *
 * These headers are configured in Shopify Admin > Settings > Customer privacy
 * under "Authorize external tools to crawl your store"
 */

namespace app\services;

use \app\Bean;
use \app\services\ShopifyClient;
use \GuzzleHttp\Client;
use \Exception;

class ShopifyCrawlerService
{
    private Client $httpClient;
    private object $connection;
    private ?object $crawlStatus = null;
    private string $workspace;
    private $logger;

    // Page types we care about
    const PAGE_TYPES = [
        'product',
        'collection',
        'page',
        'blog',
        'article',
        'homepage',
        'other'
    ];

    /**
     * Create crawler instance for a Shopify connection
     *
     * @param int $connectionId connections.id (unified table)
     * @param string $workspace Workspace slug for knowledge store
     */
    public function __construct(int $connectionId, string $workspace)
    {
        $this->workspace = $workspace;
        $this->connection = Bean::load('connections', $connectionId);

        if (!$this->connection->id) {
            throw new Exception("Connection not found: {$connectionId}");
        }

        if ($this->connection->connector_type !== 'shopify') {
            throw new Exception("Connection {$connectionId} is not a Shopify connection");
        }

        $this->httpClient = new Client([
            'timeout' => 30,
            'http_errors' => false,
            'allow_redirects' => true
        ]);

        $this->logger = \Flight::get('log');

        // Load or create crawl status
        // Note: shopifyconnections_id column name unchanged (just references connections.id now)
        $this->crawlStatus = Bean::findOne('shopifycrawlstatus',
            ' shopifyconnections_id = ? ',
            [$connectionId]
        );

        if (!$this->crawlStatus) {
            $this->crawlStatus = Bean::dispense('shopifycrawlstatus');
            $this->crawlStatus->shopifyconnections_id = $connectionId;
            $this->crawlStatus->status = 'idle';
            $this->crawlStatus->urls_discovered = 0;
            $this->crawlStatus->urls_crawled = 0;
            $this->crawlStatus->urls_errored = 0;
            $this->crawlStatus->created_at = date('Y-m-d H:i:s');
            Bean::store($this->crawlStatus);
        }
    }

    /**
     * Check if crawler auth is configured
     */
    public function hasAuthConfig(): bool
    {
        $meta = json_decode($this->connection->metadata_json ?: '{}', true);
        return !empty($meta['crawler_signature'])
            && !empty($meta['crawler_signature_input']);
    }

    /**
     * Get the store's public domain (not myshopify.com)
     */
    public function getStoreDomain(): string
    {
        $meta = json_decode($this->connection->metadata_json ?: '{}', true);

        // Try to get primary domain from metadata
        if (!empty($meta['primary_domain'])) {
            return $meta['primary_domain'];
        }

        // Fall back to myshopify domain (stored in external_eid)
        return $this->connection->external_eid;
    }

    /**
     * Build headers for crawler requests
     */
    private function getCrawlerHeaders(): array
    {
        $headers = [
            'User-Agent' => 'MyCTOBot-Crawler/1.0 (+https://myctobot.ai)',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
        ];

        $meta = json_decode($this->connection->metadata_json ?: '{}', true);

        // Add Shopify bot auth if configured
        if ($this->hasAuthConfig()) {
            $signature = EncryptionService::decrypt($meta['crawler_signature']);
            $signatureInput = EncryptionService::decrypt($meta['crawler_signature_input']);

            $headers['Signature'] = $signature;
            $headers['Signature-Input'] = $signatureInput;
            $headers['Signature-Agent'] = $meta['crawler_signature_agent'] ?? '"https://shopify.com"';
        }

        // Add storefront password if configured (for password-protected stores)
        if (!empty($meta['storefront_password'])) {
            $password = EncryptionService::decrypt($meta['storefront_password']);
            $headers['Authorization'] = 'Basic ' . base64_encode(':' . $password);
        }

        return $headers;
    }

    /**
     * Discover URLs from sitemap or JSON endpoints
     *
     * Tries sitemap.xml first, falls back to /products.json and /collections.json
     * for password-protected stores where sitemap is not accessible.
     *
     * @return array Discovery result with urls found
     */
    public function discoverUrls(): array
    {
        $domain = $this->getStoreDomain();
        $this->updateStatus('discovering', "Discovering URLs from {$domain}");

        $urls = [];
        $method = 'sitemap';

        // Try sitemap first
        try {
            $sitemapUrl = "https://{$domain}/sitemap.xml";
            $this->log('info', "Trying sitemap: {$sitemapUrl}");

            $response = $this->httpClient->get($sitemapUrl, [
                'headers' => $this->getCrawlerHeaders()
            ]);

            if ($response->getStatusCode() === 200) {
                $body = $response->getBody()->getContents();
                $urls = $this->parseSitemap($body, $domain);
                $this->log('info', "Sitemap parsed, found " . count($urls) . " URLs");
            } else {
                throw new Exception("Sitemap returned HTTP {$response->getStatusCode()}");
            }
        } catch (Exception $e) {
            $this->log('info', "Sitemap unavailable ({$e->getMessage()}), trying JSON endpoints");
        }

        // Fall back to JSON endpoints if sitemap failed or returned no URLs
        if (empty($urls)) {
            $method = 'json_api';
            $urls = $this->discoverFromJsonEndpoints($domain);
        }

        if (empty($urls)) {
            $this->updateStatus('error', "No URLs discovered from sitemap or JSON endpoints");
            return [
                'success' => false,
                'error' => "Could not discover URLs. Sitemap not accessible and JSON endpoints returned no data.",
                'status' => $this->getStatus()
            ];
        }

        // Store discovered URLs
        $newCount = 0;
        foreach ($urls as $url) {
            if ($this->storeDiscoveredUrl($url)) {
                $newCount++;
            }
        }

        $this->crawlStatus->urls_discovered = $this->countDiscoveredUrls();
        $this->crawlStatus->last_discovery_at = date('Y-m-d H:i:s');
        Bean::store($this->crawlStatus);

        $this->updateStatus('idle', "Discovered {$newCount} new URLs via {$method}");

        return [
            'success' => true,
            'total_urls' => count($urls),
            'new_urls' => $newCount,
            'method' => $method,
            'status' => $this->getStatus()
        ];
    }

    /**
     * Discover URLs from Shopify JSON endpoints or Admin API
     *
     * Tries public JSON endpoints first, falls back to Admin API GraphQL
     * for password-protected stores.
     */
    private function discoverFromJsonEndpoints(string $domain): array
    {
        $urls = [];
        $baseUrl = "https://{$domain}";

        // Always add homepage
        $urls[] = [
            'url' => $baseUrl,
            'page_type' => 'homepage'
        ];

        // Try public JSON endpoints first
        $productsFound = $this->discoverFromPublicJson($baseUrl, $urls);

        // If no products found via public JSON, try Admin API
        if (!$productsFound) {
            $this->log('info', "Public JSON returned no data, trying Admin API");
            $this->discoverFromAdminApi($baseUrl, $urls);
        }

        return $urls;
    }

    /**
     * Try to discover URLs from public JSON endpoints
     */
    private function discoverFromPublicJson(string $baseUrl, array &$urls): bool
    {
        $found = false;

        // Fetch products
        try {
            $productsUrl = "{$baseUrl}/products.json";
            $this->log('info', "Fetching products from {$productsUrl}");

            $response = $this->httpClient->get($productsUrl, [
                'headers' => $this->getCrawlerHeaders()
            ]);

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                $products = $data['products'] ?? [];

                $this->log('info', "Found " . count($products) . " products");

                foreach ($products as $product) {
                    $handle = $product['handle'] ?? null;
                    if ($handle) {
                        $urls[] = [
                            'url' => "{$baseUrl}/products/{$handle}",
                            'page_type' => 'product'
                        ];
                        $found = true;
                    }
                }
            }
        } catch (Exception $e) {
            $this->log('warning', "Failed to fetch products.json: {$e->getMessage()}");
        }

        // Fetch collections
        try {
            $collectionsUrl = "{$baseUrl}/collections.json";
            $this->log('info', "Fetching collections from {$collectionsUrl}");

            $response = $this->httpClient->get($collectionsUrl, [
                'headers' => $this->getCrawlerHeaders()
            ]);

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                $collections = $data['collections'] ?? [];

                $this->log('info', "Found " . count($collections) . " collections");

                foreach ($collections as $collection) {
                    $handle = $collection['handle'] ?? null;
                    if ($handle) {
                        $urls[] = [
                            'url' => "{$baseUrl}/collections/{$handle}",
                            'page_type' => 'collection'
                        ];
                        $found = true;
                    }
                }
            }
        } catch (Exception $e) {
            $this->log('warning', "Failed to fetch collections.json: {$e->getMessage()}");
        }

        // Fetch pages (some stores expose this)
        try {
            $pagesUrl = "{$baseUrl}/pages.json";
            $response = $this->httpClient->get($pagesUrl, [
                'headers' => $this->getCrawlerHeaders()
            ]);

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                $pages = $data['pages'] ?? [];

                foreach ($pages as $page) {
                    $handle = $page['handle'] ?? null;
                    if ($handle) {
                        $urls[] = [
                            'url' => "{$baseUrl}/pages/{$handle}",
                            'page_type' => 'page'
                        ];
                        $found = true;
                    }
                }
            }
        } catch (Exception $e) {
            // pages.json not always available, ignore
        }

        return $found;
    }

    /**
     * Discover URLs using Shopify Admin API (GraphQL)
     */
    private function discoverFromAdminApi(string $baseUrl, array &$urls): void
    {
        try {
            $client = new ShopifyClient($this->connection->id);

            if (!$client->isConnected()) {
                $this->log('warning', "Cannot use Admin API - not connected");
                return;
            }

            // Fetch products via GraphQL
            $productsQuery = <<<'GRAPHQL'
            query getProducts($first: Int!) {
                products(first: $first) {
                    edges {
                        node {
                            handle
                            title
                            status
                        }
                    }
                }
            }
            GRAPHQL;

            $result = $client->graphql($productsQuery, ['first' => 250]);
            if ($result['success'] && !empty($result['data']['products']['edges'])) {
                $products = $result['data']['products']['edges'];
                $this->log('info', "Admin API found " . count($products) . " products");

                foreach ($products as $edge) {
                    $product = $edge['node'];
                    // Only include active products
                    if (($product['status'] ?? 'ACTIVE') === 'ACTIVE' && !empty($product['handle'])) {
                        $urls[] = [
                            'url' => "{$baseUrl}/products/{$product['handle']}",
                            'page_type' => 'product'
                        ];
                    }
                }
            }

            // Fetch collections via GraphQL
            $collectionsQuery = <<<'GRAPHQL'
            query getCollections($first: Int!) {
                collections(first: $first) {
                    edges {
                        node {
                            handle
                            title
                        }
                    }
                }
            }
            GRAPHQL;

            $result = $client->graphql($collectionsQuery, ['first' => 250]);
            if ($result['success'] && !empty($result['data']['collections']['edges'])) {
                $collections = $result['data']['collections']['edges'];
                $this->log('info', "Admin API found " . count($collections) . " collections");

                foreach ($collections as $edge) {
                    $collection = $edge['node'];
                    if (!empty($collection['handle'])) {
                        $urls[] = [
                            'url' => "{$baseUrl}/collections/{$collection['handle']}",
                            'page_type' => 'collection'
                        ];
                    }
                }
            }

            // Fetch pages via GraphQL
            $pagesQuery = <<<'GRAPHQL'
            query getPages($first: Int!) {
                pages(first: $first) {
                    edges {
                        node {
                            handle
                            title
                        }
                    }
                }
            }
            GRAPHQL;

            $result = $client->graphql($pagesQuery, ['first' => 100]);
            if ($result['success'] && !empty($result['data']['pages']['edges'])) {
                $pages = $result['data']['pages']['edges'];
                $this->log('info', "Admin API found " . count($pages) . " pages");

                foreach ($pages as $edge) {
                    $page = $edge['node'];
                    if (!empty($page['handle'])) {
                        $urls[] = [
                            'url' => "{$baseUrl}/pages/{$page['handle']}",
                            'page_type' => 'page'
                        ];
                    }
                }
            }

        } catch (Exception $e) {
            $this->log('warning', "Admin API discovery failed: {$e->getMessage()}");
        }
    }

    /**
     * Parse sitemap XML (handles sitemap index and regular sitemaps)
     */
    private function parseSitemap(string $xml, string $domain): array
    {
        $urls = [];

        // Suppress XML errors
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);

        if ($doc === false) {
            throw new Exception("Failed to parse sitemap XML");
        }

        // Register namespace
        $doc->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        // Check if this is a sitemap index
        $sitemapRefs = $doc->xpath('//sm:sitemap/sm:loc');
        if (!empty($sitemapRefs)) {
            // This is a sitemap index - fetch each child sitemap
            foreach ($sitemapRefs as $ref) {
                $childUrl = (string)$ref;
                $this->log('info', "Fetching child sitemap: {$childUrl}");

                try {
                    $response = $this->httpClient->get($childUrl, [
                        'headers' => $this->getCrawlerHeaders()
                    ]);

                    if ($response->getStatusCode() === 200) {
                        $childUrls = $this->parseSitemap($response->getBody()->getContents(), $domain);
                        $urls = array_merge($urls, $childUrls);
                    }
                } catch (Exception $e) {
                    $this->log('warning', "Failed to fetch child sitemap: {$e->getMessage()}");
                }
            }
        } else {
            // Regular sitemap - extract URLs
            $urlNodes = $doc->xpath('//sm:url/sm:loc');
            foreach ($urlNodes as $urlNode) {
                $url = (string)$urlNode;
                $pageType = $this->detectPageType($url);
                $urls[] = [
                    'url' => $url,
                    'page_type' => $pageType
                ];
            }
        }

        return $urls;
    }

    /**
     * Detect page type from URL pattern
     */
    private function detectPageType(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        if ($path === '/' || $path === '') {
            return 'homepage';
        }
        if (preg_match('#^/products/#', $path)) {
            return 'product';
        }
        if (preg_match('#^/collections/#', $path)) {
            return 'collection';
        }
        if (preg_match('#^/pages/#', $path)) {
            return 'page';
        }
        if (preg_match('#^/blogs/.+/.+#', $path)) {
            return 'article';
        }
        if (preg_match('#^/blogs/#', $path)) {
            return 'blog';
        }

        return 'other';
    }

    /**
     * Store a discovered URL
     */
    private function storeDiscoveredUrl(array $urlData): bool
    {
        // Check if URL already exists
        // Note: shopifyconnections_id column name unchanged (just references connections.id now)
        $existing = Bean::findOne('shopifycrawlpages',
            ' shopifyconnections_id = ? AND url = ? ',
            [$this->connection->id, $urlData['url']]
        );

        if ($existing) {
            return false;
        }

        $page = Bean::dispense('shopifycrawlpages');
        $page->shopifyconnections_id = $this->connection->id;
        $page->url = $urlData['url'];
        $page->page_type = $urlData['page_type'];
        $page->status = 'pending';
        $page->created_at = date('Y-m-d H:i:s');
        Bean::store($page);

        return true;
    }

    /**
     * Count discovered URLs
     */
    private function countDiscoveredUrls(): int
    {
        // Note: shopifyconnections_id column name unchanged (just references connections.id now)
        return Bean::count('shopifycrawlpages',
            ' shopifyconnections_id = ? ',
            [$this->connection->id]
        );
    }

    /**
     * Crawl pending pages
     *
     * @param int $limit Max pages to crawl in this batch
     * @param array $pageTypes Optional filter by page types
     * @return array Crawl result
     */
    public function crawlPages(int $limit = 10, array $pageTypes = []): array
    {
        $this->updateStatus('crawling', "Crawling up to {$limit} pages");

        // Get pending pages
        // Note: shopifyconnections_id column name unchanged (just references connections.id now)
        $sql = ' shopifyconnections_id = ? AND status = ? ';
        $params = [$this->connection->id, 'pending'];

        if (!empty($pageTypes)) {
            $placeholders = implode(',', array_fill(0, count($pageTypes), '?'));
            $sql .= " AND page_type IN ({$placeholders}) ";
            $params = array_merge($params, $pageTypes);
        }

        $sql .= ' ORDER BY created_at ASC LIMIT ' . intval($limit);

        $pages = Bean::find('shopifycrawlpages', $sql, $params);

        $crawled = 0;
        $errored = 0;

        foreach ($pages as $page) {
            try {
                $this->crawlPage($page);
                $crawled++;
            } catch (Exception $e) {
                $page->status = 'error';
                $page->error_message = $e->getMessage();
                Bean::store($page);
                $errored++;
                $this->log('warning', "Failed to crawl {$page->url}: {$e->getMessage()}");
            }

            // Small delay to be nice to the server
            usleep(500000); // 0.5 seconds
        }

        // Update status counts
        // Note: shopifyconnections_id column name unchanged (just references connections.id now)
        $this->crawlStatus->urls_crawled = Bean::count('shopifycrawlpages',
            ' shopifyconnections_id = ? AND status = ? ',
            [$this->connection->id, 'crawled']
        );
        $this->crawlStatus->urls_errored = Bean::count('shopifycrawlpages',
            ' shopifyconnections_id = ? AND status = ? ',
            [$this->connection->id, 'error']
        );
        $this->crawlStatus->last_crawl_at = date('Y-m-d H:i:s');
        Bean::store($this->crawlStatus);

        $pending = Bean::count('shopifycrawlpages',
            ' shopifyconnections_id = ? AND status = ? ',
            [$this->connection->id, 'pending']
        );

        $statusMsg = "Crawled {$crawled}, errors {$errored}, pending {$pending}";
        $this->updateStatus($pending > 0 ? 'idle' : 'complete', $statusMsg);

        return [
            'success' => true,
            'crawled' => $crawled,
            'errored' => $errored,
            'pending' => $pending,
            'status' => $this->getStatus()
        ];
    }

    /**
     * Crawl a single page and extract content
     */
    private function crawlPage(object $page): void
    {
        $response = $this->httpClient->get($page->url, [
            'headers' => $this->getCrawlerHeaders()
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new Exception("HTTP {$response->getStatusCode()}");
        }

        $html = $response->getBody()->getContents();

        // Extract content based on page type
        $extracted = $this->extractContent($html, $page->page_type, $page->url);

        $page->title = $extracted['title'];
        $page->content_text = $extracted['content'];
        $page->metadata_json = json_encode($extracted['metadata']);
        $page->status = 'crawled';
        $page->crawled_at = date('Y-m-d H:i:s');
        Bean::store($page);

        // Index in RAG knowledge store
        $this->indexPageInRAG($page, $extracted);
    }

    /**
     * Extract content from HTML based on page type
     */
    private function extractContent(string $html, string $pageType, string $url): array
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML($html, LIBXML_NOERROR);
        $xpath = new \DOMXPath($doc);

        // Get title
        $titleNodes = $xpath->query('//title');
        $title = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : '';

        // Get meta description
        $metaDesc = '';
        $metaNodes = $xpath->query('//meta[@name="description"]/@content');
        if ($metaNodes->length > 0) {
            $metaDesc = $metaNodes->item(0)->nodeValue;
        }

        $content = '';
        $metadata = [
            'url' => $url,
            'page_type' => $pageType,
            'meta_description' => $metaDesc
        ];

        switch ($pageType) {
            case 'product':
                $content = $this->extractProductContent($xpath, $metadata);
                break;
            case 'collection':
                $content = $this->extractCollectionContent($xpath, $metadata);
                break;
            case 'article':
            case 'page':
                $content = $this->extractArticleContent($xpath, $metadata);
                break;
            default:
                $content = $this->extractGenericContent($xpath);
        }

        return [
            'title' => $title,
            'content' => $content,
            'metadata' => $metadata
        ];
    }

    /**
     * Extract product-specific content
     */
    private function extractProductContent(\DOMXPath $xpath, array &$metadata): string
    {
        $content = [];

        // Product title
        $titleNodes = $xpath->query('//*[contains(@class, "product-title") or contains(@class, "product__title")]');
        if ($titleNodes->length > 0) {
            $content[] = "Product: " . trim($titleNodes->item(0)->textContent);
        }

        // Price
        $priceNodes = $xpath->query('//*[contains(@class, "price") or @data-product-price]');
        if ($priceNodes->length > 0) {
            $price = trim($priceNodes->item(0)->textContent);
            $content[] = "Price: {$price}";
            $metadata['price'] = $price;
        }

        // Description
        $descNodes = $xpath->query('//*[contains(@class, "product-description") or contains(@class, "product__description")]');
        if ($descNodes->length > 0) {
            $content[] = "Description: " . trim($descNodes->item(0)->textContent);
        }

        // Try JSON-LD for structured data
        $jsonLd = $this->extractJsonLd($xpath, 'Product');
        if ($jsonLd) {
            $metadata['structured_data'] = $jsonLd;
            if (empty($content)) {
                $content[] = "Product: " . ($jsonLd['name'] ?? '');
                if (!empty($jsonLd['description'])) {
                    $content[] = "Description: " . $jsonLd['description'];
                }
            }
        }

        return implode("\n\n", $content) ?: $this->extractGenericContent($xpath);
    }

    /**
     * Extract collection-specific content
     */
    private function extractCollectionContent(\DOMXPath $xpath, array &$metadata): string
    {
        $content = [];

        // Collection title
        $titleNodes = $xpath->query('//*[contains(@class, "collection-title") or contains(@class, "collection__title")]//h1');
        if ($titleNodes->length === 0) {
            $titleNodes = $xpath->query('//h1');
        }
        if ($titleNodes->length > 0) {
            $content[] = "Collection: " . trim($titleNodes->item(0)->textContent);
        }

        // Collection description
        $descNodes = $xpath->query('//*[contains(@class, "collection-description") or contains(@class, "collection__description")]');
        if ($descNodes->length > 0) {
            $content[] = trim($descNodes->item(0)->textContent);
        }

        // Product count
        $productNodes = $xpath->query('//*[contains(@class, "product-card") or contains(@class, "product-item")]');
        $metadata['product_count'] = $productNodes->length;
        $content[] = "Contains {$productNodes->length} products.";

        return implode("\n\n", $content) ?: $this->extractGenericContent($xpath);
    }

    /**
     * Extract article/page content
     */
    private function extractArticleContent(\DOMXPath $xpath, array &$metadata): string
    {
        $content = [];

        // Main article content
        $articleNodes = $xpath->query('//article | //*[contains(@class, "article-content") or contains(@class, "page-content") or @role="main"]');
        if ($articleNodes->length > 0) {
            $content[] = $this->getTextContent($articleNodes->item(0));
        }

        // Try to get author
        $authorNodes = $xpath->query('//*[contains(@class, "author") or @rel="author"]');
        if ($authorNodes->length > 0) {
            $metadata['author'] = trim($authorNodes->item(0)->textContent);
        }

        return implode("\n\n", $content) ?: $this->extractGenericContent($xpath);
    }

    /**
     * Extract generic content from main content areas
     */
    private function extractGenericContent(\DOMXPath $xpath): string
    {
        // Try common content containers
        $selectors = [
            '//main',
            '//*[@id="main-content"]',
            '//*[@id="content"]',
            '//*[contains(@class, "main-content")]',
            '//article'
        ];

        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes->length > 0) {
                return $this->getTextContent($nodes->item(0));
            }
        }

        // Fallback to body, excluding nav/header/footer
        $bodyNodes = $xpath->query('//body');
        if ($bodyNodes->length > 0) {
            return $this->getTextContent($bodyNodes->item(0), true);
        }

        return '';
    }

    /**
     * Get clean text content from a DOM node
     */
    private function getTextContent(\DOMNode $node, bool $excludeNavFooter = false): string
    {
        // Clone node to avoid modifying original
        $clone = $node->cloneNode(true);

        // Remove unwanted elements
        $unwanted = ['script', 'style', 'noscript', 'svg', 'iframe'];
        if ($excludeNavFooter) {
            $unwanted = array_merge($unwanted, ['nav', 'header', 'footer', 'aside']);
        }

        foreach ($unwanted as $tag) {
            $elements = $clone->getElementsByTagName($tag);
            while ($elements->length > 0) {
                $elements->item(0)->parentNode->removeChild($elements->item(0));
            }
        }

        // Get text and clean up whitespace
        $text = $clone->textContent;
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        // Limit length
        if (strlen($text) > 10000) {
            $text = substr($text, 0, 10000) . '...';
        }

        return $text;
    }

    /**
     * Extract JSON-LD structured data
     */
    private function extractJsonLd(\DOMXPath $xpath, string $type): ?array
    {
        $scriptNodes = $xpath->query('//script[@type="application/ld+json"]');

        foreach ($scriptNodes as $node) {
            $json = json_decode($node->textContent, true);
            if (!$json) continue;

            // Handle @graph arrays
            if (isset($json['@graph'])) {
                foreach ($json['@graph'] as $item) {
                    if (isset($item['@type']) && $item['@type'] === $type) {
                        return $item;
                    }
                }
            }

            // Direct type match
            if (isset($json['@type']) && $json['@type'] === $type) {
                return $json;
            }
        }

        return null;
    }

    /**
     * Index crawled page in RAG knowledge store
     */
    private function indexPageInRAG(object $page, array $extracted): void
    {
        try {
            $store = AssistKnowledgeStore::forWorkspace($this->workspace);

            // Create knowledge entry
            $entryType = $this->mapPageTypeToEntryType($page->page_type);
            $title = $extracted['title'] ?: $page->url;
            $content = $extracted['content'];

            // Add metadata context
            $metadata = $extracted['metadata'];
            $metadata['source'] = 'shopify_crawler';
            $metadata['shop_domain'] = $this->connection->external_eid;
            $metadata['crawled_at'] = $page->crawled_at;

            // Check if entry already exists
            $existingId = $this->findExistingRAGEntry($store, $page->url);

            if ($existingId) {
                $store->updateEntry($existingId, [
                    'title' => $title,
                    'content' => $content,
                    'metadata' => $metadata
                ]);
                $page->knowledge_id = $existingId;
            } else {
                $id = $store->addEntry($entryType, $title, $content, [
                    'page' => $page->url,
                    'metadata' => $metadata
                ]);
                $page->knowledge_id = $id;
            }

            Bean::store($page);

        } catch (Exception $e) {
            $this->log('warning', "Failed to index page in RAG: {$e->getMessage()}");
        }
    }

    /**
     * Find existing RAG entry for a URL
     */
    private function findExistingRAGEntry(AssistKnowledgeStore $store, string $url): ?int
    {
        // Search for existing entry with this URL
        $results = $store->search($url, ['limit' => 1]);
        foreach ($results as $result) {
            $metadata = $result['metadata'] ?? [];
            if (($metadata['source'] ?? '') === 'shopify_crawler' && ($result['page'] ?? '') === $url) {
                return $result['id'];
            }
        }
        return null;
    }

    /**
     * Map page type to RAG entry type
     */
    private function mapPageTypeToEntryType(string $pageType): string
    {
        return match ($pageType) {
            'product' => 'page_definition',
            'collection' => 'page_definition',
            'article' => 'explanation',
            'page' => 'page_definition',
            'blog' => 'page_definition',
            default => 'explanation'
        };
    }

    /**
     * Update crawl status
     */
    private function updateStatus(string $status, string $message): void
    {
        $this->crawlStatus->status = $status;
        $this->crawlStatus->status_message = $message;
        $this->crawlStatus->updated_at = date('Y-m-d H:i:s');
        Bean::store($this->crawlStatus);
    }

    /**
     * Get current crawl status
     */
    public function getStatus(): array
    {
        return [
            'status' => $this->crawlStatus->status,
            'message' => $this->crawlStatus->status_message,
            'urls_discovered' => (int)$this->crawlStatus->urls_discovered,
            'urls_crawled' => (int)$this->crawlStatus->urls_crawled,
            'urls_errored' => (int)$this->crawlStatus->urls_errored,
            'urls_pending' => (int)$this->crawlStatus->urls_discovered - (int)$this->crawlStatus->urls_crawled - (int)$this->crawlStatus->urls_errored,
            'last_discovery_at' => $this->crawlStatus->last_discovery_at,
            'last_crawl_at' => $this->crawlStatus->last_crawl_at
        ];
    }

    /**
     * Get crawled pages with optional filters
     */
    public function getPages(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        // Note: shopifyconnections_id column name unchanged (just references connections.id now)
        $sql = ' shopifyconnections_id = ? ';
        $params = [$this->connection->id];

        if (!empty($filters['status'])) {
            $sql .= ' AND status = ? ';
            $params[] = $filters['status'];
        }

        if (!empty($filters['page_type'])) {
            $sql .= ' AND page_type = ? ';
            $params[] = $filters['page_type'];
        }

        $sql .= ' ORDER BY created_at DESC LIMIT ' . intval($limit) . ' OFFSET ' . intval($offset);

        $pages = Bean::find('shopifycrawlpages', $sql, $params);

        return array_map(function ($page) {
            return [
                'id' => $page->id,
                'url' => $page->url,
                'page_type' => $page->page_type,
                'title' => $page->title,
                'status' => $page->status,
                'crawled_at' => $page->crawled_at,
                'error_message' => $page->error_message
            ];
        }, $pages);
    }

    /**
     * Search crawled content
     */
    public function search(string $query, int $limit = 10): array
    {
        try {
            $store = AssistKnowledgeStore::forWorkspace($this->workspace);
            $results = $store->search($query, ['limit' => $limit]);

            // Filter to only Shopify crawler entries for this shop
            return array_filter($results, function ($result) {
                $metadata = $result['metadata'] ?? [];
                return ($metadata['source'] ?? '') === 'shopify_crawler'
                    && ($metadata['shop_domain'] ?? '') === $this->connection->external_eid;
            });

        } catch (Exception $e) {
            $this->log('error', "Search failed: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Reset crawl (delete all pages and status)
     */
    public function reset(): bool
    {
        // Delete all pages
        // Note: shopifyconnections_id column name unchanged (just references connections.id now)
        $pages = Bean::find('shopifycrawlpages',
            ' shopifyconnections_id = ? ',
            [$this->connection->id]
        );
        foreach ($pages as $page) {
            Bean::trash($page);
        }

        // Reset status
        $this->crawlStatus->status = 'idle';
        $this->crawlStatus->status_message = 'Reset complete';
        $this->crawlStatus->urls_discovered = 0;
        $this->crawlStatus->urls_crawled = 0;
        $this->crawlStatus->urls_errored = 0;
        $this->crawlStatus->last_discovery_at = null;
        $this->crawlStatus->last_crawl_at = null;
        Bean::store($this->crawlStatus);

        return true;
    }

    /**
     * Log message
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $context['shop'] = $this->connection->external_eid;
        $context['connection_id'] = $this->connection->id;

        if ($this->logger) {
            $this->logger->$level("[ShopifyCrawler] {$message}", $context);
        }
    }

    /**
     * Static factory
     */
    public static function forConnection(int $connectionId, string $workspace): self
    {
        return new self($connectionId, $workspace);
    }
}
