<?php
$bean = \RedBeanPHP\R::dispense('mcpservers');
$bean->member = $member;
$bean->name = 'Schema MCP Server';
$bean->description = 'Schema server description';
$bean->server_type = 'stdio';
$bean->command = 'npx';
$bean->args_json = '[]';
$bean->url = 'https://example.com/mcp';
$bean->headers_json = '{}';
$bean->env_json = '{}';
$bean->is_shared = false;
$bean->is_builtin = false;
$bean->is_required = false;
$bean->is_active = true;
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

\RedBeanPHP\R::exec('ALTER TABLE `mcpservers` MODIFY COLUMN `args_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `mcpservers` MODIFY COLUMN `headers_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `mcpservers` MODIFY COLUMN `env_json` JSON');
