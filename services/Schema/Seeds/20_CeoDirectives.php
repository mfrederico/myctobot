<?php
$directive = \RedBeanPHP\R::dispense('ceodirectives');
$directive->directive_uid = 'schema-ceo-directive-uid';
$directive->member = $member;
$directive->subject = 'Schema CEO directive';
$directive->content = 'Schema CEO directive content';
$directive->source_type = 'email';
$directive->source_email = 'ceo@example.com';
$directive->status = 'pending';
$directive->priority = 'normal';
$directive->analysis_json = '{}';
$directive->execution_plan_json = '[]';
$directive->results_json = '{}';
$directive->error_message = 'Schema error';
$directive->retry_count = 0;
$directive->started_at = date('Y-m-d H:i:s');
$directive->completed_at = date('Y-m-d H:i:s');
$directive->created_at = date('Y-m-d H:i:s');
$directive->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($directive);

\RedBeanPHP\R::exec('ALTER TABLE `ceodirectives` MODIFY COLUMN `analysis_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `ceodirectives` MODIFY COLUMN `execution_plan_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `ceodirectives` MODIFY COLUMN `results_json` JSON');
