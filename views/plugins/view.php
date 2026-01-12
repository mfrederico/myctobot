<div class="container">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/plugins">Plugin Registry</a></li>
            <?php if (!empty($plugin['category_name'])): ?>
            <li class="breadcrumb-item">
                <a href="/plugins/category/<?= htmlspecialchars($plugin['category_slug']) ?>">
                    <?= htmlspecialchars($plugin['category_name']) ?>
                </a>
            </li>
            <?php endif; ?>
            <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($plugin['name']) ?></li>
        </ol>
    </nav>

    <div class="row">
        <!-- Main Content -->
        <div class="col-lg-8">
            <!-- Plugin Header Card -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <div class="me-4">
                            <div class="bg-light rounded p-3">
                                <i class="<?= htmlspecialchars($plugin['icon'] ?? 'bi-puzzle-fill') ?> display-3 text-primary"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h1 class="h2 mb-1"><?= htmlspecialchars($plugin['name']) ?></h1>
                                    <p class="text-muted mb-2">
                                        by <strong><?= htmlspecialchars($plugin['author'] ?? 'Unknown') ?></strong>
                                        <?php if (!empty($plugin['author_url'])): ?>
                                        <a href="<?= htmlspecialchars($plugin['author_url']) ?>" target="_blank" class="ms-1">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <?php if ($plugin['is_featured']): ?>
                                <span class="badge bg-warning text-dark fs-6">
                                    <i class="bi bi-star-fill me-1"></i>Featured
                                </span>
                                <?php endif; ?>
                            </div>

                            <p class="lead mb-3"><?= htmlspecialchars($plugin['short_description'] ?? '') ?></p>

                            <!-- Category and Tags -->
                            <div class="mb-3">
                                <?php if (!empty($plugin['category_name'])): ?>
                                <a href="/plugins/category/<?= htmlspecialchars($plugin['category_slug']) ?>" class="badge bg-primary text-decoration-none me-2">
                                    <i class="<?= htmlspecialchars($plugin['category_icon'] ?? 'bi-puzzle') ?> me-1"></i>
                                    <?= htmlspecialchars($plugin['category_name']) ?>
                                </a>
                                <?php endif; ?>
                                <?php if (!empty($plugin['tags'])): ?>
                                <?php foreach ($plugin['tags'] as $tag): ?>
                                <a href="/plugins?tags=<?= htmlspecialchars($tag['slug']) ?>" class="badge bg-secondary text-decoration-none me-1">
                                    <?= htmlspecialchars($tag['name']) ?>
                                </a>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <!-- Action Buttons -->
                            <div class="d-flex gap-2">
                                <button class="btn btn-primary btn-lg" disabled>
                                    <i class="bi bi-download me-2"></i>Install Plugin
                                </button>
                                <button class="btn btn-outline-secondary btn-lg" disabled>
                                    <i class="bi bi-book me-2"></i>Documentation
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Description Card -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-file-text me-2"></i>Description</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($plugin['description'])): ?>
                    <div class="plugin-description">
                        <?= nl2br(htmlspecialchars($plugin['description'])) ?>
                    </div>
                    <?php else: ?>
                    <p class="text-muted mb-0">No detailed description available.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Related Plugins -->
            <?php if (!empty($relatedPlugins)): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-collection me-2"></i>Related Plugins</h5>
                </div>
                <div class="card-body">
                    <div class="row row-cols-1 row-cols-md-3 g-3">
                        <?php foreach ($relatedPlugins as $related): ?>
                        <div class="col">
                            <div class="card h-100">
                                <div class="card-body">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="<?= htmlspecialchars($related['icon'] ?? 'bi-puzzle-fill') ?> fs-4 text-primary me-2"></i>
                                        <h6 class="card-title mb-0">
                                            <a href="/plugins/view/<?= htmlspecialchars($related['slug']) ?>" class="text-decoration-none stretched-link">
                                                <?= htmlspecialchars($related['name']) ?>
                                            </a>
                                        </h6>
                                    </div>
                                    <p class="card-text small text-muted">
                                        <?= htmlspecialchars(substr($related['short_description'] ?? '', 0, 80)) ?>...
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Stats Card -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Plugin Info</h5>
                </div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Version</span>
                        <strong><?= htmlspecialchars($plugin['version'] ?? '1.0.0') ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Downloads</span>
                        <strong><?= number_format($plugin['downloads'] ?? 0) ?></strong>
                    </li>
                    <?php if (!empty($plugin['rating']) && $plugin['rating'] > 0): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Rating</span>
                        <strong>
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="bi <?= $i <= $plugin['rating'] ? 'bi-star-fill text-warning' : 'bi-star text-muted' ?>"></i>
                            <?php endfor; ?>
                            <span class="ms-1">(<?= $plugin['rating_count'] ?? 0 ?>)</span>
                        </strong>
                    </li>
                    <?php endif; ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Added</span>
                        <strong><?= date('M j, Y', strtotime($plugin['created_at'])) ?></strong>
                    </li>
                    <?php if (!empty($plugin['updated_at'])): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Updated</span>
                        <strong><?= date('M j, Y', strtotime($plugin['updated_at'])) ?></strong>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Author Card -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-person me-2"></i>Author</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="bg-light rounded-circle p-3 me-3">
                            <i class="bi bi-person-circle fs-4 text-secondary"></i>
                        </div>
                        <div>
                            <h6 class="mb-0"><?= htmlspecialchars($plugin['author'] ?? 'Unknown') ?></h6>
                            <?php if (!empty($plugin['author_url'])): ?>
                            <a href="<?= htmlspecialchars($plugin['author_url']) ?>" target="_blank" class="small">
                                Visit website <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tags Card -->
            <?php if (!empty($plugin['tags'])): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-tags me-2"></i>Tags</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($plugin['tags'] as $tag): ?>
                        <a href="/plugins?tags=<?= htmlspecialchars($tag['slug']) ?>" class="btn btn-sm btn-outline-secondary">
                            <?= htmlspecialchars($tag['name']) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.plugin-description {
    white-space: pre-line;
}
</style>
