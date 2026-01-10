-- Migration: Create agenttools table for custom MCP tool definitions
-- Date: 2026-01-10
-- Purpose: Enable agents to expose multiple custom MCP tools with defined parameters

-- Create agenttools table
CREATE TABLE IF NOT EXISTS `agenttools` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `agent_id` INT UNSIGNED NOT NULL,
    `tool_name` VARCHAR(100) NOT NULL,
    `tool_description` TEXT,
    `parameters_schema` JSON DEFAULT '[]',
    `prompt_template` TEXT,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `idx_agenttools_name` (`agent_id`, `tool_name`),
    INDEX `idx_agenttools_agent` (`agent_id`),
    INDEX `idx_agenttools_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: FK constraint depends on aiagents table existing
-- ALTER TABLE `agenttools` ADD CONSTRAINT `fk_agenttools_agent`
--     FOREIGN KEY (`agent_id`) REFERENCES `aiagents`(`id`) ON DELETE CASCADE;
