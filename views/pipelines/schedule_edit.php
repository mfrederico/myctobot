<?php
$isEdit = isset($schedule) && $schedule;
$action = '/pipelines/schedulestore';
$config = $scheduleConfig ?? [];
?>

<div class="container py-4" style="max-width: 900px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="/pipelines?tab=schedules">Pipelines / Schedules</a></li>
                    <li class="breadcrumb-item active"><?= $isEdit ? 'Edit' : 'Create' ?></li>
                </ol>
            </nav>
            <h1 class="h2 mb-0">
                <i class="bi bi-calendar-plus"></i>
                <?= $isEdit ? 'Edit Schedule' : 'Create Schedule' ?>
            </h1>
        </div>
        <a href="/pipelines?tab=schedules" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>

    <form method="POST" action="<?= $action ?>" id="scheduleForm">
        <input type="hidden" name="csrf_token" value="<?= Flight::csrf()->getToken() ?>">
        <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= $schedule->id ?>">
        <?php endif; ?>

        <div class="row">
            <!-- Main Form -->
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-info-circle"></i> Basic Information
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="name" class="form-label">Schedule Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name"
                                   value="<?= h($isEdit ? $schedule->name : '') ?>"
                                   placeholder="e.g., Daily Morning Report" required>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="2"
                                      placeholder="Optional description"><?= h($isEdit ? $schedule->description : '') ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="pipeline_id" class="form-label">Pipeline <span class="text-danger">*</span></label>
                            <select class="form-select" id="pipeline_id" name="pipeline_id" required>
                                <option value="">Select a pipeline...</option>
                                <?php foreach ($pipelines as $p): ?>
                                <option value="<?= $p->id ?>" <?= ($isEdit && $schedule->pipeline_id == $p->id) ? 'selected' : '' ?>>
                                    <?= h($p->name) ?> (<?= h($p->slug) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-clock"></i> Schedule Configuration
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <label class="form-label">Schedule Type <span class="text-danger">*</span></label>
                            <div class="row">
                                <?php foreach ($scheduleTypes as $type => $info): ?>
                                <div class="col-md-4 mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="schedule_type" id="type-<?= $type ?>"
                                               value="<?= $type ?>"
                                               <?= ($isEdit && $schedule->schedule_type === $type) || (!$isEdit && $type === 'daily') ? 'checked' : '' ?>
                                               onchange="updateScheduleConfig()">
                                        <label class="form-check-label" for="type-<?= $type ?>">
                                            <i class="bi <?= $info['icon'] ?> text-primary me-1"></i>
                                            <?= $info['label'] ?>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Once Config -->
                        <div id="config-once" class="schedule-config" style="display: none;">
                            <div class="mb-3">
                                <label for="once_datetime" class="form-label">Date & Time</label>
                                <input type="datetime-local" class="form-control" id="once_datetime" name="once_datetime"
                                       value="<?= h($config['datetime'] ?? '') ?>">
                            </div>
                        </div>

                        <!-- Minutely Config -->
                        <div id="config-minutely" class="schedule-config" style="display: none;">
                            <div class="mb-3">
                                <label for="minutely_interval" class="form-label">Run every</label>
                                <div class="input-group" style="max-width: 200px;">
                                    <input type="number" class="form-control" id="minutely_interval" name="minutely_interval"
                                           min="1" max="60" value="<?= (int)($config['interval'] ?? 5) ?>">
                                    <span class="input-group-text">minutes</span>
                                </div>
                            </div>
                        </div>

                        <!-- Hourly Config -->
                        <div id="config-hourly" class="schedule-config" style="display: none;">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="hourly_interval" class="form-label">Run every</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="hourly_interval" name="hourly_interval"
                                               min="1" max="24" value="<?= (int)($config['interval'] ?? 1) ?>">
                                        <span class="input-group-text">hours</span>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="hourly_minute" class="form-label">At minute</label>
                                    <select class="form-select" id="hourly_minute" name="hourly_minute">
                                        <?php for ($m = 0; $m < 60; $m += 5): ?>
                                        <option value="<?= $m ?>" <?= ($config['minute'] ?? 0) == $m ? 'selected' : '' ?>>
                                            :<?= str_pad($m, 2, '0', STR_PAD_LEFT) ?>
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Daily Config -->
                        <div id="config-daily" class="schedule-config">
                            <div class="mb-3">
                                <label class="form-label">Time of day</label>
                                <div class="row" style="max-width: 300px;">
                                    <div class="col-6">
                                        <select class="form-select" id="daily_hour" name="daily_hour">
                                            <?php for ($h = 0; $h < 24; $h++): ?>
                                            <option value="<?= $h ?>" <?= ($config['hour'] ?? 9) == $h ? 'selected' : '' ?>>
                                                <?= $h % 12 ?: 12 ?> <?= $h >= 12 ? 'PM' : 'AM' ?>
                                            </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <select class="form-select" id="daily_minute" name="daily_minute">
                                            <?php for ($m = 0; $m < 60; $m += 5): ?>
                                            <option value="<?= $m ?>" <?= ($config['minute'] ?? 0) == $m ? 'selected' : '' ?>>
                                                :<?= str_pad($m, 2, '0', STR_PAD_LEFT) ?>
                                            </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Weekly Config -->
                        <div id="config-weekly" class="schedule-config" style="display: none;">
                            <div class="mb-3">
                                <label class="form-label">Days of week</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php
                                    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                                    $selectedDays = $config['days_of_week'] ?? [1, 2, 3, 4, 5];
                                    foreach ($days as $idx => $dayName):
                                    ?>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" name="weekly_days[]"
                                               id="day-<?= $idx ?>" value="<?= $idx ?>"
                                               <?= in_array($idx, $selectedDays) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="day-<?= $idx ?>"><?= substr($dayName, 0, 3) ?></label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Time of day</label>
                                <div class="row" style="max-width: 300px;">
                                    <div class="col-6">
                                        <select class="form-select" id="weekly_hour" name="weekly_hour">
                                            <?php for ($h = 0; $h < 24; $h++): ?>
                                            <option value="<?= $h ?>" <?= ($config['hour'] ?? 9) == $h ? 'selected' : '' ?>>
                                                <?= $h % 12 ?: 12 ?> <?= $h >= 12 ? 'PM' : 'AM' ?>
                                            </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <select class="form-select" id="weekly_minute" name="weekly_minute">
                                            <?php for ($m = 0; $m < 60; $m += 5): ?>
                                            <option value="<?= $m ?>" <?= ($config['minute'] ?? 0) == $m ? 'selected' : '' ?>>
                                                :<?= str_pad($m, 2, '0', STR_PAD_LEFT) ?>
                                            </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Monthly Config -->
                        <div id="config-monthly" class="schedule-config" style="display: none;">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="monthly_day" class="form-label">Day of month</label>
                                    <select class="form-select" id="monthly_day" name="monthly_day">
                                        <?php for ($d = 1; $d <= 31; $d++): ?>
                                        <option value="<?= $d ?>" <?= ($config['day_of_month'] ?? 1) == $d ? 'selected' : '' ?>>
                                            <?= $d ?>
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="monthly_hour" class="form-label">Hour</label>
                                    <select class="form-select" id="monthly_hour" name="monthly_hour">
                                        <?php for ($h = 0; $h < 24; $h++): ?>
                                        <option value="<?= $h ?>" <?= ($config['hour'] ?? 9) == $h ? 'selected' : '' ?>>
                                            <?= $h % 12 ?: 12 ?> <?= $h >= 12 ? 'PM' : 'AM' ?>
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="monthly_minute" class="form-label">Minute</label>
                                    <select class="form-select" id="monthly_minute" name="monthly_minute">
                                        <?php for ($m = 0; $m < 60; $m += 5): ?>
                                        <option value="<?= $m ?>" <?= ($config['minute'] ?? 0) == $m ? 'selected' : '' ?>>
                                            :<?= str_pad($m, 2, '0', STR_PAD_LEFT) ?>
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Cron Config -->
                        <div id="config-cron" class="schedule-config" style="display: none;">
                            <div class="mb-3">
                                <label for="cron_expression" class="form-label">Cron Expression</label>
                                <input type="text" class="form-control font-monospace" id="cron_expression" name="cron_expression"
                                       value="<?= h($config['cron_expression'] ?? '0 9 * * *') ?>"
                                       placeholder="0 9 * * *">
                                <small class="text-muted">Format: minute hour day month weekday</small>
                            </div>
                            <div class="alert alert-info py-2 small">
                                <strong>Examples:</strong><br>
                                <code>0 9 * * *</code> - Daily at 9:00 AM<br>
                                <code>0 9 * * 1-5</code> - Weekdays at 9:00 AM<br>
                                <code>*/15 * * * *</code> - Every 15 minutes<br>
                                <code>0 0 1 * *</code> - First of each month at midnight
                            </div>
                        </div>

                        <hr class="my-4">

                        <div class="mb-3">
                            <label for="timezone" class="form-label">Timezone</label>
                            <select class="form-select" id="timezone" name="timezone">
                                <?php foreach ($timezones as $tz => $label): ?>
                                <option value="<?= $tz ?>" <?= ($isEdit && $schedule->timezone === $tz) || (!$isEdit && $tz === 'America/New_York') ? 'selected' : '' ?>>
                                    <?= $label ?> (<?= $tz ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-gear"></i> Advanced Options
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="max_concurrent" class="form-label">Max Concurrent Runs</label>
                                <input type="number" class="form-control" id="max_concurrent" name="max_concurrent"
                                       min="1" max="10" value="<?= $isEdit ? (int)$schedule->max_concurrent : 1 ?>">
                                <small class="text-muted">How many instances can run at the same time</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="on_overlap" class="form-label">On Overlap</label>
                                <select class="form-select" id="on_overlap" name="on_overlap">
                                    <?php foreach ($overlapPolicies as $policy => $info): ?>
                                    <option value="<?= $policy ?>" <?= ($isEdit && $schedule->on_overlap === $policy) ? 'selected' : '' ?>>
                                        <?= $info['label'] ?> - <?= $info['description'] ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <?php if ($isEdit): ?>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                                       <?= $schedule->is_active ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_active">Schedule is active</label>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="d-flex justify-content-between">
                    <a href="/pipelines?tab=schedules" class="btn btn-outline-secondary">
                        <i class="bi bi-x-lg"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> <?= $isEdit ? 'Update Schedule' : 'Create Schedule' ?>
                    </button>
                </div>
            </div>

            <!-- Preview Sidebar -->
            <div class="col-lg-4">
                <div class="card sticky-top" style="top: 20px;">
                    <div class="card-header bg-info text-white">
                        <i class="bi bi-calendar-check"></i> Preview
                    </div>
                    <div class="card-body">
                        <div id="preview-loading" class="text-center py-3" style="display: none;">
                            <div class="spinner-border spinner-border-sm text-primary"></div>
                            <span class="ms-2">Calculating...</span>
                        </div>
                        <div id="preview-content">
                            <h6 class="text-muted mb-3">Next Scheduled Runs</h6>
                            <?php if ($isEdit && !empty($nextRuns)): ?>
                            <ul class="list-unstyled">
                                <?php foreach ($nextRuns as $run): ?>
                                <li class="mb-2">
                                    <i class="bi bi-arrow-right text-primary"></i>
                                    <?= date('D, M j, Y g:i A', strtotime($run)) ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php else: ?>
                            <p class="text-muted">Configure the schedule to see preview</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($isEdit): ?>
                    <div class="card-footer">
                        <small class="text-muted">
                            <i class="bi bi-clock-history"></i>
                            Run count: <strong><?= (int)$schedule->run_count ?></strong><br>
                            <?php if ($schedule->last_run_at): ?>
                            Last run: <?= date('M j, Y g:i A', strtotime($schedule->last_run_at)) ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
function updateScheduleConfig() {
    // Hide all config panels
    document.querySelectorAll('.schedule-config').forEach(el => el.style.display = 'none');

    // Show selected type's config
    const selectedType = document.querySelector('input[name="schedule_type"]:checked').value;
    const configPanel = document.getElementById('config-' + selectedType);
    if (configPanel) {
        configPanel.style.display = 'block';
    }

    // Update preview
    updatePreview();
}

function updatePreview() {
    const loadingEl = document.getElementById('preview-loading');
    const contentEl = document.getElementById('preview-content');

    loadingEl.style.display = 'block';

    // Collect form data
    const formData = new FormData(document.getElementById('scheduleForm'));
    const params = new URLSearchParams(formData);

    fetch('/pipelines/schedulepreview?' + params.toString())
    .then(response => response.json())
    .then(data => {
        loadingEl.style.display = 'none';

        if (data.success && data.next_runs && data.next_runs.length > 0) {
            let html = '<h6 class="text-muted mb-3">Next Scheduled Runs</h6><ul class="list-unstyled">';
            data.next_runs.forEach(run => {
                const date = new Date(run.replace(' ', 'T'));
                html += '<li class="mb-2"><i class="bi bi-arrow-right text-primary"></i> ';
                html += date.toLocaleDateString('en-US', {
                    weekday: 'short',
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit'
                });
                html += '</li>';
            });
            html += '</ul>';
            contentEl.innerHTML = html;
        } else {
            contentEl.innerHTML = '<p class="text-muted">No upcoming runs scheduled</p>';
        }
    })
    .catch(err => {
        loadingEl.style.display = 'none';
        contentEl.innerHTML = '<p class="text-danger">Error loading preview</p>';
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateScheduleConfig();

    // Update preview when form changes
    document.querySelectorAll('#scheduleForm input, #scheduleForm select').forEach(el => {
        el.addEventListener('change', updatePreview);
    });
});
</script>
