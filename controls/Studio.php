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
            'icon' => 'bi-code-slash',
            'color' => 'primary',
            'features' => [
                'AI Dev Jobs - Automated code generation and fixes',
                'Git Repositories - Connect and manage your repos',
                'Jira Boards - Track issues and sprints',
                'Agents & Workstations - Configure AI assistants'
            ]
        ],
        'apps' => [
            'name' => 'App Studio',
            'shortName' => 'Apps',
            'tagline' => 'Build and deploy standalone microservices',
            'description' => 'Microservices',
            'icon' => 'bi-box-seam',
            'color' => 'info',
            'features' => [
                'Tenant Apps - Deploy standalone HTTP services',
                'Per-App Datastores - Isolated SQLite databases',
                'Pipeline Integration - Expose apps as MCP tools',
                'Admin Dashboards - Built-in CRUD interfaces'
            ]
        ],
        'commerce' => [
            'name' => 'Commerce Studio',
            'shortName' => 'Commerce',
            'tagline' => 'Manage your Shopify stores with AI assistance',
            'description' => 'Shopify & themes',
            'icon' => 'bi-shop',
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
            'icon' => 'bi-diagram-3',
            'color' => 'warning',
            'features' => [
                'Pipeline Builder - Visual workflow designer',
                'Run History - Monitor and debug executions',
                'Schedules - Automate recurring tasks',
                'MCP Servers - Connect external tools and services'
            ]
        ],
        'sales' => [
            'name' => 'Sales & Marketing Studio',
            'shortName' => 'Sales',
            'tagline' => 'Manage your sales pipeline, contacts, and outreach',
            'description' => 'CRM & outreach',
            'icon' => 'bi-megaphone',
            'color' => 'danger',
            'features' => [
                'CRM - Manage contacts, prospects, and customers',
                'Sales Pipeline - Track deals through stages',
                'Live Chat - Real-time customer engagement',
                'Agreements - Digital signature and onboarding'
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
     * App Studio dashboard - delegates to Studioapps controller
     */
    public function apps() {
        $controller = new Studioapps();
        $controller->index();
    }

    /**
     * Sales & Marketing Studio dashboard - delegates to Studiosales controller
     */
    public function sales() {
        $controller = new Studiosales();
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
