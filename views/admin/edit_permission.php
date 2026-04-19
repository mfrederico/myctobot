<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h4><?= h($title) ?></h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?= h($error) ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= h($name) ?>" value="<?= h($value) ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label for="control" class="form-label">Controller</label>
                            <input type="text" class="form-control" id="control" name="control" 
                                   value="<?= h($permission->control ?? '') ?>" required>
                            <small class="form-text text-muted">The controller class name (e.g., 'admin', 'member')</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="method" class="form-label">Method</label>
                            <input type="text" class="form-control" id="method" name="method" 
                                   value="<?= h($permission->method ?? '') ?>" required>
                            <small class="form-text text-muted">The method name (e.g., 'index', 'edit')</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="level" class="form-label">Authorization Level</label>
                            <select class="form-select" id="level" name="level" required>
                                <option value="1" <?= ($permission->level ?? 101) == 1 ? 'selected' : '' ?>>ROOT (1)</option>
                                <option value="50" <?= ($permission->level ?? 101) == 50 ? 'selected' : '' ?>>ADMIN (50)</option>
                                <option value="100" <?= ($permission->level ?? 101) == 100 ? 'selected' : '' ?>>MEMBER (100)</option>
                                <option value="101" <?= ($permission->level ?? 101) == 101 ? 'selected' : '' ?>>PUBLIC (101)</option>
                            </select>
                            <small class="form-text text-muted">Minimum level required to access this method</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <input type="text" class="form-control" id="description" name="description" 
                                   value="<?= h($permission->description ?? '') ?>">
                            <small class="form-text text-muted">Brief description of this permission</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="linkorder" class="form-label">Link Order</label>
                            <input type="number" class="form-control" id="linkorder" name="linkorder" 
                                   value="<?= h($permission->linkorder ?? 0) ?>">
                            <small class="form-text text-muted">Order for display (lower numbers first)</small>
                        </div>
                        
                        <?php if ($permission->id): ?>
                            <div class="mb-3">
                                <label class="form-label">Statistics</label>
                                <p class="form-control-plaintext">
                                    Valid Count: <?= $permission->validcount ?? 0 ?><br>
                                    Created: <?= $permission->created_at ?? 'N/A' ?>
                                </p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="d-flex justify-content-between">
                            <a href="/admin/permissions" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Save Permission</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>