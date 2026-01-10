<?php
/**
 * Jobs Controller
 * Handles AI Developer job management
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \Exception as Exception;
use \app\Bean;
use \app\services\EncryptionService;
use \app\services\AIDevJobManager;
use \app\services\ShardService;
use \app\services\ShardRouter;

require_once __DIR__ . '/../services/EncryptionService.php';
require_once __DIR__ . '/../services/AIDevJobManager.php';
require_once __DIR__ . '/../services/ShardService.php';
require_once __DIR__ . '/../services/ShardRouter.php';
require_once __DIR__ . '/../lib/Bean.php';

class Jobs extends BaseControls\Control {

    /**
     * List AI Developer jobs
     */
    public function index() {
        if (!$this->requireLogin()) return;

        require_once __DIR__ . '/../services/AIDevStatusService.php';
        $jobs = \app\services\AIDevStatusService::getAllJobs($this->member->id, 50);
        $activeJobs = \app\services\AIDevStatusService::getActiveJobs($this->member->id);

        $this->render('jobs/index', [
            'title' => 'AI Developer Jobs',
            'jobs' => $jobs,
            'activeJobs' => $activeJobs
        ]);
    }

    /**
     * View a single AI Developer job detail
     */
    public function view($params = []) {
        if (!$this->requireLogin()) return;

        $issueKey = $params['operation']->name ?? '';
        if (empty($issueKey)) {
            $this->flash('error', 'Issue key required');
            Flight::redirect('/jobs');
            return;
        }

        $jobManager = new AIDevJobManager($this->member->id);
        $job = $jobManager->get($issueKey);

        if (!$job) {
            $this->flash('error', 'Job not found');
            Flight::redirect('/jobs');
            return;
        }

        // Format job data for the view
        $jobData = $jobManager->formatJob($job);

        // Get job logs
        $logs = $jobManager->getLogs($issueKey);

        $this->render('jobs/view', [
            'title' => 'Job: ' . $issueKey,
            'job' => $jobData,
            'logs' => $logs
        ]);
    }

    /**
     * Start a new AI Developer job
     */
    public function start($params = []) {
        return $this->startsharded($params);
    }

    /**
     * Start a new AI Developer job on a shard (Claude Code CLI)
     */
    public function startsharded($params = []) {
        if (!$this->requireLogin()) return;

        if (Flight::request()->method !== 'POST') {
            $this->json(['success' => false, 'error' => 'POST required']);
            return;
        }

        $issueKey = Flight::request()->data->issue_key ?? '';
        $boardId = (int)(Flight::request()->data->board_id ?? 0);
        $repoId = (int)(Flight::request()->data->repo_id ?? 0);
        $cloudId = Flight::request()->data->cloud_id ?? '';
        $useOrchestrator = !empty(Flight::request()->data->use_orchestrator);

        if (empty($issueKey) || empty($boardId) || empty($repoId) || empty($cloudId)) {
            $this->json(['success' => false, 'error' => 'Missing required parameters']);
            return;
        }

        try {
            // Get Anthropic API key
            $apiKeySetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['anthropic_api_key']);

            if (!$apiKeySetting || empty($apiKeySetting->setting_value)) {
                $this->json(['success' => false, 'error' => 'Anthropic API key not configured']);
                return;
            }

            $apiKey = EncryptionService::decrypt($apiKeySetting->setting_value);

            // Find an available shard
            $shard = ShardRouter::findAvailableShard($this->member->id, ['git', 'filesystem']);

            if (!$shard) {
                $this->json(['success' => false, 'error' => 'No available shards. Please try again later.']);
                return;
            }

            // Get repository details
            $repoBean = Bean::load('repoconnections', (int)$repoId);

            if (!$repoBean->id) {
                $this->json(['success' => false, 'error' => 'Repository not found']);
                return;
            }

            $repoToken = EncryptionService::decrypt($repoBean->access_token);

            // Store repo details for payload
            $repoResult = [
                'repo_owner' => $repoBean->repo_owner,
                'repo_name' => $repoBean->repo_name,
                'default_branch' => $repoBean->default_branch ?? 'main',
                'clone_url' => $repoBean->clone_url
            ];

            // Get issue details from Jira
            $jiraClient = new \app\services\JiraClient($this->member->id, $cloudId);
            $issue = $jiraClient->getIssue($issueKey);

            $summary = $issue['fields']['summary'] ?? '';
            $description = \app\services\JiraClient::extractTextFromAdf($issue['fields']['description'] ?? null);
            $issueType = $issue['fields']['issuetype']['name'] ?? 'Task';
            $priority = $issue['fields']['priority']['name'] ?? 'Medium';

            // Get comments
            $comments = $issue['fields']['comment']['comments'] ?? [];
            $commentText = '';
            foreach (array_slice($comments, -10) as $comment) {
                $commentText .= \app\services\JiraClient::extractTextFromAdf($comment['body']) . "\n\n";
            }

            // Get attachment info
            $attachments = $issue['fields']['attachment'] ?? [];
            $attachmentInfo = '';
            if (!empty($attachments)) {
                $attachmentInfo = "## Attachments\n";
                foreach ($attachments as $att) {
                    $attachmentInfo .= "- {$att['filename']} ({$att['mimeType']}, {$att['size']} bytes)\n";
                    $attachmentInfo .= "  Download: {$att['content']}\n";
                }
            }

            // Extract URLs from description and comments
            $urlsToCheck = $this->extractUrls($description . ' ' . $commentText);

            // Get Jira credentials
            $jiraCreds = ShardRouter::getMemberMcpCredentials($this->member->id);
            $jiraHost = $jiraCreds['jira_host'] ?? '';
            $jiraEmail = $jiraCreds['jira_email'] ?? '';
            $jiraToken = $jiraCreds['jira_api_token'] ?? '';
            $jiraSiteUrl = $jiraCreds['jira_site_url'] ?? '';

            // Create or get job using AIDevJobManager
            $jobManager = new AIDevJobManager($this->member->id);
            $job = $jobManager->getOrCreate($issueKey, $boardId, $repoId, $cloudId);

            // Generate shard job ID
            $shardJobId = md5(uniqid($issueKey . '_' . microtime(true), true));

            // Build payload for shard
            $payload = [
                'anthropic_api_key' => $apiKey,
                'job_id' => $shardJobId,
                'issue_key' => $issueKey,
                'issue_data' => [
                    'summary' => $summary,
                    'description' => $description,
                    'type' => $issueType,
                    'priority' => $priority,
                    'comments' => $commentText,
                    'attachment_info' => $attachmentInfo,
                    'urls_to_check' => $urlsToCheck
                ],
                'repo_config' => [
                    'repo_owner' => $repoResult['repo_owner'],
                    'repo_name' => $repoResult['repo_name'],
                    'default_branch' => $repoResult['default_branch'] ?? 'main',
                    'clone_url' => $repoResult['clone_url']
                ],
                'jira_host' => $jiraHost,
                'jira_email' => $jiraEmail,
                'jira_api_token' => $jiraToken,
                'jira_site_url' => $jiraSiteUrl,
                'github_token' => $repoToken,
                'callback_url' => Flight::get('baseurl') . '/webhook/aidev',
                'callback_api_key' => Flight::get('cron.api_key'),
                'action' => 'implement',
                'use_orchestrator' => $useOrchestrator
            ];

            // Call shard endpoint
            $shardPort = $shard['port'];
            $shardProtocol = ($shardPort == 443 || !empty($shard['ssl'])) ? 'https' : 'http';
            $shardUrl = "{$shardProtocol}://{$shard['host']}:{$shardPort}/analysis/shardaidev";

            $client = new \GuzzleHttp\Client([
                'timeout' => 30,
                'verify' => false
            ]);

            $response = $client->post($shardUrl, [
                'json' => $payload
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if ($response->getStatusCode() !== 202) {
                throw new \Exception('Shard returned non-202 status: ' . $response->getStatusCode());
            }

            // Mark job as running with the shard job ID
            $jobManager->startRun($issueKey, $shardJobId);

            $this->logger->info('AI Developer shard job started', [
                'member_id' => $this->member->id,
                'issue_key' => $issueKey,
                'shard_job_id' => $shardJobId,
                'shard_id' => $shard['id'],
                'use_orchestrator' => $useOrchestrator
            ]);

            $this->json([
                'success' => true,
                'issue_key' => $issueKey,
                'shard_job_id' => $shardJobId,
                'shard' => $shard['name'] ?? $shard['host'],
                'message' => $useOrchestrator ? 'Job started with agent orchestrator' : 'Job started on shard',
                'use_orchestrator' => $useOrchestrator
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to start shard job', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Get job status (AJAX)
     */
    public function status($params = []) {
        if (!$this->requireLogin()) return;

        $issueKey = $params['operation']->name ?? '';
        if (empty($issueKey)) {
            $this->json(['success' => false, 'error' => 'Issue key required']);
            return;
        }

        require_once __DIR__ . '/../services/AIDevStatusService.php';
        $jobs = \app\services\AIDevStatusService::findAllJobsByIssueKey($this->member->id, $issueKey);
        $job = $jobs[0] ?? null;

        if (!$job) {
            $this->json(['success' => false, 'error' => 'Job not found']);
            return;
        }

        $this->json([
            'success' => true,
            'status' => $job
        ]);
    }

    /**
     * Get job logs (AJAX)
     */
    public function logs($params = []) {
        if (!$this->requireLogin()) return;

        $issueKey = $params['operation']->name ?? '';
        if (empty($issueKey)) {
            $this->json(['success' => false, 'error' => 'Issue key required']);
            return;
        }

        require_once __DIR__ . '/../services/AIDevStatusService.php';
        $jobs = \app\services\AIDevStatusService::findAllJobsByIssueKey($this->member->id, $issueKey);
        $job = $jobs[0] ?? null;

        if (!$job) {
            $this->json(['success' => false, 'error' => 'Job not found']);
            return;
        }

        // Convert steps_completed to log format
        $logs = [];
        foreach ($job['steps_completed'] ?? [] as $step) {
            $logs[] = [
                'level' => 'info',
                'message' => $step['step'],
                'context' => ['progress' => $step['progress'] ?? 0],
                'created_at' => $step['timestamp'] ?? null
            ];
        }

        // Add error if present
        if (!empty($job['error'])) {
            $logs[] = [
                'level' => 'error',
                'message' => $job['error'],
                'context' => null,
                'created_at' => $job['updated_at'] ?? null
            ];
        }

        $this->json([
            'success' => true,
            'logs' => $logs,
            'job' => $job
        ]);
    }

    /**
     * Resume a job after clarification
     */
    public function resume($params = []) {
        if (!$this->requireLogin()) return;

        $issueKey = $params['operation']->name ?? '';
        if (empty($issueKey)) {
            $this->json(['success' => false, 'error' => 'Issue key required']);
            return;
        }

        $jobManager = new AIDevJobManager($this->member->id);
        $job = $jobManager->get($issueKey);
        if (!$job) {
            $this->json(['success' => false, 'error' => 'Job not found']);
            return;
        }

        if ($job->status !== AIDevJobManager::STATUS_WAITING_CLARIFICATION) {
            $this->json(['success' => false, 'error' => 'Job is not waiting for clarification']);
            return;
        }

        try {
            $cronSecret = Flight::get('cron.api_key');
            $scriptPath = __DIR__ . '/../scripts/ai-dev-agent.php';

            $tenantSlug = $_SESSION['tenant_slug'] ?? null;
            $tenantParam = $tenantSlug && $tenantSlug !== 'default'
                ? sprintf(' --tenant=%s', escapeshellarg($tenantSlug))
                : '';

            $cmd = sprintf(
                'php %s --script --secret=%s --member=%d --job=%s --issue=%s --action=resume%s > /dev/null 2>&1 &',
                escapeshellarg($scriptPath),
                escapeshellarg($cronSecret),
                $this->member->id,
                escapeshellarg($job->id),
                escapeshellarg($issueKey),
                $tenantParam
            );

            exec($cmd);

            $this->json([
                'success' => true,
                'message' => 'Job resumed'
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to resume job', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Retry a completed/failed job on its existing branch
     */
    public function retry($params = []) {
        if (!$this->requireLogin()) return;

        $issueKey = $params['operation']->name ?? '';
        if (empty($issueKey)) {
            $this->json(['success' => false, 'error' => 'Issue key required']);
            return;
        }

        $jobManager = new AIDevJobManager($this->member->id);
        $job = $jobManager->get($issueKey);
        if (!$job) {
            $this->json(['success' => false, 'error' => 'Job not found']);
            return;
        }

        if (empty($job->branchName)) {
            $this->json(['success' => false, 'error' => 'No branch found for this job. Cannot retry.']);
            return;
        }

        if (!in_array($job->status, [
            AIDevJobManager::STATUS_COMPLETE,
            AIDevJobManager::STATUS_PR_CREATED,
            AIDevJobManager::STATUS_FAILED
        ])) {
            $this->json(['success' => false, 'error' => 'Can only retry completed, pr_created, or failed jobs']);
            return;
        }

        try {
            // Get API key
            $apiKeySetting = Bean::findOne('enterprisesettings', 'setting_key = ?', ['anthropic_api_key']);

            if (!$apiKeySetting || empty($apiKeySetting->setting_value)) {
                $this->json(['success' => false, 'error' => 'Anthropic API key not configured']);
                return;
            }

            // Get cloud_id from the job or look up from board
            $cloudId = $job->cloudId;
            if (empty($cloudId)) {
                $board = Bean::load('jiraboards', (int)$job->board_id);
                $cloudId = $board->cloud_id ?? null;
            }

            if (empty($cloudId)) {
                $this->json(['success' => false, 'error' => 'Could not determine Atlassian Cloud ID for this job']);
                return;
            }

            $shardJobId = md5(uniqid($issueKey . '_retry_' . microtime(true), true));

            $cronSecret = Flight::get('cron.api_key');
            $scriptPath = __DIR__ . '/../scripts/ai-dev-agent.php';

            $tenantSlug = $_SESSION['tenant_slug'] ?? null;
            $tenantParam = $tenantSlug && $tenantSlug !== 'default'
                ? sprintf(' --tenant=%s', escapeshellarg($tenantSlug))
                : '';

            $cmd = sprintf(
                'php %s --script --secret=%s --member=%d --job=%s --issue=%s --action=retry --branch=%s --pr=%d%s > /dev/null 2>&1 &',
                escapeshellarg($scriptPath),
                escapeshellarg($cronSecret),
                $this->member->id,
                escapeshellarg($shardJobId),
                escapeshellarg($issueKey),
                escapeshellarg($job->branchName),
                $job->prNumber ?? 0,
                $tenantParam
            );

            exec($cmd);

            $this->logger->info('AI Developer retry job started', [
                'member_id' => $this->member->id,
                'issue_key' => $issueKey,
                'branch' => $job->branchName
            ]);

            $this->json([
                'success' => true,
                'issue_key' => $issueKey,
                'message' => 'Retry job started on branch: ' . $job->branchName
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to retry job', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Mark a job as complete
     */
    public function complete($params = []) {
        if (!$this->requireLogin()) return;

        $issueKey = $params['operation']->name ?? '';
        if (empty($issueKey)) {
            $this->json(['success' => false, 'error' => 'Issue key required']);
            return;
        }

        $jobManager = new AIDevJobManager($this->member->id);
        $job = $jobManager->get($issueKey);
        if (!$job) {
            $this->json(['success' => false, 'error' => 'Job not found']);
            return;
        }

        if ($job->status !== AIDevJobManager::STATUS_PR_CREATED) {
            $this->json(['success' => false, 'error' => 'Can only mark pr_created jobs as complete']);
            return;
        }

        try {
            $jobManager->markComplete($issueKey);

            $this->logger->info('AI Developer job marked complete', [
                'member_id' => $this->member->id,
                'issue_key' => $issueKey
            ]);

            $this->json([
                'success' => true,
                'message' => 'Job marked as complete'
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to complete job', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Extract URLs from text
     */
    private function extractUrls(string $text): array {
        $urls = [];
        if (preg_match_all('#https?://[^\s<>"\')\]]+#i', $text, $matches)) {
            $urls = array_unique($matches[0]);
            $urls = array_filter($urls, function($url) {
                return !preg_match('#atlassian\.net|atlassian\.com#i', $url);
            });
        }
        return array_values($urls);
    }
}
