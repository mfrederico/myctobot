<div class="container py-4">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/plugins"><i class="bi bi-puzzle"></i> Plugins</a></li>
            <li class="breadcrumb-item">
                <a href="/plugins?category=<?= urlencode($plugin['category']) ?>">
                    <?= htmlspecialchars($plugin['category_name']) ?>
                </a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">
                <?= htmlspecialchars($plugin['name']) ?>
            </li>
        </ol>
    </nav>

    <div class="row">
        <!-- Main Content -->
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-body">
                    <!-- Plugin Header -->
                    <div class="d-flex align-items-start mb-4">
                        <?php if ($plugin['icon_url']): ?>
                        <img src="<?= htmlspecialchars($plugin['icon_url']) ?>"
                             alt="<?= htmlspecialchars($plugin['name']) ?>"
                             class="me-4" style="width: 80px; height: 80px; object-fit: contain;">
                        <?php else: ?>
                        <div class="me-4 d-flex align-items-center justify-content-center bg-light rounded"
                             style="width: 80px; height: 80px;">
                            <i class="bi bi-puzzle display-4 text-muted"></i>
                        </div>
                        <?php endif; ?>

                        <div class="flex-grow-1">
                            <h1 class="h2 mb-2">
                                <?= htmlspecialchars($plugin['name']) ?>
                                <?php if ($plugin['is_verified']): ?>
                                <span class="badge bg-success ms-2" title="Verified Plugin">
                                    <i class="bi bi-patch-check-fill"></i> Verified
                                </span>
                                <?php endif; ?>
                                <?php if ($plugin['is_featured']): ?>
                                <span class="badge bg-warning text-dark ms-2" title="Featured Plugin">
                                    <i class="bi bi-star-fill"></i> Featured
                                </span>
                                <?php endif; ?>
                            </h1>

                            <p class="lead text-muted mb-2">
                                <?= htmlspecialchars($plugin['short_description'] ?: substr($plugin['description'], 0, 150)) ?>
                            </p>

                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <span class="badge bg-<?= getCategoryBadgeClass($plugin['category']) ?> fs-6">
                                    <?= htmlspecialchars($plugin['category_name']) ?>
                                </span>
                                <span class="badge bg-secondary fs-6">
                                    v<?= htmlspecialchars($plugin['version']) ?>
                                </span>
                            </div>

                            <?php if ($plugin['author']): ?>
                            <div class="text-muted small">
                                By
                                <?php if ($plugin['author_url']): ?>
                                <a href="<?= htmlspecialchars($plugin['author_url']) ?>" target="_blank" rel="noopener">
                                    <?= htmlspecialchars($plugin['author']) ?>
                                </a>
                                <?php else: ?>
                                <?= htmlspecialchars($plugin['author']) ?>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Stats Row -->
                    <div class="row text-center border-top border-bottom py-3 mb-4">
                        <div class="col">
                            <div class="h4 mb-0"><?= number_format($plugin['download_count']) ?></div>
                            <div class="text-muted small">Downloads</div>
                        </div>
                        <div class="col">
                            <div class="h4 mb-0">
                                <?php if ($plugin['rating'] > 0): ?>
                                <i class="bi bi-star-fill text-warning"></i>
                                <?= number_format($plugin['rating'], 1) ?>
                                <?php else: ?>
                                <i class="bi bi-star text-muted"></i> --
                                <?php endif; ?>
                            </div>
                            <div class="text-muted small">
                                <?php if ($plugin['rating_count'] > 0): ?>
                                <?= number_format($plugin['rating_count']) ?> rating<?= $plugin['rating_count'] !== 1 ? 's' : '' ?>
                                <?php else: ?>
                                No ratings yet
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col">
                            <div class="h4 mb-0">v<?= htmlspecialchars($plugin['version']) ?></div>
                            <div class="text-muted small">Version</div>
                        </div>
                    </div>

                    <!-- Description -->
                    <h3 class="h5">Description</h3>
                    <div class="plugin-description mb-4">
                        <?php if ($plugin['description']): ?>
                        <?= nl2br(htmlspecialchars($plugin['description'])) ?>
                        <?php else: ?>
                        <p class="text-muted">No description available.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Tags -->
                    <?php if (!empty($plugin['tags'])): ?>
                    <h3 class="h5">Tags</h3>
                    <div class="mb-4">
                        <?php foreach ($plugin['tags'] as $tag): ?>
                        <a href="/plugins?q=<?= urlencode($tag) ?>" class="badge bg-light text-dark text-decoration-none me-1 mb-1">
                            <i class="bi bi-tag"></i> <?= htmlspecialchars($tag) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Related Plugins -->
            <?php if (!empty($relatedPlugins)): ?>
            <div class="card">
                <div class="card-header">
                    <h3 class="h5 mb-0"><i class="bi bi-collection"></i> Related Plugins</h3>
                </div>
                <div class="card-body">
                    <div class="row row-cols-1 row-cols-md-2 g-3">
                        <?php foreach ($relatedPlugins as $related): ?>
                        <div class="col">
                            <div class="d-flex align-items-center p-2 border rounded hover-bg-light">
                                <?php if ($related['icon_url']): ?>
                                <img src="<?= htmlspecialchars($related['icon_url']) ?>"
                                     alt="" class="me-3" style="width: 40px; height: 40px; object-fit: contain;">
                                <?php else: ?>
                                <div class="me-3 d-flex align-items-center justify-content-center bg-light rounded"
                                     style="width: 40px; height: 40px;">
                                    <i class="bi bi-puzzle text-muted"></i>
                                </div>
                                <?php endif; ?>
                                <div class="flex-grow-1 min-width-0">
                                    <a href="/plugins/view/<?= htmlspecialchars($related['slug']) ?>"
                                       class="text-decoration-none fw-bold d-block text-truncate">
                                        <?= htmlspecialchars($related['name']) ?>
                                    </a>
                                    <small class="text-muted d-block text-truncate">
                                        <?= htmlspecialchars($related['short_description'] ?: 'No description') ?>
                                    </small>
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
            <!-- Action Card -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <?php if ($plugin['homepage_url']): ?>
                        <a href="<?= htmlspecialchars($plugin['homepage_url']) ?>"
                           class="btn btn-primary btn-lg" target="_blank" rel="noopener">
                            <i class="bi bi-box-arrow-up-right"></i> Visit Homepage
                        </a>
                        <?php endif; ?>

                        <?php if ($plugin['documentation_url']): ?>
                        <a href="<?= htmlspecialchars($plugin['documentation_url']) ?>"
                           class="btn btn-outline-primary" target="_blank" rel="noopener">
                            <i class="bi bi-book"></i> Documentation
                        </a>
                        <?php endif; ?>

                        <a href="/plugins?category=<?= urlencode($plugin['category']) ?>"
                           class="btn btn-outline-secondary">
                            <i class="bi bi-folder"></i> Browse <?= htmlspecialchars($plugin['category_name']) ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Info Card -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="h6 mb-0"><i class="bi bi-info-circle"></i> Plugin Information</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted ps-3">Version</td>
                            <td class="pe-3"><?= htmlspecialchars($plugin['version']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-3">Category</td>
                            <td class="pe-3"><?= htmlspecialchars($plugin['category_name']) ?></td>
                        </tr>
                        <?php if ($plugin['author']): ?>
                        <tr>
                            <td class="text-muted ps-3">Author</td>
                            <td class="pe-3">
                                <?php if ($plugin['author_url']): ?>
                                <a href="<?= htmlspecialchars($plugin['author_url']) ?>" target="_blank" rel="noopener">
                                    <?= htmlspecialchars($plugin['author']) ?>
                                </a>
                                <?php else: ?>
                                <?= htmlspecialchars($plugin['author']) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="text-muted ps-3">Downloads</td>
                            <td class="pe-3"><?= number_format($plugin['download_count']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-3">Rating</td>
                            <td class="pe-3">
                                <?php if ($plugin['rating'] > 0): ?>
                                <?= number_format($plugin['rating'], 1) ?>/5
                                (<?= number_format($plugin['rating_count']) ?>)
                                <?php else: ?>
                                Not rated
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted ps-3">Added</td>
                            <td class="pe-3">
                                <?= date('M j, Y', strtotime($plugin['created_at'])) ?>
                            </td>
                        </tr>
                        <?php if ($plugin['updated_at']): ?>
                        <tr>
                            <td class="text-muted ps-3">Updated</td>
                            <td class="pe-3">
                                <?= date('M j, Y', strtotime($plugin['updated_at'])) ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <!-- Search Widget -->
            <div class="card">
                <div class="card-header">
                    <h3 class="h6 mb-0"><i class="bi bi-search"></i> Search Plugins</h3>
                </div>
                <div class="card-body">
                    <form method="GET" action="/plugins">
                        <div class="input-group">
                            <input type="text" class="form-control" name="q"
                                   placeholder="Search plugins...">
                            <button class="btn btn-primary" type="submit">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </form>

                    <div class="mt-3">
                        <small class="text-muted">Browse by category:</small>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            <?php foreach ($categories as $key => $name): ?>
                            <a href="/plugins?category=<?= urlencode($key) ?>"
                               class="badge bg-<?= getCategoryBadgeClass($key) ?> text-decoration-none">
                                <?= htmlspecialchars($name) ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../lib/ViewHelpers.php'; ?>

<style>
.plugin-description {
    white-space: pre-wrap;
    word-wrap: break-word;
}

.hover-bg-light:hover {
    background-color: #f8f9fa;
}

.min-width-0 {
    min-width: 0;
}

.bg-purple {
    background-color: #6f42c1 !important;
    color: white;
}
</style>
