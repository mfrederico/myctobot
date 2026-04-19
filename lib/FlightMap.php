<?php
use \Flight as Flight;
use \RedBeanPHP\R as R; // Keep for Flight::register('R') below
use \app\Bean;
use \Exception as Exception;

// Define permission levels - customize as needed
define('LEVELS', ['ROOT'=>1, 'ADMIN'=>50, 'SALES'=>75, 'MEMBER'=>100, 'PUBLIC'=>101]);
define('DEFAULT_LANG', 'EN');
define('BASEURL', 'example.com'); // Change this to your domain
define('CLASS_NAMESPACE', 'app'); // Change this to your app namespace
define('SYSTEM_ADMIN_ID', 1);        // Root admin, owns system settings

//**************************************************
// Register any classes here for pre-FlightMapping
//**************************************************
$_REGISTER_CLASSES = ['Log', 'Permissions', 'Member'];

// Register initial classes
foreach($_REGISTER_CLASSES as $_CLASS) {
    Flight::register($_CLASS, '\\'.CLASS_NAMESPACE.'\\'.$_CLASS);
}

// Register RedBeanPHP
Flight::register('R', '\RedBeanPHP\R');

/**
 * Core routing function - handles /class/method/operation/id pattern
 * This is the heart of the auto-routing system
 */
Flight::map('defaultRoute', function($prefix = '') {
    Flight::get('log')->debug('Default Route: ', [basename(__FILE__).'@'.__LINE__]);
    
    Flight::route($prefix.'/(@class(/@method(/@op(/@opid(/.*?)))))', 
    function($class = null, $function = null, $operation = null, $operationid = null, $route = null) {
        Flight::view()->set('LEVELS', LEVELS);
        
        // Default to index if not specified
        if (empty($class)) $class = 'index';
        if (empty($function)) $function = 'index';
        
        Flight::get('log')->debug("Checking permission for {$class}->{$function}");
        
        // Check permissions
        if (Flight::permissionFor($class, $function, Flight::getMember()->level)) {
            
            // Merge request data
            foreach (Flight::request()->data as $k=>$v) {
                $_REQUEST[$k] = $v;
            }
            
            // Set up parameters - supports both $params['operation']->name and $params[0..N]
            $params['operation'] = new \stdClass();
            $params['operation']->name = $operation;
            $params['operation']->type = $operationid;
            $params['route'] = $route;

            // Build numeric splat: $params[0], $params[1], ... from all URL segments after /class/method/
            $allSegments = [$operation, $operationid];
            if ($route) {
                $extra = array_filter(explode('/', trim($route, '/')), fn($s) => $s !== '');
                $allSegments = array_merge($allSegments, $extra);
            }
            foreach ($allSegments as $i => $seg) {
                $params[$i] = $seg; // null-safe: $params[0]=null if no segments
            }
            
            // Instantiate and call controller
            $classname = ucfirst($class);
            try {
                $classname = '\\'.CLASS_NAMESPACE.'\\'.$classname;

                // Check if controller class exists before trying to instantiate
                if (!class_exists($classname)) {
                    Flight::get('log')->error("Controller not found: {$classname}");
                    Flight::notFound();
                    return;
                }

                $instance = new $classname;

                // Store route params on instance for helper access
                if (method_exists($instance, 'setRouteParams')) {
                    $instance->setRouteParams($params);
                }

                // Check if method exists and is callable
                if (method_exists($instance, $function)) {
                    $reflection = new ReflectionMethod($instance, $function);

                    // Only call if method is public
                    if ($reflection->isPublic()) {
                        Flight::get('log')->info("Calling: {$classname}->{$function}");
                        $instance->$function($params);
                    } else {
                        Flight::get('log')->error("Method not public: {$function}");
                        Flight::notFound();
                    }
                } else {
                    Flight::get('log')->error("Method not found: {$function}");
                    Flight::notFound();
                }
            } catch(\Throwable $e) {
                // Catch both Exception and Error (PHP 7+)
                Flight::get('log')->error("Controller error: ".$e->getMessage());
                Flight::notFound();
            }
        } else {
            Flight::get('log')->warning("Permission denied: {$class}->{$function}");
            
            // If user is logged in, show forbidden error instead of redirecting to login
            if (Flight::isLoggedIn()) {
                Flight::renderView('error/403', [
                    'title' => '403 - Forbidden',
                    'message' => 'You do not have permission to access this page.'
                ]);
            } else {
                // Only redirect to login if not logged in
                Flight::redirect('/auth/login?redirect='.urlencode(Flight::request()->url));
            }
        }
    });
});

/**
 * Permission checking function - Now uses PermissionCache for performance
 */
Flight::map('permissionFor', function($control, $function, $level = LEVELS['PUBLIC'], $wholeclass = false) {
    // Use the new PermissionCache for high-performance permission checking
    return \app\PermissionCache::check($control, $function, $level);
});

/**
 * Get current logged in member
 */
Flight::map('getMember', function() {
    if (!isset($_SESSION['member'])) {
        // Return guest member object
        $guest = new \stdClass();
        $guest->id = 0;
        $guest->level = LEVELS['PUBLIC'];
        $guest->username = 'Guest';
        $guest->email = '';
        return $guest;
    }

    // Refresh member data from database
    $member = Bean::load('member', $_SESSION['member']['id']);
    if ($member->id) {
        $_SESSION['member'] = $member->export();
        return $member;
    }

    // Invalid session
    unset($_SESSION['member']);
    return Flight::getMember(); // Return guest
});

/**
 * Check if user is logged in
 */
Flight::map('isLoggedIn', function() {
    return isset($_SESSION['member']) && $_SESSION['member']['id'] > 0;
});

/**
 * Check if user has Pro subscription tier
 */
Flight::map('isPro', function() {
    if (!Flight::isLoggedIn()) {
        return false;
    }
    $member = Flight::getMember();
    return \app\services\SubscriptionService::isPro($member->id);
});

/**
 * Check if user can access a specific feature
 */
Flight::map('canAccessFeature', function($feature) {
    if (!Flight::isLoggedIn()) {
        return false;
    }
    $member = Flight::getMember();
    return \app\services\SubscriptionService::canAccessFeature($member->id, $feature);
});

/**
 * Get user's subscription tier
 */
Flight::map('getTier', function() {
    if (!Flight::isLoggedIn()) {
        return 'free';
    }
    $member = Flight::getMember();
    return \app\services\SubscriptionService::getTier($member->id);
});

/**
 * Check if user has permission level
 */
Flight::map('hasLevel', function($requiredLevel) {
    $member = Flight::getMember();
    return $member->level <= $requiredLevel;
});

/**
 * Simple CSRF Protection
 * Generates and validates tokens stored in session
 */
class CsrfWrapper {
    private const SESSION_KEY = 'csrf_token';
    private const TOKEN_LENGTH = 32;

    /**
     * Get or generate CSRF token
     */
    public function getToken(): string {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(self::TOKEN_LENGTH));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Get token array for forms and views
     */
    public function getTokenArray(): array {
        $token = $this->getToken();
        return [
            'csrf_token' => $token,
            'token' => $token,
            'name' => 'X-CSRF-TOKEN',
            'field' => '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">'
        ];
    }

    /**
     * Validate CSRF from form POST data
     */
    public function validateRequest(): bool {
        $token = $_POST['csrf_token'] ?? '';
        return $this->validate($token);
    }

    /**
     * Validate CSRF from JSON request body
     * Also populates $_POST so subsequent code can access the JSON data
     */
    public function validateJson(): bool {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        if (!$input) {
            return false;
        }
        // Populate $_POST with JSON data for subsequent use
        $_POST = array_merge($_POST, $input);
        $token = $input['csrf_token'] ?? '';
        return $this->validate($token);
    }

    /**
     * Validate a token against session
     */
    private function validate(string $token): bool {
        if (empty($token) || empty($_SESSION[self::SESSION_KEY])) {
            return false;
        }
        return hash_equals($_SESSION[self::SESSION_KEY], $token);
    }

    /**
     * Regenerate token (call after successful validation if desired)
     */
    public function regenerate(): string {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(self::TOKEN_LENGTH));
        return $_SESSION[self::SESSION_KEY];
    }
}

Flight::map('csrf', function() {
    static $csrf = null;
    if ($csrf === null) {
        $csrf = new CsrfWrapper();
    }
    return $csrf;
});

/**
 * Render view with common data
 */
Flight::map('renderView', function($template, $data = []) {
    // Add common data to all views
    $data['member'] = Flight::getMember();
    $data['isLoggedIn'] = Flight::isLoggedIn();
    $data['levels'] = LEVELS;
    $data['baseurl'] = Flight::get('baseurl');
    $data['csrf'] = Flight::csrf()->getTokenArray();
    
    Flight::render($template, $data);
});


/**
 * JSON response helpers
 */
Flight::map('jsonSuccess', function($data = [], $message = 'Success') {
    Flight::json([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
});

Flight::map('jsonError', function($message = 'Error', $code = 400) {
    Flight::json([
        'success' => false,
        'message' => $message
    ], $code);
});

/**
 * Load site menu (customize for your app)
 */
Flight::map('loadMenu', function() {
    $menu = [];

    if (Flight::isLoggedIn()) {
        // Detect current studio from URL path (takes priority over saved preference)
        $currentPath = $_SERVER['REQUEST_URI'] ?? '';
        $urlStudio = null;
        if (preg_match('#^/studio/(developer|commerce|pipeline|sales)#', $currentPath, $matches)) {
            $urlStudio = $matches[1];
        }

        // Use URL-detected studio if on a studio page, otherwise use saved preference
        $activeStudio = $urlStudio ?? Flight::getStudio();

        $studioLabels = [
            'developer' => 'Developer',
            'commerce' => 'Commerce',
            'pipeline' => 'Pipeline',
            'sales' => 'Sales'
        ];
        $studioIcons = [
            'developer' => 'code-slash',
            'commerce' => 'shop',
            'pipeline' => 'diagram-3',
            'sales' => 'megaphone'
        ];

        $studioLabel = $activeStudio ? ($studioLabels[$activeStudio] ?? 'Studio') : 'Studio';
        $studioIcon = $activeStudio ? ($studioIcons[$activeStudio] ?? 'lightning-charge') : 'lightning-charge';

        $menu[] = [
            'url' => '/studio',
            'label' => $studioLabel,
            'icon' => $studioIcon,
            'dropdown' => [
                ['url' => '/studio/developer', 'label' => 'Developer Studio', 'icon' => 'code-slash', 'active' => ($activeStudio === 'developer')],
                ['url' => '/studio/commerce', 'label' => 'Commerce Studio', 'icon' => 'shop', 'active' => ($activeStudio === 'commerce')],
                ['url' => '/studio/pipeline', 'label' => 'Pipeline Studio', 'icon' => 'diagram-3', 'active' => ($activeStudio === 'pipeline')],
                ['url' => '/studio/sales', 'label' => 'Sales & Marketing', 'icon' => 'megaphone', 'active' => ($activeStudio === 'sales')],
                ['divider' => true],
                ['url' => '/studio/select', 'label' => 'Switch Studio...', 'icon' => 'arrow-left-right']
            ]
        ];

        // Studio-specific menu items
        if ($activeStudio === 'developer') {
            $menu[] = ['url' => '/activity?type=aidev', 'label' => 'AI Dev Jobs', 'icon' => 'cpu'];
            $menu[] = ['url' => '/agents', 'label' => 'Agents', 'icon' => 'robot'];
            $menu[] = ['url' => '/settings/repos', 'label' => 'Repositories', 'icon' => 'github'];
            $menu[] = ['url' => '/settings/boards', 'label' => 'Jira Boards', 'icon' => 'kanban'];
        } elseif ($activeStudio === 'commerce') {
            $menu[] = ['url' => '/shopify', 'label' => 'Stores', 'icon' => 'shop'];
            $menu[] = ['url' => '/apps?target=shopify', 'label' => 'Shopify Apps', 'icon' => 'box-arrow-up-right'];
        } elseif ($activeStudio === 'pipeline') {
            $menu[] = ['url' => '/pipelines', 'label' => 'Pipelines', 'icon' => 'diagram-3'];
            $menu[] = ['url' => '/apps', 'label' => 'Apps', 'icon' => 'box-seam'];
            $menu[] = ['url' => '/mcpservers', 'label' => 'MCP Servers', 'icon' => 'plug'];
        } elseif ($activeStudio === 'sales') {
            $menu[] = ['url' => '/crm', 'label' => 'CRM', 'icon' => 'person-rolodex'];
            $menu[] = ['url' => '/crm/pipeline', 'label' => 'Pipeline', 'icon' => 'kanban'];
            $menu[] = ['url' => '/crm/contacts', 'label' => 'Contacts', 'icon' => 'address-book'];
            $menu[] = ['url' => '/livechat', 'label' => 'Live Chat', 'icon' => 'headset'];
            $menu[] = ['url' => '/livechat/chatbots', 'label' => 'Chatbots', 'icon' => 'robot'];
            $menu[] = ['url' => '/agreement/view', 'label' => 'Agreement', 'icon' => 'file-earmark-check'];
        } else {
            // Default menu when no studio selected
            $menu[] = ['url' => '/dashboard', 'label' => 'Dashboard', 'icon' => 'speedometer2'];
            $menu[] = ['url' => '/pipelines', 'label' => 'Pipelines', 'icon' => 'diagram-3'];
        }

        // Common items
        $menu[] = ['url' => '/activity', 'label' => 'Activity', 'icon' => 'activity'];
        $menu[] = ['url' => '/workstations', 'label' => 'Workstations', 'icon' => 'pc-display'];
        $menu[] = ['url' => '/knowledgebase', 'label' => 'Knowledge Base', 'icon' => 'book'];
        $menu[] = ['url' => '/settings/connections', 'label' => 'Connectors', 'icon' => 'plug'];

        // Admin menu items
        if (Flight::hasLevel(LEVELS['ADMIN'])) {
            $menu[] = ['url' => '/admin', 'label' => 'Admin', 'icon' => 'shield-lock'];
        }
    } else {
        // Public menu items
        $menu[] = ['url' => '/', 'label' => 'Home', 'icon' => 'house'];
        $menu[] = ['url' => '/auth/login', 'label' => 'Login', 'icon' => 'sign-in'];
        $menu[] = ['url' => '/auth/register', 'label' => 'Register', 'icon' => 'user-plus'];
    }

    return $menu;
});

/**
 * Error handlers
 */
Flight::map('notFound', function() {
    Flight::response()->status(404);
    Flight::renderView('error/404', [
        'title' => '404 - Page Not Found'
    ]);
    Flight::stop();
});

Flight::map('error', function($ex) {
    // Log full exception details including backtrace
    Flight::get('log')->error('Exception: ' . $ex->getMessage(), [
        'file' => $ex->getFile(),
        'line' => $ex->getLine(),
        'trace' => $ex->getTraceAsString()
    ]);
    
    // Prepare error data for view
    $errorData = [
        'title' => '500 - Server Error',
        'error' => 'An error occurred'
    ];
    
    // In debug/development mode, pass full exception details
    if (Flight::get('debug') || Flight::get('development')) {
        $errorData['exception'] = $ex;
        $errorData['error'] = $ex->getMessage();
        $errorData['file'] = $ex->getFile();
        $errorData['line'] = $ex->getLine();
        $errorData['trace'] = $ex->getTrace();
        $errorData['traceString'] = $ex->getTraceAsString();
    }
    
    Flight::renderView('error/500', $errorData);
});

/**
 * Utility functions
 */
Flight::map('isOn', function($val) {
    if (empty($val)) return false;
    if (is_string($val)) return preg_match('/ON|1|TRUE|YES|ALWAYS|DO/', strtoupper($val));
    elseif (is_bool($val)) return $val;
    else return false;
});

Flight::map('isOff', function($val) {
    if (is_string($val)) return preg_match('/OFF|0|FALSE|NO|NEVER|NOT/', strtoupper($val));
    elseif (is_bool($val)) return !$val;
    else return false;
});

/**
 * Setting management helpers
 * Uses RedBeanPHP associations: member->ownSettingsList
 * Note: getSetting/setSetting now only use the current user's ID from session
 * Use getSystemSetting/setSystemSetting for system-wide settings (member_id = NULL)
 */
Flight::map('getSetting', function($key) {
    $member = Flight::getMember();

    // Use association to find setting (lazy loads all member settings)
    foreach ($member->ownSettingsList as $setting) {
        if ($setting->setting_key === $key) {
            return $setting->setting_value;
        }
    }
    return null;
});

Flight::map('setSetting', function($key, $value) {
    $member = Flight::getMember();

    // Find existing setting in member's list
    $setting = null;
    foreach ($member->ownSettingsList as $existingSetting) {
        if ($existingSetting->setting_key === $key) {
            $setting = $existingSetting;
            break;
        }
    }

    if (!$setting) {
        // Create new setting via association
        $setting = Bean::dispense('settings');
        $setting->setting_key = $key;
        $member->ownSettingsList[] = $setting;
    }

    $setting->setting_value = $value;
    $setting->updated_at = date('Y-m-d H:i:s');

    return Bean::store($member);
});

/**
 * System-wide settings (member_id = NULL)
 * These are global application settings not tied to any user
 */
Flight::map('getSystemSetting', function($key) {
    $setting = Bean::findOne('settings', 'member_id IS NULL AND setting_key = ?', [$key]);
    return $setting ? $setting->setting_value : null;
});

Flight::map('setSystemSetting', function($key, $value) {
    $setting = Bean::findOne('settings', 'member_id IS NULL AND setting_key = ?', [$key]);
    if (!$setting) {
        $setting = Bean::dispense('settings');
        $setting->memberId = null; // System-wide settings have NULL member_id
        $setting->settingKey = $key;
    }
    $setting->settingValue = $value;
    $setting->updatedAt = date('Y-m-d H:i:s');

    return Bean::store($setting);
});

/**
 * Studio preference helpers
 * Get/set the user's active studio (developer, commerce, pipeline)
 */
Flight::map('getStudio', function() {
    return Flight::getSetting('active_studio');
});

Flight::map('setStudio', function($studio) {
    $valid = ['developer', 'apps', 'commerce', 'pipeline', 'sales'];
    if (!in_array($studio, $valid)) {
        return false;
    }
    return Flight::setSetting('active_studio', $studio);
});
