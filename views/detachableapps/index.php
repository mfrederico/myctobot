<?php
$screenCounts = [];
$pipelineCounts = [];
foreach ($apps as $a) {
    $screenCounts[$a['id']] = (int) ($a['screen_count'] ?? 0);
    $pipelineCounts[$a['id']] = (int) ($a['pipeline_count'] ?? 0);
}
?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi bi-box-arrow-up-right text-primary"></i> Detachable Apps
            </h1>
            <p class="text-muted mb-0">Build standalone applications from templates, Stitch screens, or pipeline APIs</p>
        </div>
        <div class="btn-group">
            <a href="/detachableapps/templates" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> New App
            </a>
            <button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="visually-hidden">Toggle Dropdown</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="/detachableapps/templates"><i class="bi bi-grid-3x3-gap me-2"></i>From Template</a></li>
                <li><a class="dropdown-item" href="/detachableapps/form"><i class="bi bi-file-earmark-plus me-2"></i>Blank App (Stitch)</a></li>
            </ul>
        </div>
    </div>

    <?php if (empty($apps)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-box-arrow-up-right display-4 text-muted"></i>
            <h4 class="mt-3">No detachable apps yet</h4>
            <p class="text-muted mb-4" style="max-width: 500px; margin: 0 auto;">
                Create a detachable app from a template or build one from scratch with Stitch screens and pipeline APIs.
            </p>
            <a href="/detachableapps/templates" class="btn btn-primary btn-lg">
                <i class="bi bi-plus-lg"></i> Create Your First App
            </a>
        </div>
    </div>
    <?php else: ?>
    <div class="row" id="appGrid">
        <?php foreach ($apps as $app): ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>
                        <i class="bi bi-box-arrow-up-right text-primary"></i>
                        <strong><?= h($app['name']) ?></strong>
                    </span>
                    <span class="d-flex align-items-center gap-1">
                        <?php if (!empty($app['is_hosted'])): ?>
                        <span class="badge bg-success" title="Hosted mode enabled">
                            <i class="bi bi-globe2"></i> Hosted
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($app['template_slug'])): ?>
                        <span class="badge bg-info" title="Created from template">
                            <i class="bi bi-grid-3x3-gap"></i>
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($app['git_repo_url'])): ?>
                        <span class="badge bg-dark" title="Git repository connected">
                            <i class="bi bi-git"></i> Git
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($app['current_version'])): ?>
                        <span class="badge bg-primary"><?= h($app['current_version']) ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        <?= h($app['description'] ?: 'No description') ?>
                    </p>

                    <?php
                    $buildStatus = $app['build_status'] ?? 'idle';
                    $statusClass = match ($buildStatus) {
                        'building' => 'bg-warning text-dark',
                        'failed'   => 'bg-danger',
                        'complete', 'success' => 'bg-success',
                        default    => 'bg-secondary',
                    };
                    $statusIcon = match ($buildStatus) {
                        'building' => 'bi-arrow-repeat',
                        'failed'   => 'bi-x-circle',
                        'complete', 'success' => 'bi-check-circle',
                        default    => 'bi-circle',
                    };
                    ?>
                    <div class="mb-3">
                        <span class="badge <?= $statusClass ?>">
                            <i class="bi <?= $statusIcon ?>"></i>
                            <?= h(ucfirst($buildStatus)) ?>
                        </span>
                    </div>

                    <div class="row text-center border-top pt-2">
                        <div class="col-6">
                            <div class="small text-muted">Screens</div>
                            <div class="fw-bold"><?= $screenCounts[$app['id']] ?? 0 ?></div>
                        </div>
                        <div class="col-6">
                            <div class="small text-muted">Pipelines</div>
                            <div class="fw-bold"><?= $pipelineCounts[$app['id']] ?? 0 ?></div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent">
                    <div class="d-flex flex-wrap gap-1">
                        <?php if (!empty($app['is_hosted'])): ?>
                        <a href="/dapp/<?= h($app['slug']) ?>" class="btn btn-success btn-sm" title="View hosted app" target="_blank">
                            <i class="bi bi-box-arrow-up-right"></i> View
                        </a>
                        <?php endif; ?>
                        <a href="/detachableapps/form/<?= (int) $app['id'] ?>" class="btn btn-outline-primary btn-sm" title="Edit settings">
                            <i class="bi bi-pencil"></i> Edit
                        </a>
                        <a href="/detachableapps/screens/<?= (int) $app['id'] ?>" class="btn btn-outline-secondary btn-sm" title="Manage screens">
                            <i class="bi bi-window-stack"></i> Screens
                        </a>
                        <a href="/detachableapps/pipelines/<?= (int) $app['id'] ?>" class="btn btn-outline-secondary btn-sm" title="Bind pipelines">
                            <i class="bi bi-diagram-3"></i> Pipelines
                        </a>
                        <a href="/detachableapps/liquidfiles/<?= (int) $app['id'] ?>" class="btn btn-outline-success btn-sm" title="Shopify theme files">
                            <i class="bi bi-braces"></i> Shopify
                        </a>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-outline-warning btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Build options">
                                <i class="bi bi-hammer"></i> Build
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <form method="POST" action="/detachableapps/build/<?= (int) $app['id'] ?>" onsubmit="return confirm('Start Docker build for <?= h(addslashes($app['name'])) ?>?')">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                        <button type="submit" class="dropdown-item">
                                            <i class="bi bi-box-seam me-2"></i>Docker Build
                                        </button>
                                    </form>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="/detachableapps/buildshopify/<?= (int) $app['id'] ?>">
                                        <i class="bi bi-shop me-2"></i>Shopify Build
                                    </a>
                                </li>
                            </ul>
                        </div>
                        <a href="/detachableapps/download/<?= (int) $app['id'] ?>" class="btn btn-outline-info btn-sm" title="Download build">
                            <i class="bi bi-download"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
