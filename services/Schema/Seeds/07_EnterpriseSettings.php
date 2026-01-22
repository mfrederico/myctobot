<?php
$bean = \RedBeanPHP\R::dispense('enterprisesettings');
$bean->member = $member;
$bean->setting_key = 'schema_key';
$bean->setting_value = 'schema_value';
$bean->setting_type = 'string';
$bean->is_encrypted = false;
$bean->is_shared = false;
$bean->description = 'Schema setting';
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);
