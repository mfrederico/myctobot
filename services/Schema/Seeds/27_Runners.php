<?php
$bean = \RedBeanPHP\R::dispense('runners');
$bean->name = 'Schema Runner';
$bean->description = 'Schema runner description';
$bean->host = '127.0.0.1';
$bean->port = 22;
$bean->username = 'claudeuser';
$bean->auth_type = 'key';
$bean->sshkeys_id = null;
$bean->password_encrypted = null;
$bean->work_directory = '/tmp/aidev-jobs';
$bean->capabilities = '[]';
$bean->max_concurrent_jobs = 2;
$bean->current_jobs = 0;
$bean->status = 'online';
$bean->last_heartbeat = date('Y-m-d H:i:s');
$bean->last_error = null;
$bean->is_active = true;
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

\RedBeanPHP\R::exec('ALTER TABLE `runners` MODIFY COLUMN `capabilities` JSON');
