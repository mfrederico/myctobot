<?php
/**
 * Plugin Registry Controller
 *
 * Manages plugin discovery, scanning, and viewing.
 * Provides endpoints for listing discovered plugins and triggering scans.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\services\PluginScannerService;
use \app\services\UserDatabaseService;

require_once __DIR__ . '/../lib/Bean.php';
require_once __DIR__ . '/../services/PluginScannerService.php';
require_once __DIR__ . '/../services/UserDatabaseService.php';

class PluginRegistry extends BaseControls\Control {

    private $userDbConnected = false;

    /**
     * Initialize user database connection
     */
    private function initUserDb(): bool {
        if (!$this->userDbConnected && $this->member && !empty($this->member->ceobot_db)) {
            try {
                UserDatabaseService::connect($this->member->id);
                $this->userDbConnected = true;
            } catch (\Exception $e) {
                $this->logger->error('Failed to initialize user database: ' . $e->getMessage());
                return false;
            }
        }
        return $this->userDbConnected;
    }

    /**
     * GET /plugins - List all discovered plugins
     */
    public function index(): void {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->flash('error', 'User database not initialized');
            Flight::redirect('/settings/connections');
            return;
        }

        try {
            $status = $this->getParam('status');
            $scanner = new PluginScannerService();

            $plugins = $scanner->getDiscoveredPlugins($status);

            // Get status counts for filters
            $statusCounts = [
                'discovered' => Bean::count('discoveredplugins', 'status = ?', ['discovered']),
                'validated' => Bean::count('discoveredplugins', 'status = ?', ['validated']),
                'invalid' => Bean::count('discoveredplugins', 'status = ?', ['invalid']),
                'installed' => Bean::count('discoveredplugins', 'status = ?', ['installed'])
            ];

            // Get repository connections for context
            $repos = Bean::find('repoconnections', 'enabled = ?', [1]);
            $repoCount = count($repos);

            // Get recent scans
            $recentScans = $scanner->getScanHistory(5);

            if (Flight::request()->ajax) {
                $this->jsonSuccess([
                    'plugins' => $plugins,
                    'status_counts' => $statusCounts,
                    'repo_count' => $repoCount,
                    'recent_scans' => $recentScans
                ]);
            } else {
                $this->render('plugins/index', [
                    'title' => 'Plugin Registry',
                    'plugins' => $plugins,
                    'statusCounts' => $statusCounts,
                    'repoCount' => $repoCount,
                    'recentScans' => $recentScans,
                    'currentStatus' => $status
                ]);
            }

        } catch (\Exception $e) {
            $this->handleException($e, 'Failed to load plugins');
        }
    }

    /**
     * POST /plugins/scan - Trigger a full scan of all repositories
     */
    public function scan(): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;

        if (!$this->initUserDb()) {
            $this->jsonError('User database not initialized');
            return;
        }

        try {
            $scanner = new PluginScannerService();
            $result = $scanner->scanAllSources();

            $this->logger->info('Plugin scan triggered', [
                'member_id' => $this->member->id,
                'scan_id' => $result['scan_id'],
                'repos_scanned' => $result['repos_scanned'],
                'plugins_found' => $result['plugins_found']
            ]);

            $this->jsonSuccess([
                'scan_id' => $result['scan_id'],
                'repos_scanned' => $result['repos_scanned'],
                'plugins_found' => $result['plugins_found'],
                'errors_encountered' => $result['errors_encountered'],
                'plugins' => $result['plugins']
            ], "Scan completed: {$result['plugins_found']} plugins found in {$result['repos_scanned']} repositories");

        } catch (\Exception $e) {
            $this->logger->error('Plugin scan failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->jsonError('Scan failed: ' . $e->getMessage());
        }
    }

    /**
     * POST /plugins/scan/{id} - Scan a single repository
     */
    public function scanRepo($params = []): void {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;

        if (!$this->initUserDb()) {
            $this->jsonError('User database not initialized');
            return;
        }

        $repoId = $params['operation']->name ?? $this->getParam('id');

        if (!$repoId || !is_numeric($repoId)) {
            $this->jsonError('Invalid repository ID');
            return;
        }

        try {
            $scanner = new PluginScannerService();
            $result = $scanner->scanRepository((int)$repoId);

            $this->logger->info('Single repo scan triggered', [
                'member_id' => $this->member->id,
                'repo_id' => $repoId,
                'scan_id' => $result['scan_id'],
                'plugins_found' => $result['plugins_found']
            ]);

            if ($result['plugins_found'] > 0) {
                $message = "Plugin found: {$result['plugins'][0]['name']}";
            } else {
                $message = "No plugin.json found in repository";
            }

            $this->jsonSuccess([
                'scan_id' => $result['scan_id'],
                'repos_scanned' => $result['repos_scanned'],
                'plugins_found' => $result['plugins_found'],
                'plugins' => $result['plugins']
            ], $message);

        } catch (\Exception $e) {
            $this->logger->error('Single repo scan failed', [
                'repo_id' => $repoId,
                'error' => $e->getMessage()
            ]);

            $this->jsonError('Scan failed: ' . $e->getMessage());
        }
    }

    /**
     * GET /plugins/scans - List scan history
     */
    public function scans(): void {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            if (Flight::request()->ajax) {
                $this->jsonError('User database not initialized');
            } else {
                $this->flash('error', 'User database not initialized');
                Flight::redirect('/settings/connections');
            }
            return;
        }

        try {
            $limit = (int)($this->getParam('limit') ?? 20);
            $limit = min($limit, 100); // Cap at 100

            $scanner = new PluginScannerService();
            $scans = $scanner->getScanHistory($limit);

            if (Flight::request()->ajax) {
                $this->jsonSuccess(['scans' => $scans]);
            } else {
                $this->render('plugins/scans', [
                    'title' => 'Plugin Scan History',
                    'scans' => $scans
                ]);
            }

        } catch (\Exception $e) {
            $this->handleException($e, 'Failed to load scan history');
        }
    }

    /**
     * GET /plugins/{id} - View plugin details
     */
    public function view($params = []): void {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->flash('error', 'User database not initialized');
            Flight::redirect('/plugins');
            return;
        }

        $pluginId = $params['operation']->name ?? $this->getParam('id');

        if (!$pluginId || !is_numeric($pluginId)) {
            $this->flash('error', 'Invalid plugin ID');
            Flight::redirect('/plugins');
            return;
        }

        try {
            $scanner = new PluginScannerService();
            $plugin = $scanner->getPlugin((int)$pluginId);

            if (!$plugin) {
                $this->flash('error', 'Plugin not found');
                Flight::redirect('/plugins');
                return;
            }

            // Get the associated repository connection
            $repo = Bean::load('repoconnections', $plugin['repo_connection_id']);

            if (Flight::request()->ajax) {
                $this->jsonSuccess([
                    'plugin' => $plugin,
                    'repo' => $repo ? $repo->export() : null
                ]);
            } else {
                $this->render('plugins/view', [
                    'title' => 'Plugin: ' . ($plugin['plugin_name'] ?? 'Unknown'),
                    'plugin' => $plugin,
                    'repo' => $repo ? $repo->export() : null
                ]);
            }

        } catch (\Exception $e) {
            $this->handleException($e, 'Failed to load plugin details');
        }
    }
}
