<?php
/**
 * Pipein Controller
 *
 * Webhook endpoint for triggering pipelines externally.
 * URL format: /pipein/workspace/{pipeline_slug}
 *
 * Supports:
 * - POST with JSON body
 * - POST with form data
 * - GET with query parameters (for simple triggers)
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\WorkspaceResolver;
use \app\services\ApiAuthService;

class Pipein extends BaseControls\Control {

    // Member context from API key authentication
    private $apiMember = null;
    private $apiKey = null;

    /**
     * Main webhook endpoint
     * Supports both routing styles:
     * - Subdomain: /pipein/{slug} (workspace from subdomain)
     * - Path: /pipein/{workspace}/{slug} (workspace from path)
     *
     * Example: POST https://gwt.myctobot.ai/pipein/my-deploy-pipeline
     * Example: POST https://myctobot.ai/pipein/gwt/my-deploy-pipeline
     */
    public function index($params = []) {
        $pathParts = $this->parsePath();

        // Check if workspace is in subdomain (e.g., gwt.myctobot.ai)
        $subdomainWorkspace = $_SERVER['WORKSPACE'] ?? null;

        if ($subdomainWorkspace) {
            // Subdomain routing: /pipein/{slug}
            if (count($pathParts) < 1) {
                $this->errorResponse(400, 'Invalid URL format. Expected: /pipein/{pipeline_slug}');
                return;
            }
            $workspace = $subdomainWorkspace;
            $pipelineSlug = $pathParts[0];
        } else {
            // Path routing: /pipein/{workspace}/{slug}
            if (count($pathParts) < 2) {
                $this->errorResponse(400, 'Invalid URL format. Expected: /pipein/{workspace}/{pipeline_slug}');
                return;
            }
            $workspace = $pathParts[0];
            $pipelineSlug = $pathParts[1];
        }

        // Switch to workspace database
        if (!WorkspaceResolver::switchDatabase($workspace)) {
            $this->errorResponse(400, "Invalid workspace: {$workspace}");
            return;
        }

        // Authenticate using API key - validates and gets member context
        $authResult = ApiAuthService::authenticate('pipelines', 'trigger');
        if (!$authResult['success']) {
            $this->errorResponse($authResult['code'], $authResult['error']);
            return;
        }

        $this->apiMember = $authResult['member'];
        $this->apiKey = $authResult['apikey'];

        $this->logger->info("Pipein webhook for workspace: {$workspace}, pipeline: {$pipelineSlug}", [
            'member_id' => $this->apiMember->id,
            'member_username' => $this->apiMember->username,
            'api_key_id' => $this->apiKey->id
        ]);

        // Find pipeline by slug
        $pipeline = Bean::findOne('pipelines', 'slug = ?', [$pipelineSlug]);

        if (!$pipeline) {
            $this->errorResponse(404, "Pipeline not found: {$pipelineSlug}");
            return;
        }

        if (!$pipeline->is_active) {
            $this->errorResponse(400, "Pipeline is not active");
            return;
        }

        // Validate webhook secret if configured
        $triggerConfig = json_decode($pipeline->trigger_config_json ?: '{}', true);
        if (!empty($triggerConfig['webhook_secret'])) {
            if (!$this->validateWebhookSecret($triggerConfig['webhook_secret'])) {
                $this->errorResponse(401, 'Invalid webhook secret');
                return;
            }
        }

        // Get trigger data from request
        $triggerData = $this->getTriggerData();
        $triggerData['_webhook'] = [
            'workspace' => $workspace,
            'pipeline_slug' => $pipelineSlug,
            'method' => Flight::request()->method,
            'ip' => Flight::request()->ip,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        // Determine trigger source
        $triggerSource = 'webhook';
        if (!empty($triggerData['_source'])) {
            $triggerSource = 'webhook:' . $triggerData['_source'];
        } elseif (!empty($_SERVER['HTTP_X_GITHUB_EVENT'])) {
            $triggerSource = 'webhook:github';
            $triggerData['github_event'] = $_SERVER['HTTP_X_GITHUB_EVENT'];
        } elseif (!empty($_SERVER['HTTP_X_ATLASSIAN_WEBHOOK_IDENTIFIER'])) {
            $triggerSource = 'webhook:jira';
        }

        // Create run
        $runUid = 'run-' . bin2hex(random_bytes(8));
        $stepCount = Bean::count('pipelinesteps', 'pipelines_id = ? AND is_active = 1', [$pipeline->id]);

        $run = Bean::dispense('pipelineruns');
        $run->run_uid = $runUid;
        $run->pipelines_id = $pipeline->id;
        $run->member_id = $this->apiMember->id; // From API key authentication
        $run->trigger_source = $triggerSource;
        $run->trigger_data_json = json_encode($triggerData);
        $run->status = 'pending';
        $run->context_json = $pipeline->default_context_json ?: '{}';
        $run->steps_total = $stepCount;
        $run->steps_completed = 0;
        $run->progress_percent = 0;
        $run->created_at = date('Y-m-d H:i:s');
        $run->updated_at = date('Y-m-d H:i:s');

        $runId = Bean::store($run);

        $this->logger->info("Created pipeline run: {$runUid} (ID: {$runId})");

        // Update pipeline stats
        $pipeline->run_count = ($pipeline->run_count ?? 0) + 1;
        $pipeline->last_run_at = date('Y-m-d H:i:s');
        Bean::store($pipeline);

        // Spawn background execution
        $scriptPath = dirname(__DIR__) . '/scripts/runpipe.php';
        $cmd = sprintf(
            'php %s --workspace=%s --run-id=%d > /dev/null 2>&1 &',
            escapeshellarg($scriptPath),
            escapeshellarg($workspace),
            $runId
        );
        exec($cmd);

        // Return success response
        Flight::response()->status(200);
        Flight::response()->header('Content-Type', 'application/json');
        echo json_encode([
            'success' => true,
            'run_id' => $runId,
            'run_uid' => $runUid,
            'pipeline' => $pipeline->name,
            'status' => 'pending',
            'message' => 'Pipeline run started'
        ]);
    }

    /**
     * Parse path to extract workspace and pipeline slug
     */
    private function parsePath(): array {
        $url = Flight::request()->url;

        // Remove /pipein/ prefix
        $path = preg_replace('#^/pipein/?#', '', $url);

        // Remove query string
        if (strpos($path, '?') !== false) {
            $path = substr($path, 0, strpos($path, '?'));
        }

        // Split into parts
        $parts = array_filter(explode('/', $path));

        return array_values($parts);
    }

    /**
     * Get trigger data from request
     */
    private function getTriggerData(): array {
        $method = Flight::request()->method;

        // Try JSON body first
        $rawBody = file_get_contents('php://input');
        if (!empty($rawBody)) {
            $jsonData = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                return $jsonData;
            }
        }

        // Fall back to POST data
        if ($method === 'POST' && !empty($_POST)) {
            return $_POST;
        }

        // Fall back to GET params
        if (!empty($_GET)) {
            return $_GET;
        }

        return [];
    }

    /**
     * Validate webhook secret
     */
    private function validateWebhookSecret(string $expectedSecret): bool {
        // Check X-Webhook-Secret header
        $headerSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
        if (!empty($headerSecret) && hash_equals($expectedSecret, $headerSecret)) {
            return true;
        }

        // Check Authorization: Bearer header
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            if (hash_equals($expectedSecret, $matches[1])) {
                return true;
            }
        }

        // Check query parameter
        $querySecret = $_GET['secret'] ?? '';
        if (!empty($querySecret) && hash_equals($expectedSecret, $querySecret)) {
            return true;
        }

        return false;
    }

    /**
     * Send error response
     */
    private function errorResponse(int $status, string $message): void {
        $this->logger->warning("Pipein error: {$message}");
        Flight::response()->status($status);
        Flight::response()->header('Content-Type', 'application/json');
        echo json_encode([
            'success' => false,
            'error' => $message
        ]);
    }
}
