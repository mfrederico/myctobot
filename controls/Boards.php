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
require_once __DIR__ . '/../lib/Bean.php';

use \app\Bean;

class Boards extends BaseControls\Control {

    private $userDbConnected = false;

    /**
     * Initialize user database connection
     */
    private function initUserDb() {
        if (!$this->userDbConnected && $this->member && !empty($this->member->ceobot_db)) {
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
     * List all tracked boards
     */
    public function index() {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->flash('error', 'User database not initialized');
            Flight::redirect('/settings/connections');
            return;
        }

        // Optional cloud_id filter from query string
        $filterCloudId = $this->getParam('cloud_id');

        $boards = UserDatabaseService::getBoards();
        $sites = AtlassianAuth::getConnectedSites($this->member->id);

        // Build cloud_id -> site_name lookup
        $siteNames = [];
        $filterSiteName = null;
        foreach ($sites as $site) {
            $siteNames[$site->cloud_id] = $site->site_name ?? $site->site_url ?? null;
            if ($filterCloudId && $site->cloud_id === $filterCloudId) {
                $filterSiteName = $site->site_name;
            }
        }

        // Filter boards by cloud_id if specified
        if ($filterCloudId) {
            $boards = array_filter($boards, function($board) use ($filterCloudId) {
                return $board['cloud_id'] === $filterCloudId;
            });
        }

        // Enrich boards with site_name
        foreach ($boards as &$board) {
            $board['site_name'] = $siteNames[$board['cloud_id']] ?? null;
        }
        unset($board); // break reference

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
            'title' => $filterSiteName ? "Jira Boards - {$filterSiteName}" : 'Jira Boards',
            'boards' => $boards,
            'boardsBySite' => $boardsBySite,
            'sites' => $sites,
            'hasAtlassian' => count($sites) > 0,
            'atlassianConfigured' => \app\plugins\AtlassianAuth::isConfigured(),
            'filterCloudId' => $filterCloudId,
            'filterSiteName' => $filterSiteName
        ]);
    }

    /**
     * Discover available boards from connected Jira sites
     */
    public function discover() {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->flash('error', 'User database not initialized');
            Flight::redirect('/settings/connections');
            return;
        }

        // Optional cloud_id filter from query string
        $filterCloudId = $this->getParam('cloud_id');

        $sites = AtlassianAuth::getConnectedSites($this->member->id);

        if (empty($sites)) {
            $this->flash('info', 'Please connect your Atlassian account first');
            Flight::redirect('/atlassian/connect');
            return;
        }

        // Filter sites if cloud_id specified
        $filterSiteName = null;
        if ($filterCloudId) {
            $sites = array_filter($sites, function($site) use ($filterCloudId, &$filterSiteName) {
                if ($site->cloud_id === $filterCloudId) {
                    $filterSiteName = $site->site_name;
                    return true;
                }
                return false;
            });
        }

        // Fetch boards from each connected site (or just the filtered one)
        $jiraBoards = [];
        $existingBoards = UserDatabaseService::getBoards();

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
            'title' => $filterSiteName ? "Discover Boards - {$filterSiteName}" : 'Discover Jira Boards',
            'jiraBoards' => $jiraBoards,
            'existingBoards' => $existingBoards,
            'sites' => $sites,
            'filterCloudId' => $filterCloudId,
            'filterSiteName' => $filterSiteName
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
            $existing = UserDatabaseService::getBoardByJiraId($boardId, $cloudId);
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
            $id = UserDatabaseService::addBoard([
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

        $board = UserDatabaseService::getBoard($id);
        if (!$board) {
            $this->flash('error', 'Board not found');
            Flight::redirect('/boards');
            return;
        }

        // Enrich board with site_name from Atlassian token
        if (!empty($board['cloud_id'])) {
            $site = AtlassianAuth::getSiteByCloudId($this->member->id, $board['cloud_id']);
            $board['site_name'] = $site->site_name ?? $site->site_url ?? null;
        }

        $request = Flight::request();

        if ($request->method === 'POST') {
            // Handle status_filter - can be array (multi-select) or string (text fallback)
            $statusFilter = $this->getParam('status_filter');
            if (is_array($statusFilter)) {
                $statusFilter = implode(', ', array_filter($statusFilter));
            }

            $data = [
                'enabled' => $this->getParam('enabled') ? 1 : 0,
                'digest_enabled' => $this->getParam('digest_enabled') ? 1 : 0,
                'digest_time' => $this->getParam('digest_time') ?? '08:00',
                'digest_cc' => trim($this->getParam('digest_cc') ?? ''),
                'timezone' => $this->getParam('timezone') ?? 'UTC',
                'status_filter' => $statusFilter ?? '',
                // AI Developer status transition settings (Enterprise feature)
                'aidev_status_working' => trim($this->getParam('aidev_status_working') ?? '') ?: null,
                'aidev_status_pr_created' => trim($this->getParam('aidev_status_pr_created') ?? '') ?: null,
                'aidev_status_clarification' => trim($this->getParam('aidev_status_clarification') ?? '') ?: null,
                'aidev_status_failed' => trim($this->getParam('aidev_status_failed') ?? '') ?: null,
                'aidev_status_complete' => trim($this->getParam('aidev_status_complete') ?? '') ?: null,
                // Execution mode: NULL = local runner, integer = anthropickeys.id
                'aidev_anthropic_key_id' => $this->getParam('aidev_anthropic_key_id') ?: null
            ];

            // Handle priority weights (Pro feature)
            if ($this->member->isPro()) {
                $priorityWeights = [
                    'quick_wins' => [
                        'enabled' => (bool)$this->getParam('weight_quick_wins_enabled'),
                        'value' => (int)($this->getParam('weight_quick_wins') ?? 50)
                    ],
                    'synergy' => [
                        'enabled' => (bool)$this->getParam('weight_synergy_enabled'),
                        'value' => (int)($this->getParam('weight_synergy') ?? 30)
                    ],
                    'customer' => [
                        'enabled' => (bool)$this->getParam('weight_customer_enabled'),
                        'value' => (int)($this->getParam('weight_customer') ?? 70)
                    ],
                    'design' => [
                        'enabled' => (bool)$this->getParam('weight_design_enabled'),
                        'value' => (int)($this->getParam('weight_design') ?? 40)
                    ],
                    'tech_debt' => [
                        'enabled' => (bool)$this->getParam('weight_tech_debt_enabled'),
                        'value' => (int)($this->getParam('weight_tech_debt') ?? 20)
                    ],
                    'risk' => [
                        'enabled' => (bool)$this->getParam('weight_risk_enabled'),
                        'value' => (int)($this->getParam('weight_risk') ?? 50)
                    ]
                ];
                $data['priority_weights'] = json_encode($priorityWeights);

                // Handle engineering goals (Pro feature)
                $fteCount = $this->getParam('goal_fte_count') ? (float)$this->getParam('goal_fte_count') : null;
                $hoursPerDay = $this->getParam('goal_hours_per_day') ? (int)$this->getParam('goal_hours_per_day') : 8;
                $sprintDays = $this->getParam('goal_sprint_days') ? (int)$this->getParam('goal_sprint_days') : 10;
                $productivity = $this->getParam('goal_productivity') ? (int)$this->getParam('goal_productivity') : 70;

                // Calculate capacity if FTE count is provided
                $calculatedCapacity = null;
                if ($fteCount) {
                    $totalHours = $fteCount * $hoursPerDay * $sprintDays;
                    $calculatedCapacity = round($totalHours * ($productivity / 100));
                }

                $goals = [
                    'velocity' => $this->getParam('goal_velocity') ? (int)$this->getParam('goal_velocity') : null,
                    'debt_reduction' => $this->getParam('goal_debt_reduction') ? (int)$this->getParam('goal_debt_reduction') : null,
                    'predictability' => $this->getParam('goal_predictability') ? (int)$this->getParam('goal_predictability') : null,
                    'sprint_days' => $sprintDays,
                    'fte_count' => $fteCount,
                    'hours_per_day' => $hoursPerDay,
                    'productivity' => $productivity,
                    'capacity' => $calculatedCapacity,
                    'clarity_threshold' => $this->getParam('goal_clarity_threshold') ? (int)$this->getParam('goal_clarity_threshold') : 6
                ];
                $data['goals'] = json_encode($goals);
            }

            if (UserDatabaseService::updateBoard($id, $data)) {
                $this->flash('success', 'Board settings updated');
            } else {
                $this->flash('error', 'Failed to update board settings');
            }

            Flight::redirect('/boards');
            return;
        }

        // Get timezone list
        $timezones = \DateTimeZone::listIdentifiers();

        // Get recent analyses for this board
        $analyses = UserDatabaseService::getRecentAnalyses($id, 5);
        $lastAnalysis = !empty($analyses) ? $analyses[0] : null;

        // Fetch Jira statuses for this board's project (for Status Filter and AI Developer dropdowns)
        $jiraStatuses = [];
        if (!empty($board['cloud_id']) && !empty($board['project_key'])) {
            try {
                require_once __DIR__ . '/../services/JiraClient.php';
                $jiraClient = new \app\services\JiraClient($this->member->id, $board['cloud_id']);
                $jiraStatuses = $jiraClient->getProjectStatuses($board['project_key']);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to fetch Jira statuses: ' . $e->getMessage());
            }
        }

        // Fetch API keys for execution mode dropdown (Enterprise feature)
        $anthropicKeys = [];
        if ($this->member->isEnterprise()) {
            try {
                require_once __DIR__ . '/../services/EncryptionService.php';
                $keys = Bean::findAll('anthropickeys', ' ORDER BY name ASC ');
                foreach ($keys as $key) {
                    $decrypted = \app\services\EncryptionService::decrypt($key->api_key);
                    // Mask the key for display
                    $masked = preg_match('/^(sk-ant-api\d+-)(.+)$/', $decrypted, $m)
                        ? $m[1] . substr($m[2], 0, 3) . '...' . substr($m[2], -4)
                        : substr($decrypted, 0, 10) . '...';
                    $anthropicKeys[] = [
                        'id' => $key->id,
                        'name' => $key->name,
                        'model' => $key->model,
                        'masked_key' => $masked
                    ];
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to fetch API keys: ' . $e->getMessage());
            }
        }

        $this->render('boards/edit', [
            'title' => 'Edit Board - ' . $board['board_name'],
            'board' => $board,
            'timezones' => $timezones,
            'analyses' => $analyses,
            'lastAnalysis' => $lastAnalysis,
            'isPro' => $this->member->isPro(),
            'tier' => $this->member->getTier(),
            'isEnterprise' => $this->member->isEnterprise(),
            'jiraStatuses' => $jiraStatuses,
            'anthropicKeys' => $anthropicKeys
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

        $board = UserDatabaseService::getBoard($id);
        if (!$board) {
            if (Flight::request()->ajax) {
                $this->jsonError('Board not found');
            } else {
                $this->flash('error', 'Board not found');
                Flight::redirect('/boards');
            }
            return;
        }

        if (UserDatabaseService::removeBoard($id)) {
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

        $newStatus = UserDatabaseService::toggleBoard($id);
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
