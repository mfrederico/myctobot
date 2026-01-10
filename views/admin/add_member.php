<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h4><?= htmlspecialchars($title ?? '') ?></h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars($name ?? '') ?>" value="<?= htmlspecialchars($value ?? '') ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label for="username" class="form-label">Username *</label>
                            <input type="text" class="form-control" id="username" name="username" 
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                            <small class="form-text text-muted">Minimum 3 characters</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email" class="form-label">Email *</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Password *</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                            <small class="form-text text-muted">Minimum 8 characters</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="level" class="form-label">User Level</label>
                            <select class="form-select" id="level" name="level" required>
                                <option value="0" <?= ($_POST['level'] ?? 100) == 0 ? 'selected' : '' ?>>ROOT (0)</option>
                                <option value="1" <?= ($_POST['level'] ?? 100) == 1 ? 'selected' : '' ?>>ROOT (1)</option>
                                <option value="50" <?= ($_POST['level'] ?? 100) == 50 ? 'selected' : '' ?>>ADMIN (50)</option>
                                <option value="100" <?= ($_POST['level'] ?? 100) == 100 ? 'selected' : '' ?>>MEMBER (100)</option>
                                <option value="101" <?= ($_POST['level'] ?? 100) == 101 ? 'selected' : '' ?>>PUBLIC (101)</option>
                            </select>
                            <small class="form-text text-muted">
                                <strong>ROOT:</strong> Full system access<br>
                                <strong>ADMIN:</strong> Administrative access<br>
                                <strong>MEMBER:</strong> Regular user access<br>
                                <strong>PUBLIC:</strong> Limited/guest access
                            </small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="active" <?= ($_POST['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="suspended" <?= ($_POST['status'] ?? 'active') === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                <option value="inactive" <?= ($_POST['status'] ?? 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <a href="/admin/members" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Create Member</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>