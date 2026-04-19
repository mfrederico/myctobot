<?php
// Helper function to format schedule description
function formatScheduleDescription($schedule) {
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
<div class="container-fluid py-4" data-assist-page="schedules" data-assist-purpose="List and manage recurring pipeline schedules">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi bi-calendar3"></i> Schedules
            </h1>
            <p class="text-muted mb-0">Recurring pipeline schedules and calendar view</p>
        </div>
        <a href="/schedules/create" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> New Schedule
        </a>
    </div>

    <!-- Quick Stats -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card text-center">
                <div class="card-body">
                    <div class="fs-2 fw-bold text-primary">
                        <?= count(array_filter($schedules, fn($s) => $s['is_active'])) ?>
                    </div>
                    <div class="text-muted small">Active Schedules</div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card text-center">
                <div class="card-body">
                    <div class="fs-2 fw-bold text-success">
                        <?= array_sum(array_column($schedules, 'run_count')) ?>
                    </div>
                    <div class="text-muted small">Total Runs</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Schedule List -->
    <div class="row mb-4">
        <div class="col-12">
            <?php if (empty($schedules)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-calendar3 display-4 text-muted"></i>
                    <h4 class="mt-3">No schedules yet</h4>
                    <p class="text-muted mb-4" style="max-width: 500px; margin: 0 auto;">
                        Create a schedule to automatically run your pipelines at specific times.
                        Perfect for daily reports, hourly syncs, or weekly maintenance tasks.
                    </p>
                    <a href="/schedules/create" class="btn btn-primary btn-lg">
                        <i class="bi bi-plus-lg"></i> Create Your First Schedule
                    </a>
                </div>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-list-task"></i> All Schedules
                </div>
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
                            <?php foreach ($schedules as $schedule): ?>
                            <tr class="<?= !$schedule['is_active'] ? 'text-muted' : '' ?>">
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
                                        <?= formatScheduleDescription($schedule) ?>
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
                                               id="toggle-<?= $schedule['id'] ?>"
                                               <?= $schedule['is_active'] ? 'checked' : '' ?>
                                               onchange="toggleSchedule(<?= $schedule['id'] ?>)">
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="/schedules/edit/<?= $schedule['id'] ?>" class="btn btn-outline-primary" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <button class="btn btn-outline-danger" onclick="deleteSchedule(<?= $schedule['id'] ?>, '<?= h(addslashes($schedule['name'])) ?>')" title="Delete">
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
        </div>
    </div>

    <!-- Schedule Types Reference -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-info-circle"></i> Schedule Types
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($scheduleTypes as $type => $info): ?>
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
        </div>
    </div>

    <!-- Calendar View (Bottom) -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-calendar-week"></i> Pipeline Calendar</span>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-sm btn-outline-light active" onclick="switchCalView('dayGridMonth')" id="calViewMonth">Month</button>
                        <button class="btn btn-sm btn-outline-light" onclick="switchCalView('timeGridWeek')" id="calViewWeek">Week</button>
                        <button class="btn btn-sm btn-outline-light" onclick="switchCalView('listWeek')" id="calViewList">List</button>
                    </div>
                </div>
                <div class="card-body p-2">
                    <div id="schedulesFullCalendar" style="min-height: 500px;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- FullCalendar 6.x -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>

<script>
const csrfToken = '<?= Flight::csrf()->getToken() ?>';
let _schedulesCalendar = null;

document.addEventListener('DOMContentLoaded', function() {
    const el = document.getElementById('schedulesFullCalendar');
    if (!el || typeof FullCalendar === 'undefined') return;

    _schedulesCalendar = new FullCalendar.Calendar(el, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: ''
        },
        height: 'auto',
        events: function(info, successCallback, failureCallback) {
            const start = info.startStr.substring(0, 10);
            const end = info.endStr.substring(0, 10);
            fetch(`/schedules/calendardata?start=${start}&end=${end}`)
                .then(r => r.json())
                .then(events => successCallback(events))
                .catch(err => failureCallback(err));
        },
        eventClick: function(info) {
            const props = info.event.extendedProps;
            switch (props.type) {
                case 'pipeline_run':
                    if (props.run_id) window.location.href = '/pipelines/viewrun/' + props.run_id;
                    break;
                case 'pipeline_run_group':
                    if (props.pipeline_id) window.location.href = '/pipelines/runs/' + props.pipeline_id;
                    break;
                case 'recurring_schedule':
                    if (props.schedule_id) window.location.href = '/schedules/edit/' + props.schedule_id;
                    break;
                case 'scheduled_task':
                    if (props.run_id) window.location.href = '/pipelines/viewrun/' + props.run_id;
                    else if (props.pipeline_id) window.location.href = '/pipelines/edit/' + props.pipeline_id;
                    break;
            }
        },
        themeSystem: 'standard'
    });
    _schedulesCalendar.render();
});

function switchCalView(view) {
    if (!_schedulesCalendar) return;
    _schedulesCalendar.changeView(view);
    // Update button active states
    document.querySelectorAll('#calViewMonth, #calViewWeek, #calViewList').forEach(b => b.classList.remove('active'));
    if (view === 'dayGridMonth') document.getElementById('calViewMonth').classList.add('active');
    else if (view === 'timeGridWeek') document.getElementById('calViewWeek').classList.add('active');
    else if (view === 'listWeek') document.getElementById('calViewList').classList.add('active');
}

function toggleSchedule(id) {
    fetch('/schedules/toggle/' + id, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrfToken
        },
        body: 'csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            alert('Error: ' + (data.error || 'Failed to toggle schedule'));
            // Revert checkbox
            document.getElementById('toggle-' + id).checked = !document.getElementById('toggle-' + id).checked;
        } else {
            // Reload to update calendar
            location.reload();
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
        document.getElementById('toggle-' + id).checked = !document.getElementById('toggle-' + id).checked;
    });
}

function deleteSchedule(id, name) {
    if (!confirm('Are you sure you want to delete schedule "' + name + '"?')) {
        return;
    }

    fetch('/schedules/delete/' + id, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrfToken
        },
        body: 'csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Failed to delete schedule'));
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
    });
}
</script>
