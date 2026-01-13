-- Migration: Add plugin version tracking tables
-- Issue: #104 - Track plugin versions and detect updates
-- Date: 2026-01-11
--
-- This migration creates tables to track:
-- - pluginregistry: Installed plugins with version info and update status
-- - pluginversion: Version history with release notes for each plugin
--
-- Usage:
--   MySQL: mysql -u user -p database_name < sql/migrations/add_plugin_tracking.sql
--   SQLite: sqlite3 database.sqlite < sql/migrations/add_plugin_tracking.sql

-- ============================================================================
-- Plugin Registry Table
-- Tracks installed plugins and their update status
-- ============================================================================
CREATE TABLE IF NOT EXISTS `pluginregistry` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL COMMENT 'Human-readable plugin name',
    `slug` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Unique identifier (lowercase, hyphenated)',
    `description` TEXT DEFAULT NULL COMMENT 'Plugin description',
    `repository_url` VARCHAR(500) DEFAULT NULL COMMENT 'GitHub repository URL for update checks',
    `homepage_url` VARCHAR(500) DEFAULT NULL COMMENT 'Plugin homepage or documentation URL',
    `author` VARCHAR(255) DEFAULT NULL COMMENT 'Plugin author name',
    `installed_version` VARCHAR(50) NOT NULL COMMENT 'Currently installed version (semver)',
    `latest_version` VARCHAR(50) DEFAULT NULL COMMENT 'Latest available version from repository',
    `update_available` TINYINT(1) DEFAULT 0 COMMENT 'Flag indicating if update is available',
    `update_type` ENUM('major', 'minor', 'patch') DEFAULT NULL COMMENT 'Type of available update',
    `status` ENUM('active', 'inactive', 'disabled') DEFAULT 'active' COMMENT 'Plugin status',
    `plugin_path` VARCHAR(500) DEFAULT NULL COMMENT 'Relative path to plugin directory',
    `requires_php` VARCHAR(50) DEFAULT NULL COMMENT 'Minimum PHP version required',
    `installed_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When the plugin was first installed',
    `last_checked_at` DATETIME DEFAULT NULL COMMENT 'Last time update check was performed',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation timestamp',
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Record update timestamp'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Registry of installed plugins with version tracking';

-- Index for efficient queries on update status
CREATE INDEX IF NOT EXISTS `idx_plugin_update_available` ON `pluginregistry` (`update_available`);
CREATE INDEX IF NOT EXISTS `idx_plugin_status` ON `pluginregistry` (`status`);
CREATE INDEX IF NOT EXISTS `idx_plugin_last_checked` ON `pluginregistry` (`last_checked_at`);

-- ============================================================================
-- Plugin Version Table
-- Stores version history with release notes for each plugin
-- ============================================================================
CREATE TABLE IF NOT EXISTS `pluginversion` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `pluginregistry_id` INT NOT NULL COMMENT 'Reference to plugin in registry',
    `version` VARCHAR(50) NOT NULL COMMENT 'Version number (semver format)',
    `release_notes` TEXT DEFAULT NULL COMMENT 'Release notes / changelog for this version',
    `download_url` VARCHAR(500) DEFAULT NULL COMMENT 'URL to download this version',
    `is_prerelease` TINYINT(1) DEFAULT 0 COMMENT 'Flag for pre-release versions (alpha, beta, rc)',
    `released_at` DATETIME DEFAULT NULL COMMENT 'When this version was released',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation timestamp',
    FOREIGN KEY (`pluginregistry_id`) REFERENCES `pluginregistry`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Version history for each plugin with release notes';

-- Index for efficient version queries
CREATE INDEX IF NOT EXISTS `idx_version_plugin` ON `pluginversion` (`pluginregistry_id`);
CREATE INDEX IF NOT EXISTS `idx_version_released` ON `pluginversion` (`released_at`);
CREATE INDEX IF NOT EXISTS `idx_version_prerelease` ON `pluginversion` (`is_prerelease`);

-- Unique constraint: one record per plugin-version combination
CREATE UNIQUE INDEX IF NOT EXISTS `idx_version_unique` ON `pluginversion` (`pluginregistry_id`, `version`);

-- ============================================================================
-- SQLite Alternative Syntax (uncomment if using SQLite)
-- ============================================================================
--
-- CREATE TABLE IF NOT EXISTS pluginregistry (
--     id INTEGER PRIMARY KEY AUTOINCREMENT,
--     name TEXT NOT NULL,
--     slug TEXT NOT NULL UNIQUE,
--     description TEXT,
--     repository_url TEXT,
--     homepage_url TEXT,
--     author TEXT,
--     installed_version TEXT NOT NULL,
--     latest_version TEXT,
--     update_available INTEGER DEFAULT 0,
--     update_type TEXT CHECK(update_type IN ('major', 'minor', 'patch')),
--     status TEXT DEFAULT 'active' CHECK(status IN ('active', 'inactive', 'disabled')),
--     plugin_path TEXT,
--     requires_php TEXT,
--     installed_at TEXT DEFAULT CURRENT_TIMESTAMP,
--     last_checked_at TEXT,
--     created_at TEXT DEFAULT CURRENT_TIMESTAMP,
--     updated_at TEXT
-- );
--
-- CREATE TABLE IF NOT EXISTS pluginversion (
--     id INTEGER PRIMARY KEY AUTOINCREMENT,
--     pluginregistry_id INTEGER NOT NULL,
--     version TEXT NOT NULL,
--     release_notes TEXT,
--     download_url TEXT,
--     is_prerelease INTEGER DEFAULT 0,
--     released_at TEXT,
--     created_at TEXT DEFAULT CURRENT_TIMESTAMP,
--     FOREIGN KEY (pluginregistry_id) REFERENCES pluginregistry(id) ON DELETE CASCADE,
--     UNIQUE(pluginregistry_id, version)
-- );
--
-- CREATE INDEX IF NOT EXISTS idx_plugin_update_available ON pluginregistry (update_available);
-- CREATE INDEX IF NOT EXISTS idx_plugin_status ON pluginregistry (status);
-- CREATE INDEX IF NOT EXISTS idx_version_plugin ON pluginversion (pluginregistry_id);
-- CREATE INDEX IF NOT EXISTS idx_version_released ON pluginversion (released_at);
