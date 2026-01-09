<?php
/**
 * TenantHookHelper - Shared tenant database access for Claude Code hooks
 *
 * This helper class provides multi-tenant database connectivity for hooks
 * that need to update aidevjobs records or log activity.
 *
 * Environment Variables (set by local-aidev-full.php):
 *   MYCTOBOT_APP_ROOT     - Path to myctobot application (for vendor, config)
 *   MYCTOBOT_WORKSPACE    - Tenant workspace name (e.g., "clicksimple-inc")
 *   MYCTOBOT_JOB_ID       - Current aidevjobs.id being worked on
 *   MYCTOBOT_MEMBER_ID    - Member ID running the job
 *   MYCTOBOT_PROJECT_ROOT - Repo clone directory (workspace isolation)
 *
 * Usage in hooks:
 *   require_once __DIR__ . '/TenantHookHelper.php';
 *   $helper = new TenantHookHelper();
 *   if ($helper->connect()) {
 *       $job = $helper->getJob();
 *       $job->status = 'running';
 *       $helper->saveJob($job);
 *   }
 */

use RedBeanPHP\R as R;

class TenantHookHelper
{
    private ?string $appRoot = null;
    private ?string $workspace = null;
    private ?int $jobId = null;
    private ?int $memberId = null;
    private ?string $projectRoot = null;
    private bool $connected = false;
    private ?string $logFile = null;

    public function __construct()
    {
        // Read environment variables
        $this->appRoot = getenv('MYCTOBOT_APP_ROOT') ?: null;
        $this->workspace = getenv('MYCTOBOT_WORKSPACE') ?: null;
        $this->jobId = getenv('MYCTOBOT_JOB_ID') ? (int)getenv('MYCTOBOT_JOB_ID') : null;
        $this->memberId = getenv('MYCTOBOT_MEMBER_ID') ? (int)getenv('MYCTOBOT_MEMBER_ID') : null;
        $this->projectRoot = getenv('MYCTOBOT_PROJECT_ROOT') ?: null;

        // Fallback: derive app root from hook location
        if (!$this->appRoot) {
            $this->appRoot = dirname(__DIR__, 2);
        }

        // Set up logging
        $logDir = $this->appRoot . '/log';
        if (is_dir($logDir) && is_writable($logDir)) {
            $this->logFile = $logDir . '/hooks-' . date('Y-m-d') . '.log';
        }
    }

    /**
     * Check if we have the required environment for tenant operations
     */
    public function hasContext(): bool
    {
        return $this->workspace !== null && $this->jobId !== null;
    }

    /**
     * Get the job ID
     */
    public function getJobId(): ?int
    {
        return $this->jobId;
    }

    /**
     * Get the member ID
     */
    public function getMemberId(): ?int
    {
        return $this->memberId;
    }

    /**
     * Get the workspace name
     */
    public function getWorkspace(): ?string
    {
        return $this->workspace;
    }

    /**
     * Get the project root (repo clone directory)
     */
    public function getProjectRoot(): ?string
    {
        return $this->projectRoot;
    }

    /**
     * Connect to the tenant database
     */
    public function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        if (!$this->workspace) {
            $this->log('ERROR', 'No workspace specified');
            return false;
        }

        // Load autoloader
        $autoloader = $this->appRoot . '/vendor/autoload.php';
        if (!file_exists($autoloader)) {
            $this->log('ERROR', "Autoloader not found: {$autoloader}");
            return false;
        }
        require_once $autoloader;

        // Find tenant config
        $configPath = $this->findTenantConfig();
        if (!$configPath) {
            $this->log('ERROR', "Tenant config not found for workspace: {$this->workspace}");
            return false;
        }

        // Parse config
        $config = parse_ini_file($configPath, true);
        if (!$config) {
            $this->log('ERROR', "Failed to parse config: {$configPath}");
            return false;
        }

        // Connect to database
        try {
            $dbType = $config['database']['type'] ?? 'sqlite';

            if ($dbType === 'mysql') {
                $dsn = sprintf(
                    'mysql:host=%s;dbname=%s',
                    $config['database']['host'] ?? 'localhost',
                    $config['database']['name'] ?? ''
                );
                R::setup($dsn, $config['database']['user'] ?? '', $config['database']['password'] ?? '');
            } else {
                // SQLite
                $dbPath = $config['database']['path'] ?? '';
                if (!str_starts_with($dbPath, '/')) {
                    $dbPath = $this->appRoot . '/' . $dbPath;
                }
                if (!file_exists($dbPath)) {
                    $this->log('ERROR', "SQLite database not found: {$dbPath}");
                    return false;
                }
                R::setup('sqlite:' . $dbPath);
            }

            R::freeze(false); // Allow schema modifications if needed
            $this->connected = true;
            $this->log('DEBUG', "Connected to tenant database: {$this->workspace}");
            return true;

        } catch (\Exception $e) {
            $this->log('ERROR', "Database connection failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Find the tenant config file
     */
    private function findTenantConfig(): ?string
    {
        // Try workspace-specific config first
        $configPath = $this->appRoot . '/conf/config.' . $this->workspace . '.ini';
        if (file_exists($configPath)) {
            return $configPath;
        }

        // Try default config (for single-tenant setups)
        $configPath = $this->appRoot . '/conf/config.ini';
        if (file_exists($configPath)) {
            return $configPath;
        }

        return null;
    }

    /**
     * Get the current aidevjobs bean
     */
    public function getJob(): ?object
    {
        if (!$this->connected || !$this->jobId) {
            return null;
        }

        try {
            $job = R::load('aidevjobs', $this->jobId);
            return $job->id ? $job : null;
        } catch (\Exception $e) {
            $this->log('ERROR', "Failed to load job {$this->jobId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Save the aidevjobs bean
     */
    public function saveJob(object $job): bool
    {
        if (!$this->connected) {
            return false;
        }

        try {
            R::store($job);
            return true;
        } catch (\Exception $e) {
            $this->log('ERROR', "Failed to save job: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add a log entry to aidevjoblogs
     */
    public function addJobLog(string $level, string $type, string $message): bool
    {
        if (!$this->connected || !$this->jobId) {
            return false;
        }

        try {
            $log = R::dispense('aidevjoblogs');
            $log->job_id = $this->jobId;
            $log->level = $level;
            $log->log_type = $type;
            $log->message = $this->truncate($message, 4000);
            $log->created_at = date('Y-m-d H:i:s');
            R::store($log);
            return true;
        } catch (\Exception $e) {
            $this->log('ERROR', "Failed to add job log: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update job status
     */
    public function updateJobStatus(string $status, ?string $progressMessage = null): bool
    {
        $job = $this->getJob();
        if (!$job) {
            return false;
        }

        $job->status = $status;
        if ($progressMessage !== null) {
            $job->progress_message = $progressMessage;
        }
        $job->updated_at = date('Y-m-d H:i:s');

        return $this->saveJob($job);
    }

    /**
     * Update job files_changed JSON array
     */
    public function addFileChanged(string $filePath, string $action = 'modified'): bool
    {
        $job = $this->getJob();
        if (!$job) {
            return false;
        }

        // Parse existing files_changed
        $filesChanged = [];
        if (!empty($job->files_changed)) {
            $decoded = json_decode($job->files_changed, true);
            if (is_array($decoded)) {
                $filesChanged = $decoded;
            }
        }

        // Make path relative to project root if possible
        if ($this->projectRoot && str_starts_with($filePath, $this->projectRoot)) {
            $filePath = substr($filePath, strlen($this->projectRoot) + 1);
        }

        // Add or update file entry
        $filesChanged[$filePath] = [
            'action' => $action,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        $job->files_changed = json_encode($filesChanged);
        $job->updated_at = date('Y-m-d H:i:s');

        return $this->saveJob($job);
    }

    /**
     * Check if a path is within the allowed project root (workspace isolation)
     */
    public function isWithinWorkspace(string $path): bool
    {
        if (!$this->projectRoot) {
            return true; // No isolation configured
        }

        $realPath = realpath($path);
        if (!$realPath) {
            // File doesn't exist yet, check parent directory
            $realPath = realpath(dirname($path));
            if (!$realPath) {
                return false;
            }
        }

        return str_starts_with($realPath, $this->projectRoot);
    }

    /**
     * Close database connection
     */
    public function close(): void
    {
        if ($this->connected) {
            try {
                R::close();
            } catch (\Exception $e) {
                // Ignore close errors
            }
            $this->connected = false;
        }
    }

    /**
     * Log a message to the hooks log file
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->logFile) {
            return;
        }

        $entry = sprintf(
            "[%s] [%s] [job:%s] [workspace:%s] %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $this->jobId ?? 'none',
            $this->workspace ?? 'none',
            $message,
            $context ? json_encode($context) : ''
        );

        @file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Truncate a string to a maximum length
     */
    private function truncate(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }
        return substr($text, 0, $maxLength - 15) . "\n... [truncated]";
    }

    /**
     * Destructor - ensure connection is closed
     */
    public function __destruct()
    {
        $this->close();
    }
}
