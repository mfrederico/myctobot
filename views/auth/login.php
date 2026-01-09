<?php
// With session-based tenancy, we're always on the main domain so Google OAuth works
// Pass workspace to Google OAuth so it can set the tenant after callback
$showGoogleOAuth = !empty($googleEnabled);
$hasWorkspace = !empty($workspace);
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h4>
                        <?php if ($hasWorkspace): ?>
                            Login to <span class="text-primary"><?= htmlspecialchars($workspace) ?></span>
                        <?php else: ?>
                            Login to MyCTOBot
                        <?php endif; ?>
                    </h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($workspaceError)): ?>
                        <div class="alert alert-warning alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($workspaceError) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($redirect)): ?>
                        <div class="alert alert-info alert-dismissible fade show" role="alert">
                            Please login to continue
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($_GET['welcome'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <strong>Welcome!</strong> Your workspace is ready. Log in with the email and password you created.
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($showGoogleOAuth): ?>
                        <?php
                        // Build Google OAuth URL with workspace and redirect params
                        $googleParams = [];
                        if (!empty($workspace)) $googleParams['workspace'] = $workspace;
                        if (!empty($redirect)) $googleParams['redirect'] = $redirect;
                        $googleUrl = '/auth/google' . ($googleParams ? '?' . http_build_query($googleParams) : '');
                        ?>
                        <div class="d-grid gap-2 mb-4">
                            <a href="<?= htmlspecialchars($googleUrl) ?>" class="btn btn-outline-danger btn-lg">
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

                        <?php if (!$hasWorkspace): ?>
                            <!-- Workspace field - shown when not pre-set in URL -->
                            <div class="mb-3">
                                <label for="workspace" class="form-label">Workspace Code</label>
                                <input type="text" class="form-control" id="workspace" name="workspace"
                                       placeholder="e.g., mycompany" autocomplete="off">
                                <small class="form-text text-muted">Leave blank for main site login</small>
                            </div>
                            <hr>
                        <?php else: ?>
                            <!-- Hidden workspace field when pre-set -->
                            <input type="hidden" name="workspace" value="<?= htmlspecialchars($workspace) ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="username" class="form-label">Email</label>
                            <input type="email" class="form-control" id="username" name="username" required <?= $hasWorkspace ? 'autofocus' : '' ?>>
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
                        <?php if (!$hasWorkspace): ?>
                            <a href="/auth/register">Don't have an account? Register</a><br>
                        <?php endif; ?>
                        <a href="/auth/forgot">Forgot your password?</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
