<?php
/**
 * Schedule Tab - AJAX HTML fragment
 * Loaded into the Schedules tab of the Pipelines page
 *
 * Variables available (set by controller before include):
 *   $tplSchedules       - array of formatted schedule data
 *   $tplPipelines       - active pipelines for create form
 *   $tplScheduleTypes   - SCHEDULE_TYPES constant
 *   $tplOverlapPolicies - OVERLAP_POLICIES constant
 *   $tplTimezones       - TIMEZONES constant
 */

// Helper function to format schedule description
function formatScheduleDesc($schedule) {
    $config = $schedule['schedule_config'];
    $type = $schedule['schedule_type'];

    switch ($type) {
        case 'once':
            return 'At ' . ($config['datetime'] ?? '?');
        case 'minutely':
            $interval = $config['interval'] ?? 1;
            return "Every {$interval} minute" . ($interval > 1 ? 's' : '');
        case 'hourly':
            $minute = $config['minute'] ?? 0;
            return "At :{$minute} past every hour";
        case 'daily':
            $hour = $config['hour'] ?? 9;
            $minute = str_pad($config['minute'] ?? 0, 2, '0', STR_PAD_LEFT);
            $ampm = $hour >= 12 ? 'PM' : 'AM';
            $hour12 = $hour % 12 ?: 12;
            return "Daily at {$hour12}:{$minute} {$ampm}";
        case 'weekly':
            $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            $selectedDays = array_map(fn($d) => $days[$d], $config['days_of_week'] ?? []);
            return implode(', ', $selectedDays) ?: 'No days selected';
        case 'monthly':
            $day = $config['day_of_month'] ?? 1;
            return "On day {$day} of each month";
        case 'cron':
            return $config['cron_expression'] ?? '* * * * *';
        default:
            return '';
    }
}
?>

<!-- Quick Stats -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card text-center">
            <div class="card-body">
                <div class="fs-2 fw-bold text-primary">
                    <?= count($tplSchedules) ?>
                </div>
                <div class="text-muted small">Total Schedules</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center">
            <div class="card-body">
                <div class="fs-2 fw-bold text-success">
                    <?= count(array_filter($tplSchedules, fn($s) => $s['is_active'])) ?>
                </div>
                <div class="text-muted small">Active Schedules</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center">
            <div class="card-body">
                <div class="fs-2 fw-bold text-info">
                    <?= array_sum(array_column($tplSchedules, 'run_count')) ?>
                </div>
                <div class="text-muted small">Total Runs</div>
            </div>
        </div>
    </div>
</div>

<!-- Action Bar -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="bi bi-list-task"></i> All Schedules</h5>
    <a href="/pipelines/scheduleedit" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> New Schedule
    </a>
</div>

<?php if (empty($tplSchedules)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-calendar3 display-4 text-muted"></i>
        <h4 class="mt-3">No schedules yet</h4>
        <p class="text-muted mb-4" style="max-width: 500px; margin: 0 auto;">
            Create a schedule to automatically run your pipelines at specific times.
            Perfect for daily reports, hourly syncs, or weekly maintenance tasks.
        </p>
        <a href="/pipelines/scheduleedit" class="btn btn-primary btn-lg">
            <i class="bi bi-plus-lg"></i> Create Your First Schedule
        </a>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Schedule</th>
                    <th>Pipeline</th>
                    <th>Frequency</th>
                    <th>Next Run</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tplSchedules as $schedule): ?>
                <tr class="<?= !$schedule['is_active'] ? 'text-muted' : '' ?>" id="schedule-row-<?= $schedule['id'] ?>">
                    <td>
                        <div>
                            <strong><?= h($schedule['name']) ?></strong>
                            <?php if ($schedule['current_running'] > 0): ?>
                            <span class="badge bg-warning ms-1">
                                <i class="bi bi-arrow-repeat"></i> Running
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($schedule['description'])): ?>
                        <small class="text-muted"><?= h(substr($schedule['description'], 0, 50)) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($schedule['pipeline_slug']): ?>
                        <a href="/pipelines/edit/<?= $schedule['pipeline_id'] ?>" class="text-decoration-none">
                            <?= h($schedule['pipeline_name']) ?>
                        </a>
                        <?php else: ?>
                        <span class="text-danger"><?= h($schedule['pipeline_name']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-info">
                            <i class="bi <?= $schedule['schedule_type_info']['icon'] ?? 'bi-clock' ?>"></i>
                            <?= $schedule['schedule_type_info']['label'] ?? ucfirst($schedule['schedule_type']) ?>
                        </span>
                        <div class="small text-muted mt-1">
                            <?= formatScheduleDesc($schedule) ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($schedule['next_run_at']): ?>
                        <div><?= date('M j, g:i A', strtotime($schedule['next_run_at'])) ?></div>
                        <small class="text-muted"><?= $schedule['timezone'] ?></small>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <div class="form-check form-switch d-inline-block">
                            <input class="form-check-input" type="checkbox"
                                   id="sched-toggle-<?= $schedule['id'] ?>"
                                   <?= $schedule['is_active'] ? 'checked' : '' ?>
                                   onchange="toggleScheduleInTab(<?= $schedule['id'] ?>)">
                        </div>
                    </td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <a href="/pipelines/scheduleedit/<?= $schedule['id'] ?>" class="btn btn-outline-primary" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <button class="btn btn-outline-danger" onclick="deleteScheduleInTab(<?= $schedule['id'] ?>, '<?= h(addslashes($schedule['name'])) ?>')" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Schedule Types Reference -->
<div class="card mt-4">
    <div class="card-header">
        <i class="bi bi-info-circle"></i> Schedule Types
    </div>
    <div class="card-body">
        <div class="row">
            <?php foreach ($tplScheduleTypes as $type => $info): ?>
            <div class="col-md-3 mb-3">
                <div class="d-flex align-items-start">
                    <i class="bi <?= $info['icon'] ?> text-primary me-2 mt-1"></i>
                    <div>
                        <strong><?= $info['label'] ?></strong>
                        <small class="d-block text-muted"><?= $info['description'] ?></small>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
function toggleScheduleInTab(id) {
    // Use the csrfToken from the parent page
    const token = typeof csrfToken !== 'undefined' ? csrfToken : '';

    fetch('/pipelines/scheduletoggle/' + id, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': token
        },
        body: 'csrf_token=' + encodeURIComponent(token)
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            alert('Error: ' + (data.error || 'Failed to toggle schedule'));
            // Revert checkbox
            const cb = document.getElementById('sched-toggle-' + id);
            if (cb) cb.checked = !cb.checked;
        } else {
            // Reload the schedules tab to reflect changes
            if (typeof loadTabContent === 'function') {
                // Force reload by resetting load state
                if (typeof tabLoadState !== 'undefined') tabLoadState.schedules = false;
                loadTabContent('schedules');
            }
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
        const cb = document.getElementById('sched-toggle-' + id);
        if (cb) cb.checked = !cb.checked;
    });
}

function deleteScheduleInTab(id, name) {
    if (!confirm('Are you sure you want to delete schedule "' + name + '"?')) {
        return;
    }

    const token = typeof csrfToken !== 'undefined' ? csrfToken : '';

    fetch('/pipelines/scheduledelete/' + id, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': token
        },
        body: 'csrf_token=' + encodeURIComponent(token)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove the row from the table
            const row = document.getElementById('schedule-row-' + id);
            if (row) row.remove();

            // Reload the tab if table is now empty
            const tbody = document.querySelector('#schedules-content table tbody');
            if (tbody && tbody.children.length === 0) {
                if (typeof tabLoadState !== 'undefined') tabLoadState.schedules = false;
                if (typeof loadTabContent === 'function') loadTabContent('schedules');
            }
        } else {
            alert('Error: ' + (data.error || 'Failed to delete schedule'));
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
    });
}
</script>
