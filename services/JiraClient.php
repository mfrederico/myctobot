<?php
/**
 * Jira API Client Service
 * OAuth-based Jira API client for MyCTOBot
 */

namespace app\services;

use \Flight as Flight;
use \app\plugins\AtlassianAuth;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class JiraClient {
    private Client $client;
    private string $cloudId;
    private int $memberId;
    private string $baseUrl;
    private string $agileUrl;

    public function __construct(int $memberId, string $cloudId) {
        $this->memberId = $memberId;
        $this->cloudId = $cloudId;

        // Get valid access token
        $accessToken = AtlassianAuth::getValidToken($memberId, $cloudId);
        if (!$accessToken) {
            throw new \Exception("No valid Atlassian token for member {$memberId}, cloud {$cloudId}");
        }

        $this->baseUrl = AtlassianAuth::getApiBaseUrl($cloudId);
        $this->agileUrl = AtlassianAuth::getAgileApiBaseUrl($cloudId);

        $this->client = new Client([
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Get all boards
     */
    public function getBoards(): array {
        $response = $this->client->get($this->agileUrl . '/board');
        $data = json_decode($response->getBody()->getContents(), true);
        return $data['values'] ?? [];
    }

    /**
     * Get all active sprints for a board
     */
    public function getActiveSprints(int $boardId): array {
        $response = $this->client->get($this->agileUrl . "/board/{$boardId}/sprint", [
            'query' => ['state' => 'active'],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['values'] ?? [];
    }

    /**
     * Get all issues in the current sprint using JQL search
     * Uses standard Jira REST API (works with read:jira-work scope)
     *
     * @param string $projectKey Project key (e.g., "SSI")
     * @param array $statusFilter Optional array of status names to filter
     */
    public function getCurrentSprintIssues(string $projectKey, array $statusFilter = []): array {
        // Build JQL to get issues in open sprints for this project
        $jql = "project = {$projectKey} AND sprint in openSprints()";

        // Add status filter if specified
        if (!empty($statusFilter)) {
            $statusList = '"' . implode('","', $statusFilter) . '"';
            $jql .= " AND status IN ({$statusList})";
        }

        $jql .= " ORDER BY priority DESC, created ASC";

        $issues = [];
        $startAt = 0;
        $maxResults = 50;

        $fields = 'summary,description,status,priority,assignee,reporter,created,updated,issuetype,labels,customfield_10016,comment';

        do {
            $response = $this->client->get($this->baseUrl . '/search/jql', [
                'query' => [
                    'jql' => $jql,
                    'startAt' => $startAt,
                    'maxResults' => $maxResults,
                    'fields' => $fields,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $issues = array_merge($issues, $data['issues'] ?? []);
            $startAt += $maxResults;
        } while ($startAt < ($data['total'] ?? 0));

        return $issues;
    }

    /**
     * Get a single issue by key
     */
    public function getIssue(string $issueKey): array {
        $response = $this->client->get($this->baseUrl . "/issue/{$issueKey}", [
            'query' => [
                'fields' => 'summary,description,status,priority,assignee,reporter,created,updated,issuetype,labels,customfield_10016,comment,subtasks',
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Search for issues using JQL
     */
    public function searchIssues(string $jql, int $maxResults = 50): array {
        $response = $this->client->get($this->baseUrl . '/search/jql', [
            'query' => [
                'jql' => $jql,
                'maxResults' => $maxResults,
                'fields' => 'summary,description,status,priority,assignee,issuetype,labels',
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['issues'] ?? [];
    }

    /**
     * Get sprint details
     */
    public function getSprintDetails(int $sprintId): array {
        $response = $this->client->get($this->agileUrl . "/sprint/{$sprintId}");
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get project details
     */
    public function getProject(string $projectKey): array {
        $response = $this->client->get($this->baseUrl . "/project/{$projectKey}");
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Extract plain text from Jira's Atlassian Document Format (ADF)
     */
    public static function extractTextFromAdf($adf): string {
        if (empty($adf)) {
            return '';
        }

        // Handle plain string descriptions (older Jira format)
        if (is_string($adf)) {
            return $adf;
        }

        // Handle ADF format
        if (!is_array($adf) || !isset($adf['content'])) {
            return '';
        }

        $text = '';
        foreach ($adf['content'] as $block) {
            $text .= self::extractTextFromBlock($block) . "\n";
        }

        return trim($text);
    }

    private static function extractTextFromBlock(array $block): string {
        $text = '';

        if (isset($block['text'])) {
            $text .= $block['text'];
        }

        if (isset($block['content'])) {
            foreach ($block['content'] as $child) {
                $text .= self::extractTextFromBlock($child);
            }
        }

        return $text;
    }

    /**
     * Get cloud ID
     */
    public function getCloudId(): string {
        return $this->cloudId;
    }
}
