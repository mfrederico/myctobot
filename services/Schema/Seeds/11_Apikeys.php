<?php
$bean = \RedBeanPHP\R::dispense('apikeys');
$bean->member = $member;
$bean->name = 'Schema API Key';
$bean->description = 'Schema API key description';
$bean->token_hash = hash('sha256', 'schema_token_placeholder');
$bean->token_prefix = 'mctb_sche';
$bean->scopes_json = '["read"]';
$bean->rate_limit = 1000;
$bean->is_active = true;
$bean->last_used_at = date('Y-m-d H:i:s');
$bean->expires_at = date('Y-m-d H:i:s', strtotime('+1 year'));
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

\RedBeanPHP\R::exec('ALTER TABLE `apikeys` MODIFY COLUMN `scopes_json` JSON');
