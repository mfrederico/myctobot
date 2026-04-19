<?php
/**
 * Minimal Top Bar for Sidebar Layout
 *
 * Shows:
 * - Hamburger menu toggle (mobile/tablet)
 * - Brand (mobile only)
 * - User dropdown (always)
 */
?>

<!-- Top Bar -->
<nav class="navbar navbar-expand-lg topbar sticky-top">
    <div class="container-fluid">
        <!-- Sidebar Toggle (visible on mobile/tablet) -->
        <button class="btn btn-link d-lg-none me-2 p-0" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#sidebarNav" aria-controls="sidebarNav">
            <i class="bi bi-list fs-4"></i>
        </button>

        <!-- Brand (mobile only) -->
        <a class="navbar-brand d-lg-none" href="/">
            <i class="bi bi-box"></i> <?= h($site_name ?? 'MyCTOBot') ?>
        </a>

        <!-- Spacer -->
        <div class="flex-grow-1"></div>

        <!-- Right Side -->
        <ul class="navbar-nav">
            <?php if ($isLoggedIn ?? false): ?>
                <?php if (!($member && $member->isPro())): ?>
                <!-- GO PRO Button -->
                <li class="nav-item me-2 d-none d-sm-block">
                    <a class="btn btn-warning btn-sm" href="/settings/subscription">
                        <i class="bi bi-star-fill"></i> <span class="d-none d-md-inline">GO PRO</span>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Profile Link -->
                <li class="nav-item d-none d-sm-block">
                    <a class="nav-link" href="/member/edit" title="Edit Profile">
                        <i class="bi bi-person-gear"></i>
                    </a>
                </li>

                <!-- User Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <span class="d-none d-sm-inline"><?= h($member['username'] ?? 'User') ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <a class="dropdown-item" href="/settings/connections">
                                <i class="bi bi-speedometer2 me-2"></i> Dashboard
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/member/edit">
                                <i class="bi bi-person me-2"></i> Profile
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/settings/subscription">
                                <i class="bi bi-credit-card me-2"></i> Subscription
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="/auth/logout">
                                <i class="bi bi-box-arrow-right me-2"></i> Logout
                            </a>
                        </li>
                    </ul>
                </li>
            <?php else: ?>
                <!-- Login/Register -->
                <li class="nav-item">
                    <a class="nav-link" href="/auth/login">
                        <i class="bi bi-box-arrow-in-right"></i> Login
                    </a>
                </li>
                <li class="nav-item">
                    <a class="btn btn-primary btn-sm ms-2" href="/auth/register">
                        <i class="bi bi-person-plus"></i> Register
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</nav>

<!-- Toast Container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100">
    <div id="liveToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="toast-header">
            <i class="bi bi-info-circle me-2"></i>
            <strong class="me-auto">Notification</strong>
            <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body"></div>
    </div>
</div>
