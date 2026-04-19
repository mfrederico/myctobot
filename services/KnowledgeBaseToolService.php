<?php
/**
 * KnowledgeBaseToolService
 *
 * MCP tools for knowledge base search and RAG query.
 * Uses AssistKnowledgeStore directly (no external RAG service).
 */

namespace app\services;

use app\Bean;

class KnowledgeBaseToolService
{
    private string $workspace;
    private $logger;

    public function __construct(string $workspace, $logger)
    {
        $this->workspace = $workspace;
        $this->logger = $logger;
    }

    /**
     * List knowledge bases for this workspace
     */
    public function listKnowledgeBases(array $arguments): array
    {
        $knowledgeBases = Bean::find('knowledgebases', ' 1=1 ORDER BY name ASC ');

        $result = [];
        foreach ($knowledgeBases as $kb) {
            $docCount = Bean::count('knowledgebasedocs', 'knowledgebases_id = ?', [$kb->id]);
            $result[] = [
                'id' => (int) $kb->id,
                'name' => $kb->name,
                'slug' => $kb->slug,
                'description' => $kb->description,
                'document_count' => $docCount,
                'created_at' => $kb->created_at
            ];
        }

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'knowledge_bases' => $result,
                    'count' => count($result),
                    'workspace' => $this->workspace
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Vector search against a knowledge base
     */
    public function searchKnowledgeBase(array $arguments): array
    {
        $kbId = $arguments['knowledge_base_id'] ?? null;
        $kbSlug = $arguments['knowledge_base_slug'] ?? null;
        $query = $arguments['query'] ?? '';
        $maxResults = (int) ($arguments['max_results'] ?? 5);
        $similarityThreshold = (float) ($arguments['similarity_threshold'] ?? 0.7);

        if (!$kbId && !$kbSlug) {
            throw new PipelineToolException('Either knowledge_base_id or knowledge_base_slug is required');
        }

        if (empty($query)) {
            throw new PipelineToolException('query is required');
        }

        $kb = $this->loadKb($kbId, $kbSlug);

        $store = AssistKnowledgeStore::forWorkspace($this->workspace);
        $results = $store->searchDocuments($query, $maxResults, $similarityThreshold);

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'knowledge_base' => $kb->name,
                    'query' => $query,
                    'results' => $results,
                    'result_count' => count($results)
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * RAG-augmented Q&A against a knowledge base
     */
    public function queryKnowledgeBase(array $arguments): array
    {
        $kbId = $arguments['knowledge_base_id'] ?? null;
        $kbSlug = $arguments['knowledge_base_slug'] ?? null;
        $query = $arguments['query'] ?? '';
        $maxResults = (int) ($arguments['max_results'] ?? 5);
        $similarityThreshold = (float) ($arguments['similarity_threshold'] ?? 0.7);

        if (!$kbId && !$kbSlug) {
            throw new PipelineToolException('Either knowledge_base_id or knowledge_base_slug is required');
        }

        if (empty($query)) {
            throw new PipelineToolException('query is required');
        }

        $kb = $this->loadKb($kbId, $kbSlug);

        $store = AssistKnowledgeStore::forWorkspace($this->workspace);
        $result = $store->queryWithAnswer($query, [
            'max_results' => $maxResults,
            'similarity_threshold' => $similarityThreshold,
        ]);

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'knowledge_base' => $kb->name,
                    'query' => $query,
                    'answer' => $result['response'] ?? '',
                    'sources' => $result['sources'] ?? [],
                    'context_used' => $result['context_used'] ?? null
                ], JSON_PRETTY_PRINT)
            ]],
            'isError' => false
        ];
    }

    /**
     * Get tool definitions for knowledge base tools
     */
    public static function getToolDefinitions(): array
    {
        return [
            [
                'name' => 'list_knowledge_bases',
                'description' => 'List all knowledge bases in the workspace. Returns name, slug, document count for each KB.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                    'required' => []
                ]
            ],
            [
                'name' => 'search_knowledge_base',
                'description' => 'Search a knowledge base using vector similarity. Returns matching document chunks with relevance scores. Use this for finding specific information in uploaded documents.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'knowledge_base_id' => [
                            'type' => 'integer',
                            'description' => 'Knowledge base ID (use either this or knowledge_base_slug)'
                        ],
                        'knowledge_base_slug' => [
                            'type' => 'string',
                            'description' => 'Knowledge base slug (use either this or knowledge_base_id)'
                        ],
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query text'
                        ],
                        'max_results' => [
                            'type' => 'integer',
                            'description' => 'Maximum results to return. Default: 5',
                            'default' => 5
                        ],
                        'similarity_threshold' => [
                            'type' => 'number',
                            'description' => 'Minimum similarity score (0-1). Default: 0.7',
                            'default' => 0.7
                        ]
                    ],
                    'required' => ['query']
                ]
            ],
            [
                'name' => 'query_knowledge_base',
                'description' => 'Ask a question and get an AI-generated answer based on knowledge base documents. Uses RAG (Retrieval Augmented Generation) to find relevant context and generate a comprehensive answer with source citations.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'knowledge_base_id' => [
                            'type' => 'integer',
                            'description' => 'Knowledge base ID (use either this or knowledge_base_slug)'
                        ],
                        'knowledge_base_slug' => [
                            'type' => 'string',
                            'description' => 'Knowledge base slug (use either this or knowledge_base_id)'
                        ],
                        'query' => [
                            'type' => 'string',
                            'description' => 'Question to answer using the knowledge base'
                        ],
                        'max_results' => [
                            'type' => 'integer',
                            'description' => 'Maximum context chunks to retrieve. Default: 5',
                            'default' => 5
                        ],
                        'similarity_threshold' => [
                            'type' => 'number',
                            'description' => 'Minimum similarity score (0-1). Default: 0.7',
                            'default' => 0.7
                        ]
                    ],
                    'required' => ['query']
                ]
            ]
        ];
    }

    private function loadKb(?int $kbId, ?string $kbSlug)
    {
        $kb = null;
        if ($kbId) {
            $kb = Bean::load('knowledgebases', (int) $kbId);
        } elseif ($kbSlug) {
            $kb = Bean::findOne('knowledgebases', 'slug = ?', [$kbSlug]);
        }

        if (!$kb || !$kb->id) {
            throw new PipelineToolException('Knowledge base not found');
        }

        return $kb;
    }
}
