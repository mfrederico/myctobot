<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">Permission Management</h1>
        <a href="/admin/editpermission" class="btn btn-primary">Add Permission</a>
    </div>
    
    <?php if (empty($authControls)): ?>
        <div class="alert alert-info">No permissions configured yet.</div>
    <?php else: ?>
        <?php foreach ($authControls as $control => $methods): ?>
            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="mb-0">Controller: <?= htmlspecialchars($control) ?></h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Method</th>
                                    <th>Level Required</th>
                                    <th>Description</th>
                                    <th>Valid Count</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($methods as $method => $perm): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($method) ?></code></td>
                                        <td>
                                            <?php
                                            $levelName = 'Unknown';
                                            $levelClass = 'secondary';
                                            switch($perm['level']) {
                                                case 0:
                                                case 1:
                                                    $levelName = 'ROOT';
                                                    $levelClass = 'danger';
                                                    break;
                                                case 50:
                                                    $levelName = 'ADMIN';
                                                    $levelClass = 'warning';
                                                    break;
                                                case 100:
                                                    $levelName = 'MEMBER';
                                                    $levelClass = 'primary';
                                                    break;
                                                case 101:
                                                    $levelName = 'PUBLIC';
                                                    $levelClass = 'success';
                                                    break;
                                            }
                                            ?>
                                            <span class="badge bg-<?= $levelClass ?>">
                                                <?= $levelName ?> (<?= $perm['level'] ?>)
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($perm['description'] ?? '') ?></td>
                                        <td><?= $perm['validcount'] ?? 0 ?></td>
                                        <td>
                                            <a href="/admin/editpermission?id=<?= $perm['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                            <a href="/admin/permissions?delete=<?= $perm['id'] ?>" 
                                               class="btn btn-sm btn-outline-danger"
                                               onclick="return confirm('Delete this permission?')">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <div class="mt-4">
        <h4>Permission Levels</h4>
        <ul class="list-group">
            <li class="list-group-item"><span class="badge bg-danger">0-1</span> ROOT - Full system access</li>
            <li class="list-group-item"><span class="badge bg-warning">50</span> ADMIN - Administrative access</li>
            <li class="list-group-item"><span class="badge bg-primary">100</span> MEMBER - Logged in members</li>
            <li class="list-group-item"><span class="badge bg-success">101</span> PUBLIC - Everyone</li>
        </ul>
    </div>
</div>