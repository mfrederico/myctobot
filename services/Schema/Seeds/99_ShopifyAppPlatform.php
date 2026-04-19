<?php
/**
 * Schema Seed: Shopify App Platform for Detachable Apps
 *
 * Tables:
 * - detachableappliquidfiles: Liquid template files (blocks, embeds, sections, snippets, assets)
 * - detachableappinstallations: Track merchant installations of dapps
 * - detachableappembeddedpipelines: Pipelines bundled with dapps
 *
 * Also extends detachableapps table with Shopify app fields.
 */

use \RedBeanPHP\R;

// =====================================================
// detachableappliquidfiles - Liquid template files
// =====================================================
$liquidFiles = R::dispense('detachableappliquidfiles');
$liquidFiles->detachableapps_id = null;
$liquidFiles->file_type = 'block'; // block, embed, section, snippet, asset_js, asset_css, locale
$liquidFiles->file_name = '';      // e.g., "product-reviews.liquid"
$liquidFiles->file_content = '';   // Liquid/JS/CSS code
$liquidFiles->settings_schema = '{}'; // JSON: Theme editor settings (for blocks/sections)
$liquidFiles->sort_order = 0;
$liquidFiles->created_at = R::isoDateTime();
$liquidFiles->updated_at = R::isoDateTime();
R::store($liquidFiles);
R::trash($liquidFiles);

// Ensure file_type is ENUM
R::exec("ALTER TABLE detachableappliquidfiles
    MODIFY COLUMN file_type ENUM('block', 'embed', 'section', 'snippet', 'asset_js', 'asset_css', 'locale') NOT NULL");

// Index for fast lookup
R::exec("CREATE INDEX idx_dapp_filetype ON detachableappliquidfiles(detachableapps_id, file_type)");

// =====================================================
// detachableappinstallations - Track merchant installs
// =====================================================
$installation = R::dispense('detachableappinstallations');
$installation->detachableapps_id = null;
$installation->shop_domain = '';          // mystore.myshopify.com
$installation->shopifyconnections_id = null; // FK to connections table
$installation->access_token = '';         // Encrypted OAuth token
$installation->installed_at = R::isoDateTime();
$installation->uninstalled_at = null;
$installation->settings_json = '{}';      // Merchant-specific settings
$installation->status = 'active';         // active, uninstalled, suspended
R::store($installation);
R::trash($installation);

// Ensure status is ENUM
R::exec("ALTER TABLE detachableappinstallations
    MODIFY COLUMN status ENUM('active', 'uninstalled', 'suspended') DEFAULT 'active'");

// Unique constraint: one installation per dapp per shop
R::exec("CREATE UNIQUE INDEX idx_dapp_shop ON detachableappinstallations(detachableapps_id, shop_domain)");

// Index for shop lookup
R::exec("CREATE INDEX idx_shop_domain ON detachableappinstallations(shop_domain)");

// =====================================================
// detachableappembeddedpipelines - Bundled pipelines
// =====================================================
$embeddedPipeline = R::dispense('detachableappembeddedpipelines');
$embeddedPipeline->detachableapps_id = null;
$embeddedPipeline->pipeline_slug = '';     // Auto-bind target (e.g., "customer-support")
$embeddedPipeline->pipeline_json = '';     // Full pipeline export (steps + config)
$embeddedPipeline->is_required = 1;        // Must be installed?
$embeddedPipeline->auto_bind = 1;          // Try to bind by slug first?
$embeddedPipeline->created_at = R::isoDateTime();
R::store($embeddedPipeline);
R::trash($embeddedPipeline);

// Index for dapp lookup
R::exec("CREATE INDEX idx_dapp_pipelines ON detachableappembeddedpipelines(detachableapps_id)");

// =====================================================
// Extend detachableapps table with Shopify app fields
// =====================================================

// Check if columns already exist before adding
$columns = R::getAll("SHOW COLUMNS FROM detachableapps");
$existingCols = array_column($columns, 'Field');

if (!in_array('shopify_app_handle', $existingCols)) {
    R::exec("ALTER TABLE detachableapps ADD COLUMN shopify_app_handle VARCHAR(100) DEFAULT NULL");
}

if (!in_array('shopify_api_key', $existingCols)) {
    R::exec("ALTER TABLE detachableapps ADD COLUMN shopify_api_key VARCHAR(255) DEFAULT NULL");
}

if (!in_array('shopify_api_secret', $existingCols)) {
    R::exec("ALTER TABLE detachableapps ADD COLUMN shopify_api_secret VARCHAR(500) DEFAULT NULL COMMENT 'Encrypted'");
}

if (!in_array('oauth_scopes', $existingCols)) {
    R::exec("ALTER TABLE detachableapps ADD COLUMN oauth_scopes TEXT DEFAULT NULL COMMENT 'Comma-separated'");
}

if (!in_array('app_proxy_subpath', $existingCols)) {
    R::exec("ALTER TABLE detachableapps ADD COLUMN app_proxy_subpath VARCHAR(100) DEFAULT NULL COMMENT 'e.g., /customer-support'");
}

// Create index on shopify_app_handle for lookups
R::exec("CREATE INDEX idx_shopify_handle ON detachableapps(shopify_app_handle)");
