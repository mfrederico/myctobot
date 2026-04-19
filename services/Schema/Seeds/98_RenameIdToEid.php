<?php
/**
 * Rename VARCHAR `_id` columns to `_eid`
 *
 * RedBeanPHP auto-types columns ending in `_id` as INT foreign keys.
 * These 13 columns are VARCHAR and need the `_eid` suffix instead.
 *
 * Handles dirty state where both old and new columns may exist
 * (from a failed previous migration attempt that created duplicate columns).
 */

\RedBeanPHP\R::exec('SET FOREIGN_KEY_CHECKS = 0');

// Helper: check if a column exists in a SHOW COLUMNS result set
$hasCol = function (array $columns, string $name): bool {
    foreach ($columns as $col) {
        if ($col['Field'] === $name) {
            return true;
        }
    }
    return false;
};

// --- 1. digestjobs.job_id -> job_eid ---
$cols = \RedBeanPHP\R::getAll('SHOW COLUMNS FROM `digestjobs` WHERE Field IN (?, ?)', ['job_id', 'job_eid']);
if ($hasCol($cols, 'job_id') && $hasCol($cols, 'job_eid')) {
    \RedBeanPHP\R::exec('ALTER TABLE `digestjobs` DROP COLUMN `job_eid`');
    \RedBeanPHP\R::exec('ALTER TABLE `digestjobs` CHANGE COLUMN `job_id` `job_eid` VARCHAR(64) NOT NULL');
} elseif ($hasCol($cols, 'job_id')) {
    \RedBeanPHP\R::exec('ALTER TABLE `digestjobs` CHANGE COLUMN `job_id` `job_eid` VARCHAR(64) NOT NULL');
}

// --- 2. shardjobs.job_id -> job_eid ---
$cols = \RedBeanPHP\R::getAll('SHOW COLUMNS FROM `shardjobs` WHERE Field IN (?, ?)', ['job_id', 'job_eid']);
if ($hasCol($cols, 'job_id') && $hasCol($cols, 'job_eid')) {
    \RedBeanPHP\R::exec('ALTER TABLE `shardjobs` DROP COLUMN `job_eid`');
    \RedBeanPHP\R::exec('ALTER TABLE `shardjobs` CHANGE COLUMN `job_id` `job_eid` VARCHAR(64) NOT NULL');
} elseif ($hasCol($cols, 'job_id')) {
    \RedBeanPHP\R::exec('ALTER TABLE `shardjobs` CHANGE COLUMN `job_id` `job_eid` VARCHAR(64) NOT NULL');
}

// --- 3. ctoepics.epic_id -> epic_eid ---
$cols = \RedBeanPHP\R::getAll('SHOW COLUMNS FROM `ctoepics` WHERE Field IN (?, ?)', ['epic_id', 'epic_eid']);
if ($hasCol($cols, 'epic_id') && $hasCol($cols, 'epic_eid')) {
    \RedBeanPHP\R::exec('ALTER TABLE `ctoepics` DROP COLUMN `epic_eid`');
    \RedBeanPHP\R::exec('ALTER TABLE `ctoepics` CHANGE COLUMN `epic_id` `epic_eid` VARCHAR(32)');
} elseif ($hasCol($cols, 'epic_id')) {
    \RedBeanPHP\R::exec('ALTER TABLE `ctoepics` CHANGE COLUMN `epic_id` `epic_eid` VARCHAR(32)');
}

// --- 4. ctoepics.jira_epic_id -> jira_epic_eid ---
$cols = \RedBeanPHP\R::getAll('SHOW COLUMNS FROM `ctoepics` WHERE Field IN (?, ?)', ['jira_epic_id', 'jira_epic_eid']);
if ($hasCol($cols, 'jira_epic_id') && $hasCol($cols, 'jira_epic_eid')) {
    \RedBeanPHP\R::exec('ALTER TABLE `ctoepics` DROP COLUMN `jira_epic_eid`');
    \RedBeanPHP\R::exec('ALTER TABLE `ctoepics` CHANGE COLUMN `jira_epic_id` `jira_epic_eid` VARCHAR(50)');
} elseif ($hasCol($cols, 'jira_epic_id')) {
    \RedBeanPHP\R::exec('ALTER TABLE `ctoepics` CHANGE COLUMN `jira_epic_id` `jira_epic_eid` VARCHAR(50)');
}

// --- 5. ctostories.story_id -> story_eid ---
$cols = \RedBeanPHP\R::getAll('SHOW COLUMNS FROM `ctostories` WHERE Field IN (?, ?)', ['story_id', 'story_eid']);
if ($hasCol($cols, 'story_id') && $hasCol($cols, 'story_eid')) {
    \RedBeanPHP\R::exec('ALTER TABLE `ctostories` DROP COLUMN `story_eid`');
    \RedBeanPHP\R::exec('ALTER TABLE `ctostories` CHANGE COLUMN `story_id` `story_eid` VARCHAR(32)');
} elseif ($hasCol($cols, 'story_id')) {
    \RedBeanPHP\R::exec('ALTER TABLE `ctostories` CHANGE COLUMN `story_id` `story_eid` VARCHAR(32)');
}

// --- 6. ctostories.jira_issue_id -> jira_issue_eid ---
$cols = \RedBeanPHP\R::getAll('SHOW COLUMNS FROM `ctostories` WHERE Field IN (?, ?)', ['jira_issue_id', 'jira_issue_eid']);
if ($hasCol($cols, 'jira_issue_id') && $hasCol($cols, 'jira_issue_eid')) {
    \RedBeanPHP\R::exec('ALTER TABLE `ctostories` DROP COLUMN `jira_issue_eid`');
    \RedBeanPHP\R::exec('ALTER TABLE `ctostories` CHANGE COLUMN `jira_issue_id` `jira_issue_eid` VARCHAR(50)');
} elseif ($hasCol($cols, 'jira_issue_id')) {
    \RedBeanPHP\R::exec('ALTER TABLE `ctostories` CHANGE COLUMN `jira_issue_id` `jira_issue_eid` VARCHAR(50)');
}

// --- 7. ctostories.aidev_job_id -> aidev_job_eid ---
$cols = \RedBeanPHP\R::getAll('SHOW COLUMNS FROM `ctostories` WHERE Field IN (?, ?)', ['aidev_job_id', 'aidev_job_eid']);
if ($hasCol($cols, 'aidev_job_id') && $hasCol($cols, 'aidev_job_eid')) {
    \RedBeanPHP\R::exec('ALTER TABLE `ctostories` DROP COLUMN `aidev_job_eid`');
    \RedBeanPHP\R::exec('ALTER TABLE `ctostories` CHANGE COLUMN `aidev_job_id` `aidev_job_eid` VARCHAR(64)');
} elseif ($hasCol($cols, 'aidev_job_id')) {
    \RedBeanPHP\R::exec('ALTER TABLE `ctostories` CHANGE COLUMN `aidev_job_id` `aidev_job_eid` VARCHAR(64)');
}

// --- 8. ceodirectivelogs.directive_id -> directive_eid ---
$cols = \RedBeanPHP\R::getAll('SHOW COLUMNS FROM `ceodirectivelogs` WHERE Field IN (?, ?)', ['directive_id', 'directive_eid']);
if ($hasCol($cols, 'directive_id') && $hasCol($cols, 'directive_eid')) {
    \RedBeanPHP\R::exec('ALTER TABLE `ceodirectivelogs` DROP COLUMN `directive_eid`');
    \RedBeanPHP\R::exec('ALTER TABLE `ceodirectivelogs` CHANGE COLUMN `directive_id` `directive_eid` VARCHAR(191)');
} elseif ($hasCol($cols, 'directive_id')) {
    \RedBeanPHP\R::exec('ALTER TABLE `ceodirectivelogs` CHANGE COLUMN `directive_id` `directive_eid` VARCHAR(191)');
}

// --- 9. directivelogs.directive_id -> directive_eid ---
$cols = \RedBeanPHP\R::getAll('SHOW COLUMNS FROM `directivelogs` WHERE Field IN (?, ?)', ['directive_id', 'directive_eid']);
if ($hasCol($cols, 'directive_id') && $hasCol($cols, 'directive_eid')) {
    \RedBeanPHP\R::exec('ALTER TABLE `directivelogs` DROP COLUMN `directive_eid`');
    \RedBeanPHP\R::exec('ALTER TABLE `directivelogs` CHANGE COLUMN `directive_id` `directive_eid` VARCHAR(32)');
} elseif ($hasCol($cols, 'directive_id')) {
    \RedBeanPHP\R::exec('ALTER TABLE `directivelogs` CHANGE COLUMN `directive_id` `directive_eid` VARCHAR(32)');
}

// --- 10. member.google_id -> google_eid ---
$cols = \RedBeanPHP\R::getAll('SHOW COLUMNS FROM `member` WHERE Field IN (?, ?)', ['google_id', 'google_eid']);
if ($hasCol($cols, 'google_id') && $hasCol($cols, 'google_eid')) {
    \RedBeanPHP\R::exec('ALTER TABLE `member` DROP COLUMN `google_eid`');
    \RedBeanPHP\R::exec('ALTER TABLE `member` CHANGE COLUMN `google_id` `google_eid` VARCHAR(255)');
} elseif ($hasCol($cols, 'google_id')) {
    \RedBeanPHP\R::exec('ALTER TABLE `member` CHANGE COLUMN `google_id` `google_eid` VARCHAR(255)');
}

// --- 11. subscription.stripe_customer_id -> stripe_customer_eid ---
$cols = \RedBeanPHP\R::getAll('SHOW COLUMNS FROM `subscription` WHERE Field IN (?, ?)', ['stripe_customer_id', 'stripe_customer_eid']);
if ($hasCol($cols, 'stripe_customer_id') && $hasCol($cols, 'stripe_customer_eid')) {
    \RedBeanPHP\R::exec('ALTER TABLE `subscription` DROP COLUMN `stripe_customer_eid`');
    \RedBeanPHP\R::exec('ALTER TABLE `subscription` CHANGE COLUMN `stripe_customer_id` `stripe_customer_eid` VARCHAR(255)');
} elseif ($hasCol($cols, 'stripe_customer_id')) {
    \RedBeanPHP\R::exec('ALTER TABLE `subscription` CHANGE COLUMN `stripe_customer_id` `stripe_customer_eid` VARCHAR(255)');
}

// --- 12. subscription.stripe_subscription_id -> stripe_subscription_eid ---
$cols = \RedBeanPHP\R::getAll('SHOW COLUMNS FROM `subscription` WHERE Field IN (?, ?)', ['stripe_subscription_id', 'stripe_subscription_eid']);
if ($hasCol($cols, 'stripe_subscription_id') && $hasCol($cols, 'stripe_subscription_eid')) {
    \RedBeanPHP\R::exec('ALTER TABLE `subscription` DROP COLUMN `stripe_subscription_eid`');
    \RedBeanPHP\R::exec('ALTER TABLE `subscription` CHANGE COLUMN `stripe_subscription_id` `stripe_subscription_eid` VARCHAR(255)');
} elseif ($hasCol($cols, 'stripe_subscription_id')) {
    \RedBeanPHP\R::exec('ALTER TABLE `subscription` CHANGE COLUMN `stripe_subscription_id` `stripe_subscription_eid` VARCHAR(255)');
}

// --- 13. test_discoveredplugins.plugin_id -> plugin_eid ---
try {
    $cols = \RedBeanPHP\R::getAll('SHOW COLUMNS FROM `test_discoveredplugins` WHERE Field IN (?, ?)', ['plugin_id', 'plugin_eid']);
    if ($hasCol($cols, 'plugin_id') && $hasCol($cols, 'plugin_eid')) {
        \RedBeanPHP\R::exec('ALTER TABLE `test_discoveredplugins` DROP COLUMN `plugin_eid`');
        \RedBeanPHP\R::exec('ALTER TABLE `test_discoveredplugins` CHANGE COLUMN `plugin_id` `plugin_eid` VARCHAR(64)');
    } elseif ($hasCol($cols, 'plugin_id')) {
        \RedBeanPHP\R::exec('ALTER TABLE `test_discoveredplugins` CHANGE COLUMN `plugin_id` `plugin_eid` VARCHAR(64)');
    }
} catch (\Exception $e) {
    // Table may not exist in all workspaces
}

\RedBeanPHP\R::exec('SET FOREIGN_KEY_CHECKS = 1');
