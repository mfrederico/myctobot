<?php
$bean = \RedBeanPHP\R::dispense('ctoepics');
$bean->ceodirectives = $directive;
$bean->member = $member;
$bean->name = 'Schema Epic';
$bean->description = 'Schema epic description';
$bean->status = 'planning';
$bean->priority = 'normal';
$bean->sequence = 1;
$bean->requirements_json = '{}';
$bean->started_at = date('Y-m-d H:i:s');
$bean->completed_at = date('Y-m-d H:i:s');
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

\RedBeanPHP\R::exec('ALTER TABLE `ctoepics` MODIFY COLUMN `requirements_json` JSON');
