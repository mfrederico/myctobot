<div class="container py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi <?= $studio['icon'] ?? 'bi-shop' ?> text-<?= $studio['color'] ?? 'success' ?>"></i>
                <?= htmlspecialchars($studio['name'] ?? 'Commerce Studio') ?>
            </h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($studio['tagline'] ?? '') ?></p>
        </div>
        <div class="dropdown">
            <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-arrow-left-right"></i> Switch Studio
            </button>
            <ul class="dropdown-menu dropdown-menu-end" style="min-width: 240px;">
                <?php foreach ($studios as $key => $s): ?>
                <li>
                    <a class="dropdown-item py-2 <?= $key === $studioKey ? 'active' : '' ?>" href="/studio/<?= $key ?>">
                        <div class="d-flex align-items-start">
                            <i class="bi <?= $s['icon'] ?> me-2 mt-1"></i>
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($s['shortName'] ?? $s['name'] ?? '') ?></div>
                                <small class="<?= $key === $studioKey ? 'text-light opacity-75' : 'text-muted' ?>"><?= htmlspecialchars($s['description'] ?? '') ?></small>
                            </div>
                        </div>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <a href="/shopify/connect" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded bg-success bg-opacity-10 p-3 me-3">
                        <i class="bi bi-plus-lg fs-4 text-success"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">Connect Store</div>
                        <small class="text-muted">Add a Shopify store</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="/shopify" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded bg-primary bg-opacity-10 p-3 me-3">
                        <i class="bi bi-shop fs-4 text-primary"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">Manage Stores</div>
                        <small class="text-muted">View all connections</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="/pipelines" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded bg-info bg-opacity-10 p-3 me-3">
                        <i class="bi bi-diagram-3 fs-4 text-info"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">QA Pipelines</div>
                        <small class="text-muted">Visual testing workflows</small>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="row">
        <!-- Stores -->
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-shop"></i> Connected Stores</h5>
                    <a href="/shopify" class="btn btn-sm btn-outline-primary">Manage All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($stores)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-shop display-4 text-muted"></i>
                        <p class="text-muted mt-3 mb-0">No Shopify stores connected yet.</p>
                        <a href="/shopify/connect" class="btn btn-success mt-3">
                            <i class="bi bi-plus-lg"></i> Connect Store
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Store</th>
                                    <th>Domain</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stores as $store): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="rounded bg-success bg-opacity-10 p-2 me-3">
                                                <i class="bi bi-shop text-success"></i>
                                            </div>
                                            <span class="fw-semibold"><?= htmlspecialchars($store['shop_name']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= htmlspecialchars($store['shop_domain']) ?></small>
                                    </td>
                                    <td>
                                        <?php
                                        $statusColors = [
                                            'active' => 'success',
                                            'inactive' => 'secondary',
                                            'error' => 'danger'
                                        ];
                                        $color = $statusColors[$store['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?= $color ?>"><?= ucfirst($store['status']) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <a href="/shopify/store/<?= $store['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Connection Status -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-plug"></i> Connection Status</h6>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex align-items-center justify-content-between">
                            <div>
                                <i class="bi bi-shop me-2"></i>
                                <span class="small">Shopify</span>
                            </div>
                            <?php if ($shopifyConnected): ?>
                            <span class="badge bg-success"><i class="bi bi-check-lg"></i> <?= count($stores) ?> Store<?= count($stores) !== 1 ? 's' : '' ?></span>
                            <?php else: ?>
                            <a href="/shopify/connect" class="btn btn-sm btn-outline-success">Connect</a>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Store Stats -->
            <div class="card mb-4">
                <div class="card-body text-center">
                    <div class="display-4 fw-bold text-success"><?= count($stores) ?></div>
                    <p class="text-muted mb-0">Connected Stores</p>
                </div>
            </div>

            <!-- Getting Started -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-lightbulb"></i> Getting Started</h6>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li class="mb-3 d-flex align-items-start">
                            <?php if ($shopifyConnected): ?>
                            <i class="bi bi-check-circle-fill text-success me-2"></i>
                            <?php else: ?>
                            <i class="bi bi-1-circle text-muted me-2"></i>
                            <?php endif; ?>
                            <span class="small">Connect your Shopify store</span>
                        </li>
                        <li class="mb-3 d-flex align-items-start">
                            <i class="bi bi-2-circle text-muted me-2"></i>
                            <span class="small">Set up theme preview environments</span>
                        </li>
                        <li class="mb-3 d-flex align-items-start">
                            <i class="bi bi-3-circle text-muted me-2"></i>
                            <span class="small">Create visual QA pipelines</span>
                        </li>
                        <li class="d-flex align-items-start">
                            <i class="bi bi-4-circle text-muted me-2"></i>
                            <span class="small">Automate product updates</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
