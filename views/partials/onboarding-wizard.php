<?php
/**
 * Onboarding Wizard Modal
 *
 * Include this partial in views that need the wizard.
 * Pass $showOnboarding = true to auto-open on page load.
 *
 * Variables:
 *   $showOnboarding - bool - Auto-open the wizard
 *   $gitConnected - bool - Whether any git provider is connected
 */
$showOnboarding = $showOnboarding ?? false;
$gitConnected = $gitConnected ?? false;
?>

<!-- Onboarding Wizard Modal -->
<div class="modal fade" id="onboardingWizard" tabindex="-1" aria-labelledby="onboardingWizardLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title" id="onboardingWizardLabel">
                    <i class="bi bi-rocket-takeoff me-2"></i>Getting Started
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-2">

                <!-- Step 1: Choose Git Provider -->
                <div id="wizardStep1" class="wizard-step">
                    <p class="text-muted mb-4">Where is your code hosted?</p>

                    <div class="row g-3">
                        <!-- GitHub -->
                        <div class="col-6 col-md-3">
                            <div class="provider-card" data-provider="github">
                                <div class="provider-icon">
                                    <i class="bi bi-github"></i>
                                </div>
                                <div class="provider-name">GitHub</div>
                            </div>
                        </div>

                        <!-- Bitbucket -->
                        <div class="col-6 col-md-3">
                            <div class="provider-card coming-soon" data-provider="bitbucket">
                                <div class="provider-icon">
                                    <svg viewBox="0 0 24 24" width="32" height="32" fill="currentColor">
                                        <path d="M.778 1.211a.768.768 0 00-.768.892l3.263 19.81c.084.5.515.868 1.022.873H19.95a.772.772 0 00.77-.646l3.27-20.03a.768.768 0 00-.768-.891zM14.52 15.53H9.522L8.17 8.466h7.561z"/>
                                    </svg>
                                </div>
                                <div class="provider-name">Bitbucket</div>
                                <span class="badge bg-secondary">Coming Soon</span>
                            </div>
                        </div>

                        <!-- GitLab -->
                        <div class="col-6 col-md-3">
                            <div class="provider-card coming-soon" data-provider="gitlab">
                                <div class="provider-icon">
                                    <svg viewBox="0 0 24 24" width="32" height="32" fill="currentColor">
                                        <path d="M23.955 13.587l-1.342-4.135-2.664-8.189a.455.455 0 00-.867 0L16.418 9.45H7.582L4.918 1.263a.455.455 0 00-.867 0L1.386 9.45.044 13.587a.924.924 0 00.331 1.023L12 23.054l11.625-8.443a.92.92 0 00.33-1.024"/>
                                    </svg>
                                </div>
                                <div class="provider-name">GitLab</div>
                                <span class="badge bg-secondary">Coming Soon</span>
                            </div>
                        </div>

                        <!-- Other -->
                        <div class="col-6 col-md-3">
                            <div class="provider-card" data-provider="other">
                                <div class="provider-icon">
                                    <i class="bi bi-git"></i>
                                </div>
                                <div class="provider-name">Other</div>
                                <small class="text-muted">Manual setup</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 2: GitHub -->
                <div id="wizardStepGithub" class="wizard-step" style="display: none;">
                    <div class="text-center py-4">
                        <div class="provider-icon-large mb-3">
                            <i class="bi bi-github"></i>
                        </div>
                        <h5>Connect GitHub</h5>
                        <p class="text-muted">Connect your GitHub account to enable AI-powered development on your repositories.</p>

                        <form method="GET" action="/github/connect" class="d-inline">
                            <?php if (!empty($csrf) && is_array($csrf)): ?>
                                <?php foreach ($csrf as $name => $value): ?>
                                    <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-dark btn-lg">
                                <i class="bi bi-github me-2"></i>Connect GitHub
                            </button>
                        </form>

                        <div class="mt-4 pt-3 border-top">
                            <p class="text-muted small mb-2">Don't have a GitHub account?</p>
                            <a href="https://github.com/signup" target="_blank" class="btn btn-outline-secondary btn-sm">
                                Create free GitHub account <i class="bi bi-box-arrow-up-right ms-1"></i>
                            </a>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between mt-4 pt-3 border-top">
                        <button type="button" class="btn btn-link text-muted" onclick="wizardBack()">
                            <i class="bi bi-arrow-left me-1"></i> Back
                        </button>
                        <button type="button" class="btn btn-link text-muted" data-bs-dismiss="modal">
                            Skip for now
                        </button>
                    </div>
                </div>

                <!-- Step 2: Coming Soon (Bitbucket/GitLab) -->
                <div id="wizardStepComingSoon" class="wizard-step" style="display: none;">
                    <div class="text-center py-4">
                        <div class="provider-icon-large mb-3" id="comingSoonIcon">
                            <!-- Icon set by JavaScript -->
                        </div>
                        <h5><span id="comingSoonProvider">Provider</span> Integration Coming Soon</h5>
                        <p class="text-muted">We're working on this integration. Want to be notified when it's ready?</p>

                        <form id="notifyForm" class="d-inline">
                            <input type="hidden" name="provider" id="notifyProvider" value="">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-bell me-2"></i>Notify Me
                            </button>
                        </form>

                        <div id="notifySuccess" class="alert alert-success mt-3" style="display: none;">
                            <i class="bi bi-check-circle me-2"></i>We'll let you know when it's available!
                        </div>
                    </div>

                    <div class="d-flex justify-content-between mt-4 pt-3 border-top">
                        <button type="button" class="btn btn-link text-muted" onclick="wizardBack()">
                            <i class="bi bi-arrow-left me-1"></i> Back
                        </button>
                        <button type="button" class="btn btn-link text-muted" data-bs-dismiss="modal">
                            Skip for now
                        </button>
                    </div>
                </div>

                <!-- Step 2: Other/Manual -->
                <div id="wizardStepOther" class="wizard-step" style="display: none;">
                    <div class="text-center py-4">
                        <div class="provider-icon-large mb-3">
                            <i class="bi bi-git"></i>
                        </div>
                        <h5>Manual Repository Setup</h5>
                        <p class="text-muted">You can manually add any git repository using SSH or HTTPS clone URLs.</p>

                        <a href="/enterprise/repos" class="btn btn-primary btn-lg">
                            <i class="bi bi-plus-circle me-2"></i>Set Up Repository
                        </a>

                        <div class="mt-4">
                            <p class="text-muted small">Supported: GitHub, GitLab, Bitbucket, Gitea, self-hosted, and more.</p>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between mt-4 pt-3 border-top">
                        <button type="button" class="btn btn-link text-muted" onclick="wizardBack()">
                            <i class="bi bi-arrow-left me-1"></i> Back
                        </button>
                        <button type="button" class="btn btn-link text-muted" data-bs-dismiss="modal">
                            Skip for now
                        </button>
                    </div>
                </div>

            </div>

            <!-- Footer for Step 1 only -->
            <div class="modal-footer border-0 pt-0" id="wizardStep1Footer">
                <button type="button" class="btn btn-link text-muted" data-bs-dismiss="modal">
                    Skip for now
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.provider-card {
    border: 2px solid #dee2e6;
    border-radius: 12px;
    padding: 20px 15px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.provider-card:hover:not(.coming-soon) {
    border-color: #0d6efd;
    background-color: #f8f9ff;
    transform: translateY(-2px);
}

.provider-card.coming-soon {
    opacity: 0.6;
    cursor: pointer;
}

.provider-card.coming-soon:hover {
    opacity: 0.8;
    border-color: #6c757d;
}

.provider-card .provider-icon {
    font-size: 32px;
    color: #333;
}

.provider-card .provider-name {
    font-weight: 600;
    color: #333;
}

.provider-card .badge {
    font-size: 10px;
    font-weight: 500;
}

.provider-icon-large {
    font-size: 64px;
    color: #333;
}

.provider-icon-large svg {
    width: 64px;
    height: 64px;
}

#onboardingWizard .modal-content {
    border-radius: 16px;
}
</style>

<script>
// Wizard navigation
function wizardShowStep(stepId) {
    document.querySelectorAll('.wizard-step').forEach(step => {
        step.style.display = 'none';
    });
    document.getElementById(stepId).style.display = 'block';

    // Show/hide step 1 footer
    document.getElementById('wizardStep1Footer').style.display =
        stepId === 'wizardStep1' ? 'flex' : 'none';
}

function wizardBack() {
    wizardShowStep('wizardStep1');
}

// Provider card click handlers
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.provider-card').forEach(card => {
        card.addEventListener('click', function() {
            const provider = this.dataset.provider;

            switch(provider) {
                case 'github':
                    wizardShowStep('wizardStepGithub');
                    break;
                case 'bitbucket':
                    document.getElementById('comingSoonProvider').textContent = 'Bitbucket';
                    document.getElementById('comingSoonIcon').innerHTML = '<svg viewBox="0 0 24 24" width="64" height="64" fill="currentColor"><path d="M.778 1.211a.768.768 0 00-.768.892l3.263 19.81c.084.5.515.868 1.022.873H19.95a.772.772 0 00.77-.646l3.27-20.03a.768.768 0 00-.768-.891zM14.52 15.53H9.522L8.17 8.466h7.561z"/></svg>';
                    document.getElementById('notifyProvider').value = 'bitbucket';
                    wizardShowStep('wizardStepComingSoon');
                    break;
                case 'gitlab':
                    document.getElementById('comingSoonProvider').textContent = 'GitLab';
                    document.getElementById('comingSoonIcon').innerHTML = '<svg viewBox="0 0 24 24" width="64" height="64" fill="currentColor"><path d="M23.955 13.587l-1.342-4.135-2.664-8.189a.455.455 0 00-.867 0L16.418 9.45H7.582L4.918 1.263a.455.455 0 00-.867 0L1.386 9.45.044 13.587a.924.924 0 00.331 1.023L12 23.054l11.625-8.443a.92.92 0 00.33-1.024"/></svg>';
                    document.getElementById('notifyProvider').value = 'gitlab';
                    wizardShowStep('wizardStepComingSoon');
                    break;
                case 'other':
                    wizardShowStep('wizardStepOther');
                    break;
            }
        });
    });

    // Notify form handler
    document.getElementById('notifyForm').addEventListener('submit', function(e) {
        e.preventDefault();
        // TODO: Actually save this to DB
        document.getElementById('notifySuccess').style.display = 'block';
        this.querySelector('button').style.display = 'none';
    });

    // Auto-open wizard if needed
    <?php if ($showOnboarding && !$gitConnected): ?>
    var wizardModal = new bootstrap.Modal(document.getElementById('onboardingWizard'));
    wizardModal.show();
    <?php endif; ?>
});

// Function to manually open wizard (for Setup Guide button)
function openOnboardingWizard() {
    var wizardModal = new bootstrap.Modal(document.getElementById('onboardingWizard'));
    wizardModal.show();
}
</script>
