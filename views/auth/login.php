<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h4>Login to MyCTOBot</h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($redirect)): ?>
                        <div class="alert alert-info alert-dismissible fade show" role="alert">
                            Please login to continue
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($googleEnabled)): ?>
                        <div class="d-grid gap-2 mb-4">
                            <a href="/auth/google<?= !empty($redirect) ? '?redirect=' . urlencode($redirect) : '' ?>" class="btn btn-outline-danger btn-lg">
                                <i class="bi bi-google"></i> Sign in with Google
                            </a>
                        </div>
                        <div class="text-center text-muted mb-3">
                            <small>or login with email</small>
                        </div>
                        <hr>
                    <?php endif; ?>

                    <form method="POST" action="/auth/dologin">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($redirect)): ?>
                            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="username" class="form-label">Email</label>
                            <input type="email" class="form-control" id="username" name="username" required autofocus>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>

                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="remember" name="remember">
                            <label class="form-check-label" for="remember">
                                Remember me
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Login</button>
                    </form>

                    <hr>

                    <div class="text-center">
                        <a href="/auth/register">Don't have an account? Register</a><br>
                        <a href="/auth/forgot">Forgot your password?</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
