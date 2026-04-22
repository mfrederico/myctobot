<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'CRM - MyCTOBot') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: #f0f2f5; min-height: 100vh; }

        .crm-sidebar {
            width: 240px;
            background: #1a1d23;
            min-height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1040;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s;
        }
        .crm-sidebar .sidebar-brand {
            padding: 16px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }
        .crm-sidebar .sidebar-brand img {
            height: 30px;
        }
        .crm-sidebar .sidebar-brand span {
            color: #6c757d;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-left: 8px;
        }
        .crm-sidebar .nav-section {
            padding: 16px 0 8px 20px;
            color: #6c757d;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 600;
        }
        .crm-sidebar .nav-link {
            color: #a0a4aa;
            padding: 9px 20px;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.15s;
            border-left: 3px solid transparent;
        }
        .crm-sidebar .nav-link:hover {
            color: #fff;
            background: rgba(255,255,255,0.05);
        }
        .crm-sidebar .nav-link.active {
            color: #fff;
            background: rgba(13,110,253,0.15);
            border-left-color: #0d6efd;
        }
        .crm-sidebar .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 0.9rem;
        }

        .crm-sidebar .sidebar-footer {
            margin-top: auto;
            padding: 16px 20px;
            border-top: 1px solid rgba(255,255,255,0.08);
        }
        .crm-sidebar .sidebar-footer .user-info {
            color: #a0a4aa;
            font-size: 0.8rem;
        }
        .crm-sidebar .sidebar-footer .user-info strong {
            color: #fff;
            display: block;
            font-size: 0.85rem;
        }

        .crm-main {
            margin-left: 240px;
            min-height: 100vh;
        }

        .crm-topbar {
            background: #fff;
            border-bottom: 1px solid #dee2e6;
            padding: 10px 24px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 16px;
        }

        .crm-content {
            padding: 0;
        }

        /* Mobile: collapsible sidebar */
        .crm-sidebar-toggle {
            display: none;
            position: fixed;
            top: 12px;
            left: 12px;
            z-index: 1050;
            background: #1a1d23;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 1.1rem;
        }
        .crm-sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1035;
        }
        @media (max-width: 991.98px) {
            .crm-sidebar { transform: translateX(-100%); }
            .crm-sidebar.show { transform: translateX(0); }
            .crm-main { margin-left: 0; }
            .crm-sidebar-toggle { display: block; }
            .crm-sidebar.show ~ .crm-sidebar-overlay { display: block; }
        }
    </style>
</head>
<body>

<button class="crm-sidebar-toggle" id="sidebarToggle">
    <i class="fas fa-bars"></i>
</button>

<aside class="crm-sidebar" id="crmSidebar">
    <div class="sidebar-brand">
        <a href="/" class="text-decoration-none">
            <span style="color:#fff;font-weight:600;font-size:1.1rem;">MyCTOBot</span>
            <span>CRM</span>
        </a>
    </div>

    <nav>
        <?php if (empty($hide_nav)): ?>
        <div class="nav-section">Sales</div>
        <a href="/crm" class="nav-link <?= ($crm_page ?? '') === 'dashboard' ? 'active' : '' ?>">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>
        <a href="/crm/pipeline" class="nav-link <?= ($crm_page ?? '') === 'pipeline' ? 'active' : '' ?>">
            <i class="fas fa-columns"></i> Pipeline
        </a>
        <a href="/crm/contacts?filter=prospects" class="nav-link <?= ($crm_page ?? '') === 'prospects' ? 'active' : '' ?>">
            <i class="fas fa-bullseye"></i> Prospects
        </a>
        <a href="/communications" class="nav-link <?= ($crm_page ?? '') === 'communications' ? 'active' : '' ?>">
            <i class="fas fa-inbox"></i> Inbox
        </a>

        <div class="nav-section">Accounts</div>
        <a href="/crm/contacts" class="nav-link <?= ($crm_page ?? '') === 'contacts' ? 'active' : '' ?>">
            <i class="fas fa-address-book"></i> All Contacts
        </a>
        <a href="/crm/customers" class="nav-link <?= ($crm_page ?? '') === 'customers' ? 'active' : '' ?>">
            <i class="fas fa-building"></i> Customers
        </a>

        <div class="nav-section">Actions</div>
        <a href="/crm/create" class="nav-link <?= ($crm_page ?? '') === 'create' ? 'active' : '' ?>">
            <i class="fas fa-user-plus"></i> New Contact
        </a>

        <?php if ($member->level <= 50): ?>
        <div class="nav-section">Admin</div>
        <a href="/crm/teamactivity" class="nav-link <?= ($crm_page ?? '') === 'teamactivity' ? 'active' : '' ?>">
            <i class="fas fa-users-cog"></i> Team Activity
        </a>
        <a href="/crm/settings" class="nav-link <?= ($crm_page ?? '') === 'settings' ? 'active' : '' ?>">
            <i class="fas fa-cog"></i> Pipeline Settings
        </a>
        <?php endif; ?>

        <div class="nav-section">Legal</div>
        <a href="/agreement/view" class="nav-link <?= ($crm_page ?? '') === 'agreement' ? 'active' : '' ?>">
            <i class="fas fa-file-signature"></i> My Agreement
        </a>
        <?php else: ?>
        <div class="nav-section">Agreement Required</div>
        <a href="/agreement" class="nav-link active">
            <i class="fas fa-file-signature"></i> Sign Agreement
        </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="user-info">
            <strong><?= htmlspecialchars($member->displayName() ?? 'User') ?></strong>
            <?= ucfirst($member->level <= 50 ? 'Admin' : 'Sales Rep') ?>
        </div>
        <a href="/dashboard" class="btn btn-sm btn-outline-secondary mt-2 w-100"><i class="fas fa-arrow-left"></i> Back to App</a>
    </div>
</aside>

<div class="crm-sidebar-overlay" id="sidebarOverlay"></div>

<div class="crm-main">
    <div class="crm-topbar">
        <a href="/auth/logout" class="btn btn-sm btn-outline-secondary"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
    <div class="crm-content">
        <?= $body_content ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    var toggle = document.getElementById('sidebarToggle');
    var sidebar = document.getElementById('crmSidebar');
    var overlay = document.getElementById('sidebarOverlay');
    if (toggle && sidebar) {
        toggle.addEventListener('click', function() { sidebar.classList.toggle('show'); });
        overlay.addEventListener('click', function() { sidebar.classList.remove('show'); });
    }
})();
</script>

<?php include __DIR__ . '/../partials/pageassist.php'; ?>
</body>
</html>
