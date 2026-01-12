<?php
/**
 * Plugin Registry Controller
 * Displays available plugins with detailed information for informed installation decisions.
 *
 * Routes (auto-routed):
 *   GET /plugins          - List all plugins in registry
 *   GET /plugins/detail/1 - Show plugin detail page
 */

namespace app;

use \Flight as Flight;

class Plugins extends BaseControls\Control {

    /**
     * Sample plugin data for demonstration.
     * NOTE: Future upgrade consideration - Replace with actual registry API integration.
     *
     * @return array
     */
    private function getSamplePlugins(): array {
        return [
            [
                'id' => 1,
                'name' => 'slack-integration',
                'display_name' => 'Slack Integration',
                'description' => 'Integrate your CTO Bot with Slack for real-time notifications and commands. Receive instant alerts when AI developer jobs complete, get PR review summaries directly in your channels, and trigger analysis from Slack.',
                'author' => 'MyCTOBot Team',
                'version' => '2.1.0',
                'tags' => ['communication', 'notifications', 'slack', 'messaging'],
                'last_updated' => '2026-01-10',
                'dependencies' => [
                    ['name' => 'php', 'version' => '>=8.1'],
                    ['name' => 'guzzlehttp/guzzle', 'version' => '^7.0'],
                    ['name' => 'ext-json', 'version' => '*'],
                ],
                'documentation' => "## Installation\n\n1. Navigate to **Settings > Plugins**\n2. Click **Install** on the Slack Integration card\n3. Configure your Slack workspace credentials\n\n## Configuration\n\n### Webhook URL\n\nCreate an incoming webhook in your Slack workspace:\n\n1. Go to [Slack API Apps](https://api.slack.com/apps)\n2. Create a new app or select existing\n3. Enable **Incoming Webhooks**\n4. Copy the webhook URL\n\n### Events\n\nConfigure which events trigger Slack notifications:\n\n- **Job Complete** - Notify when AI developer jobs finish\n- **PR Created** - Alert when new pull requests are opened\n- **Analysis Ready** - Send summaries when board analysis completes\n\n## Usage\n\n### Slash Commands\n\nOnce configured, use these commands in Slack:\n\n```\n/ctobot status - Check current job status\n/ctobot analyze PROJ-123 - Trigger analysis for an issue\n/ctobot help - List available commands\n```\n\n### Message Formatting\n\nNotifications include:\n- Job summary with success/failure status\n- Links to created PRs\n- Code diff statistics\n- Direct links to MyCTOBot dashboard",
                'install_count' => 1250,
                'rating' => 4.8,
                'screenshots' => [
                    '/img/plugins/slack-notification.png',
                    '/img/plugins/slack-commands.png',
                ],
                'changelog' => "### v2.1.0 (2026-01-10)\n- Added slash command support\n- Improved message formatting\n- Fixed webhook retry logic\n\n### v2.0.0 (2025-11-15)\n- Complete rewrite for Slack Bolt framework\n- Added interactive messages\n- Thread support for job updates",
                'license' => 'MIT',
                'homepage' => 'https://github.com/myctobot/slack-integration',
            ],
            [
                'id' => 2,
                'name' => 'github-actions',
                'display_name' => 'GitHub Actions Integration',
                'description' => 'Seamlessly integrate MyCTOBot with your GitHub Actions workflows. Automatically trigger AI analysis on PR events, add review comments, and manage CI/CD pipeline interactions.',
                'author' => 'MyCTOBot Team',
                'version' => '1.5.2',
                'tags' => ['ci-cd', 'github', 'automation', 'devops'],
                'last_updated' => '2026-01-08',
                'dependencies' => [
                    ['name' => 'php', 'version' => '>=8.1'],
                    ['name' => 'guzzlehttp/guzzle', 'version' => '^7.0'],
                ],
                'documentation' => "## Installation\n\n1. Install the plugin from the registry\n2. Add the GitHub App to your organization\n3. Configure webhook events\n\n## Workflow Integration\n\nAdd to your `.github/workflows/ctobot.yml`:\n\n```yaml\nname: CTO Bot Analysis\non:\n  pull_request:\n    types: [opened, synchronize]\n\njobs:\n  analyze:\n    runs-on: ubuntu-latest\n    steps:\n      - uses: myctobot/analyze-action@v1\n        with:\n          api-key: \\${{ secrets.CTOBOT_API_KEY }}\n```\n\n## Features\n\n- Automatic PR analysis on open/update\n- Inline code review comments\n- Status checks integration\n- Custom analysis triggers via workflow dispatch",
                'install_count' => 2340,
                'rating' => 4.9,
                'screenshots' => [],
                'changelog' => "### v1.5.2 (2026-01-08)\n- Fixed status check reporting\n- Added retry logic for API calls",
                'license' => 'MIT',
                'homepage' => 'https://github.com/myctobot/github-actions',
            ],
            [
                'id' => 3,
                'name' => 'jira-enhanced',
                'display_name' => 'Jira Enhanced Sync',
                'description' => 'Advanced Jira integration with bi-directional sync, custom field mapping, and automated workflow transitions. Keep your Jira boards perfectly synchronized with MyCTOBot analysis.',
                'author' => 'MyCTOBot Team',
                'version' => '3.0.1',
                'tags' => ['jira', 'project-management', 'sync', 'atlassian'],
                'last_updated' => '2026-01-05',
                'dependencies' => [
                    ['name' => 'php', 'version' => '>=8.2'],
                    ['name' => 'guzzlehttp/guzzle', 'version' => '^7.0'],
                    ['name' => 'ext-curl', 'version' => '*'],
                ],
                'documentation' => "## Installation\n\n1. Ensure you have Jira Cloud connected in Settings\n2. Install the Jira Enhanced plugin\n3. Configure field mappings\n\n## Field Mapping\n\nMap custom Jira fields to MyCTOBot data:\n\n| Jira Field | MyCTOBot Field |\n|------------|----------------|\n| AI Status | Job Status |\n| PR Link | Pull Request URL |\n| Analysis Score | Code Quality Score |\n\n## Workflow Automation\n\nAutomatically transition issues based on events:\n\n- **PR Created** -> Move to \"In Review\"\n- **PR Merged** -> Move to \"Done\"\n- **Analysis Failed** -> Add comment, keep status\n\n## Sync Options\n\n- Real-time webhook sync\n- Scheduled batch sync (every 15 min)\n- Manual sync trigger",
                'install_count' => 890,
                'rating' => 4.6,
                'screenshots' => [],
                'changelog' => "### v3.0.1 (2026-01-05)\n- Hotfix for OAuth token refresh\n\n### v3.0.0 (2025-12-20)\n- Added bi-directional sync\n- Custom field mapping UI\n- Workflow automation rules",
                'license' => 'MIT',
                'homepage' => 'https://github.com/myctobot/jira-enhanced',
            ],
            [
                'id' => 4,
                'name' => 'code-metrics',
                'display_name' => 'Code Metrics Dashboard',
                'description' => 'Visualize code quality metrics, track technical debt over time, and generate comprehensive reports. Includes complexity analysis, duplication detection, and trend charts.',
                'author' => 'DevMetrics Inc.',
                'version' => '1.2.0',
                'tags' => ['analytics', 'metrics', 'reporting', 'code-quality'],
                'last_updated' => '2025-12-28',
                'dependencies' => [
                    ['name' => 'php', 'version' => '>=8.1'],
                    ['name' => 'ext-gd', 'version' => '*'],
                ],
                'documentation' => "## Installation\n\n1. Install from the plugin registry\n2. Run initial metrics scan\n3. View dashboard at /metrics\n\n## Metrics Tracked\n\n- **Cyclomatic Complexity** - Function and class complexity scores\n- **Code Duplication** - Detected duplicate code blocks\n- **Test Coverage** - Integration with coverage reports\n- **Technical Debt** - Estimated time to fix issues\n\n## Reports\n\nGenerate PDF reports with:\n- Executive summary\n- Trend analysis\n- Hotspot identification\n- Recommendations",
                'install_count' => 456,
                'rating' => 4.3,
                'screenshots' => [],
                'changelog' => "### v1.2.0 (2025-12-28)\n- Added PDF export\n- New trend visualization",
                'license' => 'Apache-2.0',
                'homepage' => 'https://github.com/devmetrics/code-metrics',
            ],
        ];
    }

    /**
     * List all plugins in registry
     */
    public function index($params = []) {
        if (!$this->requireLogin()) return;

        $plugins = $this->getSamplePlugins();

        // Optional filtering by tag
        $tagFilter = $this->getParam('tag');
        if ($tagFilter) {
            $plugins = array_filter($plugins, function($plugin) use ($tagFilter) {
                return in_array($tagFilter, $plugin['tags']);
            });
        }

        // Optional search
        $search = $this->getParam('q');
        if ($search) {
            $searchLower = strtolower($search);
            $plugins = array_filter($plugins, function($plugin) use ($searchLower) {
                return strpos(strtolower($plugin['display_name']), $searchLower) !== false
                    || strpos(strtolower($plugin['description']), $searchLower) !== false
                    || strpos(strtolower($plugin['name']), $searchLower) !== false;
            });
        }

        // Collect all unique tags for filter dropdown
        $allTags = [];
        foreach ($this->getSamplePlugins() as $plugin) {
            foreach ($plugin['tags'] as $tag) {
                $allTags[$tag] = ($allTags[$tag] ?? 0) + 1;
            }
        }
        arsort($allTags);

        $this->viewData['title'] = 'Plugin Registry';
        $this->viewData['plugins'] = array_values($plugins);
        $this->viewData['allTags'] = $allTags;
        $this->viewData['currentTag'] = $tagFilter;
        $this->viewData['searchQuery'] = $search;

        $this->render('plugins/index', $this->viewData);
    }

    /**
     * Show plugin detail page
     */
    public function detail($params = []) {
        if (!$this->requireLogin()) return;

        // Get plugin ID from URL parameter
        $id = (int) ($params['operation']->name ?? $this->getParam('id') ?? 0);

        $plugins = $this->getSamplePlugins();
        $plugin = null;

        foreach ($plugins as $p) {
            if ($p['id'] === $id) {
                $plugin = $p;
                break;
            }
        }

        if (!$plugin) {
            $this->flash('error', 'Plugin not found');
            Flight::redirect('/plugins');
            return;
        }

        // Convert markdown documentation to HTML
        $plugin['documentation_html'] = $this->markdownToHtml($plugin['documentation']);
        $plugin['changelog_html'] = $this->markdownToHtml($plugin['changelog'] ?? '');

        $this->viewData['title'] = $plugin['display_name'] . ' - Plugin Details';
        $this->viewData['plugin'] = $plugin;
        $this->viewData['activeTab'] = $this->getParam('tab', 'overview');

        $this->render('plugins/detail', $this->viewData);
    }

    /**
     * Show plugin detail page (alias for detail method)
     * Supports /plugins/show/{id} URL pattern
     */
    public function show($params = []) {
        return $this->detail($params);
    }
}
