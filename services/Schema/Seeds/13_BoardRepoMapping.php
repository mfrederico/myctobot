<?php
$bean = \RedBeanPHP\R::dispense('boardrepomapping');
$bean->boards = $board;
$bean->repoconnections = $repo;
$bean->is_primary = true;
$bean->created_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);
