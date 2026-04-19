<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>
            <i class="fas fa-address-book"></i>
            <?php if ($filter === 'prospects'): ?>Prospects
            <?php elseif ($filter === 'customers'): ?>Customers
            <?php else: ?>All Contacts<?php endif; ?>
        </h1>
        <a href="/crm/create" class="btn btn-primary"><i class="fas fa-plus"></i> New Contact</a>
    </div>

    <?php if (empty($contacts)): ?>
        <div class="card">
            <div class="card-body text-center text-muted py-5">
                <i class="fas fa-users fa-3x mb-3"></i>
                <p>No contacts found. <a href="/crm/create">Add your first contact</a>.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-body">
                <table class="table table-hover mb-0" id="contactsTable">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Company</th>
                            <th>Email</th>
                            <th>Type</th>
                            <th>Stage / Status</th>
                            <th class="text-center">Score</th>
                            <th>Tags</th>
                            <th>Last Touch</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contacts as $c): ?>
                            <tr style="cursor:pointer" onclick="window.location='/crm/view/<?= $c->id ?>'">
                                <td>
                                    <strong><?= h($c->fullName()) ?></strong>
                                    <?php if ($c->contactDesignation === 'secondary'): ?>
                                        <span class="badge bg-light text-dark">secondary</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($c->companyName) ?></td>
                                <td><small><?= h($c->companyEmail ?? '') ?></small></td>
                                <td>
                                    <span class="badge bg-<?= $c->accountType === '3pl' ? 'info' : 'success' ?>">
                                        <?= strtoupper($c->accountType ?? '') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($c->statusCategory === 'prospect'): ?>
                                        <span class="badge <?= $c->stageBadgeClass() ?>"><?= $c->stageLabel() ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Customer</span>
                                        <?php if ($c->customerStatus): ?>
                                            <small class="text-muted"><?= h(ucfirst($c->customerStatus)) ?></small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center" data-order="<?= (int)($c->leadScore ?? 0) ?>">
                                    <?php $ls = (int)($c->leadScore ?? 0); ?>
                                    <?php if ($ls > 0): ?>
                                        <span class="badge bg-<?= $ls >= 70 ? 'success' : ($ls >= 40 ? 'warning text-dark' : 'secondary') ?>"><?= $ls ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php foreach ($c->tagArray() as $t): ?>
                                        <span class="badge bg-dark"><?= h($t) ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td data-order="<?= $c->lastTouchAt ? strtotime($c->lastTouchAt) : 0 ?>">
                                    <?php if ($c->lastTouchAt): ?>
                                        <small><?= date('M j', strtotime($c->lastTouchAt)) ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">Never</small>
                                    <?php endif; ?>
                                </td>
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
    $('#contactsTable').DataTable({
        pageLength: 25,
        order: [[5, 'desc']],
        columnDefs: [
            { targets: [5], type: 'num' },
            { targets: [6], orderable: false }
        ],
        language: {
            search: 'Search contacts:',
            lengthMenu: 'Show _MENU_ contacts',
            info: 'Showing _START_ to _END_ of _TOTAL_ contacts',
            emptyTable: 'No contacts found'
        },
        dom: '<"d-flex justify-content-between align-items-center mb-3"lf>t<"d-flex justify-content-between align-items-center mt-3"ip>'
    });
});
</script>
