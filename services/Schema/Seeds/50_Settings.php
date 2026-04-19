<?php
/**
 * Settings Schema
 *
 * User settings table for per-member configuration values.
 */

$bean = \RedBeanPHP\R::dispense('settings');
$bean->member_id = null;
$bean->setting_key = 'schema_placeholder_key';
$bean->setting_value = 'schema_placeholder_value';
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

// Ensure setting_key is VARCHAR(100)
\RedBeanPHP\R::exec('ALTER TABLE `settings` MODIFY COLUMN `setting_key` VARCHAR(100) NOT NULL');
\RedBeanPHP\R::exec('ALTER TABLE `settings` MODIFY COLUMN `setting_value` TEXT NULL');
