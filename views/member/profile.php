<div class="container py-4">
    <h1 class="h2 mb-4">My Profile</h1>
    
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Profile Information</h5>
                    
                    <table class="table">
                        <tr>
                            <th width="30%">Username:</th>
                            <td><?= h($member->username) ?></td>
                        </tr>
                        <tr>
                            <th>Email:</th>
                            <td><?= h($member->email) ?></td>
                        </tr>
                        <tr>
                            <th>Account Level:</th>
                            <td>
                                <?php
                                $lookupLevel = ((int) $member->level) === 0 ? 1 : (int) $member->level;
                                $levelName = strtoupper(LEVEL_META[$lookupLevel]['label'] ?? 'Unknown');
                                ?>
                                <?= h($levelName) ?> (<?= (int) $member->level ?>)
                            </td>
                        </tr>
                        <tr>
                            <th>Status:</th>
                            <td><?= h($member->status) ?></td>
                        </tr>
                        <tr>
                            <th>Member Since:</th>
                            <td><?= date('F j, Y', strtotime($member->created_at ?? 'now')) ?></td>
                        </tr>
                        <tr>
                            <th>Last Updated:</th>
                            <td><?= date('F j, Y g:i A', strtotime($member->updated_at ?? 'now')) ?></td>
                        </tr>
                    </table>
                    
                    <div class="mt-3">
                        <a href="/member/edit" class="btn btn-primary">Edit Profile</a>
                        <a href="/member/settings" class="btn btn-secondary">Settings</a>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Quick Actions</h5>
                    <div class="list-group">
                        <a href="/member/dashboard" class="list-group-item list-group-item-action">Dashboard</a>
                        <a href="/member/settings" class="list-group-item list-group-item-action">Settings</a>
                        <?php if ($member->level <= 50): ?>
                            <a href="/admin" class="list-group-item list-group-item-action">Admin Panel</a>
                        <?php endif; ?>
                        <a href="/auth/logout" class="list-group-item list-group-item-action">Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
