<?php
error_reporting(E_ALL);
$baseDir = dirname(__FILE__, 2);
chdir($baseDir);
require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/bootstrap.php';
require_once $baseDir . '/services/ShopifyClient.php';

$bootstrap = new \app\Bootstrap($baseDir . '/conf/config.ini');
$shopifyClient = new \app\services\ShopifyClient(3);

echo "Shop: " . $shopifyClient->getShop() . "\n";
echo "Token: " . substr($shopifyClient->getAccessToken(), 0, 10) . "...\n";
