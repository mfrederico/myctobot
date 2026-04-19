<?php
/**
 * Sidebar Navigation Component
 *
 * Bootstrap 5 offcanvas sidebar:
 * - Always visible on desktop (lg+)
 * - Collapsible offcanvas on mobile/tablet
 */

$workspaceSlug = \app\WorkspaceResolver::getSessionWorkspace();
$showWorkspace = $workspaceSlug && $workspaceSlug !== 'default';
?>

<!-- Offcanvas Sidebar -->
<div class="offcanvas-lg offcanvas-start sidebar-nav" tabindex="-1" id="sidebarNav" aria-labelledby="sidebarNavLabel">
    <div class="offcanvas-header border-bottom">
        <a class="navbar-brand d-flex align-items-center" href="/" id="sidebarNavLabel">
            <i class="bi bi-box me-2"></i>
            <span><?= h($site_name ?? 'MyCTOBot') ?></span>
        </a>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebarNav" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body d-flex flex-column">
        <?php if ($showWorkspace): ?>
        <div class="px-3 py-2 mb-2">
            <span class="badge bg-primary w-100 py-2">
                <i class="bi bi-building me-1"></i> <?= h(strtoupper($workspaceSlug)) ?>
            </span>
        </div>
        <?php endif; ?>

        <!-- Main Navigation -->
        <nav class="nav flex-column flex-grow-1">
            <?php foreach ($menu ?? [] as $item): ?>
                <?php if (isset($item['dropdown'])): ?>
                    <!-- Dropdown/Expandable Section -->
                    <div class="nav-item">
                        <a class="nav-link d-flex align-items-center" data-bs-toggle="collapse"
                           href="#nav-<?= md5($item['label']) ?>" role="button" aria-expanded="false">
                            <?php if (isset($item['icon'])): ?>
                                <i class="bi bi-<?= $item['icon'] ?> me-2"></i>
                            <?php endif; ?>
                            <span class="flex-grow-1"><?= h($item['label']) ?></span>
                            <i class="bi bi-chevron-down small"></i>
                        </a>
                        <div class="collapse ps-4" id="nav-<?= md5($item['label']) ?>">
                            <?php foreach ($item['dropdown'] as $subitem): ?>
                                <?php if (!isset($subitem['divider']) || !$subitem['divider']): ?>
                                    <a class="nav-link py-1 <?= isset($subitem['active']) && $subitem['active'] ? 'active' : '' ?>"
                                       href="<?= $subitem['url'] ?>">
                                        <?php if (isset($subitem['icon'])): ?>
                                            <i class="bi bi-<?= $subitem['icon'] ?> me-2"></i>
                                        <?php endif; ?>
                                        <?= h($subitem['label']) ?>
                                    </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Regular Nav Item -->
                    <a class="nav-link d-flex align-items-center <?= isset($item['active']) && $item['active'] ? 'active' : '' ?>"
                       href="<?= $item['url'] ?>">
                        <?php if (isset($item['icon'])): ?>
                            <i class="bi bi-<?= $item['icon'] ?> me-2"></i>
                        <?php endif; ?>
                        <?= h($item['label']) ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <!-- Bottom Section -->
        <div class="border-top pt-3 mt-auto">
            <?php if ($isLoggedIn ?? false): ?>
                <a class="nav-link d-flex align-items-center" href="/docs">
                    <i class="bi bi-book me-2"></i> Documentation
                </a>
                <a class="nav-link d-flex align-items-center" href="/help">
                    <i class="bi bi-question-circle me-2"></i> Help
                </a>
                <?php if (($member['level'] ?? 100) <= 50): ?>
                <a class="nav-link d-flex align-items-center" href="/admin">
                    <i class="bi bi-shield-lock me-2"></i> Admin
                </a>
                <?php endif; ?>
                <hr class="my-2">
                <a class="nav-link d-flex align-items-center text-danger" href="/auth/logout">
                    <i class="bi bi-box-arrow-right me-2"></i> Logout
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>
