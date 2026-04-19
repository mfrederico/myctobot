<?php
/**
 * Digest Jobs Schema
 *
 * Background jobs for Jira digest email generation.
 * Uses job_eid (not job_id) to avoid RedBeanPHP auto-typing as INT FK.
 */

$bean = \RedBeanPHP\R::dispense('digestjobs');
$bean->job_eid = 'schema-placeholder';
$bean->member = $member;
$bean->boards = $board;
$bean->board_name = 'Schema Placeholder Board';
$bean->project_key = 'SCHEMA';
$bean->status = 'queued';
$bean->send_email = 1;
$bean->digest_cc = null;
$bean->result = null;
$bean->error = null;
$bean->created_at = date('Y-m-d H:i:s');
$bean->started_at = null;
$bean->completed_at = null;
$bean->shard_eid = null;
$bean->items_count = 0;
$bean->phase = null;
$bean->elapsed_seconds = null;
$bean->updated = null;
$bean->partial_output = null;
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

// Fix columns to proper types
\RedBeanPHP\R::exec('ALTER TABLE `digestjobs` MODIFY COLUMN `job_eid` VARCHAR(64) NOT NULL');
\RedBeanPHP\R::exec('ALTER TABLE `digestjobs` MODIFY COLUMN `status` ENUM("queued","pending","running","completed","failed") DEFAULT "queued"');
\RedBeanPHP\R::exec('ALTER TABLE `digestjobs` MODIFY COLUMN `send_email` TINYINT(1) DEFAULT 1');
\RedBeanPHP\R::exec('ALTER TABLE `digestjobs` MODIFY COLUMN `result` LONGTEXT NULL');
\RedBeanPHP\R::exec('ALTER TABLE `digestjobs` MODIFY COLUMN `items_count` TINYINT DEFAULT 0');
\RedBeanPHP\R::exec('ALTER TABLE `digestjobs` MODIFY COLUMN `elapsed_seconds` INT NULL');
