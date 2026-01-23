<?php
/**
 * @step_type: shopify_graphql
 * @category: ecommerce
 * @label: Shopify GraphQL
 * @icon: bi-shop
 * @color: success
 * @description: Execute GraphQL query against a Shopify store
 *
 * Required view data: $shopifyConnections
 */
?>
<div class="config-panel" id="config_shopify_graphql" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Shopify Connection</label>
                        <select class="form-select" name="config_shopify_connection_id" id="shopifyConnectionSelect">
                            <option value="">-- Select Store --</option>
                            <?php foreach ($shopifyConnections ?? [] as $conn): ?>
                            <option value="<?= $conn->id ?>">
                                <?= htmlspecialchars($conn->shop_name ?: $conn->shop_domain) ?>
                                <?php if ($conn->connection_name): ?>(<?= htmlspecialchars($conn->connection_name) ?>)<?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Query Template</label>
                        <select class="form-select" id="shopifyQueryTemplate" onchange="applyShopifyQueryTemplate(this.value)">
                            <option value="">-- Custom Query --</option>
                            <option value="shop_info">Shop Info</option>
                            <option value="list_products">List Products</option>
                            <option value="get_product">Get Product by Handle</option>
                            <option value="list_orders">List Recent Orders</option>
                            <option value="get_customer">Get Customer with Orders</option>
                        </select>
                        <small class="text-muted">Select a template to pre-fill query</small>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">GraphQL Query</label>
                <textarea class="form-control font-monospace" name="config_shopify_query" rows="10"
                          placeholder="{ shop { name } }"></textarea>
                <small class="text-muted">
                    <a href="https://shopify.dev/docs/api/admin-graphql" target="_blank">Shopify GraphQL Docs</a> |
                    Supports variable substitution: <code>{context.key}</code>
                </small>
            </div>

            <div class="mb-3">
                <label class="form-label">Variables (JSON)</label>
                <textarea class="form-control font-monospace" name="config_shopify_variables" rows="3"
                          placeholder='{ "first": 10 }'></textarea>
                <small class="text-muted">GraphQL variables. Values support substitution: <code>"{context.limit}"</code></small>
            </div>

            <div class="d-flex justify-content-between align-items-start mb-3">
                <div class="alert alert-info mb-0 flex-grow-1 me-3">
                    <strong>Output:</strong> Access response via <code>{step_name.output.data}</code><br>
                    <small>Example: <code>{get_products.output.data.products.edges}</code></small>
                </div>
                <button type="button" class="btn btn-outline-success" onclick="testShopifyQuery()" id="testShopifyQueryBtn">
                    <i class="bi bi-play-circle"></i> Test Query
                </button>
            </div>

            <div id="shopifyQueryResult" style="display: none;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label mb-0"><strong>Query Result</strong></label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('shopifyQueryResult').style.display='none'">
                        <i class="bi bi-x"></i> Close
                    </button>
                </div>
                <pre class="bg-dark text-light p-3 rounded" style="max-height: 300px; overflow: auto; font-size: 0.85rem;"><code id="shopifyQueryResultContent"></code></pre>
            </div>
        </div>
    </div>
</div>
