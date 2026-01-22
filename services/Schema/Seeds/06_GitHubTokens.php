<?php
$bean = \RedBeanPHP\R::dispense('githubtokens');
$bean->member = $member;
$bean->access_token = 'gho_schema_placeholder_token';
$bean->token_type = 'bearer';
$bean->scope = 'repo,user';
$bean->github_user_uid = 'schema_github_user_uid';
$bean->github_login = 'schema-user';
$bean->github_name = 'Schema User';
$bean->github_avatar = 'https://github.com/avatar.png';
$bean->expires_at = date('Y-m-d H:i:s');
$bean->refresh_token = 'ghr_schema_placeholder_refresh';
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);
