<?php
/**
 * Connections Schema
 *
 * Unified connector registry for all external service integrations.
 * Uses _eid suffix for external_eid to avoid RedBeanPHP auto-typing as INT FK.
 */

$bean = \RedBeanPHP\R::dispense('connections');
$bean->connector_type = 'schema_placeholder';
$bean->member_id = null;
$bean->connection_name = 'Schema Placeholder Connection';
$bean->auth_type = 'oauth2';
$bean->access_token = null;
$bean->refresh_token = null;
$bean->token_type = null;
$bean->expires_at = null;
$bean->scopes = null;
$bean->external_eid = 'placeholder_eid';
$bean->external_name = null;
$bean->external_url = null;
$bean->enabled = 1;
$bean->shared = 0;
$bean->metadata_json = '{}';
$bean->last_used_at = null;
$bean->last_error = null;
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

// Fix columns that RedBeanPHP would mis-type
\RedBeanPHP\R::exec('ALTER TABLE `connections` MODIFY COLUMN `connector_type` VARCHAR(50) NOT NULL');
\RedBeanPHP\R::exec('ALTER TABLE `connections` MODIFY COLUMN `external_eid` VARCHAR(255) NULL DEFAULT NULL');
\RedBeanPHP\R::exec('ALTER TABLE `connections` MODIFY COLUMN `external_url` VARCHAR(500) NULL DEFAULT NULL');
\RedBeanPHP\R::exec('ALTER TABLE `connections` MODIFY COLUMN `metadata_json` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `connections` MODIFY COLUMN `enabled` TINYINT(1) DEFAULT 1');
\RedBeanPHP\R::exec('ALTER TABLE `connections` MODIFY COLUMN `shared` TINYINT(1) DEFAULT 0');
