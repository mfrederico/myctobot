<?php
/**
 * Atlassian Controller
 * Handles Atlassian OAuth 2.0 connection and management
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\plugins\AtlassianAuth;

// Load Atlassian Auth plugin
require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';

class Atlassian extends BaseControls\Control {

    /**
     * Show Atlassian connection status and options
     */
    public function index() {
        if (!$this->requireLogin()) return;

        $sites = AtlassianAuth::getConnectedSites($this->member->id);

        $this->render('atlassian/index', [
            'title' => 'Atlassian Connection',
            'sites' => $sites,
            'atlassianConfigured' => AtlassianAuth::isConfigured()
        ]);
    }

    /**
     * Start Atlassian OAuth flow
     */
    public function connect() {
        if (!$this->requireLogin()) return;

        try {
            if (!AtlassianAuth::isConfigured()) {
                $this->flash('error', 'Atlassian integration is not configured');
                Flight::redirect('/settings');
                return;
            }

            $loginUrl = AtlassianAuth::getLoginUrl();
            Flight::redirect($loginUrl);

        } catch (Exception $e) {
            $this->logger->error('Atlassian auth redirect failed: ' . $e->getMessage());
            $this->flash('error', 'Could not connect to Atlassian. Please try again.');
            Flight::redirect('/settings');
        }
    }

    /**
     * Handle Atlassian OAuth callback
     */
    public function callback() {
        if (!$this->requireLogin()) return;

        try {
            $code = $this->getParam('code');
            $state = $this->getParam('state');
            $error = $this->getParam('error');
            $errorDescription = $this->getParam('error_description');

            // Check for errors from Atlassian
            if ($error) {
                $this->logger->warning('Atlassian OAuth error', [
                    'error' => $error,
                    'description' => $errorDescription
                ]);
                $this->flash('error', 'Atlassian connection was cancelled or failed: ' . ($errorDescription ?? $error));
                Flight::redirect('/settings');
                return;
            }

            if (empty($code)) {
                $this->flash('error', 'Invalid Atlassian login response');
                Flight::redirect('/settings');
                return;
            }

            // Handle the callback and store tokens
            $resources = AtlassianAuth::handleCallback($code, $state, $this->member->id);

            if (!$resources) {
                $this->flash('error', 'Could not authenticate with Atlassian. Please try again.');
                Flight::redirect('/settings');
                return;
            }

            // Update session with new member data
            $member = R::load('member', $this->member->id);
            $_SESSION['member'] = $member->export();

            $siteCount = count($resources);
            $siteNames = array_map(function($r) { return $r['name']; }, $resources);

            $this->logger->info('Atlassian connected', [
                'member_id' => $this->member->id,
                'sites' => $siteNames
            ]);

            $this->flash('success', "Connected to {$siteCount} Atlassian site(s): " . implode(', ', $siteNames));
            Flight::redirect('/boards/discover');

        } catch (Exception $e) {
            $this->handleException($e, 'Atlassian connection failed');
            Flight::redirect('/settings');
        }
    }

    /**
     * Disconnect a specific Atlassian site
     */
    public function disconnect($params = []) {
        if (!$this->requireLogin()) return;

        try {
            // Cloud ID comes from URL: /atlassian/disconnect/{cloud_id}
            $cloudId = $params['operation']->name ?? $this->getParam('cloud_id');

            if (empty($cloudId)) {
                if (Flight::request()->ajax) {
                    $this->jsonError('No site specified');
                } else {
                    $this->flash('error', 'No site specified');
                    Flight::redirect('/atlassian');
                }
                return;
            }

            $success = AtlassianAuth::disconnect($this->member->id, $cloudId);

            if (Flight::request()->ajax) {
                if ($success) {
                    $this->jsonSuccess([], 'Atlassian site disconnected');
                } else {
                    $this->jsonError('Could not disconnect site');
                }
            } else {
                if ($success) {
                    $this->flash('success', 'Atlassian site disconnected');
                } else {
                    $this->flash('error', 'Could not disconnect site');
                }
                Flight::redirect('/atlassian');
            }

        } catch (Exception $e) {
            $this->handleException($e, 'Disconnect failed');
            Flight::redirect('/settings');
        }
    }

    /**
     * Disconnect all Atlassian sites
     */
    public function disconnectall() {
        if (!$this->requireLogin()) return;

        try {
            $count = AtlassianAuth::disconnectAll($this->member->id);

            if ($count > 0) {
                $this->flash('success', "Disconnected {$count} Atlassian site(s)");
            } else {
                $this->flash('info', 'No Atlassian sites were connected');
            }

            Flight::redirect('/settings');

        } catch (Exception $e) {
            $this->handleException($e, 'Disconnect failed');
            Flight::redirect('/settings');
        }
    }

    /**
     * Refresh tokens (AJAX endpoint)
     */
    public function refresh($params = []) {
        if (!$this->requireLogin()) return;

        try {
            // Cloud ID comes from URL: /atlassian/refresh/{cloud_id}
            $cloudId = $params['operation']->name ?? $this->getParam('cloud_id');

            if (empty($cloudId)) {
                $this->jsonError('No cloud ID specified', 400);
                return;
            }

            $success = AtlassianAuth::refreshToken($this->member->id, $cloudId);

            if ($success) {
                $this->jsonSuccess(['refreshed' => true], 'Token refreshed successfully');
            } else {
                $this->jsonError('Could not refresh token. Please reconnect.', 401);
            }

        } catch (Exception $e) {
            $this->logger->error('Token refresh failed: ' . $e->getMessage());
            $this->jsonError('Token refresh failed', 500);
        }
    }

    /**
     * List connected resources (AJAX endpoint)
     */
    public function resources() {
        if (!$this->requireLogin()) return;

        try {
            $sites = AtlassianAuth::getConnectedSites($this->member->id);

            $resources = [];
            foreach ($sites as $site) {
                $resources[] = [
                    'cloud_id' => $site->cloud_id,
                    'site_url' => $site->site_url,
                    'site_name' => $site->site_name,
                    'connected_at' => $site->created_at,
                    'expires_at' => $site->expires_at
                ];
            }

            $this->jsonSuccess(['resources' => $resources]);

        } catch (Exception $e) {
            $this->logger->error('Resources fetch failed: ' . $e->getMessage());
            $this->jsonError('Could not fetch resources', 500);
        }
    }

    /**
     * Check connection status (AJAX endpoint)
     */
    public function status() {
        if (!$this->requireLogin()) return;

        try {
            $sites = AtlassianAuth::getConnectedSites($this->member->id);
            $connected = count($sites) > 0;

            $status = [
                'connected' => $connected,
                'site_count' => count($sites),
                'configured' => AtlassianAuth::isConfigured()
            ];

            if ($connected) {
                $status['sites'] = array_map(function($site) {
                    $expired = strtotime($site->expires_at) < time();
                    return [
                        'cloud_id' => $site->cloud_id,
                        'site_name' => $site->site_name,
                        'site_url' => $site->site_url,
                        'expired' => $expired
                    ];
                }, array_values($sites));
            }

            $this->jsonSuccess($status);

        } catch (Exception $e) {
            $this->logger->error('Status check failed: ' . $e->getMessage());
            $this->jsonError('Could not check status', 500);
        }
    }
}
