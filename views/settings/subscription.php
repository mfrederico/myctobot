<?php
use app\services\PricingService;

$stripeConfigured = \app\services\StripeService::isConfigured();
$hasStripeSubscription = $subscription && !empty($subscription['stripe_subscription_eid']) && strpos($subscription['stripe_subscription_eid'], 'stub_') !== 0;
$isCanceled = $subscription && !empty($subscription['cancelled_at']);
$trialDays = \app\services\StripeService::getTrialDays();
$hasUsedTrial = \app\services\StripeService::hasUsedTrial($member->id);
$trialEligible = !$hasUsedTrial && $trialDays > 0;
$isOnTrial = $subscription && !empty($subscription['trial_ends_at']) && strtotime($subscription['trial_ends_at']) > time();

// Use platform pricing from PricingService
$pricing = PricingService::getPlatformPricing();
?>
<div class="container py-4" data-assist-page="settings/subscription" data-assist-purpose="Manage subscription plan and billing">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">Subscription</h1>
            <p class="text-muted mb-0">Manage your plan and billing</p>
        </div>
        <div class="d-flex gap-2">
            <?php if ($hasStripeSubscription): ?>
            <a href="/stripe/portal" class="btn btn-outline-primary">
                <i class="bi bi-credit-card me-1"></i> Billing Portal
            </a>
            <?php endif; ?>
            <a href="/settings/connections" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i>
        <strong>Welcome to Pro!</strong>
        <?= $isOnTrial ? "Your {$trialDays}-day free trial has started." : "Your subscription is now active." ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($isOnTrial): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <i class="bi bi-clock me-2"></i>
        <strong>Free Trial Active</strong> - Ends <?= date('M j, Y', strtotime($subscription['trial_ends_at'])) ?>. No charge until then.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['canceled'])): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <i class="bi bi-info-circle me-2"></i> Checkout was canceled. No charges were made.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($isCanceled): ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center" role="alert">
        <div>
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Subscription Canceling</strong> - Access ends <?= date('M j, Y', strtotime($subscription['current_period_end'])) ?>
        </div>
        <button class="btn btn-warning btn-sm" onclick="reactivateSubscription()">
            <i class="bi bi-arrow-counterclockwise me-1"></i> Reactivate
        </button>
    </div>
    <?php endif; ?>

    <!-- Current Plan & Usage Row -->
    <div class="row g-4 mb-4">
        <!-- Current Plan Card -->
        <div class="col-md-6">
            <div class="card h-100 border-<?= $tierInfo['color'] ?>">
                <div class="card-header bg-<?= $tierInfo['color'] ?> <?= $currentTier !== 'free' ? 'text-white' : '' ?>">
                    <i class="bi bi-star-fill me-1"></i> Current Plan
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1"><?= $tierInfo['name'] ?></h3>
                            <p class="text-muted mb-0 small"><?= $tierInfo['description'] ?></p>
                        </div>
                        <div class="text-end">
                            <div class="h4 mb-0"><?= $tierInfo['price'] ?></div>
                            <?php if ($subscription && $subscription['current_period_end']): ?>
                            <small class="text-muted">Renews <?= date('M j', strtotime($subscription['current_period_end'])) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Usage Stats Card -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-bar-chart me-1"></i> Usage
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-4">
                            <h4 class="mb-0 <?= ($limits['boards'] != -1 && $boardCount >= $limits['boards']) ? 'text-danger' : 'text-primary' ?>">
                                <?= $boardCount ?>
                            </h4>
                            <small class="text-muted">of <?= $limits['boards'] == -1 ? '∞' : $limits['boards'] ?> boards</small>
                        </div>
                        <div class="col-4">
                            <h4 class="mb-0 text-success"><?= $limits['analyses_per_day'] == -1 ? '∞' : $limits['analyses_per_day'] ?></h4>
                            <small class="text-muted">analyses/day</small>
                        </div>
                        <div class="col-4">
                            <h4 class="mb-0 text-info"><?= $limits['digest_recipients'] == -1 ? '∞' : $limits['digest_recipients'] ?></h4>
                            <small class="text-muted">recipients</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Pricing Cards -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-tags me-1"></i> Available Plans
        </div>
        <div class="card-body">
            <p class="text-muted text-center mb-4">A PHP developer costs $7,000/month fully loaded. MyCTOBot ships code while they sleep.</p>
            <div class="row g-4 justify-content-center">
                <!-- Starter Tier -->
                <div class="col-lg-4">
                    <div class="card h-100 <?= $currentTier === 'starter' ? 'border-secondary border-2' : '' ?>">
                        <?php if ($currentTier === 'starter'): ?>
                        <div class="position-absolute top-0 end-0 m-2">
                            <span class="badge bg-secondary">Current</span>
                        </div>
                        <?php endif; ?>
                        <div class="card-body">
                            <h5 class="card-title">Starter</h5>
                            <div class="display-6 mb-2"><?= $pricing['starter_monthly_formatted'] ?? '$749' ?><small class="text-muted fs-6">/mo</small></div>
                            <p class="text-muted small">For solo developers and small teams</p>
                            <hr>
                            <ul class="list-unstyled small">
                                <li class="mb-2"><i class="bi bi-check text-success me-2"></i><?= $pricing['starter_projects'] ?? 3 ?> projects</li>
                                <li class="mb-2"><i class="bi bi-check text-success me-2"></i><?= $pricing['starter_ai_jobs'] ?? 50 ?> AI jobs/month</li>
                                <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Basic pipelines</li>
                                <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Email support</li>
                            </ul>
                        </div>
                        <div class="card-footer bg-transparent border-0">
                            <?php if ($currentTier === 'starter'): ?>
                            <button class="btn btn-secondary w-100" disabled>Current Plan</button>
                            <?php elseif ($stripeConfigured): ?>
                            <a href="/stripe/checkout?tier=starter&interval=monthly" class="btn btn-outline-primary w-100">
                                Select Starter
                            </a>
                            <?php else: ?>
                            <a href="/contact" class="btn btn-outline-primary w-100">Contact Sales</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Pro Tier -->
                <div class="col-lg-4">
                    <div class="card h-100 border-primary <?= $currentTier === 'pro' ? 'border-2' : '' ?>">
                        <div class="position-absolute top-0 end-0 m-2">
                            <?php if ($currentTier === 'pro'): ?>
                            <span class="badge bg-primary">Current</span>
                            <?php else: ?>
                            <span class="badge bg-primary">Popular</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <h5 class="card-title">Pro</h5>
                            <div class="display-6 mb-2"><?= $pricing['pro_monthly_formatted'] ?? '$1,999' ?><small class="text-muted fs-6">/mo</small></div>
                            <p class="text-muted small">For growing teams</p>
                            <hr>
                            <ul class="list-unstyled small">
                                <li class="mb-2"><i class="bi bi-check text-success me-2"></i><?= ($pricing['pro_projects'] ?? 0) == 0 ? 'Unlimited' : $pricing['pro_projects'] ?> projects</li>
                                <li class="mb-2"><i class="bi bi-check text-success me-2"></i><?= ($pricing['pro_ai_jobs'] ?? 0) == 0 ? 'Unlimited' : $pricing['pro_ai_jobs'] ?> AI jobs/month</li>
                                <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Advanced pipelines</li>
                                <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Priority support</li>
                                <li class="mb-2"><i class="bi bi-check text-primary me-2"></i>Custom integrations</li>
                            </ul>
                        </div>
                        <div class="card-footer bg-transparent border-0">
                            <?php if ($currentTier === 'pro'): ?>
                            <button class="btn btn-primary w-100" disabled>Current Plan</button>
                            <?php if ($hasStripeSubscription && !$isCanceled): ?>
                            <button class="btn btn-link btn-sm w-100 text-danger mt-1" onclick="cancelSubscription()">
                                Cancel Subscription
                            </button>
                            <?php endif; ?>
                            <?php elseif ($stripeConfigured): ?>
                            <a href="/stripe/checkout?tier=pro&interval=monthly" class="btn btn-primary w-100">
                                <i class="bi bi-rocket me-1"></i> Select Pro
                            </a>
                            <?php else: ?>
                            <a href="/contact" class="btn btn-primary w-100">Contact Sales</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Enterprise Tier -->
                <div class="col-lg-4">
                    <div class="card h-100 <?= $currentTier === 'enterprise' ? 'border-warning border-2' : '' ?>">
                        <?php if ($currentTier === 'enterprise'): ?>
                        <div class="position-absolute top-0 end-0 m-2">
                            <span class="badge bg-warning text-dark">Current</span>
                        </div>
                        <?php endif; ?>
                        <div class="card-body">
                            <h5 class="card-title">Enterprise</h5>
                            <div class="display-6 mb-2"><?= $pricing['enterprise_monthly_formatted'] ?? '$5,000' ?>+<small class="text-muted fs-6">/mo</small></div>
                            <p class="text-muted small">For large organizations</p>
                            <hr>
                            <ul class="list-unstyled small">
                                <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Unlimited projects</li>
                                <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Unlimited tickets</li>
                                <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Dedicated infrastructure</li>
                                <li class="mb-2"><i class="bi bi-check text-success me-2"></i>24/7 support</li>
                                <li class="mb-2"><i class="bi bi-check text-primary me-2"></i>SLA guarantee</li>
                                <li class="mb-2"><i class="bi bi-check text-primary me-2"></i>On-premise option</li>
                            </ul>
                        </div>
                        <div class="card-footer bg-transparent border-0">
                            <?php if ($currentTier === 'enterprise'): ?>
                            <button class="btn btn-warning w-100" disabled>Current Plan</button>
                            <?php else: ?>
                            <a href="/contact" class="btn btn-outline-warning w-100">Contact Sales</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- FAQ -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-question-circle me-1"></i> FAQ
        </div>
        <div class="card-body p-0">
            <div class="accordion accordion-flush" id="faqAccordion">
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                            How does the free trial work?
                        </button>
                    </h2>
                    <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body small">
                            Get <?= $trialDays ?> days of Pro free. Payment info required but no charge until trial ends. Cancel anytime.
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                            Can I cancel anytime?
                        </button>
                    </h2>
                    <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body small">
                            Yes! Cancel anytime. You keep access until your billing period ends.
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                            What happens if I downgrade?
                        </button>
                    </h2>
                    <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body small">
                            Your data is preserved. If you exceed Free limits, remove boards before running new analyses.
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                            Is my data secure?
                        </button>
                    </h2>
                    <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body small">
                            Yes. OAuth 2.0 authentication, encrypted data at rest and in transit. We only access what's needed.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Testimonial -->
    <div class="card bg-light">
        <div class="card-body">
            <div class="d-flex align-items-start">
                <i class="bi bi-quote fs-3 text-primary me-3"></i>
                <div>
                    <p class="mb-2 fst-italic">
                        "MyCTOBot cut my standup prep from 45 minutes to 5. The clarity scoring alone saved countless hours."
                    </p>
                    <small class="text-muted">— Engineering Manager, SaaS Company</small>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function cancelSubscription() {
    if (!confirm('Cancel subscription? You keep access until your billing period ends.')) return;

    fetch('/stripe/cancel', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        alert(data.success ? (data.message || 'Subscription canceled') : ('Error: ' + (data.message || 'Failed')));
        if (data.success) location.reload();
    })
    .catch(e => alert('Error: ' + e.message));
}

function reactivateSubscription() {
    fetch('/stripe/reactivate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        alert(data.success ? (data.message || 'Reactivated!') : ('Error: ' + (data.message || 'Failed')));
        if (data.success) location.reload();
    })
    .catch(e => alert('Error: ' + e.message));
}
</script>
