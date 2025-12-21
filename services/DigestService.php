<?php
/**
 * Digest Service (DEPRECATED)
 *
 * This service has been superseded by AnalysisService which provides
 * unified analysis functionality with email support.
 *
 * Use AnalysisService instead:
 *   $service = new AnalysisService($memberId, $verbose);
 *   $service->runAnalysis($boardId, ['send_email' => true]);
 *
 * This file is kept for backward compatibility only.
 *
 * @deprecated Use AnalysisService instead
 */

namespace app\services;

use \Flight as Flight;
use \RedBeanPHP\R as R;

require_once __DIR__ . '/AnalysisService.php';

class DigestService {

    private $logger;
    private $verbose;
    private $force;

    /**
     * @deprecated Use AnalysisService instead
     */
    public function __construct($verbose = false, $force = false) {
        $this->logger = Flight::get('log');
        $this->verbose = $verbose;
        $this->force = $force;

        $this->logger->warning('DigestService is deprecated. Use AnalysisService instead.');
    }

    /**
     * Process digests for all members
     *
     * @deprecated Use cron-digest.php which uses AnalysisService
     */
    public function processAllDigests() {
        $this->log('Starting digest processing (deprecated path)...');

        // Get all active members
        $members = R::findAll('member', 'status = ?', ['active']);
        $processedCount = 0;
        $errorCount = 0;

        foreach ($members as $member) {
            if (empty($member->ceobot_db)) {
                continue;
            }

            try {
                $count = $this->processMemberDigests($member);
                $processedCount += $count;
            } catch (\Exception $e) {
                $errorCount++;
                $this->logger->error('Digest error', [
                    'member_id' => $member->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->log("Digest processing complete. Processed: {$processedCount}, Errors: {$errorCount}");

        return [
            'processed' => $processedCount,
            'errors' => $errorCount
        ];
    }

    /**
     * Process digests for a specific member
     *
     * @deprecated Use AnalysisService directly
     */
    private function processMemberDigests($member) {
        $userDbPath = Flight::get('ceobot.user_db_path') ?? 'database/';
        $dbPath = $userDbPath . $member->ceobot_db . '.sqlite';

        if (!file_exists($dbPath)) {
            return 0;
        }

        $userDb = new \SQLite3($dbPath);
        $result = $userDb->query("SELECT * FROM jiraboards WHERE enabled = 1 AND digest_enabled = 1");

        $boards = [];
        while ($board = $result->fetchArray(SQLITE3_ASSOC)) {
            $boards[] = $board;
        }
        $userDb->close();

        if (empty($boards)) {
            return 0;
        }

        $this->log("Processing digests for member {$member->id} ({$member->email}), {" . count($boards) . "} boards");

        $processedCount = 0;

        foreach ($boards as $board) {
            if ($this->shouldSendDigest($board)) {
                try {
                    // Use the new unified AnalysisService
                    $service = new AnalysisService($member->id, $this->verbose);
                    $analysisResult = $service->runAnalysis((int)$board['id'], [
                        'send_email' => true,
                        'analysis_type' => 'digest'
                    ]);

                    if ($analysisResult['success']) {
                        $this->updateLastDigestTime($member, $board['id']);
                        $processedCount++;
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Board digest failed', [
                        'member_id' => $member->id,
                        'board_id' => $board['id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $processedCount;
    }

    /**
     * Check if it's time to send digest for a board
     */
    private function shouldSendDigest($board) {
        $digestTime = $board['digest_time'] ?? '08:00';
        $timezone = $board['timezone'] ?? 'UTC';

        try {
            $tz = new \DateTimeZone($timezone);
            $now = new \DateTime('now', $tz);

            // Check if already sent today
            $lastDigest = $board['last_digest_at'];
            if ($lastDigest) {
                $lastDigestDate = (new \DateTime($lastDigest, $tz))->format('Y-m-d');
                $today = $now->format('Y-m-d');

                if ($lastDigestDate === $today) {
                    return false;
                }
            }

            // If force mode, skip time window check
            if ($this->force) {
                return true;
            }

            // Check if current time is within 15 minutes of digest time
            $currentTime = $now->format('H:i');
            $digestParts = explode(':', $digestTime);
            $currentParts = explode(':', $currentTime);

            $digestMinutes = ((int)$digestParts[0] * 60) + (int)($digestParts[1] ?? 0);
            $currentMinutes = ((int)$currentParts[0] * 60) + (int)$currentParts[1];

            $diff = abs($currentMinutes - $digestMinutes);

            return $diff <= 15;

        } catch (\Exception $e) {
            $this->logger->warning('Timezone error for board', [
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
    private function updateLastDigestTime($member, int $boardId): void {
        $userDbPath = Flight::get('ceobot.user_db_path') ?? 'database/';
        $dbPath = $userDbPath . $member->ceobot_db . '.sqlite';

        $userDb = new \SQLite3($dbPath);
        $stmt = $userDb->prepare("UPDATE jiraboards SET last_digest_at = ? WHERE id = ?");
        $stmt->bindValue(1, date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->bindValue(2, $boardId, SQLITE3_INTEGER);
        $stmt->execute();
        $userDb->close();
    }

    /**
     * Log message
     */
    private function log(string $message): void {
        if ($this->verbose) {
            echo "[" . date('Y-m-d H:i:s') . "] {$message}\n";
        }
    }
}
