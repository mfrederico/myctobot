<?php
$bean = \RedBeanPHP\R::dispense('aidevjoblogs');
$bean->issue_key = 'SCHEMA-1';
$bean->log_level = 'info';
$bean->message = 'Schema log message';
$bean->context_json = '{}';
$bean->created_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);
