<?php
$stripeConfigured = \app\services\StripeService::isConfigured();
$hasStripeSubscription = $subscription && !empty($subscription['stripe_subscription_id']) && strpos($subscription['stripe_subscription_id'], 'stub_') !== 0;
$isCanceled = $subscription && !empty($subscription['cancelled_at']);
$trialDays = \app\services\StripeService::getTrialDays();
$hasUsedTrial = \app\services\StripeService::hasUsedTrial($member->id);
$trialEligible = !$hasUsedTrial && $trialDays > 0;
$isOnTrial = $subscription && !empty($subscription['trial_ends_at']) && strtotime($subscription['trial_ends_at']) > time();
// Centralized pricing from config
$proMonthlyPrice = \app\services\SubscriptionService::getProMonthlyPrice();
$proYearlyPrice = \app\services\SubscriptionService::getProYearlyPrice();
?>
<div class="container-fluid">
    <div class="row">
        <div class="col-lg-10 offset-lg-1">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>Subscription</h1>
                <div>
                    <?php if ($hasStripeSubscription): ?>
                    <a href="/stripe/portal" class="btn btn-outline-primary me-2">
                        <i class="bi bi-credit-card"></i> Manage Billing
                    </a>
                    <?php endif; ?>
                    <a href="/settings" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Settings
                    </a>
                </div>
            </div>

            <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <strong>Welcome to Pro!</strong>
                <?php if ($isOnTrial): ?>
                Your <?= $trialDays ?>-day free trial has started. Enjoy all the premium features!
                <?php else: ?>
                Your subscription is now active. Enjoy all the premium features!
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($isOnTrial): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-clock"></i> <strong>Free Trial Active!</strong>
                Your trial ends on <?= date('M j, Y', strtotime($subscription['trial_ends_at'])) ?>.
                You won't be charged until the trial ends.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['canceled'])): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-info-circle"></i> Checkout was canceled. No charges were made.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($isCanceled): ?>
            <div class="alert alert-warning mb-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Subscription Canceling</strong> - Your subscription will end on <?= date('M j, Y', strtotime($subscription['current_period_end'])) ?>.
                        You'll retain access until then.
                    </div>
                    <button class="btn btn-warning btn-sm" onclick="reactivateSubscription()">
                        <i class="bi bi-arrow-counterclockwise"></i> Reactivate
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Current Plan Banner -->
            <div class="alert alert-<?= $tierInfo['color'] ?> mb-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="alert-heading mb-1">
                            <i class="bi bi-star-fill"></i> Current Plan: <?= $tierInfo['name'] ?>
                        </h4>
                        <p class="mb-0"><?= $tierInfo['description'] ?></p>
                    </div>
                    <div class="text-end">
                        <div class="h4 mb-0"><?= $tierInfo['price'] ?></div>
                        <?php if ($subscription && $subscription['current_period_end']): ?>
                        <small>Renews <?= date('M j, Y', strtotime($subscription['current_period_end'])) ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Usage Stats -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-bar-chart"></i> Your Current Usage
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-4">
                            <div class="h3 mb-0"><?= $boardCount ?> / <?= $limits['boards'] == -1 ? '∞' : $limits['boards'] ?></div>
                            <small class="text-muted">Boards Used</small>
                            <?php if ($limits['boards'] != -1 && $boardCount >= $limits['boards']): ?>
                            <div class="text-danger small mt-1"><i class="bi bi-exclamation-circle"></i> Limit reached</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <div class="h3 mb-0"><?= $limits['analyses_per_day'] == -1 ? 'Unlimited' : $limits['analyses_per_day'] ?></div>
                            <small class="text-muted">Analyses per Day</small>
                        </div>
                        <div class="col-md-4">
                            <div class="h3 mb-0"><?= $limits['digest_recipients'] == -1 ? 'Unlimited' : $limits['digest_recipients'] ?></div>
                            <small class="text-muted">Digest Recipients</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ROI Calculator Section -->
            <div class="card mb-4 border-success">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-calculator"></i> See Your Return on Investment
                </div>
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-7">
                            <h5>How MyCTOBot Saves You Time & Money</h5>
                            <p class="text-muted">
                                Engineering Managers and CTOs spend <strong>30-60 minutes daily</strong> reviewing tickets,
                                prioritizing work, and chasing stakeholders for clarification.
                            </p>

                            <div class="bg-light p-3 rounded mb-3">
                                <div class="row text-center">
                                    <div class="col-4">
                                        <div class="h4 text-primary mb-0">30 min</div>
                                        <small class="text-muted">Saved per day</small>
                                    </div>
                                    <div class="col-4">
                                        <div class="h4 text-primary mb-0">10 hrs</div>
                                        <small class="text-muted">Saved per month</small>
                                    </div>
                                    <div class="col-4">
                                        <div class="h4 text-success mb-0">$1,000+</div>
                                        <small class="text-muted">Monthly value*</small>
                                    </div>
                                </div>
                            </div>

                            <p class="small text-muted mb-0">
                                *Based on $100/hr loaded cost for engineering management time
                            </p>
                        </div>
                        <div class="col-md-5">
                            <div class="card bg-dark text-white">
                                <div class="card-body text-center">
                                    <h6 class="text-uppercase text-muted mb-3">Your Monthly ROI</h6>
                                    <div class="display-4 text-success mb-2">7x</div>
                                    <p class="mb-0">Return on Pro subscription</p>
                                    <hr class="border-secondary">
                                    <small class="text-muted">
                                        Pay $<?= number_format($proMonthlyPrice) ?> → Save $1,000+ in time
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- What You Get Section -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-clock-history"></i> What's Eating Your Time?
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-danger"><i class="bi bi-x-circle"></i> Without MyCTOBot Pro</h6>
                            <ul class="list-unstyled">
                                <li class="mb-2"><i class="bi bi-hourglass text-danger"></i> Manually reviewing every ticket each morning</li>
                                <li class="mb-2"><i class="bi bi-hourglass text-danger"></i> Guessing which tasks have highest customer impact</li>
                                <li class="mb-2"><i class="bi bi-hourglass text-danger"></i> Chasing reporters for missing requirements</li>
                                <li class="mb-2"><i class="bi bi-hourglass text-danger"></i> Context switching between boards and projects</li>
                                <li class="mb-2"><i class="bi bi-hourglass text-danger"></i> Inconsistent prioritization decisions</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-success"><i class="bi bi-check-circle"></i> With MyCTOBot Pro</h6>
                            <ul class="list-unstyled">
                                <li class="mb-2"><i class="bi bi-lightning text-success"></i> AI-prioritized daily digest in your inbox</li>
                                <li class="mb-2"><i class="bi bi-lightning text-success"></i> Customer impact scoring on every ticket</li>
                                <li class="mb-2"><i class="bi bi-lightning text-success"></i> Automatic clarity detection with suggested questions</li>
                                <li class="mb-2"><i class="bi bi-lightning text-success"></i> Custom priority weights (tech debt, quick wins, etc.)</li>
                                <li class="mb-2"><i class="bi bi-lightning text-success"></i> Engineering goals to guide AI recommendations</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pricing Cards -->
            <h3 class="mb-4">Choose Your Plan</h3>
            <div class="row mb-4 justify-content-center">
                <!-- Free Tier -->
                <div class="col-md-5 mb-3">
                    <div class="card h-100 <?= $currentTier === 'free' ? 'border-secondary border-2' : '' ?>">
                        <?php if ($currentTier === 'free'): ?>
                        <div class="card-header bg-secondary text-white text-center">
                            <i class="bi bi-check-circle"></i> Current Plan
                        </div>
                        <?php endif; ?>
                        <div class="card-body">
                            <h4 class="card-title">Free</h4>
                            <div class="display-6 mb-3">$0<small class="text-muted fs-6">/month</small></div>
                            <p class="text-muted">Get started with basic analysis</p>
                            <hr>
                            <ul class="list-unstyled">
                                <li class="mb-2"><i class="bi bi-check text-success"></i> 1 Jira board</li>
                                <li class="mb-2"><i class="bi bi-check text-success"></i> 3 analyses per day</li>
                                <li class="mb-2"><i class="bi bi-check text-success"></i> Basic priority recommendations</li>
                                <li class="mb-2"><i class="bi bi-check text-success"></i> Email digest</li>
                                <li class="mb-2 text-muted"><i class="bi bi-x"></i> Priority weights</li>
                                <li class="mb-2 text-muted"><i class="bi bi-x"></i> Engineering goals</li>
                                <li class="mb-2 text-muted"><i class="bi bi-x"></i> Clarity analysis</li>
                                <li class="mb-2 text-muted"><i class="bi bi-x"></i> Stakeholder detection</li>
                                <li class="mb-2 text-muted"><i class="bi bi-x"></i> Image analysis</li>
                            </ul>
                        </div>
                        <div class="card-footer bg-transparent">
                            <?php if ($currentTier === 'free'): ?>
                            <button class="btn btn-secondary w-100" disabled>Current Plan</button>
                            <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="downgrade">
                                <button type="submit" class="btn btn-outline-secondary w-100"
                                        onclick="return confirm('Are you sure you want to downgrade? You will lose access to Pro features.')">
                                    Downgrade
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Pro Tier -->
                <div class="col-md-5 mb-3">
                    <div class="card h-100 border-primary <?= $currentTier === 'pro' ? 'border-2' : '' ?>">
                        <div class="card-header bg-primary text-white text-center">
                            <?php if ($currentTier === 'pro'): ?>
                            <i class="bi bi-check-circle"></i> Current Plan
                            <?php elseif ($trialEligible): ?>
                            <i class="bi bi-gift"></i> Try 1 Sprint Free!
                            <?php else: ?>
                            <i class="bi bi-star-fill"></i> Most Popular
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <h4 class="card-title">Pro</h4>
                            <?php if ($trialEligible && $currentTier !== 'pro'): ?>
                            <div class="mb-2">
                                <span class="badge bg-success fs-6"><?= $trialDays ?>-Day Free Trial</span>
                            </div>
                            <div class="display-6 mb-1">$0<small class="text-muted fs-6"> for <?= $trialDays ?> days</small></div>
                            <p class="text-muted mb-0">then $<?= number_format($proMonthlyPrice) ?>/month</p>
                            <?php else: ?>
                            <div class="display-6 mb-3">$<?= number_format($proMonthlyPrice) ?><small class="text-muted fs-6">/month</small></div>
                            <?php endif; ?>
                            <p class="text-muted">Perfect for Engineering Managers</p>
                            <hr>
                            <ul class="list-unstyled">
                                <li class="mb-2"><i class="bi bi-check text-success"></i> <strong>Up to 5 boards</strong></li>
                                <li class="mb-2"><i class="bi bi-check text-success"></i> <strong>Unlimited analyses</strong></li>
                                <li class="mb-2"><i class="bi bi-check text-success"></i> AI priority recommendations</li>
                                <li class="mb-2"><i class="bi bi-check text-success"></i> Email digest + CC recipients</li>
                                <li class="mb-2"><i class="bi bi-check text-primary"></i> <strong>Priority weight sliders</strong></li>
                                <li class="mb-2"><i class="bi bi-check text-primary"></i> <strong>Engineering goals</strong></li>
                                <li class="mb-2"><i class="bi bi-check text-primary"></i> <strong>Ticket clarity scoring</strong></li>
                                <li class="mb-2"><i class="bi bi-check text-primary"></i> <strong>Stakeholder follow-up lists</strong></li>
                                <li class="mb-2"><i class="bi bi-check text-primary"></i> <strong>Image analysis</strong> (screenshots, mockups)</li>
                            </ul>
                        </div>
                        <div class="card-footer bg-transparent">
                            <?php if ($currentTier === 'pro'): ?>
                            <button class="btn btn-primary w-100" disabled>Current Plan</button>
                            <?php if ($hasStripeSubscription && !$isCanceled): ?>
                            <button class="btn btn-outline-danger btn-sm w-100 mt-2" onclick="cancelSubscription()">
                                Cancel Subscription
                            </button>
                            <?php endif; ?>
                            <?php elseif ($stripeConfigured && \app\services\StripeService::getPriceId('pro', 'monthly')): ?>
                            <a href="/stripe/checkout?tier=pro&interval=monthly" class="btn btn-primary w-100">
                                <?php if ($trialEligible): ?>
                                <i class="bi bi-gift"></i> Start Free Trial
                                <?php else: ?>
                                <i class="bi bi-rocket"></i> Upgrade to Pro
                                <?php endif; ?>
                            </a>
                            <?php if ($trialEligible): ?>
                            <small class="text-muted d-block text-center mt-1">No charge for <?= $trialDays ?> days. Cancel anytime.</small>
                            <?php endif; ?>
                            <small class="text-muted d-block text-center mt-2">
                                <i class="bi bi-lock"></i> Secure payment by Stripe for ClickSimple, Inc.
                            </small>
                            <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="upgrade">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-rocket"></i> Upgrade to Pro
                                </button>
                            </form>
                            <small class="text-muted d-block text-center mt-1">(Test mode - no payment required)</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Testimonial / Social Proof -->
            <div class="card bg-light mb-4">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-1 text-center">
                            <i class="bi bi-quote display-4 text-primary"></i>
                        </div>
                        <div class="col-md-11">
                            <p class="mb-2 fst-italic">
                                "MyCTOBot cut my morning standup prep from 45 minutes to 5 minutes.
                                The clarity scoring alone has saved us countless hours of back-and-forth with stakeholders."
                            </p>
                            <small class="text-muted">— Engineering Manager, SaaS Company</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FAQ -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-question-circle"></i> Frequently Asked Questions
                </div>
                <div class="card-body">
                    <div class="accordion" id="faqAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq0">
                                    How does the free trial work?
                                </button>
                            </h2>
                            <div id="faq0" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    New users get a <?= $trialDays ?>-day free trial (that's one full sprint!) of Pro features.
                                    You'll need to enter payment info, but you won't be charged until the trial ends.
                                    Cancel anytime during the trial and pay nothing.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                    Can I cancel anytime?
                                </button>
                            </h2>
                            <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Yes! You can cancel your subscription at any time. You'll continue to have access until the end of your billing period.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                    What happens to my data if I downgrade?
                                </button>
                            </h2>
                            <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Your analysis history is preserved. However, if you exceed the Free tier board limit, you'll need to remove boards before running new analyses.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                    Do you offer annual billing?
                                </button>
                            </h2>
                            <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Yes! Annual billing saves you 20%. Contact us at sales@myctobot.com for annual plans.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                    Is my Jira data secure?
                                </button>
                            </h2>
                            <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Absolutely. We use OAuth 2.0 for Atlassian authentication and never store your Jira credentials.
                                    Your data is encrypted at rest and in transit. We only access the data needed for analysis.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function cancelSubscription() {
    if (!confirm('Are you sure you want to cancel your subscription? You will retain access until the end of your billing period.')) {
        return;
    }

    fetch('/stripe/cancel', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message || 'Subscription canceled');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to cancel subscription'));
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
}

function reactivateSubscription() {
    fetch('/stripe/reactivate', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message || 'Subscription reactivated!');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to reactivate subscription'));
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
}
</script>
