<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-chart-line"></i> CRM Dashboard</h1>
        <a href="/crm/create" class="btn btn-primary"><i class="fas fa-plus"></i> New Contact</a>
    </div>

    <!-- Pipeline Funnel -->
    <div class="row mb-4">
        <div class="col-md-3">
            <a href="/crm/contacts?filter=prospects&stage=cold" class="text-decoration-none">
                <div class="card border-secondary">
                    <div class="card-body text-center">
                        <h3 class="text-secondary"><?= $pipelineCounts['cold'] ?? 0 ?></h3>
                        <small class="text-muted"><i class="fas fa-snowflake"></i> Cold</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="/crm/contacts?filter=prospects&stage=warm" class="text-decoration-none">
                <div class="card border-warning">
                    <div class="card-body text-center">
                        <h3 class="text-warning"><?= $pipelineCounts['warm'] ?? 0 ?></h3>
                        <small class="text-muted"><i class="fas fa-temperature-half"></i> Warm</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="/crm/contacts?filter=prospects&stage=hot" class="text-decoration-none">
                <div class="card border-danger">
                    <div class="card-body text-center">
                        <h3 class="text-danger"><?= $pipelineCounts['hot'] ?? 0 ?></h3>
                        <small class="text-muted"><i class="fas fa-fire"></i> Hot</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="/crm/contacts?filter=prospects&stage=closing" class="text-decoration-none">
                <div class="card border-primary">
                    <div class="card-body text-center">
                        <h3 class="text-primary"><?= $pipelineCounts['closing'] ?? 0 ?></h3>
                        <small class="text-muted"><i class="fas fa-fire-flame-curved"></i> Closing Crucible</small>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-3">
            <a href="/crm/customers" class="text-decoration-none">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <h3 class="text-success"><?= $customerCount ?? 0 ?></h3>
                        <small class="text-muted"><i class="fas fa-building"></i> Customers</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-9">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="/crm/pipeline" class="btn btn-outline-primary"><i class="fas fa-columns"></i> Pipeline Board</a>
                        <a href="/crm/contacts" class="btn btn-outline-secondary"><i class="fas fa-address-book"></i> All Contacts</a>
                        <a href="/crm/contacts?filter=prospects" class="btn btn-outline-info"><i class="fas fa-bullseye"></i> Prospects</a>
                        <a href="/crm/customers" class="btn btn-outline-success"><i class="fas fa-building"></i> Customers</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Needs Follow-Up -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <i class="fas fa-clock"></i> Needs Follow-Up (7+ days)
                </div>
                <div class="card-body p-0">
                    <?php if (empty($staleContacts)): ?>
                        <p class="text-muted p-3 mb-0">All customers have been contacted recently.</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($staleContacts as $c): ?>
                                <a href="/crm/view/<?= $c->id ?>" class="list-group-item list-group-item-action">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?= h($c->fullName()) ?></strong>
                                            <br><small class="text-muted"><?= h($c->companyName) ?></small>
                                        </div>
                                        <small class="text-danger">
                                            <?php if ($c->lastTouchAt): ?>
                                                <?= date('M j', strtotime($c->lastTouchAt)) ?>
                                            <?php else: ?>
                                                Never
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <i class="fas fa-stream"></i> Recent Activity
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentActivity)): ?>
                        <p class="text-muted p-3 mb-0">No activity yet.</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($recentActivity as $a): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between">
                                        <small><?= h($a->description) ?></small>
                                            <?php if ($a->createdAt): ?>
						<small class="text-muted"><?= $a->createdAt ? date('M j g:ia', strtotime($a->createdAt)) : '' ?></small>
                                            <?php else: ?>
                                                Never
                                            <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
