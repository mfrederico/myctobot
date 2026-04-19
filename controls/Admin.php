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
     * Runners/workstations — redirect to dedicated controller
     */
    public function runners() {
        Flight::redirect('/workstations');
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
