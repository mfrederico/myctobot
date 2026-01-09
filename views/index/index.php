<?php
// Centralized pricing from config
$proMonthlyPrice = \app\services\SubscriptionService::getProMonthlyPrice();
$proYearlyPrice = \app\services\SubscriptionService::getProYearlyPrice();
?>
<!-- Hero Section -->
<div class="bg-primary text-white py-5">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6">
                <h1 class="display-4 fw-bold">MyCTOBot</h1>
                <p class="lead">AI-powered daily sprint digests for Jira. Get intelligent prioritization and actionable insights delivered to your inbox every morning.</p>
                <div class="d-grid gap-2 d-md-flex">
                    <?php if (\app\TenantResolver::isDefault()): ?>
                        <a href="/signup" class="btn btn-light btn-lg">
                            <i class="bi bi-building"></i> Create Your Workspace
                        </a>
                        <a href="#" class="btn btn-outline-light btn-lg" data-bs-toggle="modal" data-bs-target="#existingTeamModal">
                            Already have a team?
                        </a>
                    <?php elseif (!$isLoggedIn): ?>
                        <a href="/auth/login" class="btn btn-light btn-lg">Sign In</a>
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

<!-- Go Pro Section -->
<div class="py-5">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-5">
                <span class="badge bg-warning text-dark mb-3">PRO</span>
                <h2 class="mb-4">Unlock Advanced AI Analysis</h2>
                <p class="lead">Engineering managers save 30+ minutes daily with Pro features that align AI recommendations to your team's goals.</p>

                <div class="d-flex align-items-center mb-4">
                    <div class="text-center me-4">
                        <div class="display-6 fw-bold text-primary">20x</div>
                        <small class="text-muted">ROI vs a CTO</small>
                    </div>
                    <div class="text-center me-4">
                        <div class="display-6 fw-bold text-primary">30</div>
                        <small class="text-muted">min/day saved</small>
                    </div>
                    <div class="text-center">
                        <div class="display-6 fw-bold text-primary">$<?= number_format($proMonthlyPrice) ?></div>
                        <small class="text-muted">/month</small>
                    </div>
                </div>

                <a href="/settings/subscription" class="btn btn-warning btn-lg">
                    <i class="bi bi-gift"></i> Try 1 Sprint Free
                </a>
                <p class="text-muted small mt-2 mb-0">14-day free trial. No charge until it ends.</p>
            </div>
            <div class="col-lg-7">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="card h-100 border-warning">
                            <div class="card-body">
                                <i class="bi bi-sliders text-warning" style="font-size: 1.5rem;"></i>
                                <h6 class="card-title mt-2">Priority Weights</h6>
                                <p class="card-text small text-muted">Tune AI recommendations: quick wins, tech debt, customer focus, risk mitigation.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100 border-warning">
                            <div class="card-body">
                                <i class="bi bi-bullseye text-warning" style="font-size: 1.5rem;"></i>
                                <h6 class="card-title mt-2">Engineering Goals</h6>
                                <p class="card-text small text-muted">Set velocity targets, tech debt allocation, and quality gates.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100 border-warning">
                            <div class="card-body">
                                <i class="bi bi-search text-warning" style="font-size: 1.5rem;"></i>
                                <h6 class="card-title mt-2">Clarity Analysis</h6>
                                <p class="card-text small text-muted">AI identifies vague tickets and suggests clarifying questions.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100 border-warning">
                            <div class="card-body">
                                <i class="bi bi-infinity text-warning" style="font-size: 1.5rem;"></i>
                                <h6 class="card-title mt-2">Unlimited Analysis</h6>
                                <p class="card-text small text-muted">Run on-demand analysis anytime. Up to 5 boards.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Cost Savings Section -->
<div class="py-5" style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);">
    <div class="container">
        <h2 class="text-center mb-2">The Math is Simple</h2>
        <p class="text-center text-secondary mb-5">Get CTO-level sprint insights at a fraction of the cost</p>

        <div class="row justify-content-center g-4">
            <!-- CTO Cost -->
            <div class="col-md-4">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-body text-center">
                        <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3" style="width: 70px; height: 70px;">
                            <i class="bi bi-person-badge text-secondary" style="font-size: 2rem;"></i>
                        </div>
                        <h5 class="card-title">Hire a CTO</h5>
                        <div class="display-6 fw-bold text-dark my-3">$275,000</div>
                        <p class="text-muted small mb-3">per year (industry average)</p>
                        <ul class="list-unstyled text-start small text-muted mb-0">
                            <li class="mb-2"><i class="bi bi-dash-circle opacity-50"></i> 6+ month hiring process</li>
                            <li class="mb-2"><i class="bi bi-dash-circle opacity-50"></i> Benefits add 30%+ to cost</li>
                            <li class="mb-2"><i class="bi bi-dash-circle opacity-50"></i> Single point of failure</li>
                            <li><i class="bi bi-dash-circle opacity-50"></i> Limited to one perspective</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Engineering Manager Cost -->
            <div class="col-md-4">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-body text-center">
                        <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3" style="width: 70px; height: 70px;">
                            <i class="bi bi-person-gear text-secondary" style="font-size: 2rem;"></i>
                        </div>
                        <h5 class="card-title">Hire an EM</h5>
                        <div class="display-6 fw-bold text-dark my-3">$185,000</div>
                        <p class="text-muted small mb-3">per year (industry average)</p>
                        <ul class="list-unstyled text-start small text-muted mb-0">
                            <li class="mb-2"><i class="bi bi-dash-circle opacity-50"></i> 3+ month hiring process</li>
                            <li class="mb-2"><i class="bi bi-dash-circle opacity-50"></i> Benefits add 30%+ to cost</li>
                            <li class="mb-2"><i class="bi bi-dash-circle opacity-50"></i> Manual analysis takes hours</li>
                            <li><i class="bi bi-dash-circle opacity-50"></i> No coverage on days off</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- MyCTOBot Cost -->
            <div class="col-md-4">
                <div class="card h-100 shadow border-0 position-relative overflow-hidden">
                    <div class="position-absolute top-0 start-0 end-0" style="height: 4px; background: linear-gradient(90deg, #0d6efd, #0dcaf0);"></div>
                    <span class="position-absolute top-0 end-0 badge bg-primary m-2">Best Value</span>
                    <div class="card-body text-center">
                        <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width: 70px; height: 70px;">
                            <i class="bi bi-robot text-primary" style="font-size: 2rem;"></i>
                        </div>
                        <h5 class="card-title">MyCTOBot Pro</h5>
                        <div class="display-6 fw-bold text-primary my-3">$<?= number_format($proYearlyPrice) ?></div>
                        <p class="text-muted small mb-3">per year ($<?= number_format($proMonthlyPrice) ?>/month)</p>
                        <ul class="list-unstyled text-start small mb-0">
                            <li class="mb-2"><i class="bi bi-check-circle-fill text-primary"></i> Start in 2 minutes</li>
                            <li class="mb-2"><i class="bi bi-check-circle-fill text-primary"></i> No hiring, no benefits</li>
                            <li class="mb-2"><i class="bi bi-check-circle-fill text-primary"></i> AI analysis every day</li>
                            <li><i class="bi bi-check-circle-fill text-primary"></i> Never takes a day off</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Savings Highlight -->
        <div class="row mt-5">
            <div class="col-12 text-center">
                <div class="card border-0 shadow-sm d-inline-block">
                    <div class="card-body px-5 py-4">
                        <p class="mb-1 text-muted small text-uppercase fw-semibold">Annual savings vs. hiring an Engineering Manager</p>
                        <span class="display-5 fw-bold text-primary">$184,412</span>
                        <div class="mt-2">
                            <span class="badge bg-primary bg-opacity-10 text-primary fs-6 fw-normal px-3 py-2">99.7% Cost Reduction</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <p class="text-center text-muted mt-4 small mb-0">
            <i class="bi bi-info-circle"></i> Salary figures based on 2024-2025 US averages from Glassdoor, Comparably, and Built In
        </p>
    </div>
</div>

<!-- Sample Digest Section -->
<div class="bg-light py-5">
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
        <p class="lead mb-4">Create your team workspace in under 2 minutes.</p>
        <?php if (\app\TenantResolver::isDefault()): ?>
            <a href="/signup" class="btn btn-light btn-lg">
                <i class="bi bi-building"></i> Create Your Workspace
            </a>
        <?php elseif (!$isLoggedIn): ?>
            <a href="/auth/login" class="btn btn-light btn-lg">Sign In</a>
        <?php else: ?>
            <a href="/dashboard" class="btn btn-light btn-lg">Go to Dashboard</a>
        <?php endif; ?>
    </div>
</div>

<!-- Existing Team Modal -->
<div class="modal fade" id="existingTeamModal" tabindex="-1" aria-labelledby="existingTeamModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="existingTeamModalLabel">Sign in to Your Team</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted">Enter your team's subdomain to go to your workspace:</p>
                <div class="input-group mb-3">
                    <input type="text" class="form-control" id="teamSubdomain" placeholder="yourteam" autofocus>
                    <span class="input-group-text">.myctobot.ai</span>
                </div>
                <div class="d-grid">
                    <button type="button" class="btn btn-primary" onclick="goToTeam()">
                        Go to Workspace
                    </button>
                </div>
            </div>
            <div class="modal-footer justify-content-center">
                <small class="text-muted">Don't have a team yet? <a href="/signup">Create one</a></small>
            </div>
        </div>
    </div>
</div>

<script>
function goToTeam() {
    const subdomain = document.getElementById('teamSubdomain').value.trim().toLowerCase();
    if (subdomain) {
        window.location.href = 'https://' + subdomain + '.myctobot.ai';
    }
}
document.getElementById('teamSubdomain')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') goToTeam();
});
</script>
