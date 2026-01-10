-- MyCTOBot Tenant Database Schema
-- Single database containing ALL tables for a tenant instance
-- Used for both default (myctobot.ai) and subdomain tenants (acme.myctobot.ai)
--
-- Usage: mysql -u root -p myctobot_tenantname < sql/tenant_schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- CORE TABLES (from MySQL)
-- ============================================================================

-- Member table (users within this tenant)
CREATE TABLE IF NOT EXISTS `member` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `google_id` VARCHAR(255) UNIQUE,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `username` VARCHAR(100),
    `password` VARCHAR(255),
    `display_name` VARCHAR(255),
    `avatar_url` TEXT,
    `level` INT NOT NULL DEFAULT 100,
    `status` ENUM('active', 'inactive', 'pending', 'system') DEFAULT 'active',
    `email_verified` TINYINT(1) DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    `last_login` DATETIME,
    `login_count` INT DEFAULT 0,
    `reset_token` VARCHAR(64),
    `reset_expires` DATETIME,
    INDEX `idx_google_id` (`google_id`),
    INDEX `idx_email` (`email`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Atlassian OAuth tokens
CREATE TABLE IF NOT EXISTS `atlassiantoken` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT NOT NULL,
    `access_token` TEXT NOT NULL,
    `refresh_token` TEXT NOT NULL,
    `token_type` VARCHAR(50) DEFAULT 'Bearer',
    `expires_at` DATETIME NOT NULL,
    `cloud_id` VARCHAR(100) NOT NULL,
    `site_url` VARCHAR(500),
    `site_name` VARCHAR(255),
    `scopes` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`member_id`) REFERENCES `member`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_member_cloud` (`member_id`, `cloud_id`),
    INDEX `idx_member_id` (`member_id`),
    INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permission control (route-based permissions)
CREATE TABLE IF NOT EXISTS `authcontrol` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `control` VARCHAR(100) NOT NULL,
    `method` VARCHAR(100) NOT NULL,
    `level` INT NOT NULL DEFAULT 100,
    `description` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_control_method` (`control`, `method`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User settings (per-member)
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT NOT NULL,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`member_id`) REFERENCES `member`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_member_key` (`member_id`, `setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subscription/tier management
CREATE TABLE IF NOT EXISTS `subscription` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT NOT NULL UNIQUE,
    `tier` VARCHAR(20) NOT NULL DEFAULT 'free',
    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
    `stripe_customer_id` VARCHAR(255),
    `stripe_subscription_id` VARCHAR(255),
    `current_period_start` DATETIME,
    `current_period_end` DATETIME,
    `trial_ends_at` DATETIME,
    `cancelled_at` DATETIME,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`member_id`) REFERENCES `member`(`id`) ON DELETE CASCADE,
    INDEX `idx_subscription_tier` (`tier`),
    INDEX `idx_subscription_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- JIRA/BOARD TABLES (from SQLite)
-- ============================================================================

-- Jira boards configuration
CREATE TABLE IF NOT EXISTS `jiraboards` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `board_id` INT NOT NULL,
    `board_name` VARCHAR(255) NOT NULL,
    `project_key` VARCHAR(50) NOT NULL,
    `cloud_id` VARCHAR(100) NOT NULL,
    `board_type` VARCHAR(20) DEFAULT 'scrum',
    `enabled` TINYINT(1) DEFAULT 1,
    `digest_enabled` TINYINT(1) DEFAULT 0,
    `digest_time` VARCHAR(10) DEFAULT '08:00',
    `digest_cc` TEXT,
    `timezone` VARCHAR(50) DEFAULT 'UTC',
    `status_filter` VARCHAR(100) DEFAULT 'To Do',
    `priority_weights` TEXT,
    `goals` TEXT,
    `last_analysis_at` DATETIME,
    `last_digest_at` DATETIME,
    `aidev_status_working` VARCHAR(100) DEFAULT NULL,
    `aidev_status_pr_created` VARCHAR(100) DEFAULT NULL,
    `aidev_status_clarification` VARCHAR(100) DEFAULT NULL,
    `aidev_status_failed` VARCHAR(100) DEFAULT NULL,
    `aidev_status_complete` VARCHAR(100) DEFAULT NULL,
    `aidev_anthropic_key_id` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_board_cloud` (`board_id`, `cloud_id`),
    INDEX `idx_cloud_id` (`cloud_id`),
    INDEX `idx_enabled` (`enabled`),
    INDEX `idx_digest` (`digest_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analysis results
CREATE TABLE IF NOT EXISTS `analysisresults` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `board_id` INT NOT NULL,
    `analysis_type` VARCHAR(50) NOT NULL,
    `content_json` LONGTEXT NOT NULL,
    `content_markdown` LONGTEXT,
    `issue_count` INT,
    `status_filter` VARCHAR(100),
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`board_id`) REFERENCES `jiraboards`(`id`) ON DELETE CASCADE,
    INDEX `idx_board` (`board_id`),
    INDEX `idx_type` (`analysis_type`),
    INDEX `idx_created` (`created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Digest history
CREATE TABLE IF NOT EXISTS `digesthistory` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `board_id` INT NOT NULL,
    `sent_to` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(255),
    `content_preview` TEXT,
    `status` VARCHAR(20) DEFAULT 'sent',
    `error_message` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`board_id`) REFERENCES `jiraboards`(`id`) ON DELETE CASCADE,
    INDEX `idx_board` (`board_id`),
    INDEX `idx_created` (`created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ticket analysis cache
CREATE TABLE IF NOT EXISTS `ticketanalysiscache` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `board_id` INT NOT NULL,
    `issue_key` VARCHAR(50) NOT NULL,
    `content_hash` VARCHAR(64) NOT NULL,
    `clarity_score` INT,
    `clarity_analysis` TEXT,
    `reporter_name` VARCHAR(255),
    `reporter_email` VARCHAR(255),
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_board_issue` (`board_id`, `issue_key`),
    FOREIGN KEY (`board_id`) REFERENCES `jiraboards`(`id`) ON DELETE CASCADE,
    INDEX `idx_hash` (`content_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- AI DEVELOPER TABLES (from SQLite)
-- ============================================================================

-- AI Developer jobs
CREATE TABLE IF NOT EXISTS `aidevjobs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `job_id` VARCHAR(64) NOT NULL UNIQUE,
    `member_id` INT NOT NULL,
    `issue_key` VARCHAR(50) NOT NULL,
    `board_id` INT NOT NULL,
    `repo_connection_id` INT,
    `cloud_id` VARCHAR(100),
    `status` VARCHAR(30) DEFAULT 'pending',
    `progress` INT DEFAULT 0,
    `current_step` VARCHAR(100) DEFAULT 'Initializing',
    `steps_completed` JSON,
    `current_shard_job_id` VARCHAR(64),
    `branch_name` VARCHAR(255),
    `pr_url` VARCHAR(500),
    `pr_number` INT,
    `pr_created_at` DATETIME,
    `clarification_comment_id` VARCHAR(50),
    `clarification_questions` JSON,
    `error_message` TEXT,
    `run_count` INT DEFAULT 0,
    `files_changed` JSON,
    `commit_sha` VARCHAR(64),
    `shopify_theme_id` INT,
    `shopify_preview_url` VARCHAR(500),
    `playwright_results` JSON,
    `preserve_branch` TINYINT(1) DEFAULT 1,
    `started_at` DATETIME,
    `completed_at` DATETIME,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_member` (`member_id`),
    INDEX `idx_board` (`board_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_issue` (`issue_key`),
    INDEX `idx_member_issue` (`member_id`, `issue_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI Developer job logs
CREATE TABLE IF NOT EXISTS `aidevjoblogs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `issue_key` VARCHAR(50) NOT NULL,
    `log_level` VARCHAR(20) DEFAULT 'info',
    `message` TEXT NOT NULL,
    `context_json` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_issue` (`issue_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- REPOSITORY TABLES (from SQLite)
-- ============================================================================

-- GitHub/repo connections
CREATE TABLE IF NOT EXISTS `repoconnections` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `connection_name` VARCHAR(255),
    `provider` VARCHAR(30) DEFAULT 'github',
    `repo_owner` VARCHAR(100) NOT NULL,
    `repo_name` VARCHAR(100) NOT NULL,
    `default_branch` VARCHAR(100) DEFAULT 'main',
    `clone_url` VARCHAR(500),
    `access_token` TEXT,
    `enabled` TINYINT(1) DEFAULT 1,
    `issues_enabled` TINYINT(1) DEFAULT 0,
    `webhook_id` VARCHAR(50),
    `webhook_secret` VARCHAR(100),
    `agent_id` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_agent` (`agent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Board to repo mapping
CREATE TABLE IF NOT EXISTS `boardrepomapping` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `board_id` INT NOT NULL,
    `repo_connection_id` INT NOT NULL,
    `is_default` TINYINT(1) DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`board_id`) REFERENCES `jiraboards`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`repo_connection_id`) REFERENCES `repoconnections`(`id`) ON DELETE CASCADE,
    INDEX `idx_board` (`board_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ENTERPRISE SETTINGS (from SQLite)
-- ============================================================================

-- Enterprise settings (API keys, config, etc.)
CREATE TABLE IF NOT EXISTS `enterprisesettings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT,
    `is_encrypted` TINYINT(1) DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anthropic API keys (multiple keys per tenant)
CREATE TABLE IF NOT EXISTS `anthropickeys` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `api_key` TEXT NOT NULL,
    `model` VARCHAR(100) DEFAULT 'claude-sonnet-4-20250514',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User settings (key-value store)
CREATE TABLE IF NOT EXISTS `usersettings` (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SHARD TABLES (per tenant - their own shards)
-- ============================================================================

-- Claude shards (execution servers)
CREATE TABLE IF NOT EXISTS `claudeshards` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `host` VARCHAR(255) NOT NULL,
    `port` INT DEFAULT 3500,
    `api_key` VARCHAR(255) NOT NULL,
    `shard_type` ENUM('general', 'playwright', 'database', 'full', 'custom') DEFAULT 'general',
    `capabilities` JSON,
    `max_concurrent_jobs` INT DEFAULT 2,
    `is_active` TINYINT(1) DEFAULT 1,
    `is_default` TINYINT(1) DEFAULT 0,
    `last_health_check` DATETIME,
    `health_status` ENUM('healthy', 'unhealthy', 'unknown') DEFAULT 'unknown',
    `execution_mode` VARCHAR(50) DEFAULT 'http_api',
    `ssh_user` VARCHAR(100),
    `ssh_port` INT DEFAULT 22,
    `ssh_key_path` VARCHAR(500),
    `ssh_validated` TINYINT(1) DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_host_port` (`host`, `port`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shard assignments (which members can use which shards)
CREATE TABLE IF NOT EXISTS `shardassignments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT NOT NULL,
    `shard_id` INT NOT NULL,
    `priority` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_member_shard` (`member_id`, `shard_id`),
    FOREIGN KEY (`shard_id`) REFERENCES `claudeshards`(`id`) ON DELETE CASCADE,
    INDEX `idx_shard` (`shard_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shard jobs (AI Developer jobs running on shards)
CREATE TABLE IF NOT EXISTS `shardjobs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `job_id` VARCHAR(64) NOT NULL UNIQUE,
    `member_id` INT NOT NULL,
    `shard_id` INT NOT NULL,
    `issue_key` VARCHAR(50),
    `status` ENUM('queued', 'running', 'completed', 'failed', 'cancelled') DEFAULT 'queued',
    `request_payload` JSON,
    `result_payload` JSON,
    `error_message` TEXT,
    `started_at` DATETIME,
    `completed_at` DATETIME,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_member_status` (`member_id`, `status`),
    INDEX `idx_shard_status` (`shard_id`, `status`),
    FOREIGN KEY (`shard_id`) REFERENCES `claudeshards`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Digest jobs (digest processing jobs)
CREATE TABLE IF NOT EXISTS `digestjobs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `job_id` VARCHAR(64) NOT NULL UNIQUE,
    `member_id` INT NOT NULL,
    `board_id` INT NOT NULL,
    `board_name` VARCHAR(255),
    `project_key` VARCHAR(50),
    `status` ENUM('queued', 'pending', 'running', 'completed', 'failed') DEFAULT 'queued',
    `send_email` TINYINT(1) DEFAULT 1,
    `digest_cc` TEXT,
    `result` JSON,
    `error` TEXT,
    `shard_id` INT,
    `items_count` INT,
    `phase` VARCHAR(50),
    `elapsed_seconds` INT,
    `partial_output` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `started_at` DATETIME,
    `completed_at` DATETIME,
    `updated` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_member_status` (`member_id`, `status`),
    INDEX `idx_shard` (`shard_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SEED DATA
-- ============================================================================

-- Insert default public user for guest permissions
INSERT INTO `member` (`id`, `username`, `email`, `password`, `display_name`, `level`, `status`)
VALUES (1, 'public', 'public@system.local', '', 'Public User', 101, 'system')
ON DUPLICATE KEY UPDATE `username` = 'public';

-- Insert default permissions
INSERT INTO `authcontrol` (`control`, `method`, `level`, `description`) VALUES
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
ON DUPLICATE KEY UPDATE `level` = VALUES(`level`);

SET FOREIGN_KEY_CHECKS = 1;
