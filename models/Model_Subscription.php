<?php
/**
 * Subscription Model
 * FUSE model for subscription table
 *
 * All data is now in a single MySQL database per tenant.
 */

use \RedBeanPHP\R as R;

class Model_Subscription extends \RedBeanPHP\SimpleModel {

    /**
     * Called before storing the bean
     */
    public function update() {
        // Auto-set updated_at timestamp
        $this->bean->updated_at = date('Y-m-d H:i:s');
    }

    /**
     * Check if subscription is currently active and not expired
     *
     * @return bool
     */
    public function isActive(): bool {
        if ($this->bean->status !== 'active') {
            return false;
        }

        if ($this->bean->current_period_end) {
            $endDate = new \DateTime($this->bean->current_period_end);
            if ($endDate < new \DateTime()) {
                return false; // Expired
            }
        }

        return true;
    }

    /**
     * Check if subscription is on trial
     *
     * @return bool
     */
    public function isOnTrial(): bool {
        if (!$this->bean->trial_ends_at) {
            return false;
        }

        $trialEnd = new \DateTime($this->bean->trial_ends_at);
        return $trialEnd > new \DateTime();
    }

    /**
     * Get days remaining in current period
     *
     * @return int|null Days remaining, or null if no end date
     */
    public function getDaysRemaining(): ?int {
        if (!$this->bean->current_period_end) {
            return null;
        }

        $endDate = new \DateTime($this->bean->current_period_end);
        $now = new \DateTime();

        if ($endDate < $now) {
            return 0;
        }

        return $now->diff($endDate)->days;
    }
}
