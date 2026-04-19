<?php
/**
 * Chatbots Schema
 *
 * Multi-tenant chatbot instances. Each chatbot is a configurable AI chat
 * agent that can be embedded on external sites (advisor mode) or used
 * internally within MyCTOBot (builder mode).
 *
 * Also creates chatbotkbs link table for many-to-many chatbot↔knowledgebase.
 * Adds chatbots_id FK to customersessions.
 */

use \RedBeanPHP\R;

// ── chatbots table ──────────────────────────────────────────────────────

$bean = R::dispense('chatbots');
$bean->name = 'Schema Placeholder Chatbot';
$bean->slug = 'schema-placeholder';
$bean->mode = 'advisor';
$bean->connections_id = null;
$bean->pipelines_id = null;
$bean->status = 'active';
$bean->widget_token = 'wgt_schema_placeholder_token';
$bean->config_json = '{}';
$bean->triggers_json = '[]';
$bean->member_id = null;
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
R::store($bean);
R::trash($bean);

// Fix column types
R::exec('ALTER TABLE `chatbots` MODIFY COLUMN `slug` VARCHAR(100) NOT NULL');
R::exec('ALTER TABLE `chatbots` MODIFY COLUMN `mode` VARCHAR(20) NOT NULL DEFAULT \'advisor\'');
R::exec('ALTER TABLE `chatbots` MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT \'active\'');
R::exec('ALTER TABLE `chatbots` MODIFY COLUMN `widget_token` VARCHAR(64) DEFAULT NULL');
R::exec('ALTER TABLE `chatbots` MODIFY COLUMN `config_json` JSON');
R::exec('ALTER TABLE `chatbots` MODIFY COLUMN `triggers_json` JSON');

// Indexes
R::exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_chatbots_slug ON chatbots (slug)');
R::exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_chatbots_widget_token ON chatbots (widget_token)');

// ── chatbotkbs link table ───────────────────────────────────────────────

$link = R::dispense('chatbotkbs');
$link->chatbots_id = null;
$link->knowledgebases_id = null;
$link->priority = 0;
$link->created_at = date('Y-m-d H:i:s');
R::store($link);
R::trash($link);

R::exec('ALTER TABLE `chatbotkbs` MODIFY COLUMN `priority` INT NOT NULL DEFAULT 0');
R::exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_chatbotkbs_pair ON chatbotkbs (chatbots_id, knowledgebases_id)');

// ── Add chatbots_id to customersessions ─────────────────────────────────

try {
    R::exec('ALTER TABLE `customersessions` ADD COLUMN `chatbots_id` INT DEFAULT NULL');
} catch (\Exception $e) {
    // Column may already exist
}
