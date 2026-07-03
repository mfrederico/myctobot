<?php
/**
 * PluginRepositoryService
 * Handles validation and operations for plugin repository sources
 * Part of the Plugin Registry and Discovery epic
 */

namespace app\services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use \Flight as Flight;

class PluginRepositoryService {

    private Client $client;

    public function __construct() {
        $this->client = new Client([
            'timeout' => 15,
            'verify' => true
        ]);
    }

    /**
     * Validate a plugin repository source
     * Checks if the source is accessible and valid
     *
     * @param \RedBeanPHP\OODBBean $source The source bean to validate
     * @return array ['valid' => bool, 'error' => string|null, 'details' => array]
     */
    public function validateSource($source): array {
        $provider = $source->provider;
        $repositoryUrl = $source->repository_url;
        $accessToken = null;

        // Decrypt access token if present
        if (!empty($source->access_token)) {
            require_once __DIR__ . '/EncryptionService.php';
            try {
                $accessToken = EncryptionService::decrypt($source->access_token);
            } catch (\Exception $e) {
                return [
                    'valid' => false,
                    'error' => 'Failed to decrypt access token',
                    'details' => []
                ];
            }
        }

        switch ($provider) {
            case 'github':
                return $this->validateGitHubSource($repositoryUrl, $accessToken);
            case 'gitlab':
                return $this->validateGitLabSource($repositoryUrl, $accessToken);
            case 'bitbucket':
                return $this->validateBitbucketSource($repositoryUrl, $accessToken);
            case 'git':
                return $this->validateGenericGitSource($repositoryUrl, $accessToken);
            default:
                return [
                    'valid' => false,
                    'error' => 'Unsupported provider: ' . $provider,
                    'details' => []
                ];
        }
    }

    /**
     * Validate a GitHub repository or organization
     *
     * @param string $url Repository URL (owner/repo or organization name)
     * @param string|null $token Access token for private repos
     * @return array Validation result
     */
    public function validateGitHubSource(string $url, ?string $token = null): array {
        $parsed = $this->parseRepositoryUrl($url);

        if (!$parsed) {
            return [
                'valid' => false,
                'error' => 'Invalid GitHub URL format. Use owner/repo or https://github.com/owner/repo',
                'details' => []
            ];
        }

        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'MyCTOBot/1.0'
        ];

        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        try {
            // Check if it's an organization or a repository
            $owner = $parsed['owner'];
            $repo = $parsed['repo'] ?? null;

            if ($repo) {
                // Validate specific repository
                $response = $this->client->get("https://api.github.com/repos/{$owner}/{$repo}", [
                    'headers' => $headers
                ]);
                $data = json_decode($response->getBody()->getContents(), true);

                return [
                    'valid' => true,
                    'error' => null,
                    'details' => [
                        'type' => 'repository',
                        'name' => $data['full_name'],
                        'description' => $data['description'] ?? '',
                        'default_branch' => $data['default_branch'] ?? 'main',
                        'private' => $data['private'] ?? false,
                        'stars' => $data['stargazers_count'] ?? 0
                    ]
                ];
            } else {
                // Validate organization
                $response = $this->client->get("https://api.github.com/orgs/{$owner}", [
                    'headers' => $headers
                ]);
                $data = json_decode($response->getBody()->getContents(), true);

                return [
                    'valid' => true,
                    'error' => null,
                    'details' => [
                        'type' => 'organization',
                        'name' => $data['login'],
                        'description' => $data['description'] ?? '',
                        'public_repos' => $data['public_repos'] ?? 0
                    ]
                ];
            }

        } catch (GuzzleException $e) {
            $statusCode = $e->getCode();
            $errorMessage = $this->getGitHubErrorMessage($statusCode, $e->getMessage());

            return [
                'valid' => false,
                'error' => $errorMessage,
                'details' => ['status_code' => $statusCode]
            ];
        }
    }

    /**
     * Validate a GitLab repository or group
     *
     * @param string $url Repository URL
     * @param string|null $token Access token
     * @return array Validation result
     */
    public function validateGitLabSource(string $url, ?string $token = null): array {
        $parsed = $this->parseGitLabUrl($url);

        if (!$parsed) {
            return [
                'valid' => false,
                'error' => 'Invalid GitLab URL format',
                'details' => []
            ];
        }

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'MyCTOBot/1.0'
        ];

        if ($token) {
            $headers['PRIVATE-TOKEN'] = $token;
        }

        try {
            $baseUrl = $parsed['base_url'] ?? 'https://gitlab.com';
            $projectPath = urlencode($parsed['path']);

            // SECURITY (MED SSRF): self-hosted GitLab base_url comes from a
            // user-supplied git URL — block internal/metadata targets.
            \app\services\EgressGuard::assertAllowed($baseUrl);

            $response = $this->client->get("{$baseUrl}/api/v4/projects/{$projectPath}", [
                'headers' => $headers,
                'allow_redirects' => false
            ]);
            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'valid' => true,
                'error' => null,
                'details' => [
                    'type' => 'repository',
                    'name' => $data['path_with_namespace'],
                    'description' => $data['description'] ?? '',
                    'default_branch' => $data['default_branch'] ?? 'main',
                    'visibility' => $data['visibility'] ?? 'private'
                ]
            ];

        } catch (GuzzleException $e) {
            return [
                'valid' => false,
                'error' => $this->getGenericErrorMessage($e->getCode(), $e->getMessage()),
                'details' => ['status_code' => $e->getCode()]
            ];
        }
    }

    /**
     * Validate a Bitbucket repository
     *
     * @param string $url Repository URL
     * @param string|null $token Access token (app password)
     * @return array Validation result
     */
    public function validateBitbucketSource(string $url, ?string $token = null): array {
        $parsed = $this->parseRepositoryUrl($url);

        if (!$parsed || empty($parsed['repo'])) {
            return [
                'valid' => false,
                'error' => 'Invalid Bitbucket URL format. Use workspace/repo',
                'details' => []
            ];
        }

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'MyCTOBot/1.0'
        ];

        // Bitbucket uses Basic Auth with app passwords
        $auth = null;
        if ($token) {
            // Token format should be "username:app_password"
            $auth = explode(':', $token, 2);
            if (count($auth) !== 2) {
                return [
                    'valid' => false,
                    'error' => 'Invalid Bitbucket credentials format. Use username:app_password',
                    'details' => []
                ];
            }
        }

        try {
            $options = ['headers' => $headers];
            if ($auth) {
                $options['auth'] = $auth;
            }

            $workspace = $parsed['owner'];
            $repo = $parsed['repo'];

            $response = $this->client->get("https://api.bitbucket.org/2.0/repositories/{$workspace}/{$repo}", $options);
            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'valid' => true,
                'error' => null,
                'details' => [
                    'type' => 'repository',
                    'name' => $data['full_name'],
                    'description' => $data['description'] ?? '',
                    'is_private' => $data['is_private'] ?? false
                ]
            ];

        } catch (GuzzleException $e) {
            return [
                'valid' => false,
                'error' => $this->getGenericErrorMessage($e->getCode(), $e->getMessage()),
                'details' => ['status_code' => $e->getCode()]
            ];
        }
    }

    /**
     * Validate a generic Git repository (checks if accessible)
     *
     * @param string $url Git repository URL
     * @param string|null $token Not used for generic git
     * @return array Validation result
     */
    public function validateGenericGitSource(string $url, ?string $token = null): array {
        // For generic git URLs, we can only do basic validation
        // Full validation would require git ls-remote which needs shell access

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return [
                'valid' => false,
                'error' => 'Invalid URL format',
                'details' => []
            ];
        }

        // Check if URL ends with .git or looks like a git URL
        if (!preg_match('/\.git$|git@|\/\/git\./', $url)) {
            return [
                'valid' => false,
                'error' => 'URL does not appear to be a Git repository',
                'details' => []
            ];
        }

        // Try to make a HEAD request to check accessibility
        try {
            // Convert git:// to https:// for HTTP check
            $httpUrl = preg_replace('/^git:\/\//', 'https://', $url);
            $httpUrl = preg_replace('/^git@([^:]+):/', 'https://$1/', $httpUrl);

            $this->client->head($httpUrl, [
                'timeout' => 10,
                'allow_redirects' => true
            ]);

            return [
                'valid' => true,
                'error' => null,
                'details' => [
                    'type' => 'repository',
                    'name' => basename($url, '.git'),
                    'note' => 'Basic URL check passed. Full validation requires git access.'
                ]
            ];

        } catch (GuzzleException $e) {
            // Not all git repos respond to HTTP HEAD, so treat timeout/connection errors differently
            $code = $e->getCode();
            if ($code >= 500 || $code === 0) {
                return [
                    'valid' => true,
                    'error' => null,
                    'details' => [
                        'type' => 'repository',
                        'name' => basename($url, '.git'),
                        'note' => 'URL format valid. Server may require authentication.'
                    ]
                ];
            }

            return [
                'valid' => false,
                'error' => 'Repository not accessible: ' . $this->getGenericErrorMessage($code, $e->getMessage()),
                'details' => ['status_code' => $code]
            ];
        }
    }

    /**
     * Parse a repository URL to extract owner and repo
     * Supports formats:
     * - owner/repo
     * - https://github.com/owner/repo
     * - https://github.com/owner/repo.git
     * - git@github.com:owner/repo.git
     *
     * @param string $url Repository URL or path
     * @return array|null ['owner' => string, 'repo' => string|null]
     */
    public function parseRepositoryUrl(string $url): ?array {
        $url = trim($url);

        // Handle simple owner/repo format
        if (preg_match('/^([a-zA-Z0-9_-]+)\/([a-zA-Z0-9_.-]+)$/', $url, $matches)) {
            return [
                'owner' => $matches[1],
                'repo' => rtrim($matches[2], '.git')
            ];
        }

        // Handle simple owner format (organization)
        if (preg_match('/^([a-zA-Z0-9_-]+)$/', $url, $matches)) {
            return [
                'owner' => $matches[1],
                'repo' => null
            ];
        }

        // Handle HTTPS URLs
        if (preg_match('/https?:\/\/[^\/]+\/([a-zA-Z0-9_-]+)(?:\/([a-zA-Z0-9_.-]+))?/', $url, $matches)) {
            return [
                'owner' => $matches[1],
                'repo' => isset($matches[2]) ? rtrim($matches[2], '.git') : null
            ];
        }

        // Handle SSH URLs (git@github.com:owner/repo.git)
        if (preg_match('/git@[^:]+:([a-zA-Z0-9_-]+)\/([a-zA-Z0-9_.-]+)/', $url, $matches)) {
            return [
                'owner' => $matches[1],
                'repo' => rtrim($matches[2], '.git')
            ];
        }

        return null;
    }

    /**
     * Parse a GitLab URL to extract base URL and project path
     *
     * @param string $url GitLab URL
     * @return array|null
     */
    private function parseGitLabUrl(string $url): ?array {
        $url = trim($url);

        // Handle gitlab.com URLs
        if (preg_match('/https?:\/\/(gitlab\.com)\/(.+?)(?:\.git)?$/', $url, $matches)) {
            return [
                'base_url' => 'https://' . $matches[1],
                'path' => rtrim($matches[2], '/')
            ];
        }

        // Handle self-hosted GitLab URLs
        if (preg_match('/https?:\/\/([^\/]+)\/(.+?)(?:\.git)?$/', $url, $matches)) {
            return [
                'base_url' => 'https://' . $matches[1],
                'path' => rtrim($matches[2], '/')
            ];
        }

        // Handle simple path format for gitlab.com
        if (preg_match('/^([a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_.-]+)+)$/', $url, $matches)) {
            return [
                'base_url' => 'https://gitlab.com',
                'path' => $matches[1]
            ];
        }

        return null;
    }

    /**
     * Get a user-friendly error message for GitHub API errors
     *
     * @param int $statusCode HTTP status code
     * @param string $rawMessage Raw error message
     * @return string User-friendly error message
     */
    private function getGitHubErrorMessage(int $statusCode, string $rawMessage): string {
        switch ($statusCode) {
            case 401:
                return 'Authentication failed. Please check your access token.';
            case 403:
                return 'Access forbidden. You may have hit the rate limit or lack permissions.';
            case 404:
                return 'Repository or organization not found. Please check the URL and ensure you have access.';
            case 422:
                return 'Invalid request. Please check the URL format.';
            default:
                if ($statusCode >= 500) {
                    return 'GitHub API is currently unavailable. Please try again later.';
                }
                return 'Validation failed: ' . $rawMessage;
        }
    }

    /**
     * Get a generic user-friendly error message
     *
     * @param int $statusCode HTTP status code
     * @param string $rawMessage Raw error message
     * @return string User-friendly error message
     */
    private function getGenericErrorMessage(int $statusCode, string $rawMessage): string {
        switch ($statusCode) {
            case 401:
                return 'Authentication required or failed.';
            case 403:
                return 'Access forbidden. Please check your permissions.';
            case 404:
                return 'Repository not found.';
            case 0:
                return 'Connection failed. Please check the URL.';
            default:
                if ($statusCode >= 500) {
                    return 'Server error. Please try again later.';
                }
                return 'Validation failed (HTTP ' . $statusCode . ')';
        }
    }
}
