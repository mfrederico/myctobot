-- Migration: Add delivery confirmation tracking fields to aidevjobs table
-- Issue: #26 - CEO Directive Delivery Confirmation System
-- Date: 2026-01-11
--
-- This migration adds fields to track confirmation delivery status:
-- - confirmation_sent_at: Timestamp when confirmation was successfully delivered
-- - confirmation_method: Delivery method(s) used (email, jira, webhook)
-- - confirmation_attempts: Number of delivery attempts made
-- - confirmation_last_error: Last error message if delivery failed
--
-- Usage:
--   MySQL: mysql -u user -p database_name < sql/migrations/add_delivery_confirmation_fields.sql
--   SQLite: sqlite3 database.sqlite < sql/migrations/add_delivery_confirmation_fields.sql

-- Add confirmation_sent_at column (timestamp when delivery succeeded)
ALTER TABLE `aidevjobs`
ADD COLUMN IF NOT EXISTS `confirmation_sent_at` DATETIME DEFAULT NULL
COMMENT 'Timestamp when delivery confirmation was successfully sent';

-- Add confirmation_method column (which method(s) succeeded: email, jira, webhook)
ALTER TABLE `aidevjobs`
ADD COLUMN IF NOT EXISTS `confirmation_method` VARCHAR(100) DEFAULT NULL
COMMENT 'Delivery method(s) that succeeded (comma-separated if multiple)';

-- Add confirmation_attempts column (retry counter with max of 3)
ALTER TABLE `aidevjobs`
ADD COLUMN IF NOT EXISTS `confirmation_attempts` INT DEFAULT 0
COMMENT 'Number of confirmation delivery attempts (max 3 before manual follow-up)';

-- Add confirmation_last_error column (last failure reason for debugging)
ALTER TABLE `aidevjobs`
ADD COLUMN IF NOT EXISTS `confirmation_last_error` TEXT DEFAULT NULL
COMMENT 'Last error message from failed confirmation delivery attempt';

-- Add index for efficient queries on pending confirmations
CREATE INDEX IF NOT EXISTS `idx_confirmation_pending`
ON `aidevjobs` (`confirmation_sent_at`, `confirmation_attempts`);

-- Alternative syntax for SQLite (which doesn't support IF NOT EXISTS for columns)
-- Uncomment if using SQLite:
--
-- ALTER TABLE aidevjobs ADD COLUMN confirmation_sent_at DATETIME;
-- ALTER TABLE aidevjobs ADD COLUMN confirmation_method VARCHAR(100);
-- ALTER TABLE aidevjobs ADD COLUMN confirmation_attempts INT DEFAULT 0;
-- ALTER TABLE aidevjobs ADD COLUMN confirmation_last_error TEXT;
-- CREATE INDEX IF NOT EXISTS idx_confirmation_pending ON aidevjobs (confirmation_sent_at, confirmation_attempts);
