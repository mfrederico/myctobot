<?php
/**
 * TenantAppLogger - Simple file-based logger for tenant apps
 *
 * Provides basic logging functionality for generated app servers.
 * Logs are written to per-app log files in the storage directory.
 */

namespace app\services;

class TenantAppLogger
{
    private string $logFile;
    private string $appSlug;
    private bool $toStdout;

    /**
     * Base path for log storage
     */
    private const LOG_BASE_PATH = '/home/mfrederico/development/myctobot/storage/apps';

    /**
     * Log levels
     */
    private const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
    ];

    /**
     * Create a logger for an app
     *
     * @param string $workspace The workspace slug
     * @param string $appSlug The app slug
     * @param bool $toStdout Also output to stdout (useful for debugging)
     */
    public function __construct(string $workspace, string $appSlug, bool $toStdout = false)
    {
        $this->appSlug = $appSlug;
        $this->toStdout = $toStdout;

        // Ensure log directory exists
        $logDir = self::LOG_BASE_PATH . "/{$workspace}/{$appSlug}/storage/logs";
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $this->logFile = $logDir . '/' . date('Y-m-d') . '.log';
    }

    /**
     * Log a debug message
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Log an info message
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * Log a warning message
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * Log an error message
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * Write a log entry
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);

        // Interpolate context into message
        $message = $this->interpolate($message, $context);

        // Format log line
        $logLine = "[{$timestamp}] [{$levelUpper}] [{$this->appSlug}] {$message}";

        // Add context as JSON if not empty
        if (!empty($context)) {
            $logLine .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }

        $logLine .= PHP_EOL;

        // Write to file
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);

        // Also output to stdout if enabled
        if ($this->toStdout) {
            echo $logLine;
        }
    }

    /**
     * Interpolate context values into message placeholders
     *
     * @param string $message Message with {key} placeholders
     * @param array $context Key-value pairs for interpolation
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_string($val) || is_numeric($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = (string) $val;
            }
        }
        return strtr($message, $replace);
    }

    /**
     * Get the log file path
     */
    public function getLogFile(): string
    {
        return $this->logFile;
    }

    /**
     * Read recent log entries
     *
     * @param int $lines Number of lines to read from end
     */
    public function getRecentLogs(int $lines = 100): array
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $output = [];
        $cmd = sprintf('tail -n %d %s', $lines, escapeshellarg($this->logFile));
        exec($cmd, $output);

        return $output;
    }

    /**
     * Clear the log file
     */
    public function clear(): void
    {
        if (file_exists($this->logFile)) {
            file_put_contents($this->logFile, '');
        }
    }
}
