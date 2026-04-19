<?php
/**
 * Customer Support Controller
 *
 * Public-facing controller for customer support chat.
 * Handles order verification and chat sessions for Shopify store visitors.
 *
 * This is a stateless controller (no session required) for embedding in external sites.
 *
 * Routes:
 *   GET  /customersupport/{widget_token}  -> Show verification form
 *   POST /customersupport/verify          -> Verify order + email
 *   GET  /customersupport/chat/{session}  -> Chat interface
 *   POST /customersupport/message/{session} -> Send message to agent
 *   GET  /customersupport/poll/{session}  -> Get new messages (AJAX)
 *   POST /customersupport/escalate/{session} -> Escalate to human
 */

namespace app;

use \Flight as Flight;
use \Exception as Exception;
use \app\services\ShopifyClient;
use \app\services\PipelineExecutor;
use \app\services\ShopifyCrawlerService;
use \app\services\MailgunService;
use \app\WorkspaceResolver;

class Customersupport extends BaseControls\Control {

    protected $logger;
    private ?string $workspace = null;

    /**
     * Constructor - stateless, no session required
     */
    public function __construct() {
        // Don't call parent - no session/CSRF for stateless public requests
        $this->logger = Flight::get('log');
        $this->workspace = $_SERVER['WORKSPACE'] ?? null;

        // Set CORS headers for iframe embedding
        $this->setCorsHeaders();
    }

    /**
     * Set CORS headers for iframe embedding
     */
    private function setCorsHeaders(): void {
        // Allow embedding from any origin for widget
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

        // Handle preflight
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    /**
     * Default index - show verification form
     *
     * GET /customersupport or /customersupport/{widget_token}
     */
    public function index($params = []) {
        $widgetToken = $this->opId() ?? $this->getParam('token');

        if (empty($widgetToken)) {
            $this->renderError('Widget token required');
            return;
        }

        // Find chatbot + connection by widget token
        $resolved = $this->resolveByToken($widgetToken);
        if (!$resolved) {
            $this->renderError('Invalid widget token');
            return;
        }

        $chatbot = $resolved['chatbot'];
        $connection = $resolved['connection'];
        $pipelineId = $resolved['pipeline_id'];

        if (empty($pipelineId)) {
            $this->renderError('Customer support not configured');
            return;
        }

        // Get shop info for branding
        $chatbotConfig = $chatbot ? $chatbot->box('Model_Chatbots')->getConfig() : [];
        $shopInfo = [
            'shop_name' => $chatbotConfig['branding']['name'] ?? ($connection ? ($connection->display_name ?: 'Store') : 'Store'),
            'shop_domain' => $connection ? $connection->external_eid : ''
        ];

        // Check for embed mode
        $embed = (bool)($this->getParam('embed') ?? false);

        // Render verification form (or skip if verification not required)
        $requireVerification = $chatbotConfig['require_order_verification'] ?? true;

        $this->supportRenderView('customersupport/verify', [
            'title' => 'Customer Support',
            'widget_token' => $widgetToken,
            'shop_info' => $shopInfo,
            'embed' => $embed,
            'require_verification' => $requireVerification,
            'welcome_message' => $chatbotConfig['welcome_message'] ?? 'Hi! How can I help you today?',
        ]);
    }

    /**
     * Verify order and email
     *
     * POST /customersupport/verify
     */
    public function verify($params = []) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonError('POST required', 405);
            return;
        }

        // Accept JSON body
        $input = $this->getJsonInput();

        $widgetToken = $input['widget_token'] ?? '';
        $orderNumber = trim($input['order_number'] ?? '');
        $email = trim(strtolower($input['email'] ?? ''));

        // Validate inputs
        if (empty($widgetToken)) {
            $this->sendJsonError('Widget token required');
            return;
        }
        if (empty($orderNumber)) {
            $this->sendJsonError('Order number required');
            return;
        }
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->sendJsonError('Valid email required');
            return;
        }

        // Find chatbot + connection
        $resolved = $this->resolveByToken($widgetToken);
        if (!$resolved || !$resolved['connection']) {
            $this->sendJsonError('Invalid widget token');
            return;
        }
        $connection = $resolved['connection'];
        $chatbot = $resolved['chatbot'];

        // Verify order via Shopify API
        try {
            $client = new ShopifyClient((int)$connection->id);

            // Clean up order number (remove # if present)
            $orderQuery = ltrim($orderNumber, '#');

            // Query Shopify for the order
            $query = <<<'GRAPHQL'
            query getOrderByName($query: String!) {
                orders(first: 1, query: $query) {
                    edges {
                        node {
                            id
                            name
                            email
                            createdAt
                            displayFinancialStatus
                            displayFulfillmentStatus
                            totalPriceSet {
                                shopMoney {
                                    amount
                                    currencyCode
                                }
                            }
                            customer {
                                firstName
                                lastName
                                email
                            }
                            lineItems(first: 10) {
                                edges {
                                    node {
                                        title
                                        quantity
                                    }
                                }
                            }
                        }
                    }
                }
            }
            GRAPHQL;

            $result = $client->graphql($query, ['query' => "name:#{$orderQuery}"]);

            // Check if order was found
            $orders = $result['data']['orders']['edges'] ?? [];
            if (empty($orders)) {
                $this->logger->info('Order not found', [
                    'order_number' => $orderNumber,
                    'shop' => $connection->external_eid
                ]);
                $this->sendJsonError('Order not found. Please check the order number.');
                return;
            }

            $order = $orders[0]['node'];

            // Verify email matches
            $orderEmail = strtolower($order['email'] ?? '');
            $customerEmail = strtolower($order['customer']['email'] ?? '');

            if ($email !== $orderEmail && $email !== $customerEmail) {
                $this->logger->info('Email mismatch for order', [
                    'order_number' => $orderNumber,
                    'provided_email' => $email,
                    'shop' => $connection->external_eid
                ]);
                $this->sendJsonError('Email does not match the order.');
                return;
            }

            // Create customer session
            $session = Bean::dispense('customersessions');
            $session->email = $email;
            // Store Shopify GID as string (full order data is in context_json)
            $session->order_gid = $order['id'];
            $session->order_name = $order['name'];
            $session->connections_id = $connection->id;
            $session->chatbots_id = $chatbot ? (int)$chatbot->id : null;
            $session->aiagents_id = null; // Will be set by pipeline
            $session->pipelineruns_id = null; // Will be set when chat starts

            // Prepare order data for context
            $orderData = [
                'id' => $order['id'],
                'name' => $order['name'],
                'email' => $order['email'],
                'created_at' => $order['createdAt'],
                'financial_status' => $order['displayFinancialStatus'],
                'fulfillment_status' => $order['displayFulfillmentStatus'],
                'total' => $order['totalPriceSet']['shopMoney'],
                'customer' => [
                    'first_name' => $order['customer']['firstName'] ?? '',
                    'last_name' => $order['customer']['lastName'] ?? '',
                    'email' => $order['customer']['email'] ?? ''
                ],
                'items' => array_map(function($edge) {
                    return [
                        'title' => $edge['node']['title'],
                        'quantity' => $edge['node']['quantity']
                    ];
                }, $order['lineItems']['edges'] ?? [])
            ];

            // Mark as verified with order data
            $session->markVerified($orderData);

            Bean::store($session);

            $this->logger->info('Customer session verified', [
                'session_id' => $session->id,
                'order' => $order['name'],
                'email' => $email,
                'shop' => $connection->external_eid
            ]);

            // Return session token for chat
            $this->sendJsonSuccess([
                'session_token' => $session->session_token,
                'order_name' => $order['name'],
                'customer_name' => trim(($order['customer']['firstName'] ?? '') . ' ' . ($order['customer']['lastName'] ?? ''))
            ], 'Order verified successfully');

        } catch (Exception $e) {
            $this->logger->error('Order verification failed', [
                'error' => $e->getMessage(),
                'order_number' => $orderNumber
            ]);
            $this->sendJsonError('Unable to verify order. Please try again.');
        }
    }

    /**
     * Chat interface
     *
     * GET /customersupport/chat/{session_token}
     */
    public function chat($params = []) {
        $sessionToken = $this->opId() ?? $this->getParam('session');

        $this->logger->debug('Chat endpoint called', [
            'session_token' => $sessionToken,
            'url' => Flight::request()->url,
            'workspace' => $this->workspace
        ]);

        if (empty($sessionToken)) {
            $this->renderError('Session token required');
            return;
        }

        $session = $this->getValidSession($sessionToken);
        if (!$session) {
            $this->logger->debug('Session validation failed', ['token' => $sessionToken]);
            $this->renderError('Invalid or expired session');
            return;
        }

        // Get connection info for branding
        $connection = Bean::load('connections', (int)$session->connections_id);
        $shopInfo = [
            'shop_name' => $connection->display_name ?: 'Store',
            'shop_domain' => $connection->external_eid
        ];

        // Get order context
        $orderData = $session->getVerifiedOrder();

        // Check for embed mode
        $embed = (bool)($this->getParam('embed') ?? false);

        // Render chat interface
        $this->supportRenderView('customersupport/chat', [
            'title' => 'Chat Support',
            'session_token' => $sessionToken,
            'shop_info' => $shopInfo,
            'order' => $orderData,
            'messages' => $session->getMessages(),
            'customer_name' => trim(($orderData['customer']['first_name'] ?? '') . ' ' . ($orderData['customer']['last_name'] ?? '')),
            'embed' => $embed
        ]);
    }

    /**
     * Send message to agent
     *
     * POST /customersupport/message/{session_token}
     */
    public function message($params = []) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonError('POST required', 405);
            return;
        }

        $sessionToken = $this->opId() ?? $this->getParam('session');
        if (empty($sessionToken)) {
            $this->sendJsonError('Session token required');
            return;
        }

        $session = $this->getValidSession($sessionToken);
        if (!$session) {
            $this->sendJsonError('Invalid or expired session');
            return;
        }

        $input = $this->getJsonInput();
        $message = trim($input['message'] ?? '');

        if (empty($message)) {
            $this->sendJsonError('Message required');
            return;
        }

        // Check message length
        if (strlen($message) > 2000) {
            $this->sendJsonError('Message too long (max 2000 characters)');
            return;
        }

        try {
            // Add customer message to session history
            $session->addMessage('user', $message);

            // Load chatbot for trigger matching (if available)
            $connection = Bean::load('connections', (int)$session->connections_id);
            $chatbot = $session->chatbots_id
                ? Bean::load('chatbots', (int)$session->chatbots_id)
                : null;

            // Check for configurable triggers (falls back to hardcoded if no chatbot)
            $shouldEscalate = false;
            $triggerMatch = null;

            if ($chatbot && $chatbot->id) {
                $triggerMatch = $chatbot->box('Model_Chatbots')->matchTrigger($message);
                if ($triggerMatch) {
                    if (in_array($triggerMatch['action'], ['escalate', 'escalate_and_run'])) {
                        $shouldEscalate = true;
                    }
                }
                $pipelineId = (int)($chatbot->pipelines_id ?? 0) ?: null;
            } else {
                // Legacy fallback: hardcoded escalation keywords
                $escalationKeywords = ['refund', 'cancel', 'manager', 'human', 'supervisor', 'complaint'];
                $lowerMessage = strtolower($message);
                foreach ($escalationKeywords as $keyword) {
                    if (strpos($lowerMessage, $keyword) !== false) {
                        $shouldEscalate = true;
                        break;
                    }
                }
                $metadata = json_decode($connection->metadata_json ?: '{}', true);
                $pipelineId = $metadata['pipelines_id'] ?? null;
            }

            // Start pipeline if not yet started
            if ($pipelineId && !$session->pipelineruns_id) {
                if (!$this->startChatPipeline($session, $connection, $chatbot)) {
                    $this->sendJsonError('Failed to start support pipeline');
                    return;
                }
            }

            // Send message to AI via pipeline
            if ($session->pipelineruns_id) {
                $this->sendMessageToPipeline($session, $message);
            } else {
                $this->sendJsonError('No support pipeline configured');
                return;
            }

            // Handle escalation
            if ($shouldEscalate) {
                $reason = $triggerMatch
                    ? 'Trigger: ' . ($triggerMatch['name'] ?? 'keyword match')
                    : 'Keyword trigger: ' . $message;
                $session->markEscalated($reason);
            }

            // Extend session expiry on activity
            $session->extendExpiry();

            Bean::store($session);

            // Get the AI response (last assistant message) to return directly
            $messages = $session->getMessages();
            $aiResponse = null;
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if ($messages[$i]['role'] === 'assistant') {
                    $aiResponse = $messages[$i]['content'];
                    break;
                }
            }

            $this->sendJsonSuccess([
                'message_id' => count($messages),
                'ai_response' => $aiResponse,
                'escalated' => $shouldEscalate
            ], 'Message sent');

        } catch (Exception $e) {
            $this->logger->error('Failed to send message', [
                'error' => $e->getMessage(),
                'session' => $sessionToken
            ]);
            $this->sendJsonError('Pipeline error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Poll for new messages
     *
     * GET /customersupport/poll/{session_token}
     */
    public function poll($params = []) {
        $sessionToken = $this->opId() ?? $this->getParam('session');
        if (empty($sessionToken)) {
            $this->sendJsonError('Session token required');
            return;
        }

        $session = $this->getValidSession($sessionToken);
        if (!$session) {
            $this->sendJsonError('Invalid or expired session');
            return;
        }

        $lastMessageId = (int)($this->getParam('last_id') ?? 0);
        $messages = $session->getMessages();

        // Return only new messages
        $newMessages = array_slice($messages, $lastMessageId);

        $this->sendJsonSuccess([
            'messages' => $newMessages,
            'total_count' => count($messages),
            'status' => $session->verification_status,
            'expires_at' => $session->expires_at
        ]);
    }

    /**
     * Escalate session to human support
     *
     * POST /customersupport/escalate/{session_token}
     */
    public function escalate($params = []) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonError('POST required', 405);
            return;
        }

        $sessionToken = $this->opId() ?? $this->getParam('session');
        if (empty($sessionToken)) {
            $this->sendJsonError('Session token required');
            return;
        }

        $session = $this->getValidSession($sessionToken);
        if (!$session) {
            $this->sendJsonError('Invalid or expired session');
            return;
        }

        $input = $this->getJsonInput();
        $reason = trim($input['reason'] ?? 'Customer requested human support');

        try {
            $session->markEscalated($reason);
            $session->addMessage('system', 'This conversation has been escalated to human support. A team member will respond shortly.');
            Bean::store($session);

            $this->logger->info('Session escalated', [
                'session' => $sessionToken,
                'reason' => $reason
            ]);

            // TODO: Send notification to support team

            $this->sendJsonSuccess([
                'status' => 'escalated'
            ], 'Escalated to human support');

        } catch (Exception $e) {
            $this->logger->error('Failed to escalate', [
                'error' => $e->getMessage(),
                'session' => $sessionToken
            ]);
            $this->sendJsonError('Failed to escalate');
        }
    }

    /**
     * Check session validity
     *
     * GET /customersupport/status/{session_token}
     */
    public function status($params = []) {
        $sessionToken = $this->opId() ?? $this->getParam('session');
        if (empty($sessionToken)) {
            $this->sendJsonSuccess(['valid' => false]);
            return;
        }

        // Switch to workspace database if needed
        if ($this->workspace) {
            WorkspaceResolver::switchDatabase($this->workspace);
        }

        $session = Bean::findOne('customersessions', 'session_token = ?', [$sessionToken]);

        if (!$session || !$session->id) {
            $this->sendJsonSuccess(['valid' => false]);
            return;
        }

        // Check if expired
        if ($session->isExpired()) {
            $this->sendJsonSuccess(['valid' => false, 'reason' => 'expired']);
            return;
        }

        // Must be verified or escalated
        if (!$session->isVerified() && $session->verification_status !== 'escalated') {
            $this->sendJsonSuccess(['valid' => false, 'reason' => 'not_verified']);
            return;
        }

        $this->sendJsonSuccess([
            'valid' => true,
            'status' => $session->verification_status,
            'expires_at' => $session->expires_at
        ]);
    }

    /**
     * Email chat transcript to customer
     *
     * POST /customersupport/transcript/{session_token}
     */
    public function transcript($params = []) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonError('POST required', 405);
            return;
        }

        $sessionToken = $this->opId() ?? $this->getParam('session');
        if (empty($sessionToken)) {
            $this->sendJsonError('Session token required');
            return;
        }

        $session = $this->getValidSession($sessionToken);
        if (!$session) {
            $this->sendJsonError('Invalid or expired session');
            return;
        }

        $messages = $session->getMessages();
        if (empty($messages)) {
            $this->sendJsonError('No messages to send');
            return;
        }

        try {
            $connection = Bean::load('connections', (int)$session->connections_id);
            $shopName = $connection->display_name ?: 'Store';
            $orderName = $session->order_name ?: 'Unknown';

            // Format transcript as markdown
            $markdown = "# Support Chat Transcript\n\n";
            $markdown .= "**Store:** {$shopName}\n";
            $markdown .= "**Order:** {$orderName}\n";
            $markdown .= "**Date:** " . date('F j, Y') . "\n\n";
            $markdown .= "---\n\n";

            foreach ($messages as $msg) {
                if ($msg['role'] === 'system') continue;
                $label = in_array($msg['role'], ['user', 'customer']) ? 'You' : 'Support Agent';
                $time = $msg['timestamp'] ?? '';
                $markdown .= "**{$label}** ({$time}):\n{$msg['content']}\n\n";
            }

            $markdown .= "---\n\n*This transcript was sent from {$shopName} customer support.*\n";

            // Send email via MailgunService
            $mailgun = new MailgunService();
            $subject = "Your Support Chat Transcript - Order {$orderName}";
            $sent = $mailgun->sendMarkdownEmail($subject, $markdown, $session->email);

            if ($sent) {
                $this->logger->info('Transcript sent', [
                    'session' => $sessionToken,
                    'email' => $session->email
                ]);
                $this->sendJsonSuccess([
                    'sent' => true
                ], 'Transcript sent to ' . $session->email);
            } else {
                $this->sendJsonError('Failed to send transcript email');
            }

        } catch (Exception $e) {
            $this->logger->error('Transcript send failed', [
                'error' => $e->getMessage(),
                'session' => $sessionToken
            ]);
            $this->sendJsonError('Failed to send transcript');
        }
    }

    /**
     * Resolve chatbot + connection from a widget token.
     * Tries chatbots table first, falls back to legacy connection metadata.
     *
     * @return array{chatbot: ?\RedBeanPHP\OODBBean, connection: ?\RedBeanPHP\OODBBean, pipeline_id: ?int}|null
     */
    private function resolveByToken(string $token): ?array {
        // Switch to workspace database if needed
        if ($this->workspace) {
            WorkspaceResolver::switchDatabase($this->workspace);
        }

        // Try chatbots table first
        $chatbot = \Model_Chatbots::findByToken($token);
        if ($chatbot) {
            $connection = $chatbot->connections_id
                ? Bean::load('connections', (int)$chatbot->connections_id)
                : null;
            return [
                'chatbot' => $chatbot,
                'connection' => ($connection && $connection->id) ? $connection : null,
                'pipeline_id' => $chatbot->pipelines_id ? (int)$chatbot->pipelines_id : null,
            ];
        }

        // Legacy fallback: find connection by public_agent_token in metadata_json
        $connection = Bean::findOne('connections',
            'connector_type = ? AND enabled = 1 AND metadata_json LIKE ?',
            ['shopify', '%' . $token . '%']
        );

        if (!$connection) {
            return null;
        }

        $metadata = json_decode($connection->metadata_json ?: '{}', true);
        if (($metadata['public_agent_token'] ?? '') !== $token) {
            return null;
        }

        return [
            'chatbot' => null,
            'connection' => $connection,
            'pipeline_id' => isset($metadata['pipelines_id']) ? (int)$metadata['pipelines_id'] : null,
        ];
    }

    /**
     * Legacy helper — kept for backward compatibility with verify/chat methods
     * that still reference getConnectionByToken directly.
     */
    private function getConnectionByToken(string $token): ?\RedBeanPHP\OODBBean {
        $resolved = $this->resolveByToken($token);
        return $resolved ? $resolved['connection'] : null;
    }

    /**
     * Get and validate a customer session
     */
    private function getValidSession(string $token): ?\RedBeanPHP\OODBBean {
        // Switch to workspace database if needed
        if ($this->workspace) {
            WorkspaceResolver::switchDatabase($this->workspace);
        }

        $session = Bean::findOne('customersessions', 'session_token = ?', [$token]);

        if (!$session || !$session->id) {
            return null;
        }

        // Check if expired
        if ($session->isExpired()) {
            $session->markExpired();
            Bean::store($session);
            return null;
        }

        // Must be verified
        if (!$session->isVerified() && $session->verification_status !== 'escalated') {
            return null;
        }

        return $session;
    }

    /**
     * Start a chat pipeline run for a new session
     * Creates the run, executes to first await_input, stores run_id + input_token
     */
    private function startChatPipeline($session, $connection, $chatbot = null): bool {
        try {
            require_once __DIR__ . '/../services/PipelineExecutor.php';

            // Get pipeline ID from chatbot first, fallback to connection metadata
            if ($chatbot && $chatbot->id && $chatbot->pipelines_id) {
                $pipelineId = (int)$chatbot->pipelines_id;
            } else {
                $metadata = json_decode($connection->metadata_json ?: '{}', true);
                $pipelineId = (int)($metadata['pipelines_id'] ?? 0);
            }
            if (!$pipelineId) {
                $this->logger->error('Pipeline ID not found', ['connection_id' => $connection->id, 'chatbot_id' => $chatbot ? $chatbot->id : null]);
                return false;
            }

            $pipeline = Bean::load('pipelines', $pipelineId);
            if (!$pipeline->id) {
                $this->logger->error('Pipeline not found', ['pipeline_id' => $pipelineId]);
                return false;
            }

            // Build order context string for the pipeline
            $orderData = $session->getVerifiedOrder() ?? [];
            $orderSummary = $this->buildOrderSummary($orderData, $session);
            $customerName = trim(($orderData['customer']['first_name'] ?? '') . ' ' . ($orderData['customer']['last_name'] ?? ''));
            $shopName = $connection->display_name ?: 'Store';

            // Fetch full order history for this customer
            $orderHistorySummary = '';
            try {
                $client = new ShopifyClient((int)$connection->id);
                $allOrders = $this->fetchCustomerOrders($client, $session->email);
                $orderHistorySummary = $this->buildOrderHistorySummary($allOrders);
            } catch (Exception $e) {
                $this->logger->debug('Order history fetch skipped', ['error' => $e->getMessage()]);
            }

            // Create pipeline run
            $stepCount = Bean::count('pipelinesteps', 'pipelines_id = ? AND is_active = 1', [$pipelineId]);
            $run = Bean::dispense('pipelineruns');
            $run->run_uid = 'run-' . bin2hex(random_bytes(8));
            $run->pipelines = $pipeline;
            $run->member_id = $connection->created_by_member_id ?: $connection->member_id;
            $run->trigger_source = 'customer_support';
            $run->trigger_data_json = json_encode([
                'session_token' => $session->session_token,
                'connections_id' => $connection->id,
            ]);
            $run->status = 'pending';
            $contextData = [
                'shop_name' => $shopName,
                'customer_name' => $customerName,
                'customer_email' => $session->email,
                'order_name' => $orderData['name'] ?? $session->order_name ?? 'Unknown',
                'order_data' => $orderSummary,
            ];
            if (!empty($orderHistorySummary)) {
                $contextData['order_history'] = $orderHistorySummary;
            }
            $run->context_json = json_encode($contextData);
            $run->steps_total = $stepCount;
            $run->steps_completed = 0;
            $run->progress_percent = 0;
            $run->created_at = date('Y-m-d H:i:s');
            $run->updated_at = date('Y-m-d H:i:s');
            $runId = Bean::store($run);

            $pipeline->run_count = ($pipeline->run_count ?? 0) + 1;
            $pipeline->last_run_at = date('Y-m-d H:i:s');
            Bean::store($pipeline);

            // Execute pipeline synchronously - it will pause at the first await_input
            $workspace = $this->workspace ?: 'dev';
            \app\services\PipelineDispatcher::dispatch($runId, $workspace, true);

            // Get the input token from the awaiting step run
            $awaitingStep = Bean::findOne('pipelinestepruns',
                ' pipelineruns_id = ? AND awaiting_input = 1 ',
                [$runId]
            );

            if (!$awaitingStep) {
                $this->logger->error('Pipeline did not pause at await_input', ['run_id' => $runId]);
                return false;
            }

            // Store pipeline state on session
            $session->pipelineruns_id = $runId;
            $context = $session->getContext();
            $context['pipeline_input_token'] = $awaitingStep->awaiting_input_token;
            $session->setContext($context);
            Bean::store($session);

            $this->logger->info('Chat pipeline started', [
                'run_id' => $runId,
                'input_token' => $awaitingStep->awaiting_input_token,
                'session' => $session->session_token
            ]);

            return true;

        } catch (Exception $e) {
            $this->logger->error('Failed to start chat pipeline', [
                'error' => $e->getMessage(),
                'session' => $session->session_token
            ]);
            return false;
        }
    }

    /**
     * Send a message to the AI via the pipeline
     * Resumes the pipeline from await_input, gets AI response, stores new token
     */
    private function sendMessageToPipeline($session, string $message): void {
        try {
            require_once __DIR__ . '/../services/PipelineExecutor.php';

            $runId = (int)$session->pipelineruns_id;
            $context = $session->getContext();
            $inputToken = $context['pipeline_input_token'] ?? null;

            if (!$runId || !$inputToken) {
                throw new Exception("No pipeline run ({$runId}) or input token for session {$session->session_token}");
            }

            // Build conversation history string from session messages
            $conversationHistory = $this->buildConversationHistory($session->getMessages());

            $this->logger->debug('Resuming pipeline', [
                'run_id' => $runId,
                'message_length' => strlen($message),
                'history_length' => strlen($conversationHistory),
                'session' => $session->session_token
            ]);

            // RAG search — use chatbot's linked knowledge bases if available
            $ragContext = '';
            try {
                $workspace = $this->workspace ?: 'dev';
                $store = \app\services\AssistKnowledgeStore::forWorkspace($workspace);

                // Get KB IDs from chatbot's linked KBs
                $kbIds = [];
                if ($chatbot && $chatbot->id) {
                    $links = Bean::find('chatbotkbs', ' chatbots_id = ? ', [(int)$chatbot->id]);
                    foreach ($links as $link) {
                        $kbIds[] = (int)$link->knowledgebases_id;
                    }
                }

                // Search both entries and chunks filtered by chatbot's KBs
                $ragResults = $store->searchAll($message, 5, 0.3, $kbIds);
                if (!empty($ragResults)) {
                    $ragContext = $this->formatRagResults($ragResults);
                }
            } catch (Exception $e) {
                $this->logger->debug('RAG search skipped', ['error' => $e->getMessage()]);
            }

            // Resume pipeline with message + conversation history + RAG context
            $resumeData = [
                'message' => $message,
                'conversation_history' => $conversationHistory,
            ];
            if (!empty($ragContext)) {
                $resumeData['product_context'] = $ragContext;
            }
            $workspace = $this->workspace ?: 'dev';
            $result = \app\services\PipelineDispatcher::resume(
                $runId,
                $workspace,
                $resumeData,
                $inputToken,
                'form'
            );

            if (!$result['success']) {
                throw new Exception("Pipeline resume failed: " . ($result['error'] ?? 'Unknown'));
            }

            // Extract AI response from the pipeline run context
            // After the loop resets, generate_response is archived to generate_response_prev
            $run = Bean::load('pipelineruns', $runId);
            $pipelineContext = json_decode($run->context_json ?: '{}', true);
            $aiResponse = $pipelineContext['generate_response']['output']['response']
                ?? $pipelineContext['generate_response_prev']['output']['response']
                ?? $pipelineContext['generate_response']['response']
                ?? $pipelineContext['generate_response_prev']['response']
                ?? null;

            if (empty($aiResponse)) {
                throw new Exception("No AI response in pipeline context. Keys: " . implode(', ', array_keys($pipelineContext)));
            }

            // Parse JSON-wrapped responses from the LLM
            // The AI sometimes returns structured JSON like {"response": "...", "follow_up": "..."}
            $aiResponse = $this->extractResponseText($aiResponse);

            // Add AI response to session
            $session->addMessage('assistant', $aiResponse);

            // Get the new input token for the next message
            if ($run->status === 'awaiting_input') {
                $awaitingStep = Bean::findOne('pipelinestepruns',
                    ' pipelineruns_id = ? AND awaiting_input = 1 ',
                    [$runId]
                );
                if ($awaitingStep) {
                    $ctx = $session->getContext();
                    $ctx['pipeline_input_token'] = $awaitingStep->awaiting_input_token;
                    $session->setContext($ctx);
                }
            }

            Bean::store($session);

            $this->logger->info('Pipeline message processed', [
                'run_id' => $runId,
                'response_length' => strlen($aiResponse),
                'status' => $run->status,
                'session' => $session->session_token
            ]);

        } catch (Exception $e) {
            $this->logger->error('Pipeline message failed', [
                'error' => $e->getMessage(),
                'session' => $session->session_token
            ]);
            throw $e;
        }
    }

    /**
     * Build a human-readable order summary for pipeline context
     */
    private function buildOrderSummary(array $orderData, $session): string {
        $orderName = $orderData['name'] ?? $session->order_name ?? 'Unknown';
        $orderTotal = isset($orderData['total'])
            ? ($orderData['total']['amount'] ?? '') . ' ' . ($orderData['total']['currencyCode'] ?? '')
            : 'Unknown';
        $financialStatus = $orderData['financial_status'] ?? 'Unknown';
        $fulfillmentStatus = $orderData['fulfillment_status'] ?? 'Unknown';

        $items = '';
        if (!empty($orderData['items'])) {
            foreach ($orderData['items'] as $item) {
                $items .= "- {$item['title']} (Qty: {$item['quantity']})\n";
            }
        }

        return "Order {$orderName}: Total {$orderTotal}, Payment: {$financialStatus}, Fulfillment: {$fulfillmentStatus}. Items:\n{$items}";
    }

    /**
     * Build conversation history string from session messages
     */
    private function buildConversationHistory(array $messages): string {
        $history = '';
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') continue;
            $label = in_array($msg['role'], ['user', 'customer']) ? 'Customer' : 'Agent';
            $history .= "{$label}: {$msg['content']}\n\n";
        }
        return $history ?: '(No previous messages)';
    }

    /**
     * Extract clean response text from AI output
     * The LLM sometimes returns JSON like {"response": "...", "follow_up": "..."}
     * This extracts just the human-readable text.
     */
    private function extractResponseText(string $text): string {
        $trimmed = trim($text);

        // Check if it looks like JSON
        if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
            $parsed = json_decode($trimmed, true);
            if ($parsed && json_last_error() === JSON_ERROR_NONE) {
                // Extract the response field, append follow_up if present
                $response = $parsed['response'] ?? $parsed['text'] ?? $parsed['message'] ?? null;
                if ($response) {
                    $followUp = $parsed['follow_up'] ?? $parsed['followUp'] ?? null;
                    if ($followUp) {
                        $response .= "\n\n" . $followUp;
                    }
                    return $response;
                }
            }
        }

        return $text;
    }

    /**
     * Format RAG search results for injection into AI context
     */
    private function formatRagResults(array $results): string {
        $formatted = "Relevant products/pages from the store:\n";
        foreach ($results as $result) {
            $title = $result['metadata']['title'] ?? 'Untitled';
            $url = $result['metadata']['url'] ?? '';
            $snippet = substr($result['content'] ?? '', 0, 200);
            $formatted .= "- **{$title}**";
            if ($url) {
                $formatted .= " ({$url})";
            }
            $formatted .= ": {$snippet}\n";
        }
        return $formatted;
    }

    /**
     * Fetch all orders for a customer email from Shopify
     */
    private function fetchCustomerOrders(ShopifyClient $client, string $email): array {
        $query = <<<'GRAPHQL'
        query getCustomerOrders($query: String!) {
            orders(first: 10, query: $query, sortKey: CREATED_AT, reverse: true) {
                edges {
                    node {
                        id
                        name
                        createdAt
                        displayFinancialStatus
                        displayFulfillmentStatus
                        totalPriceSet {
                            shopMoney {
                                amount
                                currencyCode
                            }
                        }
                        lineItems(first: 5) {
                            edges {
                                node {
                                    title
                                    quantity
                                }
                            }
                        }
                    }
                }
            }
        }
        GRAPHQL;

        try {
            $result = $client->graphql($query, ['query' => "email:{$email}"]);
            $orders = $result['data']['orders']['edges'] ?? [];
            return array_map(function($edge) {
                $node = $edge['node'];
                $items = array_map(function($item) {
                    return $item['node']['title'] . ' (x' . $item['node']['quantity'] . ')';
                }, $node['lineItems']['edges'] ?? []);
                return [
                    'name' => $node['name'],
                    'created_at' => $node['createdAt'],
                    'financial_status' => $node['displayFinancialStatus'],
                    'fulfillment_status' => $node['displayFulfillmentStatus'],
                    'total' => ($node['totalPriceSet']['shopMoney']['amount'] ?? '0') . ' ' .
                               ($node['totalPriceSet']['shopMoney']['currencyCode'] ?? 'USD'),
                    'items' => implode(', ', $items)
                ];
            }, $orders);
        } catch (Exception $e) {
            $this->logger->debug('Order history fetch failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Build order history summary string for AI context
     */
    private function buildOrderHistorySummary(array $orders): string {
        if (empty($orders)) return '';
        $summary = "Customer's order history:\n";
        foreach ($orders as $order) {
            $date = date('M j, Y', strtotime($order['created_at']));
            $summary .= "- {$order['name']} ({$date}): {$order['items']} - {$order['total']} - {$order['fulfillment_status']}\n";
        }
        return $summary;
    }

    /**
     * Render a view without the main layout
     */
    private function supportRenderView(string $view, array $data = []): void {
        extract($data);
        $viewFile = __DIR__ . '/../views/' . $view . '.php';
        if (file_exists($viewFile)) {
            include $viewFile;
        } else {
            $this->renderError('View not found');
        }
    }

    /**
     * Render an error page
     */
    private function renderError(string $message): void {
        http_response_code(400);
        echo '<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #1a1a2e; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .error-card { max-width: 400px; }
    </style>
</head>
<body>
    <div class="card error-card text-center">
        <div class="card-body py-5">
            <i class="bi bi-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
            <h4 class="mt-3">Unable to Load Support</h4>
            <p class="text-muted mb-0">' . h($message) . '</p>
        </div>
    </div>
</body>
</html>';
    }

    /**
     * JSON success response
     */
    protected function sendJsonSuccess(array $data, string $message = 'Success'): void {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
    }

    /**
     * JSON error response
     */
    protected function sendJsonError(string $message, int $code = 400): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message
        ]);
    }

    /**
     * Override getParam to work without parent
     */
    protected function getParam($key, $default = null) {
        return Flight::request()->query->$key
            ?? Flight::request()->data->$key
            ?? $default;
    }

    /**
     * Get operation ID from URL path (e.g., /customersupport/chat/abc123 -> abc123)
     */
    protected function opId(): ?string {
        $url = Flight::request()->url;
        // Strip query string if present
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $parts = explode('/', trim($path, '/'));
        return $parts[2] ?? null;
    }
}
