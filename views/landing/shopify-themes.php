<?php
// Extract pricing variables from controller
$p = $pricing ?? [];
$starterTheme = $p['starter_theme_formatted'] ?? '$5,000';
$proTheme = $p['pro_theme_formatted'] ?? '$10,000';
$enterpriseTheme = $p['enterprise_theme_formatted'] ?? '$15,000';
$maintenanceMonthly = $p['maintenance_monthly'] ?? 500;
$trialDays = $trialDays ?? 14;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'AI Shopify Theme Factory - Themes Built While You Sleep | MyCTOBot') ?></title>
    <meta name="description" content="Email your design requirements, get a production-ready Shopify theme. AI that understands Liquid, Dawn architecture, and Shopify best practices.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fira+Code&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/landing/css/landing.css">
    <style>
        .shopify-green { color: #96BF48; }
        .hero-shopify {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 50%, #1a2e1a 100%);
        }
        .theme-showcase {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-top: 2rem;
        }
        @media (max-width: 768px) {
            .theme-showcase { grid-template-columns: 1fr; }
        }
        .theme-card {
            background: #fff;
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: all 0.3s;
        }
        .theme-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-xl);
        }
        .theme-preview {
            height: 200px;
            background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-400);
            font-size: 3rem;
        }
        .theme-info {
            padding: 1.5rem;
        }
        .theme-info h4 {
            margin-bottom: 0.5rem;
        }
        .theme-info p {
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }
        .theme-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .theme-tag {
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
            background: var(--gray-100);
            border-radius: 1rem;
            color: var(--gray-600);
        }

        .email-demo {
            background: #fff;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl);
            max-width: 600px;
            margin: 2rem auto 0;
        }
        .email-header {
            background: var(--gray-100);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            gap: 0.5rem;
        }
        .email-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--gray-300);
        }
        .email-body {
            padding: 1.5rem;
        }
        .email-field {
            display: flex;
            margin-bottom: 0.75rem;
            font-size: 0.875rem;
        }
        .email-label {
            width: 60px;
            color: var(--gray-500);
            font-weight: 500;
        }
        .email-value {
            color: var(--gray-700);
        }
        .email-content {
            border-top: 1px solid var(--gray-200);
            padding-top: 1rem;
            margin-top: 0.5rem;
            color: var(--gray-600);
            line-height: 1.7;
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
                <li><a href="#features">Features</a></li>
                <li><a href="#pricing">Pricing</a></li>
            </ul>
            <a href="/signup" class="btn btn-primary">Get Started</a>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero hero-shopify">
        <div class="container">
            <div class="hero-badges">
                <span class="badge">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    Liquid Templates
                </span>
                <span class="badge">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    Dawn Architecture
                </span>
                <span class="badge">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    OS 2.0 Ready
                </span>
            </div>
            <h1>Shopify Themes Built<br>While You Sleep</h1>
            <p class="lead">Email us your design requirements, get a production-ready Shopify theme. Our AI understands Liquid templates, Dawn architecture, and Shopify best practices.</p>
            <div class="hero-actions">
                <a href="/signup" class="btn btn-primary btn-lg">Start Building</a>
                <a href="#how-it-works" class="btn btn-white btn-lg">See How It Works</a>
            </div>

            <div class="email-demo">
                <div class="email-header">
                    <span class="email-dot"></span>
                    <span class="email-dot"></span>
                    <span class="email-dot"></span>
                </div>
                <div class="email-body">
                    <div class="email-field">
                        <span class="email-label">To:</span>
                        <span class="email-value">tester01@myctobot.ai</span>
                    </div>
                    <div class="email-field">
                        <span class="email-label">Subject:</span>
                        <span class="email-value">New hero section with video background</span>
                    </div>
                    <div class="email-content">
                        <p><strong>Design Brief:</strong></p>
                        <p>Create a new hero section for our homepage with:</p>
                        <p>- Full-width video background (autoplay, muted, loop)<br>
                        - Centered headline and subtext overlay<br>
                        - Two CTAs: "Shop Now" and "Learn More"<br>
                        - Mobile: fallback to static image<br>
                        - Settings for video URL, headline text, button colors</p>
                        <p>Match the style of our existing Dawn-based theme.</p>
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
                    <h3>24hrs</h3>
                    <p>Average section delivery</p>
                </div>
                <div class="stat-item">
                    <h3>95%</h3>
                    <p>First-time approval rate</p>
                </div>
                <div class="stat-item">
                    <h3>100+</h3>
                    <p>Section types built</p>
                </div>
                <div class="stat-item">
                    <h3>$0</h3>
                    <p>Revision costs</p>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section class="section" id="how-it-works">
        <div class="container">
            <div class="section-header">
                <span class="overline">How It Works</span>
                <h2>From Brief to Deployed Theme</h2>
                <p>A seamless workflow that turns your design requirements into production Shopify code.</p>
            </div>

            <div class="steps">
                <div class="step">
                    <div class="step-number">1</div>
                    <div class="step-content">
                        <h4>Send Your Design Brief</h4>
                        <p>Email your requirements to your workspace inbox. Include mockups, Figma links, or just describe what you want in plain English.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-number">2</div>
                    <div class="step-content">
                        <h4>AI Analyzes & Clarifies</h4>
                        <p>Our AI parses your brief, identifies required sections and blocks, and asks clarifying questions if needed—all via email.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-number">3</div>
                    <div class="step-content">
                        <h4>Liquid Code Generated</h4>
                        <p>AI writes clean, maintainable Liquid templates following Dawn architecture patterns. Sections include full theme editor settings.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-number">4</div>
                    <div class="step-content">
                        <h4>Preview & Deploy</h4>
                        <p>Review in your development theme. When approved, AI creates a PR or deploys directly to your store. You're in control.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- What We Build -->
    <section class="section section-alt" id="features">
        <div class="container">
            <div class="section-header">
                <span class="overline">Capabilities</span>
                <h2>What Our AI Builds</h2>
                <p>From simple sections to complex custom features.</p>
            </div>

            <div class="feature-grid">
                <div class="feature-card">
                    <div class="feature-icon" style="background: linear-gradient(135deg, #96BF48, #5E8E3E);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/></svg>
                    </div>
                    <h4>Custom Sections</h4>
                    <p>Hero banners, product showcases, testimonials, FAQs, newsletter forms—any section type you need.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon" style="background: linear-gradient(135deg, #96BF48, #5E8E3E);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                    </div>
                    <h4>App Blocks</h4>
                    <p>Metafield displays, custom product badges, dynamic content blocks that work in any section.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon" style="background: linear-gradient(135deg, #96BF48, #5E8E3E);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h4>Performance Optimization</h4>
                    <p>Lazy loading, critical CSS, image optimization. Core Web Vitals improvements built-in.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon" style="background: linear-gradient(135deg, #96BF48, #5E8E3E);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                    </div>
                    <h4>Product Filtering</h4>
                    <p>Ajax-powered collection filtering, search enhancements, sort options with smooth transitions.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon" style="background: linear-gradient(135deg, #96BF48, #5E8E3E);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                    </div>
                    <h4>Theme Settings</h4>
                    <p>Full customization in the theme editor. Colors, typography, spacing—all configurable by merchants.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon" style="background: linear-gradient(135deg, #96BF48, #5E8E3E);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                    <h4>Mobile-First Design</h4>
                    <p>Responsive breakpoints, touch interactions, mobile navigation. Every section works on every device.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- For Agencies -->
    <section class="section">
        <div class="container">
            <div class="two-col">
                <div>
                    <span class="overline">For Agencies</span>
                    <h2>Scale Your Shopify Practice</h2>
                    <p>Stop saying no to theme work. With MyCTOBot, you can take on more Shopify projects without hiring more developers.</p>
                    <ul class="pricing-features">
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Predictable delivery timelines
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Consistent code quality
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            White-label ready
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Free up senior devs for complex work
                        </li>
                    </ul>
                    <a href="/signup" class="btn btn-primary mt-4">Start Agency Trial</a>
                </div>
                <div>
                    <div class="theme-showcase" style="grid-template-columns: 1fr 1fr;">
                        <div class="theme-card">
                            <div class="theme-preview">🏪</div>
                            <div class="theme-info">
                                <h4>Fashion Store</h4>
                                <p>Full theme + 12 custom sections</p>
                                <div class="theme-tags">
                                    <span class="theme-tag">Lookbook</span>
                                    <span class="theme-tag">Size Guide</span>
                                </div>
                            </div>
                        </div>
                        <div class="theme-card">
                            <div class="theme-preview">🍕</div>
                            <div class="theme-info">
                                <h4>Food & Bev</h4>
                                <p>Menu sections + ordering flow</p>
                                <div class="theme-tags">
                                    <span class="theme-tag">Menu Grid</span>
                                    <span class="theme-tag">Hours</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing -->
    <section class="section section-alt" id="pricing">
        <div class="container">
            <div class="section-header">
                <span class="overline">Pricing</span>
                <h2>Pay Per Section or Go Unlimited</h2>
            </div>

            <div class="pricing-grid">
                <div class="pricing-card">
                    <div class="pricing-header">
                        <h3>Per Section</h3>
                        <div class="price">$150<span>/section</span></div>
                        <p class="mb-0">Perfect for one-off needs</p>
                    </div>
                    <ul class="pricing-features">
                        <li><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Custom Liquid section</li>
                        <li><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Theme editor settings</li>
                        <li><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Mobile responsive</li>
                        <li><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>1 revision included</li>
                    </ul>
                    <a href="/signup" class="btn btn-outline">Get Started</a>
                </div>

                <div class="pricing-card featured">
                    <div class="pricing-header">
                        <h3>Agency</h3>
                        <div class="price">$2,000<span>/month</span></div>
                        <p class="mb-0">Unlimited sections</p>
                    </div>
                    <ul class="pricing-features">
                        <li><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Unlimited sections/month</li>
                        <li><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Multiple stores</li>
                        <li><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Priority queue</li>
                        <li><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Unlimited revisions</li>
                        <li><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>White-label delivery</li>
                    </ul>
                    <a href="/signup" class="btn btn-primary">Start Free Trial</a>
                </div>

                <div class="pricing-card">
                    <div class="pricing-header">
                        <h3>Full Theme</h3>
                        <div class="price"><?= htmlspecialchars($starterTheme) ?>-<?= htmlspecialchars($enterpriseTheme) ?></div>
                        <p class="mb-0">Complete custom theme</p>
                    </div>
                    <ul class="pricing-features">
                        <li><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Full theme from design</li>
                        <li><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>All standard sections</li>
                        <li><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Custom sections included</li>
                        <li><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Deployment included</li>
                        <li><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>30-day support</li>
                    </ul>
                    <a href="/contact" class="btn btn-outline">Get Quote</a>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="cta">
        <div class="container">
            <h2>Ready to Scale Your Shopify Business?</h2>
            <p>Connect your store and send your first design brief in minutes.</p>
            <div class="cta-actions">
                <a href="/signup" class="btn btn-white btn-lg">Get Started Free</a>
                <a href="/contact" class="btn btn-outline btn-lg" style="border-color: #fff; color: #fff;">Talk to Sales</a>
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
                    <h5>Solutions</h5>
                    <ul class="footer-links">
                        <li><a href="/landing/phpmodernization">PHP Modernization</a></li>
                        <li><a href="/landing/aideveloper">AI Developer</a></li>
                        <li><a href="/landing/shopifythemes">Shopify Themes</a></li>
                        <li><a href="/landing/pipelines">Pipelines</a></li>
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
