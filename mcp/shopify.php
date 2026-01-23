<?php
/**
 * MyCTOBot Shopify MCP Tools
 *
 * This file defines Shopify tools that fastmcphp can discover and load.
 * Tools use the authenticated member's Shopify connection from MyCTOBot.
 *
 * @package MyCTOBot\MCP
 */

declare(strict_types=1);

use Fastmcphp\Fastmcphp;

return function (Fastmcphp $mcp, callable $getService): void {

    // shopify_get_shop_info - Get store information
    $mcp->tool(
        callable: function (?int $connection_id = null) use ($getService): string {
            $shopify = $getService('shopify', $connection_id);
            $info = $shopify->getShopInfo();
            return json_encode($info, JSON_PRETTY_PRINT);
        },
        name: 'shopify_get_shop_info',
        description: 'Get information about the connected Shopify store',
    );

    // shopify_get_themes - List all themes
    $mcp->tool(
        callable: function (?int $connection_id = null) use ($getService): string {
            $shopify = $getService('shopify', $connection_id);
            $themes = $shopify->getThemes();

            return json_encode(array_map(fn($t) => [
                'id' => $t['id'],
                'name' => $t['name'],
                'role' => $t['role'],
            ], $themes), JSON_PRETTY_PRINT);
        },
        name: 'shopify_get_themes',
        description: 'List all themes for the Shopify store',
    );

    // shopify_get_theme_assets - List assets in a theme
    $mcp->tool(
        callable: function (int $theme_id, ?int $connection_id = null) use ($getService): string {
            $shopify = $getService('shopify', $connection_id);
            $assets = $shopify->getThemeAssets($theme_id);

            return json_encode(array_map(fn($a) => [
                'key' => $a['key'],
                'content_type' => $a['content_type'] ?? null,
            ], $assets), JSON_PRETTY_PRINT);
        },
        name: 'shopify_get_theme_assets',
        description: 'List all assets (files) in a theme',
    );

    // shopify_get_theme_asset - Get specific asset content
    $mcp->tool(
        callable: function (int $theme_id, string $key, ?int $connection_id = null) use ($getService): string {
            $shopify = $getService('shopify', $connection_id);
            $asset = $shopify->getThemeAsset($theme_id, $key);

            if (!$asset) {
                throw new \RuntimeException("Asset not found: {$key}");
            }

            return json_encode([
                'key' => $asset['key'],
                'value' => $asset['value'] ?? null,
            ], JSON_PRETTY_PRINT);
        },
        name: 'shopify_get_theme_asset',
        description: 'Get content of a specific theme asset file',
    );

    // shopify_update_theme_asset - Create or update asset
    $mcp->tool(
        callable: function (int $theme_id, string $key, string $value, ?int $connection_id = null) use ($getService): string {
            $shopify = $getService('shopify', $connection_id);
            $result = $shopify->updateThemeAsset($theme_id, $key, $value);

            return json_encode([
                'success' => true,
                'key' => $result['key'] ?? $key,
            ], JSON_PRETTY_PRINT);
        },
        name: 'shopify_update_theme_asset',
        description: 'Create or update a theme asset file',
    );

    // shopify_create_dev_theme - Create development theme for a ticket
    $mcp->tool(
        callable: function (string $ticket_key, string $ticket_title = '', ?int $connection_id = null) use ($getService): string {
            $shopify = $getService('shopify', $connection_id);
            $theme = $shopify->getOrCreateDevTheme($ticket_key, $ticket_title);

            return json_encode([
                'id' => $theme['id'],
                'name' => $theme['name'],
                'preview_url' => $theme['preview_url'] ?? $shopify->getPreviewUrl($theme['id'])
            ], JSON_PRETTY_PRINT);
        },
        name: 'shopify_create_dev_theme',
        description: 'Create or get existing development theme for a ticket',
    );

    // shopify_delete_theme - Delete a theme
    $mcp->tool(
        callable: function (int $theme_id, ?int $connection_id = null) use ($getService): string {
            $shopify = $getService('shopify', $connection_id);
            $deleted = $shopify->deleteTheme($theme_id);
            return json_encode(['success' => $deleted], JSON_PRETTY_PRINT);
        },
        name: 'shopify_delete_theme',
        description: 'Delete a development theme',
    );

    // shopify_get_preview_url - Get preview URL for a theme
    $mcp->tool(
        callable: function (int $theme_id, ?int $connection_id = null) use ($getService): string {
            $shopify = $getService('shopify', $connection_id);
            return json_encode(['preview_url' => $shopify->getPreviewUrl($theme_id)], JSON_PRETTY_PRINT);
        },
        name: 'shopify_get_preview_url',
        description: 'Get preview URL for a theme',
    );
};
