<?php
// Extract pricing variables from controller-passed data
$p = $pricing ?? [];
$smallProject = $p['small_project_formatted'] ?? '$2,500';
$mediumProject = $p['medium_project_formatted'] ?? '$7,500';
$largeProject = $p['large_project_formatted'] ?? '$15,000';
$enterpriseProject = $p['enterprise_project_formatted'] ?? '$25,000';
$trialDays = $trialDays ?? 14;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'PHP Legacy Modernization - Don\'t Throw Away Your PHP | MyCTOBot') ?></title>
    <meta name="description" content="Transform your legacy PHP codebase to PHP 8.x with AI-powered modernization. WordPress, WooCommerce, and custom PHP applications upgraded safely.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fira+Code&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/landing/css/landing.css">
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
                <li><a href="#features">Features</a></li>
                <li><a href="#pricing">Pricing</a></li>
                <li><a href="#faq">FAQ</a></li>
            </ul>
            <a href="/signup" class="btn btn-primary">Get Started</a>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-badges">
                <span class="badge">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    WordPress & WooCommerce
                </span>
                <span class="badge">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    PHP 5.x to 8.x
                </span>
                <span class="badge">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    AI-Powered Analysis
                </span>
            </div>
            <h1>Don't Throw Away Your PHP.<br>Modernize It with AI.</h1>
            <p class="lead">Your PHP application isn't obsolete—it just needs a modern upgrade. MyCTOBot's AI analyzes your codebase and systematically modernizes it to PHP 8.x, preserving your business logic while adding type safety and performance.</p>
            <div class="hero-actions">
                <a href="/signup" class="btn btn-primary btn-lg">Start Free Analysis</a>
                <a href="#how-it-works" class="btn btn-white btn-lg">See How It Works</a>
            </div>
        </div>
    </section>

    <!-- Stats -->
    <section class="stats-bar">
        <div class="container">
            <div class="stats-grid">
                <div class="stat-item">
                    <h3>43%</h3>
                    <p>of websites use WordPress</p>
                </div>
                <div class="stat-item">
                    <h3>587M+</h3>
                    <p>WordPress sites worldwide</p>
                </div>
                <div class="stat-item">
                    <h3>33%</h3>
                    <p>e-commerce on WooCommerce</p>
                </div>
                <div class="stat-item">
                    <h3>70K+</h3>
                    <p>PHP plugins need updates</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Problem Section -->
    <section class="section">
        <div class="container">
            <div class="two-col">
                <div>
                    <span class="overline">The Problem</span>
                    <h2>Legacy PHP Is Costing You</h2>
                    <p>PHP 5.6 and 7.x are end-of-life. Your codebase is accumulating security vulnerabilities, missing performance improvements, and becoming harder to maintain.</p>
                    <ul class="pricing-features">
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor" style="color: var(--danger)"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                            Security patches no longer available
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor" style="color: var(--danger)"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                            Hosting providers dropping support
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor" style="color: var(--danger)"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                            Missing 40%+ performance gains
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor" style="color: var(--danger)"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                            Developer talent harder to find
                        </li>
                    </ul>
                </div>
                <div>
                    <div class="code-block">
                        <pre><code><span class="comment">// Before: PHP 5.6 style</span>
<span class="keyword">function</span> <span class="function">getUser</span>($id) {
    $user = User::find($id);
    <span class="keyword">if</span> ($user == null) {
        <span class="keyword">return</span> false;
    }
    <span class="keyword">return</span> $user;
}

<span class="comment">// After: PHP 8.x modern</span>
<span class="keyword">function</span> <span class="function">getUser</span>(<span class="keyword">int</span> $id): ?User
{
    <span class="keyword">return</span> User::find($id);
}</code></pre>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section class="section section-alt" id="how-it-works">
        <div class="container">
            <div class="section-header">
                <span class="overline">How It Works</span>
                <h2>AI-Powered Modernization Pipeline</h2>
                <p>Our AI analyzes your entire codebase, creates a modernization plan, and implements changes systematically—all while preserving your business logic.</p>
            </div>

            <div class="workflow">
                <div class="workflow-step">
                    <div class="icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/></svg>
                    </div>
                    <h4>Analyze</h4>
                    <p>Scan codebase for PHP version issues</p>
                </div>
                <div class="workflow-arrow">→</div>
                <div class="workflow-step">
                    <div class="icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                    </div>
                    <h4>Plan</h4>
                    <p>Generate prioritized upgrade tasks</p>
                </div>
                <div class="workflow-arrow">→</div>
                <div class="workflow-step">
                    <div class="icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                    </div>
                    <h4>Transform</h4>
                    <p>AI implements modern PHP syntax</p>
                </div>
                <div class="workflow-arrow">→</div>
                <div class="workflow-step">
                    <div class="icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <h4>Verify</h4>
                    <p>Run tests, validate changes</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Features -->
    <section class="section" id="features">
        <div class="container">
            <div class="section-header">
                <span class="overline">Features</span>
                <h2>What Gets Modernized</h2>
                <p>Comprehensive PHP 8.x transformation covering syntax, types, security, and best practices.</p>
            </div>

            <div class="feature-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                    </div>
                    <h4>Type Declarations</h4>
                    <p>Add typed properties, return types, and parameter types throughout your codebase.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <h4>Security Fixes</h4>
                    <p>Replace deprecated crypto functions, fix SQL injection risks, update password hashing.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h4>Modern Syntax</h4>
                    <p>Match expressions, named arguments, constructor promotion, null coalescing.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <h4>Static Analysis</h4>
                    <p>PHPStan level 5+ compliance, eliminating type errors before runtime.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </div>
                    <h4>Deprecated Functions</h4>
                    <p>Automatically replace deprecated functions with modern alternatives.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </div>
                    <h4>Test Coverage</h4>
                    <p>Generate PHPUnit tests for critical paths, ensuring changes don't break functionality.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Comparison -->
    <section class="section section-alt">
        <div class="container">
            <div class="section-header">
                <span class="overline">Why Choose AI</span>
                <h2>AI Modernization vs. Manual Rewrite</h2>
            </div>

            <table class="comparison-table">
                <thead>
                    <tr>
                        <th>Factor</th>
                        <th>Manual Rewrite</th>
                        <th>MyCTOBot AI</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Time to Complete</strong></td>
                        <td>3-6 months</td>
                        <td class="text-success"><strong>2-4 weeks</strong></td>
                    </tr>
                    <tr>
                        <td><strong>Cost</strong></td>
                        <td>$50,000-200,000</td>
                        <td class="text-success"><strong><?= htmlspecialchars($smallProject) ?>-<?= htmlspecialchars($enterpriseProject) ?></strong></td>
                    </tr>
                    <tr>
                        <td><strong>Risk of Regression</strong></td>
                        <td>High</td>
                        <td class="text-success"><strong>Low (systematic)</strong></td>
                    </tr>
                    <tr>
                        <td><strong>Business Logic Preserved</strong></td>
                        <td>Sometimes lost</td>
                        <td class="text-success"><strong>Always preserved</strong></td>
                    </tr>
                    <tr>
                        <td><strong>Documentation</strong></td>
                        <td>Usually skipped</td>
                        <td class="text-success"><strong>Auto-generated</strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Pricing -->
    <section class="section" id="pricing">
        <div class="container">
            <div class="section-header">
                <span class="overline">Pricing</span>
                <h2>Simple, Transparent Pricing</h2>
                <p>Pay once for modernization, or subscribe for ongoing maintenance.</p>
            </div>

            <div class="pricing-grid">
                <div class="pricing-card">
                    <div class="pricing-header">
                        <h3>Starter</h3>
                        <div class="price"><?= htmlspecialchars($smallProject) ?></div>
                        <p class="mb-0">Up to 50K lines of code</p>
                    </div>
                    <ul class="pricing-features">
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Full codebase analysis
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            PHP 8.x syntax upgrade
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Deprecated function fixes
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Security vulnerability scan
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Email support
                        </li>
                    </ul>
                    <a href="/signup" class="btn btn-outline">Get Started</a>
                </div>

                <div class="pricing-card featured">
                    <div class="pricing-header">
                        <h3>Business</h3>
                        <div class="price"><?= htmlspecialchars($mediumProject) ?></div>
                        <p class="mb-0">Up to 250K lines of code</p>
                    </div>
                    <ul class="pricing-features">
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Everything in Starter
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Type declarations added
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            PHPStan level 5 compliance
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Test suite generation
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Priority Slack support
                        </li>
                    </ul>
                    <a href="/signup" class="btn btn-primary">Get Started</a>
                </div>

                <div class="pricing-card">
                    <div class="pricing-header">
                        <h3>Enterprise</h3>
                        <div class="price"><?= htmlspecialchars($enterpriseProject) ?>+</div>
                        <p class="mb-0">Unlimited + ongoing support</p>
                    </div>
                    <ul class="pricing-features">
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Everything in Business
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Unlimited codebase size
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Custom migration strategy
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Dedicated engineer support
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            12 months maintenance
                        </li>
                    </ul>
                    <a href="/contact" class="btn btn-outline">Contact Sales</a>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section class="section section-alt" id="faq">
        <div class="container">
            <div class="section-header">
                <span class="overline">FAQ</span>
                <h2>Frequently Asked Questions</h2>
            </div>

            <div class="faq-list">
                <div class="faq-item">
                    <button class="faq-question">
                        Will this break my existing functionality?
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div class="faq-answer">
                        <p>No. Our AI preserves all business logic and generates comprehensive test coverage. We run full test suites before and after modernization to ensure everything works identically.</p>
                    </div>
                </div>
                <div class="faq-item">
                    <button class="faq-question">
                        Do you support WordPress plugins and themes?
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div class="faq-answer">
                        <p>Yes! We specialize in WordPress ecosystem modernization including plugins, themes, and WooCommerce extensions. Our AI understands WordPress hooks, filters, and coding standards.</p>
                    </div>
                </div>
                <div class="faq-item">
                    <button class="faq-question">
                        How long does the modernization take?
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div class="faq-answer">
                        <p>Typically 2-4 weeks depending on codebase size and complexity. We provide a detailed timeline after the initial analysis phase, which takes 24-48 hours.</p>
                    </div>
                </div>
                <div class="faq-item">
                    <button class="faq-question">
                        What PHP versions do you upgrade from/to?
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div class="faq-answer">
                        <p>We upgrade from PHP 5.6+ to PHP 8.1, 8.2, or 8.3 (your choice). We handle all intermediate version changes in a single modernization pass.</p>
                    </div>
                </div>
                <div class="faq-item">
                    <button class="faq-question">
                        Can I integrate AI features after modernization?
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div class="faq-answer">
                        <p>Absolutely. Modern PHP 8.x enables better integration with AI APIs and modern libraries. After modernization, you can use our AI Developer feature to implement new AI-powered features in your application.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="cta">
        <div class="container">
            <h2>Ready to Modernize Your PHP?</h2>
            <p>Start with a free codebase analysis. Get a detailed report on what needs to change and how long it will take.</p>
            <div class="cta-actions">
                <a href="/signup" class="btn btn-white btn-lg">Start Free Analysis</a>
                <a href="/contact" class="btn btn-outline btn-lg" style="border-color: #fff; color: #fff;">Talk to an Expert</a>
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
                        <li><a href="/#pricing">Platform Pricing</a></li>
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
