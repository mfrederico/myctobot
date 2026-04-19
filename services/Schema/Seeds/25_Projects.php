<?php
$bean = \RedBeanPHP\R::dispense('projects');
$bean->member = $member;
$bean->name = 'Schema Project';
$bean->description = 'Schema project description';
$bean->status = 'active';
$bean->project_type = 'web';
$bean->settings_json = '{}';
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

\RedBeanPHP\R::exec('ALTER TABLE `projects` MODIFY COLUMN `settings_json` JSON');
