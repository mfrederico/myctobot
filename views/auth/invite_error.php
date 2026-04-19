<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow">
                <div class="card-header bg-danger text-white text-center py-3">
                    <h4 class="mb-0">
                        <i class="bi bi-exclamation-triangle"></i>
                        <?= h($title ?? 'Invitation Error') ?>
                    </h4>
                </div>
                <div class="card-body p-4 text-center">
                    <div class="mb-4">
                        <i class="bi bi-envelope-x text-danger" style="font-size: 4rem;"></i>
                    </div>

                    <p class="lead text-muted mb-4">
                        <?= h($error ?? 'There was a problem with your invitation.') ?>
                    </p>

                    <?php if (($error ?? '') === 'This invitation has expired'): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-info-circle"></i>
                        Please contact your administrator to request a new invitation.
                    </div>
                    <?php endif; ?>

                    <?php if (($error ?? '') === 'This invitation has already been used'): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        Your account may already be active. Try logging in below.
                    </div>
                    <?php endif; ?>

                    <div class="d-grid gap-2">
                        <?php if (!empty($workspace)): ?>
                        <a href="/login/<?= h($workspace) ?>" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right"></i> Go to Login
                        </a>
                        <?php else: ?>
                        <a href="/login" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right"></i> Go to Login
                        </a>
                        <?php endif; ?>

                        <a href="/" class="btn btn-outline-secondary">
                            <i class="bi bi-house"></i> Return to Home
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
