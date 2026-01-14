-- Migration: Rename non-FK _id fields to _uid
--
-- This migration renames fields that look like foreign keys but are actually
-- string identifiers (UUIDs, external IDs, etc.) to follow RedBeanPHP conventions.
--
-- Run with: mysql -u myctobot -p myctobot_gwt < scripts/migrations/rename-id-to-uid.sql
--

-- Disable foreign key checks during migration
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- jiraboards table
-- ============================================================================
ALTER TABLE `jiraboards`
    CHANGE COLUMN IF EXISTS `cloud_id` `cloud_uid` VARCHAR(100) NULL;

-- ============================================================================
-- atlassiantoken table
-- ============================================================================
ALTER TABLE `atlassiantoken`
    CHANGE COLUMN IF EXISTS `cloud_id` `cloud_uid` VARCHAR(100) NOT NULL;

DROP INDEX IF EXISTS `uk_member_cloud` ON `atlassiantoken`;

-- ============================================================================
-- aidevjobs table
-- ============================================================================
ALTER TABLE `aidevjobs`
    CHANGE COLUMN IF EXISTS `job_id` `job_uid` VARCHAR(64) NOT NULL;

ALTER TABLE `aidevjobs`
    CHANGE COLUMN IF EXISTS `cloud_id` `cloud_uid` VARCHAR(100) NULL;

ALTER TABLE `aidevjobs`
    CHANGE COLUMN IF EXISTS `current_shard_job_id` `current_shard_job_uid` VARCHAR(64) NULL;

ALTER TABLE `aidevjobs`
    CHANGE COLUMN IF EXISTS `clarification_comment_id` `clarification_comment_uid` VARCHAR(50) NULL;

DROP INDEX IF EXISTS `idx_job_id` ON `aidevjobs`;

-- ============================================================================
-- repoconnections table
-- ============================================================================
ALTER TABLE `repoconnections`
    CHANGE COLUMN IF EXISTS `webhook_id` `webhook_uid` VARCHAR(64) NULL;

-- ============================================================================
-- ceodirectives table (older name for directives)
-- ============================================================================
ALTER TABLE `ceodirectives`
    CHANGE COLUMN IF EXISTS `directive_id` `directive_uid` VARCHAR(64) NOT NULL;

ALTER TABLE `ceodirectives`
    CHANGE COLUMN IF EXISTS `source_id` `source_uid` VARCHAR(255) NULL;

-- ============================================================================
-- ctoprojects table (older name for projects)
-- ============================================================================
ALTER TABLE `ctoprojects`
    CHANGE COLUMN IF EXISTS `project_id` `project_uid` VARCHAR(64) NOT NULL;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Migration complete: _id fields renamed to _uid' AS status;
