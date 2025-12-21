<?php
error_reporting(E_ALL);
$baseDir = dirname(__FILE__, 2);
chdir($baseDir);

require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/bootstrap.php';
require_once $baseDir . '/services/AIDevJobService.php';

$bootstrap = new \app\Bootstrap($baseDir . '/conf/config.ini');
$svc = new \app\services\AIDevJobService();
$result = $svc->triggerJob(3, 'SSI-1892', 'cb1fabf7-9018-49bb-90c7-afa23343dbe5');
print_r($result);
