<?php
/**
 * Settings Controller
 * User settings and preferences
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\plugins\AtlassianAuth;
use \app\services\UserDatabaseService;

require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';
require_once __DIR__ . '/../services/UserDatabaseService.php';

class Settings extends BaseControls\Control {

    private $userDb;

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
     * Settings index page
     */
    public function index() {
        if (!$this->requireLogin()) return;

        // Get connected Atlassian sites
        $sites = AtlassianAuth::getConnectedSites($this->member->id);

        // Get user stats
        $stats = [];
        if ($this->initUserDb()) {
            $stats = $this->userDb->getStats();
        }

        $this->render('settings/index', [
            'title' => 'Settings',
            'member' => $this->member,
            'sites' => $sites,
            'stats' => $stats,
            'atlassianConfigured' => AtlassianAuth::isConfigured()
        ]);
    }

    /**
     * Update profile
     */
    public function profile() {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method === 'GET') {
            $this->render('settings/profile', [
                'title' => 'Edit Profile',
                'member' => $this->member
            ]);
            return;
        }

        // Update profile on POST
        try {
            $member = R::load('member', $this->member->id);

            $displayName = $this->sanitize($this->getParam('display_name'));
            if (!empty($displayName)) {
                $member->display_name = $displayName;
            }

            R::store($member);

            $this->flash('success', 'Profile updated successfully');
            Flight::redirect('/settings');

        } catch (Exception $e) {
            $this->logger->error('Profile update failed: ' . $e->getMessage());
            $this->flash('error', 'Failed to update profile');
            Flight::redirect('/settings');
        }
    }

    /**
     * Update notification preferences (AJAX)
     */
    public function notifications() {
        if (!$this->requireLogin()) return;

        if (!$this->initUserDb()) {
            $this->jsonError('User database not initialized');
            return;
        }

        $digestEnabled = $this->getParam('digest_enabled') === 'true' || $this->getParam('digest_enabled') === '1';

        $this->userDb->setSetting('digest_enabled', $digestEnabled ? '1' : '0');

        $this->jsonSuccess([], 'Notification preferences updated');
    }

    /**
     * Export user data (GDPR compliance)
     */
    public function export() {
        if (!$this->requireLogin()) return;

        $data = [
            'member' => [
                'id' => $this->member->id,
                'email' => $this->member->email,
                'display_name' => $this->member->display_name,
                'created_at' => $this->member->created_at,
                'last_login' => $this->member->last_login
            ],
            'atlassian_sites' => [],
            'boards' => [],
            'analyses' => []
        ];

        // Get Atlassian sites
        $sites = AtlassianAuth::getConnectedSites($this->member->id);
        foreach ($sites as $site) {
            $data['atlassian_sites'][] = [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'cloud_id' => $site->cloud_id
            ];
        }

        // Get boards and analyses
        if ($this->initUserDb()) {
            $data['boards'] = $this->userDb->getBoards();
            $data['analyses'] = $this->userDb->getAllRecentAnalyses(100);
        }

        // Output as JSON download
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="myctobot_export_' . date('Y-m-d') . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }
}
