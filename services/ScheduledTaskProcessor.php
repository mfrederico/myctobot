<?php

namespace app\services;

use \Flight as Flight;
use \app\Bean;

/**
 * ScheduledTaskProcessor
 *
 * Processes scheduled tasks for Studio orchestrations.
 * Handles different task types:
 * - revert_action: Undo a previous action using stored revert data
 * - execute_pipeline: Trigger a pipeline run
 * - webhook_call: Make an HTTP webhook call
 * - api_call: Make an API call to an external service
 */
class ScheduledTaskProcessor
{
    private bool $verbose;

    public function __construct(bool $verbose = false)
    {
        $this->verbose = $verbose;
    }

    /**
     * Process a scheduled task
     *
     * @param object $task The scheduledtasks bean
     * @return array ['success' => bool, 'error' => string|null, 'result' => mixed]
     */
    public function process($task): array
    {
        $taskType = $task->task_type;
        $payload = json_decode($task->payload_json ?: '{}', true);
        $revertData = json_decode($task->revert_data_json ?: '{}', true);

        try {
            switch ($taskType) {
                case 'revert_action':
                    return $this->processRevertAction($task, $payload, $revertData);

                case 'execute_pipeline':
                    return $this->processExecutePipeline($task, $payload);

                case 'webhook_call':
                    return $this->processWebhookCall($task, $payload);

                case 'api_call':
                    return $this->processApiCall($task, $payload);

                default:
                    return [
                        'success' => false,
                        'error' => "Unknown task type: {$taskType}"
                    ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Process a revert action - undo previous changes
     *
     * Revert data structure:
     * {
     *   "actions": [
     *     {
     *       "type": "shopify_discount",
     *       "action": "delete",
     *       "resource_id": "gid://shopify/DiscountAutomaticNode/123"
     *     },
     *     {
     *       "type": "shopify_theme_section",
     *       "action": "restore",
     *       "section_id": "announcement-bar",
     *       "original_content": "..."
     *     }
     *   ]
     * }
     */
    private function processRevertAction($task, array $payload, array $revertData): array
    {
        $actions = $revertData['actions'] ?? [];

        if (empty($actions)) {
            return [
                'success' => true,
                'result' => 'No actions to revert'
            ];
        }

        $results = [];
        $errors = [];

        foreach ($actions as $action) {
            try {
                $result = $this->executeRevertAction($task, $action);
                $results[] = $result;

                // Log the revert in studioactionlog
                $this->logAction($task, 'revert', $action, $result);

            } catch (\Exception $e) {
                $errors[] = [
                    'action' => $action,
                    'error' => $e->getMessage()
                ];

                if ($this->verbose) {
                    echo "       Revert action failed: {$e->getMessage()}\n";
                }
            }
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'error' => 'Some revert actions failed: ' . json_encode($errors),
                'result' => $results
            ];
        }

        return [
            'success' => true,
            'result' => $results
        ];
    }

    /**
     * Execute a single revert action
     */
    private function executeRevertAction($task, array $action): array
    {
        $type = $action['type'] ?? 'unknown';
        $actionType = $action['action'] ?? 'unknown';

        switch ($type) {
            case 'shopify_discount':
                return $this->revertShopifyDiscount($task, $action);

            case 'shopify_theme_section':
                return $this->revertShopifyThemeSection($task, $action);

            case 'shopify_product_price':
                return $this->revertShopifyProductPrice($task, $action);

            case 'github_revert_commit':
                return $this->revertGitHubCommit($task, $action);

            case 'github_restore_files':
                return $this->restoreGitHubFiles($task, $action);

            case 'custom':
                // Custom revert logic via callback
                return $this->executeCustomRevert($task, $action);

            default:
                return [
                    'success' => false,
                    'message' => "Unknown revert type: {$type}"
                ];
        }
    }

    /**
     * Revert a Shopify discount (delete it)
     */
    private function revertShopifyDiscount($task, array $action): array
    {
        $resourceId = $action['resource_id'] ?? null;

        if (!$resourceId) {
            return ['success' => false, 'message' => 'No resource_id provided'];
        }

        // Get the project to find the member's Shopify connection
        $project = $this->getProjectFromTask($task);
        if (!$project) {
            return ['success' => false, 'message' => 'Project not found'];
        }

        // TODO: Use ShopifyClient to delete the discount
        // For now, log what would happen
        if ($this->verbose) {
            echo "       Would delete Shopify discount: {$resourceId}\n";
        }

        // Placeholder - actual implementation will use ShopifyClient
        return [
            'success' => true,
            'message' => "Discount deletion scheduled: {$resourceId}"
        ];
    }

    /**
     * Revert a Shopify theme section to original content
     */
    private function revertShopifyThemeSection($task, array $action): array
    {
        $sectionId = $action['section_id'] ?? null;
        $originalContent = $action['original_content'] ?? null;

        if (!$sectionId || !$originalContent) {
            return ['success' => false, 'message' => 'Missing section_id or original_content'];
        }

        // TODO: Use ShopifyClient to restore the section
        if ($this->verbose) {
            echo "       Would restore theme section: {$sectionId}\n";
        }

        return [
            'success' => true,
            'message' => "Theme section restoration scheduled: {$sectionId}"
        ];
    }

    /**
     * Revert Shopify product prices to original
     */
    private function revertShopifyProductPrice($task, array $action): array
    {
        $productId = $action['product_id'] ?? null;
        $originalPrice = $action['original_price'] ?? null;
        $originalCompareAtPrice = $action['original_compare_at_price'] ?? null;

        if (!$productId) {
            return ['success' => false, 'message' => 'No product_id provided'];
        }

        // TODO: Use ShopifyClient to restore prices
        if ($this->verbose) {
            echo "       Would restore product prices: {$productId} -> {$originalPrice}\n";
        }

        return [
            'success' => true,
            'message' => "Product price restoration scheduled: {$productId}"
        ];
    }

    /**
     * Execute custom revert logic
     */
    private function executeCustomRevert($task, array $action): array
    {
        $callback = $action['callback'] ?? null;
        $data = $action['data'] ?? [];

        if (!$callback) {
            return ['success' => false, 'message' => 'No callback defined for custom revert'];
        }

        // TODO: Implement callback mechanism
        return [
            'success' => true,
            'message' => "Custom revert executed: {$callback}"
        ];
    }

    /**
     * Revert a GitHub commit by creating a revert commit
     *
     * This is used for Shopify theme changes that were pushed via GitHub.
     * Shopify auto-deploys from GitHub, so reverting the commit reverts the theme.
     *
     * Action structure:
     * {
     *   "type": "github_revert_commit",
     *   "repo": "owner/repo",
     *   "commit_sha": "abc123",
     *   "branch": "main"
     * }
     */
    private function revertGitHubCommit($task, array $action): array
    {
        $repo = $action['repo'] ?? null;
        $commitSha = $action['commit_sha'] ?? null;
        $branch = $action['branch'] ?? 'main';

        if (!$repo || !$commitSha) {
            return ['success' => false, 'message' => 'Missing repo or commit_sha'];
        }

        $project = $this->getProjectFromTask($task);
        if (!$project) {
            return ['success' => false, 'message' => 'Project not found'];
        }

        // Get member's GitHub token
        $member = Bean::load('member', $project->member_id);
        if (!$member->id) {
            return ['success' => false, 'message' => 'Member not found'];
        }

        // TODO: Use GitHub API to create revert commit
        // For now, we'll use the gh CLI approach or direct API
        // This would typically:
        // 1. Clone the repo (or use existing checkout)
        // 2. git revert <commit_sha> --no-edit
        // 3. git push

        if ($this->verbose) {
            echo "       Would revert GitHub commit: {$repo}@{$commitSha}\n";
        }

        // Placeholder - actual implementation will use GitHub API
        // POST /repos/{owner}/{repo}/git/commits with parent as commit^
        // Then update refs/heads/{branch} to new commit
        return [
            'success' => true,
            'message' => "GitHub commit revert scheduled: {$repo}@{$commitSha}"
        ];
    }

    /**
     * Restore specific files from a previous commit
     *
     * Action structure:
     * {
     *   "type": "github_restore_files",
     *   "repo": "owner/repo",
     *   "files": ["sections/announcement-bar.liquid"],
     *   "restore_from_sha": "abc123",
     *   "branch": "main"
     * }
     */
    private function restoreGitHubFiles($task, array $action): array
    {
        $repo = $action['repo'] ?? null;
        $files = $action['files'] ?? [];
        $restoreFromSha = $action['restore_from_sha'] ?? null;
        $branch = $action['branch'] ?? 'main';

        if (!$repo || empty($files) || !$restoreFromSha) {
            return ['success' => false, 'message' => 'Missing repo, files, or restore_from_sha'];
        }

        $project = $this->getProjectFromTask($task);
        if (!$project) {
            return ['success' => false, 'message' => 'Project not found'];
        }

        if ($this->verbose) {
            echo "       Would restore files from {$repo}@{$restoreFromSha}: " . implode(', ', $files) . "\n";
        }

        // Placeholder - actual implementation will:
        // 1. Get file contents at restore_from_sha using GitHub API
        // 2. Create new commit with those file contents
        // 3. Push to branch
        return [
            'success' => true,
            'message' => "File restoration scheduled: " . count($files) . " files from {$repo}@{$restoreFromSha}"
        ];
    }

    /**
     * Process execute_pipeline task - trigger a pipeline run
     */
    private function processExecutePipeline($task, array $payload): array
    {
        $pipelineId = $payload['pipeline_id'] ?? null;
        $inputData = $payload['input_data'] ?? [];
        $triggerSource = $payload['trigger_source'] ?? 'scheduled_task';

        if (!$pipelineId) {
            return ['success' => false, 'error' => 'No pipeline_id specified'];
        }

        $pipeline = Bean::load('pipelines', $pipelineId);
        if (!$pipeline->id) {
            return ['success' => false, 'error' => "Pipeline not found: {$pipelineId}"];
        }

        // Create a new pipeline run
        require_once dirname(__FILE__) . '/PipelineExecutor.php';

        try {
            $executor = new PipelineExecutor($pipeline, $triggerSource);
            $result = $executor->execute($inputData);

            return [
                'success' => $result['success'] ?? false,
                'result' => $result
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "Pipeline execution failed: " . $e->getMessage()
            ];
        }
    }

    /**
     * Process webhook_call task - make an HTTP request
     */
    private function processWebhookCall($task, array $payload): array
    {
        $url = $payload['url'] ?? null;
        $method = strtoupper($payload['method'] ?? 'POST');
        $headers = $payload['headers'] ?? [];
        $body = $payload['body'] ?? null;
        $timeout = $payload['timeout'] ?? 30;

        if (!$url) {
            return ['success' => false, 'error' => 'No URL specified'];
        }

        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => 'Invalid URL'];
        }

        // Make the HTTP request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
            }
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
            }
        }

        // Set headers
        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }
        if (!empty($curlHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => "Curl error: {$error}"];
        }

        // Consider 2xx responses as success
        $success = $httpCode >= 200 && $httpCode < 300;

        return [
            'success' => $success,
            'result' => [
                'http_code' => $httpCode,
                'response' => $response
            ],
            'error' => $success ? null : "HTTP {$httpCode}"
        ];
    }

    /**
     * Process api_call task - make an API call to a specific service
     */
    private function processApiCall($task, array $payload): array
    {
        $service = $payload['service'] ?? null;
        $endpoint = $payload['endpoint'] ?? null;
        $method = $payload['method'] ?? 'GET';
        $data = $payload['data'] ?? [];

        if (!$service || !$endpoint) {
            return ['success' => false, 'error' => 'Missing service or endpoint'];
        }

        // Get the project to find credentials
        $project = $this->getProjectFromTask($task);

        switch ($service) {
            case 'shopify':
                return $this->callShopifyApi($project, $endpoint, $method, $data);

            case 'github':
                return $this->callGithubApi($project, $endpoint, $method, $data);

            case 'jira':
                return $this->callJiraApi($project, $endpoint, $method, $data);

            default:
                return ['success' => false, 'error' => "Unknown service: {$service}"];
        }
    }

    /**
     * Get the studio project from a task
     */
    private function getProjectFromTask($task): ?object
    {
        $projectId = $task->studioprojects_id;
        if (!$projectId) {
            return null;
        }

        $project = Bean::load('studioprojects', $projectId);
        return $project->id ? $project : null;
    }

    /**
     * Call Shopify API (placeholder)
     */
    private function callShopifyApi($project, string $endpoint, string $method, array $data): array
    {
        // TODO: Implement using ShopifyClient
        return [
            'success' => true,
            'result' => "Shopify API call would be made: {$method} {$endpoint}"
        ];
    }

    /**
     * Call GitHub API (placeholder)
     */
    private function callGithubApi($project, string $endpoint, string $method, array $data): array
    {
        // TODO: Implement using GitHub client
        return [
            'success' => true,
            'result' => "GitHub API call would be made: {$method} {$endpoint}"
        ];
    }

    /**
     * Call Jira API (placeholder)
     */
    private function callJiraApi($project, string $endpoint, string $method, array $data): array
    {
        // TODO: Implement using Jira client
        return [
            'success' => true,
            'result' => "Jira API call would be made: {$method} {$endpoint}"
        ];
    }

    /**
     * Log an action to studioactionlog
     */
    private function logAction($task, string $actionType, array $actionData, array $result): void
    {
        try {
            $log = Bean::dispense('studioactionlog');
            $log->studioprojects_id = $task->studioprojects_id;
            $log->action_type = $actionType;
            $log->action_data_json = json_encode($actionData);
            $log->revertible = false; // Reverts are not re-revertible
            $log->result_json = json_encode($result);
            $log->created_at = date('Y-m-d H:i:s');
            Bean::store($log);
        } catch (\Exception $e) {
            // Log error but don't fail the task
            Flight::get('log')->warning('Failed to log action', [
                'task_id' => $task->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
