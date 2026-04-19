<?php
/**
 * Documentation Sidebar Component
 * Usage: <?php include 'partials/sidebar.php'; ?>
 * Set $activeSection before including to highlight the current section
 */

// Determine which section is active (default to 'index' if not set)
$activeSection = $activeSection ?? 'index';
?>

<div class="sticky-top pt-3">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-book"></i> Documentation</h5>
        </div>
        <div class="list-group list-group-flush">
            <a href="/docs" class="list-group-item list-group-item-action <?= $activeSection === 'index' ? 'active' : '' ?>">
                <i class="bi bi-house-door"></i> Getting Started
            </a>
            <a href="/docs/api" class="list-group-item list-group-item-action <?= $activeSection === 'api' ? 'active' : '' ?>">
                <i class="bi bi-code-slash"></i> API Reference
            </a>
            <a href="/docs/cli" class="list-group-item list-group-item-action <?= $activeSection === 'cli' ? 'active' : '' ?>">
                <i class="bi bi-terminal"></i> CLI Reference
            </a>
            <a href="/docs/caching" class="list-group-item list-group-item-action <?= $activeSection === 'caching' ? 'active' : '' ?>">
                <i class="bi bi-lightning-charge"></i> Caching System
            </a>
            <a href="/help" class="list-group-item list-group-item-action <?= $activeSection === 'help' ? 'active' : '' ?>">
                <i class="bi bi-question-circle"></i> Help Center
            </a>
            <?php if (Flight::isLoggedIn() && Flight::getMember()->level <= 50): ?>
            <a href="/admin/cache" class="list-group-item list-group-item-action <?= $activeSection === 'admin-cache' ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i> Cache Admin
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($quickLinks) && !empty($quickLinks)): ?>
    <div class="card mt-3 shadow-sm">
        <div class="card-header">
            <h6 class="mb-0">Quick Navigation</h6>
        </div>
        <div class="card-body small">
            <ul class="nav flex-column">
                <?php foreach ($quickLinks as $link): ?>
                <li class="nav-item">
                    <a class="nav-link py-1" href="<?= h($link['href']) ?>">
                        <?php if (isset($link['icon'])): ?>
                        <i class="bi <?= h($link['icon']) ?>"></i>
                        <?php endif; ?>
                        <?= h($link['text']) ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <?php if (isset($showPerformanceBadge) && $showPerformanceBadge): ?>
    <!-- Performance Badge -->
    <div class="card mt-3 bg-success text-white shadow-sm">
        <div class="card-body text-center">
            <h4 class="mb-1">9.4x</h4>
            <small>Faster with Caching</small>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
/* Sidebar Component Styles */
.sticky-top {
    top: 20px;
}

.list-group-item {
    border: none;
    padding: 12px 20px;
    transition: all 0.3s ease;
}

.list-group-item:hover {
    background-color: #f8f9fa;
    padding-left: 25px;
}

.list-group-item.active {
    background-color: #007bff;
    border-color: #007bff;
    font-weight: 600;
}

.list-group-item i {
    margin-right: 8px;
}

.nav-link {
    color: #495057;
    transition: all 0.2s ease;
}

.nav-link:hover {
    color: #007bff;
    padding-left: 20px !important;
}
</style>