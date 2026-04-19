#!/usr/bin/env php
<?php
/**
 * Async document ingestion CLI script.
 * Called by pipeline steps for background PDF/DOCX processing.
 *
 * Usage: php scripts/ingest-document.php --workspace=demo --doc_id=42
 */

$opts = getopt('', ['workspace:', 'doc_id:', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php scripts/ingest-document.php --workspace=demo --doc_id=42\n";
    exit(0);
}

$workspace = $opts['workspace'] ?? 'demo';
$docId = (int)($opts['doc_id'] ?? 0);

if (!$docId) {
    fwrite(STDERR, "Error: --doc_id is required\n");
    exit(1);
}

// Bootstrap
$_SERVER['WORKSPACE'] = $workspace;
require_once __DIR__ . '/../vendor/autoload.php';

use RedBeanPHP\R;
use app\Bean;
use app\services\DocumentIngestionService;

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

// Setup encryption key
$masterKey = $config['encryption']['master_key'] ?? '';
if ($masterKey) {
    \Flight::set('encryption.master_key', $masterKey);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting document ingestion for doc_id={$docId}, workspace={$workspace}\n";

try {
    // Load document record
    $doc = Bean::load('ragdocuments', $docId);
    if (!$doc->id) {
        fwrite(STDERR, "Document not found: {$docId}\n");
        exit(1);
    }

    $doc->status = 'processing';
    Bean::store($doc);

    // Find the uploaded file
    $uploadsDir = __DIR__ . '/../uploads/knowledge/';
    $filePath = $uploadsDir . $doc->filename;

    if (!file_exists($filePath)) {
        $doc->status = 'error';
        $doc->error_message = 'File not found on disk';
        Bean::store($doc);
        fwrite(STDERR, "File not found: {$filePath}\n");
        exit(1);
    }

    // Ingest
    $service = DocumentIngestionService::forWorkspace($workspace);
    $result = $service->ingest($filePath, $doc->filename, [
        'metadata' => [
            'doc_id' => $docId,
            'original_filename' => $doc->original_filename ?? $doc->filename,
        ]
    ]);

    if ($result['success']) {
        $doc->status = 'ready';
        $doc->chunk_count = $result['chunk_count'];
        $doc->processed_at = date('Y-m-d H:i:s');
        Bean::store($doc);
        echo "  Document processed: {$result['chunk_count']} chunks\n";
    } else {
        $doc->status = 'error';
        $doc->error_message = $result['error'] ?? 'Processing failed';
        Bean::store($doc);
        fwrite(STDERR, "Processing failed: {$result['error']}\n");
        exit(1);
    }

    echo "[" . date('Y-m-d H:i:s') . "] Done.\n";

} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    if (isset($doc) && $doc->id) {
        $doc->status = 'error';
        $doc->error_message = $e->getMessage();
        Bean::store($doc);
    }
    exit(1);
}
