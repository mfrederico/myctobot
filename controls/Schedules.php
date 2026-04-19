<?php
/**
 * DEPRECATED (index only) - Phase 2 UX consolidation.
 *
 * The schedule listing (index) now redirects to /pipelines?tab=schedules.
 * All other methods (edit, store, delete, toggle, etc.) remain functional
 * because they handle POST/AJAX actions called from the embedded schedule
 * tab within the Pipelines controller.
 *
 * @see controls/Pipelines.php
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\services\SchedulerService;

require_once __DIR__ . '/../services/SchedulerService.php';

class Schedules extends BaseControls\Control {

    /**
     * Schedule types with UI metadata
     */
    private const SCHEDULE_TYPES = [
        'once' => [
            'label' => 'One-time',
            'description' => 'Run once at a specific date/time',
            'icon' => 'bi-calendar-event'
        ],
        'minutely' => [
            'label' => 'Every N Minutes',
            'description' => 'Run every X minutes',
            'icon' => 'bi-stopwatch'
        ],
        'hourly' => [
            'label' => 'Hourly',
            'description' => 'Run every hour at a specific minute',
            'icon' => 'bi-clock'
        ],
        'daily' => [
            'label' => 'Daily',
            'description' => 'Run every day at a specific time',
            'icon' => 'bi-sun'
        ],
        'weekly' => [
            'label' => 'Weekly',
            'description' => 'Run on specific days of the week',
            'icon' => 'bi-calendar-week'
        ],
        'monthly' => [
            'label' => 'Monthly',
            'description' => 'Run on a specific day of each month',
            'icon' => 'bi-calendar-month'
        ],
        'cron' => [
            'label' => 'Cron Expression',
            'description' => 'Advanced: use cron syntax (e.g., 0 9 * * 1-5)',
            'icon' => 'bi-terminal'
        ]
    ];

    /**
     * Overlap policies
     */
    private const OVERLAP_POLICIES = [
        'skip' => [
            'label' => 'Skip',
            'description' => 'Skip this run if previous is still running'
        ],
        'queue' => [
            'label' => 'Queue',
            'description' => 'Queue up to run after current finishes'
        ],
        'allow' => [
            'label' => 'Allow Concurrent',
            'description' => 'Allow multiple instances to run simultaneously'
        ]
    ];

    /**
     * Common timezones
     */
    private const TIMEZONES = [
        'America/New_York' => 'Eastern Time (US)',
        'America/Chicago' => 'Central Time (US)',
        'America/Denver' => 'Mountain Time (US)',
        'America/Los_Angeles' => 'Pacific Time (US)',
        'UTC' => 'UTC',
        'Europe/London' => 'London (GMT/BST)',
        'Europe/Paris' => 'Paris (CET/CEST)',
        'Asia/Tokyo' => 'Tokyo (JST)',
        'Asia/Shanghai' => 'Shanghai (CST)',
        'Australia/Sydney' => 'Sydney (AEST)'
    ];

    /**
     * DEPRECATED - Redirect to /pipelines?tab=schedules
     *
     * The schedule listing is now embedded in the unified Pipelines view.
     * GET requests redirect; the old view is no longer rendered.
     */
    public function index($params = []) {
        Flight::redirect('/pipelines?tab=schedules');
    }

    /**
     * Build calendar data for the next N days
     */
    private function buildCalendarData($schedules, int $days): array {
        $calendar = [];
        $today = new \DateTime('today');

        for ($i = 0; $i < $days; $i++) {
            $date = clone $today;
            $date->modify("+{$i} days");
            $dateStr = $date->format('Y-m-d');

            $calendar[$dateStr] = [
                'date' => $dateStr,
                'day_name' => $date->format('l'),
                'day_short' => $date->format('D'),
                'day_num' => $date->format('j'),
                'month' => $date->format('M'),
                'is_today' => $i === 0,
                'runs' => []
            ];
        }

        // Fill in scheduled runs
        foreach ($schedules as $s) {
            if (!$s->is_active || !$s->next_run_at) continue;

            $config = json_decode($s->schedule_config_json ?: '{}', true);
            $nextRuns = SchedulerService::previewNextRuns(
                $s->schedule_type,
                $config,
                $s->timezone,
                20
            );

            foreach ($nextRuns as $runTime) {
                $runDate = substr($runTime, 0, 10);
                if (isset($calendar[$runDate])) {
                    $pipeline = Bean::load('pipelines', (int) $s->pipeline_id);
                    $calendar[$runDate]['runs'][] = [
                        'schedule_id' => $s->id,
                        'schedule_name' => $s->name,
                        'pipeline_name' => $pipeline ? $pipeline->name : '(deleted)',
                        'time' => substr($runTime, 11, 5),
                        'timezone' => $s->timezone
                    ];
                }
            }
        }

        // Sort runs by time for each day
        foreach ($calendar as $date => &$day) {
            usort($day['runs'], fn($a, $b) => strcmp($a['time'], $b['time']));
        }

        return $calendar;
    }

    /**
     * Create new schedule
     */
    public function create($params = []) {
        if (!$this->requireLogin()) return;

        $pipelines = Bean::findAll('pipelines', ' is_active = 1 ORDER BY name ASC ');

        $this->viewData['title'] = 'Create Schedule';
        $this->viewData['schedule'] = null;
        $this->viewData['pipelines'] = $pipelines;
        $this->viewData['scheduleTypes'] = self::SCHEDULE_TYPES;
        $this->viewData['overlapPolicies'] = self::OVERLAP_POLICIES;
        $this->viewData['timezones'] = self::TIMEZONES;

        $this->render('schedules/edit', $this->viewData);
    }

    /**
     * Edit existing schedule
     */
    public function edit($params = []) {
        if (!$this->requireLogin()) return;

        $id = (int) ($this->opId() ?? $this->getParam('id') ?? 0);
        $schedule = Bean::load('scheduledrecurring', $id);

        if (!$schedule || !$schedule->id) {
            $this->flash('error', 'Schedule not found');
            Flight::redirect('/schedules');
            return;
        }

        $pipelines = Bean::findAll('pipelines', ' is_active = 1 ORDER BY name ASC ');
        $config = json_decode($schedule->schedule_config_json ?: '{}', true);

        // Preview next runs
        $nextRuns = SchedulerService::previewNextRuns(
            $schedule->schedule_type,
            $config,
            $schedule->timezone,
            10
        );

        $this->viewData['title'] = 'Edit Schedule: ' . $schedule->name;
        $this->viewData['schedule'] = $schedule;
        $this->viewData['scheduleConfig'] = $config;
        $this->viewData['nextRuns'] = $nextRuns;
        $this->viewData['pipelines'] = $pipelines;
        $this->viewData['scheduleTypes'] = self::SCHEDULE_TYPES;
        $this->viewData['overlapPolicies'] = self::OVERLAP_POLICIES;
        $this->viewData['timezones'] = self::TIMEZONES;

        $this->render('schedules/edit', $this->viewData);
    }

    /**
     * Store new schedule
     */
    public function store($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            $this->flash('error', 'Invalid request');
            Flight::redirect('/schedules');
            return;
        }

        $pipelineId = (int) $this->getParam('pipeline_id', 0);
        $name = trim($this->getParam('name', ''));
        $scheduleType = $this->getParam('schedule_type', 'daily');
        $timezone = $this->getParam('timezone', 'America/New_York');
        $maxConcurrent = (int) $this->getParam('max_concurrent', 1);
        $onOverlap = $this->getParam('on_overlap', 'skip');

        if (empty($name)) {
            $this->flash('error', 'Schedule name is required');
            Flight::redirect('/schedules/create');
            return;
        }

        if (!$pipelineId) {
            $this->flash('error', 'Please select a pipeline');
            Flight::redirect('/schedules/create');
            return;
        }

        // Build schedule config based on type
        $config = $this->buildScheduleConfig($scheduleType);

        // Validate
        $errors = SchedulerService::validate([
            'pipeline_id' => $pipelineId,
            'schedule_type' => $scheduleType,
            'schedule_config' => $config,
            'on_overlap' => $onOverlap
        ]);

        if (!empty($errors)) {
            $this->flash('error', implode('; ', $errors));
            Flight::redirect('/schedules/create');
            return;
        }

        // Create schedule
        $scheduleId = SchedulerService::create([
            'pipeline_id' => $pipelineId,
            'name' => $name,
            'description' => trim($this->getParam('description', '')),
            'schedule_type' => $scheduleType,
            'schedule_config' => $config,
            'timezone' => $timezone,
            'max_concurrent' => $maxConcurrent,
            'on_overlap' => $onOverlap
        ]);

        $this->flash('success', 'Schedule created successfully');
        Flight::redirect('/schedules/edit/' . $scheduleId);
    }

    /**
     * Update existing schedule
     */
    public function update($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            $this->flash('error', 'Invalid request');
            Flight::redirect('/schedules');
            return;
        }

        $id = (int) ($this->opId() ?? $this->getParam('id') ?? 0);
        $schedule = Bean::load('scheduledrecurring', $id);

        if (!$schedule || !$schedule->id) {
            $this->flash('error', 'Schedule not found');
            Flight::redirect('/schedules');
            return;
        }

        $pipelineId = (int) $this->getParam('pipeline_id', 0);
        $name = trim($this->getParam('name', ''));
        $scheduleType = $this->getParam('schedule_type', 'daily');
        $timezone = $this->getParam('timezone', 'America/New_York');
        $maxConcurrent = (int) $this->getParam('max_concurrent', 1);
        $onOverlap = $this->getParam('on_overlap', 'skip');
        $isActive = $this->getParam('is_active') === 'on' ? 1 : 0;

        if (empty($name)) {
            $this->flash('error', 'Schedule name is required');
            Flight::redirect('/schedules/edit/' . $id);
            return;
        }

        // Build schedule config based on type
        $config = $this->buildScheduleConfig($scheduleType);

        // Update schedule
        $schedule->pipeline_id = $pipelineId;
        $schedule->name = $name;
        $schedule->description = trim($this->getParam('description', ''));
        $schedule->schedule_type = $scheduleType;
        $schedule->schedule_config_json = json_encode($config);
        $schedule->timezone = $timezone;
        $schedule->max_concurrent = $maxConcurrent;
        $schedule->on_overlap = $onOverlap;
        $schedule->is_active = $isActive;
        $schedule->updated_at = date('Y-m-d H:i:s');

        // Recalculate next run
        $schedule->next_run_at = SchedulerService::calculateNextRun(
            $scheduleType,
            $config,
            $timezone
        );

        Bean::store($schedule);

        $this->flash('success', 'Schedule updated successfully');
        Flight::redirect('/schedules/edit/' . $id);
    }

    /**
     * Toggle schedule active status (AJAX)
     */
    public function toggle($params = []) {
        if (!$this->requireLogin()) return;

        $id = (int) ($this->opId() ?? $this->getParam('id') ?? 0);
        $schedule = Bean::load('scheduledrecurring', $id);

        if (!$schedule || !$schedule->id) {
            Flight::json(['success' => false, 'error' => 'Schedule not found']);
            return;
        }

        $schedule->is_active = $schedule->is_active ? 0 : 1;
        $schedule->updated_at = date('Y-m-d H:i:s');

        // Recalculate next run if activating
        if ($schedule->is_active) {
            $config = json_decode($schedule->schedule_config_json ?: '{}', true);
            $schedule->next_run_at = SchedulerService::calculateNextRun(
                $schedule->schedule_type,
                $config,
                $schedule->timezone
            );
        }

        Bean::store($schedule);

        Flight::json([
            'success' => true,
            'is_active' => (bool) $schedule->is_active,
            'next_run_at' => $schedule->next_run_at
        ]);
    }

    /**
     * Delete schedule
     */
    public function delete($params = []) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) {
            Flight::json(['success' => false, 'error' => 'Invalid request']);
            return;
        }

        $id = (int) ($this->opId() ?? $this->getParam('id') ?? 0);
        $schedule = Bean::load('scheduledrecurring', $id);

        if (!$schedule || !$schedule->id) {
            Flight::json(['success' => false, 'error' => 'Schedule not found']);
            return;
        }

        Bean::trash($schedule);
        Flight::json(['success' => true]);
    }

    /**
     * Get next run preview (AJAX)
     */
    public function preview($params = []) {
        if (!$this->requireLogin()) return;

        $scheduleType = $this->getParam('schedule_type', 'daily');
        $timezone = $this->getParam('timezone', 'America/New_York');

        $config = $this->buildScheduleConfig($scheduleType);

        $nextRuns = SchedulerService::previewNextRuns(
            $scheduleType,
            $config,
            $timezone,
            5
        );

        Flight::json([
            'success' => true,
            'next_runs' => $nextRuns,
            'config' => $config
        ]);
    }

    /**
     * Calendar events JSON endpoint for FullCalendar
     *
     * GET /schedules/calendardata?start=YYYY-MM-DD&end=YYYY-MM-DD
     */
    public function calendardata($params = []) {
        if (!$this->requireLogin()) return;

        $startStr = $this->getParam('start', date('Y-m-01'));
        $endStr = $this->getParam('end', date('Y-m-d', strtotime('+35 days')));

        $events = [];
        $statusColors = [
            'completed' => '#198754',
            'failed' => '#dc3545',
            'running' => '#ffc107',
            'pending' => '#6c757d',
            'cancelled' => '#6c757d',
            'awaiting_input' => '#0dcaf0',
        ];

        // 1. Scheduled tasks (one-off, customer-specific)
        // Each one is unique — show individually with context from payload
        $tasks = Bean::find('scheduledtasks',
            ' scheduled_at >= ? AND scheduled_at <= ? ORDER BY scheduled_at ASC ',
            [$startStr . ' 00:00:00', $endStr . ' 23:59:59']
        );
        foreach ($tasks as $t) {
            $payload = json_decode($t->payload_json ?: '{}', true);
            $title = $this->buildScheduledTaskTitle($t, $payload);
            $pipelineId = $payload['pipeline_id'] ?? null;

            $events[] = [
                'id' => 'task-' . $t->id,
                'title' => $title,
                'start' => $t->scheduled_at,
                'color' => $statusColors[$t->status] ?? '#6c757d',
                'extendedProps' => [
                    'type' => 'scheduled_task',
                    'task_id' => (int) $t->id,
                    'status' => $t->status,
                    'task_type' => $t->task_type,
                    'pipeline_id' => $pipelineId ? (int) $pipelineId : null,
                    'run_id' => $t->pipelineruns_id ? (int) $t->pipelineruns_id : null,
                ],
            ];
        }

        // 2. Pipeline runs — group by pipeline + date to avoid clutter
        $runs = Bean::find('pipelineruns',
            ' created_at >= ? AND created_at <= ? ORDER BY created_at ASC ',
            [$startStr . ' 00:00:00', $endStr . ' 23:59:59']
        );

        // Group runs by pipeline_id + date
        $runGroups = [];
        $pipelineCache = [];
        foreach ($runs as $r) {
            $date = substr($r->created_at, 0, 10);
            $key = $r->pipelines_id . '|' . $date;

            if (!isset($pipelineCache[$r->pipelines_id])) {
                $p = Bean::load('pipelines', (int) $r->pipelines_id);
                $pipelineCache[$r->pipelines_id] = $p ? $p->name : 'Pipeline #' . $r->pipelines_id;
            }

            if (!isset($runGroups[$key])) {
                $runGroups[$key] = [
                    'pipeline_id' => (int) $r->pipelines_id,
                    'pipeline_name' => $pipelineCache[$r->pipelines_id],
                    'date' => $date,
                    'count' => 0,
                    'statuses' => [],
                    'first_run_id' => (int) $r->id,
                    'last_run_id' => (int) $r->id,
                    'first_time' => $r->created_at,
                ];
            }
            $runGroups[$key]['count']++;
            $runGroups[$key]['last_run_id'] = (int) $r->id;
            $status = $r->status ?: 'pending';
            $runGroups[$key]['statuses'][$status] = ($runGroups[$key]['statuses'][$status] ?? 0) + 1;
        }

        foreach ($runGroups as $key => $group) {
            // Pick color by worst status: failed > running > pending > completed
            $color = '#198754'; // default green
            if (!empty($group['statuses']['failed'])) $color = '#dc3545';
            elseif (!empty($group['statuses']['running'])) $color = '#ffc107';
            elseif (!empty($group['statuses']['pending'])) $color = '#6c757d';
            elseif (!empty($group['statuses']['awaiting_input'])) $color = '#0dcaf0';

            $title = $group['pipeline_name'];
            if ($group['count'] > 1) {
                $title .= ' x ' . $group['count'];
            } else {
                $title .= ' (Run #' . $group['first_run_id'] . ')';
            }

            $events[] = [
                'id' => 'rungroup-' . $key,
                'title' => $title,
                'start' => $group['first_time'],
                'allDay' => $group['count'] > 1, // multi-run groups show as all-day
                'color' => $color,
                'extendedProps' => [
                    'type' => $group['count'] > 1 ? 'pipeline_run_group' : 'pipeline_run',
                    'pipeline_id' => $group['pipeline_id'],
                    'run_id' => $group['count'] === 1 ? $group['first_run_id'] : null,
                    'run_count' => $group['count'],
                    'statuses' => $group['statuses'],
                ],
            ];
        }

        // 3. Recurring schedule previews (future)
        $schedules = Bean::findAll('scheduledrecurring', ' is_active = 1 ');
        foreach ($schedules as $s) {
            $config = json_decode($s->schedule_config_json ?: '{}', true);
            $nextRuns = SchedulerService::previewNextRuns(
                $s->schedule_type, $config, $s->timezone, 50
            );
            $pName = $pipelineCache[$s->pipeline_id]
                ?? (($p = Bean::load('pipelines', (int) $s->pipeline_id)) ? $p->name : 'Pipeline #' . $s->pipeline_id);

            foreach ($nextRuns as $runTime) {
                $runDate = substr($runTime, 0, 10);
                if ($runDate >= $startStr && $runDate <= $endStr) {
                    $events[] = [
                        'id' => 'sched-' . $s->id . '-' . $runTime,
                        'title' => $pName . ' - ' . $s->name,
                        'start' => $runTime,
                        'color' => '#0dcaf0',
                        'extendedProps' => [
                            'type' => 'recurring_schedule',
                            'schedule_id' => (int) $s->id,
                            'pipeline_id' => (int) $s->pipeline_id,
                            'schedule_name' => $s->name,
                        ],
                    ];
                }
            }
        }

        Flight::json($events);
    }

    /**
     * Build a meaningful title for a scheduled task from its payload.
     * Extracts customer/context info so calendar shows WHO the task is for.
     */
    private function buildScheduledTaskTitle($task, array $payload): string {
        $parts = [];

        // Try to find a pipeline name
        if (!empty($payload['pipeline_id'])) {
            $p = Bean::load('pipelines', (int) $payload['pipeline_id']);
            if ($p) $parts[] = $p->name;
        }

        // Extract customer-identifying fields from input_data
        $inputData = $payload['input_data'] ?? [];
        $contextKeys = ['customer_name', 'customer_email', 'email', 'name', 'order_id', 'order_name'];
        foreach ($contextKeys as $ck) {
            if (!empty($inputData[$ck]) && is_string($inputData[$ck])) {
                $parts[] = $inputData[$ck];
                break; // one identifier is enough
            }
        }

        if (empty($parts)) {
            $parts[] = ucfirst($task->task_type ?: 'task');
        }

        // Add status indicator for non-pending
        if ($task->status === 'failed') $parts[] = '[FAILED]';
        elseif ($task->status === 'running') $parts[] = '[running]';

        return implode(' — ', $parts);
    }

    /**
     * Build schedule config from POST params
     */
    private function buildScheduleConfig(string $type): array {
        $config = [];

        switch ($type) {
            case 'once':
                $config['datetime'] = $this->getParam('once_datetime', '');
                break;

            case 'minutely':
                $config['interval'] = max(1, (int) $this->getParam('minutely_interval', 5));
                break;

            case 'hourly':
                $config['interval'] = max(1, (int) $this->getParam('hourly_interval', 1));
                $config['minute'] = (int) $this->getParam('hourly_minute', 0);
                break;

            case 'daily':
                $config['hour'] = (int) $this->getParam('daily_hour', 9);
                $config['minute'] = (int) $this->getParam('daily_minute', 0);
                break;

            case 'weekly':
                $config['hour'] = (int) $this->getParam('weekly_hour', 9);
                $config['minute'] = (int) $this->getParam('weekly_minute', 0);
                $daysRaw = $this->getParam('weekly_days', []);
                $config['days_of_week'] = array_map('intval', is_array($daysRaw) ? $daysRaw : []);
                break;

            case 'monthly':
                $config['hour'] = (int) $this->getParam('monthly_hour', 9);
                $config['minute'] = (int) $this->getParam('monthly_minute', 0);
                $config['day_of_month'] = (int) $this->getParam('monthly_day', 1);
                break;

            case 'cron':
                $config['cron_expression'] = trim($this->getParam('cron_expression', '0 9 * * *'));
                break;
        }

        return $config;
    }
}
