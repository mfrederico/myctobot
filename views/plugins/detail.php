<?php
/**
 * Plugin Detail View
 * Displays comprehensive plugin information with tabs for different sections.
 */
$csrf = $csrf['csrf_token'] ?? '';
$p = $plugin;
?>
<div class="container py-4">
    <!-- Breadcrumb and Header -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/plugins">Plugin Registry</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($p['display_name']) ?></li>
        </ol>
    </nav>

    <!-- Plugin Header -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row">
                <div class="col-md-8">
                    <div class="d-flex align-items-start">
                        <div class="plugin-icon me-3">
                            <div class="bg-primary text-white rounded d-flex align-items-center justify-content-center" style="width: 64px; height: 64px; font-size: 1.5rem;">
                                <i class="bi bi-puzzle"></i>
                            </div>
                        </div>
                        <div>
                            <h1 class="h2 mb-1"><?= htmlspecialchars($p['display_name']) ?></h1>
                            <p class="text-muted mb-2">
                                <code><?= htmlspecialchars($p['name']) ?></code>
                                <span class="badge bg-primary ms-2">v<?= htmlspecialchars($p['version']) ?></span>
                            </p>
                            <p class="mb-2">
                                <i class="bi bi-person text-muted"></i>
                                <span class="text-muted">by</span>
                                <strong><?= htmlspecialchars($p['author']) ?></strong>
                            </p>
                            <div>
                                <?php foreach ($p['tags'] as $tag): ?>
                                <a href="/plugins?tag=<?= urlencode($tag) ?>" class="badge bg-secondary text-decoration-none me-1">
                                    <?= htmlspecialchars($tag) ?>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                    <button type="button" class="btn btn-success btn-lg mb-2"
                            onclick="installPlugin(<?= $p['id'] ?>, '<?= htmlspecialchars($p['display_name'], ENT_QUOTES) ?>')">
                        <i class="bi bi-download"></i> Install Plugin
                    </button>
                    <div class="d-flex justify-content-md-end gap-4 text-muted">
                        <span title="Total Installations">
                            <i class="bi bi-download"></i> <?= number_format($p['install_count']) ?>
                        </span>
                        <span title="Average Rating">
                            <i class="bi bi-star-fill text-warning"></i> <?= number_format($p['rating'], 1) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Main Content -->
        <div class="col-lg-8">
            <!-- Tabs Navigation -->
            <ul class="nav nav-tabs mb-4" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $activeTab === 'overview' ? 'active' : '' ?>"
                            id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview"
                            type="button" role="tab" aria-controls="overview" aria-selected="<?= $activeTab === 'overview' ? 'true' : 'false' ?>">
                        <i class="bi bi-info-circle"></i> Overview
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $activeTab === 'documentation' ? 'active' : '' ?>"
                            id="documentation-tab" data-bs-toggle="tab" data-bs-target="#documentation"
                            type="button" role="tab" aria-controls="documentation" aria-selected="<?= $activeTab === 'documentation' ? 'true' : 'false' ?>">
                        <i class="bi bi-book"></i> Documentation
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $activeTab === 'dependencies' ? 'active' : '' ?>"
                            id="dependencies-tab" data-bs-toggle="tab" data-bs-target="#dependencies"
                            type="button" role="tab" aria-controls="dependencies" aria-selected="<?= $activeTab === 'dependencies' ? 'true' : 'false' ?>">
                        <i class="bi bi-box-seam"></i> Dependencies
                        <span class="badge bg-secondary"><?= count($p['dependencies']) ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $activeTab === 'changelog' ? 'active' : '' ?>"
                            id="changelog-tab" data-bs-toggle="tab" data-bs-target="#changelog"
                            type="button" role="tab" aria-controls="changelog" aria-selected="<?= $activeTab === 'changelog' ? 'true' : 'false' ?>">
                        <i class="bi bi-clock-history"></i> Changelog
                    </button>
                </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content">
                <!-- Overview Tab -->
                <div class="tab-pane fade <?= $activeTab === 'overview' ? 'show active' : '' ?>"
                     id="overview" role="tabpanel" aria-labelledby="overview-tab">
                    <div class="card">
                        <div class="card-header">
                            <i class="bi bi-info-circle"></i> Description
                        </div>
                        <div class="card-body">
                            <p class="lead"><?= htmlspecialchars($p['description']) ?></p>
                        </div>
                    </div>

                    <?php if (!empty($p['screenshots'])): ?>
                    <div class="card mt-4">
                        <div class="card-header">
                            <i class="bi bi-images"></i> Screenshots
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php foreach ($p['screenshots'] as $screenshot): ?>
                                <div class="col-md-6 mb-3">
                                    <img src="<?= htmlspecialchars($screenshot) ?>"
                                         alt="Plugin screenshot"
                                         class="img-fluid rounded border"
                                         onerror="this.parentElement.style.display='none'">
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Documentation Tab -->
                <div class="tab-pane fade <?= $activeTab === 'documentation' ? 'show active' : '' ?>"
                     id="documentation" role="tabpanel" aria-labelledby="documentation-tab">
                    <div class="card">
                        <div class="card-header">
                            <i class="bi bi-book"></i> Installation & Usage Guide
                        </div>
                        <div class="card-body documentation-content">
                            <?= $p['documentation_html'] ?>
                        </div>
                    </div>
                </div>

                <!-- Dependencies Tab -->
                <div class="tab-pane fade <?= $activeTab === 'dependencies' ? 'show active' : '' ?>"
                     id="dependencies" role="tabpanel" aria-labelledby="dependencies-tab">
                    <div class="card">
                        <div class="card-header">
                            <i class="bi bi-box-seam"></i> Required Dependencies
                        </div>
                        <div class="card-body">
                            <?php if (empty($p['dependencies'])): ?>
                            <p class="text-muted mb-0">
                                <i class="bi bi-check-circle text-success"></i>
                                This plugin has no external dependencies.
                            </p>
                            <?php else: ?>
                            <p class="text-muted mb-3">
                                This plugin requires the following packages and extensions to be installed:
                            </p>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 50%">Package / Extension</th>
                                            <th style="width: 30%">Version Constraint</th>
                                            <th style="width: 20%">Type</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($p['dependencies'] as $dep): ?>
                                        <?php
                                            $isExtension = strpos($dep['name'], 'ext-') === 0;
                                            $isPHP = $dep['name'] === 'php';
                                            $depType = $isPHP ? 'Runtime' : ($isExtension ? 'PHP Extension' : 'Composer Package');
                                            $depIcon = $isPHP ? 'bi-gear' : ($isExtension ? 'bi-plug' : 'bi-box');
                                            $depBadge = $isPHP ? 'bg-warning text-dark' : ($isExtension ? 'bg-info' : 'bg-secondary');
                                        ?>
                                        <tr>
                                            <td>
                                                <i class="bi <?= $depIcon ?> text-muted me-2"></i>
                                                <code><?= htmlspecialchars($dep['name']) ?></code>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark font-monospace">
                                                    <?= htmlspecialchars($dep['version']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?= $depBadge ?>">
                                                    <?= $depType ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="alert alert-light mt-3 mb-0">
                                <i class="bi bi-lightbulb text-warning"></i>
                                <strong>Tip:</strong> Most Composer packages will be installed automatically.
                                PHP extensions may need to be enabled in your <code>php.ini</code> file.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Changelog Tab -->
                <div class="tab-pane fade <?= $activeTab === 'changelog' ? 'show active' : '' ?>"
                     id="changelog" role="tabpanel" aria-labelledby="changelog-tab">
                    <div class="card">
                        <div class="card-header">
                            <i class="bi bi-clock-history"></i> Version History
                        </div>
                        <div class="card-body changelog-content">
                            <?php if (!empty($p['changelog_html'])): ?>
                                <?= $p['changelog_html'] ?>
                            <?php else: ?>
                                <p class="text-muted mb-0">No changelog available.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Metadata Card -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-file-text"></i> Plugin Information
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted" style="width: 40%">Version</td>
                            <td><span class="badge bg-primary"><?= htmlspecialchars($p['version']) ?></span></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Author</td>
                            <td><?= htmlspecialchars($p['author']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">License</td>
                            <td>
                                <span class="badge bg-light text-dark">
                                    <?= htmlspecialchars($p['license'] ?? 'Not specified') ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Last Updated</td>
                            <td>
                                <i class="bi bi-calendar text-muted"></i>
                                <?= date('M j, Y', strtotime($p['last_updated'])) ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Installations</td>
                            <td>
                                <i class="bi bi-download text-muted"></i>
                                <?= number_format($p['install_count']) ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Rating</td>
                            <td>
                                <?php
                                $rating = $p['rating'];
                                $fullStars = floor($rating);
                                $halfStar = ($rating - $fullStars) >= 0.5;
                                ?>
                                <?php for ($i = 0; $i < $fullStars; $i++): ?>
                                <i class="bi bi-star-fill text-warning"></i>
                                <?php endfor; ?>
                                <?php if ($halfStar): ?>
                                <i class="bi bi-star-half text-warning"></i>
                                <?php endif; ?>
                                <?php for ($i = $fullStars + ($halfStar ? 1 : 0); $i < 5; $i++): ?>
                                <i class="bi bi-star text-warning"></i>
                                <?php endfor; ?>
                                <span class="text-muted ms-1">(<?= number_format($rating, 1) ?>)</span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Links Card -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-link-45deg"></i> Links
                </div>
                <div class="card-body">
                    <?php if (!empty($p['homepage'])): ?>
                    <a href="<?= htmlspecialchars($p['homepage']) ?>" target="_blank" rel="noopener"
                       class="btn btn-outline-secondary btn-sm w-100 mb-2">
                        <i class="bi bi-github"></i> View on GitHub
                    </a>
                    <?php endif; ?>
                    <a href="/plugins" class="btn btn-outline-primary btn-sm w-100">
                        <i class="bi bi-arrow-left"></i> Back to Registry
                    </a>
                </div>
            </div>

            <!-- Tags Card -->
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-tags"></i> Tags
                </div>
                <div class="card-body">
                    <?php foreach ($p['tags'] as $tag): ?>
                    <a href="/plugins?tag=<?= urlencode($tag) ?>"
                       class="badge bg-secondary text-decoration-none me-1 mb-1">
                        <?= htmlspecialchars($tag) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Documentation Content Styling */
.documentation-content,
.changelog-content {
    line-height: 1.7;
}

.documentation-content h1,
.documentation-content h2,
.documentation-content h3,
.changelog-content h1,
.changelog-content h2,
.changelog-content h3 {
    margin-top: 1.5rem;
    margin-bottom: 0.75rem;
    font-weight: 600;
}

.documentation-content h1:first-child,
.documentation-content h2:first-child,
.documentation-content h3:first-child,
.changelog-content h1:first-child,
.changelog-content h2:first-child,
.changelog-content h3:first-child {
    margin-top: 0;
}

.documentation-content code,
.changelog-content code {
    background-color: #f8f9fa;
    padding: 0.125rem 0.375rem;
    border-radius: 0.25rem;
    font-size: 0.875em;
}

.documentation-content pre,
.changelog-content pre {
    background-color: #f8f9fa;
    padding: 1rem;
    border-radius: 0.375rem;
    overflow-x: auto;
}

.documentation-content ul,
.changelog-content ul {
    padding-left: 1.25rem;
}

.documentation-content li,
.changelog-content li {
    margin-bottom: 0.5rem;
}

.documentation-content table {
    width: 100%;
    margin-bottom: 1rem;
    border-collapse: collapse;
}

.documentation-content table th,
.documentation-content table td {
    padding: 0.5rem;
    border: 1px solid #dee2e6;
}

.documentation-content table th {
    background-color: #f8f9fa;
}

/* Plugin icon placeholder styling */
.plugin-icon .bg-primary {
    font-size: 2rem;
}

/* Star rating display */
.bi-star-fill,
.bi-star-half,
.bi-star {
    font-size: 0.875rem;
}
</style>

<script>
function installPlugin(id, name) {
    // Placeholder for install functionality
    // NOTE: Future upgrade consideration - Implement actual plugin installation
    if (confirm('Install "' + name + '"?\n\nThis will add the plugin to your MyCTOBot instance.')) {
        alert('Plugin installation is not yet implemented.\n\nThis feature will be available in a future update.');
    }
}

// Handle tab state in URL for bookmarking
document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function(tab) {
    tab.addEventListener('shown.bs.tab', function(e) {
        var tabId = e.target.getAttribute('aria-controls');
        var url = new URL(window.location);
        url.searchParams.set('tab', tabId);
        window.history.replaceState({}, '', url);
    });
});
</script>
