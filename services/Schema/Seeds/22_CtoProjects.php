<?php
$bean = \RedBeanPHP\R::dispense('ctoprojects');
$bean->ceodirectives = $directive;
$bean->member = $member;
$bean->name = 'Schema CTO Project';
$bean->description = 'Schema project description';
$bean->status = 'planning';
$bean->priority = 'normal';
$bean->requirements_json = '{}';
$bean->architecture_json = '{}';
$bean->timeline_json = '{}';
$bean->started_at = date('Y-m-d H:i:s');
$bean->completed_at = date('Y-m-d H:i:s');
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

\RedBeanPHP\R::exec('ALTER TABLE `ctoprojects` MODIFY COLUMN `requirements_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `ctoprojects` MODIFY COLUMN `architecture_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `ctoprojects` MODIFY COLUMN `timeline_json` JSON');
