<?php
/**
 * Tenant Apps Schema
 *
 * Apps are standalone HTTP server services that tenants can create.
 * They run on localhost ports and can expose pipelines as HTTP endpoints.
 */

// Create tenantapps table
$bean = \RedBeanPHP\R::dispense('tenantapps');
$bean->member = $member;
$bean->workspace = 'schema_workspace';
$bean->slug = 'schema-app';
$bean->name = 'Schema App';
$bean->description = 'Schema app description';

// Server Configuration
$bean->port = null;
$bean->status = 'stopped';
$bean->pid = null;
$bean->tmux_session = null;

// App Configuration
$bean->app_type = 'pipeline';
$bean->config_json = '{}';
$bean->routes_json = '[]';

// Pipeline Integration
$bean->pipelines_id = null;

// Authentication
$bean->auth_type = 'api_key';
$bean->api_key = null;

// MCP Exposure
$bean->expose_mcp = false;
$bean->mcp_tools_json = '[]';

// Runtime & Health
$bean->auto_restart = false;
$bean->health_check_path = '/health';
$bean->last_started_at = null;
$bean->last_stopped_at = null;
$bean->last_health_check = null;
$bean->error_message = null;

// Metadata
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');

$parentId = \RedBeanPHP\R::store($bean);

// Modify JSON columns
\RedBeanPHP\R::exec('ALTER TABLE `tenantapps` MODIFY COLUMN `config_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `tenantapps` MODIFY COLUMN `routes_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `tenantapps` MODIFY COLUMN `mcp_tools_json` JSON');

// Add unique constraints
\RedBeanPHP\R::exec('ALTER TABLE `tenantapps` ADD UNIQUE INDEX IF NOT EXISTS `uk_workspace_slug` (`workspace`, `slug`)');
\RedBeanPHP\R::exec('ALTER TABLE `tenantapps` ADD UNIQUE INDEX IF NOT EXISTS `uk_port` (`port`)');

// Create tenantapplogs table (parent must exist for FK)
$logBean = \RedBeanPHP\R::dispense('tenantapplogs');
$logBean->tenantapps_id = $parentId;
$logBean->level = 'info';
$logBean->message = 'Schema placeholder log message';
$logBean->context_json = '{}';
$logBean->created_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($logBean);
\RedBeanPHP\R::trash($logBean);

// Trash parent after children are cleaned up
\RedBeanPHP\R::trash($bean);

// Modify JSON column for logs
\RedBeanPHP\R::exec('ALTER TABLE `tenantapplogs` MODIFY COLUMN `context_json` JSON');
