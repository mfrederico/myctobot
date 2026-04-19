<div class="container py-4">
    <div class="row">
        <div class="col-lg-3 col-md-4">
            <!-- Sidebar Navigation Component -->
            <?php
            $activeSection = 'pipelines';
            $quickLinks = [
                ['href' => '#overview', 'icon' => 'bi-diagram-3', 'text' => 'Overview'],
                ['href' => '#variable-substitution', 'icon' => 'bi-braces', 'text' => 'Variables'],
                ['href' => '#context-variables', 'icon' => 'bi-input-cursor', 'text' => 'Context Variables'],
                ['href' => '#mcp-tool-exposure', 'icon' => 'bi-plug', 'text' => 'MCP Tools'],
                ['href' => '#input-schema', 'icon' => 'bi-file-code', 'text' => 'Input Schema'],
                ['href' => '#derive-from-steps', 'icon' => 'bi-magic', 'text' => 'Derive from Steps'],
                ['href' => '#step-types-reference', 'icon' => 'bi-list-check', 'text' => 'Step Types'],
                ['href' => '#best-practices', 'icon' => 'bi-trophy', 'text' => 'Best Practices'],
                ['href' => '#troubleshooting', 'icon' => 'bi-wrench', 'text' => 'Troubleshooting']
            ];
            $showPerformanceBadge = false;
            include __DIR__ . '/partials/sidebar.php';
            ?>
        </div>

        <div class="col-lg-9 col-md-8">
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb bg-light px-3 py-2 rounded shadow-sm">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item"><a href="/docs">Documentation</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Pipeline System</li>
                </ol>
            </nav>

            <!-- Documentation Content -->
            <div class="documentation-content bg-white p-4 rounded shadow-sm">
                <?= $content ?>
            </div>

            <!-- Back to top button -->
            <button id="backToTop" class="btn btn-primary btn-floating" title="Back to top">
                <i class="bi bi-arrow-up"></i>
            </button>
        </div>
    </div>
</div>

<style>
/* Use same styles as main docs page */
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
}

.documentation-content table th,
.documentation-content table td {
    padding: 12px;
    vertical-align: top;
}

.documentation-content table th {
    background-color: #f8f9fa;
    font-weight: 600;
}

.documentation-content blockquote {
    border-left: 4px solid #007bff;
    padding-left: 20px;
    margin: 20px 0;
    color: #6c757d;
    font-style: italic;
}

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
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });
});
</script>
