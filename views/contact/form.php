<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <h1 class="mb-4">Contact Support</h1>

            <?php if ($success ?? false): ?>
                <div class="alert alert-success">
                    <h4 class="alert-heading">Thank you for contacting us!</h4>
                    <p>Your message has been received and our support team will review it shortly.</p>
                    <hr>
                    <p class="mb-0">We typically respond within 24-48 hours. If your issue is urgent, please indicate that in your message.</p>
                </div>
                <a href="/" class="btn btn-primary">Return to Home</a>
            <?php else: ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <h4 class="alert-heading">Please fix the following errors:</h4>
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?= h($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <form id="contactForm" method="POST" action="/contact/submit">
                            <?php
                            // Include CSRF token if available
                            if (isset($csrf) && is_array($csrf)):
                                foreach ($csrf as $name => $value): ?>
                                    <input type="hidden" name="<?= h($name) ?>" value="<?= h($value) ?>">
                                <?php endforeach;
                            endif;
                            ?>

                            <div class="mb-3">
                                <label for="name" class="form-label">Your Name <span class="text-danger">*</span></label>
                                <input type="text"
                                       class="form-control"
                                       id="name"
                                       name="name"
                                       value="<?= h($data['name'] ?? $_SESSION['member']['first_name'] ?? '') ?>"
                                       required>
                            </div>

                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                <input type="email"
                                       class="form-control"
                                       id="email"
                                       name="email"
                                       value="<?= h($data['email'] ?? $_SESSION['member']['email'] ?? '') ?>"
                                       required>
                                <small class="form-text text-muted">We'll use this to respond to your inquiry</small>
                            </div>

                            <div class="mb-3">
                                <label for="category" class="form-label">Category</label>
                                <select class="form-select" id="category" name="category">
                                    <option value="general" <?= ($data['category'] ?? '') === 'general' ? 'selected' : '' ?>>General Inquiry</option>
                                    <option value="support" <?= ($data['category'] ?? '') === 'support' ? 'selected' : '' ?>>Technical Support</option>
                                    <option value="billing" <?= ($data['category'] ?? '') === 'billing' ? 'selected' : '' ?>>Billing Question</option>
                                    <option value="feature" <?= ($data['category'] ?? '') === 'feature' ? 'selected' : '' ?>>Feature Request</option>
                                    <option value="bug" <?= ($data['category'] ?? '') === 'bug' ? 'selected' : '' ?>>Bug Report</option>
                                    <option value="other" <?= ($data['category'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="subject" class="form-label">Subject <span class="text-danger">*</span></label>
                                <input type="text"
                                       class="form-control"
                                       id="subject"
                                       name="subject"
                                       value="<?= h($data['subject'] ?? '') ?>"
                                       required>
                            </div>

                            <div class="mb-3">
                                <label for="message" class="form-label">Message <span class="text-danger">*</span></label>
                                <textarea class="form-control"
                                          id="message"
                                          name="message"
                                          rows="8"
                                          required><?= h($data['message'] ?? '') ?></textarea>
                                <small class="form-text text-muted">Please provide as much detail as possible</small>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <span class="btn-text"><i class="bi bi-envelope-fill"></i> Send Message</span>
                                    <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-2"></span>Sending...</span>
                                </button>
                                <a href="/" class="btn btn-outline-secondary">Cancel</a>
                            </div>
                        </form>

                        <!-- Success message shown after async submission -->
                        <div id="successMessage" class="d-none">
                            <div class="alert alert-success">
                                <h4 class="alert-heading"><i class="bi bi-check-circle-fill"></i> Thank you!</h4>
                                <p>Your message has been received and is being processed by our AI assistant.</p>
                                <hr>
                                <p class="mb-0">We'll route it to the right team and respond within 24-48 hours.</p>
                            </div>
                            <a href="/" class="btn btn-primary">Return to Home</a>
                        </div>

                        <!-- Error message shown on failure -->
                        <div id="errorMessage" class="d-none">
                            <div class="alert alert-warning">
                                <h4 class="alert-heading"><i class="bi bi-exclamation-triangle-fill"></i> Submission Queued</h4>
                                <p>We received your message but encountered a minor issue processing it immediately.</p>
                                <hr>
                                <p class="mb-0">Don't worry - your message is safe and our team will review it shortly.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 text-center text-muted">
                    <p><i class="bi bi-info-circle"></i> Need immediate assistance? Check our <a href="/help">Help Center</a> for quick answers.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
/**
 * Contact Form - AI Classification Integration
 *
 * Submits the form to the contact-classifier tenant app for AI-powered
 * classification and routing. Falls back to standard form submission if
 * the tenant app is unavailable.
 */
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('contactForm');
    const submitBtn = document.getElementById('submitBtn');
    const successMessage = document.getElementById('successMessage');
    const errorMessage = document.getElementById('errorMessage');

    // Tenant app endpoint - dev workspace hosts the contact classifier
    const CLASSIFIER_ENDPOINT = 'https://dev.myctobot.ai/app/contact-classifier/run';

    // Check if async submission is enabled (can be disabled via data attribute)
    const asyncEnabled = form?.dataset.asyncEnabled !== 'false';

    if (!form || !asyncEnabled) {
        return; // Use standard form submission
    }

    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        // Show loading state
        submitBtn.disabled = true;
        submitBtn.querySelector('.btn-text')?.classList.add('d-none');
        submitBtn.querySelector('.btn-loading')?.classList.remove('d-none');

        // Collect form data
        const formData = new FormData(form);
        const data = {
            name: formData.get('name'),
            email: formData.get('email'),
            category: formData.get('category'),
            subject: formData.get('subject'),
            message: formData.get('message'),
            referrer: document.referrer || window.location.href,
            user_agent: navigator.userAgent
        };

        try {
            // Submit to tenant app (cross-origin)
            const response = await fetch(CLASSIFIER_ENDPOINT, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success || response.ok) {
                // Show success, hide form
                form.classList.add('d-none');
                successMessage.classList.remove('d-none');
            } else {
                // Show warning but don't block - message was likely saved
                form.classList.add('d-none');
                errorMessage.classList.remove('d-none');
                console.warn('Contact classifier response:', result);
            }

        } catch (error) {
            console.error('Contact submission error:', error);

            // On network error, fall back to standard form submission
            // This ensures the message is captured even if tenant app is down
            form.dataset.asyncEnabled = 'false';
            form.submit();
        }
    });
});
</script>
