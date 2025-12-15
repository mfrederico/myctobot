<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?= htmlspecialchars($title ?? 'MyCTOBot - AI Sprint Intelligence') ?></title>

    <!-- SEO Meta Tags -->
    <meta name="description" content="<?= htmlspecialchars(Flight::get('social.og_description') ?? 'AI-powered daily sprint digests for Jira') ?>">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="<?= htmlspecialchars(Flight::get('social.og_type') ?? 'website') ?>">
    <meta property="og:url" content="<?= htmlspecialchars(Flight::get('social.og_url') ?? 'https://myctobot.ai') ?>">
    <meta property="og:title" content="<?= htmlspecialchars(Flight::get('social.og_title') ?? 'MyCTOBot - AI-Powered Sprint Intelligence') ?>">
    <meta property="og:description" content="<?= htmlspecialchars(Flight::get('social.og_description') ?? 'Replace your $275K CTO with AI') ?>">
    <meta property="og:image" content="<?= htmlspecialchars(Flight::get('social.og_image') ?? 'https://myctobot.ai/images/og-preview.png') ?>">

    <!-- Twitter -->
    <meta name="twitter:card" content="<?= htmlspecialchars(Flight::get('social.twitter_card') ?? 'summary_large_image') ?>">
    <meta name="twitter:url" content="<?= htmlspecialchars(Flight::get('social.og_url') ?? 'https://myctobot.ai') ?>">
    <meta name="twitter:title" content="<?= htmlspecialchars(Flight::get('social.og_title') ?? 'MyCTOBot - AI-Powered Sprint Intelligence') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars(Flight::get('social.og_description') ?? 'Replace your $275K CTO with AI') ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars(Flight::get('social.og_image') ?? 'https://myctobot.ai/images/og-preview.png') ?>">
    <?php if (Flight::get('social.twitter_site')): ?>
    <meta name="twitter:site" content="<?= htmlspecialchars(Flight::get('social.twitter_site')) ?>">
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
<body>
    <!-- Header -->
    <?= $header_content ?>
    
    <!-- Main Content -->
    <main class="flex-shrink-0">
        <?= $body_content ?>
    </main>
    
    <!-- Footer -->
    <?= $footer_content ?>
    
    <!-- Bootstrap 5 JS Bundle (includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
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