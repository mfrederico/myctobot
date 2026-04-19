<?php
$bean = \RedBeanPHP\R::dispense('pipelinesteps');
$bean->pipelines_id = null;
$bean->name = 'Schema Step';
$bean->description = 'Schema step description';
$bean->step_type = 'http';
$bean->step_order = 1;
$bean->config_json = '{}';
$bean->timeout_seconds = 300;
$bean->retry_count = 0;
$bean->retry_delay_seconds = 60;
$bean->continue_on_failure = false;
$bean->condition_json = '{}';
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

\RedBeanPHP\R::exec('ALTER TABLE `pipelinesteps` MODIFY COLUMN `config_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `pipelinesteps` MODIFY COLUMN `condition_json` JSON');
