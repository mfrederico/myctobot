<?php
namespace app;

use \Flight as Flight;
use \app\Bean;
use \RedBeanPHP\R as R; // Keep for getDatabaseAdapter()
use \Exception as Exception;
use app\BaseControls\Control;

class Admin extends Control {
    
    const ROOT_LEVEL = 1;
    const ADMIN_LEVEL = 50;
    const MEMBER_LEVEL = 100;
    const PUBLIC_LEVEL = 101;

    public function __construct() {
        parent::__construct();
        
        // Check if user is logged in
        if (!Flight::isLoggedIn()) {
            Flight::redirect('/auth/login?redirect=' . urlencode(Flight::request()->url));
            exit;
        }
        
        // Check if user has admin level
        if ($this->member->level > self::ADMIN_LEVEL) {
            $this->logger->warning('Unauthorized admin access attempt', [
                'member_id' => $this->member->id,
                'member_level' => $this->member->level,
                'ip' => Flight::request()->ip
            ]);
            Flight::redirect('/');
            exit;
        }
    }

    /**
     * Admin dashboard
     */
    public function index($params = []) {
        $this->viewData['title'] = 'Admin Dashboard';

        // Get system stats
        $this->viewData['stats'] = [
            'members' => Bean::count('member'),
            'permissions' => Bean::count('authcontrol'),
            'active_sessions' => $this->getActiveSessions(),
        ];

        // Get cache stats for dashboard (using consistent field names)
        $this->viewData['cache_stats'] = \app\PermissionCache::getStats();

        $this->render('admin/index', $this->viewData);
    }

    /**
     * Member management
     */
    public function members($params = []) {
        $this->viewData['title'] = 'Member Management';
        
        $request = Flight::request();
        
        // Handle delete action
        if ($request->query->delete && is_numeric($request->query->delete)) {
            $this->deleteMember($request->query->delete);
            Flight::redirect('/admin/members');
            return;
        }
        
        // Handle bulk actions
        if ($request->method === 'POST' && !empty($request->data->bulk_action) && !empty($request->data->selected_members)) {
            if (Flight::csrf()->validateRequest()) {
                $this->handleBulkAction($request->data->bulk_action, $request->data->selected_members);
                Flight::redirect('/admin/members');
                return;
            }
        }
        
        // Get all members
        $this->viewData['members'] = Bean::findAll('member', 'ORDER BY created_at DESC');
        
        $this->render('admin/members', $this->viewData);
    }

    /**
     * Edit member
     */
    public function editmember($params = []) {
        $request = Flight::request();
        $memberId = $request->query->id ?? null;
        
        if (!$memberId) {
            Flight::redirect('/admin/members');
            return;
        }
        
        $member = Bean::load('member', $memberId);
        if (!$member->id) {
            Flight::redirect('/admin/members');
            return;
        }
        
        if ($request->method === 'POST') {
            // Validate CSRF
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
                // Validate input
                $username = trim($request->data->username ?? '');
                $email = trim($request->data->email ?? '');
                $level = intval($request->data->level ?? $member->level);
                $status = $request->data->status ?? $member->status;
                
                if (empty($username)) {
                    $this->viewData['error'] = 'Username is required';
                } elseif (empty($email)) {
                    $this->viewData['error'] = 'Email is required';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->viewData['error'] = 'Invalid email format';
                } elseif (strlen($username) < 3) {
                    $this->viewData['error'] = 'Username must be at least 3 characters long';
                } else {
                    // Check for duplicate username/email (excluding current member)
                    $existingUsername = Bean::findOne('member', 'username = ? AND id != ?', [$username, $member->id]);
                    $existingEmail = Bean::findOne('member', 'email = ? AND id != ?', [$email, $member->id]);
                    
                    if ($existingUsername) {
                        $this->viewData['error'] = 'Username already exists';
                    } elseif ($existingEmail) {
                        $this->viewData['error'] = 'Email already exists';
                    } else {
                        // Update member
                        $member->username = $username;
                        $member->email = $email;
                        $member->level = $level;
                        $member->status = $status;
                        
                        // Update password if provided
                        if (!empty($request->data->password)) {
                            if (strlen($request->data->password) < 8) {
                                $this->viewData['error'] = 'Password must be at least 8 characters long';
                            } else {
                                $member->password = password_hash($request->data->password, PASSWORD_DEFAULT);
                            }
                        }
                        
                        if (empty($this->viewData['error'])) {
                            $member->updated_at = date('Y-m-d H:i:s');
                            
                            try {
                                Bean::store($member);
                                $this->viewData['success'] = 'Member updated successfully';
                                $this->logger->info('Member updated by admin', [
                                    'member_id' => $member->id,
                                    'updated_by' => $this->member->id
                                ]);
                            } catch (Exception $e) {
                                $this->logger->error('Failed to update member', [
                                    'member_id' => $member->id,
                                    'error' => $e->getMessage()
                                ]);
                                $this->viewData['error'] = 'Error updating member: ' . $e->getMessage();
                            }
                        }
                    }
                }
            }
        }
        
        $this->viewData['title'] = 'Edit Member';
        $this->viewData['editMember'] = $member;
        
        $this->render('admin/edit_member', $this->viewData);
    }

    /**
     * Add new member (via email invitation)
     */
    public function addmember($params = []) {
        require_once __DIR__ . '/../services/InviteService.php';

        $request = Flight::request();

        if ($request->method === 'POST') {
            // Validate CSRF
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
                // Validate input
                $displayName = trim($request->data->display_name ?? '');
                $email = trim($request->data->email ?? '');
                $level = intval($request->data->level ?? 100);

                if (empty($email)) {
                    $this->viewData['error'] = 'Email is required';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->viewData['error'] = 'Invalid email format';
                } else {
                    // Use InviteService to create and send invitation
                    $inviteService = new \app\services\InviteService();
                    $result = $inviteService->createInvite(
                        $email,
                        $level,
                        $this->member->id,
                        $displayName ?: null
                    );

                    if ($result['success']) {
                        $this->logger->info('Member invited by admin', [
                            'member_id' => $result['member']->id,
                            'email' => $email,
                            'level' => $level,
                            'invited_by' => $this->member->id,
                            'email_sent' => $result['email_sent']
                        ]);

                        if ($result['email_sent']) {
                            $this->flash('success', "Invitation sent to {$email}");
                        } else {
                            $this->flash('warning', "Member created but email could not be sent: " . ($result['email_error'] ?? 'Unknown error'));
                        }

                        Flight::redirect('/admin/members');
                        return;
                    } else {
                        $this->viewData['error'] = $result['error'];
                    }
                }
            }
        }

        $this->viewData['title'] = 'Invite New Member';
        $this->render('admin/add_member', $this->viewData);
    }

    /**
     * Resend invitation to a pending member
     */
    public function resendinvite($params = []) {
        require_once __DIR__ . '/../services/InviteService.php';

        $memberId = Flight::request()->query->id ?? null;

        if (!$memberId) {
            $this->flash('error', 'Member ID required');
            Flight::redirect('/admin/members');
            return;
        }

        $inviteService = new \app\services\InviteService();
        $result = $inviteService->resendInvite((int)$memberId, $this->member->id);

        if ($result['success']) {
            if ($result['email_sent']) {
                $this->flash('success', 'Invitation resent successfully');
            } else {
                $this->flash('warning', 'Invitation updated but email could not be sent: ' . ($result['email_error'] ?? 'Unknown error'));
            }
        } else {
            $this->flash('error', $result['error']);
        }

        Flight::redirect('/admin/members');
    }

    /**
     * Permission management
     */
    public function permissions($params = []) {
        $this->viewData['title'] = 'Permission Management';
        
        $request = Flight::request();
        
        // Handle delete action
        if ($request->query->delete && is_numeric($request->query->delete)) {
            $auth = Bean::load('authcontrol', $request->query->delete);
            if ($auth->id) {
                Bean::trash($auth);
                $this->logger->info('Deleted permission', ['id' => $request->query->delete]);
            }
            Flight::redirect('/admin/permissions');
            return;
        }
        
        // Get all permissions grouped by control
        $_auths = Bean::findAll('authcontrol', 'ORDER BY control ASC, method ASC');
        $auths = [];
        
        foreach ($_auths as $_control) {
            $auths[$_control['control']][$_control['method']] = $_control->export();
        }
        
        $this->viewData['authControls'] = $auths;
        
        $this->render('admin/permissions', $this->viewData);
    }

    /**
     * Edit permission
     */
    public function editpermission($params = []) {
        $request = Flight::request();
        $permId = $request->query->id ?? null;
        
        if (!$permId) {
            // Create new permission
            $permission = Bean::dispense('authcontrol');
        } else {
            $permission = Bean::load('authcontrol', $permId);
            if (!$permission->id && $permId) {
                Flight::redirect('/admin/permissions');
                return;
            }
        }
        
        if ($request->method === 'POST') {
            // Validate CSRF
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
                // Update permission
                $permission->control = $request->data->control ?? '';
                $permission->method = $request->data->method ?? '';
                $permission->level = intval($request->data->level ?? 101);
                $permission->description = $request->data->description ?? '';
                $permission->linkorder = intval($request->data->linkorder ?? 0);
                
                if (!$permission->id) {
                    $permission->validcount = 0;
                    $permission->created_at = date('Y-m-d H:i:s');
                }
                
                try {
                    Bean::store($permission);
                    Flight::redirect('/admin/permissions');
                    return;
                } catch (Exception $e) {
                    $this->viewData['error'] = 'Error saving permission: ' . $e->getMessage();
                }
            }
        }
        
        $this->viewData['title'] = $permId ? 'Edit Permission' : 'Add Permission';
        $this->viewData['permission'] = $permission;
        
        $this->render('admin/edit_permission', $this->viewData);
    }

    /**
     * System settings
     */
    public function settings($params = []) {
        $this->viewData['title'] = 'System Settings';

        $request = Flight::request();

        if ($request->method === 'POST') {
            // Validate CSRF
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
                // Update settings
                foreach ($request->data as $key => $value) {
                    if ($key !== 'csrf_token' && $key !== 'csrf_token_name') {
                        Flight::setSystemSetting($key, $value); // System-wide setting
                    }
                }
                $this->viewData['success'] = 'Settings updated successfully';
            }
        }
        
        // Get current settings (system-wide settings have NULL member_id)
        $this->viewData['settings'] = Bean::findAll('settings', 'member_id IS NULL');
        
        $this->render('admin/settings', $this->viewData);
    }

    /**
     * Delete member
     */
    private function deleteMember($id) {
        // Don't allow deleting self or system users
        if ($id == $this->member->id) {
            $this->logger->warning('Attempted to delete self', ['member_id' => $id]);
            return;
        }
        
        $member = Bean::load('member', $id);
        if ($member->id && $member->username !== 'public-user-entity') {
            // Additional protection for critical accounts
            if ($member->level <= self::ADMIN_LEVEL && $member->id != $this->member->id) {
                // Only ROOT users can delete ADMIN users
                if ($this->member->level > self::ROOT_LEVEL) {
                    $this->logger->warning('Non-root user attempted to delete admin', [
                        'target_member_id' => $id,
                        'target_level' => $member->level,
                        'admin_id' => $this->member->id,
                        'admin_level' => $this->member->level
                    ]);
                    return;
                }
            }
            
            try {
                // Log member details before deletion
                $this->logger->info('Deleting member', [
                    'id' => $id,
                    'username' => $member->username,
                    'email' => $member->email,
                    'level' => $member->level,
                    'deleted_by' => $this->member->id
                ]);
                
                Bean::trash($member);
                
                $this->logger->info('Member deleted successfully', ['id' => $id]);
            } catch (Exception $e) {
                $this->logger->error('Failed to delete member', [
                    'id' => $id,
                    'error' => $e->getMessage()
                ]);
            }
        } else {
            $this->logger->warning('Attempted to delete non-existent or protected user', ['id' => $id]);
        }
    }

    /**
     * Get active sessions count
     */
    private function getActiveSessions() {
        // This is a simple implementation - you might want to track sessions in database
        try {
            $sessionPath = session_save_path();
            if (is_readable($sessionPath)) {
                return count(scandir($sessionPath)) - 2; // Subtract . and ..
            }
        } catch (Exception $e) {
            // If we can't read session directory, just return estimate
        }
        return 1; // At least current user is active
    }
    
    /**
     * Handle bulk actions for members
     */
    private function handleBulkAction($action, $selectedMembers) {
        if (!is_array($selectedMembers)) {
            return;
        }
        
        $count = 0;
        
        switch ($action) {
            case 'activate':
                foreach ($selectedMembers as $memberId) {
                    if (is_numeric($memberId)) {
                        $member = Bean::load('member', $memberId);
                        if ($member->id && $member->username !== 'public-user-entity') {
                            $member->status = 'active';
                            $member->updated_at = date('Y-m-d H:i:s');
                            Bean::store($member);
                            $count++;
                        }
                    }
                }
                $this->logger->info("Bulk activated $count members", ['admin_id' => $this->member->id]);
                break;
                
            case 'suspend':
                foreach ($selectedMembers as $memberId) {
                    if (is_numeric($memberId) && $memberId != $this->member->id) {
                        $member = Bean::load('member', $memberId);
                        if ($member->id && $member->username !== 'public-user-entity') {
                            $member->status = 'suspended';
                            $member->updated_at = date('Y-m-d H:i:s');
                            Bean::store($member);
                            $count++;
                        }
                    }
                }
                $this->logger->info("Bulk suspended $count members", ['admin_id' => $this->member->id]);
                break;
                
            case 'delete':
                foreach ($selectedMembers as $memberId) {
                    if (is_numeric($memberId) && $memberId != $this->member->id) {
                        $member = Bean::load('member', $memberId);
                        if ($member->id && $member->username !== 'public-user-entity') {
                            // Same protection as single delete
                            if ($member->level <= self::ADMIN_LEVEL && $this->member->level > self::ROOT_LEVEL) {
                                continue; // Skip admin deletion by non-root
                            }
                            Bean::trash($member);
                            $count++;
                        }
                    }
                }
                $this->logger->info("Bulk deleted $count members", ['admin_id' => $this->member->id]);
                break;
        }
    }

    /**
     * Cache management page
     */
    public function cache() {
        // Check admin permission
        if (!$this->requireLevel(self::ADMIN_LEVEL)) {
            return;
        }

        // Handle cache actions
        if ($this->getParam('action')) {
            $action = $this->getParam('action');

            switch ($action) {
                case 'clear':
                    // Clear permission cache
                    \app\PermissionCache::clear();

                    // Clear query cache if available
                    $cachedAdapter = Flight::get('cachedDatabaseAdapter');
                    if ($cachedAdapter instanceof \app\CachedDatabaseAdapter) {
                        $cachedAdapter->clearAllCache();
                        $this->flash('success', 'Permission and query caches cleared successfully');
                    } else {
                        $this->flash('success', 'Permission cache cleared successfully');
                    }

                    Flight::redirect('/admin/cache');
                    return;

                case 'clear_query':
                    // Clear only query cache
                    $cachedAdapter = Flight::get('cachedDatabaseAdapter');
                    if ($cachedAdapter instanceof \app\CachedDatabaseAdapter) {
                        $cachedAdapter->clearAllCache();
                        $this->flash('success', 'Query cache cleared successfully');
                    } else {
                        $this->flash('error', 'Query cache not available');
                    }
                    Flight::redirect('/admin/cache');
                    return;

                case 'reload':
                    $stats = \app\PermissionCache::reload();
                    $this->flash('success', 'Permission cache reloaded with ' . count($stats) . ' entries');
                    Flight::redirect('/admin/cache');
                    return;

                case 'warmup':
                    $stats = \app\PermissionCache::warmup();
                    $this->flash('success', 'Cache warmed up successfully');
                    Flight::redirect('/admin/cache');
                    return;
            }
        }

        // Get cache statistics
        $this->viewData['cache_stats'] = \app\PermissionCache::getStats();
        $this->viewData['permissions'] = \app\PermissionCache::getAll();

        // Get query cache statistics from CachedDatabaseAdapter or Bean
        // Note: getDatabaseAdapter() may not return CachedDatabaseAdapter after Bean::selectDatabase() calls
        // So we check Flight storage first, then fall back to Bean's built-in caching
        $cachedAdapter = Flight::get('cachedDatabaseAdapter');
        if ($cachedAdapter instanceof \app\CachedDatabaseAdapter) {
            $this->viewData['query_cache_stats'] = $cachedAdapter->getCacheStats();
        } else {
            // Fall back to Bean's built-in APCu caching stats
            $this->viewData['query_cache_stats'] = Bean::getCacheStats();
        }

        // Get OPcache stats if available
        if (function_exists('opcache_get_status')) {
            $this->viewData['opcache_stats'] = opcache_get_status(false);
        }

        // Check if APCu is available
        $this->viewData['apcu_available'] = function_exists('apcu_cache_info');
        if ($this->viewData['apcu_available']) {
            $this->viewData['apcu_info'] = apcu_cache_info();
        }

        $this->render('admin/cache', $this->viewData);
    }

    /**
     * Clear cache after permission updates
     */
    private function clearPermissionCache() {
        // Clear the permission cache when permissions are modified
        \app\PermissionCache::clear();
        $this->logger->info('Permission cache cleared after update');
    }

    // ========================================
    // Runner Management (Account Executive)
    // ========================================

    /**
     * List all Claude Code runners
     */
    public function runners($params = []) {
        require_once __DIR__ . '/../services/RunnerService.php';

        $this->viewData['title'] = 'Claude Code Runners';

        // Get all runners with stats
        $runners = \app\services\RunnerService::getAllRunners(false);

        foreach ($runners as &$runner) {
            $runner['stats'] = \app\services\RunnerService::getRunnerStats($runner['id']);
            $runner['capabilities'] = json_decode($runner['capabilities'] ?? '[]', true);
        }

        $this->viewData['runners'] = $runners;

        $this->render('admin/runners', $this->viewData);
    }

    /**
     * Create a new runner
     */
    public function createrunner($params = []) {
        require_once __DIR__ . '/../services/RunnerService.php';
        require_once __DIR__ . '/../services/SSHKeyService.php';

        $request = Flight::request();

        if ($request->method === 'POST') {
            if (!Flight::csrf()->validateRequest()) {
                $this->flash('error', 'Invalid CSRF token');
                Flight::redirect('/admin/runners');
                return;
            }

            // Get execution mode
            $executionMode = $request->data->execution_mode ?? 'ssh_tmux';

            $data = [
                'name' => $request->data->name ?? '',
                'description' => $request->data->description ?? '',
                'host' => $request->data->host ?? '',
                'port' => (int)($request->data->port ?? 3500),
                'api_key' => $request->data->api_key ?? '',
                'runner_type' => $request->data->runner_type ?? 'general',
                'capabilities' => is_array($request->data->capabilities) ? $request->data->capabilities : [],
                'max_concurrent_jobs' => (int)($request->data->max_concurrent_jobs ?? 2),
                'is_active' => isset($request->data->is_active) ? 1 : 0,
                'is_default' => isset($request->data->is_default) ? 1 : 0,
                // SSH fields
                'execution_mode' => $executionMode,
                'ssh_user' => trim($request->data->ssh_user ?? 'claudeuser'),
                'ssh_port' => (int)($request->data->ssh_port ?? 22),
                'sshkey_id' => ($request->data->sshkey_id ?? '') ?: null,
                'ssh_validated' => 0
            ];

            // Validation depends on execution mode
            if ($executionMode === 'ssh_tmux') {
                if (empty($data['name']) || empty($data['host']) || empty($data['ssh_user'])) {
                    $this->flash('error', 'Name, host, and SSH user are required for SSH mode');
                    Flight::redirect('/admin/createrunner');
                    return;
                }
            } else {
                if (empty($data['name']) || empty($data['host']) || empty($data['api_key'])) {
                    $this->flash('error', 'Name, host, and API key are required for HTTP mode');
                    Flight::redirect('/admin/createrunner');
                    return;
                }
            }

            try {
                $runnerId = \app\services\RunnerService::createRunner($data);
                $this->logger->info('Runner created', ['runner_id' => $runnerId, 'name' => $data['name']]);
                $this->flash('success', 'Runner created successfully');
                Flight::redirect('/admin/runners');
            } catch (\Exception $e) {
                $this->flash('error', 'Failed to create runner: ' . $e->getMessage());
                Flight::redirect('/admin/createrunner');
            }
            return;
        }

        $this->viewData['title'] = 'Create Runner';
        $this->viewData['sshKeys'] = \app\services\SSHKeyService::getKeys();
        $this->render('admin/runner_form', $this->viewData);
    }

    /**
     * Edit a runner
     */
    public function editrunner($params = []) {
        require_once __DIR__ . '/../services/RunnerService.php';
        require_once __DIR__ . '/../services/SSHKeyService.php';

        $runnerId = (int)($this->opId() ?? 0);
        if (!$runnerId) {
            Flight::redirect('/admin/runners');
            return;
        }

        $runner = \app\services\RunnerService::getRunner($runnerId);
        if (!$runner) {
            $this->flash('error', 'Runner not found');
            Flight::redirect('/admin/runners');
            return;
        }

        $request = Flight::request();

        if ($request->method === 'POST') {
            if (!Flight::csrf()->validateRequest()) {
                $this->flash('error', 'Invalid CSRF token');
                Flight::redirect('/admin/editrunner/' . $runnerId);
                return;
            }

            // Get execution mode
            $executionMode = $request->data->execution_mode ?? ($runner['execution_mode'] ?? 'ssh_tmux');

            $data = [
                'name' => $request->data->name ?? '',
                'description' => $request->data->description ?? '',
                'host' => $request->data->host ?? '',
                'port' => (int)($request->data->port ?? 3500),
                'runner_type' => $request->data->runner_type ?? 'general',
                'capabilities' => is_array($request->data->capabilities) ? $request->data->capabilities : [],
                'max_concurrent_jobs' => (int)($request->data->max_concurrent_jobs ?? 2),
                'is_active' => isset($request->data->is_active) ? 1 : 0,
                'is_default' => isset($request->data->is_default) ? 1 : 0,
                // SSH fields
                'execution_mode' => $executionMode,
                'ssh_user' => trim($request->data->ssh_user ?? 'claudeuser'),
                'ssh_port' => (int)($request->data->ssh_port ?? 22),
                'sshkey_id' => ($request->data->sshkey_id ?? '') ?: null
            ];

            // Only update API key if provided
            if (!empty($request->data->api_key)) {
                $data['api_key'] = $request->data->api_key;
            }

            // Reset validation if SSH settings changed
            $sshChanged = ($data['host'] !== $runner['host'] ||
                          $data['ssh_user'] !== ($runner['ssh_user'] ?? 'claudeuser') ||
                          $data['ssh_port'] !== ($runner['ssh_port'] ?? 22));
            if ($sshChanged) {
                $data['ssh_validated'] = 0;
            }

            try {
                $this->logger->info('Updating runner', ['runner_id' => $runnerId, 'data' => $data]);
                \app\services\RunnerService::updateRunner($runnerId, $data);
                $this->logger->info('Runner updated', ['runner_id' => $runnerId]);
                $this->flash('success', 'Runner updated successfully');
                Flight::redirect('/admin/runners');
            } catch (\Exception $e) {
                $this->flash('error', 'Failed to update runner: ' . $e->getMessage());
                Flight::redirect('/admin/editrunner/' . $runnerId);
            }
            return;
        }

        $runner['capabilities'] = json_decode($runner['capabilities'] ?? '[]', true);
        $this->viewData['title'] = 'Edit Runner';
        $this->viewData['runner'] = $runner;
        $this->viewData['sshKeys'] = \app\services\SSHKeyService::getKeys();
        $this->render('admin/runner_form', $this->viewData);
    }

    /**
     * Delete a runner
     */
    public function deleterunner($params = []) {
        require_once __DIR__ . '/../services/RunnerService.php';

        $runnerId = (int)($this->opId() ?? 0);
        if (!$runnerId) {
            Flight::redirect('/admin/runners');
            return;
        }

        try {
            \app\services\RunnerService::deleteRunner($runnerId);
            $this->logger->info('Runner deleted', ['runner_id' => $runnerId]);
            $this->flash('success', 'Runner deleted');
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to delete runner: ' . $e->getMessage());
        }

        Flight::redirect('/admin/runners');
    }

    /**
     * Test runner connectivity (routes to HTTP or SSH based on execution_mode)
     */
    public function testrunner($params = []) {
        require_once __DIR__ . '/../services/RunnerService.php';
        require_once __DIR__ . '/../services/RunnerDiagnosticService.php';

        $runnerId = (int)($this->opId() ?? 0);
        if (!$runnerId) {
            $this->json(['success' => false, 'error' => 'Runner ID required']);
            return;
        }

        $runner = \app\services\RunnerService::getRunner($runnerId);
        if (!$runner) {
            $this->json(['success' => false, 'error' => 'Runner not found']);
            return;
        }

        // Check if POST data provided - use form values instead of saved values
        $request = Flight::request();
        if ($request->method === 'POST') {
            // Override runner values with form data for testing unsaved changes
            if (!empty($request->data->host)) {
                $runner['host'] = $request->data->host;
            }
            if (!empty($request->data->ssh_user)) {
                $runner['ssh_user'] = $request->data->ssh_user;
            }
            if (!empty($request->data->ssh_port)) {
                $runner['ssh_port'] = (int)$request->data->ssh_port;
            }
            if (isset($request->data->sshkey_id)) {
                $runner['sshkey_id'] = $request->data->sshkey_id ?: null;
            }
            if (!empty($request->data->execution_mode)) {
                $runner['execution_mode'] = $request->data->execution_mode;
            }
        }

        // Route based on execution mode
        $executionMode = $runner['execution_mode'] ?? 'http_api';

        if ($executionMode === 'ssh_tmux') {
            // Use SSH diagnostic for quick check
            $diagnostic = new \app\services\RunnerDiagnosticService($runner);
            $result = $diagnostic->quickCheck();

            // Only update health status in database if testing saved values (GET request)
            if ($request->method !== 'POST') {
                $healthStatus = $result['connected'] ? 'healthy' : 'unhealthy';
                $runnerBean = Bean::load('runners', $runnerId);
                $runnerBean->health_status = $healthStatus;
                $runnerBean->last_health_check = date('Y-m-d H:i:s');
                Bean::store($runnerBean);
            } else {
                $healthStatus = $result['connected'] ? 'healthy' : 'unhealthy';
            }

            $this->json([
                'success' => $result['connected'],
                'data' => [
                    'execution_mode' => 'ssh_tmux',
                    'ssh_user' => $runner['ssh_user'] ?? 'claudeuser',
                    'host' => $runner['host'],
                    'time_ms' => $result['time_ms'],
                    'health_status' => $healthStatus
                ],
                'error' => $result['error'] ?? null
            ]);
        } else {
            // Use HTTP health check
            $result = \app\services\RunnerService::healthCheck($runnerId);

            // Include runner info from DB alongside remote health data
            $this->json([
                'success' => $result['healthy'],
                'data' => [
                    'execution_mode' => 'http_api',
                    'host' => $runner['host'],
                    'port' => $runner['port'],
                    'runner_type' => $runner['runner_type'] ?? 'general',
                    'max_concurrent_jobs' => $runner['max_concurrent_jobs'] ?? 2,
                    'capabilities' => json_decode($runner['capabilities'] ?? '[]', true),
                    'remote_health' => $result['data'] ?? null
                ],
                'error' => $result['error'] ?? null
            ]);
        }
    }

    /**
     * Run full SSH diagnostic on a runner
     */
    public function diagnoserunner($params = []) {
        require_once __DIR__ . '/../services/RunnerService.php';
        require_once __DIR__ . '/../services/RunnerDiagnosticService.php';

        $runnerId = (int)($this->opId() ?? 0);
        if (!$runnerId) {
            $this->json(['success' => false, 'error' => 'Runner ID required']);
            return;
        }

        $runner = \app\services\RunnerService::getRunner($runnerId);
        if (!$runner) {
            $this->json(['success' => false, 'error' => 'Runner not found']);
            return;
        }

        // Check if POST data provided - use form values instead of saved values
        $request = Flight::request();
        $testingUnsaved = false;
        if ($request->method === 'POST') {
            $testingUnsaved = true;
            // Override runner values with form data for testing unsaved changes
            if (!empty($request->data->host)) {
                $runner['host'] = $request->data->host;
            }
            if (!empty($request->data->ssh_user)) {
                $runner['ssh_user'] = $request->data->ssh_user;
            }
            if (!empty($request->data->ssh_port)) {
                $runner['ssh_port'] = (int)$request->data->ssh_port;
            }
            if (isset($request->data->sshkey_id)) {
                $runner['sshkey_id'] = $request->data->sshkey_id ?: null;
            }
            if (!empty($request->data->execution_mode)) {
                $runner['execution_mode'] = $request->data->execution_mode;
            }
        }

        $diagnostic = new \app\services\RunnerDiagnosticService($runner);
        $result = $diagnostic->runDiagnostic();

        // Only save diagnostic result to database if testing saved values (not POST)
        if (!$testingUnsaved) {
            $healthStatus = $result['ready'] ? 'healthy' : 'unhealthy';
            $runnerBean = Bean::load('runners', $runnerId);
            $runnerBean->ssh_validated = $result['ready'] ? 1 : 0;
            $runnerBean->health_status = $healthStatus;
            $runnerBean->last_health_check = date('Y-m-d H:i:s');
            Bean::store($runnerBean);
        }

        // If ready, also get install commands for anything missing
        if (!$result['ready']) {
            $result['install_commands'] = $diagnostic->getInstallCommands();
        }

        $this->json($result);
    }

    /**
     * Run a "Hello World" test on a runner using Claude CLI
     * This tests the full pipeline: SSH -> Claude CLI -> Response
     */
    public function testhelloworld($params = []) {
        require_once __DIR__ . '/../services/RunnerService.php';
        require_once __DIR__ . '/../services/RunnerDiagnosticService.php';

        $runnerId = (int)($this->opId() ?? 0);
        if (!$runnerId) {
            $this->json(['success' => false, 'error' => 'Runner ID required']);
            return;
        }

        $runner = \app\services\RunnerService::getRunner($runnerId);
        if (!$runner) {
            $this->json(['success' => false, 'error' => 'Runner not found']);
            return;
        }

        // Check if POST data provided - use form values instead of saved values
        $request = Flight::request();
        if ($request->method === 'POST') {
            if (!empty($request->data->host)) {
                $runner['host'] = $request->data->host;
            }
            if (!empty($request->data->ssh_user)) {
                $runner['ssh_user'] = $request->data->ssh_user;
            }
            if (!empty($request->data->ssh_port)) {
                $runner['ssh_port'] = (int)$request->data->ssh_port;
            }
            if (isset($request->data->sshkey_id)) {
                $runner['sshkey_id'] = $request->data->sshkey_id ?: null;
            }
        }

        $diagnostic = new \app\services\RunnerDiagnosticService($runner);
        $result = $diagnostic->runHelloWorld();

        $this->json($result);
    }

    /**
     * Test a workstation with a specific agent configuration (via job-executor)
     * POST /admin/testwithagent/{runner_id}
     *
     * Creates a test job and submits to job-executor for full pipeline testing.
     * Combines workstation SSH connection with agent config (model, Ollama settings).
     */
    public function testwithagent($params = []) {
        require_once __DIR__ . '/../services/JobExecutorConfig.php';

        $runnerId = (int)($this->opId() ?? 0);
        $agentId = (int)($this->getParam('agent_id') ?? 0);

        if (!$runnerId || !$agentId) {
            $this->json(['success' => false, 'error' => 'Workstation ID and Agent ID are required']);
            return;
        }

        $memberId = $this->member->id ?? 1;
        $workspaceSlug = $_SESSION['workspace_slug'] ?? 'default';

        $result = \app\services\JobExecutorConfig::submitTestJob($agentId, $runnerId, $memberId, $workspaceSlug);

        $this->json($result);
    }

    /**
     * Get test status (for workstation tests)
     * GET /admin/teststatus/{test_id}
     *
     * Polls the status of a background agent test.
     */
    public function teststatus($params = []) {
        $testId = $this->opId() ?? $this->getParam('test_id') ?? '';
        $sendExit = $this->getParam('send_exit') === '1' || $this->getParam('send_exit') === 'true';

        if (empty($testId)) {
            $this->json(['success' => false, 'error' => 'Test ID is required']);
            return;
        }

        // First check database for job-executor jobs (job_uid contains test_id)
        $job = Bean::findOne('aidevjobs', 'job_uid LIKE ?', ['%' . $testId]);

        if ($job) {
            $status = $job->status ?? 'pending';
            $phase = $job->phase ?? '';

            // Calculate duration if we have timing info
            $durationMs = null;
            if ($job->started_at && ($job->completed_at || $job->updated_at)) {
                $startTime = strtotime($job->started_at);
                $endTime = strtotime($job->completed_at ?? $job->updated_at);
                if ($startTime && $endTime) {
                    $durationMs = ($endTime - $startTime) * 1000;
                }
            }

            // Load workstation info if available
            $workstationInfo = null;
            if ($job->runners_id) {
                $runner = Bean::load('runners', $job->runners_id);
                if ($runner && $runner->id) {
                    $workstationInfo = [
                        'name' => $runner->name,
                        'host' => $runner->host,
                        'ssh_user' => $runner->ssh_user ?? 'claudeuser',
                    ];
                }
            }

            // Determine work directory path
            $sshUser = $workstationInfo['ssh_user'] ?? 'claudeuser';
            $workDir = "/home/{$sshUser}/jobs/" . $job->job_uid;

            $response = [
                'success' => true,
                'status' => $status,
                'phase' => $phase,
                'job_uid' => $job->job_uid,
                'duration_ms' => $durationMs,
                'work_dir' => $workDir,
                'started_at' => $job->started_at,
                'completed_at' => $job->completed_at,
                'workstation' => $workstationInfo,
            ];

            // Map status to old format for UI compatibility
            if ($status === 'completed') {
                $response['success'] = true;
                $response['status'] = 'completed';
                $response['message'] = $job->summary ?? 'Test completed successfully';
            } elseif ($status === 'failed') {
                $response['success'] = false;
                $response['status'] = 'failed';
                $response['error'] = $job->error_message ?? 'Test failed';
            } elseif (in_array($status, ['running', 'validated'])) {
                $response['status'] = 'running';
                $response['phase'] = $phase;
            }

            // Send /exit to tmux session if requested
            if ($sendExit && $job->job_uid) {
                $exitResult = \app\services\JobExecutorConfig::sendExit($job->job_uid);
                $response['exit_sent'] = $exitResult['success'] ?? false;
                $response['exit_message'] = $exitResult['message'] ?? $exitResult['error'] ?? null;
            }

            $this->json($response);
            return;
        }

        // Fall back to status file (legacy method)
        $statusFile = "/tmp/agent-test-{$testId}.json";

        if (!file_exists($statusFile)) {
            $this->json(['success' => false, 'error' => 'Test not found']);
            return;
        }

        $status = json_decode(file_get_contents($statusFile), true);

        if (!$status) {
            $this->json(['success' => false, 'error' => 'Failed to read test status']);
            return;
        }

        $this->json(array_merge(['success' => true], $status));
    }

    /**
     * Send /exit command to job-executor to stop a tmux session
     *
     * @param string $jobUid The job UID
     * @return array Result with success/error
     */
    private function sendExitToJobExecutor(string $jobUid): array {
        $workspaceSlug = $_SESSION['workspace_slug'] ?? 'default';
        $jobExecutorUrl = \app\services\JobExecutorConfig::getUrl();
        $verifySsl = \app\services\JobExecutorConfig::shouldVerifySsl();

        $exitUrl = rtrim($jobExecutorUrl, '/') . '/api/jobs/exit';

        $ch = curl_init($exitUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'workspace' => $workspaceSlug,
                'job_uid' => $jobUid,
            ]),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            Flight::get('log')->warning('Failed to send exit to job-executor', [
                'error' => $curlError,
                'job_uid' => $jobUid,
            ]);
            return ['success' => false, 'error' => "Connection failed: {$curlError}"];
        }

        $result = json_decode($response, true) ?? [];

        if ($httpCode !== 200) {
            return ['success' => false, 'error' => $result['error'] ?? "HTTP {$httpCode}"];
        }

        return $result;
    }

    /**
     * Get available agents for workstation testing dropdown
     * GET /admin/testagents
     */
    public function testagents($params = []) {
        require_once __DIR__ . '/../services/AgentTestService.php';
        $agents = \app\services\AgentTestService::getActiveAgents();

        $this->json([
            'success' => true,
            'agents' => $agents
        ]);
    }

    /**
     * Test job-executor ping/pong flow
     * POST /admin/testjobexecutor/{runner_id}
     *
     * Creates a test job, submits to job-executor, and verifies the ping/pong validation.
     * This tests the full job-executor integration without actually running Claude.
     */
    public function testjobexecutor($params = []) {
        require_once __DIR__ . '/../services/RunnerService.php';

        $runnerId = (int)($this->opId() ?? 0);
        if (!$runnerId) {
            $this->json(['success' => false, 'error' => 'Runner ID required']);
            return;
        }

        $runner = \app\services\RunnerService::getRunner($runnerId);
        if (!$runner) {
            $this->json(['success' => false, 'error' => 'Runner not found']);
            return;
        }

        // Get job-executor URL from POST or use config default
        $jobExecutorUrl = $this->getParam('job_executor_url') ?: \app\services\JobExecutorConfig::getUrl();

        // Get workspace slug
        $workspaceSlug = $_SESSION['workspace_slug'] ?? 'default';

        $startTime = microtime(true);

        try {
            // Step 1: Create a test job in aidevjobs
            $jobUid = 'test-' . $workspaceSlug . '-' . bin2hex(random_bytes(8));

            // Find a repo that has this runner's agent assigned (or any repo for testing)
            $repo = null;
            $agent = Bean::findOne('aiagents', 'runners_id = ? AND is_active = 1', [$runnerId]);
            if ($agent) {
                $repo = Bean::findOne('repoconnections', 'aiagents_id = ?', [$agent->id]);
            }

            // Create test job
            $job = Bean::dispense('aidevjobs');
            $job->job_uid = $jobUid;
            $job->member_id = $this->member->id ?? 1;
            $job->boards_id = null;
            $job->repoconnections_id = $repo ? $repo->id : null;
            $job->runners_id = $runnerId; // Direct runner assignment for testing
            $job->issue_key = 'TEST-JE-001';
            $job->status = 'pending';
            $job->phase = 'test';
            $job->prompt = 'This is a test job for job-executor ping/pong validation. Please respond with "Hello from job-executor test!"';
            $job->created_at = date('Y-m-d H:i:s');
            Bean::store($job);

            Flight::get('log')->info('Created test job for job-executor', [
                'job_uid' => $jobUid,
                'runner_id' => $runnerId,
                'workspace' => $workspaceSlug,
            ]);

            // Step 2: Submit to job-executor (PING)
            $submitUrl = rtrim($jobExecutorUrl, '/') . '/api/jobs/submit';
            // Build callback URL with workspace subdomain from site config
            $callbackUrl = \app\services\SiteConfig::getWorkspaceUrl($workspaceSlug) . '/api/jobexecutor';
            $verifySsl = \app\services\JobExecutorConfig::shouldVerifySsl();

            $ch = curl_init($submitUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'workspace' => $workspaceSlug,
                    'job_uid' => $jobUid,
                    'callback_url' => $callbackUrl,
                ]),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => $verifySsl,
                CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $duration = round((microtime(true) - $startTime) * 1000);

            // Check result
            if ($curlError) {
                // Cleanup test job
                Bean::trash($job);

                $this->json([
                    'success' => false,
                    'error' => "Connection failed: {$curlError}",
                    'duration_ms' => $duration,
                    'job_executor_url' => $jobExecutorUrl,
                ]);
                return;
            }

            if ($httpCode !== 200) {
                // Cleanup test job
                Bean::trash($job);

                $this->json([
                    'success' => false,
                    'error' => "Job-executor returned HTTP {$httpCode}",
                    'response' => $response,
                    'duration_ms' => $duration,
                    'job_executor_url' => $jobExecutorUrl,
                ]);
                return;
            }

            $result = json_decode($response, true);

            if (!$result || !($result['success'] ?? false)) {
                // Cleanup test job
                Bean::trash($job);

                $this->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Job-executor rejected submission',
                    'response' => $result,
                    'duration_ms' => $duration,
                ]);
                return;
            }

            // Step 3: Verify job was validated (check status in DB)
            $job = Bean::load('aidevjobs', $job->id);
            $wasValidated = ($job->status === 'validated');

            // Cleanup test job
            Bean::trash($job);

            $this->json([
                'success' => true,
                'message' => 'Job-executor ping/pong test passed!',
                'details' => [
                    'ping' => 'Job submitted to job-executor',
                    'pong' => $wasValidated ? 'Job-executor validated with MyCTOBot' : 'Validation pending',
                    'job_uid' => $jobUid,
                    'workstation' => $result['data']['workstation'] ?? $runner['name'],
                ],
                'duration_ms' => $duration,
                'job_executor_url' => $jobExecutorUrl,
            ]);

        } catch (\Exception $e) {
            Flight::get('log')->error('Job-executor test failed', ['error' => $e->getMessage()]);

            $this->json([
                'success' => false,
                'error' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000),
            ]);
        }
    }

    /**
     * Health check all runners
     */
    public function runnerhealth($params = []) {
        require_once __DIR__ . '/../services/RunnerService.php';

        $results = \app\services\RunnerService::healthCheckAll();

        $this->json([
            'success' => true,
            'results' => $results
        ]);
    }

    // ========================================
    // SSH Key Management
    // ========================================

    /**
     * List SSH keys (AJAX)
     */
    public function sshkeys($params = []) {
        require_once __DIR__ . '/../services/SSHKeyService.php';

        // Connect to user database for workspace-specific keys
        
        $keys = \app\services\SSHKeyService::getKeys();

        
        $this->json([
            'success' => true,
            'keys' => $keys,
            'key_types' => \app\services\SSHKeyService::getSupportedTypes()
        ]);
    }

    /**
     * Generate a new SSH key
     */
    public function generatesshkey($params = []) {
        require_once __DIR__ . '/../services/SSHKeyService.php';

        $request = Flight::request();

        if ($request->method !== 'POST') {
            $this->json(['success' => false, 'error' => 'POST required']);
            return;
        }

        if (!Flight::csrf()->validateJson()) {
            $this->json(['success' => false, 'error' => 'Invalid CSRF token']);
            return;
        }

        // validateJson() populates $_POST with JSON data
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $keyType = $_POST['key_type'] ?? 'ecdsa';
        $comment = trim($_POST['comment'] ?? '');

        if (empty($name)) {
            $this->json(['success' => false, 'error' => 'Name is required']);
            return;
        }

        // Connect to user database for workspace-specific keys
        
        try {
            $key = \app\services\SSHKeyService::createKey(
                $this->member->id,
                $name,
                $keyType,
                $description,
                $comment
            );

            $this->logger->info('SSH key generated', [
                'member_id' => $this->member->id,
                'key_id' => $key['id'],
                'key_type' => $keyType,
                'fingerprint' => $key['fingerprint']
            ]);

            
            $this->json([
                'success' => true,
                'key' => $key,
                'message' => 'SSH key generated successfully. Save the private key now - it cannot be retrieved later.'
            ]);

        } catch (\Exception $e) {
                        $this->logger->error('SSH key generation failed', [
                'error' => $e->getMessage()
            ]);
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Get SSH key details (with private key for download)
     */
    public function getsshkey($params = []) {
        require_once __DIR__ . '/../services/SSHKeyService.php';

        $keyId = (int)($this->opId() ?? 0);
        if (!$keyId) {
            $this->json(['success' => false, 'error' => 'Key ID required']);
            return;
        }

        $includePrivate = $this->getParam('include_private') === '1';

                $key = \app\services\SSHKeyService::getKey($keyId, $includePrivate);
        
        if (!$key) {
            $this->json(['success' => false, 'error' => 'Key not found']);
            return;
        }

        $this->json([
            'success' => true,
            'key' => $key
        ]);
    }

    /**
     * Delete an SSH key
     */
    public function deletesshkey($params = []) {
        require_once __DIR__ . '/../services/SSHKeyService.php';

        $keyId = (int)($this->opId() ?? 0);
        if (!$keyId) {
            $this->json(['success' => false, 'error' => 'Key ID required']);
            return;
        }

        
        // Get key info for logging
        $key = \app\services\SSHKeyService::getKey($keyId);
        if (!$key) {
                        $this->json(['success' => false, 'error' => 'Key not found']);
            return;
        }

        $deleted = \app\services\SSHKeyService::deleteKey($keyId);

        $this->logger->info('SSH key deleted', [
            'member_id' => $this->member->id,
            'key_id' => $keyId,
            'key_name' => $key['name'],
            'fingerprint' => $key['fingerprint']
        ]);

        
        $this->json([
            'success' => $deleted,
            'message' => $deleted ? 'Key deleted successfully' : 'Failed to delete key'
        ]);
    }

    // ========================================
    // Plugin Registry Cache Management
    // ========================================

    /**
     * Plugin registry cache management page
     */
    public function plugincache($params = []) {
        // Check admin permission
        if (!$this->requireLevel(self::ADMIN_LEVEL)) {
            return;
        }

        // Handle cache actions
        if ($this->getParam('action')) {
            $action = $this->getParam('action');

            switch ($action) {
                case 'refresh':
                    // Force refresh the plugin cache
                    $result = \app\PluginRegistryCache::refresh(true);

                    if ($result['success']) {
                        $this->flash('success', "Plugin cache refreshed successfully. Found {$result['count']} plugins.");
                    } else {
                        $errorCount = count($result['errors']);
                        $this->flash('warning', "Plugin cache refreshed with {$errorCount} error(s). Found {$result['count']} plugins.");
                    }

                    $this->logger->info('Plugin cache manually refreshed', [
                        'admin_id' => $this->member->id,
                        'plugins_count' => $result['count'],
                        'errors' => $result['errors']
                    ]);

                    Flight::redirect('/admin/plugincache');
                    return;

                case 'invalidate':
                    // Clear the plugin cache
                    \app\PluginRegistryCache::invalidate();
                    $this->flash('success', 'Plugin cache invalidated successfully');

                    $this->logger->info('Plugin cache invalidated', [
                        'admin_id' => $this->member->id
                    ]);

                    Flight::redirect('/admin/plugincache');
                    return;

                case 'warmup':
                    // Warm up the cache
                    $stats = \app\PluginRegistryCache::warmup();
                    $this->flash('success', "Plugin cache warmed up with {$stats['count']} plugins");

                    $this->logger->info('Plugin cache warmed up', [
                        'admin_id' => $this->member->id,
                        'stats' => $stats
                    ]);

                    Flight::redirect('/admin/plugincache');
                    return;
            }
        }

        // Get cache statistics and data for display
        $this->viewData['title'] = 'Plugin Registry Cache';
        $this->viewData['cache_stats'] = \app\PluginRegistryCache::getStats();
        $this->viewData['plugins'] = \app\PluginRegistryCache::getPlugins();
        $this->viewData['errors'] = \app\PluginRegistryCache::getRefreshErrors();

        // Get configured sources for display
        $sourcesConfig = Flight::get('plugin_registry.sources') ?? '';
        $this->viewData['sources'] = array_filter(array_map('trim', explode(',', $sourcesConfig)));

        $this->render('admin/plugincache', $this->viewData);
    }

    /**
     * API endpoint for plugin cache refresh (for AJAX calls)
     */
    public function refreshplugincache($params = []) {
        // Check admin permission
        if (!$this->requireLevel(self::ADMIN_LEVEL)) {
            return;
        }

        $force = $this->getParam('force', false);

        $result = \app\PluginRegistryCache::refresh((bool)$force);

        $this->logger->info('Plugin cache refresh requested via API', [
            'admin_id' => $this->member->id,
            'force' => $force,
            'result' => $result
        ]);

        $this->json([
            'success' => $result['success'],
            'data' => [
                'count' => $result['count'],
                'errors' => $result['errors'],
                'duration_ms' => $result['duration_ms'] ?? null,
                'skipped' => $result['skipped'] ?? false,
                'reason' => $result['reason'] ?? null
            ]
        ]);
    }

    /**
     * API endpoint for plugin cache statistics
     */
    public function plugincachestats($params = []) {
        // Check admin permission
        if (!$this->requireLevel(self::ADMIN_LEVEL)) {
            return;
        }

        $this->json([
            'success' => true,
            'data' => \app\PluginRegistryCache::getStats()
        ]);
    }

    // ========================================
    // MCP Server Helpers
    // ========================================

    /**
     * Get built-in MCP servers that are always available
     * These are merged with database servers for runner capabilities
     *
     * @return array Built-in server configurations
     */
    private function getBuiltinMcpServers(): array {
        return [
            [
                'name' => 'jira',
                'description' => 'Jira Integration (Connected Service)',
                'server_type' => 'http',
                'is_builtin' => true,
                'is_required' => true,
            ],
            [
                'name' => 'pipelines',
                'description' => 'MyCTOBot Pipeline Tools',
                'server_type' => 'http',
                'is_builtin' => true,
                'is_required' => true,
            ],
            [
                'name' => 'playwright',
                'description' => 'Playwright Browser Automation',
                'server_type' => 'stdio',
                'is_builtin' => true,
                'is_required' => false,
            ],
            [
                'name' => 'github',
                'description' => 'GitHub API Integration',
                'server_type' => 'stdio',
                'is_builtin' => true,
                'is_required' => false,
            ],
            [
                'name' => 'fetch',
                'description' => 'HTTP Fetch Capabilities',
                'server_type' => 'stdio',
                'is_builtin' => true,
                'is_required' => false,
            ],
            [
                'name' => 'filesystem',
                'description' => 'Filesystem Access',
                'server_type' => 'stdio',
                'is_builtin' => true,
                'is_required' => false,
            ],
            [
                'name' => 'mantic',
                'description' => 'Semantic Code Search',
                'server_type' => 'stdio',
                'is_builtin' => true,
                'is_required' => false,
            ],
        ];
    }

    /**
     * Get merged MCP servers for runner capabilities
     * Combines database servers with built-in defaults, avoiding duplicates
     *
     * @return array Merged server list with is_builtin and is_required flags
     */
    private function getMergedMcpServers(): array {
        // Get database servers
        $dbServers = Bean::find('mcpservers', ' is_active = 1 ORDER BY name ');

        // Convert to array format and add flags
        $serverMap = [];
        foreach ($dbServers as $server) {
            $serverMap[$server->name] = [
                'id' => $server->id,
                'name' => $server->name,
                'description' => $server->description ?: $server->name,
                'server_type' => $server->server_type,
                'is_builtin' => (bool) ($server->is_builtin ?? false),
                'is_required' => (bool) ($server->is_required ?? false),
                'is_db' => true,
            ];
        }

        // Merge with built-in servers (built-in takes precedence for flags)
        foreach ($this->getBuiltinMcpServers() as $builtin) {
            $name = $builtin['name'];
            if (isset($serverMap[$name])) {
                // Update flags from built-in definition
                $serverMap[$name]['is_builtin'] = true;
                $serverMap[$name]['is_required'] = $builtin['is_required'];
            } else {
                // Add built-in server that's not in database
                $serverMap[$name] = [
                    'id' => null,
                    'name' => $name,
                    'description' => $builtin['description'],
                    'server_type' => $builtin['server_type'],
                    'is_builtin' => true,
                    'is_required' => $builtin['is_required'],
                    'is_db' => false,
                ];
            }
        }

        // Sort: required first, then alphabetical
        uasort($serverMap, function($a, $b) {
            if ($a['is_required'] !== $b['is_required']) {
                return $b['is_required'] ? 1 : -1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return array_values($serverMap);
    }

}
