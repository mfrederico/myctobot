<?php
$bean = \RedBeanPHP\R::dispense('atlassiantoken');
$bean->member = $member;
$bean->access_token = 'schema_access_token_placeholder';
$bean->refresh_token = 'schema_refresh_token_placeholder';
$bean->token_type = 'Bearer';
$bean->expires_at = date('Y-m-d H:i:s');
$bean->cloud_uid = 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx';
$bean->site_url = 'https://example.atlassian.net';
$bean->site_name = 'Schema Example Site';
$bean->scopes = 'read:jira-work read:jira-user offline_access';
$bean->is_shared = false;
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

\RedBeanPHP\R::exec('ALTER TABLE `atlassiantoken` ADD UNIQUE INDEX IF NOT EXISTS `uk_member_cloud` (`member_id`, `cloud_uid`)');
