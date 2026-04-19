<?php
/**
 * System Settings Schema
 *
 * Global system configuration (uses underscored table name).
 * RedBeanPHP doesn't allow underscores in bean types, so we use DDL directly.
 */

// Since RedBeanPHP doesn't handle underscored table names well, create directly
\RedBeanPHP\R::exec('
    CREATE TABLE IF NOT EXISTS `system_settings` (
        `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `setting_key` VARCHAR(100) NOT NULL,
        `setting_value` TEXT NULL,
        `description` TEXT NULL,
        `updated_at` DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
');

// Insert placeholder row
\RedBeanPHP\R::exec('
    INSERT INTO `system_settings` (setting_key, setting_value, description, updated_at)
    VALUES (?, ?, ?, ?)
', [
    'schema_placeholder_key',
    'schema_placeholder_value',
    'Schema placeholder setting',
    date('Y-m-d H:i:s')
]);

// Delete placeholder row
\RedBeanPHP\R::exec('DELETE FROM `system_settings` WHERE setting_key = ?', ['schema_placeholder_key']);
