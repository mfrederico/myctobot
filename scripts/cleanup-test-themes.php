<?php
error_reporting(E_ALL);
$baseDir = dirname(__FILE__, 2);
chdir($baseDir);
require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/bootstrap.php';
require_once $baseDir . '/services/ShopifyClient.php';

$bootstrap = new \app\Bootstrap($baseDir . '/conf/config.ini');
$shopifyClient = new \app\services\ShopifyClient(3);
$shop = $shopifyClient->getShop();
$accessToken = $shopifyClient->getAccessToken();

// Themes to delete (AI-DEV and test themes we created)
$themesToDelete = [
    183421600057,  // [AI-DEV] SSI-1892: CLONE - GWT- Header Gradient Bu
    183421796665,  // Shopiify_Tools_Yuva/SSI-1892-gradient
];

foreach ($themesToDelete as $themeId) {
    echo "Deleting theme {$themeId}... ";
    $ch = curl_init("https://{$shop}/admin/api/2024-10/themes/{$themeId}.json");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Shopify-Access-Token: ' . $accessToken]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo ($httpCode == 200 ? "OK" : "Failed ({$httpCode})") . "\n";
}

echo "\nDone!\n";
