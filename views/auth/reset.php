<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h4>Reset Password</h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <p class="text-muted mb-4">Enter your new password below.</p>

                    <form method="POST" action="/auth/doreset">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <input type="hidden" name="token" value="<?= htmlspecialchars($token ?? '') ?>">

                        <div class="mb-3">
                            <label for="password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="password" name="password" required minlength="8" autofocus>
                            <small class="form-text text-muted">Minimum 8 characters</small>
                        </div>

                        <div class="mb-3">
                            <label for="password_confirm" class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" id="password_confirm" name="password_confirm" required minlength="8">
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Reset Password</button>
                    </form>

                    <hr>

                    <div class="text-center">
                        <a href="/login">Back to Login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
