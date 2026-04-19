<?php
// Add template_slug column to detachableapps table
// This column stores which app template was used to create the app (null for Stitch-based apps)

try {
    // Try MySQL/MariaDB first (most common in this project)
    $result = \RedBeanPHP\R::getAll("SHOW COLUMNS FROM detachableapps LIKE 'template_slug'");
    if (empty($result)) {
        \RedBeanPHP\R::exec('ALTER TABLE detachableapps ADD COLUMN template_slug VARCHAR(128) DEFAULT NULL');
    }
} catch (\Exception $e) {
    // Fallback for SQLite
    try {
        $cols = \RedBeanPHP\R::getAll('PRAGMA table_info(detachableapps)');
        $hasColumn = false;
        foreach ($cols as $col) {
            if ($col['name'] === 'template_slug') {
                $hasColumn = true;
                break;
            }
        }
        if (!$hasColumn) {
            \RedBeanPHP\R::exec('ALTER TABLE detachableapps ADD COLUMN template_slug VARCHAR(128) DEFAULT NULL');
        }
    } catch (\Exception $e2) {
        // Table might not exist yet; RedBeanPHP will create it automatically when first bean is stored
    }
}
