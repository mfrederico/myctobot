<?php
/**
 * McpGatewayToolService - Extracted tool handlers for the /mcp gateway
 *
 * Contains the 13 tool implementations and supporting helpers that were
 * previously in Mcp.php. Instantiated lazily only when a tool is called.
 */

namespace app\services;

use app\Bean;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class McpGatewayToolService
{
    public function __construct(
        private readonly int $memberId,
        private readonly ?object $memberBean,
        private readonly string $workspace,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Dispatch a tool call by name
     */
    public function dispatch(string $name, array $args): mixed
    {
        return match ($name) {
            'jira_get_issue' => $this->jiraGetIssue($args),
            'jira_comment' => $this->jiraComment($args),
            'jira_transition' => $this->jiraTransition($args),
            'jira_get_transitions' => $this->jiraGetTransitions($args),
            'github_get_issue' => $this->githubGetIssue($args),
            'github_comment' => $this->githubComment($args),
            'github_close_issue' => $this->githubCloseIssue($args),
            'github_add_labels' => $this->githubAddLabels($args),
            'github_create_pr' => $this->githubCreatePR($args),
            'gateway_info' => $this->gatewayInfo($args),
            'kb_query' => $this->kbQuery($args),
            'kb_list' => $this->kbList($args),
            'kb_search' => $this->kbSearch($args),
            default => throw new \Exception("Unknown tool: {$name}"),
        };
    }

    // =========================================================================
    // Jira Tools
    // =========================================================================

    private function getJiraClient(): JiraClient
    {
        $token = Bean::findOne('connections', 'connector_type = ? AND (member_id = ? OR shared = 1)', ['atlassian', $this->memberId]);
        if (!$token || !$token->external_eid) {
            throw new \Exception('No Jira connection found. Connect Jira in settings.');
        }
        return new JiraClient($this->memberId, $token->external_eid);
    }

    private function jiraGetIssue(array $args): array
    {
        $issueKey = $args['issue_key'] ?? '';
        if (!$issueKey) throw new \Exception('issue_key required');

        $jira = $this->getJiraClient();
        $issue = $jira->getIssue($issueKey);

        return [
            'key' => $issue['key'],
            'summary' => $issue['fields']['summary'] ?? '',
            'status' => $issue['fields']['status']['name'] ?? '',
            'type' => $issue['fields']['issuetype']['name'] ?? '',
            'priority' => $issue['fields']['priority']['name'] ?? '',
            'assignee' => $issue['fields']['assignee']['displayName'] ?? 'Unassigned',
            'reporter' => $issue['fields']['reporter']['displayName'] ?? '',
            'description' => $issue['fields']['description'] ?? '',
            'created' => $issue['fields']['created'] ?? '',
            'updated' => $issue['fields']['updated'] ?? '',
        ];
    }

    private function jiraComment(array $args): string
    {
        $issueKey = $args['issue_key'] ?? '';
        $message = $args['message'] ?? '';
        if (!$issueKey || !$message) throw new \Exception('issue_key and message required');

        $jira = $this->getJiraClient();
        $jira->addComment($issueKey, $message);

        return "Comment added to {$issueKey}";
    }

    private function jiraTransition(array $args): string
    {
        $issueKey = $args['issue_key'] ?? '';
        $statusName = $args['status_name'] ?? '';
        if (!$issueKey || !$statusName) throw new \Exception('issue_key and status_name required');

        $jira = $this->getJiraClient();
        $jira->transitionIssue($issueKey, $statusName);

        return "Transitioned {$issueKey} to {$statusName}";
    }

    private function jiraGetTransitions(array $args): array
    {
        $issueKey = $args['issue_key'] ?? '';
        if (!$issueKey) throw new \Exception('issue_key required');

        $jira = $this->getJiraClient();
        $transitions = $jira->getTransitions($issueKey);

        return array_map(fn($t) => [
            'id' => $t['id'],
            'name' => $t['name'],
        ], $transitions);
    }

    // =========================================================================
    // GitHub Tools
    // =========================================================================

    private function getGitHubClient(): GitHubClient
    {
        $conn = Bean::findOne('connections', 'connector_type = ? AND (member_id = ? OR shared = 1) ORDER BY id ASC', ['github', $this->memberId]);
        if ($conn && !empty($conn->access_token)) {
            $decrypted = EncryptionService::decrypt($conn->access_token);
            return new GitHubClient($decrypted);
        }

        throw new \Exception('No GitHub connection found. Connect GitHub in settings.');
    }

    private function parseGitHubIssueKey(string $key): array
    {
        if (!preg_match('/^([^\/]+)\/([^#]+)#(\d+)$/', $key, $m)) {
            throw new \Exception("Invalid issue key format. Use: owner/repo#123");
        }
        return ['owner' => $m[1], 'repo' => $m[2], 'number' => (int)$m[3]];
    }

    private function githubGetIssue(array $args): array
    {
        $issueKey = $args['issue_key'] ?? '';
        if (!$issueKey) throw new \Exception('issue_key required');

        $parsed = $this->parseGitHubIssueKey($issueKey);
        $gh = $this->getGitHubClient();
        $issue = $gh->getIssue($parsed['owner'], $parsed['repo'], $parsed['number']);

        return [
            'number' => $issue['number'],
            'title' => $issue['title'],
            'state' => $issue['state'],
            'body' => $issue['body'] ?? '',
            'user' => $issue['user']['login'] ?? '',
            'labels' => array_map(fn($l) => $l['name'], $issue['labels'] ?? []),
            'created_at' => $issue['created_at'],
            'updated_at' => $issue['updated_at'],
        ];
    }

    private function githubComment(array $args): string
    {
        $issueKey = $args['issue_key'] ?? '';
        $message = $args['message'] ?? '';
        if (!$issueKey || !$message) throw new \Exception('issue_key and message required');

        $parsed = $this->parseGitHubIssueKey($issueKey);
        $gh = $this->getGitHubClient();
        $gh->addIssueComment($parsed['owner'], $parsed['repo'], $parsed['number'], $message);

        return "Comment added to {$issueKey}";
    }

    private function githubCloseIssue(array $args): string
    {
        $issueKey = $args['issue_key'] ?? '';
        if (!$issueKey) throw new \Exception('issue_key required');

        $parsed = $this->parseGitHubIssueKey($issueKey);
        $gh = $this->getGitHubClient();

        if (!empty($args['comment'])) {
            $gh->addIssueComment($parsed['owner'], $parsed['repo'], $parsed['number'], $args['comment']);
        }

        $gh->closeIssue($parsed['owner'], $parsed['repo'], $parsed['number']);

        return "Closed {$issueKey}";
    }

    private function githubAddLabels(array $args): string
    {
        $issueKey = $args['issue_key'] ?? '';
        $labels = $args['labels'] ?? [];
        if (!$issueKey || empty($labels)) throw new \Exception('issue_key and labels required');

        $parsed = $this->parseGitHubIssueKey($issueKey);
        $gh = $this->getGitHubClient();
        $gh->addLabels($parsed['owner'], $parsed['repo'], $parsed['number'], $labels);

        return "Added labels to {$issueKey}: " . implode(', ', $labels);
    }

    private function githubCreatePR(array $args): array
    {
        $repo = $args['repo'] ?? '';
        $title = $args['title'] ?? '';
        $head = $args['head'] ?? '';
        $base = $args['base'] ?? 'main';
        $body = $args['body'] ?? '';

        if (!$repo || !$title || !$head) {
            throw new \Exception('repo, title, and head are required');
        }

        if (!preg_match('/^([^\/]+)\/(.+)$/', $repo, $m)) {
            throw new \Exception("Invalid repo format. Use: owner/repo");
        }

        $gh = $this->getGitHubClient();
        $pr = $gh->createPullRequest($m[1], $m[2], $title, $body, $head, $base);

        return [
            'number' => $pr['number'],
            'url' => $pr['html_url'],
            'title' => $pr['title'],
            'state' => $pr['state'],
        ];
    }

    // =========================================================================
    // Gateway Tools
    // =========================================================================

    private function gatewayInfo(array $args): array
    {
        $connections = $this->memberBean ? $this->memberBean->getConnections() : [];

        return [
            'server' => 'MyCTOBot Gateway',
            'version' => '1.0.0',
            'workspace' => $this->workspace,
            'member_id' => $this->memberId,
            'connections' => $connections,
        ];
    }

    // =========================================================================
    // Knowledge Base Tools
    // =========================================================================

    private function kbQuery(array $args): array
    {
        $question = $args['question'] ?? '';
        if (!$question) throw new \Exception('question required');

        $similarityThreshold = $args['similarity_threshold'] ?? 0.4;
        $maxResults = $args['max_results'] ?? 5;

        $store = AssistKnowledgeStore::forWorkspace($this->workspace);
        $result = $store->queryWithAnswer($question, [
            'max_results' => $maxResults,
            'similarity_threshold' => $similarityThreshold,
        ]);

        return [
            'answer' => $result['response'] ?? null,
            'sources' => array_map(fn($s) => [
                'filename' => $s['filename'] ?? '',
                'content' => $s['preview'] ?? $s['content'] ?? '',
                'similarity' => $s['similarity'] ?? $s['score'] ?? 0,
            ], $result['sources'] ?? []),
            'context_used' => $result['context_used'] ?? false,
        ];
    }

    private function kbList(array $args): array
    {
        $kbs = Bean::find('knowledgebases', ' ORDER BY name ASC ');

        return [
            'knowledge_bases' => array_map(fn($kb) => [
                'id' => $kb->id,
                'name' => $kb->name,
                'slug' => $kb->slug,
                'description' => $kb->description ?? '',
                'document_count' => $kb->document_count ?? 0,
            ], $kbs),
            'count' => count($kbs),
        ];
    }

    private function kbSearch(array $args): array
    {
        $query = $args['query'] ?? '';
        if (!$query) throw new \Exception('query required');

        $kbSlug = $args['kb_slug'] ?? null;
        $limit = $args['limit'] ?? 10;

        $client = new \GuzzleHttp\Client(['timeout' => 60]);

        $requestData = [
            'tenant' => $this->workspace,
            'query' => $query,
            'similarity_threshold' => 0.2,
            'max_results' => $limit,
            'include_metadata' => true,
            'search_only' => true
        ];

        if ($kbSlug) {
            $requestData['kb_slug'] = $kbSlug;
        }

        $response = $client->post("{$this->getRagServiceUrl()}/api/query", [
            'json' => $requestData
        ]);

        $result = json_decode($response->getBody()->getContents(), true);

        if (!$result['success']) {
            throw new \Exception($result['error'] ?? 'Search failed');
        }

        return [
            'results' => array_map(fn($s) => [
                'filename' => $s['filename'] ?? '',
                'content' => $s['content'] ?? '',
                'similarity' => $s['similarity'] ?? $s['score'] ?? 0,
                'metadata' => $s['metadata'] ?? [],
            ], $result['sources'] ?? []),
            'count' => count($result['sources'] ?? []),
        ];
    }
}
