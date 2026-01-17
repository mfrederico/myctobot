<?php
/**
 * Health Controller
 * Provides health check endpoint for runner monitoring
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;

class Health extends BaseControls\Control {

    /**
     * Health check endpoint
     * Returns runner status and job counts for RunnerRouter
     */
    public function index() {
        // Count running digest jobs (if table exists)
        $runningJobs = 0;
        $queuedJobs = 0;

        try {
            $runningJobs = Bean::count('digestjobs', 'status = ?', ['running']);
            $queuedJobs = Bean::count('digestjobs', 'status = ?', ['queued']);
        } catch (\Exception $e) {
            // Table might not exist on runner with SQLite
        }

        Flight::json([
            'status' => 'healthy',
            'timestamp' => date('c'),
            'jobs' => [
                'running' => $runningJobs,
                'queued' => $queuedJobs
            ],
            'version' => '1.0.0',
            'php_version' => PHP_VERSION
        ]);
    }
}
