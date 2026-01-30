<?php
// Include InviteService for status helpers
require_once __DIR__ . '/../../services/InviteService.php';
use app\services\InviteService;
?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2"><i class="bi bi-people"></i> Member Management</h1>
        <a href="/admin/addmember" class="btn btn-primary">
            <i class="bi bi-envelope-plus"></i> Invite New Member
        </a>
    </div>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Search and Filter -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="input-group">
                <input type="text" class="form-control" id="memberSearch" placeholder="Search members...">
                <button class="btn btn-outline-secondary" type="button" id="clearSearch">Clear</button>
            </div>
        </div>
        <div class="col-md-2">
            <select class="form-select" id="levelFilter">
                <option value="">All Levels</option>
                <option value="0,1">ROOT (0-1)</option>
                <option value="50">ADMIN (50)</option>
                <option value="100">MEMBER (100)</option>
                <option value="101">PUBLIC (101)</option>
            </select>
        </div>
        <div class="col-md-2">
            <select class="form-select" id="statusFilter">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="pending">Pending Invite</option>
                <option value="suspended">Suspended</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-primary" onclick="exportMembers()">
                <i class="bi bi-download"></i> Export CSV
            </button>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;">ID</th>
                            <th>Member</th>
                            <th style="width: 100px;">Level</th>
                            <th style="width: 150px;">Status</th>
                            <th style="width: 150px;">Invite Sent</th>
                            <th style="width: 120px;">Last Login</th>
                            <th style="width: 180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $member): ?>
                            <?php
                            // Get invite status
                            $inviteStatus = InviteService::getInviteStatus($member);

                            // Level display
                            $levelName = 'Unknown';
                            $levelClass = 'secondary';
                            switch((int)$member->level) {
                                case 0:
                                case 1:
                                    $levelName = 'ROOT';
                                    $levelClass = 'danger';
                                    break;
                                case 50:
                                    $levelName = 'ADMIN';
                                    $levelClass = 'warning text-dark';
                                    break;
                                case 100:
                                    $levelName = 'MEMBER';
                                    $levelClass = 'primary';
                                    break;
                                case 101:
                                    $levelName = 'PUBLIC';
                                    $levelClass = 'secondary';
                                    break;
                            }

                            // Is system user?
                            $isSystemUser = ($member->username === 'public-user-entity' || $member->status === 'system');
                            $isCurrentUser = ($member->id == ($_SESSION['member']['id'] ?? 0));
                            ?>
                            <tr class="<?= $member->status === 'pending' ? 'table-warning' : '' ?>">
                                <td class="text-muted"><?= $member->id ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <?php if ($member->avatar_url): ?>
                                            <img src="<?= htmlspecialchars($member->avatar_url) ?>" class="rounded-circle me-2" style="width: 32px; height: 32px;">
                                        <?php else: ?>
                                            <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                                                <?= strtoupper(substr($member->email ?? 'U', 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <strong><?= htmlspecialchars($member->display_name ?: $member->username ?: 'Unnamed') ?></strong>
                                            <br>
                                            <small class="text-muted"><?= htmlspecialchars($member->email ?? '') ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $levelClass ?>">
                                        <?= $levelName ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $inviteStatus['color'] ?>">
                                        <i class="bi bi-<?= $inviteStatus['icon'] ?>"></i>
                                        <?= $inviteStatus['label'] ?>
                                    </span>
                                    <?php if ($member->status === 'pending' && $member->invite_expires_at): ?>
                                        <?php
                                        $expiresIn = strtotime($member->invite_expires_at) - time();
                                        $daysLeft = floor($expiresIn / 86400);
                                        ?>
                                        <?php if ($expiresIn > 0): ?>
                                            <br><small class="text-muted">Expires in <?= $daysLeft ?> day<?= $daysLeft != 1 ? 's' : '' ?></small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($member->invite_sent_at): ?>
                                        <span title="<?= htmlspecialchars($member->invite_sent_at) ?>">
                                            <?= InviteService::timeAgo($member->invite_sent_at) ?>
                                        </span>
                                    <?php elseif ($member->status !== 'pending'): ?>
                                        <span class="text-muted">-</span>
                                    <?php else: ?>
                                        <span class="text-muted">Not sent</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($member->last_login): ?>
                                        <span title="<?= htmlspecialchars($member->last_login) ?>">
                                            <?= InviteService::timeAgo($member->last_login) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">Never</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$isSystemUser): ?>
                                        <?php if ($member->status === 'pending'): ?>
                                            <a href="/admin/resendinvite?id=<?= $member->id ?>"
                                               class="btn btn-sm btn-outline-primary"
                                               title="Resend Invitation"
                                               onclick="return confirm('Resend invitation to <?= htmlspecialchars($member->email) ?>?')">
                                                <i class="bi bi-envelope-arrow-up"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="/admin/editmember?id=<?= $member->id ?>"
                                           class="btn btn-sm btn-outline-secondary"
                                           title="Edit Member">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <?php if (!$isCurrentUser): ?>
                                            <a href="/admin/members?delete=<?= $member->id ?>"
                                               class="btn btn-sm btn-outline-danger"
                                               title="Delete Member"
                                               onclick="return confirm('Delete this member? This cannot be undone.')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">System User</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Legend -->
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-light">
                    <strong>Permission Levels</strong>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td><span class="badge bg-danger">ROOT</span></td>
                            <td>Full system access, can manage all admins</td>
                        </tr>
                        <tr>
                            <td><span class="badge bg-warning text-dark">ADMIN</span></td>
                            <td>Administrative access, member management</td>
                        </tr>
                        <tr>
                            <td><span class="badge bg-primary">MEMBER</span></td>
                            <td>Standard user access</td>
                        </tr>
                        <tr>
                            <td><span class="badge bg-secondary">PUBLIC</span></td>
                            <td>Limited/guest access</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-light">
                    <strong>Invite Status</strong>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td><span class="badge bg-success"><i class="bi bi-check-circle-fill"></i> Active</span></td>
                            <td>User has activated their account</td>
                        </tr>
                        <tr>
                            <td><span class="badge bg-warning"><i class="bi bi-hourglass-split"></i> Invite Pending</span></td>
                            <td>Invitation sent, waiting for user to accept</td>
                        </tr>
                        <tr>
                            <td><span class="badge bg-danger"><i class="bi bi-clock-history"></i> Invite Expired</span></td>
                            <td>Invitation expired, needs to be resent</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Member search and filtering functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('memberSearch');
    const levelFilter = document.getElementById('levelFilter');
    const statusFilter = document.getElementById('statusFilter');
    const clearButton = document.getElementById('clearSearch');
    const table = document.querySelector('.table tbody');

    function filterMembers() {
        const searchTerm = searchInput.value.toLowerCase();
        const levelValue = levelFilter.value;
        const statusValue = statusFilter.value.toLowerCase();

        Array.from(table.children).forEach(row => {
            const memberCell = row.cells[1].textContent.toLowerCase();
            const level = row.cells[2].textContent.toLowerCase();
            const status = row.cells[3].textContent.toLowerCase();

            let showRow = true;

            // Search filter (searches name and email)
            if (searchTerm && !memberCell.includes(searchTerm)) {
                showRow = false;
            }

            // Level filter
            if (levelValue) {
                const levelNumbers = levelValue.split(',').map(l => l.toLowerCase());
                let matchesLevel = false;
                levelNumbers.forEach(l => {
                    if (level.includes(l) || level.includes(getLevelName(l))) {
                        matchesLevel = true;
                    }
                });
                if (!matchesLevel) {
                    showRow = false;
                }
            }

            // Status filter
            if (statusValue && !status.includes(statusValue)) {
                showRow = false;
            }

            row.style.display = showRow ? '' : 'none';
        });
    }

    function getLevelName(level) {
        switch(level) {
            case '0':
            case '1': return 'root';
            case '50': return 'admin';
            case '100': return 'member';
            case '101': return 'public';
            default: return '';
        }
    }

    searchInput.addEventListener('input', filterMembers);
    levelFilter.addEventListener('change', filterMembers);
    statusFilter.addEventListener('change', filterMembers);

    clearButton.addEventListener('click', function() {
        searchInput.value = '';
        levelFilter.value = '';
        statusFilter.value = '';
        filterMembers();
    });
});

// Export members to CSV
function exportMembers() {
    const table = document.querySelector('.table');
    const rows = Array.from(table.querySelectorAll('tbody tr')).filter(row =>
        row.style.display !== 'none'
    );

    let csv = 'ID,Name,Email,Level,Status,Invite Sent,Last Login\n';

    rows.forEach(row => {
        if (row.cells.length >= 6) {
            const id = row.cells[0].textContent.trim();
            const memberCell = row.cells[1];
            const name = memberCell.querySelector('strong')?.textContent.trim() || '';
            const email = memberCell.querySelector('small')?.textContent.trim() || '';
            const level = row.cells[2].querySelector('.badge')?.textContent.trim() || '';
            const status = row.cells[3].querySelector('.badge')?.textContent.trim() || '';
            const inviteSent = row.cells[4].textContent.trim();
            const lastLogin = row.cells[5].textContent.trim();

            const rowData = [id, name, email, level, status, inviteSent, lastLogin].map(cell =>
                '"' + cell.replace(/"/g, '""') + '"'
            );
            csv += rowData.join(',') + '\n';
        }
    });

    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'members_' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}
</script>
