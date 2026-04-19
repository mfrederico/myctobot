<?php
/**
 * Assist Knowledge Store
 *
 * Vector-based knowledge store using MariaDB native VECTOR(768) columns.
 * Uses Bean::/R:: for CRUD and VEC_DISTANCE_COSINE() for similarity search.
 *
 * Features:
 * - Stores knowledge entries and document chunks with embeddings
 * - Uses Ollama nomic-embed-text for 768-dim embeddings
 * - MariaDB VECTOR INDEX for accelerated nearest-neighbor search
 * - Per-knowledgebase isolation via knowledgebases_id FK
 * - Backward-compatible interface (same public methods)
 *
 * Tables (MariaDB):
 *   kbentries       - Knowledge entries (definitions, FAQs, workflows)
 *   kbchunks        - Document chunks with embeddings
 *   kbinteractions  - User interaction log
 */

namespace app\services;

use \app\Bean;
use \RedBeanPHP\R;

class AssistKnowledgeStore
{
    private string $workspace;
    private string $ollamaHost;
    private string $embeddingModel;
    private int $embeddingDimension = 768;

    const ENTRY_TYPES = [
        'page_definition',
        'field_definition',
        'workflow',
        'explanation',
        'user_learned',
        'faq',
    ];

    public function __construct(string $workspace, array $config = [])
    {
        $this->workspace = $workspace;

        $iniHost = null;
        $iniPath = dirname(__DIR__) . '/conf/assistant.ini';
        if (file_exists($iniPath)) {
            $ini = parse_ini_file($iniPath, true);
            $iniHost = $ini['ollama']['host'] ?? null;
        }
        $this->ollamaHost = $config['ollama_host'] ?? $iniHost ?? 'http://127.0.0.1:11434';
        $this->embeddingModel = $config['embedding_model'] ?? 'nomic-embed-text';
    }

    // ════════════════════════════════════════════════════════════════
    // Knowledge Entries (kbentries)
    // ════════════════════════════════════════════════════════════════

    /**
     * Add a knowledge entry with auto-generated embedding
     */
    public function addEntry(string $type, string $title, string $content, array $options = []): int
    {
        if (!in_array($type, self::ENTRY_TYPES)) {
            throw new \InvalidArgumentException("Invalid entry type: {$type}");
        }

        $embedding = $this->getEmbedding($title . ' ' . $content);
        if (!$embedding) {
            return 0;
        }

        $vecJson = json_encode($embedding);
        $metadata = json_encode($options['metadata'] ?? []);
        $kbId = $options['knowledgebases_id'] ?? null;

        $id = R::getCell(
            'INSERT INTO kbentries (knowledgebases_id, entry_type, page, element, title, content, embedding, metadata, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, VEC_FromText(?), ?, NOW(), NOW())
             RETURNING id',
            [
                $kbId,
                $type,
                $options['page'] ?? null,
                $options['element'] ?? null,
                $title,
                $content,
                $vecJson,
                $metadata,
            ]
        );

        // Fallback if RETURNING not supported
        if (!$id) {
            $id = R::getCell('SELECT LAST_INSERT_ID()');
        }

        return (int)$id;
    }

    /**
     * Update an existing entry and re-embed if content changed
     */
    public function updateEntry(int $id, array $data): bool
    {
        $entry = Bean::load('kbentries', $id);
        if (!$entry || !$entry->id) return false;

        foreach (['title', 'content', 'page', 'element', 'relevance_score'] as $field) {
            if (isset($data[$field])) {
                $entry->$field = $data[$field];
            }
        }
        if (isset($data['metadata'])) {
            $entry->metadata = json_encode($data['metadata']);
        }
        $entry->updated_at = date('Y-m-d H:i:s');
        Bean::store($entry);

        // Re-embed if content changed
        if (isset($data['title']) || isset($data['content'])) {
            $embedding = $this->getEmbedding(($entry->title ?? '') . ' ' . ($entry->content ?? ''));
            if ($embedding) {
                R::exec(
                    'UPDATE kbentries SET embedding = VEC_FromText(?) WHERE id = ?',
                    [json_encode($embedding), $id]
                );
            }
        }

        return true;
    }

    /**
     * Upsert: update if page URL exists, otherwise insert
     */
    public function upsertEntry(string $type, string $title, string $content, array $options = []): int
    {
        $page = $options['page'] ?? null;

        if ($page) {
            $kbId = $options['knowledgebases_id'] ?? null;
            $sql = 'SELECT id FROM kbentries WHERE page = ?';
            $params = [$page];
            if ($kbId) {
                $sql .= ' AND knowledgebases_id = ?';
                $params[] = $kbId;
            }
            $existingId = R::getCell($sql, $params);

            if ($existingId) {
                $this->updateEntry((int)$existingId, [
                    'title' => $title,
                    'content' => $content,
                    'metadata' => $options['metadata'] ?? [],
                ]);
                return (int)$existingId;
            }
        }

        return $this->addEntry($type, $title, $content, $options);
    }

    /**
     * Get an entry by ID
     */
    public function getEntry(int $id): ?array
    {
        $row = R::getRow('SELECT id, knowledgebases_id, entry_type, page, element, title, content, metadata, access_count, relevance_score, created_at, updated_at FROM kbentries WHERE id = ?', [$id]);
        if ($row) {
            $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
        }
        return $row ?: null;
    }

    /**
     * Delete an entry
     */
    public function deleteEntry(int $id): bool
    {
        return (bool)R::exec('DELETE FROM kbentries WHERE id = ?', [$id]);
    }

    /**
     * Search knowledge entries by vector similarity
     *
     * @param string $query Search query
     * @param array $options page, type, limit, knowledgebases_ids (array of KB IDs)
     */
    public function search(string $query, array $options = []): array
    {
        $limit = $options['limit'] ?? 5;
        $threshold = $options['threshold'] ?? 0.3;

        $queryEmbedding = $this->getEmbedding($query);
        if (!$queryEmbedding) {
            return $this->textSearch($query, $options);
        }

        $vecJson = json_encode($queryEmbedding);

        $sql = 'SELECT id, knowledgebases_id, entry_type, page, element, title, content, metadata, access_count,
                       VEC_DISTANCE_COSINE(embedding, VEC_FromText(?)) AS dist
                FROM kbentries WHERE 1=1';
        $params = [$vecJson];

        if (!empty($options['page'])) {
            $sql .= ' AND (page = ? OR page IS NULL)';
            $params[] = $options['page'];
        }
        if (!empty($options['type'])) {
            $sql .= ' AND entry_type = ?';
            $params[] = $options['type'];
        }
        if (!empty($options['knowledgebases_ids'])) {
            $placeholders = implode(',', array_fill(0, count($options['knowledgebases_ids']), '?'));
            $sql .= " AND (knowledgebases_id IN ({$placeholders}) OR knowledgebases_id IS NULL)";
            $params = array_merge($params, $options['knowledgebases_ids']);
        }

        $sql .= ' HAVING dist < ? ORDER BY dist ASC LIMIT ?';
        $params[] = (1 - $threshold); // VEC_DISTANCE_COSINE returns distance (0=identical), threshold is similarity
        $params[] = $limit;

        $rows = R::getAll($sql, $params);

        return array_map(function ($row) {
            $row['similarity'] = round(1 - (float)$row['dist'], 3);
            $row['score'] = 1 - (float)$row['dist'];
            $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
            unset($row['dist']);
            return $row;
        }, $rows);
    }

    /**
     * Text-based search fallback
     */
    private function textSearch(string $query, array $options = []): array
    {
        $limit = $options['limit'] ?? 5;
        $sql = 'SELECT id, knowledgebases_id, entry_type, page, element, title, content, metadata, access_count, 1.0 as similarity, 1.0 as score
                FROM kbentries WHERE (title LIKE ? OR content LIKE ?)';
        $params = ["%{$query}%", "%{$query}%"];

        if (!empty($options['page'])) {
            $sql .= ' AND (page = ? OR page IS NULL)';
            $params[] = $options['page'];
        }

        $sql .= ' ORDER BY relevance_score DESC, access_count DESC LIMIT ?';
        $params[] = $limit;

        $rows = R::getAll($sql, $params);
        return array_map(function ($row) {
            $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
            return $row;
        }, $rows);
    }

    /**
     * Get entries for a specific page
     */
    public function getPageEntries(string $page, int $limit = 10): array
    {
        $rows = R::getAll(
            'SELECT id, knowledgebases_id, entry_type, page, element, title, content, metadata, access_count, relevance_score
             FROM kbentries
             WHERE page = ? OR page IS NULL
             ORDER BY CASE WHEN page = ? THEN 0 ELSE 1 END, relevance_score DESC, access_count DESC
             LIMIT ?',
            [$page, $page, $limit]
        );

        return array_map(function ($row) {
            $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
            return $row;
        }, $rows);
    }

    /**
     * Increment access count
     */
    public function recordAccess(int $id): void
    {
        R::exec('UPDATE kbentries SET access_count = access_count + 1 WHERE id = ?', [$id]);
    }

    /**
     * Seed initial knowledge definitions
     */
    public function seedKnowledge(array $definitions): void
    {
        foreach ($definitions as $def) {
            $existing = R::getCell(
                'SELECT id FROM kbentries WHERE entry_type = ? AND page = ? AND title = ?',
                [$def['type'] ?? 'page_definition', $def['page'] ?? null, $def['title']]
            );

            if (!$existing) {
                $this->addEntry(
                    $def['type'] ?? 'page_definition',
                    $def['title'],
                    $def['content'],
                    [
                        'page' => $def['page'] ?? null,
                        'element' => $def['element'] ?? null,
                        'metadata' => $def['metadata'] ?? [],
                        'knowledgebases_id' => $def['knowledgebases_id'] ?? null,
                    ]
                );
            }
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Document Chunks (kbchunks)
    // ════════════════════════════════════════════════════════════════

    /**
     * Insert a document chunk with pre-computed embedding
     */
    public function insertChunk(string $filename, int $chunkIndex, string $content, array $embedding, array $metadata = []): int
    {
        $vecJson = json_encode($embedding);
        $metaJson = json_encode($metadata);
        $kbId = $metadata['knowledgebases_id'] ?? null;
        $docId = $metadata['ragdocuments_id'] ?? null;

        // Upsert: delete existing chunk with same filename+index, then insert
        R::exec('DELETE FROM kbchunks WHERE filename = ? AND chunk_index = ?', [$filename, $chunkIndex]);

        R::exec(
            'INSERT INTO kbchunks (knowledgebases_id, ragdocuments_id, filename, chunk_index, content, embedding, metadata, created_at)
             VALUES (?, ?, ?, ?, ?, VEC_FromText(?), ?, NOW())',
            [$kbId, $docId, $filename, $chunkIndex, $content, $vecJson, $metaJson]
        );

        return (int)R::getCell('SELECT LAST_INSERT_ID()');
    }

    /**
     * Search document chunks by vector similarity
     *
     * @param string $query Search text
     * @param int $limit Max results
     * @param float $threshold Minimum similarity (0-1)
     * @param array $kbIds Optional: filter to specific knowledge base IDs
     */
    public function searchDocuments(string $query, int $limit = 5, float $threshold = 0.5, array $kbIds = []): array
    {
        $queryEmbedding = $this->getEmbedding($query);
        if (!$queryEmbedding) {
            return $this->textSearchDocuments($query, $limit);
        }

        $vecJson = json_encode($queryEmbedding);

        $sql = 'SELECT id, knowledgebases_id, ragdocuments_id, filename, chunk_index, content, metadata,
                       VEC_DISTANCE_COSINE(embedding, VEC_FromText(?)) AS dist
                FROM kbchunks WHERE 1=1';
        $params = [$vecJson];

        if (!empty($kbIds)) {
            $placeholders = implode(',', array_fill(0, count($kbIds), '?'));
            $sql .= " AND knowledgebases_id IN ({$placeholders})";
            $params = array_merge($params, $kbIds);
        }

        $sql .= ' HAVING dist < ? ORDER BY dist ASC LIMIT ?';
        $params[] = (1 - $threshold);
        $params[] = $limit;

        $rows = R::getAll($sql, $params);

        return array_map(function ($row) {
            return [
                'id' => (int)$row['id'],
                'filename' => $row['filename'],
                'chunk_index' => (int)$row['chunk_index'],
                'content' => $row['content'],
                'score' => 1 - (float)$row['dist'],
                'similarity' => round(1 - (float)$row['dist'], 3),
                'metadata' => json_decode($row['metadata'] ?: '{}', true),
            ];
        }, $rows);
    }

    /**
     * Text-based fallback for document search
     */
    private function textSearchDocuments(string $query, int $limit = 5): array
    {
        $rows = R::getAll(
            'SELECT id, filename, chunk_index, content, metadata FROM kbchunks WHERE content LIKE ? ORDER BY created_at DESC LIMIT ?',
            ["%{$query}%", $limit]
        );

        return array_map(function ($row) {
            return [
                'id' => (int)$row['id'],
                'filename' => $row['filename'],
                'chunk_index' => (int)($row['chunk_index'] ?? 0),
                'content' => $row['content'],
                'score' => 1.0,
                'similarity' => 1.0,
                'metadata' => json_decode($row['metadata'] ?: '{}', true),
            ];
        }, $rows);
    }

    /**
     * Search BOTH knowledge entries AND document chunks, merged by score
     */
    public function searchAll(string $query, int $limit = 5, float $threshold = 0.3, array $kbIds = []): array
    {
        $queryEmbedding = $this->getEmbedding($query);
        if (!$queryEmbedding) {
            $knowledge = $this->textSearch($query, ['limit' => $limit]);
            $docs = $this->textSearchDocuments($query, $limit);
            $merged = array_merge(
                array_map(fn($k) => array_merge($k, ['source_type' => 'knowledge']), $knowledge),
                array_map(fn($d) => array_merge($d, ['source_type' => 'document']), $docs)
            );
            usort($merged, fn($a, $b) => ($b['similarity'] ?? 0) <=> ($a['similarity'] ?? 0));
            return array_slice($merged, 0, $limit);
        }

        $vecJson = json_encode($queryEmbedding);
        $distThreshold = 1 - $threshold;
        $results = [];

        // Search kbentries
        $sql1 = 'SELECT id, title, content, page, metadata,
                        VEC_DISTANCE_COSINE(embedding, VEC_FromText(?)) AS dist
                 FROM kbentries WHERE 1=1';
        $params1 = [$vecJson];
        if (!empty($kbIds)) {
            $ph = implode(',', array_fill(0, count($kbIds), '?'));
            $sql1 .= " AND (knowledgebases_id IN ({$ph}) OR knowledgebases_id IS NULL)";
            $params1 = array_merge($params1, $kbIds);
        }
        $sql1 .= ' HAVING dist < ? ORDER BY dist ASC LIMIT ?';
        $params1[] = $distThreshold;
        $params1[] = $limit;

        foreach (R::getAll($sql1, $params1) as $row) {
            $results[] = [
                'source_type' => 'knowledge',
                'title' => $row['title'],
                'content' => $row['content'],
                'page' => $row['page'],
                'similarity' => round(1 - (float)$row['dist'], 3),
                'score' => 1 - (float)$row['dist'],
                'metadata' => json_decode($row['metadata'] ?? '{}', true),
            ];
        }

        // Search kbchunks
        $sql2 = 'SELECT id, filename, chunk_index, content, metadata,
                        VEC_DISTANCE_COSINE(embedding, VEC_FromText(?)) AS dist
                 FROM kbchunks WHERE 1=1';
        $params2 = [$vecJson];
        if (!empty($kbIds)) {
            $ph = implode(',', array_fill(0, count($kbIds), '?'));
            $sql2 .= " AND knowledgebases_id IN ({$ph})";
            $params2 = array_merge($params2, $kbIds);
        }
        $sql2 .= ' HAVING dist < ? ORDER BY dist ASC LIMIT ?';
        $params2[] = $distThreshold;
        $params2[] = $limit;

        foreach (R::getAll($sql2, $params2) as $row) {
            $results[] = [
                'source_type' => 'document',
                'title' => $row['filename'],
                'content' => $row['content'],
                'filename' => $row['filename'],
                'chunk_index' => (int)$row['chunk_index'],
                'similarity' => round(1 - (float)$row['dist'], 3),
                'score' => 1 - (float)$row['dist'],
                'metadata' => json_decode($row['metadata'] ?: '{}', true),
            ];
        }

        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($results, 0, $limit);
    }

    /**
     * Delete all chunks for a document
     */
    public function deleteDocument(string $filename): int
    {
        R::exec('DELETE FROM kbchunks WHERE filename = ?', [$filename]);
        // Return affected rows (R::exec returns it)
        return 1;
    }

    /**
     * List all documents with chunk counts
     */
    public function listDocuments(): array
    {
        return R::getAll(
            'SELECT filename, COUNT(*) as chunk_count, MIN(created_at) as created_at
             FROM kbchunks GROUP BY filename ORDER BY created_at DESC'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Interactions
    // ════════════════════════════════════════════════════════════════

    public function logInteraction(string $page, string $userMessage, ?string $response = null, array $actions = []): int
    {
        $bean = Bean::dispense('kbinteractions');
        $bean->page = $page;
        $bean->user_message = $userMessage;
        $bean->assistant_response = $response;
        $bean->actions_taken = json_encode($actions);
        $bean->created_at = date('Y-m-d H:i:s');
        return (int)Bean::store($bean);
    }

    public function getRecentInteractions(string $page, int $limit = 5): array
    {
        $rows = Bean::find('kbinteractions', ' page = ? ORDER BY created_at DESC LIMIT ? ', [$page, $limit]);
        return array_map(function ($row) {
            return [
                'id' => (int)$row->id,
                'page' => $row->page,
                'user_message' => $row->user_message,
                'assistant_response' => $row->assistant_response,
                'actions_taken' => json_decode($row->actions_taken ?? '[]', true),
                'feedback' => $row->feedback,
                'created_at' => $row->created_at,
            ];
        }, $rows);
    }

    public function learnFromInteractions(?string $page = null): array
    {
        $sql = 'SELECT page, user_message, COUNT(*) as count FROM kbinteractions
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)';
        $params = [];
        if ($page) {
            $sql .= ' AND page = ?';
            $params[] = $page;
        }
        $sql .= ' GROUP BY page, user_message HAVING count >= 2';

        $patterns = R::getAll($sql, $params);
        $created = [];

        foreach ($patterns as $pattern) {
            $existing = R::getCell(
                'SELECT id FROM kbentries WHERE entry_type = ? AND page = ? AND title = ?',
                ['faq', $pattern['page'], $pattern['user_message']]
            );

            if (!$existing) {
                $id = $this->addEntry('faq', $pattern['user_message'], '', [
                    'page' => $pattern['page'],
                    'metadata' => ['auto_generated' => true, 'query_count' => (int)$pattern['count']],
                ]);
                if ($id) $created[] = $id;
            }
        }

        return $created;
    }

    // ════════════════════════════════════════════════════════════════
    // RAG Query (retrieve + generate)
    // ════════════════════════════════════════════════════════════════

    public function queryWithAnswer(string $query, array $options = []): array
    {
        $limit = $options['max_results'] ?? 5;
        $threshold = $options['similarity_threshold'] ?? 0.5;
        $model = $options['model'] ?? 'qwen2.5:3b';
        $ollamaHost = $options['ollama_host'] ?? $this->ollamaHost;
        $kbIds = $options['knowledgebases_ids'] ?? [];

        $results = $this->searchAll($query, $limit, $threshold, $kbIds);

        if (empty($results)) {
            return [
                'success' => true,
                'response' => "I don't have any relevant information in the knowledge base to answer that question.",
                'sources' => [],
                'context_used' => false
            ];
        }

        $context = '';
        $tokenEstimate = 0;
        foreach ($results as $r) {
            $chunkTokens = (int)(strlen($r['content']) / 4);
            if ($tokenEstimate + $chunkTokens > 3000) break;
            $source = $r['filename'] ?? $r['title'] ?? 'unknown';
            $context .= "---\n[Source: {$source}]\n{$r['content']}\n\n";
            $tokenEstimate += $chunkTokens;
        }

        $prompt = "You are a helpful assistant that answers questions based on the provided context from a knowledge base.\n\n"
            . "Instructions:\n"
            . "- Answer the question using ONLY the information from the context below\n"
            . "- If the context doesn't contain enough information to answer, say so clearly\n"
            . "- Be concise and direct in your answers\n"
            . "- If quoting from the context, mention the source document\n"
            . "- Do not make up information that isn't in the context\n\n"
            . "Context from knowledge base:\n{$context}\n\n"
            . "Question: {$query}\n\nAnswer:";

        $answer = $this->callOllamaGenerate($ollamaHost, $model, $prompt);

        $sources = array_map(fn($r) => [
            'filename' => $r['filename'] ?? $r['title'] ?? '',
            'similarity' => $r['similarity'],
            'score' => $r['score'],
            'content' => $r['content'],
            'preview' => substr($r['content'], 0, 100) . '...',
            'metadata' => $r['metadata'] ?? [],
        ], $results);

        return [
            'success' => true,
            'response' => $answer,
            'sources' => $sources,
            'context_used' => true,
        ];
    }

    // ════════════════════════════════════════════════════════════════
    // Stats & Cleanup
    // ════════════════════════════════════════════════════════════════

    public function getStats(): array
    {
        return [
            'total_entries' => (int)R::getCell('SELECT COUNT(*) FROM kbentries'),
            'by_type' => R::getAll('SELECT entry_type, COUNT(*) as count FROM kbentries GROUP BY entry_type'),
            'total_chunks' => (int)R::getCell('SELECT COUNT(*) FROM kbchunks'),
            'total_interactions' => (int)R::getCell('SELECT COUNT(*) FROM kbinteractions'),
        ];
    }

    public function cleanup(int $daysOld = 90): int
    {
        R::exec('DELETE FROM kbinteractions WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [$daysOld]);
        R::exec("DELETE FROM kbentries WHERE JSON_EXTRACT(metadata, '$.auto_generated') = true AND access_count = 0 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        return 1;
    }

    // ════════════════════════════════════════════════════════════════
    // Embedding via Ollama
    // ════════════════════════════════════════════════════════════════

    public function getEmbedding(string $text): ?array
    {
        $url = rtrim($this->ollamaHost, '/') . '/api/embed';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->embeddingModel,
                'input' => substr($text, 0, 8000),
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) return null;

        $data = json_decode($response, true);
        return $data['embeddings'][0] ?? null;
    }

    private function callOllamaGenerate(string $host, string $model, string $prompt): string
    {
        $url = rtrim($host, '/') . '/api/generate';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
                'options' => ['temperature' => 0.3],
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        return $data['response'] ?? 'Unable to generate answer.';
    }

    // ════════════════════════════════════════════════════════════════
    // Factory
    // ════════════════════════════════════════════════════════════════

    public static function forWorkspace(string $workspace, array $config = []): self
    {
        return new self($workspace, $config);
    }
}
