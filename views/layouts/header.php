<!-- Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <!-- Brand -->
        <a class="navbar-brand" href="/">
            <i class="bi bi-box"></i> <?= htmlspecialchars($site_name ?? 'MyCTOBot.ai') ?>
        </a>
        
        <!-- Mobile Toggle -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <!-- Navigation Menu -->
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <?php foreach ($menu ?? [] as $item): ?>
                    <?php if (isset($item['dropdown'])): ?>
                        <!-- Dropdown Menu -->
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                                <?php if (isset($item['icon'])): ?>
                                    <i class="bi bi-<?= $item['icon'] ?>"></i>
                                <?php endif; ?>
                                <?= htmlspecialchars($item['label']) ?>
                            </a>
                            <ul class="dropdown-menu">
                                <?php foreach ($item['dropdown'] as $subitem): ?>
                                    <li>
                                        <a class="dropdown-item" href="<?= $subitem['url'] ?>">
                                            <?php if (isset($subitem['icon'])): ?>
                                                <i class="bi bi-<?= $subitem['icon'] ?>"></i>
                                            <?php endif; ?>
                                            <?= htmlspecialchars($subitem['label']) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php else: ?>
                        <!-- Regular Menu Item -->
                        <li class="nav-item">
                            <a class="nav-link" href="<?= $item['url'] ?>">
                                <?php if (isset($item['icon'])): ?>
                                    <i class="bi bi-<?= $item['icon'] ?>"></i>
                                <?php endif; ?>
                                <?= htmlspecialchars($item['label']) ?>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
            
            <!-- Right Side Menu -->
            <ul class="navbar-nav ms-auto">
                <?php if ($isLoggedIn ?? false): ?>
                    <?php if (!($member && $member->isPro())): ?>
                        <!-- GO PRO Button -->
                        <li class="nav-item me-2">
                            <a class="btn btn-warning btn-sm" href="/settings/subscription">
                                <i class="bi bi-star-fill"></i> GO PRO
                            </a>
                        </li>
                    <?php endif; ?>
                    <!-- Settings Link -->
                    <li class="nav-item">
                        <a class="nav-link" href="/settings">
                            <i class="bi bi-gear"></i>
                        </a>
                    </li>
                    <!-- User Dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i>
                            <?= htmlspecialchars($member['username'] ?? 'User') ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="/dashboard">
                                    <i class="bi bi-speedometer2"></i> Dashboard
                                </a>
                            </li>
                            <?php if ($member && $member->isEnterprise()): ?>
                            <li>
                                <a class="dropdown-item" href="/enterprise">
                                    <i class="bi bi-robot"></i> AI Developer
                                    <span class="badge bg-primary ms-1">Enterprise</span>
                                </a>
                            </li>
                            <?php endif; ?>
                            <li>
                                <a class="dropdown-item" href="/member/profile">
                                    <i class="bi bi-person"></i> Profile
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="/settings">
                                    <i class="bi bi-gear"></i> Settings
                                </a>
                            </li>
                            <?php if (($member['level'] ?? 100) <= 50): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item" href="/admin">
                                        <i class="bi bi-shield-lock"></i> Admin Panel
                                    </a>
                                </li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="/docs">
                                    <i class="bi bi-book"></i> Documentation
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="/help">
                                    <i class="bi bi-question-circle"></i> Help
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="/auth/logout">
                                    <i class="bi bi-box-arrow-right"></i> Logout
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
                        <a class="btn btn-primary ms-2" href="/auth/register">
                            <i class="bi bi-person-plus"></i> Register
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<!-- Toast Container for Notifications -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 11">
    <div id="liveToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="toast-header">
            <i class="bi bi-info-circle me-2"></i>
            <strong class="me-auto">Notification</strong>
            <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body"></div>
    </div>
</div>

<!-- Breadcrumb (optional) -->
<?php if (isset($breadcrumbs) && !empty($breadcrumbs)): ?>
<nav aria-label="breadcrumb">
    <div class="container">
        <ol class="breadcrumb py-2 mb-0">
            <?php foreach ($breadcrumbs as $crumb): ?>
                <?php if (isset($crumb['active']) && $crumb['active']): ?>
                    <li class="breadcrumb-item active" aria-current="page">
                        <?= htmlspecialchars($crumb['label']) ?>
                    </li>
                <?php else: ?>
                    <li class="breadcrumb-item">
                        <a href="<?= $crumb['url'] ?>"><?= htmlspecialchars($crumb['label']) ?></a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ol>
    </div>
</nav>
<?php endif; ?>
