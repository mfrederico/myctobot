<div class="container-fluid">
    <div class="row">
        <!-- Sidebar - Categories and Tags -->
        <div class="col-lg-3 col-md-4 mb-4">
            <!-- Back to All Plugins -->
            <a href="/plugins" class="btn btn-outline-secondary w-100 mb-3">
                <i class="bi bi-arrow-left me-2"></i>All Plugins
            </a>

            <!-- Categories Card -->
            <div class="card mb-3">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-grid-3x3-gap me-2"></i>Categories
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($categories as $cat): ?>
                    <a href="/plugins/category/<?= htmlspecialchars($cat->slug) ?>"
                       class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= ($category->slug === $cat->slug) ? 'active' : '' ?>">
                        <span>
                            <i class="<?= htmlspecialchars($cat->icon ?? 'bi-puzzle') ?> me-2"></i>
                            <?= htmlspecialchars($cat->name) ?>
                        </span>
                        <span class="badge bg-secondary rounded-pill"><?= htmlspecialchars($cat->plugin_count) ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Subcategories if available -->
            <?php if (!empty($subcategories)): ?>
            <div class="card mb-3">
                <div class="card-header bg-info text-white">
                    <i class="bi bi-diagram-3 me-2"></i>Subcategories
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($subcategories as $subcat): ?>
                    <a href="/plugins/category/<?= htmlspecialchars($subcat->slug) ?>"
                       class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span>
                            <i class="<?= htmlspecialchars($subcat->icon ?? 'bi-puzzle') ?> me-2"></i>
                            <?= htmlspecialchars($subcat->name) ?>
                        </span>
                        <span class="badge bg-secondary rounded-pill"><?= htmlspecialchars($subcat->plugin_count) ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Tags Filter Card -->
            <div class="card">
                <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-tags me-2"></i>Filter by Tags</span>
                    <?php if (!empty($selectedTags)): ?>
                    <a href="<?= htmlspecialchars(self::buildCategoryFilterUrl($category->slug, ['tags' => null])) ?>" class="btn btn-sm btn-light">Clear</a>
                    <?php endif; ?>
                </div>
                <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                    <?php if (empty($tags)): ?>
                    <p class="text-muted mb-0">No tags available in this category</p>
                    <?php else: ?>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($tags as $tag): ?>
                        <?php
                            $isSelected = in_array($tag['slug'], $selectedTags);
                            $newTags = $isSelected
                                ? array_diff($selectedTags, [$tag['slug']])
                                : array_merge($selectedTags, [$tag['slug']]);
                            $tagUrl = self::buildCategoryFilterUrl($category->slug, ['tags' => empty($newTags) ? null : implode(',', $newTags), 'page' => null]);
                        ?>
                        <a href="<?= htmlspecialchars($tagUrl) ?>"
                           class="btn btn-sm <?= $isSelected ? 'btn-primary' : 'btn-outline-secondary' ?>">
                            <?= htmlspecialchars($tag['name']) ?>
                            <span class="badge <?= $isSelected ? 'bg-light text-primary' : 'bg-secondary' ?>"><?= htmlspecialchars($tag['count']) ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Main Content - Plugin Grid -->
        <div class="col-lg-9 col-md-8">
            <!-- Category Header -->
            <div class="card bg-light mb-4">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="me-4">
                            <i class="<?= htmlspecialchars($category->icon ?? 'bi-puzzle') ?> display-4 text-primary"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h1 class="h3 mb-1"><?= htmlspecialchars($category->name) ?></h1>
                            <?php if (!empty($category->description)): ?>
                            <p class="text-muted mb-0"><?= htmlspecialchars($category->description) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="text-end">
                            <span class="display-6 text-primary"><?= number_format($totalPlugins) ?></span>
                            <br>
                            <small class="text-muted">plugin<?= $totalPlugins !== 1 ? 's' : '' ?></small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search and Sort Row -->
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <div>
                    <?php if (!empty($search)): ?>
                    <small class="text-muted">
                        Search results for "<?= htmlspecialchars($search) ?>" in <?= htmlspecialchars($category->name) ?>
                    </small>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2">
                    <!-- Search Form -->
                    <form method="GET" action="/plugins/category/<?= htmlspecialchars($category->slug) ?>" class="d-flex">
                        <?php if (!empty($selectedTags)): ?>
                        <input type="hidden" name="tags" value="<?= htmlspecialchars(implode(',', $selectedTags)) ?>">
                        <?php endif; ?>
                        <div class="input-group">
                            <input type="text" name="search" class="form-control" placeholder="Search in <?= htmlspecialchars($category->name) ?>..."
                                   value="<?= htmlspecialchars($search ?? '') ?>">
                            <button class="btn btn-outline-primary" type="submit">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </form>

                    <!-- Sort Dropdown -->
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-sort-down me-1"></i>
                            <?php
                            $sortLabels = [
                                'downloads' => 'Most Popular',
                                'rating' => 'Highest Rated',
                                'name' => 'Name (A-Z)',
                                'created_at' => 'Newest'
                            ];
                            echo $sortLabels[$sort] ?? 'Sort';
                            ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php foreach ($sortLabels as $sortKey => $sortLabel): ?>
                            <li>
                                <a class="dropdown-item <?= $sort === $sortKey ? 'active' : '' ?>"
                                   href="<?= htmlspecialchars(self::buildCategoryFilterUrl($category->slug, ['sort' => $sortKey, 'page' => null])) ?>">
                                    <?= htmlspecialchars($sortLabel) ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Active Filters Display -->
            <?php if (!empty($selectedTags) || !empty($search)): ?>
            <div class="mb-3">
                <span class="text-muted me-2">Active filters:</span>
                <?php foreach ($selectedTags as $tagSlug): ?>
                <?php
                    $newTags = array_diff($selectedTags, [$tagSlug]);
                    $removeUrl = self::buildCategoryFilterUrl($category->slug, ['tags' => empty($newTags) ? null : implode(',', $newTags)]);
                ?>
                <a href="<?= htmlspecialchars($removeUrl) ?>" class="badge bg-secondary text-decoration-none me-1">
                    <?= htmlspecialchars($tagSlug) ?> <i class="bi bi-x"></i>
                </a>
                <?php endforeach; ?>
                <?php if (!empty($search)): ?>
                <a href="<?= htmlspecialchars(self::buildCategoryFilterUrl($category->slug, ['search' => null])) ?>" class="badge bg-info text-decoration-none me-1">
                    "<?= htmlspecialchars($search) ?>" <i class="bi bi-x"></i>
                </a>
                <?php endif; ?>
                <a href="/plugins/category/<?= htmlspecialchars($category->slug) ?>" class="text-muted small ms-2">Clear filters</a>
            </div>
            <?php endif; ?>

            <!-- Plugin Grid -->
            <?php if (empty($plugins)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                No plugins found in this category matching your criteria. Try adjusting your filters or
                <a href="/plugins/category/<?= htmlspecialchars($category->slug) ?>" class="alert-link">view all plugins in <?= htmlspecialchars($category->name) ?></a>.
            </div>
            <?php else: ?>
            <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-4">
                <?php foreach ($plugins as $plugin): ?>
                <div class="col">
                    <div class="card h-100 plugin-card">
                        <div class="card-body">
                            <div class="d-flex align-items-start mb-3">
                                <div class="plugin-icon me-3">
                                    <i class="<?= htmlspecialchars($plugin['icon'] ?? 'bi-puzzle-fill') ?> fs-1 text-primary"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h5 class="card-title mb-1">
                                        <a href="/plugins/view/<?= htmlspecialchars($plugin['slug']) ?>" class="text-decoration-none text-dark stretched-link">
                                            <?= htmlspecialchars($plugin['name']) ?>
                                        </a>
                                    </h5>
                                    <small class="text-muted">
                                        v<?= htmlspecialchars($plugin['version'] ?? '1.0.0') ?>
                                        by <?= htmlspecialchars($plugin['author'] ?? 'Unknown') ?>
                                    </small>
                                </div>
                            </div>
                            <p class="card-text text-muted small">
                                <?= htmlspecialchars($plugin['short_description'] ?? '') ?>
                            </p>
                        </div>
                        <div class="card-footer bg-transparent">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <?php if ($plugin['is_featured']): ?>
                                <span class="badge bg-warning text-dark">
                                    <i class="bi bi-star-fill me-1"></i>Featured
                                </span>
                                <?php else: ?>
                                <span></span>
                                <?php endif; ?>
                                <span class="text-muted small">
                                    <i class="bi bi-download me-1"></i><?= number_format($plugin['downloads'] ?? 0) ?>
                                </span>
                            </div>
                            <?php if (!empty($plugin['tags'])): ?>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach (array_slice($plugin['tags'], 0, 3) as $tag): ?>
                                <span class="badge bg-light text-secondary"><?= htmlspecialchars($tag['name']) ?></span>
                                <?php endforeach; ?>
                                <?php if (count($plugin['tags']) > 3): ?>
                                <span class="badge bg-light text-secondary">+<?= count($plugin['tags']) - 3 ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <nav aria-label="Plugin pagination" class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(self::buildCategoryFilterUrl($category->slug, ['page' => $page - 1])) ?>">
                            <i class="bi bi-chevron-left"></i> Previous
                        </a>
                    </li>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    ?>

                    <?php if ($startPage > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="<?= htmlspecialchars(self::buildCategoryFilterUrl($category->slug, ['page' => 1])) ?>">1</a>
                    </li>
                    <?php if ($startPage > 2): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(self::buildCategoryFilterUrl($category->slug, ['page' => $i])) ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>

                    <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item">
                        <a class="page-link" href="<?= htmlspecialchars(self::buildCategoryFilterUrl($category->slug, ['page' => $totalPages])) ?>"><?= $totalPages ?></a>
                    </li>
                    <?php endif; ?>

                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(self::buildCategoryFilterUrl($category->slug, ['page' => $page + 1])) ?>">
                            Next <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.plugin-card {
    transition: transform 0.2s, box-shadow 0.2s;
}
.plugin-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.plugin-icon {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
}
</style>

<?php
/**
 * Helper function to build filter URLs for category page
 */
function self_buildCategoryFilterUrl($categorySlug, $changes) {
    global $selectedTags, $search, $sort, $page;

    $params = [];

    // Tags
    $tags = array_key_exists('tags', $changes) ? $changes['tags'] : (!empty($selectedTags) ? implode(',', $selectedTags) : null);
    if ($tags) {
        $params['tags'] = $tags;
    }

    // Search
    $searchParam = array_key_exists('search', $changes) ? $changes['search'] : $search;
    if ($searchParam) {
        $params['search'] = $searchParam;
    }

    // Sort
    $sortParam = array_key_exists('sort', $changes) ? $changes['sort'] : $sort;
    if ($sortParam && $sortParam !== 'downloads') {
        $params['sort'] = $sortParam;
    }

    // Page
    $pageParam = array_key_exists('page', $changes) ? $changes['page'] : $page;
    if ($pageParam && $pageParam > 1) {
        $params['page'] = $pageParam;
    }

    $queryString = http_build_query($params);
    return '/plugins/category/' . $categorySlug . ($queryString ? '?' . $queryString : '');
}

// Make the function available in the template scope
class self {
    public static function buildCategoryFilterUrl($categorySlug, $changes) {
        return self_buildCategoryFilterUrl($categorySlug, $changes);
    }
}
?>
