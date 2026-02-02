<?php
/**
 * Pipelines Feature Page
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
    <title><?= htmlspecialchars($title ?? 'AI Pipelines - Automation with AI Agents | MyCTOBot') ?></title>
    <meta name="description" content="Build powerful automation workflows with AI agents, shell commands, and webhooks. Like Zapier, but for developers who need AI and code execution.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fira+Code&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/landing/css/landing.css">
    <style>
        .pipeline-visual {
            background: var(--gray-900);
            border-radius: 12px;
            padding: 2rem;
            margin-top: 2rem;
            overflow-x: auto;
        }
        .pipeline-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            min-width: 600px;
        }
        .pipeline-col-header {
            text-align: center;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--gray-400);
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--gray-700);
            margin-bottom: 0.5rem;
        }
        .pipeline-cell {
            background: var(--gray-800);
            border: 1px solid var(--gray-700);
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
            transition: all 0.2s;
        }
        .pipeline-cell:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }
        .pipeline-cell .icon {
            width: 32px;
            height: 32px;
            margin: 0 auto 0.5rem;
            color: var(--primary-light);
        }
        .pipeline-cell .name {
            font-size: 0.875rem;
            font-weight: 600;
            color: #fff;
            margin-bottom: 0.25rem;
        }
        .pipeline-cell .type {
            font-size: 0.75rem;
            color: var(--gray-400);
        }
        .pipeline-cell.ai-agent { border-left: 3px solid #7c3aed; }
        .pipeline-cell.webhook { border-left: 3px solid #10b981; }
        .pipeline-cell.script { border-left: 3px solid #3b82f6; }
        .pipeline-cell.parser { border-left: 3px solid #f59e0b; }

        .step-type-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-top: 2rem;
        }
        .step-type-card {
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1.5rem;
            text-align: center;
        }
        .step-type-card .icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.75rem;
        }
        .step-type-card .icon svg {
            width: 24px;
            height: 24px;
            color: #fff;
        }
        .step-type-card h5 {
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }
        .step-type-card p {
            font-size: 0.75rem;
            color: var(--gray-500);
            margin: 0;
        }

        .comparison-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-top: 2rem;
        }
        @media (max-width: 768px) {
            .comparison-grid { grid-template-columns: 1fr; }
        }
        .competitor-card {
            background: var(--gray-50);
            border-radius: var(--radius-lg);
            padding: 2rem;
        }
        .competitor-card.highlight {
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.05), rgba(124, 58, 237, 0.05));
            border: 2px solid var(--primary);
        }
        .competitor-card h4 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .competitor-list {
            list-style: none;
        }
        .competitor-list li {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            padding: 0.5rem 0;
            font-size: 0.875rem;
        }
        .competitor-list svg {
            width: 16px;
            height: 16px;
            margin-top: 2px;
            flex-shrink: 0;
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
                <li><a href="#features">Examples</a></li>
                <li><a href="#step-types">Step Types</a></li>
                <li><a href="/#pricing">Platform Pricing</a></li>
            </ul>
            <a href="/signup" class="btn btn-primary">Start Building</a>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-badges">
                <span class="badge">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    AI Agent Steps
                </span>
                <span class="badge">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    Shell Execution
                </span>
                <span class="badge">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    MCP Compatible
                </span>
            </div>
            <h1>Zapier for Developers<br>Who Need AI and Code</h1>
            <p class="lead">Build powerful automation workflows with AI agents, shell commands, webhooks, and data transformations. A spreadsheet-like interface for complex technical pipelines.</p>
            <div class="hero-actions">
                <a href="/signup" class="btn btn-primary btn-lg">Start Free</a>
                <a href="#features" class="btn btn-white btn-lg">See Examples</a>
            </div>

            <div class="pipeline-visual">
                <div class="pipeline-grid">
                    <div>
                        <div class="pipeline-col-header">Start</div>
                        <div class="pipeline-cell webhook">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                            <div class="name">webhook_in</div>
                            <div class="type">Jira Webhook</div>
                        </div>
                    </div>
                    <div>
                        <div class="pipeline-col-header">Analyze</div>
                        <div class="pipeline-cell ai-agent">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            <div class="name">analyze_req</div>
                            <div class="type">AI Agent</div>
                        </div>
                    </div>
                    <div>
                        <div class="pipeline-col-header">Build</div>
                        <div class="pipeline-cell script">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            <div class="name">build_deploy</div>
                            <div class="type">Shell Command</div>
                        </div>
                    </div>
                    <div>
                        <div class="pipeline-col-header">Notify</div>
                        <div class="pipeline-cell webhook">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                            <div class="name">slack_notify</div>
                            <div class="type">Webhook Out</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Why Pipelines -->
    <section class="section">
        <div class="container">
            <div class="section-header">
                <span class="overline">Why Pipelines</span>
                <h2>More Than Simple Automations</h2>
                <p>Traditional automation tools are great for connecting apps. But what if you need AI reasoning, code execution, or complex data transformations?</p>
            </div>

            <div class="comparison-grid">
                <div class="competitor-card">
                    <h4>
                        <span style="font-size: 1.5rem;">🔗</span>
                        Traditional Automation (Zapier, Make)
                    </h4>
                    <ul class="competitor-list">
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor" style="color: var(--success)"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Connect 5,000+ apps
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor" style="color: var(--success)"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Simple trigger → action flows
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor" style="color: var(--danger)"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                            No AI reasoning
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor" style="color: var(--danger)"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                            No code execution
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor" style="color: var(--danger)"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                            Limited data transformation
                        </li>
                    </ul>
                </div>
                <div class="competitor-card highlight">
                    <h4>
                        <span style="font-size: 1.5rem;">🤖</span>
                        MyCTOBot Pipelines
                    </h4>
                    <ul class="competitor-list">
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor" style="color: var(--success)"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            <strong>AI Agent steps</strong> for intelligent decisions
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor" style="color: var(--success)"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            <strong>Shell execution</strong> on secure workstations
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor" style="color: var(--success)"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            <strong>Repo script</strong> integration
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor" style="color: var(--success)"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            <strong>jq/PHP parsers</strong> for complex transforms
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor" style="color: var(--success)"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            <strong>MCP tool exposure</strong> for AI assistants
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Step Types -->
    <section class="section section-alt" id="step-types">
        <div class="container">
            <div class="section-header">
                <span class="overline">Building Blocks</span>
                <h2>Powerful Step Types</h2>
                <p>Combine these primitives to build any automation workflow imaginable.</p>
            </div>

            <div class="step-type-grid">
                <div class="step-type-card">
                    <div class="icon" style="background: linear-gradient(135deg, #7c3aed, #6366f1);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </div>
                    <h5>AI Agent</h5>
                    <p>Claude analyzes, decides, and acts</p>
                </div>
                <div class="step-type-card">
                    <div class="icon" style="background: linear-gradient(135deg, #3b82f6, #0ea5e9);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                    </div>
                    <h5>Script</h5>
                    <p>Run scripts from your repo</p>
                </div>
                <div class="step-type-card">
                    <div class="icon" style="background: linear-gradient(135deg, #1e293b, #334155);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </div>
                    <h5>Shell Command</h5>
                    <p>Direct terminal execution</p>
                </div>
                <div class="step-type-card">
                    <div class="icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7v10c0 2 1 3 3 3h10c2 0 3-1 3-3V7c0-2-1-3-3-3H7c-2 0-3 1-3 3z"/><path d="M10 12h4M12 10v4"/></svg>
                    </div>
                    <h5>Parser</h5>
                    <p>Transform data with jq/PHP</p>
                </div>
                <div class="step-type-card">
                    <div class="icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                    </div>
                    <h5>Webhook Out</h5>
                    <p>POST to external services</p>
                </div>
                <div class="step-type-card">
                    <div class="icon" style="background: linear-gradient(135deg, #64748b, #475569);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <h5>Wait</h5>
                    <p>Await approval or events</p>
                </div>
                <div class="step-type-card">
                    <div class="icon" style="background: linear-gradient(135deg, #22c55e, #16a34a);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                    </div>
                    <h5>Harvest</h5>
                    <p>Collect parallel row results</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Example Pipelines -->
    <section class="section" id="features">
        <div class="container">
            <div class="section-header">
                <span class="overline">Examples</span>
                <h2>What Can You Build?</h2>
                <p>Real pipelines solving real problems.</p>
            </div>

            <div class="feature-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </div>
                    <h4>Deploy Pipeline</h4>
                    <p>PR merged → run tests → build Docker → deploy to K8s → notify Slack. Full CI/CD with AI-powered rollback decisions.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </div>
                    <h4>Content Pipeline</h4>
                    <p>Topic idea → AI writes draft → review queue → human approval → publish to CMS → social media posts.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                    <h4>Data Pipeline</h4>
                    <p>Poll API hourly → transform with jq → detect anomalies with AI → store in DB → alert if thresholds breached.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    </div>
                    <h4>Support Pipeline</h4>
                    <p>Ticket created → AI drafts response → check knowledge base → human review → send reply → update CRM.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <h4>Security Audit</h4>
                    <p>Nightly scan → run dependency checks → AI analyzes vulnerabilities → create Jira tickets → assign to team.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    <h4>E-commerce Sync</h4>
                    <p>Shopify order → update inventory system → sync to ERP → generate shipping label → email confirmation.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- MCP Integration -->
    <section class="section section-alt">
        <div class="container">
            <div class="two-col">
                <div>
                    <span class="overline">MCP Integration</span>
                    <h2>Expose Pipelines as AI Tools</h2>
                    <p>Any pipeline can be exposed as an MCP tool, making it callable from Claude Desktop, Claude Code, or any MCP-compatible AI assistant.</p>
                    <ul class="pricing-features">
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Define input schema
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Synchronous execution
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Results returned to AI
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Secure API authentication
                        </li>
                    </ul>
                </div>
                <div>
                    <div class="code-block">
                        <pre><code><span class="comment">// Claude can now call your pipeline!</span>

<span class="comment">// User: "Deploy the staging build"</span>
<span class="comment">// Claude uses myctobot_deploy-staging tool</span>

{
  <span class="string">"name"</span>: <span class="string">"myctobot_deploy-staging"</span>,
  <span class="string">"description"</span>: <span class="string">"Deploy to staging env"</span>,
  <span class="string">"inputSchema"</span>: {
    <span class="string">"type"</span>: <span class="string">"object"</span>,
    <span class="string">"properties"</span>: {
      <span class="string">"branch"</span>: { <span class="string">"type"</span>: <span class="string">"string"</span> },
      <span class="string">"notify"</span>: { <span class="string">"type"</span>: <span class="string">"boolean"</span> }
    }
  }
}</code></pre>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Included in Platform -->
    <section class="section" id="pricing">
        <div class="container">
            <div class="section-header">
                <span class="overline">Part of MyCTOBot</span>
                <h2>Included in Every Plan</h2>
                <p>Pipelines is a core feature of the MyCTOBot platform — not a separate product.</p>
            </div>

            <div class="two-col" style="align-items: center;">
                <div>
                    <h3 style="margin-bottom: 1rem;">Automate your entire workflow.</h3>
                    <p style="color: var(--gray-600); margin-bottom: 1.5rem;">
                        Pipelines work alongside AI Developer, Code Review, and all other MyCTOBot features.
                        Build complex automation workflows with AI agents, shell commands, and webhooks.
                    </p>
                    <ul class="pricing-features">
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Pipeline automation
                        </li>
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
                            MCP tool exposure
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
                                Unlimited pipelines
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
            <p>Get an AI engineering team with powerful automation. Start your free <?= $trialDays ?>-day trial.</p>
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
