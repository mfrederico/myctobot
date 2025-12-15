<?php
/**
 * Member Model
 * Extends RedBeanPHP's member bean with custom methods
 */

use \RedBeanPHP\R as R;
use \app\services\SubscriptionService;

class Model_Member extends \RedBeanPHP\SimpleModel {

    /**
     * Check if member has Pro tier or higher
     *
     * @return bool
     */
    public function isPro(): bool {
        if (!$this->bean->id) {
            return false;
        }
        return SubscriptionService::isPro($this->bean->id);
    }

    /**
     * Check if member has Enterprise tier
     *
     * @return bool
     */
    public function isEnterprise(): bool {
        if (!$this->bean->id) {
            return false;
        }
        return SubscriptionService::isEnterprise($this->bean->id);
    }

    /**
     * Get member's subscription tier
     *
     * @return string
     */
    public function getTier(): string {
        if (!$this->bean->id) {
            return 'free';
        }
        return SubscriptionService::getTier($this->bean->id);
    }

    /**
     * Get member's subscription details
     *
     * @return array|null
     */
    public function getSubscription(): ?array {
        if (!$this->bean->id) {
            return null;
        }
        return SubscriptionService::getSubscription($this->bean->id);
    }
}
