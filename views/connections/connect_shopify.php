<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi bi-shop text-success me-2"></i>Connect Shopify Store
            </h1>
            <p class="text-muted mb-0">Enter your store domain to start the OAuth authorization flow.</p>
        </div>
        <a href="/settings/connections" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>

    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="/connections/connect/shopify">
                        <div class="mb-3">
                            <label for="shop" class="form-label">Shop Domain <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="shop" name="shop"
                                       placeholder="your-store" required>
                                <span class="input-group-text">.myshopify.com</span>
                            </div>
                            <div class="form-text">
                                <i class="bi bi-info-circle me-1"></i>
                                You'll be redirected to Shopify to authorize access. No manual token needed.
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-box-arrow-in-right"></i> Connect with Shopify
                            </button>
                            <a href="/settings/connections" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
