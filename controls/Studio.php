<?php
/**
 * Studio Controller
 * Persona-based dashboard system for focused user workflows
 *
 * Users select their primary use case (Developer, Commerce, or Pipeline)
 * and see a focused interface tailored to that workflow.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \Exception as Exception;

class Studio extends BaseControls\Control {

    /**
     * Studio definitions with their features and icons
     */
    private const STUDIOS = [
        'developer' => [
            'name' => 'Developer Studio',
            'shortName' => 'Developer',
            'tagline' => 'Build and deploy with AI-powered development tools',
            'description' => 'Code & repos',
            'icon' => 'code-slash',
            'color' => 'primary',
            'features' => [
                'AI Dev Jobs - Automated code generation and fixes',
                'Git Repositories - Connect and manage your repos',
                'Jira Boards - Track issues and sprints',
                'Agents & Workstations - Configure AI assistants'
            ]
        ],
        'commerce' => [
            'name' => 'Commerce Studio',
            'shortName' => 'Commerce',
            'tagline' => 'Manage your Shopify stores with AI assistance',
            'description' => 'Shopify & themes',
            'icon' => 'shop',
            'color' => 'success',
            'features' => [
                'Shopify Stores - Connect and manage multiple stores',
                'Theme Preview - Test changes before going live',
                'Visual QA - AI-powered screenshot comparison',
                'Product Management - Bulk edits and automation'
            ]
        ],
        'pipeline' => [
            'name' => 'Pipeline Studio',
            'shortName' => 'Pipeline',
            'tagline' => 'Orchestrate complex workflows and automations',
            'description' => 'Automations',
            'icon' => 'diagram-3',
            'color' => 'warning',
            'features' => [
                'Pipeline Builder - Visual workflow designer',
                'Run History - Monitor and debug executions',
                'Schedules - Automate recurring tasks',
                'MCP Servers - Connect external tools and services'
            ]
        ]
    ];

    /**
     * Get all studio configurations (static helper for views)
     */
    public static function getAllStudios(): array {
        return self::STUDIOS;
    }

    /**
     * Get a specific studio configuration
     */
    public static function getStudioConfig(string $type): ?array {
        return self::STUDIOS[$type] ?? null;
    }

    /**
     * Get current studio for a member
     */
    public static function getCurrentStudio(int $memberId): ?array {
        $studioType = Flight::getStudio();
        if (empty($studioType) || !isset(self::STUDIOS[$studioType])) {
            return null;
        }
        return array_merge(['type' => $studioType], self::STUDIOS[$studioType]);
    }

    /**
     * Main index - redirect to preferred studio or show selector
     */
    public function index() {
        if (!$this->requireLogin()) return;

        $activeStudio = Flight::getStudio();

        if ($activeStudio && isset(self::STUDIOS[$activeStudio])) {
            // Redirect to the user's preferred studio
            Flight::redirect("/studio/{$activeStudio}");
            return;
        }

        // No preference set - show selector
        $this->showSelector();
    }

    /**
     * Force show the studio selector (for switching studios)
     */
    public function select() {
        if (!$this->requireLogin()) return;
        $this->showSelector();
    }

    /**
     * Show the studio selector page
     */
    private function showSelector() {
        $this->viewData['title'] = 'Choose Your Studio';
        $this->viewData['studios'] = self::STUDIOS;
        $this->render('studio/selector', $this->viewData);
    }

    /**
     * Developer Studio dashboard - delegates to Studiodev controller
     */
    public function developer() {
        $controller = new Studiodev();
        $controller->index();
    }

    /**
     * Commerce Studio dashboard - delegates to Studiocommerce controller
     */
    public function commerce() {
        $controller = new Studiocommerce();
        $controller->index();
    }

    /**
     * Pipeline Studio dashboard - delegates to Studiopipeline controller
     */
    public function pipeline() {
        $controller = new Studiopipeline();
        $controller->index();
    }

    /**
     * AJAX endpoint to set studio preference
     */
    public function setstudio() {
        if (!$this->requireLogin()) return;

        $studio = $this->getParam('studio');

        if (!$studio || !isset(self::STUDIOS[$studio])) {
            Flight::jsonError('Invalid studio', 400);
            return;
        }

        $result = Flight::setStudio($studio);

        if ($result) {
            Flight::jsonSuccess([
                'studio' => $studio,
                'redirect' => "/studio/{$studio}"
            ], 'Studio preference saved');
        } else {
            Flight::jsonError('Failed to save preference', 500);
        }
    }
}
