<?php
/**
 * Leads Schema
 *
 * Marketing leads and prospective customers.
 */

$bean = \RedBeanPHP\R::dispense('leads');
$bean->email = 'schema@placeholder.com';
$bean->store_url = null;
$bean->use_case = null;
$bean->notes = null;
$bean->source = null;
$bean->status = null;
$bean->created_at = date('Y-m-d H:i:s');
$bean->lead_score = null;
$bean->classification = null;
\RedBeanPHP\R::store($bean);
\RedBeanPHP\R::trash($bean);
