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
            <!-- Connection and Template Row -->
            <div class="row mb-3">
                <div class="col-md-6">
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
                <div class="col-md-6">
                    <label class="form-label">Query Template</label>
                    <select class="form-select" id="shopifyQueryTemplate" onchange="applyShopifyQueryTemplate(this.value)">
                        <option value="">-- Custom Query --</option>
                        <option value="shop_info">Shop Info</option>
                        <option value="list_products">List Products</option>
                        <option value="get_product">Get Product by Handle</option>
                        <option value="list_orders">List Recent Orders</option>
                        <option value="get_customer">Get Customer with Orders</option>
                    </select>
                </div>
            </div>

            <!-- Side-by-side Query and Variables -->
            <div class="row graphql-editor-row">
                <div class="col-lg-7 col-md-12 mb-3 mb-lg-0">
                    <label class="form-label d-flex justify-content-between align-items-center">
                        <span>GraphQL Query</span>
                        <a href="https://shopify.dev/docs/api/admin-graphql" target="_blank" class="small text-decoration-none">
                            <i class="bi bi-box-arrow-up-right"></i> Docs
                        </a>
                    </label>
                    <textarea class="form-control font-monospace graphql-query-editor"
                              name="config_shopify_query"
                              rows="18"
                              placeholder="{ shop { name } }"></textarea>
                    <small class="text-muted">
                        Supports variable substitution: <code>{context.key}</code>
                    </small>
                </div>
                <div class="col-lg-5 col-md-12">
                    <label class="form-label">Variables (JSON)</label>
                    <textarea class="form-control font-monospace graphql-variables-editor"
                              name="config_shopify_variables"
                              rows="18"
                              placeholder='{ "first": 10 }'></textarea>
                    <small class="text-muted">
                        Values support substitution: <code>"{context.limit}"</code>
                    </small>
                </div>
            </div>

            <!-- Test and Output Row -->
            <div class="d-flex justify-content-between align-items-start mt-3">
                <div class="alert alert-info mb-0 flex-grow-1 me-3 py-2">
                    <strong>Output:</strong> <code>{step_name.output.data}</code>
                    <small class="d-block text-muted">Example: <code>{get_products.output.data.products.edges}</code></small>
                </div>
                <button type="button" class="btn btn-outline-success" onclick="testShopifyQuery()" id="testShopifyQueryBtn">
                    <i class="bi bi-play-circle"></i> Test Query
                </button>
            </div>

            <!-- Query Result -->
            <div id="shopifyQueryResult" class="mt-3" style="display: none;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label mb-0"><strong>Query Result</strong></label>
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            onclick="document.getElementById('shopifyQueryResult').style.display='none'">
                        <i class="bi bi-x"></i> Close
                    </button>
                </div>
                <pre class="bg-dark text-light p-3 rounded graphql-result-panel"><code id="shopifyQueryResultContent"></code></pre>
            </div>
        </div>
    </div>
</div>
