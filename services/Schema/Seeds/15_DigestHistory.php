<?php
$bean = \RedBeanPHP\R::dispense('digesthistory');
$bean->boards = $board;
$bean->member = $member;
$bean->digest_type = 'daily';
$bean->content_markdown = 'Schema digest content';
$bean->email_sent = false;
$bean->email_to = 'schema@example.com';
$bean->created_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);
