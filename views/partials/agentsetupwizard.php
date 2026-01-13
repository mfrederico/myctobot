<?php
/**
 * Agent Setup Wizard
 *
 * Step-by-step wizard for creating AI agents with pre-configured profiles.
 * Designed for users who are new to AI development systems.
 */
$csrf = $csrf['csrf_token'] ?? '';
?>

<!-- Agent Setup Wizard Modal -->
<div class="modal fade" id="agentSetupWizard" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title">
                    <i class="bi bi-robot"></i> Create Your AI Agent
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Progress Steps -->
                <div class="agent-wizard-progress mb-4">
                    <div class="d-flex justify-content-between position-relative">
                        <div class="progress position-absolute" style="top: 15px; left: 10%; width: 80%; height: 3px; z-index: 0;">
                            <div class="progress-bar bg-primary" id="agentWizardProgressBar" style="width: 0%"></div>
                        </div>
                        <div class="step-circle active" data-step="1">
                            <span class="step-number">1</span>
                            <span class="step-label">Profile</span>
                        </div>
                        <div class="step-circle" data-step="2">
                            <span class="step-number">2</span>
                            <span class="step-label">Provider</span>
                        </div>
                        <div class="step-circle" data-step="3">
                            <span class="step-number">3</span>
                            <span class="step-label">Tools</span>
                        </div>
                        <div class="step-circle" data-step="4">
                            <span class="step-number">4</span>
                            <span class="step-label">Review</span>
                        </div>
                    </div>
                </div>

                <!-- Step 1: Choose Profile -->
                <div class="wizard-step" id="agentStep1">
                    <div class="text-center mb-4">
                        <h4>What kind of AI agent do you need?</h4>
                        <p class="text-muted">Choose a pre-configured profile or start from scratch. Each profile is optimized for specific tasks.</p>
                    </div>

                    <div class="row g-3">
                        <!-- Code Developer -->
                        <div class="col-md-6">
                            <div class="card agent-profile-card h-100" data-profile="developer">
                                <div class="card-body">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="profile-icon bg-primary bg-opacity-10 text-primary rounded-3 p-3 me-3">
                                            <i class="bi bi-code-square fs-4"></i>
                                        </div>
                                        <div>
                                            <h5 class="mb-0">Code Developer</h5>
                                            <span class="badge bg-primary">Most Popular</span>
                                        </div>
                                    </div>
                                    <p class="text-muted small mb-3">
                                        Full-stack implementation agent that can write, modify, and refactor code.
                                        Includes GitHub integration for creating pull requests.
                                    </p>
                                    <div class="capabilities-preview">
                                        <span class="badge bg-light text-dark me-1"><i class="bi bi-check"></i> Code Writing</span>
                                        <span class="badge bg-light text-dark me-1"><i class="bi bi-check"></i> GitHub PRs</span>
                                        <span class="badge bg-light text-dark"><i class="bi bi-check"></i> File Editing</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Code Reviewer -->
                        <div class="col-md-6">
                            <div class="card agent-profile-card h-100" data-profile="reviewer">
                                <div class="card-body">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="profile-icon bg-success bg-opacity-10 text-success rounded-3 p-3 me-3">
                                            <i class="bi bi-search fs-4"></i>
                                        </div>
                                        <div>
                                            <h5 class="mb-0">Code Reviewer</h5>
                                            <span class="badge bg-success">Security Focus</span>
                                        </div>
                                    </div>
                                    <p class="text-muted small mb-3">
                                        Reviews code for bugs, security issues, and best practices.
                                        Provides detailed feedback without making changes.
                                    </p>
                                    <div class="capabilities-preview">
                                        <span class="badge bg-light text-dark me-1"><i class="bi bi-check"></i> Code Analysis</span>
                                        <span class="badge bg-light text-dark me-1"><i class="bi bi-check"></i> Security Audit</span>
                                        <span class="badge bg-light text-dark"><i class="bi bi-check"></i> Best Practices</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- QA Tester -->
                        <div class="col-md-6">
                            <div class="card agent-profile-card h-100" data-profile="tester">
                                <div class="card-body">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="profile-icon bg-info bg-opacity-10 text-info rounded-3 p-3 me-3">
                                            <i class="bi bi-browser-chrome fs-4"></i>
                                        </div>
                                        <div>
                                            <h5 class="mb-0">QA Tester</h5>
                                            <span class="badge bg-info text-dark">Browser Testing</span>
                                        </div>
                                    </div>
                                    <p class="text-muted small mb-3">
                                        Tests web applications using browser automation.
                                        Validates UI, captures screenshots, and reports issues.
                                    </p>
                                    <div class="capabilities-preview">
                                        <span class="badge bg-light text-dark me-1"><i class="bi bi-check"></i> UI Testing</span>
                                        <span class="badge bg-light text-dark me-1"><i class="bi bi-check"></i> Screenshots</span>
                                        <span class="badge bg-light text-dark"><i class="bi bi-check"></i> Automation</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Documentation Writer -->
                        <div class="col-md-6">
                            <div class="card agent-profile-card h-100" data-profile="docs">
                                <div class="card-body">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="profile-icon bg-warning bg-opacity-10 text-warning rounded-3 p-3 me-3">
                                            <i class="bi bi-journal-text fs-4"></i>
                                        </div>
                                        <div>
                                            <h5 class="mb-0">Documentation Writer</h5>
                                            <span class="badge bg-warning text-dark">Content</span>
                                        </div>
                                    </div>
                                    <p class="text-muted small mb-3">
                                        Creates and updates documentation, READMEs, and API docs.
                                        Analyzes code to generate accurate technical documentation.
                                    </p>
                                    <div class="capabilities-preview">
                                        <span class="badge bg-light text-dark me-1"><i class="bi bi-check"></i> Documentation</span>
                                        <span class="badge bg-light text-dark me-1"><i class="bi bi-check"></i> README</span>
                                        <span class="badge bg-light text-dark"><i class="bi bi-check"></i> API Docs</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Custom Agent -->
                        <div class="col-12">
                            <div class="card agent-profile-card border-dashed" data-profile="custom">
                                <div class="card-body py-3">
                                    <div class="d-flex align-items-center">
                                        <div class="profile-icon bg-secondary bg-opacity-10 text-secondary rounded-3 p-3 me-3">
                                            <i class="bi bi-gear fs-4"></i>
                                        </div>
                                        <div>
                                            <h5 class="mb-0">Start from Scratch</h5>
                                            <p class="text-muted small mb-0">Configure every option manually for full control</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Provider Selection -->
                <div class="wizard-step d-none" id="agentStep2">
                    <div class="text-center mb-4">
                        <h4>Choose Your AI Provider</h4>
                        <p class="text-muted">The provider determines which AI model powers your agent.</p>
                    </div>

                    <!-- Beginner Explanation -->
                    <div class="alert alert-light border mb-4">
                        <div class="d-flex">
                            <i class="bi bi-lightbulb text-warning me-3 fs-4"></i>
                            <div>
                                <strong>What is an AI Provider?</strong>
                                <p class="mb-0 small text-muted">
                                    AI providers are services that run large language models (LLMs). Think of it as choosing
                                    which "brain" powers your agent. Claude (by Anthropic) is optimized for coding tasks,
                                    while Ollama lets you run models locally on your own hardware.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <!-- Claude CLI (Recommended) -->
                        <div class="col-md-6">
                            <div class="card provider-card h-100" data-provider="claude_cli">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-terminal text-primary fs-3 me-2"></i>
                                            <h5 class="mb-0">Claude Code CLI</h5>
                                        </div>
                                        <span class="badge bg-success">Recommended</span>
                                    </div>
                                    <p class="text-muted small mb-3">
                                        Anthropic's official CLI tool for coding. Best for complex multi-file changes,
                                        supports MCP tools, and runs on remote workstation shards.
                                    </p>
                                    <ul class="list-unstyled small text-muted">
                                        <li><i class="bi bi-check-circle text-success me-1"></i> Most capable for code tasks</li>
                                        <li><i class="bi bi-check-circle text-success me-1"></i> Full MCP tool support</li>
                                        <li><i class="bi bi-check-circle text-success me-1"></i> Runs on workstation shards</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Ollama -->
                        <div class="col-md-6">
                            <div class="card provider-card h-100" data-provider="ollama">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-cpu text-success fs-3 me-2"></i>
                                            <h5 class="mb-0">Ollama</h5>
                                        </div>
                                        <span class="badge bg-info text-dark">Self-Hosted</span>
                                    </div>
                                    <p class="text-muted small mb-3">
                                        Run open-source models locally. Great for privacy-sensitive work
                                        or when you want to avoid API costs.
                                    </p>
                                    <ul class="list-unstyled small text-muted">
                                        <li><i class="bi bi-check-circle text-success me-1"></i> No API costs</li>
                                        <li><i class="bi bi-check-circle text-success me-1"></i> Data stays local</li>
                                        <li><i class="bi bi-info-circle text-muted me-1"></i> Requires Ollama server</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- OpenAI -->
                        <div class="col-md-6">
                            <div class="card provider-card h-100" data-provider="openai">
                                <div class="card-body">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="bi bi-stars text-dark fs-3 me-2"></i>
                                        <h5 class="mb-0">OpenAI</h5>
                                    </div>
                                    <p class="text-muted small mb-3">
                                        Use GPT-4 or other OpenAI models. Good alternative if you
                                        already have OpenAI API credits.
                                    </p>
                                    <ul class="list-unstyled small text-muted">
                                        <li><i class="bi bi-check-circle text-success me-1"></i> GPT-4 & GPT-3.5</li>
                                        <li><i class="bi bi-info-circle text-muted me-1"></i> Requires OpenAI API key</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Claude API -->
                        <div class="col-md-6">
                            <div class="card provider-card h-100" data-provider="claude_api">
                                <div class="card-body">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="bi bi-cloud text-info fs-3 me-2"></i>
                                        <h5 class="mb-0">Claude API</h5>
                                    </div>
                                    <p class="text-muted small mb-3">
                                        Direct API access to Claude models. Simpler setup but fewer
                                        features than Claude CLI.
                                    </p>
                                    <ul class="list-unstyled small text-muted">
                                        <li><i class="bi bi-check-circle text-success me-1"></i> Simple API integration</li>
                                        <li><i class="bi bi-info-circle text-muted me-1"></i> Requires Anthropic API key</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Provider Config (shown after selection) -->
                    <div class="mt-4 d-none" id="providerConfigSection">
                        <h6><i class="bi bi-gear"></i> Provider Configuration</h6>
                        <div id="providerConfigFields"></div>
                    </div>
                </div>

                <!-- Step 3: Tools & Capabilities -->
                <div class="wizard-step d-none" id="agentStep3">
                    <div class="text-center mb-4">
                        <h4>Configure Tools & Capabilities</h4>
                        <p class="text-muted">Tools extend what your agent can do beyond just reading and writing code.</p>
                    </div>

                    <!-- Beginner Explanation -->
                    <div class="alert alert-light border mb-4">
                        <div class="d-flex">
                            <i class="bi bi-lightbulb text-warning me-3 fs-4"></i>
                            <div>
                                <strong>What are MCP Tools?</strong>
                                <p class="mb-0 small text-muted">
                                    MCP (Model Context Protocol) tools let your agent interact with external services.
                                    For example, a GitHub tool lets the agent create pull requests, while a browser tool
                                    lets it test web pages. These are pre-configured based on your profile.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-6">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <i class="bi bi-plug"></i> MCP Tools
                                </div>
                                <div class="card-body">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input mcp-tool-check" type="checkbox" id="mcpGithub" value="github" checked>
                                        <label class="form-check-label" for="mcpGithub">
                                            <i class="bi bi-github"></i> GitHub
                                            <small class="text-muted d-block">Create branches, commits, and pull requests</small>
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input mcp-tool-check" type="checkbox" id="mcpFetch" value="fetch" checked>
                                        <label class="form-check-label" for="mcpFetch">
                                            <i class="bi bi-globe"></i> Web Fetch
                                            <small class="text-muted d-block">Fetch web pages and API responses</small>
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input mcp-tool-check" type="checkbox" id="mcpPlaywright" value="playwright">
                                        <label class="form-check-label" for="mcpPlaywright">
                                            <i class="bi bi-browser-chrome"></i> Playwright
                                            <small class="text-muted d-block">Browser automation for UI testing</small>
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input mcp-tool-check" type="checkbox" id="mcpMantic" value="mantic">
                                        <label class="form-check-label" for="mcpMantic">
                                            <i class="bi bi-search"></i> Mantic Search
                                            <small class="text-muted d-block">Intelligent code search and analysis</small>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <i class="bi bi-stars"></i> Agent Capabilities
                                </div>
                                <div class="card-body">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input capability-check" type="checkbox" id="capImpl" value="code_implementation" checked>
                                        <label class="form-check-label" for="capImpl">
                                            Code Implementation
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input capability-check" type="checkbox" id="capReview" value="code_review">
                                        <label class="form-check-label" for="capReview">
                                            Code Review
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input capability-check" type="checkbox" id="capTest" value="browser_testing">
                                        <label class="form-check-label" for="capTest">
                                            Browser Testing
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input capability-check" type="checkbox" id="capDocs" value="documentation">
                                        <label class="form-check-label" for="capDocs">
                                            Documentation
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input capability-check" type="checkbox" id="capSecurity" value="security_audit">
                                        <label class="form-check-label" for="capSecurity">
                                            Security Audit
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input capability-check" type="checkbox" id="capDebug" value="debugging">
                                        <label class="form-check-label" for="capDebug">
                                            Debugging
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Review & Create -->
                <div class="wizard-step d-none" id="agentStep4">
                    <div class="text-center mb-4">
                        <h4>Review Your Agent</h4>
                        <p class="text-muted">Confirm your agent configuration before creating.</p>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-4 text-muted">Name:</div>
                                <div class="col-8">
                                    <input type="text" class="form-control form-control-sm" id="agentName" placeholder="My AI Agent">
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-4 text-muted">Description:</div>
                                <div class="col-8">
                                    <textarea class="form-control form-control-sm" id="agentDescription" rows="2" placeholder="Optional description..."></textarea>
                                </div>
                            </div>
                            <hr>
                            <div class="row mb-2">
                                <div class="col-4 text-muted">Profile:</div>
                                <div class="col-8" id="reviewProfile">-</div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-4 text-muted">Provider:</div>
                                <div class="col-8" id="reviewProvider">-</div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-4 text-muted">MCP Tools:</div>
                                <div class="col-8" id="reviewMcpTools">-</div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-4 text-muted">Capabilities:</div>
                                <div class="col-8" id="reviewCapabilities">-</div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-success mt-3 d-none" id="agentCreatedSuccess">
                        <i class="bi bi-check-circle"></i> Agent created successfully!
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-link text-muted" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-outline-secondary d-none" id="agentWizardBack">
                    <i class="bi bi-arrow-left"></i> Back
                </button>
                <button type="button" class="btn btn-primary" id="agentWizardNext">
                    Next <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.agent-wizard-progress .step-circle {
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 80px;
}

.agent-wizard-progress .step-number {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #dee2e6;
    color: #6c757d;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 14px;
    transition: all 0.3s ease;
}

.agent-wizard-progress .step-circle.active .step-number,
.agent-wizard-progress .step-circle.completed .step-number {
    background: var(--bs-primary);
    color: white;
}

.agent-wizard-progress .step-circle.completed .step-number::after {
    content: '\2713';
}

.agent-wizard-progress .step-label {
    font-size: 11px;
    color: #6c757d;
    margin-top: 4px;
}

.agent-profile-card {
    cursor: pointer;
    transition: all 0.2s ease;
    border: 2px solid transparent;
}

.agent-profile-card:hover {
    border-color: var(--bs-primary);
    transform: translateY(-2px);
}

.agent-profile-card.selected {
    border-color: var(--bs-primary);
    background-color: rgba(var(--bs-primary-rgb), 0.05);
}

.agent-profile-card.border-dashed {
    border-style: dashed;
    border-color: #dee2e6;
}

.provider-card {
    cursor: pointer;
    transition: all 0.2s ease;
    border: 2px solid transparent;
}

.provider-card:hover {
    border-color: var(--bs-primary);
}

.provider-card.selected {
    border-color: var(--bs-primary);
    background-color: rgba(var(--bs-primary-rgb), 0.05);
}

.profile-icon {
    flex-shrink: 0;
}

.wizard-step {
    min-height: 300px;
}
</style>

<script>
(function() {
    // Wizard state
    const wizardState = {
        currentStep: 1,
        totalSteps: 4,
        profile: null,
        provider: 'claude_cli',
        providerConfig: {},
        mcpTools: ['github', 'fetch'],
        capabilities: ['code_implementation']
    };

    // Profile configurations
    const profileConfigs = {
        developer: {
            name: 'Code Developer',
            provider: 'claude_cli',
            mcpTools: ['github', 'fetch', 'mantic'],
            capabilities: ['code_implementation', 'refactoring', 'debugging']
        },
        reviewer: {
            name: 'Code Reviewer',
            provider: 'claude_cli',
            mcpTools: ['github', 'fetch'],
            capabilities: ['code_review', 'security_audit']
        },
        tester: {
            name: 'QA Tester',
            provider: 'claude_cli',
            mcpTools: ['playwright', 'fetch'],
            capabilities: ['browser_testing', 'debugging']
        },
        docs: {
            name: 'Documentation Writer',
            provider: 'claude_cli',
            mcpTools: ['github', 'fetch'],
            capabilities: ['documentation']
        },
        custom: {
            name: 'Custom Agent',
            provider: 'claude_cli',
            mcpTools: [],
            capabilities: []
        }
    };

    // Provider display names
    const providerNames = {
        claude_cli: 'Claude Code CLI',
        ollama: 'Ollama',
        openai: 'OpenAI',
        claude_api: 'Claude API'
    };

    // Initialize wizard
    function initWizard() {
        // Profile card selection
        document.querySelectorAll('.agent-profile-card').forEach(card => {
            card.addEventListener('click', function() {
                document.querySelectorAll('.agent-profile-card').forEach(c => c.classList.remove('selected'));
                this.classList.add('selected');
                wizardState.profile = this.dataset.profile;

                // Apply profile config
                const config = profileConfigs[wizardState.profile];
                if (config) {
                    wizardState.provider = config.provider;
                    wizardState.mcpTools = [...config.mcpTools];
                    wizardState.capabilities = [...config.capabilities];
                }
            });
        });

        // Provider card selection
        document.querySelectorAll('.provider-card').forEach(card => {
            card.addEventListener('click', function() {
                document.querySelectorAll('.provider-card').forEach(c => c.classList.remove('selected'));
                this.classList.add('selected');
                wizardState.provider = this.dataset.provider;
                showProviderConfig(wizardState.provider);
            });
        });

        // Navigation buttons
        document.getElementById('agentWizardNext').addEventListener('click', nextStep);
        document.getElementById('agentWizardBack').addEventListener('click', prevStep);
    }

    function showStep(step) {
        // Hide all steps
        document.querySelectorAll('.wizard-step').forEach(s => s.classList.add('d-none'));

        // Show current step
        document.getElementById('agentStep' + step).classList.remove('d-none');

        // Update progress
        document.querySelectorAll('.step-circle').forEach(c => {
            const stepNum = parseInt(c.dataset.step);
            c.classList.remove('active', 'completed');
            if (stepNum < step) c.classList.add('completed');
            if (stepNum === step) c.classList.add('active');
        });

        // Update progress bar
        const progress = ((step - 1) / (wizardState.totalSteps - 1)) * 100;
        document.getElementById('agentWizardProgressBar').style.width = progress + '%';

        // Update buttons
        const backBtn = document.getElementById('agentWizardBack');
        const nextBtn = document.getElementById('agentWizardNext');

        backBtn.classList.toggle('d-none', step === 1);

        if (step === wizardState.totalSteps) {
            nextBtn.innerHTML = '<i class="bi bi-check-lg"></i> Create Agent';
        } else {
            nextBtn.innerHTML = 'Next <i class="bi bi-arrow-right"></i>';
        }

        // Step-specific setup
        if (step === 2) {
            // Pre-select provider
            document.querySelectorAll('.provider-card').forEach(c => {
                c.classList.toggle('selected', c.dataset.provider === wizardState.provider);
            });
        }

        if (step === 3) {
            // Update checkboxes based on state
            document.querySelectorAll('.mcp-tool-check').forEach(cb => {
                cb.checked = wizardState.mcpTools.includes(cb.value);
            });
            document.querySelectorAll('.capability-check').forEach(cb => {
                cb.checked = wizardState.capabilities.includes(cb.value);
            });
        }

        if (step === 4) {
            updateReview();
        }
    }

    function nextStep() {
        // Validate current step
        if (wizardState.currentStep === 1 && !wizardState.profile) {
            alert('Please select an agent profile');
            return;
        }

        if (wizardState.currentStep === 3) {
            // Collect MCP tools and capabilities
            wizardState.mcpTools = [];
            document.querySelectorAll('.mcp-tool-check:checked').forEach(cb => {
                wizardState.mcpTools.push(cb.value);
            });

            wizardState.capabilities = [];
            document.querySelectorAll('.capability-check:checked').forEach(cb => {
                wizardState.capabilities.push(cb.value);
            });
        }

        if (wizardState.currentStep === wizardState.totalSteps) {
            // Create agent
            createAgent();
            return;
        }

        wizardState.currentStep++;
        showStep(wizardState.currentStep);
    }

    function prevStep() {
        if (wizardState.currentStep > 1) {
            wizardState.currentStep--;
            showStep(wizardState.currentStep);
        }
    }

    function showProviderConfig(provider) {
        const section = document.getElementById('providerConfigSection');
        const fields = document.getElementById('providerConfigFields');

        let html = '';

        if (provider === 'claude_cli') {
            html = `
                <div class="mb-3">
                    <label class="form-label small">Model</label>
                    <select class="form-select form-select-sm" id="configModel">
                        <option value="sonnet" selected>Claude Sonnet (Recommended)</option>
                        <option value="opus">Claude Opus (Most Capable)</option>
                        <option value="haiku">Claude Haiku (Fastest)</option>
                    </select>
                </div>
            `;
        } else if (provider === 'ollama') {
            html = `
                <div class="mb-3">
                    <label class="form-label small">Ollama Server URL</label>
                    <input type="url" class="form-control form-control-sm" id="configOllamaUrl"
                           value="http://localhost:11434" placeholder="http://localhost:11434">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Model</label>
                    <input type="text" class="form-control form-control-sm" id="configOllamaModel"
                           value="llama3" placeholder="llama3, codellama, mistral, etc.">
                </div>
            `;
        } else if (provider === 'openai') {
            html = `
                <div class="mb-3">
                    <label class="form-label small">OpenAI API Key</label>
                    <input type="password" class="form-control form-control-sm" id="configOpenaiKey"
                           placeholder="sk-...">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Model</label>
                    <select class="form-select form-select-sm" id="configOpenaiModel">
                        <option value="gpt-4" selected>GPT-4</option>
                        <option value="gpt-4-turbo">GPT-4 Turbo</option>
                        <option value="gpt-3.5-turbo">GPT-3.5 Turbo</option>
                    </select>
                </div>
            `;
        } else if (provider === 'claude_api') {
            html = `
                <div class="mb-3">
                    <label class="form-label small">Anthropic API Key</label>
                    <input type="password" class="form-control form-control-sm" id="configClaudeKey"
                           placeholder="sk-ant-...">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Model</label>
                    <select class="form-select form-select-sm" id="configClaudeModel">
                        <option value="claude-3-5-sonnet-20241022" selected>Claude 3.5 Sonnet</option>
                        <option value="claude-3-opus-20240229">Claude 3 Opus</option>
                        <option value="claude-3-haiku-20240307">Claude 3 Haiku</option>
                    </select>
                </div>
            `;
        }

        fields.innerHTML = html;
        section.classList.remove('d-none');
    }

    function updateReview() {
        const profileConfig = profileConfigs[wizardState.profile];

        document.getElementById('reviewProfile').textContent = profileConfig?.name || 'Custom';
        document.getElementById('reviewProvider').textContent = providerNames[wizardState.provider] || wizardState.provider;
        document.getElementById('reviewMcpTools').textContent = wizardState.mcpTools.length > 0
            ? wizardState.mcpTools.join(', ') : 'None';
        document.getElementById('reviewCapabilities').textContent = wizardState.capabilities.length > 0
            ? wizardState.capabilities.map(c => c.replace(/_/g, ' ')).join(', ') : 'None';

        // Set default name
        if (!document.getElementById('agentName').value) {
            document.getElementById('agentName').value = profileConfig?.name || 'My AI Agent';
        }
    }

    function createAgent() {
        const name = document.getElementById('agentName').value || 'My AI Agent';
        const description = document.getElementById('agentDescription').value || '';

        // Collect provider config
        const providerConfig = {};
        if (wizardState.provider === 'claude_cli') {
            const modelEl = document.getElementById('configModel');
            providerConfig.model = modelEl ? modelEl.value : 'sonnet';
        } else if (wizardState.provider === 'ollama') {
            const urlEl = document.getElementById('configOllamaUrl');
            const modelEl = document.getElementById('configOllamaModel');
            providerConfig.base_url = urlEl ? urlEl.value : 'http://localhost:11434';
            providerConfig.model = modelEl ? modelEl.value : 'llama3';
        } else if (wizardState.provider === 'openai') {
            const keyEl = document.getElementById('configOpenaiKey');
            const modelEl = document.getElementById('configOpenaiModel');
            providerConfig.api_key = keyEl ? keyEl.value : '';
            providerConfig.model = modelEl ? modelEl.value : 'gpt-4';
        } else if (wizardState.provider === 'claude_api') {
            const keyEl = document.getElementById('configClaudeKey');
            const modelEl = document.getElementById('configClaudeModel');
            providerConfig.api_key = keyEl ? keyEl.value : '';
            providerConfig.model = modelEl ? modelEl.value : 'claude-3-5-sonnet-20241022';
        }

        // Build MCP servers config
        const mcpServers = buildMcpServersConfig(wizardState.mcpTools);

        const data = {
            name: name,
            description: description,
            provider: wizardState.provider,
            provider_config: providerConfig,
            mcp_servers: mcpServers,
            capabilities: wizardState.capabilities,
            is_active: true
        };

        // Disable button while saving
        const nextBtn = document.getElementById('agentWizardNext');
        nextBtn.disabled = true;
        nextBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Creating...';

        fetch('/agents/createFromWizard', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': '<?= $csrf ?>'
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                document.getElementById('agentCreatedSuccess').classList.remove('d-none');
                nextBtn.innerHTML = '<i class="bi bi-check-lg"></i> Done';
                nextBtn.classList.remove('btn-primary');
                nextBtn.classList.add('btn-success');

                // Redirect to agent edit page after short delay
                setTimeout(() => {
                    if (result.agent_id) {
                        window.location.href = '/agents/edit/' + result.agent_id;
                    } else {
                        window.location.href = '/agents';
                    }
                }, 1500);
            } else {
                alert('Error: ' + (result.message || 'Failed to create agent'));
                nextBtn.disabled = false;
                nextBtn.innerHTML = '<i class="bi bi-check-lg"></i> Create Agent';
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
            nextBtn.disabled = false;
            nextBtn.innerHTML = '<i class="bi bi-check-lg"></i> Create Agent';
        });
    }

    function buildMcpServersConfig(tools) {
        const servers = {};

        tools.forEach(tool => {
            if (tool === 'github') {
                servers.github = {
                    command: 'npx',
                    args: ['-y', '@modelcontextprotocol/server-github'],
                    env: {
                        GITHUB_PERSONAL_ACCESS_TOKEN: '${GITHUB_TOKEN}'
                    }
                };
            } else if (tool === 'fetch') {
                servers.fetch = {
                    command: 'npx',
                    args: ['-y', '@modelcontextprotocol/server-fetch']
                };
            } else if (tool === 'playwright') {
                servers.playwright = {
                    command: 'npx',
                    args: ['@anthropic/mcp-server-playwright']
                };
            } else if (tool === 'mantic') {
                servers.mantic = {
                    command: 'npx',
                    args: ['-y', '@mantic-ai/mantic-mcp-server']
                };
            }
        });

        return servers;
    }

    // Initialize when DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWizard);
    } else {
        initWizard();
    }

    // Export for external use
    window.openAgentSetupWizard = function() {
        // Reset state
        wizardState.currentStep = 1;
        wizardState.profile = null;
        wizardState.provider = 'claude_cli';
        wizardState.mcpTools = ['github', 'fetch'];
        wizardState.capabilities = ['code_implementation'];

        // Reset UI
        document.querySelectorAll('.agent-profile-card').forEach(c => c.classList.remove('selected'));
        document.querySelectorAll('.provider-card').forEach(c => c.classList.remove('selected'));
        document.getElementById('agentName').value = '';
        document.getElementById('agentDescription').value = '';
        document.getElementById('agentCreatedSuccess').classList.add('d-none');
        document.getElementById('providerConfigSection').classList.add('d-none');

        const nextBtn = document.getElementById('agentWizardNext');
        nextBtn.disabled = false;
        nextBtn.classList.remove('btn-success');
        nextBtn.classList.add('btn-primary');

        showStep(1);

        const modal = new bootstrap.Modal(document.getElementById('agentSetupWizard'));
        modal.show();
    };
})();
</script>
