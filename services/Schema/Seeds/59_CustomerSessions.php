<?php
/**
 * Customer Sessions Schema
 *
 * Customer support chat sessions linked to Shopify orders.
 */

$bean = \RedBeanPHP\R::dispense('customersessions');
$bean->email = 'schema@placeholder.com';
$bean->order_id = null;
$bean->order_name = null;
$bean->aiagents_id = null;
$bean->shopifyconnections_id = null;
$bean->pipelineruns_id = null;
$bean->order_gid = null;
$bean->session_token = 'schema_placeholder_token';
$bean->verification_status = 'pending';
$bean->created_at = date('Y-m-d H:i:s');
$bean->expires_at = null;
$bean->context_json = '{}';
$bean->verified_at = null;
$bean->updated_at = date('Y-m-d H:i:s');
$bean->escalated_at = null;
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

// Ensure JSON column
\RedBeanPHP\R::exec('ALTER TABLE `customersessions` MODIFY COLUMN `context_json` JSON');
