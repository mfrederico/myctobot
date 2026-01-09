<?php
/**
 * Mailgun Email Service
 * Handles sending emails via Mailgun API
 */

namespace app\services;

use \Flight as Flight;
use GuzzleHttp\Client;

class MailgunService {
    private ?Client $client = null;
    private string $domain;
    private string $fromEmail;
    private string $fromName;
    private bool $enabled;

    public function __construct() {
        // Try conf/mailgun.ini first, then fall back to Flight config
        $config = $this->loadMailgunConfig();

        $apiKey = $config['key'] ?? '';
        $this->domain = $config['domain'] ?? '';
        $this->fromEmail = $config['fromEmail'] ?? 'noreply@myctobot.ai';
        $this->fromName = $config['fromName'] ?? 'MyCTOBot';

        $this->enabled = !empty($apiKey) && !empty($this->domain);

        if ($this->enabled) {
            $endpoint = $config['endpoint'] ?? 'https://api.mailgun.net';
            $this->client = new Client([
                'base_uri' => $endpoint . '/v3/',
                'auth' => ['api', $apiKey],
            ]);
        }
    }

    /**
     * Load Mailgun config from tenant config or conf/mailgun.ini fallback
     */
    private function loadMailgunConfig(): array {
        // Try tenant/Flight config first
        $apiKey = Flight::get('mailgun.api_key') ?? '';
        $domain = Flight::get('mailgun.domain') ?? '';

        if (!empty($apiKey) && !empty($domain)) {
            return [
                'key' => $apiKey,
                'domain' => $domain,
                'fromEmail' => Flight::get('mailgun.from_email') ?? '',
                'fromName' => Flight::get('mailgun.from_name') ?? '',
                'endpoint' => Flight::get('mailgun.endpoint') ?? '',
            ];
        }

        // Fall back to conf/mailgun.ini
        $iniPath = dirname(__DIR__) . '/conf/mailgun.ini';
        if (file_exists($iniPath)) {
            $config = parse_ini_file($iniPath);
            if ($config && !empty($config['key']) && !empty($config['domain'])) {
                return $config;
            }
        }

        return [];
    }

    public function isEnabled(): bool {
        return $this->enabled;
    }

    /**
     * Send an email with markdown content converted to HTML
     *
     * @param string $subject Email subject
     * @param string $markdownContent Markdown content to convert and send
     * @param string $to Primary recipient email
     * @param string|null $cc Comma-separated CC recipients (optional)
     */
    public function sendMarkdownEmail(string $subject, string $markdownContent, string $to, ?string $cc = null): bool {
        if (!$this->enabled) {
            return false;
        }

        if (empty($to)) {
            throw new \RuntimeException('No recipient specified for email');
        }

        $html = $this->markdownToHtml($markdownContent);

        return $this->send($to, $subject, $html, $markdownContent, $cc);
    }

    /**
     * Send an email
     *
     * @param string $to Primary recipient email
     * @param string $subject Email subject
     * @param string $html HTML content
     * @param string|null $text Plain text content (optional)
     * @param string|null $cc Comma-separated CC recipients (optional)
     */
    public function send(string $to, string $subject, string $html, ?string $text = null, ?string $cc = null): bool {
        if (!$this->enabled || !$this->client) {
            return false;
        }

        $from = "{$this->fromName} <{$this->fromEmail}>";

        $formParams = [
            'from' => $from,
            'to' => $to,
            'subject' => $subject,
            'html' => $this->wrapHtml($html),
            'text' => $text ?? strip_tags($html),
        ];

        // Add CC recipients if provided
        if (!empty($cc)) {
            $formParams['cc'] = $cc;
        }

        $response = $this->client->post("{$this->domain}/messages", [
            'form_params' => $formParams,
        ]);

        return $response->getStatusCode() === 200;
    }

    /**
     * Convert markdown to HTML
     */
    private function markdownToHtml(string $markdown): string {
        $html = $markdown;

        // Headers
        $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);

        // Bold
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);

        // Italic
        $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);

        // Links - [text](url) format
        $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" style="color: #3498db;">$1</a>', $html);

        // Blockquotes
        $html = preg_replace('/^> (.+)$/m', '<blockquote>$1</blockquote>', $html);

        // Horizontal rules
        $html = preg_replace('/^---$/m', '<hr>', $html);

        // Tables
        $html = $this->convertTables($html);

        // Unordered lists
        $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $html);

        // Line breaks
        $html = preg_replace('/\n\n/', '</p><p>', $html);
        $html = '<p>' . $html . '</p>';

        // Clean up empty paragraphs
        $html = preg_replace('/<p>\s*<\/p>/', '', $html);
        $html = preg_replace('/<p>\s*(<h[1-6]>)/', '$1', $html);
        $html = preg_replace('/(<\/h[1-6]>)\s*<\/p>/', '$1', $html);
        $html = preg_replace('/<p>\s*(<ul>)/', '$1', $html);
        $html = preg_replace('/(<\/ul>)\s*<\/p>/', '$1', $html);
        $html = preg_replace('/<p>\s*(<hr>)/', '$1', $html);
        $html = preg_replace('/(<hr>)\s*<\/p>/', '$1', $html);
        $html = preg_replace('/<p>\s*(<table)/', '$1', $html);
        $html = preg_replace('/(<\/table>)\s*<\/p>/', '$1', $html);
        $html = preg_replace('/<p>\s*(<blockquote>)/', '$1', $html);
        $html = preg_replace('/(<\/blockquote>)\s*<\/p>/', '$1', $html);

        return $html;
    }

    /**
     * Convert markdown tables to HTML
     */
    private function convertTables(string $html): string {
        $pattern = '/\|(.+)\|\n\|[-| ]+\|\n((?:\|.+\|\n?)+)/';

        return preg_replace_callback($pattern, function ($matches) {
            $headerRow = trim($matches[1]);
            $bodyRows = trim($matches[2]);

            // Parse header
            $headers = array_map('trim', explode('|', $headerRow));
            $headerHtml = '<tr>' . implode('', array_map(fn($h) => "<th>{$h}</th>", $headers)) . '</tr>';

            // Parse body rows
            $rows = explode("\n", $bodyRows);
            $bodyHtml = '';
            foreach ($rows as $row) {
                $row = trim($row, '|');
                $cells = array_map('trim', explode('|', $row));
                $bodyHtml .= '<tr>' . implode('', array_map(fn($c) => "<td>{$c}</td>", $cells)) . '</tr>';
            }

            return "<table border=\"1\" cellpadding=\"8\" cellspacing=\"0\"><thead>{$headerHtml}</thead><tbody>{$bodyHtml}</tbody></table>";
        }, $html);
    }

    /**
     * Wrap HTML content in a styled template
     */
    private function wrapHtml(string $content): string {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        h1 { color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
        h2 { color: #34495e; margin-top: 30px; }
        h3 { color: #7f8c8d; }
        table { border-collapse: collapse; width: 100%; margin: 15px 0; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #3498db; color: white; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        blockquote {
            border-left: 4px solid #3498db;
            margin: 15px 0;
            padding: 10px 20px;
            background-color: #f8f9fa;
        }
        ul { padding-left: 20px; }
        li { margin: 5px 0; }
        hr { border: none; border-top: 1px solid #eee; margin: 30px 0; }
        strong { color: #2c3e50; }
        .high { color: #e74c3c; font-weight: bold; }
        .medium { color: #f39c12; font-weight: bold; }
        .low { color: #27ae60; font-weight: bold; }
    </style>
</head>
<body>
{$content}
<hr>
<p style="color: #999; font-size: 12px;">Generated by MyCTOBot - Jira Sprint Analyzer</p>
</body>
</html>
HTML;
    }

    /**
     * Check if Mailgun is configured
     */
    public static function isConfigured(): bool {
        // Check tenant/Flight config first
        $apiKey = Flight::get('mailgun.api_key');
        $domain = Flight::get('mailgun.domain');
        if (!empty($apiKey) && !empty($domain)) {
            return true;
        }

        // Fall back to conf/mailgun.ini
        $iniPath = dirname(__DIR__) . '/conf/mailgun.ini';
        if (file_exists($iniPath)) {
            $config = parse_ini_file($iniPath);
            if ($config && !empty($config['key']) && !empty($config['domain'])) {
                return true;
            }
        }

        return false;
    }
}
