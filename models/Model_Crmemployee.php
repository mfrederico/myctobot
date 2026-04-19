<?php
/**
 * CRM Employee FUSE Model
 *
 * Represents a person discovered at a CRM contact's company.
 * Used for gatekeeper protocol and decision-maker targeting.
 *
 * Belongs to: crmcontact (via crmcontact_id)
 *
 * Role types:
 *   decision_maker - CEO, Founder, Owner, VP, Director (the buyer)
 *   influencer     - Manager, Lead, Specialist (can champion internally)
 *   gatekeeper     - Admin, Office Manager, Receptionist (controls access)
 *   unknown        - Not yet classified
 */

class Model_Crmemployee extends \RedBeanPHP\SimpleModel {

    public function update(): void {
        $bean = $this->bean;
        if (empty($bean->created_at)) {
            $bean->created_at = date('Y-m-d H:i:s');
        }
        $bean->updated_at = date('Y-m-d H:i:s');
    }

    /**
     * Classify role based on title keywords
     */
    public static function classifyRole(string $title): string {
        $title = strtolower($title);

        // Decision makers
        if (preg_match('/(ceo|cfo|coo|cto|cmo|founder|co-founder|owner|president|principal|partner|director|vp|vice president|head of|chief)/i', $title)) {
            return 'decision_maker';
        }

        // Influencers
        if (preg_match('/(manager|lead|supervisor|coordinator|specialist|analyst|buyer|purchasing|procurement|operations|logistics|shipping|fulfillment)/i', $title)) {
            return 'influencer';
        }

        // Gatekeepers
        if (preg_match('/(admin|assistant|receptionist|office manager|secretary|front desk|executive assistant)/i', $title)) {
            return 'gatekeeper';
        }

        return 'unknown';
    }

    /**
     * Role badge class for UI
     */
    public function roleBadgeClass(): string {
        return match($this->bean->role_type ?? 'unknown') {
            'decision_maker' => 'bg-success',
            'influencer' => 'bg-info',
            'gatekeeper' => 'bg-warning text-dark',
            default => 'bg-secondary',
        };
    }

    /**
     * Role icon for UI
     */
    public function roleIcon(): string {
        return match($this->bean->role_type ?? 'unknown') {
            'decision_maker' => 'bi-star-fill',
            'influencer' => 'bi-megaphone',
            'gatekeeper' => 'bi-shield-lock',
            default => 'bi-person',
        };
    }
}
