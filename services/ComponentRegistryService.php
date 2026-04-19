<?php
/**
 * ComponentRegistryService
 *
 * Aggregates all workspace components into a single manifest.
 * Used by the get_workspace_manifest MCP tool so AI agents can discover
 * what already exists before building new components.
 */

namespace app\services;

use app\Bean;

require_once __DIR__ . '/ConnectorRegistry.php';
require_once __DIR__ . '/StepTypeRegistry.php';
require_once __DIR__ . '/../lib/AppTemplates/AppTemplateInterface.php';
require_once __DIR__ . '/../lib/AppTemplates/AppTemplateRegistry.php';

class ComponentRegistryService
{
    private string $workspace;
    private $logger;

    private const ALL_SECTIONS = [
        'workspace_info',
        'connections',
        'pipelines',
        'dapps',
        'knowledge_bases',
        'pipeline_templates',
        'app_templates',
        'step_types',
        'connectors',
        'recipes',
        'summary',
    ];

    public function __construct(string $workspace, $logger)
    {
        $this->workspace = $workspace;
        $this->logger = $logger;
    }

    /**
     * MCP-formatted wrapper — returns content array for JSON-RPC response
     */
    public function getWorkspaceManifest(array $arguments): array
    {
        $sections = $arguments['sections'] ?? [];
        $manifest = $this->getManifest($sections);

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ]],
            'isError' => false
        ];
    }

    /**
     * Build the full manifest (or filtered sections)
     */
    public function getManifest(array $sections = []): array
    {
        // Normalize: empty array = all sections
        if (empty($sections)) {
            $sections = self::ALL_SECTIONS;
        }

        // Validate requested sections
        $sections = array_intersect($sections, self::ALL_SECTIONS);

        $manifest = [];

        // Collect each requested section
        $collectors = [
            'workspace_info'     => 'getWorkspaceInfo',
            'connections'        => 'getConnections',
            'pipelines'          => 'getPipelines',
            'dapps'              => 'getDapps',
            'knowledge_bases'    => 'getKnowledgeBases',
            'pipeline_templates' => 'getPipelineTemplates',
            'app_templates'      => 'getAppTemplates',
            'step_types'         => 'getStepTypes',
            'connectors'         => 'getConnectors',
            'recipes'            => 'getRecipes',
        ];

        foreach ($collectors as $key => $method) {
            if (in_array($key, $sections)) {
                $manifest[$key] = $this->$method();
            }
        }

        // Summary is computed from the other sections
        if (in_array('summary', $sections)) {
            $manifest['summary'] = $this->getSummary($manifest);
        }

        return $manifest;
    }

    // ─── Private collectors ────────────────────────────────────────────

    private function getWorkspaceInfo(): array
    {
        return [
            'slug' => $this->workspace,
            'base_url' => "https://{$this->workspace}.myctobot.ai",
        ];
    }

    private function getConnections(): array
    {
        $connections = Bean::find('connections', ' enabled = 1 ORDER BY connector_type ASC, external_name ASC ');
        $result = [];

        foreach ($connections as $conn) {
            $result[] = [
                'id'          => (int) $conn->id,
                'type'        => $conn->connector_type,
                'name'        => $conn->external_name ?: $conn->connection_name,
                'external_id' => $conn->external_eid,
                'enabled'     => true,
            ];
        }

        return $result;
    }

    private function getPipelines(): array
    {
        $pipelines = Bean::find('pipelines', ' is_active = 1 ORDER BY name ASC ');
        $result = [];

        foreach ($pipelines as $p) {
            $stepCount = Bean::count('pipelinesteps', 'pipelines_id = ?', [$p->id]);
            $result[] = [
                'id'             => (int) $p->id,
                'slug'           => $p->slug,
                'name'           => $p->name,
                'description'    => $p->description ?: '',
                'step_count'     => $stepCount,
                'expose_as_tool' => (bool) $p->expose_as_tool,
                'is_active'      => true,
            ];
        }

        return $result;
    }

    private function getDapps(): array
    {
        $dapps = Bean::find('detachableapps', ' 1=1 ORDER BY name ASC ');
        $result = [];

        foreach ($dapps as $dapp) {
            $entry = [
                'id'        => (int) $dapp->id,
                'slug'      => $dapp->slug,
                'name'      => $dapp->name,
                'is_hosted' => (bool) $dapp->is_hosted,
            ];

            if ($dapp->is_hosted) {
                $entry['hosted_url'] = "/dapp/{$dapp->slug}";
            }

            // Bound pipelines via link table
            $bindings = Bean::find('detachableapppipelines', 'detachableapps_id = ?', [$dapp->id]);
            $boundPipelines = [];
            foreach ($bindings as $binding) {
                $pipeline = Bean::load('pipelines', (int) $binding->pipelines_id);
                if ($pipeline && $pipeline->id) {
                    $boundPipelines[] = [
                        'slug' => $pipeline->slug,
                        'name' => $pipeline->name,
                    ];
                }
            }
            $entry['bound_pipelines'] = $boundPipelines;

            $result[] = $entry;
        }

        return $result;
    }

    private function getKnowledgeBases(): array
    {
        $knowledgeBases = Bean::find('knowledgebases', ' 1=1 ORDER BY name ASC ');
        $result = [];

        foreach ($knowledgeBases as $kb) {
            $docCount = Bean::count('knowledgebasedocs', 'knowledgebases_id = ?', [$kb->id]);
            $result[] = [
                'id'             => (int) $kb->id,
                'slug'           => $kb->slug,
                'name'           => $kb->name,
                'document_count' => $docCount,
            ];
        }

        return $result;
    }

    private function getPipelineTemplates(): array
    {
        $basePath = __DIR__ . '/../seeds/pipelines';
        $files = glob($basePath . '/**/*.json');
        $result = [];

        foreach ($files as $file) {
            $json = file_get_contents($file);
            $data = json_decode($json, true);
            if (!$data || !isset($data['pipeline'])) {
                continue;
            }

            $p = $data['pipeline'];
            $relativePath = 'seeds/pipelines/' . str_replace($basePath . '/', '', $file);

            // Derive category from directory name
            $dirName = basename(dirname($file));

            $stepCount = isset($data['steps']) ? count($data['steps']) : 0;

            $result[] = [
                'slug'        => $p['slug'] ?? basename($file, '.json'),
                'name'        => $p['name'] ?? '',
                'category'    => $dirName,
                'description' => $p['description'] ?? '',
                'file'        => $relativePath,
                'step_count'  => $stepCount,
            ];
        }

        return $result;
    }

    private function getAppTemplates(): array
    {
        $templates = \app\lib\AppTemplates\AppTemplateRegistry::getAll();
        $result = [];

        foreach ($templates as $slug => $template) {
            $result[] = [
                'slug'        => $template->getSlug(),
                'name'        => $template->getName(),
                'description' => $template->getDescription(),
                'icon'        => $template->getIcon(),
            ];
        }

        return $result;
    }

    private function getStepTypes(): array
    {
        $grouped = StepTypeRegistry::getAll(grouped: true);
        $result = [];

        foreach ($grouped as $catKey => $catInfo) {
            $result[$catKey] = array_keys($catInfo['types']);
        }

        return $result;
    }

    private function getConnectors(): array
    {
        $allMeta = ConnectorRegistry::getAllMeta();
        $result = [];

        foreach ($allMeta as $key => $meta) {
            $result[] = [
                'key'       => $key,
                'name'      => $meta['name'] ?? ucfirst($key),
                'auth_type' => $meta['auth_type'] ?? 'unknown',
            ];
        }

        return $result;
    }

    private function getRecipes(): array
    {
        $dir = __DIR__ . '/../recipes';
        if (!is_dir($dir)) {
            return [];
        }

        $result = [];
        foreach (glob($dir . '/*.md') as $file) {
            $basename = basename($file, '.md');
            if ($basename === '_index') {
                continue;
            }

            $content = file_get_contents($file);

            // Parse tags from ## Tags section
            $tags = [];
            if (preg_match('/^## Tags\s*\n(.+?)$/m', $content, $m)) {
                $tags = array_map('trim', explode(',', $m[1]));
            }

            // Parse description from ## Description section
            $description = '';
            if (preg_match('/^## Description\s*\n(.+?)(?=\n## )/ms', $content, $m)) {
                $description = trim($m[1]);
            }

            $result[] = [
                'slug' => $basename,
                'file' => "recipes/{$basename}.md",
                'tags' => $tags,
                'description' => $description,
            ];
        }

        return $result;
    }

    private function getSummary(array $manifest): array
    {
        // Use manifest data if already collected, otherwise query DB directly
        $pipelineTemplateCount = isset($manifest['pipeline_templates'])
            ? count($manifest['pipeline_templates'])
            : count(glob(__DIR__ . '/../seeds/pipelines/**/*.json'));

        $appTemplateCount = isset($manifest['app_templates'])
            ? count($manifest['app_templates'])
            : count(\app\lib\AppTemplates\AppTemplateRegistry::getAll());

        return [
            'connections_count'            => isset($manifest['connections']) ? count($manifest['connections']) : Bean::count('connections', 'enabled = 1'),
            'pipelines_count'              => isset($manifest['pipelines']) ? count($manifest['pipelines']) : Bean::count('pipelines', 'is_active = 1'),
            'dapps_count'                  => isset($manifest['dapps']) ? count($manifest['dapps']) : Bean::count('detachableapps'),
            'knowledge_bases_count'        => isset($manifest['knowledge_bases']) ? count($manifest['knowledge_bases']) : Bean::count('knowledgebases'),
            'pipeline_templates_available' => $pipelineTemplateCount,
            'app_templates_available'      => $appTemplateCount,
            'recipes_available'            => isset($manifest['recipes']) ? count($manifest['recipes']) : count(glob(__DIR__ . '/../recipes/*.md') ?: []) - (file_exists(__DIR__ . '/../recipes/_index.md') ? 1 : 0),
        ];
    }
}
