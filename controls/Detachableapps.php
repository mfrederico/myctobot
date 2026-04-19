<?php
/**
 * Detachableapps Controller - DEPRECATED
 *
 * Redirect stub for Phase 1 UX consolidation.
 * All detachable app functionality has been unified into /apps.
 * These redirects ensure old bookmarks and links continue to work.
 */

namespace app;

use \Flight as Flight;

class Detachableapps extends BaseControls\Control
{
    public function index() { Flight::redirect('/apps'); }
    public function form($params = []) { Flight::redirect('/apps/form/' . ($params[0] ?? '')); }
    public function store() { Flight::redirect('/apps'); }
    public function update($params = []) { Flight::redirect('/apps/form/' . ($params[0] ?? '')); }
    public function delete($params = []) { Flight::redirect('/apps'); }
    public function screens($params = []) { Flight::redirect('/apps/screens/' . ($params[0] ?? '')); }
    public function addscreen($params = []) { Flight::redirect('/apps/addscreen/' . ($params[0] ?? '')); }
    public function removescreen($params = []) { Flight::redirect('/apps/removescreen/' . ($params[0] ?? '')); }
    public function updatescreens($params = []) { Flight::redirect('/apps/updatescreens/' . ($params[0] ?? '')); }
    public function addblockscreen($params = []) { Flight::redirect('/apps/addblockscreen/' . ($params[0] ?? '')); }
    public function pipelines($params = []) { Flight::redirect('/apps/pipelines/' . ($params[0] ?? '')); }
    public function bindpipeline($params = []) { Flight::redirect('/apps/bindpipeline/' . ($params[0] ?? '')); }
    public function unbindpipeline($params = []) { Flight::redirect('/apps/unbindpipeline/' . ($params[0] ?? '')); }
    public function build($params = []) { Flight::redirect('/apps/build/' . ($params[0] ?? '')); }
    public function release($params = []) { Flight::redirect('/apps/release/' . ($params[0] ?? '')); }
    public function releases($params = []) { Flight::redirect('/apps/releases/' . ($params[0] ?? '')); }
    public function download($params = []) { Flight::redirect('/apps/download/' . ($params[0] ?? '')); }
    public function buildshopify($params = []) { Flight::redirect('/apps/buildshopify/' . ($params[0] ?? '')); }
    public function dobuildshopify($params = []) { Flight::redirect('/apps/dobuildshopify/' . ($params[0] ?? '')); }
    public function downloadshopify($params = []) { Flight::redirect('/apps/downloadshopify/' . ($params[0] ?? '')); }
    public function deployshopify($params = []) { Flight::redirect('/apps/deployshopify/' . ($params[0] ?? '')); }
    public function deploymentstatus($params = []) { Flight::redirect('/apps/deploymentstatus/' . ($params[0] ?? '')); }
    public function getshopifyproducts($params = []) { Flight::redirect('/apps/getshopifyproducts/' . ($params[0] ?? '')); }
    public function liquidfiles($params = []) { Flight::redirect('/apps/liquidfiles/' . ($params[0] ?? '')); }
    public function saveliquidfile($params = []) { Flight::redirect('/apps/saveliquidfile'); }
    public function deleteliquidfile($params = []) { Flight::redirect('/apps/deleteliquidfile'); }
    public function getliquidfile($params = []) { Flight::redirect('/apps/getliquidfile/' . ($params[0] ?? '')); }
    public function templates() { Flight::redirect('/apps/templates'); }
    public function templateform($params = []) { Flight::redirect('/apps/templateform/' . ($params[0] ?? '')); }
    public function createfromtemplate($params = []) { Flight::redirect('/apps/createfromtemplate/' . ($params[0] ?? '')); }
    public function createproject($params = []) { Flight::redirect('/apps/createproject/' . ($params[0] ?? '')); }
    public function stitchprojects($params = []) { Flight::redirect('/apps/stitchprojects/' . ($params[0] ?? '')); }
}
