<?php
$bean = \RedBeanPHP\R::dispense('aiagents');
$bean->member = $member;  // Sets member_id via RedBeanPHP association
$bean->created_by_name = 'Schema User';
$bean->name = 'Default Agent';
$bean->description = 'Schema agent description';
$bean->runner_type = 'claude_cli';
$bean->runner_config = '{}';
$bean->hooks_config = '{}';
$bean->connections_id = null;
$bean->runners_id = null;
$bean->is_active = true;
$bean->is_default = false;
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

\RedBeanPHP\R::exec('ALTER TABLE `aiagents` MODIFY COLUMN `runner_config` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `aiagents` MODIFY COLUMN `hooks_config` JSON');

// Drop legacy mcp_servers column (replaced by aiagents_mcpservers link table)
try {
    \RedBeanPHP\R::exec('ALTER TABLE `aiagents` DROP COLUMN `mcp_servers`');
} catch (\Exception $e) {
    // Column may already be dropped
}

// Add aiagents_id FK to repoconnections
$repo->aiagents_id = null;
\RedBeanPHP\R::store($repo);
