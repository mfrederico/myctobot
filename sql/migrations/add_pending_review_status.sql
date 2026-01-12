-- Migration: Add pending_review and approved status to ctostories
-- Run this on existing tenant databases

ALTER TABLE `ctostories`
    MODIFY COLUMN `status` ENUM('pending_review', 'approved', 'backlog', 'ready', 'in_progress', 'review', 'done', 'blocked') DEFAULT 'pending_review';

-- Update any existing stories without issues to pending_review
UPDATE `ctostories` SET `status` = 'pending_review' WHERE `jira_issue_key` IS NULL AND `status` = 'backlog';
