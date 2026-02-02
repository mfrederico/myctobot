<?php
// Simplified landing page - focused on one message
use app\services\PricingService;

$trialDays = $trialDays ?? PricingService::getTrialDays();
$pricing = PricingService::getPlatformPricing();
?>

<!-- Hero Section -->
<div class="bg-dark text-white py-5" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); min-height: 80vh; display: flex; align-items: center;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10 text-center">
                <h1 class="display-3 fw-bold mb-4">Turn ideas into<br><span class="text-primary">deployed software.</span></h1>
                <p class="lead mb-5 text-light opacity-75 mx-auto" style="max-width: 700px;">
                    MyCTOBot is an AI engineering team that takes your requirements and ships production-ready code — following your standards, not its own.
                </p>
                <div class="d-flex justify-content-center gap-3 flex-wrap">
                    <?php if (\app\WorkspaceResolver::isDefault()): ?>
                        <a href="/signup" class="btn btn-primary btn-lg px-5">
                            Start <?= $trialDays ?>-Day Trial
                        </a>
                        <a href="https://demo.myctobot.ai" class="btn btn-outline-light btn-lg px-4">
                            Try Live Demo
                        </a>
                    <?php elseif (!$isLoggedIn): ?>
                        <a href="/auth/login" class="btn btn-primary btn-lg px-5">Sign In</a>
                    <?php else: ?>
                        <a href="/dashboard" class="btn btn-primary btn-lg px-5">Go to Dashboard</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- How It Works Section -->
<div class="py-5" id="how-it-works">
    <div class="container">
        <h2 class="text-center mb-2">From concept to production in four steps.</h2>
        <p class="text-center text-muted mb-5">No engineering team required.</p>

        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <div class="text-center">
                    <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;">
                        <span class="display-6 fw-bold text-primary">1</span>
                    </div>
                    <h5>Describe</h5>
                    <p class="text-muted">
                        Tell MyCTOBot what you want to build. A feature, an app, a fix. Plain English.
                    </p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="text-center">
                    <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;">
                        <span class="display-6 fw-bold text-primary">2</span>
                    </div>
                    <h5>Architect</h5>
                    <p class="text-muted">
                        AI breaks it down — architecture, dependencies, risk analysis, implementation plan.
                    </p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="text-center">
                    <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;">
                        <span class="display-6 fw-bold text-primary">3</span>
                    </div>
                    <h5>Implement</h5>
                    <p class="text-muted">
                        Code gets written, reviewed, and tested. Following YOUR coding standards.
                    </p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="text-center">
                    <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;">
                        <span class="display-6 fw-bold text-primary">4</span>
                    </div>
                    <h5>Deploy</h5>
                    <p class="text-muted">
                        Approved changes ship automatically. You review. AI executes.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Differentiator Section -->
<div class="py-5 bg-light">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6">
                <h2 class="mb-4">AI without guardrails is chaos.</h2>
                <p class="lead text-muted mb-4">
                    Most AI coding tools generate whatever they want. MyCTOBot is different.
                </p>
                <p class="text-muted mb-4">
                    You define the rules — architecture patterns, security requirements, coding standards. The AI follows them religiously. Every time.
                </p>
                <p class="fw-semibold">Your standards. Your patterns. AI speed.</p>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 shadow-lg">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center mb-4">
                            <div class="rounded-circle bg-success d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                                <i class="bi bi-check-lg text-white fs-4"></i>
                            </div>
                            <div>
                                <h5 class="mb-0">AI that follows your rules</h5>
                                <small class="text-muted">Not generic best practices</small>
                            </div>
                        </div>
                        <ul class="list-unstyled mb-0">
                            <li class="mb-3 d-flex align-items-center">
                                <i class="bi bi-check-circle text-success me-3"></i>
                                <span>Uses your existing utilities and patterns</span>
                            </li>
                            <li class="mb-3 d-flex align-items-center">
                                <i class="bi bi-check-circle text-success me-3"></i>
                                <span>Enforces your security requirements</span>
                            </li>
                            <li class="mb-3 d-flex align-items-center">
                                <i class="bi bi-check-circle text-success me-3"></i>
                                <span>Matches your code style exactly</span>
                            </li>
                            <li class="d-flex align-items-center">
                                <i class="bi bi-check-circle text-success me-3"></i>
                                <span>Learns your architecture over time</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Social Proof Placeholder -->
<div class="py-4 bg-dark text-white">
    <div class="container text-center">
        <p class="mb-0 opacity-75">Trusted by technical leaders building production software with AI</p>
    </div>
</div>

<!-- Pricing Section -->
<div class="py-5" id="pricing">
    <div class="container">
        <h2 class="text-center mb-2">Less than a junior developer. Works 24/7.</h2>
        <p class="text-center text-muted mb-5">A PHP developer costs $7,000/month fully loaded. MyCTOBot ships code while they sleep.</p>

        <div class="row justify-content-center g-4">
            <!-- Starter -->
            <div class="col-lg-4 col-md-6">
                <div class="card h-100 shadow-sm">
                    <div class="card-body p-4">
                        <h5 class="text-muted mb-3">Starter</h5>
                        <div class="display-5 fw-bold mb-1"><?= $pricing['starter_monthly_formatted'] ?? '$749' ?></div>
                        <p class="text-muted mb-4">per month</p>
                        <ul class="list-unstyled mb-4">
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i><?= $pricing['starter_projects'] ?? 3 ?> projects</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i>AI code review</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Basic pipelines</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i>GitHub + Jira</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Email support</li>
                        </ul>
                        <?php if (\app\WorkspaceResolver::isDefault()): ?>
                        <a href="/signup" class="btn btn-outline-primary w-100">Start Trial</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Pro -->
            <div class="col-lg-4 col-md-6">
                <div class="card h-100 shadow border-primary">
                    <div class="card-header bg-primary text-white text-center py-2">
                        <small class="fw-semibold">MOST POPULAR</small>
                    </div>
                    <div class="card-body p-4">
                        <h5 class="text-primary mb-3">Pro</h5>
                        <div class="display-5 fw-bold mb-1"><?= $pricing['pro_monthly_formatted'] ?? '$1,999' ?></div>
                        <p class="text-muted mb-4">per month</p>
                        <ul class="list-unstyled mb-4">
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Unlimited projects</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i>AI implementation agents</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Full pipeline automation</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Advanced code review</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i>All integrations</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Priority support</li>
                        </ul>
                        <?php if (\app\WorkspaceResolver::isDefault()): ?>
                        <a href="/signup" class="btn btn-primary w-100">Start <?= $trialDays ?>-Day Trial</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Enterprise -->
            <div class="col-lg-4 col-md-6">
                <div class="card h-100 shadow-sm">
                    <div class="card-body p-4">
                        <h5 class="text-muted mb-3">Enterprise</h5>
                        <div class="display-5 fw-bold mb-1"><?= $pricing['enterprise_monthly_formatted'] ?? '$5,000' ?>+</div>
                        <p class="text-muted mb-4">per month</p>
                        <ul class="list-unstyled mb-4">
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Everything in Pro</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Dedicated infrastructure</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Custom integrations</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i>SLA guarantee</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i>White-glove onboarding</li>
                        </ul>
                        <a href="/contact" class="btn btn-outline-dark w-100">Talk to Us</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Final CTA -->
<div class="py-5" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);">
    <div class="container text-center text-white">
        <h2 class="mb-3">Stop hiring. Start shipping.</h2>
        <p class="lead mb-4 opacity-75">
            Your next engineer doesn't need a salary, benefits, or onboarding.<br>They need requirements.
        </p>
        <?php if (\app\WorkspaceResolver::isDefault()): ?>
            <div class="d-flex justify-content-center gap-3 flex-wrap">
                <a href="/signup" class="btn btn-primary btn-lg px-5">
                    Start <?= $trialDays ?>-Day Trial
                </a>
                <a href="https://demo.myctobot.ai" class="btn btn-outline-light btn-lg px-4">
                    Try Live Demo
                </a>
            </div>
        <?php elseif (!$isLoggedIn): ?>
            <a href="/auth/login" class="btn btn-primary btn-lg px-5">Sign In</a>
        <?php else: ?>
            <a href="/dashboard" class="btn btn-primary btn-lg px-5">Go to Dashboard</a>
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
                    <span class="input-group-text">.<?= htmlspecialchars($site_domain ?? 'myctobot.ai') ?></span>
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
        window.location.href = window.SiteConfig.protocol + '://' + subdomain + '.' + window.SiteConfig.domain;
    }
}
document.getElementById('teamSubdomain')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') goToTeam();
});
</script>
