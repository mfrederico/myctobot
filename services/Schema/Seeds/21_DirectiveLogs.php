<?php
$bean = \RedBeanPHP\R::dispense('directivelogs');
$bean->ceodirectives = $directive;
$bean->log_level = 'info';
$bean->message = 'Schema directive log';
$bean->context_json = '{}';
$bean->created_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

\RedBeanPHP\R::exec('ALTER TABLE `directivelogs` MODIFY COLUMN `context_json` JSON');
