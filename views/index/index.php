<?php
// Centralized pricing from config
use app\services\PricingService;

// Get all product pricing for the product cards
$allPricing = $allProducts ?? PricingService::getAllProducts();
$trialDays = $trialDays ?? PricingService::getTrialDays();

// Pro pricing for the main pricing section (backwards compatibility)
$proMonthlyPrice = $allPricing['ai-developer']['pro_monthly'] ?? 500;
$proYearlyPrice = $allPricing['ai-developer']['pro_yearly'] ?? 4800;
?>
<!-- Hero Section -->
<div class="bg-dark text-white py-5" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);">
    <div class="container">
        <div class="row align-items-center py-4">
            <div class="col-lg-7">
                <span class="badge bg-primary mb-3">AI-Powered Development Platform</span>
                <h1 class="display-4 fw-bold mb-4">Your AI Development Team<br><span class="text-primary">That Never Sleeps</span></h1>
                <p class="lead mb-4 text-light opacity-75">
                    MyCTOBot is the complete AI development platform. From automated code implementation to intelligent code reviews, from workflow pipelines to legacy modernization&mdash;we've got your development needs covered.
                </p>
                <div class="d-flex flex-wrap gap-3 mb-4">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-robot text-primary me-2"></i>
                        <span class="small">AI Developer Agents</span>
                    </div>
                    <div class="d-flex align-items-center">
                        <i class="bi bi-diagram-3 text-info me-2"></i>
                        <span class="small">Smart Pipelines</span>
                    </div>
                    <div class="d-flex align-items-center">
                        <i class="bi bi-code-square text-success me-2"></i>
                        <span class="small">Code Review AI</span>
                    </div>
                    <div class="d-flex align-items-center">
                        <i class="bi bi-shop text-warning me-2"></i>
                        <span class="small">Shopify Themes</span>
                    </div>
                </div>
                <div class="d-grid gap-2 d-md-flex">
                    <?php if (\app\WorkspaceResolver::isDefault()): ?>
                        <a href="/signup" class="btn btn-primary btn-lg">
                            <i class="bi bi-play-fill"></i> Start Free Trial
                        </a>
                        <a href="#products" class="btn btn-outline-light btn-lg">
                            Explore Products
                        </a>
                    <?php elseif (!$isLoggedIn): ?>
                        <a href="/auth/login" class="btn btn-primary btn-lg">Sign In</a>
                    <?php else: ?>
                        <a href="/dashboard" class="btn btn-primary btn-lg">Go to Dashboard</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-5 text-center d-none d-lg-block">
                <div class="position-relative">
                    <!-- Stacked cards showing different products -->
                    <div class="card bg-dark border-secondary shadow-lg position-absolute" style="transform: rotate(6deg); top: 20px; right: 0; width: 90%; opacity: 0.7;">
                        <div class="card-body py-3 px-4">
                            <small class="text-success"><i class="bi bi-check-circle me-1"></i> Code Review Complete</small>
                        </div>
                    </div>
                    <div class="card bg-dark border-secondary shadow-lg position-absolute" style="transform: rotate(3deg); top: 10px; right: 10px; width: 90%; opacity: 0.85;">
                        <div class="card-body py-3 px-4">
                            <small class="text-info"><i class="bi bi-diagram-3 me-1"></i> Pipeline Running...</small>
                        </div>
                    </div>
                    <div class="card bg-dark border-primary shadow-lg" style="position: relative; z-index: 10;">
                        <div class="card-header bg-primary bg-opacity-25 border-primary py-2">
                            <div class="d-flex align-items-center">
                                <span class="text-danger me-2">&#9679;</span>
                                <span class="text-warning me-2">&#9679;</span>
                                <span class="text-success me-2">&#9679;</span>
                                <span class="text-muted small ms-2">AI Developer</span>
                            </div>
                        </div>
                        <div class="card-body text-start" style="font-family: monospace; font-size: 0.8rem;">
                            <p class="text-info mb-1"># Jira: SHOP-1234 ai-dev</p>
                            <p class="text-light mb-2">Add loyalty points display to product cards...</p>
                            <p class="text-success mb-0"><i class="bi bi-check-circle"></i> Branch created</p>
                            <p class="text-success mb-0"><i class="bi bi-check-circle"></i> 3 files modified</p>
                            <p class="text-primary mb-0"><i class="bi bi-git"></i> PR ready for review</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Products Section -->
<div class="py-5" id="products">
    <div class="container">
        <div class="text-center mb-5">
            <span class="badge bg-primary mb-3">Our Products</span>
            <h2 class="mb-3">Everything You Need to Ship Faster</h2>
            <p class="lead text-muted">Five powerful products, one platform. Choose what you need or use them all together.</p>
        </div>

        <div class="row g-4">
            <!-- AI Developer -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm border-0 hover-lift">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center mb-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 56px; height: 56px; background: linear-gradient(135deg, #7c3aed 0%, #2563eb 100%);">
                                <i class="bi bi-robot text-white" style="font-size: 1.5rem;"></i>
                            </div>
                            <div>
                                <h5 class="mb-0">AI Developer</h5>
                                <small class="text-muted">Label tickets, get PRs</small>
                            </div>
                        </div>
                        <p class="text-muted mb-3">
                            Label any Jira ticket with <code>ai-dev</code> and wake up to a pull request. AI that understands your codebase and follows your CLAUDE.md standards.
                        </p>
                        <ul class="list-unstyled small text-muted mb-3">
                            <li class="mb-1"><i class="bi bi-check text-success me-2"></i>Automatic PR creation</li>
                            <li class="mb-1"><i class="bi bi-check text-success me-2"></i>Follows your coding standards</li>
                            <li class="mb-1"><i class="bi bi-check text-success me-2"></i>Jira & GitHub integration</li>
                        </ul>
                        <a href="/landing/aideveloper" class="btn btn-outline-primary btn-sm">
                            Learn More <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                    <div class="card-footer bg-transparent border-0 px-4 pb-4">
                        <small class="text-muted">Starting at <strong class="text-dark"><?= $allPricing['ai-developer']['starter_monthly_formatted'] ?? '$150' ?>/mo</strong></small>
                    </div>
                </div>
            </div>

            <!-- AI Pipelines -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm border-0 hover-lift">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center mb-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 56px; height: 56px; background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                                <i class="bi bi-diagram-3 text-white" style="font-size: 1.5rem;"></i>
                            </div>
                            <div>
                                <h5 class="mb-0">AI Pipelines</h5>
                                <small class="text-muted">Automation with AI</small>
                            </div>
                        </div>
                        <p class="text-muted mb-3">
                            Build powerful automation workflows with AI agents, shell commands, and webhooks. Like Zapier, but for developers who need AI and code execution.
                        </p>
                        <ul class="list-unstyled small text-muted mb-3">
                            <li class="mb-1"><i class="bi bi-check text-success me-2"></i>Visual pipeline builder</li>
                            <li class="mb-1"><i class="bi bi-check text-success me-2"></i>AI agent steps</li>
                            <li class="mb-1"><i class="bi bi-check text-success me-2"></i>Webhook triggers</li>
                        </ul>
                        <a href="/landing/pipelines" class="btn btn-outline-success btn-sm">
                            Learn More <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                    <div class="card-footer bg-transparent border-0 px-4 pb-4">
                        <small class="text-muted">Starting at <strong class="text-dark"><?= $allPricing['pipelines']['starter_monthly_formatted'] ?? '$79' ?>/mo</strong></small>
                    </div>
                </div>
            </div>

            <!-- AI Code Review -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm border-0 hover-lift">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center mb-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 56px; height: 56px; background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);">
                                <i class="bi bi-code-square text-white" style="font-size: 1.5rem;"></i>
                            </div>
                            <div>
                                <h5 class="mb-0">AI Code Review</h5>
                                <small class="text-muted">Every PR reviewed</small>
                            </div>
                        </div>
                        <p class="text-muted mb-3">
                            AI-powered code review that catches bugs, security issues, and style violations before they reach production. Automated PR comments in minutes.
                        </p>
                        <ul class="list-unstyled small text-muted mb-3">
                            <li class="mb-1"><i class="bi bi-check text-success me-2"></i>Security vulnerability detection</li>
                            <li class="mb-1"><i class="bi bi-check text-success me-2"></i>Style enforcement</li>
                            <li class="mb-1"><i class="bi bi-check text-success me-2"></i>Inline PR comments</li>
                        </ul>
                        <a href="/landing/codereview" class="btn btn-outline-primary btn-sm">
                            Learn More <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                    <div class="card-footer bg-transparent border-0 px-4 pb-4">
                        <small class="text-muted">Starting at <strong class="text-dark"><?= $allPricing['code-review']['starter_monthly_formatted'] ?? '$49' ?>/mo</strong></small>
                    </div>
                </div>
            </div>

            <!-- PHP Modernization -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm border-0 hover-lift">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center mb-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 56px; height: 56px; background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                                <i class="bi bi-filetype-php text-white" style="font-size: 1.5rem;"></i>
                            </div>
                            <div>
                                <h5 class="mb-0">PHP Modernization</h5>
                                <small class="text-muted">Upgrade to PHP 8.x</small>
                            </div>
                        </div>
                        <p class="text-muted mb-3">
                            AI-powered PHP upgrades that modernize your legacy codebase. Add type hints, update deprecated functions, and adopt modern patterns automatically.
                        </p>
                        <ul class="list-unstyled small text-muted mb-3">
                            <li class="mb-1"><i class="bi bi-check text-success me-2"></i>PHP 8.x compatibility</li>
                            <li class="mb-1"><i class="bi bi-check text-success me-2"></i>Type safety upgrades</li>
                            <li class="mb-1"><i class="bi bi-check text-success me-2"></i>Modern pattern adoption</li>
                        </ul>
                        <a href="/landing/phpmodernization" class="btn btn-outline-secondary btn-sm">
                            Learn More <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                    <div class="card-footer bg-transparent border-0 px-4 pb-4">
                        <small class="text-muted">Starting at <strong class="text-dark"><?= $allPricing['php-modernization']['small_project_formatted'] ?? '$2,500' ?></strong> per project</small>
                    </div>
                </div>
            </div>

            <!-- Shopify Theme Factory -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm border-0 hover-lift">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center mb-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 56px; height: 56px; background: linear-gradient(135deg, #95bf47 0%, #5e8e3e 100%);">
                                <i class="bi bi-shop text-white" style="font-size: 1.5rem;"></i>
                            </div>
                            <div>
                                <h5 class="mb-0">Shopify Themes</h5>
                                <small class="text-muted">Email to theme</small>
                            </div>
                        </div>
                        <p class="text-muted mb-3">
                            Email your design requirements and receive a production-ready Shopify theme. Liquid templates, dynamic sections, and metafield support included.
                        </p>
                        <ul class="list-unstyled small text-muted mb-3">
                            <li class="mb-1"><i class="bi bi-check text-success me-2"></i>Custom Liquid templates</li>
                            <li class="mb-1"><i class="bi bi-check text-success me-2"></i>Dynamic sections</li>
                            <li class="mb-1"><i class="bi bi-check text-success me-2"></i>Mobile-first design</li>
                        </ul>
                        <a href="/landing/shopifythemes" class="btn btn-outline-success btn-sm">
                            Learn More <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                    <div class="card-footer bg-transparent border-0 px-4 pb-4">
                        <small class="text-muted">Starting at <strong class="text-dark"><?= $allPricing['shopify-themes']['starter_theme_formatted'] ?? '$5,000' ?></strong> per theme</small>
                    </div>
                </div>
            </div>

            <!-- Enterprise Platform -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow border-primary hover-lift">
                    <div class="card-header bg-primary text-white py-2 text-center">
                        <small class="fw-semibold">FULL PLATFORM</small>
                    </div>
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center mb-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 56px; height: 56px; background: linear-gradient(135deg, #1a1a2e 0%, #0f3460 100%);">
                                <i class="bi bi-building text-white" style="font-size: 1.5rem;"></i>
                            </div>
                            <div>
                                <h5 class="mb-0">Enterprise</h5>
                                <small class="text-muted">Everything included</small>
                            </div>
                        </div>
                        <p class="text-muted mb-3">
                            Full platform access with all products, dedicated workstations, custom integrations, priority support, and SLA guarantees.
                        </p>
                        <ul class="list-unstyled small text-muted mb-3">
                            <li class="mb-1"><i class="bi bi-check text-success me-2"></i>All products included</li>
                            <li class="mb-1"><i class="bi bi-check text-success me-2"></i>Dedicated infrastructure</li>
                            <li class="mb-1"><i class="bi bi-check text-success me-2"></i>Priority support & SLA</li>
                        </ul>
                        <a href="/contact" class="btn btn-primary btn-sm">
                            Contact Sales <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                    <div class="card-footer bg-transparent border-0 px-4 pb-4">
                        <small class="text-muted">Starting at <strong class="text-dark"><?= isset($allPricing['enterprise']['monthly']) ? '$' . number_format($allPricing['enterprise']['monthly']) : '$10,000' ?>/mo</strong></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- The Problem Section -->
<div class="py-5 bg-light">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8 text-center">
                <h2 class="mb-4">AI Without Guardrails is Chaos</h2>
                <p class="lead text-muted mb-5">
                    Generic AI coding assistants produce inconsistent results, ignore your architecture, and create security vulnerabilities.
                    You need AI that understands your codebase, follows your patterns, and builds within your framework.
                </p>
            </div>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="card h-100 border-danger border-opacity-25">
                    <div class="card-body">
                        <div class="text-danger mb-3"><i class="bi bi-exclamation-triangle" style="font-size: 2rem;"></i></div>
                        <h5>Generic AI</h5>
                        <ul class="text-muted small mb-0">
                            <li>Ignores your coding standards</li>
                            <li>Creates security vulnerabilities</li>
                            <li>Reinvents existing utilities</li>
                            <li>No context of your architecture</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 border-warning border-opacity-25">
                    <div class="card-body">
                        <div class="text-warning mb-3"><i class="bi bi-arrow-right" style="font-size: 2rem;"></i></div>
                        <h5>The Gap</h5>
                        <p class="text-muted small mb-0">
                            Between your ideas and production-ready code, there's infrastructure to connect,
                            patterns to follow, and security to maintain. AI needs structure to be useful.
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 border-success border-opacity-25">
                    <div class="card-body">
                        <div class="text-success mb-3"><i class="bi bi-check-circle" style="font-size: 2rem;"></i></div>
                        <h5>MyCTOBot</h5>
                        <ul class="text-muted small mb-0">
                            <li>Learns your codebase patterns</li>
                            <li>Follows CLAUDE.md guidelines</li>
                            <li>Uses your existing utilities</li>
                            <li>Security-first by design</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- How It Works Section -->
<div class="py-5" id="how-it-works">
    <div class="container">
        <h2 class="text-center mb-2">From Directive to Deployment</h2>
        <p class="text-center text-muted mb-5">A complete workflow that turns your vision into production code</p>

        <div class="row g-4">
            <div class="col-md-3">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-body text-center">
                        <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                            <span class="fw-bold text-primary">1</span>
                        </div>
                        <h5>CEO Directive</h5>
                        <p class="text-muted small mb-0">
                            Describe what you want to build in plain English. AI breaks it down into projects, epics, and implementable stories.
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-body text-center">
                        <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                            <span class="fw-bold text-primary">2</span>
                        </div>
                        <h5>Review & Approve</h5>
                        <p class="text-muted small mb-0">
                            Review the generated stories on a Kanban board. Approve what you want built, edit requirements, set priorities.
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-body text-center">
                        <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                            <span class="fw-bold text-primary">3</span>
                        </div>
                        <h5>AI Implements</h5>
                        <p class="text-muted small mb-0">
                            Claude Code agents implement each story following your CLAUDE.md guidelines, creating branches and PRs automatically.
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-body text-center">
                        <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                            <span class="fw-bold text-primary">4</span>
                        </div>
                        <h5>QA & Deploy</h5>
                        <p class="text-muted small mb-0">
                            Build QA releases with selected stories, merge to release branches, and deploy with confidence.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Framework & Guidelines Section -->
<div class="py-5 bg-dark text-white">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6">
                <span class="badge bg-info mb-3">Your Rules, AI's Execution</span>
                <h2 class="mb-4">CLAUDE.md: Your AI Constitution</h2>
                <p class="lead text-light opacity-75 mb-4">
                    Define your coding standards, architectural patterns, and security requirements in a single file.
                    Every AI agent follows these guidelines religiously.
                </p>
                <ul class="list-unstyled">
                    <li class="mb-3 d-flex align-items-start">
                        <i class="bi bi-check-circle-fill text-success me-3 mt-1"></i>
                        <div>
                            <strong>Framework Conventions</strong>
                            <p class="text-light opacity-50 small mb-0">RedBeanPHP relationships, FlightPHP patterns, your custom utilities</p>
                        </div>
                    </li>
                    <li class="mb-3 d-flex align-items-start">
                        <i class="bi bi-check-circle-fill text-success me-3 mt-1"></i>
                        <div>
                            <strong>Security Requirements</strong>
                            <p class="text-light opacity-50 small mb-0">CSRF protection, input validation, authentication patterns</p>
                        </div>
                    </li>
                    <li class="mb-3 d-flex align-items-start">
                        <i class="bi bi-check-circle-fill text-success me-3 mt-1"></i>
                        <div>
                            <strong>Code Style</strong>
                            <p class="text-light opacity-50 small mb-0">Naming conventions, file structure, documentation standards</p>
                        </div>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6">
                <div class="card bg-dark border-secondary">
                    <div class="card-header bg-secondary bg-opacity-25 border-secondary py-2">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-file-earmark-code text-info me-2"></i>
                            <span class="text-muted small">CLAUDE.md</span>
                        </div>
                    </div>
                    <div class="card-body" style="font-family: monospace; font-size: 0.75rem;">
<pre class="text-light mb-0" style="white-space: pre-wrap;"># Project Development Standards

## RedBeanPHP Rules (CRITICAL)
- Use Bean:: wrapper for all database operations
- ALWAYS use associations over manual FKs
- Bean operations for CRUD, never raw SQL exec

## Security Requirements
- Validate CSRF on all POST requests
- Use $this->sanitize() for user input
- Check permissions with Flight::hasLevel()

## File Structure
/controls  - Controllers (auto-routed)
/views     - PHP view templates
/services  - Business logic</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Use Cases Section -->
<div class="py-5 bg-light">
    <div class="container">
        <h2 class="text-center mb-2">Build Any Software System</h2>
        <p class="text-center text-muted mb-5">From MVPs to enterprise applications, MyCTOBot scales with your ambitions</p>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                                <i class="bi bi-cloud text-primary"></i>
                            </div>
                            <h5 class="mb-0">SaaS Applications</h5>
                        </div>
                        <p class="text-muted small">
                            Multi-workspace architectures, subscription billing, user management, API integrations.
                            Build production-ready SaaS with security baked in.
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="rounded-circle bg-success bg-opacity-10 d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                                <i class="bi bi-shop text-success"></i>
                            </div>
                            <h5 class="mb-0">E-Commerce</h5>
                        </div>
                        <p class="text-muted small">
                            Shopify apps, custom storefronts, inventory systems, payment integrations.
                            AI that understands commerce patterns.
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="rounded-circle bg-info bg-opacity-10 d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                                <i class="bi bi-gear text-info"></i>
                            </div>
                            <h5 class="mb-0">Internal Tools</h5>
                        </div>
                        <p class="text-muted small">
                            Admin dashboards, workflow automation, reporting systems.
                            Turn operational ideas into working software overnight.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Security Section -->
<div class="py-5">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6 order-lg-2">
                <span class="badge bg-danger mb-3">Enterprise Ready</span>
                <h2 class="mb-4">Security-Driven Development</h2>
                <p class="lead text-muted mb-4">
                    AI-generated code follows security best practices by default. Your CLAUDE.md defines
                    the security requirements, and every line of code is checked against them.
                </p>
                <div class="row g-3">
                    <div class="col-6">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-shield-lock text-danger me-2"></i>
                            <span class="small">OWASP Compliance</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-key text-danger me-2"></i>
                            <span class="small">OAuth 2.0 / CSRF</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-database-lock text-danger me-2"></i>
                            <span class="small">SQL Injection Prevention</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-incognito text-danger me-2"></i>
                            <span class="small">XSS Protection</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div class="card border-0 shadow-lg">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center mb-4">
                            <div class="rounded-circle bg-success d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                <i class="bi bi-check-lg text-white"></i>
                            </div>
                            <div>
                                <h6 class="mb-0">Security Audit Passed</h6>
                                <small class="text-muted">All checks validated</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <small>CSRF Protection</small>
                                <small class="text-success">Implemented</small>
                            </div>
                            <div class="progress" style="height: 4px;">
                                <div class="progress-bar bg-success" style="width: 100%"></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <small>Input Validation</small>
                                <small class="text-success">Implemented</small>
                            </div>
                            <div class="progress" style="height: 4px;">
                                <div class="progress-bar bg-success" style="width: 100%"></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <small>Authentication</small>
                                <small class="text-success">Implemented</small>
                            </div>
                            <div class="progress" style="height: 4px;">
                                <div class="progress-bar bg-success" style="width: 100%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="d-flex justify-content-between mb-1">
                                <small>Permission Checks</small>
                                <small class="text-success">Implemented</small>
                            </div>
                            <div class="progress" style="height: 4px;">
                                <div class="progress-bar bg-success" style="width: 100%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Integrations Section -->
<div class="py-5 bg-light">
    <div class="container">
        <h2 class="text-center mb-2">Connect Your Infrastructure</h2>
        <p class="text-center text-muted mb-5">MyCTOBot integrates with the tools you already use</p>

        <div class="row justify-content-center g-4">
            <div class="col-auto">
                <div class="card border-0 shadow-sm text-center px-4 py-3">
                    <i class="bi bi-github" style="font-size: 2.5rem;"></i>
                    <small class="text-muted mt-2">GitHub</small>
                </div>
            </div>
            <div class="col-auto">
                <div class="card border-0 shadow-sm text-center px-4 py-3">
                    <img src="https://cdn.worldvectorlogo.com/logos/jira-1.svg" alt="Jira" style="height: 2.5rem;">
                    <small class="text-muted mt-2">Jira</small>
                </div>
            </div>
            <div class="col-auto">
                <div class="card border-0 shadow-sm text-center px-4 py-3">
                    <img src="https://cdn.worldvectorlogo.com/logos/shopify.svg" alt="Shopify" style="height: 2.5rem;">
                    <small class="text-muted mt-2">Shopify</small>
                </div>
            </div>
            <div class="col-auto">
                <div class="card border-0 shadow-sm text-center px-4 py-3">
                    <img src="https://cdn.worldvectorlogo.com/logos/claude-ai-icon.svg" alt="Claude" style="height: 2.5rem;" onerror="this.outerHTML='<i class=\'bi bi-robot\' style=\'font-size: 2.5rem;\'></i>'">
                    <small class="text-muted mt-2">Claude AI</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Pricing Section -->
<div class="py-5">
    <div class="container">
        <h2 class="text-center mb-2">Simple, Transparent Pricing</h2>
        <p class="text-center text-muted mb-5">Start free, scale as you grow</p>

        <div class="row justify-content-center g-4">
            <div class="col-md-5">
                <div class="card h-100 shadow-sm">
                    <div class="card-body p-4">
                        <h5 class="text-muted">Free</h5>
                        <div class="display-5 fw-bold mb-3">$0<small class="fs-6 fw-normal text-muted">/month</small></div>
                        <ul class="list-unstyled mb-4">
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i> 1 project</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i> Daily digest emails</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i> GitHub integration</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i> Basic AI analysis</li>
                        </ul>
                        <?php if (\app\WorkspaceResolver::isDefault()): ?>
                        <a href="/signup" class="btn btn-outline-primary w-100">Get Started</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-5">
                <div class="card h-100 shadow border-primary">
                    <div class="card-header bg-primary text-white text-center py-2">
                        <small class="fw-semibold">RECOMMENDED</small>
                    </div>
                    <div class="card-body p-4">
                        <h5 class="text-primary">Pro</h5>
                        <div class="display-5 fw-bold mb-3">$<?= number_format($proMonthlyPrice) ?><small class="fs-6 fw-normal text-muted">/month</small></div>
                        <ul class="list-unstyled mb-4">
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i> Unlimited projects</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i> AI Developer agents</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i> CEO Directives</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i> Review Board & QA releases</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i> Shopify integration</li>
                            <li class="mb-2"><i class="bi bi-check text-success me-2"></i> Priority support</li>
                        </ul>
                        <?php if (\app\WorkspaceResolver::isDefault()): ?>
                        <a href="/signup" class="btn btn-primary w-100">Start <?= $trialDays ?>-Day Trial</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- CTA Section -->
<div class="py-5" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);">
    <div class="container text-center text-white">
        <h2 class="mb-3">Ready to Let Your Ideas Flow?</h2>
        <p class="lead mb-4 opacity-75">
            Connect your infrastructure, define your guidelines, and start building with AI that follows your rules.
        </p>
        <?php if (\app\WorkspaceResolver::isDefault()): ?>
            <a href="/signup" class="btn btn-primary btn-lg me-2">
                <i class="bi bi-plus-circle"></i> Create Your Workspace
            </a>
            <a href="#" class="btn btn-outline-light btn-lg" data-bs-toggle="modal" data-bs-target="#existingTeamModal">
                Sign In to Existing Team
            </a>
        <?php elseif (!$isLoggedIn): ?>
            <a href="/auth/login" class="btn btn-primary btn-lg">Sign In</a>
        <?php else: ?>
            <a href="/dashboard" class="btn btn-primary btn-lg">Go to Dashboard</a>
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
