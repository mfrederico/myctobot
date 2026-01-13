<?php
/**
 * Permissions Controller
 * Manages role-based access control
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;

class Permissions extends BaseControls\Control {
    
    private static $cache = [];
    
    /**
     * Check permission for a controller/method combination
     */
    public function permfor($control, $method, $level = LEVELS['PUBLIC'], $wholeclass = false) {
        // Normalize names
        $control = strtolower($control);
        $method = strtolower($method);
        
        // Check cache first
        $cacheKey = "{$control}:{$method}:{$level}";
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }
        
        // Some routes are always public
        $publicRoutes = [
            'index:index',
            'auth:login',
            'auth:dologin',
            'auth:logout',  // Logout should always be accessible
            'auth:register',
            'auth:doregister',
            'auth:forgot',
            'auth:doforgot',
            'auth:reset',
            'auth:doreset',
            'error:notfound',
            'error:forbidden',
            'error:servererror'
        ];
        
        if (in_array("{$control}:{$method}", $publicRoutes)) {
            self::$cache[$cacheKey] = true;
            return true;
        }
        
        // Check database for permission
        $auth = R::findOne('authcontrol', 'control = ? AND method = ?', [$control, $method]);
        
        if (!$auth) {
            // Check for wildcard permission (entire controller)
            $auth = R::findOne('authcontrol', 'control = ? AND method = ?', [$control, '*']);
        }
        
        if ($auth) {
            // Use level field
            $requiredLevel = $auth->level ?? LEVELS['PUBLIC'];
            $hasPermission = $level <= $requiredLevel;
            self::$cache[$cacheKey] = $hasPermission;
            return $hasPermission;
        }
        
        // In build mode, auto-create permission
        if (Flight::get('build')) {
            $this->logger->info("Build mode: Creating permission for {$control}:{$method}");
            
            $auth = R::dispense('authcontrol');
            $auth->control = $control;
            $auth->method = $method;
            $auth->level = LEVELS['ADMIN']; // Default to admin
            $auth->description = "Auto-generated permission for {$control}:{$method}";
            $auth->created_at = date('Y-m-d H:i:s');
            R::store($auth);

            // Clear cache after creating permission
            \app\PermissionCache::clear();

            // Admin level required by default
            $hasPermission = $level <= LEVELS['ADMIN'];
            self::$cache[$cacheKey] = $hasPermission;
            return $hasPermission;
        }
        
        // Default deny
        self::$cache[$cacheKey] = false;
        return false;
    }
    
    /**
     * Admin interface for managing permissions
     */
    public function index() {
        // Require admin access
        if (!$this->requireLevel(LEVELS['ADMIN'])) {
            return;
        }
        
        // Get all permissions
        $permissions = R::findAll('authcontrol', 'ORDER BY control, method');
        
        $this->render('permissions/index', [
            'title' => 'Permission Management',
            'permissions' => $permissions,
            'levels' => LEVELS
        ]);
    }
    
    /**
     * Edit permission
     */
    public function edit($params) {
        // Require admin access
        if (!$this->requireLevel(LEVELS['ADMIN'])) {
            return;
        }
        
        $id = $this->opType() ?? 0;
        
        if ($id) {
            $permission = R::load('authcontrol', $id);
            if (!$permission->id) {
                $this->flash('error', 'Permission not found');
                Flight::redirect('/permissions');
                return;
            }
        } else {
            $permission = R::dispense('authcontrol');
        }
        
        $this->render('permissions/edit', [
            'title' => $id ? 'Edit Permission' : 'Add Permission',
            'permission' => $permission,
            'levels' => LEVELS
        ]);
    }
    
    /**
     * Save permission
     */
    public function save() {
        // Require admin access
        if (!$this->requireLevel(LEVELS['ADMIN'])) {
            return;
        }
        
        // Validate CSRF
        if (!$this->validateCSRF()) {
            return;
        }
        
        try {
            $id = $this->getParam('id', 0);
            
            if ($id) {
                $permission = R::load('authcontrol', $id);
                if (!$permission->id) {
                    throw new Exception('Permission not found');
                }
            } else {
                $permission = R::dispense('authcontrol');
                $permission->created_at = date('Y-m-d H:i:s');
            }
            
            $permission->control = strtolower($this->sanitize($this->getParam('control')));
            $permission->method = strtolower($this->sanitize($this->getParam('method')));
            $permission->level = (int)$this->getParam('level');
            $permission->description = $this->sanitize($this->getParam('description'));
            $permission->updated_at = date('Y-m-d H:i:s');
            
            R::store($permission);

            // Clear both local and APCu cache
            self::$cache = [];
            \app\PermissionCache::clear();

            $this->flash('success', 'Permission saved successfully');
            Flight::redirect('/permissions');
            
        } catch (Exception $e) {
            $this->handleException($e, 'Failed to save permission');
        }
    }
    
    /**
     * Delete permission
     */
    public function delete() {
        // Require admin access
        if (!$this->requireLevel(LEVELS['ADMIN'])) {
            return;
        }
        
        // Validate CSRF
        if (!$this->validateCSRF()) {
            return;
        }
        
        try {
            $id = $this->getParam('id');
            $permission = R::load('authcontrol', $id);
            
            if ($permission->id) {
                R::trash($permission);

                // Clear both local and APCu cache
                self::$cache = [];
                \app\PermissionCache::clear();

                $this->jsonSuccess([], 'Permission deleted');
            } else {
                $this->jsonError('Permission not found', 404);
            }
            
        } catch (Exception $e) {
            $this->logger->error('Delete permission failed: ' . $e->getMessage());
            $this->jsonError('Failed to delete permission', 500);
        }
    }
    
    /**
     * Build mode - scan controllers and create permissions
     */
    public function build() {
        // Require admin access
        if (!$this->requireLevel(LEVELS['ROOT'])) {
            return;
        }
        
        $this->render('permissions/build', [
            'title' => 'Build Permissions'
        ]);
    }
    
    /**
     * Scan controllers and suggest permissions
     */
    public function scan() {
        // Require admin access
        if (!$this->requireLevel(LEVELS['ROOT'])) {
            return;
        }
        
        try {
            $controllerPath = __DIR__;
            $controllers = glob($controllerPath . '/*.php');
            $suggestions = [];
            
            foreach ($controllers as $file) {
                $className = basename($file, '.php');
                
                // Skip base classes
                if (in_array($className, ['BaseControls', 'Control'])) {
                    continue;
                }
                
                $class = '\\app\\' . $className;
                
                if (class_exists($class)) {
                    $reflection = new \ReflectionClass($class);
                    $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
                    
                    foreach ($methods as $method) {
                        // Skip constructor and inherited methods
                        if ($method->name === '__construct' || 
                            $method->getDeclaringClass()->getName() !== $class) {
                            continue;
                        }
                        
                        // Only include lowercase methods (FlightPHP convention)
                        if ($method->name === strtolower($method->name)) {
                            $controlName = strtolower($className);
                            $methodName = strtolower($method->name);
                            
                            // Check if permission exists
                            $exists = R::count('authcontrol', 
                                'control = ? AND method = ?', 
                                [$controlName, $methodName]) > 0;
                            
                            if (!$exists) {
                                $suggestions[] = [
                                    'control' => $controlName,
                                    'method' => $methodName,
                                    'class' => $className,
                                    'suggested_level' => $this->suggestLevel($controlName, $methodName)
                                ];
                            }
                        }
                    }
                }
            }
            
            $this->jsonSuccess($suggestions, count($suggestions) . ' new permissions found');
            
        } catch (Exception $e) {
            $this->logger->error('Permission scan failed: ' . $e->getMessage());
            $this->jsonError('Scan failed: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Suggest permission level based on controller/method name
     */
    private function suggestLevel($control, $method) {
        // Admin controllers
        if (in_array($control, ['admin', 'permissions', 'settings'])) {
            return LEVELS['ADMIN'];
        }
        
        // Write operations
        if (preg_match('/^(create|add|edit|update|delete|save|do)/', $method)) {
            return LEVELS['MEMBER'];
        }
        
        // Member areas
        if (in_array($control, ['member', 'profile', 'dashboard'])) {
            return LEVELS['MEMBER'];
        }
        
        // Default to member level
        return LEVELS['MEMBER'];
    }
}