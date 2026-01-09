<?php $hasWorkspace = !empty($workspace); ?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h4><?= $hasWorkspace ? 'Reset Password for <span class="text-primary">' . htmlspecialchars($workspace) . '</span>' : 'Reset Password' ?></h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($success) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <p class="text-muted mb-4">Enter your email address and we'll send you a link to reset your password.</p>

                    <form method="POST" action="/auth/doforgot">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if ($hasWorkspace): ?>
                            <input type="hidden" name="workspace" value="<?= htmlspecialchars($workspace) ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" required autofocus>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Send Reset Link</button>
                    </form>

                    <hr>

                    <div class="text-center">
                        <a href="/login<?= $hasWorkspace ? '/' . urlencode($workspace) : '' ?>">Back to Login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
