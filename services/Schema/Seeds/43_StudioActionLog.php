<?php
// Studio Action Log - Audit trail for reversions

$bean = \RedBeanPHP\R::dispense('studioactionlog');
$bean->studioprojects_id = null;
$bean->action_type = 'example_action';
$bean->action_data_json = '{}';
$bean->revertible = 1;
$bean->reverted_at = null;
$bean->reverted_by_task_id = null;
$bean->created_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

\RedBeanPHP\R::exec('ALTER TABLE `studioactionlog` MODIFY COLUMN `action_data_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `studioactionlog` ADD INDEX IF NOT EXISTS `idx_project_type` (`studioprojects_id`, `action_type`)');
