<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow-sm">
                <div class="card-body text-center">
                    <div class="mb-4">
                        <i class="bi bi-envelope-check text-success" style="font-size: 4rem;"></i>
                    </div>
                    <h2 class="card-title mb-3">Check Your Email</h2>
                    <p class="text-muted mb-4">
                        We've sent a verification email to:<br>
                        <strong><?= htmlspecialchars($email ?? '') ?></strong>
                    </p>
                    <p class="text-muted">
                        Click the link in the email to verify your account and complete registration.
                    </p>
                    <hr class="my-4">
                    <p class="small text-muted mb-0">
                        Didn't receive the email? Check your spam folder or
                        <a href="/register">try registering again</a>.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
