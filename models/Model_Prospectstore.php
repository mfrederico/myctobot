<?php
/**
 * FUSE Model for prospectstore bean.
 * Stores discovered ecommerce stores for self-shipping analysis.
 */

class Model_Prospectstore extends \RedBeanPHP\SimpleModel
{
    /**
     * Auto-set timestamps before storing.
     */
    public function update(): void
    {
        $bean = $this->bean;
        if (empty($bean->createdAt)) {
            $bean->createdAt = date('Y-m-d H:i:s');
        }
        $bean->updatedAt = date('Y-m-d H:i:s');
    }

    /**
     * Get selling points as array.
     */
    public function sellingPointsArray(): array
    {
        $json = $this->bean->sellingPoints;
        if (empty($json)) return [];
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get AI analysis as array.
     */
    public function analysisArray(): array
    {
        $json = $this->bean->aiAnalysis;
        if (empty($json)) return [];
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get status badge class for display.
     */
    public function statusBadgeClass(): string
    {
        return match($this->bean->status ?? '') {
            'discovered' => 'bg-info',
            'fetched' => 'bg-secondary',
            'analyzed' => 'bg-warning text-dark',
            'qualified' => 'bg-success',
            'pushed' => 'bg-primary',
            'disqualified' => 'bg-dark',
            'error' => 'bg-danger',
            default => 'bg-light text-dark',
        };
    }
}
