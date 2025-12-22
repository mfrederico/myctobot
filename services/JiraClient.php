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
                'fields' => 'summary,description,status,priority,assignee,reporter,created,updated,issuetype,labels,customfield_10016,comment,subtasks,attachment',
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

    // ========================================
    // Write Methods (Enterprise tier - requires write:jira-work scope)
    // ========================================

    /**
     * Add a comment to an issue
     * Requires write:jira-work scope
     *
     * @param string $issueKey Issue key (e.g., "PROJ-123")
     * @param string $body Comment body in plain text (will be converted to ADF)
     * @return array Comment data
     */
    public function addComment(string $issueKey, string $body): array {
        // Convert plain text to ADF format
        $adfBody = self::textToAdf($body);

        $response = $this->client->post($this->baseUrl . "/issue/{$issueKey}/comment", [
            'json' => [
                'body' => $adfBody
            ]
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Add a comment to an issue with ADF formatting
     * Requires write:jira-work scope
     *
     * @param string $issueKey Issue key
     * @param array $adfBody ADF formatted body
     * @return array Comment data
     */
    public function addCommentAdf(string $issueKey, array $adfBody): array {
        $response = $this->client->post($this->baseUrl . "/issue/{$issueKey}/comment", [
            'json' => [
                'body' => $adfBody
            ]
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Update an existing comment
     * Requires write:jira-work scope
     *
     * @param string $issueKey Issue key
     * @param string $commentId Comment ID
     * @param string $body New comment body
     * @return array Updated comment data
     */
    public function updateComment(string $issueKey, string $commentId, string $body): array {
        $adfBody = self::textToAdf($body);

        $response = $this->client->put($this->baseUrl . "/issue/{$issueKey}/comment/{$commentId}", [
            'json' => [
                'body' => $adfBody
            ]
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Delete a comment
     * Requires write:jira-work scope
     *
     * @param string $issueKey Issue key
     * @param string $commentId Comment ID
     */
    public function deleteComment(string $issueKey, string $commentId): void {
        $this->client->delete($this->baseUrl . "/issue/{$issueKey}/comment/{$commentId}");
    }

    // ========================================
    // Issue Transitions
    // ========================================

    /**
     * Execute a transition on an issue by transition ID
     * Requires write:jira-work scope
     *
     * @param string $issueKey Issue key
     * @param string $transitionId Transition ID
     * @param array $fields Optional fields to set during transition
     * @return bool Success
     */
    public function doTransition(string $issueKey, string $transitionId, array $fields = []): bool {
        $body = [
            'transition' => ['id' => $transitionId]
        ];

        if (!empty($fields)) {
            $body['fields'] = $fields;
        }

        $response = $this->client->post($this->baseUrl . "/issue/{$issueKey}/transitions", [
            'json' => $body
        ]);

        return $response->getStatusCode() === 204;
    }

    /**
     * Transition an issue to a target status by name
     * Looks up the transition ID and executes it
     *
     * @param string $issueKey Issue key
     * @param string $targetStatusName Target status name (e.g., "In Progress", "Ready for QA")
     * @return array Result with 'success', 'message', 'from_status', 'to_status'
     */
    public function transitionToStatus(string $issueKey, string $targetStatusName): array {
        try {
            // Get current issue status for logging
            $issue = $this->getIssue($issueKey, ['status']);
            $currentStatus = $issue['fields']['status']['name'] ?? 'Unknown';

            // If already in target status, skip
            if (strcasecmp($currentStatus, $targetStatusName) === 0) {
                return [
                    'success' => true,
                    'message' => 'Already in target status',
                    'from_status' => $currentStatus,
                    'to_status' => $targetStatusName,
                    'skipped' => true
                ];
            }

            // Get available transitions
            $transitions = $this->getTransitions($issueKey);

            // Find transition that leads to target status
            $targetTransition = null;
            foreach ($transitions as $transition) {
                $toStatusName = $transition['to']['name'] ?? '';
                if (strcasecmp($toStatusName, $targetStatusName) === 0) {
                    $targetTransition = $transition;
                    break;
                }
            }

            if (!$targetTransition) {
                // List available statuses for debugging
                $availableStatuses = array_map(function($t) {
                    return $t['to']['name'] ?? 'Unknown';
                }, $transitions);

                return [
                    'success' => false,
                    'message' => "Cannot transition to '{$targetStatusName}' from '{$currentStatus}'",
                    'from_status' => $currentStatus,
                    'to_status' => $targetStatusName,
                    'available_statuses' => $availableStatuses
                ];
            }

            // Execute the transition
            $success = $this->doTransition($issueKey, $targetTransition['id']);

            return [
                'success' => $success,
                'message' => $success ? 'Transition successful' : 'Transition failed',
                'from_status' => $currentStatus,
                'to_status' => $targetStatusName,
                'transition_id' => $targetTransition['id'],
                'transition_name' => $targetTransition['name']
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'from_status' => $currentStatus ?? 'Unknown',
                'to_status' => $targetStatusName
            ];
        }
    }

    /**
     * Get all statuses available for a project
     *
     * @param string $projectKey Project key
     * @return array Array of status objects
     */
    public function getProjectStatuses(string $projectKey): array {
        $response = $this->client->get($this->baseUrl . "/project/{$projectKey}/statuses");
        $data = json_decode($response->getBody()->getContents(), true);

        // Flatten the statuses from all issue types
        $statuses = [];
        $seen = [];
        foreach ($data as $issueType) {
            foreach ($issueType['statuses'] ?? [] as $status) {
                $name = $status['name'];
                if (!isset($seen[$name])) {
                    $statuses[] = $status;
                    $seen[$name] = true;
                }
            }
        }

        return $statuses;
    }

    /**
     * Get all comments for an issue
     *
     * @param string $issueKey Issue key
     * @return array Array of comments
     */
    public function getComments(string $issueKey): array {
        $response = $this->client->get($this->baseUrl . "/issue/{$issueKey}/comment");
        $data = json_decode($response->getBody()->getContents(), true);
        return $data['comments'] ?? [];
    }

    /**
     * Add a label to an issue
     * Requires write:jira-work scope
     *
     * @param string $issueKey Issue key
     * @param string $label Label to add
     * @return array Updated issue data
     */
    public function addLabel(string $issueKey, string $label): array {
        $response = $this->client->put($this->baseUrl . "/issue/{$issueKey}", [
            'json' => [
                'update' => [
                    'labels' => [
                        ['add' => $label]
                    ]
                ]
            ]
        ]);

        // Return updated issue
        return $this->getIssue($issueKey);
    }

    /**
     * Remove a label from an issue
     * Requires write:jira-work scope
     *
     * @param string $issueKey Issue key
     * @param string $label Label to remove
     * @return array Updated issue data
     */
    public function removeLabel(string $issueKey, string $label): array {
        $response = $this->client->put($this->baseUrl . "/issue/{$issueKey}", [
            'json' => [
                'update' => [
                    'labels' => [
                        ['remove' => $label]
                    ]
                ]
            ]
        ]);

        // Return updated issue
        return $this->getIssue($issueKey);
    }

    /**
     * Update issue fields
     * Requires write:jira-work scope
     *
     * @param string $issueKey Issue key
     * @param array $fields Fields to update
     * @return array Updated issue data
     */
    public function updateIssue(string $issueKey, array $fields): array {
        $this->client->put($this->baseUrl . "/issue/{$issueKey}", [
            'json' => [
                'fields' => $fields
            ]
        ]);

        return $this->getIssue($issueKey);
    }

    /**
     * Transition an issue to a new status
     * Requires write:jira-work scope
     *
     * @param string $issueKey Issue key
     * @param string $transitionId Transition ID
     * @param array $fields Optional fields to update during transition
     */
    public function transitionIssue(string $issueKey, string $transitionId, array $fields = []): void {
        $payload = [
            'transition' => ['id' => $transitionId]
        ];

        if (!empty($fields)) {
            $payload['fields'] = $fields;
        }

        $this->client->post($this->baseUrl . "/issue/{$issueKey}/transitions", [
            'json' => $payload
        ]);
    }

    /**
     * Get available transitions for an issue
     *
     * @param string $issueKey Issue key
     * @return array Available transitions
     */
    public function getTransitions(string $issueKey): array {
        $response = $this->client->get($this->baseUrl . "/issue/{$issueKey}/transitions");
        $data = json_decode($response->getBody()->getContents(), true);
        return $data['transitions'] ?? [];
    }

    /**
     * Upload a file as an attachment to an issue
     *
     * @param string $issueKey Issue key
     * @param string $filePath Path to the file to upload
     * @return array Attachment data from Jira
     * @throws \Exception If upload fails
     */
    public function uploadAttachment(string $issueKey, string $filePath): array {
        if (!file_exists($filePath)) {
            throw new \Exception("File not found: {$filePath}");
        }

        $response = $this->client->post(
            $this->baseUrl . "/issue/{$issueKey}/attachments",
            [
                'headers' => [
                    'X-Atlassian-Token' => 'no-check'
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($filePath, 'r'),
                        'filename' => basename($filePath)
                    ]
                ]
            ]
        );

        $data = json_decode($response->getBody()->getContents(), true);
        return $data[0] ?? $data;
    }

    // ========================================
    // ADF Helper Methods
    // ========================================

    /**
     * Convert plain text to Atlassian Document Format (ADF)
     *
     * @param string $text Plain text content
     * @return array ADF document
     */
    public static function textToAdf(string $text): array {
        $paragraphs = preg_split('/\n\n+/', $text);

        $content = [];
        foreach ($paragraphs as $para) {
            if (empty(trim($para))) {
                continue;
            }

            // Split by single newlines within paragraphs
            $lines = explode("\n", $para);
            $paraContent = [];

            foreach ($lines as $i => $line) {
                if ($i > 0) {
                    $paraContent[] = ['type' => 'hardBreak'];
                }
                $paraContent[] = ['type' => 'text', 'text' => $line];
            }

            $content[] = [
                'type' => 'paragraph',
                'content' => $paraContent
            ];
        }

        return [
            'type' => 'doc',
            'version' => 1,
            'content' => $content
        ];
    }

    /**
     * Create ADF with formatting (bold, italic, links, etc.)
     *
     * @param array $elements Array of elements with formatting
     * @return array ADF document
     */
    public static function createAdf(array $elements): array {
        return [
            'type' => 'doc',
            'version' => 1,
            'content' => $elements
        ];
    }

    /**
     * Create a paragraph element for ADF
     */
    public static function adfParagraph(array $content): array {
        return [
            'type' => 'paragraph',
            'content' => $content
        ];
    }

    /**
     * Create a text element for ADF
     *
     * @param string $text Text content
     * @param array $marks Optional marks (bold, italic, link, etc.)
     */
    public static function adfText(string $text, array $marks = []): array {
        $element = ['type' => 'text', 'text' => $text];
        if (!empty($marks)) {
            $element['marks'] = $marks;
        }
        return $element;
    }

    /**
     * Create a bold mark for ADF
     */
    public static function adfBold(): array {
        return ['type' => 'strong'];
    }

    /**
     * Create an italic mark for ADF
     */
    public static function adfItalic(): array {
        return ['type' => 'em'];
    }

    /**
     * Create a link mark for ADF
     */
    public static function adfLink(string $url): array {
        return ['type' => 'link', 'attrs' => ['href' => $url]];
    }

    /**
     * Create a code block for ADF
     */
    public static function adfCodeBlock(string $code, string $language = ''): array {
        $block = [
            'type' => 'codeBlock',
            'content' => [
                ['type' => 'text', 'text' => $code]
            ]
        ];

        if ($language) {
            $block['attrs'] = ['language' => $language];
        }

        return $block;
    }

    /**
     * Create a bullet list for ADF
     */
    public static function adfBulletList(array $items): array {
        $listItems = [];
        foreach ($items as $item) {
            $listItems[] = [
                'type' => 'listItem',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => is_string($item)
                            ? [['type' => 'text', 'text' => $item]]
                            : $item
                    ]
                ]
            ];
        }

        return [
            'type' => 'bulletList',
            'content' => $listItems
        ];
    }

    /**
     * Create a heading for ADF
     *
     * @param string $text Heading text
     * @param int $level Heading level (1-6)
     */
    public static function adfHeading(string $text, int $level = 1): array {
        return [
            'type' => 'heading',
            'attrs' => ['level' => min(6, max(1, $level))],
            'content' => [
                ['type' => 'text', 'text' => $text]
            ]
        ];
    }
}
