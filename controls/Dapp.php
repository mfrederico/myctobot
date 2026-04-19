<?php
/**
 * Dapp Controller - Hosted Detachable App Serving
 *
 * Serves detachable apps directly from MyCTOBot (hosted mode).
 * Stateless, public endpoint — no session required.
 *
 * URL patterns:
 *   /dapp                                → redirect to /detachableapps
 *   /dapp/{slug}                         → serve default screen
 *   /dapp/{slug}/{route}                 → serve screen by route_path
 *   /dapp/{slug}/api/chat               → proxy chat message (orchestrator)
 *   /dapp/{slug}/api/pipeline/{alias}    → proxy pipeline call
 *   /dapp/{slug}/assets/myctobot-sdk.js  → serve SDK JavaScript
 *   /dapp/{slug}/manifest.json           → serve app manifest
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\services\PipelineDispatcher;

class Dapp extends BaseControls\Control
{
    protected $logger;
    private ?string $workspace = null;

    public function __construct()
    {
        // Stateless: no parent::__construct(), no session/CSRF
        $this->logger = Flight::get('log');
        $this->workspace = $_SERVER['WORKSPACE'] ?? null;

        $this->setCorsHeaders();
    }

    /**
     * Main entry point — parse URI and dispatch
     */
    public function index($params = [])
    {
        // Handle OPTIONS pre-flight
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        // Switch to workspace database
        if ($this->workspace) {
            WorkspaceResolver::switchDatabase($this->workspace);
        }

        // Parse URI: /dapp/{slug}/{path...}
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/dapp', PHP_URL_PATH);
        $remainder = preg_replace('#^/dapp/?#', '', $uri);
        $parts = explode('/', $remainder, 2);

        $slug = $parts[0] ?? '';
        $path = $parts[1] ?? '';

        if (empty($slug)) {
            Flight::redirect('/detachableapps');
            return;
        }

        // Route to appropriate handler
        if ($path === 'manifest.json') {
            $this->serveManifest($slug);
        } elseif ($path === 'assets/myctobot-sdk.js') {
            $this->serveSdk($slug);
        } elseif ($path === 'chat' || $path === 'chat/') {
            $this->serveChat($slug);
        } elseif ($path === 'api/chat' || $path === 'api/chat/') {
            $this->proxyChat($slug);
        } elseif ($path === 'api/poll' || $path === 'api/poll/') {
            $this->pollMessages($slug);
        } elseif (str_starts_with($path, 'api/pipeline/')) {
            $alias = substr($path, strlen('api/pipeline/'));
            $alias = rtrim($alias, '/');
            $this->proxyPipeline($slug, $alias);
        } else {
            $routePath = '/' . ltrim($path, '/');
            $this->serveScreen($slug, $routePath);
        }
    }

    /**
     * Serve a screen's cached HTML with SDK injected
     */
    private function serveScreen(string $slug, string $path): void
    {
        $app = $this->loadApp($slug);
        if (!$app) return;

        // Find screen by route_path, fall back to default
        $screen = Bean::findOne('detachableappscreens',
            ' detachableapps_id = ? AND route_path = ? ',
            [(int) $app->id, $path]
        );

        if (!$screen && $path === '/') {
            $screen = Bean::findOne('detachableappscreens',
                ' detachableapps_id = ? AND is_default = 1 ',
                [(int) $app->id]
            );
        }

        if (!$screen) {
            $this->sendError(404, 'Page not found', $slug);
            return;
        }

        $html = $screen->html_cache ?? '';
        if (empty($html)) {
            // No built HTML — redirect to chat widget for chat-type dapps
            header("Location: /dapp/{$slug}/chat");
            return;
        }

        // Inject SDK at serve time
        $html = $this->injectSdk($html, $slug);

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');
        echo $html;
    }

    /**
     * Proxy a pipeline call from the hosted app
     */
    private function proxyPipeline(string $slug, string $alias): void
    {
        $app = $this->loadApp($slug);
        if (!$app) return;

        if (empty($alias)) {
            $this->sendJsonError(400, 'Pipeline alias required');
            return;
        }

        // Look up binding
        $binding = Bean::findOne('detachableapppipelines',
            ' detachableapps_id = ? AND alias = ? AND is_active = 1 ',
            [(int) $app->id, $alias]
        );

        if (!$binding) {
            $this->sendJsonError(404, 'Pipeline not bound to this app: ' . $alias);
            return;
        }

        // Parse input from request body
        $rawBody = file_get_contents('php://input');
        $input = json_decode($rawBody, true) ?: [];

        // Create pipeline run
        $run = Bean::dispense('pipelineruns');
        $run->pipelines_id = (int) $binding->pipelines_id;
        $run->status = 'pending';
        $run->trigger_type = 'api';
        $run->trigger_source = 'hosted_app';
        $run->context_json = json_encode(array_merge($input, [
            '_dapp_slug' => $slug,
            '_dapp_alias' => $alias,
        ]));
        $run->created_at = date('Y-m-d H:i:s');
        Bean::store($run);

        $workspace = $this->workspace ?: 'default';

        try {
            $result = PipelineDispatcher::dispatch((int) $run->id, $workspace, sync: true);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => $result['success'] ?? false,
                'run_id' => (int) $run->id,
                'status' => $result['status'] ?? 'unknown',
                'output' => $result['output'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Dapp pipeline proxy failed', [
                'slug' => $slug,
                'alias' => $alias,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
            $this->sendJsonError(500, 'Pipeline execution failed');
        }
    }

    /**
     * Serve the chat widget page
     */
    private function serveChat(string $slug): void
    {
        $app = $this->loadApp($slug);
        if (!$app) return;

        $appConfig = json_decode($app->config_json ?: '{}', true);
        $shopName = $appConfig['shop_name'] ?? $app->name ?? 'Store';

        // Check if embed mode requested (for iframe embedding)
        $embedMode = isset($_GET['embed']) && $_GET['embed'] === '1';

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');

        // Pass data to the view
        $botConfig = $appConfig['bot_personality'] ?? [];
        $viewData = [
            'slug' => $slug,
            'shop_name' => $shopName,
            'embed_mode' => $embedMode,
            'app_name' => $app->name,
            'bot_name' => $botConfig['name'] ?? null,
            'bot_greeting' => $botConfig['greeting'] ?? null,
        ];
        extract($viewData);

        include __DIR__ . '/../views/dapp/chat.php';
    }

    /**
     * Proxy a chat message through the orchestrator pipeline
     *
     * POST /dapp/{slug}/api/chat
     * Body: {"message": "...", "session_token": "sco_..."}
     * Returns: {"success": true, "session_token": "...", "response": {"text": "...", "rich": null}, "meta": {...}}
     */
    private function proxyChat(string $slug): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonError(405, 'Method not allowed');
            return;
        }

        $app = $this->loadApp($slug);
        if (!$app) return;

        // Parse request body (try php://input first, fall back to Flight request)
        $rawBody = file_get_contents('php://input');
        if (empty($rawBody)) {
            $rawBody = Flight::request()->getBody();
        }
        $body = json_decode($rawBody, true);
        if (!$body) {
            $this->sendJsonError(400, 'Invalid request body');
            return;
        }

        // Handle structured form submissions from any intent plugin (no message needed)
        $formAction = $body['form_action'] ?? null;
        if ($formAction) {
            $this->handleFormAction($slug, $app, $body, $formAction);
            return;
        }

        if (empty(trim($body['message'] ?? ''))) {
            $this->sendJsonError(400, 'Message required');
            return;
        }

        $message = trim($body['message']);
        if (mb_strlen($message) > 2000) {
            $this->sendJsonError(400, 'Message too long (max 2000 characters)');
            return;
        }

        $t0 = microtime(true);

        $sessionToken = $body['session_token'] ?? null;

        // Load or create session
        $session = null;
        if ($sessionToken) {
            $session = Bean::findOne('customersessions', 'session_token = ?', [$sessionToken]);
            if ($session) {
                $model = $session->box('Model_Customersessions');
                if ($model->isExpired()) {
                    $session = null; // Expired — create new
                }
            }
        }

        if (!$session) {
            $session = Bean::dispense('customersessions');
            $session->verification_status = 'verified';
            $session->verified_at = date('Y-m-d H:i:s');
            $context = [
                'source' => 'chat_orchestrator',
                'messages' => [],
                'dapp_slug' => $slug,
            ];
            $session->context_json = json_encode($context);
            Bean::store($session);
        }

        // Get session model and conversation history
        $sessionModel = $session->box('Model_Customersessions');

        // Escalated/resolved bypass — store message directly, no pipeline
        $sessionStatus = $session->verification_status ?? '';
        if (in_array($sessionStatus, ['escalated', 'resolved'])) {
            $sessionModel->addMessage('user', $message);
            $session->last_message_at = date('Y-m-d H:i:s');
            $sessionModel->extendExpiry();
            Bean::store($session);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'session_token' => $session->session_token,
                'response' => ['text' => '', 'rich' => null],
                'meta' => ['intent' => 'live_chat', 'sentiment' => 'neutral'],
                'escalated' => true,
                'agent_name' => $this->getAgentDisplayName($session),
            ]);
            return;
        }

        $existingMessages = $sessionModel->getMessages();

        // Format conversation history (last 20 entries = ~10 pairs)
        $recentMessages = array_slice($existingMessages, -20);
        $conversationHistory = '';
        foreach ($recentMessages as $msg) {
            $role = ($msg['role'] === 'user') ? 'Customer' : 'Assistant';
            $conversationHistory .= "{$role}: {$msg['content']}\n";
        }
        if (empty($conversationHistory)) {
            $conversationHistory = '(new conversation)';
        }

        // Get shop info from dapp config
        $appConfig = json_decode($app->config_json ?: '{}', true);
        $shopConnectionId = $appConfig['shopify_connection_id'] ?? 1;
        $shopName = $appConfig['shop_name'] ?? $app->name ?? 'Store';

        // Find the orchestrator pipeline bound to this dapp
        // Look for alias "chat" first, fall back to first active binding
        $binding = Bean::findOne('detachableapppipelines',
            ' detachableapps_id = ? AND alias = ? AND is_active = 1 ',
            [(int) $app->id, 'chat']
        );
        if (!$binding) {
            $binding = Bean::findOne('detachableapppipelines',
                ' detachableapps_id = ? AND is_active = 1 ',
                [(int) $app->id]
            );
        }
        if (!$binding) {
            $this->sendJsonError(404, 'No chat pipeline bound to this app');
            return;
        }

        // Build bot personality from config
        $botPersonality = $this->buildBotPersonality($appConfig);

        // Get previous interaction state from session
        $sessionContext = $sessionModel->getContext();
        $previousState = $sessionContext['interaction_state'] ?? null;

        // Structured LLM classifier — language-agnostic intent + param extraction
        $intentPlugins = $appConfig['intent_plugins'] ?? [];
        $classifier = new \app\services\ChatClassifier($appConfig['classifier'] ?? [], $appConfig, $this->logger);
        $classifyResult = $classifier->classify($message, $conversationHistory, $shopName, $sessionContext);

        if ($classifyResult) {
            $fastIntent = $classifyResult['intent'] ?? 'general';
            $fastConfidence = (float) ($classifyResult['confidence'] ?? 0);

            // Per-plugin or global confidence threshold
            $intentPlugin = $intentPlugins[$fastIntent] ?? null;
            $minConfidence = (float) ($intentPlugin['min_confidence']
                ?? $appConfig['classifier']['min_confidence']
                ?? 0.75);

            $this->logger->debug('Fast classify gate', [
                'intent' => $fastIntent,
                'confidence' => $fastConfidence,
                'min_confidence' => $minConfidence,
                'has_plugin' => $intentPlugin !== null,
                'has_form' => !empty($intentPlugin['form']),
            ]);

            // Escalate intent — handle immediately without plugin or pipeline
            if ($fastIntent === 'escalate' && $fastConfidence >= $minConfidence) {
                $sessionModel->addMessage('user', $message);

                if ($session->verification_status !== 'escalated') {
                    $sessionModel->markEscalated('Customer requested human agent');
                    $session->last_message_at = date('Y-m-d H:i:s');
                    Bean::store($session);
                    $this->tryAutoAssignAgent($session);
                    // Reload to pick up agent assignment
                    $session = Bean::load('customersessions', (int) $session->id);
                }

                $agentName = $this->getAgentDisplayName($session);
                $responseText = $agentName
                    ? "You've been connected with {$agentName}. They'll be with you shortly."
                    : "I'm connecting you with a support agent. Please hold on — someone will be with you shortly.";

                $sessionModel->addMessage('assistant', $responseText);
                $sessionModel->extendExpiry();
                $this->updateSessionSentimentAndSubject($session, 'escalate', 'frustrated', $message, $classifyResult);
                Bean::store($session);

                header('Content-Type: application/json');
                $newState = 'frustrated';
                $sCtx = $sessionModel->getContext();
                $sCtx['interaction_state'] = $newState;
                $sessionModel->setContext($sCtx);
                Bean::store($session);
                echo json_encode([
                    'success' => true,
                    'session_token' => $session->session_token,
                    'response' => ['text' => $responseText, 'type' => 'text'],
                    'meta' => ['intent' => 'escalate', 'confidence' => $fastConfidence, 'sentiment' => 'frustrated'],
                    'escalated' => true,
                    'bot_name' => $botPersonality['name'],
                    'suggested_actions' => $this->getSuggestedActions($newState, 'escalate'),
                ]);
                return;
            }

            // Greeting intent — handle directly without pipeline
            if ($fastIntent === 'greeting' && $fastConfidence >= $minConfidence) {
                $lower = mb_strtolower($message);
                $isGoodbye = preg_match('/\b(bye|goodbye|see ya|take care|cheers)\b/', $lower);
                $isThanks = preg_match('/\b(thanks?|thank you|thx)\b/', $lower);
                $botName = $botPersonality['name'];

                if ($isThanks) {
                    $responseText = "You're welcome! Is there anything else I can help you with?";
                } elseif ($isGoodbye) {
                    $responseText = "Goodbye! Feel free to come back anytime. Have a great day!";
                } else {
                    $responseText = "Hi there! I'm {$botName}, your shopping assistant. How can I help you today? I can help you find products, check order status, or answer questions about our store.";
                }

                $fastSentiment = $classifyResult['sentiment'] ?? 'neutral';
                $newState = $this->determineInteractionState($fastIntent, $fastSentiment, $previousState, $sessionContext);
                $sessionModel->addMessage('user', $message);
                $sessionModel->addMessage('assistant', $responseText);
                $sessionModel->extendExpiry();
                $sCtx = $sessionModel->getContext();
                $sCtx['last_intent'] = $fastIntent;
                $sCtx['interaction_state'] = $newState;
                $sessionModel->setContext($sCtx);
                $this->updateSessionSentimentAndSubject($session, $fastIntent, $fastSentiment, $message, $classifyResult);
                Bean::store($session);

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'session_token' => $session->session_token,
                    'response' => ['text' => $responseText, 'type' => 'text'],
                    'meta' => ['intent' => $fastIntent, 'confidence' => $fastConfidence, 'sentiment' => $fastSentiment],
                    'escalated' => false,
                    'bot_name' => $botName,
                    'suggested_actions' => $this->getSuggestedActions($newState, $fastIntent),
                ]);
                return;
            }

            // FAQ intent — handle directly with local LLM (avoids pipeline's cloud model dependency)
            if ($fastIntent === 'faq' && $fastConfidence >= $minConfidence) {
                $responseText = $this->handleFaqDirect($message, $conversationHistory, $appConfig, $botPersonality);
                if ($responseText) {
                    $fastSentiment = $classifyResult['sentiment'] ?? 'neutral';
                    $newState = $this->determineInteractionState($fastIntent, $fastSentiment, $previousState, $sessionContext);
                    $sessionModel->addMessage('user', $message);
                    $sessionModel->addMessage('assistant', $responseText);
                    $sessionModel->extendExpiry();
                    $sCtx = $sessionModel->getContext();
                    $sCtx['last_intent'] = $fastIntent;
                    $sCtx['interaction_state'] = $newState;
                    $sessionModel->setContext($sCtx);
                    $this->updateSessionSentimentAndSubject($session, $fastIntent, $fastSentiment, $message, $classifyResult);
                    Bean::store($session);

                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'session_token' => $session->session_token,
                        'response' => ['text' => $responseText, 'type' => 'text'],
                        'meta' => ['intent' => $fastIntent, 'confidence' => $fastConfidence, 'sentiment' => $fastSentiment],
                        'escalated' => false,
                        'bot_name' => $botPersonality['name'],
                        'suggested_actions' => $this->getSuggestedActions($newState, $fastIntent),
                    ]);
                    return;
                }
                // If handleFaqDirect fails, fall through to pipeline
            }

            if ($intentPlugin && !empty($intentPlugin['form']) && $fastConfidence >= $minConfidence) {
                // High confidence plugin match — check if we have enough data to act directly
                $sessionEmail = $session->email ?? null;
                if (!$sessionEmail && !empty($classifyResult['customer_email'])) {
                    $sessionEmail = $classifyResult['customer_email'];
                }

                $aType = $intentPlugin['action']['type'] ?? '';
                $hasActionData = $this->classifierHasActionData($classifyResult, $intentPlugin);
                $this->logger->debug('Fast classify action', [
                    'has_action_data' => $hasActionData,
                    'action_type' => $aType,
                    'session_email' => $sessionEmail ? '***' : null,
                    'order_query' => $classifyResult['order_query'] ?? null,
                ]);

                if ($hasActionData) {
                    // Fast path: execute action directly (e.g. order lookup with extracted data)
                    if ($aType === 'shopify_order_lookup' && $sessionEmail) {
                        $orderQuery = $classifyResult['order_query'] ?? $message;
                        $directResult = $this->lookupOrder($shopConnectionId, $orderQuery, $sessionEmail);
                        if ($directResult) {
                            $fastSentiment = $classifyResult['sentiment'] ?? 'neutral';
                            $newState = $this->determineInteractionState($fastIntent, $fastSentiment, $previousState, $sessionContext);
                            $sessionModel->addMessage('user', $message);
                            $sessionModel->addMessage('assistant', $directResult['text'], $directResult['rich'] ?? null);
                            $sessionModel->extendExpiry();
                            $sCtx = $sessionModel->getContext();
                            $sCtx['last_intent'] = $fastIntent;
                            $sCtx['last_classify'] = $classifyResult;
                            $sCtx['interaction_state'] = $newState;
                            $sCtx['message_count'] = count($sessionModel->getMessages());
                            $sessionModel->setContext($sCtx);
                            $this->updateSessionSentimentAndSubject($session, $fastIntent, $fastSentiment, $message, $classifyResult);
                            Bean::store($session);

                            header('Content-Type: application/json');
                            echo json_encode([
                                'success' => true,
                                'session_token' => $session->session_token,
                                'response' => $directResult,
                                'meta' => ['intent' => $fastIntent, 'confidence' => $fastConfidence, 'sentiment' => $fastSentiment],
                                'escalated' => false,
                                'bot_name' => $botPersonality['name'],
                                'suggested_actions' => $this->getSuggestedActions($newState, $fastIntent),
                            ]);
                            return;
                        }
                    }
                    // Other action types with extracted data — fall through to show form
                }

                // Show form with prefill from session + classifier-extracted data
                $prefill = $this->buildFormPrefill($session, $sessionModel);
                // Merge classifier-extracted data into prefill
                if (!empty($classifyResult['customer_email']) && empty($prefill['email'])) {
                    $prefill['email'] = $classifyResult['customer_email'];
                }
                if (!empty($classifyResult['order_query']) && empty($prefill['order_number'])) {
                    $extracted = $classifyResult['order_query'];
                    if (preg_match('/#?(\d+)/', $extracted, $m)) {
                        $prefill['order_number'] = $m[1];
                    }
                }
                // Prefill details/textarea with the original message
                if (!empty($message) && empty($prefill['details'])) {
                    $prefill['details'] = $message;
                }

                $response = $this->buildFormResponse(
                    $intentPlugin, $fastIntent, $prefill, $intentPlugins, $sessionModel, $message
                );

                $fastSentiment = $classifyResult['sentiment'] ?? 'neutral';
                $newState = $this->determineInteractionState($fastIntent, $fastSentiment, $previousState, $sessionContext);
                $sessionModel->addMessage('user', $message);
                $sessionModel->addMessage('assistant', $response['text'], $response['rich'] ?? null);
                $sessionModel->extendExpiry();
                $sCtx = $sessionModel->getContext();
                $sCtx['last_intent'] = $fastIntent;
                $sCtx['last_classify'] = $classifyResult;
                $sCtx['interaction_state'] = $newState;
                $sCtx['message_count'] = count($sessionModel->getMessages());
                $sessionModel->setContext($sCtx);
                $this->updateSessionSentimentAndSubject($session, $fastIntent, $fastSentiment, $message, $classifyResult);
                Bean::store($session);

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'session_token' => $session->session_token,
                    'response' => $response,
                    'meta' => ['intent' => $fastIntent, 'confidence' => $fastConfidence, 'sentiment' => $fastSentiment],
                    'escalated' => false,
                    'bot_name' => $botPersonality['name'],
                    'suggested_actions' => $this->getSuggestedActions($newState, $fastIntent),
                ]);
                return;
            }
            // Low confidence or no plugin — fall through to full pipeline
        }
        // Ollama failed or returned null — fall through to full pipeline

        // Search knowledge base for FAQ/general intents or low-confidence results
        $kbContext = '';
        $exemplarContext = '';
        $currentIntent = $classifyResult['intent'] ?? 'general';
        $currentSentiment = $classifyResult['sentiment'] ?? 'neutral';
        $currentState = $this->determineInteractionState($currentIntent, $currentSentiment, $previousState, $sessionContext);

        if (in_array($currentIntent, ['faq', 'general', 'product_search']) || ($classifyResult && ($classifyResult['confidence'] ?? 1) < 0.6)) {
            $kbContext = $this->searchKnowledgeBase($message, $appConfig);
        }

        // Retrieve exemplar conversations from interaction memory
        $exemplarContext = $this->getExemplarConversations($currentIntent, $currentState, $appConfig);

        // Create pipeline run with message context
        $run = Bean::dispense('pipelineruns');
        $run->pipelines_id = (int) $binding->pipelines_id;
        $run->status = 'pending';
        $run->trigger_type = 'api';
        $run->trigger_source = 'chat_orchestrator';
        $crawledProducts = $this->searchCrawledProducts($message, $shopConnectionId);

        $pipelineContext = [
            'message' => $message,
            'session_token' => $session->session_token,
            'conversation_history' => $conversationHistory,
            'product_context' => $crawledProducts,
            'shop_connection_id' => $shopConnectionId,
            'shop_name' => $shopName,
            'customer_email' => $session->email ?? '',
            'customer_name' => '',
            '_dapp_slug' => $slug,
            // Personality and interaction state for handler prompts
            'bot_personality' => $botPersonality['prompt_block'],
            'bot_name' => $botPersonality['name'],
            'interaction_state' => $currentState,
            'policies_summary' => $botPersonality['policies_summary'],
            // RAG context
            'kb_context' => $kbContext,
            'exemplar_context' => $exemplarContext,
        ];
        // When fast classify succeeded with high confidence, skip the pipeline's
        // classify step entirely — pre-seed its output and start from sentiment_gate
        if ($classifyResult) {
            $pipelineContext['_fast_classify'] = $classifyResult;
            $fcIntent = $classifyResult['intent'] ?? '';
            $fcConf = (float) ($classifyResult['confidence'] ?? 0);
            if ($fcIntent && $fcConf >= 0.75) {
                // Ensure sentiment fields exist for the sentiment_gate condition
                $seeded = $classifyResult;
                if (!isset($seeded['sentiment_score'])) $seeded['sentiment_score'] = 0.0;
                if (!isset($seeded['sentiment_label'])) $seeded['sentiment_label'] = $classifyResult['sentiment'] ?? 'neutral';
                $pipelineContext['classify'] = [
                    'output' => ['parsed' => $seeded],
                ];
                $run->entry_step = 'sentiment_gate';
            }
        }
        $run->context_json = json_encode($pipelineContext);
        $run->created_at = date('Y-m-d H:i:s');
        Bean::store($run);

        $workspace = $this->workspace ?: 'default';

        try {
            PipelineDispatcher::dispatch((int) $run->id, $workspace, sync: true);

            // Reload run to get full context with step outputs
            $run = Bean::load('pipelineruns', (int) $run->id);
            $runContext = json_decode($run->context_json ?: '{}', true);

            // Extract classify output for intent + sentiment
            $classifyOutput = $this->extractStepParsed($runContext, 'classify');
            $meta = [
                'intent' => $classifyOutput['intent'] ?? 'unknown',
                'sentiment' => $classifyOutput['sentiment_label'] ?? 'neutral',
            ];

            // Post-pipeline: check if classified intent has a plugin with a form
            $intent = $meta['intent'];
            $intentPlugin = $intentPlugins[$intent] ?? null;

            if ($intentPlugin && !empty($intentPlugin['form']) && !empty($intentPlugin['action'])) {
                // Check if classifier extracted enough data to act directly
                if ($this->classifierHasActionData($classifyOutput, $intentPlugin)) {
                    $response = $this->executeIntentAction($intentPlugin, $classifyOutput, $appConfig);
                } else {
                    // Show form — classifier didn't get enough data
                    $prefill = $this->buildFormPrefill($session, $sessionModel);
                    if (!empty($message) && empty($prefill['details'])) {
                        $prefill['details'] = $message;
                    }
                    $response = $this->buildFormResponse(
                        $intentPlugin, $intent, $prefill, $intentPlugins, $sessionModel, $message
                    );
                }
            } else {
                // No plugin: extract from handler step as before
                $response = $this->extractHandlerResponse($runContext, $intent);
            }

            // Compute interaction state from pipeline results
            $pipelineSentiment = $meta['sentiment'] ?? 'neutral';
            $newState = $this->determineInteractionState($meta['intent'], $pipelineSentiment, $previousState, $sessionContext);

            // Save messages to session
            $sessionModel->addMessage('user', $message);
            $sessionModel->addMessage('assistant', $response['text'], $response['rich'] ?? null);
            $sessionModel->extendExpiry();

            // Update session metadata
            $sCtx = $sessionModel->getContext();
            $sCtx['last_intent'] = $meta['intent'];
            $sCtx['interaction_state'] = $newState;
            $sCtx['message_count'] = count($sessionModel->getMessages());
            $sessionModel->setContext($sCtx);
            $this->updateSessionSentimentAndSubject($session, $meta['intent'], $pipelineSentiment, $message, $classifyResult);
            Bean::store($session);

            // Store conversation in KB if interaction state indicates completion
            if (in_array($newState, ['satisfied']) || count($sessionModel->getMessages()) >= 10) {
                $this->classifyAndStoreConversation($session, $appConfig);
            }

            // Check for escalation
            $isEscalated = ($meta['intent'] === 'escalate');
            if ($isEscalated && $session->verification_status !== 'escalated') {
                $sessionModel->markEscalated('Customer requested escalation');
                $session->last_message_at = date('Y-m-d H:i:s');
                Bean::store($session);
                $this->tryAutoAssignAgent($session);
                $newState = 'frustrated';
            }

            $this->logger->debug('Chat response time', [
                'ms' => round((microtime(true) - $t0) * 1000),
                'intent' => $meta['intent'] ?? 'unknown',
                'source' => $classifyResult['_source'] ?? 'llm',
            ]);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'session_token' => $session->session_token,
                'response' => $response,
                'meta' => $meta,
                'escalated' => $isEscalated,
                'bot_name' => $botPersonality['name'],
                'suggested_actions' => $this->getSuggestedActions($newState, $meta['intent']),
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Chat orchestrator failed', [
                'slug' => $slug,
                'run_id' => $run->id ?? null,
                'error' => $e->getMessage(),
            ]);
            $this->sendJsonError(500, 'Chat processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Serve the SDK JavaScript
     */
    private function serveSdk(string $slug): void
    {
        $baseUrl = "/dapp/{$slug}";

        $js = <<<JS
/**
 * MyCTOBot Hosted App SDK
 * Auto-injected at serve time for hosted detachable apps.
 */
(function() {
    'use strict';

    const MYCTOBOT_SDK = {
        baseUrl: '{$baseUrl}',

        /**
         * Call a bound pipeline by alias
         */
        async callPipeline(alias, input = {}) {
            const url = this.baseUrl + '/api/pipeline/' + encodeURIComponent(alias);
            const resp = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(input)
            });
            return resp.json();
        },

        /**
         * Auto-bind forms with data-pipeline attribute
         */
        bindForms() {
            document.querySelectorAll('form[data-pipeline]').forEach(form => {
                if (form._mctobotBound) return;
                form._mctobotBound = true;

                form.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const alias = form.dataset.pipeline;
                    const data = Object.fromEntries(new FormData(form));

                    // Disable submit button
                    const btn = form.querySelector('[type="submit"]');
                    const origText = btn ? btn.textContent : '';
                    if (btn) { btn.disabled = true; btn.textContent = 'Sending...'; }

                    try {
                        const result = await MYCTOBOT_SDK.callPipeline(alias, data);
                        form.dispatchEvent(new CustomEvent('pipeline:result', { detail: result }));

                        // If there's a data-pipeline-success element, show result
                        const successEl = form.querySelector('[data-pipeline-success]');
                        if (successEl) {
                            successEl.style.display = '';
                            successEl.textContent = successEl.dataset.pipelineSuccess || 'Success!';
                        }
                    } catch (err) {
                        form.dispatchEvent(new CustomEvent('pipeline:error', { detail: err }));
                    } finally {
                        if (btn) { btn.disabled = false; btn.textContent = origText; }
                    }
                });
            });
        },

        /**
         * Auto-load content for elements with data-pipeline-load
         */
        async loadElements() {
            const els = document.querySelectorAll('[data-pipeline-load]');
            for (const el of els) {
                if (el._mctobotLoaded) continue;
                el._mctobotLoaded = true;

                const alias = el.dataset.pipelineLoad;
                let input = {};
                try { input = JSON.parse(el.dataset.pipelineInput || '{}'); } catch(e) {}

                try {
                    const result = await this.callPipeline(alias, input);
                    el.dispatchEvent(new CustomEvent('pipeline:loaded', { detail: result }));

                    if (result.output && result.output.html) {
                        el.innerHTML = result.output.html;
                    } else if (result.output && result.output.text) {
                        el.textContent = result.output.text;
                    }
                } catch (err) {
                    el.dispatchEvent(new CustomEvent('pipeline:error', { detail: err }));
                }
            }
        }
    };

    // Expose globally
    window.MyCTOBot = MYCTOBOT_SDK;

    // Auto-bind on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            MYCTOBOT_SDK.bindForms();
            MYCTOBOT_SDK.loadElements();
        });
    } else {
        MYCTOBOT_SDK.bindForms();
        MYCTOBOT_SDK.loadElements();
    }
})();
JS;

        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        echo $js;
    }

    /**
     * Serve app manifest (JSON metadata)
     */
    private function serveManifest(string $slug): void
    {
        $app = $this->loadApp($slug);
        if (!$app) return;

        $screens = Bean::find('detachableappscreens',
            ' detachableapps_id = ? ORDER BY sort_order ASC ',
            [(int) $app->id]
        );

        $screenList = [];
        foreach ($screens as $s) {
            $screenList[] = [
                'title' => $s->screen_title,
                'route_path' => $s->route_path,
                'is_default' => (bool) $s->is_default,
            ];
        }

        $bindings = Bean::find('detachableapppipelines',
            ' detachableapps_id = ? AND is_active = 1 ',
            [(int) $app->id]
        );

        $pipelineList = [];
        foreach ($bindings as $b) {
            $pipelineList[] = [
                'alias' => $b->alias,
                'pipeline_id' => (int) $b->pipelines_id,
            ];
        }

        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=60');
        echo json_encode([
            'name' => $app->name,
            'slug' => $app->slug,
            'description' => $app->description,
            'screens' => $screenList,
            'pipelines' => $pipelineList,
        ], JSON_PRETTY_PRINT);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Load and validate app for hosted serving
     */
    private function loadApp(string $slug): ?\RedBeanPHP\OODBBean
    {
        $workspace = $this->workspace ?: 'default';

        $app = Bean::findOne('detachableapps',
            ' slug = ? AND workspace = ? ',
            [$slug, $workspace]
        );

        if (!$app) {
            $this->sendError(404, 'App not found', $slug);
            return null;
        }

        if (!(int) $app->is_hosted) {
            $this->sendError(403, 'Hosted mode is not enabled for this app', $slug);
            return null;
        }

        return $app;
    }

    /**
     * Inject SDK script tag before </body>
     */
    private function injectSdk(string $html, string $slug): string
    {
        $sdkUrl = "/dapp/{$slug}/assets/myctobot-sdk.js";
        $sdkTag = '<script src="' . $sdkUrl . '"></script>';

        // Try to inject before </body>
        if (stripos($html, '</body>') !== false) {
            return str_ireplace('</body>', $sdkTag . "\n</body>", $html);
        }

        // Fallback: append
        return $html . "\n" . $sdkTag;
    }

    /**
     * Set CORS headers
     */
    private function setCorsHeaders(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    }

    /**
     * Send HTML error page
     */
    private function sendError(int $code, string $message, string $slug): void
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');

        echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$code} - {$message}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="alert alert-danger">
            <h4>{$code}</h4>
            <p>{$message}</p>
            <p class="mb-0"><small>App: <code>{$slug}</code></small></p>
        </div>
        <a href="/detachableapps" class="btn btn-outline-secondary">Back to Apps</a>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Send JSON error response
     */
    private function sendJsonError(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message]);
    }

    /**
     * Extract the parsed output from an ai_agent step in pipeline context.
     *
     * ai_agent steps store: {output: {response: "raw string", parsed: {...}}}
     * This extracts the `parsed` object (or falls back to parsing `response`).
     */
    private function extractStepParsed(array $runContext, string $stepName): ?array
    {
        $step = $runContext[$stepName] ?? null;
        if (!is_array($step)) return null;

        $output = $step['output'] ?? $step;

        // Prefer parsed (pre-parsed JSON from json_output: true)
        if (isset($output['parsed']) && is_array($output['parsed'])) {
            return $output['parsed'];
        }

        // Fallback: try to parse the raw response string
        if (isset($output['response']) && is_string($output['response'])) {
            $decoded = json_decode(trim($output['response']), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Fallback: if output itself has the expected keys
        if (isset($output['text']) || isset($output['intent'])) {
            return $output;
        }

        return null;
    }

    /**
     * Extract {text, rich} response from whichever handler ran.
     *
     * LLM handlers output {text, rich} directly (json_output: true).
     * Shopify GraphQL handlers output raw API data — we format it here.
     */
    private function extractHandlerResponse(array $runContext, string $intent): array
    {
        $default = ['text' => 'I apologize, but I was unable to process your message. Please try again.', 'rich' => null];

        // Map intent → handler step name
        $handlerMap = [
            'product_search' => 'handle_product_search',
            'order_status'   => 'handle_order_status',
            'order_update'   => 'handle_general',
            'order_cancel'   => 'handle_general',
            'return_request' => 'handle_return',
            'spare_parts'    => 'handle_spare_parts',
            'repair_request' => 'handle_repair',
            'send_transcript' => 'handle_general',
            'faq'            => 'handle_faq',
            'greeting'       => 'handle_greeting',
            'escalate'       => 'handle_escalate',
            'identify'       => 'handle_identify',
            'general'        => 'handle_general',
        ];

        $stepName = $handlerMap[$intent] ?? 'handle_general';

        // Shopify GraphQL steps: format raw data into {text, rich}
        $shopifySteps = ['handle_product_search', 'handle_spare_parts', 'handle_identify'];
        if (in_array($stepName, $shopifySteps)) {
            $result = $this->formatShopifyResponse($runContext, $stepName, $intent);

            // Retry product search with broader queries if no results found
            if (($intent === 'product_search' || $intent === 'spare_parts') && empty($result['rich'])) {
                $retried = $this->retryProductSearch($runContext, $intent);
                if ($retried) return $retried;

                // Final fallback: use crawled product context (vector similarity search)
                // This catches semantic matches like "beginner snowboarder" → product descriptions
                $crawledFallback = $this->fallbackToCrawledProducts($runContext);
                if ($crawledFallback) return $crawledFallback;
            }

            return $result;
        }

        // LLM handler steps: extract parsed {text, rich}
        $parsed = $this->extractStepParsed($runContext, $stepName);
        if ($parsed && isset($parsed['text'])) {
            return [
                'text' => $parsed['text'],
                'rich' => $parsed['rich'] ?? null,
            ];
        }

        return $default;
    }

    /**
     * Convert Shopify product edges into chat widget items, filtering out-of-stock.
     */
    private function formatProductEdges(array $edges, string $storefrontBase): array
    {
        $items = [];
        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];
            if (($node['totalInventory'] ?? 0) <= 0) continue; // skip out-of-stock

            $price = $node['priceRange']['minVariantPrice']['amount'] ?? null;
            $handle = $node['handle'] ?? '';
            $productUrl = $node['onlineStoreUrl'] ?? ($storefrontBase && $handle ? "{$storefrontBase}/products/{$handle}" : null);
            $items[] = [
                'title'     => $node['title'] ?? 'Product',
                'handle'    => $handle,
                'price'     => $price ? ('$' . number_format((float)$price / 100, 2)) : null,
                'image_url' => $node['featuredImage']['url'] ?? null,
                'available' => true,
                'url'       => $productUrl,
            ];
        }
        return $items;
    }

    /**
     * Build a product search {text, rich} response from items array.
     * Uses structured classifier output for price filtering, sorting, and limiting.
     */
    private function buildProductResponse(array $items, string $intent, ?array $classifyResult = null): ?array
    {
        if (empty($items)) return null;

        // Read structured params from classifier (replaces regex extraction)
        $priceMin    = $classifyResult['price_min'] ?? null;
        $priceMax    = $classifyResult['price_max'] ?? null;
        $sortBy      = $classifyResult['sort_by'] ?? null;
        $resultLimit = $classifyResult['result_limit'] ?? null;

        // Map sort_by to sort direction
        $priceSort = null;
        $qualifier = '';
        if ($sortBy === 'PRICE_DESC') {
            $priceSort = 'desc';
            $qualifier = 'most expensive';
            if (!$resultLimit) $resultLimit = 1;
        } elseif ($sortBy === 'PRICE_ASC') {
            $priceSort = 'asc';
            $qualifier = 'most affordable';
            if (!$resultLimit) $resultLimit = 1;
        } elseif ($sortBy === 'BEST_SELLING') {
            $qualifier = 'best selling';
            if (!$resultLimit) $resultLimit = 1;
        } elseif ($sortBy === 'CREATED_AT_DESC') {
            $qualifier = 'newest';
            if (!$resultLimit) $resultLimit = 1;
        }

        // Filter by price range
        if ($priceMax !== null || $priceMin !== null) {
            $items = array_filter($items, function ($item) use ($priceMax, $priceMin) {
                $p = (float) str_replace(['$', ','], '', $item['price'] ?? '0');
                if ($p <= 0) return true; // keep items with unknown price
                if ($priceMax !== null && $p > $priceMax) return false;
                if ($priceMin !== null && $p < $priceMin) return false;
                return true;
            });
            $items = array_values($items);
        }

        if ($priceSort) {
            usort($items, function ($a, $b) use ($priceSort) {
                $pa = (float) str_replace(['$', ','], '', $a['price'] ?? '0');
                $pb = (float) str_replace(['$', ','], '', $b['price'] ?? '0');
                return $priceSort === 'desc' ? $pb <=> $pa : $pa <=> $pb;
            });
            if ($resultLimit) {
                $items = array_slice($items, 0, $resultLimit);
            }
        }

        if (empty($items)) {
            // All items filtered out by price — return helpful message
            $rangeText = '';
            if ($priceMax !== null && $priceMin !== null) {
                $rangeText = " between $" . number_format($priceMin, 0) . " and $" . number_format($priceMax, 0);
            } elseif ($priceMax !== null) {
                $rangeText = " under $" . number_format($priceMax, 0);
            } elseif ($priceMin !== null) {
                $rangeText = " over $" . number_format($priceMin, 0);
            }
            return [
                'text' => "I couldn't find any products{$rangeText} matching your search. Would you like to see what we have at other price points?",
                'rich' => null,
            ];
        }

        $count = count($items);
        $label = $intent === 'spare_parts' ? 'spare parts' : 'products';
        if ($qualifier === 'best-value') {
            $text = "Here are **{$count} great deals** for you:";
        } elseif ($qualifier && $count === 1) {
            $text = "Here's the **{$qualifier}** {$label} we have:";
        } else {
            $text = "I found **{$count} {$label}** that match your search:";
        }

        return [
            'text' => $text,
            'rich' => ['type' => 'products', 'data' => ['items' => $items]],
        ];
    }

    /**
     * Format raw Shopify GraphQL output into {text, rich} for the chat widget.
     */
    private function formatShopifyResponse(array $runContext, string $stepName, string $intent): array
    {
        $step = $runContext[$stepName] ?? null;
        if (!is_array($step)) {
            return ['text' => 'I had trouble looking that up. Could you try again?', 'rich' => null];
        }

        $output = $step['output'] ?? $step;
        $data = $output['data'] ?? $output;

        // Resolve storefront base URL from the step output (shop domain)
        $shopDomain = $output['shop'] ?? null;
        $storefrontBase = $shopDomain ? 'https://' . rtrim($shopDomain, '/') : '';

        // Product search / spare parts
        if ($intent === 'product_search' || $intent === 'spare_parts') {
            $edges = $data['products']['edges'] ?? [];
            $items = $this->formatProductEdges($edges, $storefrontBase);
            $result = $this->buildProductResponse($items, $intent, $runContext['_fast_classify'] ?? null);
            if ($result) return $result;

            $label = $intent === 'spare_parts' ? 'spare parts' : 'products';
            return ['text' => "I couldn't find any matching {$label}. Could you try different search terms?", 'rich' => null];
        }

        // Customer identify
        if ($intent === 'identify') {
            $edges = $data['customers']['edges'] ?? [];
            if (empty($edges)) {
                return ['text' => "I couldn't find an account with that information. Could you double-check the email address?", 'rich' => null];
            }

            $customer = $edges[0]['node'] ?? [];
            return [
                'text' => "I found your account! Welcome back, **{$customer['displayName']}**.",
                'rich' => [
                    'type' => 'customer',
                    'data' => [
                        'name'        => $customer['displayName'] ?? '',
                        'email'       => $customer['email'] ?? '',
                        'orders'      => $customer['numberOfOrders'] ?? 0,
                        'total_spent' => '$' . number_format((float)($customer['totalSpent']['amount'] ?? 0) / 100, 2),
                    ],
                ],
            ];
        }

        return ['text' => 'Here is what I found.', 'rich' => null];
    }

    /**
     * Retry product search with broader queries when initial search returns empty.
     *
     * Shopify's full-text search doesn't handle compound words well
     * (e.g., "snow board" won't match "Snowboard"). This retries with:
     * 1. Words concatenated (snow board → snowboard)
     * 2. Individual words (snow, board)
     * 3. First word with wildcard prefix via title filter
     */
    private function retryProductSearch(array $runContext, string $intent): ?array
    {
        $connectionId = $runContext['shop_connection_id'] ?? $runContext['_shop_connection_id'] ?? null;
        if (!$connectionId) return null;

        // Get the original search query from classify
        $classifyParsed = $runContext['classify']['output']['parsed']
            ?? $runContext['_fast_classify']
            ?? null;
        $searchQuery = $classifyParsed['search_query'] ?? null;
        $message = $runContext['message'] ?? '';

        if (empty($searchQuery) && empty($message)) return null;

        // Build retry queries
        $retryQueries = [];
        $words = preg_split('/\s+/', trim($searchQuery ?: $message));
        $words = array_filter($words, fn($w) => mb_strlen($w) > 2); // skip tiny words

        // Try depluralized (snowboards → snowboard)
        $depluralized = preg_replace('/s$/i', '', $searchQuery ?: $message);
        if ($depluralized !== ($searchQuery ?: $message)) {
            $retryQueries[] = trim($depluralized);
        }

        // Try concatenated (snow board → snowboard)
        if (count($words) > 1) {
            $retryQueries[] = implode('', $words);
        }

        // Try title prefix for each meaningful word
        foreach ($words as $word) {
            $safe = preg_replace('/[^a-zA-Z0-9]/', '', $word);
            if (mb_strlen($safe) >= 3) {
                $retryQueries[] = "title:*{$safe}*";
            }
        }

        if (empty($retryQueries)) return null;

        try {
            $client = new \app\services\ShopifyClient((int) $connectionId);
            if (!$client->isConnected()) return null;

            $gql = 'query searchProducts($query: String!) { products(first: 20, query: $query) { edges { node { id title handle totalInventory featuredImage { url altText } priceRange { minVariantPrice { amount currencyCode } } onlineStoreUrl } } } }';

            foreach ($retryQueries as $query) {
                $result = $client->graphql($gql, ['query' => $query]);
                $edges = $result['data']['products']['edges'] ?? [];
                if (!empty($edges)) {
                    $shopDomain = $result['shop'] ?? null;
                    $storefrontBase = $shopDomain ? 'https://' . rtrim($shopDomain, '/') : '';
                    $items = $this->formatProductEdges($edges, $storefrontBase);
                    $classifyParsed2 = $runContext['_fast_classify'] ?? $classifyParsed ?? null;
                    $response = $this->buildProductResponse($items, $intent, $classifyParsed2);
                    if ($response) return $response;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Product search retry failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Fallback to crawled product data (vector similarity) when Shopify search returns 0.
     * Matches semantic queries like "beginner snowboarder" against product descriptions.
     */
    private function fallbackToCrawledProducts(array $runContext): ?array
    {
        $message = $runContext['message'] ?? '';
        $connectionId = $runContext['shop_connection_id'] ?? null;
        if (!$connectionId || !$message) return null;

        try {
            $workspace = $this->workspace ?: 'default';
            require_once __DIR__ . '/../services/ShopifyCrawlerService.php';
            $crawler = \app\services\ShopifyCrawlerService::forConnection((int)$connectionId, $workspace);
            $results = $crawler->search($message, 20);
            if (empty($results)) return null;

            // Filter to product-type results only and build product cards
            $items = [];
            foreach ($results as $r) {
                $meta = $r['metadata'] ?? [];
                $pageType = $meta['page_type'] ?? '';
                if ($pageType !== 'product') continue;

                $url = $meta['url'] ?? '';
                $title = $r['title'] ?? $meta['title'] ?? '';
                // Extract handle from URL if not in metadata (/products/the-complete-snowboard)
                $handle = $meta['handle'] ?? '';
                if (!$handle && $url && preg_match('#/products/([^/?]+)#', $url, $hm)) {
                    $handle = $hm[1];
                }
                // Parse price — "USD 949.95" format from crawler (dollars)
                $rawPrice = $meta['price'] ?? null;
                $price = null;
                if ($rawPrice && preg_match('/[\d.]+/', $rawPrice, $pm)) {
                    $price = (float) $pm[0];
                }

                if (!$title) continue;

                $items[] = [
                    'title'     => $title,
                    'handle'    => $handle,
                    'price'     => $price ? ('$' . number_format($price, 2)) : null,
                    'image_url' => $meta['image_url'] ?? null,
                    'available' => true,
                    'url'       => $url,
                ];
            }

            if (empty($items)) return null;

            return $this->buildProductResponse($items, 'product_search', $runContext['_fast_classify'] ?? null);
        } catch (\Throwable $e) {
            $this->logger->warning('Crawled product fallback failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Look up an order in Shopify by order number or customer email.
     * Returns {text, rich} response or null if lookup fails.
     */
    private function lookupOrder($connectionId, ?string $orderQuery, ?string $customerEmail): ?array
    {
        try {
            $client = new \app\services\ShopifyClient((int) $connectionId);
            if (!$client->isConnected()) return null;

            $hasOrderNum = $orderQuery && preg_match('/#?(\d+)/', $orderQuery, $m);
            $hasEmail = $customerEmail && filter_var($customerEmail, FILTER_VALIDATE_EMAIL);

            // Security: always require email for verification
            if (!$hasEmail) {
                return [
                    'text' => "For security, I need your **email address** to verify your identity before looking up order details. Could you provide it?",
                    'rich' => null,
                ];
            }

            // Build search query
            if ($hasOrderNum) {
                // Search by order number — we'll verify email after
                $searchQuery = 'name:#' . $m[1];
            } else {
                // Search by email only
                $searchQuery = 'email:' . $customerEmail;
            }

            $gql = 'query lookupOrder($query: String!) {
                orders(first: 3, query: $query, sortKey: CREATED_AT, reverse: true) {
                    edges { node {
                        id name email createdAt
                        displayFinancialStatus displayFulfillmentStatus
                        totalPriceSet { shopMoney { amount currencyCode } }
                        fulfillments { trackingInfo { number url company } status }
                        customer { displayName email }
                        lineItems(first: 10) { edges { node { title quantity } } }
                    } }
                }
            }';

            $result = $client->graphql($gql, ['query' => $searchQuery]);
            if (!($result['success'] ?? false)) return null;

            $edges = $result['data']['orders']['edges'] ?? [];
            $requestedOrderNum = $hasOrderNum ? '#' . $m[1] : null;
            $fallbackNote = '';

            // If order # search came up empty or email doesn't match,
            // fall back to searching by email for the customer's recent orders
            if ($hasOrderNum) {
                $needsFallback = false;

                if (empty($edges)) {
                    $needsFallback = true;
                } else {
                    $orderEmail = $edges[0]['node']['customer']['email'] ?? $edges[0]['node']['email'] ?? '';
                    if (mb_strtolower($orderEmail) !== mb_strtolower($customerEmail)) {
                        $needsFallback = true;
                    }
                }

                if ($needsFallback && $hasEmail) {
                    // Retry with email search
                    $emailResult = $client->graphql($gql, ['query' => 'email:' . $customerEmail]);
                    $emailEdges = ($emailResult['success'] ?? false) ? ($emailResult['data']['orders']['edges'] ?? []) : [];

                    if (!empty($emailEdges)) {
                        $edges = $emailEdges;
                        $fallbackNote = "I couldn't find order **{$requestedOrderNum}** on your account, but here's your most recent order:\n\n";
                    } else {
                        return [
                            'text' => "I couldn't find order **{$requestedOrderNum}**, and I don't see any orders for that email address. Could you double-check and try again?",
                            'rich' => null,
                        ];
                    }
                } elseif ($needsFallback) {
                    return [
                        'text' => "I couldn't verify that order with the email address provided. Please make sure you're using the email associated with your order.",
                        'rich' => null,
                    ];
                }
            }

            if (empty($edges)) {
                return [
                    'text' => "I couldn't find any orders matching that information. Could you double-check and try again?",
                    'rich' => null,
                ];
            }

            $order = $edges[0]['node'];

            $orderName = $order['name'] ?? 'Order';
            $financial = $order['displayFinancialStatus'] ?? 'Unknown';
            $fulfillment = $order['displayFulfillmentStatus'] ?? 'Processing';
            $totalRaw = $order['totalPriceSet']['shopMoney']['amount'] ?? '0';
            $total = number_format((float)$totalRaw / 100, 2);
            $currency = $order['totalPriceSet']['shopMoney']['currencyCode'] ?? 'USD';
            $customerName = $order['customer']['displayName'] ?? '';

            // Line items
            $items = [];
            foreach (($order['lineItems']['edges'] ?? []) as $li) {
                $node = $li['node'] ?? [];
                $items[] = [
                    'title' => $node['title'] ?? 'Item',
                    'quantity' => (int) ($node['quantity'] ?? 1),
                ];
            }

            // Tracking info
            $tracking = null;
            $trackingCompany = null;
            foreach (($order['fulfillments'] ?? []) as $f) {
                foreach (($f['trackingInfo'] ?? []) as $t) {
                    $tracking = $t['number'] ?? null;
                    $trackingCompany = $t['company'] ?? null;
                    break 2;
                }
            }

            $text = $fallbackNote ?: "Here are the details for **{$orderName}**:\n\n";
            if ($fallbackNote) $text .= "**{$orderName}**:\n\n";
            $text .= "- **Status:** {$fulfillment}\n";
            $text .= "- **Payment:** {$financial}\n";
            $text .= "- **Total:** \${$total} {$currency}\n";
            if ($tracking) {
                $text .= "- **Tracking:** {$tracking}" . ($trackingCompany ? " ({$trackingCompany})" : '') . "\n";
            }
            if ($customerName) {
                $text .= "\nOrder for: **{$customerName}**\n";
            }

            return [
                'text' => $text,
                'rich' => [
                    'type' => 'order',
                    'data' => [
                        'name' => $orderName,
                        'status' => $fulfillment,
                        'fulfillment' => $fulfillment,
                        'financial' => $financial,
                        'total' => "\${$total} {$currency}",
                        'tracking' => $tracking,
                        'items' => $items,
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Order lookup failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Handle generic form submission from any intent plugin.
     * Routes to the appropriate action handler based on plugin config.
     */
    private function handleFormAction(string $slug, $app, array $body, string $formAction): void
    {
        $sessionToken = $body['session_token'] ?? null;
        $appConfig = json_decode($app->config_json ?: '{}', true);
        $intentPlugins = $appConfig['intent_plugins'] ?? [];

        $plugin = $intentPlugins[$formAction] ?? null;
        if (!$plugin || empty($plugin['action'])) {
            $this->sendJsonError(400, 'Unknown form action: ' . $formAction);
            return;
        }

        // Load session
        $session = null;
        if ($sessionToken) {
            $session = Bean::findOne('customersessions', 'session_token = ?', [$sessionToken]);
        }
        if (!$session) {
            $this->sendJsonError(400, 'Session expired. Please start a new conversation.');
            return;
        }
        $sessionModel = $session->box('Model_Customersessions');

        // Validate required fields
        $formFields = $plugin['form']['fields'] ?? [];
        $formData = [];
        $hasAnyValue = false;
        foreach ($formFields as $field) {
            $name = $field['name'];
            $value = trim($body[$name] ?? '');
            $formData[$name] = $value;
            if (!empty($value)) $hasAnyValue = true;
            if (!empty($field['required']) && empty($value)) {
                $this->sendJsonError(400, 'Please fill in the required field: ' . ($field['label'] ?? $name));
                return;
            }
        }
        if (!$hasAnyValue) {
            $this->sendJsonError(400, 'Please fill in at least one field.');
            return;
        }

        // Build user-visible summary
        $userMsg = ucfirst(str_replace('_', ' ', $formAction)) . ':';
        foreach ($formData as $key => $val) {
            if (!empty($val)) $userMsg .= " {$key}={$val}";
        }

        // Execute the action
        $actionConfig = $plugin['action'];
        $actionType = $actionConfig['type'] ?? 'unknown';
        $response = null;

        // Check for chained intent (e.g. order lookup → return form)
        $nextIntent = $body['next_intent'] ?? null;

        switch ($actionType) {
            case 'shopify_order_lookup':
                $shopConnectionId = $appConfig['shopify_connection_id'] ?? 1;
                $response = $this->lookupOrder($shopConnectionId, $formData['order_number'] ?? null, $formData['email'] ?? null);
                if (!$response) {
                    $response = ['text' => "I couldn't find any orders matching that information. Please double-check and try again.", 'rich' => null];
                } elseif (!empty($response['rich']['data']['name'])) {
                    // Save verified order to session for future use
                    $sessionContext = $sessionModel->getContext();
                    $sessionContext['verified_order'] = $response['rich']['data'];
                    $sessionModel->setContext($sessionContext);

                    // Chain to next intent if set (e.g. return_request after order verified)
                    if ($nextIntent && !empty($intentPlugins[$nextIntent]['form'])) {
                        $nextPlugin = $intentPlugins[$nextIntent];
                        // Save email to session now so buildFormPrefill picks it up
                        $formEmail = $formData['email'] ?? '';
                        if ($formEmail && filter_var($formEmail, FILTER_VALIDATE_EMAIL)) {
                            $session->email = $formEmail;
                        }
                        $prefill = $this->buildFormPrefill($session, $sessionModel);
                        // Restore original message as details prefill
                        $pendingMessage = $sessionContext['pending_message'] ?? '';
                        if (!empty($pendingMessage) && empty($prefill['details'])) {
                            $prefill['details'] = $pendingMessage;
                        }
                        // Filter out fields already known (order_number, email)
                        $nextForm = $nextPlugin['form'];
                        $nextForm['fields'] = array_values(array_filter($nextForm['fields'] ?? [], function ($f) use ($prefill) {
                            return !in_array($f['name'], ['order_number', 'email']) || empty($prefill[$f['name']]);
                        }));
                        $intentLabel = str_replace('_', ' ', $nextIntent);
                        $orderName = $response['rich']['data']['name'];
                        $response['text'] .= "\n\nGreat, I found your order **{$orderName}**! Now let's complete your {$intentLabel}:";
                        $response['rich'] = [
                            'type' => 'intent_form',
                            'data' => array_merge($nextForm, ['form_action' => $nextIntent, 'prefill' => $prefill]),
                        ];
                    }
                }
                break;

            case 'shopify_customer_lookup':
                $shopConnectionId = $appConfig['shopify_connection_id'] ?? 1;
                $response = $this->lookupCustomer($shopConnectionId, $formData['email'] ?? null);
                if (!$response) {
                    $response = ['text' => "I couldn't find a customer with that information. Please try again.", 'rich' => null];
                }
                break;

            case 'pipeline':
                $response = $this->executePipelineAction($actionConfig, $formData, $sessionModel, $appConfig);
                break;

            case 'session_action':
                // Update session context with form data
                $sessionContext = $sessionModel->getContext();
                foreach ($formData as $k => $v) {
                    if (!empty($v)) $sessionContext[$k] = $v;
                }
                $sessionModel->setContext($sessionContext);
                $responseText = $this->substituteVars($actionConfig['response_text'] ?? 'Got it, thanks!', $formData);
                $response = ['text' => $responseText, 'rich' => null];
                break;

            default:
                $response = ['text' => 'This action is not yet supported.', 'rich' => null];
        }

        // If the action didn't produce rich data (lookup failed / not found),
        // re-attach the form so the customer can try again
        if (empty($response['rich']) && !empty($plugin['form'])) {
            $retryable = in_array($actionType, ['shopify_order_lookup', 'shopify_customer_lookup']);
            if ($retryable) {
                $prefill = $this->buildFormPrefill($session, $sessionModel);
                $retryData = array_merge($plugin['form'], ['form_action' => $formAction, 'prefill' => $prefill]);
                // Preserve next_intent chain on retry
                if ($nextIntent) $retryData['next_intent'] = $nextIntent;
                $response['rich'] = ['type' => 'intent_form', 'data' => $retryData];
            }
        }

        // Save customer email to session if provided
        $email = $formData['email'] ?? '';
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $session->email = $email;
        }

        // Save messages to session
        $sessionModel->addMessage('user', $userMsg);
        $sessionModel->addMessage('assistant', $response['text'], $response['rich'] ?? null);
        $sessionModel->extendExpiry();

        $sessionContext = $sessionModel->getContext();
        $sessionContext['last_intent'] = $formAction;
        $sessionContext['message_count'] = count($sessionModel->getMessages());
        $sessionModel->setContext($sessionContext);
        $this->updateSessionSentimentAndSubject($session, $formAction, 'neutral', $userMsg);
        Bean::store($session);

        // Store to customerrequests for CS team visibility
        $this->storeCustomerRequest($session, $formAction, $actionType, $formData, $response);

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'session_token' => $session->session_token,
            'response' => $response,
            'meta' => ['intent' => $formAction, 'sentiment' => 'neutral'],
            'escalated' => false,
        ]);
    }

    /**
     * Execute a pipeline-type action from form submission.
     */
    private function executePipelineAction(array $actionConfig, array $formData, $sessionModel, array $appConfig): array
    {
        $pipelineSlug = $actionConfig['pipeline_slug'] ?? null;
        if (!$pipelineSlug) {
            return ['text' => 'Pipeline not configured for this action.', 'rich' => null];
        }

        $pipeline = Bean::findOne('pipelines', ' slug = ? ', [$pipelineSlug]);
        if (!$pipeline) {
            $this->logger->warning('Intent plugin pipeline not found', ['slug' => $pipelineSlug]);
            return ['text' => 'This feature is temporarily unavailable. Please try again later.', 'rich' => null];
        }

        // Build pipeline context from form data
        $context = $formData;
        $context['shop_name'] = $appConfig['shop_name'] ?? '';
        $context['shop_connection_id'] = $appConfig['shopify_connection_id'] ?? '';

        // Include verified order data from session (for order-gated flows)
        $sessionContext = $sessionModel->getContext();
        if (!empty($sessionContext['verified_order'])) {
            $context['verified_order'] = $sessionContext['verified_order'];
        }

        // Include transcript if configured
        if (!empty($actionConfig['include_transcript'])) {
            $messages = $sessionModel->getMessages();
            $transcript = '';
            foreach ($messages as $msg) {
                $role = ($msg['role'] === 'user') ? 'Customer' : 'Assistant';
                $transcript .= "[{$msg['timestamp']}] {$role}: {$msg['content']}\n";
            }
            $context['transcript'] = $transcript;
        }

        // Create and dispatch pipeline run
        $run = Bean::dispense('pipelineruns');
        $run->pipelines_id = (int) $pipeline->id;
        $run->status = 'pending';
        $run->trigger_type = 'api';
        $run->trigger_source = 'intent_plugin';
        $run->context_json = json_encode($context);
        $run->created_at = date('Y-m-d H:i:s');
        Bean::store($run);

        $workspace = $this->workspace ?: 'default';

        try {
            $result = PipelineDispatcher::dispatch((int) $run->id, $workspace, sync: true);
            $responseText = $actionConfig['response_text'] ?? 'Your request has been submitted.';
            $responseText = $this->substituteVars($responseText, $formData);
            return ['text' => $responseText, 'rich' => null];
        } catch (\Throwable $e) {
            $this->logger->error('Intent pipeline failed', ['slug' => $pipelineSlug, 'error' => $e->getMessage()]);
            return ['text' => 'Something went wrong processing your request. Please try again.', 'rich' => null];
        }
    }

    /**
     * Look up a customer in Shopify by email.
     */
    private function lookupCustomer($connectionId, ?string $email): ?array
    {
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['text' => 'Please provide a valid email address.', 'rich' => null];
        }

        try {
            $client = new \app\services\ShopifyClient((int) $connectionId);
            if (!$client->isConnected()) return null;

            $gql = 'query lookupCustomer($query: String!) {
                customers(first: 1, query: $query) {
                    edges { node {
                        id displayName email
                        numberOfOrders
                        totalSpent { amount currencyCode }
                    } }
                }
            }';

            $result = $client->graphql($gql, ['query' => 'email:' . $email]);
            if (!($result['success'] ?? false)) return null;

            $edges = $result['data']['customers']['edges'] ?? [];
            if (empty($edges)) {
                return ['text' => "I couldn't find an account with that email. Please double-check.", 'rich' => null];
            }

            $customer = $edges[0]['node'];
            return [
                'text' => "I found your account! Welcome back, **{$customer['displayName']}**.",
                'rich' => [
                    'type' => 'customer',
                    'data' => [
                        'name' => $customer['displayName'] ?? '',
                        'email' => $customer['email'] ?? '',
                        'orders' => $customer['numberOfOrders'] ?? 0,
                        'total_spent' => '$' . number_format((float)($customer['totalSpent']['amount'] ?? 0) / 100, 2),
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Customer lookup failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Check if a message already contains enough data to skip showing the form.
     * E.g., "Where is my order #1234? My email is john@example.com"
     */
    private function messageHasFormData(string $message, array $plugin): bool
    {
        $actionType = $plugin['action']['type'] ?? '';

        if ($actionType === 'shopify_order_lookup') {
            // Need order number or email in the message
            $hasOrderNum = preg_match('/#?\d{4,}/', $message);
            $hasEmail = preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/', $message);
            return $hasOrderNum || $hasEmail;
        }

        // For pipeline/other actions, always show the form
        return false;
    }

    /**
     * Check if the classifier extracted enough data to execute the action directly.
     */
    private function classifierHasActionData(?array $classifyOutput, array $plugin): bool
    {
        if (!$classifyOutput) return false;

        $actionType = $plugin['action']['type'] ?? '';

        if ($actionType === 'shopify_order_lookup') {
            $orderQuery = $classifyOutput['order_query'] ?? null;
            $customerEmail = $classifyOutput['customer_email'] ?? null;
            if (!is_string($orderQuery)) $orderQuery = null;
            if (!is_string($customerEmail)) $customerEmail = null;
            $hasOrderNum = $orderQuery && preg_match('/#?\d{3,}/', $orderQuery);
            $hasEmail = $customerEmail && filter_var($customerEmail, FILTER_VALIDATE_EMAIL);
            return $hasOrderNum || $hasEmail;
        }

        if ($actionType === 'shopify_customer_lookup') {
            $email = $classifyOutput['customer_email'] ?? null;
            return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL);
        }

        // For pipelines, always show the form (need structured input)
        return false;
    }

    /**
     * Execute an intent action directly using classifier-extracted data.
     */
    private function executeIntentAction(array $plugin, array $classifyOutput, array $appConfig): array
    {
        $actionType = $plugin['action']['type'] ?? '';
        $shopConnectionId = $appConfig['shopify_connection_id'] ?? 1;

        if ($actionType === 'shopify_order_lookup') {
            $orderQuery = $classifyOutput['order_query'] ?? null;
            $customerEmail = $classifyOutput['customer_email'] ?? null;
            if (!is_string($orderQuery)) $orderQuery = null;
            if (!is_string($customerEmail)) $customerEmail = null;

            $result = $this->lookupOrder($shopConnectionId, $orderQuery, $customerEmail);
            return $result ?: $this->extractHandlerResponse([], 'order_status');
        }

        if ($actionType === 'shopify_customer_lookup') {
            $email = $classifyOutput['customer_email'] ?? null;
            $result = $this->lookupCustomer($shopConnectionId, $email);
            return $result ?: ['text' => 'Could not look up customer.', 'rich' => null];
        }

        // Fallback
        return ['text' => 'I can help with that! Let me get some details.', 'rich' => null];
    }

    /**
     * Build prefill data for intent forms from session state.
     * Maps known field names to session values so returning customers
     * don't have to re-enter their info.
     */
    private function buildFormPrefill($session, $sessionModel): array
    {
        $prefill = [];

        // Email from session bean
        $email = $session->email ?? null;
        if ($email) $prefill['email'] = $email;

        // Order info from session context
        $ctx = $sessionModel->getContext();
        if (!empty($ctx['verified_order']['name'])) {
            // Strip the # prefix if present for form field
            $prefill['order_number'] = ltrim($ctx['verified_order']['name'], '#');
        }
        if (!empty($ctx['customer_name'])) {
            $prefill['name'] = $ctx['customer_name'];
        }

        return $prefill;
    }

    /**
     * Build form response, gating on order lookup if plugin requires it.
     *
     * If the plugin has require_order=true and the session has no verified order,
     * shows the order_status form first with next_intent chaining.
     */
    private function buildFormResponse(array $plugin, string $intent, array $prefill, array $intentPlugins, $sessionModel, string $message): array
    {
        $formData = $plugin['form'];

        // Check if this intent requires a verified order first
        if (!empty($plugin['require_order'])) {
            $ctx = $sessionModel->getContext();
            if (empty($ctx['verified_order'])) {
                // No verified order — show order lookup form with chain back to this intent
                $orderPlugin = $intentPlugins['order_status'] ?? null;
                if ($orderPlugin && !empty($orderPlugin['form'])) {
                    $orderForm = $orderPlugin['form'];
                    $intentLabel = str_replace('_', ' ', $intent);
                    // Save the original message for later prefill after order verification
                    $ctx['pending_intent'] = $intent;
                    $ctx['pending_message'] = $message;
                    $sessionModel->setContext($ctx);
                    return [
                        'text' => "I'd be happy to help with your {$intentLabel}! First, let me look up your order.",
                        'rich' => [
                            'type' => 'intent_form',
                            'data' => array_merge($orderForm, [
                                'form_action' => 'order_status',
                                'prefill' => $prefill,
                                'next_intent' => $intent,
                            ]),
                        ],
                    ];
                }
            }
        }

        // Normal: show the plugin's own form
        return [
            'text' => $formData['prompt_text'] ?? 'Please fill in the details:',
            'rich' => [
                'type' => 'intent_form',
                'data' => array_merge($formData, ['form_action' => $intent, 'prefill' => $prefill]),
            ],
        ];
    }

    /**
     * Replace {field_name} placeholders in a string with form data values.
     */
    private function substituteVars(string $template, array $data): string
    {
        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($data) {
            return $data[$m[1]] ?? $m[0];
        }, $template);
    }

    /**
     * Fast LLM-based intent classification using local Ollama.
     *
     * Replaces keyword matching — calls a small local model (~0.6s) to classify
     * the customer's intent, extract structured data (order #, email), and return
     * a confidence score.
     *
     * Returns null on any failure (graceful degradation — full pipeline runs instead).
     */
    /**
     * Build a personality prompt block from dapp config.
     */
    private function buildBotPersonality(array $appConfig): array
    {
        $p = $appConfig['bot_personality'] ?? [];
        return [
            'name' => $p['name'] ?? 'Assistant',
            'tone' => $p['tone'] ?? 'friendly, helpful, concise',
            'store_context' => $p['store_context'] ?? '',
            'policies_summary' => $p['policies_summary'] ?? '',
            'greeting' => $p['greeting'] ?? 'Hi! How can I help you today?',
            'positive_nudges' => (bool) ($p['positive_nudges'] ?? true),
            'prompt_block' => $this->formatPersonalityPrompt($p),
        ];
    }

    private function formatPersonalityPrompt(array $p): string
    {
        $name = $p['name'] ?? 'Assistant';
        $tone = $p['tone'] ?? 'friendly, helpful, concise';
        $store = $p['store_context'] ?? '';
        $policies = $p['policies_summary'] ?? '';

        $block = "You are {$name}, a {$tone} customer support assistant.";
        if ($store) {
            $block .= "\nStore: {$store}";
        }
        if ($policies) {
            $block .= "\nPolicies: {$policies}";
        }
        if (!empty($p['positive_nudges'])) {
            $block .= "\nAlways end with a helpful suggestion or positive next step. Be proactive, not just reactive.";
        }
        return $block;
    }

    /**
     * Update session's current_sentiment and subject columns for livechat dashboard.
     * RedBeanPHP auto-creates these columns on first write.
     */
    private function updateSessionSentimentAndSubject($session, string $intent, string $sentiment, string $message, ?array $classifyResult = null): void
    {
        $session->current_sentiment = $sentiment;
        if (empty($session->subject)) {
            $session->subject = $this->deriveSubject($intent, $classifyResult, $message);
        }
    }

    /**
     * Derive a short subject line from intent and message for the livechat session list.
     */
    private function deriveSubject(string $intent, ?array $classifyResult, string $message): string
    {
        switch ($intent) {
            case 'product_search':
                $query = $classifyResult['search_query'] ?? null;
                return $query ? 'Product search: ' . mb_substr($query, 0, 40) : 'Product search';
            case 'order_status':
                $orderQuery = $classifyResult['order_query'] ?? null;
                return $orderQuery ? 'Order inquiry: ' . mb_substr($orderQuery, 0, 40) : 'Order inquiry';
            case 'return_request':
                return 'Return request';
            case 'repair_request':
                return 'Repair request';
            case 'order_cancel':
                return 'Order cancellation';
            case 'faq':
                return 'FAQ: ' . mb_substr($message, 0, 50);
            case 'greeting':
                return 'New conversation';
            case 'escalate':
                return 'Escalation request';
            default:
                return mb_substr($message, 0, 50);
        }
    }

    /**
     * Determine the interaction state based on intent, sentiment, and history.
     */
    private function determineInteractionState(string $intent, string $sentiment, ?string $previousState, array $sessionContext): string
    {
        // Hostile/frustrated overrides everything
        if (in_array($sentiment, ['hostile', 'frustrated', 'negative'])) {
            if ($sentiment === 'hostile' || $sentiment === 'frustrated') {
                return 'frustrated';
            }
            // Negative but not frustrated — keep current state unless it was already frustrated
            if ($previousState === 'frustrated') {
                return 'frustrated'; // Stay frustrated until positive signal
            }
        }

        // Positive sentiment after any action = satisfied
        if ($sentiment === 'positive' && $previousState && $previousState !== 'greeting') {
            return 'satisfied';
        }

        // Intent-based transitions
        return match ($intent) {
            'greeting' => $previousState && $previousState !== 'greeting' ? 'satisfied' : 'greeting',
            'product_search', 'spare_parts' => 'browsing',
            'order_status', 'order_update', 'order_cancel', 'return_request', 'repair_request' => 'order_support',
            'escalate' => 'frustrated',
            'faq', 'general', 'identify', 'send_transcript' => $previousState ?? 'greeting',
            default => $previousState ?? 'greeting',
        };
    }

    /**
     * Handle FAQ questions directly using local LLM (qwen2.5:3b on GPU).
     * Avoids pipeline dependency on cloud models for simple policy questions.
     */
    private function handleFaqDirect(string $message, string $conversationHistory, array $appConfig, array $botPersonality): ?string
    {
        $classifierConfig = $appConfig['classifier'] ?? [];
        $host = $classifierConfig['host'] ?? 'http://127.0.0.1:11435';
        $model = $classifierConfig['model'] ?? 'qwen2.5:3b';
        $botName = $botPersonality['name'] ?? 'Assistant';
        $policies = $botPersonality['policies_summary'] ?? '';
        $storeContext = $botPersonality['store_context'] ?? '';

        // Search KB for relevant context
        $kbContext = $this->searchKnowledgeBase($message, $appConfig);

        // Search crawled products for relevant store pages
        $shopConnectionId = $appConfig['shopify_connection_id'] ?? null;
        $crawledContext = $shopConnectionId ? $this->searchCrawledProducts($message, $shopConnectionId) : '';

        $systemPrompt = "You are {$botName}, a friendly customer support assistant for {$storeContext}.\n\nStore policies: {$policies}\n\nAnswer the customer's question concisely (2-3 sentences). Be warm and helpful. If you reference products or pages, include their URLs as markdown links.";

        $contextBlock = '';
        if ($kbContext) $contextBlock .= "\n\nKnowledge base context:\n{$kbContext}";
        if ($crawledContext) $contextBlock .= "\n\nStore pages:\n{$crawledContext}";

        $userPrompt = $conversationHistory !== '(new conversation)'
            ? "History:\n{$conversationHistory}\n\nQuestion: {$message}{$contextBlock}\n\nAnswer:"
            : "Question: {$message}{$contextBlock}\n\nAnswer:";

        try {
            $provider = new \app\services\LLMProviders\OllamaProvider([
                'host' => $host,
                'model' => $model,
                'timeout' => 15,
            ]);

            $result = $provider->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ], ['model' => $model, 'temperature' => 0.3]);

            if (($result['success'] ?? false) && !empty($result['response'])) {
                return trim($result['response']);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('FAQ direct handler failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Get suggested follow-up actions based on interaction state.
     */
    private function getSuggestedActions(string $state, string $intent): array
    {
        return match ($state) {
            'greeting' => [
                ['label' => 'Browse products', 'message' => 'What products do you have?'],
                ['label' => 'Track my order', 'message' => 'I want to check my order status'],
                ['label' => 'Get help', 'message' => 'I need help with something'],
            ],
            'browsing' => [
                ['label' => 'See bestsellers', 'message' => 'Show me your best sellers'],
                ['label' => 'Compare options', 'message' => 'Can you compare these for me?'],
                ['label' => 'Check availability', 'message' => 'Is this in stock?'],
            ],
            'considering' => [
                ['label' => 'Add to cart', 'message' => 'I want to buy this'],
                ['label' => 'See similar', 'message' => 'Show me similar options'],
                ['label' => 'Any deals?', 'message' => 'Are there any current promotions?'],
            ],
            'order_support' => [
                ['label' => 'Track another order', 'message' => 'I want to check another order'],
                ['label' => 'Start a return', 'message' => 'I need to return something'],
                ['label' => 'Talk to human', 'message' => 'I\'d like to speak with a human agent'],
            ],
            'frustrated' => [
                ['label' => 'Talk to human', 'message' => 'I\'d like to speak with a human agent'],
                ['label' => 'Try again', 'message' => 'Let me try explaining again'],
            ],
            'satisfied' => [
                ['label' => 'Browse products', 'message' => 'Let me look at your products'],
                ['label' => 'Anything else', 'message' => 'That\'s all, thanks!'],
            ],
            default => [
                ['label' => 'Browse products', 'message' => 'What products do you have?'],
                ['label' => 'Track my order', 'message' => 'I want to check my order status'],
            ],
        };
    }

    // heuristicClassify() and fastClassify() moved to services/ChatClassifier.php

    /**
     * Store a customer request for CS team visibility.
     */
    /**
     * Search the knowledge base for relevant context.
     * Returns formatted text block for injection into pipeline context.
     */
    private function searchKnowledgeBase(string $query, array $appConfig): string
    {
        $kbSlug = $appConfig['knowledge_base_slug'] ?? null;
        $kbId = $appConfig['knowledge_base_id'] ?? null;
        if (!$kbSlug && !$kbId) {
            return '';
        }

        try {
            require_once __DIR__ . '/../services/KnowledgeBaseToolService.php';
            $workspace = $this->workspace ?: 'default';
            $kbService = new \app\services\KnowledgeBaseToolService($workspace, $this->logger);

            $args = [
                'query' => $query,
                'max_results' => 3,
                'similarity_threshold' => 0.65,
            ];
            if ($kbSlug) $args['knowledge_base_slug'] = $kbSlug;
            if ($kbId) $args['knowledge_base_id'] = $kbId;

            $result = $kbService->searchKnowledgeBase($args);

            // Parse the result text to extract chunks
            $resultText = $result['content'][0]['text'] ?? '{}';
            $resultData = json_decode($resultText, true);
            $chunks = $resultData['results'] ?? [];

            if (empty($chunks)) {
                return '';
            }

            // Format as a prompt-friendly text block
            $lines = ["Relevant knowledge from store FAQ/policies:"];
            foreach ($chunks as $chunk) {
                $text = $chunk['text'] ?? $chunk['content'] ?? '';
                $score = $chunk['score'] ?? $chunk['similarity'] ?? 0;
                if ($text && $score >= 0.65) {
                    $lines[] = "- " . trim($text);
                }
            }

            return implode("\n", $lines);
        } catch (\Throwable $e) {
            $this->logger->warning('KB search failed', ['error' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Search crawled product/collection/page data from the AssistKnowledgeStore.
     * Returns a prompt-friendly block with URLs, titles, and descriptions.
     */
    private function searchCrawledProducts(string $query, $connectionId): string
    {
        if (!$connectionId) return '';
        try {
            $workspace = $this->workspace ?: 'default';
            require_once __DIR__ . '/../services/ShopifyCrawlerService.php';
            $crawler = \app\services\ShopifyCrawlerService::forConnection((int)$connectionId, $workspace);
            $results = $crawler->search($query, 10);
            if (empty($results)) return '';

            $lines = ["Relevant store pages and products:"];
            foreach ($results as $r) {
                $meta = $r['metadata'] ?? [];
                $url = $meta['url'] ?? $r['page'] ?? '';
                $type = $meta['page_type'] ?? 'page';
                $title = $r['title'] ?? '';
                $snippet = substr($r['content'] ?? '', 0, 300);
                $lines[] = "- [{$type}] {$title}: {$url}";
                if ($snippet) $lines[] = "  {$snippet}";
            }
            return implode("\n", $lines);
        } catch (\Throwable $e) {
            $this->logger->warning('Crawled product search failed', ['error' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Classify a completed conversation's archetype and store in KB for future exemplars.
     * Called when a session resolves or has enough messages.
     */
    private function classifyAndStoreConversation($session, array $appConfig): void
    {
        $memoryConfig = $appConfig['interaction_memory'] ?? [];
        if (empty($memoryConfig['enabled'])) {
            return;
        }

        $sessionModel = $session->box('Model_Customersessions');
        $messages = $sessionModel->getMessages();
        $threshold = (int) ($memoryConfig['store_threshold'] ?? 5);

        if (count($messages) < $threshold) {
            return; // Too short to be valuable
        }

        $kbSlug = $memoryConfig['kb_slug'] ?? 'chat-interactions';

        try {
            // Analyze the conversation to determine archetype
            $sentiments = [];
            $intents = [];
            $sessionContext = $sessionModel->getContext();

            foreach ($messages as $msg) {
                if ($msg['role'] === 'user') {
                    // Use last_classify if available, otherwise infer from message
                    $sentiments[] = $sessionContext['last_classify']['sentiment'] ?? 'neutral';
                }
            }

            // Determine archetype from sentiment trajectory
            $lastSentiment = end($sentiments) ?: 'neutral';
            $hasNegative = in_array('negative', $sentiments) || in_array('frustrated', $sentiments);
            $hasPositive = in_array('positive', $sentiments);
            $wasEscalated = ($session->verification_status === 'escalated');
            $wasResolved = ($session->verification_status === 'resolved');

            if ($wasEscalated) {
                $archetype = 'escalated';
            } elseif ($hasNegative && $hasPositive) {
                $archetype = 'mixed'; // Started bad, ended good — most valuable
            } elseif ($hasNegative) {
                $archetype = 'avoidant';
            } else {
                $archetype = 'confident';
            }

            // Format transcript as a structured document
            $transcript = "## Chat Interaction [{$archetype}]\n\n";
            $transcript .= "**Archetype:** {$archetype}\n";
            $transcript .= "**Messages:** " . count($messages) . "\n";
            $transcript .= "**Intent flow:** " . ($sessionContext['last_intent'] ?? 'unknown') . "\n";
            $transcript .= "**Resolution:** " . $session->verification_status . "\n";
            $transcript .= "**Date:** " . date('Y-m-d H:i:s') . "\n\n";
            $transcript .= "### Conversation\n\n";

            foreach ($messages as $msg) {
                $role = ($msg['role'] === 'user') ? 'Customer' : 'Assistant';
                $transcript .= "**{$role}:** {$msg['content']}\n\n";
            }

            // Store in knowledge base via DocumentIngestionService
            $workspace = $this->workspace ?: 'default';
            $filename = "conversation-{$archetype}-" . (int)$session->id . '.md';

            $service = \app\services\DocumentIngestionService::forWorkspace($workspace);
            $result = $service->ingestText($transcript, $filename, [
                'archetype' => $archetype,
                'message_count' => count($messages),
                'intent' => $sessionContext['last_intent'] ?? 'general',
                'resolution' => $session->verification_status,
                'session_id' => (int) $session->id,
            ]);

            if ($result['success']) {
                $this->logger->info('Stored conversation in KB', [
                    'archetype' => $archetype,
                    'messages' => count($messages),
                    'kb_slug' => $kbSlug,
                    'chunks' => $result['chunk_count'],
                ]);
            } else {
                $this->logger->warning('Failed to store conversation in KB', [
                    'error' => $result['error'] ?? 'unknown',
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Conversation memory storage failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Retrieve exemplar conversations from KB that match current interaction context.
     */
    private function getExemplarConversations(string $intent, string $interactionState, array $appConfig): string
    {
        $memoryConfig = $appConfig['interaction_memory'] ?? [];
        if (empty($memoryConfig['enabled'])) {
            return '';
        }

        $kbSlug = $memoryConfig['kb_slug'] ?? 'chat-interactions';
        $exemplarCount = (int) ($memoryConfig['exemplar_count'] ?? 2);

        try {
            require_once __DIR__ . '/../services/KnowledgeBaseToolService.php';
            $workspace = $this->workspace ?: 'default';
            $kbService = new \app\services\KnowledgeBaseToolService($workspace, $this->logger);

            // Search for successful interactions matching this context
            $query = "successful {$interactionState} conversation about {$intent}";
            $result = $kbService->searchKnowledgeBase([
                'knowledge_base_slug' => $kbSlug,
                'query' => $query,
                'max_results' => $exemplarCount,
                'similarity_threshold' => 0.6,
            ]);

            $resultText = $result['content'][0]['text'] ?? '{}';
            $resultData = json_decode($resultText, true);
            $chunks = $resultData['results'] ?? [];

            if (empty($chunks)) {
                return '';
            }

            $lines = ["Here are examples of similar successful conversations for reference:"];
            foreach ($chunks as $chunk) {
                $text = $chunk['text'] ?? $chunk['content'] ?? '';
                if ($text) {
                    // Truncate long exemplars
                    if (mb_strlen($text) > 500) {
                        $text = mb_substr($text, 0, 500) . '...';
                    }
                    $lines[] = "\n---\n" . trim($text);
                }
            }

            return implode("\n", $lines);
        } catch (\Throwable $e) {
            $this->logger->debug('Exemplar retrieval failed (non-critical)', ['error' => $e->getMessage()]);
            return '';
        }
    }

    private function storeCustomerRequest($session, string $intent, string $actionType, array $formData, array $response): void
    {
        try {
            $req = Bean::dispense('customerrequests');
            $req->customersessions_id = (int) $session->id;
            $req->session_token = $session->session_token;
            $req->intent = $intent;
            $req->action_type = $actionType;
            $req->customer_email = $formData['email'] ?? $session->email ?? '';
            $req->form_data_json = json_encode($formData);
            $req->action_result_json = json_encode([
                'response_text' => $response['text'] ?? '',
                'has_rich' => !empty($response['rich']),
            ]);
            $req->status = 'completed';
            $req->created_at = date('Y-m-d H:i:s');
            Bean::store($req);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to store customer request', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Poll for new messages (used during live chat with agent).
     *
     * GET /dapp/{slug}/api/poll?session_token=xxx&last_count=N
     */
    private function pollMessages(string $slug): void
    {
        $app = $this->loadApp($slug);
        if (!$app) return;

        $sessionToken = $_GET['session_token'] ?? '';
        $lastCount = (int) ($_GET['last_count'] ?? 0);

        if (empty($sessionToken)) {
            $this->sendJsonError(400, 'session_token required');
            return;
        }

        $session = Bean::findOne('customersessions', 'session_token = ?', [$sessionToken]);
        if (!$session) {
            $this->sendJsonError(404, 'Session not found');
            return;
        }

        $sessionModel = $session->box('Model_Customersessions');
        $allMessages = $sessionModel->getMessages();
        $totalCount = count($allMessages);

        // Return only new messages since last_count
        $newMessages = ($lastCount > 0 && $lastCount < $totalCount)
            ? array_slice($allMessages, $lastCount)
            : [];

        // Get agent info
        $agentName = $this->getAgentDisplayName($session);
        $status = $session->verification_status ?? 'verified';

        // Calculate queue position if waiting
        $queuePosition = 0;
        $estimatedWait = null;
        if ($status === 'escalated' && empty($session->agent_member_id)) {
            $queuePosition = Bean::count('customersessions',
                ' verification_status = ? AND (agent_member_id IS NULL OR agent_member_id = 0) AND resolved_at IS NULL AND id <= ? ',
                ['escalated', (int) $session->id]
            );
            $estimatedWait = $queuePosition * 5; // minutes

            // Retry auto-assign on each poll while unassigned
            $this->tryAutoAssignAgent($session);
            // Reload in case assignment happened
            $session = Bean::load('customersessions', (int) $session->id);
            if ($session->agent_member_id) {
                $agentName = $this->getAgentDisplayName($session);
                $queuePosition = 0;
                $estimatedWait = null;
                // Re-fetch messages (assignment added a system message)
                $sessionModel = $session->box('Model_Customersessions');
                $allMessages = $sessionModel->getMessages();
                $totalCount = count($allMessages);
                $newMessages = ($lastCount > 0 && $lastCount < $totalCount)
                    ? array_slice($allMessages, $lastCount)
                    : [];
            }
        }

        // Check if agent is typing (within last 10 seconds)
        $agentTyping = false;
        $context = json_decode($session->context_json ?: '{}', true);
        if (!empty($context['agent_typing'])) {
            $agentTyping = (time() - (int) $context['agent_typing']) < 10;
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'messages' => $newMessages,
            'total_count' => $totalCount,
            'queue_position' => $queuePosition,
            'agent_name' => $agentName,
            'estimated_wait' => $estimatedWait,
            'status' => $session->verification_status ?? $status,
            'agent_typing' => $agentTyping,
        ]);
    }

    /**
     * Try to auto-assign an available CS agent to an escalated session.
     */
    private function tryAutoAssignAgent($session): void
    {
        try {
            // Only assign if not already assigned
            if (!empty($session->agent_member_id) && (int)$session->agent_member_id > 0) {
                return;
            }

            $agents = Bean::find('csagents', ' status = ? ORDER BY id ASC ', ['online']);
            foreach ($agents as $agent) {
                $activeCount = Bean::count('customersessions',
                    ' agent_member_id = ? AND verification_status = ? AND resolved_at IS NULL ',
                    [(int)$agent->member_id, 'escalated']
                );
                if ($activeCount < (int)$agent->max_concurrent) {
                    $sessionModel = $session->box('Model_Customersessions');
                    $sessionModel->assignAgent((int)$agent->member_id, $agent->display_name);
                    $agent->last_active_at = date('Y-m-d H:i:s');
                    Bean::store($agent);
                    Bean::store($session);
                    return;
                }
            }

            // No available agent — set queue timestamp if not already set
            if (empty($session->queue_entered_at)) {
                $session->queue_entered_at = date('Y-m-d H:i:s');
                Bean::store($session);
            }
        } catch (\Throwable $e) {
            // Log but don't break the customer-facing flow
            $logger = \Flight::get('log');
            $logger->warning('Auto-assign agent failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get the display name of the assigned agent (if any).
     */
    private function getAgentDisplayName($session): ?string
    {
        $agentMemberId = (int) ($session->agent_member_id ?? 0);
        if ($agentMemberId <= 0) return null;

        $agent = Bean::findOne('csagents', ' member_id = ? ', [$agentMemberId]);
        return $agent ? $agent->display_name : null;
    }
}
