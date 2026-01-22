<?php
// Studio Templates - Pre-built orchestration patterns
// Idempotent: checks if table exists before creating schema

$tables = \RedBeanPHP\R::inspect();
$tableExists = in_array('studiotemplates', $tables);

if (!$tableExists) {
    // Create table by dispensing a bean with all columns
    $bean = \RedBeanPHP\R::dispense('studiotemplates');
    $bean->name = 'Schema Template';
    $bean->slug = 'schema-template';
    $bean->category = 'example';
    $bean->description = str_repeat('x', 500);
    $bean->icon = 'bi-lightning';
    $bean->color = 'primary';
    $bean->required_connections_json = '["shopify"]';
    $bean->wizard_config_json = '{}';
    $bean->pipeline_template_json = '{}';
    $bean->variables_schema_json = '{}';
    $bean->estimated_duration_minutes = 5;
    $bean->is_active = 1;
    $bean->created_at = date('Y-m-d H:i:s');
    $bean->updated_at = date('Y-m-d H:i:s');
    \RedBeanPHP\R::store($bean);
    \RedBeanPHP\R::trash($bean);

    // Convert to proper JSON columns
    \RedBeanPHP\R::exec('ALTER TABLE `studiotemplates` MODIFY COLUMN `required_connections_json` JSON');
    \RedBeanPHP\R::exec('ALTER TABLE `studiotemplates` MODIFY COLUMN `wizard_config_json` JSON');
    \RedBeanPHP\R::exec('ALTER TABLE `studiotemplates` MODIFY COLUMN `pipeline_template_json` JSON');
    \RedBeanPHP\R::exec('ALTER TABLE `studiotemplates` MODIFY COLUMN `variables_schema_json` JSON');
    \RedBeanPHP\R::exec('ALTER TABLE `studiotemplates` ADD UNIQUE INDEX IF NOT EXISTS `uk_slug` (`slug`)');
}
