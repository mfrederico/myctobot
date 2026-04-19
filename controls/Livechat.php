<?php
/**
 * Livechat Controller
 *
 * Session-based controller for CS agents and admins to manage live chat sessions.
 * Agents see their assigned chats + queue, admins see all chats across all agents.
 *
 * URL patterns:
 *   /livechat                → Agent dashboard
 *   /livechat/chat/{id}      → Single chat view
 *   /livechat/pickup         → Claim a queued session (POST)
 *   /livechat/reply          → Send message to customer (POST)
 *   /livechat/resolve        → Resolve/close a chat (POST)
 *   /livechat/transfer       → Transfer chat to another agent (POST)
 *   /livechat/poll/{id}      → Poll for new messages (GET, AJAX)
 *   /livechat/status         → Toggle agent online/away/offline (POST)
 *   /livechat/dashboard      → AJAX refresh dashboard data (GET)
 *   /livechat/admin          → Admin overlord view
 *   /livechat/assign         → Admin force-assign (POST)
 *   /livechat/agents         → Admin manage CS agents (GET/POST)
 *   /livechat/chatbots       → Chatbot management (list / create)
 *   /livechat/chatbots/{slug}→ Individual chatbot settings page
 *   /livechat/chatbotsave    → Save chatbot config (POST AJAX)
 *   /livechat/chatbottoken   → Generate widget token (POST AJAX)
 *   /livechat/chatbottriggers→ Save trigger rules (POST AJAX)
 *   /livechat/chatbotkbs     → Link/unlink knowledge bases (POST AJAX)
 *   /livechat/chatbotcrawl   → Trigger crawl (POST AJAX)
 */

namespace app;

use \Flight as Flight;
use \app\Bean;

class Livechat extends BaseControls\Control {

    private ?object $csAgent = null;

    /**
     * Load CS agent record for current member (called by agent endpoints)
     */
    private function requireAgent(): bool {
        if (!$this->requireLogin()) return false;

        $memberId = (int) ($this->member['id'] ?? 0);
        $this->csAgent = Bean::findOne('csagents', ' member_id = ? ', [$memberId]);
        if (!$this->csAgent) {
            Flight::jsonError('You are not registered as a CS agent.', 403);
            return false;
        }
        return true;
    }

    /**
     * Agent dashboard — queue + active chats
     */
    public function index($params = []) {
        if (!$this->requireLogin()) return;

        $memberId = (int) ($this->member['id'] ?? 0);
        $this->csAgent = Bean::findOne('csagents', ' member_id = ? ', [$memberId]);

        // If not a CS agent, redirect to admin if they have permission, otherwise deny
        if (!$this->csAgent) {
            if (Flight::hasLevel(LEVELS['ADMIN'])) {
                Flight::redirect('/livechat/admin');
                return;
            }
            Flight::jsonError('You are not registered as a CS agent.', 403);
            return;
        }

        $this->render('livechat/index', [
            'agent' => $this->agentToArray($this->csAgent),
            'member' => $this->member,
        ]);
    }

    /**
     * Single chat view
     */
    public function chat($params = []) {
        if (!$this->requireAgent()) return;

        $sessionId = (int) ($params[0] ?? 0);
        if ($sessionId <= 0) {
            Flight::jsonError('Session ID required', 400);
            return;
        }

        $session = Bean::load('customersessions', $sessionId);
        if (!$session || !$session->id) {
            Flight::jsonError('Session not found', 404);
            return;
        }

        // Only allow if assigned to this agent (or admin)
        $agentMemberId = (int) ($this->csAgent->member_id ?? 0);
        if ((int)$session->agent_member_id !== $agentMemberId && !Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::jsonError('This chat is not assigned to you.', 403);
            return;
        }

        $sessionModel = $session->box('Model_Customersessions');

        $this->render('livechat/chat', [
            'session' => $sessionModel->toArray(),
            'messages' => $sessionModel->getMessages(),
            'agent' => $this->agentToArray($this->csAgent),
            'member' => $this->member,
        ]);
    }

    /**
     * Pick up / claim a queued session
     */
    public function pickup($params = []) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }
        if (!$this->requireAgent()) return;

        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $sessionId = (int) ($body['session_id'] ?? 0);

        if ($sessionId <= 0) {
            Flight::jsonError('session_id required', 400);
            return;
        }

        $session = Bean::load('customersessions', $sessionId);
        if (!$session || !$session->id) {
            Flight::jsonError('Session not found', 404);
            return;
        }

        // Check it's unassigned and escalated
        if ($session->verification_status !== 'escalated') {
            Flight::jsonError('Session is not escalated', 400);
            return;
        }
        if (!empty($session->agent_member_id) && (int)$session->agent_member_id > 0) {
            Flight::jsonError('Session is already assigned to an agent', 400);
            return;
        }

        // Check agent capacity
        $agentInfo = $this->agentToArray($this->csAgent);
        if ($agentInfo['active_chats'] >= $agentInfo['max_concurrent']) {
            Flight::jsonError('You are at maximum concurrent chats (' . $agentInfo['max_concurrent'] . ')', 400);
            return;
        }

        // Assign
        $sessionModel = $session->box('Model_Customersessions');
        $sessionModel->assignAgent((int)$this->csAgent->member_id, $this->csAgent->display_name);
        $this->csAgent->last_active_at = date('Y-m-d H:i:s');
        Bean::store($this->csAgent);
        Bean::store($session);

        Flight::jsonSuccess(['session_id' => (int)$session->id], 'Chat picked up successfully.');
    }

    /**
     * Send a reply message to the customer
     */
    public function reply($params = []) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }
        if (!$this->requireAgent()) return;

        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $sessionId = (int) ($body['session_id'] ?? 0);
        $message = trim($body['message'] ?? '');

        if ($sessionId <= 0 || empty($message)) {
            Flight::jsonError('session_id and message required', 400);
            return;
        }

        $session = Bean::load('customersessions', $sessionId);
        if (!$session || !$session->id) {
            Flight::jsonError('Session not found', 404);
            return;
        }

        // Verify assigned to this agent
        $agentMemberId = (int) ($this->csAgent->member_id ?? 0);
        if ((int)$session->agent_member_id !== $agentMemberId && !Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::jsonError('This chat is not assigned to you.', 403);
            return;
        }

        $sessionModel = $session->box('Model_Customersessions');
        $sessionModel->addMessage('agent', $message);
        $session->last_message_at = date('Y-m-d H:i:s');
        $sessionModel->extendExpiry();
        Bean::store($session);

        // Touch agent activity
        $this->csAgent->last_active_at = date('Y-m-d H:i:s');
        Bean::store($this->csAgent);

        Flight::jsonSuccess(['total_count' => count($sessionModel->getMessages())], 'Reply sent.');
    }

    /**
     * Resolve / close a chat session
     */
    public function resolve($params = []) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }
        if (!$this->requireAgent()) return;

        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $sessionId = (int) ($body['session_id'] ?? 0);
        $summary = trim($body['summary'] ?? '');

        if ($sessionId <= 0) {
            Flight::jsonError('session_id required', 400);
            return;
        }

        $session = Bean::load('customersessions', $sessionId);
        if (!$session || !$session->id) {
            Flight::jsonError('Session not found', 404);
            return;
        }

        $agentMemberId = (int) ($this->csAgent->member_id ?? 0);
        if ((int)$session->agent_member_id !== $agentMemberId && !Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::jsonError('This chat is not assigned to you.', 403);
            return;
        }

        $captureKnowledge = $body['capture_knowledge'] ?? true;

        $sessionModel = $session->box('Model_Customersessions');
        $sessionModel->markResolved($summary);
        Bean::store($session);

        // Capture resolution to RAG knowledge base (non-blocking)
        if ($captureKnowledge && !empty($summary)) {
            $this->captureResolutionKnowledge($session, $sessionModel, $summary);
        }

        Flight::jsonSuccess(['session_id' => (int)$session->id], 'Chat resolved.');
    }

    /**
     * Transfer chat to another agent
     */
    public function transfer($params = []) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }
        if (!$this->requireAgent()) return;

        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $sessionId = (int) ($body['session_id'] ?? 0);
        $targetAgentId = (int) ($body['target_agent_id'] ?? 0);

        if ($sessionId <= 0 || $targetAgentId <= 0) {
            Flight::jsonError('session_id and target_agent_id required', 400);
            return;
        }

        $session = Bean::load('customersessions', $sessionId);
        if (!$session || !$session->id) {
            Flight::jsonError('Session not found', 404);
            return;
        }

        $targetAgent = Bean::load('csagents', $targetAgentId);
        if (!$targetAgent || !$targetAgent->id) {
            Flight::jsonError('Target agent not found', 404);
            return;
        }

        $targetInfo = $this->agentToArray($targetAgent);
        if ($targetInfo['active_chats'] >= $targetInfo['max_concurrent']) {
            Flight::jsonError('Target agent is at maximum capacity', 400);
            return;
        }

        $sessionModel = $session->box('Model_Customersessions');
        $fromName = $this->csAgent->display_name ?? 'Previous agent';
        $sessionModel->addMessage('system', "Chat transferred from {$fromName} to {$targetAgent->display_name}.");
        $session->agent_member_id = (int)$targetAgent->member_id;
        $session->last_message_at = date('Y-m-d H:i:s');
        Bean::store($session);

        $targetAgent->last_active_at = date('Y-m-d H:i:s');
        Bean::store($targetAgent);

        Flight::jsonSuccess(['session_id' => (int)$session->id], 'Chat transferred.');
    }

    /**
     * Poll for new messages in a chat session (AJAX, agent-side)
     */
    public function poll($params = []) {
        if (!$this->requireAgent()) return;

        $sessionId = (int) ($params[0] ?? 0);
        $lastCount = (int) ($_GET['last_count'] ?? 0);

        if ($sessionId <= 0) {
            Flight::jsonError('Session ID required', 400);
            return;
        }

        $session = Bean::load('customersessions', $sessionId);
        if (!$session || !$session->id) {
            Flight::jsonError('Session not found', 404);
            return;
        }

        $sessionModel = $session->box('Model_Customersessions');
        $allMessages = $sessionModel->getMessages();
        $totalCount = count($allMessages);

        $newMessages = ($lastCount > 0 && $lastCount < $totalCount)
            ? array_slice($allMessages, $lastCount)
            : [];

        Flight::jsonSuccess([
            'messages' => $newMessages,
            'total_count' => $totalCount,
            'status' => $session->verification_status,
        ]);
    }

    /**
     * Toggle agent status (online/away/offline)
     */
    public function status($params = []) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }
        if (!$this->requireAgent()) return;

        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $newStatus = $body['status'] ?? '';

        if (!in_array($newStatus, ['online', 'away', 'offline'])) {
            Flight::jsonError('Invalid status. Must be: online, away, offline', 400);
            return;
        }

        $this->csAgent->status = $newStatus;
        $this->csAgent->last_active_at = date('Y-m-d H:i:s');
        Bean::store($this->csAgent);

        Flight::jsonSuccess(['status' => $newStatus], 'Status updated.');
    }

    /**
     * AJAX: Refresh dashboard data (queue + active chats)
     */
    public function dashboard($params = []) {
        if (!$this->requireAgent()) return;

        $agentMemberId = (int) $this->csAgent->member_id;

        // Auto-resolve stale sessions (no activity for 10 minutes)
        $this->resolveStaleSessions();

        // Queue: escalated, unassigned, unresolved
        $queueSessions = Bean::find('customersessions',
            ' verification_status = ? AND (agent_member_id IS NULL OR agent_member_id = 0) AND resolved_at IS NULL ORDER BY queue_entered_at ASC, created_at ASC ',
            ['escalated']
        );

        $queue = [];
        foreach ($queueSessions as $s) {
            $model = $s->box('Model_Customersessions');
            $messages = $model->getMessages();
            $lastMsg = end($messages);
            $context = $model->getContext();
            $queue[] = [
                'id' => (int)$s->id,
                'email' => $s->email ?? 'Unknown',
                'order_name' => $context['verified_order']['name'] ?? null,
                'last_message' => $lastMsg ? mb_substr($lastMsg['content'], 0, 80) : '',
                'escalation_reason' => $context['escalation_reason'] ?? '',
                'wait_time' => $s->queue_entered_at ? $this->timeSince($s->queue_entered_at) : ($s->escalated_at ? $this->timeSince($s->escalated_at) : ''),
                'created_at' => $s->created_at,
            ];
        }

        // My active chats: escalated, assigned to me, not resolved
        $activeChats = Bean::find('customersessions',
            ' agent_member_id = ? AND verification_status = ? AND resolved_at IS NULL ORDER BY last_message_at DESC ',
            [$agentMemberId, 'escalated']
        );

        $active = [];
        foreach ($activeChats as $s) {
            $model = $s->box('Model_Customersessions');
            $messages = $model->getMessages();
            $lastMsg = end($messages);
            $context = $model->getContext();
            $active[] = [
                'id' => (int)$s->id,
                'email' => $s->email ?? 'Unknown',
                'order_name' => $context['verified_order']['name'] ?? null,
                'last_message' => $lastMsg ? mb_substr($lastMsg['content'], 0, 80) : '',
                'last_message_role' => $lastMsg['role'] ?? '',
                'message_count' => count($messages),
                'duration' => $s->escalated_at ? $this->timeSince($s->escalated_at) : '',
                'created_at' => $s->created_at,
            ];
        }

        // Resolved today count
        $today = date('Y-m-d');
        $resolvedToday = Bean::count('customersessions',
            ' agent_member_id = ? AND resolved_at IS NOT NULL AND resolved_at >= ? ',
            [$agentMemberId, $today . ' 00:00:00']
        );

        Flight::jsonSuccess([
            'queue' => $queue,
            'active' => $active,
            'queue_count' => count($queue),
            'active_count' => count($active),
            'resolved_today' => $resolvedToday,
            'agent_status' => $this->csAgent->status,
            'max_concurrent' => (int)$this->csAgent->max_concurrent,
        ]);
    }

    // =========================================================================
    // FORM + SNIPPET + RAG ENDPOINTS
    // =========================================================================

    /**
     * GET /livechat/intents/{session_id}
     * Returns available intent plugin forms for a session's dapp.
     */
    public function intents($params = []) {
        if (!$this->requireAgent()) return;

        $sessionId = (int) ($params[0] ?? 0);
        if ($sessionId <= 0) {
            Flight::jsonError('Session ID required', 400);
            return;
        }

        $session = Bean::load('customersessions', $sessionId);
        if (!$session || !$session->id) {
            Flight::jsonError('Session not found', 404);
            return;
        }

        $dappSlug = $this->getDappSlugForSession($session);
        if (!$dappSlug) {
            Flight::jsonSuccess(['intents' => []], 'No dapp linked to this session.');
            return;
        }

        $app = Bean::findOne('detachableapps', ' slug = ? ', [$dappSlug]);
        if (!$app) {
            Flight::jsonSuccess(['intents' => []], 'Dapp not found.');
            return;
        }

        $appConfig = json_decode($app->config_json ?: '{}', true);
        $intentPlugins = $appConfig['intent_plugins'] ?? [];

        $result = [];
        foreach ($intentPlugins as $key => $plugin) {
            if (empty($plugin['form'])) continue;
            $result[] = [
                'key' => $key,
                'label' => $plugin['label'] ?? ucfirst(str_replace('_', ' ', $key)),
                'prompt_text' => $plugin['prompt_text'] ?? '',
                'field_count' => count($plugin['form']['fields'] ?? []),
            ];
        }

        Flight::jsonSuccess(['intents' => $result]);
    }

    /**
     * POST /livechat/sendform
     * Agent sends an intent form to the customer widget.
     * Body: {session_id, intent_key}
     */
    public function sendform($params = []) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }
        if (!$this->requireAgent()) return;

        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $sessionId = (int) ($body['session_id'] ?? 0);
        $intentKey = trim($body['intent_key'] ?? '');

        if ($sessionId <= 0 || empty($intentKey)) {
            Flight::jsonError('session_id and intent_key required', 400);
            return;
        }

        $session = Bean::load('customersessions', $sessionId);
        if (!$session || !$session->id) {
            Flight::jsonError('Session not found', 404);
            return;
        }

        // Verify assigned to this agent
        $agentMemberId = (int) ($this->csAgent->member_id ?? 0);
        if ((int)$session->agent_member_id !== $agentMemberId && !Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::jsonError('This chat is not assigned to you.', 403);
            return;
        }

        $dappSlug = $this->getDappSlugForSession($session);
        if (!$dappSlug) {
            Flight::jsonError('No dapp linked to this session.', 400);
            return;
        }

        $app = Bean::findOne('detachableapps', ' slug = ? ', [$dappSlug]);
        if (!$app) {
            Flight::jsonError('Dapp not found.', 404);
            return;
        }

        $appConfig = json_decode($app->config_json ?: '{}', true);
        $intentPlugins = $appConfig['intent_plugins'] ?? [];
        $plugin = $intentPlugins[$intentKey] ?? null;

        if (!$plugin || empty($plugin['form'])) {
            Flight::jsonError('Intent plugin not found or has no form: ' . $intentKey, 404);
            return;
        }

        // Build prefill from session context
        $sessionModel = $session->box('Model_Customersessions');
        $prefill = $this->buildSessionPrefill($session, $sessionModel);

        // Build rich data for the form
        $formData = array_merge($plugin['form'], [
            'form_action' => $intentKey,
            'prefill' => $prefill,
        ]);

        $richData = [
            'type' => 'intent_form',
            'data' => $formData,
        ];

        $label = $plugin['label'] ?? ucfirst(str_replace('_', ' ', $intentKey));
        $promptText = $plugin['prompt_text'] ?? "Please fill out this form:";

        $sessionModel->addMessage('agent', $promptText, $richData);
        $session->last_message_at = date('Y-m-d H:i:s');
        $sessionModel->extendExpiry();
        Bean::store($session);

        // Touch agent activity
        $this->csAgent->last_active_at = date('Y-m-d H:i:s');
        Bean::store($this->csAgent);

        Flight::jsonSuccess([
            'intent_key' => $intentKey,
            'label' => $label,
        ], 'Form sent to customer.');
    }

    /**
     * GET/POST /livechat/snippets
     * GET: Returns system + personal snippets for the current agent.
     * POST: CRUD for personal snippets.
     */
    public function snippets($params = []) {
        if (!$this->requireAgent()) return;

        $agentId = (int) $this->csAgent->id;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $action = $body['action'] ?? 'create';

            if ($action === 'create') {
                $title = trim($body['title'] ?? '');
                $content = trim($body['content'] ?? '');
                $category = trim($body['category'] ?? 'General');

                if (empty($title) || empty($content)) {
                    Flight::jsonError('title and content required', 400);
                    return;
                }

                $snippet = Bean::dispense('cssnippets');
                $snippet->csagents_id = $agentId;
                $snippet->title = $title;
                $snippet->content = $content;
                $snippet->category = $category;
                $snippet->is_system = false;
                $snippet->sort_order = 0;
                Bean::store($snippet);

                Flight::jsonSuccess(['id' => (int)$snippet->id], 'Snippet created.');
                return;

            } elseif ($action === 'update') {
                $snippetId = (int) ($body['snippet_id'] ?? 0);
                $snippet = Bean::load('cssnippets', $snippetId);
                if (!$snippet || !$snippet->id || (int)$snippet->csagents_id !== $agentId) {
                    Flight::jsonError('Snippet not found or not yours.', 404);
                    return;
                }
                if (isset($body['title'])) $snippet->title = trim($body['title']);
                if (isset($body['content'])) $snippet->content = trim($body['content']);
                if (isset($body['category'])) $snippet->category = trim($body['category']);
                Bean::store($snippet);
                Flight::jsonSuccess([], 'Snippet updated.');
                return;

            } elseif ($action === 'delete') {
                $snippetId = (int) ($body['snippet_id'] ?? 0);
                $snippet = Bean::load('cssnippets', $snippetId);
                if (!$snippet || !$snippet->id || (int)$snippet->csagents_id !== $agentId) {
                    Flight::jsonError('Snippet not found or not yours.', 404);
                    return;
                }
                Bean::trash($snippet);
                Flight::jsonSuccess([], 'Snippet deleted.');
                return;
            }

            Flight::jsonError('Invalid action', 400);
            return;
        }

        // GET: Return system + personal snippets
        $systemSnippets = Bean::find('cssnippets',
            ' is_system = 1 ORDER BY category ASC, sort_order ASC, title ASC '
        );
        $personalSnippets = Bean::find('cssnippets',
            ' csagents_id = ? AND is_system = 0 ORDER BY category ASC, sort_order ASC, title ASC ',
            [$agentId]
        );

        $formatSnippet = function($s) {
            return [
                'id' => (int)$s->id,
                'title' => $s->title ?? '',
                'content' => $s->content ?? '',
                'category' => $s->category ?? 'General',
                'is_system' => (bool)$s->is_system,
            ];
        };

        Flight::jsonSuccess([
            'system' => array_values(array_map($formatSnippet, $systemSnippets)),
            'personal' => array_values(array_map($formatSnippet, $personalSnippets)),
        ]);
    }

    // =========================================================================
    // ADMIN ENDPOINTS
    // =========================================================================

    /**
     * Admin overlord view — all agents, all chats, full queue
     */
    public function admin($params = []) {
        if (!$this->requireLogin()) return;
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::jsonError('Admin access required', 403);
            return;
        }

        // If AJAX request, return JSON data
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            $this->adminData();
            return;
        }

        $this->render('livechat/admin', [
            'member' => $this->member,
        ]);
    }

    /**
     * Admin AJAX data refresh
     */
    private function adminData(): void {
        // All agents
        $agentBeans = Bean::findAll('csagents', ' ORDER BY status ASC, display_name ASC ');
        $agents = [];
        foreach ($agentBeans as $a) {
            $agents[] = $this->agentToArray($a);
        }

        // Queue
        $queueSessions = Bean::find('customersessions',
            ' verification_status = ? AND (agent_member_id IS NULL OR agent_member_id = 0) AND resolved_at IS NULL ORDER BY queue_entered_at ASC, created_at ASC ',
            ['escalated']
        );
        $queue = [];
        foreach ($queueSessions as $s) {
            $model = $s->box('Model_Customersessions');
            $context = $model->getContext();
            $queue[] = [
                'id' => (int)$s->id,
                'email' => $s->email ?? 'Unknown',
                'escalation_reason' => $context['escalation_reason'] ?? '',
                'wait_time' => $s->queue_entered_at ? $this->timeSince($s->queue_entered_at) : '',
            ];
        }

        // All active chats
        $activeSessions = Bean::find('customersessions',
            ' verification_status = ? AND agent_member_id IS NOT NULL AND agent_member_id > 0 AND resolved_at IS NULL ORDER BY last_message_at DESC ',
            ['escalated']
        );
        $activeChats = [];
        foreach ($activeSessions as $s) {
            $model = $s->box('Model_Customersessions');
            $messages = $model->getMessages();
            $agentBean = Bean::findOne('csagents', ' member_id = ? ', [(int)$s->agent_member_id]);
            $activeChats[] = [
                'id' => (int)$s->id,
                'email' => $s->email ?? 'Unknown',
                'agent_name' => $agentBean ? $agentBean->display_name : 'Unknown',
                'agent_id' => $agentBean ? (int)$agentBean->id : 0,
                'message_count' => count($messages),
                'duration' => $s->escalated_at ? $this->timeSince($s->escalated_at) : '',
            ];
        }

        Flight::jsonSuccess([
            'agents' => $agents,
            'queue' => $queue,
            'active_chats' => $activeChats,
        ]);
    }

    /**
     * Admin: force-assign a session to a specific agent
     */
    public function assign($params = []) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }
        if (!$this->requireLogin()) return;
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::jsonError('Admin access required', 403);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $sessionId = (int) ($body['session_id'] ?? 0);
        $agentId = (int) ($body['agent_id'] ?? 0);

        if ($sessionId <= 0 || $agentId <= 0) {
            Flight::jsonError('session_id and agent_id required', 400);
            return;
        }

        $session = Bean::load('customersessions', $sessionId);
        if (!$session || !$session->id) {
            Flight::jsonError('Session not found', 404);
            return;
        }

        $agent = Bean::load('csagents', $agentId);
        if (!$agent || !$agent->id) {
            Flight::jsonError('Agent not found', 404);
            return;
        }

        $sessionModel = $session->box('Model_Customersessions');
        $sessionModel->assignAgent((int)$agent->member_id, $agent->display_name);
        Bean::store($session);

        $agent->last_active_at = date('Y-m-d H:i:s');
        Bean::store($agent);

        Flight::jsonSuccess(['session_id' => (int)$session->id], 'Session assigned to ' . $agent->display_name);
    }

    /**
     * Admin: manage CS agents (list, add, update, remove)
     */
    public function agents($params = []) {
        if (!$this->requireLogin()) return;
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::jsonError('Admin access required', 403);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $action = $body['action'] ?? 'add';

            if ($action === 'add') {
                $memberId = (int) ($body['member_id'] ?? 0);
                $displayName = trim($body['display_name'] ?? '');
                $maxConcurrent = (int) ($body['max_concurrent'] ?? 3);

                if ($memberId <= 0 || empty($displayName)) {
                    Flight::jsonError('member_id and display_name required', 400);
                    return;
                }

                // Check if already exists
                $existing = Bean::findOne('csagents', ' member_id = ? ', [$memberId]);
                if ($existing) {
                    Flight::jsonError('This member is already a CS agent', 400);
                    return;
                }

                $agent = Bean::dispense('csagents');
                $agent->member_id = $memberId;
                $agent->display_name = $displayName;
                $agent->max_concurrent = $maxConcurrent;
                $agent->status = 'offline';
                Bean::store($agent);

                Flight::jsonSuccess(['id' => (int)$agent->id], 'Agent added.');
                return;

            } elseif ($action === 'update') {
                $agentId = (int) ($body['agent_id'] ?? 0);
                $agent = Bean::load('csagents', $agentId);
                if (!$agent || !$agent->id) {
                    Flight::jsonError('Agent not found', 404);
                    return;
                }
                if (isset($body['display_name'])) $agent->display_name = trim($body['display_name']);
                if (isset($body['max_concurrent'])) $agent->max_concurrent = (int) $body['max_concurrent'];
                if (isset($body['status'])) $agent->status = $body['status'];
                Bean::store($agent);
                Flight::jsonSuccess([], 'Agent updated.');
                return;

            } elseif ($action === 'remove') {
                $agentId = (int) ($body['agent_id'] ?? 0);
                $agent = Bean::load('csagents', $agentId);
                if (!$agent || !$agent->id) {
                    Flight::jsonError('Agent not found', 404);
                    return;
                }
                Bean::trash($agent);
                Flight::jsonSuccess([], 'Agent removed.');
                return;
            }

            Flight::jsonError('Invalid action', 400);
            return;
        }

        // GET: List all agents + all members for add dropdown
        $agentBeans = Bean::findAll('csagents', ' ORDER BY display_name ASC ');
        $agents = [];
        foreach ($agentBeans as $a) {
            $agents[] = $this->agentToArray($a);
        }

        $members = Bean::findAll('member', ' ORDER BY display_name ASC, email ASC ');
        $memberList = [];
        foreach ($members as $m) {
            $name = $m->display_name
                ?: trim(($m->first_name ?? '') . ' ' . ($m->last_name ?? ''))
                ?: $m->email
                ?: 'Member #' . $m->id;
            $memberList[] = [
                'id' => (int)$m->id,
                'name' => $name,
                'email' => $m->email ?? '',
            ];
        }

        Flight::jsonSuccess([
            'agents' => $agents,
            'members' => $memberList,
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Auto-resolve sessions with no activity for 10 minutes.
     * Called from dashboard() polling — runs at most once per 30s via simple time check.
     */
    private function resolveStaleSessions(): void {
        static $lastRun = 0;
        $now = time();
        if ($now - $lastRun < 30) return; // throttle to once per 30s
        $lastRun = $now;

        $cutoff = date('Y-m-d H:i:s', $now - 600); // 10 minutes ago
        $staleSessions = Bean::find('customersessions',
            ' resolved_at IS NULL AND last_message_at IS NOT NULL AND last_message_at < ? AND verification_status IN (?, ?) ',
            [$cutoff, 'verified', 'escalated']
        );

        foreach ($staleSessions as $s) {
            $s->resolved_at = date('Y-m-d H:i:s');
            $s->resolution_summary = 'Timed out (no activity for 10 minutes)';
            Bean::store($s);
        }
    }

    /**
     * Human-readable "X min ago" from a datetime string
     */
    private function timeSince(string $datetime): string {
        $seconds = time() - strtotime($datetime);
        if ($seconds < 60) return 'just now';
        $minutes = (int) floor($seconds / 60);
        if ($minutes < 60) return $minutes . 'min';
        $hours = (int) floor($minutes / 60);
        return $hours . 'h ' . ($minutes % 60) . 'min';
    }

    /**
     * Extract dapp_slug from a session's context_json.
     */
    private function getDappSlugForSession($session): ?string {
        $contextJson = $session->context_json ?: '{}';
        $context = json_decode($contextJson, true) ?: [];
        return $context['dapp_slug'] ?? null;
    }

    /**
     * Build prefill values for intent forms from session state.
     */
    private function buildSessionPrefill($session, $sessionModel): array {
        $prefill = [];

        $email = $session->email ?? null;
        if ($email) $prefill['email'] = $email;

        $ctx = $sessionModel->getContext();
        if (!empty($ctx['verified_order']['name'])) {
            $prefill['order_number'] = ltrim($ctx['verified_order']['name'], '#');
        }

        return $prefill;
    }

    /**
     * Capture resolution pattern to RAG knowledge base.
     * Non-blocking — resolve always succeeds even if RAG is down.
     */
    private function captureResolutionKnowledge($session, $sessionModel, string $summary): void {
        try {
            $logger = Flight::get('log');

            // Get-or-create the cs-resolutions KB
            $kb = Bean::findOne('knowledgebases', ' slug = ? ', ['cs-resolutions']);
            if (!$kb) {
                $kb = Bean::dispense('knowledgebases');
                $kb->name = 'CS Resolutions';
                $kb->slug = 'cs-resolutions';
                $kb->description = 'Auto-captured customer support resolution patterns from live chat.';
                $kb->created_at = date('Y-m-d H:i:s');
                Bean::store($kb);
            }

            // Build structured markdown document
            $ctx = $sessionModel->getContext();
            $messages = $ctx['messages'] ?? [];
            $order = $ctx['verified_order'] ?? null;
            $escalationReason = $ctx['escalation_reason'] ?? '';

            $md = "# CS Resolution: Session #{$session->id}\n\n";
            $md .= "## Metadata\n";
            $md .= "- **Date**: " . date('Y-m-d H:i:s') . "\n";
            $md .= "- **Customer Email**: " . ($session->email ?: 'N/A') . "\n";
            if ($order) {
                $md .= "- **Order**: " . ($order['name'] ?? 'N/A') . "\n";
                $md .= "- **Order Status**: " . ($order['status'] ?? 'N/A') . "\n";
            }
            if ($escalationReason) {
                $md .= "- **Escalation Reason**: {$escalationReason}\n";
            }
            $agentName = $this->csAgent->display_name ?? 'Unknown';
            $md .= "- **Resolved By**: {$agentName}\n";

            $md .= "\n## Resolution Summary\n{$summary}\n";

            $md .= "\n## Conversation Transcript\n";
            foreach ($messages as $msg) {
                $role = ucfirst($msg['role'] ?? 'unknown');
                $content = $msg['content'] ?? '';
                $ts = $msg['timestamp'] ?? '';
                $md .= "**[{$ts}] {$role}**: {$content}\n\n";
            }

            // Write temp file
            $tmpFile = tempnam(sys_get_temp_dir(), 'cs_resolution_');
            $tmpMd = $tmpFile . '.md';
            rename($tmpFile, $tmpMd);
            file_put_contents($tmpMd, $md);

            // Create ragdocuments record
            $doc = Bean::dispense('ragdocuments');
            $doc->knowledgebase_id = (int)$kb->id;
            $doc->member_id = (int)($this->csAgent->member_id ?? 0);
            $doc->filename = 'cs-resolution-' . (int)$session->id . '.md';
            $doc->original_filename = $doc->filename;
            $doc->file_size = strlen($md);
            $doc->mime_type = 'text/markdown';
            $doc->file_extension = 'md';
            $doc->status = 'processing';
            $doc->created_at = date('Y-m-d H:i:s');
            Bean::store($doc);

            // Ingest text directly via DocumentIngestionService
            $workspace = $_SESSION['workspace_slug'] ?? ($_SERVER['WORKSPACE'] ?? 'default');
            $service = \app\services\DocumentIngestionService::forWorkspace($workspace);
            $result = $service->ingestText($md, $doc->filename, [
                'doc_id' => $doc->id,
                'source' => 'cs-resolution',
                'knowledgebases_id' => (int)$kb->id,
                'ragdocuments_id' => (int)$doc->id,
            ]);

            if ($result['success']) {
                $doc->status = 'ready';
                $doc->chunk_count = $result['chunk_count'] ?? 0;
            } else {
                $doc->status = 'error';
                $doc->error_message = $result['error'] ?? 'Ingestion failed';
            }
            Bean::store($doc);

            @unlink($tmpMd);

            $logger->info('RAG resolution captured', [
                'session_id' => (int)$session->id,
                'doc_id' => (int)$doc->id,
                'kb_id' => (int)$kb->id,
            ]);
        } catch (\Throwable $e) {
            // Non-blocking — log and continue
            $logger = Flight::get('log');
            $logger->warning('RAG resolution capture failed (non-fatal)', [
                'session_id' => (int)$session->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ======================================================================
    // SESSIONS MONITORING (Part 1)
    // ======================================================================

    /**
     * GET /livechat/sessions — paginated list of ALL customer sessions
     * Query params: status, email, date_from, date_to, page, per_page
     */
    public function sessions($params = []) {
        if (!$this->requireAgent()) return;

        $status = trim($_GET['status'] ?? 'all');
        $email = trim($_GET['email'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(50, max(10, (int)($_GET['per_page'] ?? 20)));

        $where = ' 1=1 ';
        $bindings = [];

        if ($status !== 'all') {
            $where .= ' AND verification_status = ? ';
            $bindings[] = $status;
        }
        if (!empty($email)) {
            $where .= ' AND email LIKE ? ';
            $bindings[] = '%' . $email . '%';
        }
        if (!empty($dateFrom)) {
            $where .= ' AND created_at >= ? ';
            $bindings[] = $dateFrom . ' 00:00:00';
        }
        if (!empty($dateTo)) {
            $where .= ' AND created_at <= ? ';
            $bindings[] = $dateTo . ' 23:59:59';
        }

        $total = Bean::count('customersessions', $where, $bindings);
        $offset = ($page - 1) * $perPage;

        $sessions = Bean::find('customersessions',
            $where . " ORDER BY COALESCE(last_message_at, created_at) DESC LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        $result = [];
        foreach ($sessions as $s) {
            $model = $s->box('Model_Customersessions');
            $messages = $model->getMessages();
            $lastMsg = !empty($messages) ? end($messages) : null;
            $ctx = $model->getContext();

            $agentName = null;
            if ($s->agent_member_id) {
                $agentBean = Bean::findOne('csagents', ' member_id = ? ', [(int)$s->agent_member_id]);
                $agentName = $agentBean ? $agentBean->display_name : 'Unknown';
            }

            $result[] = [
                'id' => (int)$s->id,
                'email' => $s->email ?: 'Anonymous',
                'status' => $s->verification_status ?? 'pending',
                'sentiment' => $s->current_sentiment ?? 'neutral',
                'subject' => $s->subject ?? ($lastMsg ? mb_substr($lastMsg['content'] ?? '', 0, 50) : 'New conversation'),
                'agent_name' => $agentName,
                'message_count' => count($messages),
                'last_message' => $lastMsg ? mb_substr($lastMsg['content'] ?? '', 0, 100) : '',
                'last_message_role' => $lastMsg['role'] ?? '',
                'order_name' => $ctx['verified_order']['name'] ?? null,
                'has_agent' => !empty($s->agent_member_id),
                'created_at' => $s->created_at ?? null,
                'last_message_at' => $s->last_message_at ?? null,
                'resolved_at' => $s->resolved_at ?? null,
            ];
        }

        Flight::jsonSuccess([
            'sessions' => $result,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int)ceil($total / $perPage),
        ]);
    }

    /**
     * GET /livechat/viewsession/{id} — read-only transcript viewer with takeover option
     */
    public function viewsession($params = []) {
        if (!$this->requireAgent()) return;

        $sessionId = (int) ($params[0] ?? 0);
        if ($sessionId <= 0) {
            Flight::jsonError('Session ID required', 400);
            return;
        }

        $session = Bean::load('customersessions', $sessionId);
        if (!$session || !$session->id) {
            Flight::jsonError('Session not found', 404);
            return;
        }

        $sessionModel = $session->box('Model_Customersessions');
        $agentMemberId = (int) ($this->csAgent->member_id ?? 0);
        $isAssignedToMe = ((int)$session->agent_member_id === $agentMemberId);

        // If assigned to this agent, redirect to interactive chat view
        if ($isAssignedToMe) {
            Flight::redirect('/livechat/chat/' . $sessionId);
            return;
        }

        $canTakeover = $this->canTakeover($session);

        $this->render('livechat/chat', [
            'session' => $sessionModel->toArray(),
            'messages' => $sessionModel->getMessages(),
            'agent' => $this->agentToArray($this->csAgent),
            'member' => $this->member,
            'readOnly' => true,
            'canTakeover' => $canTakeover,
        ]);
    }

    /**
     * Can an agent take over this session? (bot-active or unassigned escalated)
     */
    private function canTakeover($session): bool {
        $status = $session->verification_status ?? '';
        if (in_array($status, ['verified', 'escalated']) && empty($session->agent_member_id)) {
            return true;
        }
        return false;
    }

    // ======================================================================
    // BREAK-IN / TAKEOVER (Part 2)
    // ======================================================================

    /**
     * POST /livechat/takeover — agent takes over a bot conversation mid-chat
     */
    public function takeover($params = []) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }
        if (!$this->requireAgent()) return;

        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $sessionId = (int) ($body['session_id'] ?? 0);

        if ($sessionId <= 0) {
            Flight::jsonError('session_id required', 400);
            return;
        }

        $session = Bean::load('customersessions', $sessionId);
        if (!$session || !$session->id) {
            Flight::jsonError('Session not found', 404);
            return;
        }

        $status = $session->verification_status ?? '';
        if (!in_array($status, ['verified', 'escalated', 'pending'])) {
            Flight::jsonError('Cannot take over a ' . $status . ' session', 400);
            return;
        }
        if (!empty($session->agent_member_id) && (int)$session->agent_member_id > 0) {
            Flight::jsonError('Session is already assigned to an agent', 400);
            return;
        }

        // Check agent capacity
        $agentInfo = $this->agentToArray($this->csAgent);
        if ($agentInfo['active_chats'] >= $agentInfo['max_concurrent']) {
            Flight::jsonError('You are at maximum concurrent chats', 400);
            return;
        }

        // Perform takeover
        $session->verification_status = 'escalated';
        $session->escalated_at = $session->escalated_at ?: date('Y-m-d H:i:s');
        $session->agent_member_id = (int)$this->csAgent->member_id;
        $session->queue_entered_at = null;
        $session->last_message_at = date('Y-m-d H:i:s');

        $sessionModel = $session->box('Model_Customersessions');
        $agentName = $this->csAgent->display_name ?? 'An agent';
        $sessionModel->addMessage('system', "{$agentName} has joined the chat.");
        Bean::store($session);

        $this->csAgent->last_active_at = date('Y-m-d H:i:s');
        Bean::store($this->csAgent);

        Flight::jsonSuccess([
            'session_id' => (int)$session->id
        ], 'You have taken over this chat.');
    }

    // ======================================================================
    // TYPING INDICATOR (Part 4c)
    // ======================================================================

    /**
     * POST /livechat/typing — agent signals typing status
     */
    public function typing($params = []) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }
        if (!$this->requireAgent()) return;

        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $sessionId = (int) ($body['session_id'] ?? 0);
        $isTyping = (bool) ($body['is_typing'] ?? false);

        if ($sessionId <= 0) {
            Flight::json(['success' => true]);
            return;
        }

        $session = Bean::load('customersessions', $sessionId);
        if (!$session || !$session->id) {
            Flight::json(['success' => true]);
            return;
        }

        $sessionModel = $session->box('Model_Customersessions');
        $ctx = $sessionModel->getContext();
        $ctx['agent_typing'] = $isTyping ? date('Y-m-d H:i:s') : null;
        $sessionModel->setContext($ctx);
        Bean::store($session);

        Flight::json(['success' => true]);
    }

    // ======================================================================
    // KNOWLEDGE BASE MANAGEMENT (Part 3)
    // ======================================================================

    /**
     * GET/POST /livechat/knowledge — manage KB documents for chatbot
     */
    public function knowledge($params = []) {
        if (!$this->requireLogin()) return;
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::jsonError('Admin access required', 403);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $action = $body['action'] ?? 'create';

            if ($action === 'create') {
                return $this->createKnowledgeDocument($body);
            } elseif ($action === 'delete') {
                return $this->deleteKnowledgeDocument($body);
            }

            Flight::jsonError('Invalid action', 400);
            return;
        }

        // GET: List documents from both KBs
        $policyKb = $this->getOrCreateKB('store-policies', 'Store Policies',
            'Store policies, FAQ, and reference content for the chat bot.');
        $resolutionsKb = Bean::findOne('knowledgebases', ' slug = ? ', ['cs-resolutions']);

        $policyDocs = $this->getKbDocuments($policyKb);
        $resolutionDocs = $resolutionsKb ? $this->getKbDocuments($resolutionsKb) : [];

        Flight::jsonSuccess([
            'policy_kb' => ['id' => (int)$policyKb->id, 'name' => $policyKb->name, 'slug' => $policyKb->slug],
            'resolution_kb' => $resolutionsKb ? ['id' => (int)$resolutionsKb->id, 'name' => $resolutionsKb->name] : null,
            'policy_documents' => $policyDocs,
            'resolution_documents' => $resolutionDocs,
            'templates' => $this->getPolicyTemplates(),
        ]);
    }

    private function getOrCreateKB(string $slug, string $name, string $description): object {
        $kb = Bean::findOne('knowledgebases', ' slug = ? ', [$slug]);
        if (!$kb) {
            $kb = Bean::dispense('knowledgebases');
            $kb->name = $name;
            $kb->slug = $slug;
            $kb->description = $description;
            $kb->created_at = date('Y-m-d H:i:s');
            Bean::store($kb);
        }
        return $kb;
    }

    private function getKbDocuments($kb): array {
        $docs = Bean::find('ragdocuments', ' knowledgebase_id = ? ORDER BY created_at DESC ', [(int)$kb->id]);
        $result = [];
        foreach ($docs as $d) {
            $result[] = [
                'id' => (int)$d->id,
                'filename' => $d->original_filename ?? $d->filename ?? 'Untitled',
                'status' => $d->status ?? 'unknown',
                'chunk_count' => (int)($d->chunk_count ?? 0),
                'file_size' => (int)($d->file_size ?? 0),
                'created_at' => $d->created_at ?? null,
            ];
        }
        return $result;
    }

    private function getPolicyTemplates(): array {
        return [
            ['key' => 'return_policy', 'label' => 'Return & Refund Policy', 'placeholder' => "# Return & Refund Policy\n\n## Return Window\nCustomers have 30 days from delivery to initiate a return.\n\n## Conditions\n- Items must be unused and in original packaging\n- Proof of purchase required\n- Final sale items are not eligible\n\n## Process\n1. Contact us via chat or email\n2. We'll provide a return shipping label\n3. Ship the item back within 7 days\n\n## Refund Timeline\nRefunds are processed within 5-7 business days after we receive the return."],
            ['key' => 'shipping_policy', 'label' => 'Shipping Policy', 'placeholder' => "# Shipping Policy\n\n## Processing Time\nOrders ship within 1-2 business days.\n\n## Shipping Methods\n- Standard: 5-7 business days\n- Express: 2-3 business days\n- Free shipping on orders over \$100\n\n## International Shipping\nWe ship to select international destinations. Additional duties and taxes may apply."],
            ['key' => 'privacy_policy', 'label' => 'Privacy Policy', 'placeholder' => "# Privacy Policy\n\n## Information We Collect\n- Name and email when you create an account or place an order\n- Shipping and billing address\n- Payment information (processed securely by our payment provider)\n\n## How We Use Your Information\n- To process and fulfill orders\n- To send order updates and tracking info\n- To improve our products and services\n\n## Data Retention\nWe retain your information for as long as your account is active or as needed to provide services."],
            ['key' => 'warranty', 'label' => 'Warranty Policy', 'placeholder' => "# Warranty Policy\n\n## Coverage\nAll products come with a 1-year manufacturer warranty covering defects in materials and workmanship.\n\n## What's Not Covered\n- Normal wear and tear\n- Damage from misuse or accidents\n- Unauthorized modifications\n\n## Claim Process\n1. Contact customer support with your order number\n2. Describe the issue with photos if possible\n3. We'll arrange a replacement or repair"],
            ['key' => 'faq', 'label' => 'Frequently Asked Questions', 'placeholder' => "# Frequently Asked Questions\n\n## Q: How do I track my order?\nA: You'll receive a tracking email once your order ships. You can also check your order status by contacting us with your order number.\n\n## Q: Can I change or cancel my order?\nA: Orders can be modified within 2 hours of placement. After that, please contact us and we'll do our best to accommodate changes.\n\n## Q: Do you offer gift wrapping?\nA: Yes! Add gift wrapping at checkout for a small fee.\n\n## Q: What payment methods do you accept?\nA: We accept all major credit cards, PayPal, and Shop Pay."],
        ];
    }

    private function createKnowledgeDocument(array $body): void {
        $title = trim($body['title'] ?? '');
        $content = trim($body['content'] ?? '');
        $kbSlug = $body['kb_slug'] ?? 'store-policies';

        if (empty($title) || empty($content)) {
            Flight::jsonError('title and content required', 400);
            return;
        }

        $kb = $this->getOrCreateKB($kbSlug, 'Store Policies', 'Store policies for chat bot.');
        $logger = Flight::get('log');

        try {
            // Slugify filename
            $filename = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));
            $filename = trim($filename, '-') . '.md';

            $tmpFile = tempnam(sys_get_temp_dir(), 'kb_policy_');
            $tmpMd = $tmpFile . '.md';
            rename($tmpFile, $tmpMd);
            file_put_contents($tmpMd, $content);

            $doc = Bean::dispense('ragdocuments');
            $doc->knowledgebase_id = (int)$kb->id;
            $doc->member_id = (int)($this->member['id'] ?? 0);
            $doc->filename = $filename;
            $doc->original_filename = $title . '.md';
            $doc->file_size = strlen($content);
            $doc->mime_type = 'text/markdown';
            $doc->file_extension = 'md';
            $doc->status = 'processing';
            $doc->created_at = date('Y-m-d H:i:s');
            Bean::store($doc);

            $workspace = $_SESSION['workspace_slug'] ?? ($_SERVER['WORKSPACE'] ?? 'default');
            $service = \app\services\DocumentIngestionService::forWorkspace($workspace);
            $result = $service->ingestText($content, $doc->filename, [
                'doc_id' => $doc->id,
                'source' => 'kb-policy',
                'knowledgebases_id' => (int)$kb->id,
                'ragdocuments_id' => (int)$doc->id,
            ]);

            if ($result['success']) {
                $doc->status = 'ready';
                $doc->chunk_count = $result['chunk_count'] ?? 0;
            } else {
                $doc->status = 'error';
                $doc->error_message = $result['error'] ?? 'Ingestion failed';
            }
            Bean::store($doc);
            @unlink($tmpMd);

            $logger->info('KB document created', [
                'doc_id' => (int)$doc->id,
                'kb_slug' => $kbSlug,
                'title' => $title,
            ]);

            Flight::jsonSuccess(['doc_id' => (int)$doc->id, 'status' => $doc->status], 'Document created and queued for ingestion.');
        } catch (\Throwable $e) {
            $logger->warning('KB document creation failed', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to create document: ' . $e->getMessage(), 500);
        }
    }

    private function deleteKnowledgeDocument(array $body): void {
        $docId = (int)($body['doc_id'] ?? 0);
        if ($docId <= 0) {
            Flight::jsonError('doc_id required', 400);
            return;
        }

        $doc = Bean::load('ragdocuments', $docId);
        if (!$doc || !$doc->id) {
            Flight::jsonError('Document not found', 404);
            return;
        }

        Bean::trash($doc);
        Flight::jsonSuccess([], 'Document deleted.');
    }

    /**
     * Convert a csagents bean to array without relying on FUSE model box().
     */
    // ── Chatbot Management ─────────────────────────────────────────────

    /**
     * List all chatbots or show individual chatbot settings page.
     * GET /livechat/chatbots         → list page
     * GET /livechat/chatbots/{slug}  → settings page for one chatbot
     * POST /livechat/chatbots        → create new chatbot
     */
    public function chatbots($params = []) {
        if (!$this->requireLogin()) return;
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::jsonError('Admin access required', 403);
            return;
        }

        $slug = $params[0] ?? null;

        // POST: create new chatbot
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$slug) {
            $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            return $this->createChatbot($body);
        }

        // GET with slug: individual chatbot settings page
        if ($slug) {
            $chatbot = \Model_Chatbots::findBySlug($slug);
            if (!$chatbot) {
                Flight::jsonError('Chatbot not found', 404);
                return;
            }

            $model = $chatbot->box('Model_Chatbots');

            // Load available connections and pipelines for dropdowns
            $connections = Bean::find('connections', ' enabled = 1 ORDER BY connection_name ASC ');
            $connList = [];
            foreach ($connections as $c) {
                $connList[] = [
                    'id' => (int)$c->id,
                    'name' => $c->connection_name ?: $c->external_name ?: $c->external_eid,
                    'type' => $c->connector_type,
                    'domain' => $c->external_eid,
                ];
            }

            $pipelines = Bean::find('pipelines', ' is_active = 1 ORDER BY name ASC ');
            $pipeList = [];
            foreach ($pipelines as $p) {
                $pipeList[] = [
                    'id' => (int)$p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                ];
            }

            $allKbs = Bean::findAll('knowledgebases', ' ORDER BY name ASC ');
            $kbList = [];
            foreach ($allKbs as $kb) {
                $kbList[] = [
                    'id' => (int)$kb->id,
                    'name' => $kb->name,
                    'slug' => $kb->slug,
                ];
            }

            $this->render('livechat/chatbotsettings', [
                'member' => $this->member,
                'chatbot' => $model->toArray(),
                'connections' => $connList,
                'pipelines' => $pipeList,
                'knowledgebases' => $kbList,
            ]);
            return;
        }

        // GET: list all chatbots
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            return $this->chatbotsData();
        }

        $this->render('livechat/chatbots', [
            'member' => $this->member,
        ]);
    }

    /**
     * AJAX: return chatbots list as JSON
     */
    private function chatbotsData(): void {
        $bots = Bean::findAll('chatbots', ' ORDER BY name ASC ');
        $result = [];
        foreach ($bots as $bot) {
            $result[] = $bot->box('Model_Chatbots')->toArray();
        }
        Flight::jsonSuccess(['chatbots' => $result]);
    }

    /**
     * Create a new chatbot
     */
    private function createChatbot(array $body): void {
        $name = trim($body['name'] ?? '');
        $mode = $body['mode'] ?? 'advisor';

        if (empty($name)) {
            Flight::jsonError('Name is required', 400);
            return;
        }

        $slug = \Model_Chatbots::generateSlug($name);

        // Ensure unique slug
        $existing = \Model_Chatbots::findBySlug($slug);
        if ($existing) {
            Flight::jsonError('A chatbot with this name already exists', 400);
            return;
        }

        $chatbot = Bean::dispense('chatbots');
        $chatbot->name = $name;
        $chatbot->slug = $slug;
        $chatbot->mode = $mode;
        $chatbot->member_id = (int)($this->member['id'] ?? 0);

        if (!empty($body['connections_id'])) {
            $chatbot->connections_id = (int)$body['connections_id'];
        }
        if (!empty($body['pipelines_id'])) {
            $chatbot->pipelines_id = (int)$body['pipelines_id'];
        }

        // Generate widget token
        $chatbot->box('Model_Chatbots')->generateWidgetToken();

        // Set default config
        $defaultConfig = [
            'welcome_message' => 'Hi! How can I help you today?',
            'require_order_verification' => ($mode === 'advisor'),
            'session_expiry_seconds' => 3600,
            'max_message_length' => 2000,
        ];
        $chatbot->config_json = json_encode($defaultConfig, JSON_UNESCAPED_SLASHES);

        // Set default triggers for advisor mode
        if ($mode === 'advisor') {
            $defaultTriggers = [
                ['name' => 'Talk to Human', 'keywords' => ['manager', 'human', 'supervisor', 'complaint'], 'action' => 'escalate', 'pipeline_id' => null, 'enabled' => true],
            ];
            $chatbot->triggers_json = json_encode($defaultTriggers, JSON_UNESCAPED_SLASHES);
        }

        Bean::store($chatbot);

        Flight::jsonSuccess([
            'chatbot' => $chatbot->box('Model_Chatbots')->toArray(),
            'redirect' => '/livechat/chatbots/' . $slug,
        ], 'Chatbot created');
    }

    /**
     * Save chatbot general config (AJAX POST)
     */
    public function chatbotsave($params = []) {
        if (!$this->requireLogin()) return;
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::jsonError('Admin access required', 403);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Flight::jsonError('POST required', 405);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $chatbotId = (int)($body['chatbot_id'] ?? 0);

        if (!$chatbotId) {
            Flight::jsonError('chatbot_id required', 400);
            return;
        }

        $chatbot = Bean::load('chatbots', $chatbotId);
        if (!$chatbot || !$chatbot->id) {
            Flight::jsonError('Chatbot not found', 404);
            return;
        }

        // Update basic fields
        if (isset($body['name'])) {
            $chatbot->name = trim($body['name']);
        }
        if (isset($body['mode'])) {
            $chatbot->mode = $body['mode'];
        }
        if (isset($body['status'])) {
            $chatbot->status = $body['status'];
        }
        if (array_key_exists('connections_id', $body)) {
            $chatbot->connections_id = $body['connections_id'] ? (int)$body['connections_id'] : null;
        }
        if (array_key_exists('pipelines_id', $body)) {
            $chatbot->pipelines_id = $body['pipelines_id'] ? (int)$body['pipelines_id'] : null;
        }

        // Update config_json (merge with existing)
        if (isset($body['config']) && is_array($body['config'])) {
            $model = $chatbot->box('Model_Chatbots');
            $existing = $model->getConfig();
            $merged = array_merge($existing, $body['config']);
            $model->setConfig($merged);
        }

        Bean::store($chatbot);

        Flight::jsonSuccess([
            'chatbot' => $chatbot->box('Model_Chatbots')->toArray(),
        ], 'Chatbot saved');
    }

    /**
     * Generate or regenerate widget token (AJAX POST)
     */
    public function chatbottoken($params = []) {
        if (!$this->requireLogin()) return;
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::jsonError('Admin access required', 403);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Flight::jsonError('POST required', 405);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $chatbotId = (int)($body['chatbot_id'] ?? 0);

        $chatbot = Bean::load('chatbots', $chatbotId);
        if (!$chatbot || !$chatbot->id) {
            Flight::jsonError('Chatbot not found', 404);
            return;
        }

        $token = $chatbot->box('Model_Chatbots')->generateWidgetToken();
        Bean::store($chatbot);

        Flight::jsonSuccess([
            'widget_token' => $token,
        ], 'Widget token generated');
    }

    /**
     * Save trigger rules (AJAX POST)
     */
    public function chatbottriggers($params = []) {
        if (!$this->requireLogin()) return;
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::jsonError('Admin access required', 403);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Flight::jsonError('POST required', 405);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $chatbotId = (int)($body['chatbot_id'] ?? 0);
        $triggers = $body['triggers'] ?? [];

        $chatbot = Bean::load('chatbots', $chatbotId);
        if (!$chatbot || !$chatbot->id) {
            Flight::jsonError('Chatbot not found', 404);
            return;
        }

        // Validate trigger structure
        $validated = [];
        foreach ($triggers as $t) {
            if (empty($t['name']) || empty($t['keywords']) || empty($t['action'])) {
                continue;
            }
            $validated[] = [
                'name' => trim($t['name']),
                'keywords' => is_array($t['keywords']) ? $t['keywords'] : array_map('trim', explode(',', $t['keywords'])),
                'action' => in_array($t['action'], ['escalate', 'run_pipeline', 'escalate_and_run']) ? $t['action'] : 'escalate',
                'pipeline_id' => !empty($t['pipeline_id']) ? (int)$t['pipeline_id'] : null,
                'enabled' => (bool)($t['enabled'] ?? true),
            ];
        }

        $chatbot->box('Model_Chatbots')->setTriggers($validated);
        Bean::store($chatbot);

        Flight::jsonSuccess([
            'triggers' => $validated,
        ], 'Triggers saved');
    }

    /**
     * Link/unlink knowledge bases (AJAX POST)
     */
    public function chatbotkbs($params = []) {
        if (!$this->requireLogin()) return;
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::jsonError('Admin access required', 403);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Flight::jsonError('POST required', 405);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $chatbotId = (int)($body['chatbot_id'] ?? 0);
        $action = $body['action'] ?? '';

        $chatbot = Bean::load('chatbots', $chatbotId);
        if (!$chatbot || !$chatbot->id) {
            Flight::jsonError('Chatbot not found', 404);
            return;
        }

        $model = $chatbot->box('Model_Chatbots');

        if ($action === 'link') {
            $kbId = (int)($body['kb_id'] ?? 0);
            $priority = (int)($body['priority'] ?? 0);
            if (!$kbId) {
                Flight::jsonError('kb_id required', 400);
                return;
            }
            $model->linkKnowledgeBase($kbId, $priority);
        } elseif ($action === 'unlink') {
            $kbId = (int)($body['kb_id'] ?? 0);
            if (!$kbId) {
                Flight::jsonError('kb_id required', 400);
                return;
            }
            $model->unlinkKnowledgeBase($kbId);
        } else {
            Flight::jsonError('Invalid action (link or unlink)', 400);
            return;
        }

        Flight::jsonSuccess([
            'knowledge_bases' => $model->getLinkedKnowledgeBases(),
        ], 'Knowledge bases updated');
    }

    /**
     * Get documents from all linked KBs for a chatbot (AJAX GET)
     */
    public function chatbotkbdocs($params = []) {
        if (!$this->requireLogin()) return;
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::jsonError('Admin access required', 403);
            return;
        }

        $chatbotId = (int)($this->getParam('chatbot_id') ?? 0);
        if (!$chatbotId) {
            Flight::jsonError('chatbot_id required', 400);
            return;
        }

        $chatbot = Bean::load('chatbots', $chatbotId);
        if (!$chatbot || !$chatbot->id) {
            Flight::jsonError('Chatbot not found', 404);
            return;
        }

        // Get linked KBs and their documents
        $links = Bean::find('chatbotkbs', ' chatbots_id = ? ORDER BY priority DESC, id ASC ', [$chatbotId]);
        $kbsWithDocs = [];

        foreach ($links as $link) {
            $kb = Bean::load('knowledgebases', (int)$link->knowledgebases_id);
            if (!$kb || !$kb->id) continue;

            $kbsWithDocs[] = [
                'id' => (int)$kb->id,
                'name' => $kb->name,
                'slug' => $kb->slug,
                'description' => $kb->description,
                'documents' => $this->getKbDocuments($kb),
            ];
        }

        // Get latest scanner pipeline run if configured
        $model = $chatbot->box('Model_Chatbots');
        $scannerPipelineId = $model->getConfigValue('scanner_pipeline_id');
        $latestScan = null;

        if ($scannerPipelineId) {
            $run = Bean::findOne('pipelineruns',
                ' pipelines_id = ? ORDER BY created_at DESC LIMIT 1 ',
                [(int)$scannerPipelineId]
            );
            if ($run && $run->id) {
                $latestScan = [
                    'id' => (int)$run->id,
                    'status' => $run->status,
                    'created_at' => $run->created_at,
                    'progress_percent' => (int)($run->progress_percent ?? 0),
                ];
            }
        }

        Flight::jsonSuccess([
            'knowledge_bases' => $kbsWithDocs,
            'latest_scan' => $latestScan,
        ]);
    }

    /**
     * Trigger scanner pipeline for a chatbot (AJAX POST)
     * Replaces direct crawler calls — uses configurable pipeline instead.
     */
    public function chatbotcrawl($params = []) {
        if (!$this->requireLogin()) return;
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::jsonError('Admin access required', 403);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Flight::jsonError('POST required', 405);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $chatbotId = (int)($body['chatbot_id'] ?? 0);
        $action = $body['crawl_action'] ?? 'scan';

        $chatbot = Bean::load('chatbots', $chatbotId);
        if (!$chatbot || !$chatbot->id) {
            Flight::jsonError('Chatbot not found', 404);
            return;
        }

        $model = $chatbot->box('Model_Chatbots');
        $scannerPipelineId = (int)($model->getConfigValue('scanner_pipeline_id') ?? 0);

        // "scan" action dispatches the scanner pipeline
        if ($action === 'scan') {
            if (!$scannerPipelineId) {
                Flight::jsonError('No scanner pipeline configured. Set one in the Knowledge tab.', 400);
                return;
            }

            $pipeline = Bean::load('pipelines', $scannerPipelineId);
            if (!$pipeline || !$pipeline->id) {
                Flight::jsonError('Scanner pipeline not found', 404);
                return;
            }

            try {
                $workspace = $_SESSION['workspace_slug'] ?? 'default';

                // Use override connections_id if provided, else fall back to chatbot's connection
                $connectionId = !empty($body['connections_id'])
                    ? (int)$body['connections_id']
                    : ($chatbot->connections_id ? (int)$chatbot->connections_id : null);

                // Create pipeline run with chatbot context
                $run = Bean::dispense('pipelineruns');
                $run->run_uid = 'run-' . bin2hex(random_bytes(8));
                $run->pipelines = $pipeline;
                $run->member_id = (int)($this->member['id'] ?? 0);
                $run->trigger_source = 'chatbot_scanner';
                $run->trigger_data_json = json_encode([
                    'chatbot_id' => (int)$chatbot->id,
                    'chatbot_slug' => $chatbot->slug,
                    'connections_id' => $connectionId,
                ]);
                // Get first linked KB for storing crawled content
                $linkedKbs = $model->getLinkedKnowledgeBases();
                $kbId = !empty($linkedKbs) ? $linkedKbs[0]['kb_id'] : null;

                $run->status = 'pending';
                $run->context_json = json_encode([
                    'chatbot_name' => $chatbot->name,
                    'connections_id' => $connectionId,
                    'kb_id' => $kbId,
                    'workspace' => $workspace,
                ]);
                $stepCount = Bean::count('pipelinesteps', 'pipelines_id = ? AND is_active = 1', [$scannerPipelineId]);
                $run->steps_total = $stepCount;
                $run->steps_completed = 0;
                $run->progress_percent = 0;
                $run->created_at = date('Y-m-d H:i:s');
                $run->updated_at = date('Y-m-d H:i:s');
                $runId = Bean::store($run);

                $pipeline->run_count = ($pipeline->run_count ?? 0) + 1;
                $pipeline->last_run_at = date('Y-m-d H:i:s');
                Bean::store($pipeline);

                // Dispatch asynchronously
                $logger = Flight::get('log');
                $logger->info('Scanner pipeline dispatching', [
                    'run_id' => $runId,
                    'pipeline_id' => $scannerPipelineId,
                    'connections_id' => $connectionId,
                    'chatbot' => $chatbot->slug,
                ]);

                \app\services\PipelineDispatcher::dispatch($runId, $workspace);

                $logger->info('Scanner pipeline dispatch complete', [
                    'run_id' => $runId,
                    'status' => Bean::load('pipelineruns', $runId)->status ?? 'unknown',
                ]);

                Flight::jsonSuccess([
                    'run_id' => $runId,
                    'status' => 'pending',
                ], 'Scanner pipeline started');

            } catch (\Exception $e) {
                $logger = Flight::get('log');
                $logger->error('Scanner pipeline failed', [
                    'error' => $e->getMessage(),
                    'chatbot' => $chatbot->slug,
                ]);
                Flight::jsonError('Failed to start scanner: ' . $e->getMessage(), 500);
            }
            return;
        }

        // "status" action checks latest scanner run
        if ($action === 'status') {
            $latestRun = null;
            if ($scannerPipelineId) {
                $run = Bean::findOne('pipelineruns',
                    ' pipelines_id = ? ORDER BY created_at DESC ',
                    [$scannerPipelineId]
                );
                if ($run && $run->id) {
                    $latestRun = [
                        'id' => (int)$run->id,
                        'status' => $run->status,
                        'created_at' => $run->created_at,
                        'progress_percent' => (int)($run->progress_percent ?? 0),
                    ];
                }
            }
            Flight::jsonSuccess(['latest_scan' => $latestRun]);
            return;
        }

        Flight::jsonError('Invalid action (scan, status)', 400);
    }

    /**
     * Delete/archive a chatbot (AJAX POST)
     */
    public function chatbotdelete($params = []) {
        if (!$this->requireLogin()) return;
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::jsonError('Admin access required', 403);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Flight::jsonError('POST required', 405);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $chatbotId = (int)($body['chatbot_id'] ?? 0);

        $chatbot = Bean::load('chatbots', $chatbotId);
        if (!$chatbot || !$chatbot->id) {
            Flight::jsonError('Chatbot not found', 404);
            return;
        }

        $chatbot->status = 'archived';
        Bean::store($chatbot);

        Flight::jsonSuccess([], 'Chatbot archived');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function agentToArray($agent): array {
        $memberId = (int) ($agent->member_id ?? 0);
        $activeChats = Bean::count('customersessions',
            ' agent_member_id = ? AND verification_status = ? AND resolved_at IS NULL ',
            [$memberId, 'escalated']
        );
        return [
            'id' => (int) $agent->id,
            'member_id' => $memberId,
            'display_name' => $agent->display_name ?? '',
            'max_concurrent' => (int) ($agent->max_concurrent ?: 3),
            'status' => $agent->status ?? 'offline',
            'active_chats' => $activeChats,
            'last_active_at' => $agent->last_active_at ?? null,
            'created_at' => $agent->created_at ?? null,
        ];
    }
}
