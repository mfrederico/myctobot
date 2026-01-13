<?php
/**
 * Code Review Controller
 * Provides codebase quality analysis UI and API
 */

namespace app;

use \Flight as Flight;
use \Exception as Exception;
use \app\services\CodeReviewService;

require_once __DIR__ . '/../services/CodeReviewService.php';

class CodeReview extends BaseControls\Control {

    /**
     * Code review dashboard
     */
    public function index() {
        if (!$this->requireLogin()) return;

        // Get recent review if cached
        $cacheFile = dirname(__DIR__) . '/cache/code-review-latest.json';
        $cachedReview = null;
        if (file_exists($cacheFile)) {
            $cachedReview = json_decode(file_get_contents($cacheFile), true);
        }

        $this->render('codereview/index', [
            'title' => 'Code Review',
            'cachedReview' => $cachedReview
        ]);
    }

    /**
     * Run a new code review scan
     * POST /codereview/run
     */
    public function run() {
        if (!$this->requireLogin()) return;

        // Check admin level (code review is admin-only)
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            return $this->jsonErrorReturn('Admin access required', 403);
        }

        try {
            $input = $this->getJsonInput();
            $quick = $input['quick'] ?? true;
            $minLines = $input['min_lines'] ?? 5;

            $service = new CodeReviewService();
            $results = $service->runReview([
                'quick' => $quick,
                'min_lines' => $minLines
            ]);

            // Cache results
            $cacheDir = dirname(__DIR__) . '/cache';
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }
            file_put_contents($cacheDir . '/code-review-latest.json', json_encode($results, JSON_PRETTY_PRINT));

            $this->jsonSuccess($results, 'Code review completed');

        } catch (Exception $e) {
            $this->logger->error('Code review failed: ' . $e->getMessage());
            return $this->jsonErrorReturn('Code review failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get patterns configuration
     * GET /codereview/patterns
     */
    public function patterns() {
        if (!$this->requireLogin()) return;

        $patternsFile = dirname(__DIR__) . '/scripts/duplicate-patterns.json';
        if (!file_exists($patternsFile)) {
            return $this->jsonErrorReturn('Patterns file not found', 404);
        }

        $patterns = json_decode(file_get_contents($patternsFile), true);
        $this->jsonSuccess($patterns);
    }

    /**
     * Update patterns configuration
     * POST /codereview/patterns
     */
    public function updatePatterns() {
        if (!$this->requireLogin()) return;

        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            return $this->jsonErrorReturn('Admin access required', 403);
        }

        try {
            $input = $this->getJsonInput();
            $patterns = $input['patterns'] ?? null;

            if (!$patterns) {
                return $this->jsonErrorReturn('No patterns provided', 400);
            }

            $patternsFile = dirname(__DIR__) . '/scripts/duplicate-patterns.json';

            // Load existing config to preserve description
            $existing = [];
            if (file_exists($patternsFile)) {
                $existing = json_decode(file_get_contents($patternsFile), true);
            }

            $config = [
                'description' => $existing['description'] ?? 'Custom patterns for duplicate code detection',
                'patterns' => $patterns
            ];

            file_put_contents($patternsFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->jsonSuccess(['patterns' => $patterns], 'Patterns updated');

        } catch (Exception $e) {
            $this->logger->error('Failed to update patterns: ' . $e->getMessage());
            return $this->jsonErrorReturn('Failed to update patterns', 500);
        }
    }

    /**
     * Generate markdown report
     * POST /codereview/report
     */
    public function report() {
        if (!$this->requireLogin()) return;

        try {
            $input = $this->getJsonInput();
            $results = $input['results'] ?? null;

            // If no results provided, run a new scan
            if (!$results) {
                $service = new CodeReviewService();
                $results = $service->runReview(['quick' => true]);
            }

            $service = new CodeReviewService();
            $markdown = $service->generateMarkdownReport($results);

            $this->jsonSuccess([
                'markdown' => $markdown,
                'summary' => $results['summary'] ?? []
            ]);

        } catch (Exception $e) {
            $this->logger->error('Report generation failed: ' . $e->getMessage());
            return $this->jsonErrorReturn('Report generation failed', 500);
        }
    }
}
