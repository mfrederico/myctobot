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
    `board_id` INT DEFAULT NULL COMMENT 'Nullable for GitHub-only workflows',
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
    -- Delivery confirmation tracking (Issue #26)
    `confirmation_sent_at` DATETIME DEFAULT NULL COMMENT 'Timestamp when delivery confirmation was successfully sent',
    `confirmation_method` VARCHAR(100) DEFAULT NULL COMMENT 'Delivery method(s) that succeeded (email, jira, webhook)',
    `confirmation_attempts` INT DEFAULT 0 COMMENT 'Number of confirmation delivery attempts (max 3)',
    `confirmation_last_error` TEXT DEFAULT NULL COMMENT 'Last error message from failed confirmation attempt',
    INDEX `idx_member` (`member_id`),
    INDEX `idx_board` (`board_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_issue` (`issue_key`),
    INDEX `idx_member_issue` (`member_id`, `issue_key`),
    INDEX `idx_confirmation_pending` (`confirmation_sent_at`, `confirmation_attempts`)
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
    `member_id` INT DEFAULT NULL,
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
    INDEX `idx_agent` (`agent_id`),
    INDEX `idx_member` (`member_id`)
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
-- AI AGENT TABLES
-- ============================================================================

-- AI Agent profiles (LLM providers, MCP servers, hooks)
CREATE TABLE IF NOT EXISTS `aiagents` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `provider` VARCHAR(50) DEFAULT 'claude_cli',
    `provider_config` JSON,
    `runner_type` VARCHAR(50) DEFAULT 'claude_cli',
    `runner_config` JSON,
    `mcp_servers` JSON DEFAULT '[]',
    `hooks_config` JSON DEFAULT '{}',
    `capabilities` JSON DEFAULT '[]',
    `expose_as_mcp` TINYINT(1) DEFAULT 0,
    `mcp_tool_name` VARCHAR(100),
    `mcp_tool_description` TEXT,
    `is_active` TINYINT(1) DEFAULT 1,
    `is_default` TINYINT(1) DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_member` (`member_id`),
    INDEX `idx_active` (`is_active`),
    INDEX `idx_default` (`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agent custom MCP tools (multiple tools per agent)
CREATE TABLE IF NOT EXISTS `agenttools` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `agent_id` INT UNSIGNED NOT NULL,
    `tool_name` VARCHAR(100) NOT NULL,
    `tool_description` TEXT,
    `parameters_schema` JSON DEFAULT '[]',
    `prompt_template` TEXT,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `idx_agenttools_name` (`agent_id`, `tool_name`),
    INDEX `idx_agenttools_agent` (`agent_id`),
    INDEX `idx_agenttools_active` (`is_active`),
    FOREIGN KEY (`agent_id`) REFERENCES `aiagents`(`id`) ON DELETE CASCADE
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

-- ============================================================================
-- AUTONOMOUS CTO SYSTEM TABLES
-- ============================================================================

-- CEO Directives (email input from CEO)
CREATE TABLE IF NOT EXISTS `ceodirectives` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `directive_id` VARCHAR(32) NOT NULL UNIQUE,
    `member_id` INT NOT NULL,

    -- Email metadata
    `email_from` VARCHAR(255),
    `email_subject` VARCHAR(500),
    `email_body` TEXT,
    `email_message_id` VARCHAR(255),

    -- Parsed content
    `parsed_intent` ENUM('project', 'feature', 'bug', 'question', 'report') DEFAULT 'project',
    `parsed_summary` TEXT,
    `parsed_requirements` JSON,

    -- Processing state
    `status` ENUM('received', 'parsing', 'planning', 'executing', 'completed', 'failed') DEFAULT 'received',
    `current_phase` VARCHAR(50),
    `error_message` TEXT,

    -- Approval mode
    `approval_mode` ENUM('auto', 'manual') DEFAULT 'auto',

    -- Linked project
    `project_id` INT,

    -- Response tracking
    `response_sent_at` DATETIME,
    `response_content` TEXT,

    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_status` (`status`),
    INDEX `idx_member` (`member_id`),
    INDEX `idx_directive_id` (`directive_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CTO Projects (strategic decomposition)
CREATE TABLE IF NOT EXISTS `ctoprojects` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` VARCHAR(32) NOT NULL UNIQUE,
    `directive_id` INT,
    `member_id` INT NOT NULL,

    -- Project info
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `goals` JSON,

    -- Planning
    `estimated_effort` VARCHAR(50),
    `risk_assessment` JSON,
    `tech_stack` JSON,

    -- Jira/GitHub linking
    `jira_project_key` VARCHAR(50),
    `board_id` INT,
    `github_repo_id` INT,

    -- Status
    `status` ENUM('planning', 'in_progress', 'blocked', 'completed', 'cancelled') DEFAULT 'planning',
    `completion_percentage` INT DEFAULT 0,

    -- Milestones
    `milestones` JSON,

    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_status` (`status`),
    INDEX `idx_directive` (`directive_id`),
    INDEX `idx_project_id` (`project_id`),
    FOREIGN KEY (`directive_id`) REFERENCES `ceodirectives`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CTO Epics (PM-level breakdown)
CREATE TABLE IF NOT EXISTS `ctoepics` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `epic_id` VARCHAR(32) NOT NULL UNIQUE,
    `project_id` INT NOT NULL,

    -- Epic info
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `acceptance_criteria` JSON,

    -- Jira linking
    `jira_epic_key` VARCHAR(50),
    `jira_epic_id` VARCHAR(50),

    -- Status tracking
    `status` ENUM('backlog', 'in_progress', 'review', 'completed') DEFAULT 'backlog',
    `story_count` INT DEFAULT 0,
    `stories_completed` INT DEFAULT 0,

    -- Ordering
    `priority` INT DEFAULT 0,
    `sequence` INT DEFAULT 0,

    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_project` (`project_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_epic_id` (`epic_id`),
    FOREIGN KEY (`project_id`) REFERENCES `ctoprojects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CTO Stories (linked to existing aidevjobs)
CREATE TABLE IF NOT EXISTS `ctostories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `story_id` VARCHAR(32) NOT NULL UNIQUE,
    `epic_id` INT NOT NULL,

    -- Story info
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `acceptance_criteria` JSON,
    `story_points` INT,

    -- Jira linking
    `jira_issue_key` VARCHAR(50),
    `jira_issue_id` VARCHAR(50),

    -- AI Dev job linking
    `aidev_job_id` VARCHAR(64),

    -- Status workflow: pending_review → approved → ready → in_progress → review → done
    `status` ENUM('pending_review', 'approved', 'backlog', 'ready', 'in_progress', 'review', 'done', 'blocked') DEFAULT 'pending_review',
    `blocker_reason` TEXT,

    -- Dependencies
    `depends_on` JSON,

    -- Verification
    `verified_at` DATETIME,
    `verification_result` JSON,

    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_epic` (`epic_id`),
    INDEX `idx_jira` (`jira_issue_key`),
    INDEX `idx_status` (`status`),
    INDEX `idx_story_id` (`story_id`),
    FOREIGN KEY (`epic_id`) REFERENCES `ctoepics`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Directive Processing Log
CREATE TABLE IF NOT EXISTS `directivelogs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `directive_id` INT NOT NULL,
    `phase` VARCHAR(50) NOT NULL,
    `log_level` ENUM('info', 'warning', 'error') DEFAULT 'info',
    `message` TEXT NOT NULL,
    `context_json` JSON,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX `idx_directive` (`directive_id`),
    FOREIGN KEY (`directive_id`) REFERENCES `ceodirectives`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add authcontrol entries for new controllers
INSERT INTO `authcontrol` (`control`, `method`, `level`, `description`) VALUES
('directives', 'index', 100, 'CEO Directives dashboard'),
('directives', 'view', 100, 'View directive detail'),
('directives', 'retry', 100, 'Retry failed directive'),
('directives', 'cancel', 100, 'Cancel directive'),
('projects', 'index', 100, 'CTO Projects dashboard'),
('projects', 'view', 100, 'View project detail'),
('projects', 'report', 100, 'Generate project report'),
('projects', 'pause', 100, 'Pause project execution'),
('projects', 'resume', 100, 'Resume project execution'),
('webhook', 'mailgun', 101, 'Mailgun incoming email webhook')
ON DUPLICATE KEY UPDATE `level` = VALUES(`level`);

-- ============================================================================
-- PLUGIN REGISTRY TABLES
-- ============================================================================

-- Plugin categories for organizing plugins
CREATE TABLE IF NOT EXISTS `plugincategory` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT,
    `parent_id` INT DEFAULT NULL,
    `icon` VARCHAR(50) DEFAULT 'bi-puzzle',
    `plugin_count` INT DEFAULT 0,
    `display_order` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_slug` (`slug`),
    INDEX `idx_parent` (`parent_id`),
    INDEX `idx_display_order` (`display_order`),
    FOREIGN KEY (`parent_id`) REFERENCES `plugincategory`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plugin tags for filtering
CREATE TABLE IF NOT EXISTS `plugintag` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL,
    `slug` VARCHAR(50) NOT NULL UNIQUE,
    `plugin_count` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plugins in the registry
CREATE TABLE IF NOT EXISTS `plugin` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT,
    `short_description` VARCHAR(255),
    `version` VARCHAR(20),
    `author` VARCHAR(100),
    `author_url` VARCHAR(255),
    `icon` VARCHAR(100) DEFAULT 'bi-puzzle-fill',
    `category_id` INT,
    `is_active` TINYINT(1) DEFAULT 1,
    `is_featured` TINYINT(1) DEFAULT 0,
    `downloads` INT DEFAULT 0,
    `rating` DECIMAL(2,1) DEFAULT 0.0,
    `rating_count` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_slug` (`slug`),
    INDEX `idx_category` (`category_id`),
    INDEX `idx_active` (`is_active`),
    INDEX `idx_featured` (`is_featured`),
    INDEX `idx_downloads` (`downloads` DESC),
    FOREIGN KEY (`category_id`) REFERENCES `plugincategory`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plugin-tag relationship (many-to-many)
CREATE TABLE IF NOT EXISTS `plugin_plugintag` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `plugin_id` INT NOT NULL,
    `plugintag_id` INT NOT NULL,
    UNIQUE KEY `uk_plugin_tag` (`plugin_id`, `plugintag_id`),
    FOREIGN KEY (`plugin_id`) REFERENCES `plugin`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`plugintag_id`) REFERENCES `plugintag`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed plugin categories
INSERT INTO `plugincategory` (`name`, `slug`, `description`, `icon`, `display_order`) VALUES
('User Interface', 'ui', 'Plugins for enhancing user interfaces, themes, and visual components', 'bi-palette', 1),
('Database', 'database', 'Database connectors, ORM extensions, and data management tools', 'bi-database', 2),
('Integration', 'integration', 'Third-party service integrations and API connectors', 'bi-plug', 3),
('Authentication', 'authentication', 'Security, authentication, and authorization plugins', 'bi-shield-lock', 4),
('Analytics', 'analytics', 'Analytics, reporting, and data visualization tools', 'bi-graph-up', 5),
('Utilities', 'utilities', 'General-purpose utility libraries and helper tools', 'bi-tools', 6)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Seed plugin tags
INSERT INTO `plugintag` (`name`, `slug`) VALUES
('PHP', 'php'),
('JavaScript', 'javascript'),
('API', 'api'),
('Frontend', 'frontend'),
('Backend', 'backend'),
('Security', 'security'),
('Performance', 'performance'),
('Caching', 'caching'),
('REST', 'rest'),
('OAuth', 'oauth')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Seed sample plugins
INSERT INTO `plugin` (`name`, `slug`, `short_description`, `description`, `version`, `author`, `icon`, `category_id`, `is_featured`, `downloads`) VALUES
('Bootstrap Theme Pack', 'bootstrap-theme-pack', 'Collection of Bootstrap 5 themes and components', 'A comprehensive collection of professionally designed Bootstrap 5 themes, components, and UI kits for rapid development.', '2.1.0', 'ThemeCraft', 'bi-brush', 1, 1, 1250),
('Redis Cache Driver', 'redis-cache-driver', 'High-performance Redis caching for FlightPHP', 'Seamlessly integrate Redis caching into your FlightPHP application for improved performance and scalability.', '1.5.2', 'CacheWorks', 'bi-lightning', 2, 0, 890),
('Slack Notifier', 'slack-notifier', 'Send notifications to Slack channels', 'Easy-to-use Slack integration for sending notifications, alerts, and messages from your application.', '3.0.1', 'IntegratePro', 'bi-slack', 3, 1, 2100),
('JWT Authentication', 'jwt-authentication', 'Secure JWT-based authentication system', 'Complete JWT authentication solution with token refresh, blacklisting, and role-based access control.', '2.0.0', 'SecureAuth', 'bi-key', 4, 1, 3200),
('Google Analytics Bridge', 'google-analytics-bridge', 'Google Analytics 4 integration', 'Track user behavior and generate reports with seamless Google Analytics 4 integration.', '1.2.0', 'AnalyticsPro', 'bi-bar-chart', 5, 0, 780),
('Form Validator', 'form-validator', 'Comprehensive form validation library', 'Powerful server-side and client-side form validation with customizable rules and error messages.', '4.1.0', 'FormMaster', 'bi-check-square', 6, 1, 1560),
('MySQL Query Builder', 'mysql-query-builder', 'Fluent MySQL query builder for RedBeanPHP', 'Extend RedBeanPHP with a fluent query builder interface for complex MySQL queries.', '1.8.0', 'DBTools', 'bi-database-gear', 2, 0, 1100),
('OAuth2 Server', 'oauth2-server', 'Full OAuth2 server implementation', 'Complete OAuth2 server with support for all grant types and token management.', '2.3.0', 'SecureAuth', 'bi-shield-check', 4, 0, 950),
('Chart.js Dashboard', 'chartjs-dashboard', 'Interactive dashboard components', 'Beautiful, responsive dashboard components using Chart.js for data visualization.', '1.4.2', 'ChartWorks', 'bi-pie-chart', 5, 1, 1890),
('Email Queue', 'email-queue', 'Async email queue with retry logic', 'Reliable email delivery with queue management, retry logic, and delivery tracking.', '2.0.1', 'MailPro', 'bi-envelope', 6, 0, 670)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Link plugins to tags (after plugins are inserted)
-- Bootstrap Theme Pack: frontend, javascript
INSERT INTO `plugin_plugintag` (`plugin_id`, `plugintag_id`)
SELECT p.id, t.id FROM `plugin` p, `plugintag` t WHERE p.slug = 'bootstrap-theme-pack' AND t.slug IN ('frontend', 'javascript')
ON DUPLICATE KEY UPDATE `plugin_id` = VALUES(`plugin_id`);

-- Redis Cache Driver: backend, php, caching, performance
INSERT INTO `plugin_plugintag` (`plugin_id`, `plugintag_id`)
SELECT p.id, t.id FROM `plugin` p, `plugintag` t WHERE p.slug = 'redis-cache-driver' AND t.slug IN ('backend', 'php', 'caching', 'performance')
ON DUPLICATE KEY UPDATE `plugin_id` = VALUES(`plugin_id`);

-- Slack Notifier: api, backend, rest
INSERT INTO `plugin_plugintag` (`plugin_id`, `plugintag_id`)
SELECT p.id, t.id FROM `plugin` p, `plugintag` t WHERE p.slug = 'slack-notifier' AND t.slug IN ('api', 'backend', 'rest')
ON DUPLICATE KEY UPDATE `plugin_id` = VALUES(`plugin_id`);

-- JWT Authentication: security, backend, php, api
INSERT INTO `plugin_plugintag` (`plugin_id`, `plugintag_id`)
SELECT p.id, t.id FROM `plugin` p, `plugintag` t WHERE p.slug = 'jwt-authentication' AND t.slug IN ('security', 'backend', 'php', 'api')
ON DUPLICATE KEY UPDATE `plugin_id` = VALUES(`plugin_id`);

-- Google Analytics Bridge: javascript, frontend, api
INSERT INTO `plugin_plugintag` (`plugin_id`, `plugintag_id`)
SELECT p.id, t.id FROM `plugin` p, `plugintag` t WHERE p.slug = 'google-analytics-bridge' AND t.slug IN ('javascript', 'frontend', 'api')
ON DUPLICATE KEY UPDATE `plugin_id` = VALUES(`plugin_id`);

-- Form Validator: php, frontend, javascript
INSERT INTO `plugin_plugintag` (`plugin_id`, `plugintag_id`)
SELECT p.id, t.id FROM `plugin` p, `plugintag` t WHERE p.slug = 'form-validator' AND t.slug IN ('php', 'frontend', 'javascript')
ON DUPLICATE KEY UPDATE `plugin_id` = VALUES(`plugin_id`);

-- MySQL Query Builder: php, backend
INSERT INTO `plugin_plugintag` (`plugin_id`, `plugintag_id`)
SELECT p.id, t.id FROM `plugin` p, `plugintag` t WHERE p.slug = 'mysql-query-builder' AND t.slug IN ('php', 'backend')
ON DUPLICATE KEY UPDATE `plugin_id` = VALUES(`plugin_id`);

-- OAuth2 Server: security, oauth, api, php
INSERT INTO `plugin_plugintag` (`plugin_id`, `plugintag_id`)
SELECT p.id, t.id FROM `plugin` p, `plugintag` t WHERE p.slug = 'oauth2-server' AND t.slug IN ('security', 'oauth', 'api', 'php')
ON DUPLICATE KEY UPDATE `plugin_id` = VALUES(`plugin_id`);

-- Chart.js Dashboard: javascript, frontend
INSERT INTO `plugin_plugintag` (`plugin_id`, `plugintag_id`)
SELECT p.id, t.id FROM `plugin` p, `plugintag` t WHERE p.slug = 'chartjs-dashboard' AND t.slug IN ('javascript', 'frontend')
ON DUPLICATE KEY UPDATE `plugin_id` = VALUES(`plugin_id`);

-- Email Queue: php, backend, performance
INSERT INTO `plugin_plugintag` (`plugin_id`, `plugintag_id`)
SELECT p.id, t.id FROM `plugin` p, `plugintag` t WHERE p.slug = 'email-queue' AND t.slug IN ('php', 'backend', 'performance')
ON DUPLICATE KEY UPDATE `plugin_id` = VALUES(`plugin_id`);

-- Update plugin counts in categories
UPDATE `plugincategory` pc SET `plugin_count` = (
    SELECT COUNT(*) FROM `plugin` p WHERE p.category_id = pc.id AND p.is_active = 1
);

-- Update plugin counts in tags
UPDATE `plugintag` pt SET `plugin_count` = (
    SELECT COUNT(*) FROM `plugin_plugintag` pp WHERE pp.plugintag_id = pt.id
);

-- Add authcontrol entries for plugin registry
INSERT INTO `authcontrol` (`control`, `method`, `level`, `description`) VALUES
('plugins', 'index', 101, 'Plugin registry - browse all plugins'),
('plugins', 'category', 101, 'Plugin registry - browse by category'),
('plugins', 'view', 101, 'Plugin registry - view plugin details'),
('plugins', 'search', 101, 'Plugin registry - search plugins')
ON DUPLICATE KEY UPDATE `level` = VALUES(`level`);

SET FOREIGN_KEY_CHECKS = 1;
