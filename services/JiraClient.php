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

        $fields = 'summary,description,status,priority,assignee,reporter,created,updated,issuetype,labels,customfield_10016,comment,attachment';

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

    /**
     * Get image attachments from an issue
     * Returns array of image info with base64 data
     *
     * @param array $issue Issue data with 'fields' containing 'attachment'
     * @param int $maxImages Maximum images to fetch (default 3)
     * @param int $maxSizeKb Maximum image size in KB (default 1024 = 1MB)
     * @return array Array of ['filename' => string, 'mimeType' => string, 'base64' => string]
     */
    public function getIssueImages(array $issue, int $maxImages = 3, int $maxSizeKb = 1024): array {
        $attachments = $issue['fields']['attachment'] ?? [];
        if (empty($attachments)) {
            return [];
        }

        // Filter to only image attachments
        $imageTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp'];
        $images = [];

        foreach ($attachments as $attachment) {
            if (count($images) >= $maxImages) {
                break;
            }

            $mimeType = $attachment['mimeType'] ?? '';
            $size = $attachment['size'] ?? 0;

            // Check if it's an image and within size limit
            if (!in_array($mimeType, $imageTypes)) {
                continue;
            }

            if ($size > ($maxSizeKb * 1024)) {
                continue; // Skip images that are too large
            }

            // Download the image
            $imageData = $this->downloadAttachment($attachment['content'] ?? '');
            if ($imageData) {
                $images[] = [
                    'filename' => $attachment['filename'] ?? 'image',
                    'mimeType' => $mimeType,
                    'base64' => base64_encode($imageData),
                    'size' => $size
                ];
            }
        }

        return $images;
    }

    /**
     * Download an attachment from Jira
     *
     * @param string $url Attachment URL
     * @return string|null Binary image data or null on failure
     */
    private function downloadAttachment(string $url): ?string {
        if (empty($url)) {
            return null;
        }

        try {
            $response = $this->client->get($url);
            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            // Log error but don't fail the whole analysis
            if (Flight::has('log')) {
                Flight::log()->warning('Failed to download Jira attachment', [
                    'url' => $url,
                    'error' => $e->getMessage()
                ]);
            }
            return null;
        }
    }

    /**
     * Extract image URLs from ADF content (inline images)
     * These are images embedded in the description, not attachments
     *
     * @param array|null $adf ADF content
     * @return array Array of image URLs
     */
    public static function extractImagesFromAdf($adf): array {
        if (empty($adf) || !is_array($adf) || !isset($adf['content'])) {
            return [];
        }

        $images = [];
        self::findImagesInContent($adf['content'], $images);
        return $images;
    }

    private static function findImagesInContent(array $content, array &$images): void {
        foreach ($content as $block) {
            if (isset($block['type']) && $block['type'] === 'mediaSingle') {
                // Found a media block
                if (isset($block['content'])) {
                    foreach ($block['content'] as $media) {
                        if (isset($media['type']) && $media['type'] === 'media') {
                            $images[] = [
                                'id' => $media['attrs']['id'] ?? null,
                                'collection' => $media['attrs']['collection'] ?? null,
                                'type' => $media['attrs']['type'] ?? 'file'
                            ];
                        }
                    }
                }
            }

            // Recurse into nested content
            if (isset($block['content'])) {
                self::findImagesInContent($block['content'], $images);
            }
        }
    }
}
