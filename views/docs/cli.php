<div class="container py-4">
    <div class="row">
        <div class="col-lg-3 col-md-4">
            <!-- Sidebar Navigation Component -->
            <?php
            $activeSection = 'cli';
            $quickLinks = [
                ['href' => '#basic-usage', 'icon' => 'bi-play', 'text' => 'Basic Usage'],
                ['href' => '#options', 'icon' => 'bi-gear', 'text' => 'Options'],
                ['href' => '#examples', 'icon' => 'bi-code', 'text' => 'Examples'],
                ['href' => '#cron-jobs', 'icon' => 'bi-clock', 'text' => 'Cron Jobs'],
                ['href' => '#cli-handler', 'icon' => 'bi-cpu', 'text' => 'CLI Handler'],
                ['href' => '#troubleshooting', 'icon' => 'bi-tools', 'text' => 'Troubleshooting']
            ];
            $showPerformanceBadge = false; // Not relevant for CLI docs
            include __DIR__ . '/partials/sidebar.php';
            ?>
        </div>

        <div class="col-lg-9 col-md-8">
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb bg-light px-3 py-2 rounded shadow-sm">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item"><a href="/docs">Documentation</a></li>
                    <li class="breadcrumb-item active" aria-current="page">CLI Reference</li>
                </ol>
            </nav>

            <div class="documentation-content bg-white p-4 rounded shadow-sm">
                <h1><i class="bi bi-terminal"></i> CLI Documentation</h1>

                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> The TikNix framework includes comprehensive CLI support for running controllers from the command line, perfect for cron jobs, background tasks, and administrative scripts.
                </div>

                <?= $content ?>

                <div id="cli-handler" class="mt-5">
                    <h2>CLI Handler Architecture</h2>
                    <p>The TikNix CLI system uses the <code>CliHandler</code> class to:</p>
                    <ul>
                        <li>Parse command-line arguments</li>
                        <li>Set up the execution environment</li>
                        <li>Handle authentication (run as specific member)</li>
                        <li>Route to the appropriate controller/method</li>
                        <li>Manage output (verbose, cron mode, etc.)</li>
                    </ul>

                    <h3>Environment Variables</h3>
                    <p>The CLI handler sets these environment variables:</p>
                    <pre><code>REQUEST_URI    = /controller/method
REQUEST_METHOD = GET (default) or specified method
HTTP_HOST      = cli (for session handling)</code></pre>
                </div>

                <div id="troubleshooting" class="mt-5">
                    <h2>Troubleshooting</h2>

                    <h3>Common Issues</h3>

                    <h4>Permission Denied</h4>
                    <pre><code class="language-bash"># Ensure the script is executable
chmod +x public/index.php

# Run with proper PHP binary
/usr/bin/php public/index.php --control=test</code></pre>

                    <h4>Class Not Found</h4>
                    <p>Make sure the controller exists and follows naming conventions:</p>
                    <ul>
                        <li>Controller file: <code>controls/Test.php</code></li>
                        <li>Class name: <code>class Test extends BaseControls\Control</code></li>
                        <li>Namespace: <code>namespace app;</code></li>
                    </ul>

                    <h4>Database Connection Issues</h4>
                    <p>CLI mode uses the same database configuration as web mode. Check:</p>
                    <ul>
                        <li>Database credentials in <code>conf/config.ini</code></li>
                        <li>Database server is accessible from CLI environment</li>
                        <li>PHP CLI has required database extensions</li>
                    </ul>

                    <h3>Debugging</h3>
                    <pre><code class="language-bash"># Use verbose mode for detailed output
php public/index.php --control=test --method=debug --verbose

# Check logs
tail -f log/app-*.log

# Test database connection
php -r "require 'bootstrap.php'; \$app = new app\Bootstrap('conf/config.ini');"</code></pre>
                </div>

                <div class="alert alert-success alert-dismissible fade show mt-5" role="alert">
                    <h4><i class="bi bi-lightbulb"></i> Pro Tip</h4>
                    <p>Create a shell script wrapper for commonly used CLI commands:</p>
                    <pre class="mb-0"><code class="language-bash">#!/bin/bash
# tiknix-cli.sh
cd /path/to/tiknix
/usr/bin/php public/index.php "$@"</code></pre>
                    <p class="mb-0 mt-2">Then use: <code>./tiknix-cli.sh --control=cleanup --method=daily</code></p>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            </div>

            <!-- Footer Info -->
            <div class="mt-4 p-3 bg-light rounded text-center small text-muted">
                <p class="mb-1">
                    <i class="bi bi-info-circle"></i>
                    TikNix Framework v1.0 |
                    PHP <?= phpversion() ?> |
                    <?= date('Y') ?>
                </p>
            </div>

            <!-- Back to top button -->
            <button id="backToTop" class="btn btn-primary btn-floating" title="Back to top">
                <i class="bi bi-arrow-up"></i>
            </button>
        </div>
    </div>
</div>

<style>
/* Documentation Styles */
.documentation-content {
    font-size: 16px;
    line-height: 1.8;
    color: #333;
}

.documentation-content h1 {
    color: #2c3e50;
    font-size: 2.5rem;
    font-weight: 700;
    border-bottom: 3px solid #007bff;
    padding-bottom: 15px;
    margin-bottom: 30px;
}

.documentation-content h2 {
    color: #34495e;
    font-size: 2rem;
    font-weight: 600;
    margin-top: 40px;
    margin-bottom: 20px;
    padding-left: 15px;
    border-left: 5px solid #007bff;
}

.documentation-content h3 {
    color: #495057;
    font-size: 1.5rem;
    font-weight: 500;
    margin-top: 30px;
    margin-bottom: 15px;
}

.documentation-content h4 {
    color: #6c757d;
    font-size: 1.25rem;
    font-weight: 500;
    margin-top: 25px;
    margin-bottom: 10px;
}

.documentation-content pre {
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 20px;
    overflow-x: auto;
    margin: 20px 0;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.documentation-content code {
    background-color: #fff3cd;
    padding: 3px 8px;
    border-radius: 4px;
    color: #d63384;
    font-size: 0.9em;
    font-weight: 500;
}

.documentation-content pre code {
    background-color: transparent;
    padding: 0;
    color: #212529;
    font-size: 0.875rem;
}

.documentation-content table {
    width: 100%;
    margin: 20px 0;
    border-collapse: collapse;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.documentation-content table th {
    background-color: #007bff;
    color: white;
    padding: 12px;
    text-align: left;
    font-weight: 600;
}

.documentation-content table td {
    padding: 10px 12px;
    border-bottom: 1px solid #dee2e6;
}

.documentation-content table tr:hover {
    background-color: #f8f9fa;
}

/* Back to Top Button */
#backToTop {
    position: fixed;
    bottom: 30px;
    right: 30px;
    display: none;
    width: 50px;
    height: 50px;
    border-radius: 50%;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    z-index: 1000;
    transition: all 0.3s ease;
}

#backToTop:hover {
    transform: translateY(-5px);
    box-shadow: 0 6px 12px rgba(0,0,0,0.15);
}

/* Code Syntax Highlighting */
.language-bash { color: #0066cc; }
.language-php { color: #8b00ff; }
.language-ini { color: #008000; }

/* Responsive */
@media (max-width: 768px) {
    .documentation-content h1 { font-size: 2rem; }
    .documentation-content h2 { font-size: 1.5rem; }
    .documentation-content h3 { font-size: 1.25rem; }
}
</style>

<script>
// Back to top button
window.addEventListener('scroll', function() {
    const backToTop = document.getElementById('backToTop');
    if (window.pageYOffset > 300) {
        backToTop.style.display = 'block';
    } else {
        backToTop.style.display = 'none';
    }
});

document.getElementById('backToTop').addEventListener('click', function() {
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
});

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            const offset = 80;
            const targetPosition = target.getBoundingClientRect().top + window.pageYOffset - offset;
            window.scrollTo({
                top: targetPosition,
                behavior: 'smooth'
            });
        }
    });
});

// Copy code functionality
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('pre').forEach(pre => {
        const wrapper = document.createElement('div');
        wrapper.style.position = 'relative';
        pre.parentNode.insertBefore(wrapper, pre);
        wrapper.appendChild(pre);

        const button = document.createElement('button');
        button.className = 'btn btn-sm btn-outline-secondary';
        button.innerHTML = '<i class="bi bi-clipboard"></i> Copy';
        button.style.position = 'absolute';
        button.style.top = '10px';
        button.style.right = '10px';

        button.addEventListener('click', function() {
            const code = pre.querySelector('code').textContent;
            navigator.clipboard.writeText(code).then(() => {
                button.innerHTML = '<i class="bi bi-check"></i> Copied!';
                setTimeout(() => {
                    button.innerHTML = '<i class="bi bi-clipboard"></i> Copy';
                }, 2000);
            });
        });

        wrapper.appendChild(button);
    });
});
</script>