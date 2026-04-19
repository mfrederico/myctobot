<?php
/**
 * Scheduled Recurring Schema
 *
 * Recurring pipeline schedules (cron-like triggers).
 */

$bean = \RedBeanPHP\R::dispense('scheduledrecurring');
$bean->pipeline_id = null;
$bean->name = 'Schema Placeholder Schedule';
$bean->description = 'Schema placeholder recurring schedule';
$bean->schedule_type = 'daily';
$bean->schedule_config_json = '{}';
$bean->timezone = 'UTC';
$bean->max_concurrent = 1;
$bean->on_overlap = 'skip';
$bean->queue_limit = null;
$bean->is_active = 1;
$bean->entry_step = null;
$bean->input_data_json = '{}';
$bean->run_count = 0;
$bean->current_running = 0;
$bean->queued_count = 0;
$bean->last_run_at = null;
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
$bean->next_run_at = null;
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

// Ensure JSON and TINYINT columns
\RedBeanPHP\R::exec('ALTER TABLE `scheduledrecurring` MODIFY COLUMN `schedule_config_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `scheduledrecurring` MODIFY COLUMN `input_data_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `scheduledrecurring` MODIFY COLUMN `max_concurrent` TINYINT DEFAULT 1');
\RedBeanPHP\R::exec('ALTER TABLE `scheduledrecurring` MODIFY COLUMN `is_active` TINYINT(1) DEFAULT 1');
\RedBeanPHP\R::exec('ALTER TABLE `scheduledrecurring` MODIFY COLUMN `run_count` INT DEFAULT 0');
\RedBeanPHP\R::exec('ALTER TABLE `scheduledrecurring` MODIFY COLUMN `current_running` TINYINT DEFAULT 0');
\RedBeanPHP\R::exec('ALTER TABLE `scheduledrecurring` MODIFY COLUMN `queued_count` TINYINT DEFAULT 0');
