-- Add project_type column to ctoprojects table
-- Tracks the source of the project: 'jira', 'github', 'monday', 'zoho', etc.

ALTER TABLE ctoprojects
ADD COLUMN project_type VARCHAR(20) NOT NULL DEFAULT 'jira' AFTER name;

-- Add index for filtering by project type
CREATE INDEX idx_ctoprojects_project_type ON ctoprojects(project_type);
