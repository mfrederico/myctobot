<?php
/**
 * Shopify Connections Schema (Legacy)
 *
 * Legacy Shopify connection table (replaced by unified connections table).
 * Kept for backward compatibility during migration.
 */

$bean = \RedBeanPHP\R::dispense('shopifyconnections');
$bean->created_by_member_id = null;
$bean->created_by_name = 'Schema Placeholder';
$bean->connection_name = 'Schema Shopify Connection';
$bean->shop_domain = 'schema-placeholder.myshopify.com';
$bean->access_token = null;
$bean->enabled = 1;
$bean->shared = 0;
$bean->created_at = date('Y-m-d H:i:s');
$bean->shop_name = null;
$bean->shop_email = null;
$bean->repo_connection_id = null;
$bean->updated_at = date('Y-m-d H:i:s');
$bean->repoconnections_id = null;
$bean->crawler_signature = null;
$bean->crawler_signature_input = null;
$bean->crawler_signature_agent = null;
$bean->primary_domain = null;
$bean->storefront_password = null;
$bean->pipelines_id = null;
$bean->public_agent_token = null;
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

// Ensure TINYINT columns
\RedBeanPHP\R::exec('ALTER TABLE `shopifyconnections` MODIFY COLUMN `enabled` TINYINT(1) DEFAULT 1');
\RedBeanPHP\R::exec('ALTER TABLE `shopifyconnections` MODIFY COLUMN `shared` TINYINT(1) DEFAULT 0');
