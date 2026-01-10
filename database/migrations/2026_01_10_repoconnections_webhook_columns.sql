-- Migration: Add webhook columns to repoconnections table
-- Date: 2026-01-10

-- For MySQL/MariaDB
ALTER TABLE `repoconnections` ADD COLUMN IF NOT EXISTS `issues_enabled` TINYINT(1) DEFAULT 0 AFTER `enabled`;
ALTER TABLE `repoconnections` ADD COLUMN IF NOT EXISTS `webhook_id` VARCHAR(50) AFTER `issues_enabled`;
ALTER TABLE `repoconnections` ADD COLUMN IF NOT EXISTS `webhook_secret` VARCHAR(100) AFTER `webhook_id`;

-- Note: SQLite doesn't support ADD COLUMN IF NOT EXISTS, so for SQLite databases,
-- the columns will be auto-created by RedBeanPHP's fluid mode when first accessed.
