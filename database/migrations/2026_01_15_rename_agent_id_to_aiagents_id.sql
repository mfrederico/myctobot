-- Migration: Rename agent_id/agent_uid to aiagents_id for RedBeanPHP association compatibility
-- The column was named agent_id or agent_uid but RedBeanPHP expects {parent_table}_id format

-- First, check which column exists and rename accordingly in repoconnections
-- If agent_id exists
SET @col_agent_id = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'repoconnections' AND COLUMN_NAME = 'agent_id');
SET @sql_agent_id = IF(@col_agent_id > 0,
    'ALTER TABLE `repoconnections` CHANGE COLUMN `agent_id` `aiagents_id` INT DEFAULT NULL',
    'SELECT 1');
PREPARE stmt1 FROM @sql_agent_id;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

-- If agent_uid exists
SET @col_agent_uid = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'repoconnections' AND COLUMN_NAME = 'agent_uid');
SET @sql_agent_uid = IF(@col_agent_uid > 0,
    'ALTER TABLE `repoconnections` CHANGE COLUMN `agent_uid` `aiagents_id` INT DEFAULT NULL',
    'SELECT 1');
PREPARE stmt2 FROM @sql_agent_uid;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- If aiagents_id doesn't exist yet (column was never created), add it
SET @col_aiagents_id = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'repoconnections' AND COLUMN_NAME = 'aiagents_id');
SET @sql_add = IF(@col_aiagents_id = 0,
    'ALTER TABLE `repoconnections` ADD COLUMN `aiagents_id` INT DEFAULT NULL',
    'SELECT 1');
PREPARE stmt3 FROM @sql_add;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

-- Rename in agenttools table (if exists)
SET @table_exists = (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agenttools');

SET @col_at_agent_id = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agenttools' AND COLUMN_NAME = 'agent_id');
SET @sql_at = IF(@table_exists > 0 AND @col_at_agent_id > 0,
    'ALTER TABLE `agenttools` CHANGE COLUMN `agent_id` `aiagents_id` INT UNSIGNED NOT NULL',
    'SELECT 1');
PREPARE stmt4 FROM @sql_at;
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;

-- Update indexes on repoconnections
ALTER TABLE `repoconnections` DROP INDEX IF EXISTS `idx_agent`;
ALTER TABLE `repoconnections` DROP INDEX IF EXISTS `idx_aiagents`;
ALTER TABLE `repoconnections` ADD INDEX `idx_aiagents` (`aiagents_id`);

-- Update indexes on agenttools (if table exists)
-- Note: These may fail if table doesn't exist, that's OK
-- ALTER TABLE `agenttools` DROP INDEX IF EXISTS `idx_agenttools_agent`;
-- ALTER TABLE `agenttools` DROP INDEX IF EXISTS `idx_agenttools_aiagents`;
-- ALTER TABLE `agenttools` ADD INDEX `idx_agenttools_aiagents` (`aiagents_id`);
