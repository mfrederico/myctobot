<?php
/**
 * @step_type: schedule_task
 * @category: flow
 * @label: Schedule Task
 * @icon: bi-calendar-event
 * @color: info
 * @description: Schedule a future task (pipeline, webhook, etc.)
 */
?>
<div class="config-panel" id="config_schedule_task" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <!-- Row 1: Task Type -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Task Type</label>
                <select class="form-select" name="config_schedule_task_type" onchange="toggleScheduleTaskConfig(this)">
                    <option value="execute_pipeline">Execute Pipeline</option>
                    <option value="webhook_call">Webhook Call</option>
                    <option value="revert_action">Revert Action</option>
                </select>
            </div>

            <!-- Row 2: Timing Mode Toggle -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Timing</label>
                <div class="btn-group w-100" role="group">
                    <input type="radio" class="btn-check" name="config_schedule_timing_mode" id="timing_delay" value="delay" checked
                           onchange="toggleScheduleTimingMode('delay')">
                    <label class="btn btn-outline-primary" for="timing_delay">
                        <i class="bi bi-hourglass-split"></i> Delay from now
                    </label>

                    <input type="radio" class="btn-check" name="config_schedule_timing_mode" id="timing_datetime" value="datetime"
                           onchange="toggleScheduleTimingMode('datetime')">
                    <label class="btn btn-outline-primary" for="timing_datetime">
                        <i class="bi bi-calendar-event"></i> At date/time
                    </label>

                    <input type="radio" class="btn-check" name="config_schedule_timing_mode" id="timing_recurring" value="recurring"
                           onchange="toggleScheduleTimingMode('recurring')">
                    <label class="btn btn-outline-primary" for="timing_recurring">
                        <i class="bi bi-arrow-repeat"></i> Recurring
                    </label>
                </div>
            </div>

            <!-- Timing: Delay Mode -->
            <div id="schedule_timing_delay" class="mb-3">
                <div class="input-group">
                    <input type="number" class="form-control" name="config_schedule_delay" value="1" min="1">
                    <select class="form-select" name="config_schedule_delay_unit" style="max-width: 120px;">
                        <option value="1">seconds</option>
                        <option value="60">minutes</option>
                        <option value="3600" selected>hours</option>
                        <option value="86400">days</option>
                    </select>
                </div>
                <div class="form-text">Time until task executes (one-off, non-blocking)</div>
            </div>

            <!-- Timing: Datetime Mode -->
            <div id="schedule_timing_datetime" class="mb-3" style="display: none;">
                <div class="row">
                    <div class="col-md-7">
                        <label class="form-label">Date &amp; Time</label>
                        <input type="text" class="form-control" name="config_schedule_datetime"
                               id="scheduleDateTime" placeholder="Select date and time...">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Timezone</label>
                        <select class="form-select" name="config_schedule_timezone">
                            <option value="America/New_York">Eastern Time (US)</option>
                            <option value="America/Chicago">Central Time (US)</option>
                            <option value="America/Denver">Mountain Time (US)</option>
                            <option value="America/Los_Angeles">Pacific Time (US)</option>
                            <option value="UTC">UTC</option>
                            <option value="Europe/London">London (GMT/BST)</option>
                            <option value="Europe/Paris">Paris (CET/CEST)</option>
                            <option value="Asia/Tokyo">Tokyo (JST)</option>
                            <option value="Asia/Shanghai">Shanghai (CST)</option>
                            <option value="Australia/Sydney">Sydney (AEST)</option>
                        </select>
                    </div>
                </div>
                <div class="form-text">One-off execution at a specific date/time</div>
            </div>

            <!-- Timing: Recurring Mode -->
            <div id="schedule_timing_recurring" style="display: none;">
                <div class="row mb-3">
                    <div class="col-md-5">
                        <label class="form-label">Schedule Type</label>
                        <select class="form-select" name="config_schedule_recur_type" onchange="toggleRecurringConfig(this.value)">
                            <option value="minutely">Every N Minutes</option>
                            <option value="hourly">Hourly</option>
                            <option value="daily" selected>Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="cron">Cron Expression</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Timezone</label>
                        <select class="form-select" name="config_schedule_timezone">
                            <option value="America/New_York">Eastern (US)</option>
                            <option value="America/Chicago">Central (US)</option>
                            <option value="America/Denver">Mountain (US)</option>
                            <option value="America/Los_Angeles">Pacific (US)</option>
                            <option value="UTC">UTC</option>
                            <option value="Europe/London">London</option>
                            <option value="Europe/Paris">Paris</option>
                            <option value="Asia/Tokyo">Tokyo</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">On Overlap</label>
                        <select class="form-select" name="config_schedule_on_overlap">
                            <option value="skip">Skip</option>
                            <option value="queue">Queue</option>
                            <option value="allow">Allow</option>
                        </select>
                    </div>
                </div>

                <!-- Minutely config -->
                <div id="recur_minutely" class="mb-3" style="display: none;">
                    <div class="input-group" style="max-width: 250px;">
                        <span class="input-group-text">Every</span>
                        <input type="number" class="form-control" name="config_schedule_minutely_interval" value="5" min="1" max="1440">
                        <span class="input-group-text">minutes</span>
                    </div>
                </div>

                <!-- Hourly config -->
                <div id="recur_hourly" class="mb-3" style="display: none;">
                    <div class="row">
                        <div class="col-auto">
                            <div class="input-group">
                                <span class="input-group-text">Every</span>
                                <input type="number" class="form-control" name="config_schedule_hourly_interval" value="1" min="1" max="24" style="width: 70px;">
                                <span class="input-group-text">hour(s) at minute</span>
                                <input type="number" class="form-control" name="config_schedule_hourly_minute" value="0" min="0" max="59" style="width: 70px;">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Daily config -->
                <div id="recur_daily" class="mb-3">
                    <div class="input-group" style="max-width: 300px;">
                        <span class="input-group-text">At</span>
                        <input type="number" class="form-control" name="config_schedule_daily_hour" value="9" min="0" max="23" style="width: 70px;">
                        <span class="input-group-text">:</span>
                        <input type="number" class="form-control" name="config_schedule_daily_minute" value="0" min="0" max="59" style="width: 70px;">
                    </div>
                </div>

                <!-- Weekly config -->
                <div id="recur_weekly" class="mb-3" style="display: none;">
                    <div class="mb-2">
                        <label class="form-label small text-muted">Days of week</label>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php
                            $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                            foreach ($days as $i => $day): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="config_schedule_weekly_days" value="<?= $i ?>" id="weekday_<?= $i ?>">
                                <label class="form-check-label" for="weekday_<?= $i ?>"><?= $day ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="input-group" style="max-width: 300px;">
                        <span class="input-group-text">At</span>
                        <input type="number" class="form-control" name="config_schedule_weekly_hour" value="9" min="0" max="23" style="width: 70px;">
                        <span class="input-group-text">:</span>
                        <input type="number" class="form-control" name="config_schedule_weekly_minute" value="0" min="0" max="59" style="width: 70px;">
                    </div>
                </div>

                <!-- Monthly config -->
                <div id="recur_monthly" class="mb-3" style="display: none;">
                    <div class="input-group" style="max-width: 400px;">
                        <span class="input-group-text">Day</span>
                        <input type="number" class="form-control" name="config_schedule_monthly_day" value="1" min="1" max="31" style="width: 70px;">
                        <span class="input-group-text">at</span>
                        <input type="number" class="form-control" name="config_schedule_monthly_hour" value="9" min="0" max="23" style="width: 70px;">
                        <span class="input-group-text">:</span>
                        <input type="number" class="form-control" name="config_schedule_monthly_minute" value="0" min="0" max="59" style="width: 70px;">
                    </div>
                </div>

                <!-- Cron config -->
                <div id="recur_cron" class="mb-3" style="display: none;">
                    <input type="text" class="form-control font-monospace" name="config_schedule_cron_expression"
                           value="0 9 * * *" placeholder="0 9 * * *" style="max-width: 300px;">
                    <div class="form-text">Standard cron: min hour day month weekday</div>
                </div>

                <!-- Next runs preview -->
                <div id="schedule_next_runs_preview" class="mb-3" style="display: none;">
                    <label class="form-label small text-muted">Next 5 runs preview</label>
                    <ul class="list-group list-group-flush small" id="scheduleNextRunsList"></ul>
                </div>

                <button type="button" class="btn btn-sm btn-outline-info" onclick="previewRecurringRuns()">
                    <i class="bi bi-eye"></i> Preview Next Runs
                </button>
            </div>

            <hr>

            <!-- Execute Pipeline Config -->
            <div id="schedule_pipeline_config">
                <h6 class="text-muted"><i class="bi bi-diagram-3"></i> Pipeline Configuration</h6>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Pipeline</label>
                            <select class="form-select" name="config_schedule_pipeline_id"
                                    onchange="onSchedulePipelineChange(this.value)">
                                <option value="">-- Select Pipeline --</option>
                                <option value="_self">This Pipeline (self)</option>
                                <?php
                                $pipelines = \app\Bean::find('pipelines', 'is_active = 1 ORDER BY name');
                                foreach ($pipelines as $p): ?>
                                <option value="<?= $p->id ?>"><?= h($p->name) ?> (<?= $p->slug ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Entry Step <small class="text-muted">(optional)</small></label>
                            <select class="form-select" name="config_schedule_entry_step" id="scheduleEntryStepSelect">
                                <option value="">-- First step --</option>
                            </select>
                            <div class="form-text">Start from this step</div>
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end mb-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100" onclick="autoDeriveScheduleInputData()" title="Auto-derive input from pipeline schema">
                            <i class="bi bi-magic"></i> Auto-derive
                        </button>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Input Data (JSON)</label>
                    <textarea class="form-control font-monospace" name="config_schedule_input_data" rows="3"
                              placeholder='{"key": "{context.value}"}'></textarea>
                    <div class="form-text">Context passed to the scheduled pipeline. Supports variable substitution.</div>
                </div>
            </div>

            <!-- Webhook Config (hidden by default) -->
            <div id="schedule_webhook_config" style="display: none;">
                <h6 class="text-muted"><i class="bi bi-send"></i> Webhook Configuration</h6>

                <div class="mb-3">
                    <label class="form-label">URL</label>
                    <input type="url" class="form-control" name="config_schedule_webhook_url"
                           placeholder="https://api.example.com/callback">
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">Method</label>
                            <select class="form-select" name="config_schedule_webhook_method">
                                <option value="POST">POST</option>
                                <option value="PUT">PUT</option>
                                <option value="GET">GET</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="mb-3">
                            <label class="form-label">Headers (JSON)</label>
                            <input type="text" class="form-control font-monospace" name="config_schedule_webhook_headers"
                                   placeholder='{"Authorization": "Bearer ..."}'>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Body (JSON)</label>
                    <textarea class="form-control font-monospace" name="config_schedule_webhook_body" rows="3"
                              placeholder='{"message": "Scheduled reminder"}'></textarea>
                </div>
            </div>

            <!-- Revert Config (hidden by default) -->
            <div id="schedule_revert_config" style="display: none;">
                <h6 class="text-muted"><i class="bi bi-arrow-counterclockwise"></i> Revert Configuration</h6>

                <div class="alert alert-info small">
                    <i class="bi bi-info-circle"></i>
                    Revert actions use data from previous steps. The revert data should be stored in
                    <code>{prev.output._revert_state}</code> by an earlier step.
                </div>
            </div>

            <!-- View Calendar Button + Time Variables Reference -->
            <div class="d-flex justify-content-between align-items-start mt-3">
                <button type="button" class="btn btn-sm btn-outline-info" onclick="openScheduleCalendar()">
                    <i class="bi bi-calendar3"></i> View Calendar
                </button>
                <div>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#timeVarsRef">
                        <i class="bi bi-clock"></i> Time Variables
                    </button>
                </div>
            </div>

            <div class="collapse mt-2" id="timeVarsRef">
                <div class="alert alert-secondary small mb-0">
                    <strong>Available <code>{time.*}</code> variables for input data:</strong>
                    <div class="row mt-1">
                        <div class="col-md-6">
                            <code>{time.now}</code> - 2026-02-07 14:30:00<br>
                            <code>{time.date}</code> - 2026-02-07<br>
                            <code>{time.time}</code> - 14:30:00<br>
                            <code>{time.iso8601}</code> - 2026-02-07T14:30:00-05:00
                        </div>
                        <div class="col-md-6">
                            <code>{time.epoch}</code> - 1738942200<br>
                            <code>{time.microtime}</code> - 1738942200.123456<br>
                            <code>{time.timestamp_ms}</code> - 1738942200123
                        </div>
                    </div>
                </div>
            </div>

            <div class="alert alert-secondary small mt-3 mb-0">
                <i class="bi bi-lightbulb"></i>
                <strong>How it works:</strong> This step creates a scheduled task that will execute at the specified time.
                The pipeline continues immediately (non-blocking). Use for reminders, delayed actions, recurring jobs, or cleanup tasks.
            </div>
        </div>
    </div>
</div>

<!-- Schedule Calendar Modal -->
<div class="modal fade" id="scheduleCalendarModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calendar3"></i> Pipeline Schedule Calendar</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="scheduleFullCalendar" style="min-height: 500px;"></div>
            </div>
        </div>
    </div>
</div>

<script>
// Cache for loaded pipeline steps
let _scheduleStepsCache = {};
let _scheduleCalendarInstance = null;

function toggleScheduleTaskConfig(select) {
    const pipelineConfig = document.getElementById('schedule_pipeline_config');
    const webhookConfig = document.getElementById('schedule_webhook_config');
    const revertConfig = document.getElementById('schedule_revert_config');

    pipelineConfig.style.display = 'none';
    webhookConfig.style.display = 'none';
    revertConfig.style.display = 'none';

    switch (select.value) {
        case 'execute_pipeline':
            pipelineConfig.style.display = 'block';
            break;
        case 'webhook_call':
            webhookConfig.style.display = 'block';
            break;
        case 'revert_action':
            revertConfig.style.display = 'block';
            break;
    }
}

function toggleScheduleTimingMode(mode) {
    document.getElementById('schedule_timing_delay').style.display = mode === 'delay' ? 'block' : 'none';
    document.getElementById('schedule_timing_datetime').style.display = mode === 'datetime' ? 'block' : 'none';
    document.getElementById('schedule_timing_recurring').style.display = mode === 'recurring' ? 'block' : 'none';

    // Init flatpickr when datetime mode selected
    if (mode === 'datetime') {
        const dtInput = document.getElementById('scheduleDateTime');
        if (dtInput && !dtInput._flatpickr && typeof flatpickr !== 'undefined') {
            flatpickr(dtInput, {
                enableTime: true,
                dateFormat: 'Y-m-d H:i',
                time_24hr: true,
                minDate: 'today',
                theme: document.body.classList.contains('dark-mode') ? 'dark' : 'light'
            });
        }
    }
}

function toggleRecurringConfig(type) {
    ['minutely', 'hourly', 'daily', 'weekly', 'monthly', 'cron'].forEach(t => {
        const el = document.getElementById('recur_' + t);
        if (el) el.style.display = t === type ? 'block' : 'none';
    });
    // Hide preview when type changes
    document.getElementById('schedule_next_runs_preview').style.display = 'none';
}

function resolveSchedulePipelineId(val) {
    if (val === '_self') {
        // Use the global pipelineId variable set by edit.php
        return (typeof pipelineId !== 'undefined' && pipelineId) ? String(pipelineId) : '';
    }
    return val || '';
}

function onSchedulePipelineChange(val) {
    if (!val || val === '') {
        const select = document.getElementById('scheduleEntryStepSelect');
        select.innerHTML = '<option value="">-- First step --</option>';
        return;
    }
    const resolvedId = resolveSchedulePipelineId(val);
    if (resolvedId) {
        loadPipelineSteps(resolvedId, '');
    }
}

function loadPipelineSteps(pipelineId, selectedStep) {
    if (!pipelineId) return;

    // Check cache first
    if (_scheduleStepsCache[pipelineId]) {
        populateEntryStepDropdown(_scheduleStepsCache[pipelineId], selectedStep);
        return;
    }

    const select = document.getElementById('scheduleEntryStepSelect');
    select.innerHTML = '<option value="">Loading...</option>';
    select.disabled = true;

    fetch('/pipelines/pipelinestepslist/' + pipelineId)
        .then(r => r.json())
        .then(data => {
            select.disabled = false;
            if (data.success) {
                _scheduleStepsCache[pipelineId] = data.data;
                populateEntryStepDropdown(data.data, selectedStep);
            } else {
                select.innerHTML = '<option value="">-- First step --</option>';
            }
        })
        .catch(() => {
            select.disabled = false;
            select.innerHTML = '<option value="">-- First step --</option>';
        });
}

function populateEntryStepDropdown(data, selectedStep) {
    const select = document.getElementById('scheduleEntryStepSelect');
    select.innerHTML = '<option value="">-- First step --</option>';

    (data.steps || []).forEach(step => {
        const opt = document.createElement('option');
        opt.value = step.step_name;
        opt.textContent = `${step.label} (${step.step_type}, row ${step.row})`;
        if (step.step_name === selectedStep) opt.selected = true;
        select.appendChild(opt);
    });
}

function autoDeriveScheduleInputData() {
    const pipelineSelect = document.querySelector('[name="config_schedule_pipeline_id"]');
    const rawVal = pipelineSelect ? pipelineSelect.value : '';
    const resolvedId = resolveSchedulePipelineId(rawVal);

    if (!resolvedId) {
        alert('Please select a pipeline first.');
        return;
    }

    const doDerive = (data) => {
        const schema = data.input_schema || {};
        const properties = schema.properties || {};
        const template = {};
        for (const [key, def] of Object.entries(properties)) {
            template[key] = `{context.${key}}`;
        }
        const textarea = document.querySelector('[name="config_schedule_input_data"]');
        if (textarea) {
            if (Object.keys(template).length > 0) {
                textarea.value = JSON.stringify(template, null, 2);
            } else {
                textarea.value = '{}';
                textarea.placeholder = 'No input schema defined on target pipeline. Add properties to its Input Schema to auto-derive.';
                alert('This pipeline has no input schema defined.\n\nTo use auto-derive, add an input_schema with properties to the target pipeline\'s settings.');
            }
        }
    };

    if (_scheduleStepsCache[resolvedId]) {
        doDerive(_scheduleStepsCache[resolvedId]);
    } else {
        fetch('/pipelines/pipelinestepslist/' + resolvedId)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    _scheduleStepsCache[resolvedId] = data.data;
                    doDerive(data.data);
                } else {
                    alert('Could not load pipeline schema: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => alert('Error: ' + err.message));
    }
}

function previewRecurringRuns() {
    const scheduleType = document.querySelector('[name="config_schedule_recur_type"]')?.value || 'daily';
    const timezone = document.querySelector('#schedule_timing_recurring [name="config_schedule_timezone"]')?.value || 'America/New_York';

    const params = new URLSearchParams({ schedule_type: scheduleType, timezone: timezone });

    // Add type-specific params
    switch (scheduleType) {
        case 'minutely':
            params.set('minutely_interval', document.querySelector('[name="config_schedule_minutely_interval"]')?.value || '5');
            break;
        case 'hourly':
            params.set('hourly_interval', document.querySelector('[name="config_schedule_hourly_interval"]')?.value || '1');
            params.set('hourly_minute', document.querySelector('[name="config_schedule_hourly_minute"]')?.value || '0');
            break;
        case 'daily':
            params.set('daily_hour', document.querySelector('[name="config_schedule_daily_hour"]')?.value || '9');
            params.set('daily_minute', document.querySelector('[name="config_schedule_daily_minute"]')?.value || '0');
            break;
        case 'weekly':
            params.set('weekly_hour', document.querySelector('[name="config_schedule_weekly_hour"]')?.value || '9');
            params.set('weekly_minute', document.querySelector('[name="config_schedule_weekly_minute"]')?.value || '0');
            document.querySelectorAll('input[name="config_schedule_weekly_days"]:checked').forEach(cb => {
                params.append('weekly_days[]', cb.value);
            });
            break;
        case 'monthly':
            params.set('monthly_day', document.querySelector('[name="config_schedule_monthly_day"]')?.value || '1');
            params.set('monthly_hour', document.querySelector('[name="config_schedule_monthly_hour"]')?.value || '9');
            params.set('monthly_minute', document.querySelector('[name="config_schedule_monthly_minute"]')?.value || '0');
            break;
        case 'cron':
            params.set('cron_expression', document.querySelector('[name="config_schedule_cron_expression"]')?.value || '0 9 * * *');
            break;
    }

    fetch('/schedules/preview?' + params.toString())
        .then(r => r.json())
        .then(data => {
            if (data.success && data.next_runs) {
                const list = document.getElementById('scheduleNextRunsList');
                const container = document.getElementById('schedule_next_runs_preview');
                list.innerHTML = '';
                data.next_runs.forEach(run => {
                    const li = document.createElement('li');
                    li.className = 'list-group-item py-1 px-2 bg-transparent';
                    li.innerHTML = '<i class="bi bi-clock text-info"></i> ' + run;
                    list.appendChild(li);
                });
                container.style.display = 'block';
            }
        })
        .catch(err => console.error('Preview error:', err));
}

function openScheduleCalendar() {
    const modal = new bootstrap.Modal(document.getElementById('scheduleCalendarModal'));
    modal.show();

    // Init or re-render FullCalendar
    setTimeout(() => initScheduleFullCalendar(), 200);
}

function initScheduleFullCalendar() {
    const el = document.getElementById('scheduleFullCalendar');
    if (!el || typeof FullCalendar === 'undefined') return;

    if (_scheduleCalendarInstance) {
        _scheduleCalendarInstance.refetchEvents();
        return;
    }

    _scheduleCalendarInstance = new FullCalendar.Calendar(el, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,listWeek'
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
        dateClick: function(info) {
            // Click a date to populate the datetime picker and switch mode
            const dtRadio = document.getElementById('timing_datetime');
            if (dtRadio) {
                dtRadio.checked = true;
                toggleScheduleTimingMode('datetime');
            }
            const dtInput = document.getElementById('scheduleDateTime');
            if (dtInput) {
                const dateStr = info.dateStr.substring(0, 10) + ' 09:00';
                if (dtInput._flatpickr) {
                    dtInput._flatpickr.setDate(dateStr, true);
                } else {
                    dtInput.value = dateStr;
                }
            }
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('scheduleCalendarModal'));
            if (modal) modal.hide();
        },
        eventClick: function(info) {
            const props = info.event.extendedProps;
            switch (props.type) {
                case 'pipeline_run':
                    if (props.run_id) window.open('/pipelines/viewrun/' + props.run_id, '_blank');
                    break;
                case 'pipeline_run_group':
                    if (props.pipeline_id) window.open('/pipelines/runs/' + props.pipeline_id, '_blank');
                    break;
                case 'recurring_schedule':
                    if (props.schedule_id) window.open('/schedules/edit/' + props.schedule_id, '_blank');
                    break;
                case 'scheduled_task':
                    // Navigate to the originating run if available, otherwise the target pipeline
                    if (props.run_id) window.open('/pipelines/viewrun/' + props.run_id, '_blank');
                    else if (props.pipeline_id) window.open('/pipelines/edit/' + props.pipeline_id, '_blank');
                    break;
            }
        },
        themeSystem: 'standard'
    });
    _scheduleCalendarInstance.render();
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const taskTypeSelect = document.querySelector('select[name="config_schedule_task_type"]');
    if (taskTypeSelect) {
        toggleScheduleTaskConfig(taskTypeSelect);
    }
});
</script>
