<?php
/**
 * Customersessions Model
 * FUSE model for customersessions table
 *
 * Manages customer support chat sessions for Shopify store visitors.
 * Each session is created after customer verifies their order + email.
 */

use \RedBeanPHP\R as R;

class Model_Customersessions extends \RedBeanPHP\SimpleModel {

    /**
     * Valid verification statuses
     */
    private const VALID_STATUSES = ['pending', 'verified', 'expired', 'escalated', 'resolved'];

    /**
     * Default session duration in seconds (1 hour)
     */
    private const DEFAULT_EXPIRY_SECONDS = 3600;

    /**
     * Called after dispense (when creating a new bean)
     */
    public function dispense() {
        $this->bean->session_token = $this->generateToken();
        $this->bean->verification_status = 'pending';
        $this->bean->created_at = date('Y-m-d H:i:s');
        $this->bean->expires_at = date('Y-m-d H:i:s', time() + self::DEFAULT_EXPIRY_SECONDS);
        $this->bean->context_json = '{}';
    }

    /**
     * Called before storing the bean
     */
    public function update() {
        $this->bean->updated_at = date('Y-m-d H:i:s');

        // Validate verification_status
        if ($this->bean->verification_status && !in_array($this->bean->verification_status, self::VALID_STATUSES)) {
            $this->bean->verification_status = 'pending';
        }

        // Validate context_json is valid JSON if provided
        if ($this->bean->context_json) {
            $decoded = json_decode($this->bean->context_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->bean->context_json = '{}';
            }
        }
    }

    /**
     * Generate a secure session token
     */
    private function generateToken(): string {
        return 'cs_' . bin2hex(random_bytes(24));
    }

    /**
     * Check if session is verified
     */
    public function isVerified(): bool {
        return $this->bean->verification_status === 'verified';
    }

    /**
     * Check if session has expired
     */
    public function isExpired(): bool {
        if ($this->bean->verification_status === 'expired') {
            return true;
        }
        if ($this->bean->expires_at) {
            return strtotime($this->bean->expires_at) < time();
        }
        return false;
    }

    /**
     * Mark session as verified with order data
     */
    public function markVerified(array $orderData): void {
        $this->bean->verification_status = 'verified';
        $this->bean->verified_at = date('Y-m-d H:i:s');

        // Extend expiry on verification
        $this->bean->expires_at = date('Y-m-d H:i:s', time() + self::DEFAULT_EXPIRY_SECONDS);

        // Store order context
        $context = $this->getContext();
        $context['verified_order'] = $orderData;
        $this->setContext($context);
    }

    /**
     * Mark session as escalated to human support
     */
    public function markEscalated(string $reason = ''): void {
        $this->bean->verification_status = 'escalated';
        $this->bean->escalated_at = date('Y-m-d H:i:s');

        $context = $this->getContext();
        $context['escalation_reason'] = $reason;
        $this->setContext($context);
    }

    /**
     * Mark session as expired
     */
    public function markExpired(): void {
        $this->bean->verification_status = 'expired';
    }

    /**
     * Assign a CS agent to this escalated session
     */
    public function assignAgent(int $memberId, string $displayName): void {
        $this->bean->agent_member_id = $memberId;
        $this->bean->queue_entered_at = null;
        $this->addMessage('system', "{$displayName} has joined the chat.");
    }

    /**
     * Mark session as resolved by the agent
     */
    public function markResolved(string $summary = ''): void {
        $this->bean->verification_status = 'resolved';
        $this->bean->resolved_at = date('Y-m-d H:i:s');
        $msg = 'This chat has been resolved.';
        if ($summary) {
            $msg .= " Summary: {$summary}";
        }
        $this->addMessage('system', $msg);
    }

    /**
     * Extend session expiry
     */
    public function extendExpiry(int $seconds = null): void {
        $seconds = $seconds ?? self::DEFAULT_EXPIRY_SECONDS;
        $this->bean->expires_at = date('Y-m-d H:i:s', time() + $seconds);
    }

    /**
     * Get context as array
     */
    public function getContext(): array {
        return json_decode($this->bean->context_json ?: '{}', true) ?: [];
    }

    /**
     * Set context from array
     */
    public function setContext(array $context): void {
        $this->bean->context_json = json_encode($context);
    }

    /**
     * Add a message to the chat history
     *
     * @param string $role     Message sender: user, agent, assistant, system
     * @param string $content  Text content of the message
     * @param array|null $richData  Optional rich data (e.g. ['type'=>'intent_form','data'=>...])
     */
    public function addMessage(string $role, string $content, ?array $richData = null): void {
        $context = $this->getContext();
        if (!isset($context['messages'])) {
            $context['messages'] = [];
        }
        $msg = [
            'role' => $role,
            'content' => $content,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        if ($richData !== null) {
            $msg['rich'] = $richData;
        }
        $context['messages'][] = $msg;
        $this->setContext($context);
    }

    /**
     * Get chat message history
     */
    public function getMessages(): array {
        $context = $this->getContext();
        return $context['messages'] ?? [];
    }

    /**
     * Get the verified order data
     */
    public function getVerifiedOrder(): ?array {
        $context = $this->getContext();
        return $context['verified_order'] ?? null;
    }

    /**
     * Export as array for API responses
     */
    public function toArray(): array {
        return [
            'id' => $this->bean->id,
            'session_token' => $this->bean->session_token,
            'email' => $this->bean->email,
            'order_id' => $this->bean->order_id,
            'order_name' => $this->bean->order_name,
            'verification_status' => $this->bean->verification_status,
            'aiagents_id' => $this->bean->aiagents_id,
            'connections_id' => $this->bean->connections_id,
            'pipelineruns_id' => $this->bean->pipelineruns_id,
            'agent_member_id' => $this->bean->agent_member_id,
            'is_verified' => $this->isVerified(),
            'is_expired' => $this->isExpired(),
            'context' => $this->getContext(),
            'created_at' => $this->bean->created_at,
            'verified_at' => $this->bean->verified_at,
            'escalated_at' => $this->bean->escalated_at,
            'resolved_at' => $this->bean->resolved_at,
            'expires_at' => $this->bean->expires_at,
        ];
    }

    /**
     * Find a session by token (static helper)
     */
    public static function findByToken(string $token): ?\RedBeanPHP\OODBBean {
        return \app\Bean::findOne('customersessions', 'session_token = ?', [$token]);
    }
}
