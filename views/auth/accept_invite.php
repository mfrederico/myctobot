<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow">
                <div class="card-header bg-success text-white text-center py-3">
                    <h4 class="mb-0">
                        <i class="bi bi-envelope-check"></i>
                        Accept Invitation
                    </h4>
                </div>
                <div class="card-body p-4">
                    <?php if (!empty($tenantDisplayName)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-building"></i>
                        You're joining <strong><?= htmlspecialchars($tenantDisplayName) ?></strong>
                    </div>
                    <?php endif; ?>

                    <div class="mb-4 text-center">
                        <p class="text-muted mb-1">Setting up account for:</p>
                        <strong class="fs-5"><?= htmlspecialchars($email ?? '') ?></strong>
                    </div>

                    <form method="POST" action="/auth/invite/<?= htmlspecialchars($token ?? '') ?><?= !empty($tenant) ? '?tenant=' . htmlspecialchars($tenant) : '' ?>">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token ?? '') ?>">
                        <input type="hidden" name="tenant" value="<?= htmlspecialchars($tenant ?? '') ?>">

                        <div class="mb-3">
                            <label for="display_name" class="form-label">Your Name</label>
                            <input type="text" class="form-control" id="display_name" name="display_name"
                                   value="<?= htmlspecialchars($displayName ?? '') ?>"
                                   placeholder="Enter your name"
                                   autofocus>
                            <small class="form-text text-muted">How you want to be identified in the app</small>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Create Password *</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="password" name="password"
                                       minlength="8" required placeholder="Minimum 8 characters">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password')">
                                    <i class="bi bi-eye" id="password-icon"></i>
                                </button>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="confirm_password" class="form-label">Confirm Password *</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                                       minlength="8" required placeholder="Re-enter your password">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')">
                                    <i class="bi bi-eye" id="confirm_password-icon"></i>
                                </button>
                            </div>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-success btn-lg">
                                <i class="bi bi-check-circle"></i> Activate Account
                            </button>
                        </div>
                    </form>
                </div>
                <div class="card-footer text-center text-muted">
                    <small>
                        By activating your account, you agree to our
                        <a href="/terms" target="_blank">Terms of Service</a>
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const icon = document.getElementById(fieldId + '-icon');

    if (field.type === 'password') {
        field.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        field.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

// Password match validation
document.getElementById('confirm_password').addEventListener('input', function() {
    const password = document.getElementById('password').value;
    const confirm = this.value;

    if (confirm && password !== confirm) {
        this.setCustomValidity('Passwords do not match');
        this.classList.add('is-invalid');
    } else {
        this.setCustomValidity('');
        this.classList.remove('is-invalid');
    }
});

document.getElementById('password').addEventListener('input', function() {
    const confirm = document.getElementById('confirm_password');
    if (confirm.value) {
        confirm.dispatchEvent(new Event('input'));
    }
});
</script>
