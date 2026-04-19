<?php
/**
 * Shopify AI Landing Page
 * Focused lead capture for AI-callable Shopify automation
 */
$trialDays = $trialDays ?? 14;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopify Automations Your AI Can Control | MyCTOBot</title>
    <meta name="description" content="Build Shopify workflows that Claude and other AI assistants can trigger. Flash sales, inventory updates, customer notifications - all callable via MCP.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --shopify: #96bf48;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            line-height: 1.6;
            color: var(--gray-800);
            background: var(--gray-900);
            min-height: 100vh;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 1.5rem;
        }

        /* Header */
        .header {
            padding: 1.5rem 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .header .container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .logo {
            color: #fff;
            font-weight: 700;
            font-size: 1.25rem;
            text-decoration: none;
        }
        .badge {
            background: rgba(150, 191, 72, 0.2);
            color: var(--shopify);
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Hero */
        .hero {
            padding: 4rem 0 3rem;
            text-align: center;
        }
        .hero h1 {
            color: #fff;
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 1rem;
        }
        .hero h1 span {
            color: var(--shopify);
        }
        .hero p {
            color: var(--gray-500);
            font-size: 1.125rem;
            max-width: 600px;
            margin: 0 auto 2rem;
        }
        @media (max-width: 640px) {
            .hero h1 { font-size: 1.75rem; }
            .hero p { font-size: 1rem; }
        }

        /* Demo Box */
        .demo-box {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 3rem;
            text-align: left;
        }
        .demo-label {
            color: var(--gray-500);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.75rem;
        }
        .demo-prompt {
            color: #fff;
            font-size: 1rem;
            line-height: 1.6;
        }
        .demo-prompt strong {
            color: var(--shopify);
        }
        .demo-arrow {
            text-align: center;
            color: var(--gray-500);
            padding: 1rem 0;
            font-size: 1.5rem;
        }
        .demo-result {
            background: rgba(150, 191, 72, 0.1);
            border: 1px solid rgba(150, 191, 72, 0.3);
            border-radius: 0.5rem;
            padding: 1rem;
            color: var(--shopify);
            font-size: 0.875rem;
        }

        /* Form */
        .form-section {
            background: #fff;
            border-radius: 1rem;
            padding: 2rem;
            margin-bottom: 3rem;
        }
        .form-section h2 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--gray-900);
        }
        .form-section .subtitle {
            color: var(--gray-500);
            margin-bottom: 1.5rem;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--gray-800);
            margin-bottom: 0.5rem;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            font-size: 1rem;
            font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        .btn-submit {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%);
            color: #fff;
            border: none;
            border-radius: 0.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
        }
        .btn-submit:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        .form-note {
            text-align: center;
            font-size: 0.75rem;
            color: var(--gray-500);
            margin-top: 1rem;
        }

        /* Features */
        .features {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-bottom: 3rem;
        }
        @media (max-width: 640px) {
            .features { grid-template-columns: 1fr; }
        }
        .feature {
            text-align: center;
            padding: 1rem;
        }
        .feature-icon {
            width: 48px;
            height: 48px;
            background: rgba(150, 191, 72, 0.2);
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.75rem;
            color: var(--shopify);
        }
        .feature h3 {
            color: #fff;
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }
        .feature p {
            color: var(--gray-500);
            font-size: 0.875rem;
        }

        /* Footer */
        .footer {
            border-top: 1px solid rgba(255,255,255,0.1);
            padding: 2rem 0;
            text-align: center;
            color: var(--gray-500);
            font-size: 0.875rem;
        }
        .footer a {
            color: var(--gray-500);
            text-decoration: none;
        }
        .footer a:hover {
            color: #fff;
        }

        /* Success State */
        .success-message {
            display: none;
            text-align: center;
            padding: 2rem;
        }
        .success-message.show {
            display: block;
        }
        .success-message svg {
            width: 64px;
            height: 64px;
            color: var(--shopify);
            margin-bottom: 1rem;
        }
        .success-message h3 {
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }
        .success-message p {
            color: var(--gray-600);
        }
        .form-content.hidden {
            display: none;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <a href="/" class="logo">MyCTOBot</a>
            <span class="badge">Early Access</span>
        </div>
    </header>

    <!-- Hero -->
    <section class="hero">
        <div class="container">
            <h1>Shopify Automations<br>Your <span>AI Can Control</span></h1>
            <p>Build workflows that Claude, ChatGPT, or any MCP-compatible AI can trigger. Run flash sales, update inventory, notify customers - all with a simple prompt.</p>

            <div class="demo-box">
                <div class="demo-label">You say to Claude:</div>
                <div class="demo-prompt">
                    "Run a <strong>flash sale</strong> on my Shopify store - <strong>25% off</strong> all winter jackets, <strong>email my VIP list</strong>, and update the <strong>homepage banner</strong>."
                </div>
                <div class="demo-arrow">&darr;</div>
                <div class="demo-result">
                    Claude calls your MCP pipeline &rarr; Shopify prices updated &rarr; Klaviyo email sent &rarr; Theme banner changed &rarr; Done in 30 seconds
                </div>
            </div>
        </div>
    </section>

    <!-- Lead Capture Form -->
    <section class="form-section">
        <div class="container">
            <div class="form-content" id="formContent">
                <h2>Get Early Access</h2>
                <p class="subtitle">We're onboarding a small group of Shopify merchants. Join the waitlist.</p>

                <form id="leadForm" action="/pipein/shopify-ai-lead-capture" method="POST">
                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <input type="email" id="email" name="email" required placeholder="you@yourstore.com">
                    </div>

                    <div class="form-group">
                        <label for="store_url">Shopify Store URL</label>
                        <input type="text" id="store_url" name="store_url" placeholder="yourstore.myshopify.com">
                    </div>

                    <div class="form-group">
                        <label for="use_case">What would you automate first?</label>
                        <select id="use_case" name="use_case">
                            <option value="">Select one...</option>
                            <option value="flash_sales">Flash sales & promotions</option>
                            <option value="inventory">Inventory management</option>
                            <option value="customer_comms">Customer communications</option>
                            <option value="theme_updates">Theme/content updates</option>
                            <option value="order_processing">Order processing</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="notes">Anything else? (optional)</label>
                        <textarea id="notes" name="notes" placeholder="Tell us about your store or specific automation needs..."></textarea>
                    </div>

                    <button type="submit" class="btn-submit" id="submitBtn">Join Waitlist</button>
                    <p class="form-note">No spam. We'll reach out when we're ready for you.</p>
                </form>
            </div>

            <div class="success-message" id="successMessage">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <h3>You're on the list!</h3>
                <p>We'll be in touch soon with early access details.</p>
            </div>
        </div>
    </section>

    <!-- Features -->
    <section class="features">
        <div class="container" style="display: contents;">
            <div class="feature">
                <div class="feature-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                    </svg>
                </div>
                <h3>MCP Native</h3>
                <p>Expose pipelines as tools any AI can call</p>
            </div>
            <div class="feature">
                <div class="feature-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                        <line x1="8" y1="21" x2="16" y2="21"/>
                        <line x1="12" y1="17" x2="12" y2="21"/>
                    </svg>
                </div>
                <h3>Real Code Execution</h3>
                <p>Not sandboxed snippets - actual shell commands</p>
            </div>
            <div class="feature">
                <div class="feature-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                        <line x1="3" y1="6" x2="21" y2="6"/>
                        <path d="M16 10a4 4 0 0 1-8 0"/>
                    </svg>
                </div>
                <h3>Shopify GraphQL</h3>
                <p>Full Admin API access in your pipelines</p>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> ClickSimple, Inc. &middot; <a href="/legal/privacy">Privacy</a> &middot; <a href="/legal/terms">Terms</a></p>
        </div>
    </footer>

    <script>
    document.getElementById('leadForm').addEventListener('submit', async function(e) {
        e.preventDefault();

        const btn = document.getElementById('submitBtn');
        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Submitting...';

        try {
            const formData = new FormData(this);
            const data = Object.fromEntries(formData.entries());

            const response = await fetch('/pipein/shopify-ai-lead-capture', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                document.getElementById('formContent').classList.add('hidden');
                document.getElementById('successMessage').classList.add('show');
            } else {
                alert(result.error || 'Something went wrong. Please try again.');
                btn.disabled = false;
                btn.textContent = originalText;
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Something went wrong. Please try again.');
            btn.disabled = false;
            btn.textContent = originalText;
        }
    });
    </script>
</body>
</html>
