-- Migration: Add missing columns to aidevjobs table for database-based job tracking
-- Run this on each tenant database

-- Add job_id column (unique identifier for the job)
ALTER TABLE `aidevjobs` ADD COLUMN IF NOT EXISTS `job_id` VARCHAR(64) NULL AFTER `id`;

-- Add progress tracking columns
ALTER TABLE `aidevjobs` ADD COLUMN IF NOT EXISTS `progress` INT DEFAULT 0 AFTER `status`;
ALTER TABLE `aidevjobs` ADD COLUMN IF NOT EXISTS `current_step` VARCHAR(100) DEFAULT 'Initializing' AFTER `progress`;
ALTER TABLE `aidevjobs` ADD COLUMN IF NOT EXISTS `steps_completed` JSON AFTER `current_step`;

-- Add PR created timestamp
ALTER TABLE `aidevjobs` ADD COLUMN IF NOT EXISTS `pr_created_at` DATETIME AFTER `pr_number`;

-- Add Shopify integration columns
ALTER TABLE `aidevjobs` ADD COLUMN IF NOT EXISTS `shopify_theme_id` INT AFTER `commit_sha`;
ALTER TABLE `aidevjobs` ADD COLUMN IF NOT EXISTS `shopify_preview_url` VARCHAR(500) AFTER `shopify_theme_id`;
ALTER TABLE `aidevjobs` ADD COLUMN IF NOT EXISTS `playwright_results` JSON AFTER `shopify_preview_url`;
ALTER TABLE `aidevjobs` ADD COLUMN IF NOT EXISTS `preserve_branch` TINYINT(1) DEFAULT 1 AFTER `playwright_results`;

-- Convert text columns to JSON where appropriate
ALTER TABLE `aidevjobs` MODIFY COLUMN `clarification_questions` JSON;
ALTER TABLE `aidevjobs` MODIFY COLUMN `files_changed` JSON;

-- Add unique index on job_id
ALTER TABLE `aidevjobs` ADD UNIQUE INDEX IF NOT EXISTS `idx_job_id` (`job_id`);

-- Add composite index for member + issue lookups
ALTER TABLE `aidevjobs` ADD INDEX IF NOT EXISTS `idx_member_issue` (`member_id`, `issue_key`);

-- Remove the unique constraint on issue_key (allows multiple jobs per issue)
-- First check if the constraint exists, then drop it
-- Note: The exact constraint name may vary - check your database
-- ALTER TABLE `aidevjobs` DROP INDEX `issue_key`;

-- Generate job_id for existing rows that don't have one
UPDATE `aidevjobs` SET `job_id` = CONCAT(
    LPAD(HEX(FLOOR(RAND() * 0xFFFFFFFF)), 8, '0'),
    LPAD(HEX(FLOOR(RAND() * 0xFFFFFFFF)), 8, '0'),
    LPAD(HEX(FLOOR(RAND() * 0xFFFFFFFF)), 8, '0'),
    LPAD(HEX(FLOOR(RAND() * 0xFFFFFFFF)), 8, '0')
) WHERE `job_id` IS NULL OR `job_id` = '';

-- Now make job_id NOT NULL
ALTER TABLE `aidevjobs` MODIFY COLUMN `job_id` VARCHAR(64) NOT NULL;
