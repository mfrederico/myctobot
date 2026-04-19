<?php
/**
 * Magic Link FUSE Model
 * Daily HMAC login tokens for sales reps.
 */

class Model_Magiclink extends \RedBeanPHP\SimpleModel {

    /**
     * Check if this link is still valid
     */
    public function isValid(): bool {
        $bean = $this->bean;
        if (!empty($bean->usedAt)) return false;
        if (strtotime($bean->expiresAt) < time()) return false;
        return true;
    }

    public function update(): void {
        if (empty($this->bean->createdAt)) {
            $this->bean->createdAt = date('Y-m-d H:i:s');
        }
    }
}
