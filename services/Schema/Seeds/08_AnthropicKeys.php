<?php
$bean = \RedBeanPHP\R::dispense('anthropickeys');
$bean->member = $member;
$bean->created_by_name = 'Schema User';
$bean->name = 'Schema Key';
$bean->api_key = 'sk-ant-schema-placeholder';
$bean->model = 'claude-sonnet-4-20250514';
$bean->shared = false;
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);
