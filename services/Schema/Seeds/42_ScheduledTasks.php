<?php
// Scheduled Tasks - Non-blocking temporal scheduling
// Idempotent: checks if table exists before creating schema

$tables = \RedBeanPHP\R::inspect();
$tableExists = in_array('scheduledtasks', $tables);

if (!$tableExists) {
    $bean = \RedBeanPHP\R::dispense('scheduledtasks');
    $bean->studioprojects_id = null;
    $bean->pipelineruns_id = null;
    $bean->task_type = 'revert_action';
    $bean->scheduled_at = date('Y-m-d H:i:s');
    $bean->status = 'pending';
    $bean->payload_json = '{}';
    $bean->revert_data_json = '{}';
    $bean->retry_count = 0;
    $bean->max_retries = 3;
    $bean->error_message = null;
    $bean->started_at = null;
    $bean->completed_at = null;
    $bean->created_at = date('Y-m-d H:i:s');
    $bean->updated_at = date('Y-m-d H:i:s');
    \RedBeanPHP\R::store($bean);
    \RedBeanPHP\R::trash($bean);

    // Convert to proper JSON columns and add indexes
    \RedBeanPHP\R::exec('ALTER TABLE `scheduledtasks` MODIFY COLUMN `payload_json` JSON');
    \RedBeanPHP\R::exec('ALTER TABLE `scheduledtasks` MODIFY COLUMN `revert_data_json` JSON');
    \RedBeanPHP\R::exec('ALTER TABLE `scheduledtasks` ADD INDEX IF NOT EXISTS `idx_scheduled_at_status` (`scheduled_at`, `status`)');
}
