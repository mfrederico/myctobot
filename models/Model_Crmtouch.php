<?php
/**
 * CRM Touch FUSE Model
 * Unified touch base / call log / meeting / email tracking.
 * Enables FUSE discovery for crmtouch beans.
 */

class Model_Crmtouch extends \RedBeanPHP\SimpleModel {

    /**
     * Get touch type label
     */
    public function typeLabel(): string {
        return match($this->bean->touchType ?? 'manual') {
            'call' => 'Phone Call',
            'email' => 'Email',
            'meeting' => 'Meeting',
            'text' => 'Text/SMS',
            'linkedin' => 'LinkedIn',
            'manual' => 'Manual Log',
            default => ucfirst($this->bean->touchType ?? 'Unknown')
        };
    }

    /**
     * Get touch type icon class (FontAwesome)
     */
    public function typeIcon(): string {
        return match($this->bean->touchType ?? 'manual') {
            'call' => 'fa-phone',
            'email' => 'fa-envelope',
            'meeting' => 'fa-handshake',
            'text' => 'fa-comment-sms',
            'linkedin' => 'fa-brands fa-linkedin',
            'manual' => 'fa-pen',
            default => 'fa-circle'
        };
    }

    public function update(): void {
        if (empty($this->bean->createdAt)) {
            $this->bean->createdAt = date('Y-m-d H:i:s');
        }
    }
}
