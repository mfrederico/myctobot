-- Add invite columns to member table for tenant-aware invitations
-- Run this on each tenant database

ALTER TABLE `member`
ADD COLUMN IF NOT EXISTS `invite_token` VARCHAR(64) NULL AFTER `reset_expires`,
ADD COLUMN IF NOT EXISTS `invite_sent_at` DATETIME NULL AFTER `invite_token`,
ADD COLUMN IF NOT EXISTS `invite_expires_at` DATETIME NULL AFTER `invite_sent_at`,
ADD COLUMN IF NOT EXISTS `invited_by` INT NULL AFTER `invite_expires_at`;

-- Add index for invite token lookups
CREATE INDEX IF NOT EXISTS `idx_invite_token` ON `member` (`invite_token`);
