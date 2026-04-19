<div class="container py-4" data-assist-page="member/dashboard" data-assist-purpose="Personal member dashboard and account overview">
    <h1 class="h2 mb-4">Dashboard</h1>
    
    <div class="row">
        <!-- Welcome Card -->
        <div class="col-md-12 mb-4">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h4 class="card-title">Welcome back, <?= h($member->username) ?>!</h4>
                    <p class="card-text">You are logged in as 
                        <?php
                        $levelName = 'Member';
                        switch($member->level) {
                            case 0:
                            case 1:
                                $levelName = 'Root Administrator';
                                break;
                            case 50:
                                $levelName = 'Administrator';
                                break;
                            case 100:
                                $levelName = 'Member';
                                break;
                        }
                        echo $levelName;
                        ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- Quick Stats -->
        <div class="col-md-3 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title text-muted">Member Since</h5>
                    <h3 class="card-text"><?= date('M Y', strtotime($member->created_at ?? 'now')) ?></h3>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title text-muted">Last Login</h5>
                    <h3 class="card-text"><?= date('M d', strtotime($member->last_login ?? 'now')) ?></h3>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title text-muted">Account Status</h5>
                    <h3 class="card-text text-success"><?= ucfirst($member->status) ?></h3>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title text-muted">Login Count</h5>
                    <h3 class="card-text"><?= $member->login_count ?? 1 ?></h3>
                </div>
            </div>
        </div>
    </div>
    
    <!-- CEO Directives & Project Management -->
    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card border-warning">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0">CEO Directives</h5>
                </div>
                <div class="card-body">
                    <div class="list-group">
                        <a href="/directives" class="list-group-item list-group-item-action">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">1. Directives</h6>
                                <small>→</small>
                            </div>
                            <p class="mb-1 text-muted">Incoming CEO directives</p>
                        </a>
                        <a href="/projects" class="list-group-item list-group-item-action">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">2. Projects</h6>
                                <small>→</small>
                            </div>
                            <p class="mb-1 text-muted">Planning & progress</p>
                        </a>
                        <a href="/reviewboard" class="list-group-item list-group-item-action list-group-item-warning">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">3. Review Board</h6>
                                <small>→</small>
                            </div>
                            <p class="mb-1 text-muted">Approve stories → create issues</p>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">AI Development</h5>
                </div>
                <div class="card-body">
                    <div class="list-group">
                        <a href="/aidev" class="list-group-item list-group-item-action">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">AI Dev Jobs</h6>
                                <small>→</small>
                            </div>
                            <p class="mb-1 text-muted">Monitor AI developer agent job status</p>
                        </a>
                        <a href="/boards" class="list-group-item list-group-item-action">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">Jira Boards</h6>
                                <small>→</small>
                            </div>
                            <p class="mb-1 text-muted">Manage tracked Jira boards</p>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Quick Actions -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="list-group">
                        <a href="/member/profile" class="list-group-item list-group-item-action">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">View Profile</h6>
                                <small>→</small>
                            </div>
                            <p class="mb-1 text-muted">View and manage your profile information</p>
                        </a>
                        <a href="/member/edit" class="list-group-item list-group-item-action">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">Edit Profile</h6>
                                <small>→</small>
                            </div>
                            <p class="mb-1 text-muted">Update your personal information and password</p>
                        </a>
                        <a href="/member/settings" class="list-group-item list-group-item-action">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">Settings</h6>
                                <small>→</small>
                            </div>
                            <p class="mb-1 text-muted">Configure your account preferences</p>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Account Information -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Account Information</h5>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">Username:</dt>
                        <dd class="col-sm-8"><?= h($member->username) ?></dd>
                        
                        <dt class="col-sm-4">Email:</dt>
                        <dd class="col-sm-8"><?= h($member->email) ?></dd>
                        
                        <dt class="col-sm-4">Account Level:</dt>
                        <dd class="col-sm-8">
                            <?php
                            $levelBadge = 'badge bg-secondary';
                            switch($member->level) {
                                case 0:
                                case 1:
                                    $levelBadge = 'badge bg-danger';
                                    break;
                                case 50:
                                    $levelBadge = 'badge bg-warning';
                                    break;
                                case 100:
                                    $levelBadge = 'badge bg-primary';
                                    break;
                            }
                            ?>
                            <span class="<?= $levelBadge ?>"><?= $levelName ?></span>
                        </dd>
                        
                        <dt class="col-sm-4">Member ID:</dt>
                        <dd class="col-sm-8">#<?= $member->id ?></dd>
                        
                        <dt class="col-sm-4">Last Updated:</dt>
                        <dd class="col-sm-8"><?= date('F j, Y', strtotime($member->updated_at ?? 'now')) ?></dd>
                    </dl>
                    
                    <?php if ($member->level <= 50): ?>
                        <hr>
                        <a href="/admin" class="btn btn-warning">Admin Panel →</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Activity -->
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Recent Activity</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle"></i> Activity tracking will be available in a future update.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>