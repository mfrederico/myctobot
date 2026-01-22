<?php
$bean = \RedBeanPHP\R::dispense('pipelinestepruns');
$bean->pipelineruns_id = null;
$bean->pipelinesteps_id = null;
$bean->status = 'pending';
$bean->input_json = '{}';
$bean->output_json = '{}';
$bean->error_message = null;
$bean->attempt_number = 1;
$bean->started_at = date('Y-m-d H:i:s');
$bean->completed_at = date('Y-m-d H:i:s');
$bean->created_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

\RedBeanPHP\R::exec('ALTER TABLE `pipelinestepruns` MODIFY COLUMN `input_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `pipelinestepruns` MODIFY COLUMN `output_json` JSON');
