<?php
/**
 * Attachment Service
 * Detects and processes file attachments in CEO directives (Jira issues)
 *
 * This service provides:
 * - Detection of all file attachments (not just images)
 * - Size validation with configurable limits
 * - Acknowledgment formatting with filenames and sizes
 * - Integration with workspace RAG system for document indexing
 */

namespace app\services;

use \Flight as Flight;
use GuzzleHttp\Client;

class AttachmentService {

    /** @var int Default maximum attachment size in bytes (25MB) */
    private const DEFAULT_MAX_SIZE_BYTES = 25 * 1024 * 1024;

    /** @var array Image MIME types for classification */
    private const IMAGE_TYPES = [
        'image/png',
        'image/jpeg',
        'image/jpg',
        'image/gif',
        'image/webp',
        'image/svg+xml'
    ];

    /** @var array Document MIME types for RAG indexing */
    private const DOCUMENT_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'text/csv',
        'text/html',
        'text/markdown',
        'application/json'
    ];

    /** @var JiraClient */
    private JiraClient $jiraClient;

    /** @var string RAG service URL */
    private string $ragServiceUrl;

    /** @var int Maximum attachment size in bytes */
    private int $maxSizeBytes;

    /**
     * Create a new AttachmentService
     *
     * @param JiraClient $jiraClient Jira API client for fetching attachments
     * @param string|null $ragServiceUrl Optional RAG service URL (defaults from env)
     * @param int|null $maxSizeBytes Optional max attachment size (defaults from env or 25MB)
     */
    public function __construct(
        JiraClient $jiraClient,
        ?string $ragServiceUrl = null,
        ?int $maxSizeBytes = null
    ) {
        $this->jiraClient = $jiraClient;
        $this->ragServiceUrl = $ragServiceUrl ?? getenv('RAG_SERVICE_URL') ?: 'http://localhost:9501';
        $this->maxSizeBytes = $maxSizeBytes ?? $this->getMaxSizeFromEnv();
    }

    /**
     * Get max attachment size from environment or use default
     *
     * @return int Max size in bytes
     */
    private function getMaxSizeFromEnv(): int {
        $envValue = getenv('ATTACHMENT_MAX_SIZE_MB');
        if ($envValue !== false && is_numeric($envValue)) {
            return (int)$envValue * 1024 * 1024;
        }
        return self::DEFAULT_MAX_SIZE_BYTES;
    }

    /**
     * Detect all attachments from a Jira issue
     *
     * @param array $issue Jira issue data with 'fields' containing 'attachment'
     * @return array Array of attachment info with metadata
     */
    public function detectAttachments(array $issue): array {
        $attachments = $issue['fields']['attachment'] ?? [];
        if (empty($attachments)) {
            return [];
        }

        $result = [];
        foreach ($attachments as $attachment) {
            $info = $this->processAttachment($attachment);
            if ($info !== null) {
                $result[] = $info;
            }
        }

        return $result;
    }

    /**
     * Process a single attachment and extract metadata
     *
     * @param array $attachment Raw attachment data from Jira
     * @return array|null Processed attachment info or null if invalid
     */
    private function processAttachment(array $attachment): ?array {
        $filename = $attachment['filename'] ?? null;
        if (empty($filename)) {
            return null;
        }

        $size = (int)($attachment['size'] ?? 0);
        $mimeType = $attachment['mimeType'] ?? 'application/octet-stream';
        $contentUrl = $attachment['content'] ?? '';
        $attachmentId = $attachment['id'] ?? '';
        $created = $attachment['created'] ?? null;
        $author = $attachment['author']['displayName'] ?? 'Unknown';

        return [
            'id' => $attachmentId,
            'filename' => $filename,
            'size' => $size,
            'size_formatted' => $this->formatSize($size),
            'mime_type' => $mimeType,
            'content_url' => $contentUrl,
            'created' => $created,
            'author' => $author,
            'type' => $this->classifyAttachment($mimeType, $filename),
            'is_oversized' => $size > $this->maxSizeBytes,
            'is_indexable' => $this->isIndexable($mimeType)
        ];
    }

    /**
     * Classify attachment by type
     *
     * @param string $mimeType MIME type
     * @param string $filename Filename for extension fallback
     * @return string Classification: 'image', 'document', 'code', 'archive', 'other'
     */
    private function classifyAttachment(string $mimeType, string $filename): string {
        // Check by MIME type first
        if (in_array($mimeType, self::IMAGE_TYPES, true)) {
            return 'image';
        }

        if (in_array($mimeType, self::DOCUMENT_TYPES, true)) {
            return 'document';
        }

        // Check by extension for code files and archives
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $codeExtensions = ['php', 'js', 'ts', 'py', 'java', 'go', 'rb', 'cs', 'cpp', 'c', 'h', 'css', 'scss', 'less', 'vue', 'jsx', 'tsx'];
        if (in_array($ext, $codeExtensions, true)) {
            return 'code';
        }

        $archiveExtensions = ['zip', 'tar', 'gz', 'rar', '7z'];
        if (in_array($ext, $archiveExtensions, true)) {
            return 'archive';
        }

        return 'other';
    }

    /**
     * Check if attachment can be indexed in RAG
     *
     * @param string $mimeType MIME type
     * @return bool True if indexable
     */
    private function isIndexable(string $mimeType): bool {
        return in_array($mimeType, self::DOCUMENT_TYPES, true) ||
               in_array($mimeType, self::IMAGE_TYPES, true);
    }

    /**
     * Validate attachment size against configured limit
     *
     * @param array $attachment Attachment info from detectAttachments()
     * @return array Validation result with 'valid', 'message', 'size', 'limit'
     */
    public function validateAttachmentSize(array $attachment): array {
        $size = (int)($attachment['size'] ?? 0);
        $filename = $attachment['filename'] ?? 'unknown';

        $valid = $size <= $this->maxSizeBytes;
        $limitFormatted = $this->formatSize($this->maxSizeBytes);

        return [
            'valid' => $valid,
            'message' => $valid
                ? "Attachment '{$filename}' is within size limits"
                : "WARNING: Attachment '{$filename}' ({$attachment['size_formatted']}) exceeds the {$limitFormatted} limit",
            'size' => $size,
            'size_formatted' => $attachment['size_formatted'] ?? $this->formatSize($size),
            'limit' => $this->maxSizeBytes,
            'limit_formatted' => $limitFormatted
        ];
    }

    /**
     * Format acknowledgment text for attachments
     *
     * @param array $attachments Array of attachment info from detectAttachments()
     * @param bool $includeWarnings Whether to include oversized warnings
     * @return string Formatted acknowledgment text
     */
    public function formatAcknowledgment(array $attachments, bool $includeWarnings = true): string {
        if (empty($attachments)) {
            return "No file attachments found in this directive.";
        }

        $lines = [];
        $lines[] = "**Attachments Detected (" . count($attachments) . " file(s)):**";
        $lines[] = "";

        // Group by type
        $byType = [];
        foreach ($attachments as $att) {
            $type = $att['type'] ?? 'other';
            $byType[$type][] = $att;
        }

        // Order: images, documents, code, archive, other
        $typeOrder = ['image', 'document', 'code', 'archive', 'other'];
        $typeLabels = [
            'image' => 'Images',
            'document' => 'Documents',
            'code' => 'Code Files',
            'archive' => 'Archives',
            'other' => 'Other Files'
        ];

        $oversizedWarnings = [];

        foreach ($typeOrder as $type) {
            if (!isset($byType[$type]) || empty($byType[$type])) {
                continue;
            }

            $lines[] = "_{$typeLabels[$type]}:_";
            foreach ($byType[$type] as $att) {
                $line = "- {$att['filename']} ({$att['size_formatted']})";
                if ($att['is_oversized'] && $includeWarnings) {
                    $line .= " [OVERSIZED]";
                    $oversizedWarnings[] = $att['filename'];
                }
                $lines[] = $line;
            }
            $lines[] = "";
        }

        // Add warnings section if any
        if ($includeWarnings && !empty($oversizedWarnings)) {
            $limit = $this->formatSize($this->maxSizeBytes);
            $lines[] = "**Warnings:**";
            $lines[] = "The following attachment(s) exceed the {$limit} size limit and may not be fully processed:";
            foreach ($oversizedWarnings as $filename) {
                $lines[] = "- {$filename}";
            }
            $lines[] = "";
        }

        return implode("\n", $lines);
    }

    /**
     * Index a document attachment to the workspace's RAG system
     *
     * @param string $workspaceSlug Workspace identifier
     * @param array $attachment Attachment info from detectAttachments()
     * @param string|null $knowledgeBaseSlug Optional knowledge base slug (defaults to 'ceo-directives')
     * @return array Result with 'success', 'message', 'job_uid' (for async processing)
     */
    public function indexToRag(string $workspaceSlug, array $attachment, ?string $knowledgeBaseSlug = null): array {
        // Only index documents and images (not code or archives)
        if (!($attachment['is_indexable'] ?? false)) {
            return [
                'success' => false,
                'message' => "Attachment type '{$attachment['type']}' is not indexable",
                'skipped' => true
            ];
        }

        // Skip oversized attachments
        if ($attachment['is_oversized'] ?? false) {
            return [
                'success' => false,
                'message' => "Attachment '{$attachment['filename']}' exceeds size limit and cannot be indexed",
                'oversized' => true
            ];
        }

        try {
            // Download attachment content from Jira
            $contentUrl = $attachment['content_url'] ?? '';
            if (empty($contentUrl)) {
                return [
                    'success' => false,
                    'message' => 'No content URL available for attachment'
                ];
            }

            // Prepare the request to RAG service
            $kbSlug = $knowledgeBaseSlug ?? 'ceo-directives';

            // We send the URL to the RAG service and let it fetch the content
            // This avoids downloading large files through this service
            $result = $this->sendUrlToRagService(
                $workspaceSlug,
                $kbSlug,
                $contentUrl,
                $attachment['filename'],
                $attachment['mime_type']
            );

            return $result;

        } catch (\Exception $e) {
            $this->log('error', 'Failed to index attachment to RAG', [
                'filename' => $attachment['filename'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'RAG indexing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Index multiple attachments to RAG
     *
     * @param string $workspaceSlug Workspace identifier
     * @param array $attachments Array of attachment info
     * @param string|null $knowledgeBaseSlug Optional KB slug
     * @return array Summary with 'indexed', 'skipped', 'failed', 'details'
     */
    public function indexMultipleToRag(
        string $workspaceSlug,
        array $attachments,
        ?string $knowledgeBaseSlug = null
    ): array {
        $indexed = 0;
        $skipped = 0;
        $failed = 0;
        $details = [];

        foreach ($attachments as $attachment) {
            $result = $this->indexToRag($workspaceSlug, $attachment, $knowledgeBaseSlug);

            $details[] = [
                'filename' => $attachment['filename'] ?? 'unknown',
                'result' => $result
            ];

            if ($result['success'] ?? false) {
                $indexed++;
            } elseif ($result['skipped'] ?? false) {
                $skipped++;
            } else {
                $failed++;
            }
        }

        return [
            'indexed' => $indexed,
            'skipped' => $skipped,
            'failed' => $failed,
            'total' => count($attachments),
            'details' => $details
        ];
    }

    /**
     * Send URL to RAG service for ingestion
     *
     * @param string $workspaceId Workspace identifier
     * @param string $kbSlug Knowledge base slug
     * @param string $url Content URL to ingest
     * @param string $filename Original filename
     * @param string $mimeType MIME type
     * @return array Result from RAG service
     */
    private function sendUrlToRagService(
        string $workspaceId,
        string $kbSlug,
        string $url,
        string $filename,
        string $mimeType
    ): array {
        try {
            $client = new Client(['timeout' => 120]);

            $response = $client->post("{$this->ragServiceUrl}/api/ingest-url", [
                'json' => [
                    'url' => $url,
                    'tenant' => $workspaceId,
                    'kb_slug' => $kbSlug,
                    'filename' => $filename,
                    'mime_type' => $mimeType,
                    'source' => 'jira-attachment',
                    'async' => true
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            return $result ?? ['success' => false, 'message' => 'Invalid response from RAG service'];

        } catch (\Exception $e) {
            $this->log('warning', 'RAG service request failed', [
                'url' => $this->ragServiceUrl,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Format file size in human-readable format
     *
     * @param int $bytes Size in bytes
     * @return string Formatted size (e.g., "1.5 MB")
     */
    public function formatSize(int $bytes): string {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }
        return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }

    /**
     * Get configuration info for this service
     *
     * @return array Service configuration
     */
    public function getConfig(): array {
        return [
            'max_size_bytes' => $this->maxSizeBytes,
            'max_size_formatted' => $this->formatSize($this->maxSizeBytes),
            'rag_service_url' => $this->ragServiceUrl,
            'image_types' => self::IMAGE_TYPES,
            'document_types' => self::DOCUMENT_TYPES
        ];
    }

    /**
     * Get statistics about attachments
     *
     * @param array $attachments Array of attachment info from detectAttachments()
     * @return array Statistics about attachments
     */
    public function getStatistics(array $attachments): array {
        $totalSize = 0;
        $byType = [];
        $oversizedCount = 0;
        $indexableCount = 0;

        foreach ($attachments as $att) {
            $totalSize += (int)($att['size'] ?? 0);
            $type = $att['type'] ?? 'other';
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            if ($att['is_oversized'] ?? false) {
                $oversizedCount++;
            }
            if ($att['is_indexable'] ?? false) {
                $indexableCount++;
            }
        }

        return [
            'total_count' => count($attachments),
            'total_size' => $totalSize,
            'total_size_formatted' => $this->formatSize($totalSize),
            'by_type' => $byType,
            'oversized_count' => $oversizedCount,
            'indexable_count' => $indexableCount
        ];
    }

    /**
     * Log a message
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context
     */
    private function log(string $level, string $message, array $context = []): void {
        if (Flight::has('log')) {
            Flight::log()->{$level}($message, $context);
        }
    }
}
