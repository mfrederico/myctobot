-- Add ownership and sharing to anthropickeys table
-- Allows API keys to be owned by a member and optionally shared with workspace

ALTER TABLE `anthropickeys` ADD COLUMN IF NOT EXISTS `created_by_member_id` INT NOT NULL DEFAULT 0 AFTER `id`;
ALTER TABLE `anthropickeys` ADD COLUMN IF NOT EXISTS `created_by_name` VARCHAR(255) AFTER `created_by_member_id`;
ALTER TABLE `anthropickeys` ADD COLUMN IF NOT EXISTS `shared` TINYINT(1) DEFAULT 0 AFTER `model`;
ALTER TABLE `anthropickeys` ADD INDEX IF NOT EXISTS `idx_member` (`created_by_member_id`);
ALTER TABLE `anthropickeys` ADD INDEX IF NOT EXISTS `idx_shared` (`shared`);
