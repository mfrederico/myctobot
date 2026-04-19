<?php
$bean = \RedBeanPHP\R::dispense('analysisresults');
$bean->boards = $board;
$bean->analysis_type = 'sprint';
$bean->content_json = '{}';
$bean->content_markdown = 'Schema analysis content';
$bean->issue_count = 0;
$bean->status_filter = 'all';
$bean->created_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);
