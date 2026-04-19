<?php
/**
 * Shard Jobs Schema
 *
 * Jobs dispatched to remote shard servers and their assignments.
 * Uses _eid suffix for non-FK identifiers to avoid RedBeanPHP auto-typing.
 */

// Create shardjobs table
$job = \RedBeanPHP\R::dispense('shardjobs');
$job->job_eid = 'schema-placeholder';
$job->member = $member;
$job->shard_eid = 'schema-shard';
$job->issue_key = 'SCHEMA-1';
$job->status = 'queued';
$job->request_payload = null;
$job->result_payload = null;
$job->error_message = null;
$job->started_at = null;
$job->completed_at = null;
$job->created_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($job);
\RedBeanPHP\R::trash($job);

// Fix columns to proper types
\RedBeanPHP\R::exec('ALTER TABLE `shardjobs` MODIFY COLUMN `job_eid` VARCHAR(64) NOT NULL');
\RedBeanPHP\R::exec('ALTER TABLE `shardjobs` MODIFY COLUMN `shard_eid` VARCHAR(64) NULL');
\RedBeanPHP\R::exec('ALTER TABLE `shardjobs` MODIFY COLUMN `status` ENUM("queued","running","completed","failed","cancelled") DEFAULT "queued"');
\RedBeanPHP\R::exec('ALTER TABLE `shardjobs` MODIFY COLUMN `request_payload` LONGTEXT NULL');
\RedBeanPHP\R::exec('ALTER TABLE `shardjobs` MODIFY COLUMN `result_payload` LONGTEXT NULL');

// Create shardassignments table
$assignment = \RedBeanPHP\R::dispense('shardassignments');
$assignment->member = $member;
$assignment->shard_eid = 'schema-shard';
$assignment->priority = 0;
$assignment->created_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($assignment);
\RedBeanPHP\R::trash($assignment);

\RedBeanPHP\R::exec('ALTER TABLE `shardassignments` MODIFY COLUMN `shard_eid` VARCHAR(64) NULL');
\RedBeanPHP\R::exec('ALTER TABLE `shardassignments` MODIFY COLUMN `priority` INT DEFAULT 0');
