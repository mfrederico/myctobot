<?php
/**
 * @step_type: shopify_theme
 * @category: ecommerce
 * @label: Shopify Theme
 * @icon: bi-palette
 * @color: info
 * @description: Manage Shopify theme assets, script tags, and banners
 *
 * Required view data: $shopifyConnections
 */
?>
<div class="config-panel" id="config_shopify_theme" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <!-- Connection and Operation Row -->
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Shopify Connection</label>
                    <select class="form-select" name="config_shopify_theme_connection_id" id="shopifyThemeConnectionSelect">
                        <option value="">-- Select Store --</option>
                        <?php foreach ($shopifyConnections ?? [] as $conn): ?>
                        <option value="<?= $conn->id ?>">
                            <?= h($conn->external_name ?: $conn->external_eid) ?>
                            <?php if ($conn->connection_name): ?>(<?= h($conn->connection_name) ?>)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Or use variable: <code>{context.connection_id}</code></small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Operation</label>
                    <select class="form-select" name="config_shopify_theme_operation" id="shopifyThemeOperation" onchange="updateShopifyThemeConfig(this.value)">
                        <option value="">-- Select Operation --</option>
                        <optgroup label="Script Tags">
                            <option value="install_widget">Install Widget Script</option>
                            <option value="remove_widget">Remove Widget Script</option>
                            <option value="list_scripts">List Script Tags</option>
                        </optgroup>
                        <optgroup label="Theme Assets">
                            <option value="upload_banner">Upload Promotional Banner</option>
                            <option value="get_asset">Get Theme Asset</option>
                            <option value="update_asset">Update Theme Asset</option>
                            <option value="delete_asset">Delete Theme Asset</option>
                        </optgroup>
                        <optgroup label="Announcements">
                            <option value="inject_announcement">Inject Announcement Bar</option>
                        </optgroup>
                        <optgroup label="Themes">
                            <option value="list_themes">List Themes</option>
                            <option value="get_main_theme">Get Main Theme</option>
                        </optgroup>
                    </select>
                </div>
            </div>

            <!-- Dynamic Config Sections (shown based on operation) -->

            <!-- Install/Remove Widget -->
            <div id="shopifyThemeWidgetConfig" class="operation-config" style="display: none;">
                <div class="mb-3">
                    <label class="form-label">Widget/Script URL</label>
                    <input type="text" class="form-control font-monospace"
                           name="config_shopify_theme_script_url"
                           placeholder="https://example.com/widget.js or {context.widget_url}">
                    <small class="text-muted">For remove: can be a URL pattern to match</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">Load Event</label>
                    <select class="form-select" name="config_shopify_theme_script_event">
                        <option value="onload">onload (after page loads)</option>
                        <option value="DOMContentLoaded">DOMContentLoaded (as soon as DOM ready)</option>
                    </select>
                </div>
            </div>

            <!-- Upload Banner -->
            <div id="shopifyThemeBannerConfig" class="operation-config" style="display: none;">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Banner Name</label>
                        <input type="text" class="form-control"
                               name="config_shopify_theme_banner_name"
                               placeholder="flash-sale-january or {context.sale_name}">
                        <small class="text-muted">Used for asset filename</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Theme ID (optional)</label>
                        <input type="text" class="form-control"
                               name="config_shopify_theme_id"
                               placeholder="Leave empty for main theme">
                        <small class="text-muted">Or use <code>{context.theme_id}</code></small>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Image Source</label>
                    <select class="form-select mb-2" name="config_shopify_theme_banner_source" id="bannerSourceSelect" onchange="toggleBannerSourceFields(this.value)">
                        <option value="url">URL (download from web)</option>
                        <option value="base64">Base64 Data (from context)</option>
                        <option value="path">Local File Path</option>
                    </select>
                </div>
                <div id="bannerUrlField">
                    <div class="mb-3">
                        <label class="form-label">Image URL</label>
                        <input type="text" class="form-control font-monospace"
                               name="config_shopify_theme_banner_url"
                               placeholder="https://example.com/banner.png or {context.banner_url}">
                    </div>
                </div>
                <div id="bannerBase64Field" style="display: none;">
                    <div class="mb-3">
                        <label class="form-label">Base64 Data Variable</label>
                        <input type="text" class="form-control font-monospace"
                               name="config_shopify_theme_banner_base64"
                               placeholder="{context.banner_base64} or {generate_banner.output.base64}">
                    </div>
                </div>
                <div id="bannerPathField" style="display: none;">
                    <div class="mb-3">
                        <label class="form-label">Local File Path</label>
                        <input type="text" class="form-control font-monospace"
                               name="config_shopify_theme_banner_path"
                               placeholder="/tmp/banner.png or {context.file_path}">
                    </div>
                </div>
            </div>

            <!-- Asset Operations -->
            <div id="shopifyThemeAssetConfig" class="operation-config" style="display: none;">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Asset Key</label>
                        <input type="text" class="form-control font-monospace"
                               name="config_shopify_theme_asset_key"
                               placeholder="assets/custom.css or layout/theme.liquid">
                        <small class="text-muted">Full path within theme</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Theme ID (optional)</label>
                        <input type="text" class="form-control"
                               name="config_shopify_theme_asset_theme_id"
                               placeholder="Leave empty for main theme">
                    </div>
                </div>
                <div id="assetContentField" class="mb-3">
                    <label class="form-label">Asset Content</label>
                    <textarea class="form-control font-monospace"
                              name="config_shopify_theme_asset_content"
                              rows="8"
                              placeholder="CSS, Liquid, or other content..."></textarea>
                    <small class="text-muted">Supports variable substitution: <code>{context.css_content}</code></small>
                </div>
            </div>

            <!-- Announcement Bar -->
            <div id="shopifyThemeAnnouncementConfig" class="operation-config" style="display: none;">
                <div class="row mb-3">
                    <div class="col-md-12">
                        <label class="form-label">Message</label>
                        <input type="text" class="form-control"
                               name="config_shopify_theme_announcement_message"
                               placeholder="Flash Sale! 50% off everything!">
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Background Color</label>
                        <input type="color" class="form-control form-control-color w-100"
                               name="config_shopify_theme_announcement_bg"
                               value="#ff0000">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Text Color</label>
                        <input type="color" class="form-control form-control-color w-100"
                               name="config_shopify_theme_announcement_text"
                               value="#ffffff">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Expires At (optional)</label>
                        <input type="datetime-local" class="form-control"
                               name="config_shopify_theme_announcement_expires">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Link URL (optional)</label>
                    <input type="text" class="form-control"
                           name="config_shopify_theme_announcement_link"
                           placeholder="/collections/sale or {context.sale_url}">
                </div>
            </div>

            <!-- Output Reference -->
            <div class="alert alert-info mb-0 mt-3">
                <strong>Output:</strong> <code>{step_name.output}</code>
                <small class="d-block text-muted">
                    Contains operation result: <code>script_tag</code>, <code>asset</code>, <code>themes</code>, etc.
                </small>
            </div>
        </div>
    </div>
</div>

<script>
function updateShopifyThemeConfig(operation) {
    // Hide all operation configs
    document.querySelectorAll('#config_shopify_theme .operation-config').forEach(el => {
        el.style.display = 'none';
    });

    // Show relevant config section
    switch (operation) {
        case 'install_widget':
        case 'remove_widget':
            document.getElementById('shopifyThemeWidgetConfig').style.display = 'block';
            break;
        case 'upload_banner':
            document.getElementById('shopifyThemeBannerConfig').style.display = 'block';
            break;
        case 'get_asset':
        case 'update_asset':
        case 'delete_asset':
            document.getElementById('shopifyThemeAssetConfig').style.display = 'block';
            // Show/hide content field based on operation
            document.getElementById('assetContentField').style.display =
                operation === 'update_asset' ? 'block' : 'none';
            break;
        case 'inject_announcement':
            document.getElementById('shopifyThemeAnnouncementConfig').style.display = 'block';
            break;
        // list_themes, get_main_theme, list_scripts need no extra config
    }
}

function toggleBannerSourceFields(source) {
    document.getElementById('bannerUrlField').style.display = source === 'url' ? 'block' : 'none';
    document.getElementById('bannerBase64Field').style.display = source === 'base64' ? 'block' : 'none';
    document.getElementById('bannerPathField').style.display = source === 'path' ? 'block' : 'none';
}
</script>
