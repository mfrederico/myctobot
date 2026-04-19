#!/usr/bin/env php
<?php
/**
 * Crawl Shopify store via Admin API and index into knowledge store.
 * Designed for cron/pipeline execution.
 *
 * Usage: php scripts/crawl-shopify-api.php --workspace=demo --connection=1 [--kb=6]
 */

// Parse CLI args
$opts = getopt('', ['workspace:', 'connection:', 'kb:', 'script', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php scripts/crawl-shopify-api.php --workspace=demo --connection=1 [--kb=6]\n";
    exit(0);
}

$workspace = $opts['workspace'] ?? 'demo';
$connectionId = (int) ($opts['connection'] ?? 1);
$knowledgebaseId = !empty($opts['kb']) ? (int)$opts['kb'] : null;

// Bootstrap
$_SERVER['WORKSPACE'] = $workspace;
require_once __DIR__ . '/../vendor/autoload.php';

use RedBeanPHP\R;
use app\Bean;
use app\services\ShopifyClient;
use app\services\AssistKnowledgeStore;

// Load workspace config
$configFile = __DIR__ . "/../conf/config.{$workspace}.ini";
if (!file_exists($configFile)) {
    fwrite(STDERR, "Config file not found: {$configFile}\n");
    exit(1);
}
$config = parse_ini_file($configFile, true);

// Setup database
$dbConfig = $config['database'] ?? [];
$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
    $dbConfig['host'] ?? 'localhost',
    $dbConfig['name'] ?? "myctobot_{$workspace}"
);
R::setup($dsn, $dbConfig['user'] ?? '', $dbConfig['pass'] ?? '');
R::freeze(true);

// Setup encryption key (needed for Shopify access token decryption)
// EncryptionService reads from Flight::get('encryption.master_key')
$masterKey = $config['encryption']['master_key'] ?? '';
if ($masterKey) {
    \Flight::set('encryption.master_key', $masterKey);
}

require_once __DIR__ . '/../services/ShopifyClient.php';
require_once __DIR__ . '/../services/AssistKnowledgeStore.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting Shopify API crawl for workspace={$workspace}, connection={$connectionId}\n";

try {
    $client = new ShopifyClient($connectionId);
    if (!$client->isConnected()) {
        fwrite(STDERR, "Shopify connection {$connectionId} not active\n");
        exit(1);
    }

    $store = AssistKnowledgeStore::forWorkspace($workspace);
    $storeDomain = $client->getShop();
    $baseUrl = "https://{$storeDomain}";
    $indexed = 0;

    // --- Products ---
    $productsQuery = <<<'GQL'
    query($first: Int!, $after: String) {
        products(first: $first, after: $after, query: "status:active") {
            edges {
                node {
                    handle title description productType status totalInventory tags
                    featuredImage { url }
                    priceRange { minVariantPrice { amount currencyCode } maxVariantPrice { amount currencyCode } }
                    variants(first: 10) { edges { node { title price sku availableForSale } } }
                }
                cursor
            }
            pageInfo { hasNextPage }
        }
    }
    GQL;

    $after = null;
    do {
        $vars = ['first' => 50, 'after' => $after];
        $result = $client->graphql($productsQuery, $vars);
        if (!$result['success']) {
            echo "  GraphQL products query failed\n";
            break;
        }

        $edges = $result['data']['products']['edges'] ?? [];
        foreach ($edges as $edge) {
            $p = $edge['node'];
            $after = $edge['cursor'];
            $url = "{$baseUrl}/products/{$p['handle']}";
            $minPriceRaw = (float)($p['priceRange']['minVariantPrice']['amount'] ?? 0);
            $maxPriceRaw = (float)($p['priceRange']['maxVariantPrice']['amount'] ?? 0);
            $currency = $p['priceRange']['minVariantPrice']['currencyCode'] ?? 'USD';
            // Shopify MoneyV2.amount is in cents (e.g., 94995.0 for $949.95)
            $minPrice = number_format($minPriceRaw / 100, 2);
            $maxPrice = number_format($maxPriceRaw / 100, 2);
            $priceStr = ($minPrice === $maxPrice) ? "{$currency} {$minPrice}" : "{$currency} {$minPrice} - {$maxPrice}";

            $variants = [];
            foreach (($p['variants']['edges'] ?? []) as $ve) {
                $v = $ve['node'];
                $variantPrice = number_format((float)($v['price'] ?? 0), 2);
                $variants[] = $v['title'] . " ({$currency} {$variantPrice}" . ($v['availableForSale'] ? '' : ', out of stock') . ")";
            }

            $content = "Product: {$p['title']}\n";
            $content .= "URL: {$url}\n";
            $content .= "Price: {$priceStr}\n";
            if ($p['productType']) $content .= "Type: {$p['productType']}\n";
            if ($p['totalInventory'] !== null) $content .= "Inventory: {$p['totalInventory']} units\n";
            if (!empty($p['tags'])) $content .= "Tags: " . implode(', ', $p['tags']) . "\n";
            if (!empty($variants)) $content .= "Variants: " . implode('; ', $variants) . "\n";
            if ($p['description']) $content .= "\n{$p['description']}";

            $store->upsertEntry('page_definition', $p['title'], trim($content), [
                'page' => $url,
                'knowledgebases_id' => $knowledgebaseId,
                'metadata' => [
                    'url' => $url,
                    'handle' => $p['handle'],
                    'image_url' => $p['featuredImage']['url'] ?? null,
                    'page_type' => 'product',
                    'source' => 'shopify_crawler',
                    'shop_domain' => $storeDomain,
                    'price' => $priceStr,
                    'product_type' => $p['productType'],
                    'indexed_at' => date('Y-m-d H:i:s'),
                ]
            ]);
            $indexed++;
        }
        $hasNext = $result['data']['products']['pageInfo']['hasNextPage'] ?? false;
    } while ($hasNext);

    echo "  Products indexed: {$indexed}\n";
    $prodCount = $indexed;

    // --- Collections ---
    $collectionsQuery = <<<'GQL'
    query($first: Int!) {
        collections(first: $first) {
            edges {
                node {
                    handle title description
                    productsCount { count }
                }
            }
        }
    }
    GQL;

    $result = $client->graphql($collectionsQuery, ['first' => 50]);
    if ($result['success']) {
        foreach (($result['data']['collections']['edges'] ?? []) as $edge) {
            $c = $edge['node'];
            $url = "{$baseUrl}/collections/{$c['handle']}";
            $count = $c['productsCount']['count'] ?? 0;

            $content = "Collection: {$c['title']}\n";
            $content .= "URL: {$url}\n";
            $content .= "Products: {$count} items\n";
            if ($c['description']) $content .= "\n{$c['description']}";

            $store->upsertEntry('page_definition', $c['title'], trim($content), [
                'page' => $url,
                'knowledgebases_id' => $knowledgebaseId,
                'metadata' => [
                    'url' => $url,
                    'page_type' => 'collection',
                    'source' => 'shopify_crawler',
                    'shop_domain' => $storeDomain,
                    'product_count' => $count,
                    'indexed_at' => date('Y-m-d H:i:s'),
                ]
            ]);
            $indexed++;
        }
    }

    echo "  Collections indexed: " . ($indexed - $prodCount) . "\n";
    $collCount = $indexed;

    // --- Pages ---
    $pagesQuery = <<<'GQL'
    query($first: Int!) {
        pages(first: $first) {
            edges {
                node {
                    handle title bodySummary
                }
            }
        }
    }
    GQL;

    $result = $client->graphql($pagesQuery, ['first' => 50]);
    if ($result['success']) {
        foreach (($result['data']['pages']['edges'] ?? []) as $edge) {
            $pg = $edge['node'];
            $url = "{$baseUrl}/pages/{$pg['handle']}";

            $content = "Page: {$pg['title']}\n";
            $content .= "URL: {$url}\n";
            if ($pg['bodySummary']) $content .= "\n{$pg['bodySummary']}";

            $store->upsertEntry('page_definition', $pg['title'], trim($content), [
                'page' => $url,
                'knowledgebases_id' => $knowledgebaseId,
                'metadata' => [
                    'url' => $url,
                    'page_type' => 'page',
                    'source' => 'shopify_crawler',
                    'shop_domain' => $storeDomain,
                    'indexed_at' => date('Y-m-d H:i:s'),
                ]
            ]);
            $indexed++;
        }
    }

    echo "  Pages indexed: " . ($indexed - $collCount) . "\n";
    echo "[" . date('Y-m-d H:i:s') . "] Done. Total indexed: {$indexed}\n";

} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
