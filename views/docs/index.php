<div class="container py-4">
    <div class="row">
        <div class="col-lg-3 col-md-4">
            <!-- Sidebar Navigation Component -->
            <?php
            $activeSection = 'index';
            $quickLinks = [
                ['href' => '#features', 'icon' => 'bi-star', 'text' => 'Features'],
                ['href' => '#high-performance-caching-system', 'icon' => 'bi-lightning', 'text' => 'Caching System'],
                ['href' => '#quick-start', 'icon' => 'bi-rocket-takeoff', 'text' => 'Quick Start'],
                ['href' => '#project-structure', 'icon' => 'bi-folder-fill', 'text' => 'Project Structure'],
                ['href' => '#creating-controllers', 'icon' => 'bi-file-code', 'text' => 'Controllers'],
                ['href' => '#cli-support', 'icon' => 'bi-terminal-fill', 'text' => 'CLI Support'],
                ['href' => '#deployment-checklist', 'icon' => 'bi-cloud-upload', 'text' => 'Deployment']
            ];
            $showPerformanceBadge = true;
            include __DIR__ . '/partials/sidebar.php';
            ?>
        </div>

        <div class="col-lg-9 col-md-8">
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb bg-light px-3 py-2 rounded shadow-sm">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Documentation</li>
                </ol>
            </nav>

            <!-- Documentation Content -->
            <div class="documentation-content bg-white p-4 rounded shadow-sm">
                <?= $content ?>
            </div>

            <!-- Footer Info -->
            <div class="mt-4 p-3 bg-light rounded text-center small text-muted">
                <p class="mb-1">
                    <i class="bi bi-info-circle"></i>
                    TikNix Framework v1.0 |
                    PHP <?= phpversion() ?> |
                    <?= date('Y') ?>
                </p>
                <p class="mb-0">
                    <a href="https://github.com/mfrederico/tiknix" target="_blank" class="text-decoration-none">
                        <i class="bi bi-github"></i> View on GitHub
                    </a>
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
/* Enhanced Documentation Styles */
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

.documentation-content ul, .documentation-content ol {
    margin-bottom: 20px;
    padding-left: 30px;
}

.documentation-content li {
    margin-bottom: 8px;
    line-height: 1.8;
}

.documentation-content li strong {
    color: #007bff;
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

.documentation-content blockquote {
    border-left: 5px solid #007bff;
    padding: 15px 20px;
    margin: 20px 0;
    background-color: #f8f9fa;
    font-style: italic;
    color: #495057;
    border-radius: 0 8px 8px 0;
}

.documentation-content a {
    color: #007bff;
    text-decoration: none;
    font-weight: 500;
}

.documentation-content a:hover {
    text-decoration: underline;
    color: #0056b3;
}

.documentation-content p {
    margin-bottom: 15px;
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

/* Responsive */
@media (max-width: 768px) {
    .sticky-top {
        position: relative !important;
        top: 0 !important;
    }

    .documentation-content h1 {
        font-size: 2rem;
    }

    .documentation-content h2 {
        font-size: 1.5rem;
    }

    .documentation-content h3 {
        font-size: 1.25rem;
    }
}

/* Code Syntax Highlighting */
.language-bash { color: #0066cc; }
.language-php { color: #8b00ff; }
.language-ini { color: #008000; }
.language-sql { color: #cc0000; }
.language-javascript { color: #f0ad4e; }

/* Animation */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.documentation-content > * {
    animation: fadeIn 0.5s ease-out;
}
</style>

<script>
// Back to top button functionality
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
            const offset = 80; // Account for sticky header
            const targetPosition = target.getBoundingClientRect().top + window.pageYOffset - offset;
            window.scrollTo({
                top: targetPosition,
                behavior: 'smooth'
            });
        }
    });
});

// Active section highlighting
window.addEventListener('scroll', function() {
    const sections = document.querySelectorAll('h2[id], h3[id]');
    const navLinks = document.querySelectorAll('.nav-link[href^="#"]');

    let current = '';
    sections.forEach(section => {
        const sectionTop = section.offsetTop;
        const sectionHeight = section.clientHeight;
        if (pageYOffset >= (sectionTop - 100)) {
            current = section.getAttribute('id');
        }
    });

    navLinks.forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('href').slice(1) === current) {
            link.classList.add('active');
        }
    });
});

// Code copy functionality
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
</script>