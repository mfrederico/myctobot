<?php
/**
 * PluginSources Controller
 * Manages plugin repository sources for plugin discovery
 * Part of the Plugin Registry and Discovery epic
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\services\PluginRepositoryService;

require_once __DIR__ . '/../lib/Bean.php';
require_once __DIR__ . '/../services/PluginRepositoryService.php';

use \app\Bean;

class PluginSources extends BaseControls\Control {

    /**
     * List all plugin repository sources
     * GET /pluginsources
     */
    public function index() {
        if (!$this->requireLogin()) return;

        $memberId = $this->member->id;

        // Get all sources for this member
        $sources = Bean::findAll('pluginrepositorysource', 'member_id = ? ORDER BY created_at DESC', [$memberId]);

        // Convert beans to array for view
        $sourceList = [];
        foreach ($sources as $source) {
            $sourceList[] = [
                'id' => $source->id,
                'provider' => $source->provider,
                'repository_url' => $source->repository_url,
                'source_type' => $source->source_type,
                'validation_status' => $source->validation_status,
                'validation_error' => $source->validation_error,
                'last_validated_at' => $source->last_validated_at,
                'is_active' => (bool) $source->is_active,
                'description' => $source->description,
                'created_at' => $source->created_at,
                'updated_at' => $source->updated_at
            ];
        }

        $this->render('pluginsources/index', [
            'title' => 'Plugin Repository Sources',
            'sources' => $sourceList
        ]);
    }

    /**
     * Show form to add a new source
     * GET /pluginsources/add
     */
    public function add() {
        if (!$this->requireLogin()) return;

        $this->render('pluginsources/add', [
            'title' => 'Add Plugin Repository Source'
        ]);
    }

    /**
     * Store a new plugin repository source
     * POST /pluginsources/store
     */
    public function store() {
        if (!$this->requireLogin()) return;

        if (Flight::request()->method !== 'POST') {
            Flight::redirect('/pluginsources/add');
            return;
        }

        if (!$this->validateCSRF()) return;

        $memberId = $this->member->id;

        // Get and validate input
        $provider = $this->sanitize($this->getParam('provider'));
        $repositoryUrl = $this->sanitize($this->getParam('repository_url'));
        $sourceType = $this->sanitize($this->getParam('source_type')) ?: 'repository';
        $accessToken = $this->getParam('access_token'); // Don't sanitize tokens
        $description = $this->sanitize($this->getParam('description'));

        // Validate required fields
        if (empty($provider) || empty($repositoryUrl)) {
            $this->flash('error', 'Provider and repository URL are required.');
            Flight::redirect('/pluginsources/add');
            return;
        }

        // Validate provider
        $validProviders = ['github', 'gitlab', 'bitbucket', 'git'];
        if (!in_array($provider, $validProviders)) {
            $this->flash('error', 'Invalid provider. Supported providers: ' . implode(', ', $validProviders));
            Flight::redirect('/pluginsources/add');
            return;
        }

        // Check for duplicate
        $existing = Bean::findOne('pluginrepositorysource',
            'provider = ? AND repository_url = ? AND member_id = ?',
            [$provider, $repositoryUrl, $memberId]
        );

        if ($existing) {
            $this->flash('error', 'This repository source already exists.');
            Flight::redirect('/pluginsources/add');
            return;
        }

        try {
            // Create new source
            $source = Bean::dispense('pluginrepositorysource');
            $source->member_id = $memberId;
            $source->provider = $provider;
            $source->repository_url = $repositoryUrl;
            $source->source_type = $sourceType;
            $source->description = $description;
            $source->validation_status = 'pending';
            $source->is_active = 1;
            $source->created_at = date('Y-m-d H:i:s');

            // Encrypt and store access token if provided
            if (!empty($accessToken)) {
                require_once __DIR__ . '/../services/EncryptionService.php';
                $source->access_token = \app\services\EncryptionService::encrypt($accessToken);
            }

            Bean::store($source);

            // Attempt initial validation
            $service = new PluginRepositoryService();
            $validationResult = $service->validateSource($source);

            if ($validationResult['valid']) {
                $source->validation_status = 'valid';
                $source->validation_error = null;
            } else {
                $source->validation_status = 'error';
                $source->validation_error = $validationResult['error'];
            }
            $source->last_validated_at = date('Y-m-d H:i:s');
            $source->updated_at = date('Y-m-d H:i:s');
            Bean::store($source);

            $this->logger->info('Plugin repository source added', [
                'member_id' => $memberId,
                'provider' => $provider,
                'repository_url' => $repositoryUrl,
                'validation_status' => $source->validation_status
            ]);

            if ($validationResult['valid']) {
                $this->flash('success', 'Plugin repository source added and validated successfully.');
            } else {
                $this->flash('warning', 'Plugin repository source added but validation failed: ' . $validationResult['error']);
            }

            Flight::redirect('/pluginsources');

        } catch (Exception $e) {
            $this->logger->error('Failed to add plugin repository source', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to add plugin repository source: ' . $e->getMessage());
            Flight::redirect('/pluginsources/add');
        }
    }

    /**
     * Validate a plugin repository source
     * POST /pluginsources/validate/{id}
     */
    public function validate($params = []) {
        if (!$this->requireLogin()) return;

        $sourceId = $params['operation']->name ?? 0;
        $memberId = $this->member->id;

        if (empty($sourceId)) {
            Flight::jsonError('Source ID required', 400);
            return;
        }

        // Load and verify ownership
        $source = Bean::load('pluginrepositorysource', (int)$sourceId);
        if (!$source->id || $source->member_id != $memberId) {
            Flight::jsonError('Source not found', 404);
            return;
        }

        try {
            $service = new PluginRepositoryService();
            $validationResult = $service->validateSource($source);

            if ($validationResult['valid']) {
                $source->validation_status = 'valid';
                $source->validation_error = null;
            } else {
                $source->validation_status = 'error';
                $source->validation_error = $validationResult['error'];
            }
            $source->last_validated_at = date('Y-m-d H:i:s');
            $source->updated_at = date('Y-m-d H:i:s');
            Bean::store($source);

            $this->logger->info('Plugin repository source validated', [
                'source_id' => $sourceId,
                'validation_status' => $source->validation_status
            ]);

            Flight::jsonSuccess([
                'validation_status' => $source->validation_status,
                'validation_error' => $source->validation_error,
                'last_validated_at' => $source->last_validated_at
            ], $validationResult['valid'] ? 'Source validated successfully' : 'Validation failed');

        } catch (Exception $e) {
            $this->logger->error('Failed to validate plugin repository source', ['error' => $e->getMessage()]);
            Flight::jsonError('Validation failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a plugin repository source
     * POST /pluginsources/delete/{id}
     */
    public function delete($params = []) {
        if (!$this->requireLogin()) return;

        $sourceId = $params['operation']->name ?? 0;
        $memberId = $this->member->id;

        if (empty($sourceId)) {
            $this->flash('error', 'Source ID required');
            Flight::redirect('/pluginsources');
            return;
        }

        // Load and verify ownership
        $source = Bean::load('pluginrepositorysource', (int)$sourceId);
        if (!$source->id || $source->member_id != $memberId) {
            $this->flash('error', 'Source not found');
            Flight::redirect('/pluginsources');
            return;
        }

        try {
            $repositoryUrl = $source->repository_url;
            Bean::trash($source);

            $this->logger->info('Plugin repository source deleted', [
                'member_id' => $memberId,
                'source_id' => $sourceId,
                'repository_url' => $repositoryUrl
            ]);

            $this->flash('success', 'Plugin repository source deleted successfully.');
        } catch (Exception $e) {
            $this->logger->error('Failed to delete plugin repository source', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to delete source: ' . $e->getMessage());
        }

        Flight::redirect('/pluginsources');
    }

    /**
     * Toggle a plugin repository source active/inactive
     * POST /pluginsources/toggle/{id}
     */
    public function toggle($params = []) {
        if (!$this->requireLogin()) return;

        $sourceId = $params['operation']->name ?? 0;
        $memberId = $this->member->id;

        if (empty($sourceId)) {
            Flight::jsonError('Source ID required', 400);
            return;
        }

        // Load and verify ownership
        $source = Bean::load('pluginrepositorysource', (int)$sourceId);
        if (!$source->id || $source->member_id != $memberId) {
            Flight::jsonError('Source not found', 404);
            return;
        }

        try {
            $source->is_active = $source->is_active ? 0 : 1;
            $source->updated_at = date('Y-m-d H:i:s');
            Bean::store($source);

            $this->logger->info('Plugin repository source toggled', [
                'source_id' => $sourceId,
                'is_active' => $source->is_active
            ]);

            Flight::jsonSuccess([
                'is_active' => (bool) $source->is_active
            ], $source->is_active ? 'Source enabled' : 'Source disabled');

        } catch (Exception $e) {
            $this->logger->error('Failed to toggle plugin repository source', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to toggle source: ' . $e->getMessage(), 500);
        }
    }
}
