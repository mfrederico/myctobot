<div class="container py-4" data-assist-page="studio/sales" data-assist-purpose="Sales and marketing studio for CRM, pipeline, and outreach">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi <?= $studio['icon'] ?? 'bi-megaphone' ?> text-<?= $studio['color'] ?? 'danger' ?>"></i>
                <?= h($studio['name'] ?? 'Sales & Marketing Studio') ?>
            </h1>
            <p class="text-muted mb-0"><?= h($studio['tagline'] ?? '') ?></p>
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
                                <div class="fw-semibold"><?= h($s['shortName'] ?? $s['name'] ?? '') ?></div>
                                <small class="<?= $key === $studioKey ? 'text-light opacity-75' : 'text-muted' ?>"><?= h($s['description'] ?? '') ?></small>
                            </div>
                        </div>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <?php if (!$hasSigned): ?>
    <!-- Agreement Required Banner -->
    <div class="alert alert-warning d-flex align-items-center mb-4">
        <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
        <div class="flex-grow-1">
            <strong>Agreement Required</strong> — You must sign the Sales Referral Agreement before accessing the CRM.
        </div>
        <a href="/agreement" class="btn btn-warning btn-sm ms-3">Sign Agreement</a>
    </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <a href="/crm" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded bg-danger bg-opacity-10 p-3 me-3">
                        <i class="bi bi-speedometer2 fs-4 text-danger"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">CRM Dashboard</div>
                        <small class="text-muted">Sales overview</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-6">
            <a href="/crm/pipeline" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded bg-primary bg-opacity-10 p-3 me-3">
                        <i class="bi bi-kanban fs-4 text-primary"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">Pipeline</div>
                        <small class="text-muted">Deal stages</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-6">
            <a href="/livechat" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded bg-success bg-opacity-10 p-3 me-3">
                        <i class="bi bi-headset fs-4 text-success"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">Live Chat</div>
                        <small class="text-muted">Customer engagement</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-6">
            <a href="/crm/create" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded bg-info bg-opacity-10 p-3 me-3">
                        <i class="bi bi-person-plus fs-4 text-info"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-dark">New Contact</div>
                        <small class="text-muted">Add a lead</small>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-2 col-4">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-3">
                    <div class="fs-3 fw-bold text-primary"><?= $prospectCount ?></div>
                    <small class="text-muted">Prospects</small>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-4">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-3">
                    <div class="fs-3 fw-bold text-info"><?= $pipelineCounts['cold'] ?? 0 ?></div>
                    <small class="text-muted">Cold</small>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-4">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-3">
                    <div class="fs-3 fw-bold text-warning"><?= $pipelineCounts['warm'] ?? 0 ?></div>
                    <small class="text-muted">Warm</small>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-4">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-3">
                    <div class="fs-3 fw-bold text-orange" style="color:#fd7e14;"><?= $pipelineCounts['hot'] ?? 0 ?></div>
                    <small class="text-muted">Hot</small>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-4">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-3">
                    <div class="fs-3 fw-bold text-danger"><?= $pipelineCounts['closing'] ?? 0 ?></div>
                    <small class="text-muted">Closing</small>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-4">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-3">
                    <div class="fs-3 fw-bold text-success"><?= $customerCount ?></div>
                    <small class="text-muted">Customers</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Activity & Links -->
    <div class="row g-3">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clock-history"></i> Recent Activity</span>
                    <a href="/crm" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentActivity)): ?>
                        <div class="text-center text-muted py-4">No recent activity.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($recentActivity as $act): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                                <small><?= h($act->description ?? $act->activityType ?? '') ?></small>
                                <small class="text-muted"><?= date('M j g:ia', strtotime($act->createdAt)) ?></small>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <i class="bi bi-lightning-charge"></i> Quick Links
                </div>
                <div class="list-group list-group-flush">
                    <a href="/crm/contacts" class="list-group-item list-group-item-action"><i class="bi bi-address-book me-2"></i> All Contacts</a>
                    <a href="/crm/contacts?filter=prospects" class="list-group-item list-group-item-action"><i class="bi bi-bullseye me-2"></i> Prospects</a>
                    <a href="/crm/customers" class="list-group-item list-group-item-action"><i class="bi bi-building me-2"></i> Customers</a>
                    <a href="/agreement/view" class="list-group-item list-group-item-action"><i class="bi bi-file-earmark-check me-2"></i> My Agreement</a>
                    <a href="/livechat" class="list-group-item list-group-item-action"><i class="bi bi-chat-dots me-2"></i> Live Chat</a>
                </div>
            </div>
        </div>
    </div>
</div>
