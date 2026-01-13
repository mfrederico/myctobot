<?php
/**
 * Onboarding Wizard Modal - Enhanced Version
 *
 * A step-by-step guided setup wizard for new users.
 *
 * Variables passed from controller:
 *   $showOnboarding - bool - Auto-open the wizard on page load
 *   $onboardingProgress - array - Current setup progress
 *   $gitConnected - bool - Whether GitHub is connected
 *   $jiraConnected - bool - Whether Jira is connected
 *   $repoCount - int - Number of connected repositories
 *   $boardCount - int - Number of tracked boards
 */
$showOnboarding = $showOnboarding ?? false;
$gitConnected = $gitConnected ?? false;
$jiraConnected = $jiraConnected ?? false;
$repoCount = $repoCount ?? 0;
$boardCount = $boardCount ?? 0;

// Calculate current step based on actual progress
$currentStep = 1;
if ($gitConnected) $currentStep = 2;
if ($gitConnected && $repoCount > 0) $currentStep = 3;
if ($gitConnected && $repoCount > 0 && $jiraConnected) $currentStep = 4;
if ($gitConnected && $repoCount > 0 && $jiraConnected && $boardCount > 0) $currentStep = 5;

$steps = [
    1 => ['title' => 'Connect GitHub', 'icon' => 'bi-github'],
    2 => ['title' => 'Add Repository', 'icon' => 'bi-folder-plus'],
    3 => ['title' => 'Connect Jira', 'icon' => 'bi-kanban'],
    4 => ['title' => 'Track Board', 'icon' => 'bi-check2-square'],
    5 => ['title' => 'Ready!', 'icon' => 'bi-rocket-takeoff'],
];

$isComplete = ($currentStep >= 5);
?>

<!-- Onboarding Wizard Modal -->
<div class="modal fade" id="onboardingWizard" tabindex="-1" aria-labelledby="onboardingWizardLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content onboarding-modal">
            <!-- Progress Header -->
            <div class="modal-header border-0 pb-0">
                <div class="w-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="modal-title" id="onboardingWizardLabel">
                            <i class="bi bi-rocket-takeoff me-2 text-primary"></i>
                            <?= $isComplete ? 'Setup Complete!' : 'Getting Started' ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <!-- Progress Steps -->
                    <div class="onboarding-progress">
                        <?php foreach ($steps as $num => $step): ?>
                            <?php
                            $isActive = ($num === $currentStep);
                            $isCompleted = ($num < $currentStep);
                            $stepClass = $isCompleted ? 'completed' : ($isActive ? 'active' : '');
                            ?>
                            <div class="progress-step <?= $stepClass ?>">
                                <div class="step-circle">
                                    <?php if ($isCompleted): ?>
                                        <i class="bi bi-check-lg"></i>
                                    <?php else: ?>
                                        <?= $num ?>
                                    <?php endif; ?>
                                </div>
                                <div class="step-label"><?= $step['title'] ?></div>
                            </div>
                            <?php if ($num < 5): ?>
                                <div class="progress-line <?= $isCompleted ? 'completed' : '' ?>"></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="modal-body pt-4">
                <!-- Step 1: Connect GitHub -->
                <div id="wizardStep1" class="wizard-step" style="<?= $currentStep === 1 ? '' : 'display:none;' ?>">
                    <div class="text-center py-3">
                        <div class="step-icon mb-4">
                            <i class="bi bi-github"></i>
                        </div>
                        <h4 class="mb-3">Connect Your GitHub Account</h4>
                        <p class="text-muted mb-4">
                            MyCTOBot needs access to your GitHub repositories to create pull requests
                            and implement your tickets automatically.
                        </p>

                        <form method="GET" action="/github/connect" class="d-inline">
                            <button type="submit" class="btn btn-dark btn-lg px-5">
                                <i class="bi bi-github me-2"></i>Connect GitHub
                            </button>
                        </form>

                        <div class="mt-4">
                            <small class="text-muted">
                                <i class="bi bi-shield-check me-1"></i>
                                We only request the permissions we need. Your code stays yours.
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Add Repository -->
                <div id="wizardStep2" class="wizard-step" style="<?= $currentStep === 2 ? '' : 'display:none;' ?>">
                    <div class="text-center py-3">
                        <div class="step-icon mb-4 text-success">
                            <i class="bi bi-folder-plus"></i>
                        </div>
                        <h4 class="mb-3">Add a Repository</h4>
                        <p class="text-muted mb-4">
                            Choose a repository where MyCTOBot will implement your Jira tickets.
                            We'll set up webhooks to watch for the <code>ai-dev</code> label.
                        </p>

                        <a href="/github/repos" class="btn btn-primary btn-lg px-5">
                            <i class="bi bi-plus-circle me-2"></i>Add Repository
                        </a>

                        <div class="mt-4">
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                You can add multiple repositories later from the dashboard.
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Connect Jira -->
                <div id="wizardStep3" class="wizard-step" style="<?= $currentStep === 3 ? '' : 'display:none;' ?>">
                    <div class="text-center py-3">
                        <div class="step-icon mb-4 text-info">
                            <i class="bi bi-kanban"></i>
                        </div>
                        <h4 class="mb-3">Connect Jira</h4>
                        <p class="text-muted mb-4">
                            Connect your Atlassian account so MyCTOBot can read tickets,
                            post progress updates, and transition statuses.
                        </p>

                        <a href="/atlassian/connect" class="btn btn-primary btn-lg px-5">
                            <i class="bi bi-link-45deg me-2"></i>Connect Jira
                        </a>

                        <div class="mt-4">
                            <small class="text-muted">
                                <i class="bi bi-shield-check me-1"></i>
                                Uses OAuth - we never see your Atlassian password.
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Track Board -->
                <div id="wizardStep4" class="wizard-step" style="<?= $currentStep === 4 ? '' : 'display:none;' ?>">
                    <div class="text-center py-3">
                        <div class="step-icon mb-4 text-warning">
                            <i class="bi bi-check2-square"></i>
                        </div>
                        <h4 class="mb-3">Track a Board</h4>
                        <p class="text-muted mb-4">
                            Select a Jira board to track. MyCTOBot will watch for tickets
                            with the <code>ai-dev</code> label and start working automatically.
                        </p>

                        <a href="/boards/discover" class="btn btn-primary btn-lg px-5">
                            <i class="bi bi-search me-2"></i>Discover Boards
                        </a>

                        <div class="mt-4">
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                You can track Scrum or Kanban boards from any project.
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Step 5: Complete! -->
                <div id="wizardStep5" class="wizard-step" style="<?= $currentStep === 5 ? '' : 'display:none;' ?>">
                    <div class="text-center py-3">
                        <div class="celebration-icon mb-4">
                            <i class="bi bi-trophy-fill"></i>
                        </div>
                        <h4 class="mb-3 text-success">You're All Set!</h4>
                        <p class="text-muted mb-4">
                            MyCTOBot is ready to work. To start your first AI-powered implementation:
                        </p>

                        <div class="how-to-start bg-light rounded p-4 text-start mb-4">
                            <ol class="mb-0">
                                <li class="mb-2">Go to any ticket in your tracked Jira board</li>
                                <li class="mb-2">Add the label: <code class="bg-primary text-white px-2 py-1 rounded">ai-dev</code></li>
                                <li class="mb-0">Watch the magic happen!</li>
                            </ol>
                        </div>

                        <button type="button" class="btn btn-success btn-lg px-5" data-bs-dismiss="modal">
                            <i class="bi bi-rocket-takeoff me-2"></i>Let's Go!
                        </button>

                        <div class="mt-4 pt-3 border-top">
                            <p class="text-muted small mb-2">Want to customize how your AI works?</p>
                            <a href="/agents" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-robot me-1"></i>
                                Configure AI Agents
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="modal-footer border-0 pt-0">
                <div class="d-flex justify-content-between w-100">
                    <div>
                        <?php if (!$isComplete): ?>
                        <small class="text-muted">
                            Step <?= $currentStep ?> of 5
                        </small>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if (!$isComplete): ?>
                        <button type="button" class="btn btn-link text-muted" onclick="dismissOnboardingWizard()">
                            I'll finish later
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.onboarding-modal {
    border-radius: 16px;
    overflow: hidden;
}

/* Progress Steps */
.onboarding-progress {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 20px;
}

.progress-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
}

.step-circle {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #e9ecef;
    color: #6c757d;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.3s ease;
}

.progress-step.active .step-circle {
    background: #0d6efd;
    color: white;
    box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.2);
}

.progress-step.completed .step-circle {
    background: #198754;
    color: white;
}

.step-label {
    font-size: 11px;
    color: #6c757d;
    margin-top: 6px;
    white-space: nowrap;
}

.progress-step.active .step-label {
    color: #0d6efd;
    font-weight: 600;
}

.progress-step.completed .step-label {
    color: #198754;
}

.progress-line {
    width: 40px;
    height: 3px;
    background: #e9ecef;
    margin: 0 4px;
    margin-bottom: 20px;
    transition: background 0.3s ease;
}

.progress-line.completed {
    background: #198754;
}

/* Step Icons */
.step-icon {
    font-size: 64px;
    color: #333;
}

.step-icon.text-success { color: #198754 !important; }
.step-icon.text-info { color: #0dcaf0 !important; }
.step-icon.text-warning { color: #ffc107 !important; }

/* Celebration */
.celebration-icon {
    font-size: 80px;
    color: #ffc107;
    animation: bounce 0.6s ease infinite alternate;
}

@keyframes bounce {
    from { transform: translateY(0); }
    to { transform: translateY(-10px); }
}

.how-to-start ol {
    padding-left: 1.2rem;
}

.how-to-start li {
    color: #495057;
}

/* Responsive */
@media (max-width: 576px) {
    .step-label {
        display: none;
    }
    .progress-line {
        width: 20px;
        margin-bottom: 0;
    }
    .step-circle {
        width: 32px;
        height: 32px;
        font-size: 12px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-open wizard if needed
    <?php if ($showOnboarding && !$isComplete): ?>
    var wizardModal = new bootstrap.Modal(document.getElementById('onboardingWizard'));
    wizardModal.show();
    <?php endif; ?>
});

// Function to manually open wizard (for Setup Guide button)
function openOnboardingWizard() {
    var wizardModal = new bootstrap.Modal(document.getElementById('onboardingWizard'));
    wizardModal.show();
}

// Function to dismiss wizard and remember the preference
function dismissOnboardingWizard() {
    // Call API to save dismissal preference
    fetch('/settings/dismissWizard', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        // Close the modal
        var modal = bootstrap.Modal.getInstance(document.getElementById('onboardingWizard'));
        if (modal) {
            modal.hide();
        }
    })
    .catch(err => {
        // Still close modal even if API fails
        var modal = bootstrap.Modal.getInstance(document.getElementById('onboardingWizard'));
        if (modal) {
            modal.hide();
        }
    });
}
</script>
