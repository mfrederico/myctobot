-- Migration: Convert repos and agents from per-member to per-workspace
-- Each tenant database should have shared repos and agent profiles for all members
--
-- Run with: mysql -u myctobot -p myctobot_gwt < database/migrations/2026_01_12_repos_agents_to_workspace_level.sql

-- =============================================================================
-- REPOCONNECTIONS: Convert to workspace-level
-- =============================================================================

-- Step 1: Drop foreign key constraint if exists
SET @fk_exists_repo = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'repoconnections'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME LIKE '%member%'
);

-- Drop FK if it exists
SET @drop_fk_repo = IF(@fk_exists_repo > 0,
    (SELECT CONCAT('ALTER TABLE `repoconnections` DROP FOREIGN KEY `', CONSTRAINT_NAME, '`')
     FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
     AND TABLE_NAME = 'repoconnections'
     AND CONSTRAINT_TYPE = 'FOREIGN KEY'
     AND CONSTRAINT_NAME LIKE '%member%'
     LIMIT 1),
    'SELECT 1'
);
PREPARE stmt_repo FROM @drop_fk_repo;
EXECUTE stmt_repo;
DEALLOCATE PREPARE stmt_repo;

-- Step 2: Make member_id nullable (keeps historical reference to who created the connection)
ALTER TABLE `repoconnections` MODIFY COLUMN `member_id` INT NULL;

-- Step 3: Add created_by column to track who added the repo
ALTER TABLE `repoconnections`
    ADD COLUMN IF NOT EXISTS `created_by_member_id` INT NULL AFTER `member_id`,
    ADD COLUMN IF NOT EXISTS `created_by_name` VARCHAR(255) AFTER `created_by_member_id`;

-- Step 4: Copy existing member_id to created_by_member_id for history
UPDATE `repoconnections`
SET `created_by_member_id` = `member_id`
WHERE `created_by_member_id` IS NULL AND `member_id` IS NOT NULL;

-- Step 5: Set member_id to NULL since repos are now workspace-level
-- (Keep the value in created_by_member_id for audit trail)
UPDATE `repoconnections` SET `member_id` = NULL;

-- =============================================================================
-- AIAGENTS: Convert to workspace-level
-- =============================================================================

-- Step 1: Check if aiagents table exists
SET @aiagents_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'aiagents'
);

-- Only proceed if table exists
SET @alter_aiagents = IF(@aiagents_exists > 0,
    'ALTER TABLE `aiagents` MODIFY COLUMN `member_id` INT NULL',
    'SELECT 1'
);
PREPARE stmt_agents FROM @alter_aiagents;
EXECUTE stmt_agents;
DEALLOCATE PREPARE stmt_agents;

-- Add created_by columns to aiagents if table exists
SET @add_created_by_agents = IF(@aiagents_exists > 0,
    'ALTER TABLE `aiagents` ADD COLUMN IF NOT EXISTS `created_by_member_id` INT NULL AFTER `member_id`, ADD COLUMN IF NOT EXISTS `created_by_name` VARCHAR(255) AFTER `created_by_member_id`',
    'SELECT 1'
);
PREPARE stmt_agents2 FROM @add_created_by_agents;
EXECUTE stmt_agents2;
DEALLOCATE PREPARE stmt_agents2;

-- Copy member_id to created_by for history
SET @copy_member_id = IF(@aiagents_exists > 0,
    'UPDATE `aiagents` SET `created_by_member_id` = `member_id` WHERE `created_by_member_id` IS NULL AND `member_id` IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt_agents3 FROM @copy_member_id;
EXECUTE stmt_agents3;
DEALLOCATE PREPARE stmt_agents3;

-- Set member_id to NULL since agents are now workspace-level
SET @nullify_member_id = IF(@aiagents_exists > 0,
    'UPDATE `aiagents` SET `member_id` = NULL',
    'SELECT 1'
);
PREPARE stmt_agents4 FROM @nullify_member_id;
EXECUTE stmt_agents4;
DEALLOCATE PREPARE stmt_agents4;

-- =============================================================================
-- Done! Both tables now support workspace-level resources
-- - member_id is nullable (no longer used for filtering)
-- - created_by_member_id keeps audit trail of who created the resource
-- - All members in the workspace can access/manage these resources
-- =============================================================================
