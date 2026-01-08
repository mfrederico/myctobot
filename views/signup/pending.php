<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">
            <div class="text-center mb-4">
                <div class="mb-3">
                    <i class="bi bi-envelope-check text-primary" style="font-size: 4rem;"></i>
                </div>
                <h1 class="h2">Check Your Email</h1>
                <p class="text-muted">We've sent you a verification link</p>
            </div>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        <p class="mb-3">
                            We've sent a verification email to:
                        </p>
                        <p class="h5 text-primary mb-3">
                            <?= htmlspecialchars($maskedEmail) ?>
                        </p>
                        <p class="text-muted small">
                            Click the link in the email to activate your workspace.
                        </p>
                    </div>

                    <hr>

                    <div class="text-center">
                        <p class="text-muted mb-3">Didn't receive the email?</p>
                        <button type="button" class="btn btn-outline-primary" id="resendBtn" onclick="resendEmail()">
                            Resend Verification Email
                        </button>
                        <div id="resendMessage" class="mt-2 small"></div>
                    </div>
                </div>
            </div>

            <div class="card mt-4 border-info">
                <div class="card-body">
                    <h6 class="card-title">
                        <i class="bi bi-lightbulb text-info"></i>
                        Tips
                    </h6>
                    <ul class="card-text small text-muted mb-0 ps-3">
                        <li>Check your spam or junk folder</li>
                        <li>Make sure you entered the correct email address</li>
                        <li>The verification link expires in 24 hours</li>
                    </ul>
                </div>
            </div>

            <div class="text-center mt-4">
                <a href="/signup" class="text-muted small">
                    <i class="bi bi-arrow-left"></i> Start over with a different email
                </a>
            </div>
        </div>
    </div>
</div>

<style>
.card {
    border-radius: 12px;
}
</style>

<script>
function resendEmail() {
    const btn = document.getElementById('resendBtn');
    const msg = document.getElementById('resendMessage');
    const email = <?= json_encode($email) ?>;

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';
    msg.innerHTML = '';

    fetch('/signup/resend', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'email=' + encodeURIComponent(email)
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = 'Resend Verification Email';

        if (data.success) {
            msg.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Email sent! Check your inbox.</span>';
        } else {
            msg.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-circle"></i> ' + (data.error || 'Failed to send') + '</span>';
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = 'Resend Verification Email';
        msg.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-circle"></i> An error occurred</span>';
    });
}
</script>
