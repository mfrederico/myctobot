<?php
namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
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
            'members' => R::count('member'),
            'permissions' => R::count('authcontrol'),
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
        $this->viewData['members'] = R::findAll('member', 'ORDER BY created_at DESC');
        
        $this->render('admin/members', $this->viewData);
    }

    /**
     * Edit member
     */
    public function editMember($params = []) {
        $request = Flight::request();
        $memberId = $request->query->id ?? null;
        
        if (!$memberId) {
            Flight::redirect('/admin/members');
            return;
        }
        
        $member = R::load('member', $memberId);
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
                    $existingUsername = R::findOne('member', 'username = ? AND id != ?', [$username, $member->id]);
                    $existingEmail = R::findOne('member', 'email = ? AND id != ?', [$email, $member->id]);
                    
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
                                R::store($member);
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
     * Add new member
     */
    public function addMember($params = []) {
        $request = Flight::request();
        
        if ($request->method === 'POST') {
            // Validate CSRF
            if (!Flight::csrf()->validateRequest()) {
                $this->viewData['error'] = 'Invalid CSRF token';
            } else {
                // Validate input
                $username = trim($request->data->username ?? '');
                $email = trim($request->data->email ?? '');
                $password = $request->data->password ?? '';
                $level = intval($request->data->level ?? 100);
                $status = $request->data->status ?? 'active';
                
                if (empty($username)) {
                    $this->viewData['error'] = 'Username is required';
                } elseif (empty($email)) {
                    $this->viewData['error'] = 'Email is required';
                } elseif (empty($password)) {
                    $this->viewData['error'] = 'Password is required';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->viewData['error'] = 'Invalid email format';
                } elseif (strlen($username) < 3) {
                    $this->viewData['error'] = 'Username must be at least 3 characters long';
                } elseif (strlen($password) < 8) {
                    $this->viewData['error'] = 'Password must be at least 8 characters long';
                } else {
                    // Check for duplicate username/email
                    $existingUsername = R::findOne('member', 'username = ?', [$username]);
                    $existingEmail = R::findOne('member', 'email = ?', [$email]);
                    
                    if ($existingUsername) {
                        $this->viewData['error'] = 'Username already exists';
                    } elseif ($existingEmail) {
                        $this->viewData['error'] = 'Email already exists';
                    } else {
                        // Create new member
                        $member = R::dispense('member');
                        $member->username = $username;
                        $member->email = $email;
                        $member->password = password_hash($password, PASSWORD_DEFAULT);
                        $member->level = $level;
                        $member->status = $status;
                        $member->created_at = date('Y-m-d H:i:s');
                        $member->updated_at = date('Y-m-d H:i:s');
                        
                        try {
                            R::store($member);
                            $this->logger->info('New member created by admin', [
                                'member_id' => $member->id,
                                'username' => $username,
                                'created_by' => $this->member->id
                            ]);
                            Flight::redirect('/admin/members');
                            return;
                        } catch (Exception $e) {
                            $this->logger->error('Failed to create member', [
                                'username' => $username,
                                'error' => $e->getMessage()
                            ]);
                            $this->viewData['error'] = 'Error creating member: ' . $e->getMessage();
                        }
                    }
                }
            }
        }
        
        $this->viewData['title'] = 'Add New Member';
        $this->render('admin/add_member', $this->viewData);
    }

    /**
     * Permission management
     */
    public function permissions($params = []) {
        $this->viewData['title'] = 'Permission Management';
        
        $request = Flight::request();
        
        // Handle delete action
        if ($request->query->delete && is_numeric($request->query->delete)) {
            $auth = R::load('authcontrol', $request->query->delete);
            if ($auth->id) {
                R::trash($auth);
                $this->logger->info('Deleted permission', ['id' => $request->query->delete]);
            }
            Flight::redirect('/admin/permissions');
            return;
        }
        
        // Get all permissions grouped by control
        $_auths = R::findAll('authcontrol', 'ORDER BY control ASC, method ASC');
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
    public function editPermission($params = []) {
        $request = Flight::request();
        $permId = $request->query->id ?? null;
        
        if (!$permId) {
            // Create new permission
            $permission = R::dispense('authcontrol');
        } else {
            $permission = R::load('authcontrol', $permId);
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
                    R::store($permission);
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
        $this->viewData['settings'] = R::findAll('settings', 'member_id IS NULL');
        
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
        
        $member = R::load('member', $id);
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
                
                R::trash($member);
                
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
                        $member = R::load('member', $memberId);
                        if ($member->id && $member->username !== 'public-user-entity') {
                            $member->status = 'active';
                            $member->updated_at = date('Y-m-d H:i:s');
                            R::store($member);
                            $count++;
                        }
                    }
                }
                $this->logger->info("Bulk activated $count members", ['admin_id' => $this->member->id]);
                break;
                
            case 'suspend':
                foreach ($selectedMembers as $memberId) {
                    if (is_numeric($memberId) && $memberId != $this->member->id) {
                        $member = R::load('member', $memberId);
                        if ($member->id && $member->username !== 'public-user-entity') {
                            $member->status = 'suspended';
                            $member->updated_at = date('Y-m-d H:i:s');
                            R::store($member);
                            $count++;
                        }
                    }
                }
                $this->logger->info("Bulk suspended $count members", ['admin_id' => $this->member->id]);
                break;
                
            case 'delete':
                foreach ($selectedMembers as $memberId) {
                    if (is_numeric($memberId) && $memberId != $this->member->id) {
                        $member = R::load('member', $memberId);
                        if ($member->id && $member->username !== 'public-user-entity') {
                            // Same protection as single delete
                            if ($member->level <= self::ADMIN_LEVEL && $this->member->level > self::ROOT_LEVEL) {
                                continue; // Skip admin deletion by non-root
                            }
                            R::trash($member);
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
                    $dbAdapter = R::getDatabaseAdapter();
                    if ($dbAdapter instanceof \app\CachedDatabaseAdapter) {
                        $dbAdapter->clearAllCache();
                        $this->flash('success', 'Permission and query caches cleared successfully');
                    } else {
                        $this->flash('success', 'Permission cache cleared successfully');
                    }

                    Flight::redirect('/admin/cache');
                    return;

                case 'clear_query':
                    // Clear only query cache
                    $dbAdapter = R::getDatabaseAdapter();
                    if ($dbAdapter instanceof \app\CachedDatabaseAdapter) {
                        $dbAdapter->clearAllCache();
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

        // Get query cache statistics from CachedDatabaseAdapter
        $dbAdapter = R::getDatabaseAdapter();
        if ($dbAdapter instanceof \app\CachedDatabaseAdapter) {
            $this->viewData['query_cache_stats'] = $dbAdapter->getCacheStats();
        } else {
            $this->viewData['query_cache_stats'] = null;
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
    // Shard Management (Account Executive)
    // ========================================

    /**
     * List all Claude Code shards
     */
    public function shards($params = []) {
        require_once __DIR__ . '/../services/ShardService.php';

        $this->viewData['title'] = 'Claude Code Shards';

        // Get all shards with stats
        $shards = \app\services\ShardService::getAllShards(false);

        foreach ($shards as &$shard) {
            $shard['stats'] = \app\services\ShardService::getShardStats($shard['id']);
            $shard['capabilities'] = json_decode($shard['capabilities'] ?? '[]', true);
        }

        $this->viewData['shards'] = $shards;

        $this->render('admin/shards', $this->viewData);
    }

    /**
     * Create a new shard
     */
    public function createshard($params = []) {
        require_once __DIR__ . '/../services/ShardService.php';

        $request = Flight::request();

        if ($request->method === 'POST') {
            if (!Flight::csrf()->validateRequest()) {
                $this->flash('error', 'Invalid CSRF token');
                Flight::redirect('/admin/shards');
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
                'shard_type' => $request->data->shard_type ?? 'general',
                'capabilities' => is_array($request->data->capabilities) ? $request->data->capabilities : [],
                'max_concurrent_jobs' => (int)($request->data->max_concurrent_jobs ?? 2),
                'is_active' => isset($request->data->is_active) ? 1 : 0,
                'is_default' => isset($request->data->is_default) ? 1 : 0,
                // SSH fields
                'execution_mode' => $executionMode,
                'ssh_user' => trim($request->data->ssh_user ?? 'claudeuser'),
                'ssh_port' => (int)($request->data->ssh_port ?? 22),
                'ssh_key_path' => trim($request->data->ssh_key_path ?? '') ?: null,
                'ssh_validated' => 0
            ];

            // Validation depends on execution mode
            if ($executionMode === 'ssh_tmux') {
                if (empty($data['name']) || empty($data['host']) || empty($data['ssh_user'])) {
                    $this->flash('error', 'Name, host, and SSH user are required for SSH mode');
                    Flight::redirect('/admin/createshard');
                    return;
                }
            } else {
                if (empty($data['name']) || empty($data['host']) || empty($data['api_key'])) {
                    $this->flash('error', 'Name, host, and API key are required for HTTP mode');
                    Flight::redirect('/admin/createshard');
                    return;
                }
            }

            try {
                $shardId = \app\services\ShardService::createShard($data);
                $this->logger->info('Shard created', ['shard_id' => $shardId, 'name' => $data['name']]);
                $this->flash('success', 'Shard created successfully');
                Flight::redirect('/admin/shards');
            } catch (\Exception $e) {
                $this->flash('error', 'Failed to create shard: ' . $e->getMessage());
                Flight::redirect('/admin/createshard');
            }
            return;
        }

        $this->viewData['title'] = 'Create Shard';
        $this->render('admin/shard_form', $this->viewData);
    }

    /**
     * Edit a shard
     */
    public function editshard($params = []) {
        require_once __DIR__ . '/../services/ShardService.php';

        $shardId = (int)($params['operation']->name ?? 0);
        if (!$shardId) {
            Flight::redirect('/admin/shards');
            return;
        }

        $shard = \app\services\ShardService::getShard($shardId);
        if (!$shard) {
            $this->flash('error', 'Shard not found');
            Flight::redirect('/admin/shards');
            return;
        }

        $request = Flight::request();

        if ($request->method === 'POST') {
            if (!Flight::csrf()->validateRequest()) {
                $this->flash('error', 'Invalid CSRF token');
                Flight::redirect('/admin/editshard/' . $shardId);
                return;
            }

            // Get execution mode
            $executionMode = $request->data->execution_mode ?? ($shard['execution_mode'] ?? 'ssh_tmux');

            $data = [
                'name' => $request->data->name ?? '',
                'description' => $request->data->description ?? '',
                'host' => $request->data->host ?? '',
                'port' => (int)($request->data->port ?? 3500),
                'shard_type' => $request->data->shard_type ?? 'general',
                'capabilities' => is_array($request->data->capabilities) ? $request->data->capabilities : [],
                'max_concurrent_jobs' => (int)($request->data->max_concurrent_jobs ?? 2),
                'is_active' => isset($request->data->is_active) ? 1 : 0,
                'is_default' => isset($request->data->is_default) ? 1 : 0,
                // SSH fields
                'execution_mode' => $executionMode,
                'ssh_user' => trim($request->data->ssh_user ?? 'claudeuser'),
                'ssh_port' => (int)($request->data->ssh_port ?? 22),
                'ssh_key_path' => trim($request->data->ssh_key_path ?? '') ?: null
            ];

            // Only update API key if provided
            if (!empty($request->data->api_key)) {
                $data['api_key'] = $request->data->api_key;
            }

            // Reset validation if SSH settings changed
            $sshChanged = ($data['host'] !== $shard['host'] ||
                          $data['ssh_user'] !== ($shard['ssh_user'] ?? 'claudeuser') ||
                          $data['ssh_port'] !== ($shard['ssh_port'] ?? 22));
            if ($sshChanged) {
                $data['ssh_validated'] = 0;
            }

            try {
                $this->logger->info('Updating shard', ['shard_id' => $shardId, 'data' => $data]);
                \app\services\ShardService::updateShard($shardId, $data);
                $this->logger->info('Shard updated', ['shard_id' => $shardId]);
                $this->flash('success', 'Shard updated successfully');
                Flight::redirect('/admin/shards');
            } catch (\Exception $e) {
                $this->flash('error', 'Failed to update shard: ' . $e->getMessage());
                Flight::redirect('/admin/editshard/' . $shardId);
            }
            return;
        }

        $shard['capabilities'] = json_decode($shard['capabilities'] ?? '[]', true);
        $this->viewData['title'] = 'Edit Shard';
        $this->viewData['shard'] = $shard;
        $this->render('admin/shard_form', $this->viewData);
    }

    /**
     * Delete a shard
     */
    public function deleteshard($params = []) {
        require_once __DIR__ . '/../services/ShardService.php';

        $shardId = (int)($params['operation']->name ?? 0);
        if (!$shardId) {
            Flight::redirect('/admin/shards');
            return;
        }

        try {
            \app\services\ShardService::deleteShard($shardId);
            $this->logger->info('Shard deleted', ['shard_id' => $shardId]);
            $this->flash('success', 'Shard deleted');
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to delete shard: ' . $e->getMessage());
        }

        Flight::redirect('/admin/shards');
    }

    /**
     * Test shard connectivity (routes to HTTP or SSH based on execution_mode)
     */
    public function testshard($params = []) {
        require_once __DIR__ . '/../services/ShardService.php';
        require_once __DIR__ . '/../services/ShardDiagnosticService.php';

        $shardId = (int)($params['operation']->name ?? 0);
        if (!$shardId) {
            $this->json(['success' => false, 'error' => 'Shard ID required']);
            return;
        }

        $shard = \app\services\ShardService::getShard($shardId);
        if (!$shard) {
            $this->json(['success' => false, 'error' => 'Shard not found']);
            return;
        }

        // Route based on execution mode
        $executionMode = $shard['execution_mode'] ?? 'http_api';

        if ($executionMode === 'ssh_tmux') {
            // Use SSH diagnostic for quick check
            $diagnostic = new \app\services\ShardDiagnosticService($shard);
            $result = $diagnostic->quickCheck();

            // Update health status in database
            $healthStatus = $result['connected'] ? 'healthy' : 'unhealthy';
            $shardBean = R::load('claudeshards', $shardId);
            $shardBean->health_status = $healthStatus;
            $shardBean->last_health_check = date('Y-m-d H:i:s');
            R::store($shardBean);

            $this->json([
                'success' => $result['connected'],
                'data' => [
                    'execution_mode' => 'ssh_tmux',
                    'ssh_user' => $shard['ssh_user'] ?? 'claudeuser',
                    'host' => $shard['host'],
                    'time_ms' => $result['time_ms'],
                    'health_status' => $healthStatus
                ],
                'error' => $result['error'] ?? null
            ]);
        } else {
            // Use HTTP health check
            $result = \app\services\ShardService::healthCheck($shardId);

            // Include shard info from DB alongside remote health data
            $this->json([
                'success' => $result['healthy'],
                'data' => [
                    'execution_mode' => 'http_api',
                    'host' => $shard['host'],
                    'port' => $shard['port'],
                    'shard_type' => $shard['shard_type'] ?? 'general',
                    'max_concurrent_jobs' => $shard['max_concurrent_jobs'] ?? 2,
                    'capabilities' => json_decode($shard['capabilities'] ?? '[]', true),
                    'remote_health' => $result['data'] ?? null
                ],
                'error' => $result['error'] ?? null
            ]);
        }
    }

    /**
     * Run full SSH diagnostic on a shard
     */
    public function diagnoseshard($params = []) {
        require_once __DIR__ . '/../services/ShardService.php';
        require_once __DIR__ . '/../services/ShardDiagnosticService.php';

        $shardId = (int)($params['operation']->name ?? 0);
        if (!$shardId) {
            $this->json(['success' => false, 'error' => 'Shard ID required']);
            return;
        }

        $shard = \app\services\ShardService::getShard($shardId);
        if (!$shard) {
            $this->json(['success' => false, 'error' => 'Shard not found']);
            return;
        }

        $diagnostic = new \app\services\ShardDiagnosticService($shard);
        $result = $diagnostic->runDiagnostic();

        // Save diagnostic result to database using bean (auto-creates columns if needed)
        $healthStatus = $result['ready'] ? 'healthy' : 'unhealthy';
        $shardBean = R::load('claudeshards', $shardId);
        $shardBean->ssh_validated = $result['ready'] ? 1 : 0;
        $shardBean->health_status = $healthStatus;
        $shardBean->last_health_check = date('Y-m-d H:i:s');
        R::store($shardBean);

        // If ready, also get install commands for anything missing
        if (!$result['ready']) {
            $result['install_commands'] = $diagnostic->getInstallCommands();
        }

        $this->json($result);
    }

    /**
     * Health check all shards
     */
    public function shardhealth($params = []) {
        require_once __DIR__ . '/../services/ShardService.php';

        $results = \app\services\ShardService::healthCheckAll();

        $this->json([
            'success' => true,
            'results' => $results
        ]);
    }

    /**
     * View/Edit MCP servers for a shard
     */
    public function shardmcp($params = []) {
        require_once __DIR__ . '/../services/ShardService.php';

        $shardId = (int) ($params['operation']->name ?? 0);
        if (!$shardId) {
            Flight::redirect('/admin/shards');
            exit;
        }

        $shard = \app\services\ShardService::getShard($shardId);
        if (!$shard) {
            Flight::redirect('/admin/shards');
            exit;
        }

        $executionMode = $shard['execution_mode'] ?? 'http_api';

        // For SSH mode, MCP is configured via .mcp.json when jobs start
        if ($executionMode === 'ssh_tmux') {
            $this->viewData['title'] = 'MCP Servers - ' . $shard['name'];
            $this->viewData['shard'] = $shard;
            $this->viewData['ssh_mode'] = true;
            $this->render('admin/shard_mcp');
            return;
        }

        // HTTP mode - get config from remote API
        $mcpServers = [];
        $availableServers = [];

        try {
            $client = new \GuzzleHttp\Client([
                'base_uri' => "http://{$shard['host']}:{$shard['port']}",
                'timeout' => 10,
                'headers' => ['Authorization' => 'Bearer ' . $shard['api_key']]
            ]);

            // Get current config
            $response = $client->get('/config/mcp');
            $data = json_decode($response->getBody()->getContents(), true);
            $mcpServers = $data['mcp_servers'] ?? [];

            // Get available servers
            $response = $client->get('/config/mcp/available');
            $data = json_decode($response->getBody()->getContents(), true);
            $availableServers = $data['available'] ?? [];

        } catch (\Exception $e) {
            $this->viewData['error'] = 'Could not connect to shard: ' . $e->getMessage();
        }

        // Handle POST (update MCP config)
        if (Flight::request()->method === 'POST') {
            $posted = Flight::request()->data;

            $newConfig = [];
            foreach ($posted['mcp'] ?? [] as $name => $server) {
                if (!empty($server['enabled'])) {
                    $newConfig[$name] = [
                        'command' => $server['command'] ?? 'npx',
                        'args' => array_filter(explode(' ', $server['args'] ?? '')),
                        'env' => [],
                        'enabled' => true
                    ];

                    // Parse env vars
                    if (!empty($server['env'])) {
                        foreach (explode("\n", $server['env']) as $line) {
                            $line = trim($line);
                            if (strpos($line, '=') !== false) {
                                list($key, $value) = explode('=', $line, 2);
                                $newConfig[$name]['env'][trim($key)] = trim($value);
                            }
                        }
                    }
                }
            }

            try {
                $client = new \GuzzleHttp\Client([
                    'base_uri' => "http://{$shard['host']}:{$shard['port']}",
                    'timeout' => 10,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $shard['api_key'],
                        'Content-Type' => 'application/json'
                    ]
                ]);

                $response = $client->post('/config/mcp', [
                    'json' => ['mcp_servers' => $newConfig]
                ]);

                $result = json_decode($response->getBody()->getContents(), true);

                // Update database using bean
                $shardBean = R::load('claudeshards', $shardId);
                $shardBean->mcp_servers = json_encode($newConfig);

                // Update capabilities based on enabled MCP servers
                $capabilities = ['git', 'filesystem'];
                foreach (array_keys($newConfig) as $mcpName) {
                    if (!in_array($mcpName, $capabilities)) {
                        $capabilities[] = $mcpName;
                    }
                }
                $shardBean->capabilities = json_encode($capabilities);
                R::store($shardBean);

                $this->viewData['success'] = 'MCP configuration updated successfully';
                $mcpServers = $result['mcp_servers'] ?? $newConfig;

            } catch (\Exception $e) {
                $this->viewData['error'] = 'Failed to update MCP config: ' . $e->getMessage();
            }
        }

        $this->viewData['title'] = 'MCP Servers - ' . $shard['name'];
        $this->viewData['shard'] = $shard;
        $this->viewData['mcp_servers'] = $mcpServers;
        $this->viewData['available_servers'] = $availableServers;

        $this->render('admin/shard_mcp');
    }
}