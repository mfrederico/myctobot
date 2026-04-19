<?php
/**
 * SchedulerService - Recurring Task Scheduler
 *
 * Manages scheduled recurring tasks with support for:
 * - Multiple schedule types (minutely, hourly, daily, weekly, monthly, cron)
 * - Timezone support
 * - Concurrency control (max_concurrent, overlap policies)
 * - Pre-calculated next_run_at for efficient querying
 */

namespace app\services;

use app\Bean;
use DateTime;
use DateTimeZone;
use Exception;

class SchedulerService
{
    // Schedule types
    public const TYPE_ONCE = 'once';
    public const TYPE_MINUTELY = 'minutely';
    public const TYPE_HOURLY = 'hourly';
    public const TYPE_DAILY = 'daily';
    public const TYPE_WEEKLY = 'weekly';
    public const TYPE_MONTHLY = 'monthly';
    public const TYPE_CRON = 'cron';

    // Overlap policies
    public const OVERLAP_SKIP = 'skip';       // Skip if previous still running
    public const OVERLAP_QUEUE = 'queue';     // Queue up to queue_limit
    public const OVERLAP_ALLOW = 'allow';     // Allow concurrent (up to max_concurrent)

    /**
     * Create a new scheduled recurring task
     */
    public static function create(array $config): int
    {
        $schedule = Bean::dispense('scheduledrecurring');

        $schedule->pipeline_id = $config['pipeline_id'] ?? null;
        $schedule->name = $config['name'] ?? 'Unnamed Schedule';
        $schedule->description = $config['description'] ?? '';
        $schedule->schedule_type = $config['schedule_type'] ?? self::TYPE_DAILY;
        $schedule->schedule_config_json = json_encode($config['schedule_config'] ?? []);
        $schedule->timezone = $config['timezone'] ?? 'UTC';
        $schedule->max_concurrent = (int) ($config['max_concurrent'] ?? 1);
        $schedule->on_overlap = $config['on_overlap'] ?? self::OVERLAP_SKIP;
        $schedule->queue_limit = (int) ($config['queue_limit'] ?? 10);
        $schedule->is_active = isset($config['is_active']) ? ($config['is_active'] ? 1 : 0) : 1;
        $schedule->entry_step = $config['entry_step'] ?? null;
        $schedule->input_data_json = json_encode($config['input_data'] ?? []);
        $schedule->run_count = 0;
        $schedule->current_running = 0;
        $schedule->queued_count = 0;
        $schedule->last_run_at = null;
        $schedule->created_at = date('Y-m-d H:i:s');
        $schedule->updated_at = date('Y-m-d H:i:s');

        // Calculate initial next_run_at
        $schedule->next_run_at = self::calculateNextRun(
            $config['schedule_type'] ?? self::TYPE_DAILY,
            $config['schedule_config'] ?? [],
            $config['timezone'] ?? 'UTC'
        );

        return Bean::store($schedule);
    }

    /**
     * Calculate the next run time based on schedule configuration
     */
    public static function calculateNextRun(
        string $type,
        array $config,
        string $timezone = 'UTC',
        ?DateTime $after = null
    ): ?string {
        try {
            $tz = new DateTimeZone($timezone);
            $utcTz = new DateTimeZone('UTC');
            $now = $after ?? new DateTime('now', $tz);

            $next = null;

            switch ($type) {
                case self::TYPE_ONCE:
                    // One-time schedule - use the specified datetime
                    if (!empty($config['datetime'])) {
                        $runAt = new DateTime($config['datetime'], $tz);
                        $next = $runAt > $now ? $runAt : null;
                    }
                    break;

                case self::TYPE_MINUTELY:
                    // Every N minutes
                    $interval = max(1, (int) ($config['interval'] ?? 1));
                    $next = clone $now;
                    $next->modify("+{$interval} minutes");
                    $next->setTime(
                        (int) $next->format('G'),
                        (int) $next->format('i'),
                        0
                    );
                    break;

                case self::TYPE_HOURLY:
                    // Every N hours at minute M
                    $interval = max(1, (int) ($config['interval'] ?? 1));
                    $minute = (int) ($config['minute'] ?? 0);
                    $next = clone $now;
                    $next->setTime((int) $next->format('G'), $minute, 0);
                    if ($next <= $now) {
                        $next->modify("+{$interval} hours");
                    }
                    break;

                case self::TYPE_DAILY:
                    // Every day at HH:MM
                    $hour = (int) ($config['hour'] ?? 9);
                    $minute = (int) ($config['minute'] ?? 0);
                    $next = clone $now;
                    $next->setTime($hour, $minute, 0);
                    if ($next <= $now) {
                        $next->modify('+1 day');
                    }
                    break;

                case self::TYPE_WEEKLY:
                    // On specific days of week at HH:MM
                    $hour = (int) ($config['hour'] ?? 9);
                    $minute = (int) ($config['minute'] ?? 0);
                    $daysOfWeek = $config['days_of_week'] ?? [1, 2, 3, 4, 5]; // Mon-Fri default

                    $next = clone $now;
                    $next->setTime($hour, $minute, 0);

                    // Find next matching day
                    for ($i = 0; $i < 8; $i++) {
                        $dayOfWeek = (int) $next->format('w'); // 0=Sun, 6=Sat
                        if (in_array($dayOfWeek, $daysOfWeek) && $next > $now) {
                            break;
                        }
                        $next->modify('+1 day');
                    }
                    break;

                case self::TYPE_MONTHLY:
                    // On specific day of month at HH:MM
                    $hour = (int) ($config['hour'] ?? 9);
                    $minute = (int) ($config['minute'] ?? 0);
                    $dayOfMonth = (int) ($config['day_of_month'] ?? 1);

                    $next = clone $now;
                    $next->setDate(
                        (int) $next->format('Y'),
                        (int) $next->format('m'),
                        min($dayOfMonth, (int) $next->format('t')) // Handle months with fewer days
                    );
                    $next->setTime($hour, $minute, 0);

                    if ($next <= $now) {
                        $next->modify('+1 month');
                        // Re-adjust day for new month
                        $next->setDate(
                            (int) $next->format('Y'),
                            (int) $next->format('m'),
                            min($dayOfMonth, (int) $next->format('t'))
                        );
                    }
                    break;

                case self::TYPE_CRON:
                    // Standard cron expression
                    $expression = $config['cron_expression'] ?? '0 9 * * *';
                    $cronResult = self::calculateNextCronRun($expression, $tz, $now);
                    if ($cronResult) {
                        $next = new DateTime($cronResult, $tz);
                    }
                    break;

                default:
                    return null;
            }

            if (!$next) return null;

            // Convert to UTC for storage — getDueSchedules() compares against UTC server time
            $next->setTimezone($utcTz);
            return $next->format('Y-m-d H:i:s');

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Calculate next run time from cron expression
     */
    private static function calculateNextCronRun(
        string $expression,
        DateTimeZone $tz,
        DateTime $after
    ): ?string {
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) {
            return null;
        }

        list($cronMinute, $cronHour, $cronDay, $cronMonth, $cronWeekday) = $parts;

        // Start from next minute
        $check = clone $after;
        $check->modify('+1 minute');
        $check->setTime((int) $check->format('G'), (int) $check->format('i'), 0);

        // Check up to 1 year ahead
        $maxIterations = 525600; // minutes in a year
        for ($i = 0; $i < $maxIterations; $i++) {
            $minute = (int) $check->format('i');
            $hour = (int) $check->format('G');
            $day = (int) $check->format('j');
            $month = (int) $check->format('n');
            $weekday = (int) $check->format('w');

            if (
                self::matchCronField($cronMinute, $minute, 0, 59) &&
                self::matchCronField($cronHour, $hour, 0, 23) &&
                self::matchCronField($cronDay, $day, 1, 31) &&
                self::matchCronField($cronMonth, $month, 1, 12) &&
                self::matchCronField($cronWeekday, $weekday, 0, 6)
            ) {
                return $check->format('Y-m-d H:i:s');
            }

            $check->modify('+1 minute');
        }

        return null;
    }

    /**
     * Match a cron field value
     */
    private static function matchCronField(string $field, int $value, int $min, int $max): bool
    {
        if ($field === '*') {
            return true;
        }

        // List (1,3,5)
        if (strpos($field, ',') !== false) {
            return in_array((string) $value, explode(',', $field));
        }

        // Range (1-5)
        if (strpos($field, '-') !== false && strpos($field, '/') === false) {
            list($start, $end) = explode('-', $field);
            return $value >= (int) $start && $value <= (int) $end;
        }

        // Step (*/15 or 0-30/5)
        if (strpos($field, '/') !== false) {
            list($range, $step) = explode('/', $field);
            $step = (int) $step;

            if ($range === '*') {
                return $value % $step === 0;
            }

            if (strpos($range, '-') !== false) {
                list($start, $end) = explode('-', $range);
                if ($value < (int) $start || $value > (int) $end) {
                    return false;
                }
                return ($value - (int) $start) % $step === 0;
            }
        }

        // Exact value
        return $value === (int) $field;
    }

    /**
     * Get schedules that are due to run
     */
    public static function getDueSchedules(): array
    {
        $now = date('Y-m-d H:i:s');
        return Bean::find(
            'scheduledrecurring',
            'is_active = 1 AND next_run_at IS NOT NULL AND next_run_at <= ?',
            [$now]
        );
    }

    /**
     * Check if a schedule can run based on concurrency rules
     */
    public static function canRun(object $schedule): array
    {
        $currentRunning = (int) $schedule->current_running;
        $maxConcurrent = (int) $schedule->max_concurrent;
        $queuedCount = (int) $schedule->queued_count;
        $queueLimit = (int) $schedule->queue_limit;
        $onOverlap = $schedule->on_overlap;

        // No concurrency issues
        if ($currentRunning < $maxConcurrent) {
            return ['can_run' => true, 'reason' => 'ok'];
        }

        // At max concurrent - apply overlap policy
        switch ($onOverlap) {
            case self::OVERLAP_ALLOW:
                // Allow anyway (useful for independent tasks)
                return ['can_run' => true, 'reason' => 'overlap_allowed'];

            case self::OVERLAP_QUEUE:
                // Queue if under limit
                if ($queuedCount < $queueLimit) {
                    return ['can_run' => false, 'queue' => true, 'reason' => 'queued'];
                }
                return ['can_run' => false, 'reason' => 'queue_full'];

            case self::OVERLAP_SKIP:
            default:
                return ['can_run' => false, 'reason' => 'skipped_overlap'];
        }
    }

    /**
     * Mark a schedule as started running
     */
    public static function markRunning(object $schedule): void
    {
        $schedule->current_running = (int) $schedule->current_running + 1;
        $schedule->run_count = (int) $schedule->run_count + 1;
        $schedule->last_run_at = date('Y-m-d H:i:s');
        $schedule->updated_at = date('Y-m-d H:i:s');

        // Calculate next run time
        $config = json_decode($schedule->schedule_config_json ?: '{}', true);
        $schedule->next_run_at = self::calculateNextRun(
            $schedule->schedule_type,
            $config,
            $schedule->timezone
        );

        Bean::store($schedule);
    }

    /**
     * Mark a schedule run as completed
     */
    public static function markCompleted(int $scheduleId): void
    {
        $schedule = Bean::load('scheduledrecurring', $scheduleId);
        if ($schedule && $schedule->id) {
            $schedule->current_running = max(0, (int) $schedule->current_running - 1);
            $schedule->updated_at = date('Y-m-d H:i:s');
            Bean::store($schedule);

            // Process queue if any
            if ($schedule->queued_count > 0) {
                self::processQueue($schedule);
            }
        }
    }

    /**
     * Increment queue count
     */
    public static function incrementQueue(object $schedule): void
    {
        $schedule->queued_count = (int) $schedule->queued_count + 1;
        $schedule->updated_at = date('Y-m-d H:i:s');
        Bean::store($schedule);
    }

    /**
     * Process queued runs
     */
    private static function processQueue(object $schedule): void
    {
        if ($schedule->queued_count > 0 && $schedule->current_running < $schedule->max_concurrent) {
            $schedule->queued_count = (int) $schedule->queued_count - 1;
            // The actual run will be triggered by the next cron tick
            Bean::store($schedule);
        }
    }

    /**
     * Get preview of next N run times
     */
    public static function previewNextRuns(
        string $type,
        array $config,
        string $timezone = 'UTC',
        int $count = 5
    ): array {
        $runs = [];
        $tz = new DateTimeZone($timezone);
        $utcTz = new DateTimeZone('UTC');
        $after = new DateTime('now', $tz);

        for ($i = 0; $i < $count; $i++) {
            // calculateNextRun returns UTC — parse as UTC, then convert to local for display
            $nextRunUtc = self::calculateNextRun($type, $config, $timezone, $after);
            if (!$nextRunUtc) {
                break;
            }
            $runs[] = $nextRunUtc;
            // Feed the UTC result back as the new $after — convert to schedule TZ so
            // calculateNextRun's internal comparisons work correctly
            $after = new DateTime($nextRunUtc, $utcTz);
            $after->setTimezone($tz);
        }

        return $runs;
    }

    /**
     * Validate schedule configuration
     */
    public static function validate(array $config): array
    {
        $errors = [];

        $type = $config['schedule_type'] ?? null;
        $validTypes = [
            self::TYPE_ONCE,
            self::TYPE_MINUTELY,
            self::TYPE_HOURLY,
            self::TYPE_DAILY,
            self::TYPE_WEEKLY,
            self::TYPE_MONTHLY,
            self::TYPE_CRON
        ];

        if (!$type || !in_array($type, $validTypes)) {
            $errors[] = 'Invalid schedule_type. Must be one of: ' . implode(', ', $validTypes);
        }

        if (empty($config['pipeline_id'])) {
            $errors[] = 'pipeline_id is required';
        }

        $scheduleConfig = $config['schedule_config'] ?? [];

        if ($type === self::TYPE_CRON && empty($scheduleConfig['cron_expression'])) {
            $errors[] = 'cron_expression is required for cron schedule type';
        }

        if ($type === self::TYPE_ONCE && empty($scheduleConfig['datetime'])) {
            $errors[] = 'datetime is required for once schedule type';
        }

        $onOverlap = $config['on_overlap'] ?? self::OVERLAP_SKIP;
        $validOverlap = [self::OVERLAP_SKIP, self::OVERLAP_QUEUE, self::OVERLAP_ALLOW];
        if (!in_array($onOverlap, $validOverlap)) {
            $errors[] = 'Invalid on_overlap. Must be one of: ' . implode(', ', $validOverlap);
        }

        return $errors;
    }
}
