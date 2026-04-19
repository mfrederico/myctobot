<?php
/**
 * Dashboard Controller
 * Main dashboard for MyCTOBot users
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;
use \Exception as Exception;
use \app\plugins\AtlassianAuth;
use \app\services\UserDatabaseService;

require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';
require_once __DIR__ . '/../services/UserDatabaseService.php';

class Dashboard extends BaseControls\Control {

    /**
     * Main dashboard page - redirects to settings/connections
     */
    public function index() {
        if (!$this->requireLogin()) return;

        // Redirect to the main dashboard at /settings/connections
        Flight::redirect('/settings/connections');
        return;

        // Get connected Atlassian sites
        $sites = AtlassianAuth::getConnectedSites($this->member->id);

        // Get user database stats if available
        $userStats = null;
        $boards = [];
        $recentAnalyses = [];

        try {
            UserDatabaseService::connect($this->member->id);
            $userStats = UserDatabaseService::getStats();
            $boards = UserDatabaseService::getBoards();
            $recentAnalyses = UserDatabaseService::getAllRecentAnalyses(5);
            UserDatabaseService::restore();
        } catch (Exception $e) {
            $this->logger->error('Failed to load user data: ' . $e->getMessage());
        }

        // Get basic stats
        $stats = $this->getStats();
        $stats['user'] = $userStats;

        // Get current workspace for display
        $workspaceSlug = $_SESSION['workspace_slug'] ?? null;

        $this->render('dashboard/index', [
            'title' => 'Dashboard',
            'member' => $this->member,
            'sites' => $sites,
            'stats' => $stats,
            'boards' => $boards,
            'recentAnalyses' => $recentAnalyses,
            'hasAtlassian' => count($sites) > 0,
            'atlassianConfigured' => AtlassianAuth::isConfigured(),
            'workspaceSlug' => $workspaceSlug
        ]);
    }

    /**
     * Get basic stats for dashboard
     */
    private function getStats() {
        $stats = [];

        try {
            $member = Bean::load('member', $_SESSION['member']['id']);
            $stats['last_login'] = $member->last_login ?? 'Never';
            $stats['login_count'] = $member->login_count ?? 0;
            $stats['member_since'] = date('F j, Y', strtotime($member->created_at));

            // Admin stats
            if (Flight::hasLevel(LEVELS['ADMIN'])) {
                $stats['total_members'] = Bean::count('member');
                $stats['active_members'] = Bean::count('member', 'status = ?', ['active']);
            }

        } catch (\Exception $e) {
            Flight::get('log')->error('Dashboard stats error: ' . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Quick stats widget (AJAX)
     */
    public function stats() {
        if (!$this->requireLogin()) return;

        $stats = $this->getStats();

        $this->json([
            'success' => true,
            'stats' => $stats
        ]);
    }
}
