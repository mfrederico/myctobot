<?php
// Studio Projects - User's orchestration projects

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

\RedBeanPHP\R::exec('ALTER TABLE `studioprojects` MODIFY COLUMN `variables_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `studioprojects` MODIFY COLUMN `schedule_config_json` JSON');
