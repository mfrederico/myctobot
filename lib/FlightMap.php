<?php
use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \ParagonIE\AntiCSRF\AntiCSRF;

// Define permission levels - customize as needed
define('LEVELS', ['ROOT'=>1, 'ADMIN'=>50, 'MEMBER'=>100, 'PUBLIC'=>101]);
define('DEFAULT_LANG', 'EN');
define('BASEURL', 'example.com'); // Change this to your domain
define('CLASS_NAMESPACE', 'app'); // Change this to your app namespace

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
            
            // Set up parameters
            $params['operation'] = new \stdClass();
            $params['operation']->name = $operation;
            $params['operation']->type = $operationid;
            $params['route'] = $route;
            
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
    $member = R::load('member', $_SESSION['member']['id']);
    if ($member->id) {
        $_SESSION['member'] = $member->export();

        // Register user database if member has one (Enterprise feature)
        // This allows R::selectDatabase('user_X') anywhere in the request
        if (!empty($member->ceobot_db)) {
            $dbKey = 'user_' . $member->id;
            $dbDir = Flight::get('ceobot.user_db_path') ?? 'database/';
            $dbPath = $dbDir . $member->ceobot_db . '.sqlite';

            // Only add if file exists and not already registered
            if (file_exists($dbPath)) {
                try {
                    R::selectDatabase($dbKey);
                    // Already registered, switch back to default
                    R::selectDatabase('default');
                } catch (\Throwable $e) {
                    // Not registered yet, add it
                    R::addDatabase($dbKey, 'sqlite:' . $dbPath);
                    R::selectDatabase('default');
                }
            }
        }

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
 * CSRF Protection
 */
Flight::map('csrf', function() {
    static $csrf = null;
    if ($csrf === null) {
        $csrf = new AntiCSRF();
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
    
    // Public menu items
    $menu[] = ['url' => '/', 'label' => 'Home', 'icon' => 'home'];
    
    if (Flight::isLoggedIn()) {
        // Member menu items
        $menu[] = ['url' => '/dashboard', 'label' => 'Dashboard', 'icon' => 'speedometer2'];

        // Enterprise tier - AI Developer
        $member = Flight::get('member');
        if ($member && $member->isEnterprise()) {
            $menu[] = ['url' => '/enterprise', 'label' => 'AI Developer', 'icon' => 'robot'];
        }

        // Admin menu items
        if (Flight::hasLevel(LEVELS['ADMIN'])) {
            $menu[] = ['url' => '/admin', 'label' => 'Admin', 'icon' => 'gear'];
        }
    } else {
        $menu[] = ['url' => '/auth/login', 'label' => 'Login', 'icon' => 'sign-in'];
        $menu[] = ['url' => '/auth/register', 'label' => 'Register', 'icon' => 'user-plus'];
    }
    
    return $menu;
});

/**
 * Error handlers
 */
Flight::map('notFound', function() {
    Flight::renderView('error/404', [
        'title' => '404 - Page Not Found'
    ]);
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
        $setting = R::dispense('settings');
        $setting->setting_key = $key;
        $member->ownSettingsList[] = $setting;
    }

    $setting->setting_value = $value;
    $setting->updated_at = date('Y-m-d H:i:s');

    return R::store($member);
});

/**
 * System-wide settings (member_id = NULL)
 * These are global application settings not tied to any user
 */
Flight::map('getSystemSetting', function($key) {
    $setting = R::findOne('settings', 'member_id IS NULL AND setting_key = ?', [$key]);
    return $setting ? $setting->setting_value : null;
});

Flight::map('setSystemSetting', function($key, $value) {
    $setting = R::findOne('settings', 'member_id IS NULL AND setting_key = ?', [$key]);
    if (!$setting) {
        $setting = R::dispense('settings');
        $setting->memberId = null; // System-wide settings have NULL member_id
        $setting->settingKey = $key;
    }
    $setting->settingValue = $value;
    $setting->updatedAt = date('Y-m-d H:i:s');

    return R::store($setting);
});
