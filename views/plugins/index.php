<?php
/**
 * Plugin Registry - Index View
 * Displays a card-based list of available plugins with filtering and search.
 */
$csrf = $csrf['csrf_token'] ?? '';
?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2 mb-0">
            <i class="bi bi-puzzle"></i> Plugin Registry
        </h1>
        <a href="/settings" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Settings
        </a>
    </div>

    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i>
        <strong>Plugin Registry</strong> - Browse and install plugins to extend MyCTOBot functionality.
        Click on a plugin card to view detailed information, documentation, and installation instructions.
    </div>

    <!-- Search and Filter Bar -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="/plugins" class="row g-3">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" name="q"
                               placeholder="Search plugins..."
                               value="<?= htmlspecialchars($searchQuery ?? '') ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <select class="form-select" name="tag" onchange="this.form.submit()">
                        <option value="">All Categories</option>
                        <?php foreach ($allTags as $tag => $count): ?>
                        <option value="<?= htmlspecialchars($tag) ?>" <?= $currentTag === $tag ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucfirst($tag)) ?> (<?= $count ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-filter"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if (empty($plugins)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-puzzle display-4 text-muted"></i>
            <h4 class="mt-3">No plugins found</h4>
            <p class="text-muted mb-4">
                <?php if ($searchQuery || $currentTag): ?>
                Try adjusting your search or filter criteria.
                <?php else: ?>
                The plugin registry is currently empty.
                <?php endif; ?>
            </p>
            <?php if ($searchQuery || $currentTag): ?>
            <a href="/plugins" class="btn btn-outline-primary">
                <i class="bi bi-x-circle"></i> Clear Filters
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>

    <!-- Results Count -->
    <p class="text-muted mb-3">
        Showing <?= count($plugins) ?> plugin<?= count($plugins) !== 1 ? 's' : '' ?>
        <?php if ($currentTag): ?>
            in <span class="badge bg-secondary"><?= htmlspecialchars($currentTag) ?></span>
        <?php endif; ?>
        <?php if ($searchQuery): ?>
            matching "<strong><?= htmlspecialchars($searchQuery) ?></strong>"
        <?php endif; ?>
    </p>

    <!-- Plugin Cards Grid -->
    <div class="row">
        <?php foreach ($plugins as $plugin): ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 plugin-card">
                <div class="card-header d-flex justify-content-between align-items-start">
                    <div>
                        <h5 class="card-title mb-1">
                            <a href="/plugins/detail/<?= $plugin['id'] ?>" class="text-decoration-none">
                                <?= htmlspecialchars($plugin['display_name']) ?>
                            </a>
                        </h5>
                        <small class="text-muted">
                            <i class="bi bi-person"></i> <?= htmlspecialchars($plugin['author']) ?>
                        </small>
                    </div>
                    <span class="badge bg-primary">v<?= htmlspecialchars($plugin['version']) ?></span>
                </div>
                <div class="card-body">
                    <p class="card-text text-muted small">
                        <?= htmlspecialchars(substr($plugin['description'], 0, 150)) ?><?= strlen($plugin['description']) > 150 ? '...' : '' ?>
                    </p>

                    <!-- Tags -->
                    <div class="mb-3">
                        <?php foreach (array_slice($plugin['tags'], 0, 3) as $tag): ?>
                        <a href="/plugins?tag=<?= urlencode($tag) ?>" class="badge bg-light text-dark text-decoration-none me-1">
                            <?= htmlspecialchars($tag) ?>
                        </a>
                        <?php endforeach; ?>
                        <?php if (count($plugin['tags']) > 3): ?>
                        <span class="badge bg-light text-muted">+<?= count($plugin['tags']) - 3 ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Stats -->
                    <div class="d-flex justify-content-between text-muted small">
                        <span title="Installations">
                            <i class="bi bi-download"></i> <?= number_format($plugin['install_count']) ?>
                        </span>
                        <span title="Rating">
                            <i class="bi bi-star-fill text-warning"></i> <?= number_format($plugin['rating'], 1) ?>
                        </span>
                        <span title="Last Updated">
                            <i class="bi bi-calendar"></i> <?= date('M j, Y', strtotime($plugin['last_updated'])) ?>
                        </span>
                    </div>
                </div>
                <div class="card-footer bg-transparent">
                    <div class="d-flex justify-content-between align-items-center">
                        <a href="/plugins/detail/<?= $plugin['id'] ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-info-circle"></i> Details
                        </a>
                        <button type="button" class="btn btn-sm btn-success"
                                onclick="installPlugin(<?= $plugin['id'] ?>, '<?= htmlspecialchars($plugin['display_name'], ENT_QUOTES) ?>')"
                                title="Install plugin">
                            <i class="bi bi-download"></i> Install
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<style>
.plugin-card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.plugin-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}
.plugin-card .card-title a:hover {
    color: #0d6efd !important;
}
.plugin-card .badge.bg-light:hover {
    background-color: #e9ecef !important;
}
</style>

<script>
function installPlugin(id, name) {
    // Placeholder for install functionality
    // NOTE: Future upgrade consideration - Implement actual plugin installation
    alert('Plugin installation for "' + name + '" is not yet implemented.\n\nThis will be available in a future update.');
}
</script>
