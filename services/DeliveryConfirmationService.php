<?php
/**
 * Delivery Confirmation Service
 *
 * Sends acknowledgment notifications to the CEO when directives (AI Developer jobs)
 * are successfully processed or fail. Supports multiple delivery channels:
 * - Email (via MailgunService)
 * - Jira comment
 * - Webhook callback
 *
 * Implements retry logic with exponential backoff for failed delivery attempts.
 */

namespace app\services;

use \Flight as Flight;
use \RedBeanPHP\R as R;

require_once __DIR__ . '/MailgunService.php';
require_once __DIR__ . '/JiraClient.php';
require_once __DIR__ . '/AIDevStatusService.php';

class DeliveryConfirmationService {

    /** @var \Monolog\Logger */
    private $logger;

    /** @var int Maximum retry attempts for failed deliveries */
    const MAX_RETRY_ATTEMPTS = 3;

    /** @var int Base delay in seconds for exponential backoff */
    const BASE_RETRY_DELAY = 60;

    /** Confirmation methods */
    const METHOD_EMAIL = 'email';
    const METHOD_JIRA = 'jira';
    const METHOD_WEBHOOK = 'webhook';

    public function __construct() {
        $this->logger = Flight::get('log');
    }

    /**
     * Send success confirmation when a directive (AI Developer job) completes
     *
     * @param int $memberId Member ID
     * @param string $jobId Job ID
     * @param string $issueKey Jira issue key
     * @param string|null $cloudId Atlassian cloud ID (for Jira comments)
     * @param array $result Job result containing pr_url, pr_number, branch_name, files_changed, summary
     * @return array Result with success status and delivery details
     */
    public function sendSuccessConfirmation(
        int $memberId,
        string $jobId,
        string $issueKey,
        ?string $cloudId,
        array $result
    ): array {
        $this->logger->info('Sending success confirmation', [
            'member_id' => $memberId,
            'job_id' => $jobId,
            'issue_key' => $issueKey
        ]);

        $deliveryResults = [];
        $methods = $this->getEnabledMethods($memberId);
        $timestamp = date('Y-m-d H:i:s');

        // Build confirmation message details
        $details = [
            'job_id' => $jobId,
            'issue_key' => $issueKey,
            'timestamp' => $timestamp,
            'status' => 'success',
            'pr_url' => $result['pr_url'] ?? null,
            'pr_number' => $result['pr_number'] ?? null,
            'branch_name' => $result['branch_name'] ?? null,
            'files_changed' => $result['files_changed'] ?? [],
            'summary' => $result['summary'] ?? 'Implementation complete'
        ];

        // Try each enabled delivery method
        foreach ($methods as $method) {
            try {
                $methodResult = $this->deliverConfirmation($memberId, $cloudId, $issueKey, $details, $method, 'success');
                $deliveryResults[$method] = $methodResult;
            } catch (\Exception $e) {
                $this->logger->error("Confirmation delivery failed via {$method}", [
                    'job_id' => $jobId,
                    'error' => $e->getMessage()
                ]);
                $deliveryResults[$method] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        // Record confirmation attempt in database
        $this->recordConfirmationAttempt($memberId, $jobId, $deliveryResults, 'success');

        return [
            'success' => $this->anyDeliverySucceeded($deliveryResults),
            'delivery_results' => $deliveryResults,
            'timestamp' => $timestamp
        ];
    }

    /**
     * Send failure notification when a directive encounters an unrecoverable error
     *
     * @param int $memberId Member ID
     * @param string $jobId Job ID
     * @param string $issueKey Jira issue key
     * @param string|null $cloudId Atlassian cloud ID
     * @param string $errorMessage Error description
     * @param array $context Additional context about the failure
     * @return array Result with success status and delivery details
     */
    public function sendFailureNotification(
        int $memberId,
        string $jobId,
        string $issueKey,
        ?string $cloudId,
        string $errorMessage,
        array $context = []
    ): array {
        $this->logger->info('Sending failure notification', [
            'member_id' => $memberId,
            'job_id' => $jobId,
            'issue_key' => $issueKey,
            'error' => $errorMessage
        ]);

        $deliveryResults = [];
        $methods = $this->getEnabledMethods($memberId);
        $timestamp = date('Y-m-d H:i:s');

        // Build failure notification details
        $details = [
            'job_id' => $jobId,
            'issue_key' => $issueKey,
            'timestamp' => $timestamp,
            'status' => 'failed',
            'error_message' => $errorMessage,
            'context' => $context,
            'next_steps' => $this->generateNextSteps($errorMessage, $context)
        ];

        // Try each enabled delivery method
        foreach ($methods as $method) {
            try {
                $methodResult = $this->deliverConfirmation($memberId, $cloudId, $issueKey, $details, $method, 'failure');
                $deliveryResults[$method] = $methodResult;
            } catch (\Exception $e) {
                $this->logger->error("Failure notification delivery failed via {$method}", [
                    'job_id' => $jobId,
                    'error' => $e->getMessage()
                ]);
                $deliveryResults[$method] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        // Record notification attempt in database
        $this->recordConfirmationAttempt($memberId, $jobId, $deliveryResults, 'failure');

        return [
            'success' => $this->anyDeliverySucceeded($deliveryResults),
            'delivery_results' => $deliveryResults,
            'timestamp' => $timestamp
        ];
    }

    /**
     * Retry failed confirmation deliveries with exponential backoff
     *
     * @param int|null $memberId Optional member ID to filter retries (null = all members)
     * @return array Summary of retry results
     */
    public function retryFailedDeliveries(?int $memberId = null): array {
        $this->logger->info('Processing confirmation delivery retry queue', [
            'member_id' => $memberId ?? 'all'
        ]);

        // Find jobs with failed confirmation attempts that haven't exceeded max retries
        $sql = 'confirmation_sent_at IS NULL AND confirmation_attempts < ? AND confirmation_attempts > 0';
        $params = [self::MAX_RETRY_ATTEMPTS];

        if ($memberId !== null) {
            $sql .= ' AND member_id = ?';
            $params[] = $memberId;
        }

        $jobs = R::find('aidevjobs', $sql, $params);

        $results = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'skipped' => 0,
            'details' => []
        ];

        foreach ($jobs as $job) {
            $jobId = $job->job_id;
            $attempts = (int) $job->confirmation_attempts;

            // Check if enough time has passed for exponential backoff
            $lastAttempt = strtotime($job->updated_at);
            $requiredDelay = self::BASE_RETRY_DELAY * pow(2, $attempts - 1);

            if (time() - $lastAttempt < $requiredDelay) {
                $results['skipped']++;
                continue;
            }

            $results['processed']++;

            // Determine if this was a success or failure job and retry accordingly
            $isSuccessJob = in_array($job->status, [
                AIDevStatusService::STATUS_COMPLETE,
                AIDevStatusService::STATUS_PR_CREATED
            ]);

            try {
                if ($isSuccessJob) {
                    $retryResult = $this->sendSuccessConfirmation(
                        (int) $job->member_id,
                        $jobId,
                        $job->issue_key,
                        $job->cloud_id,
                        [
                            'pr_url' => $job->pr_url,
                            'pr_number' => $job->pr_number,
                            'branch_name' => $job->branch_name,
                            'files_changed' => json_decode($job->files_changed ?: '[]', true),
                            'summary' => 'Implementation complete'
                        ]
                    );
                } else {
                    $retryResult = $this->sendFailureNotification(
                        (int) $job->member_id,
                        $jobId,
                        $job->issue_key,
                        $job->cloud_id,
                        $job->error_message ?? 'Unknown error'
                    );
                }

                if ($retryResult['success']) {
                    $results['succeeded']++;
                } else {
                    $results['failed']++;
                }

                $results['details'][$jobId] = $retryResult;

            } catch (\Exception $e) {
                $results['failed']++;
                $results['details'][$jobId] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];

                $this->logger->error('Retry failed for job', [
                    'job_id' => $jobId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Log jobs that have exceeded max retries for manual follow-up
        $this->logExceededRetryJobs($memberId);

        return $results;
    }

    /**
     * Deliver confirmation via specific method
     */
    private function deliverConfirmation(
        int $memberId,
        ?string $cloudId,
        string $issueKey,
        array $details,
        string $method,
        string $type
    ): array {
        switch ($method) {
            case self::METHOD_EMAIL:
                return $this->deliverViaEmail($memberId, $details, $type);

            case self::METHOD_JIRA:
                if ($cloudId) {
                    return $this->deliverViaJira($memberId, $cloudId, $issueKey, $details, $type);
                }
                return ['success' => false, 'error' => 'No cloud ID available for Jira delivery'];

            case self::METHOD_WEBHOOK:
                return $this->deliverViaWebhook($memberId, $details, $type);

            default:
                return ['success' => false, 'error' => "Unknown delivery method: {$method}"];
        }
    }

    /**
     * Deliver confirmation via email using MailgunService
     */
    private function deliverViaEmail(int $memberId, array $details, string $type): array {
        $mailgun = new MailgunService();

        if (!$mailgun->isEnabled()) {
            return ['success' => false, 'error' => 'Email service not configured'];
        }

        // Get recipient email from settings
        $recipientEmail = $this->getConfirmationEmail($memberId);
        if (!$recipientEmail) {
            return ['success' => false, 'error' => 'No confirmation email configured'];
        }

        // Build email content
        $subject = $type === 'success'
            ? "MyCTOBot: Directive Completed - {$details['issue_key']}"
            : "MyCTOBot: Directive Failed - {$details['issue_key']}";

        $content = $this->buildEmailContent($details, $type);

        try {
            $result = $mailgun->sendMarkdownEmail($subject, $content, $recipientEmail);
            return ['success' => $result, 'method' => 'email', 'recipient' => $recipientEmail];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Deliver confirmation via Jira comment
     */
    private function deliverViaJira(
        int $memberId,
        string $cloudId,
        string $issueKey,
        array $details,
        string $type
    ): array {
        try {
            $jiraClient = new JiraClient($memberId, $cloudId);

            // Build ADF content for Jira comment
            $adfContent = $this->buildJiraAdfContent($details, $type);

            $comment = $jiraClient->addCommentAdf($issueKey, $adfContent);

            return [
                'success' => true,
                'method' => 'jira',
                'comment_id' => $comment['id'] ?? null
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Deliver confirmation via webhook callback
     */
    private function deliverViaWebhook(int $memberId, array $details, string $type): array {
        $webhookUrl = $this->getWebhookUrl($memberId);

        if (!$webhookUrl) {
            return ['success' => false, 'error' => 'No webhook URL configured'];
        }

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 30]);

            $payload = [
                'event' => $type === 'success' ? 'directive.completed' : 'directive.failed',
                'timestamp' => $details['timestamp'],
                'data' => $details
            ];

            // Get webhook secret if configured
            $webhookSecret = $this->getWebhookSecret($memberId);
            $headers = ['Content-Type' => 'application/json'];

            if ($webhookSecret) {
                $signature = hash_hmac('sha256', json_encode($payload), $webhookSecret);
                $headers['X-Webhook-Signature'] = $signature;
            }

            $response = $client->post($webhookUrl, [
                'json' => $payload,
                'headers' => $headers
            ]);

            $statusCode = $response->getStatusCode();
            $success = $statusCode >= 200 && $statusCode < 300;

            return [
                'success' => $success,
                'method' => 'webhook',
                'status_code' => $statusCode
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Build email content from details
     */
    private function buildEmailContent(array $details, string $type): string {
        if ($type === 'success') {
            $content = "# Directive Completed Successfully\n\n";
            $content .= "**Issue:** {$details['issue_key']}\n";
            $content .= "**Job ID:** {$details['job_id']}\n";
            $content .= "**Timestamp:** {$details['timestamp']}\n\n";

            if (!empty($details['pr_url'])) {
                $prDisplay = $details['pr_number'] ? "PR #{$details['pr_number']}" : 'Pull Request';
                $content .= "**Pull Request:** [{$prDisplay}]({$details['pr_url']})\n";
            }

            if (!empty($details['branch_name'])) {
                $content .= "**Branch:** `{$details['branch_name']}`\n";
            }

            if (!empty($details['summary'])) {
                $content .= "\n## Summary\n{$details['summary']}\n";
            }

            if (!empty($details['files_changed'])) {
                $fileCount = count($details['files_changed']);
                $content .= "\n## Files Changed ({$fileCount})\n";
                foreach (array_slice($details['files_changed'], 0, 10) as $file) {
                    $content .= "- `{$file}`\n";
                }
                if ($fileCount > 10) {
                    $content .= "- ...and " . ($fileCount - 10) . " more files\n";
                }
            }
        } else {
            $content = "# Directive Failed\n\n";
            $content .= "**Issue:** {$details['issue_key']}\n";
            $content .= "**Job ID:** {$details['job_id']}\n";
            $content .= "**Timestamp:** {$details['timestamp']}\n\n";
            $content .= "## Error\n{$details['error_message']}\n";

            if (!empty($details['next_steps'])) {
                $content .= "\n## Recommended Next Steps\n";
                foreach ($details['next_steps'] as $step) {
                    $content .= "- {$step}\n";
                }
            }
        }

        return $content;
    }

    /**
     * Build Jira ADF content for comment
     */
    private function buildJiraAdfContent(array $details, string $type): array {
        $content = [];

        if ($type === 'success') {
            // Header
            $content[] = [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'MyCTOBot - Delivery Confirmation', 'marks' => [['type' => 'strong']]]
                ]
            ];

            // Status indicator
            $content[] = [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Status: ', 'marks' => [['type' => 'strong']]],
                    ['type' => 'text', 'text' => 'Directive processed successfully']
                ]
            ];

            // Details list
            $listItems = [];

            $listItems[] = [
                'type' => 'listItem',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Job ID: ', 'marks' => [['type' => 'strong']]],
                        ['type' => 'text', 'text' => $details['job_id'], 'marks' => [['type' => 'code']]]
                    ]
                ]]
            ];

            $listItems[] = [
                'type' => 'listItem',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Processed at: ', 'marks' => [['type' => 'strong']]],
                        ['type' => 'text', 'text' => $details['timestamp']]
                    ]
                ]]
            ];

            if (!empty($details['pr_url'])) {
                $prText = $details['pr_number'] ? "PR #{$details['pr_number']}" : 'View Pull Request';
                $listItems[] = [
                    'type' => 'listItem',
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => 'Pull Request: ', 'marks' => [['type' => 'strong']]],
                            ['type' => 'text', 'text' => $prText, 'marks' => [['type' => 'link', 'attrs' => ['href' => $details['pr_url']]]]]
                        ]
                    ]]
                ];
            }

            $content[] = [
                'type' => 'bulletList',
                'content' => $listItems
            ];

        } else {
            // Failure notification
            $content[] = [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'MyCTOBot - Delivery Confirmation (Failed)', 'marks' => [['type' => 'strong']]]
                ]
            ];

            $content[] = [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Status: ', 'marks' => [['type' => 'strong']]],
                    ['type' => 'text', 'text' => 'Directive processing failed']
                ]
            ];

            $content[] = [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Error: ', 'marks' => [['type' => 'strong']]],
                    ['type' => 'text', 'text' => $details['error_message']]
                ]
            ];

            if (!empty($details['next_steps'])) {
                $content[] = [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Recommended Next Steps:', 'marks' => [['type' => 'strong']]]
                    ]
                ];

                $stepItems = [];
                foreach ($details['next_steps'] as $step) {
                    $stepItems[] = [
                        'type' => 'listItem',
                        'content' => [[
                            'type' => 'paragraph',
                            'content' => [['type' => 'text', 'text' => $step]]
                        ]]
                    ];
                }

                $content[] = [
                    'type' => 'bulletList',
                    'content' => $stepItems
                ];
            }
        }

        return [
            'type' => 'doc',
            'version' => 1,
            'content' => $content
        ];
    }

    /**
     * Generate recommended next steps based on error type
     */
    private function generateNextSteps(string $errorMessage, array $context): array {
        $steps = [];
        $errorLower = strtolower($errorMessage);

        if (strpos($errorLower, 'clarification') !== false || strpos($errorLower, 'unclear') !== false) {
            $steps[] = 'Review the ticket description and add more specific requirements';
            $steps[] = 'Check if any attached files or screenshots are missing';
            $steps[] = 'Re-add the ai-dev label once clarification is provided';
        } elseif (strpos($errorLower, 'auth') !== false || strpos($errorLower, 'permission') !== false) {
            $steps[] = 'Verify GitHub repository access permissions';
            $steps[] = 'Check that the API token has required scopes';
            $steps[] = 'Contact administrator if access issues persist';
        } elseif (strpos($errorLower, 'conflict') !== false || strpos($errorLower, 'merge') !== false) {
            $steps[] = 'Pull latest changes from the main branch';
            $steps[] = 'Resolve any merge conflicts manually';
            $steps[] = 'Re-trigger the AI Developer job';
        } elseif (strpos($errorLower, 'timeout') !== false) {
            $steps[] = 'Consider breaking the task into smaller subtasks';
            $steps[] = 'Check if the repository is exceptionally large';
            $steps[] = 'Try triggering the job during off-peak hours';
        } else {
            $steps[] = 'Review the error message for specific issues';
            $steps[] = 'Check the job logs for more details';
            $steps[] = 'Contact support if the issue persists';
        }

        return $steps;
    }

    /**
     * Record confirmation attempt in the database
     */
    private function recordConfirmationAttempt(int $memberId, string $jobId, array $deliveryResults, string $type): void {
        $job = R::findOne('aidevjobs', 'job_id = ? AND member_id = ?', [$jobId, $memberId]);

        if (!$job) {
            $this->logger->warning('Job not found for confirmation recording', [
                'job_id' => $jobId,
                'member_id' => $memberId
            ]);
            return;
        }

        $anySuccess = $this->anyDeliverySucceeded($deliveryResults);
        $attempts = (int) ($job->confirmation_attempts ?? 0) + 1;
        $successMethods = [];
        $lastError = null;

        foreach ($deliveryResults as $method => $result) {
            if ($result['success'] ?? false) {
                $successMethods[] = $method;
            } else {
                $lastError = $result['error'] ?? 'Unknown error';
            }
        }

        $job->confirmation_attempts = $attempts;

        if ($anySuccess) {
            $job->confirmation_sent_at = date('Y-m-d H:i:s');
            $job->confirmation_method = implode(',', $successMethods);
            $job->confirmation_last_error = null;
        } else {
            $job->confirmation_last_error = $lastError;
        }

        $job->updated_at = date('Y-m-d H:i:s');
        R::store($job);

        // Log the attempt
        AIDevStatusService::log($jobId, $memberId, $anySuccess ? 'info' : 'warning',
            "Delivery confirmation attempt {$attempts}: " . ($anySuccess ? 'succeeded' : 'failed'), [
                'type' => $type,
                'methods_tried' => array_keys($deliveryResults),
                'methods_succeeded' => $successMethods,
                'last_error' => $lastError
            ]
        );
    }

    /**
     * Log jobs that have exceeded max retry attempts for manual follow-up
     */
    private function logExceededRetryJobs(?int $memberId): void {
        $sql = 'confirmation_sent_at IS NULL AND confirmation_attempts >= ?';
        $params = [self::MAX_RETRY_ATTEMPTS];

        if ($memberId !== null) {
            $sql .= ' AND member_id = ?';
            $params[] = $memberId;
        }

        $exceededJobs = R::find('aidevjobs', $sql, $params);

        foreach ($exceededJobs as $job) {
            $this->logger->error('Delivery confirmation exceeded max retries - requires manual follow-up', [
                'job_id' => $job->job_id,
                'member_id' => $job->member_id,
                'issue_key' => $job->issue_key,
                'attempts' => $job->confirmation_attempts,
                'last_error' => $job->confirmation_last_error
            ]);
        }
    }

    /**
     * Check if any delivery method succeeded
     */
    private function anyDeliverySucceeded(array $deliveryResults): bool {
        foreach ($deliveryResults as $result) {
            if ($result['success'] ?? false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get enabled confirmation methods for a member
     */
    private function getEnabledMethods(int $memberId): array {
        $methods = [];

        // Check Flight config for global defaults
        $emailEnabled = Flight::get('confirmation.email_enabled') ?? true;
        $jiraEnabled = Flight::get('confirmation.jira_enabled') ?? true;
        $webhookEnabled = Flight::get('confirmation.webhook_enabled') ?? false;

        // Check member-specific settings (override globals)
        $emailSetting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?',
            ['confirmation_email_enabled', $memberId]);
        $jiraSetting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?',
            ['confirmation_jira_enabled', $memberId]);
        $webhookSetting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?',
            ['confirmation_webhook_enabled', $memberId]);

        if ($emailSetting) {
            $emailEnabled = $emailSetting->setting_value === '1';
        }
        if ($jiraSetting) {
            $jiraEnabled = $jiraSetting->setting_value === '1';
        }
        if ($webhookSetting) {
            $webhookEnabled = $webhookSetting->setting_value === '1';
        }

        if ($emailEnabled) {
            $methods[] = self::METHOD_EMAIL;
        }
        if ($jiraEnabled) {
            $methods[] = self::METHOD_JIRA;
        }
        if ($webhookEnabled) {
            $methods[] = self::METHOD_WEBHOOK;
        }

        // Default to Jira if nothing is enabled
        if (empty($methods)) {
            $methods[] = self::METHOD_JIRA;
        }

        return $methods;
    }

    /**
     * Get confirmation email address for a member
     */
    private function getConfirmationEmail(int $memberId): ?string {
        // Check member-specific setting first
        $setting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?',
            ['confirmation_recipient_email', $memberId]);

        if ($setting && $setting->setting_value) {
            return $setting->setting_value;
        }

        // Fall back to member's email
        $member = R::load('member', $memberId);
        if ($member && $member->email) {
            return $member->email;
        }

        // Fall back to global config
        return Flight::get('confirmation.recipient_email');
    }

    /**
     * Get webhook URL for a member
     */
    private function getWebhookUrl(int $memberId): ?string {
        $setting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?',
            ['confirmation_webhook_url', $memberId]);

        return $setting ? $setting->setting_value : Flight::get('confirmation.webhook_url');
    }

    /**
     * Get webhook secret for a member
     */
    private function getWebhookSecret(int $memberId): ?string {
        $setting = R::findOne('enterprisesettings', 'setting_key = ? AND member_id = ?',
            ['confirmation_webhook_secret', $memberId]);

        if ($setting && $setting->setting_value) {
            return EncryptionService::decrypt($setting->setting_value);
        }

        return Flight::get('confirmation.webhook_secret');
    }
}
