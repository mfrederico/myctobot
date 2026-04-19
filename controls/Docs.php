<?php
/**
 * Documentation Controller
 * Displays the README and other documentation
 */

namespace app;

use \Flight as Flight;

class Docs extends BaseControls\Control {

    /**
     * Display main documentation (README)
     */
    public function index() {
        $readmePath = BASE_PATH . '/README.md';
        $content = '';

        if (file_exists($readmePath)) {
            $markdown = file_get_contents($readmePath);

            // Use MarkdownParser utility class
            $content = MarkdownParser::parse($markdown);
        } else {
            $content = '<div class="alert alert-warning">Documentation file not found.</div>';
        }

        $this->render('docs/index', [
            'title' => 'Documentation',
            'content' => $content
        ]);
    }
    
    
    /**
     * Display API documentation
     */
    public function api() {
        $this->render('docs/api', [
            'title' => 'API Documentation'
        ]);
    }
    
    /**
     * Display CLI documentation
     */
    public function cli() {
        // Render the CLI help view to capture its output
        ob_start();
        Flight::render('docs/cli_help');
        $content = ob_get_clean();

        $this->render('docs/cli', [
            'title' => 'CLI Documentation',
            'content' => $content
        ]);
    }

    /**
     * Display Pipelines documentation
     */
    public function pipelines() {
        $pipelinesPath = BASE_PATH . '/docs/PIPELINES.md';
        $content = '';

        if (file_exists($pipelinesPath)) {
            $markdown = file_get_contents($pipelinesPath);
            $content = MarkdownParser::parse($markdown);
        } else {
            $content = '<div class="alert alert-warning">Pipeline documentation file not found.</div>';
        }

        $this->render('docs/pipelines', [
            'title' => 'Pipeline System Documentation',
            'content' => $content
        ]);
    }

    /**
     * Display Caching documentation
     */
    public function caching() {
        $cachingPath = BASE_PATH . '/docs/CACHING.md';
        $content = '';

        if (file_exists($cachingPath)) {
            $markdown = file_get_contents($cachingPath);
            $content = MarkdownParser::parse($markdown);
        } else {
            // Fallback content if file doesn't exist
            $content = '<div class="alert alert-info">
                <h4>TikNix Caching System</h4>
                <p>The TikNix framework includes a sophisticated multi-tier caching system that provides:</p>
                <ul>
                    <li><strong>9.4x faster database queries</strong> with transparent query caching</li>
                    <li><strong>175,000 permission checks/second</strong> with 3-tier permission caching</li>
                    <li><strong>Zero configuration</strong> - works out of the box</li>
                    <li><strong>Multi-workspace safe</strong> - isolated cache namespaces</li>
                </ul>
                <p>For detailed documentation, please ensure <code>docs/CACHING.md</code> exists.</p>
                </div>';
        }

        $this->render('docs/caching', [
            'title' => 'Caching System Documentation',
            'content' => $content
        ]);
    }

    /**
     * Display Shopify Plugin Builder tutorial
     */
    public function shopify() {
        $shopifyPath = BASE_PATH . '/docs/SHOPIFY_UI_TUTORIAL.md';
        $content = '';

        if (file_exists($shopifyPath)) {
            $markdown = file_get_contents($shopifyPath);
            $content = MarkdownParser::parse($markdown);
        } else {
            $content = '<div class="alert alert-warning">Shopify tutorial file not found.</div>';
        }

        $this->render('docs/index', [
            'title' => 'Shopify Plugin Builder Tutorial',
            'content' => $content
        ]);
    }
}