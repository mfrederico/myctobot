-- PHP Framework Starter - Database Schema
-- Compatible with MySQL/MariaDB
-- RedBeanPHP will auto-create additional tables as needed

-- Members table (users)
CREATE TABLE IF NOT EXISTS `member` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL COMMENT 'NULL for OAuth-only users',
  `level` int(11) DEFAULT 100 COMMENT '1=Root, 50=Admin, 100=Member',
  `status` enum('active','pending','suspended','deleted') DEFAULT 'pending',
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `avatar_url` varchar(500) DEFAULT NULL COMMENT 'External avatar URL (e.g., from OAuth)',
  `google_id` varchar(255) DEFAULT NULL COMMENT 'Google OAuth user ID',
  `bio` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `timezone` varchar(50) DEFAULT 'UTC',
  `last_login` datetime DEFAULT NULL,
  `login_count` int(11) DEFAULT 0,
  `verification_token` varchar(255) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `google_id` (`google_id`),
  KEY `status` (`status`),
  KEY `level` (`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Permissions table
CREATE TABLE IF NOT EXISTS `authcontrol` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `control` varchar(100) NOT NULL COMMENT 'Controller name',
  `method` varchar(100) NOT NULL COMMENT 'Method name or * for all',
  `level` int(11) DEFAULT 100 COMMENT 'Required permission level',
  `description` text DEFAULT NULL,
  `validcount` int(11) DEFAULT 0 COMMENT 'Count of successful permission checks',
  `linkorder` int(11) DEFAULT 0 COMMENT 'Display order in UI',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `control_method` (`control`, `method`),
  KEY `level` (`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Settings table (key-value store per member)
CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` int(11) UNSIGNED DEFAULT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_setting` (`member_id`, `setting_key`),
  KEY `member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sessions table (optional - for database sessions)
CREATE TABLE IF NOT EXISTS `sessions` (
  `id` varchar(128) NOT NULL,
  `member_id` int(11) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` text NOT NULL,
  `last_activity` int(11) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `last_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Activity log table (optional - for auditing)
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` int(11) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `controller` varchar(100) DEFAULT NULL,
  `method` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `data` json DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `action` (`action`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default permissions
INSERT INTO `authcontrol` (`control`, `method`, `level`, `description`) VALUES
-- Public routes
('index', 'index', 101, 'Home page'),
('auth', 'login', 101, 'Login page'),
('auth', 'dologin', 101, 'Process login'),
('auth', 'register', 101, 'Registration page'),
('auth', 'doregister', 101, 'Process registration'),
('auth', 'forgot', 101, 'Forgot password page'),
('auth', 'doforgot', 101, 'Process forgot password'),
('auth', 'reset', 101, 'Reset password page'),
('auth', 'doreset', 101, 'Process reset password'),
('auth', 'google', 101, 'Google OAuth login'),
('auth', 'googlecallback', 101, 'Google OAuth callback'),
('auth', 'logout', 100, 'Logout'),

-- Member routes
('member', '*', 100, 'All member methods'),
('dashboard', '*', 100, 'Dashboard access'),
('profile', '*', 100, 'Profile management'),

-- Admin routes
('admin', '*', 50, 'Admin panel access'),
('permissions', '*', 50, 'Permission management'),

-- Root only
('permissions', 'build', 1, 'Build mode - scan controllers'),
('permissions', 'scan', 1, 'Scan for new permissions')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- Insert default admin user (change password immediately!)
-- Password: admin123
INSERT INTO `member` (`email`, `username`, `password`, `level`, `status`, `created_at`) VALUES
('admin@example.com', 'admin', '$2y$10$YNkKqPPGgTXMPkXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX', 1, 'active', NOW())
ON DUPLICATE KEY UPDATE `id` = `id`;

-- Note: The password hash above is a placeholder. 
-- Generate a real hash with: password_hash('your_password', PASSWORD_DEFAULT)