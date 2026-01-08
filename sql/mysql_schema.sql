-- MyCTOBot MySQL Schema
-- Central database for authentication and OAuth tokens

-- Create database
CREATE DATABASE IF NOT EXISTS myctobot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE myctobot;

-- Member table (users)
CREATE TABLE IF NOT EXISTS member (
    id INT AUTO_INCREMENT PRIMARY KEY,
    google_id VARCHAR(255) UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    username VARCHAR(100),
    password VARCHAR(255),
    display_name VARCHAR(255),
    avatar_url TEXT,
    level INT NOT NULL DEFAULT 100,
    status ENUM('active', 'inactive', 'pending', 'system') DEFAULT 'active',
    ceobot_db VARCHAR(64),
    email_verified TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    last_login DATETIME,
    login_count INT DEFAULT 0,
    reset_token VARCHAR(64),
    reset_expires DATETIME,
    INDEX idx_google_id (google_id),
    INDEX idx_email (email),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Atlassian OAuth tokens (no underscore for RedBeanPHP compatibility)
CREATE TABLE IF NOT EXISTS atlassiantoken (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    access_token TEXT NOT NULL,
    refresh_token TEXT NOT NULL,
    token_type VARCHAR(50) DEFAULT 'Bearer',
    expires_at DATETIME NOT NULL,
    cloud_id VARCHAR(100) NOT NULL,
    site_url VARCHAR(500),
    site_name VARCHAR(255),
    scopes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES member(id) ON DELETE CASCADE,
    UNIQUE KEY uk_member_cloud (member_id, cloud_id),
    INDEX idx_member_id (member_id),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permission control (for route-based permissions)
CREATE TABLE IF NOT EXISTS authcontrol (
    id INT AUTO_INCREMENT PRIMARY KEY,
    control VARCHAR(100) NOT NULL,
    method VARCHAR(100) NOT NULL,
    level INT NOT NULL DEFAULT 100,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_control_method (control, method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User settings
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES member(id) ON DELETE CASCADE,
    UNIQUE KEY uk_member_key (member_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System settings
CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    description TEXT,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subscription/tier management (no underscore for RedBeanPHP compatibility)
CREATE TABLE IF NOT EXISTS subscription (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL UNIQUE,
    tier VARCHAR(20) NOT NULL DEFAULT 'free',
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    stripe_customer_id VARCHAR(255),
    stripe_subscription_id VARCHAR(255),
    current_period_start DATETIME,
    current_period_end DATETIME,
    trial_ends_at DATETIME,
    cancelled_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES member(id) ON DELETE CASCADE,
    INDEX idx_subscription_tier (tier),
    INDEX idx_subscription_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default public user for guest permissions
INSERT INTO member (id, username, email, password, display_name, level, status)
VALUES (1, 'public', 'public@system.local', '', 'Public User', 101, 'system')
ON DUPLICATE KEY UPDATE username = 'public';

-- Insert default permissions
INSERT INTO authcontrol (control, method, level, description) VALUES
('index', 'index', 101, 'Landing page'),
('auth', 'login', 101, 'Login page'),
('auth', 'dologin', 101, 'Process login'),
('auth', 'google', 101, 'Start Google OAuth'),
('auth', 'googlecallback', 101, 'Google OAuth callback'),
('auth', 'logout', 100, 'Logout'),
('dashboard', 'index', 100, 'Main dashboard'),
('atlassian', 'connect', 100, 'Start Atlassian OAuth'),
('atlassian', 'callback', 100, 'Atlassian OAuth callback'),
('atlassian', 'disconnect', 100, 'Disconnect Atlassian'),
('atlassian', 'refresh', 100, 'Refresh Atlassian tokens'),
('boards', 'index', 100, 'List boards'),
('boards', 'discover', 100, 'Discover Jira boards'),
('boards', 'add', 100, 'Add board'),
('boards', 'edit', 100, 'Edit board'),
('boards', 'remove', 100, 'Remove board'),
('boards', 'toggle', 100, 'Toggle board status'),
('analysis', 'index', 100, 'Analysis dashboard'),
('analysis', 'run', 100, 'Run analysis'),
('analysis', 'view', 100, 'View analysis'),
('analysis', 'email', 100, 'Email analysis'),
('settings', 'index', 100, 'Settings page'),
('settings', 'profile', 100, 'Edit profile'),
('settings', 'notifications', 100, 'Notification settings'),
('admin', 'index', 50, 'Admin dashboard'),
('admin', 'members', 50, 'Manage members'),
('api', 'crondigest', 1, 'Cron digest endpoint'),
('analysis', 'sharddigest', 1, 'Shard digest analysis endpoint')
ON DUPLICATE KEY UPDATE level = VALUES(level);

-- AI Agent profiles (runner configurations for AI Developer)
CREATE TABLE IF NOT EXISTS aiagents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    runner_type VARCHAR(50) NOT NULL DEFAULT 'claude_cli',
    runner_config JSON,
    mcp_servers JSON,
    hooks_config JSON,
    is_active TINYINT(1) DEFAULT 1,
    is_default TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES member(id) ON DELETE CASCADE,
    INDEX idx_member_id (member_id),
    INDEX idx_active (is_active),
    INDEX idx_default (is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pending signups (for email verification before provisioning)
CREATE TABLE IF NOT EXISTS pendingsignup (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subdomain VARCHAR(32) NOT NULL UNIQUE,
    business_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    verification_token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    resend_count INT DEFAULT 0,
    last_resend_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (verification_token),
    INDEX idx_expires (expires_at),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
