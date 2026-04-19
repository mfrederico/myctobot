<?php
/**
 * DetachableAppBuilder - Generates standalone Docker apps from Stitch screens + pipelines
 *
 * Takes a detachableapps bean and generates a complete Docker-ready app directory
 * by substituting variables into the template files.
 */

namespace app\services;

use app\Bean;
use Flight as Flight;

class DetachableAppBuilder
{
    private static ?string $rootPath = null;
    private $logger;

    public function __construct($logger = null)
    {
        $this->logger = $logger ?: (class_exists('\Flight') ? Flight::get('log') : null);
    }

    private static function getRootPath(): string
    {
        if (self::$rootPath === null) {
            self::$rootPath = dirname(__DIR__);
        }
        return self::$rootPath;
    }

    private static function getTemplateDir(): string
    {
        return self::getRootPath() . '/templates/detachable';
    }

    private static function getStorageDir(): string
    {
        return self::getRootPath() . '/storage/detachable';
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Build the detachable app: generate all files in the output directory
     *
     * @param int $appId The detachableapps bean ID
     * @return string The output directory path
     */
    public function build(int $appId): string
    {
        $app = Bean::load('detachableapps', $appId);
        if (!$app || !$app->id) {
            throw new \RuntimeException("Detachable app not found: {$appId}");
        }

        $app->build_status = 'building';
        Bean::store($app);

        try {
            $outputDir = self::getStorageDir() . '/' . ($app->workspace ?: 'default') . '/' . $app->slug;

            // Create directory structure
            $this->ensureDirs([
                $outputDir,
                $outputDir . '/public/screens',
                $outputDir . '/public/assets',
                $outputDir . '/php',
            ]);

            // Gather data
            $screens = $this->getScreens($app);
            $pipelines = $this->getPipelines($app);
            $apiToken = $this->getApiToken($app);
            $config = $app->box()->getConfig();

            $workspace = $app->workspace ?: 'default';
            $defaultApiUrl = "https://{$workspace}.myctobot.ai";
            $defaultRoute = '/';

            // Build route map and pipeline list
            $routes = [];
            foreach ($screens as $screen) {
                $routePath = $screen->route_path ?: '/';
                $filename = $screen->box()->getFilename();
                $routes[] = [
                    'path' => $routePath,
                    'file' => $filename,
                    'title' => $screen->screen_title,
                    'is_default' => (bool) $screen->is_default,
                ];
                if ($screen->is_default) {
                    $defaultRoute = $routePath;
                }
            }

            $allowedPipelines = [];
            $pipelineMap = [];
            foreach ($pipelines as $binding) {
                if (!$binding->is_active) continue;
                $pipeline = Bean::load('pipelines', (int) $binding->pipelines_id);
                if (!$pipeline || !$pipeline->id) continue;
                $alias = $binding->alias ?: $pipeline->slug;
                $allowedPipelines[] = $alias;
                $pipelineMap[] = [
                    'alias' => $alias,
                    'slug' => $pipeline->slug,
                    'name' => $pipeline->name,
                ];
            }

            // Common template replacements
            $replacements = [
                '{{APP_NAME}}'               => $app->name,
                '{{APP_SLUG}}'               => $app->slug,
                '{{APP_VERSION}}'            => $app->current_version ?: 'v0.0.0',
                '{{WORKSPACE}}'              => $workspace,
                '{{DEFAULT_API_URL}}'        => $defaultApiUrl,
                '{{DEFAULT_ROUTE}}'          => $defaultRoute,
                '{{GENERATED_AT}}'           => date('Y-m-d H:i:s'),
                '{{ROUTES_JSON}}'            => json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                '{{PIPELINES_JSON}}'         => json_encode($pipelineMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                '{{ALLOWED_PIPELINES_JSON}}' => json_encode($allowedPipelines),
                '{{SCREENS_JSON}}'           => json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                '{{API_TOKEN}}'              => $apiToken,
            ];

            // Generate all files from templates
            $this->generateFromTemplate('Dockerfile.tpl', $outputDir . '/Dockerfile', $replacements);
            $this->generateFromTemplate('docker-compose.yml.tpl', $outputDir . '/docker-compose.yml', $replacements);
            $this->generateFromTemplate('nginx.conf.tpl', $outputDir . '/nginx.conf', $replacements);
            $this->generateFromTemplate('docker-entrypoint.sh.tpl', $outputDir . '/docker-entrypoint.sh', $replacements);
            $this->generateFromTemplate('php/index.php.tpl', $outputDir . '/php/index.php', $replacements);
            $this->generateFromTemplate('php/config.php.tpl', $outputDir . '/php/config.php', $replacements);
            $this->generateFromTemplate('php/proxy.php.tpl', $outputDir . '/php/proxy.php', $replacements);
            $this->generateFromTemplate('public/index.html.tpl', $outputDir . '/public/index.html', $replacements);
            $this->generateFromTemplate('public/assets/myctobot-sdk.js.tpl', $outputDir . '/public/assets/myctobot-sdk.js', $replacements);
            $this->generateFromTemplate('public/assets/app-manifest.json.tpl', $outputDir . '/public/assets/app-manifest.json', $replacements);
            $this->generateFromTemplate('.env.example.tpl', $outputDir . '/.env.example', $replacements);
            $this->generateFromTemplate('.gitignore.tpl', $outputDir . '/.gitignore', $replacements);
            $this->generateFromTemplate('manifest.json.tpl', $outputDir . '/manifest.json', $replacements);

            // Copy and inject SDK into screen HTML files
            foreach ($screens as $screen) {
                $filename = $screen->box()->getFilename();
                $html = $screen->html_cache ?: '';
                if (!empty($html)) {
                    $html = $this->injectSdk($html);
                }
                $this->writeFile($outputDir . '/public/screens/' . $filename, $html);
            }

            $app->build_status = 'idle';
            Bean::store($app);

            $this->log('info', 'Detachable app built', [
                'app_id' => $appId,
                'slug' => $app->slug,
                'output_dir' => $outputDir,
                'screens' => count($screens),
                'pipelines' => count($allowedPipelines),
            ]);

            return $outputDir;

        } catch (\Exception $e) {
            $app->build_status = 'failed';
            Bean::store($app);
            throw $e;
        }
    }

    /**
     * Fetch all screen HTML from Stitch and cache in DB
     *
     * @param int $appId
     * @return array Results for each screen
     */
    public function fetchAllScreens(int $appId): array
    {
        $app = Bean::load('detachableapps', $appId);
        if (!$app || !$app->id) {
            throw new \RuntimeException("Detachable app not found: {$appId}");
        }

        if (!$app->stitch_connection_eid) {
            // Template-based apps have pre-cached HTML, no Stitch fetch needed
            return [];
        }

        $client = new StitchClient((int) $app->stitch_connection_eid);
        $screens = $this->getScreens($app);
        $results = [];

        foreach ($screens as $screen) {
            try {
                $downloadUrl = $screen->stitch_download_url ?: '';
                $result = $this->fetchScreenHtml(
                    $client,
                    $app->stitch_project_eid,
                    $screen->stitch_screen_eid,
                    $downloadUrl
                );

                if ($result['success']) {
                    $screen->html_cache = $result['content'];
                    $screen->html_cached_at = date('Y-m-d H:i:s');
                    Bean::store($screen);
                    $results[] = [
                        'screen_id' => $screen->id,
                        'title' => $screen->screen_title,
                        'success' => true,
                    ];
                } else {
                    $results[] = [
                        'screen_id' => $screen->id,
                        'title' => $screen->screen_title,
                        'success' => false,
                        'error' => $result['error'] ?? 'Unknown error',
                    ];
                }
            } catch (\Exception $e) {
                $results[] = [
                    'screen_id' => $screen->id,
                    'title' => $screen->screen_title,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Package the built app as a .zip download
     *
     * @param int $appId
     * @return string Path to the zip file
     */
    public function packageZip(int $appId): string
    {
        $app = Bean::load('detachableapps', $appId);
        if (!$app || !$app->id) {
            throw new \RuntimeException("Detachable app not found: {$appId}");
        }

        $outputDir = self::getStorageDir() . '/' . ($app->workspace ?: 'default') . '/' . $app->slug;
        if (!is_dir($outputDir)) {
            throw new \RuntimeException('App has not been built yet. Run build first.');
        }

        $zipPath = self::getStorageDir() . '/' . ($app->workspace ?: 'default') . '/' . $app->slug . '.zip';

        // Remove old zip if exists
        if (file_exists($zipPath)) {
            unlink($zipPath);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException("Failed to create zip file: {$zipPath}");
        }

        $this->addDirToZip($zip, $outputDir, $app->slug);
        $zip->close();

        return $zipPath;
    }

    /**
     * Build, commit, tag, and push a git release
     *
     * @param int $appId
     * @param string $version Version string (e.g. "v1.0.0"), auto-incremented if empty
     * @param string $notes Release notes
     * @return array Release info
     */
    public function gitRelease(int $appId, string $version = '', string $notes = ''): array
    {
        $app = Bean::load('detachableapps', $appId);
        if (!$app || !$app->id) {
            throw new \RuntimeException("Detachable app not found: {$appId}");
        }

        if (empty($app->git_repo_url)) {
            throw new \RuntimeException('No Git repository URL configured for this app');
        }

        // Auto-increment version if not specified
        if (empty($version)) {
            $version = $this->incrementVersion($app->current_version ?: '');
        }
        if (!str_starts_with($version, 'v')) {
            $version = 'v' . $version;
        }

        // Validate version format (vX.Y.Z)
        if (!preg_match('/^v\d+\.\d+\.\d+$/', $version)) {
            throw new \InvalidArgumentException("Invalid version format: {$version}. Expected vX.Y.Z");
        }

        // Build the app
        $outputDir = $this->build($appId);

        // Prepare SSH key if deploy key is set
        $keyFile = null;
        $sshEnv = [];
        if (!empty($app->git_deploy_key)) {
            $keyFile = tempnam(sys_get_temp_dir(), 'deploy_key_');
            $decryptedKey = EncryptionService::decrypt($app->git_deploy_key);
            file_put_contents($keyFile, $decryptedKey);
            chmod($keyFile, 0600);
            $sshEnv = ['GIT_SSH_COMMAND' => 'ssh -i ' . escapeshellarg($keyFile) . ' -o StrictHostKeyChecking=no'];
        }

        try {
            // Initialize git repo if needed
            if (!is_dir($outputDir . '/.git')) {
                $this->runGitCommand($outputDir, ['init']);
                $this->runGitCommand($outputDir, ['remote', 'add', 'origin', $app->git_repo_url]);
            }

            // Commit and tag
            $commitMsg = "Release {$version}";
            if (!empty($notes)) {
                $commitMsg .= ": {$notes}";
            }

            $this->runGitCommand($outputDir, ['add', '-A']);
            $this->runGitCommand($outputDir, ['commit', '-m', $commitMsg]);
            $this->runGitCommand($outputDir, ['tag', '-a', $version, '-m', $commitMsg]);

            // Push with optional deploy key
            $this->runGitCommand($outputDir, ['push', 'origin', 'main', '--tags'], $sshEnv);

            // Get commit SHA
            $sha = trim($this->runGitCommand($outputDir, ['rev-parse', 'HEAD']));

            // Create release record
            $release = Bean::dispense('detachableappreleases');
            $release->detachableapps_id = $app->id;
            $release->version = $version;
            $release->git_commit_sha = $sha;
            $release->git_tag = $version;
            $release->release_notes = $notes;
            $release->built_by_member_id = $_SESSION['member_id'] ?? null;

            // Snapshot screen state
            $screens = $this->getScreens($app);
            $snapshot = [];
            foreach ($screens as $s) {
                $snapshot[] = [
                    'title' => $s->screen_title,
                    'route_path' => $s->route_path,
                    'stitch_screen_eid' => $s->stitch_screen_eid,
                ];
            }
            $release->screens_snapshot_json = json_encode($snapshot);
            Bean::store($release);

            // Update app version
            $app->current_version = $version;
            Bean::store($app);

            $this->log('info', 'Git release created', [
                'app_id' => $appId,
                'version' => $version,
                'commit' => $sha,
            ]);

            return [
                'success' => true,
                'version' => $version,
                'commit_sha' => $sha,
                'release_id' => $release->id,
            ];

        } finally {
            // Clean up temp key file
            if ($keyFile !== null && file_exists($keyFile)) {
                unlink($keyFile);
            }
        }
    }

    // =========================================================================
    // SCREEN FETCHING (delegates to StitchClient)
    // =========================================================================

    /**
     * Fetch screen HTML via authenticated Stitch proxy.
     * Reuses the same pattern as Stitch controller's fetchScreenHtml().
     */
    private function fetchScreenHtml(StitchClient $client, string $projectId, string $screenId, string $cachedUrl = ''): array
    {
        // Try cached URL first
        if (!empty($cachedUrl)) {
            $result = $client->downloadAuthenticatedUrl($cachedUrl);
            if ($result['success']) {
                return $result;
            }
        }

        // Fetch screen to get a fresh download URL
        $name = "projects/{$projectId}/screens/{$screenId}";
        $screenResult = $client->getScreen($name, $projectId, $screenId);
        $screen = $screenResult['result']['structuredContent'] ?? [];
        if (empty($screen) || !isset($screen['name'])) {
            foreach (($screenResult['result']['content'] ?? []) as $item) {
                if (isset($item['text'])) {
                    $parsed = json_decode($item['text'], true);
                    if ($parsed && isset($parsed['name'])) {
                        $screen = $parsed;
                        break;
                    }
                }
            }
        }

        $downloadUrl = $screen['htmlCode']['downloadUrl']
            ?? $screen['codeDownloadUrl']
            ?? $screen['code_download_url']
            ?? '';

        if (empty($downloadUrl)) {
            return ['success' => false, 'error' => 'No download URL available for this screen'];
        }

        return $client->downloadAuthenticatedUrl($downloadUrl);
    }

    // =========================================================================
    // SDK INJECTION
    // =========================================================================

    /**
     * Inject the MyCTOBot SDK script tag into Stitch HTML before </body>
     */
    private function injectSdk(string $html): string
    {
        $sdkTag = '<script src="/assets/myctobot-sdk.js"></script>';

        // Insert before </body> if it exists
        if (stripos($html, '</body>') !== false) {
            return str_ireplace('</body>', $sdkTag . "\n</body>", $html);
        }

        // Otherwise append
        return $html . "\n" . $sdkTag;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function getScreens($app): array
    {
        return Bean::find(
            'detachableappscreens',
            ' detachableapps_id = ? ORDER BY sort_order ASC, id ASC ',
            [$app->id]
        );
    }

    private function getPipelines($app): array
    {
        return Bean::find(
            'detachableapppipelines',
            ' detachableapps_id = ? AND is_active = 1 ',
            [$app->id]
        );
    }

    private function getApiToken($app): string
    {
        if (!$app->apikeys_id) return '';
        $apiKey = Bean::load('apikeys', (int) $app->apikeys_id);
        return ($apiKey && $apiKey->token) ? $apiKey->token : '';
    }

    /**
     * Auto-increment version: v1.0.2 -> v1.0.3, empty -> v1.0.0
     */
    private function incrementVersion(string $current): string
    {
        if (empty($current)) return 'v1.0.0';

        $ver = ltrim($current, 'v');
        $parts = explode('.', $ver);
        if (count($parts) !== 3) return 'v1.0.0';

        $parts[2] = (int)$parts[2] + 1;
        return 'v' . implode('.', $parts);
    }

    /**
     * Run a git command safely using proc_open with escaped arguments
     *
     * @param string $workDir Working directory for the git command
     * @param array $args Git command arguments (each will be escaped)
     * @param array $env Additional environment variables
     * @return string Command output
     */
    private function runGitCommand(string $workDir, array $args, array $env = []): string
    {
        $cmd = 'git';
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $fullEnv = array_merge($_ENV, $env);
        $process = proc_open($cmd, $descriptors, $pipes, $workDir, $fullEnv);

        if (!is_resource($process)) {
            throw new \RuntimeException("Failed to run git command: {$cmd}");
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException("Git command failed (exit {$exitCode}): {$stderr}");
        }

        return $stdout;
    }

    /**
     * Load and render a template file with variable substitution
     *
     * @param string $templateFile Template path relative to templates/{$baseDir}/
     * @param string $outputPath Destination file path
     * @param array $replacements Variable substitutions
     * @param string $baseDir Base directory under templates/ (default: 'detachable', empty for root)
     */
    private function generateFromTemplate(string $templateFile, string $outputPath, array $replacements, string $baseDir = 'detachable'): void
    {
        $rootPath = self::getRootPath();

        // Handle empty baseDir (template path includes subdirectory)
        if ($baseDir === '') {
            $templatePath = $rootPath . '/templates/' . $templateFile;
        } else {
            $templatePath = $rootPath . '/templates/' . $baseDir . '/' . $templateFile;
        }

        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Template not found: {$templatePath}");
        }

        $template = file_get_contents($templatePath);
        $content = strtr($template, $replacements);
        $this->writeFile($outputPath, $content);
    }

    private function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("Failed to write file: {$path}");
        }
    }

    private function ensureDirs(array $dirs): void
    {
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    throw new \RuntimeException("Failed to create directory: {$dir}");
                }
            }
        }
    }

    /**
     * Recursively add a directory to a ZipArchive
     */
    private function addDirToZip(\ZipArchive $zip, string $dir, string $prefix): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $filePath = $file->getRealPath();
            $relativePath = $prefix . '/' . substr($filePath, strlen($dir) + 1);

            if ($file->isDir()) {
                // Skip .git directory
                if (str_contains($relativePath, '/.git')) continue;
                $zip->addEmptyDir($relativePath);
            } else {
                // Skip .git directory contents
                if (str_contains($relativePath, '/.git/')) continue;
                $zip->addFile($filePath, $relativePath);
            }
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger && method_exists($this->logger, $level)) {
            $this->logger->$level($message, $context);
        }
    }

    /**
     * Create an API key for a detachable app (scoped to pipelines:run)
     */
    public static function createApiKey(int $memberId, string $appName): \RedBeanPHP\OODBBean
    {
        $apiKey = Bean::dispense('apikeys');
        $apiKey->name = "Detachable App: {$appName}";
        $apiKey->token = ApiAuthService::generateToken();
        $apiKey->member_id = $memberId;
        $apiKey->scopes_json = json_encode(['pipelines:run', 'pipelines:status']);
        $apiKey->is_active = 1;
        $apiKey->created_at = date('Y-m-d H:i:s');
        Bean::store($apiKey);
        return $apiKey;
    }

    // =========================================================================
    // SHOPIFY APP BUILDER
    // =========================================================================

    /**
     * Build a Shopify App Store bundle from Liquid files + pipelines
     *
     * @param int $appId The detachableapps bean ID
     * @param array $options Build options (e.g., ['create_zip' => true])
     * @return array Build result with output_dir, file_count, zip_path
     */
    public function buildShopifyApp(int $appId, array $options = []): array
    {
        $app = Bean::load('detachableapps', $appId);
        if (!$app || !$app->id) {
            throw new \RuntimeException("Detachable app not found: {$appId}");
        }

        $app->build_status = 'building';
        Bean::store($app);

        try {
            $workspace = $app->workspace ?: 'default';
            $outputDir = self::getStorageDir() . '/' . $workspace . '/shopify-' . $app->slug;

            // Create directory structure
            $extensionDir = $outputDir . '/extensions/theme-app-extension';
            $this->ensureDirs([
                $outputDir,
                $extensionDir . '/blocks',
                $extensionDir . '/assets',
                $extensionDir . '/snippets',
                $extensionDir . '/sections',
                $extensionDir . '/locales',
            ]);

            // Load all Liquid files
            $liquidFiles = Bean::find(
                'detachableappliquidfiles',
                ' detachableapps_id = ? ORDER BY file_type, sort_order, file_name ',
                [$appId]
            );

            // Group files by type for counting and listing
            $filesByType = [
                'block' => [], 'embed' => [], 'section' => [],
                'snippet' => [], 'asset_js' => [], 'asset_css' => [], 'locale' => [],
            ];

            foreach ($liquidFiles as $file) {
                $filesByType[$file->file_type][] = $file;
            }

            // Copy Liquid files to extension directories
            $totalFiles = 0;
            foreach ($liquidFiles as $file) {
                $destPath = $this->getShopifyFilePath($extensionDir, $file);
                $this->writeFile($destPath, $file->file_content);
                $totalFiles++;
            }

            // Generate block and embed lists for README
            $blockList = '';
            foreach ($filesByType['block'] as $block) {
                $blockList .= "- **{$block->file_name}**\n";
            }
            if (empty($blockList)) {
                $blockList = '(none)';
            }

            $embedList = '';
            foreach ($filesByType['embed'] as $embed) {
                $embedList .= "- **{$embed->file_name}**\n";
            }
            if (empty($embedList)) {
                $embedList = '(none)';
            }

            // Load embedded pipelines
            $embeddedPipelines = Bean::find(
                'detachableappembeddedpipelines',
                ' detachableapps_id = ? ORDER BY pipeline_slug ',
                [$appId]
            );

            $pipelineList = '';
            foreach ($embeddedPipelines as $ep) {
                $pipelineList .= "- **{$ep->pipeline_slug}**" . ($ep->is_required ? " (required)" : " (optional)") . "\n";
            }
            if (empty($pipelineList)) {
                $pipelineList = '(none)';
            }

            // Prepare template replacements
            $defaultBaseUrl = "https://{$workspace}.myctobot.ai";
            $proxySubpath = $app->app_proxy_subpath ?: '/' . $app->slug;
            $oauthScopes = $app->oauth_scopes ?: 'read_products,write_themes';

            $replacements = [
                '{{APP_NAME}}'          => $app->name,
                '{{APP_SLUG}}'          => $app->slug,
                '{{APP_VERSION}}'       => $app->current_version ?: 'v1.0.0',
                '{{WORKSPACE}}'         => $workspace,
                '{{GENERATED_AT}}'      => date('Y-m-d H:i:s'),
                '{{SHOPIFY_API_KEY}}'   => $app->shopify_api_key ?: 'YOUR_API_KEY_HERE',
                '{{APP_URL}}'           => $defaultBaseUrl . '/apps/shopify/' . $app->slug,
                '{{REDIRECT_URL}}'      => $defaultBaseUrl . '/apps/shopify/callback/' . $app->slug,
                '{{OAUTH_SCOPES}}'      => $oauthScopes,
                '{{PROXY_URL}}'         => $defaultBaseUrl . '/apps/myctobot/proxy',
                '{{PROXY_SUBPATH}}'     => $proxySubpath,
                '{{DEV_STORE_URL}}'     => '',  // Merchant sets this during dev
                '{{BLOCKS_COUNT}}'      => count($filesByType['block']),
                '{{EMBEDS_COUNT}}'      => count($filesByType['embed']),
                '{{SECTIONS_COUNT}}'    => count($filesByType['section']),
                '{{SNIPPETS_COUNT}}'    => count($filesByType['snippet']),
                '{{JS_ASSETS_COUNT}}'   => count($filesByType['asset_js']),
                '{{CSS_ASSETS_COUNT}}'  => count($filesByType['asset_css']),
                '{{BLOCK_LIST}}'        => $blockList,
                '{{EMBED_LIST}}'        => $embedList,
                '{{PIPELINE_LIST}}'     => $pipelineList,
            ];

            // Generate app config and README
            $this->generateFromTemplate(
                'shopify-app/shopify.app.toml.tpl',
                $outputDir . '/shopify.app.toml',
                $replacements,
                ''  // Empty baseDir - shopify-app is already in the path
            );

            $this->generateFromTemplate(
                'shopify-app/README.md.tpl',
                $outputDir . '/README.md',
                $replacements,
                ''  // Empty baseDir - shopify-app is already in the path
            );

            // Export embedded pipelines as JSON
            if (!empty($embeddedPipelines)) {
                $pipelinesDir = $outputDir . '/pipelines';
                $this->ensureDirs([$pipelinesDir]);

                foreach ($embeddedPipelines as $ep) {
                    $pipelineData = json_decode($ep->pipeline_json ?: '{}', true);
                    $filename = $ep->pipeline_slug . '.json';
                    $this->writeFile(
                        $pipelinesDir . '/' . $filename,
                        json_encode($pipelineData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    );
                }
            }

            // Create ZIP if requested
            $zipPath = null;
            if ($options['create_zip'] ?? false) {
                $zipPath = $this->createZip($outputDir, $app->slug);
            }

            $app->build_status = 'idle';
            $app->last_build_at = date('Y-m-d H:i:s');
            Bean::store($app);

            $this->log('info', 'Shopify app built', [
                'app_id' => $appId,
                'slug' => $app->slug,
                'output_dir' => $outputDir,
                'files' => $totalFiles,
                'pipelines' => count($embeddedPipelines),
            ]);

            return [
                'success' => true,
                'output_dir' => $outputDir,
                'file_count' => $totalFiles,
                'zip_path' => $zipPath,
            ];

        } catch (\Exception $e) {
            $app->build_status = 'failed';
            Bean::store($app);
            throw $e;
        }
    }

    /**
     * Get destination path for a Liquid file in the Shopify extension structure
     */
    private function getShopifyFilePath(string $extensionDir, object $file): string
    {
        $fileName = $file->file_name;

        switch ($file->file_type) {
            case 'block':
                return $extensionDir . '/blocks/' . $fileName;
            case 'embed':
                // Embeds go in root of extension (no subdirectory)
                return $extensionDir . '/' . $fileName;
            case 'section':
                return $extensionDir . '/sections/' . $fileName;
            case 'snippet':
                return $extensionDir . '/snippets/' . $fileName;
            case 'asset_js':
            case 'asset_css':
                return $extensionDir . '/assets/' . $fileName;
            case 'locale':
                // Locale files go in locales/ with language code prefix
                // Default to en.default.json if not specified
                $localeFileName = $fileName ?: 'en.default.json';
                return $extensionDir . '/locales/' . $localeFileName;
            default:
                throw new \RuntimeException("Unknown file type: {$file->file_type}");
        }
    }

    /**
     * Create a ZIP archive of the app bundle
     */
    private function createZip(string $sourceDir, string $appSlug): string
    {
        $zipPath = dirname($sourceDir) . '/' . $appSlug . '.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Failed to create ZIP: {$zipPath}");
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($sourceDir) + 1);
                $zip->addFile($filePath, $appSlug . '/' . $relativePath);
            }
        }

        $zip->close();
        return $zipPath;
    }

}
