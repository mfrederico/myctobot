<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">Member Management</h1>
        <a href="/admin/addMember" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add New Member
        </a>
    </div>
    
    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success ?? '') ?></div>
    <?php endif; ?>
    
    <!-- Search and Filter -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="input-group">
                <input type="text" class="form-control" id="memberSearch" placeholder="Search members...">
                <button class="btn btn-outline-secondary" type="button" id="clearSearch">Clear</button>
            </div>
        </div>
        <div class="col-md-3">
            <select class="form-select" id="levelFilter">
                <option value="">All Levels</option>
                <option value="0,1">ROOT (0-1)</option>
                <option value="50">ADMIN (50)</option>
                <option value="100">MEMBER (100)</option>
                <option value="101">PUBLIC (101)</option>
            </select>
        </div>
        <div class="col-md-3">
            <select class="form-select" id="statusFilter">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-primary" onclick="exportMembers()">Export CSV</button>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Level</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($members as $member): ?>
                    <tr>
                        <td><?= $member->id ?></td>
                        <td><?= htmlspecialchars($member->username ?? '') ?></td>
                        <td><?= htmlspecialchars($member->email ?? '') ?></td>
                        <td>
                            <?php
                            $levelName = 'Unknown';
                            $levelClass = 'secondary';
                            switch($member->level) {
                                case 0:
                                case 1:
                                    $levelName = 'ROOT';
                                    $levelClass = 'danger';
                                    break;
                                case 50:
                                    $levelName = 'ADMIN';
                                    $levelClass = 'warning';
                                    break;
                                case 100:
                                    $levelName = 'MEMBER';
                                    $levelClass = 'primary';
                                    break;
                                case 101:
                                    $levelName = 'PUBLIC';
                                    $levelClass = 'success';
                                    break;
                            }
                            ?>
                            <span class="badge bg-<?= $levelClass ?>">
                                <?= $levelName ?> (<?= $member->level ?>)
                            </span>
                        </td>
                        <td>
                            <?php
                            $statusClass = 'secondary';
                            switch($member->status) {
                                case 'active':
                                    $statusClass = 'success';
                                    break;
                                case 'suspended':
                                    $statusClass = 'warning';
                                    break;
                                case 'inactive':
                                    $statusClass = 'secondary';
                                    break;
                            }
                            // Special case for public-user-entity
                            if ($member->username === 'public-user-entity') {
                                $statusClass = 'info';
                            }
                            ?>
                            <span class="badge bg-<?= $statusClass ?>">
                                <?= htmlspecialchars($member->status ?? '') ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                            if (!empty($member->created_at) && strtotime($member->created_at) !== false) {
                                echo date('Y-m-d', strtotime($member->created_at));
                            } else {
                                echo '<span class="text-muted">N/A</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <a href="/admin/editMember?id=<?= $member->id ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                            <?php if ($member->username !== 'public-user-entity' && $member->id != $_SESSION['member']['id']): ?>
                                <a href="/admin/members?delete=<?= $member->id ?>" 
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Delete this member?')">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="mt-4">
        <h4>User Levels</h4>
        <ul class="list-group">
            <li class="list-group-item"><span class="badge bg-danger">0-1</span> ROOT - Full system access</li>
            <li class="list-group-item"><span class="badge bg-warning">50</span> ADMIN - Administrative access</li>
            <li class="list-group-item"><span class="badge bg-primary">100</span> MEMBER - Regular members</li>
            <li class="list-group-item"><span class="badge bg-success">101</span> PUBLIC - Public/Guest access</li>
        </ul>
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
        const statusValue = statusFilter.value;
        
        Array.from(table.children).forEach(row => {
            const username = row.cells[1].textContent.toLowerCase();
            const email = row.cells[2].textContent.toLowerCase();
            const level = row.cells[3].textContent;
            const status = row.cells[4].textContent.toLowerCase();
            
            let showRow = true;
            
            // Search filter
            if (searchTerm && !username.includes(searchTerm) && !email.includes(searchTerm)) {
                showRow = false;
            }
            
            // Level filter
            if (levelValue) {
                const levelNumbers = levelValue.split(',');
                const rowLevel = level.match(/\((\d+)\)/);
                if (!rowLevel || !levelNumbers.includes(rowLevel[1])) {
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
    const rows = Array.from(table.querySelectorAll('tr')).filter(row => 
        row.style.display !== 'none' && row.cells.length > 1
    );
    
    let csv = 'ID,Username,Email,Level,Status,Created\n';
    
    rows.forEach(row => {
        if (row.cells.length >= 6) {
            const cells = Array.from(row.cells).slice(0, 6);
            const rowData = cells.map(cell => {
                let text = cell.textContent.trim();
                // Clean up badge text
                if (cell.querySelector('.badge')) {
                    text = cell.querySelector('.badge').textContent.trim();
                }
                return '"' + text.replace(/"/g, '""') + '"';
            });
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