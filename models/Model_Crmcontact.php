<?php
/**
 * CRM Contact FUSE Model
 *
 * Enables RedBeanPHP associations for the crmcontact bean:
 * - ownCrmnoteList: Notes attached to this contact
 * - ownCrmtouchList: Touch base / call log entries
 *
 * Use xown prefix for cascade delete.
 */

class Model_Crmcontact extends \RedBeanPHP\SimpleModel {

    /**
     * Get full display name
     */
    public function fullName(): string {
        $bean = $this->bean;
        if (!empty($bean->firstName) || !empty($bean->lastName)) {
            return trim(($bean->firstName ?? '') . ' ' . ($bean->lastName ?? ''));
        }
        if (!empty($bean->companyName)) {
            return $bean->companyName;
        }
        return $bean->companyEmail ?? 'Unknown';
    }

    /**
     * Parse CSV tags into array
     */
    public function tagArray(): array {
        $tags = trim($this->bean->tags ?? '');
        if (empty($tags)) return [];
        return array_map('trim', explode(',', $tags));
    }

    /**
     * Get pipeline stage label
     */
    public function stageLabel(): string {
        return match($this->bean->pipelineStage ?? '') {
            'cold' => 'Cold',
            'warm' => 'Warm',
            'hot' => 'Hot',
            'closing' => 'Closing Crucible',
            default => 'None'
        };
    }

    /**
     * Get stage badge CSS class
     */
    public function stageBadgeClass(): string {
        return match($this->bean->pipelineStage ?? '') {
            'cold' => 'bg-secondary',
            'warm' => 'bg-warning text-dark',
            'hot' => 'bg-danger',
            'closing' => 'bg-primary',
            default => 'bg-light text-dark'
        };
    }

    /**
     * Before storing - auto-set timestamps
     */
    public function update(): void {
        $bean = $this->bean;
        if (empty($bean->createdAt)) {
            $bean->createdAt = date('Y-m-d H:i:s');
        }
        $bean->updatedAt = date('Y-m-d H:i:s');
    }
}
