<?php
/**
 * @step_type: shopify_liquid_eval
 * @category: ecommerce
 * @label: Shopify Liquid Eval
 * @icon: bi-braces
 * @color: primary
 * @description: Evaluate Liquid code against live Shopify store data (requires Shopify CLI)
 *
 * Required view data: $shopifyConnections
 */
?>
<div class="config-panel" id="config_shopify_liquid_eval" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <!-- Connection and Context Row -->
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Shopify Connection</label>
                    <select class="form-select" name="config_shopify_liquid_connection_id" id="shopifyLiquidConnectionSelect">
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
                    <label class="form-label">Page Context (URL)</label>
                    <input type="text" class="form-control font-monospace"
                           name="config_shopify_liquid_url"
                           placeholder="/products/my-product or {context.product_url}">
                    <small class="text-muted">
                        Optional. Sets Liquid objects like <code>product</code>, <code>collection</code>, etc.
                    </small>
                </div>
            </div>

            <!-- Liquid Code Editor -->
            <div class="mb-3">
                <label class="form-label d-flex justify-content-between align-items-center">
                    <span>Liquid Code</span>
                    <a href="https://shopify.dev/docs/api/liquid" target="_blank" class="small text-decoration-none">
                        <i class="bi bi-box-arrow-up-right"></i> Liquid Docs
                    </a>
                </label>
                <textarea class="form-control font-monospace liquid-code-editor"
                          name="config_shopify_liquid_code"
                          rows="12"
                          placeholder="{{ product.title | upcase }}&#10;{{ product.price | money }}&#10;&#10;{% for variant in product.variants %}&#10;  {{ variant.title }}&#10;{% endfor %}"></textarea>
                <small class="text-muted">
                    Supports variable substitution: <code>{context.product_handle}</code>
                </small>
            </div>

            <!-- Examples and Help -->
            <div class="accordion mb-3" id="liquidExamplesAccordion">
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#liquidExamples">
                            <i class="bi bi-lightbulb me-2"></i> Examples
                        </button>
                    </h2>
                    <div id="liquidExamples" class="accordion-collapse collapse" data-bs-parent="#liquidExamplesAccordion">
                        <div class="accordion-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <strong>Product Info:</strong>
                                    <pre class="bg-dark text-light p-2 rounded small mb-0"><code>{{ product.title }}
{{ product.price | money }}
{{ product.description | strip_html | truncate: 100 }}</code></pre>
                                </div>
                                <div class="col-md-6">
                                    <strong>Collection Loop:</strong>
                                    <pre class="bg-dark text-light p-2 rounded small mb-0"><code>{% for product in collections.featured.products %}
  {{ product.title }} - {{ product.price | money }}
{% endfor %}</code></pre>
                                </div>
                                <div class="col-md-6">
                                    <strong>Metafields:</strong>
                                    <pre class="bg-dark text-light p-2 rounded small mb-0"><code>{{ product.metafields.custom.size }}
{{ shop.metafields.global.announcement }}</code></pre>
                                </div>
                                <div class="col-md-6">
                                    <strong>Shop Info:</strong>
                                    <pre class="bg-dark text-light p-2 rounded small mb-0"><code>{{ shop.name }}
{{ shop.currency }}
{{ shop.domain }}</code></pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Test and Output Row -->
            <div class="d-flex justify-content-between align-items-start">
                <div class="alert alert-info mb-0 flex-grow-1 me-3 py-2">
                    <strong>Output:</strong> <code>{step_name.output.result}</code>
                    <small class="d-block text-muted">
                        The evaluated Liquid output as text. Also: <code>{step_name.output.success}</code>
                    </small>
                </div>
                <button type="button" class="btn btn-outline-primary" onclick="testLiquidEval()" id="testLiquidEvalBtn">
                    <i class="bi bi-play-circle"></i> Test Liquid
                </button>
            </div>

            <!-- Liquid Result -->
            <div id="liquidEvalResult" class="mt-3" style="display: none;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label mb-0"><strong>Liquid Output</strong></label>
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            onclick="document.getElementById('liquidEvalResult').style.display='none'">
                        <i class="bi bi-x"></i> Close
                    </button>
                </div>
                <pre class="bg-dark text-light p-3 rounded liquid-result-panel"><code id="liquidEvalResultContent"></code></pre>
            </div>
        </div>
    </div>
</div>

<script>
function testLiquidEval() {
    const connectionId = document.querySelector('[name="config_shopify_liquid_connection_id"]').value;
    const url = document.querySelector('[name="config_shopify_liquid_url"]').value;
    const code = document.querySelector('[name="config_shopify_liquid_code"]').value;

    if (!connectionId) {
        alert('Please select a Shopify connection');
        return;
    }

    if (!code.trim()) {
        alert('Please enter Liquid code to evaluate');
        return;
    }

    const btn = document.getElementById('testLiquidEvalBtn');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Evaluating...';

    fetch('/pipelines/testliquideval', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            connection_id: connectionId,
            url: url || '',
            code: code
        })
    })
    .then(r => r.json())
    .then(data => {
        const resultDiv = document.getElementById('liquidEvalResult');
        const resultContent = document.getElementById('liquidEvalResultContent');

        if (data.success) {
            resultContent.textContent = data.result || '(empty output)';
            resultDiv.style.display = 'block';
        } else {
            resultContent.textContent = 'ERROR: ' + (data.error || 'Unknown error');
            resultDiv.style.display = 'block';
        }
    })
    .catch(err => {
        alert('Test failed: ' + err.message);
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    });
}
</script>

<style>
.liquid-code-editor {
    font-size: 0.9rem;
    line-height: 1.4;
}
.liquid-result-panel {
    max-height: 400px;
    overflow-y: auto;
    font-size: 0.85rem;
    white-space: pre-wrap;
    word-break: break-word;
}
</style>
