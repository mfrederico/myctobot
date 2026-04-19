<?php
/**
 * CRM Enrichment Schema - Adds enrichment and outreach columns to crmcontact
 *
 * Columns added:
 * - enriched_at, enrichment_json, enrichment_status, enrichment_source
 * - lead_score, website_url, linkedin_url, decision_maker_name, decision_maker_title
 * - outreach_status, outreach_step, last_outreach_at
 */

$tableName = 'crmcontact';

// Check if columns already exist by loading a sample bean
$sample = \RedBeanPHP\R::findOne($tableName, ' 1=1 LIMIT 1 ');

// If no rows exist, dispense a temporary bean to create columns
if (!$sample) {
    $sample = \RedBeanPHP\R::dispense($tableName);
}

// Only add columns that don't exist yet
$columns = \RedBeanPHP\R::inspect($tableName);

$newColumns = [
    'enriched_at' => null,
    'enrichment_json' => null,
    'enrichment_status' => '',         // pending / enriched / failed / stale
    'enrichment_source' => '',         // ai / manual / import
    'lead_score' => null,              // 1-100 composite score
    'website_url' => '',
    'linkedin_url' => '',
    'decision_maker_name' => '',
    'decision_maker_title' => '',
    'outreach_status' => 'none',       // none / queued / in_sequence / responded / opted_out
    'outreach_step' => 0,              // Current sequence step (0 = not started)
    'last_outreach_at' => null,
];

foreach ($newColumns as $col => $defaultValue) {
    if (!isset($columns[$col])) {
        $bean = \RedBeanPHP\R::dispense($tableName);
        $bean->$col = $defaultValue;
        \RedBeanPHP\R::store($bean);
        \RedBeanPHP\R::trash($bean);
    }
}
