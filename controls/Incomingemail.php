<?php
/**
 * Incoming Email Controller
 * Handles incoming email webhooks from Mailgun for CEO directives
 */

namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;
use \app\Bean;
use \app\services\UserDatabaseService;
use \app\services\CeoDirectiveService;
use \app\TenantResolver;

require_once __DIR__ . '/../services/UserDatabaseService.php';
require_once __DIR__ . '/../services/CeoDirectiveService.php';

class Incomingemail extends BaseControls\Control {

    /**
     * Handle Mailgun incoming email webhook
     * Endpoint: POST /webhook/mailgun/incoming
     *
     * Mailgun sends POST with form data containing:
     * - recipient: the email address receiving the message
     * - sender: email address of the sender
     * - from: full from header (name + email)
     * - subject: email subject
     * - body-plain: plain text body
     * - body-html: HTML body (if available)
     * - stripped-text: body without quoted parts
     * - Message-Id: unique message ID
     * - timestamp: Unix timestamp
     * - token: Mailgun token
     * - signature: HMAC signature for verification
     */
    public function mailgun() {
        // Debug: Log raw input
        $rawInput = file_get_contents('php://input');
        $this->logger->debug('Mailgun raw input', [
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'not set',
            'post_count' => count($_POST),
            'raw_length' => strlen($rawInput),
            'raw_preview' => substr($rawInput, 0, 500)
        ]);

        // Get POST data from Mailgun
        $timestamp = $_POST['timestamp'] ?? '';
        $token = $_POST['token'] ?? '';
        $signature = $_POST['signature'] ?? '';

        // Extract email data
        $recipient = $_POST['recipient'] ?? '';
        $sender = $_POST['sender'] ?? '';
        $from = $_POST['from'] ?? $sender;
        $subject = $_POST['subject'] ?? '';
        $bodyPlain = $_POST['body-plain'] ?? '';
        $bodyHtml = $_POST['body-html'] ?? '';
        $strippedText = $_POST['stripped-text'] ?? $bodyPlain;
        $messageId = $_POST['Message-Id'] ?? '';

        // Parse tenant from recipient email (e.g., gwt@myctobot.ai -> gwt)
        $tenant = $this->parseTenantFromRecipient($recipient);

        $this->logger->info('Mailgun webhook received', [
            'recipient' => $recipient,
            'sender' => $sender,
            'subject' => $subject,
            'tenant' => $tenant
        ]);

        // Verify authentication - either via query key or Mailgun signature
        $queryKey = $_GET['key'] ?? null;
        $mailgunConfig = $this->loadMailgunConfig();
        $configuredKey = $mailgunConfig['webhook_key'] ?? null;

        if ($queryKey && $configuredKey) {
            // Key-based authentication
            if ($queryKey !== $configuredKey) {
                $this->logger->warning('Mailgun webhook: invalid key');
                Flight::response()->status(401);
                echo json_encode(['error' => 'Invalid key']);
                return;
            }
        } elseif (!empty($mailgunConfig['key'])) {
            // Fall back to Mailgun signature verification
            if (!$this->verifyMailgunSignature($mailgunConfig['key'], $timestamp, $token, $signature)) {
                $this->logger->warning('Mailgun webhook: invalid signature', [
                    'recipient' => $recipient,
                    'sender' => $sender
                ]);
                Flight::response()->status(401);
                echo json_encode(['error' => 'Invalid signature']);
                return;
            }
        }

        // Switch to tenant database if tenant found
        if ($tenant) {
            if (!$this->switchToTenantForWebhook($tenant)) {
                $this->logger->warning("Invalid tenant for incoming email: {$tenant}");
                Flight::response()->status(400);
                echo json_encode(['error' => "Invalid tenant: {$tenant}"]);
                return;
            }
        } else {
            $this->logger->warning('No tenant found in recipient email', [
                'recipient' => $recipient
            ]);
            Flight::response()->status(400);
            echo json_encode(['error' => 'No tenant found in recipient email']);
            return;
        }

        // Find member by sender email
        $member = R::findOne('member', 'email = ?', [$sender]);
        if (!$member) {
            // Try to find by the email in "from" header
            preg_match('/<([^>]+)>/', $from, $matches);
            $fromEmail = $matches[1] ?? $sender;
            $member = R::findOne('member', 'email = ?', [$fromEmail]);
        }

        if (!$member) {
            $this->logger->warning('Rejected email from non-workspace member', [
                'sender' => $sender,
                'from' => $from,
                'tenant' => $tenant
            ]);
            // Return 200 so Mailgun stops retrying, but don't process the email
            Flight::response()->status(200);
            echo json_encode([
                'success' => false,
                'rejected' => true,
                'message' => "Email from '{$sender}' rejected. Only workspace members can send directives to this address."
            ]);
            return;
        }

        $memberId = $member->id;

        // Parse approval mode from subject
        // [AUTO] = auto-execute, [REVIEW] = require approval
        $approvalMode = 'auto'; // default
        if (preg_match('/\[REVIEW\]/i', $subject)) {
            $approvalMode = 'manual';
            $subject = trim(preg_replace('/\[REVIEW\]/i', '', $subject));
        } elseif (preg_match('/\[AUTO\]/i', $subject)) {
            $approvalMode = 'auto';
            $subject = trim(preg_replace('/\[AUTO\]/i', '', $subject));
        }

        // Create the directive record
        try {
            $directiveId = $this->generateDirectiveId();

            $directive = Bean::dispense('ceodirectives');
            $directive->directive_uid = $directiveId;
            $directive->member_id = $memberId;
            $directive->email_from = $from;
            $directive->email_subject = $subject;
            $directive->email_body = $strippedText ?: $bodyPlain;
            $directive->email_message_id = $messageId;
            $directive->approval_mode = $approvalMode;
            $directive->status = 'received';
            $directive->created_at = date('Y-m-d H:i:s');

            Bean::store($directive);

            // Log the directive creation
            CeoDirectiveService::logDirectiveEvent($directive->id, 'received', 'info', 'Directive received from email', [
                'from' => $from,
                'subject' => $subject,
                'approval_mode' => $approvalMode
            ]);

            $this->logger->info('CEO directive created', [
                'directive_uid' => $directiveId,
                'member_id' => $memberId,
                'subject' => $subject,
                'approval_mode' => $approvalMode
            ]);

            // Return success to Mailgun
            Flight::response()->status(200);
            echo json_encode([
                'success' => true,
                'directive_uid' => $directiveId
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to create directive from email', [
                'error' => $e->getMessage(),
                'sender' => $sender,
                'subject' => $subject
            ]);
            Flight::response()->status(500);
            echo json_encode(['error' => 'Failed to process email']);
        }
    }

    /**
     * Load Mailgun config from conf/mailgun.ini
     */
    private function loadMailgunConfig(): array {
        $iniPath = __DIR__ . '/../conf/mailgun.ini';
        if (file_exists($iniPath)) {
            $config = parse_ini_file($iniPath);
            if ($config) {
                return $config;
            }
        }
        return [];
    }

    /**
     * Parse tenant from recipient email
     * Examples:
     *   gwt@myctobot.ai -> gwt
     *   ceo@gwt.myctobot.ai -> gwt
     *   directive@testcorp.myctobot.ai -> testcorp
     */
    private function parseTenantFromRecipient(string $recipient): ?string {
        // Pattern 1: {tenant}@myctobot.ai
        if (preg_match('/^([a-z0-9_-]+)@myctobot\.ai$/i', $recipient, $matches)) {
            return strtolower($matches[1]);
        }

        // Pattern 2: {anything}@{tenant}.myctobot.ai
        if (preg_match('/@([a-z0-9_-]+)\.myctobot\.ai$/i', $recipient, $matches)) {
            return strtolower($matches[1]);
        }

        // Pattern 3: Check configured domain in tenant configs
        // Extract domain from recipient and check if it matches a tenant
        if (preg_match('/@(.+)$/i', $recipient, $matches)) {
            $domain = strtolower($matches[1]);
            // Look for config files matching the domain
            $configFiles = glob(__DIR__ . '/../conf/config.*.ini');
            foreach ($configFiles as $configFile) {
                $tenantName = preg_replace('/^.*config\.(.+)\.ini$/', '$1', $configFile);
                $config = parse_ini_file($configFile, true);
                $tenantDomain = $config['directives']['email_domain'] ?? null;
                if ($tenantDomain && strtolower($tenantDomain) === $domain) {
                    return $tenantName;
                }
            }
        }

        return null;
    }

    /**
     * Verify Mailgun webhook signature
     */
    private function verifyMailgunSignature(string $apiKey, string $timestamp, string $token, string $signature): bool {
        if (empty($timestamp) || empty($token) || empty($signature)) {
            return false;
        }

        // Check timestamp is within 5 minutes to prevent replay attacks
        $now = time();
        if (abs($now - (int)$timestamp) > 300) {
            $this->logger->warning('Mailgun signature: timestamp too old', [
                'timestamp' => $timestamp,
                'now' => $now
            ]);
            return false;
        }

        // Verify signature
        $expectedSignature = hash_hmac('sha256', $timestamp . $token, $apiKey);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Switch to tenant database for webhook processing
     */
    private function switchToTenantForWebhook(string $tenant): bool {
        return TenantResolver::switchDatabase($tenant);
    }

    /**
     * Generate a unique directive ID
     */
    private function generateDirectiveId(): string {
        return bin2hex(random_bytes(16));
    }
}
