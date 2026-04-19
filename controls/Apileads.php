<?php
/**
 * API Leads Controller - Public Lead Capture via Pipeline
 *
 * Handles lead capture form submissions without authentication.
 * Triggers the lead capture pipeline to process and store leads.
 *
 * Routes:
 *   POST /apileads/shopifyai -> shopifyai()
 *
 * This is a PUBLIC endpoint - no authentication required.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\WorkspaceResolver;

class Apileads {

    protected $logger;
    private string $workspace = 'dev';

    public function __construct() {
        // Stateless - no session required
        $this->logger = Flight::get('log');

        // Use default workspace for leads storage
        WorkspaceResolver::switchDatabase($this->workspace);
    }

    /**
     * Index - not used
     */
    public function index() {
        Flight::jsonError('Method not allowed', 405);
    }

    /**
     * Handle Shopify AI landing page lead capture
     * POST /apileads/shopifyai
     *
     * Triggers the shopify-ai-lead-capture pipeline
     */
    public function shopifyai() {
        // Only accept POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }

        // Get JSON body
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input)) {
            // Try form data
            $input = $_POST;
        }

        // Validate required fields
        $email = trim($input['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flight::jsonError('Valid email address required', 400);
            return;
        }

        // Sanitize inputs
        $storeUrl = trim($input['store_url'] ?? '');
        $useCase = trim($input['use_case'] ?? '');
        $notes = trim($input['notes'] ?? '');

        // Rate limiting: Check for duplicate (same email in last 24 hours)
        $existing = Bean::findOne('leads',
            'email = ? AND source = ? AND created_at > ?',
            [$email, 'shopify-ai', date('Y-m-d H:i:s', strtotime('-24 hours'))]
        );

        if ($existing) {
            // Silently succeed to prevent enumeration
            $this->logger->info("Duplicate lead submission blocked", ['email' => $email]);
            Flight::json([
                'success' => true,
                'message' => 'Thanks! You\'re on the list.'
            ]);
            return;
        }

        try {
            // Find the lead capture pipeline
            $pipeline = Bean::findOne('pipelines', 'slug = ?', ['shopify-ai-lead-capture']);

            if (!$pipeline || !$pipeline->is_active) {
                // Fallback: store lead directly if pipeline not found
                $this->storeLeadDirectly($email, $storeUrl, $useCase, $notes);
                Flight::json([
                    'success' => true,
                    'message' => 'Thanks! You\'re on the list.'
                ]);
                return;
            }

            // Create context for pipeline
            $context = [
                'email' => $email,
                'store_url' => $storeUrl,
                'use_case' => $useCase,
                'notes' => $notes,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'referrer' => $_SERVER['HTTP_REFERER'] ?? null,
            ];

            // Create pipeline run
            $runUid = 'lead-' . bin2hex(random_bytes(8));
            $stepCount = Bean::count('pipelinesteps', 'pipelines_id = ? AND is_active = 1', [$pipeline->id]);

            $run = Bean::dispense('pipelineruns');
            $run->run_uid = $runUid;
            $run->pipelines_id = $pipeline->id;
            $run->member_id = 1; // System user for public submissions
            $run->trigger_source = 'form:shopify-ai-landing';
            $run->trigger_data_json = json_encode($input);
            $run->status = 'pending';
            $run->context_json = json_encode($context);
            $run->steps_total = $stepCount;
            $run->steps_completed = 0;
            $run->progress_percent = 0;
            $run->created_at = date('Y-m-d H:i:s');
            $run->updated_at = date('Y-m-d H:i:s');

            $runId = Bean::store($run);

            $this->logger->info("Lead capture pipeline triggered", [
                'run_id' => $runId,
                'run_uid' => $runUid,
                'email' => $email
            ]);

            // Update pipeline stats
            $pipeline->run_count = ($pipeline->run_count ?? 0) + 1;
            $pipeline->last_run_at = date('Y-m-d H:i:s');
            Bean::store($pipeline);

            // Dispatch via engine or background process
            \app\services\PipelineDispatcher::dispatch($runId, $this->workspace);

            Flight::json([
                'success' => true,
                'message' => 'Thanks! You\'re on the list.'
            ]);

        } catch (\Exception $e) {
            $this->logger->error("Failed to process lead: " . $e->getMessage());

            // Fallback: try to store directly
            try {
                $this->storeLeadDirectly($email, $storeUrl, $useCase, $notes);
                Flight::json([
                    'success' => true,
                    'message' => 'Thanks! You\'re on the list.'
                ]);
            } catch (\Exception $e2) {
                Flight::jsonError('Something went wrong. Please try again.', 500);
            }
        }
    }

    /**
     * Fallback: Store lead directly without pipeline
     */
    private function storeLeadDirectly(string $email, string $storeUrl, string $useCase, string $notes): int {
        $lead = Bean::dispense('leads');
        $lead->email = $email;
        $lead->store_url = $storeUrl;
        $lead->use_case = $useCase;
        $lead->notes = $notes;
        $lead->source = 'shopify-ai';
        $lead->status = 'new';
        $lead->ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $lead->user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $lead->referrer = $_SERVER['HTTP_REFERER'] ?? null;
        $lead->created_at = date('Y-m-d H:i:s');

        $leadId = Bean::store($lead);

        $this->logger->info("Lead stored directly (fallback)", [
            'lead_id' => $leadId,
            'email' => $email
        ]);

        return $leadId;
    }
}
