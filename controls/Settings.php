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
use \app\services\SubscriptionService;
use \app\services\TierFeatures;

require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';
require_once __DIR__ . '/../services/UserDatabaseService.php';
require_once __DIR__ . '/../services/ConnectionsService.php';

use \app\services\ConnectionsService;

class Settings extends BaseControls\Control {

    private $userDbConnected = false;

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
     * Settings index page - redirects to unified connections page
     */
    public function index() {
        // Redirect to consolidated settings/connections page
        Flight::redirect('/settings/connections');
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

        UserDatabaseService::setSetting('digest_enabled', $digestEnabled ? '1' : '0');

        $this->jsonSuccess([], 'Notification preferences updated');
    }

    /**
     * Subscription management page
     */
    public function subscription() {
        if (!$this->requireLogin()) return;

        $request = Flight::request();

        // Handle POST actions (upgrade/downgrade)
        if ($request->method === 'POST') {
            $action = $this->getParam('action');

            if ($action === 'upgrade') {
                SubscriptionService::stubUpgrade($this->member->id);
                $this->flash('success', 'Upgraded to Pro! Enjoy your new features.');
            } elseif ($action === 'downgrade') {
                SubscriptionService::stubDowngrade($this->member->id);
                $this->flash('info', 'Downgraded to Free tier.');
            }

            Flight::redirect('/settings/subscription');
            return;
        }

        // Get current subscription info
        $currentTier = SubscriptionService::getTier($this->member->id);
        $subscription = SubscriptionService::getSubscription($this->member->id);
        $tierInfo = SubscriptionService::getTierInfo($currentTier);

        // Get board count for context
        $boardCount = 0;
        if ($this->initUserDb()) {
            $boards = UserDatabaseService::getBoards();
            $boardCount = count($boards);
        }

        // Get feature access
        $features = TierFeatures::getFeatures($currentTier);
        $limits = TierFeatures::getLimits($currentTier);

        $this->render('settings/subscription', [
            'title' => 'Subscription',
            'currentTier' => $currentTier,
            'subscription' => $subscription,
            'tierInfo' => $tierInfo,
            'boardCount' => $boardCount,
            'features' => $features,
            'limits' => $limits
        ]);
    }

    /**
     * Unified connections management page
     * Shows all connected services (Atlassian, GitHub, Anthropic, Shopify, etc.)
     * Also includes profile, stats, and account actions (consolidated settings page)
     */
    public function connections() {
        if (!$this->requireLogin()) return;

        // Switch to user database FIRST before any queries
        $this->initUserDb();

        $connectionsService = new ConnectionsService($this->member->id);
        $connections = $connectionsService->getAllConnections();
        $summary = $connectionsService->getConnectionSummary();

        // Check AI Developer requirements
        $aiDevReady = $connectionsService->checkRequirements('ai_developer');

        // Get connected Atlassian sites for stats
        $sites = AtlassianAuth::getConnectedSites($this->member->id);

        // Get user stats
        $stats = UserDatabaseService::getStats();

        // Get subscription info - use SubscriptionService directly for reliability
        $tier = SubscriptionService::getTier($this->member->id);
        $tierInfo = SubscriptionService::getTierInfo($tier);

        // Get agent and shard counts (available to all users)
        $agentCount = Bean::count('aiagents', 'member_id = ?', [$this->member->id]);
        $shardCount = 0;

        // Shards are admin-level (not per-member)
        if ($this->member->level <= 50) {
            require_once __DIR__ . '/../services/ShardService.php';
            $allShards = \app\services\ShardService::getAllShards(false);
            $shardCount = count($allShards);
        }

        // Check if we should show onboarding wizard (all tiers)
        $gitConnected = $connections['github']['connected'] ?? false;
        $jiraConnected = $connections['atlassian']['connected'] ?? false;
        $repoCount = $connections['github']['details']['repo_count'] ?? 0;
        $boardCount = $stats['total_boards'] ?? 0;

        // Show onboarding if any step is incomplete AND user hasn't dismissed it
        $setupComplete = $gitConnected && $repoCount > 0 && $jiraConnected && $boardCount > 0;
        $wizardDismissedKey = 'onboarding_wizard_dismissed_' . $this->member->id;
        $wizardDismissed = UserDatabaseService::getSetting($wizardDismissedKey) === '1';
        $showOnboarding = !$setupComplete && !$wizardDismissed;

        $this->render('settings/connections', [
            'title' => 'Settings',
            'connections' => $connections,
            'summary' => $summary,
            'aiDevReady' => $aiDevReady,
            'tier' => $tier,
            'member' => $this->member,
            'sites' => $sites,
            'stats' => $stats,
            'tierInfo' => $tierInfo,
            'agentCount' => $agentCount,
            'shardCount' => $shardCount,
            'showOnboarding' => $showOnboarding,
            'gitConnected' => $gitConnected,
            'jiraConnected' => $jiraConnected,
            'repoCount' => $repoCount,
            'boardCount' => $boardCount
        ]);
    }

    /**
     * Dismiss onboarding wizard (AJAX)
     * Stores user preference to not auto-show the wizard
     */
    public function dismissWizard() {
        if (!$this->requireLogin()) return;

        // Store in enterprisesettings table (tenant database)
        // Use member-specific key so each user can have their own preference
        $key = 'onboarding_wizard_dismissed_' . $this->member->id;
        UserDatabaseService::setSetting($key, '1');

        $this->jsonSuccess([], 'Wizard dismissed');
    }

    /**
     * Reset onboarding wizard (AJAX)
     * Allows user to see the wizard again
     */
    public function resetWizard() {
        if (!$this->requireLogin()) return;

        $key = 'onboarding_wizard_dismissed_' . $this->member->id;
        UserDatabaseService::setSetting($key, '0');

        $this->jsonSuccess([], 'Wizard reset');
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
            $data['boards'] = UserDatabaseService::getBoards();
            $data['analyses'] = UserDatabaseService::getAllRecentAnalyses(100);
        }

        // Output as JSON download
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="myctobot_export_' . date('Y-m-d') . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }
}
