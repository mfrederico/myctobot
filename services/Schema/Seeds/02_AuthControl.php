<?php
$bean = \RedBeanPHP\R::dispense('authcontrol');
$bean->control = 'schema';
$bean->method = 'index';
$bean->level = 101;
$bean->description = 'Schema placeholder';
$bean->created_at = date('Y-m-d H:i:s');
$bean->validcount = 0;
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);
