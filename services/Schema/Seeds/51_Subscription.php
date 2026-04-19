<?php
/**
 * Subscription Schema
 *
 * Member subscription and billing information (Stripe integration).
 */

$bean = \RedBeanPHP\R::dispense('subscription');
$bean->member_id = null;
$bean->billing_email = 'schema@placeholder.com';
$bean->billing_name = 'Schema Placeholder';
$bean->tier = 'free';
$bean->status = 'active';
$bean->stripe_customer_id = null;
$bean->stripe_subscription_id = null;
$bean->current_period_start = null;
$bean->current_period_end = null;
$bean->trial_ends_at = null;
$bean->cancelled_at = null;
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
$bean->trial_used = 0;
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);

// Ensure VARCHAR lengths and defaults
\RedBeanPHP\R::exec('ALTER TABLE `subscription` MODIFY COLUMN `tier` VARCHAR(20) DEFAULT "free"');
\RedBeanPHP\R::exec('ALTER TABLE `subscription` MODIFY COLUMN `status` VARCHAR(20) DEFAULT "active"');
\RedBeanPHP\R::exec('ALTER TABLE `subscription` MODIFY COLUMN `trial_used` TINYINT(1) DEFAULT 0');
