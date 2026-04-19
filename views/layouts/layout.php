<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?= h($title ?? 'MyCTOBot - AI Sprint Intelligence') ?></title>

    <!-- SEO Meta Tags -->
    <meta name="description" content="<?= h(Flight::get('social.og_description') ?? 'AI-powered daily sprint digests for Jira') ?>">

    <?php
    // Site config fallbacks for OG tags
    $ogBaseUrl = $site_base_url ?? 'https://myctobot.ai';
    $ogSiteName = $site_name ?? 'MyCTOBot';
    ?>
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="<?= h(Flight::get('social.og_type') ?? 'website') ?>">
    <meta property="og:url" content="<?= h(Flight::get('social.og_url') ?? $ogBaseUrl) ?>">
    <meta property="og:title" content="<?= h(Flight::get('social.og_title') ?? $ogSiteName . ' - AI-Powered Sprint Intelligence') ?>">
    <meta property="og:description" content="<?= h(Flight::get('social.og_description') ?? 'Replace your $275K CTO with AI') ?>">
    <meta property="og:image" content="<?= h(Flight::get('social.og_image') ?? $ogBaseUrl . '/images/og-preview.png') ?>">

    <!-- Twitter -->
    <meta name="twitter:card" content="<?= h(Flight::get('social.twitter_card') ?? 'summary_large_image') ?>">
    <meta name="twitter:url" content="<?= h(Flight::get('social.og_url') ?? $ogBaseUrl) ?>">
    <meta name="twitter:title" content="<?= h(Flight::get('social.og_title') ?? $ogSiteName . ' - AI-Powered Sprint Intelligence') ?>">
    <meta name="twitter:description" content="<?= h(Flight::get('social.og_description') ?? 'Replace your $275K CTO with AI') ?>">
    <meta name="twitter:image" content="<?= h(Flight::get('social.og_image') ?? $ogBaseUrl . '/images/og-preview.png') ?>">
    <?php if (Flight::get('social.twitter_site')): ?>
    <meta name="twitter:site" content="<?= h(Flight::get('social.twitter_site')) ?>">
    <?php endif; ?>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- Custom CSS -->
    <link href="/css/app.css" rel="stylesheet">
    
    <!-- Additional CSS -->
    <?php if (isset($additional_css)): ?>
        <?php foreach ($additional_css as $css): ?>
            <link href="<?= $css ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body<?= ($useSidebar ?? false) ? ' class="has-sidebar"' : '' ?>>
    <?php if ($useSidebar ?? false): ?>
    <!-- Sidebar Navigation -->
    <?= $sidebar_content ?? '' ?>
    <?php endif; ?>

    <!-- Header/Top Bar -->
    <?= $header_content ?>

    <!-- Main Content -->
    <main class="flex-shrink-0">
        <?= $body_content ?>
    </main>

    <!-- Footer -->
    <?= $footer_content ?>

    <!-- Site Configuration for JavaScript -->
    <script>
    window.SiteConfig = {
        domain: '<?= h($site_domain ?? 'myctobot.ai', ENT_QUOTES, 'UTF-8') ?>',
        protocol: '<?= h($site_protocol ?? 'https', ENT_QUOTES, 'UTF-8') ?>',
        baseUrl: '<?= h($site_base_url ?? 'https://myctobot.ai', ENT_QUOTES, 'UTF-8') ?>',
        name: '<?= h($site_name ?? 'MyCTOBot', ENT_QUOTES, 'UTF-8') ?>'
    };
    </script>

    <!-- Bootstrap 5 JS Bundle (includes Popper) -->
    <!-- Temporarily hide AMD define so Bootstrap sets window.bootstrap globally -->
    <!-- (Monaco editor's AMD loader can otherwise intercept Bootstrap's UMD export) -->
    <script>var __savedDefine = window.define; window.define = undefined;</script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>window.define = __savedDefine; __savedDefine = undefined;</script>
    
    <!-- jQuery (optional, but useful) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    
    <!-- Custom JS -->
    <script src="/js/app.js"></script>
    
    <!-- Flash Messages -->
    <?php if (isset($_SESSION['flash'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($_SESSION['flash'] as $flash): ?>
                showToast('<?= $flash['type'] ?>', '<?= addslashes($flash['message']) ?>');
            <?php endforeach; ?>
            <?php unset($_SESSION['flash']); ?>
        });
    </script>
    <?php endif; ?>
    
    <!-- Additional JS -->
    <?php if (isset($additional_js)): ?>
        <?php foreach ($additional_js as $js): ?>
            <script src="<?= $js ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>