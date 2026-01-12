<?php $csrf = $csrf['csrf_token'] ?? ''; ?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2 mb-0">
            <i class="bi bi-puzzle"></i> Plugin Marketplace
        </h1>
    </div>

    <!-- Search Section -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="/plugins" id="searchForm" class="row g-3 align-items-end">
                <div class="col-md-6 col-lg-7">
                    <label for="searchInput" class="form-label">Search Plugins</label>
                    <div class="position-relative">
                        <input type="text"
                               class="form-control form-control-lg"
                               id="searchInput"
                               name="q"
                               value="<?= htmlspecialchars($query ?? '') ?>"
                               placeholder="Search by name, description, or tags..."
                               autocomplete="off">
                        <div id="autocompleteDropdown" class="autocomplete-dropdown"></div>
                    </div>
                </div>
                <div class="col-md-4 col-lg-3">
                    <label for="categorySelect" class="form-label">Category</label>
                    <select class="form-select form-select-lg" id="categorySelect" name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat['key']) ?>"
                                <?= ($category ?? '') === $cat['key'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?> (<?= $cat['count'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        <i class="bi bi-search"></i> Search
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($query)): ?>
    <!-- Active Search Indicator -->
    <div class="mb-3">
        <span class="badge bg-info fs-6">
            <i class="bi bi-search"></i> Results for: "<?= htmlspecialchars($query) ?>"
        </span>
        <?php if (!empty($category)): ?>
        <span class="badge bg-secondary fs-6 ms-1">
            Category: <?= htmlspecialchars($categories[array_search($category, array_column($categories, 'key'))]['name'] ?? $category) ?>
        </span>
        <?php endif; ?>
        <a href="/plugins" class="btn btn-sm btn-outline-secondary ms-2">
            <i class="bi bi-x-lg"></i> Clear Search
        </a>
    </div>
    <?php endif; ?>

    <?php if (empty($plugins) && !empty($noResultsSuggestions)): ?>
    <!-- No Results - Helpful Suggestions -->
    <div class="card border-warning">
        <div class="card-body text-center py-5">
            <i class="bi bi-search display-4 text-muted"></i>
            <h4 class="mt-3"><?= htmlspecialchars($noResultsSuggestions['message']) ?></h4>

            <div class="mt-4 text-start" style="max-width: 600px; margin: 0 auto;">
                <h5><i class="bi bi-lightbulb"></i> Search Tips</h5>
                <ul class="text-muted">
                    <?php foreach ($noResultsSuggestions['tips'] as $tip): ?>
                    <li><?= htmlspecialchars($tip) ?></li>
                    <?php endforeach; ?>
                </ul>

                <?php if (!empty($noResultsSuggestions['alternative_terms'])): ?>
                <h5 class="mt-4"><i class="bi bi-arrow-right-circle"></i> Try These Search Terms</h5>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($noResultsSuggestions['alternative_terms'] as $term): ?>
                    <a href="/plugins?q=<?= urlencode($term) ?>" class="btn btn-outline-primary btn-sm">
                        <?= htmlspecialchars($term) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($noResultsSuggestions['popular_categories'])): ?>
                <h5 class="mt-4"><i class="bi bi-folder"></i> Browse Popular Categories</h5>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($noResultsSuggestions['popular_categories'] as $cat): ?>
                    <a href="/plugins?category=<?= urlencode($cat['key']) ?>" class="btn btn-outline-secondary btn-sm">
                        <?= htmlspecialchars($cat['name']) ?>
                        <span class="badge bg-secondary ms-1"><?= $cat['count'] ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($noResultsSuggestions['popular_plugins'])): ?>
                <h5 class="mt-4"><i class="bi bi-star"></i> Popular Plugins</h5>
                <div class="list-group">
                    <?php foreach ($noResultsSuggestions['popular_plugins'] as $plugin): ?>
                    <a href="/plugins/view/<?= htmlspecialchars($plugin['slug']) ?>" class="list-group-item list-group-item-action">
                        <strong><?= htmlspecialchars($plugin['name']) ?></strong>
                        <span class="badge bg-info ms-2"><?= htmlspecialchars($plugin['category']) ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php elseif (empty($plugins)): ?>
    <!-- No Plugins Available -->
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-puzzle display-4 text-muted"></i>
            <h4 class="mt-3">No plugins available yet</h4>
            <p class="text-muted">Check back later for new plugins.</p>
        </div>
    </div>

    <?php else: ?>
    <!-- Plugin Grid -->
    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-4 g-4">
        <?php foreach ($plugins as $plugin): ?>
        <div class="col">
            <div class="card h-100 plugin-card">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <span class="badge bg-<?= getCategoryBadgeClass($plugin['category']) ?>">
                        <?= htmlspecialchars($plugin['category_name']) ?>
                    </span>
                    <div>
                        <?php if ($plugin['is_featured']): ?>
                        <span class="badge bg-warning text-dark" title="Featured">
                            <i class="bi bi-star-fill"></i>
                        </span>
                        <?php endif; ?>
                        <?php if ($plugin['is_verified']): ?>
                        <span class="badge bg-success" title="Verified">
                            <i class="bi bi-patch-check-fill"></i>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($plugin['icon_url']): ?>
                    <div class="text-center mb-3">
                        <img src="<?= htmlspecialchars($plugin['icon_url']) ?>"
                             alt="<?= htmlspecialchars($plugin['name']) ?>"
                             class="plugin-icon" style="max-width: 64px; max-height: 64px;">
                    </div>
                    <?php else: ?>
                    <div class="text-center mb-3">
                        <i class="bi bi-puzzle display-4 text-muted"></i>
                    </div>
                    <?php endif; ?>

                    <h5 class="card-title">
                        <a href="/plugins/view/<?= htmlspecialchars($plugin['slug']) ?>" class="text-decoration-none">
                            <?= htmlspecialchars($plugin['name']) ?>
                        </a>
                    </h5>

                    <p class="card-text text-muted small">
                        <?= htmlspecialchars($plugin['short_description'] ?: substr($plugin['description'] ?? '', 0, 100)) ?>
                        <?php if (strlen($plugin['description'] ?? '') > 100 && !$plugin['short_description']): ?>...<?php endif; ?>
                    </p>

                    <?php if (!empty($plugin['tags'])): ?>
                    <div class="mb-2">
                        <?php foreach (array_slice($plugin['tags'], 0, 3) as $tag): ?>
                        <span class="badge bg-light text-dark"><?= htmlspecialchars($tag) ?></span>
                        <?php endforeach; ?>
                        <?php if (count($plugin['tags']) > 3): ?>
                        <span class="badge bg-light text-dark">+<?= count($plugin['tags']) - 3 ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($plugin['relevance_score'] !== null && !empty($query)): ?>
                    <div class="mb-2">
                        <span class="badge bg-info" title="Search relevance score">
                            <i class="bi bi-bullseye"></i> <?= $plugin['relevance_score'] ?>% match
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-transparent">
                    <div class="d-flex justify-content-between align-items-center small text-muted">
                        <span title="Downloads">
                            <i class="bi bi-download"></i> <?= number_format($plugin['download_count']) ?>
                        </span>
                        <span title="Rating">
                            <?php if ($plugin['rating'] > 0): ?>
                            <i class="bi bi-star-fill text-warning"></i>
                            <?= number_format($plugin['rating'], 1) ?>
                            <?php else: ?>
                            <i class="bi bi-star text-muted"></i> No ratings
                            <?php endif; ?>
                        </span>
                        <span title="Version">
                            v<?= htmlspecialchars($plugin['version']) ?>
                        </span>
                    </div>
                    <div class="mt-2">
                        <a href="/plugins/view/<?= htmlspecialchars($plugin['slug']) ?>"
                           class="btn btn-sm btn-outline-primary w-100">
                            <i class="bi bi-eye"></i> View Details
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($pagination['totalPages'] > 1): ?>
    <nav class="mt-4" aria-label="Plugin pagination">
        <ul class="pagination justify-content-center">
            <li class="page-item <?= !$pagination['hasPrev'] ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= buildPaginationUrl($pagination['page'] - 1, $query, $category) ?>">
                    <i class="bi bi-chevron-left"></i> Previous
                </a>
            </li>

            <?php
            $startPage = max(1, $pagination['page'] - 2);
            $endPage = min($pagination['totalPages'], $pagination['page'] + 2);
            ?>

            <?php if ($startPage > 1): ?>
            <li class="page-item">
                <a class="page-link" href="<?= buildPaginationUrl(1, $query, $category) ?>">1</a>
            </li>
            <?php if ($startPage > 2): ?>
            <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
            <li class="page-item <?= $i === $pagination['page'] ? 'active' : '' ?>">
                <a class="page-link" href="<?= buildPaginationUrl($i, $query, $category) ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>

            <?php if ($endPage < $pagination['totalPages']): ?>
            <?php if ($endPage < $pagination['totalPages'] - 1): ?>
            <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif; ?>
            <li class="page-item">
                <a class="page-link" href="<?= buildPaginationUrl($pagination['totalPages'], $query, $category) ?>">
                    <?= $pagination['totalPages'] ?>
                </a>
            </li>
            <?php endif; ?>

            <li class="page-item <?= !$pagination['hasNext'] ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= buildPaginationUrl($pagination['page'] + 1, $query, $category) ?>">
                    Next <i class="bi bi-chevron-right"></i>
                </a>
            </li>
        </ul>
    </nav>
    <div class="text-center text-muted small">
        Showing <?= (($pagination['page'] - 1) * $pagination['limit']) + 1 ?>
        - <?= min($pagination['page'] * $pagination['limit'], $pagination['total']) ?>
        of <?= number_format($pagination['total']) ?> plugins
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php
// Helper function for category badge colors
function getCategoryBadgeClass($category) {
    $classes = [
        'general' => 'secondary',
        'automation' => 'primary',
        'integration' => 'info',
        'analytics' => 'success',
        'security' => 'danger',
        'development' => 'dark',
        'productivity' => 'warning',
        'communication' => 'purple'
    ];
    return $classes[$category] ?? 'secondary';
}

// Helper function for pagination URLs
function buildPaginationUrl($page, $query, $category) {
    $params = ['page' => $page];
    if (!empty($query)) $params['q'] = $query;
    if (!empty($category)) $params['category'] = $category;
    return '/plugins?' . http_build_query($params);
}
?>

<style>
.autocomplete-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 1000;
    background: white;
    border: 1px solid #dee2e6;
    border-top: none;
    border-radius: 0 0 0.375rem 0.375rem;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
    display: none;
    max-height: 300px;
    overflow-y: auto;
}

.autocomplete-dropdown.show {
    display: block;
}

.autocomplete-item {
    padding: 0.75rem 1rem;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
}

.autocomplete-item:last-child {
    border-bottom: none;
}

.autocomplete-item:hover,
.autocomplete-item.active {
    background-color: #f8f9fa;
}

.autocomplete-item .plugin-name {
    font-weight: 600;
}

.autocomplete-item .plugin-category {
    font-size: 0.8rem;
    color: #6c757d;
}

.autocomplete-item .plugin-desc {
    font-size: 0.85rem;
    color: #6c757d;
    margin-top: 0.25rem;
}

.plugin-card {
    transition: transform 0.2s, box-shadow 0.2s;
}

.plugin-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

.bg-purple {
    background-color: #6f42c1 !important;
    color: white;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const dropdown = document.getElementById('autocompleteDropdown');
    const categorySelect = document.getElementById('categorySelect');
    let debounceTimer = null;
    let activeIndex = -1;
    let suggestions = [];

    // Debounced autocomplete search
    searchInput.addEventListener('input', function() {
        const query = this.value.trim();

        clearTimeout(debounceTimer);

        if (query.length < 2) {
            hideDropdown();
            return;
        }

        debounceTimer = setTimeout(function() {
            fetchAutocomplete(query);
        }, 300);
    });

    // Keyboard navigation
    searchInput.addEventListener('keydown', function(e) {
        if (!dropdown.classList.contains('show')) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(activeIndex + 1, suggestions.length - 1);
            updateActiveItem();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = Math.max(activeIndex - 1, -1);
            updateActiveItem();
        } else if (e.key === 'Enter' && activeIndex >= 0) {
            e.preventDefault();
            selectSuggestion(suggestions[activeIndex]);
        } else if (e.key === 'Escape') {
            hideDropdown();
        }
    });

    // Click outside to close
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
            hideDropdown();
        }
    });

    // Category change auto-submit
    categorySelect.addEventListener('change', function() {
        document.getElementById('searchForm').submit();
    });

    function fetchAutocomplete(query) {
        fetch('/plugins/autocomplete?q=' + encodeURIComponent(query), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data && data.data.suggestions) {
                suggestions = data.data.suggestions;
                renderDropdown(suggestions);
            }
        })
        .catch(error => {
            console.error('Autocomplete error:', error);
        });
    }

    function renderDropdown(items) {
        if (items.length === 0) {
            hideDropdown();
            return;
        }

        dropdown.innerHTML = items.map((item, index) => `
            <div class="autocomplete-item" data-index="${index}">
                <div class="plugin-name">${escapeHtml(item.name)}</div>
                <span class="plugin-category badge bg-secondary">${escapeHtml(item.category)}</span>
                ${item.short_description ? `<div class="plugin-desc">${escapeHtml(item.short_description)}</div>` : ''}
            </div>
        `).join('');

        // Add click handlers
        dropdown.querySelectorAll('.autocomplete-item').forEach((item, index) => {
            item.addEventListener('click', function() {
                selectSuggestion(suggestions[index]);
            });
        });

        activeIndex = -1;
        dropdown.classList.add('show');
    }

    function hideDropdown() {
        dropdown.classList.remove('show');
        activeIndex = -1;
    }

    function updateActiveItem() {
        dropdown.querySelectorAll('.autocomplete-item').forEach((item, index) => {
            item.classList.toggle('active', index === activeIndex);
        });
    }

    function selectSuggestion(suggestion) {
        // Navigate directly to the plugin
        window.location.href = '/plugins/view/' + suggestion.slug;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});
</script>
