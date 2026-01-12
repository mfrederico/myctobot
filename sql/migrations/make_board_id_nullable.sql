-- Migration: Make board_id nullable for GitHub-only workflows
-- Run this on existing tenant databases

ALTER TABLE `aidevjobs`
    MODIFY COLUMN `board_id` INT DEFAULT NULL COMMENT 'Nullable for GitHub-only workflows';

-- Verify the change
DESCRIBE `aidevjobs`;
