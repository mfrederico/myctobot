<?php
$bean = \RedBeanPHP\R::dispense('pipelines');
$bean->member = $member;
$bean->name = 'Schema Pipeline';
$bean->description = 'Schema pipeline description';
$bean->slug = 'schema-pipeline';
$bean->trigger_type = 'manual';
$bean->trigger_config = '{}';
$bean->is_active = true;
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

\RedBeanPHP\R::exec('ALTER TABLE `pipelines` MODIFY COLUMN `trigger_config` JSON');
\RedBeanPHP\R::exec('ALTER TABLE `pipelines` ADD UNIQUE INDEX IF NOT EXISTS `uk_slug` (`slug`)');
