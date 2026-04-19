<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="bi bi-envelope-plus"></i> <?= h($title ?? 'Invite New Member') ?></h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?= h($error) ?></div>
                    <?php endif; ?>

                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success"><?= h($success) ?></div>
                    <?php endif; ?>

                    <div class="alert alert-info mb-4">
                        <i class="bi bi-info-circle"></i>
                        An email invitation will be sent to the user. They will set their own password when accepting the invite.
                    </div>

                    <form method="POST">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= h($name ?? '') ?>" value="<?= h($value ?? '') ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address *</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?= h($_POST['email'] ?? '') ?>" required
                                   placeholder="user@example.com">
                            <small class="form-text text-muted">Invitation will be sent to this email</small>
                        </div>

                        <div class="mb-3">
                            <label for="display_name" class="form-label">Display Name</label>
                            <input type="text" class="form-control" id="display_name" name="display_name"
                                   value="<?= h($_POST['display_name'] ?? '') ?>"
                                   placeholder="John Doe">
                            <small class="form-text text-muted">Optional - user can set this when accepting invite</small>
                        </div>

                        <div class="mb-3">
                            <label for="level" class="form-label">Permission Level *</label>
                            <select class="form-select" id="level" name="level" required>
                                <option value="100" <?= ($_POST['level'] ?? 100) == 100 ? 'selected' : '' ?>>Member (Standard User)</option>
                                <option value="50" <?= ($_POST['level'] ?? 100) == 50 ? 'selected' : '' ?>>Admin (Can manage members & settings)</option>
                                <option value="1" <?= ($_POST['level'] ?? 100) == 1 ? 'selected' : '' ?>>Root (Full system access)</option>
                            </select>
                            <div class="form-text">
                                <table class="table table-sm table-borderless mt-2 mb-0" style="font-size: 0.85em;">
                                    <tr>
                                        <td><span class="badge bg-secondary">Member</span></td>
                                        <td>Can use dashboards, view boards, run analysis</td>
                                    </tr>
                                    <tr>
                                        <td><span class="badge bg-warning text-dark">Admin</span></td>
                                        <td>+ Manage members, settings, integrations</td>
                                    </tr>
                                    <tr>
                                        <td><span class="badge bg-danger">Root</span></td>
                                        <td>+ Full system access, can delete admins</td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <hr>

                        <div class="d-flex justify-content-between">
                            <a href="/admin/members" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-send"></i> Send Invitation
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
