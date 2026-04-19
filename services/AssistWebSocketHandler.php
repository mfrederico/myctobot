<?php
/**
 * Assist WebSocket Handler
 *
 * Handles WebSocket messages for the AI Page Assistant.
 * Used with OpenSwoole WebSocket server.
 *
 * Message Types (Client -> Server):
 *   - chat: Send a message with page context
 *
 * Message Types (Server -> Client):
 *   - thinking: Processing indicator
 *   - chunk: Streaming text chunk
 *   - action: UI action to execute
 *   - done: Response complete
 *   - error: Error occurred
 */

namespace app\services;

use OpenSwoole\WebSocket\Server;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\Http\Request;

class AssistWebSocketHandler
{
    private Server $server;
    private array $assistServices = []; // Per-workspace services
    private array $connections = [];
    private array $conversations = [];
    private array $baseConfig;
    private $logger;

    /**
     * @param Server $server OpenSwoole WebSocket server
     * @param array $config Configuration options
     */
    public function __construct(Server $server, array $config = [])
    {
        $this->server = $server;
        $this->baseConfig = $config;
        $this->logger = $config['logger'] ?? null;
    }

    /**
     * Get or create assistant service for a workspace
     *
     * @param string|null $workspace Workspace slug
     * @return PageAssistantService
     */
    private function getAssistService(?string $workspace): PageAssistantService
    {
        $key = $workspace ?? 'default';
        if (!isset($this->assistServices[$key])) {
            $config = array_merge($this->baseConfig, ['workspace' => $workspace]);
            $this->assistServices[$key] = new PageAssistantService($config);
        }
        return $this->assistServices[$key];
    }

    /**
     * Handle new WebSocket connection
     *
     * @param Request $request HTTP request that initiated the connection
     * @param int $fd Connection file descriptor
     */
    public function onOpen(Request $request, int $fd): void
    {
        $this->log("New connection: fd={$fd}");

        // Extract auth token and workspace from query string or headers
        $token = $request->get['token'] ?? null;
        $sessionId = $request->get['session'] ?? null;
        $workspace = $request->get['workspace'] ?? null;

        // Extract workspace from subdomain if not in query
        if (!$workspace && isset($request->header['host'])) {
            $host = $request->header['host'];
            $parts = explode('.', $host);
            if (count($parts) >= 3) {
                $workspace = $parts[0];
            }
        }

        // For now, accept all connections (add auth later if needed)
        $this->connections[$fd] = [
            'fd' => $fd,
            'token' => $token,
            'session_id' => $sessionId,
            'workspace' => $workspace,
            'connected_at' => time(),
            'last_activity' => time(),
        ];

        // Send connected confirmation
        $this->send($fd, [
            'type' => 'connected',
            'message' => 'Connected to Page Assistant'
        ]);
    }

    /**
     * Handle WebSocket message
     *
     * @param Frame $frame WebSocket frame
     */
    public function onMessage(Frame $frame): void
    {
        $fd = $frame->fd;
        $data = json_decode($frame->data, true);

        if (!$data) {
            $this->sendError($fd, 'Invalid JSON message');
            return;
        }

        $this->log("Message from fd={$fd}: " . ($data['type'] ?? 'unknown'));

        // Update activity timestamp
        if (isset($this->connections[$fd])) {
            $this->connections[$fd]['last_activity'] = time();
        }

        $type = $data['type'] ?? '';

        switch ($type) {
            case 'chat':
                $this->handleChat($fd, $data);
                break;

            case 'ping':
                $this->send($fd, ['type' => 'pong']);
                break;

            case 'clear':
                $this->handleClear($fd, $data);
                break;

            default:
                $this->sendError($fd, "Unknown message type: {$type}");
        }
    }

    /**
     * Handle connection close
     *
     * @param int $fd Connection file descriptor
     */
    public function onClose(int $fd): void
    {
        $this->log("Connection closed: fd={$fd}");

        // Clean up connection data
        unset($this->connections[$fd]);

        // Optionally clean up old conversations
        $this->cleanupOldConversations();
    }

    /**
     * Handle chat message
     *
     * @param int $fd Connection file descriptor
     * @param array $data Message data
     */
    private function handleChat(int $fd, array $data): void
    {
        $message = $data['message'] ?? '';
        $context = $data['context'] ?? [];
        $conversationId = $data['conversation_id'] ?? $this->generateConversationId();

        // Debug: log received context
        $this->log("Chat context: page=" . ($context['page'] ?? 'none') . ", url=" . ($context['url'] ?? 'none') . ", forms=" . count($context['forms'] ?? []));

        if (empty($message)) {
            $this->sendError($fd, 'Message is required');
            return;
        }

        // Get workspace from connection
        $workspace = $this->connections[$fd]['workspace'] ?? null;

        // Get workspace-specific assistant service
        $assistService = $this->getAssistService($workspace);

        // Get or create conversation history
        $history = $this->conversations[$conversationId] ?? [];

        // Send thinking indicator
        $this->send($fd, [
            'type' => 'thinking',
            'content' => 'Analyzing your request...'
        ]);

        // Track full response for learning
        $fullResponse = '';
        $allActions = [];

        try {
            // Stream the response
            $result = $assistService->chatStream(
                $message,
                $context,
                $history,
                function ($chunk, $action) use ($fd, &$fullResponse, &$allActions) {
                    if ($chunk !== '') {
                        $fullResponse .= $chunk;
                        $this->send($fd, [
                            'type' => 'chunk',
                            'content' => $chunk
                        ]);
                    }

                    if ($action) {
                        $allActions[] = $action;
                        unset($action['_sent']); // Remove internal flag
                        // Send full action payload (includes builder fields like block_type, block_id, config)
                        $actionPayload = array_merge(['type' => 'action'], $action);
                        $this->send($fd, $actionPayload);
                    }
                },
                function ($thinking) use ($fd) {
                    $this->send($fd, [
                        'type' => 'thinking',
                        'content' => $thinking
                    ]);
                }
            );

            // If no content was streamed, inform the user
            if (empty($fullResponse)) {
                if (!empty($result) && ($result['success'] ?? true) === false) {
                    // Explicit failure from chatStream
                    $errorMsg = $result['error'] ?? 'The AI model is currently unavailable.';
                } else {
                    // LLM returned empty response (auth error, model issue, etc.)
                    $errorMsg = 'The AI model returned an empty response. It may be unavailable or misconfigured.';
                }
                $fullResponse = "**Error:** {$errorMsg}";
                $this->send($fd, [
                    'type' => 'chunk',
                    'content' => $fullResponse
                ]);
            }

            // Store in conversation history
            $this->conversations[$conversationId][] = [
                'role' => 'user',
                'content' => $message,
                'timestamp' => time()
            ];

            // Store assistant response in history
            if ($fullResponse) {
                $this->conversations[$conversationId][] = [
                    'role' => 'assistant',
                    'content' => $fullResponse,
                    'timestamp' => time()
                ];

                // Learn from this interaction (async/non-blocking in production)
                $page = $context['page'] ?? 'unknown';
                $assistService->learnFromInteraction($page, $message, $fullResponse, $allActions);
            }

            // Send done
            $this->send($fd, [
                'type' => 'done',
                'conversation_id' => $conversationId
            ]);

        } catch (\Exception $e) {
            $this->log("Error in chat: " . $e->getMessage());
            $this->sendError($fd, 'Failed to process message: ' . $e->getMessage());
        }
    }

    /**
     * Handle clear conversation request
     *
     * @param int $fd Connection file descriptor
     * @param array $data Message data
     */
    private function handleClear(int $fd, array $data): void
    {
        $conversationId = $data['conversation_id'] ?? null;

        if ($conversationId && isset($this->conversations[$conversationId])) {
            unset($this->conversations[$conversationId]);
        }

        $this->send($fd, [
            'type' => 'cleared',
            'conversation_id' => $conversationId
        ]);
    }

    /**
     * Send a message to a connection
     *
     * @param int $fd Connection file descriptor
     * @param array $data Data to send
     */
    private function send(int $fd, array $data): void
    {
        if (!$this->server->isEstablished($fd)) {
            return;
        }

        $this->server->push($fd, json_encode($data));
    }

    /**
     * Send an error message
     *
     * @param int $fd Connection file descriptor
     * @param string $message Error message
     */
    private function sendError(int $fd, string $message): void
    {
        $this->send($fd, [
            'type' => 'error',
            'message' => $message
        ]);
    }

    /**
     * Generate a unique conversation ID
     *
     * @return string Conversation ID
     */
    private function generateConversationId(): string
    {
        return 'conv_' . bin2hex(random_bytes(8));
    }

    /**
     * Clean up old conversations (older than 30 minutes)
     */
    private function cleanupOldConversations(): void
    {
        $cutoff = time() - (30 * 60); // 30 minutes

        foreach ($this->conversations as $id => $messages) {
            if (empty($messages)) {
                unset($this->conversations[$id]);
                continue;
            }

            // Check last message timestamp
            $lastMessage = end($messages);
            if (($lastMessage['timestamp'] ?? 0) < $cutoff) {
                unset($this->conversations[$id]);
            }
        }
    }

    /**
     * Log a message
     *
     * @param string $message Log message
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logLine = "[{$timestamp}] [AssistWS] {$message}";

        if ($this->logger) {
            $this->logger->info($message);
        } else {
            echo $logLine . PHP_EOL;
        }
    }

    /**
     * Get connection count
     *
     * @return int Number of active connections
     */
    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * Get connection info
     *
     * @param int $fd Connection file descriptor
     * @return array|null Connection info or null
     */
    public function getConnection(int $fd): ?array
    {
        return $this->connections[$fd] ?? null;
    }

    /**
     * Broadcast message to all connections
     *
     * @param array $data Data to send
     */
    public function broadcast(array $data): void
    {
        foreach ($this->connections as $fd => $info) {
            $this->send($fd, $data);
        }
    }
}
