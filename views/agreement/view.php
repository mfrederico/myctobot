<style>
    .agreement-container { max-width: 800px; margin: 0 auto; padding: 24px; }
    .signed-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #e8f5e9;
        color: #2e7d32;
        padding: 6px 16px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
    }
    .agreement-text-readonly {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 2rem;
        max-height: 400px;
        overflow-y: auto;
        font-size: 0.85rem;
        line-height: 1.7;
        color: #495057;
    }
    .signing-record {
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 1.5rem;
    }
    .sig-image {
        background: #fafafa;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 1rem;
        text-align: center;
    }
    .sig-image img {
        max-width: 300px;
        height: auto;
    }
</style>

<div class="agreement-container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1">Sales Referral Agreement</h3>
            <p class="text-muted mb-0">Signed on <?= date('F j, Y \a\t g:i A', strtotime($agreement->signedAt)) ?></p>
        </div>
        <span class="signed-badge"><i class="fas fa-check-circle"></i> Signed</span>
    </div>

    <?php foreach ($_SESSION['flash'] ?? [] as $flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
            <?= htmlspecialchars($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; unset($_SESSION['flash']); ?>

    <!-- Signing Record -->
    <div class="signing-record mb-4">
        <h6 class="fw-bold mb-3"><i class="fas fa-file-signature me-2"></i>Signing Record</h6>
        <div class="row g-3">
            <div class="col-md-4">
                <small class="text-muted d-block">Signed By</small>
                <strong><?= htmlspecialchars($agreement->typedName) ?></strong>
            </div>
            <div class="col-md-4">
                <small class="text-muted d-block">Date & Time</small>
                <strong><?= date('M j, Y g:i A T', strtotime($agreement->signedAt)) ?></strong>
            </div>
            <div class="col-md-4">
                <small class="text-muted d-block">Agreement Version</small>
                <strong>v<?= htmlspecialchars($agreement->agreementVersion) ?></strong>
            </div>
            <div class="col-md-4">
                <small class="text-muted d-block">IP Address</small>
                <code><?= htmlspecialchars($agreement->ipAddress) ?></code>
            </div>
            <div class="col-md-8">
                <small class="text-muted d-block">User Agent</small>
                <code style="font-size: 0.7rem; word-break: break-all;"><?= htmlspecialchars(substr($agreement->userAgent, 0, 120)) ?></code>
            </div>
        </div>

        <!-- Signature Image -->
        <div class="mt-3">
            <small class="text-muted d-block mb-2">Signature</small>
            <div class="sig-image">
                <img src="<?= htmlspecialchars($agreement->signatureData) ?>" alt="Digital Signature">
            </div>
        </div>
    </div>

    <!-- Agreement Text (collapsed) -->
    <div class="mb-4">
        <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#agreementText">
            <i class="fas fa-file-alt me-1"></i> View Full Agreement Text
        </button>
        <div class="collapse mt-3" id="agreementText">
            <div class="agreement-text-readonly">
                <pre style="white-space: pre-wrap; font-family: inherit; margin: 0;"><?= htmlspecialchars($agreement->agreementText) ?></pre>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="d-flex gap-2">
        <a href="/crm" class="btn btn-primary"><i class="fas fa-arrow-left me-1"></i> Back to CRM</a>
    </div>
</div>
