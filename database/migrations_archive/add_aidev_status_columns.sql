-- Migration: Add AI Developer status transition columns to jira_boards
-- Run this on existing user SQLite databases

-- Add columns for AI Developer status transitions (per-board settings)
ALTER TABLE jira_boards ADD COLUMN aidev_status_working TEXT DEFAULT NULL;
ALTER TABLE jira_boards ADD COLUMN aidev_status_pr_created TEXT DEFAULT NULL;
ALTER TABLE jira_boards ADD COLUMN aidev_status_clarification TEXT DEFAULT NULL;
ALTER TABLE jira_boards ADD COLUMN aidev_status_failed TEXT DEFAULT NULL;

-- Note: SQLite doesn't support adding multiple columns in one ALTER TABLE
-- Each column stores the TARGET STATUS NAME (e.g., "In Progress", "Ready for QA")
-- The system will look up the transition ID at runtime based on the status name
