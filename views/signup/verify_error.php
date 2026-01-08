<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">
            <div class="text-center mb-4">
                <div class="mb-3">
                    <i class="bi bi-exclamation-triangle text-warning" style="font-size: 4rem;"></i>
                </div>
                <h1 class="h2"><?= htmlspecialchars($title) ?></h1>
            </div>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        <p class="mb-3 text-muted">
                            <?= htmlspecialchars($error) ?>
                        </p>
                    </div>

                    <?php if (!empty($canResend) && !empty($email)): ?>
                        <hr>
                        <div class="text-center">
                            <p class="text-muted mb-3">Would you like to request a new verification link?</p>
                            <button type="button" class="btn btn-primary" id="resendBtn" onclick="resendEmail()">
                                Send New Verification Email
                            </button>
                            <div id="resendMessage" class="mt-2 small"></div>
                        </div>
                    <?php endif; ?>

                    <div class="text-center mt-4">
                        <a href="/signup" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Start Over
                        </a>
                    </div>
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

<?php if (!empty($canResend) && !empty($email)): ?>
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
        btn.innerHTML = 'Send New Verification Email';

        if (data.success) {
            msg.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Email sent! Check your inbox.</span>';
            // Redirect to pending page after short delay
            setTimeout(function() {
                window.location.href = '/signup/pending?email=' + encodeURIComponent(email);
            }, 2000);
        } else {
            msg.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-circle"></i> ' + (data.error || 'Failed to send') + '</span>';
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = 'Send New Verification Email';
        msg.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-circle"></i> An error occurred</span>';
    });
}
</script>
<?php endif; ?>
