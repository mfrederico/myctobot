<?php
/**
 * Knowledge Base Controller
 * Manages documents for RAG chatbot integration
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\services\UserDatabaseService;
use \app\services\SubscriptionService;
use \app\services\TierFeatures;
use \app\services\LLMProviders\LLMProviderFactory;

require_once __DIR__ . '/../services/UserDatabaseService.php';
require_once __DIR__ . '/../services/LLMProviders/LLMProviderFactory.php';

class Knowledgebase extends BaseControls\Control {

    private $userDbConnected = false;
    private $ragServiceUrl;

    public function __construct() {
        parent::__construct();
        $this->ragServiceUrl = getenv('RAG_SERVICE_URL') ?: 'http://localhost:9501';
    }

    private function initUserDb() {
        if (!$this->userDbConnected && $this->member) {
            try {
                UserDatabaseService::connect($this->member->id);
                $this->userDbConnected = true;
            } catch (Exception $e) {
                $this->logger->error('Failed to initialize user database: ' . $e->getMessage());
                return false;
            }
        }
        return $this->userDbConnected;
    }

    /**
     * Get current tenant slug
     */
    private function getTenantSlug(): string {
        return $_SESSION['tenant_slug'] ?? 'default';
    }

    /**
     * Knowledge base index - list all documents for selected KB
     */
    public function index() {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->flash('error', 'Database not initialized');
            Flight::redirect('/dashboard');
            return;
        }

        // Get all knowledge bases
        try {
            $knowledgeBases = Bean::find('knowledgebases', ' ORDER BY name ASC ');
        } catch (Exception $e) {
            $knowledgeBases = [];
        }

        // Get selected KB (from query param or session)
        $selectedKbId = $this->getParam('kb') ?? $_SESSION['selected_kb'] ?? null;

        // If no KB selected but KBs exist, select the first one
        if (!$selectedKbId && !empty($knowledgeBases)) {
            $firstKb = reset($knowledgeBases);
            $selectedKbId = $firstKb->id;
        }

        // Store selection in session
        if ($selectedKbId) {
            $_SESSION['selected_kb'] = $selectedKbId;
        }

        // Get documents for selected KB
        $documents = [];
        $selectedKb = null;
        if ($selectedKbId && $selectedKbId !== 'undefined') {
            try {
                $selectedKb = Bean::load('knowledgebases', (int)$selectedKbId);
                if ($selectedKb->id) {
                    $documents = Bean::find('ragdocuments', ' knowledgebase_id = ? ORDER BY created_at DESC ', [$selectedKbId]);
                }
            } catch (Exception $e) {
                $documents = [];
            }
        }

        // Calculate storage usage (across all KBs)
        $usage = $this->getStorageUsage();

        // Get tier limits
        $tier = SubscriptionService::getTier($this->member->id);
        $limits = TierFeatures::getLimits($tier);
        $maxStorageMB = $limits['knowledge_base_mb'] ?? 50; // Default 50MB

        // Check if chat is available
        $chatAvailable = TierFeatures::hasFeature($tier, 'knowledge_base_chat');

        // Get all agent profiles for dropdown
        $agentProfiles = $this->getAgentProfiles();

        // Get agent info for selected KB
        $selectedAgent = null;
        if ($selectedKb && $selectedKb->agent_profile_id) {
            $selectedAgent = $this->getAgentInfo((int)$selectedKb->agent_profile_id);
        }

        $this->render('knowledgebase/index', [
            'title' => 'Knowledge Base',
            'knowledgeBases' => $knowledgeBases,
            'selectedKb' => $selectedKb,
            'selectedKbId' => $selectedKbId,
            'documents' => $documents,
            'usage' => $usage,
            'maxStorageMB' => $maxStorageMB,
            'chatAvailable' => $chatAvailable,
            'tier' => $tier,
            'tenantSlug' => $this->getTenantSlug(),
            'ragServiceUrl' => $this->ragServiceUrl,
            'agentProfiles' => $agentProfiles,
            'selectedAgent' => $selectedAgent
        ]);
    }

    /**
     * Create a new knowledge base
     */
    public function createkb() {
        if (!$this->requireLogin()) return;

        if (Flight::request()->method !== 'POST') {
            Flight::jsonError('Invalid request method', 405);
            return;
        }

        if (!$this->initUserDb()) {
            Flight::jsonError('Database not initialized');
            return;
        }

        $name = trim($this->getParam('name') ?? '');
        $description = trim($this->getParam('description') ?? '');
        $agentProfileId = $this->getParam('agent_profile_id');

        if (empty($name)) {
            Flight::jsonError('Name is required');
            return;
        }

        try {
            // Generate slug from name (for MCP/API access)
            $slug = $this->generateSlug($name);

            // Ensure slug is unique within tenant
            $existing = Bean::findOne('knowledgebases', ' slug = ? ', [$slug]);
            if ($existing) {
                $slug = $slug . '-' . time();
            }

            $kb = Bean::dispense('knowledgebases');
            $kb->name = $this->sanitize($name);
            $kb->slug = $slug;
            $kb->description = $this->sanitize($description);
            $kb->agent_profile_id = $agentProfileId ? (int)$agentProfileId : null;
            $kb->member_id = $this->member->id;
            $kb->created_at = date('Y-m-d H:i:s');
            $kb->updated_at = date('Y-m-d H:i:s');
            Bean::store($kb);

            Flight::jsonSuccess([
                'id' => $kb->id,
                'name' => $kb->name,
                'slug' => $kb->slug
            ], 'Knowledge base created');

        } catch (Exception $e) {
            $this->logger->error('Failed to create knowledge base: ' . $e->getMessage());
            Flight::jsonError('Failed to create knowledge base');
        }
    }

    /**
     * Delete a knowledge base and all its documents
     */
    public function deletekb() {
        if (!$this->requireLogin()) return;

        if (Flight::request()->method !== 'POST' && Flight::request()->method !== 'DELETE') {
            Flight::jsonError('Invalid request method', 405);
            return;
        }

        if (!$this->initUserDb()) {
            Flight::jsonError('Database not initialized');
            return;
        }

        $kbId = $this->getParam('id');
        if (empty($kbId)) {
            Flight::jsonError('Knowledge base ID required');
            return;
        }

        try {
            $kb = Bean::load('knowledgebases', $kbId);
            if (!$kb->id) {
                Flight::jsonError('Knowledge base not found', 404);
                return;
            }

            // Delete all documents in this KB
            $documents = Bean::find('ragdocuments', ' knowledgebase_id = ? ', [$kbId]);
            $tenantSlug = $this->getTenantSlug();
            foreach ($documents as $doc) {
                $this->deleteFromRagService($tenantSlug, $doc->filename);
                Bean::trash($doc);
            }

            // Delete the KB
            Bean::trash($kb);

            // Clear session if this was selected
            if (isset($_SESSION['selected_kb']) && $_SESSION['selected_kb'] == $kbId) {
                unset($_SESSION['selected_kb']);
            }

            Flight::jsonSuccess([], 'Knowledge base deleted');

        } catch (Exception $e) {
            $this->logger->error('Failed to delete knowledge base: ' . $e->getMessage());
            Flight::jsonError('Failed to delete knowledge base');
        }
    }

    /**
     * Upload document
     */
    public function upload() {
        if (!$this->requireLogin()) return;

        if (Flight::request()->method !== 'POST') {
            Flight::jsonError('Invalid request method', 405);
            return;
        }

        if (!$this->initUserDb()) {
            Flight::jsonError('Database not initialized');
            return;
        }

        // Validate file upload
        if (empty($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = $this->getUploadErrorMessage($_FILES['document']['error'] ?? -1);
            Flight::jsonError($errorMsg);
            return;
        }

        $file = $_FILES['document'];
        $tenantSlug = $this->getTenantSlug();

        // Check file size (use PHP's upload_max_filesize setting)
        $maxFileSize = $this->parseIniSize(ini_get('upload_max_filesize'));
        if ($file['size'] > $maxFileSize) {
            $maxMB = round($maxFileSize / 1024 / 1024);
            Flight::jsonError("File too large. Maximum size is {$maxMB}MB.");
            return;
        }

        // Check total storage limit
        $usage = $this->getStorageUsage();
        $tier = SubscriptionService::getTier($this->member->id);
        $limits = TierFeatures::getLimits($tier);
        $maxStorageMB = $limits['knowledge_base_mb'] ?? 50;

        if (($usage['total_bytes'] + $file['size']) > ($maxStorageMB * 1024 * 1024)) {
            Flight::jsonError("Storage limit exceeded. Maximum is {$maxStorageMB}MB. Please delete some documents first.");
            return;
        }

        // Validate file type
        $allowedTypes = ['pdf', 'docx', 'doc', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'html', 'htm'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedTypes)) {
            Flight::jsonError('Unsupported file type. Allowed: ' . implode(', ', $allowedTypes));
            return;
        }

        // Get knowledge base ID from request
        $kbId = $this->getParam('kb_id') ?? $_SESSION['selected_kb'] ?? null;
        if (!$kbId) {
            Flight::jsonError('Please select a knowledge base first');
            return;
        }

        // Verify KB exists
        try {
            $kb = Bean::load('knowledgebases', $kbId);
            if (!$kb->id) {
                Flight::jsonError('Knowledge base not found');
                return;
            }
        } catch (Exception $e) {
            Flight::jsonError('Knowledge base not found');
            return;
        }

        try {
            // Sanitize filename to match RAG service storage
            $safeFilename = $this->sanitizeFilename($file['name']);

            // Get vision config from KB's agent (or defaults)
            $visionConfig = $this->getVisionConfig($kb->agent_profile_id ? (int)$kb->agent_profile_id : null);

            // Create document record
            $doc = Bean::dispense('ragdocuments');
            $doc->knowledgebase_id = $kbId;
            $doc->member_id = $this->member->id;
            $doc->filename = $safeFilename;
            $doc->original_filename = $this->sanitize($file['name']); // Keep original for display
            $doc->file_size = $file['size'];
            $doc->mime_type = $file['type'];
            $doc->file_extension = $ext;
            $doc->status = 'processing'; // Async mode starts processing immediately
            $doc->vision_model = $visionConfig['vision_model']; // Track which model will be used for OCR
            $doc->created_at = date('Y-m-d H:i:s');
            Bean::store($doc);

            // Forward to RAG service for async processing
            $result = $this->sendToRagService(
                $doc->id,
                $file['tmp_name'],
                $tenantSlug,
                $doc->filename,
                $visionConfig,
                true // async mode
            );

            if ($result['success'] && !empty($result['job_id'])) {
                // Async mode - store job_id for polling
                $doc->job_id = $result['job_id'];
                Bean::store($doc);

                Flight::jsonSuccess([
                    'id' => $doc->id,
                    'filename' => $doc->filename,
                    'status' => 'processing',
                    'job_id' => $result['job_id'],
                    'vision_model' => $visionConfig['vision_model'],
                    'vision_source' => $visionConfig['source'],
                    'async' => true
                ], 'Document uploaded - processing in background');
            } elseif ($result['success']) {
                // Sync mode completed (fallback)
                $doc->status = 'ready';
                $doc->chunk_count = $result['chunk_count'] ?? 0;
                $doc->processed_at = date('Y-m-d H:i:s');
                Bean::store($doc);

                Flight::jsonSuccess([
                    'id' => $doc->id,
                    'filename' => $doc->filename,
                    'status' => $doc->status,
                    'chunk_count' => $doc->chunk_count ?? 0,
                    'vision_model' => $visionConfig['vision_model'],
                    'vision_source' => $visionConfig['source']
                ], 'Document uploaded and processed');
            } else {
                $doc->status = 'error';
                $doc->error_message = $result['error'] ?? 'Processing failed';
                Bean::store($doc);

                Flight::jsonError($result['error'] ?? 'Processing failed');
            }

        } catch (Exception $e) {
            $this->logger->error('Document upload failed: ' . $e->getMessage());
            Flight::jsonError('Upload failed: ' . $e->getMessage());
        }
    }

    /**
     * Poll job status for async document processing
     */
    public function polljob($jobId = null) {
        if (!$this->requireLogin()) return;

        $jobId = $jobId ?? $this->getParam('job_id');
        if (empty($jobId)) {
            Flight::jsonError('Job ID required');
            return;
        }

        if (!$this->initUserDb()) {
            Flight::jsonError('Database not initialized');
            return;
        }

        // Find document by job_id
        $doc = Bean::findOne('ragdocuments', ' job_id = ? ', [$jobId]);
        if (!$doc) {
            Flight::jsonError('Document not found for job', 404);
            return;
        }

        // Check job status from RAG service
        $result = $this->checkJobStatus($jobId);

        if ($result['success'] && !empty($result['job'])) {
            $job = $result['job'];

            if ($job['status'] === 'completed') {
                $doc->status = 'ready';
                $doc->chunk_count = $job['chunk_count'] ?? 0;
                $doc->processed_at = date('Y-m-d H:i:s');
                Bean::store($doc);

                Flight::jsonSuccess([
                    'status' => 'ready',
                    'chunk_count' => $doc->chunk_count,
                    'processed_at' => $doc->processed_at
                ]);
            } elseif ($job['status'] === 'failed') {
                $doc->status = 'error';
                $doc->error_message = $job['error'] ?? 'Processing failed';
                Bean::store($doc);

                Flight::jsonSuccess([
                    'status' => 'error',
                    'error' => $doc->error_message
                ]);
            } elseif ($job['status'] === 'queued') {
                // Queued - waiting for other documents to finish
                Flight::jsonSuccess([
                    'status' => 'queued',
                    'queue_position' => $job['queue_position'] ?? null
                ]);
            } else {
                // Still processing
                Flight::jsonSuccess([
                    'status' => 'processing'
                ]);
            }
        } else {
            Flight::jsonError($result['error'] ?? 'Failed to check job status');
        }
    }

    /**
     * Upload from URL
     */
    public function uploadurl() {
        if (!$this->requireLogin()) return;

        if (Flight::request()->method !== 'POST') {
            Flight::jsonError('Invalid request method', 405);
            return;
        }

        if (!$this->initUserDb()) {
            Flight::jsonError('Database not initialized');
            return;
        }

        $url = $this->getParam('url');
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            Flight::jsonError('Invalid URL');
            return;
        }

        // Get knowledge base ID from request
        $kbId = $this->getParam('kb_id') ?? $_SESSION['selected_kb'] ?? null;
        if (!$kbId) {
            Flight::jsonError('Please select a knowledge base first');
            return;
        }

        // Verify KB exists
        try {
            $kb = Bean::load('knowledgebases', $kbId);
            if (!$kb->id) {
                Flight::jsonError('Knowledge base not found');
                return;
            }
        } catch (Exception $e) {
            Flight::jsonError('Knowledge base not found');
            return;
        }

        $tenantSlug = $this->getTenantSlug();

        try {
            // Create document record
            $doc = Bean::dispense('ragdocuments');
            $doc->knowledgebase_id = $kbId;
            $doc->member_id = $this->member->id;
            $doc->filename = $this->sanitize(parse_url($url, PHP_URL_HOST) . '_' . date('Y-m-d'));
            $doc->source_url = $url;
            $doc->file_size = 0; // Unknown for URLs
            $doc->mime_type = 'text/html';
            $doc->file_extension = 'html';
            $doc->status = 'pending';
            $doc->created_at = date('Y-m-d H:i:s');
            Bean::store($doc);

            // Send URL to RAG service
            $result = $this->sendUrlToRagService($doc->id, $url, $tenantSlug);

            if ($result['success']) {
                $doc->status = 'ready';
                $doc->chunk_count = $result['chunk_count'] ?? 0;
                $doc->processed_at = date('Y-m-d H:i:s');
            } else {
                $doc->status = 'error';
                $doc->error_message = $result['error'] ?? 'Processing failed';
            }
            Bean::store($doc);

            Flight::jsonSuccess([
                'id' => $doc->id,
                'filename' => $doc->filename,
                'status' => $doc->status,
                'chunk_count' => $doc->chunk_count ?? 0
            ], $result['success'] ? 'URL imported successfully' : 'URL import failed');

        } catch (Exception $e) {
            $this->logger->error('URL upload failed: ' . $e->getMessage());
            Flight::jsonError('Import failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete a document
     */
    public function delete() {
        if (!$this->requireLogin()) return;

        if (Flight::request()->method !== 'POST' && Flight::request()->method !== 'DELETE') {
            Flight::jsonError('Invalid request method', 405);
            return;
        }

        if (!$this->initUserDb()) {
            Flight::jsonError('Database not initialized');
            return;
        }

        $docId = $this->getParam('id');
        if (empty($docId)) {
            Flight::jsonError('Document ID required');
            return;
        }

        try {
            $doc = Bean::load('ragdocuments', $docId);
            if (!$doc->id) {
                Flight::jsonError('Document not found', 404);
                return;
            }

            // Verify ownership
            if ($doc->member_id !== $this->member->id && $this->member->level > LEVELS['ADMIN']) {
                Flight::jsonError('Permission denied', 403);
                return;
            }

            $tenantSlug = $this->getTenantSlug();
            $filename = $doc->filename;

            // Delete from RAG service vector store
            $this->deleteFromRagService($tenantSlug, $filename);

            // Delete from database
            Bean::trash($doc);

            Flight::jsonSuccess([], 'Document deleted');

        } catch (Exception $e) {
            $this->logger->error('Document deletion failed: ' . $e->getMessage());
            Flight::jsonError('Delete failed: ' . $e->getMessage());
        }
    }

    /**
     * Get document status (for polling during processing)
     */
    public function status($id = null) {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            Flight::jsonError('Database not initialized');
            return;
        }

        $docId = $id ?? $this->getParam('id');
        if (empty($docId)) {
            Flight::jsonError('Document ID required');
            return;
        }

        try {
            $doc = Bean::load('ragdocuments', $docId);
            if (!$doc->id) {
                Flight::jsonError('Document not found', 404);
                return;
            }

            Flight::jsonSuccess([
                'id' => $doc->id,
                'filename' => $doc->filename,
                'status' => $doc->status,
                'chunk_count' => $doc->chunk_count ?? 0,
                'error_message' => $doc->error_message ?? null,
                'processed_at' => $doc->processed_at
            ]);

        } catch (Exception $e) {
            Flight::jsonError('Status check failed: ' . $e->getMessage());
        }
    }

    /**
     * Chat interface (embedded iframe or redirect)
     */
    public function chat() {
        if (!$this->requireLogin()) return;

        $tier = SubscriptionService::getTier($this->member->id);
        if (!TierFeatures::hasFeature($tier, 'knowledge_base_chat')) {
            $this->flash('warning', 'Chat feature requires an upgraded plan');
            Flight::redirect('/knowledgebase');
            return;
        }

        $tenantSlug = $this->getTenantSlug();

        $this->render('knowledgebase/chat', [
            'title' => 'Knowledge Base Chat',
            'tenantSlug' => $tenantSlug,
            'ragServiceUrl' => $this->ragServiceUrl,
            'chatUrl' => "{$this->ragServiceUrl}/chat/{$tenantSlug}"
        ]);
    }

    /**
     * Get storage usage statistics
     */
    private function getStorageUsage(): array {
        try {
            // Use Bean::find to query user database (R:: queries default database)
            $documents = Bean::find('ragdocuments');
            $totalBytes = 0;
            foreach ($documents as $doc) {
                $totalBytes += (int)($doc->file_size ?? 0);
            }
            $docCount = count($documents);
        } catch (Exception $e) {
            // Table doesn't exist yet - return zeros
            $totalBytes = 0;
            $docCount = 0;
        }

        return [
            'total_bytes' => (int)$totalBytes,
            'total_mb' => round($totalBytes / (1024 * 1024), 2),
            'document_count' => (int)$docCount
        ];
    }

    /**
     * Send file to RAG service for processing
     *
     * @param int $docId Document ID
     * @param string $filePath Path to uploaded file
     * @param string $tenantId Tenant identifier
     * @param string|null $originalFilename Original filename
     * @param array $visionConfig Optional vision OCR config (model, host)
     * @param bool $async Use async mode (returns immediately with job_id)
     */
    private function sendToRagService(int $docId, string $filePath, string $tenantId, string $originalFilename = null, array $visionConfig = [], bool $async = true): array {
        try {
            // Short timeout for async (just submission), longer for sync
            $timeout = $async ? 30 : 300;
            $client = new \GuzzleHttp\Client(['timeout' => $timeout]);

            $multipart = [
                [
                    'name' => 'file',
                    'contents' => fopen($filePath, 'r'),
                    'filename' => $originalFilename ?? basename($filePath)
                ],
                [
                    'name' => 'tenant_id',
                    'contents' => $tenantId
                ],
                [
                    'name' => 'doc_id',
                    'contents' => (string)$docId
                ],
                [
                    'name' => 'async',
                    'contents' => $async ? '1' : '0'
                ]
            ];

            // Add vision config if provided
            if (!empty($visionConfig['vision_model'])) {
                $multipart[] = [
                    'name' => 'vision_model',
                    'contents' => $visionConfig['vision_model']
                ];
            }
            if (!empty($visionConfig['ollama_host'])) {
                $multipart[] = [
                    'name' => 'ollama_host',
                    'contents' => $visionConfig['ollama_host']
                ];
            }

            $response = $client->post("{$this->ragServiceUrl}/api/ingest", [
                'multipart' => $multipart
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            return $result ?? ['success' => false, 'error' => 'Invalid response'];

        } catch (Exception $e) {
            $this->logger->error('RAG service request failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check async job status from RAG service
     */
    private function checkJobStatus(string $jobId): array {
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 10]);
            $response = $client->get("{$this->ragServiceUrl}/api/jobs/{$jobId}");
            $result = json_decode($response->getBody()->getContents(), true);
            return $result ?? ['success' => false, 'error' => 'Invalid response'];
        } catch (Exception $e) {
            $this->logger->error('Job status check failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get vision OCR config for a knowledge base
     * Uses agent's vision model if available, otherwise falls back to global defaults
     */
    private function getVisionConfig(?int $agentProfileId): array {
        // Default vision config from environment
        $config = [
            'vision_model' => getenv('OLLAMA_VISION_MODEL') ?: 'moondream:latest',
            'ollama_host' => getenv('OLLAMA_URL') ?: 'http://localhost:11434',
            'source' => 'default'
        ];

        if (!$agentProfileId) {
            return $config;
        }

        try {
            $agent = Bean::load('aiagents', $agentProfileId);
            if (!$agent->id) {
                return $config;
            }

            $provider = $agent->provider ?: 'claude_cli';
            $providerConfig = json_decode($agent->provider_config ?: '{}', true);

            // Get capabilities to check if agent has vision
            $capabilities = LLMProviderFactory::getCapabilities($provider, $providerConfig);

            if (!empty($capabilities['vision'])) {
                // Use agent's Ollama config for vision
                if (!empty($providerConfig['ollama_host'])) {
                    $config['ollama_host'] = $providerConfig['ollama_host'];
                }
                // For vision, we need a vision-capable model
                // If agent uses Ollama, check if it's a vision model
                if (!empty($providerConfig['ollama_model'])) {
                    $modelLower = strtolower($providerConfig['ollama_model']);
                    if (str_contains($modelLower, 'vision') ||
                        str_contains($modelLower, 'llava') ||
                        str_contains($modelLower, 'moondream')) {
                        $config['vision_model'] = $providerConfig['ollama_model'];
                        $config['source'] = 'agent:' . $agent->name;
                    }
                }
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to load agent vision config: ' . $e->getMessage());
        }

        return $config;
    }

    /**
     * Send URL to RAG service for processing
     */
    private function sendUrlToRagService(int $docId, string $url, string $tenantId): array {
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 300]); // 5 min for vision OCR

            $response = $client->post("{$this->ragServiceUrl}/api/ingest-url", [
                'json' => [
                    'url' => $url,
                    'tenant_id' => $tenantId,
                    'doc_id' => $docId
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            return $result ?? ['success' => false, 'error' => 'Invalid response'];

        } catch (Exception $e) {
            $this->logger->error('RAG service URL request failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete document from RAG service
     */
    private function deleteFromRagService(string $tenantId, string $filename): bool {
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 30]);

            $response = $client->delete("{$this->ragServiceUrl}/api/documents", [
                'json' => [
                    'tenant_id' => $tenantId,
                    'filename' => $filename
                ]
            ]);

            return $response->getStatusCode() === 200;

        } catch (Exception $e) {
            $this->logger->error('RAG service delete failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get upload error message
     */
    private function getUploadErrorMessage(int $errorCode): string {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form limit',
            UPLOAD_ERR_PARTIAL => 'File only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by extension'
        ];
        return $errors[$errorCode] ?? 'Unknown upload error';
    }

    /**
     * Generate URL-safe slug from name (for MCP/API access)
     */
    private function generateSlug(string $name): string {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'kb-' . time();
    }

    /**
     * Parse PHP ini size values (e.g., "100M", "2G") to bytes
     */
    private function parseIniSize(string $size): int {
        $size = trim($size);
        $unit = strtoupper(substr($size, -1));
        $value = (int) $size;

        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Update knowledge base settings
     */
    public function updatekb() {
        if (!$this->requireLogin()) return;

        if (Flight::request()->method !== 'POST') {
            Flight::jsonError('Invalid request method', 405);
            return;
        }

        if (!$this->initUserDb()) {
            Flight::jsonError('Database not initialized');
            return;
        }

        $kbId = $this->getParam('id');
        if (empty($kbId)) {
            Flight::jsonError('Knowledge base ID required');
            return;
        }

        try {
            $kb = Bean::load('knowledgebases', (int)$kbId);
            if (!$kb->id) {
                Flight::jsonError('Knowledge base not found', 404);
                return;
            }

            // Update fields if provided
            $name = $this->getParam('name');
            $description = $this->getParam('description');
            $agentProfileId = $this->getParam('agent_profile_id');

            if ($name !== null) {
                $kb->name = $this->sanitize(trim($name));
            }
            if ($description !== null) {
                $kb->description = $this->sanitize(trim($description));
            }
            if ($agentProfileId !== null) {
                $kb->agent_profile_id = $agentProfileId ? (int)$agentProfileId : null;
            }

            $kb->updated_at = date('Y-m-d H:i:s');
            Bean::store($kb);

            // Get updated agent info
            $agentInfo = null;
            if ($kb->agent_profile_id) {
                $agentInfo = $this->getAgentInfo((int)$kb->agent_profile_id);
            }

            Flight::jsonSuccess([
                'id' => $kb->id,
                'name' => $kb->name,
                'description' => $kb->description,
                'agent_profile_id' => $kb->agent_profile_id,
                'agent' => $agentInfo
            ], 'Knowledge base updated');

        } catch (Exception $e) {
            $this->logger->error('Failed to update knowledge base: ' . $e->getMessage());
            Flight::jsonError('Failed to update knowledge base');
        }
    }

    /**
     * RAG Query interface
     */
    public function query() {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->flash('error', 'Database not initialized');
            Flight::redirect('/knowledgebase');
            return;
        }

        $kbId = $this->getParam('kb') ?? $_SESSION['selected_kb'] ?? null;

        // Get all knowledge bases
        try {
            $knowledgeBases = Bean::find('knowledgebases', ' ORDER BY name ASC ');
        } catch (Exception $e) {
            $knowledgeBases = [];
        }

        // Get selected KB
        $selectedKb = null;
        $selectedAgent = null;
        if ($kbId) {
            try {
                $selectedKb = Bean::load('knowledgebases', (int)$kbId);
                if ($selectedKb->agent_profile_id) {
                    $selectedAgent = $this->getAgentInfo((int)$selectedKb->agent_profile_id);
                }
            } catch (Exception $e) {
                // Ignore
            }
        }

        $this->render('knowledgebase/query', [
            'title' => 'Query Knowledge Base',
            'knowledgeBases' => $knowledgeBases,
            'selectedKb' => $selectedKb,
            'selectedKbId' => $kbId,
            'selectedAgent' => $selectedAgent,
            'tenantSlug' => $this->getTenantSlug(),
            'ragServiceUrl' => $this->ragServiceUrl
        ]);
    }

    /**
     * Execute RAG query
     */
    public function executequery() {
        if (!$this->requireLogin()) return;

        if (Flight::request()->method !== 'POST') {
            Flight::jsonError('Invalid request method', 405);
            return;
        }

        if (!$this->initUserDb()) {
            Flight::jsonError('Database not initialized');
            return;
        }

        $kbId = $this->getParam('kb_id');
        $query = trim($this->getParam('query') ?? '');
        $options = [
            'similarity_threshold' => (float)($this->getParam('similarity_threshold') ?? 0.4),
            'max_results' => (int)($this->getParam('max_results') ?? 5),
            'include_metadata' => (bool)($this->getParam('include_metadata') ?? true),
        ];

        if (empty($kbId)) {
            Flight::jsonError('Knowledge base ID required');
            return;
        }

        if (empty($query)) {
            Flight::jsonError('Query is required');
            return;
        }

        try {
            $kb = Bean::load('knowledgebases', (int)$kbId);
            if (!$kb->id) {
                Flight::jsonError('Knowledge base not found', 404);
                return;
            }

            $tenantSlug = $this->getTenantSlug();

            // Send query to RAG service
            $result = $this->sendQueryToRagService($tenantSlug, $kb->slug, $query, $options);

            if ($result['success']) {
                // Map RAG service response fields to expected format
                // RAG returns: response, sources, context_used
                // Frontend expects: answer, results, sources
                Flight::jsonSuccess([
                    'query' => $query,
                    'answer' => $result['response'] ?? null,
                    'results' => $result['sources'] ?? [], // sources become the result chunks
                    'sources' => $result['sources'] ?? [],
                    'context_used' => $result['context_used'] ?? false,
                    'metadata' => [
                        'kb_name' => $kb->name,
                        'kb_slug' => $kb->slug,
                        'options' => $options
                    ]
                ]);
            } else {
                Flight::jsonError($result['error'] ?? 'Query failed');
            }

        } catch (Exception $e) {
            $this->logger->error('RAG query failed: ' . $e->getMessage());
            Flight::jsonError('Query failed: ' . $e->getMessage());
        }
    }

    /**
     * Send query to RAG service
     */
    private function sendQueryToRagService(string $tenantId, string $kbSlug, string $query, array $options): array {
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 60]);

            $response = $client->post("{$this->ragServiceUrl}/api/query", [
                'json' => [
                    'tenant_id' => $tenantId,
                    'kb_slug' => $kbSlug,
                    'query' => $query,
                    'similarity_threshold' => $options['similarity_threshold'],
                    'max_results' => $options['max_results'],
                    'include_metadata' => $options['include_metadata']
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            return $result ?? ['success' => false, 'error' => 'Invalid response'];

        } catch (Exception $e) {
            $this->logger->error('RAG service query failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get all agent profiles for dropdown
     */
    private function getAgentProfiles(): array {
        try {
            $agents = Bean::find('aiagents', ' is_active = 1 ORDER BY name ASC ');
            $profiles = [];

            foreach ($agents as $agent) {
                $provider = $agent->provider ?: 'claude_cli';
                $providerInfo = LLMProviderFactory::getProviderInfo($provider);
                $providerConfig = json_decode($agent->provider_config ?: '{}', true);

                $profiles[] = [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'description' => $agent->description,
                    'provider' => $provider,
                    'provider_label' => $providerInfo['name'] ?? $provider,
                    'model' => $providerConfig['model'] ?? null
                ];
            }

            return $profiles;
        } catch (Exception $e) {
            $this->logger->error('Failed to load agent profiles: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get detailed info for a specific agent
     */
    private function getAgentInfo(int $agentId): ?array {
        try {
            $agent = Bean::load('aiagents', $agentId);
            if (!$agent->id) {
                return null;
            }

            $provider = $agent->provider ?: 'claude_cli';
            $providerInfo = LLMProviderFactory::getProviderInfo($provider);
            $providerConfig = json_decode($agent->provider_config ?: '{}', true);
            $llmCapabilities = LLMProviderFactory::getCapabilities($provider, $providerConfig);

            return [
                'id' => $agent->id,
                'name' => $agent->name,
                'description' => $agent->description,
                'provider' => $provider,
                'provider_label' => $providerInfo['name'] ?? $provider,
                'provider_icon' => $providerInfo['icon'] ?? null,
                'model' => $providerConfig['model'] ?? null,
                'capabilities' => $llmCapabilities
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to load agent info: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Sanitize filename for safe storage
     * Removes special characters, replaces spaces with hyphens, lowercases
     */
    private function sanitizeFilename(string $filename): string {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $baseName = pathinfo($filename, PATHINFO_FILENAME);

        // Replace non-alphanumeric chars with hyphens
        $safeName = preg_replace('/[^a-z0-9]+/i', '-', $baseName);
        // Lowercase and trim hyphens
        $safeName = trim(strtolower($safeName), '-');
        // Collapse multiple hyphens
        $safeName = preg_replace('/-+/', '-', $safeName);

        if (empty($safeName)) {
            $safeName = 'document';
        }

        return $safeName . '.' . $ext;
    }
}
