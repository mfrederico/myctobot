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
            'tagline' => 'Build and deploy with AI-powered development tools',
            'icon' => 'bi-code-slash',
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
            'tagline' => 'Manage your Shopify stores with AI assistance',
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
            'tagline' => 'Orchestrate complex workflows and automations',
            'icon' => 'bi-diagram-3',
            'color' => 'info',
            'features' => [
                'Pipeline Builder - Visual workflow designer',
                'Run History - Monitor and debug executions',
                'Schedules - Automate recurring tasks',
                'MCP Servers - Connect external tools and services'
            ]
        ]
    ];

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
     * Developer Studio dashboard
     */
    public function developer() {
        if (!$this->requireLogin()) return;

        // Set this as the active studio
        Flight::setStudio('developer');

        $member = Flight::getMember();
        $studioInfo = self::STUDIOS['developer'];

        // Get recent AI Dev jobs
        $recentJobs = Bean::find('aidevjobs',
            ' member_id = ? ORDER BY created_at DESC LIMIT 5 ',
            [$member->id]
        );

        $jobs = [];
        foreach ($recentJobs as $job) {
            $jobs[] = [
                'id' => $job->id,
                'issue_key' => $job->issue_key,
                'status' => $job->status,
                'created_at' => $job->created_at,
                'completed_at' => $job->completed_at
            ];
        }

        // Get connected repos
        $repos = Bean::find('repoconnections',
            ' member_id = ? ORDER BY created_at DESC LIMIT 5 ',
            [$member->id]
        );

        $repoList = [];
        foreach ($repos as $repo) {
            $repoList[] = [
                'id' => $repo->id,
                'name' => $repo->repo_name ?? $repo->name,
                'provider' => $repo->provider ?? 'github',
                'url' => $repo->repo_url ?? $repo->url
            ];
        }

        // Get Jira boards
        $boards = Bean::find('jiraboards',
            ' member_id = ? ORDER BY created_at DESC LIMIT 5 ',
            [$member->id]
        );

        $boardList = [];
        foreach ($boards as $board) {
            $boardList[] = [
                'id' => $board->id,
                'name' => $board->name,
                'project_key' => $board->project_key
            ];
        }

        $this->viewData['title'] = 'Developer Studio';
        $this->viewData['studio'] = $studioInfo;
        $this->viewData['studioKey'] = 'developer';
        $this->viewData['jobs'] = $jobs;
        $this->viewData['repos'] = $repoList;
        $this->viewData['boards'] = $boardList;
        $this->viewData['studios'] = self::STUDIOS;

        $this->render('studio/developer', $this->viewData);
    }

    /**
     * Commerce Studio dashboard
     */
    public function commerce() {
        if (!$this->requireLogin()) return;

        // Set this as the active studio
        Flight::setStudio('commerce');

        $member = Flight::getMember();
        $studioInfo = self::STUDIOS['commerce'];

        // Get Shopify connections
        $shopifyStores = Bean::find('shopifyconnections',
            ' member_id = ? ORDER BY created_at DESC ',
            [$member->id]
        );

        $stores = [];
        foreach ($shopifyStores as $store) {
            $stores[] = [
                'id' => $store->id,
                'shop_domain' => $store->shop_domain,
                'shop_name' => $store->shop_name ?? $store->shop_domain,
                'status' => $store->status ?? 'active'
            ];
        }

        $this->viewData['title'] = 'Commerce Studio';
        $this->viewData['studio'] = $studioInfo;
        $this->viewData['studioKey'] = 'commerce';
        $this->viewData['stores'] = $stores;
        $this->viewData['studios'] = self::STUDIOS;

        $this->render('studio/commerce', $this->viewData);
    }

    /**
     * Pipeline Studio dashboard
     */
    public function pipeline() {
        if (!$this->requireLogin()) return;

        // Set this as the active studio
        Flight::setStudio('pipeline');

        $member = Flight::getMember();
        $studioInfo = self::STUDIOS['pipeline'];

        // Get pipelines
        $pipelines = Bean::find('pipelines',
            ' member_id = ? ORDER BY updated_at DESC LIMIT 10 ',
            [$member->id]
        );

        $pipelineList = [];
        foreach ($pipelines as $p) {
            $pipelineList[] = [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'is_active' => (bool) $p->is_active,
                'run_count' => $p->run_count ?? 0,
                'last_run_at' => $p->last_run_at
            ];
        }

        // Get recent runs
        $recentRuns = Bean::find('pipelineruns',
            ' member_id = ? ORDER BY created_at DESC LIMIT 10 ',
            [$member->id]
        );

        $runs = [];
        foreach ($recentRuns as $run) {
            $pipeline = Bean::load('pipelines', $run->pipelines_id);
            $runs[] = [
                'id' => $run->id,
                'run_uid' => $run->run_uid,
                'pipeline_name' => $pipeline->name ?? 'Unknown',
                'status' => $run->status,
                'trigger_source' => $run->trigger_source,
                'created_at' => $run->created_at
            ];
        }

        // Get MCP servers
        $mcpServers = Bean::find('mcpservers',
            ' member_id = ? ORDER BY name ASC LIMIT 5 ',
            [$member->id]
        );

        $servers = [];
        foreach ($mcpServers as $server) {
            $servers[] = [
                'id' => $server->id,
                'name' => $server->name,
                'transport' => $server->transport,
                'is_active' => (bool) $server->is_active
            ];
        }

        $this->viewData['title'] = 'Pipeline Studio';
        $this->viewData['studio'] = $studioInfo;
        $this->viewData['studioKey'] = 'pipeline';
        $this->viewData['pipelines'] = $pipelineList;
        $this->viewData['runs'] = $runs;
        $this->viewData['servers'] = $servers;
        $this->viewData['studios'] = self::STUDIOS;

        $this->render('studio/pipeline', $this->viewData);
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
