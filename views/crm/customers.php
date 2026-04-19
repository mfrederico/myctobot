<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-building"></i> Customers</h1>
        <a href="/crm/create" class="btn btn-primary"><i class="fas fa-plus"></i> New Contact</a>
    </div>

    <!-- Status Filter Tabs -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= empty($status) ? 'active' : '' ?>" href="/crm/customers">All</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $status === 'onboarding' ? 'active' : '' ?>" href="/crm/customers?status=onboarding">
                <i class="fas fa-rocket"></i> Onboarding
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $status === 'active' ? 'active' : '' ?>" href="/crm/customers?status=active">
                <i class="fas fa-check-circle"></i> Active
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $status === 'upsell' ? 'active' : '' ?>" href="/crm/customers?status=upsell">
                <i class="fas fa-arrow-up"></i> Upsell
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $status === 'touchbase' ? 'active' : '' ?>" href="/crm/customers?status=touchbase">
                <i class="fas fa-hand-wave"></i> Touch Base
            </a>
        </li>
    </ul>

    <?php if (empty($customers)): ?>
        <div class="card">
            <div class="card-body text-center text-muted py-5">
                <i class="fas fa-building fa-3x mb-3"></i>
                <p>No customers found.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-body">
                <table class="table table-hover mb-0" id="customersTable">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Company</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Tags</th>
                            <th>Last Touch</th>
                            <th>Est. Shipments</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $c): ?>
                            <tr style="cursor:pointer" onclick="window.location='/crm/view/<?= $c->id ?>'">
                                <td>
                                    <strong><?= h($c->fullName()) ?></strong>
                                    <?php if ($c->contactDesignation === 'secondary'): ?>
                                        <span class="badge bg-light text-dark">secondary</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($c->companyName) ?></td>
                                <td><span class="badge bg-<?= $c->accountType === '3pl' ? 'info' : 'success' ?>"><?= strtoupper($c->accountType) ?></span></td>
                                <td>
                                    <?php
                                    $statusBadge = match($c->customerStatus ?? '') {
                                        'onboarding' => 'bg-info',
                                        'upsell' => 'bg-warning text-dark',
                                        'touchbase' => 'bg-secondary',
                                        'active' => 'bg-success',
                                        default => 'bg-light text-dark'
                                    };
                                    ?>
                                    <span class="badge <?= $statusBadge ?>"><?= h(ucfirst($c->customerStatus ?: 'None')) ?></span>
                                </td>
                                <td>
                                    <?php foreach ($c->tagArray() as $t): ?>
                                        <span class="badge bg-dark"><?= h($t) ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td data-order="<?= $c->lastTouchAt ? strtotime($c->lastTouchAt) : 0 ?>">
                                    <?php if ($c->lastTouchAt): ?>
                                        <?php
                                        $daysSince = (int)((time() - strtotime($c->lastTouchAt)) / 86400);
                                        $touchClass = $daysSince > 7 ? 'text-danger' : ($daysSince > 3 ? 'text-warning' : 'text-success');
                                        ?>
                                        <small class="<?= $touchClass ?>"><?= date('M j', strtotime($c->lastTouchAt)) ?> (<?= $daysSince ?>d ago)</small>
                                    <?php else: ?>
                                        <small class="text-danger">Never</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($c->estimatedShipments) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
$(document).ready(function() {
    $('#customersTable').DataTable({
        pageLength: 25,
        order: [[5, 'asc']],
        columnDefs: [
            { targets: [4], orderable: false }
        ],
        language: {
            search: 'Search customers:',
            lengthMenu: 'Show _MENU_ customers',
            info: 'Showing _START_ to _END_ of _TOTAL_ customers',
            emptyTable: 'No customers found'
        },
        dom: '<"d-flex justify-content-between align-items-center mb-3"lf>t<"d-flex justify-content-between align-items-center mt-3"ip>'
    });
});
</script>
