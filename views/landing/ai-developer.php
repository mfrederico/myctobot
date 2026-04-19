<?php
/**
 * AI Developer Feature Page
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
    <title><?= h($title ?? 'AI Developer - Your Development Team That Never Sleeps | MyCTOBot') ?></title>
    <meta name="description" content="<?= h($p['description'] ?? 'Label any Jira ticket with ai-dev and wake up to a pull request. AI that understands your codebase, follows your standards, and ships code while you sleep.') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fira+Code&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/landing/css/landing.css">
    <style>
        .hero-terminal {
            background: #1e1e1e;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
            margin-top: 3rem;
            max-width: 700px;
        }
        .terminal-header {
            background: #323232;
            padding: 12px 16px;
            display: flex;
            gap: 8px;
        }
        .terminal-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        .terminal-dot.red { background: #ff5f56; }
        .terminal-dot.yellow { background: #ffbd2e; }
        .terminal-dot.green { background: #27c93f; }
        .terminal-body {
            padding: 20px;
            font-family: 'Fira Code', monospace;
            font-size: 14px;
            line-height: 1.6;
            color: #d4d4d4;
        }
        .terminal-line {
            margin-bottom: 8px;
        }
        .terminal-prompt { color: #98c379; }
        .terminal-cmd { color: #61afef; }
        .terminal-output { color: #abb2bf; }
        .terminal-success { color: #98c379; }
        .terminal-dim { color: #5c6370; }

        .label-demo {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #0052CC;
            color: white;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        .label-demo.ai-dev {
            background: linear-gradient(135deg, #7c3aed, #2563eb);
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <a href="/" class="navbar-brand">
                <svg viewBox="0 0 32 32" fill="currentColor"><path d="M16 2L4 8v16l12 6 12-6V8L16 2zm0 2.5l9 4.5v11l-9 4.5-9-4.5V9l9-4.5z"/></svg>
                MyCTOBot
            </a>
            <ul class="navbar-nav">
                <li><a href="#how-it-works">How It Works</a></li>
                <li><a href="#features">Use Cases</a></li>
                <li><a href="/#pricing">Platform Pricing</a></li>
            </ul>
            <a href="/signup" class="btn btn-primary">Start Free Trial</a>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-badges">
                <span class="badge">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    Jira Integration
                </span>
                <span class="badge">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    GitHub PRs
                </span>
                <span class="badge">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    Claude Code CLI
                </span>
            </div>
            <h1>Your Development Team<br>That Never Sleeps</h1>
            <p class="lead">Label any Jira ticket with <span class="label-demo ai-dev">ai-dev</span> and wake up to a pull request. AI that understands your codebase, follows your standards, and ships code while you sleep.</p>
            <div class="hero-actions">
                <a href="/signup" class="btn btn-primary btn-lg">Start <?= $trialDays ?>-Day Free Trial</a>
                <a href="#demo" class="btn btn-white btn-lg">Watch Demo</a>
            </div>

            <div class="hero-terminal">
                <div class="terminal-header">
                    <span class="terminal-dot red"></span>
                    <span class="terminal-dot yellow"></span>
                    <span class="terminal-dot green"></span>
                </div>
                <div class="terminal-body">
                    <div class="terminal-line">
                        <span class="terminal-dim">[22:47:03]</span>
                        <span class="terminal-output">Jira webhook received: PROJ-1234 labeled with ai-dev</span>
                    </div>
                    <div class="terminal-line">
                        <span class="terminal-dim">[22:47:05]</span>
                        <span class="terminal-output">Analyzing ticket requirements...</span>
                    </div>
                    <div class="terminal-line">
                        <span class="terminal-dim">[22:47:12]</span>
                        <span class="terminal-success">Requirements clear. Starting implementation.</span>
                    </div>
                    <div class="terminal-line">
                        <span class="terminal-dim">[22:47:15]</span>
                        <span class="terminal-output">Cloning repo: acme/frontend</span>
                    </div>
                    <div class="terminal-line">
                        <span class="terminal-dim">[22:48:23]</span>
                        <span class="terminal-output">Creating branch: fix/PROJ-1234-add-dark-mode</span>
                    </div>
                    <div class="terminal-line">
                        <span class="terminal-dim">[22:52:47]</span>
                        <span class="terminal-success">Implementation complete. 4 files changed.</span>
                    </div>
                    <div class="terminal-line">
                        <span class="terminal-dim">[22:53:01]</span>
                        <span class="terminal-output">Running tests... All 47 tests passed.</span>
                    </div>
                    <div class="terminal-line">
                        <span class="terminal-dim">[22:53:15]</span>
                        <span class="terminal-success">PR created: github.com/acme/frontend/pull/892</span>
                    </div>
                    <div class="terminal-line">
                        <span class="terminal-dim">[22:53:18]</span>
                        <span class="terminal-success">Jira ticket updated with PR link</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats -->
    <section class="stats-bar">
        <div class="container">
            <div class="stats-grid">
                <div class="stat-item">
                    <h3>5min</h3>
                    <p>Average time to first PR</p>
                </div>
                <div class="stat-item">
                    <h3>92%</h3>
                    <p>PR acceptance rate</p>
                </div>
                <div class="stat-item">
                    <h3>24/7</h3>
                    <p>Continuous development</p>
                </div>
                <div class="stat-item">
                    <h3>10x</h3>
                    <p>Backlog velocity increase</p>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section class="section" id="how-it-works">
        <div class="container">
            <div class="section-header">
                <span class="overline">How It Works</span>
                <h2>From Ticket to PR in Minutes</h2>
                <p>A seamless workflow that integrates directly into your existing Jira and GitHub setup.</p>
            </div>

            <div class="steps">
                <div class="step">
                    <div class="step-number">1</div>
                    <div class="step-content">
                        <h4>Label Your Ticket</h4>
                        <p>Add the <span class="label-demo ai-dev">ai-dev</span> label to any Jira ticket. That's it. No special format required-write tickets naturally.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-number">2</div>
                    <div class="step-content">
                        <h4>AI Analyzes Requirements</h4>
                        <p>Claude reads your ticket, acceptance criteria, and linked context. If anything is unclear, it posts clarifying questions to the ticket before proceeding.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-number">3</div>
                    <div class="step-content">
                        <h4>Code Implementation</h4>
                        <p>AI clones your repo, creates a feature branch, and implements the solution following your CLAUDE.md coding standards. Tests are written and run automatically.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-number">4</div>
                    <div class="step-content">
                        <h4>Pull Request Created</h4>
                        <p>A detailed PR is opened with summary, changes, and test results. The Jira ticket is updated with the PR link. Just review, approve, and merge.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CLAUDE.md Section -->
    <section class="section section-alt">
        <div class="container">
            <div class="two-col">
                <div>
                    <span class="overline">Your Rules, AI's Execution</span>
                    <h2>CLAUDE.md: Your AI Constitution</h2>
                    <p>Every line of code follows your team's standards. Define your patterns, security requirements, and conventions in a single file that AI follows religiously.</p>
                    <ul class="pricing-features">
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Framework-specific patterns
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Security requirements enforced
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Uses your existing utilities
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Consistent code style
                        </li>
                    </ul>
                </div>
                <div>
                    <div class="code-block">
                        <pre><code><span class="comment"># CLAUDE.md - Your AI Constitution</span>

<span class="keyword">## Framework Rules</span>
- Use React Query for data fetching
- Components in /components, hooks in /hooks
- Use Tailwind CSS, never inline styles

<span class="keyword">## Security Requirements</span>
- Sanitize all user input with DOMPurify
- Use parameterized queries, never string concat
- CSRF tokens on all POST requests

<span class="keyword">## Testing</span>
- Jest + React Testing Library
- Minimum 80% coverage on new code
- E2E tests for user-facing features</code></pre>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Use Cases -->
    <section class="section" id="features">
        <div class="container">
            <div class="section-header">
                <span class="overline">Use Cases</span>
                <h2>What Can AI Developer Build?</h2>
                <p>From bug fixes to features, AI handles the work while you focus on strategy.</p>
            </div>

            <div class="feature-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <h4>Bug Fixes</h4>
                    <p>50-70% of bug fixes auto-resolved. AI reads the error, finds the cause, and fixes it.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                    </div>
                    <h4>New Features</h4>
                    <p>Clear requirements = working code. AI implements CRUD operations, UI components, and integrations.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </div>
                    <h4>Refactoring</h4>
                    <p>Tech debt reduction on autopilot. AI modernizes code while preserving functionality.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <h4>Test Writing</h4>
                    <p>Coverage gaps filled automatically. AI writes unit, integration, and E2E tests.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <h4>Documentation</h4>
                    <p>API docs, READMEs, and inline comments generated from your code.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </div>
                    <h4>API Integration</h4>
                    <p>Connect to third-party APIs with proper error handling and rate limiting.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Included in Platform -->
    <section class="section section-alt" id="pricing">
        <div class="container">
            <div class="section-header">
                <span class="overline">Part of MyCTOBot</span>
                <h2>Included in Every Plan</h2>
                <p>AI Developer is a core feature of the MyCTOBot platform — not a separate product.</p>
            </div>

            <div class="two-col" style="align-items: center;">
                <div>
                    <h3 style="margin-bottom: 1rem;">One platform. Everything you need.</h3>
                    <p style="color: var(--gray-600); margin-bottom: 1.5rem;">
                        AI Developer works alongside Code Review, Pipelines, and all other MyCTOBot features.
                        Connect your tools once, get an AI engineering team that handles implementation, review, and deployment.
                    </p>
                    <ul class="pricing-features">
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            AI implementation agents
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Automated code review
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Pipeline automation
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Jira, GitHub, GitLab integrations
                        </li>
                    </ul>
                </div>
                <div>
                    <div class="pricing-card featured" style="max-width: 400px; margin: 0 auto;">
                        <div class="pricing-header">
                            <h3>MyCTOBot Pro</h3>
                            <div class="price"><?= $pricing['pro_monthly_formatted'] ?? '$1,999' ?><span>/month</span></div>
                            <p class="mb-0">Full AI engineering platform</p>
                        </div>
                        <ul class="pricing-features">
                            <li>
                                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                Unlimited AI Developer jobs
                            </li>
                            <li>
                                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                Unlimited projects
                            </li>
                            <li>
                                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                All platform features
                            </li>
                        </ul>
                        <a href="/signup" class="btn btn-primary">Start <?= $trialDays ?>-Day Trial</a>
                        <p style="font-size: 0.75rem; color: var(--gray-500); margin-top: 0.75rem; margin-bottom: 0;">
                            <a href="/#pricing" style="color: var(--primary);">View all plans →</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="cta">
        <div class="container">
            <h2>Stop Hiring. Start Shipping.</h2>
            <p>Get an AI engineering team that implements features while you sleep. Start your free <?= $trialDays ?>-day trial.</p>
            <div class="cta-actions">
                <a href="/signup" class="btn btn-white btn-lg">Start Free Trial</a>
                <a href="/" class="btn btn-outline btn-lg" style="border-color: #fff; color: #fff;">Learn About MyCTOBot</a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-grid">
                <div>
                    <div class="footer-brand">MyCTOBot</div>
                    <p>AI-powered development automation. From idea to production with AI that follows your rules.</p>
                </div>
                <div class="footer-column">
                    <h5>Product</h5>
                    <ul class="footer-links">
                        <li><a href="/features">Features</a></li>
                        <li><a href="/pricing">Pricing</a></li>
                        <li><a href="/docs">Documentation</a></li>
                    </ul>
                </div>
                <div class="footer-column">
                    <h5>Features</h5>
                    <ul class="footer-links">
                        <li><a href="/landing/aideveloper">AI Developer</a></li>
                        <li><a href="/landing/codereview">Code Review</a></li>
                        <li><a href="/landing/pipelines">Pipelines</a></li>
                    </ul>
                </div>
                <div class="footer-column">
                    <h5>Services</h5>
                    <ul class="footer-links">
                        <li><a href="/landing/phpmodernization">PHP Modernization</a></li>
                        <li><a href="/landing/shopifythemes">Shopify Themes</a></li>
                    </ul>
                </div>
                <div class="footer-column">
                    <h5>Company</h5>
                    <ul class="footer-links">
                        <li><a href="/contact">Contact</a></li>
                        <li><a href="/legal/privacy">Privacy</a></li>
                        <li><a href="/legal/terms">Terms</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?= date('Y') ?> ClickSimple, Inc. All rights reserved.</p>
                <p>Powered by <a href="https://claude.ai" style="color: var(--primary)">Claude AI</a></p>
            </div>
        </div>
    </footer>

    <script src="/landing/js/landing.js"></script>
</body>
</html>
