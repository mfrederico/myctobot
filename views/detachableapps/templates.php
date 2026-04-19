<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi bi-grid-3x3-gap text-primary"></i> App Templates
            </h1>
            <p class="text-muted mb-0">Choose a pre-built template to create your app</p>
        </div>
        <a href="/detachableapps" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Apps
        </a>
    </div>

    <div class="row">
        <?php foreach ($templates as $template): ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-body text-center py-4">
                    <i class="bi <?= h($template->getIcon()) ?> display-4 text-primary"></i>
                    <h5 class="mt-3"><?= h($template->getName()) ?></h5>
                    <p class="text-muted small mb-3"><?= h($template->getDescription()) ?></p>
                    <a href="/detachableapps/templateform/<?= h($template->getSlug()) ?>" class="btn btn-primary">
                        <i class="bi bi-plus-lg"></i> Use This Template
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Blank App Card -->
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 border-dashed">
                <div class="card-body text-center py-4">
                    <i class="bi bi-file-earmark-plus display-4 text-muted"></i>
                    <h5 class="mt-3 text-muted">Blank App</h5>
                    <p class="text-muted small mb-3">Start from scratch with a Stitch connection and build your own screens.</p>
                    <a href="/detachableapps/form" class="btn btn-outline-secondary">
                        <i class="bi bi-plus-lg"></i> Create Blank App
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
