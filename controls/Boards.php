<?php
/**
 * Boards Controller
 * Manages Jira boards for tracking and analysis
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\plugins\AtlassianAuth;
use \app\services\UserDatabaseService;

// Load plugins and services
require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';
require_once __DIR__ . '/../services/UserDatabaseService.php';

class Boards extends BaseControls\Control {

    private $userDb;

    /**
     * Initialize user database connection
     */
    private function initUserDb() {
        if (!$this->userDb && $this->member && !empty($this->member->ceobot_db)) {
            try {
                $this->userDb = new UserDatabaseService($this->member->id);
            } catch (Exception $e) {
                $this->logger->error('Failed to initialize user database: ' . $e->getMessage());
                return false;
            }
        }
        return $this->userDb !== null;
    }

    /**
     * List all tracked boards
     */
    public function index() {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->flash('error', 'User database not initialized');
            Flight::redirect('/dashboard');
            return;
        }

        $boards = $this->userDb->getBoards();
        $sites = AtlassianAuth::getConnectedSites($this->member->id);

        // Group boards by site
        $boardsBySite = [];
        foreach ($boards as $board) {
            $cloudId = $board['cloud_id'];
            if (!isset($boardsBySite[$cloudId])) {
                $boardsBySite[$cloudId] = [
                    'site' => null,
                    'boards' => []
                ];
                // Find site info
                foreach ($sites as $site) {
                    if ($site->cloud_id === $cloudId) {
                        $boardsBySite[$cloudId]['site'] = $site;
                        break;
                    }
                }
            }
            $boardsBySite[$cloudId]['boards'][] = $board;
        }

        $this->render('boards/index', [
            'title' => 'Jira Boards',
            'boards' => $boards,
            'boardsBySite' => $boardsBySite,
            'sites' => $sites,
            'hasAtlassian' => count($sites) > 0,
            'atlassianConfigured' => \app\plugins\AtlassianAuth::isConfigured()
        ]);
    }

    /**
     * Discover available boards from connected Jira sites
     */
    public function discover() {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->flash('error', 'User database not initialized');
            Flight::redirect('/dashboard');
            return;
        }

        $sites = AtlassianAuth::getConnectedSites($this->member->id);

        if (empty($sites)) {
            $this->flash('info', 'Please connect your Atlassian account first');
            Flight::redirect('/atlassian/connect');
            return;
        }

        // Fetch boards from each connected site
        $jiraBoards = [];
        $existingBoards = $this->userDb->getBoards();

        foreach ($sites as $site) {
            $token = AtlassianAuth::getValidToken($this->member->id, $site->cloud_id);
            if (!$token) {
                $jiraBoards[$site->cloud_id] = [];
                continue;
            }

            $boards = $this->fetchJiraBoards($site->cloud_id, $token);
            $jiraBoards[$site->cloud_id] = $boards;
        }

        $this->render('boards/discover', [
            'title' => 'Discover Jira Boards',
            'jiraBoards' => $jiraBoards,
            'existingBoards' => $existingBoards,
            'sites' => $sites
        ]);
    }

    /**
     * Add a board to tracking
     */
    public function add() {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->jsonError('User database not initialized');
            return;
        }

        $request = Flight::request();

        if ($request->method === 'POST') {
            $boardId = (int) $this->getParam('board_id');
            $cloudId = $this->getParam('cloud_id');
            $boardName = $this->getParam('board_name');
            $projectKey = $this->getParam('project_key');
            $boardType = $this->getParam('board_type') ?? 'scrum';

            if (!$boardId || !$cloudId || !$boardName || !$projectKey) {
                if ($request->ajax) {
                    $this->jsonError('Missing required fields');
                } else {
                    $this->flash('error', 'Missing required fields');
                    Flight::redirect('/boards/discover');
                }
                return;
            }

            // Check if already tracked
            $existing = $this->userDb->getBoardByJiraId($boardId, $cloudId);
            if ($existing) {
                if ($request->ajax) {
                    $this->jsonError('Board is already being tracked');
                } else {
                    $this->flash('warning', 'Board is already being tracked');
                    Flight::redirect('/boards');
                }
                return;
            }

            // Add the board
            $id = $this->userDb->addBoard([
                'board_id' => $boardId,
                'board_name' => $boardName,
                'project_key' => $projectKey,
                'cloud_id' => $cloudId,
                'board_type' => $boardType,
                'enabled' => 1,
                'digest_enabled' => 0,
                'status_filter' => 'To Do'
            ]);

            if ($id) {
                $this->logger->info('Board added', [
                    'member_id' => $this->member->id,
                    'board_id' => $boardId,
                    'board_name' => $boardName
                ]);

                if ($request->ajax) {
                    $this->jsonSuccess(['id' => $id], 'Board added successfully');
                } else {
                    $this->flash('success', "Board '{$boardName}' added successfully");
                    Flight::redirect('/boards');
                }
            } else {
                if ($request->ajax) {
                    $this->jsonError('Failed to add board');
                } else {
                    $this->flash('error', 'Failed to add board');
                    Flight::redirect('/boards/discover');
                }
            }
            return;
        }

        // GET request - redirect to discover
        Flight::redirect('/boards/discover');
    }

    /**
     * Edit board settings
     */
    public function edit($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->flash('error', 'User database not initialized');
            Flight::redirect('/boards');
            return;
        }

        // Board ID comes from URL: /boards/edit/{id}
        $id = $params['operation']->name ?? $this->getParam('id');
        if (!$id) {
            $this->flash('error', 'No board specified');
            Flight::redirect('/boards');
            return;
        }

        $board = $this->userDb->getBoard($id);
        if (!$board) {
            $this->flash('error', 'Board not found');
            Flight::redirect('/boards');
            return;
        }

        $request = Flight::request();

        if ($request->method === 'POST') {
            $data = [
                'enabled' => $this->getParam('enabled') ? 1 : 0,
                'digest_enabled' => $this->getParam('digest_enabled') ? 1 : 0,
                'digest_time' => $this->getParam('digest_time') ?? '08:00',
                'digest_cc' => trim($this->getParam('digest_cc') ?? ''),
                'timezone' => $this->getParam('timezone') ?? 'UTC',
                'status_filter' => $this->getParam('status_filter') ?? 'To Do'
            ];

            if ($this->userDb->updateBoard($id, $data)) {
                $this->flash('success', 'Board settings updated');
            } else {
                $this->flash('error', 'Failed to update board settings');
            }

            Flight::redirect('/boards');
            return;
        }

        // Get timezone list
        $timezones = \DateTimeZone::listIdentifiers();

        $this->render('boards/edit', [
            'title' => 'Edit Board - ' . $board['board_name'],
            'board' => $board,
            'timezones' => $timezones
        ]);
    }

    /**
     * Remove a board from tracking
     */
    public function remove($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->jsonError('User database not initialized');
            return;
        }

        // Board ID comes from URL: /boards/remove/{id}
        $id = $params['operation']->name ?? $this->getParam('id');
        if (!$id) {
            if (Flight::request()->ajax) {
                $this->jsonError('No board specified');
            } else {
                $this->flash('error', 'No board specified');
                Flight::redirect('/boards');
            }
            return;
        }

        $board = $this->userDb->getBoard($id);
        if (!$board) {
            if (Flight::request()->ajax) {
                $this->jsonError('Board not found');
            } else {
                $this->flash('error', 'Board not found');
                Flight::redirect('/boards');
            }
            return;
        }

        if ($this->userDb->removeBoard($id)) {
            $this->logger->info('Board removed', [
                'member_id' => $this->member->id,
                'board_id' => $id,
                'board_name' => $board['board_name']
            ]);

            if (Flight::request()->ajax) {
                $this->jsonSuccess([], "Board '{$board['board_name']}' removed");
            } else {
                $this->flash('success', "Board '{$board['board_name']}' removed");
                Flight::redirect('/boards');
            }
        } else {
            if (Flight::request()->ajax) {
                $this->jsonError('Failed to remove board');
            } else {
                $this->flash('error', 'Failed to remove board');
                Flight::redirect('/boards');
            }
        }
    }

    /**
     * Toggle board enabled status (AJAX)
     */
    public function toggle($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->jsonError('User database not initialized');
            return;
        }

        // Board ID comes from URL: /boards/toggle/{id}
        $id = $params['operation']->name ?? $this->getParam('id');
        if (!$id) {
            $this->jsonError('No board specified');
            return;
        }

        $newStatus = $this->userDb->toggleBoard($id);
        if ($newStatus !== false) {
            $this->jsonSuccess([
                'id' => $id,
                'enabled' => $newStatus
            ], $newStatus ? 'Board enabled' : 'Board disabled');
        } else {
            $this->jsonError('Failed to toggle board status');
        }
    }

    /**
     * Fetch boards from Jira API
     */
    private function fetchJiraBoards($cloudId, $accessToken) {
        $baseUrl = AtlassianAuth::getAgileApiBaseUrl($cloudId);
        $url = $baseUrl . '/board';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $this->logger->error('Failed to fetch Jira boards', [
                'http_code' => $httpCode,
                'cloud_id' => $cloudId,
                'url' => $url,
                'response' => $response
            ]);
            return [];
        }

        $data = json_decode($response, true);
        $this->logger->debug('Jira boards API response', [
            'cloud_id' => $cloudId,
            'total' => $data['total'] ?? 0,
            'values_count' => count($data['values'] ?? []),
            'raw_response' => substr($response, 0, 500)
        ]);
        $boards = [];

        if (isset($data['values'])) {
            foreach ($data['values'] as $board) {
                $boards[] = [
                    'id' => $board['id'],
                    'name' => $board['name'],
                    'type' => $board['type'] ?? 'scrum',
                    'project_key' => $board['location']['projectKey'] ?? '',
                    'project_name' => $board['location']['displayName'] ?? ''
                ];
            }
        }

        return $boards;
    }
}
