<!-- Hero Section -->
<div class="bg-primary text-white py-5">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6">
                <h1 class="display-4 fw-bold">MyCTOBot</h1>
                <p class="lead">AI-powered daily sprint digests for Jira. Get intelligent prioritization and actionable insights delivered to your inbox every morning.</p>
                <div class="d-grid gap-2 d-md-flex">
                    <?php if (!$isLoggedIn): ?>
                        <?php if (!empty($googleEnabled)): ?>
                        <a href="/auth/google" class="btn btn-light btn-lg">
                            <i class="bi bi-google"></i> Sign in with Google
                        </a>
                        <?php else: ?>
                        <a href="/auth/login" class="btn btn-light btn-lg">Get Started</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="/dashboard" class="btn btn-light btn-lg">Go to Dashboard</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-6 text-center">
                <i class="bi bi-kanban-fill" style="font-size: 12rem; opacity: 0.3;"></i>
            </div>
        </div>
    </div>
</div>

<!-- Features Section -->
<div class="py-5">
    <div class="container">
        <h2 class="text-center mb-5">How It Works</h2>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-link-45deg text-primary" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">1. Connect Your Jira</h4>
                        <p class="card-text">Securely link your Atlassian account using OAuth. No API keys to manage.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-kanban text-primary" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">2. Select Your Boards</h4>
                        <p class="card-text">Choose which Jira boards to track and customize your digest schedule.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-envelope-check text-primary" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">3. Get Daily Insights</h4>
                        <p class="card-text">Receive AI-powered priority analysis and recommendations every morning.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Benefits Section -->
<div class="bg-light py-5">
    <div class="container">
        <h2 class="text-center mb-5">Why MyCTOBot?</h2>
        <div class="row g-4">
            <div class="col-md-6">
                <div class="d-flex">
                    <div class="flex-shrink-0">
                        <i class="bi bi-robot text-primary" style="font-size: 2rem;"></i>
                    </div>
                    <div class="ms-3">
                        <h5>AI-Powered Analysis</h5>
                        <p class="text-muted">Claude AI analyzes your sprint backlog to identify customer-impacting priorities and potential blockers.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="d-flex">
                    <div class="flex-shrink-0">
                        <i class="bi bi-clock-history text-primary" style="font-size: 2rem;"></i>
                    </div>
                    <div class="ms-3">
                        <h5>Save Time Every Day</h5>
                        <p class="text-muted">No more morning stand-up prep. Get a curated summary of what matters most.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="d-flex">
                    <div class="flex-shrink-0">
                        <i class="bi bi-shield-check text-primary" style="font-size: 2rem;"></i>
                    </div>
                    <div class="ms-3">
                        <h5>Secure OAuth Integration</h5>
                        <p class="text-muted">Your Jira data stays safe with industry-standard OAuth 2.0 authentication.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="d-flex">
                    <div class="flex-shrink-0">
                        <i class="bi bi-calendar-check text-primary" style="font-size: 2rem;"></i>
                    </div>
                    <div class="ms-3">
                        <h5>Customizable Schedule</h5>
                        <p class="text-muted">Set your preferred digest time for each board. Get insights when you need them.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sample Digest Section -->
<div class="py-5">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6">
                <h2 class="mb-4">Sample Daily Digest</h2>
                <p class="lead">Every morning, you'll receive an email like this with prioritized action items and risk alerts.</p>
                <ul class="list-unstyled">
                    <li class="mb-2"><i class="bi bi-check-circle text-success"></i> Customer-first prioritization</li>
                    <li class="mb-2"><i class="bi bi-check-circle text-success"></i> Blocked ticket identification</li>
                    <li class="mb-2"><i class="bi bi-check-circle text-success"></i> Risk alerts and recommendations</li>
                    <li class="mb-2"><i class="bi bi-check-circle text-success"></i> Direct links to Jira tickets</li>
                </ul>
            </div>
            <div class="col-lg-6">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <i class="bi bi-envelope"></i> [PROJ] Daily Sprint Digest
                    </div>
                    <div class="card-body" style="font-family: monospace; font-size: 0.85rem;">
                        <p><strong>Priority 1: Customer-Impacting</strong></p>
                        <ul class="small">
                            <li>PROJ-123: Fix login timeout issue</li>
                            <li>PROJ-145: Payment gateway error handling</li>
                        </ul>
                        <p class="mt-3"><strong>Blocked Tickets</strong></p>
                        <ul class="small text-danger">
                            <li>PROJ-156: Awaiting API documentation</li>
                        </ul>
                        <p class="mt-3"><strong>Recommendations</strong></p>
                        <p class="small text-muted">Consider pairing on PROJ-123 to expedite resolution...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- CTA Section -->
<div class="bg-primary text-white py-5">
    <div class="container text-center">
        <h2 class="mb-4">Start Getting Smarter Sprint Insights Today</h2>
        <p class="lead mb-4">Free to use. Connect your Jira in under 2 minutes.</p>
        <?php if (!$isLoggedIn): ?>
            <?php if (!empty($googleEnabled)): ?>
            <a href="/auth/google" class="btn btn-light btn-lg">
                <i class="bi bi-google"></i> Sign in with Google
            </a>
            <?php else: ?>
            <a href="/auth/register" class="btn btn-light btn-lg">Get Started Free</a>
            <?php endif; ?>
        <?php else: ?>
            <a href="/dashboard" class="btn btn-light btn-lg">Go to Dashboard</a>
        <?php endif; ?>
    </div>
</div>
