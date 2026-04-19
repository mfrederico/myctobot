<?php
/**
 * Commerce Studio Controller
 * Shopify-focused workspace for store management
 */

namespace app;

use \Flight as Flight;
use \app\Bean;

class Studiocommerce extends BaseControls\Control {

    /**
     * Commerce Studio main view
     */
    public function index() {
        if (!$this->requireLogin()) return;

        // Set studio preference
        Flight::setStudio('commerce');

        // Get studio configuration
        $studioKey = 'commerce';
        $studio = Studio::getStudioConfig($studioKey);
        $studios = Studio::getAllStudios();

        // Get Shopify connections (these are the stores)
        // Check member_id for ownership (unified table uses member_id)
        $stores = Bean::find('connections',
            ' connector_type = ? AND member_id = ? ORDER BY created_at DESC ',
            ['shopify', $this->member->id]
        );

        // Get commerce-related pipelines (if any have shopify in name or config)
        $commercePipelines = Bean::find('pipelines',
            ' member_id = ? AND (name LIKE ? OR name LIKE ?) ORDER BY updated_at DESC LIMIT 5 ',
            [$this->member->id, '%shopify%', '%store%']
        );

        $this->render('studio/commerce', [
            'title' => 'Commerce Studio',
            'studio' => $studio,
            'studios' => $studios,
            'studioKey' => $studioKey,
            'shopifyConnected' => count($stores) > 0,
            'stores' => $stores,
            'commercePipelines' => $commercePipelines
        ]);
    }
}
