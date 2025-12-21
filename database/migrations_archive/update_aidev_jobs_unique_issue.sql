-- Migration: Update ai_dev_jobs to use issue_key as unique identifier
-- This changes from "one record per job run" to "one record per Jira ticket"

-- Step 1: Create new table with correct schema
CREATE TABLE IF NOT EXISTS ai_dev_jobs_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    issue_key TEXT NOT NULL UNIQUE,      -- One record per Jira issue
    board_id INTEGER NOT NULL,
    repo_connection_id INTEGER,
    cloud_id TEXT,
    status TEXT DEFAULT 'pending',       -- pending/running/pr_created/waiting_clarification/failed/complete
    current_shard_job_id TEXT,           -- The active shard job ID for tracking
    branch_name TEXT,
    pr_url TEXT,
    pr_number INTEGER,
    clarification_comment_id TEXT,
    clarification_questions TEXT,        -- JSON array of questions
    error_message TEXT,
    run_count INTEGER DEFAULT 0,         -- How many times AI has worked on this issue
    last_output TEXT,                    -- Full output from the last run
    last_result_json TEXT,               -- Result data from the last run (JSON)
    files_changed TEXT,                  -- JSON array of changed files
    commit_sha TEXT,
    started_at TEXT,
    completed_at TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    FOREIGN KEY (repo_connection_id) REFERENCES repo_connections(id) ON DELETE SET NULL
);

-- Step 2: Migrate existing data (keep most recent job per issue_key)
INSERT OR REPLACE INTO ai_dev_jobs_new (
    issue_key, board_id, repo_connection_id, status, current_shard_job_id,
    branch_name, pr_url, pr_number, clarification_comment_id,
    error_message, run_count, started_at, completed_at, created_at, updated_at
)
SELECT
    issue_key, board_id, repo_connection_id, status, job_id,
    branch_name, pr_url, pr_number, clarification_comment_id,
    error_message, 1, started_at, completed_at, created_at, updated_at
FROM ai_dev_jobs
WHERE id IN (
    SELECT MAX(id) FROM ai_dev_jobs GROUP BY issue_key
);

-- Step 3: Drop old table and rename new one
DROP TABLE IF EXISTS ai_dev_jobs;
ALTER TABLE ai_dev_jobs_new RENAME TO ai_dev_jobs;

-- Step 4: Recreate indexes
CREATE INDEX IF NOT EXISTS idx_ai_job_status ON ai_dev_jobs(status);
CREATE INDEX IF NOT EXISTS idx_ai_job_issue ON ai_dev_jobs(issue_key);
CREATE INDEX IF NOT EXISTS idx_ai_job_board ON ai_dev_jobs(board_id);
