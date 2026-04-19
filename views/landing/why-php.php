<?php
/**
 * Why PHP - Making the case for PHP in an AI-dominated Node/Python world
 */
use app\services\PricingService;

$trialDays = $trialDays ?? PricingService::getTrialDays();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Why PHP? The Case for the Web's Most Battle-Tested Language | MyCTOBot</title>
    <meta name="description" content="PHP powers 77% of the web. Facebook scaled with it. Netflix uses it. Here's why MyCTOBot is opinionated towards PHP over Node and Python.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fira+Code&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/landing/css/landing.css">
    <style>
        .stat-huge {
            font-size: 4rem;
            font-weight: 700;
            background: linear-gradient(135deg, #4F46E5, #7C3AED);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1;
        }
        .comparison-table {
            width: 100%;
            border-collapse: collapse;
            margin: 2rem 0;
        }
        .comparison-table th,
        .comparison-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }
        .comparison-table th {
            background: var(--gray-50);
            font-weight: 600;
        }
        .comparison-table tr:hover {
            background: var(--gray-50);
        }
        .logo-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 2rem;
            align-items: center;
            justify-content: center;
            margin: 2rem 0;
            opacity: 0.7;
        }
        .logo-grid span {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-500);
        }
        .timeline-item {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .timeline-year {
            flex-shrink: 0;
            width: 80px;
            font-weight: 700;
            color: var(--primary);
        }
        .timeline-content h4 {
            margin-bottom: 0.25rem;
        }
        .timeline-content p {
            color: var(--gray-600);
            margin: 0;
        }
        .incident-card {
            background: #FEF2F2;
            border-left: 4px solid #EF4444;
            padding: 1rem 1.5rem;
            margin-bottom: 1rem;
            border-radius: 0 8px 8px 0;
        }
        .incident-card h5 {
            color: #DC2626;
            margin-bottom: 0.25rem;
        }
        .incident-card p {
            color: #7F1D1D;
            margin: 0;
            font-size: 0.875rem;
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
                <li><a href="#the-numbers">The Numbers</a></li>
                <li><a href="#vs-alternatives">vs Node/Python</a></li>
                <li><a href="#modern-php">Modern PHP</a></li>
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
                    77% of the Web
                </span>
                <span class="badge">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    Battle-Tested at Scale
                </span>
                <span class="badge">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    Zero Supply Chain Drama
                </span>
            </div>
            <h1>Why PHP?<br>Because It Actually Works.</h1>
            <p class="lead">The AI world is obsessed with Node and Python. We're not. MyCTOBot is bringing PHP into the AI age — because 77% of the web deserves modern AI tooling too.</p>
            <div class="hero-actions">
                <a href="/signup" class="btn btn-primary btn-lg">Build with PHP</a>
                <a href="#the-numbers" class="btn btn-white btn-lg">See the Data</a>
            </div>
        </div>
    </section>

    <!-- Stats -->
    <section class="stats-bar" id="the-numbers">
        <div class="container">
            <div class="stats-grid">
                <div class="stat-item">
                    <h3>77%</h3>
                    <p>of websites use PHP</p>
                </div>
                <div class="stat-item">
                    <h3>43%</h3>
                    <p>run WordPress alone</p>
                </div>
                <div class="stat-item">
                    <h3>3B+</h3>
                    <p>Facebook users on PHP</p>
                </div>
                <div class="stat-item">
                    <h3>30yrs</h3>
                    <p>of battle-tested stability</p>
                </div>
            </div>
        </div>
    </section>

    <!-- The Giants -->
    <section class="section">
        <div class="container">
            <div class="section-header">
                <span class="overline">Proven at Scale</span>
                <h2>The Biggest Sites in the World Run PHP</h2>
                <p>This isn't a toy language. It's the backbone of the internet.</p>
            </div>

            <div class="logo-grid">
                <span>Facebook/Meta</span>
                <span>Wikipedia</span>
                <span>WordPress</span>
                <span>Slack</span>
                <span>Etsy</span>
                <span>Mailchimp</span>
                <span>Tumblr</span>
                <span>Vimeo</span>
            </div>

            <div class="two-col" style="margin-top: 3rem;">
                <div>
                    <h3>Facebook scaled to 3 billion users on PHP</h3>
                    <p>When Facebook hit scaling problems, they didn't rewrite in Node or Python. They made PHP faster — creating HHVM and later Hack. The core is still PHP.</p>
                    <p>Why? Because PHP's shared-nothing architecture scales horizontally without the complexity of managing state across processes.</p>
                </div>
                <div>
                    <h3>Netflix chose PHP for their TV UI</h3>
                    <p>Netflix's TV application interface — the thing 200+ million subscribers interact with daily — runs on PHP. Not because they couldn't afford Node developers. Because PHP was the right tool.</p>
                    <p>When your business depends on uptime, you don't chase trends. You use what works.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- The Problem with Alternatives -->
    <section class="section section-alt" id="vs-alternatives">
        <div class="container">
            <div class="section-header">
                <span class="overline">The Uncomfortable Truth</span>
                <h2>Node and Python Have Problems</h2>
                <p>The AI ecosystem defaulted to Node and Python. But defaults aren't always right.</p>
            </div>

            <div class="two-col">
                <div>
                    <h3 style="color: #DC2626;">Node.js: A Supply Chain Nightmare</h3>
                    <p>The Node ecosystem has a dependency problem. A single package can pull in hundreds of transitive dependencies — each one a potential security risk.</p>

                    <div class="incident-card" style="background: #FEE2E2; border-left-color: #B91C1C;">
                        <h5>September 2025 — 500 Packages Compromised</h5>
                        <p>Attackers hijacked maintainer accounts, injecting crypto-stealing malware into packages with 2.6 BILLION weekly downloads. <a href="https://www.trendmicro.com/en_us/research/25/i/npm-supply-chain-attack.html" target="_blank" style="color: #B91C1C;">Source</a></p>
                    </div>
                    <div class="incident-card">
                        <h5>left-pad (2016)</h5>
                        <p>11 lines of code broke thousands of builds worldwide when unpublished from npm.</p>
                    </div>
                    <div class="incident-card">
                        <h5>event-stream (2018)</h5>
                        <p>Malicious code injected into popular package, targeting Bitcoin wallets.</p>
                    </div>
                    <div class="incident-card">
                        <h5>colors & faker (2022)</h5>
                        <p>Maintainer intentionally corrupted packages used by millions.</p>
                    </div>
                    <div class="incident-card">
                        <h5>node-ipc (2022)</h5>
                        <p>Popular package weaponized to delete files on Russian/Belarusian systems.</p>
                    </div>

                    <p style="margin-top: 1.5rem;"><strong>This keeps happening.</strong> It's not bad luck — it's architectural. When your average project has 1,000+ dependencies, you're trusting 1,000+ strangers with your production systems.</p>
                </div>
                <div>
                    <h3 style="color: #D97706;">Python: Great for AI, Rough on the Web</h3>
                    <p>Python dominates machine learning. But for web applications? It struggles.</p>

                    <ul class="pricing-features">
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor" style="color: #DC2626;"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                            GIL limits true concurrency
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor" style="color: #DC2626;"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                            Deployment complexity (virtualenvs, WSGI, ASGI)
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor" style="color: #DC2626;"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                            Higher memory footprint per request
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor" style="color: #DC2626;"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                            Not available by default on most hosts
                        </li>
                    </ul>

                    <p style="margin-top: 1.5rem;">Python is the right choice for training models and data science. For serving web requests at scale? PHP benchmarks faster and deploys easier.</p>

                    <div class="code-block" style="margin-top: 1.5rem;">
                        <pre><code><span class="comment"># Python deployment</span>
virtualenv venv
source venv/bin/activate
pip install -r requirements.txt
gunicorn --workers 4 app:app

<span class="comment"># PHP deployment</span>
git pull
<span class="comment"># Done. Apache/nginx already configured.</span></code></pre>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Comparison Table -->
    <section class="section">
        <div class="container">
            <div class="section-header">
                <span class="overline">Head to Head</span>
                <h2>PHP vs Node vs Python for Web Apps</h2>
            </div>

            <table class="comparison-table">
                <thead>
                    <tr>
                        <th>Factor</th>
                        <th>PHP 8.x</th>
                        <th>Node.js</th>
                        <th>Python</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Hosting Availability</strong></td>
                        <td style="color: var(--success);">Everywhere. $5/mo shared hosting works.</td>
                        <td>Requires Node runtime, usually VPS+</td>
                        <td>Requires Python runtime, WSGI server</td>
                    </tr>
                    <tr>
                        <td><strong>Dependency Security</strong></td>
                        <td style="color: var(--success);">Composer + minimal deps culture</td>
                        <td style="color: #DC2626;">npm + 1000s of transitive deps</td>
                        <td>pip + moderate deps, some supply chain issues</td>
                    </tr>
                    <tr>
                        <td><strong>Requests/Second</strong></td>
                        <td style="color: var(--success);">~15,000+ (PHP-FPM)</td>
                        <td>~12,000 (Express)</td>
                        <td>~3,000 (Django/Flask)</td>
                    </tr>
                    <tr>
                        <td><strong>Memory per Request</strong></td>
                        <td style="color: var(--success);">~2MB (shared-nothing)</td>
                        <td>~30MB+ (V8 overhead)</td>
                        <td>~20MB+ (interpreter overhead)</td>
                    </tr>
                    <tr>
                        <td><strong>Deployment</strong></td>
                        <td style="color: var(--success);">git pull, done</td>
                        <td>npm install, pm2/docker, env vars</td>
                        <td>virtualenv, pip, gunicorn, supervisor</td>
                    </tr>
                    <tr>
                        <td><strong>30 Years of Production Use</strong></td>
                        <td style="color: var(--success);">Yes</td>
                        <td>15 years</td>
                        <td>Web frameworks ~20 years</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Modern PHP -->
    <section class="section section-alt" id="modern-php">
        <div class="container">
            <div class="section-header">
                <span class="overline">This Isn't 2005</span>
                <h2>Modern PHP Is Actually Good</h2>
                <p>If your mental model of PHP is from the WordPress 2.x era, it's time to update.</p>
            </div>

            <div class="two-col">
                <div>
                    <div class="timeline-item">
                        <div class="timeline-year">PHP 7.0</div>
                        <div class="timeline-content">
                            <h4>2x Performance Boost</h4>
                            <p>Complete engine rewrite. Doubled speed overnight.</p>
                        </div>
                    </div>
                    <div class="timeline-item">
                        <div class="timeline-year">PHP 7.4</div>
                        <div class="timeline-content">
                            <h4>Typed Properties</h4>
                            <p>Class properties can now have types. Real type safety.</p>
                        </div>
                    </div>
                    <div class="timeline-item">
                        <div class="timeline-year">PHP 8.0</div>
                        <div class="timeline-content">
                            <h4>JIT Compiler + Attributes</h4>
                            <p>Just-in-time compilation. Up to 3x faster for compute.</p>
                        </div>
                    </div>
                    <div class="timeline-item">
                        <div class="timeline-year">PHP 8.1</div>
                        <div class="timeline-content">
                            <h4>Enums + Fibers</h4>
                            <p>First-class enums. Async primitives without callback hell.</p>
                        </div>
                    </div>
                    <div class="timeline-item">
                        <div class="timeline-year">PHP 8.2+</div>
                        <div class="timeline-content">
                            <h4>Readonly Classes + DNF Types</h4>
                            <p>Immutability by default. Complex type expressions.</p>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="code-block">
                        <pre><code><span class="comment">// Modern PHP 8.x - Not your father's PHP</span>

<span class="keyword">readonly class</span> <span class="function">User</span>
{
    <span class="keyword">public function</span> <span class="function">__construct</span>(
        <span class="keyword">public int</span> $id,
        <span class="keyword">public string</span> $email,
        <span class="keyword">public</span> UserRole $role,
        <span class="keyword">public</span> ?Carbon $verifiedAt = <span class="keyword">null</span>,
    ) {}
}

<span class="keyword">enum</span> <span class="function">UserRole</span>: <span class="keyword">string</span>
{
    <span class="keyword">case</span> Admin = <span class="string">'admin'</span>;
    <span class="keyword">case</span> Member = <span class="string">'member'</span>;
    <span class="keyword">case</span> Guest = <span class="string">'guest'</span>;
}

<span class="comment">// Named arguments, null-safe operator</span>
$user = <span class="keyword">new</span> User(
    id: <span class="number">1</span>,
    email: <span class="string">'dev@example.com'</span>,
    role: UserRole::Admin,
);

<span class="comment">// Match expressions (not switch)</span>
$permissions = <span class="keyword">match</span>($user->role) {
    UserRole::Admin => [<span class="string">'*'</span>],
    UserRole::Member => [<span class="string">'read'</span>, <span class="string">'write'</span>],
    UserRole::Guest => [<span class="string">'read'</span>],
};</code></pre>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Ubiquity -->
    <section class="section">
        <div class="container">
            <div class="section-header">
                <span class="overline">Deploy Anywhere</span>
                <h2>PHP Runs Everywhere. Literally.</h2>
                <p>No special runtime. No container orchestration. Just upload and go.</p>
            </div>

            <div class="feature-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/></svg>
                    </div>
                    <h4>Any Shared Host</h4>
                    <p>$5/month hosting includes PHP. No DevOps required. Upload via FTP if you want.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/></svg>
                    </div>
                    <h4>Any VPS</h4>
                    <p>apt install php — done. Apache and nginx have PHP modules built-in.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7v10c0 2 1 3 3 3h10c2 0 3-1 3-3V7c0-2-1-3-3-3H7c-2 0-3 1-3 3z"/><path d="M9 12h6M12 9v6"/></svg>
                    </div>
                    <h4>Containers (If You Want)</h4>
                    <p>Official PHP Docker images. But you don't need containers to deploy PHP.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h4>Serverless</h4>
                    <p>AWS Lambda, Vercel, and others support PHP. Scale to zero when idle.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Bringing PHP into the AI Age -->
    <section class="section section-alt">
        <div class="container">
            <div class="section-header">
                <span class="overline">The Missing Piece</span>
                <h2>Bringing PHP into the AI Age</h2>
                <p>The AI revolution left PHP behind. Until now.</p>
            </div>

            <div class="two-col">
                <div>
                    <h3>AI tooling forgot about 77% of the web</h3>
                    <p>Look at the AI coding landscape: GitHub Copilot optimized for JavaScript/TypeScript. Claude trained heavily on Python. ChatGPT defaulting to Node for web examples.</p>
                    <p>Meanwhile, the majority of production websites — the ones actually making money — run PHP. WordPress. WooCommerce. Custom business applications. Agency client work.</p>
                    <p><strong>These developers got left behind.</strong></p>
                </div>
                <div>
                    <h3>MyCTOBot speaks PHP natively</h3>
                    <p>We built MyCTOBot specifically for PHP developers and the agencies that serve them. Not as an afterthought. As the primary focus.</p>
                    <ul class="pricing-features">
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            AI trained on modern PHP 8.x patterns
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Understands WordPress, Laravel, Symfony
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Generates code that deploys on any host
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Avoids dependency bloat by default
                        </li>
                    </ul>
                    <p style="margin-top: 1rem;"><strong>Finally, AI that writes PHP the way senior PHP developers do.</strong></p>
                </div>
            </div>
        </div>
    </section>

    <!-- Why MyCTOBot chose PHP -->
    <section class="section">
        <div class="container">
            <div class="two-col">
                <div>
                    <span class="overline">Our Position</span>
                    <h2>Why MyCTOBot Is PHP-First</h2>
                    <p>We could have built for Node. The AI ecosystem expected us to. Here's why we didn't.</p>
                    <ul class="pricing-features">
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            <strong>Agencies need simple deployments.</strong> Most clients are on shared hosting or basic VPS. PHP just works.
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            <strong>We don't trust npm.</strong> Supply chain attacks are existential risks. Composer's ecosystem is smaller and more vetted.
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            <strong>Performance matters.</strong> PHP 8 with OPcache handles more requests per dollar than Node or Python.
                        </li>
                        <li>
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            <strong>The talent pool is massive.</strong> PHP developers aren't rare. They're everywhere, and they ship.
                        </li>
                    </ul>
                </div>
                <div>
                    <div class="pricing-card featured" style="max-width: 400px; margin: 0 auto;">
                        <div class="pricing-header">
                            <h3>MyCTOBot</h3>
                            <p class="mb-0" style="font-size: 1.1rem;">AI that writes PHP the right way</p>
                        </div>
                        <ul class="pricing-features">
                            <li>
                                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                PHP 8.x modern syntax
                            </li>
                            <li>
                                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                Minimal dependencies
                            </li>
                            <li>
                                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                Performance-first patterns
                            </li>
                            <li>
                                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                Deploy-anywhere code
                            </li>
                        </ul>
                        <a href="/signup" class="btn btn-primary">Start <?= $trialDays ?>-Day Trial</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="cta">
        <div class="container">
            <h2>Ready to Build Something That Lasts?</h2>
            <p>Join teams who chose PHP because it works — not because it's trendy.</p>
            <div class="cta-actions">
                <a href="/signup" class="btn btn-white btn-lg">Start Building</a>
                <a href="/" class="btn btn-outline btn-lg" style="border-color: #fff; color: #fff;">Back to MyCTOBot</a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-grid">
                <div>
                    <div class="footer-brand">MyCTOBot</div>
                    <p>AI-powered development automation. Opinionated towards PHP, performance, and simplicity.</p>
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
