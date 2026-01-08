<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">
            <div class="text-center mb-4">
                <div class="mb-3">
                    <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                </div>
                <h1 class="h2">Welcome to MyCTOBot!</h1>
                <p class="text-muted">Your workspace is ready</p>
            </div>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        <h5 class="text-muted mb-2">Your workspace URL:</h5>
                        <a href="<?= htmlspecialchars($tenant['url']) ?>" class="h4 text-decoration-none" target="_blank">
                            <?= htmlspecialchars($tenant['url']) ?>
                        </a>
                    </div>

                    <hr>

                    <div class="mb-4">
                        <h6 class="text-muted mb-3">What's Next?</h6>
                        <ol class="ps-3">
                            <li class="mb-2">
                                <strong>Log in</strong> to your new workspace using the email and password you just created
                            </li>
                            <li class="mb-2">
                                <strong>Connect Jira</strong> to start analyzing your sprints
                            </li>
                            <li class="mb-2">
                                <strong>Configure your boards</strong> and set up daily digests
                            </li>
                        </ol>
                    </div>

                    <div class="d-grid">
                        <a href="<?= htmlspecialchars($tenant['url']) ?>/auth/login" class="btn btn-primary btn-lg">
                            Go to Your Workspace
                        </a>
                    </div>
                </div>
            </div>

            <div class="card mt-4 border-info">
                <div class="card-body">
                    <h6 class="card-title">
                        <i class="bi bi-info-circle text-info"></i>
                        Bookmark Your URL
                    </h6>
                    <p class="card-text small text-muted mb-0">
                        Save <strong><?= htmlspecialchars($tenant['url']) ?></strong> to your bookmarks.
                        This is your team's private workspace URL.
                    </p>
                </div>
            </div>

            <div class="text-center mt-4 text-muted small">
                Need help? Contact <a href="mailto:support@myctobot.ai">support@myctobot.ai</a>
            </div>
        </div>
    </div>
</div>

<style>
.card {
    border-radius: 12px;
}
</style>
