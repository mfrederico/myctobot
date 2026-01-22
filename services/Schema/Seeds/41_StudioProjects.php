<?php
// Studio Projects - User's orchestration projects
// Idempotent: checks if table exists before creating schema

$tables = \RedBeanPHP\R::inspect();
$tableExists = in_array('studioprojects', $tables);

if (!$tableExists) {
    // Create a dummy member reference for FK (will be null in actual use)
    $member = \RedBeanPHP\R::dispense('member');

    $bean = \RedBeanPHP\R::dispense('studioprojects');
    $bean->member = $member;
    $bean->name = 'Schema Project';
    $bean->slug = 'schema-project';
    $bean->studiotemplates_id = null;
    $bean->status = 'draft';
    $bean->variables_json = '{}';
    $bean->pipelines_id = null;
    $bean->schedule_config_json = '{}';
    $bean->next_run_at = null;
    $bean->last_run_at = null;
    $bean->run_count = 0;
    $bean->created_at = date('Y-m-d H:i:s');
    $bean->updated_at = date('Y-m-d H:i:s');
    \RedBeanPHP\R::store($bean);
    \RedBeanPHP\R::trash($bean);

    // Convert to proper JSON columns
    \RedBeanPHP\R::exec('ALTER TABLE `studioprojects` MODIFY COLUMN `variables_json` JSON');
    \RedBeanPHP\R::exec('ALTER TABLE `studioprojects` MODIFY COLUMN `schedule_config_json` JSON');
}
