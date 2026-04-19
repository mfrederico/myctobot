<?php
/**
 * PipelineEngineBuilder - Generates the pipeline engine server directory
 *
 * Creates the OpenSwoole server files for a workspace's pipeline engine.
 * Follows the same pattern as TenantAppBuilder but for engine services.
 */

namespace app\services;

use app\Bean;

class PipelineEngineBuilder
{
    private static ?string $rootPath = null;

    private static function getRootPath(): string
    {
        if (self::$rootPath === null) {
            self::$rootPath = dirname(__DIR__);
        }
        return self::$rootPath;
    }

    private static function getTemplateDir(): string
    {
        return self::getRootPath() . '/templates/pipeline-engine';
    }

    private static function getStorageDir(): string
    {
        return self::getRootPath() . '/storage/engines';
    }

    /**
     * Build the engine server directory for a workspace
     *
     * @param string $workspace The workspace slug
     * @param int $serviceId The tenantapps service ID
     * @return string Path to the generated engine directory
     */
    public function build(string $workspace, int $serviceId): string
    {
        $service = Bean::load('tenantapps', $serviceId);
        if (!$service || !$service->id) {
            throw new \RuntimeException("Engine service not found: {$serviceId}");
        }

        $engineDir = self::getStorageDir() . "/{$workspace}";
        $this->ensureDirectories($engineDir);

        // Generate files from templates
        $replacements = $this->buildReplacements($service, $workspace);

        $this->generateFromTemplate(
            $engineDir . '/server.php',
            self::getTemplateDir() . '/server.php.tpl',
            $replacements
        );

        $this->generateFromTemplate(
            $engineDir . '/config.php',
            self::getTemplateDir() . '/config.php.tpl',
            $replacements
        );

        // Reuse bootstrap from tenantapp template (same pattern)
        $this->generateFromTemplate(
            $engineDir . '/bootstrap.php',
            self::getRootPath() . '/templates/tenantapp/bootstrap.php.tpl',
            $replacements
        );

        return $engineDir;
    }

    /**
     * Check if engine is already built for a workspace
     */
    public function isBuilt(string $workspace): bool
    {
        $engineDir = self::getStorageDir() . "/{$workspace}";
        return file_exists($engineDir . '/server.php');
    }

    /**
     * Clean up generated engine files
     */
    public function cleanup(string $workspace): void
    {
        $engineDir = self::getStorageDir() . "/{$workspace}";
        if (is_dir($engineDir)) {
            $this->removeDirectory($engineDir);
        }
    }

    /**
     * Build template replacement values
     */
    private function buildReplacements($service, string $workspace): array
    {
        $config = json_decode($service->config_json ?: '{}', true) ?: [];

        return [
            '{{MYCTOBOT_ROOT}}' => self::getRootPath(),
            '{{WORKSPACE}}' => $workspace,
            '{{PORT}}' => (string) ($service->port ?? 9600),
            '{{WORKERS}}' => (string) ($config['workers'] ?? 4),
            '{{SERVICE_ID}}' => (string) $service->id,
            '{{ENGINE_TOKEN}}' => $service->api_key ?? '',
            '{{DEBUG}}' => !empty($config['debug']) ? 'true' : 'false',
            '{{GENERATED_AT}}' => date('Y-m-d H:i:s'),
            '{{APP_NAME}}' => 'Pipeline Engine',
            '{{APP_SLUG}}' => 'pipeline-engine',
            '{{HOST}}' => '0.0.0.0',
            '{{HEALTH_PATH}}' => '/health',
            '{{APP_ID}}' => (string) $service->id,
            '{{AUTH_TYPE}}' => 'token',
            '{{API_KEY}}' => $service->api_key ?? '',
            '{{PIPELINE_ID}}' => '0',
            '{{APP_TYPE}}' => 'engine',
            '{{CORS_ORIGIN}}' => '*',
            '{{ROUTES_JSON}}' => '[]',
            '{{EXPOSE_MCP}}' => 'false',
            '{{MCP_TOOLS_JSON}}' => '[]',
        ];
    }

    /**
     * Generate a file from a template with replacements
     */
    private function generateFromTemplate(string $outputPath, string $templatePath, array $replacements): void
    {
        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Template not found: {$templatePath}");
        }

        $content = file_get_contents($templatePath);
        $content = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $content
        );

        file_put_contents($outputPath, $content);
    }

    /**
     * Ensure directory structure exists
     */
    private function ensureDirectories(string $engineDir): void
    {
        $dirs = [
            $engineDir,
            $engineDir . '/storage',
            $engineDir . '/storage/logs',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * Recursively remove a directory
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($dir);
    }
}
