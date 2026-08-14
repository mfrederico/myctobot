<?php
/**
 * Webhook Controller
 * Handles incoming webhooks from Jira and GitHub
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\services\AIDevJobService;
use \app\services\AIDevJobManager;
use \app\services\EncryptionService;
use \app\services\UserDatabaseService;
use \app\services\MailgunService;
use \app\services\TmuxService;
use \app\services\CeoDirectiveLogger;
use \app\services\GitOperations;
use \app\WorkspaceResolver;

require_once __DIR__ . '/../lib/plugins/AtlassianAuth.php';
require_once __DIR__ . '/../services/GitOperations.php';
require_once __DIR__ . '/../services/AIDevJobService.php';
require_once __DIR__ . '/../services/AIDevJobManager.php';
require_once __DIR__ . '/../services/EncryptionService.php';
require_once __DIR__ . '/../services/UserDatabaseService.php';
require_once __DIR__ . '/../services/MailgunService.php';
require_once __DIR__ . '/../services/TmuxService.php';
require_once __DIR__ . '/../services/CeoDirectiveLogger.php';

class Webhook extends BaseControls\Control {

    /** @var string|null Current CEO directive ID for audit trail */
    private ?string $currentDirectiveId = null;

    /** @var CeoDirectiveLogger|null Current CEO directive logger instance */
    private ?CeoDirectiveLogger $currentDirectiveLogger = null;

    /**
     * Handle Jira webhook
     * Endpoint: POST /webhook/jira or POST /webhook/jira/workspace
     *
     * For multi-workspace, use /webhook/jira/workspace where workspace matches
     * the config file name (e.g., /webhook/jira/gwt loads conf/config.gwt.ini)
     */
    public function jira($params = []) {
        // Check for workspace parameter (from URL: /webhook/jira/workspace)
        $workspace = $this->opId() ?? null;

        // If workspace specified, switch to workspace database
        if ($workspace) {
            if (!$this->switchToworkspaceForWebhook($workspace)) {
                Flight::response()->status(400);
                echo json_encode(['error' => "Invalid workspace: {$workspace}"]);
                return;
            }
            $this->logger->info("Webhook using workspace: {$workspace}");
        }

        // Get raw payload
        $payload = file_get_contents('php://input');

        if (empty($payload)) {
            $this->logger->warning('Jira webhook: empty payload');
            Flight::response()->status(400);
            echo json_encode(['error' => 'Empty payload']);
            return;
        }

        // SECURITY (HIGH-4): verify the signature UNCONDITIONALLY and fail closed.
        // Previously verification was skipped when no secret was configured (the
        // default in every workspace), so forged payloads triggered AI-dev jobs.
        $secret = Flight::get('webhooks.jira_secret');
        if (empty($secret)) {
            $this->logger->error('Jira webhook rejected: webhooks.jira_secret is not configured (fail-closed)');
            Flight::response()->status(401);
            echo json_encode(['error' => 'Webhook signing secret not configured']);
            return;
        }
        $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? $_SERVER['HTTP_X_ATLASSIAN_WEBHOOK_SIGNATURE'] ?? '';
        if (!$this->validateJiraSignature($payload, $signature, $secret)) {
            $this->logger->warning('Jira webhook: invalid signature');
            Flight::response()->status(401);
            echo json_encode(['error' => 'Invalid signature']);
            return;
        }

        $data = json_decode($payload, true);
        if (!$data) {
            $this->logger->warning('Jira webhook: invalid JSON');
            Flight::response()->status(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }

        $this->logger->info('Jira webhook received', [
            'event' => $data['webhookEvent'] ?? 'unknown',
            'workspace' => $workspace ?? 'default'
        ]);

        try {
            $this->processJiraWebhook($data);

            // Log completion if we have a directive logger
            if ($this->currentDirectiveLogger && $this->currentDirectiveId) {
                $this->currentDirectiveLogger->logCompleted(
                    $this->currentDirectiveId,
                    'Jira webhook processed successfully',
                    ['event' => $data['webhookEvent'] ?? 'unknown']
                );
            }

            echo json_encode(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('Jira webhook processing failed', ['error' => $e->getMessage()]);

            // Log error with full stack trace for audit trail
            if ($this->currentDirectiveLogger && $this->currentDirectiveId) {
                $this->currentDirectiveLogger->logError(
                    $this->currentDirectiveId,
                    'Webhook processing failed: ' . $e->getMessage(),
                    $e,
                    'Request failed with 500 error; manual review required'
                );
            }

            Flight::response()->status(500);
            echo json_encode(['error' => 'Processing failed']);
        }
    }

    /**
     * Handle Mailgun incoming email webhook
     * Endpoint: POST /webhook/mailgun
     *
     * Two recipient patterns are routed here; discriminator picks one:
     *   reply-{slug}-{token}@myctobot.ai → CrmCommsInboundService (CRM thread reply)
     *   {workspace}@myctobot.ai          → IncomingEmailService    (CEO directive)
     *
     * Mailgun posts the same payload shape for both (form-urlencoded or
     * multipart with recipient/sender/body-* + timestamp/token/signature),
     * so we branch on the recipient local-part BEFORE delegating to either
     * handler — each handler owns its own signature verification afterward.
     */
    public function mailgun() {
        // Mailgun sends TWO webhook flavors to the same URL when both Receiving
        // routes AND Event webhooks are configured:
        //   Receiving (inbound mail)     → form-urlencoded / multipart
        //   Events (delivered/opened/…)  → application/json with {signature, event-data}
        // We only care about inbound mail here. 200-accept JSON so Mailgun
        // doesn't retry the event delivery.
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            \Flight::json(['accepted' => false, 'reason' => 'events webhook ignored'], 200);
            return;
        }

        $recipient = trim((string)($_POST['recipient'] ?? ''));

        require_once __DIR__ . '/../services/CrmCommsInboundService.php';
        if (\app\services\CrmCommsInboundService::matches($recipient)) {
            $handler = new \app\services\CrmCommsInboundService();
            $handler->handle();
            return;
        }

        require_once __DIR__ . '/../services/IncomingEmailService.php';
        $handler = new \app\services\IncomingEmailService();
        $handler->handleMailgun();
    }

    /**
     * Switch to workspace database for webhook processing
     */
    private function switchToworkspaceForWebhook(string $workspace): bool {
        return WorkspaceResolver::switchDatabase($workspace);
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
            'cloud_uid' => $cloudId ?: 'not found'
        ]);

        if (empty($issueKey) || empty($cloudId)) {
            $this->logger->debug('Jira webhook: missing issue key or cloud ID', [
                'has_issue_key' => !empty($issueKey),
                'has_cloud_uid' => !empty($cloudId),
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

            // Initialize CEO Directive Logger for audit trail
            $directiveLogger = new CeoDirectiveLogger($memberId);
            $directiveId = $directiveLogger->logDirectiveReceived($issueKey, 'jira', [
                'event' => $event,
                'cloud_uid' => $cloudId,
                'user' => $data['user']['displayName'] ?? 'unknown'
            ]);

            // Store directive ID for use in subsequent processing
            $this->currentDirectiveId = $directiveId;
            $this->currentDirectiveLogger = $directiveLogger;
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

        $this->logger->debug('handleIssueUpdated: changelog items', [
            'issue_key' => $issueKey,
            'items_count' => count($items),
            'items' => array_map(fn($i) => ['field' => $i['field'] ?? '', 'to' => $i['toString'] ?? ''], $items)
        ]);

        // Check current labels on the issue
        $issue = $data['issue'] ?? [];
        $currentLabels = [];
        foreach (($issue['fields']['labels'] ?? []) as $label) {
            $currentLabels[] = is_string($label) ? $label : ($label['name'] ?? '');
        }

        // Find any ai-dev label (ai-dev or ai-dev-{repo_id})
        $currentAiDevLabel = $this->findAiDevLabel($currentLabels);
        $hasAiDevLabel = $currentAiDevLabel !== null;

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
                // Jira webhooks use 'from'/'to', but some formats use 'fromString'/'toString'
                $oldLabels = explode(' ', $item['fromString'] ?? $item['from'] ?? '');
                $newLabels = explode(' ', $item['toString'] ?? $item['to'] ?? '');
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
        $this->logger->debug('Status transition check', [
            'issue_key' => $issueKey,
            'new_status' => $newStatusName,
            'current_labels' => $currentLabels,
            'has_aidev_label' => $hasAiDevLabel,
            'aidev_label' => $currentAiDevLabel
        ]);
        if ($newStatusName && $hasAiDevLabel) {
            $this->logger->debug('Calling checkCompleteStatusTransition', [
                'issue_key' => $issueKey,
                'new_status' => $newStatusName
            ]);
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
            if ($hasAiDevLabel) {
                // Extract repo ID from labels (repo-{id} or ai-dev-{id})
                $repoId = $this->extractRepoIdFromLabels($currentLabels);
                $this->logger->debug('Jira webhook: status change on ai-dev issue, checking if job needed', [
                    'issue_key' => $issueKey,
                    'label' => $currentAiDevLabel,
                    'repo_id' => $repoId
                ]);
                $this->triggerAIDevJobIfNeeded($issueKey, $cloudId, $repoId);
            } else {
                $this->logger->debug('Jira webhook: ignoring status-only change', ['issue_key' => $issueKey]);
            }
            return;
        }

        // Check changelog for ai-dev label being added or removed
        foreach ($items as $item) {
            if ($item['field'] === 'labels') {
                // Jira webhooks use 'from'/'to', but some formats use 'fromString'/'toString'
                $oldLabelStr = $item['fromString'] ?? $item['from'] ?? '';
                $newLabelStr = $item['toString'] ?? $item['to'] ?? '';
                $oldLabels = explode(' ', $oldLabelStr);
                $newLabels = explode(' ', $newLabelStr);

                // Find ai-dev labels in old and new
                $oldAiDevLabel = null;
                $newAiDevLabel = null;
                foreach ($oldLabels as $l) {
                    if ($this->isAiDevLabel($l)) {
                        $oldAiDevLabel = $l;
                        break;
                    }
                }
                foreach ($newLabels as $l) {
                    if ($this->isAiDevLabel($l)) {
                        $newAiDevLabel = $l;
                        break;
                    }
                }

                // Check if ai-dev label was ADDED (any form)
                $aiDevWasAdded = $newAiDevLabel !== null && $oldAiDevLabel === null;

                // Check if ai-dev label was REMOVED (any form)
                $aiDevWasRemoved = $oldAiDevLabel !== null && $newAiDevLabel === null;

                if ($aiDevWasAdded && $hasAiDevLabel) {
                    // Extract repo ID from labels (repo-{id} or ai-dev-{id}), null = use default
                    $repoId = $this->extractRepoIdFromLabels($currentLabels);
                    $this->logger->info('AI-dev label added, triggering job', [
                        'issue_key' => $issueKey,
                        'label' => $currentAiDevLabel,
                        'repo_id' => $repoId ?? 'default'
                    ]);
                    $this->triggerAIDevJob($issueKey, $cloudId, $repoId);
                    return;
                }

                if ($aiDevWasRemoved) {
                    $memberId = $this->findMemberByCloudId($cloudId);
                    $this->logger->info('ai-dev label removed - attempting session close', [
                        'issue_key' => $issueKey,
                        'member_id' => $memberId,
                        'removed_label' => $oldAiDevLabel,
                        'old_labels' => $oldLabels,
                        'new_labels' => $newLabels
                    ]);
                    if ($memberId) {
                        $this->closeLocalTmuxSession($issueKey, $memberId, $cloudId, 'label_removed');
                    } else {
                        $this->logger->warning('ai-dev label removed but no member found', [
                            'issue_key' => $issueKey,
                            'cloud_id' => $cloudId
                        ]);
                    }
                    return;
                }
            }
        }
    }

    /**
     * Trigger AI Dev job only if not already running/completed
     */
    private function triggerAIDevJobIfNeeded(string $issueKey, string $cloudId, ?int $repoId = null): void {
        $memberId = $this->findMemberByCloudId($cloudId);
        if (!$memberId) {
            return;
        }

        // Check if ticket is already at "complete" status - prevents race condition
        // where label removal hasn't propagated yet but status already indicates done
        if ($this->isTicketAtCompleteStatus($issueKey, $memberId, $cloudId)) {
            $this->logger->debug('Jira webhook: skipping trigger - ticket already at complete status', [
                'issue_key' => $issueKey
            ]);
            return;
        }

        // Check if there's already an active job for this issue
        $domainId = \app\TmuxManager::getDomainId();
        $statusDir = __DIR__ . '/../storage/aidev_status/' . $domainId . '/member_' . $memberId;
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
                                'status' => $status,
                                'lockfile' => $file
                            ]);
                            return;
                        }
                    }
                }
            }
        }

        // No active job found, trigger new one
        $this->triggerAIDevJob($issueKey, $cloudId, $repoId);
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
            $this->logger->debug('No member found for cloud', ['cloud_uid' => $cloudId]);
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
            'runner_job_uid' => $job->currentRunnerJobId,
            'comment_id' => $commentId
        ]);

        // Resume the job - pass issue_key now since that's our primary identifier
        $this->resumeJob($memberId, $issueKey, $commentId);
    }

    /**
     * Trigger a new AI Developer job from webhook
     * AIDevJobService handles both local runner and remote runner execution
     *
     * @param string $issueKey Jira issue key (e.g., SSI-1234)
     * @param string $cloudId Atlassian cloud ID
     * @param int|null $repoId Optional repo connection ID from ai-dev-{id} label
     */
    private function triggerAIDevJob(string $issueKey, string $cloudId, ?int $repoId = null): void {
        $memberId = $this->findMemberByCloudId($cloudId);
        if (!$memberId) {
            $this->logger->debug('No member found for cloud', ['cloud_uid' => $cloudId]);
            return;
        }

        // Log processing step for CEO directive audit trail
        if ($this->currentDirectiveLogger && $this->currentDirectiveId) {
            $this->currentDirectiveLogger->logProcessingStep(
                $this->currentDirectiveId,
                'job_trigger',
                'Triggering AI Developer job',
                ['repo_id' => $repoId, 'workspace' => Flight::get('workspace.slug')]
            );
        }

        // Get workspace slug for multi-workspace support
        $workspace = Flight::get('workspace.slug');

        // AIDevJobService handles local vs remote runner execution based on config
        // triggerJob signature: (memberId, issueKey, cloudId, boardId, repoId, workspace)
        $jobService = new AIDevJobService();
        $result = $jobService->triggerJob($memberId, $issueKey, $cloudId, null, $repoId, $workspace);

        if ($result['success']) {
            // Check if session was already running (no new job_uid)
            if ($result['already_running'] ?? false) {
                $this->logger->info('AI Developer session already running for issue', [
                    'member_id' => $memberId,
                    'issue_key' => $issueKey,
                    'session_id' => $result['session_id'] ?? null,
                    'tmux_name' => $result['tmux_name'] ?? null
                ]);
                return; // No further action needed
            }

            $isQueued = $result['queued'] ?? false;
            $jobId = $result['job_uid'] ?? null;

            // Link job to existing story if one exists
            if ($jobId) {
                $story = \app\Bean::findOne('ctostories', 'jira_issue_key = ?', [$issueKey]);
                if ($story && $story->id && empty($story->aidev_job_uid)) {
                    $story->aidev_job_uid = $jobId;
                    $story->status = $isQueued ? 'queued' : 'in_progress';
                    \app\Bean::store($story);
                }
            }

            $this->logger->info($isQueued ? 'AI Developer job queued via webhook' : 'AI Developer job triggered via webhook', [
                'member_id' => $memberId,
                'job_uid' => $jobId,
                'issue_key' => $issueKey,
                'repo_id' => $repoId,
                'queued' => $isQueued,
                'position' => $result['position'] ?? null,
                'local' => $result['local'] ?? false,
                'runner' => $result['runner'] ?? null
            ]);

            // Log successful job trigger or queue
            if ($this->currentDirectiveLogger && $this->currentDirectiveId && $jobId) {
                $stepType = $isQueued ? 'job_queued' : 'job_started';
                $stepMessage = $isQueued
                    ? "AI Developer job queued (position {$result['position']})"
                    : 'AI Developer job started successfully';

                $this->currentDirectiveLogger->logProcessingStep(
                    $this->currentDirectiveId,
                    $stepType,
                    $stepMessage,
                    [
                        'job_uid' => $jobId,
                        'queued' => $isQueued,
                        'position' => $result['position'] ?? null,
                        'local' => $result['local'] ?? false,
                        'runner' => $result['runner'] ?? null
                    ]
                );
            }
        } else {
            $this->logger->warning('Failed to trigger AI Developer job via webhook', [
                'member_id' => $memberId,
                'issue_key' => $issueKey,
                'repo_id' => $repoId,
                'error' => $result['error'] ?? 'Unknown error'
            ]);

            // Log job trigger failure
            if ($this->currentDirectiveLogger && $this->currentDirectiveId) {
                $this->currentDirectiveLogger->logError(
                    $this->currentDirectiveId,
                    'Failed to trigger AI Developer job: ' . ($result['error'] ?? 'Unknown error'),
                    null,
                    'Will retry on next webhook or manual trigger',
                    ['repo_id' => $repoId]
                );
            }
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
     * Close local tmux session when ai-dev label is removed or complete status reached
     */
    private function closeLocalTmuxSession(string $issueKey, int $memberId, ?string $cloudId = null, string $reason = 'closed'): void {
        $workspace = Flight::get('workspace.slug');

        $this->logger->info('closeLocalTmuxSession called', [
            'issue_key' => $issueKey,
            'member_id' => $memberId,
            'reason' => $reason,
            'workspace' => $workspace
        ]);

        // Clean up status file regardless of whether tmux session exists
        $this->cleanupJobStatus($memberId, $issueKey, $reason);

        // Get cloudId from issue if not provided (needed for label removal)
        if (!$cloudId) {
            $cloudId = $this->findCloudIdForIssue($issueKey, $memberId);
        }

        // Get work directory and signal the session to complete
        $tmux = new TmuxService($memberId, $issueKey, null, $workspace);
        $workDir = $tmux->getWorkDir();
        $sessionExists = $tmux->exists();

        $this->logger->info('closeLocalTmuxSession session check', [
            'issue_key' => $issueKey,
            'work_dir' => $workDir,
            'work_dir_exists' => is_dir($workDir),
            'session_exists' => $sessionExists,
            'session_name' => $tmux->getSessionName()
        ]);

        if ($workDir && is_dir($workDir)) {
            // Create done file to signal the waiting script
            $doneFile = $workDir . '/done';
            file_put_contents($doneFile, json_encode([
                'timestamp' => date('Y-m-d H:i:s'),
                'reason' => $reason,
                'issue_key' => $issueKey
            ]));
            $this->logger->info('Created task complete signal', [
                'issue_key' => $issueKey,
                'done_file' => $doneFile
            ]);

            // If session exists, send /exit through SSH to remote Claude
            if ($sessionExists) {
                $tmux->sendExit();
                $this->logger->info('Sent /exit to Claude via tmux', ['issue_key' => $issueKey]);

                // Remove ai-dev and myctobot-working labels only when /exit is sent
                if ($cloudId) {
                    // Jira issue
                    Atlassian::removeLabelsOnExit($issueKey, $memberId, $cloudId);
                } elseif (preg_match('#^([^/]+)/([^#]+)#(\d+)$#', $issueKey, $matches)) {
                    // GitHub issue (format: owner/repo#123)
                    Github::removeLabelsOnExit($matches[1], $matches[2], (int) $matches[3], $memberId);
                }
            }

            // Wait for script to cleanup gracefully
            sleep(10);
        }

        // Fallback: cleanup labels directly if script didn't
        if ($cloudId) {
            try {
                require_once __DIR__ . '/../services/AIDevJobService.php';
                $jobService = new \app\services\AIDevJobService();
                $cleanupResult = $jobService->cleanupJobLabels(null, $issueKey);
                if (!empty($cleanupResult['labels_removed'])) {
                    $this->logger->info('Cleaned up remaining labels', [
                        'issue_key' => $issueKey,
                        'labels_removed' => $cleanupResult['labels_removed']
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to cleanup labels', [
                    'issue_key' => $issueKey,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Force kill tmux if still exists after timeout
        if ($tmux->exists()) {
            $tmux->kill();
            $this->logger->info('Force killed tmux session', ['issue_key' => $issueKey]);
        }
    }

    /**
     * Recursively remove a work directory
     */
    private function removeWorkDirectory(string $dir): bool {
        if (!is_dir($dir)) {
            return false;
        }

        // Safety check: only remove directories under /tmp
        if (strpos($dir, '/tmp/') !== 0) {
            $this->logger->warning('Refusing to remove directory outside /tmp', ['dir' => $dir]);
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeWorkDirectory($path);
            } else {
                unlink($path);
            }
        }
        return rmdir($dir);
    }

    /**
     * Clean up job status file when session is closed
     */
    private function cleanupJobStatus(int $memberId, string $issueKey, string $reason = 'closed'): void {
        // Get domain ID for multi-workspace path
        $domainId = \app\TmuxManager::getDomainId();

        // Find and update the status file for this issue
        $statusDir = __DIR__ . '/../storage/aidev_status/' . $domainId . '/member_' . $memberId;
        if (!is_dir($statusDir)) {
            return;
        }

        $files = glob($statusDir . '/*.json');
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content) {
                $data = json_decode($content, true);
                if ($data && ($data['issue_key'] ?? '') === $issueKey) {
                    $status = $data['status'] ?? '';
                    if (in_array($status, ['pending', 'running'])) {
                        // Mark as complete/cancelled
                        $data['status'] = ($reason === 'complete') ? 'complete' : 'cancelled';
                        $data['updated_at'] = date('Y-m-d H:i:s');
                        $data['close_reason'] = $reason;
                        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
                        $this->logger->info('Job status file updated', [
                            'issue_key' => $issueKey,
                            'member_id' => $memberId,
                            'old_status' => $status,
                            'new_status' => $data['status'],
                            'reason' => $reason
                        ]);
                    }
                    break;
                }
            }
        }
    }

    /**
     * Check if status transition matches the configured "complete" status for the board
     * If so, close the AI Developer tmux session
     */
    private function checkCompleteStatusTransition(string $issueKey, string $cloudId, string $newStatus): void {
        $this->logger->debug('checkCompleteStatusTransition: START', [
            'issue_key' => $issueKey,
            'cloud_uid' => $cloudId,
            'new_status' => $newStatus
        ]);

        $memberId = $this->findMemberByCloudId($cloudId);
        $this->logger->debug('checkCompleteStatusTransition: member lookup', ['member_id' => $memberId]);
        if (!$memberId) {
            $this->logger->debug('checkCompleteStatusTransition: No member found for cloud', ['cloud_uid' => $cloudId]);
            return;
        }

        // Extract project key from issue key (e.g., "SSI-1893" -> "SSI")
        $projectKey = explode('-', $issueKey)[0] ?? '';
        if (empty($projectKey)) {
            $this->logger->debug('checkCompleteStatusTransition: Empty project key');
            return;
        }

        // Switch to user database to find the board
        $this->logger->debug('checkCompleteStatusTransition: Switching to user DB', ['member_id' => $memberId]);
        try {
            UserDatabaseService::connect($memberId);
        } catch (\Exception $e) {
            $this->logger->debug('checkCompleteStatusTransition: Failed to get user database', [
                'member_id' => $memberId,
                'error' => $e->getMessage()
            ]);
            return;
        }

        // Find the board for this project in user database
        $this->logger->debug('checkCompleteStatusTransition: Looking for board', ['project_key' => $projectKey]);
        $boards = UserDatabaseService::getBoards();
        $board = $boards[0] ?? null; // First try getting all boards
        // Filter to find the matching project
        foreach ($boards as $b) {
            $bArray = is_object($b) ? (array)$b->export() : $b;
            if (($bArray['project_key'] ?? '') === $projectKey) {
                $board = (object)$bArray;
                break;
            }
        }
        UserDatabaseService::restore();
        if (!$board) {
            $this->logger->debug('checkCompleteStatusTransition: No board found', ['project_key' => $projectKey]);
            return;
        }

        $completeStatus = $board->aidev_status_complete ?? null;
        $this->logger->debug('checkCompleteStatusTransition: Board found', [
            'board_id' => $board->id,
            'complete_status' => $completeStatus
        ]);
        if (empty($completeStatus)) {
            $this->logger->debug('checkCompleteStatusTransition: No complete status configured', ['board_id' => $board->id]);
            return;
        }

        $this->logger->debug('checkCompleteStatusTransition: Comparing statuses', [
            'new_status' => $newStatus,
            'complete_status' => $completeStatus,
            'match' => strcasecmp($newStatus, $completeStatus) === 0
        ]);

        // Check if the new status matches the configured complete status (case-insensitive)
        if (strcasecmp($newStatus, $completeStatus) === 0) {
            $this->logger->info('Ticket transitioned to complete status, closing AI Developer session', [
                'issue_key' => $issueKey,
                'member_id' => $memberId,
                'status' => $newStatus,
                'configured_complete_status' => $completeStatus
            ]);
            // User moving ticket to complete status is the signal to close session
            // /exit will be sent to Claude, which gracefully shuts down
            $this->closeLocalTmuxSession($issueKey, $memberId, $cloudId, 'complete');
        }
    }

    /**
     * Check if a ticket is already at the configured "complete" status
     * Used to prevent race condition where label removal hasn't propagated yet
     */
    private function isTicketAtCompleteStatus(string $issueKey, int $memberId, string $cloudId): bool {
        try {
            // Extract project key from issue key
            $projectKey = explode('-', $issueKey)[0] ?? '';
            if (empty($projectKey)) {
                return false;
            }

            // Get board configuration from user database
            UserDatabaseService::connect($memberId);
            $boards = UserDatabaseService::getBoards();
            UserDatabaseService::restore();
            $board = null;
            foreach ($boards as $b) {
                $bArray = is_object($b) ? (array)$b->export() : $b;
                if (($bArray['project_key'] ?? '') === $projectKey) {
                    $board = (object)$bArray;
                    break;
                }
            }

            if (!$board) {
                return false;
            }

            $completeStatus = $board->aidev_status_complete ?? null;
            if (empty($completeStatus)) {
                return false;
            }

            // Fetch current ticket status from Jira
            $jiraClient = new \app\services\JiraClient($memberId, $cloudId);
            $issue = $jiraClient->getIssue($issueKey);
            $currentStatus = $issue['fields']['status']['name'] ?? '';

            $isComplete = strcasecmp($currentStatus, $completeStatus) === 0;

            $this->logger->debug('isTicketAtCompleteStatus check', [
                'issue_key' => $issueKey,
                'current_status' => $currentStatus,
                'complete_status' => $completeStatus,
                'is_complete' => $isComplete
            ]);

            return $isComplete;

        } catch (\Exception $e) {
            $this->logger->warning('Error checking ticket complete status', [
                'issue_key' => $issueKey,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Download a Jira attachment to the local work directory
     */
    private function downloadJiraAttachment(string $issueKey, int $memberId, string $attachmentUrl, string $filename, string $oauthToken): ?string {
        $workspace = Flight::get('workspace.slug');
        $tmux = new TmuxService($memberId, $issueKey, null, $workspace);
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

        $workspace = Flight::get('workspace.slug');
        $tmux = new TmuxService($memberId, $issueKey, null, $workspace);

        $this->logger->debug('Local session check', [
            'issue_key' => $issueKey,
            'member_id' => $memberId,
            'workspace' => $workspace,
            'has_tmux_session' => $tmux->exists(),
        ]);

        // Only process if there's a local tmux session
        // Note: Claude may run remotely via SSH (job-executor architecture),
        // so we just check if the session exists to send-keys through SSH
        if (!$tmux->exists()) {
            return;
        }

        // Ensure repo is cloned (re-clone if missing after /tmp cleanup)
        $workDir = $tmux->getWorkDir();
        if ($workDir) {
            GitOperations::ensureRepoCloned($workDir, $issueKey, $memberId);
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

        // Skip comments with agent signature (posted by MCP, prevents echo)
        if (preg_match('/\[agent:[^\]]+\]/', $body)) {
            $this->logger->debug('Skipping agent-signed comment', [
                'issue_key' => $issueKey,
                'author' => $author
            ]);
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
                // Jira uses 'toString' or 'to' for new status
                $newStatus = $item['toString'] ?? $item['to'] ?? '';

                // Check for generic "Done" statuses
                $doneStatuses = ['done', 'closed', 'resolved', 'complete', 'completed', 'cancelled'];
                $shouldClose = in_array(strtolower($newStatus), $doneStatuses);

                // Also check board's configured complete status
                if (!$shouldClose) {
                    $projectKey = explode('-', $issueKey)[0] ?? '';
                    $board = Bean::findOne('boards', '(member_id = ? OR is_shared = 1) AND project_key = ?', [$memberId, $projectKey]);
                    if ($board) {
                        $boardCompleteStatus = $board->aidev_status_complete ?? null;
                        if ($boardCompleteStatus && strcasecmp($newStatus, $boardCompleteStatus) === 0) {
                            $shouldClose = true;
                            $this->logger->debug('Board complete status matched', [
                                'issue_key' => $issueKey,
                                'new_status' => $newStatus,
                                'board_complete_status' => $boardCompleteStatus
                            ]);
                        }
                    }
                }

                if ($shouldClose) {
                    // Don't kill if there's an active job running for this issue
                    $activeJob = Bean::findOne('aidevjobs',
                        'issue_key = ? AND status IN (?, ?) ORDER BY created_at DESC',
                        [$issueKey, 'running', 'queued']
                    );
                    if ($activeJob) {
                        $this->logger->info('Status suggests close but job still active, skipping kill', [
                            'issue_key' => $issueKey,
                            'member_id' => $memberId,
                            'status' => $newStatus,
                            'job_status' => $activeJob->status
                        ]);
                    } else {
                        $this->logger->info('Ticket closed, killing local Claude session', [
                            'issue_key' => $issueKey,
                            'member_id' => $memberId,
                            'status' => $newStatus
                        ]);
                        // Use closeLocalTmuxSession to also clean up status file
                        $this->closeLocalTmuxSession($issueKey, $memberId, null, 'complete');
                        return;
                    }
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
     * Augment a running local Claude session with a GitHub comment
     *
     * Similar to Jira's augmentLocalSession, this sends new GitHub issue
     * comments to running tmux sessions so Claude can react to real-time updates.
     */
    private function augmentGitHubLocalSession(string $issueKey, int $memberId, string $author, string $body): void {
        // Skip bot comments (our own comments).
        //
        // NOTE: neither of these catches the agent's own writes on their own. The
        // agent posts with the MEMBER's OAuth token, so the author is a normal user
        // login with no 'bot' in it, and its milestone comments ("AI Developer
        // starting work...") do not contain the string 'MyCTOBot'. The agent
        // signature check below is what actually breaks the echo loop.
        if (strpos(strtolower($author), 'bot') !== false || strpos($body, 'MyCTOBot') !== false) {
            $this->logger->debug('GitHub: Skipping bot comment', ['author' => $author]);
            return;
        }

        // Skip comments carrying the agent signature (same mechanism as Jira).
        // Without this, every comment the agent posts fires issue_comment, gets
        // injected back into its own session, and it responds to itself.
        if (preg_match('/\[agent:[^\]]+\]/', $body)) {
            $this->logger->debug('GitHub: Skipping agent-signed comment', [
                'issue_key' => $issueKey,
                'author' => $author
            ]);
            return;
        }

        // Check if there's a running tmux session for this issue
        $workspace = Flight::get('workspace.slug');
        $tmux = new TmuxService($memberId, $issueKey, null, $workspace);
        if (!$tmux->exists()) {
            $this->logger->debug('GitHub: No running tmux session to augment', [
                'issue_key' => $issueKey,
                'member_id' => $memberId,
                'workspace' => $workspace
            ]);
            return;
        }

        // Ensure repo is cloned (re-clone if missing after /tmp cleanup)
        $workDir = $tmux->getWorkDir();
        if ($workDir) {
            GitOperations::ensureRepoCloned($workDir, $issueKey, $memberId);
        }

        // Backstop against a runaway echo loop. The signature check above only
        // covers comments posted through our own MCP gateway; the agent is also
        // told to use the external github MCP server, whose writes we cannot sign.
        // If that ever loops, this stops it rather than letting the session spin.
        if ($workDir && !$this->allowSessionForward($workDir, $issueKey)) {
            return;
        }

        $this->logger->info('GitHub: Augmenting running session with new comment', [
            'issue_key' => $issueKey,
            'member_id' => $memberId,
            'author' => $author
        ]);

        // Pull attachments BEFORE truncating - an image dropped at the end of a
        // long comment would otherwise have its URL cut off.
        $attachmentNote = $this->pullCommentAttachments($body, $memberId, (string) $workDir);

        // Truncate very long comments
        if (strlen($body) > 2000) {
            $body = substr($body, 0, 2000) . '... [truncated]';
        }

        // Build message for Claude
        $message = "[GITHUB UPDATE] New comment from @{$author}: {$body}";

        if ($attachmentNote !== '') {
            $message .= "\n" . $attachmentNote;
        }

        // Send to session
        if ($tmux->sendMessage($message)) {
            $this->logger->info('Sent GitHub comment to local Claude session', [
                'issue_key' => $issueKey,
                'member_id' => $memberId,
                'author' => $author
            ]);
        } else {
            $this->logger->warning('Failed to send GitHub comment to session', [
                'issue_key' => $issueKey,
                'member_id' => $memberId
            ]);
        }
    }

    /**
     * Rate-limit how many webhook comments may be pushed into one session.
     *
     * An echo loop (agent comments -> webhook -> agent reads its own comment ->
     * comments again) is self-sustaining and burns tokens until someone notices.
     * Anything above this rate is not a human talking to the agent.
     *
     * State lives in the per-job work directory, so it is per-tenant by
     * construction and disappears with the job.
     *
     * @param string $workDir  Session work directory
     * @param string $issueKey For logging
     * @return bool True if the forward is allowed
     */
    private function allowSessionForward(string $workDir, string $issueKey): bool {
        $maxForwards = 12;   // per window
        $windowSecs  = 300;  // 5 minutes

        $file = rtrim($workDir, '/') . '/.comment-forwards.json';
        $now = time();

        $stamps = [];
        if (is_file($file)) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (is_array($decoded)) {
                $stamps = array_filter($decoded, fn($t) => is_numeric($t) && ($now - (int) $t) < $windowSecs);
            }
        }

        if (count($stamps) >= $maxForwards) {
            $this->logger->warning('GitHub: comment forward rate limit hit - suppressing possible echo loop', [
                'issue_key' => $issueKey,
                'forwards_in_window' => count($stamps),
                'window_seconds' => $windowSecs
            ]);
            return false;
        }

        $stamps[] = $now;
        @file_put_contents($file, json_encode(array_values($stamps)));
        @chmod($file, 0600);

        return true;
    }

    /**
     * Download any attachments referenced in a webhook comment.
     *
     * Comments arrive as raw text, so an image someone drops into the thread
     * mid-run reaches the agent as a bare github.com/user-attachments URL that
     * 404s without credentials - the same failure the dispatcher already fixes
     * for the initial issue body.
     *
     * @return string Prompt fragment describing downloaded files (may be empty)
     */
    private function pullCommentAttachments(string $body, int $memberId, string $workDir): string {
        if ($workDir === '' || !is_dir($workDir)) {
            return '';
        }

        try {
            require_once __DIR__ . '/../services/JobAttachmentService.php';

            // The tenant's own GitHub credential - same lookup the dispatcher uses.
            $conn = Bean::findOne(
                'connections',
                'connector_type = ? AND (member_id = ? OR shared = 1) ORDER BY id ASC',
                ['github', $memberId]
            );

            if (!$conn || empty($conn->access_token)) {
                return '';
            }

            $token = EncryptionService::decrypt($conn->access_token);
            if (empty($token)) {
                return '';
            }

            $result = \app\services\JobAttachmentService::pullGitHub($body, $token, $workDir);

            if (empty($result['stored']) && empty($result['failed'])) {
                return '';
            }

            $this->logger->info('GitHub: pulled attachments from comment', [
                'stored' => count($result['stored']),
                'failed' => count($result['failed'])
            ]);

            return \app\services\JobAttachmentService::formatForPrompt($result, 'New GitHub');

        } catch (\Throwable $e) {
            // Never let an attachment failure stop the comment reaching the agent.
            $this->logger->warning('GitHub: comment attachment download failed', [
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }

    /**
     * Find linked issue number from PR body
     *
     * Parses common patterns like:
     * - Fixes #123
     * - Closes #123
     * - Resolves #123
     * - Related to #123
     * - References #123
     *
     * @param string $prBody The PR description body
     * @param string $repoFullName The full repo name (owner/repo)
     * @return string|null The linked issue key (owner/repo#123) or null
     */
    private function findLinkedIssueFromPrBody(string $prBody, string $repoFullName): ?string {
        // Common patterns for linking issues
        // Matches: Fixes #123, Closes #123, Resolves #123, Related to #123, etc.
        $patterns = [
            '/(?:fix(?:es|ed)?|close[sd]?|resolve[sd]?|related\s+to|references?|refs?)\s*#(\d+)/i',
            '/(?:fix(?:es|ed)?|close[sd]?|resolve[sd]?)\s*:\s*#(\d+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prBody, $matches)) {
                $issueNumber = $matches[1];
                return "{$repoFullName}#{$issueNumber}";
            }
        }

        // Also check for explicit issue URLs
        // e.g., https://github.com/owner/repo/issues/123
        if (preg_match('/github\.com\/([^\/]+\/[^\/]+)\/issues\/(\d+)/', $prBody, $matches)) {
            $linkedRepo = $matches[1];
            $issueNumber = $matches[2];
            // Only link if same repo
            if ($linkedRepo === $repoFullName) {
                return "{$repoFullName}#{$issueNumber}";
            }
        }

        return null;
    }

    /**
     * Handle GitHub webhook
     * Endpoint: POST /webhook/github
     *
     * Validates webhook signature against repo-specific secret,
     * then processes issues/comments to trigger AI Dev jobs.
     */
    public function github() {
        $payload = file_get_contents('php://input');

        if (empty($payload)) {
            $this->logger->warning('GitHub webhook: empty payload');
            Flight::response()->status(400);
            echo json_encode(['error' => 'Empty payload']);
            return;
        }

        $event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
        $data = json_decode($payload, true);

        if (!$data) {
            $this->logger->warning('GitHub webhook: invalid JSON');
            Flight::response()->status(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }

        // Check for workspace query parameter (workspace identifier)
        $workspace = $_GET['workspace'] ?? null;
        if ($workspace && $workspace !== 'default') {
            if (!$this->switchToworkspaceForWebhook($workspace)) {
                $this->logger->warning('GitHub webhook: invalid workspace', ['workspace' => $workspace]);
                Flight::response()->status(400);
                echo json_encode(['error' => "Invalid workspace: {$workspace}"]);
                return;
            }
            $this->logger->info("GitHub webhook using workspace: {$workspace}");
        }

        // Get repo full name from payload to look up workspace and secret
        $repoFullName = $data['repository']['full_name'] ?? '';
        if (empty($repoFullName)) {
            $this->logger->warning('GitHub webhook: no repository in payload');
            Flight::response()->status(400);
            echo json_encode(['error' => 'Missing repository']);
            return;
        }

        // Find the repo connection (if workspace was set, searches that workspace's DB first)
        $repoConnection = $this->findGitHubRepoConnection($repoFullName, $workspace);
        if (!$repoConnection) {
            $this->logger->debug('GitHub webhook: repo not connected', ['repo' => $repoFullName]);
            // Return 200 so GitHub doesn't keep retrying
            echo json_encode(['success' => true, 'message' => 'Repo not connected']);
            return;
        }

        // SECURITY (HIGH-4): verify unconditionally and fail closed. Legacy repo
        // connections may have an empty webhook_secret — reject those rather than
        // processing forged push/PR events.
        $secret = $repoConnection['webhook_secret'] ?? '';
        $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        if (empty($secret)) {
            $this->logger->error('GitHub webhook rejected: no webhook_secret on repo connection (fail-closed)', ['repo' => $repoFullName]);
            Flight::response()->status(401);
            echo json_encode(['error' => 'Webhook signing secret not configured']);
            return;
        }
        if (!$this->validateGitHubSignature($payload, $signature, $secret)) {
            $this->logger->warning('GitHub webhook: invalid signature', ['repo' => $repoFullName]);
            Flight::response()->status(401);
            echo json_encode(['error' => 'Invalid signature']);
            return;
        }

        $this->logger->info('GitHub webhook received', [
            'event' => $event,
            'repo' => $repoFullName,
            'workspace' => $repoConnection['workspace'] ?? 'unknown'
        ]);

        try {
            $this->processGitHubWebhook($event, $data, $repoConnection);
            echo json_encode(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('GitHub webhook processing failed', ['error' => $e->getMessage()]);
            Flight::response()->status(500);
            echo json_encode(['error' => 'Processing failed']);
        }
    }

    /**
     * Find GitHub repo connection across all workspaces
     * Returns repo data with workspace info if found
     *
     * @param string $fullName Repository full name (owner/repo)
     * @param string|null $workspace If provided, search this workspace first (already switched)
     */
    private function findGitHubRepoConnection(string $fullName, ?string $workspace = null): ?array {
        // Split full_name into owner/name
        $parts = explode('/', $fullName, 2);
        if (count($parts) !== 2) {
            return null;
        }
        list($repoOwner, $repoName) = $parts;

        // If workspace is provided, we already switched to that workspace's database
        // Search it first before falling back to all workspaces
        if ($workspace && $workspace !== 'default') {
            $repo = Bean::findOne('repoconnections', 'repo_owner = ? AND repo_name = ? AND provider = ?', [$repoOwner, $repoName, 'github']);
            if ($repo) {
                $repoData = $repo->export();
                $repoData['workspace'] = $workspace;
                // Use member_id from repo connection if stored, otherwise fall back to workspace lookup
                if (empty($repoData['member_id'])) {
                    $repoData['member_id'] = $this->findMemberIdForworkspace($workspace);
                }
                Flight::set('workspace.slug', $workspace);
                Flight::set('workspace.active', true);
                return $repoData;
            }
            // Not found in specified workspace, fall through to search all workspaces
        }

        // Search all workspace configs for this repo
        $confDir = __DIR__ . '/../conf';
        $configs = glob($confDir . '/config.*.ini');

        foreach ($configs as $configFile) {
            // Extract workspace slug from filename (config.{workspace}.ini)
            if (!preg_match('/config\.([^.]+)\.ini$/', $configFile, $matches)) {
                continue;
            }
            $workspace = $matches[1];

            // Skip example config
            if ($workspace === 'example') {
                continue;
            }

            $workspaceConfig = parse_ini_file($configFile, true);
            if (!$workspaceConfig || empty($workspaceConfig['database'])) {
                continue;
            }

            // Connect to workspace database
            try {
                $dbConfig = $workspaceConfig['database'];
                $type = $dbConfig['type'] ?? 'mysql';
                $workspaceDbKey = "github_webhook_{$workspace}";

                if ($type === 'sqlite') {
                    $dbPath = $dbConfig['path'] ?? "database/{$workspace}.sqlite";
                    if (!file_exists($dbPath)) continue;
                    $dsn = "sqlite:{$dbPath}";
                    Bean::useDatabase($workspaceDbKey, $dsn);
                } else {
                    $host = $dbConfig['host'] ?? 'localhost';
                    $port = $dbConfig['port'] ?? 3306;
                    $name = $dbConfig['name'] ?? $workspace;
                    $user = $dbConfig['user'] ?? 'root';
                    $pass = $dbConfig['pass'] ?? '';
                    $dsn = "{$type}:host={$host};port={$port};dbname={$name}";
                    Bean::useDatabase($workspaceDbKey, $dsn, $user, $pass);
                }

                // Look for repo connection (repoOwner/repoName already parsed at top of method)
                $repo = Bean::findOne('repoconnections', 'repo_owner = ? AND repo_name = ? AND provider = ?', [$repoOwner, $repoName, 'github']);
                if ($repo) {
                    $repoData = $repo->export();
                    $repoData['workspace'] = $workspace;
                    $repoData['workspace_config'] = $workspaceConfig;

                    // Use member_id from repo connection if stored, otherwise fall back to workspace lookup
                    if (empty($repoData['member_id'])) {
                        $repoData['member_id'] = $this->findMemberIdForworkspace($workspace);
                    }

                    // Keep this database selected for subsequent operations
                    Flight::set('workspace.slug', $workspace);
                    Flight::set('workspace.active', true);

                    return $repoData;
                }

                // Clean up - remove this database connection
                Bean::close();

            } catch (\Exception $e) {
                $this->logger->debug('Error checking workspace for repo', [
                    'workspace' => $workspace,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return null;
    }

    /**
     * Find member ID for a workspace
     */
    private function findMemberIdForworkspace(string $workspace): ?int {
        // Switch back to default database
        Bean::selectDatabase('default');

        // Look up member by workspace slug
        $member = Bean::findOne('member', 'workspace = ?', [$workspace]);
        if ($member) {
            return (int)$member->id;
        }

        return null;
    }

    /**
     * Process GitHub webhook data
     */
    private function processGitHubWebhook(string $event, array $data, array $repoConnection): void {
        switch ($event) {
            case 'issues':
                $this->handleGitHubIssuesEvent($data, $repoConnection);
                break;

            case 'issue_comment':
                $this->handleGitHubIssueCommentEvent($data, $repoConnection);
                break;

            case 'pull_request':
                $this->handlePullRequestEvent($data);
                break;

            case 'pull_request_review':
                $this->handlePullRequestReviewEvent($data);
                break;

            case 'check_run':
                $this->handleCheckRunEvent($data);
                break;

            case 'ping':
                // GitHub sends ping when webhook is created or tested
                $this->logger->info('GitHub webhook: ping received', [
                    'zen' => $data['zen'] ?? '',
                    'hook_id' => $data['hook_id'] ?? ''
                ]);
                break;

            default:
                $this->logger->debug('GitHub webhook: unhandled event', ['event' => $event]);
        }
    }

    /**
     * Handle GitHub issues event (labeled, opened, etc.)
     */
    private function handleGitHubIssuesEvent(array $data, array $repoConnection): void {
        // Check if issue tracking is enabled for this repo
        // If not set, default to false (GitHub used only for code hosting)
        $issuesEnabled = ($repoConnection['issues_enabled'] ?? 0) == 1;
        if (!$issuesEnabled) {
            $this->logger->debug('GitHub issues event ignored - issue tracking disabled for repo', [
                'repo' => $data['repository']['full_name'] ?? ''
            ]);
            return;
        }

        $action = $data['action'] ?? '';
        $issue = $data['issue'] ?? [];
        $issueNumber = $issue['number'] ?? 0;
        $issueTitle = $issue['title'] ?? '';
        $issueBody = $issue['body'] ?? '';
        $repoFullName = $data['repository']['full_name'] ?? '';
        $labels = $issue['labels'] ?? [];

        $this->logger->debug('GitHub issues event', [
            'action' => $action,
            'issue_number' => $issueNumber,
            'repo' => $repoFullName
        ]);

        // Check if ai-dev label is present
        $hasAiDevLabel = false;
        foreach ($labels as $label) {
            if (($label['name'] ?? '') === 'ai-dev') {
                $hasAiDevLabel = true;
                break;
            }
        }

        // Handle different actions
        switch ($action) {
            case 'labeled':
                $labelAdded = $data['label']['name'] ?? '';
                if ($labelAdded === 'ai-dev') {
                    $this->logger->info('GitHub: ai-dev label added', [
                        'issue' => $issueNumber,
                        'repo' => $repoFullName
                    ]);
                    $this->triggerGitHubAIDevJob($data, $repoConnection);
                }
                break;

            case 'unlabeled':
                $labelRemoved = $data['label']['name'] ?? '';
                if ($labelRemoved === 'ai-dev') {
                    $this->logger->info('GitHub: ai-dev label removed, killing session', [
                        'issue' => $issueNumber,
                        'repo' => $repoFullName
                    ]);
                    $memberId = $repoConnection['member_id'] ?? null;
                    if ($memberId) {
                        $issueKey = "{$repoFullName}#{$issueNumber}";
                        $this->closeLocalTmuxSession($issueKey, $memberId, null, 'label_removed');
                    }
                }
                break;

            case 'opened':
                // If issue is opened with ai-dev label already present, trigger job
                if ($hasAiDevLabel) {
                    $this->logger->info('GitHub: issue opened with ai-dev label', [
                        'issue' => $issueNumber,
                        'repo' => $repoFullName
                    ]);
                    $this->triggerGitHubAIDevJob($data, $repoConnection);
                }
                break;

            case 'closed':
                // Cleanup when issue is closed - kill the tmux session
                $this->logger->info('GitHub: issue closed, killing session', [
                    'issue' => $issueNumber,
                    'repo' => $repoFullName,
                    'had_ai_dev_label' => $hasAiDevLabel
                ]);
                $memberId = $repoConnection['member_id'] ?? null;
                if ($memberId) {
                    $issueKey = "{$repoFullName}#{$issueNumber}";
                    $this->closeLocalTmuxSession($issueKey, $memberId, null, 'issue_closed');
                }
                break;
        }
    }

    /**
     * Handle GitHub issue_comment event
     */
    private function handleGitHubIssueCommentEvent(array $data, array $repoConnection): void {
        // Check if issue tracking is enabled for this repo
        $issuesEnabled = ($repoConnection['issues_enabled'] ?? 0) == 1;
        if (!$issuesEnabled) {
            return;
        }

        $action = $data['action'] ?? '';
        if ($action !== 'created') {
            return; // Only handle new comments
        }

        $issue = $data['issue'] ?? [];
        $comment = $data['comment'] ?? [];
        $issueNumber = $issue['number'] ?? 0;
        $commentId = $comment['id'] ?? '';
        $commentBody = $comment['body'] ?? '';
        $commentAuthor = $comment['user']['login'] ?? '';
        $repoFullName = $data['repository']['full_name'] ?? '';
        $memberId = $repoConnection['member_id'] ?? null;

        // Create issue key format
        $issueKey = "{$repoFullName}#{$issueNumber}";

        // Check if this is a comment on a PR (PRs have pull_request field)
        $isPullRequest = isset($issue['pull_request']);
        $linkedIssueKey = null;

        if ($isPullRequest && $memberId) {
            // Try to find the linked issue from PR body
            $prBody = $issue['body'] ?? '';
            $linkedIssueKey = $this->findLinkedIssueFromPrBody($prBody, $repoFullName);

            if ($linkedIssueKey) {
                $this->logger->info('GitHub: PR comment forwarding to linked issue', [
                    'pr_number' => $issueNumber,
                    'linked_issue' => $linkedIssueKey,
                    'repo' => $repoFullName
                ]);
                // Forward to the linked issue's session instead
                $this->augmentGitHubLocalSession($linkedIssueKey, $memberId, $commentAuthor, "[PR #{$issueNumber} comment] {$commentBody}");
                return; // Don't process further - this is a PR comment, not an issue
            }
        }

        // First, try to augment any running local tmux session with this comment
        if ($memberId) {
            $this->augmentGitHubLocalSession($issueKey, $memberId, $commentAuthor, $commentBody);
        }

        // Check if issue has ai-dev label
        $hasAiDevLabel = false;
        foreach (($issue['labels'] ?? []) as $label) {
            if (($label['name'] ?? '') === 'ai-dev') {
                $hasAiDevLabel = true;
                break;
            }
        }

        if (!$hasAiDevLabel) {
            return;
        }

        $this->logger->info('GitHub: comment on ai-dev issue', [
            'issue' => $issueNumber,
            'repo' => $repoFullName,
            'author' => $commentAuthor
        ]);

        if (!$memberId) {
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
        $botCommentId = $job->clarificationCommentId ?? '';
        if ($commentId === $botCommentId) {
            return;
        }

        $this->logger->info('GitHub: Clarification response detected', [
            'issue_key' => $issueKey,
            'comment_id' => $commentId
        ]);

        // Resume the job
        $this->resumeGitHubJob($memberId, $issueKey, $commentId);
    }

    /**
     * Resume a GitHub job after clarification
     */
    private function resumeGitHubJob(int $memberId, string $issueKey, string $answerCommentId): void {
        $cronSecret = Flight::get('cron.api_key');
        $scriptPath = __DIR__ . '/../scripts/ai-dev-agent.php';

        $cmd = sprintf(
            'php %s --secret=%s --member=%d --issue=%s --action=resume --comment=%s --provider=github > /dev/null 2>&1 &',
            escapeshellarg($scriptPath),
            escapeshellarg($cronSecret),
            $memberId,
            escapeshellarg($issueKey),
            escapeshellarg($answerCommentId)
        );

        exec($cmd);

        $this->logger->info('GitHub job resume triggered', [
            'member_id' => $memberId,
            'issue_key' => $issueKey,
            'comment_id' => $answerCommentId
        ]);
    }

    /**
     * Trigger AI Dev job for GitHub issue
     */
    private function triggerGitHubAIDevJob(array $data, array $repoConnection): void {
        $issue = $data['issue'] ?? [];
        $issueNumber = $issue['number'] ?? 0;
        $issueTitle = $issue['title'] ?? '';
        $issueBody = $issue['body'] ?? '';
        $repoFullName = $data['repository']['full_name'] ?? '';
        $memberId = $repoConnection['member_id'] ?? null;
        $repoId = $repoConnection['id'] ?? null;
        $workspace = $repoConnection['workspace'] ?? null;

        if (!$memberId) {
            $this->logger->warning('Cannot trigger GitHub AI Dev: no member_id', [
                'repo' => $repoFullName
            ]);
            return;
        }

        if (!$repoId) {
            $this->logger->warning('Cannot trigger GitHub AI Dev: no repo_id', [
                'repo' => $repoFullName
            ]);
            return;
        }

        // Create issue key in format: owner/repo#123
        $issueKey = "{$repoFullName}#{$issueNumber}";

        // Use AIDevJobService to trigger the job
        $jobService = new AIDevJobService();
        $result = $jobService->triggerGitHubJob($memberId, $issueKey, $repoId, $workspace);

        if ($result['success']) {
            // Check if session was already running (no new job_uid)
            if ($result['already_running'] ?? false) {
                $this->logger->info('GitHub AI Developer session already running for issue', [
                    'member_id' => $memberId,
                    'issue_key' => $issueKey,
                    'session_id' => $result['session_id'] ?? null
                ]);
                return; // No further action needed
            }

            $this->logger->info('GitHub AI Dev job triggered via webhook', [
                'member_id' => $memberId,
                'job_uid' => $result['job_uid'] ?? null,
                'issue_key' => $issueKey,
                'repo_id' => $repoId,
                'local' => $result['local'] ?? false
            ]);

            // Add a working label to the issue
            $this->addGitHubWorkingLabel($repoConnection, $repoFullName, $issueNumber);
        } else {
            $this->logger->warning('Failed to trigger GitHub AI Dev job via webhook', [
                'member_id' => $memberId,
                'issue_key' => $issueKey,
                'repo_id' => $repoId,
                'error' => $result['error'] ?? 'Unknown error'
            ]);
        }
    }

    /**
     * Add working label to GitHub issue
     */
    private function addGitHubWorkingLabel(array $repoConnection, string $repoFullName, int $issueNumber): void {
        try {
            // Get GitHub token from unified connections table
            $conn = Bean::findOne('connections', 'connector_type = ? ORDER BY id ASC', ['github']);
            if (!$conn || empty($conn->access_token)) {
                return;
            }

            $token = EncryptionService::decrypt($conn->access_token);
            $github = new \app\services\GitHubClient($token);

            [$owner, $repo] = explode('/', $repoFullName);

            // Try to add myctobot-working label
            try {
                $github->addLabels($owner, $repo, $issueNumber, ['myctobot-working']);
            } catch (\Exception $e) {
                // Label might not exist, try to create it first
                try {
                    $github->createLabel($owner, $repo, 'myctobot-working', 'fbca04', 'MyCTOBot is working on this issue');
                    $github->addLabels($owner, $repo, $issueNumber, ['myctobot-working']);
                } catch (\Exception $e2) {
                    $this->logger->debug('Could not add working label', ['error' => $e2->getMessage()]);
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug('Failed to add working label to GitHub issue', ['error' => $e->getMessage()]);
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
     * Check if ticket has the ai-dev label
     * Returns true if 'ai-dev' label is present
     */
    private function hasAiDevLabel(array $labels): bool {
        foreach ($labels as $label) {
            $labelName = is_string($label) ? $label : ($label['name'] ?? '');
            if ($labelName === 'ai-dev') {
                return true;
            }
        }
        return false;
    }

    /**
     * Find ai-dev label in list
     * Returns 'ai-dev' or null
     */
    private function findAiDevLabel(array $labels): ?string {
        foreach ($labels as $label) {
            $labelName = is_string($label) ? $label : ($label['name'] ?? '');
            if ($labelName === 'ai-dev') {
                return $labelName;
            }
        }
        return null;
    }

    /**
     * Check if a label is ai-dev
     */
    private function isAiDevLabel(string $label): bool {
        return $label === 'ai-dev';
    }

    /**
     * Find repo from labels - supports both repo-{id} (numeric) and repo-{slug} (alphanumeric)
     * Returns the repo ID or null if not found
     *
     * Priority: First checks for numeric ID match, then slug match
     */
    private function findRepoIdFromLabels(array $labels): ?int {
        foreach ($labels as $label) {
            $labelName = is_string($label) ? $label : ($label['name'] ?? '');

            // First try: numeric ID (legacy format: repo-123)
            if (preg_match('/^repo-(\d+)$/', $labelName, $matches)) {
                return (int) $matches[1];
            }

            // Second try: slug (new format: repo-my-slug)
            if (preg_match('/^repo-([a-z0-9][a-z0-9-]*[a-z0-9]|[a-z0-9])$/', $labelName, $matches)) {
                $slug = $matches[1];
                $repo = Bean::findOne('repoconnections', 'slug = ?', [$slug]);
                if ($repo && $repo->id) {
                    return (int) $repo->id;
                }
            }
        }
        return null;
    }

    /**
     * Extract repo ID from labels via repo-{id} or repo-{slug} label
     */
    private function extractRepoIdFromLabels(array $labels): ?int {
        return $this->findRepoIdFromLabels($labels);
    }

    /**
     * @deprecated Use extractRepoIdFromLabels instead
     */
    private function extractRepoIdFromLabel(string $label): ?int {
        if (preg_match('/^ai-dev-(\d+)$/', $label, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }

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
        // e.g., https://yourcompany.atlassian.net/rest/api/3/issue/12345
        if (preg_match('#https://([^/]+\.atlassian\.net)/#', $selfUrl, $matches)) {
            $siteDomain = $matches[1];
            $siteUrl = 'https://' . $siteDomain;

            // Look up the cloud ID from our stored connections
            $token = Bean::findOne('connections', 'connector_type = ? AND external_url = ?', ['atlassian', $siteUrl]);
            if ($token) {
                return $token->external_eid;
            }

            // Also try without https
            $token = Bean::findOne('connections', 'connector_type = ? AND external_url LIKE ?', ['atlassian', '%' . $siteDomain . '%']);
            if ($token) {
                return $token->external_eid;
            }
        }

        return '';
    }

    /**
     * Find cloud ID for a given issue key and member
     */
    private function findCloudIdForIssue(string $issueKey, int $memberId): ?string {
        // Get the project key from the issue key
        $projectKey = explode('-', $issueKey)[0] ?? '';

        // Find the board for this project to get the cloud_uid (shared boards available to all)
        $board = Bean::findOne('boards', '(member_id = ? OR is_shared = 1) AND project_key = ?', [$memberId, $projectKey]);
        if ($board && !empty($board->cloud_uid)) {
            return $board->cloud_uid;
        }

        // Fall back to first connection for member (or shared)
        $token = Bean::findOne('connections', 'connector_type = ? AND (member_id = ? OR shared = 1)', ['atlassian', $memberId]);
        if ($token) {
            return $token->external_eid;
        }

        return null;
    }

    /**
     * Find member ID by Atlassian cloud ID
     */
    private function findMemberByCloudId(string $cloudId): ?int {
        $token = Bean::findOne('connections', 'connector_type = ? AND external_eid = ?', ['atlassian', $cloudId]);
        return $token ? (int)$token->member_id : null;
    }

    /**
     * Handle runner digest callback
     * Endpoint: POST /webhook/digest
     *
     * Called by runner when digest analysis is complete
     * Body: { job_uid, status, result: { analysis, markdown_report }, error }
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

        $jobId = $data['job_uid'] ?? '';
        $type = $data['type'] ?? 'final'; // 'progress' or 'final'
        $status = $data['status'] ?? '';
        $result = $data['result'] ?? null;
        $error = $data['error'] ?? null;
        $itemsCount = $data['items_count'] ?? 0;
        $phase = $data['phase'] ?? 'running';
        $elapsedSeconds = $data['elapsed_seconds'] ?? 0;
        $partialOutput = $data['partial_output'] ?? null;

        $this->logger->info('Digest webhook received', [
            'job_uid' => $jobId,
            'type' => $type,
            'items_count' => $itemsCount,
            'status' => $status,
            'has_result' => !empty($result)
        ]);

        if (empty($jobId)) {
            Flight::response()->status(400);
            echo json_encode(['error' => 'Missing job_uid']);
            return;
        }

        try {
            // Look up the pending digest job
            $digestJob = Bean::findOne('digestjobs', 'job_uid = ?', [$jobId]);

            if (!$digestJob) {
                $this->logger->warning('Digest job not found', ['job_uid' => $jobId]);
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

                Bean::store($digestJob);

                $this->logger->debug('Digest progress update', [
                    'job_uid' => $jobId,
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
                        'job_uid' => $jobId,
                        'items_count' => $itemsCount
                    ]);
                }

                $this->logger->error('Digest analysis failed', [
                    'job_uid' => $jobId,
                    'error' => $error,
                    'items_count' => $itemsCount
                ]);
            }

            $digestJob->result = json_encode($result);
            Bean::store($digestJob);

            echo json_encode(['success' => true, 'items_count' => $itemsCount]);

        } catch (\Exception $e) {
            $this->logger->error('Digest webhook processing failed', [
                'job_uid' => $jobId,
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
        $boardId = (int) $digestJob->boards_id;

        // Get markdown report from result
        $markdown = $result['markdown_report'] ?? '';
        $analysis = $result['analysis'] ?? [];

        if (empty($markdown) && !empty($result['raw_output'])) {
            // Fallback to raw output if no markdown
            $markdown = "# Sprint Digest\n\n" . $result['raw_output'];
        }

        // Store the analysis
        UserDatabaseService::connect($memberId);
        $analysisId = UserDatabaseService::storeAnalysis($boardId, 'digest', [
            'success' => true,
            'analysis' => $analysis,
            'runner_job_uid' => $digestJob->job_uid
        ], $markdown);

        // Update last_digest_at for the board
        UserDatabaseService::updateBoard($boardId, ['last_digest_at' => date('Y-m-d H:i:s')]);
        UserDatabaseService::restore();

        $this->logger->info('Digest analysis stored', [
            'member_id' => $memberId,
            'board_id' => $boardId,
            'analysis_id' => $analysisId
        ]);

        // Send email if requested
        if ($digestJob->send_email) {
            $this->sendDigestEmail($digestJob, $markdown);
        }
    }

    /**
     * Handle runner AI Developer callback
     * Endpoint: POST /webhook/aidev
     *
     * Called by runner when AI Developer work is complete
     * Body: { job_uid, status, result: { success, pr_url, pr_number, ... }, error }
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

        $jobId = $data['job_uid'] ?? '';
        $status = $data['status'] ?? '';
        $result = $data['result'] ?? [];
        $elapsedSeconds = $data['elapsed_seconds'] ?? 0;

        $this->logger->info('AIdev webhook received', [
            'job_uid' => $jobId,
            'status' => $status,
            'has_result' => !empty($result)
        ]);

        if (empty($jobId)) {
            Flight::response()->status(400);
            echo json_encode(['error' => 'Missing job_uid']);
            return;
        }

        try {
            // Find the job by searching all member directories
            $jobData = $this->findAIDevJob($jobId);

            if (!$jobData) {
                $this->logger->warning('AIdev job not found', ['job_uid' => $jobId]);
                Flight::response()->status(404);
                echo json_encode(['error' => 'Job not found']);
                return;
            }

            $memberId = $jobData['member_id'];

            $cloudId = $jobData['cloud_uid'] ?? '';
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
                    'job_uid' => $jobId,
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
                $themeId = (int)($result['shopify_themeid'] ?? 0);
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
                    'job_uid' => $jobId,
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

            } elseif ($status === 'checkpoint') {
                // Checkpoint - Claude completed initial work but session stays alive
                // Don't remove labels or transition status - just log it
                $this->logger->info('AIdev job checkpoint', [
                    'job_uid' => $jobId,
                    'issue_key' => $issueKey,
                    'pr_url' => $result['pr_url'] ?? null,
                    'summary' => $result['summary'] ?? null
                ]);

                // Update job manager with PR info if present
                if (!empty($result['pr_url'])) {
                    $jobManager->complete(
                        $issueKey,
                        $result['pr_url'],
                        $result['pr_number'] ?? null,
                        $result['branch_name'] ?? null,
                        $result['raw_output'] ?? null,
                        $result
                    );
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
                    'job_uid' => $jobId,
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
                'job_uid' => $jobId,
                'error' => $e->getMessage()
            ]);
            Flight::response()->status(500);
            echo json_encode(['error' => 'Processing failed']);
        }
    }

    /**
     * Find AI Dev job by runner job ID across all members
     * Searches JSON status files first (primary source), then database as fallback
     */
    private function findAIDevJob(string $runnerJobId): ?array {
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
                        if ($data && ($data['job_uid'] ?? '') === $runnerJobId) {
                            return [
                                'member_id' => $memberId,
                                'job_uid' => $runnerJobId,
                                'issue_key' => $data['issue_key'] ?? '',
                                'cloud_uid' => $data['cloud_uid'] ?? '',
                                'board_id' => (int) ($data['board_id'] ?? 0),
                                'status' => $data['status'] ?? '',
                                'repo_connection_id' => $data['repo_connection_id'] ?? null
                            ];
                        }
                    }
                }
            }
        }

        // Fallback: search database for jobs with matching current_runner_job_uid.
        // Also match job_uid - the workstation/SSH dispatch path exports the job_uid
        // as MYCTOBOT_JOB_ID and never calls startRun(), so current_runner_job_uid
        // is empty for those jobs and the checkpoint would 404.
        $job = Bean::findOne('aidevjobs', 'current_runner_job_uid = ?', [$runnerJobId]);

        if (!$job) {
            $job = Bean::findOne('aidevjobs', 'job_uid = ?', [$runnerJobId]);
        }

        if ($job) {
            return [
                'member_id' => (int) $job->member_id,
                'job_uid' => $runnerJobId,
                'issue_key' => $job->issue_key,
                'cloud_uid' => $job->cloud_uid,
                'board_id' => (int) $job->boards_id,
                'status' => $job->status,
                'repo_connection_id' => $job->repoconnections_id
            ];
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
            // Store in enterprisesettings table (workspace database should already be selected)
            $setting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['credit_balance_error']);
            if (!$setting) {
                $setting = Bean::dispense('enterprisesettings');
                $setting->setting_key = 'credit_balance_error';
                $setting->is_encrypted = 0;
                $setting->is_shared = 0;  // Credit errors are member-specific
            }
            $setting->setting_value = $errorMsg;
            $setting->updated_at = date('Y-m-d H:i:s');
            Bean::store($setting);

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
            'job_uid' => $jobId,
            'elapsed_seconds' => $elapsed,
            'result' => $result,
            'received_at' => date('Y-m-d H:i:s')
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Send digest email
     */
    private function sendDigestEmail($digestJob, string $markdown): void {
        $member = Bean::load('member', $digestJob->member_id);
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
