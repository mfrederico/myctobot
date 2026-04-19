<?php
$bean = \RedBeanPHP\R::dispense('ticketanalysiscache');
$bean->boards = $board;
$bean->issue_key = 'SCHEMA-1';
$bean->content_hash = 'schema_content_hash';
$bean->clarity_score = 0;
$bean->clarity_analysis = '{}';
$bean->reporter_name = 'Schema Reporter';
$bean->reporter_email = 'reporter@example.com';
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

\RedBeanPHP\R::exec('ALTER TABLE `ticketanalysiscache` MODIFY COLUMN `clarity_analysis` JSON');
