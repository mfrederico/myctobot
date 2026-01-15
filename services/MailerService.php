<?php
/**
 * Generic Mailer Service
 * Abstracts email sending across different providers (Mailgun, SendGrid, SES, etc.)
 */

namespace app\services;

use \Flight as Flight;

// Load available email providers
require_once __DIR__ . '/MailgunService.php';

class MailerService {
    private ?object $provider = null;
    private string $providerName = 'none';

    public function __construct() {
        // Determine which provider to use based on configuration
        $this->initializeProvider();
    }

    /**
     * Initialize the email provider based on available configuration
     */
    private function initializeProvider(): void {
        // Check for Mailgun configuration first
        if (MailgunService::isConfigured()) {
            $this->provider = new MailgunService();
            $this->providerName = 'mailgun';
            return;
        }

        // Future: Add other providers here
        // if (SendGridService::isConfigured()) { ... }
        // if (SESService::isConfigured()) { ... }

        Flight::get('log')->warning('No email provider configured');
    }

    /**
     * Check if email sending is enabled
     */
    public function isEnabled(): bool {
        return $this->provider !== null && $this->provider->isEnabled();
    }

    /**
     * Get the active provider name
     */
    public function getProviderName(): string {
        return $this->providerName;
    }

    /**
     * Send an email
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $html HTML content
     * @param string|null $text Plain text content (optional, auto-generated from HTML if not provided)
     * @param string|null $cc Comma-separated CC recipients (optional)
     * @return bool Success
     */
    public function send(string $to, string $subject, string $html, ?string $text = null, ?string $cc = null): bool {
        if (!$this->isEnabled()) {
            Flight::get('log')->warning('Email not sent - no provider configured', [
                'to' => $to,
                'subject' => $subject
            ]);
            return false;
        }

        try {
            return $this->provider->send($to, $subject, $html, $text, $cc);
        } catch (\Exception $e) {
            Flight::get('log')->error('Failed to send email', [
                'provider' => $this->providerName,
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send an email with markdown content
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $markdown Markdown content (converted to HTML)
     * @param string|null $cc Comma-separated CC recipients (optional)
     * @return bool Success
     */
    public function sendMarkdown(string $to, string $subject, string $markdown, ?string $cc = null): bool {
        if (!$this->isEnabled()) {
            return false;
        }

        // Use provider's markdown method if available, otherwise convert manually
        if (method_exists($this->provider, 'sendMarkdownEmail')) {
            return $this->provider->sendMarkdownEmail($subject, $markdown, $to, $cc);
        }

        // Basic markdown to HTML conversion fallback
        $html = $this->markdownToHtml($markdown);
        return $this->send($to, $subject, $html, $markdown, $cc);
    }

    /**
     * Basic markdown to HTML conversion
     */
    private function markdownToHtml(string $markdown): string {
        $html = htmlspecialchars($markdown);

        // Headers
        $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);

        // Bold and italic
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);

        // Links
        $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $html);

        // Line breaks
        $html = nl2br($html);

        return $html;
    }

    /**
     * Check if any email provider is configured
     */
    public static function isConfigured(): bool {
        // Check Mailgun
        if (MailgunService::isConfigured()) {
            return true;
        }

        // Future: Check other providers
        // if (SendGridService::isConfigured()) return true;

        return false;
    }
}
