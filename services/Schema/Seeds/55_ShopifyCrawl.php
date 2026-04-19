<?php
/**
 * Shopify Crawl Schema
 *
 * Tables for Shopify storefront crawler and knowledge base integration.
 * Uses NULL for shopifyconnections_id since parent is not guaranteed to exist.
 */

// Create shopifycrawlpages table (no parent dependency, set FK to NULL)
$pages = \RedBeanPHP\R::dispense('shopifycrawlpages');
$pages->shopifyconnections_id = null;
$pages->url = 'https://schema.example.com/placeholder';
$pages->page_type = 'product';
$pages->title = 'Schema Placeholder Page';
$pages->content_text = 'Schema placeholder content';
$pages->metadata_json = '{}';
$pages->status = 'pending';
$pages->error_message = null;
$pages->knowledge_id = null;
$pages->crawled_at = null;
$pages->created_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($pages);
\RedBeanPHP\R::trash($pages);

\RedBeanPHP\R::exec('ALTER TABLE `shopifycrawlpages` MODIFY COLUMN `content_text` TEXT NULL');
\RedBeanPHP\R::exec('ALTER TABLE `shopifycrawlpages` MODIFY COLUMN `metadata_json` JSON');

// Create shopifycrawlstatus table (no parent dependency, set FK to NULL)
$status = \RedBeanPHP\R::dispense('shopifycrawlstatus');
$status->shopifyconnections_id = null;
$status->status = 'idle';
$status->status_message = null;
$status->urls_discovered = 0;
$status->urls_crawled = 0;
$status->urls_errored = 0;
$status->last_discovery_at = null;
$status->last_crawl_at = null;
$status->created_at = date('Y-m-d H:i:s');
$status->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($status);
\RedBeanPHP\R::trash($status);

\RedBeanPHP\R::exec('ALTER TABLE `shopifycrawlstatus` MODIFY COLUMN `urls_discovered` INT DEFAULT 0');
\RedBeanPHP\R::exec('ALTER TABLE `shopifycrawlstatus` MODIFY COLUMN `urls_crawled` INT DEFAULT 0');
\RedBeanPHP\R::exec('ALTER TABLE `shopifycrawlstatus` MODIFY COLUMN `urls_errored` INT DEFAULT 0');
