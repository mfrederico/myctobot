<div class="container-fluid py-4">
    <h1 class="h2 mb-4">Admin Dashboard</h1>
    
    <div class="row">
        <div class="col-md-4 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Total Members</h5>
                    <h2 class="text-primary"><?= $stats['members'] ?></h2>
                </div>
            </div>
        </div>
        
        <div class="col-md-4 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Permissions</h5>
                    <h2 class="text-success"><?= $stats['permissions'] ?></h2>
                </div>
            </div>
        </div>
        
        <div class="col-md-4 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Active Sessions</h5>
                    <h2 class="text-info"><?= $stats['active_sessions'] ?></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12 mb-4">
            <div class="card border-<?= $cache_stats['apcu_available'] ? 'success' : 'warning' ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="card-title mb-1">
                                <i class="bi bi-lightning-charge-fill text-warning"></i> Cache Status
                            </h5>
                            <p class="mb-0 text-muted">
                                <?php if ($cache_stats['apcu_available']): ?>
                                    <span class="badge bg-success me-2">APCu Active</span>
                                    <?php if ($cache_stats['in_apcu']): ?>
                                        <span class="badge bg-info">Permissions Cached</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">Cache Warming Recommended</span>
                                    <?php endif; ?>
                                    <span class="ms-2"><?= $cache_stats['count'] ?> permissions cached</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">APCu Not Available</span>
                                    <span class="ms-2">Using database-only caching</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div>
                            <a href="/admin/cache" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-gear"></i> Manage Cache
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-12">
            <h3 class="mb-3">Quick Actions</h3>
            <div class="list-group">
                <a href="/admin/members" class="list-group-item list-group-item-action">
                    <div class="d-flex w-100 justify-content-between">
                        <h5 class="mb-1">Member Management</h5>
                    </div>
                    <p class="mb-1">View, edit, and manage user accounts</p>
                </a>
                
                <a href="/admin/permissions" class="list-group-item list-group-item-action">
                    <div class="d-flex w-100 justify-content-between">
                        <h5 class="mb-1">Permission Management</h5>
                    </div>
                    <p class="mb-1">Configure access controls and permissions</p>
                </a>
                
                <a href="/admin/settings" class="list-group-item list-group-item-action">
                    <div class="d-flex w-100 justify-content-between">
                        <h5 class="mb-1">System Settings</h5>
                    </div>
                    <p class="mb-1">Configure system-wide settings</p>
                </a>

                <a href="/admin/cache" class="list-group-item list-group-item-action">
                    <div class="d-flex w-100 justify-content-between">
                        <h5 class="mb-1"><i class="bi bi-lightning-charge"></i> Cache Management</h5>
                    </div>
                    <p class="mb-1">View cache statistics and clear caches (APCu, OPcache, Query Cache)</p>
                </a>

                <a href="/contact/admin" class="list-group-item list-group-item-action">
                    <div class="d-flex w-100 justify-content-between">
                        <h5 class="mb-1">Contact Messages</h5>
                        <?php
                        $newMessages = \app\Bean::count('contact', 'status = ?', ['new']);
                        if ($newMessages > 0):
                        ?>
                        <span class="badge bg-primary"><?= $newMessages ?> New</span>
                        <?php endif; ?>
                    </div>
                    <p class="mb-1">View and respond to contact form submissions</p>
                </a>
            </div>
        </div>
    </div>
</div>