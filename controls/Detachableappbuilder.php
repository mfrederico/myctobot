<?php
/**
 * Detachableappbuilder Controller - DEPRECATED
 *
 * Redirect stub for Phase 1 UX consolidation.
 * All detachable app builder functionality has been unified into /appbuilder.
 * These redirects ensure old bookmarks and links continue to work.
 */

namespace app;

use \Flight as Flight;

class Detachableappbuilder extends BaseControls\Control
{
    public function index($params = []) {
        $appId = $params[0] ?? '';
        if ($appId) {
            Flight::redirect('/appbuilder/index/' . $appId . $this->queryString());
        } else {
            Flight::redirect('/apps');
        }
    }
    public function screens($params = []) { Flight::redirect('/appbuilder/screens/' . ($params[0] ?? '')); }
    public function screen($params = []) { Flight::redirect('/appbuilder/screen/' . ($params[0] ?? '')); }
    public function deletescreen($params = []) { Flight::redirect('/appbuilder/deletescreen/' . ($params[0] ?? '')); }
    public function reorder($params = []) { Flight::redirect('/appbuilder/reorder/' . ($params[0] ?? '')); }
    public function preview($params = []) { Flight::redirect('/appbuilder/preview/' . ($params[0] ?? '')); }
    public function pipeline($params = []) { Flight::redirect('/appbuilder/pipeline/' . ($params[0] ?? '')); }
    public function blocktypes() { Flight::redirect('/appbuilder/blocktypes'); }

    /**
     * Preserve query string during redirect (e.g., ?screen_id=123)
     */
    private function queryString(): string
    {
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        return $qs ? '?' . $qs : '';
    }
}
