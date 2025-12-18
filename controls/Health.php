<?php
/**
 * Health Controller
 * Provides health check endpoint for shard monitoring
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;

class Health extends BaseControls\Control {

    /**
     * Health check endpoint
     * Returns shard status and job counts for ShardRouter
     */
    public function index() {
        // Count running digest jobs (if table exists)
        $runningJobs = 0;
        $queuedJobs = 0;

        try {
            $runningJobs = R::count('digestjobs', 'status = ?', ['running']);
            $queuedJobs = R::count('digestjobs', 'status = ?', ['queued']);
        } catch (\Exception $e) {
            // Table might not exist on shard with SQLite
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
