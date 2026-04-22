<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-users-cog"></i> Team Activity</h1>
        <div>
            <span class="badge bg-dark fs-6"><?= $totalContacts ?> contacts</span>
            <?php if ($unassigned > 0): ?>
                <span class="badge bg-warning text-dark fs-6"><?= $unassigned ?> unassigned</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alert cards for issues -->
    <?php
    $inactiveReps = array_filter($repStats, fn($r) => $r['is_inactive']);
    $staleReps = array_filter($repStats, fn($r) => $r['is_stale'] && !$r['is_inactive']);
    ?>
    <?php if (!empty($inactiveReps)): ?>
        <div class="alert alert-danger d-flex align-items-center mb-3">
            <i class="fas fa-exclamation-triangle me-2 fs-5"></i>
            <div>
                <strong><?= count($inactiveReps) ?> inactive rep(s)</strong> — no login or activity in 7+ days:
                <?php foreach ($inactiveReps as $r): ?>
                    <span class="badge bg-danger ms-1"><?= h($r['member']->email) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($staleReps)): ?>
        <div class="alert alert-warning d-flex align-items-center mb-3">
            <i class="fas fa-clock me-2 fs-5"></i>
            <div>
                <strong><?= count($staleReps) ?> stale rep(s)</strong> — no login or activity in 3+ days:
                <?php foreach ($staleReps as $r): ?>
                    <span class="badge bg-warning text-dark ms-1"><?= h($r['member']->email) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Rep Performance Table -->
    <div class="card">
        <div class="card-body">
            <table class="table table-hover mb-0" id="teamTable">
                <thead>
                    <tr>
                        <th>Rep</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Last Login</th>
                        <th class="text-center">Logins</th>
                        <th class="text-center">Contacts Owned</th>
                        <th class="text-center">Activities <small class="text-muted">(7d / 30d)</small></th>
                        <th class="text-center">Touches <small class="text-muted">(7d / 30d)</small></th>
                        <th class="text-center">Notes <small class="text-muted">(30d)</small></th>
                        <th class="text-center">Created <small class="text-muted">(30d)</small></th>
                        <th class="text-center">Converted <small class="text-muted">(30d)</small></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($repStats as $r): ?>
                        <?php $m = $r['member']; ?>
                        <tr class="<?= $r['is_inactive'] ? 'table-danger' : ($r['is_stale'] ? 'table-warning' : '') ?>">
                            <td>
                                <strong><?= h($m->displayName()) ?></strong>
                                <br><small class="text-muted"><?= h($m->email) ?></small>
                            </td>
                            <td class="text-center" data-order="<?= $r['is_inactive'] ? 0 : ($r['is_stale'] ? 1 : 2) ?>">
                                <?php if ($r['is_inactive']): ?>
                                    <span class="badge bg-danger">Inactive</span>
                                <?php elseif ($r['is_stale']): ?>
                                    <span class="badge bg-warning text-dark">Stale</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Active</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center" data-order="<?= $r['last_login'] ? strtotime($r['last_login']) : 0 ?>">
                                <?php if ($r['last_login']): ?>
                                    <?= date('M j', strtotime($r['last_login'])) ?>
                                    <?php if ($r['days_since_login'] !== null): ?>
                                        <br><small class="text-<?= $r['days_since_login'] > 7 ? 'danger' : ($r['days_since_login'] > 3 ? 'warning' : 'success') ?>"><?= $r['days_since_login'] ?>d ago</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-danger">Never</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?= $r['login_count'] ?></td>
                            <td class="text-center"><?= $r['contacts_owned'] ?></td>
                            <td class="text-center">
                                <strong><?= $r['activities_7d'] ?></strong> / <?= $r['activities_30d'] ?>
                            </td>
                            <td class="text-center">
                                <strong><?= $r['touches_7d'] ?></strong> / <?= $r['touches_30d'] ?>
                            </td>
                            <td class="text-center"><?= $r['notes_30d'] ?></td>
                            <td class="text-center"><?= $r['contacts_created_30d'] ?></td>
                            <td class="text-center">
                                <?php if ($r['conversions_30d'] > 0): ?>
                                    <span class="badge bg-success"><?= $r['conversions_30d'] ?></span>
                                <?php else: ?>
                                    0
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Activity Feed (all reps) -->
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-stream"></i> Recent Activity (All Reps)
                </div>
                <div class="card-body p-0">
                    <?php
                    $allActivity = \app\Bean::find('crmactivity', '1=1 ORDER BY created_at DESC LIMIT 30');
                    $memberCache = [];
                    ?>
                    <?php if (empty($allActivity)): ?>
                        <p class="text-muted p-3 mb-0">No activity yet.</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($allActivity as $a): ?>
                                <?php
                                $mid = (int)($a->memberId ?? 0);
                                if ($mid && !isset($memberCache[$mid])) {
                                    $memberCache[$mid] = \app\Bean::load('member', $mid);
                                }
                                $repName = isset($memberCache[$mid]) ? $memberCache[$mid]->displayName() : 'System';
                                ?>
                                <div class="list-group-item py-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="badge bg-secondary me-1"><?= h($repName) ?></span>
                                            <?= h($a->description ?? $a->activityType ?? '') ?>
                                            <?php if ($a->crmcontactId): ?>
                                                <a href="/crm/view/<?= $a->crmcontactId ?>" class="text-decoration-none ms-1"><small>#<?= $a->crmcontactId ?></small></a>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted text-nowrap"><?= $a->createdAt ? date('M j g:ia', strtotime($a->createdAt)) : '' ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#teamTable').DataTable({
        paging: false,
        order: [[1, 'asc']],
        language: { search: 'Filter reps:' },
        dom: '<"mb-3"f>t'
    });
});
</script>
