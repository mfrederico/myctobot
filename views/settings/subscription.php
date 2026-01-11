<?php
$stripeConfigured = \app\services\StripeService::isConfigured();
$hasStripeSubscription = $subscription && !empty($subscription['stripe_subscription_id']) && strpos($subscription['stripe_subscription_id'], 'stub_') !== 0;
$isCanceled = $subscription && !empty($subscription['cancelled_at']);
$trialDays = \app\services\StripeService::getTrialDays();
$hasUsedTrial = \app\services\StripeService::hasUsedTrial($member->id);
$trialEligible = !$hasUsedTrial && $trialDays > 0;
$isOnTrial = $subscription && !empty($subscription['trial_ends_at']) && strtotime($subscription['trial_ends_at']) > time();
$proMonthlyPrice = \app\services\SubscriptionService::getProMonthlyPrice();
$proYearlyPrice = \app\services\SubscriptionService::getProYearlyPrice();
?>
<div class="container py-4">
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
            <div class="row g-4 justify-content-center">
                <!-- Free Tier -->
                <div class="col-lg-5">
                    <div class="card h-100 <?= $currentTier === 'free' ? 'border-secondary border-2' : '' ?>">
                        <?php if ($currentTier === 'free'): ?>
                        <div class="position-absolute top-0 end-0 m-2">
                            <span class="badge bg-secondary">Current</span>
                        </div>
                        <?php endif; ?>
                        <div class="card-body">
                            <h5 class="card-title">Free</h5>
                            <div class="display-6 mb-2">$0<small class="text-muted fs-6">/mo</small></div>
                            <p class="text-muted small">Get started with basic analysis</p>
                            <hr>
                            <ul class="list-unstyled small">
                                <li class="mb-2"><i class="bi bi-check text-success me-2"></i>1 Jira board</li>
                                <li class="mb-2"><i class="bi bi-check text-success me-2"></i>3 analyses/day</li>
                                <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Basic priorities</li>
                                <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Email digest</li>
                                <li class="mb-2 text-muted"><i class="bi bi-x me-2"></i>Priority weights</li>
                                <li class="mb-2 text-muted"><i class="bi bi-x me-2"></i>Engineering goals</li>
                                <li class="mb-2 text-muted"><i class="bi bi-x me-2"></i>Image analysis</li>
                            </ul>
                        </div>
                        <div class="card-footer bg-transparent border-0">
                            <?php if ($currentTier === 'free'): ?>
                            <button class="btn btn-secondary w-100" disabled>Current Plan</button>
                            <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="downgrade">
                                <button type="submit" class="btn btn-outline-secondary w-100"
                                        onclick="return confirm('Downgrade to Free? You will lose Pro features.')">
                                    Downgrade
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Pro Tier -->
                <div class="col-lg-5">
                    <div class="card h-100 border-primary <?= $currentTier === 'pro' ? 'border-2' : '' ?>">
                        <div class="position-absolute top-0 end-0 m-2">
                            <?php if ($currentTier === 'pro'): ?>
                            <span class="badge bg-primary">Current</span>
                            <?php elseif ($trialEligible): ?>
                            <span class="badge bg-success">Free Trial</span>
                            <?php else: ?>
                            <span class="badge bg-primary">Popular</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <h5 class="card-title">Pro</h5>
                            <?php if ($trialEligible && $currentTier !== 'pro'): ?>
                            <div class="display-6 mb-0">$0</div>
                            <p class="text-muted small mb-2">for <?= $trialDays ?> days, then $<?= number_format($proMonthlyPrice) ?>/mo</p>
                            <?php else: ?>
                            <div class="display-6 mb-2">$<?= number_format($proMonthlyPrice) ?><small class="text-muted fs-6">/mo</small></div>
                            <p class="text-muted small">For Engineering Managers</p>
                            <?php endif; ?>
                            <hr>
                            <ul class="list-unstyled small">
                                <li class="mb-2"><i class="bi bi-check text-success me-2"></i><strong>5 boards</strong></li>
                                <li class="mb-2"><i class="bi bi-check text-success me-2"></i><strong>Unlimited analyses</strong></li>
                                <li class="mb-2"><i class="bi bi-check text-success me-2"></i>AI priorities + digest</li>
                                <li class="mb-2"><i class="bi bi-check text-primary me-2"></i>Priority weight sliders</li>
                                <li class="mb-2"><i class="bi bi-check text-primary me-2"></i>Engineering goals</li>
                                <li class="mb-2"><i class="bi bi-check text-primary me-2"></i>Clarity scoring</li>
                                <li class="mb-2"><i class="bi bi-check text-primary me-2"></i>Image analysis</li>
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
                            <?php elseif ($stripeConfigured && \app\services\StripeService::getPriceId('pro', 'monthly')): ?>
                            <a href="/stripe/checkout?tier=pro&interval=monthly" class="btn btn-primary w-100">
                                <?= $trialEligible ? '<i class="bi bi-gift me-1"></i> Start Free Trial' : '<i class="bi bi-rocket me-1"></i> Upgrade' ?>
                            </a>
                            <small class="text-muted d-block text-center mt-2">
                                <i class="bi bi-lock me-1"></i>Secure payment via Stripe
                            </small>
                            <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="upgrade">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-rocket me-1"></i> Upgrade to Pro
                                </button>
                            </form>
                            <small class="text-muted d-block text-center mt-1">(Demo mode)</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ROI & Value Section -->
    <div class="row g-4 mb-4">
        <!-- ROI Calculator -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-calculator me-1"></i> ROI Calculator
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Engineering Managers spend <strong>30-60 min/day</strong> on ticket review and prioritization.
                    </p>
                    <div class="row text-center mb-3">
                        <div class="col-4">
                            <div class="h5 text-primary mb-0">30 min</div>
                            <small class="text-muted">saved/day</small>
                        </div>
                        <div class="col-4">
                            <div class="h5 text-primary mb-0">10 hrs</div>
                            <small class="text-muted">saved/month</small>
                        </div>
                        <div class="col-4">
                            <div class="h5 text-success mb-0">$1,000+</div>
                            <small class="text-muted">value/month</small>
                        </div>
                    </div>
                    <div class="bg-dark text-white rounded p-3 text-center">
                        <small class="text-muted d-block mb-1">Monthly ROI</small>
                        <span class="h3 text-success">7x</span>
                        <small class="text-muted d-block">return on Pro</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- What You Get -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-lightning me-1"></i> Pro Benefits
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-6">
                            <h6 class="text-danger small"><i class="bi bi-x-circle me-1"></i>Without Pro</h6>
                            <ul class="list-unstyled small text-muted">
                                <li class="mb-1"><i class="bi bi-hourglass me-1"></i>Manual ticket review</li>
                                <li class="mb-1"><i class="bi bi-hourglass me-1"></i>Guessing priorities</li>
                                <li class="mb-1"><i class="bi bi-hourglass me-1"></i>Chasing clarification</li>
                            </ul>
                        </div>
                        <div class="col-6">
                            <h6 class="text-success small"><i class="bi bi-check-circle me-1"></i>With Pro</h6>
                            <ul class="list-unstyled small">
                                <li class="mb-1"><i class="bi bi-lightning text-success me-1"></i>AI-prioritized digest</li>
                                <li class="mb-1"><i class="bi bi-lightning text-success me-1"></i>Impact scoring</li>
                                <li class="mb-1"><i class="bi bi-lightning text-success me-1"></i>Clarity detection</li>
                            </ul>
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
