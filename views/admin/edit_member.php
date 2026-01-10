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
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username"
                                   value="<?= htmlspecialchars($editMember->username ?? '') ?>" required
                                   <?= ($editMember->username ?? '') === 'public-user-entity' ? 'readonly' : '' ?>>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?= htmlspecialchars($editMember->email ?? '') ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password">
                            <small class="form-text text-muted">Leave blank to keep current password</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="level" class="form-label">User Level</label>
                            <select class="form-select" id="level" name="level" required
                                    <?= ($editMember->username ?? '') === 'public-user-entity' ? 'disabled' : '' ?>>
                                <option value="0" <?= ($editMember->level ?? 100) == 0 ? 'selected' : '' ?>>ROOT (0)</option>
                                <option value="1" <?= ($editMember->level ?? 100) == 1 ? 'selected' : '' ?>>ROOT (1)</option>
                                <option value="50" <?= ($editMember->level ?? 100) == 50 ? 'selected' : '' ?>>ADMIN (50)</option>
                                <option value="100" <?= ($editMember->level ?? 100) == 100 ? 'selected' : '' ?>>MEMBER (100)</option>
                                <option value="101" <?= ($editMember->level ?? 100) == 101 ? 'selected' : '' ?>>PUBLIC (101)</option>
                            </select>
                            <?php if (($editMember->username ?? '') === 'public-user-entity'): ?>
                                <input type="hidden" name="level" value="101">
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status" required
                                    <?= ($editMember->username ?? '') === 'public-user-entity' ? 'disabled' : '' ?>>
                                <option value="active" <?= ($editMember->status ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="suspended" <?= ($editMember->status ?? '') === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                <option value="inactive" <?= ($editMember->status ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                            <?php if (($editMember->username ?? '') === 'public-user-entity'): ?>
                                <input type="hidden" name="status" value="active">
                                <small class="form-text text-muted">System user - status cannot be changed</small>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Member Information</label>
                            <p class="form-control-plaintext">
                                ID: <?= $editMember->id ?? '' ?><br>
                                Created: <?= htmlspecialchars($editMember->created_at ?? 'N/A') ?><br>
                                Updated: <?= htmlspecialchars($editMember->updated_at ?? 'N/A') ?>
                            </p>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <a href="/admin/members" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Update Member</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>