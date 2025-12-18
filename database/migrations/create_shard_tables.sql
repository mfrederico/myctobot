-- Claude Code Shard Tables
-- Run this migration to add shard support

-- Shard definitions (configured by admins)
CREATE TABLE IF NOT EXISTS claude_shards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    host VARCHAR(255) NOT NULL,
    port INT DEFAULT 3500,
    api_key VARCHAR(255) NOT NULL,
    shard_type ENUM('general', 'playwright', 'database', 'full', 'custom') DEFAULT 'general',
    capabilities JSON,
    max_concurrent_jobs INT DEFAULT 2,
    is_active TINYINT(1) DEFAULT 1,
    is_default TINYINT(1) DEFAULT 0,
    last_health_check DATETIME NULL,
    health_status ENUM('healthy', 'unhealthy', 'unknown') DEFAULT 'unknown',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_host_port (host, port)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Shard assignments (which members can use which shards)
-- If no assignment exists, member can use any default shard
CREATE TABLE IF NOT EXISTS shard_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    shard_id INT NOT NULL,
    priority INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shard_id) REFERENCES claude_shards(id) ON DELETE CASCADE,
    UNIQUE KEY unique_member_shard (member_id, shard_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Shard job tracking
CREATE TABLE IF NOT EXISTS shard_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id VARCHAR(64) NOT NULL UNIQUE,
    member_id INT NOT NULL,
    shard_id INT NOT NULL,
    issue_key VARCHAR(50),
    status ENUM('queued', 'running', 'completed', 'failed', 'cancelled') DEFAULT 'queued',
    request_payload JSON,
    result_payload JSON,
    error_message TEXT,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shard_id) REFERENCES claude_shards(id),
    INDEX idx_member_status (member_id, status),
    INDEX idx_shard_status (shard_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert the first shard (update with your actual values)
INSERT INTO claude_shards (name, description, host, port, api_key, shard_type, capabilities, is_default)
VALUES (
    'shard-general-01',
    'General purpose Claude Code shard',
    '173.231.12.84',
    3500,
    '2dd7ff98f19a83eabbbf879cfb8a50d743d1bc7bdcd4dc7bdfe4bf361684677d',
    'general',
    '["git", "filesystem"]',
    1
);
