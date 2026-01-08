<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">
            <div class="text-center mb-4">
                <h1 class="h2">Get Started with MyCTOBot</h1>
                <p class="text-muted">Create your team workspace in seconds</p>
            </div>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="/signup/dosignup" id="signupForm">
                        <?php
                        if (isset($csrf) && is_array($csrf)):
                            foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                            <?php endforeach;
                        endif;
                        ?>

                        <div class="mb-3">
                            <label for="business_name" class="form-label">Business / Team Name</label>
                            <input type="text"
                                   class="form-control"
                                   id="business_name"
                                   name="business_name"
                                   value="<?= htmlspecialchars($data['business_name'] ?? '') ?>"
                                   required
                                   maxlength="100"
                                   placeholder="Acme Corp">
                            <div class="form-text">Your company or team name</div>
                        </div>

                        <div class="mb-3">
                            <label for="subdomain" class="form-label">Choose Your URL</label>
                            <div class="input-group">
                                <input type="text"
                                       class="form-control"
                                       id="subdomain"
                                       name="subdomain"
                                       value="<?= htmlspecialchars($data['subdomain'] ?? '') ?>"
                                       required
                                       minlength="3"
                                       maxlength="32"
                                       pattern="[a-z0-9]([a-z0-9-]*[a-z0-9])?"
                                       placeholder="acme"
                                       autocomplete="off">
                                <span class="input-group-text">.myctobot.ai</span>
                            </div>
                            <div id="subdomain-feedback" class="form-text">
                                3-32 characters, letters, numbers, and hyphens only
                            </div>
                        </div>

                        <hr class="my-4">

                        <div class="mb-3">
                            <label for="email" class="form-label">Admin Email</label>
                            <input type="email"
                                   class="form-control"
                                   id="email"
                                   name="email"
                                   value="<?= htmlspecialchars($data['email'] ?? '') ?>"
                                   required
                                   placeholder="you@company.com">
                            <div class="form-text">You'll use this to log in</div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password"
                                       class="form-control"
                                       id="password"
                                       name="password"
                                       required
                                       minlength="6"
                                       placeholder="Create password">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="password_confirm" class="form-label">Confirm</label>
                                <input type="password"
                                       class="form-control"
                                       id="password_confirm"
                                       name="password_confirm"
                                       required
                                       minlength="6"
                                       placeholder="Confirm password">
                            </div>
                        </div>
                        <div class="form-text mb-3">At least 6 characters</div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                Create Your Workspace
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="text-center mt-4">
                <p class="text-muted mb-2">Already have an account?</p>
                <p class="small text-muted">
                    Log in at <strong>yourteam.myctobot.ai</strong>
                </p>
            </div>

            <div class="text-center mt-3 text-muted small">
                By signing up, you agree to our
                <a href="/terms">Terms of Service</a> and
                <a href="/privacy">Privacy Policy</a>
            </div>
        </div>
    </div>
</div>

<style>
.card {
    border-radius: 12px;
}
.form-control:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
}
#subdomain-feedback.text-success {
    color: #198754 !important;
}
#subdomain-feedback.text-danger {
    color: #dc3545 !important;
}
.subdomain-checking {
    color: #6c757d;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const subdomainInput = document.getElementById('subdomain');
    const feedback = document.getElementById('subdomain-feedback');
    const submitBtn = document.getElementById('submitBtn');
    const businessNameInput = document.getElementById('business_name');
    let checkTimeout = null;
    let isSubdomainValid = false;
    let userTouchedSubdomain = false;  // Track if user manually edited subdomain

    // Mark subdomain as touched when user interacts with it
    subdomainInput.addEventListener('focus', function() {
        userTouchedSubdomain = true;
    });

    // Auto-suggest subdomain from business name (only if user hasn't touched it)
    // Use setTimeout to let any pending form fills complete first
    businessNameInput.addEventListener('blur', function() {
        const businessValue = this.value;
        setTimeout(function() {
            // Double-check subdomain is still empty after delay
            if (!userTouchedSubdomain && !subdomainInput.value && businessValue) {
                const suggested = businessValue
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-|-$/g, '')
                    .substring(0, 32);
                if (suggested.length >= 3) {
                    subdomainInput.value = suggested;
                    checkSubdomain(suggested);
                }
            }
        }, 100);  // Small delay to handle race conditions
    });

    // Check subdomain as user types
    subdomainInput.addEventListener('input', function() {
        userTouchedSubdomain = true;  // User is typing, definitely touched
        const value = this.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
        this.value = value;

        if (checkTimeout) {
            clearTimeout(checkTimeout);
        }

        if (value.length < 3) {
            feedback.className = 'form-text text-muted';
            feedback.textContent = '3-32 characters, letters, numbers, and hyphens only';
            isSubdomainValid = false;
            updateSubmitButton();
            return;
        }

        feedback.className = 'form-text subdomain-checking';
        feedback.textContent = 'Checking availability...';

        checkTimeout = setTimeout(function() {
            checkSubdomain(value);
        }, 500);
    });

    function checkSubdomain(subdomain) {
        fetch('/signup/checksubdomain?subdomain=' + encodeURIComponent(subdomain))
            .then(response => response.json())
            .then(data => {
                if (data.available) {
                    feedback.className = 'form-text text-success';
                    feedback.innerHTML = '<i class="bi bi-check-circle"></i> ' +
                        data.url + ' is available!';
                    isSubdomainValid = true;
                } else {
                    feedback.className = 'form-text text-danger';
                    feedback.innerHTML = '<i class="bi bi-x-circle"></i> ' +
                        (data.error || 'Not available');
                    isSubdomainValid = false;
                }
                updateSubmitButton();
            })
            .catch(function() {
                feedback.className = 'form-text text-muted';
                feedback.textContent = 'Unable to check - try again';
                isSubdomainValid = false;
                updateSubmitButton();
            });
    }

    function updateSubmitButton() {
        // We don't disable the button, server will validate anyway
        // This is just visual feedback
    }

    // Form validation
    document.getElementById('signupForm').addEventListener('submit', function(e) {
        const password = document.getElementById('password').value;
        const confirm = document.getElementById('password_confirm').value;

        if (password !== confirm) {
            e.preventDefault();
            alert('Passwords do not match');
            return false;
        }

        if (!isSubdomainValid) {
            e.preventDefault();
            alert('Please choose an available subdomain');
            return false;
        }

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating workspace...';
    });
});
</script>
