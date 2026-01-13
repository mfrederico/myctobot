-- Add project_type column to aidevjobs table
-- Tracks the source of the issue: 'jira', 'github', 'monday', 'zoho', etc.

ALTER TABLE aidevjobs
ADD COLUMN project_type VARCHAR(20) NOT NULL DEFAULT 'jira' AFTER issue_key;

-- Add index for filtering by project type
CREATE INDEX idx_aidevjobs_project_type ON aidevjobs(project_type);

-- Update existing GitHub issues (those with owner/repo#number format)
UPDATE aidevjobs
SET project_type = 'github'
WHERE issue_key REGEXP '^[^/]+/[^#]+#[0-9]+$';
