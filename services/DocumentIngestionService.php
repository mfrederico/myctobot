<?php
/**
 * Document Ingestion Service
 *
 * Handles file → text → chunks → embeddings → store.
 * Replaces the standalone RAG Swoole server (port 9501) for document processing.
 *
 * Supports: PDF (via PaddleOCR), DOCX (XML extraction), TXT, HTML
 * Uses: AssistKnowledgeStore for vector storage, Ollama for embeddings
 */

namespace app\services;

class DocumentIngestionService
{
    private AssistKnowledgeStore $store;
    private string $ollamaHost;
    private string $paddleOcrUrl;
    private int $maxTokens;
    private int $overlapTokens;
    private int $minTokens;

    public function __construct(
        AssistKnowledgeStore $store,
        string $ollamaHost = 'http://localhost:11434',
        string $paddleOcrUrl = 'http://127.0.0.1:9502'
    ) {
        $this->store = $store;
        $this->ollamaHost = $ollamaHost;
        $this->paddleOcrUrl = $paddleOcrUrl;
        $this->maxTokens = 512;
        $this->overlapTokens = 50;
        $this->minTokens = 20;
    }

    /**
     * Ingest a file: extract text → chunk → embed → store
     *
     * @return array ['success' => bool, 'chunk_count' => int, 'error' => string|null]
     */
    public function ingest(string $filePath, string $filename, array $options = []): array
    {
        try {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $visionConfig = $options['vision_config'] ?? [];

            $text = $this->extractText($filePath, $ext, $visionConfig);
            $text = $this->sanitizeUtf8($text);

            if (empty(trim($text))) {
                return ['success' => false, 'chunk_count' => 0, 'error' => 'No text could be extracted from document'];
            }

            return $this->ingestText($text, $filename, $options['metadata'] ?? []);

        } catch (\Exception $e) {
            return ['success' => false, 'chunk_count' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Ingest raw text: chunk → embed → store
     */
    public function ingestText(string $text, string $filename, array $metadata = []): array
    {
        $text = $this->sanitizeUtf8($text);
        $chunks = $this->chunkText($text);

        if (empty($chunks)) {
            return ['success' => false, 'chunk_count' => 0, 'error' => 'Document too small to process'];
        }

        // Delete existing chunks for this filename (upsert behavior)
        $this->store->deleteDocument($filename);

        $chunkCount = 0;
        $errors = [];

        foreach ($chunks as $chunk) {
            try {
                $embedding = $this->getEmbedding($chunk['content']);
                if (!$embedding) {
                    $errors[] = "Chunk {$chunk['chunk_index']}: embedding failed";
                    continue;
                }

                $chunkMeta = array_merge($metadata, [
                    'chunk_of' => count($chunks),
                    'token_count' => $chunk['token_count'],
                ]);

                $this->store->insertChunk(
                    $filename,
                    $chunk['chunk_index'],
                    $chunk['content'],
                    $embedding,
                    $chunkMeta
                );
                $chunkCount++;

            } catch (\Exception $e) {
                $errors[] = "Chunk {$chunk['chunk_index']}: {$e->getMessage()}";
            }
        }

        return [
            'success' => $chunkCount > 0,
            'chunk_count' => $chunkCount,
            'total_chunks' => count($chunks),
            'errors' => $errors
        ];
    }

    /**
     * Ingest from URL: fetch → extract → chunk → embed → store
     */
    public function ingestUrl(string $url, string $filename = ''): array
    {
        if (empty($filename)) {
            $filename = parse_url($url, PHP_URL_HOST) . '-' . md5($url) . '.html';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'MyCTOBot/1.0'
        ]);
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$html || $httpCode !== 200) {
            return ['success' => false, 'chunk_count' => 0, 'error' => "Failed to fetch URL (HTTP {$httpCode})"];
        }

        // Strip HTML tags, keep text
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return $this->ingestText(trim($text), $filename, ['source_url' => $url]);
    }

    /**
     * Fetch URL and return extracted text
     */
    public function fetchUrlText(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'MyCTOBot/1.0'
        ]);
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$html || $httpCode !== 200) {
            throw new \Exception("Failed to fetch URL (HTTP {$httpCode})");
        }

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    // ========================================
    // Text Extraction
    // ========================================

    /**
     * Extract text from a file based on extension
     */
    private function extractText(string $filePath, string $ext, array $visionConfig = []): string
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        switch ($ext) {
            case 'pdf':
                return $this->extractPdf($filePath, $visionConfig);
            case 'docx':
            case 'doc':
                return $this->extractDocx($filePath);
            case 'html':
            case 'htm':
                $html = file_get_contents($filePath);
                return strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            case 'txt':
            default:
                return file_get_contents($filePath);
        }
    }

    /**
     * Extract text from PDF — PaddleOCR with text-layer fallback
     */
    private function extractPdf(string $filePath, array $visionConfig = []): string
    {
        // Try PaddleOCR first (handles both text-layer and scanned PDFs)
        $text = $this->extractViaPaddleOcr($filePath);
        if (!empty(trim($text))) {
            return $text;
        }

        // Fallback: read as plain text (some PDFs have extractable text)
        $content = file_get_contents($filePath);
        // Simple PDF text extraction: find text between BT/ET markers
        if (preg_match_all('/\(([^)]+)\)/', $content, $matches)) {
            $extracted = implode(' ', $matches[1]);
            if (strlen($extracted) > 100) {
                return $extracted;
            }
        }

        return '';
    }

    /**
     * Extract text from PDF using PaddleOCR service (port 9502)
     */
    private function extractViaPaddleOcr(string $filePath): string
    {
        // Check if PaddleOCR is available
        $healthCh = curl_init("{$this->paddleOcrUrl}/health");
        curl_setopt_array($healthCh, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        $healthResp = curl_exec($healthCh);
        $healthCode = curl_getinfo($healthCh, CURLINFO_HTTP_CODE);
        curl_close($healthCh);

        if ($healthCode !== 200) {
            return ''; // PaddleOCR not available
        }

        // Convert PDF pages to images, then OCR each
        $tempDir = sys_get_temp_dir() . '/paddle_' . uniqid();
        mkdir($tempDir, 0755, true);

        // Use pdftoppm if available, otherwise try ImageMagick convert
        $ppmPath = trim(shell_exec('which pdftoppm 2>/dev/null') ?: '');
        $convertPath = trim(shell_exec('which convert 2>/dev/null') ?: '');

        if ($ppmPath) {
            exec("pdftoppm -r 200 -png " . escapeshellarg($filePath) . " " . escapeshellarg($tempDir . '/page'), $output, $retCode);
        } elseif ($convertPath) {
            exec("convert -density 200 " . escapeshellarg($filePath) . " " . escapeshellarg($tempDir . '/page-%03d.png'), $output, $retCode);
        } else {
            // Send file directly to PaddleOCR path endpoint
            $ch = curl_init("{$this->paddleOcrUrl}/ocr/path");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['path' => $filePath]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 300
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($resp, true);
            $this->cleanupTempDir($tempDir);
            return $data['text'] ?? '';
        }

        // OCR each page image
        $pages = glob($tempDir . '/*.png');
        sort($pages);
        $allText = [];

        foreach ($pages as $i => $pagePath) {
            $ch = curl_init("{$this->paddleOcrUrl}/ocr/path");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['path' => $pagePath]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 60
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($resp, true);
            $pageText = $data['text'] ?? '';
            if (!empty(trim($pageText))) {
                $allText[] = "--- Page " . ($i + 1) . " ---\n" . $pageText;
            }
        }

        $this->cleanupTempDir($tempDir);
        return implode("\n\n", $allText);
    }

    /**
     * Extract text from DOCX via ZIP + XML parsing
     */
    private function extractDocx(string $filePath): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            return '';
        }

        $content = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!$content) {
            return '';
        }

        // Strip XML tags, keeping text content
        $text = strip_tags($content);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return preg_replace('/\s+/', ' ', trim($text));
    }

    // ========================================
    // Chunking
    // ========================================

    /**
     * Chunk text into segments with overlap
     * Uses sentence boundaries with ~3.5 chars/token estimation
     */
    private function chunkText(string $text): array
    {
        $maxChars = (int)($this->maxTokens * 3.5);
        $overlapChars = (int)($this->overlapTokens * 3.5);
        $minChars = (int)($this->minTokens * 3.5);

        // Split into sentences
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($sentences)) {
            // No sentence boundaries — split by character limit
            $sentences = str_split($text, $maxChars);
        }

        $chunks = [];
        $currentChunk = '';
        $overlapBuffer = [];
        $chunkIndex = 0;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (empty($sentence)) continue;

            // If single sentence exceeds max, split by words
            if (strlen($sentence) > $maxChars) {
                if (!empty($currentChunk)) {
                    if (strlen($currentChunk) >= $minChars) {
                        $chunks[] = $this->makeChunk($currentChunk, $chunkIndex++);
                    }
                    $currentChunk = '';
                }

                $words = explode(' ', $sentence);
                $wordChunk = '';
                foreach ($words as $word) {
                    if (strlen($wordChunk . ' ' . $word) > $maxChars && !empty($wordChunk)) {
                        $chunks[] = $this->makeChunk($wordChunk, $chunkIndex++);
                        $wordChunk = $word;
                    } else {
                        $wordChunk = empty($wordChunk) ? $word : $wordChunk . ' ' . $word;
                    }
                }
                if (!empty($wordChunk) && strlen($wordChunk) >= $minChars) {
                    $currentChunk = $wordChunk;
                }
                continue;
            }

            // Normal case: pack sentences greedily
            $candidate = empty($currentChunk) ? $sentence : $currentChunk . ' ' . $sentence;

            if (strlen($candidate) > $maxChars) {
                // Current chunk is full — save it
                if (strlen($currentChunk) >= $minChars) {
                    $chunks[] = $this->makeChunk($currentChunk, $chunkIndex++);
                }

                // Start new chunk with overlap from recent sentences
                $overlap = implode(' ', array_slice($overlapBuffer, -3));
                $currentChunk = strlen($overlap) > 0 ? $overlap . ' ' . $sentence : $sentence;
            } else {
                $currentChunk = $candidate;
            }

            $overlapBuffer[] = $sentence;
            if (count($overlapBuffer) > 5) {
                array_shift($overlapBuffer);
            }
        }

        // Don't forget the last chunk
        if (!empty($currentChunk) && strlen($currentChunk) >= $minChars) {
            $chunks[] = $this->makeChunk($currentChunk, $chunkIndex);
        }

        return $chunks;
    }

    private function makeChunk(string $content, int $index): array
    {
        return [
            'content' => trim($content),
            'chunk_index' => $index,
            'token_count' => (int)(strlen($content) / 3.5),
        ];
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * Get embedding from Ollama
     */
    private function getEmbedding(string $text): ?array
    {
        $url = rtrim($this->ollamaHost, '/') . '/api/embed';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'nomic-embed-text',
                'input' => substr($text, 0, 8000) // Max input for embedding model
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            return null;
        }

        $data = json_decode($response, true);
        return $data['embeddings'][0] ?? null;
    }

    /**
     * Sanitize text to valid UTF-8
     */
    private function sanitizeUtf8(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = iconv('UTF-8', 'UTF-8//IGNORE', $text);
        $text = str_replace("\0", '', $text);
        return trim($text);
    }

    private function cleanupTempDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($dir);
    }

    /**
     * Static factory
     */
    public static function forWorkspace(string $workspace, array $config = []): self
    {
        $store = AssistKnowledgeStore::forWorkspace($workspace, $config);
        return new self(
            $store,
            $config['ollama_host'] ?? 'http://localhost:11434',
            $config['paddle_ocr_url'] ?? 'http://127.0.0.1:9502'
        );
    }
}
