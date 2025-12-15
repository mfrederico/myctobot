<?php
/**
 * Digest Service
 * Handles automated daily digest emails for all users
 */

namespace app\services;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\plugins\AtlassianAuth;

require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';
require_once __DIR__ . '/UserDatabaseService.php';
require_once __DIR__ . '/JiraClient.php';
require_once __DIR__ . '/ClaudeClient.php';
require_once __DIR__ . '/MailgunService.php';
require_once __DIR__ . '/SubscriptionService.php';
require_once __DIR__ . '/TierFeatures.php';
require_once __DIR__ . '/../analyzers/PriorityAnalyzer.php';
require_once __DIR__ . '/../analyzers/ClarityAnalyzer.php';

class DigestService {

    private $logger;
    private $verbose;

    public function __construct($verbose = false) {
        $this->logger = Flight::get('log');
        $this->verbose = $verbose;
    }

    /**
     * Process digests for all members
     */
    public function processAllDigests() {
        $this->log('Starting digest processing...');

        // Get all active members
        $members = R::findAll('member', 'status = ?', ['active']);
        $processedCount = 0;
        $errorCount = 0;

        foreach ($members as $member) {
            if (empty($member->ceobot_db)) {
                continue; // Skip members without database
            }

            try {
                $count = $this->processMemberDigests($member);
                $processedCount += $count;
            } catch (\Exception $e) {
                $this->logger->error('Digest processing failed for member', [
                    'member_id' => $member->id,
                    'error' => $e->getMessage()
                ]);
                $errorCount++;
            }
        }

        $this->log("Digest processing complete. Processed: {$processedCount}, Errors: {$errorCount}");

        return [
            'processed' => $processedCount,
            'errors' => $errorCount
        ];
    }

    /**
     * Process digests for a single member
     */
    public function processMemberDigests($member) {
        $userDb = new UserDatabaseService($member->id);
        $boards = $userDb->getBoardsForDigest();

        if (empty($boards)) {
            return 0;
        }

        $this->log("Processing digests for member {$member->id} ({$member->email}), {" . count($boards) . "} boards");

        $processedCount = 0;

        foreach ($boards as $board) {
            if ($this->shouldSendDigest($board)) {
                try {
                    $this->sendBoardDigest($member, $board, $userDb);
                    $processedCount++;
                } catch (\Exception $e) {
                    $this->logger->error('Board digest failed', [
                        'member_id' => $member->id,
                        'board_id' => $board['id'],
                        'error' => $e->getMessage()
                    ]);

                    // Log failed digest
                    $userDb->logDigest(
                        $board['id'],
                        $member->email,
                        "[{$board['project_key']}] Digest Failed",
                        '',
                        'failed',
                        $e->getMessage()
                    );
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
            $currentTime = $now->format('H:i');

            // Check if current time is within 15 minutes of digest time
            $digestParts = explode(':', $digestTime);
            $currentParts = explode(':', $currentTime);

            $digestMinutes = ((int)$digestParts[0] * 60) + (int)($digestParts[1] ?? 0);
            $currentMinutes = ((int)$currentParts[0] * 60) + (int)$currentParts[1];

            $diff = abs($currentMinutes - $digestMinutes);

            // Within 15 minute window
            if ($diff > 15) {
                return false;
            }

            // Check if already sent today
            $lastDigest = $board['last_digest_at'];
            if ($lastDigest) {
                $lastDigestDate = (new \DateTime($lastDigest, $tz))->format('Y-m-d');
                $today = $now->format('Y-m-d');

                if ($lastDigestDate === $today) {
                    return false; // Already sent today
                }
            }

            return true;

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
     * Send digest for a specific board
     */
    private function sendBoardDigest($member, $board, $userDb) {
        $this->log("Sending digest for board {$board['board_name']} to {$member->email}");

        // Get valid Atlassian token
        $token = AtlassianAuth::getValidToken($member->id, $board['cloud_id']);
        if (!$token) {
            throw new \Exception('No valid Atlassian token');
        }

        // Initialize clients
        $jiraClient = new JiraClient($member->id, $board['cloud_id']);
        $claudeClient = new ClaudeClient();
        $mailgun = new MailgunService();

        if (!$mailgun->isEnabled()) {
            throw new \Exception('Mailgun not configured');
        }

        // Get status filter
        $statusFilter = $board['status_filter'] ?? 'To Do';
        $statusArray = array_map('trim', explode(',', $statusFilter));

        // Fetch issues
        $issues = $jiraClient->getCurrentSprintIssues($board['project_key'], $statusArray);

        if (empty($issues)) {
            $this->log("No issues found for board {$board['board_name']}, skipping digest");
            return;
        }

        // Load board weights and goals (Pro features)
        $weights = null;
        $goals = null;
        $clarityThreshold = 6;
        $isPro = SubscriptionService::isPro($member->id);

        if ($isPro) {
            if (!empty($board['priority_weights'])) {
                $weights = json_decode($board['priority_weights'], true);
            }
            if (!empty($board['goals'])) {
                $goals = json_decode($board['goals'], true);
                $clarityThreshold = $goals['clarity_threshold'] ?? 6;
            }
        }

        // Run clarity analysis with caching (Pro feature)
        $clarityResult = null;
        $clarityAnalyzer = new \app\analyzers\ClarityAnalyzer($claudeClient);
        if ($isPro || SubscriptionService::canAccessFeature($member->id, TierFeatures::FEATURE_CLARITY_ANALYSIS)) {
            $clarityResult = $clarityAnalyzer->analyzeWithCache(
                $issues,
                $board['id'],
                $userDb,
                $clarityThreshold
            );
        }

        // Run priority analysis with weights, goals, and clarity data
        $analyzer = new \app\analyzers\PriorityAnalyzer($claudeClient);
        $result = $analyzer->generateDailyPriorities(
            $issues,
            null, // estimations
            $clarityResult ? $clarityResult['clarifications_needed'] : null,
            null, // similarities
            $weights,
            $goals
        );

        if (!$result['success']) {
            throw new \Exception('Analysis failed: ' . ($result['error'] ?? 'Unknown error'));
        }

        // Add clarity results to the result
        if ($clarityResult) {
            $result['analysis']['clarifications_needed'] = $clarityResult['clarifications_needed'];
            $result['clarity_stats'] = [
                'analyzed_count' => $clarityResult['analyzed_count'],
                'cached_count' => $clarityResult['cached_count'],
                'clarification_count' => count($clarityResult['clarifications_needed'])
            ];
        }

        // Get Jira site URL for creating ticket links
        $jiraSiteUrl = AtlassianAuth::getSiteUrl($member->id, $board['cloud_id']);

        // Generate markdown with Jira links
        $markdown = $analyzer->generateDailyLog($result, $jiraSiteUrl);

        // Append clarification section to markdown if there are items
        if ($clarityResult && !empty($clarityResult['clarifications_needed'])) {
            $markdown .= $this->generateClarificationMarkdown($clarityResult['clarifications_needed'], $jiraSiteUrl);
        }

        // Store analysis
        $result['status_filter'] = $statusFilter;
        $analysisId = $userDb->storeAnalysis($board['id'], 'digest', $result, $markdown);

        // Send email with optional CC
        $subject = "[{$board['project_key']}] Daily Sprint Digest - " . date('Y-m-d');
        $ccEmails = !empty($board['digest_cc']) ? $board['digest_cc'] : null;
        $success = $mailgun->sendMarkdownEmail($subject, $markdown, $member->email, $ccEmails);

        if (!$success) {
            throw new \Exception('Failed to send email via Mailgun');
        }

        // Log successful digest
        $userDb->logDigest(
            $board['id'],
            $member->email,
            $subject,
            substr($markdown, 0, 500),
            'sent'
        );

        $this->log("Digest sent successfully for {$board['board_name']}");
    }

    /**
     * Log message
     */
    private function log($message) {
        if ($this->verbose) {
            echo "[" . date('Y-m-d H:i:s') . "] {$message}\n";
        }
        $this->logger->info($message);
    }

    /**
     * Generate markdown section for clarification items
     */
    private function generateClarificationMarkdown(array $clarifications, ?string $jiraSiteUrl = null): string {
        if (empty($clarifications)) {
            return '';
        }

        $md = "\n\n## Tickets Needing Clarification\n\n";
        $md .= "The following tickets have low clarity scores and may need stakeholder input before work can begin.\n\n";

        foreach ($clarifications as $item) {
            $ticketLink = $jiraSiteUrl
                ? "[{$item['key']}](" . rtrim($jiraSiteUrl, '/') . '/browse/' . $item['key'] . ")"
                : $item['key'];

            $scoreEmoji = $item['clarity_score'] < 4 ? '🔴' : ($item['clarity_score'] < 6 ? '🟡' : '🟢');

            $md .= "### {$scoreEmoji} {$ticketLink} - {$item['summary']}\n\n";
            $md .= "| Attribute | Value |\n";
            $md .= "|-----------|-------|\n";
            $md .= "| Clarity Score | **{$item['clarity_score']}/10** |\n";
            $md .= "| Reporter | {$item['reporter_name']} |\n";
            if (!empty($item['reporter_email'])) {
                $md .= "| Email | {$item['reporter_email']} |\n";
            }
            $md .= "| Type | {$item['type']} |\n";
            $md .= "| Priority | {$item['priority']} |\n\n";

            if (!empty($item['assessment'])) {
                $md .= "**Assessment**: {$item['assessment']}\n\n";
            }

            if (!empty($item['missing_elements'])) {
                $md .= "**Missing Elements**:\n";
                foreach ($item['missing_elements'] as $element) {
                    $md .= "- {$element}\n";
                }
                $md .= "\n";
            }

            if (!empty($item['suggested_questions'])) {
                $md .= "**Suggested Questions for Stakeholder**:\n";
                foreach ($item['suggested_questions'] as $question) {
                    $md .= "- {$question}\n";
                }
                $md .= "\n";
            }

            $md .= "---\n\n";
        }

        return $md;
    }
}
