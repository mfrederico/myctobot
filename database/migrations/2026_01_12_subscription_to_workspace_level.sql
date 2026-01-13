-- Migration: Convert subscription from per-user to per-workspace
-- Each tenant database should have ONE subscription that applies to all members
--
-- Run with: mysql -u myctobot -p myctobot_gwt < database/migrations/2026_01_12_subscription_to_workspace_level.sql

-- Step 1: Drop foreign key constraint (if exists)
SET @fk_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'subscription'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

-- Drop FK if it exists (using prepared statement for conditional)
SET @drop_fk = IF(@fk_exists > 0,
    'ALTER TABLE `subscription` DROP FOREIGN KEY `subscription_ibfk_1`',
    'SELECT 1'
);
PREPARE stmt FROM @drop_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 2: Drop the unique constraint on member_id
ALTER TABLE `subscription` DROP INDEX IF EXISTS `member_id`;

-- Step 3: Make member_id nullable (keeps historical reference to who originally subscribed)
ALTER TABLE `subscription` MODIFY COLUMN `member_id` INT NULL;

-- Step 4: Add billing_email for workspace-level billing contact
ALTER TABLE `subscription`
    ADD COLUMN IF NOT EXISTS `billing_email` VARCHAR(255) AFTER `member_id`,
    ADD COLUMN IF NOT EXISTS `billing_name` VARCHAR(255) AFTER `billing_email`;

-- Step 5: Ensure only one active subscription per workspace
-- First, keep only the most recent subscription if multiple exist
-- This creates a temporary table with the subscription to keep
CREATE TEMPORARY TABLE IF NOT EXISTS `sub_to_keep` AS
    SELECT MAX(id) as keep_id FROM `subscription` WHERE status = 'active';

-- Delete duplicates (keep only the one with highest ID)
DELETE FROM `subscription`
WHERE id NOT IN (SELECT keep_id FROM `sub_to_keep` WHERE keep_id IS NOT NULL)
AND status = 'active'
AND (SELECT COUNT(*) FROM `sub_to_keep` WHERE keep_id IS NOT NULL) > 0;

DROP TEMPORARY TABLE IF EXISTS `sub_to_keep`;

-- Step 6: Add index for status lookup (workspace has one subscription)
CREATE INDEX IF NOT EXISTS `idx_subscription_active` ON `subscription` (`status`, `tier`);

-- Done! The subscription table now supports workspace-level subscriptions
-- - member_id is nullable (optional reference to billing admin)
-- - billing_email/billing_name for invoice recipients
-- - One active subscription per workspace database
