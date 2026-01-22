<?php
// DiscoveredPlugins table
$bean = \RedBeanPHP\R::dispense('discoveredplugins');
$bean->source_type = 'npm';
$bean->source_url = 'https://registry.npmjs.org';
$bean->plugin_name = 'schema-plugin';
$bean->plugin_version = '1.0.0';
$bean->plugin_description = 'Schema plugin description';
$bean->plugin_author = 'Schema Author';
$bean->plugin_repository = 'https://github.com/schema/plugin';
$bean->plugin_keywords = 'schema,test';
$bean->plugin_requires = '[]';
$bean->plugin_json = '{}';
$bean->download_count = 0;
$bean->star_count = 0;
$bean->last_published_at = date('Y-m-d H:i:s');
$bean->discovered_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

// PluginScans table
$bean = \RedBeanPHP\R::dispense('pluginscans');
$bean->source_type = 'npm';
$bean->status = 'completed';
$bean->plugins_found = 0;
$bean->plugins_added = 0;
$bean->plugins_updated = 0;
$bean->error_log = '[]';
$bean->started_at = date('Y-m-d H:i:s');
$bean->completed_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

// InstalledPlugins table
$bean = \RedBeanPHP\R::dispense('installedplugins');
$bean->member = $member;
$bean->plugin_name = 'schema-plugin';
$bean->plugin_version = '1.0.0';
$bean->source_type = 'npm';
$bean->config_json = '{}';
$bean->is_active = true;
$bean->installed_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

\RedBeanPHP\R::exec('ALTER TABLE `discoveredplugins` MODIFY COLUMN `plugin_requires` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `discoveredplugins` MODIFY COLUMN `plugin_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `pluginscans` MODIFY COLUMN `error_log` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `installedplugins` MODIFY COLUMN `config_json` JSON');
