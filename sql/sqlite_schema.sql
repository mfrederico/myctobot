-- MyCTOBot Per-User SQLite Schema
-- Each user gets their own SQLite database: database/{ceobot_db}.sqlite

-- Jira boards tracked by this user
CREATE TABLE IF NOT EXISTS jira_boards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    board_id INTEGER NOT NULL,
    board_name TEXT NOT NULL,
    project_key TEXT NOT NULL,
    cloud_id TEXT NOT NULL,
    board_type TEXT DEFAULT 'scrum',
    enabled INTEGER DEFAULT 1,
    digest_enabled INTEGER DEFAULT 0,
    digest_time TEXT DEFAULT '08:00',
    digest_cc TEXT DEFAULT '',
    timezone TEXT DEFAULT 'UTC',
    status_filter TEXT DEFAULT 'To Do',
    priority_weights TEXT,
    goals TEXT,
    last_analysis_at TEXT,
    last_digest_at TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    UNIQUE(board_id, cloud_id)
);

-- Analysis results
CREATE TABLE IF NOT EXISTS analysis_results (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    board_id INTEGER NOT NULL,
    analysis_type TEXT NOT NULL,
    content_json TEXT NOT NULL,
    content_markdown TEXT,
    issue_count INTEGER,
    status_filter TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (board_id) REFERENCES jira_boards(id) ON DELETE CASCADE
);

-- Digest email history
CREATE TABLE IF NOT EXISTS digest_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    board_id INTEGER NOT NULL,
    sent_to TEXT NOT NULL,
    subject TEXT,
    content_preview TEXT,
    status TEXT DEFAULT 'sent',
    error_message TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (board_id) REFERENCES jira_boards(id) ON DELETE CASCADE
);

-- Ticket analysis cache (for clarity scoring and AI confidence)
CREATE TABLE IF NOT EXISTS ticket_analysis_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    board_id INTEGER NOT NULL,
    issue_key TEXT NOT NULL,
    content_hash TEXT NOT NULL,
    clarity_score INTEGER,
    clarity_analysis TEXT,
    reporter_name TEXT,
    reporter_email TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    UNIQUE(board_id, issue_key),
    FOREIGN KEY (board_id) REFERENCES jira_boards(id) ON DELETE CASCADE
);

-- User settings (key-value store)
CREATE TABLE IF NOT EXISTS user_settings (
    key TEXT PRIMARY KEY,
    value TEXT,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_boards_cloud ON jira_boards(cloud_id);
CREATE INDEX IF NOT EXISTS idx_boards_enabled ON jira_boards(enabled);
CREATE INDEX IF NOT EXISTS idx_boards_digest ON jira_boards(digest_enabled);
CREATE INDEX IF NOT EXISTS idx_analysis_board ON analysis_results(board_id);
CREATE INDEX IF NOT EXISTS idx_analysis_created ON analysis_results(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_analysis_type ON analysis_results(analysis_type);
CREATE INDEX IF NOT EXISTS idx_digest_board ON digest_history(board_id);
CREATE INDEX IF NOT EXISTS idx_digest_created ON digest_history(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_ticket_cache_board ON ticket_analysis_cache(board_id);
CREATE INDEX IF NOT EXISTS idx_ticket_cache_hash ON ticket_analysis_cache(content_hash);

-- Default settings
INSERT OR IGNORE INTO user_settings (key, value) VALUES ('digest_email', '');
INSERT OR IGNORE INTO user_settings (key, value) VALUES ('default_status_filter', 'To Do');
INSERT OR IGNORE INTO user_settings (key, value) VALUES ('default_digest_time', '08:00');
