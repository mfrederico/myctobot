<?php
/**
 * Shared helper functions for views
 */

/**
 * Get Bootstrap badge class for a plugin category
 *
 * @param string $category The category name
 * @return string Bootstrap badge class (without 'bg-' prefix for flexibility)
 */
function getCategoryBadgeClass($category) {
    $classes = [
        'general' => 'secondary',
        'automation' => 'primary',
        'integration' => 'info',
        'analytics' => 'success',
        'security' => 'danger',
        'development' => 'dark',
        'productivity' => 'warning',
        'communication' => 'purple'
    ];
    return $classes[$category] ?? 'secondary';
}
