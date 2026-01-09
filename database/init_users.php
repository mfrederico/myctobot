<?php
/**
 * Initialize default users for the application
 * Run this script once to create the admin and public-user-entity
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap.php';

use RedBeanPHP\R;
use app\Bootstrap;

// Initialize the application
$app = new Bootstrap();

// Create admin user
$admin = R::findOne('member', 'username = ?', ['admin']);
if (!$admin) {
    $admin = R::dispense('member');
    $admin->username = 'admin';
    $admin->email = 'admin@localhost';
    $admin->password = password_hash('admin123', PASSWORD_DEFAULT); // Change this password!
    $admin->level = 0; // Root level
    $admin->status = 'active';
    $admin->created_at = date('Y-m-d H:i:s');
    $admin->updated_at = date('Y-m-d H:i:s');
    $adminId = R::store($admin);
    echo "Created admin user with ID: $adminId\n";
    echo "Default password: admin123 (PLEASE CHANGE THIS!)\n";
} else {
    echo "Admin user already exists\n";
}

// Create public user entity
$publicUser = R::findOne('member', 'username = ?', ['public-user-entity']);
if (!$publicUser) {
    $publicUser = R::dispense('member');
    $publicUser->username = 'public-user-entity';
    $publicUser->email = 'public@localhost';
    $publicUser->password = ''; // No password for public entity
    $publicUser->level = 101; // Public level
    $publicUser->status = 'active'; // Use 'active' instead of 'system'
    $publicUser->created_at = date('Y-m-d H:i:s');
    $publicUser->updated_at = date('Y-m-d H:i:s');
    $publicId = R::store($publicUser);
    echo "Created public-user-entity with ID: $publicId\n";
} else {
    echo "Public-user-entity already exists\n";
}

// Create initial authcontrol permissions
$permissions = [
    ['control' => 'index', 'method' => 'index', 'level' => 101, 'description' => 'Public homepage'],
    ['control' => 'auth', 'method' => 'login', 'level' => 101, 'description' => 'Login page'],
    ['control' => 'auth', 'method' => 'logout', 'level' => 100, 'description' => 'Logout action'],
    ['control' => 'auth', 'method' => 'register', 'level' => 101, 'description' => 'Registration page'],
    ['control' => 'member', 'method' => 'profile', 'level' => 100, 'description' => 'Member profile'],
    ['control' => 'member', 'method' => 'edit', 'level' => 100, 'description' => 'Edit profile'],
    ['control' => 'admin', 'method' => 'index', 'level' => 50, 'description' => 'Admin dashboard'],
    ['control' => 'admin', 'method' => 'members', 'level' => 50, 'description' => 'Member management'],
    ['control' => 'admin', 'method' => 'permissions', 'level' => 50, 'description' => 'Permission management'],
    ['control' => 'admin', 'method' => 'settings', 'level' => 50, 'description' => 'System settings'],

    // Enterprise tier - AI Developer (requires login, tier check in controller)
    ['control' => 'enterprise', 'method' => '*', 'level' => 100, 'description' => 'Enterprise AI Developer features'],

    // Webhooks - Public access (signature validation in controller)
    ['control' => 'webhook', 'method' => 'jira', 'level' => 101, 'description' => 'Jira webhook endpoint'],
    ['control' => 'webhook', 'method' => 'github', 'level' => 101, 'description' => 'GitHub webhook endpoint'],

    // Tenant signup - Public access (only works on default/public site)
    ['control' => 'signup', 'method' => 'index', 'level' => 101, 'description' => 'Tenant signup form'],
    ['control' => 'signup', 'method' => 'checksubdomain', 'level' => 101, 'description' => 'Check subdomain availability'],
    ['control' => 'signup', 'method' => 'dosignup', 'level' => 101, 'description' => 'Process tenant signup'],
];

foreach ($permissions as $perm) {
    $authControl = R::findOne('authcontrol', 'control = ? AND method = ?', [$perm['control'], $perm['method']]);
    if (!$authControl) {
        $authControl = R::dispense('authcontrol');
        $authControl->control = $perm['control'];
        $authControl->method = $perm['method'];
        $authControl->level = $perm['level'];
        $authControl->description = $perm['description'];
        $authControl->linkorder = 0;
        $authControl->validcount = 0;
        $authControl->created_at = date('Y-m-d H:i:s');
        R::store($authControl);
        echo "Created permission: {$perm['control']}/{$perm['method']}\n";
    }
}

echo "\nInitialization complete!\n";