<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="/orchestration">Studio</a></li>
                    <li class="breadcrumb-item active">Templates</li>
                </ol>
            </nav>
            <h1 class="h2 mb-0">
                <i class="bi bi-collection"></i> Orchestration Templates
            </h1>
        </div>
        <a href="/orchestration/wizard" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Custom Orchestration
        </a>
    </div>

    <!-- Category Filter -->
    <div class="card mb-4">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap gap-2">
                <a href="/orchestration/templates" class="btn btn-sm <?= !$selectedCategory ? 'btn-primary' : 'btn-outline-secondary' ?>">
                    <i class="bi bi-grid-3x3-gap"></i> All
                </a>
                <?php foreach ($categories as $key => $cat): ?>
                <a href="/orchestration/templates?category=<?= $key ?>"
                   class="btn btn-sm <?= $selectedCategory === $key ? 'btn-' . $cat['color'] : 'btn-outline-' . $cat['color'] ?>">
                    <i class="bi <?= $cat['icon'] ?>"></i> <?= $cat['label'] ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if (empty($templates)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-collection display-4 text-muted"></i>
            <h4 class="mt-3">No templates found</h4>
            <p class="text-muted mb-4">
                <?php if ($selectedCategory): ?>
                No templates in this category yet.
                <?php else: ?>
                Templates are being added. Check back soon!
                <?php endif; ?>
            </p>
            <a href="/orchestration/wizard" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Create Custom Orchestration
            </a>
        </div>
    </div>
    <?php else: ?>
    <div class="row g-4">
        <?php foreach ($templates as $template): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 hover-shadow">
                <div class="card-body">
                    <div class="d-flex align-items-start mb-3">
                        <div class="rounded bg-<?= $template['color'] ?> bg-opacity-10 p-3 me-3">
                            <i class="bi <?= $template['icon'] ?> fs-4 text-<?= $template['color'] ?>"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h5 class="card-title mb-1"><?= htmlspecialchars($template['name']) ?></h5>
                            <?php if ($template['category_info']): ?>
                            <span class="badge bg-<?= $template['category_info']['color'] ?> bg-opacity-10 text-<?= $template['category_info']['color'] ?>">
                                <?= $template['category_info']['label'] ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <p class="card-text text-muted small mb-3">
                        <?= htmlspecialchars($template['description']) ?>
                    </p>
                    <?php if (!empty($template['required_connections'])): ?>
                    <div class="mb-3">
                        <small class="text-muted d-block mb-1">Requires:</small>
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($template['required_connections'] as $conn): ?>
                            <span class="badge bg-light text-dark">
                                <?= htmlspecialchars(ucfirst($conn)) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($template['estimated_duration_minutes']): ?>
                    <small class="text-muted">
                        <i class="bi bi-clock"></i> ~<?= $template['estimated_duration_minutes'] ?> min setup
                    </small>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-transparent border-top-0">
                    <div class="d-grid gap-2">
                        <a href="/orchestration/wizard/<?= $template['id'] ?>" class="btn btn-<?= $template['color'] ?>">
                            <i class="bi bi-play-fill"></i> Use This Template
                        </a>
                        <a href="/orchestration/template/<?= $template['id'] ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-info-circle"></i> View Details
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<style>
.hover-shadow {
    transition: box-shadow 0.2s ease-in-out, transform 0.2s ease-in-out;
}
.hover-shadow:hover {
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
    transform: translateY(-2px);
}
</style>
