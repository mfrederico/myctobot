<?php
/**
 * AI Code Review Feature Page
 * Part of MyCTOBot Platform - not sold separately
 */
use app\services\PricingService;

$trialDays = $trialDays ?? PricingService::getTrialDays();
$pricing = PricingService::getPlatformPricing();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'AI Code Review - Automated PR Analysis | MyCTOBot') ?></title>
    <meta name="description" content="AI-powered code review that catches bugs, security issues, and style violations before they reach production. Automated PR comments in minutes.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/landing/css/landing.css" rel="stylesheet">
    <style>
        .review-demo {
            background: var(--dark-bg);
            border-radius: 12px;
            overflow: hidden;
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 13px;
        }

        .review-header {
            background: linear-gradient(135deg, #238636 0%, #2ea043 100%);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .review-header i {
            font-size: 18px;
        }

        .review-body {
            padding: 0;
        }

        .code-file {
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .code-file-header {
            background: rgba(255,255,255,0.05);
            padding: 10px 16px;
            font-size: 12px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .code-lines {
            padding: 0;
        }

        .code-line {
            display: flex;
            padding: 2px 16px;
            font-size: 12px;
            line-height: 1.6;
        }

        .line-number {
            color: var(--text-muted);
            min-width: 40px;
            text-align: right;
            padding-right: 16px;
            user-select: none;
        }

        .line-content {
            flex: 1;
        }

        .line-added {
            background: rgba(46, 160, 67, 0.15);
        }

        .line-removed {
            background: rgba(248, 81, 73, 0.15);
        }

        .ai-comment {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(59, 130, 246, 0.1));
            border-left: 3px solid var(--primary);
            margin: 8px 16px;
            padding: 12px 16px;
            border-radius: 0 8px 8px 0;
        }

        .ai-comment-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }

        .ai-avatar {
            width: 24px;
            height: 24px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }

        .ai-name {
            font-weight: 600;
            color: var(--primary);
        }

        .ai-badge {
            background: var(--primary);
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .severity-critical {
            border-left-color: #f85149;
        }

        .severity-critical .ai-badge {
            background: #f85149;
        }

        .severity-warning {
            border-left-color: #d29922;
        }

        .severity-warning .ai-badge {
            background: #d29922;
        }

        .severity-info {
            border-left-color: #58a6ff;
        }

        .severity-info .ai-badge {
            background: #58a6ff;
        }

        .code-suggestion {
            background: rgba(0,0,0,0.3);
            border-radius: 6px;
            padding: 10px 12px;
            margin-top: 10px;
            font-size: 12px;
        }

        .code-suggestion-header {
            color: var(--text-muted);
            font-size: 10px;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card {
            background: var(--dark-bg);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            border-color: var(--primary);
            transform: translateY(-4px);
        }

        .stat-number {
            font-size: 48px;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-label {
            color: var(--text-muted);
            margin-top: 8px;
        }

        .check-list {
            list-style: none;
            padding: 0;
        }

        .check-list li {
            padding: 12px 0;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        .check-list li:last-child {
            border-bottom: none;
        }

        .check-list i {
            color: #2ea043;
            font-size: 18px;
            margin-top: 2px;
        }

        .integration-logos {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            opacity: 0.7;
        }

        .integration-logo {
            font-size: 32px;
            color: var(--text-muted);
        }

        .review-type-card {
            background: var(--dark-bg);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 24px;
            height: 100%;
            transition: all 0.3s ease;
        }

        .review-type-card:hover {
            border-color: var(--primary);
        }

        .review-type-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 16px;
        }

        .icon-security { background: rgba(248, 81, 73, 0.2); color: #f85149; }
        .icon-performance { background: rgba(210, 153, 34, 0.2); color: #d29922; }
        .icon-style { background: rgba(88, 166, 255, 0.2); color: #58a6ff; }
        .icon-tests { background: rgba(46, 160, 67, 0.2); color: #2ea043; }
        .icon-docs { background: rgba(163, 113, 247, 0.2); color: #a371f7; }
        .icon-deps { background: rgba(219, 171, 9, 0.2); color: #dbab09; }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top">
        <div class="container">
            <a class="navbar-brand" href="/">
                <i class="bi bi-robot"></i> MyCTOBot
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="#features">Features</a></li>
                    <li class="nav-item"><a class="nav-link" href="#how-it-works">How It Works</a></li>
                    <li class="nav-item"><a class="nav-link" href="/#pricing">Platform Pricing</a></li>
                    <li class="nav-item"><a class="nav-link" href="#faq">FAQ</a></li>
                </ul>
                <a href="/signup" class="btn btn-primary ms-3">Start Free Trial</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <span class="badge-pill">AI-Powered Code Review</span>
                    <h1 class="display-4 fw-bold mb-4">
                        Catch Bugs Before<br>
                        <span class="gradient-text">Your Users Do</span>
                    </h1>
                    <p class="lead mb-4">
                        AI code review that analyzes every PR for security vulnerabilities,
                        performance issues, and code quality problems. Get actionable feedback
                        in minutes, not hours.
                    </p>
                    <div class="d-flex gap-3 flex-wrap">
                        <a href="/signup" class="btn btn-primary btn-lg">
                            <i class="bi bi-github me-2"></i>Connect GitHub
                        </a>
                        <a href="#demo" class="btn btn-outline-light btn-lg">
                            <i class="bi bi-play-circle me-2"></i>See Demo
                        </a>
                    </div>
                    <div class="mt-4 text-muted">
                        <small><i class="bi bi-shield-check me-1"></i> SOC 2 compliant. Your code never leaves your infrastructure.</small>
                    </div>
                </div>
                <div class="col-lg-6 mt-5 mt-lg-0">
                    <!-- AI Review Demo -->
                    <div class="review-demo" id="demo">
                        <div class="review-header">
                            <i class="bi bi-git"></i>
                            <span>Pull Request #847: Add user authentication</span>
                        </div>
                        <div class="review-body">
                            <div class="code-file">
                                <div class="code-file-header">
                                    <i class="bi bi-file-code"></i>
                                    src/auth/login.js
                                </div>
                                <div class="code-lines">
                                    <div class="code-line">
                                        <span class="line-number">42</span>
                                        <span class="line-content">async function validateUser(email, password) {</span>
                                    </div>
                                    <div class="code-line line-added">
                                        <span class="line-number">43</span>
                                        <span class="line-content" style="color: #7ee787;">+ const query = `SELECT * FROM users WHERE email='${email}'`;</span>
                                    </div>
                                    <div class="code-line line-added">
                                        <span class="line-number">44</span>
                                        <span class="line-content" style="color: #7ee787;">+ const user = await db.query(query);</span>
                                    </div>
                                </div>

                                <!-- AI Comment - Critical -->
                                <div class="ai-comment severity-critical">
                                    <div class="ai-comment-header">
                                        <div class="ai-avatar"><i class="bi bi-robot"></i></div>
                                        <span class="ai-name">MyCTOBot</span>
                                        <span class="ai-badge">CRITICAL</span>
                                    </div>
                                    <div class="ai-comment-body">
                                        <strong>SQL Injection Vulnerability</strong><br>
                                        String interpolation in SQL queries allows attackers to execute arbitrary SQL.
                                        Use parameterized queries instead.
                                    </div>
                                    <div class="code-suggestion">
                                        <div class="code-suggestion-header">Suggested Fix</div>
                                        <code style="color: #7ee787;">const query = 'SELECT * FROM users WHERE email = ?';<br>
const user = await db.query(query, [email]);</code>
                                    </div>
                                </div>

                                <div class="code-lines">
                                    <div class="code-line line-added">
                                        <span class="line-number">45</span>
                                        <span class="line-content" style="color: #7ee787;">+ if (password === user.password) {</span>
                                    </div>
                                </div>

                                <!-- AI Comment - Warning -->
                                <div class="ai-comment severity-warning">
                                    <div class="ai-comment-header">
                                        <div class="ai-avatar"><i class="bi bi-robot"></i></div>
                                        <span class="ai-name">MyCTOBot</span>
                                        <span class="ai-badge">WARNING</span>
                                    </div>
                                    <div class="ai-comment-body">
                                        <strong>Plain Text Password Comparison</strong><br>
                                        Passwords should be hashed. Use bcrypt.compare() for secure password verification.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="py-5 border-bottom border-secondary">
        <div class="container">
            <div class="row g-4">
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-number">2M+</div>
                        <div class="stat-label">PRs Reviewed</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-number">94%</div>
                        <div class="stat-label">Issues Caught Pre-Merge</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-number">&lt;5min</div>
                        <div class="stat-label">Avg Review Time</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-number">500+</div>
                        <div class="stat-label">Teams Trust Us</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- What We Review Section -->
    <section class="py-6" id="features">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="display-6 fw-bold">Comprehensive Code Analysis</h2>
                <p class="lead text-muted">Every PR is analyzed across six critical dimensions</p>
            </div>

            <div class="row g-4">
                <div class="col-md-6 col-lg-4">
                    <div class="review-type-card">
                        <div class="review-type-icon icon-security">
                            <i class="bi bi-shield-exclamation"></i>
                        </div>
                        <h4>Security Vulnerabilities</h4>
                        <p class="text-muted mb-3">
                            Detect OWASP Top 10 vulnerabilities including SQL injection, XSS,
                            authentication flaws, and sensitive data exposure.
                        </p>
                        <ul class="check-list">
                            <li><i class="bi bi-check-circle-fill"></i><span>Injection attacks</span></li>
                            <li><i class="bi bi-check-circle-fill"></i><span>Auth bypass vulnerabilities</span></li>
                            <li><i class="bi bi-check-circle-fill"></i><span>Secrets in code detection</span></li>
                        </ul>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="review-type-card">
                        <div class="review-type-icon icon-performance">
                            <i class="bi bi-speedometer2"></i>
                        </div>
                        <h4>Performance Issues</h4>
                        <p class="text-muted mb-3">
                            Identify N+1 queries, memory leaks, inefficient algorithms,
                            and blocking operations before they impact users.
                        </p>
                        <ul class="check-list">
                            <li><i class="bi bi-check-circle-fill"></i><span>Database query optimization</span></li>
                            <li><i class="bi bi-check-circle-fill"></i><span>Memory leak detection</span></li>
                            <li><i class="bi bi-check-circle-fill"></i><span>Async/await best practices</span></li>
                        </ul>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="review-type-card">
                        <div class="review-type-icon icon-style">
                            <i class="bi bi-brush"></i>
                        </div>
                        <h4>Code Style & Standards</h4>
                        <p class="text-muted mb-3">
                            Enforce your team's coding standards and best practices
                            based on your CLAUDE.md or custom ruleset.
                        </p>
                        <ul class="check-list">
                            <li><i class="bi bi-check-circle-fill"></i><span>Custom ruleset support</span></li>
                            <li><i class="bi bi-check-circle-fill"></i><span>Naming conventions</span></li>
                            <li><i class="bi bi-check-circle-fill"></i><span>Code organization</span></li>
                        </ul>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="review-type-card">
                        <div class="review-type-icon icon-tests">
                            <i class="bi bi-check2-square"></i>
                        </div>
                        <h4>Test Coverage</h4>
                        <p class="text-muted mb-3">
                            Verify new code includes appropriate tests and
                            that edge cases are properly handled.
                        </p>
                        <ul class="check-list">
                            <li><i class="bi bi-check-circle-fill"></i><span>Missing test detection</span></li>
                            <li><i class="bi bi-check-circle-fill"></i><span>Edge case identification</span></li>
                            <li><i class="bi bi-check-circle-fill"></i><span>Test quality analysis</span></li>
                        </ul>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="review-type-card">
                        <div class="review-type-icon icon-docs">
                            <i class="bi bi-file-text"></i>
                        </div>
                        <h4>Documentation</h4>
                        <p class="text-muted mb-3">
                            Ensure public APIs and complex logic are properly
                            documented for future maintainability.
                        </p>
                        <ul class="check-list">
                            <li><i class="bi bi-check-circle-fill"></i><span>Missing JSDoc/PHPDoc</span></li>
                            <li><i class="bi bi-check-circle-fill"></i><span>README updates</span></li>
                            <li><i class="bi bi-check-circle-fill"></i><span>API documentation</span></li>
                        </ul>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="review-type-card">
                        <div class="review-type-icon icon-deps">
                            <i class="bi bi-box-seam"></i>
                        </div>
                        <h4>Dependency Analysis</h4>
                        <p class="text-muted mb-3">
                            Check for vulnerable dependencies, license conflicts,
                            and unnecessary package additions.
                        </p>
                        <ul class="check-list">
                            <li><i class="bi bi-check-circle-fill"></i><span>CVE vulnerability checks</span></li>
                            <li><i class="bi bi-check-circle-fill"></i><span>License compatibility</span></li>
                            <li><i class="bi bi-check-circle-fill"></i><span>Bundle size impact</span></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section class="py-6 bg-dark-gradient" id="how-it-works">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="display-6 fw-bold">Set Up in 60 Seconds</h2>
                <p class="lead text-muted">No configuration required. Just connect and go.</p>
            </div>

            <div class="row g-4">
                <div class="col-md-4">
                    <div class="step">
                        <div class="step-number">1</div>
                        <h4>Connect Your Repo</h4>
                        <p class="text-muted">
                            Install our GitHub app with one click. We only request
                            read access to pull requests.
                        </p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="step">
                        <div class="step-number">2</div>
                        <h4>Open a PR</h4>
                        <p class="text-muted">
                            Create or update any pull request. Our AI automatically
                            reviews the changes within minutes.
                        </p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="step">
                        <div class="step-number">3</div>
                        <h4>Fix & Ship</h4>
                        <p class="text-muted">
                            Address the inline comments, get AI approval, and
                            merge with confidence.
                        </p>
                    </div>
                </div>
            </div>

            <div class="text-center mt-5">
                <h5 class="mb-4">Works With Your Favorite Tools</h5>
                <div class="integration-logos">
                    <i class="bi bi-github integration-logo" title="GitHub"></i>
                    <i class="bi bi-gitlab integration-logo" title="GitLab"></i>
                    <i class="bi bi-git integration-logo" title="Bitbucket"></i>
                    <i class="bi bi-slack integration-logo" title="Slack"></i>
                    <i class="bi bi-discord integration-logo" title="Discord"></i>
                </div>
            </div>
        </div>
    </section>

    <!-- Included in Platform -->
    <section class="pricing-section" id="pricing">
        <div class="container">
            <div class="text-center mb-5">
                <span class="badge bg-primary mb-3">Part of MyCTOBot</span>
                <h2 class="display-6 fw-bold">Included in Every Plan</h2>
                <p class="lead text-muted">AI Code Review is a core feature of the MyCTOBot platform.</p>
            </div>

            <div class="row g-4 justify-content-center">
                <div class="col-lg-5">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-body p-4">
                            <h4 class="mb-4">One platform. Complete AI engineering.</h4>
                            <p class="text-muted mb-4">
                                Code Review works alongside AI Developer, Pipelines, and all other MyCTOBot features.
                                Every PR gets reviewed automatically — security, performance, style, and more.
                            </p>
                            <ul class="list-unstyled">
                                <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Automated code review on every PR</li>
                                <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>AI implementation agents</li>
                                <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Pipeline automation</li>
                                <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>All integrations included</li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="pricing-card featured">
                        <div class="featured-badge">Recommended</div>
                        <h4>MyCTOBot Pro</h4>
                        <div class="price"><?= htmlspecialchars($pricing['pro_monthly_formatted'] ?? '$1,999') ?><span class="price-period">/month</span></div>
                        <p class="text-muted mb-4">Full AI engineering platform</p>
                        <ul class="pricing-features">
                            <li><i class="bi bi-check-circle-fill text-success me-2"></i>Unlimited code reviews</li>
                            <li><i class="bi bi-check-circle-fill text-success me-2"></i>Unlimited projects</li>
                            <li><i class="bi bi-check-circle-fill text-success me-2"></i>All platform features</li>
                        </ul>
                        <a href="/signup" class="btn btn-primary w-100">Start <?= $trialDays ?>-Day Trial</a>
                        <p class="text-center mt-3 mb-0">
                            <a href="/#pricing" class="text-primary">View all plans →</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section class="py-6" id="faq">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="display-6 fw-bold">Frequently Asked Questions</h2>
            </div>

            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="faq-item">
                        <div class="faq-question">
                            <span>Is my code secure?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div class="faq-answer">
                            <p>Absolutely. We only process diffs from pull requests, never your full codebase.
                            All data is encrypted in transit and at rest. Enterprise customers can use our
                            self-hosted option where code never leaves your infrastructure. We're SOC 2 Type II compliant.</p>
                        </div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question">
                            <span>Which languages do you support?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div class="faq-answer">
                            <p>We support all major programming languages including JavaScript/TypeScript, Python,
                            Java, Go, Ruby, PHP, C#, Rust, and more. Our AI model understands language-specific
                            idioms and best practices.</p>
                        </div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question">
                            <span>Can I customize the review rules?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div class="faq-answer">
                            <p>Yes! On Team and Enterprise plans, you can upload your CLAUDE.md or custom ruleset
                            to enforce your team's specific coding standards. You can also configure severity
                            levels and which categories to check.</p>
                        </div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question">
                            <span>Does this replace human code review?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div class="faq-answer">
                            <p>No, we complement human reviewers. AI catches mechanical issues (security, performance,
                            style) so your senior developers can focus on architecture, design patterns, and
                            mentoring. Most teams see a 40% reduction in review turnaround time.</p>
                        </div>
                    </div>

                    <div class="faq-item">
                        <div class="faq-question">
                            <span>What if the AI is wrong?</span>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div class="faq-answer">
                            <p>You can dismiss any suggestion with a single click. Our AI learns from dismissals
                            to improve over time. False positive rates are typically under 5%, and we provide
                            confidence scores for each suggestion.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <div class="container text-center">
            <h2 class="display-6 fw-bold mb-4">Stop Hiring. Start Shipping.</h2>
            <p class="lead mb-4">Get an AI engineering team that reviews every PR automatically. Start your free <?= $trialDays ?>-day trial.</p>
            <div class="d-flex gap-3 justify-content-center flex-wrap">
                <a href="/signup" class="btn btn-light btn-lg">
                    Start Free Trial
                </a>
                <a href="/" class="btn btn-outline-light btn-lg">
                    Learn About MyCTOBot
                </a>
            </div>
            <p class="mt-3 text-white-50">Code Review is included in all MyCTOBot plans.</p>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-3">
                    <h5><i class="bi bi-robot me-2"></i>MyCTOBot</h5>
                    <p class="text-muted">AI engineering platform. From idea to production with AI that follows your rules.</p>
                </div>
                <div class="col-lg-2">
                    <h6>Product</h6>
                    <ul class="footer-links">
                        <li><a href="/#pricing">Pricing</a></li>
                        <li><a href="/docs">Documentation</a></li>
                    </ul>
                </div>
                <div class="col-lg-2">
                    <h6>Features</h6>
                    <ul class="footer-links">
                        <li><a href="/landing/aideveloper">AI Developer</a></li>
                        <li><a href="/landing/codereview">Code Review</a></li>
                        <li><a href="/landing/pipelines">Pipelines</a></li>
                    </ul>
                </div>
                <div class="col-lg-2">
                    <h6>Services</h6>
                    <ul class="footer-links">
                        <li><a href="/landing/phpmodernization">PHP Modernization</a></li>
                        <li><a href="/landing/shopifythemes">Shopify Themes</a></li>
                    </ul>
                </div>
                <div class="col-lg-2">
                    <h6>Company</h6>
                    <ul class="footer-links">
                        <li><a href="/contact">Contact</a></li>
                        <li><a href="/legal/privacy">Privacy</a></li>
                        <li><a href="/legal/terms">Terms</a></li>
                    </ul>
                </div>
                <div class="col-lg-2">
                    <h6>Connect</h6>
                    <div class="d-flex gap-3">
                        <a href="#" class="text-muted"><i class="bi bi-twitter-x"></i></a>
                        <a href="#" class="text-muted"><i class="bi bi-linkedin"></i></a>
                        <a href="#" class="text-muted"><i class="bi bi-github"></i></a>
                        <a href="#" class="text-muted"><i class="bi bi-discord"></i></a>
                    </div>
                </div>
            </div>
            <hr class="my-4 border-secondary">
            <div class="d-flex flex-wrap justify-content-between align-items-center">
                <p class="text-muted mb-0">&copy; <?= date('Y') ?> MyCTOBot. All rights reserved.</p>
                <div class="d-flex gap-3">
                    <a href="/privacy" class="text-muted">Privacy</a>
                    <a href="/terms" class="text-muted">Terms</a>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/landing/js/landing.js"></script>
</body>
</html>
