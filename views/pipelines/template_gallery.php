<?php
/**
 * Template Gallery - AJAX HTML fragment
 * Loaded into the Templates tab of the Pipelines page
 *
 * Variables available (set by controller before include):
 *   $tplTemplates       - array of formatted template data
 *   $tplCategories      - TEMPLATE_CATEGORIES constant
 *   $tplSelectedCategory - currently selected category filter or null
 */
?>
<!-- Category Filter -->
<div class="card mb-4">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="text-muted small me-2">Filter:</span>
            <button class="btn btn-sm <?= !$tplSelectedCategory ? 'btn-primary' : 'btn-outline-secondary' ?>"
                    onclick="filterTemplateCategory(null)">
                <i class="bi bi-grid-3x3-gap"></i> All
            </button>
            <?php foreach ($tplCategories as $key => $cat): ?>
            <button class="btn btn-sm <?= $tplSelectedCategory === $key ? 'btn-' . $cat['color'] : 'btn-outline-' . $cat['color'] ?>"
                    onclick="filterTemplateCategory('<?= $key ?>')">
                <i class="bi <?= $cat['icon'] ?>"></i> <?= $cat['label'] ?>
            </button>
            <?php endforeach; ?>

            <div class="ms-auto">
                <a href="/pipelines/templatewizard" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg"></i> Custom from Template
                </a>
            </div>
        </div>
    </div>
</div>

<?php if (empty($tplTemplates)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-collection display-4 text-muted"></i>
        <h4 class="mt-3">No templates found</h4>
        <p class="text-muted mb-4">
            <?php if ($tplSelectedCategory): ?>
            No templates in this category yet. Try a different filter.
            <?php else: ?>
            Templates are being added. Check back soon!
            <?php endif; ?>
        </p>
        <a href="/pipelines/templatewizard" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Create Custom Pipeline
        </a>
    </div>
</div>
<?php else: ?>
<div class="row g-4">
    <?php foreach ($tplTemplates as $template): ?>
    <div class="col-md-6 col-lg-4">
        <div class="card h-100 hover-shadow">
            <div class="card-body">
                <div class="d-flex align-items-start mb-3">
                    <div class="rounded bg-<?= $template['color'] ?> bg-opacity-10 p-3 me-3">
                        <i class="bi <?= $template['icon'] ?> fs-4 text-<?= $template['color'] ?>"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h5 class="card-title mb-1"><?= h($template['name']) ?></h5>
                        <?php if ($template['category_info']): ?>
                        <span class="badge bg-<?= $template['category_info']['color'] ?> bg-opacity-10 text-<?= $template['category_info']['color'] ?>">
                            <?= $template['category_info']['label'] ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <p class="card-text text-muted small mb-3">
                    <?= h($template['description']) ?>
                </p>
                <?php if (!empty($template['required_connections'])): ?>
                <div class="mb-3">
                    <small class="text-muted d-block mb-1">Requires:</small>
                    <div class="d-flex flex-wrap gap-1">
                        <?php foreach ($template['required_connections'] as $conn): ?>
                        <span class="badge bg-light text-dark">
                            <?= h(ucfirst($conn)) ?>
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
                <div class="d-grid">
                    <a href="/pipelines/templatewizard/<?= $template['id'] ?>" class="btn btn-<?= $template['color'] ?>">
                        <i class="bi bi-play-fill"></i> Use This Template
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.hover-shadow {
    transition: box-shadow 0.2s ease-in-out, transform 0.2s ease-in-out;
}
.hover-shadow:hover {
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
    transform: translateY(-2px);
}
</style>

<script>
function filterTemplateCategory(category) {
    // Reload templates tab with category filter
    const container = document.getElementById('templates-content');
    if (!container) return;

    const url = '/pipelines/templategallery' + (category ? '?category=' + encodeURIComponent(category) : '');

    container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="text-muted mt-2">Loading templates...</p></div>';

    fetch(url)
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(html => {
            container.innerHTML = html;
            // Re-initialize scripts in loaded content
            container.querySelectorAll('script').forEach(oldScript => {
                const newScript = document.createElement('script');
                if (oldScript.src) {
                    newScript.src = oldScript.src;
                } else {
                    newScript.textContent = oldScript.textContent;
                }
                oldScript.parentNode.replaceChild(newScript, oldScript);
            });
        })
        .catch(err => {
            container.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Failed to load templates: ' + err.message + '</div>';
        });
}
</script>
