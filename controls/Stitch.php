<?php
/**
 * Stitch Controller
 * Handles Google Stitch AI design tool integration via OAuth
 */

namespace app;

use \Flight as Flight;
use \Exception as Exception;
use \app\services\StitchClient;
use \app\services\OAuthStateService;
use \app\Bean;

require_once __DIR__ . '/../services/StitchClient.php';
require_once __DIR__ . '/../services/OAuthStateService.php';
require_once __DIR__ . '/../services/connectors/StitchConnector.php';

class Stitch extends BaseControls\Control {

    /**
     * List Stitch connections
     */
    public function index() {
        if (!$this->requireLogin()) return;

        $connections = StitchClient::getAllConnections($this->member->id);

        $this->render('stitch/index', [
            'title' => 'Google Stitch',
            'connections' => $connections,
            'member_id' => $this->member->id,
            'oauth_configured' => !empty(\app\services\connectors\StitchConnector::loadOAuthConfig()['client_id']),
        ]);
    }

    /**
     * Start OAuth flow - delegates to generic Connections controller
     */
    public function connect() {
        Flight::redirect('/connections/connect/stitch');
    }

    /**
     * Handle OAuth callback from Google
     * Forwards to generic Connections controller callback
     */
    public function callback() {
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        Flight::redirect('/connections/callback/stitch' . ($queryString ? '?' . $queryString : ''));
    }

    /**
     * Test a Stitch connection (AJAX)
     */
    public function test($params = []) {
        if (!$this->requireLogin()) return;

        $connectionId = (int)($params[0] ?? $this->getParam('id') ?? 0);

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            $conn = StitchClient::getConnection($connectionId);
            if (!$conn) {
                $this->json(['success' => false, 'message' => 'Connection not found']);
                return;
            }

            // Check access
            if ($conn->member_id != $this->member->id && !$conn->shared) {
                $this->json(['success' => false, 'message' => 'Access denied']);
                return;
            }

            $client = new StitchClient($connectionId);
            $result = $client->testConnection();
            $this->json($result);

        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Disconnect a Stitch connection
     */
    public function disconnect($params = []) {
        if (!$this->requireLogin()) return;

        $connectionId = (int)($params[0] ?? $this->getParam('id') ?? 0);

        if (empty($connectionId)) {
            $this->flash('error', 'No connection specified.');
            Flight::redirect('/stitch');
            return;
        }

        try {
            $conn = StitchClient::getConnection($connectionId);
            if (!$conn) {
                $this->flash('error', 'Connection not found.');
                Flight::redirect('/stitch');
                return;
            }

            // Only owner can disconnect
            if ($conn->member_id != $this->member->id) {
                $this->flash('error', 'You can only disconnect your own connections.');
                Flight::redirect('/stitch');
                return;
            }

            $email = $conn->external_eid;
            StitchClient::deleteConnection($connectionId);

            $this->flash('success', "Disconnected {$email}");
            $this->logger->info('Stitch connection disconnected', [
                'member_id' => $this->member->id,
                'connection_id' => $connectionId,
                'google_email' => $email,
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to disconnect Stitch', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to disconnect: ' . $e->getMessage());
        }

        Flight::redirect('/stitch');
    }

    /**
     * List projects for a connection (AJAX)
     */
    public function projects($params = []) {
        if (!$this->requireLogin()) return;

        $connectionId = (int)($params[0] ?? $this->getParam('id') ?? 0);

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            $conn = StitchClient::getConnection($connectionId);
            if (!$conn) {
                $this->json(['success' => false, 'message' => 'Connection not found']);
                return;
            }

            if ($conn->member_id != $this->member->id && !$conn->shared) {
                $this->json(['success' => false, 'message' => 'Access denied']);
                return;
            }

            $client = new StitchClient($connectionId);
            $result = $client->listProjects();

            $this->json([
                'success' => true,
                'data' => $result['result'] ?? $result
            ]);

        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * List available MCP tools (AJAX)
     */
    public function tools($params = []) {
        if (!$this->requireLogin()) return;

        $connectionId = (int)($params[0] ?? $this->getParam('id') ?? 0);
        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            $client = new StitchClient($connectionId);
            $result = $client->listTools();
            $this->json(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Call any MCP tool (AJAX) - for testing
     */
    public function calltool($params = []) {
        if (!$this->requireLogin()) return;

        $connectionId = (int)($params[0] ?? $this->getParam('connection_id') ?? 0);
        $toolName = $this->getParam('tool');
        $arguments = json_decode($this->getParam('arguments') ?? '{}', true) ?: [];

        if (empty($connectionId) || empty($toolName)) {
            $this->json(['success' => false, 'message' => 'connection_id and tool required']);
            return;
        }

        try {
            $client = new StitchClient($connectionId);
            $result = $client->callMcpTool($toolName, $arguments);
            $this->json(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Browse projects for a connection
     */
    public function browse($params = []) {
        if (!$this->requireLogin()) return;

        $connectionId = (int)($params[0] ?? 0);
        if (empty($connectionId)) {
            $this->flash('error', 'No connection specified.');
            Flight::redirect('/stitch');
            return;
        }

        try {
            $conn = StitchClient::getConnection($connectionId);
            if (!$conn) {
                $this->flash('error', 'Connection not found.');
                Flight::redirect('/stitch');
                return;
            }

            if ($conn->member_id != $this->member->id && !$conn->shared) {
                $this->flash('error', 'Access denied.');
                Flight::redirect('/stitch');
                return;
            }

            $client = new StitchClient($connectionId);
            $result = $client->listProjects();

            // Parse projects from MCP response
            // structuredContent.projects has the parsed array directly
            $projects = $result['result']['structuredContent']['projects'] ?? [];

            // Fallback: parse from content[].text JSON
            if (empty($projects)) {
                $content = $result['result']['content'] ?? [];
                foreach ($content as $item) {
                    if (isset($item['text'])) {
                        $parsed = json_decode($item['text'], true);
                        if ($parsed) {
                            // Content text may be {"projects": [...]} wrapper
                            if (isset($parsed['projects']) && is_array($parsed['projects'])) {
                                $projects = array_merge($projects, $parsed['projects']);
                            } else {
                                $projects[] = $parsed;
                            }
                        }
                    }
                }
            }

            $this->render('stitch/browse', [
                'title' => 'Stitch Projects',
                'connection' => $conn,
                'connectionId' => $connectionId,
                'projects' => $projects,
            ]);

        } catch (Exception $e) {
            $this->flash('error', 'Failed to load projects: ' . $e->getMessage());
            Flight::redirect('/stitch');
        }
    }

    /**
     * View screens for a project
     */
    public function screens($params = []) {
        if (!$this->requireLogin()) return;

        $connectionId = (int)($params[0] ?? 0);
        $projectId = $params[1] ?? '';

        if (empty($connectionId) || empty($projectId)) {
            $this->flash('error', 'Connection and project ID required.');
            Flight::redirect('/stitch');
            return;
        }

        try {
            $conn = StitchClient::getConnection($connectionId);
            if (!$conn) {
                $this->flash('error', 'Connection not found.');
                Flight::redirect('/stitch');
                return;
            }

            if ($conn->member_id != $this->member->id && !$conn->shared) {
                $this->flash('error', 'Access denied.');
                Flight::redirect('/stitch');
                return;
            }

            $client = new StitchClient($connectionId);

            // Get project info
            $projectResult = $client->getProject("projects/{$projectId}");
            $projectData = $projectResult['result']['structuredContent'] ?? [];
            if (empty($projectData) || !isset($projectData['name'])) {
                foreach (($projectResult['result']['content'] ?? []) as $item) {
                    if (isset($item['text'])) {
                        $parsed = json_decode($item['text'], true);
                        if ($parsed && isset($parsed['name'])) { $projectData = $parsed; break; }
                    }
                }
            }

            // Get screens list from Stitch API
            $screensResult = $client->listScreens($projectId);
            $screens = $screensResult['result']['structuredContent']['screens'] ?? [];
            if (empty($screens)) {
                foreach (($screensResult['result']['content'] ?? []) as $item) {
                    if (isset($item['text'])) {
                        $parsed = json_decode($item['text'], true);
                        if ($parsed) {
                            if (isset($parsed['screens']) && is_array($parsed['screens'])) {
                                $screens = array_merge($screens, $parsed['screens']);
                            } else {
                                $screens[] = $parsed;
                            }
                        }
                    }
                }
            }

            // list_screens often returns empty - check project data for screens
            if (empty($screens) && !empty($projectData['screens'])) {
                $screens = $projectData['screens'];
            }

            // Last resort: check pipeline run output for this project's screens
            if (empty($screens)) {
                $screens = $this->findScreensFromPipelineRuns($connectionId, $projectId);
            }

            $this->render('stitch/screens', [
                'title' => 'Screens - ' . ($projectData['title'] ?? 'Project'),
                'connection' => $conn,
                'connectionId' => $connectionId,
                'projectId' => $projectId,
                'project' => $projectData,
                'screens' => $screens,
            ]);

        } catch (Exception $e) {
            $this->flash('error', 'Failed to load screens: ' . $e->getMessage());
            Flight::redirect("/stitch/browse/{$connectionId}");
        }
    }

    /**
     * View single screen detail
     */
    public function viewscreen($params = []) {
        if (!$this->requireLogin()) return;

        $connectionId = (int)($params[0] ?? 0);
        $projectId = $params[1] ?? '';
        // screenId via query param (FlightPHP only captures 2 path segments after /class/method/)
        $screenId = $_GET['screen'] ?? $params[2] ?? '';

        if (empty($connectionId) || empty($projectId) || empty($screenId)) {
            $this->flash('error', 'Connection, project, and screen ID required.');
            Flight::redirect('/stitch');
            return;
        }

        try {
            $conn = StitchClient::getConnection($connectionId);
            if (!$conn) {
                $this->flash('error', 'Connection not found.');
                Flight::redirect('/stitch');
                return;
            }

            if ($conn->member_id != $this->member->id && !$conn->shared) {
                $this->flash('error', 'Access denied.');
                Flight::redirect('/stitch');
                return;
            }

            $client = new StitchClient($connectionId);
            $name = "projects/{$projectId}/screens/{$screenId}";
            $result = $client->getScreen($name, $projectId, $screenId);

            $screen = $result['result']['structuredContent'] ?? [];
            if (empty($screen) || !isset($screen['name'])) {
                foreach (($result['result']['content'] ?? []) as $item) {
                    if (isset($item['text'])) {
                        $parsed = json_decode($item['text'], true);
                        if ($parsed && isset($parsed['name'])) { $screen = $parsed; break; }
                    }
                }
            }

            $this->render('stitch/viewscreen', [
                'title' => 'Screen - ' . ($screen['title'] ?? 'Untitled'),
                'connection' => $conn,
                'connectionId' => $connectionId,
                'projectId' => $projectId,
                'screenId' => $screenId,
                'screen' => $screen,
            ]);

        } catch (Exception $e) {
            $this->flash('error', 'Failed to load screen: ' . $e->getMessage());
            Flight::redirect("/stitch/screens/{$connectionId}/{$projectId}");
        }
    }

    /**
     * Download HTML code for a screen (proxy with auth)
     */
    public function downloadhtml($params = []) {
        if (!$this->requireLogin()) return;

        $connectionId = (int)($params[0] ?? 0);
        $projectId = $params[1] ?? '';
        $screenId = $_GET['screen'] ?? $params[2] ?? '';

        if (empty($connectionId) || empty($projectId) || empty($screenId)) {
            Flight::jsonError('Connection, project, and screen ID required', 400);
            return;
        }

        try {
            $conn = StitchClient::getConnection($connectionId);
            if (!$conn || ($conn->member_id != $this->member->id && !$conn->shared)) {
                Flight::jsonError('Access denied', 403);
                return;
            }

            $url = $_GET['url'] ?? '';
            $client = new StitchClient($connectionId);
            $result = $this->fetchScreenHtml($client, $projectId, $screenId, $url);

            if (!$result['success']) {
                Flight::jsonError('Download failed: ' . ($result['error'] ?? 'Unknown error'), 502);
                return;
            }

            $title = preg_replace('/[^a-zA-Z0-9_-]/', '_', $result['screen_title'] ?? $screenId);
            header('Content-Type: text/html; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"screen-{$title}.html\"");
            header('Content-Length: ' . strlen($result['content']));
            echo $result['content'];
            exit;

        } catch (Exception $e) {
            Flight::jsonError('Download error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Preview HTML code for a screen in browser (proxy with auth)
     */
    public function previewhtml($params = []) {
        if (!$this->requireLogin()) return;

        $connectionId = (int)($params[0] ?? 0);
        $projectId = $params[1] ?? '';
        $screenId = $_GET['screen'] ?? $params[2] ?? '';

        if (empty($connectionId) || empty($projectId) || empty($screenId)) {
            Flight::jsonError('Connection, project, and screen ID required', 400);
            return;
        }

        try {
            $conn = StitchClient::getConnection($connectionId);
            if (!$conn || ($conn->member_id != $this->member->id && !$conn->shared)) {
                Flight::jsonError('Access denied', 403);
                return;
            }

            $url = $_GET['url'] ?? '';
            $client = new StitchClient($connectionId);
            $result = $this->fetchScreenHtml($client, $projectId, $screenId, $url);

            if (!$result['success']) {
                Flight::jsonError('Download failed: ' . ($result['error'] ?? 'Unknown error'), 502);
                return;
            }

            $backUrl = "/stitch/screens/{$connectionId}/{$projectId}";
            $downloadQs = 'screen=' . urlencode($screenId) . (!empty($url) ? '&url=' . urlencode($url) : '');
            $downloadLink = "/stitch/downloadhtml/{$connectionId}/{$projectId}?{$downloadQs}";

            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Preview</title>';
            echo '<style>body{margin:0} .preview-toolbar{position:fixed;top:0;left:0;right:0;z-index:99999;background:#1a1a2e;padding:8px 16px;display:flex;align-items:center;gap:12px;font-family:system-ui,sans-serif;font-size:14px;color:#fff;box-shadow:0 2px 8px rgba(0,0,0,0.3)}';
            echo '.preview-toolbar a,.preview-toolbar button{color:#fff;text-decoration:none;padding:4px 12px;border-radius:4px;border:1px solid #444;background:transparent;cursor:pointer;font-size:13px}';
            echo '.preview-toolbar a:hover,.preview-toolbar button:hover{background:#333}';
            echo '.preview-content{margin-top:48px}</style></head><body>';
            echo '<div class="preview-toolbar">';
            echo '<span><strong>Preview</strong></span>';
            echo '<a href="' . htmlspecialchars($backUrl) . '">Back to Screens</a>';
            echo '<a href="' . htmlspecialchars($downloadLink) . '">Download HTML</a>';
            echo '</div>';
            echo '<div class="preview-content">';
            echo $result['content'];
            echo '</div></body></html>';
            exit;

        } catch (Exception $e) {
            Flight::jsonError('Preview error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * AJAX endpoint for lazy-loading screen data
     */
    public function screendata($params = []) {
        if (!$this->requireLogin()) return;

        $connectionId = (int)($params[0] ?? 0);
        $projectId = $params[1] ?? '';
        $screenId = $_GET['screen'] ?? $params[2] ?? '';

        if (empty($connectionId) || empty($projectId) || empty($screenId)) {
            $this->json(['success' => false, 'message' => 'Connection, project, and screen ID required']);
            return;
        }

        try {
            $conn = StitchClient::getConnection($connectionId);
            if (!$conn || ($conn->member_id != $this->member->id && !$conn->shared)) {
                $this->json(['success' => false, 'message' => 'Access denied']);
                return;
            }

            $client = new StitchClient($connectionId);
            $name = "projects/{$projectId}/screens/{$screenId}";
            $result = $client->getScreen($name, $projectId, $screenId);

            $screen = $result['result']['structuredContent'] ?? [];
            if (empty($screen) || !isset($screen['name'])) {
                foreach (($result['result']['content'] ?? []) as $item) {
                    if (isset($item['text'])) {
                        $parsed = json_decode($item['text'], true);
                        if ($parsed && isset($parsed['name'])) { $screen = $parsed; break; }
                    }
                }
            }

            $this->json(['success' => true, 'data' => $screen]);

        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Fetch screen HTML via authenticated proxy.
     * Tries cached URL first, falls back to fresh URL from get_screen on 403.
     */
    private function fetchScreenHtml(StitchClient $client, string $projectId, string $screenId, string $cachedUrl = ''): array {
        // Try cached URL first if provided
        if (!empty($cachedUrl)) {
            $result = $client->downloadAuthenticatedUrl($cachedUrl);
            if ($result['success']) {
                return $result;
            }
            // If 403/expired, fall through to fetch fresh URL
            $this->logger->debug('Cached URL failed, fetching fresh URL', ['error' => $result['error'] ?? '']);
        }

        // Fetch screen to get a fresh download URL
        $name = "projects/{$projectId}/screens/{$screenId}";
        $screenResult = $client->getScreen($name, $projectId, $screenId);
        $screen = $screenResult['result']['structuredContent'] ?? [];
        if (empty($screen) || !isset($screen['name'])) {
            foreach (($screenResult['result']['content'] ?? []) as $item) {
                if (isset($item['text'])) {
                    $parsed = json_decode($item['text'], true);
                    if ($parsed && isset($parsed['name'])) { $screen = $parsed; break; }
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

        $result = $client->downloadAuthenticatedUrl($downloadUrl);
        $result['screen_title'] = $screen['title'] ?? '';
        return $result;
    }

    /**
     * Search pipeline step run outputs for screens generated for a project.
     * Fallback when list_screens API returns empty.
     */
    private function findScreensFromPipelineRuns(int $connectionId, string $projectId): array {
        $screens = [];
        $seenIds = [];

        // Find stitch_mcp step runs that generated screens
        // Look at recent completed step runs for this workspace's Stitch pipeline
        $stepRuns = Bean::find('pipelinestepruns',
            ' status = ? ORDER BY id DESC LIMIT 50 ',
            ['success']
        );

        foreach ($stepRuns as $sr) {
            $output = json_decode($sr->output_json ?: '{}', true);
            if (!is_array($output)) continue;

            $sc = $output['output']['structuredContent'] ?? [];
            if (empty($sc['outputComponents'])) continue;

            // Check if this output belongs to the right project
            $outputProjectId = (string)($sc['projectId'] ?? '');
            if (!empty($outputProjectId) && $outputProjectId !== $projectId) continue;

            foreach ($sc['outputComponents'] as $comp) {
                $design = $comp['design'] ?? [];
                if (empty($design['screens'])) continue;

                foreach ($design['screens'] as $screen) {
                    $screenId = $screen['id'] ?? '';
                    if (empty($screenId) || isset($seenIds[$screenId])) continue;

                    // Verify this screen belongs to the target project
                    $screenName = $screen['name'] ?? '';
                    if (!empty($screenName) && !str_contains($screenName, "projects/{$projectId}/")) continue;

                    $seenIds[$screenId] = true;
                    $screens[] = $screen;
                }
            }
        }

        return $screens;
    }

    /**
     * Update GCP project ID for a connection (AJAX)
     */
    public function updateproject($params = []) {
        if (!$this->requireLogin()) return;

        $connectionId = (int)($params[0] ?? $this->getParam('id') ?? 0);
        $gcpProjectId = trim($this->getParam('gcp_project_id') ?? '');

        if (empty($connectionId)) {
            $this->json(['success' => false, 'message' => 'No connection specified']);
            return;
        }

        try {
            $conn = StitchClient::getConnection($connectionId);
            if (!$conn || $conn->member_id != $this->member->id) {
                $this->json(['success' => false, 'message' => 'Access denied']);
                return;
            }

            $metadata = json_decode($conn->metadata_json ?: '{}', true);
            $metadata['gcp_project_id'] = $gcpProjectId;
            $conn->metadata_json = json_encode($metadata);
            $conn->updated_at = date('Y-m-d H:i:s');
            Bean::store($conn);

            $this->json(['success' => true, 'message' => 'GCP Project ID updated']);

        } catch (Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
