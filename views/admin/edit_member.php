<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h4><?= h($title ?? '') ?></h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?= h($error) ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success"><?= h($success) ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= h($name ?? '') ?>" value="<?= h($value ?? '') ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username"
                                   value="<?= h($editMember->username ?? '') ?>" required
                                   <?= ($editMember->username ?? '') === 'public-user-entity' ? 'readonly' : '' ?>>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?= h($editMember->email ?? '') ?>" required>
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
                                <?php
                                $currentLevel = (int) ($editMember->level ?? 100);
                                if ($currentLevel === 0) $currentLevel = 1; // legacy ROOT alias
                                ?>
                                <?php foreach (LEVEL_META as $value => $meta): ?>
                                    <option value="<?= $value ?>" <?= $currentLevel === $value ? 'selected' : '' ?>>
                                        <?= strtoupper(h($meta['label'])) ?> (<?= $value ?>)
                                    </option>
                                <?php endforeach; ?>
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
                                Created: <?= h($editMember->created_at ?? 'N/A') ?><br>
                                Updated: <?= h($editMember->updated_at ?? 'N/A') ?>
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