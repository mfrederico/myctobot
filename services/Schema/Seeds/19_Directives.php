<?php
$bean = \RedBeanPHP\R::dispense('directives');
$bean->directive_uid = 'schema-directive-uid';
$bean->member = $member;
$bean->directive_type = 'email';
$bean->subject = 'Schema directive subject';
$bean->content = 'Schema directive content';
$bean->source_type = 'email';
$bean->source_uid = 'schema_source_uid';
$bean->source_email = 'schema@example.com';
$bean->status = 'pending';
$bean->processed_content = 'Schema processed content';
$bean->context_json = '{}';
$bean->actions_json = '[]';
$bean->results_json = '{}';
$bean->error_message = 'Schema error message';
$bean->retry_count = 0;
$bean->scheduled_at = date('Y-m-d H:i:s');
$bean->started_at = date('Y-m-d H:i:s');
$bean->completed_at = date('Y-m-d H:i:s');
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

\RedBeanPHP\R::exec('ALTER TABLE `directives` MODIFY COLUMN `context_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `directives` MODIFY COLUMN `actions_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `directives` MODIFY COLUMN `results_json` JSON');
