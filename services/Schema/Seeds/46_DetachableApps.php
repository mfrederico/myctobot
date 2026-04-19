<?php
/**
 * Detachable Apps Schema
 *
 * Standalone Docker app packages built from Stitch screens + pipeline bindings.
 * Uses _eid suffix for external IDs to avoid RedBeanPHP _id auto-typing as INT FK.
 */

// Create detachableapps table
$bean = \RedBeanPHP\R::dispense('detachableapps');
$bean->name = 'Schema Detachable App';
$bean->slug = 'schema-detachable-app';
$bean->description = 'Schema placeholder';
$bean->stitch_connection_eid = 0;
$bean->stitch_project_eid = 'placeholder';
$bean->git_repo_url = '';
$bean->git_deploy_key = null;
$bean->apikeys_id = null;
$bean->member = $member;
$bean->workspace = 'schema_workspace';
$bean->config_json = '{}';
$bean->build_status = 'idle';
$bean->current_version = null;
$bean->api_token = null;
$bean->created_at = date('Y-m-d H:i:s');
$bean->updated_at = date('Y-m-d H:i:s');
$parentId = \RedBeanPHP\R::store($bean);

// Fix columns RedBeanPHP would mis-type
\RedBeanPHP\R::exec('ALTER TABLE `detachableapps` MODIFY COLUMN `stitch_connection_eid` VARCHAR(191) NULL DEFAULT NULL');
\RedBeanPHP\R::exec('ALTER TABLE `detachableapps` MODIFY COLUMN `stitch_project_eid` VARCHAR(191) NULL DEFAULT NULL');
\RedBeanPHP\R::exec('ALTER TABLE `detachableapps` MODIFY COLUMN `config_json` JSON');

// Create detachableappscreens table (parent must exist for FK)
$screen = \RedBeanPHP\R::dispense('detachableappscreens');
$screen->detachableapps_id = $parentId;
$screen->stitch_screen_eid = 'placeholder';
$screen->screen_title = 'Schema Placeholder Screen';
$screen->route_path = '/';
$screen->is_default = 0;
$screen->stitch_download_url = '';
$screen->sort_order = 0;
$screen->html_cache = null;
$screen->html_cached_at = null;
$screen->created_at = date('Y-m-d H:i:s');
$screen->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($screen);
\RedBeanPHP\R::trash($screen);

// Fix columns
\RedBeanPHP\R::exec('ALTER TABLE `detachableappscreens` MODIFY COLUMN `stitch_screen_eid` VARCHAR(191) NULL DEFAULT NULL');
\RedBeanPHP\R::exec('ALTER TABLE `detachableappscreens` MODIFY COLUMN `stitch_download_url` TEXT NULL DEFAULT NULL');
\RedBeanPHP\R::exec('ALTER TABLE `detachableappscreens` MODIFY COLUMN `html_cache` LONGTEXT NULL DEFAULT NULL');

// Create detachableapppipelines table (parent must exist for FK)
$pip = \RedBeanPHP\R::dispense('detachableapppipelines');
$pip->detachableapps_id = $parentId;
$pip->pipelines_id = null;
$pip->alias = 'placeholder-alias';
$pip->is_active = 1;
$pip->created_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($pip);
\RedBeanPHP\R::trash($pip);

// Create detachableappreleases table (parent must exist for FK)
$rel = \RedBeanPHP\R::dispense('detachableappreleases');
$rel->detachableapps_id = $parentId;
$rel->version = 'v1.0.0';
$rel->git_commit_sha = '';
$rel->git_tag = 'v1.0.0';
$rel->release_notes = '';
$rel->built_by_member = $member;
$rel->screens_snapshot_json = '[]';
$rel->created_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($rel);
\RedBeanPHP\R::trash($rel);

\RedBeanPHP\R::exec('ALTER TABLE `detachableappreleases` MODIFY COLUMN `screens_snapshot_json` JSON');

// Trash parent after all children are cleaned up
\RedBeanPHP\R::trash($bean);
