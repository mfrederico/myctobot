<div class="signup-page">
    <!-- Hero Section -->
    <div class="container-fluid bg-gradient-primary py-4">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6 text-white mb-4 mb-lg-0">
                    <h1 class="display-5 fw-bold mb-3">Your AI-Powered Development Team</h1>
                    <p class="lead mb-0">MyCTOBot connects to your Jira and GitHub to automatically implement tickets, create pull requests, and ship code while you sleep.</p>
                </div>
                <div class="col-lg-6">
                    <div class="signup-card card shadow-lg border-0">
                        <div class="card-body p-4">
                            <h4 class="card-title text-center mb-4">Get Started Free</h4>

                            <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <ul class="mb-0 small">
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
                                    <label for="business_name" class="form-label small fw-semibold">Business / Team Name</label>
                                    <input type="text" class="form-control" id="business_name" name="business_name"
                                           value="<?= htmlspecialchars($data['business_name'] ?? '') ?>"
                                           required maxlength="100" placeholder="Acme Corp">
                                </div>

                                <div class="mb-3">
                                    <label for="email" class="form-label small fw-semibold">Email</label>
                                    <input type="email" class="form-control" id="email" name="email"
                                           value="<?= htmlspecialchars($data['email'] ?? '') ?>"
                                           required placeholder="you@company.com">
                                </div>

                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <label for="password" class="form-label small fw-semibold">Password</label>
                                        <input type="password" class="form-control" id="password" name="password"
                                               required minlength="6" placeholder="6+ characters">
                                    </div>
                                    <div class="col-6 mb-3">
                                        <label for="password_confirm" class="form-label small fw-semibold">Confirm</label>
                                        <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                                               required minlength="6" placeholder="Confirm">
                                    </div>
                                </div>

                                <div class="d-grid mb-3">
                                    <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                        Create Your Workspace
                                    </button>
                                </div>

                                <p class="text-center text-muted small mb-0">
                                    Already have an account? Log in at <strong>yourteam.myctobot.ai</strong>
                                </p>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Features Section -->
    <div class="container py-5">
        <div class="text-center mb-5">
            <h2 class="fw-bold">How MyCTOBot Works</h2>
            <p class="text-muted">Connect your tools. Assign tickets. Watch the magic happen.</p>
        </div>

        <div class="row g-4">
            <!-- Feature 1: Jira Integration -->
            <div class="col-lg-4">
                <div class="feature-card h-100">
                    <div class="feature-icon bg-primary-subtle text-primary">
                        <i class="bi bi-kanban"></i>
                    </div>
                    <h5 class="fw-bold">Jira Integration</h5>
                    <p class="text-muted small">Label any ticket with <code>ai-dev</code> and MyCTOBot picks it up automatically. It reads the requirements, understands the context, and gets to work.</p>
                    <div class="screenshot-wrapper">
                        <img src="/media/screenshots/jira-ticket.svg" alt="Jira ticket with ai-dev label" class="screenshot" onerror="this.parentElement.innerHTML='<div class=\'screenshot-placeholder\'><i class=\'bi bi-image\'></i><span>Jira Board View</span></div>'">
                    </div>
                </div>
            </div>

            <!-- Feature 2: AI Implementation -->
            <div class="col-lg-4">
                <div class="feature-card h-100">
                    <div class="feature-icon bg-success-subtle text-success">
                        <i class="bi bi-robot"></i>
                    </div>
                    <h5 class="fw-bold">AI Implements Your Tickets</h5>
                    <p class="text-muted small">Claude analyzes your codebase, writes the implementation, runs tests, and commits clean code. It even posts progress updates back to Jira.</p>
                    <div class="screenshot-wrapper">
                        <img src="/media/screenshots/claude-working.svg" alt="Claude implementing code" class="screenshot" onerror="this.parentElement.innerHTML='<div class=\'screenshot-placeholder\'><i class=\'bi bi-terminal\'></i><span>AI at Work</span></div>'">
                    </div>
                </div>
            </div>

            <!-- Feature 3: GitHub PRs -->
            <div class="col-lg-4">
                <div class="feature-card h-100">
                    <div class="feature-icon bg-warning-subtle text-warning">
                        <i class="bi bi-git"></i>
                    </div>
                    <h5 class="fw-bold">Automatic Pull Requests</h5>
                    <p class="text-muted small">When implementation is complete, MyCTOBot creates a GitHub PR with a detailed summary. Perfect for Shopify themes, apps, and any codebase.</p>
                    <div class="screenshot-wrapper">
                        <img src="/media/screenshots/github-pr.svg" alt="GitHub pull request" class="screenshot" onerror="this.parentElement.innerHTML='<div class=\'screenshot-placeholder\'><i class=\'bi bi-github\'></i><span>Pull Request</span></div>'">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Use Cases Section -->
    <div class="bg-light py-5">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6 mb-4 mb-lg-0">
                    <div class="screenshot-wrapper-lg">
                        <img src="/media/screenshots/shopify-theme.svg" alt="Shopify theme code" class="screenshot-lg" onerror="this.parentElement.innerHTML='<div class=\'screenshot-placeholder-lg\'><i class=\'bi bi-shop\'></i><span>Shopify Theme Development</span></div>'">
                    </div>
                </div>
                <div class="col-lg-6">
                    <h3 class="fw-bold mb-3">Built for Shopify Developers</h3>
                    <p class="text-muted">MyCTOBot understands Liquid templates, theme architecture, and Shopify best practices. It can:</p>
                    <ul class="feature-list">
                        <li><i class="bi bi-check-circle-fill text-success"></i> Implement new theme sections and blocks</li>
                        <li><i class="bi bi-check-circle-fill text-success"></i> Fix CSS and JavaScript bugs</li>
                        <li><i class="bi bi-check-circle-fill text-success"></i> Add product filtering and search features</li>
                        <li><i class="bi bi-check-circle-fill text-success"></i> Optimize performance and Core Web Vitals</li>
                        <li><i class="bi bi-check-circle-fill text-success"></i> Create custom app blocks and metafield displays</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Workflow Section -->
    <div class="container py-5">
        <div class="text-center mb-5">
            <h2 class="fw-bold">Simple 3-Step Workflow</h2>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="workflow-step">
                    <div class="step-number">1</div>
                    <h5 class="fw-bold">Connect Your Tools</h5>
                    <p class="text-muted small mb-0">Link your Jira and GitHub accounts. MyCTOBot securely accesses only what it needs.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="workflow-step">
                    <div class="step-number">2</div>
                    <h5 class="fw-bold">Label Your Tickets</h5>
                    <p class="text-muted small mb-0">Add the <code>ai-dev</code> label to any Jira ticket you want implemented.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="workflow-step">
                    <div class="step-number">3</div>
                    <h5 class="fw-bold">Review & Merge</h5>
                    <p class="text-muted small mb-0">Get a PR with clean code, tests, and documentation. Review and merge when ready.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Social Proof -->
    <div class="bg-dark text-white py-5">
        <div class="container">
            <div class="row text-center">
                <div class="col-md-4 mb-4 mb-md-0">
                    <div class="display-4 fw-bold text-primary">500+</div>
                    <p class="text-white-50 mb-0">Tickets Completed</p>
                </div>
                <div class="col-md-4 mb-4 mb-md-0">
                    <div class="display-4 fw-bold text-success">98%</div>
                    <p class="text-white-50 mb-0">PR Acceptance Rate</p>
                </div>
                <div class="col-md-4">
                    <div class="display-4 fw-bold text-warning">24/7</div>
                    <p class="text-white-50 mb-0">Always Working</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Final CTA -->
    <div class="container py-5">
        <div class="text-center">
            <h3 class="fw-bold mb-3">Ready to 10x Your Development Output?</h3>
            <p class="text-muted mb-4">Start your free workspace and connect your first board in minutes.</p>
            <a href="#" class="btn btn-primary btn-lg" onclick="document.querySelector('.signup-card').scrollIntoView({behavior: 'smooth'}); return false;">
                Get Started Now
            </a>
        </div>
    </div>

    <!-- Terms -->
    <div class="text-center pb-4 text-muted small">
        By signing up, you agree to our
        <a href="/legal/terms">Terms of Service</a> and
        <a href="/legal/privacy">Privacy Policy</a>
    </div>
</div>

<style>
/* Hero gradient */
.bg-gradient-primary {
    background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 50%, #084298 100%);
}

/* Signup card */
.signup-card {
    border-radius: 16px;
    background: white;
}
.signup-card .form-control {
    border-radius: 8px;
}
.signup-card .form-control:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
}
.signup-card .btn-primary {
    border-radius: 8px;
    padding: 12px;
    font-weight: 600;
}

/* Feature cards */
.feature-card {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    transition: transform 0.2s, box-shadow 0.2s;
}
.feature-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 30px rgba(0,0,0,0.12);
}
.feature-icon {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    margin-bottom: 16px;
}

/* Screenshots */
.screenshot-wrapper {
    margin-top: 16px;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 8px 30px rgba(0,0,0,0.15);
    background: #f8f9fa;
}
.screenshot {
    width: 100%;
    height: auto;
    display: block;
}
.screenshot-placeholder {
    padding: 40px 20px;
    text-align: center;
    color: #6c757d;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}
.screenshot-placeholder i {
    font-size: 32px;
    display: block;
    margin-bottom: 8px;
    opacity: 0.5;
}
.screenshot-placeholder span {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.screenshot-wrapper-lg {
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    transform: perspective(1000px) rotateY(-5deg);
    transition: transform 0.3s;
}
.screenshot-wrapper-lg:hover {
    transform: perspective(1000px) rotateY(0deg);
}
.screenshot-lg {
    width: 100%;
    height: auto;
    display: block;
}
.screenshot-placeholder-lg {
    padding: 80px 40px;
    text-align: center;
    color: #6c757d;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}
.screenshot-placeholder-lg i {
    font-size: 48px;
    display: block;
    margin-bottom: 12px;
    opacity: 0.5;
}
.screenshot-placeholder-lg span {
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 1px;
}

/* Feature list */
.feature-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.feature-list li {
    padding: 8px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Workflow steps */
.workflow-step {
    text-align: center;
    padding: 24px;
}
.step-number {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    font-weight: bold;
    margin: 0 auto 16px;
}

/* Code styling */
code {
    background: #e9ecef;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.85em;
    color: #d63384;
}

/* Responsive */
@media (max-width: 991px) {
    .signup-card {
        margin-top: 20px;
    }
    .screenshot-wrapper-lg {
        transform: none;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const submitBtn = document.getElementById('submitBtn');

    document.getElementById('signupForm').addEventListener('submit', function(e) {
        const password = document.getElementById('password').value;
        const confirm = document.getElementById('password_confirm').value;

        if (password !== confirm) {
            e.preventDefault();
            alert('Passwords do not match');
            return false;
        }

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating workspace...';
    });
});
</script>
