#!/usr/bin/env php
<?php
/**
 * MyCTOBot Daily Digest Scheduler
 *
 * Run this script via cron every 15 minutes to process scheduled digests.
 * It checks each member's boards and runs analysis + sends email for boards
 * that are due based on their configured digest_time and timezone.
 *
 * CRONTAB:
 * --------
 * 0,15,30,45 * * * * cd /path/to/myctobot/scripts && php cron-digest.php --script --verbose >> ../log/cron.log 2>&1
 *
 * OPTIONS:
 * --------
 *   --script     REQUIRED for CLI execution
 *   --verbose    Show detailed output
 *   --dry-run    Check what would be sent without actually sending
 *   --force      Send digests now, ignoring time window (for missed digests)
 *   --help       Show this help message
 */

// Determine paths
$scriptDir = dirname(__FILE__);
$baseDir = dirname($scriptDir);

// Change to base directory
chdir($baseDir);

// Parse command line options
$options = getopt('', ['verbose', 'dry-run', 'force', 'script', 'help']);

if (isset($options['help'])) {
    echo "MyCTOBot Daily Digest Scheduler\n\n";
    echo "Usage: php cron-digest.php --script [options]\n\n";
    echo "Options:\n";
    echo "  --script     REQUIRED for standalone script mode\n";
    echo "  --verbose    Show detailed output\n";
    echo "  --dry-run    Check what would be sent without sending\n";
    echo "  --force      Send digests now, ignoring time window\n";
    echo "  --help       Show this help message\n";
    exit(0);
}

$verbose = isset($options['verbose']);
$dryRun = isset($options['dry-run']);
$force = isset($options['force']);

if ($verbose) {
    echo "MyCTOBot Digest Scheduler\n";
    echo "=========================\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n";
    echo "Base: {$baseDir}\n";
    if ($force) echo "Mode: FORCE (ignoring time windows)\n";
    if ($dryRun) echo "Mode: DRY RUN\n";
    echo "\n";
}

// Load vendor autoload first
require_once $baseDir . '/vendor/autoload.php';

// Load bootstrap class and instantiate it
require_once $baseDir . '/bootstrap.php';

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\services\AnalysisService;

// Load the service
require_once $baseDir . '/services/AnalysisService.php';

try {
    $bootstrap = new \app\Bootstrap($baseDir . '/conf/config.ini');

    // Set timezone from config
    $configTimezone = Flight::get('config')['app']['timezone'] ?? 'America/New_York';
    date_default_timezone_set($configTimezone);

    if ($verbose) {
        echo "Application initialized\n";
        echo "Timezone: {$configTimezone} (current time: " . date('H:i:s') . ")\n\n";
    }

    // Process all members
    $members = R::findAll('member', 'status = ?', ['active']);
    $processedCount = 0;
    $errorCount = 0;

    foreach ($members as $member) {
        if (empty($member->ceobot_db)) {
            continue; // Skip members without database
        }

        try {
            $result = processMemberDigests($member, $verbose, $dryRun, $force);
            $processedCount += $result['processed'];
            $errorCount += $result['errors'];
        } catch (\Exception $e) {
            $errorCount++;
            if ($verbose) {
                echo "Error processing member {$member->id}: {$e->getMessage()}\n";
            }
            Flight::get('log')->error('Digest cron error for member', [
                'member_id' => $member->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    if ($verbose) {
        echo "\n=========================\n";
        echo "Processing complete!\n";
        echo "Digests sent: {$processedCount}\n";
        echo "Errors: {$errorCount}\n";
    }

    exit($errorCount > 0 ? 1 : 0);

} catch (Exception $e) {
    $message = "FATAL ERROR: " . $e->getMessage();

    if ($verbose) {
        echo "\n{$message}\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }

    try {
        $logger = Flight::get('log');
        if ($logger) {
            $logger->error($message, ['trace' => $e->getTraceAsString()]);
        }
    } catch (Exception $logError) {
        // Ignore logging errors
    }

    exit(1);
}

/**
 * Process digests for a single member
 */
function processMemberDigests($member, bool $verbose, bool $dryRun, bool $force): array {
    $userDbPath = Flight::get('ceobot.user_db_path') ?? 'database/';
    $dbPath = $userDbPath . $member->ceobot_db . '.sqlite';

    if (!file_exists($dbPath)) {
        return ['processed' => 0, 'errors' => 0];
    }

    $userDb = new \SQLite3($dbPath);
    $result = $userDb->query("SELECT * FROM jiraboards WHERE enabled = 1 AND digest_enabled = 1");

    $boards = [];
    while ($board = $result->fetchArray(SQLITE3_ASSOC)) {
        $boards[] = $board;
    }
    $userDb->close();

    if (empty($boards)) {
        return ['processed' => 0, 'errors' => 0];
    }

    if ($verbose) {
        echo "Processing member {$member->id} ({$member->email}), " . count($boards) . " boards\n";
    }

    $processedCount = 0;
    $errorCount = 0;

    foreach ($boards as $board) {
        $shouldSend = shouldSendDigest($board, $force, $verbose);
        if ($shouldSend) {
            if ($verbose) {
                echo "  -> Board: {$board['board_name']} ({$board['project_key']})\n";
            }

            if ($dryRun) {
                echo "     [DRY RUN] Would send digest for {$board['board_name']}\n";
                echo "     Digest Time: {$board['digest_time']} ({$board['timezone']})\n";
                echo "     Last Digest: " . ($board['last_digest_at'] ?? 'Never') . "\n";
                $processedCount++;
                continue;
            }

            try {
                // Use the unified AnalysisService
                $service = new AnalysisService($member->id, $verbose);
                $analysisResult = $service->runAnalysis((int)$board['id'], [
                    'send_email' => true,
                    'analysis_type' => 'digest'
                ]);

                if ($analysisResult['success']) {
                    // Update last_digest_at in the board
                    updateLastDigestTime($member, $board['id']);
                    $processedCount++;

                    if ($verbose) {
                        echo "     Digest sent successfully\n";
                    }
                } else {
                    $errorCount++;
                    if ($verbose) {
                        echo "     Analysis returned no success\n";
                    }
                }
            } catch (\Exception $e) {
                $errorCount++;
                if ($verbose) {
                    echo "     ERROR: {$e->getMessage()}\n";
                }
                Flight::get('log')->error('Board digest failed', [
                    'member_id' => $member->id,
                    'board_id' => $board['id'],
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    return ['processed' => $processedCount, 'errors' => $errorCount];
}

/**
 * Check if it's time to send digest for a board
 */
function shouldSendDigest(array $board, bool $force, bool $verbose = false): bool {
    $digestTime = $board['digest_time'] ?? '08:00';
    $timezone = $board['timezone'] ?? 'UTC';
    $boardName = $board['board_name'] ?? "Board {$board['id']}";

    try {
        $tz = new \DateTimeZone($timezone);
        $now = new \DateTime('now', $tz);
        $currentTime = $now->format('H:i');

        // Check if already sent today (always check this, even with --force)
        $lastDigest = $board['last_digest_at'];
        if ($lastDigest) {
            $lastDigestDate = (new \DateTime($lastDigest, $tz))->format('Y-m-d');
            $today = $now->format('Y-m-d');

            if ($lastDigestDate === $today) {
                if ($verbose) {
                    echo "  [SKIP] {$boardName}: Already sent today ({$lastDigest})\n";
                }
                return false;
            }
        }

        // If force mode, skip time window check
        if ($force) {
            if ($verbose) {
                echo "  [FORCE] {$boardName}: Forcing digest (last: " . ($lastDigest ?? 'never') . ")\n";
            }
            return true;
        }

        // Check if current time is within 15 minutes of digest time
        $digestParts = explode(':', $digestTime);
        $currentParts = explode(':', $currentTime);

        $digestMinutes = ((int)$digestParts[0] * 60) + (int)($digestParts[1] ?? 0);
        $currentMinutes = ((int)$currentParts[0] * 60) + (int)$currentParts[1];

        $diff = abs($currentMinutes - $digestMinutes);

        // Within 15 minute window
        if ($diff <= 15) {
            return true;
        }

        if ($verbose) {
            echo "  [SKIP] {$boardName}: Outside time window (now: {$currentTime} {$timezone}, digest: {$digestTime}, diff: {$diff} min)\n";
        }
        return false;

    } catch (\Exception $e) {
        if ($verbose) {
            echo "  [ERROR] {$boardName}: Timezone error - {$e->getMessage()}\n";
        }
        Flight::get('log')->warning('Timezone error for board', [
            'board_id' => $board['id'],
            'timezone' => $timezone,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Update the last_digest_at timestamp for a board
 */
function updateLastDigestTime($member, int $boardId): void {
    $userDbPath = Flight::get('ceobot.user_db_path') ?? 'database/';
    $dbPath = $userDbPath . $member->ceobot_db . '.sqlite';

    $userDb = new \SQLite3($dbPath);
    $stmt = $userDb->prepare("UPDATE jiraboards SET last_digest_at = ? WHERE id = ?");
    $stmt->bindValue(1, date('Y-m-d H:i:s'), SQLITE3_TEXT);
    $stmt->bindValue(2, $boardId, SQLITE3_INTEGER);
    $stmt->execute();
    $userDb->close();
}
