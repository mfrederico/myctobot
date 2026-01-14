-- Migration: Add ceodirectives and ctoprojects tables
-- Run on existing tenant databases that are missing these tables

-- CEO Directives table
CREATE TABLE IF NOT EXISTS `ceodirectives` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `directive_uid` VARCHAR(191),
    `member_id` INT UNSIGNED,
    `email_from` VARCHAR(191),
    `email_subject` VARCHAR(191),
    `email_body` TEXT,
    `email_message_id` VARCHAR(191),
    `parsed_intent` VARCHAR(191),
    `parsed_summary` TEXT,
    `parsed_requirements` JSON,
    `status` VARCHAR(191),
    `current_phase` VARCHAR(191),
    `error_message` TEXT,
    `approval_mode` VARCHAR(191),
    `response_sent_at` DATETIME,
    `response_content` TEXT,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    INDEX `idx_status` (`status`),
    INDEX `idx_member` (`member_id`),
    INDEX `idx_directive_uid` (`directive_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CTO Projects table
CREATE TABLE IF NOT EXISTS `ctoprojects` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `project_uid` VARCHAR(191),
    `member_id` INT UNSIGNED,
    `jiraboards_id` INT UNSIGNED,
    `repoconnections_id` INT UNSIGNED,
    `name` VARCHAR(191),
    `project_type` VARCHAR(191) DEFAULT 'jira',
    `description` TEXT,
    `goals` JSON,
    `estimated_effort` VARCHAR(191),
    `risk_assessment` JSON,
    `tech_stack` JSON,
    `jira_project_key` VARCHAR(191),
    `status` VARCHAR(191) DEFAULT 'planning',
    `completion_percentage` INT DEFAULT 0,
    `milestones` JSON,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    INDEX `idx_status` (`status`),
    INDEX `idx_project_uid` (`project_uid`),
    INDEX `idx_project_type` (`project_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CTO Epics table
CREATE TABLE IF NOT EXISTS `ctoepics` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `epic_uid` VARCHAR(191),
    `ctoprojects_id` INT UNSIGNED,
    `title` VARCHAR(191),
    `description` TEXT,
    `acceptance_criteria` JSON,
    `jira_epic_key` VARCHAR(191),
    `jira_epic_uid` VARCHAR(191),
    `status` VARCHAR(191) DEFAULT 'backlog',
    `story_count` INT DEFAULT 0,
    `stories_completed` INT DEFAULT 0,
    `priority` INT DEFAULT 0,
    `sequence` INT DEFAULT 0,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    INDEX `idx_project` (`ctoprojects_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_epic_uid` (`epic_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CTO Stories table
CREATE TABLE IF NOT EXISTS `ctostories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `story_uid` VARCHAR(191),
    `ctoepics_id` INT UNSIGNED,
    `title` VARCHAR(191),
    `description` TEXT,
    `acceptance_criteria` JSON,
    `story_points` INT,
    `jira_issue_key` VARCHAR(191),
    `jira_issue_uid` VARCHAR(191),
    `aidev_job_uid` VARCHAR(191),
    `status` VARCHAR(191) DEFAULT 'pending_review',
    `blocker_reason` TEXT,
    `depends_on` JSON,
    `verified_at` DATETIME,
    `verification_result` JSON,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    INDEX `idx_epic` (`ctoepics_id`),
    INDEX `idx_jira` (`jira_issue_key`),
    INDEX `idx_status` (`status`),
    INDEX `idx_story_uid` (`story_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
