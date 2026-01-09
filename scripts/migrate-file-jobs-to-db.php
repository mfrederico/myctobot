#!/usr/bin/env php
<?php
/**
 * Migrate file-based AI Developer jobs to database
 *
 * Usage: php scripts/migrate-file-jobs-to-db.php --tenant=gwt
 */

$baseDir = dirname(__FILE__, 2);
chdir($baseDir);

require_once $baseDir . '/vendor/autoload.php';

use RedBeanPHP\R as R;

$options = getopt('', ['tenant:', 'dry-run', 'help']);

if (isset($options['help']) || empty($options['tenant'])) {
    echo "Migrate file-based AI Developer jobs to database\n\n";
    echo "Usage: php scripts/migrate-file-jobs-to-db.php --tenant=gwt [--dry-run]\n\n";
    exit(1);
}

$tenant = $options['tenant'];
$dryRun = isset($options['dry-run']);

// Load tenant config
$configFile = $baseDir . "/conf/config.{$tenant}.ini";
if (!file_exists($configFile)) {
    echo "Error: Config not found: {$configFile}\n";
    exit(1);
}

$config = parse_ini_file($configFile, true);
$db = $config['database'];

// Connect to database
$dsn = "{$db['type']}:host={$db['host']};port={$db['port']};dbname={$db['name']}";
R::setup($dsn, $db['user'], $db['pass']);
R::freeze(false);

echo "Connected to database: {$db['name']}\n";

// Find all job files
$storageDir = $baseDir . '/storage/aidev_status';
$domainDirs = glob($storageDir . '/*', GLOB_ONLYDIR);

$migrated = 0;
$skipped = 0;
$errors = 0;

foreach ($domainDirs as $domainDir) {
    $domainId = basename($domainDir);
    echo "\nProcessing domain: {$domainId}\n";

    $memberDirs = glob($domainDir . '/member_*', GLOB_ONLYDIR);

    foreach ($memberDirs as $memberDir) {
        $memberId = (int) str_replace('member_', '', basename($memberDir));
        echo "  Member {$memberId}:\n";

        $jobFiles = glob($memberDir . '/*.json');

        foreach ($jobFiles as $jobFile) {
            $data = json_decode(file_get_contents($jobFile), true);
            if (!$data) {
                echo "    - Skipping invalid JSON: " . basename($jobFile) . "\n";
                $errors++;
                continue;
            }

            $jobId = $data['job_id'] ?? null;
            $issueKey = $data['issue_key'] ?? null;

            if (!$jobId || !$issueKey) {
                echo "    - Skipping invalid job (no job_id or issue_key): " . basename($jobFile) . "\n";
                $errors++;
                continue;
            }

            // Check if already exists
            $existing = R::findOne('aidevjobs', 'job_id = ?', [$jobId]);
            if ($existing) {
                echo "    - Skipping {$issueKey} (already in DB)\n";
                $skipped++;
                continue;
            }

            if ($dryRun) {
                echo "    + Would migrate: {$issueKey} ({$data['status']})\n";
                $migrated++;
                continue;
            }

            // Create the job bean
            $job = R::dispense('aidevjobs');
            $job->job_id = $jobId;
            $job->member_id = $data['member_id'] ?? $memberId;
            $job->board_id = $data['board_id'] ?? 0;
            $job->issue_key = $issueKey;
            $job->repo_connection_id = $data['repo_connection_id'] ?? null;
            $job->cloud_id = $data['cloud_id'] ?? null;
            $job->status = $data['status'] ?? 'pending';
            $job->progress = $data['progress'] ?? 0;
            $job->current_step = $data['current_step'] ?? 'Unknown';
            $job->steps_completed = json_encode($data['steps_completed'] ?? []);
            $job->branch_name = $data['branch_name'] ?? null;
            $job->pr_url = $data['pr_url'] ?? null;
            $job->pr_number = $data['pr_number'] ?? null;
            $job->pr_created_at = $data['pr_created_at'] ?? null;
            $job->clarification_comment_id = $data['clarification_comment_id'] ?? null;
            $job->clarification_questions = json_encode($data['clarification_questions'] ?? []);
            $job->error_message = $data['error'] ?? null;
            $job->files_changed = json_encode($data['files_changed'] ?? []);
            $job->commit_sha = $data['commit_sha'] ?? null;
            $job->shopify_theme_id = $data['shopify_theme_id'] ?? null;
            $job->shopify_preview_url = $data['shopify_preview_url'] ?? null;
            $job->playwright_results = $data['playwright_results'] ? json_encode($data['playwright_results']) : null;
            $job->preserve_branch = $data['preserve_branch'] ?? 1;
            $job->started_at = $data['started_at'] ?? null;
            $job->completed_at = $data['completed_at'] ?? null;
            $job->created_at = $data['started_at'] ?? date('Y-m-d H:i:s');
            $job->updated_at = $data['updated_at'] ?? date('Y-m-d H:i:s');

            try {
                R::store($job);
                echo "    + Migrated: {$issueKey} ({$data['status']})\n";
                $migrated++;
            } catch (Exception $e) {
                echo "    ! Error migrating {$issueKey}: " . $e->getMessage() . "\n";
                $errors++;
            }
        }
    }
}

echo "\n";
echo "=================================\n";
echo "Migration complete\n";
echo "  Migrated: {$migrated}\n";
echo "  Skipped:  {$skipped}\n";
echo "  Errors:   {$errors}\n";

if ($dryRun) {
    echo "\n(Dry run - no changes made)\n";
}
