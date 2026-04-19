<?php
/**
 * Csagents Model
 * FUSE model for csagents table
 *
 * Manages customer service agent records for live chat assignment.
 */

use \RedBeanPHP\R as R;

class Model_Csagents extends \RedBeanPHP\SimpleModel {

    private const VALID_STATUSES = ['online', 'away', 'offline'];
    private const DEFAULT_MAX_CONCURRENT = 3;

    /**
     * Called after dispense (when creating a new bean)
     */
    public function dispense() {
        $this->bean->max_concurrent = self::DEFAULT_MAX_CONCURRENT;
        $this->bean->status = 'offline';
        $this->bean->created_at = date('Y-m-d H:i:s');
    }

    /**
     * Called before storing the bean
     */
    public function update() {
        $this->bean->updated_at = date('Y-m-d H:i:s');

        if ($this->bean->status && !in_array($this->bean->status, self::VALID_STATUSES)) {
            $this->bean->status = 'offline';
        }

        if (!$this->bean->max_concurrent || (int)$this->bean->max_concurrent < 0) {
            $this->bean->max_concurrent = self::DEFAULT_MAX_CONCURRENT;
        }
    }

    /**
     * Check if agent is available for new chats
     */
    public function isAvailable(): bool {
        return $this->bean->status === 'online';
    }

    /**
     * Get the number of active (escalated, unresolved) chats for this agent
     */
    public function getActiveChatCount(): int {
        return \app\Bean::count('customersessions',
            ' agent_member_id = ? AND verification_status = ? AND resolved_at IS NULL ',
            [(int)$this->bean->member_id, 'escalated']
        );
    }

    /**
     * Check if agent has capacity for more chats
     */
    public function hasCapacity(): bool {
        return $this->isAvailable() && $this->getActiveChatCount() < (int)$this->bean->max_concurrent;
    }

    /**
     * Touch last_active_at timestamp
     */
    public function touchActive(): void {
        $this->bean->last_active_at = date('Y-m-d H:i:s');
    }

    /**
     * Export as array for API responses
     */
    public function toArray(): array {
        return [
            'id' => (int)$this->bean->id,
            'member_id' => (int)$this->bean->member_id,
            'display_name' => $this->bean->display_name,
            'max_concurrent' => (int)$this->bean->max_concurrent,
            'status' => $this->bean->status,
            'active_chats' => $this->getActiveChatCount(),
            'last_active_at' => $this->bean->last_active_at,
            'created_at' => $this->bean->created_at,
        ];
    }
}
