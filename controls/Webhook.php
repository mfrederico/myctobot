<?php
/**
 * Webhook Controller
 * Handles incoming webhooks from Jira and GitHub
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;
use \app\services\AIDevJobService;
use \app\services\AIDevJobManager;
use \app\services\UserDatabase;
use \app\services\EncryptionService;
use \app\services\UserDatabaseService;
use \app\services\MailgunService;
use \app\services\TmuxService;

require_once __DIR__ . '/../lib/Bean.php';
require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';
require_once __DIR__ . '/../services/AIDevJobService.php';
require_once __DIR__ . '/../services/AIDevJobManager.php';
require_once __DIR__ . '/../services/UserDatabase.php';
require_once __DIR__ . '/../services/EncryptionService.php';
require_once __DIR__ . '/../services/UserDatabaseService.php';
require_once __DIR__ . '/../services/MailgunService.php';
require_once __DIR__ . '/../services/TmuxService.php';

class Webhook extends BaseControls\Control {

    /**
     * Handle Jira webhook
     * Endpoint: POST /webhook/jira
     */
    public function jira() {
        // Get raw payload
        $payload = file_get_contents('php://input');

        if (empty($payload)) {
            $this->logger->warning('Jira webhook: empty payload');
            Flight::response()->status(400);
            echo json_encode(['error' => 'Empty payload']);
            return;
        }

        // Validate signature if secret is configured
        $secret = Flight::get('webhooks.jira_secret');
        if (!empty($secret)) {
            $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? $_SERVER['HTTP_X_ATLASSIAN_WEBHOOK_SIGNATURE'] ?? '';
            if (!$this->validateJiraSignature($payload, $signature, $secret)) {
                $this->logger->warning('Jira webhook: invalid signature');
                Flight::response()->status(401);
                echo json_encode(['error' => 'Invalid signature']);
                return;
            }
        }

        $data = json_decode($payload, true);
        if (!$data) {
            $this->logger->warning('Jira webhook: invalid JSON');
            Flight::response()->status(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }

        $this->logger->info('Jira webhook received', [
            'event' => $data['webhookEvent'] ?? 'unknown'
        ]);

        try {
            $this->processJiraWebhook($data);
            echo json_encode(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('Jira webhook processing failed', ['error' => $e->getMessage()]);
            Flight::response()->status(500);
            echo json_encode(['error' => 'Processing failed']);
        }
    }

    /**
     * Process Jira webhook data
     */
    private function processJiraWebhook(array $data): void {
        $event = $data['webhookEvent'] ?? '';
        $issue = $data['issue'] ?? [];
        $issueKey = $issue['key'] ?? '';
        $selfUrl = $issue['self'] ?? '';

        // Try to extract cloud ID from self URL (API gateway format)
        $cloudId = $this->extractCloudIdFromSelf($selfUrl);

        // If that fails, try to look up by site URL (webhook format)
        if (empty($cloudId) && !empty($selfUrl)) {
            $cloudId = $this->findCloudIdBySiteUrl($selfUrl);
        }

        $this->logger->debug('Jira webhook parsing', [
            'event' => $event,
            'issue_key' => $issueKey,
            'self_url' => $selfUrl,
            'cloud_id' => $cloudId ?: 'not found'
        ]);

        if (empty($issueKey) || empty($cloudId)) {
            $this->logger->debug('Jira webhook: missing issue key or cloud ID', [
                'has_issue_key' => !empty($issueKey),
                'has_cloud_id' => !empty($cloudId),
                'self_url' => $selfUrl
            ]);
            return;
        }

        // Check if this is our own bot's update (avoid loops)
        $memberId = $this->findMemberByCloudId($cloudId);
        if ($memberId) {
            $jobService = new AIDevJobService();
            if ($jobService->isBotUpdate($data, $memberId)) {
                $this->logger->debug('Jira webhook: ignoring bot update', ['issue_key' => $issueKey]);
                return;
            }
        }

        // First, check if there's a local tmux session to augment
        $this->augmentLocalSession($issueKey, $cloudId, $data);

        switch ($event) {
            case 'jira:issue_updated':
                $this->handleIssueUpdated($data, $issueKey, $cloudId);
                break;

            case 'comment_created':
                $this->handleCommentCreated($data, $issueKey, $cloudId);
                break;

            default:
                $this->logger->debug('Jira webhook: unhandled event', ['event' => $event]);
        }
    }

    /**
     * Handle issue:updated event - check for ai-dev label and Done transitions
     */
    private function handleIssueUpdated(array $data, string $issueKey, string $cloudId): void {
        $changelog = $data['changelog'] ?? [];
        $items = $changelog['items'] ?? [];

        // Check current labels on the issue
        $issue = $data['issue'] ?? [];
        $currentLabels = [];
        foreach (($issue['fields']['labels'] ?? []) as $label) {
            $currentLabels[] = is_string($label) ? $label : ($label['name'] ?? '');
        }

        // Check what changed
        $hasOnlyStatusChange = true;
        $hasLabelChange = false;
        $hasBotLabelChange = false;
        $isDoneTransition = false;
        $newStatusName = null;

        foreach ($items as $item) {
            if ($item['field'] === 'labels') {
                $hasLabelChange = true;
                $hasOnlyStatusChange = false;
                // Check if this is our bot's label change
                $oldLabels = explode(' ', $item['fromString'] ?? '');
                $newLabels = explode(' ', $item['toString'] ?? '');
                if ((in_array('myctobot-working', $newLabels) && !in_array('myctobot-working', $oldLabels)) ||
                    (!in_array('myctobot-working', $newLabels) && in_array('myctobot-working', $oldLabels))) {
                    $hasBotLabelChange = true;
                }
            } elseif ($item['field'] === 'status') {
                // Track the new status name
                $newStatusName = $item['toString'] ?? null;
                $toStatus = strtolower($newStatusName ?? '');
                if (in_array($toStatus, ['done', 'closed', 'resolved', 'complete', 'completed'])) {
                    $isDoneTransition = true;
                }
            } else {
                $hasOnlyStatusChange = false;
            }
        }

        // Check if this status transition should close the AI Developer session
        if ($newStatusName && in_array('ai-dev', $currentLabels)) {
            $this->checkCompleteStatusTransition($issueKey, $cloudId, $newStatusName);
        }

        // Handle "Done" transition - cleanup Shopify themes and branches
        if ($isDoneTransition) {
            $memberId = $this->findMemberByCloudId($cloudId);
            if ($memberId) {
                $this->logger->info('Ticket transitioned to Done, triggering cleanup', [
                    'issue_key' => $issueKey,
                    'member_id' => $memberId
                ]);
                $jobService = new AIDevJobService();
                $cleanupResult = $jobService->cleanupOnTicketDone($memberId, $issueKey);
                $this->logger->info('Cleanup completed', [
                    'issue_key' => $issueKey,
                    'shopify_themes_deleted' => $cleanupResult['shopify_themes_deleted'],
                    'jobs_marked_complete' => $cleanupResult['jobs_marked_complete']
                ]);
            }
            // Continue processing (don't return) - ticket might also have other changes
        }

        // Skip if this webhook is just our bot's label change
        if ($hasLabelChange && $hasBotLabelChange && count($items) <= 2) {
            $this->logger->debug('Jira webhook: ignoring bot label change', ['issue_key' => $issueKey]);
            return;
        }

        // Skip status-only changes UNLESS the issue has ai-dev label (then check if job needed)
        if ($hasOnlyStatusChange && !$hasLabelChange) {
            // Even for status-only changes, check if ai-dev label is present and no job running
            if (in_array('ai-dev', $currentLabels)) {
                $this->logger->debug('Jira webhook: status change on ai-dev issue, checking if job needed', ['issue_key' => $issueKey]);
                $this->triggerAIDevJobIfNeeded($issueKey, $cloudId);
            } else {
                $this->logger->debug('Jira webhook: ignoring status-only change', ['issue_key' => $issueKey]);
            }
            return;
        }

        // Check changelog for ai-dev label being added
        foreach ($items as $item) {
            if ($item['field'] === 'labels') {
                $oldLabels = explode(' ', $item['fromString'] ?? '');
                $newLabels = explode(' ', $item['toString'] ?? '');

                // Check if ai-dev label was ADDED
                $aiDevWasAdded = in_array('ai-dev', $newLabels) && !in_array('ai-dev', $oldLabels);

                // Check if ai-dev label was REMOVED
                $aiDevWasRemoved = in_array('ai-dev', $oldLabels) && !in_array('ai-dev', $newLabels);

                if ($aiDevWasAdded && in_array('ai-dev', $currentLabels)) {
                    $this->triggerAIDevJob($issueKey, $cloudId);
                    return;
                }

                if ($aiDevWasRemoved) {
                    $memberId = $this->findMemberByCloudId($cloudId);
                    if ($memberId) {
                        $this->logger->info('ai-dev label removed, closing local session if exists', [
                            'issue_key' => $issueKey,
                            'member_id' => $memberId
                        ]);
                        $this->closeLocalTmuxSession($issueKey, $memberId);
                    }
                    return;
                }
            }
        }
    }

    /**
     * Trigger AI Dev job only if not already running/completed
     */
    private function triggerAIDevJobIfNeeded(string $issueKey, string $cloudId): void {
        $memberId = $this->findMemberByCloudId($cloudId);
        if (!$memberId) {
            return;
        }

        // Check if there's already an active job for this issue
        $statusDir = __DIR__ . '/../storage/aidev_status/member_' . $memberId;
        if (is_dir($statusDir)) {
            $files = glob($statusDir . '/*.json');
            foreach ($files as $file) {
                $content = @file_get_contents($file);
                if ($content) {
                    $data = json_decode($content, true);
                    if ($data && ($data['issue_key'] ?? '') === $issueKey) {
                        $status = $data['status'] ?? '';
                        // Skip if job is running, pending, or already has PR
                        if (in_array($status, ['pending', 'running', 'pr_created', 'waiting_clarification'])) {
                            $this->logger->debug('Jira webhook: job already exists for issue', [
                                'issue_key' => $issueKey,
                                'status' => $status
                            ]);
                            return;
                        }
                    }
                }
            }
        }

        // No active job found, trigger new one
        $this->triggerAIDevJob($issueKey, $cloudId);
    }

    /**
     * Handle comment:created event - check for clarification responses
     */
    private function handleCommentCreated(array $data, string $issueKey, string $cloudId): void {
        $comment = $data['comment'] ?? [];
        $commentId = $comment['id'] ?? '';
        $authorAccountId = $comment['author']['accountId'] ?? '';

        // Find member by cloud ID
        $memberId = $this->findMemberByCloudId($cloudId);
        if (!$memberId) {
            $this->logger->debug('No member found for cloud', ['cloud_id' => $cloudId]);
            return;
        }

        // Check if there's a job waiting for clarification for this issue
        $jobManager = new AIDevJobManager($memberId);
        $job = $jobManager->get($issueKey);

        if (!$job) {
            return;
        }

        if ($job->status !== AIDevJobManager::STATUS_WAITING_CLARIFICATION) {
            return;
        }

        // Check if this comment is from someone other than the bot
        // (we don't want to resume on our own comments)
        $botCommentId = $job->clarificationCommentId ?? '';
        if ($commentId === $botCommentId) {
            return;
        }

        $this->logger->info('Clarification response detected', [
            'issue_key' => $issueKey,
            'shard_job_id' => $job->currentShardJobId,
            'comment_id' => $commentId
        ]);

        // Resume the job - pass issue_key now since that's our primary identifier
        $this->resumeJob($memberId, $issueKey, $commentId);
    }

    /**
     * Trigger a new AI Developer job from webhook
     */
    private function triggerAIDevJob(string $issueKey, string $cloudId): void {
        $memberId = $this->findMemberByCloudId($cloudId);
        if (!$memberId) {
            $this->logger->debug('No member found for cloud', ['cloud_id' => $cloudId]);
            return;
        }

        // Check if local runner is enabled
        $useLocalRunner = Flight::get('aidev.use_local_runner') ?? false;

        if ($useLocalRunner) {
            $this->spawnLocalRunner($issueKey, $memberId, $cloudId);
            return;
        }

        // Use the shared AIDevJobService to trigger the job on a shard
        $jobService = new AIDevJobService();
        $result = $jobService->triggerJob($memberId, $issueKey, $cloudId);

        if ($result['success']) {
            $this->logger->info('AI Developer job triggered via webhook (shard)', [
                'member_id' => $memberId,
                'job_id' => $result['job_id'],
                'issue_key' => $issueKey,
                'shard' => $result['shard'] ?? 'unknown'
            ]);
        } else {
            $this->logger->warning('Failed to trigger AI Developer job via webhook', [
                'member_id' => $memberId,
                'issue_key' => $issueKey,
                'error' => $result['error'] ?? 'Unknown error'
            ]);
        }
    }

    /**
     * Spawn local AI Developer runner in a tmux session
     * Uses YOUR Claude subscription instead of API credits
     */
    private function spawnLocalRunner(string $issueKey, int $memberId, string $cloudId): void {
        $tmux = new TmuxService($memberId, $issueKey);

        if ($tmux->exists()) {
            $this->logger->info('Local tmux session already exists', ['issue_key' => $issueKey, 'member_id' => $memberId]);
            return;
        }

        $scriptPath = dirname(__DIR__) . '/scripts/local-aidev-full.php';

        if (!file_exists($scriptPath)) {
            $this->logger->error('Local runner script not found', ['path' => $scriptPath]);
            return;
        }

        if ($tmux->spawn($scriptPath, true)) {
            $this->logger->info('Local AI Developer spawned in tmux', [
                'issue_key' => $issueKey,
                'session' => $tmux->getSessionName(),
                'member_id' => $memberId
            ]);
        } else {
            $this->logger->error('Failed to spawn local AI Developer', [
                'issue_key' => $issueKey,
                'member_id' => $memberId
            ]);
        }
    }

    /**
     * Resume a job after clarification
     */
    private function resumeJob(int $memberId, string $issueKey, string $answerCommentId): void {
        $cronSecret = Flight::get('cron.api_key');
        $scriptPath = __DIR__ . '/../scripts/ai-dev-agent.php';

        $cmd = sprintf(
            'php %s --secret=%s --member=%d --issue=%s --action=resume --comment=%s > /dev/null 2>&1 &',
            escapeshellarg($scriptPath),
            escapeshellarg($cronSecret),
            $memberId,
            escapeshellarg($issueKey),
            escapeshellarg($answerCommentId)
        );

        exec($cmd);

        $this->logger->info('AI Developer job resumed via webhook', [
            'member_id' => $memberId,
            'issue_key' => $issueKey
        ]);
    }

    // =========================================
    // Local tmux Session Augmentation
    // =========================================

    /**
     * Close local tmux session when ai-dev label is removed
     */
    private function closeLocalTmuxSession(string $issueKey, int $memberId): void {
        $tmux = new TmuxService($memberId, $issueKey);

        if (!$tmux->exists()) {
            $this->logger->debug('No local tmux session to close', ['issue_key' => $issueKey, 'member_id' => $memberId]);
            return;
        }

        $killed = $tmux->kill();
        $this->logger->info('Local tmux session closed', [
            'issue_key' => $issueKey,
            'member_id' => $memberId,
            'success' => $killed
        ]);
    }

    /**
     * Check if status transition matches the configured "complete" status for the board
     * If so, close the AI Developer tmux session
     */
    private function checkCompleteStatusTransition(string $issueKey, string $cloudId, string $newStatus): void {
        $memberId = $this->findMemberByCloudId($cloudId);
        if (!$memberId) {
            return;
        }

        // Extract project key from issue key (e.g., "SSI-1893" -> "SSI")
        $projectKey = explode('-', $issueKey)[0] ?? '';
        if (empty($projectKey)) {
            return;
        }

        // Find the board for this project
        $board = R::findOne('jiraboards', 'project_key = ? AND member_id = ?', [$projectKey, $memberId]);
        if (!$board) {
            return;
        }

        $completeStatus = $board->aidev_status_complete ?? null;
        if (empty($completeStatus)) {
            return;
        }

        // Check if the new status matches the configured complete status (case-insensitive)
        if (strcasecmp($newStatus, $completeStatus) === 0) {
            $this->logger->info('Ticket transitioned to complete status, closing AI Developer session', [
                'issue_key' => $issueKey,
                'member_id' => $memberId,
                'status' => $newStatus,
                'configured_complete_status' => $completeStatus
            ]);
            $this->closeLocalTmuxSession($issueKey, $memberId);
        }
    }

    /**
     * Download a Jira attachment to the local work directory
     */
    private function downloadJiraAttachment(string $issueKey, int $memberId, string $attachmentUrl, string $filename, string $oauthToken): ?string {
        $tmux = new TmuxService($memberId, $issueKey);
        $attachmentsDir = $tmux->getWorkDir() . '/attachments';

        if (!is_dir($attachmentsDir)) {
            @mkdir($attachmentsDir, 0755, true);
        }

        $localPath = "{$attachmentsDir}/{$filename}";

        // Skip if already downloaded
        if (file_exists($localPath)) {
            return $localPath;
        }

        // Download using curl
        $cmd = sprintf(
            'curl -s -L -H "Authorization: Bearer %s" -o %s %s 2>/dev/null',
            escapeshellarg($oauthToken),
            escapeshellarg($localPath),
            escapeshellarg($attachmentUrl)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode === 0 && file_exists($localPath)) {
            return $localPath;
        }

        return null;
    }

    /**
     * Augment a running local Claude session with new Jira data
     *
     * NOTE: This only works if the webhook is received on the same machine
     * where the tmux session is running. For production webhooks going to
     * a remote server, the tmux session won't be found.
     *
     * For local development, use ngrok or similar to tunnel webhooks to localhost.
     */
    private function augmentLocalSession(string $issueKey, string $cloudId, array $data): void {
        // Get memberId from cloudId
        $memberId = $this->findMemberByCloudId($cloudId);
        if (!$memberId) {
            return;
        }

        $tmux = new TmuxService($memberId, $issueKey);

        $this->logger->debug('Local session check', [
            'issue_key' => $issueKey,
            'member_id' => $memberId,
            'has_tmux_session' => $tmux->exists(),
            'claude_running' => $tmux->isClaudeRunning()
        ]);

        // Only process if there's a local session with Claude running
        if (!$tmux->exists() || !$tmux->isClaudeRunning()) {
            return;
        }

        $event = $data['webhookEvent'] ?? '';
        $this->logger->info('Augmenting local Claude session', [
            'issue_key' => $issueKey,
            'member_id' => $memberId,
            'event' => $event
        ]);

        // Handle different event types
        switch ($event) {
            case 'comment_created':
                $this->augmentWithComment($issueKey, $memberId, $tmux, $data);
                break;

            case 'jira:issue_updated':
                $this->augmentWithUpdate($issueKey, $memberId, $tmux, $data);
                break;
        }
    }

    /**
     * Augment session with a new comment
     */
    private function augmentWithComment(string $issueKey, int $memberId, TmuxService $tmux, array $data): void {
        $comment = $data['comment'] ?? [];
        $author = $comment['author']['displayName'] ?? 'Unknown';
        $body = $this->extractTextFromCommentBody($comment['body'] ?? []);

        // Check if comment is from our bot (skip)
        $authorAccountId = $comment['author']['accountId'] ?? '';
        if (strpos($authorAccountId, 'app_') === 0 || strpos($body, 'MyCTOBot') !== false) {
            return;
        }

        // Build message for Claude
        $message = "[JIRA UPDATE] New comment from {$author}: {$body}";

        // Send to session
        if ($tmux->sendMessage($message)) {
            $this->logger->info('Sent comment to local Claude session', [
                'issue_key' => $issueKey,
                'member_id' => $memberId,
                'author' => $author
            ]);
        }
    }

    /**
     * Augment session with an update (status change, attachment, etc.)
     */
    private function augmentWithUpdate(string $issueKey, int $memberId, TmuxService $tmux, array $data): void {
        $changelog = $data['changelog'] ?? [];
        $items = $changelog['items'] ?? [];

        foreach ($items as $item) {
            $field = $item['field'] ?? '';

            if ($field === 'status') {
                $newStatus = $item['toString'] ?? '';

                // Check for "Done" status - kill the session
                $doneStatuses = ['done', 'closed', 'resolved', 'complete', 'completed', 'cancelled'];
                if (in_array(strtolower($newStatus), $doneStatuses)) {
                    $this->logger->info('Ticket closed, killing local Claude session', [
                        'issue_key' => $issueKey,
                        'member_id' => $memberId,
                        'status' => $newStatus
                    ]);
                    $tmux->kill();
                    return;
                }

                // Notify of status change
                $message = "[JIRA UPDATE] Ticket status changed to: {$newStatus}";
                $tmux->sendMessage($message);
            }

            if ($field === 'Attachment') {
                // New attachment added - try to download it
                $this->handleNewAttachment($issueKey, $memberId, $tmux, $data);
            }

            if ($field === 'description') {
                $newDesc = $item['toString'] ?? '';
                $message = "[JIRA UPDATE] Ticket description was updated. New description excerpt: " . substr($newDesc, 0, 500);
                $tmux->sendMessage($message);
            }
        }
    }

    /**
     * Handle a new attachment being added
     */
    private function handleNewAttachment(string $issueKey, int $memberId, TmuxService $tmux, array $data): void {
        $issue = $data['issue'] ?? [];
        $attachments = $issue['fields']['attachment'] ?? [];

        if (empty($attachments)) {
            return;
        }

        // Get cloudId from data for OAuth
        $cloudId = $data['cloudId'] ?? '';
        if (empty($cloudId)) {
            return;
        }

        try {
            $oauthToken = \app\plugins\AtlassianAuth::getValidToken($memberId, $cloudId);
        } catch (\Exception $e) {
            $this->logger->warning('Could not get OAuth token for attachment download', [
                'issue_key' => $issueKey,
                'error' => $e->getMessage()
            ]);
            return;
        }

        // Download the latest attachment (likely the one just added)
        $latest = end($attachments);
        $filename = $latest['filename'] ?? '';
        $contentUrl = $latest['content'] ?? '';
        $mimeType = $latest['mimeType'] ?? '';

        if (empty($contentUrl) || empty($filename)) {
            return;
        }

        // Only download images
        if (strpos($mimeType, 'image/') !== 0) {
            $message = "[JIRA UPDATE] New attachment added: {$filename} ({$mimeType})";
            $tmux->sendMessage($message);
            return;
        }

        // Download the image
        $localPath = $this->downloadJiraAttachment($issueKey, $memberId, $contentUrl, $filename, $oauthToken);

        if ($localPath) {
            $message = "[JIRA UPDATE] New image attachment '{$filename}' downloaded to {$localPath} - please view it with the Read tool";
            $tmux->sendMessage($message);
            $this->logger->info('Downloaded attachment for local Claude session', [
                'issue_key' => $issueKey,
                'filename' => $filename,
                'local_path' => $localPath
            ]);
        }
    }

    /**
     * Extract text from Jira ADF comment body
     */
    private function extractTextFromCommentBody($body): string {
        if (is_string($body)) {
            return $body;
        }

        if (!is_array($body)) {
            return '';
        }

        $text = '';
        $extractText = function($node) use (&$extractText, &$text) {
            if (isset($node['text'])) {
                $text .= $node['text'] . ' ';
            }
            if (isset($node['content']) && is_array($node['content'])) {
                foreach ($node['content'] as $child) {
                    $extractText($child);
                }
            }
        };

        $extractText($body);
        return trim($text);
    }

    /**
     * Handle GitHub webhook
     * Endpoint: POST /webhook/github
     */
    public function github() {
        $payload = file_get_contents('php://input');

        if (empty($payload)) {
            $this->logger->warning('GitHub webhook: empty payload');
            Flight::response()->status(400);
            echo json_encode(['error' => 'Empty payload']);
            return;
        }

        // Validate signature if secret is configured
        $secret = Flight::get('webhooks.github_secret');
        if (!empty($secret)) {
            $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
            if (!$this->validateGitHubSignature($payload, $signature, $secret)) {
                $this->logger->warning('GitHub webhook: invalid signature');
                Flight::response()->status(401);
                echo json_encode(['error' => 'Invalid signature']);
                return;
            }
        }

        $event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
        $data = json_decode($payload, true);

        if (!$data) {
            $this->logger->warning('GitHub webhook: invalid JSON');
            Flight::response()->status(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }

        $this->logger->info('GitHub webhook received', ['event' => $event]);

        try {
            $this->processGitHubWebhook($event, $data);
            echo json_encode(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('GitHub webhook processing failed', ['error' => $e->getMessage()]);
            Flight::response()->status(500);
            echo json_encode(['error' => 'Processing failed']);
        }
    }

    /**
     * Process GitHub webhook data
     */
    private function processGitHubWebhook(string $event, array $data): void {
        switch ($event) {
            case 'pull_request':
                $this->handlePullRequestEvent($data);
                break;

            case 'pull_request_review':
                $this->handlePullRequestReviewEvent($data);
                break;

            case 'check_run':
                $this->handleCheckRunEvent($data);
                break;

            default:
                $this->logger->debug('GitHub webhook: unhandled event', ['event' => $event]);
        }
    }

    /**
     * Handle pull_request events
     */
    private function handlePullRequestEvent(array $data): void {
        $action = $data['action'] ?? '';
        $pr = $data['pull_request'] ?? [];
        $prNumber = $pr['number'] ?? 0;
        $repo = $data['repository'] ?? [];
        $repoFullName = $repo['full_name'] ?? '';

        // Log for future use (e.g., updating job status when PR is merged/closed)
        $this->logger->debug('Pull request event', [
            'action' => $action,
            'pr_number' => $prNumber,
            'repo' => $repoFullName
        ]);

        // Could update job status when PR is merged
        if ($action === 'closed' && ($pr['merged'] ?? false)) {
            // Find job by PR number and update status to complete
            // This would require adding pr_number lookup to AIDevJobManager
        }
    }

    /**
     * Handle pull_request_review events
     */
    private function handlePullRequestReviewEvent(array $data): void {
        // Log review events for future use
        $action = $data['action'] ?? '';
        $review = $data['review'] ?? [];
        $state = $review['state'] ?? '';

        $this->logger->debug('Pull request review event', [
            'action' => $action,
            'state' => $state
        ]);
    }

    /**
     * Handle check_run events (CI/CD status)
     */
    private function handleCheckRunEvent(array $data): void {
        $action = $data['action'] ?? '';
        $checkRun = $data['check_run'] ?? [];
        $status = $checkRun['status'] ?? '';
        $conclusion = $checkRun['conclusion'] ?? '';

        $this->logger->debug('Check run event', [
            'action' => $action,
            'status' => $status,
            'conclusion' => $conclusion
        ]);
    }

    // ========================================
    // Helper Methods
    // ========================================

    /**
     * Validate Jira webhook signature
     */
    private function validateJiraSignature(string $payload, string $signature, string $secret): bool {
        if (empty($signature)) {
            return false;
        }

        // Jira uses HMAC-SHA256
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Validate GitHub webhook signature
     */
    private function validateGitHubSignature(string $payload, string $signature, string $secret): bool {
        if (empty($signature)) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Extract cloud ID from Jira self URL
     */
    private function extractCloudIdFromSelf(string $selfUrl): string {
        // URL format: https://api.atlassian.com/ex/jira/{cloudId}/rest/api/...
        if (preg_match('#/ex/jira/([a-f0-9-]+)/#', $selfUrl, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * Find cloud ID by looking up site URL in our tokens
     * URL format: https://{site}.atlassian.net/rest/api/...
     */
    private function findCloudIdBySiteUrl(string $selfUrl): string {
        // Extract the site domain from the self URL
        // e.g., https://greenworkstools.atlassian.net/rest/api/3/issue/12345
        if (preg_match('#https://([^/]+\.atlassian\.net)/#', $selfUrl, $matches)) {
            $siteDomain = $matches[1];
            $siteUrl = 'https://' . $siteDomain;

            // Look up the cloud ID from our stored tokens
            $token = R::findOne('atlassiantoken', 'site_url = ?', [$siteUrl]);
            if ($token) {
                return $token->cloud_id;
            }

            // Also try without https
            $token = R::findOne('atlassiantoken', 'site_url LIKE ?', ['%' . $siteDomain . '%']);
            if ($token) {
                return $token->cloud_id;
            }
        }

        return '';
    }

    /**
     * Find member ID by Atlassian cloud ID
     */
    private function findMemberByCloudId(string $cloudId): ?int {
        $token = R::findOne('atlassiantoken', 'cloud_id = ?', [$cloudId]);
        return $token ? (int)$token->member_id : null;
    }

    /**
     * Get member's SQLite database
     */
    private function getMemberDb(int $memberId): ?\SQLite3 {
        $member = R::load('member', $memberId);
        if (!$member || empty($member->ceobot_db)) {
            return null;
        }

        $dbPath = Flight::get('ceobot.user_db_path') ?? 'database/';
        $dbFile = $dbPath . $member->ceobot_db . '.sqlite';

        if (!file_exists($dbFile)) {
            return null;
        }

        return new \SQLite3($dbFile);
    }

    /**
     * Handle shard digest callback
     * Endpoint: POST /webhook/digest
     *
     * Called by shard when digest analysis is complete
     * Body: { job_id, status, result: { analysis, markdown_report }, error }
     */
    public function digest() {
        // Validate API key from Authorization header
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
        $expectedKey = Flight::get('cron.api_key');

        if (empty($expectedKey)) {
            $this->logger->error('Digest webhook: cron.api_key not configured');
            Flight::response()->status(500);
            echo json_encode(['error' => 'Server misconfigured']);
            return;
        }

        // Support both "Bearer <key>" and raw key formats
        $providedKey = preg_replace('/^Bearer\s+/i', '', $authHeader);

        if (empty($providedKey) || !hash_equals($expectedKey, $providedKey)) {
            $this->logger->warning('Digest webhook: invalid API key');
            Flight::response()->status(401);
            echo json_encode(['error' => 'Invalid API key']);
            return;
        }

        $payload = file_get_contents('php://input');

        if (empty($payload)) {
            $this->logger->warning('Digest webhook: empty payload');
            Flight::response()->status(400);
            echo json_encode(['error' => 'Empty payload']);
            return;
        }

        $data = json_decode($payload, true);
        if (!$data) {
            $this->logger->warning('Digest webhook: invalid JSON');
            Flight::response()->status(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }

        $jobId = $data['job_id'] ?? '';
        $type = $data['type'] ?? 'final'; // 'progress' or 'final'
        $status = $data['status'] ?? '';
        $result = $data['result'] ?? null;
        $error = $data['error'] ?? null;
        $itemsCount = $data['items_count'] ?? 0;
        $phase = $data['phase'] ?? 'running';
        $elapsedSeconds = $data['elapsed_seconds'] ?? 0;
        $partialOutput = $data['partial_output'] ?? null;

        $this->logger->info('Digest webhook received', [
            'job_id' => $jobId,
            'type' => $type,
            'items_count' => $itemsCount,
            'status' => $status,
            'has_result' => !empty($result)
        ]);

        if (empty($jobId)) {
            Flight::response()->status(400);
            echo json_encode(['error' => 'Missing job_id']);
            return;
        }

        try {
            // Look up the pending digest job
            $digestJob = R::findOne('digestjobs', 'job_id = ?', [$jobId]);

            if (!$digestJob) {
                $this->logger->warning('Digest job not found', ['job_id' => $jobId]);
                Flight::response()->status(404);
                echo json_encode(['error' => 'Job not found']);
                return;
            }

            // Handle progress updates
            if ($type === 'progress') {
                $digestJob->items_count = $itemsCount;
                $digestJob->phase = $phase;
                $digestJob->elapsed_seconds = $elapsedSeconds;
                $digestJob->updated = date('Y-m-d H:i:s');

                // Optionally store partial output for debugging
                if ($partialOutput) {
                    $digestJob->partial_output = $partialOutput;
                }

                R::store($digestJob);

                $this->logger->debug('Digest progress update', [
                    'job_id' => $jobId,
                    'phase' => $phase,
                    'items_count' => $itemsCount,
                    'elapsed' => $elapsedSeconds
                ]);

                echo json_encode(['success' => true, 'items_count' => $itemsCount, 'phase' => $phase]);
                return;
            }

            // Handle final result
            $digestJob->items_count = $itemsCount;

            if ($status === 'completed' && $result) {
                $this->processDigestResult($digestJob, $result);
                $digestJob->status = 'completed';
                $digestJob->completed_at = date('Y-m-d H:i:s');
            } else {
                $digestJob->status = 'failed';
                $digestJob->error = $error ?? 'Unknown error';
                $digestJob->completed_at = date('Y-m-d H:i:s');

                // If we have partial results, still try to save them
                if ($result && !empty($result['raw_output'])) {
                    $digestJob->partial_output = $result['raw_output'];
                    $this->logger->info('Saving partial digest results', [
                        'job_id' => $jobId,
                        'items_count' => $itemsCount
                    ]);
                }

                $this->logger->error('Digest analysis failed', [
                    'job_id' => $jobId,
                    'error' => $error,
                    'items_count' => $itemsCount
                ]);
            }

            $digestJob->result = json_encode($result);
            R::store($digestJob);

            echo json_encode(['success' => true, 'items_count' => $itemsCount]);

        } catch (\Exception $e) {
            $this->logger->error('Digest webhook processing failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage()
            ]);
            Flight::response()->status(500);
            echo json_encode(['error' => 'Processing failed']);
        }
    }

    /**
     * Process successful digest result - store and send email
     */
    private function processDigestResult($digestJob, array $result): void {
        $memberId = (int) $digestJob->member_id;
        $boardId = (int) $digestJob->board_id;

        // Get markdown report from result
        $markdown = $result['markdown_report'] ?? '';
        $analysis = $result['analysis'] ?? [];

        if (empty($markdown) && !empty($result['raw_output'])) {
            // Fallback to raw output if no markdown
            $markdown = "# Sprint Digest\n\n" . $result['raw_output'];
        }

        // Store the analysis
        $userDb = new UserDatabaseService($memberId);
        $analysisId = $userDb->storeAnalysis($boardId, 'digest', [
            'success' => true,
            'analysis' => $analysis,
            'shard_job_id' => $digestJob->job_id
        ], $markdown);

        $this->logger->info('Digest analysis stored', [
            'member_id' => $memberId,
            'board_id' => $boardId,
            'analysis_id' => $analysisId
        ]);

        // Send email if requested
        if ($digestJob->send_email) {
            $this->sendDigestEmail($digestJob, $markdown);
        }

        // Update last_digest_at for the board
        $userDb->updateBoard($boardId, ['last_digest_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Handle shard AI Developer callback
     * Endpoint: POST /webhook/aidev
     *
     * Called by shard when AI Developer work is complete
     * Body: { job_id, status, result: { success, pr_url, pr_number, ... }, error }
     */
    public function aidev() {
        // Validate API key from Authorization header
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
        $expectedKey = Flight::get('cron.api_key');

        if (empty($expectedKey)) {
            $this->logger->error('AIdev webhook: cron.api_key not configured');
            Flight::response()->status(500);
            echo json_encode(['error' => 'Server misconfigured']);
            return;
        }

        $providedKey = preg_replace('/^Bearer\s+/i', '', $authHeader);

        if (empty($providedKey) || !hash_equals($expectedKey, $providedKey)) {
            $this->logger->warning('AIdev webhook: invalid API key');
            Flight::response()->status(401);
            echo json_encode(['error' => 'Invalid API key']);
            return;
        }

        $payload = file_get_contents('php://input');

        if (empty($payload)) {
            $this->logger->warning('AIdev webhook: empty payload');
            Flight::response()->status(400);
            echo json_encode(['error' => 'Empty payload']);
            return;
        }

        $data = json_decode($payload, true);
        if (!$data) {
            $this->logger->warning('AIdev webhook: invalid JSON');
            Flight::response()->status(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }

        $jobId = $data['job_id'] ?? '';
        $status = $data['status'] ?? '';
        $result = $data['result'] ?? [];
        $elapsedSeconds = $data['elapsed_seconds'] ?? 0;

        $this->logger->info('AIdev webhook received', [
            'job_id' => $jobId,
            'status' => $status,
            'has_result' => !empty($result)
        ]);

        if (empty($jobId)) {
            Flight::response()->status(400);
            echo json_encode(['error' => 'Missing job_id']);
            return;
        }

        try {
            // Find the job by searching all member directories
            $jobData = $this->findAIDevJob($jobId);

            if (!$jobData) {
                $this->logger->warning('AIdev job not found', ['job_id' => $jobId]);
                Flight::response()->status(404);
                echo json_encode(['error' => 'Job not found']);
                return;
            }

            $memberId = $jobData['member_id'];

            $cloudId = $jobData['cloud_id'] ?? '';
            $issueKey = $jobData['issue_key'] ?? '';
            $boardId = $jobData['board_id'] ?? 0;
            $jobService = new AIDevJobService();
            $jobManager = new AIDevJobManager($memberId);

            // Ensure AIDevStatusService is available for all status handlers
            require_once __DIR__ . '/../services/AIDevStatusService.php';

            // Handle based on status
            if ($status === 'completed' && !empty($result['success']) && !empty($result['pr_url'])) {
                // PR was created successfully
                $jobManager->complete(
                    $issueKey,
                    $result['pr_url'],
                    $result['pr_number'] ?? null,
                    $result['branch_name'] ?? null,
                    $result['raw_output'] ?? null,
                    $result
                );

                // Also update JSON status file (was missing - caused "running" status to persist)
                \app\services\AIDevStatusService::prCreated(
                    $memberId,
                    $jobId,
                    $result['pr_url'],
                    $result['pr_number'] ?? null,
                    $result['branch_name'] ?? null
                );

                $this->logger->info('AIdev job PR created', [
                    'job_id' => $jobId,
                    'issue_key' => $issueKey,
                    'pr_url' => $result['pr_url']
                ]);

                // Post summary comment to Jira and handle completion (remove label, transition status)
                if ($cloudId && $issueKey) {
                    $jobService->postPRSummaryToJira($memberId, $cloudId, $issueKey, $result);
                    $jobService->onJobCompleted($memberId, $cloudId, $issueKey, $boardId);
                }

            } elseif ($status === 'preview_ready' && !empty($result['shopify_preview_url'])) {
                // Shopify preview is ready - update status and post to Jira
                $themeId = (int)($result['shopify_theme_id'] ?? 0);
                $previewUrl = $result['shopify_preview_url'];
                $playwrightResults = $result['playwright_results'] ?? null;

                \app\services\AIDevStatusService::previewReady(
                    $memberId,
                    $jobId,
                    $themeId,
                    $previewUrl,
                    $playwrightResults
                );

                $this->logger->info('AIdev Shopify preview ready', [
                    'job_id' => $jobId,
                    'issue_key' => $issueKey,
                    'preview_url' => $previewUrl
                ]);

                // Post preview URL to Jira
                if ($cloudId && $issueKey) {
                    $jobService->postPreviewToJira($memberId, $cloudId, $issueKey, $previewUrl, $playwrightResults);
                }

            } elseif ($status === 'needs_clarification' && !empty($result['questions'])) {
                // Claude needs clarification - post questions to Jira
                $this->postClarificationToJira($memberId, $issueKey, $cloudId, $result['questions']);

                // Transition to clarification status if configured
                if ($cloudId && $issueKey && $boardId) {
                    $jobService->onJobNeedsClarification($memberId, $cloudId, $issueKey, $boardId);
                }

            } else {
                // Failed - check multiple possible error locations
                $errorMsg = $result['reason'] ?? $result['error'] ?? null;
                if (!$errorMsg && !empty($result['raw_output'])) {
                    // Error might be in raw_output (e.g., "Credit balance is too low")
                    $errorMsg = trim($result['raw_output']);
                }
                $errorMsg = $errorMsg ?: 'Unknown error';
                $jobManager->fail($issueKey, $errorMsg, $result['raw_output'] ?? null);

                // Also update JSON status file
                \app\services\AIDevStatusService::fail($memberId, $jobId, $errorMsg);

                $this->logger->error('AIdev job failed', [
                    'job_id' => $jobId,
                    'issue_key' => $issueKey,
                    'error' => $errorMsg
                ]);

                // Track credit balance errors for UI warning
                if (stripos($errorMsg, 'credit balance') !== false || stripos($errorMsg, 'insufficient') !== false) {
                    $this->storeCreditBalanceError($memberId, $errorMsg);
                }

                // Remove working label and transition to failed status if configured
                if ($cloudId && $issueKey) {
                    $jobService->onJobFailed($memberId, $cloudId, $issueKey, $boardId, $errorMsg);
                }
            }

            // Store the full result for debugging
            $this->storeAIDevResult($memberId, $jobId, $result, $elapsedSeconds);

            // Check for credit balance errors in verification (even on successful jobs)
            if (!empty($result['verification']['credit_error'])) {
                $this->storeCreditBalanceError($memberId, 'Anthropic API credits are low. Verification iterations may have been skipped.');
            }

            echo json_encode(['success' => true]);

        } catch (\Exception $e) {
            $this->logger->error('AIdev webhook processing failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage()
            ]);
            Flight::response()->status(500);
            echo json_encode(['error' => 'Processing failed']);
        }
    }

    /**
     * Find AI Dev job by shard job ID across all members
     * Searches JSON status files first (primary source), then database as fallback
     */
    private function findAIDevJob(string $shardJobId): ?array {
        // First, search JSON status files (primary source of truth for active jobs)
        $statusDir = __DIR__ . '/../storage/aidev_status';
        if (is_dir($statusDir)) {
            $memberDirs = glob($statusDir . '/member_*', GLOB_ONLYDIR);
            foreach ($memberDirs as $memberDir) {
                $memberId = (int) str_replace($statusDir . '/member_', '', $memberDir);
                $files = glob($memberDir . '/*.json');
                foreach ($files as $file) {
                    $content = @file_get_contents($file);
                    if ($content) {
                        $data = json_decode($content, true);
                        if ($data && ($data['job_id'] ?? '') === $shardJobId) {
                            return [
                                'member_id' => $memberId,
                                'job_id' => $shardJobId,
                                'issue_key' => $data['issue_key'] ?? '',
                                'cloud_id' => $data['cloud_id'] ?? '',
                                'board_id' => (int) ($data['board_id'] ?? 0),
                                'status' => $data['status'] ?? '',
                                'repo_connection_id' => $data['repo_connection_id'] ?? null
                            ];
                        }
                    }
                }
            }
        }

        // Fallback: search database for jobs with matching current_shard_job_id
        $members = R::find('member', 'ceobot_db IS NOT NULL AND ceobot_db != ?', ['']);

        foreach ($members as $member) {
            try {
                $job = UserDatabase::with($member->id, function() use ($shardJobId) {
                    return Bean::findOne('aidevjobs', 'current_shard_job_id = ?', [$shardJobId]);
                });

                if ($job) {
                    return [
                        'member_id' => (int) $member->id,
                        'job_id' => $shardJobId,
                        'issue_key' => $job->issue_key,
                        'cloud_id' => $job->cloud_id,
                        'board_id' => (int) $job->board_id,
                        'status' => $job->status,
                        'repo_connection_id' => $job->repo_connection_id
                    ];
                }
            } catch (\Exception $e) {
                // Skip members with database issues
                $this->logger->debug('Error checking member database', [
                    'member_id' => $member->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return null;
    }

    /**
     * Post clarification questions to Jira
     */
    private function postClarificationToJira(int $memberId, string $issueKey, string $cloudId, array $questions): void {
        // Build comment text
        $questionText = "**MyCTOBot AI Developer - Clarification Needed**\n\n";
        $questionText .= "Before I can implement this ticket, I need some clarification:\n\n";
        foreach ($questions as $i => $question) {
            $questionText .= ($i + 1) . ". {$question}\n";
        }
        $questionText .= "\nPlease reply to this comment with your answers.";

        $jobManager = new AIDevJobManager($memberId);

        try {
            // Get Jira client
            require_once __DIR__ . '/../services/JiraClient.php';
            $jira = new \app\services\JiraClient($memberId, $cloudId);
            $comment = $jira->addComment($issueKey, $questionText);

            // Update job status
            $jobManager->waitForClarification($issueKey, $comment['id'], $questions);

            $this->logger->info('Posted clarification questions to Jira', [
                'issue_key' => $issueKey,
                'comment_id' => $comment['id']
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to post clarification to Jira', [
                'issue_key' => $issueKey,
                'error' => $e->getMessage()
            ]);

            $jobManager->fail($issueKey, 'Failed to post clarification questions: ' . $e->getMessage());
        }
    }

    /**
     * Store credit balance error for UI warning
     */
    private function storeCreditBalanceError(int $memberId, string $errorMsg): void {
        try {
            $member = R::load('member', $memberId);
            if (!$member || empty($member->ceobot_db)) {
                return;
            }

            $dbPath = Flight::get('ceobot.user_db_path') ?? 'database/';
            $dbFile = $dbPath . $member->ceobot_db . '.sqlite';

            if (!file_exists($dbFile)) {
                return;
            }

            $db = new \SQLite3($dbFile);
            $db->exec("INSERT OR REPLACE INTO enterprisesettings
                       (setting_key, setting_value, is_encrypted, updated_at)
                       VALUES ('credit_balance_error', '" . $db->escapeString($errorMsg) . "', 0, datetime('now'))");
            $db->close();

            $this->logger->warning('Credit balance error recorded', [
                'member_id' => $memberId,
                'error' => $errorMsg
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to store credit balance error', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Store AI Dev result for debugging
     */
    private function storeAIDevResult(int $memberId, string $jobId, array $result, int $elapsed): void {
        $statusDir = __DIR__ . "/../storage/aidev_status/member_{$memberId}/results";

        if (!is_dir($statusDir)) {
            @mkdir($statusDir, 0755, true);
        }

        $resultFile = $statusDir . '/' . $jobId . '_result.json';
        file_put_contents($resultFile, json_encode([
            'job_id' => $jobId,
            'elapsed_seconds' => $elapsed,
            'result' => $result,
            'received_at' => date('Y-m-d H:i:s')
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Send digest email
     */
    private function sendDigestEmail($digestJob, string $markdown): void {
        $member = R::load('member', $digestJob->member_id);
        if (!$member || empty($member->email)) {
            return;
        }

        $mailgun = new MailgunService();
        if (!$mailgun->isEnabled()) {
            $this->logger->warning('Mailgun not configured, skipping digest email');
            return;
        }

        $boardName = $digestJob->board_name ?? 'Sprint Board';
        $projectKey = $digestJob->project_key ?? '';
        $subject = "[{$projectKey}] Daily Sprint Digest - " . date('Y-m-d');

        $ccEmails = !empty($digestJob->digest_cc) ? $digestJob->digest_cc : null;

        $success = $mailgun->sendMarkdownEmail($subject, $markdown, $member->email, $ccEmails);

        if ($success) {
            $this->logger->info('Digest email sent', [
                'member_id' => $digestJob->member_id,
                'email' => $member->email
            ]);
        } else {
            $this->logger->error('Failed to send digest email', [
                'member_id' => $digestJob->member_id
            ]);
        }
    }
}
