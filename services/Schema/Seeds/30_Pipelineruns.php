<?php
$bean = \RedBeanPHP\R::dispense('pipelineruns');
$bean->pipelines_id = null;
$bean->member = $member;
$bean->run_uid = 'schema-run-uid';
$bean->status = 'pending';
$bean->trigger_type = 'manual';
$bean->trigger_data = '{}';
$bean->context_json = '{}';
$bean->results_json = '{}';
$bean->error_message = null;
$bean->started_at = date('Y-m-d H:i:s');
$bean->completed_at = date('Y-m-d H:i:s');
$bean->created_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

\RedBeanPHP\R::exec('ALTER TABLE `pipelineruns` MODIFY COLUMN `trigger_data` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `pipelineruns` MODIFY COLUMN `context_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `pipelineruns` MODIFY COLUMN `results_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `pipelineruns` ADD UNIQUE INDEX IF NOT EXISTS `uk_run_uid` (`run_uid`)');
