<?php
/**
 * MarkdownParser - Simple Markdown to HTML converter
 *
 * Converts basic Markdown syntax to HTML for documentation rendering.
 * Can be used across the application for any markdown content.
 *
 * @package TikNix
 */

namespace app;

class MarkdownParser {

    /**
     * Convert header text to URL-friendly slug for ID attribute
     *
     * @param string $text Header text
     * @return string URL-friendly slug
     */
    private static function slugify($text) {
        // Remove HTML tags
        $text = strip_tags($text);
        // Convert to lowercase
        $text = strtolower($text);
        // Replace spaces and special characters with hyphens
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        // Remove leading/trailing hyphens
        $text = trim($text, '-');
        return $text;
    }

    /**
     * Parse markdown text and convert to HTML
     *
     * @param string $text Markdown text to parse
     * @return string HTML output
     */
    public static function parse($text) {
        // IMPORTANT: Process code blocks FIRST to protect code from other transformations

        // Store code blocks temporarily to prevent them from being processed
        $codeBlocks = [];
        $blockCounter = 0;

        // Convert code blocks first (triple backticks)
        $text = preg_replace_callback('/```(\w*)\n(.*?)```/s', function($matches) use (&$codeBlocks, &$blockCounter) {
            $language = $matches[1] ?: 'plaintext';
            $code = h($matches[2], ENT_QUOTES, 'UTF-8');
            $placeholder = "###CODEBLOCK{$blockCounter}###";
            $codeBlocks[$placeholder] = '<pre><code class="language-' . $language . '">' . $code . '</code></pre>';
            $blockCounter++;
            return $placeholder;
        }, $text);

        // Store inline code temporarily
        $inlineCode = [];
        $inlineCounter = 0;

        // Convert inline code (single backticks) - must be before other inline formatting
        $text = preg_replace_callback('/`([^`\n]+)`/', function($matches) use (&$inlineCode, &$inlineCounter) {
            $code = h($matches[1], ENT_QUOTES, 'UTF-8');
            $placeholder = "###INLINECODE{$inlineCounter}###";
            $inlineCode[$placeholder] = '<code>' . $code . '</code>';
            $inlineCounter++;
            return $placeholder;
        }, $text);

        // Convert headers with ID attributes for anchor links
        $text = preg_replace_callback('/^#### (.*?)$/m', function($matches) {
            $id = self::slugify($matches[1]);
            return '<h4 id="' . $id . '">' . $matches[1] . '</h4>';
        }, $text);
        $text = preg_replace_callback('/^### (.*?)$/m', function($matches) {
            $id = self::slugify($matches[1]);
            return '<h3 id="' . $id . '">' . $matches[1] . '</h3>';
        }, $text);
        $text = preg_replace_callback('/^## (.*?)$/m', function($matches) {
            $id = self::slugify($matches[1]);
            return '<h2 id="' . $id . '">' . $matches[1] . '</h2>';
        }, $text);
        $text = preg_replace_callback('/^# (.*?)$/m', function($matches) {
            $id = self::slugify($matches[1]);
            return '<h1 id="' . $id . '">' . $matches[1] . '</h1>';
        }, $text);

        // Convert bold and italic (order matters!)
        $text = preg_replace('/\*\*\*(.*?)\*\*\*/s', '<strong><em>$1</em></strong>', $text);
        $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<!\*)\*(?!\*)([^*\n]+)\*(?!\*)/s', '<em>$1</em>', $text);

        // Convert links — SECURITY (LOW XSS): block dangerous URL schemes
        // (javascript:/data:/vbscript:) and encode the href to prevent attribute
        // breakout.
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function($m) {
            $url = trim($m[2]);
            if (preg_match('/^\s*(javascript|data|vbscript):/i', $url)) {
                $url = '#';
            }
            return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $m[1] . '</a>';
        }, $text);

        // Convert blockquotes
        $text = preg_replace('/^> (.*)$/m', '<blockquote>$1</blockquote>', $text);

        // Convert horizontal rules
        $text = preg_replace('/^---+$/m', '<hr>', $text);

        // Convert lists (handle nested lists better)
        $lines = explode("\n", $text);
        $inList = false;
        $listHtml = '';
        $newLines = [];

        foreach ($lines as $line) {
            if (preg_match('/^(\s*)[-*+] (.*)$/', $line, $matches)) {
                if (!$inList) {
                    $inList = true;
                    $listHtml = '<ul>';
                }
                $indent = strlen($matches[1]);
                $content = $matches[2];
                $listHtml .= '<li>' . $content . '</li>';
            } else {
                if ($inList) {
                    $newLines[] = $listHtml . '</ul>';
                    $inList = false;
                    $listHtml = '';
                }
                $newLines[] = $line;
            }
        }
        if ($inList) {
            $newLines[] = $listHtml . '</ul>';
        }
        $text = implode("\n", $newLines);

        // Convert tables (simple table support)
        $text = preg_replace_callback('/^\|(.+)\|$/m', function($matches) {
            $cells = explode('|', trim($matches[1], '|'));
            $cellHtml = '';
            foreach ($cells as $cell) {
                $cellHtml .= '<td>' . trim($cell) . '</td>';
            }
            return '<tr>' . $cellHtml . '</tr>';
        }, $text);

        // Wrap table rows in table tags
        $text = preg_replace('/(<tr>.*?<\/tr>\s*)+/s', '<table class="table table-bordered">$0</table>', $text);

        // Restore inline code
        foreach ($inlineCode as $placeholder => $code) {
            $text = str_replace($placeholder, $code, $text);
        }

        // Restore code blocks
        foreach ($codeBlocks as $placeholder => $code) {
            $text = str_replace($placeholder, $code, $text);
        }

        // Convert line breaks to paragraphs (but be smarter about it)
        $paragraphs = explode("\n\n", $text);
        $html = '';
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para) {
                // Don't wrap if it's already an HTML element
                if (!preg_match('/^<(h[1-6]|ul|ol|pre|div|table|blockquote|hr)/i', $para)) {
                    // Don't use nl2br on content that already has block elements
                    if (preg_match('/<(li|tr|td|th)/', $para)) {
                        $html .= $para;
                    } else {
                        $html .= '<p>' . nl2br($para) . '</p>';
                    }
                } else {
                    $html .= $para;
                }
            }
        }

        return $html;
    }

    /**
     * Basic markdown to HTML conversion (lightweight version)
     *
     * Handles headers, bold, italic, links, lists, blockquotes, tables, and line breaks.
     *
     * @param string $markdown Markdown text to parse
     * @param bool $escapeHtml Whether to escape HTML entities (default: true for security)
     * @param bool $forEmail Whether to add inline styles for email clients (default: false)
     * @return string HTML output
     */
    public static function parseBasic(string $markdown, bool $escapeHtml = true, bool $forEmail = false): string {
        if (empty($markdown)) {
            return '';
        }

        $html = $escapeHtml ? h($markdown, ENT_QUOTES, 'UTF-8') : $markdown;

        // Headers
        $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);

        // Bold and italic
        $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $html);

        // Inline code
        $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);

        // Links - with optional inline styling for email
        $linkStyle = $forEmail ? ' style="color: #3498db;"' : ' target="_blank"';
        $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2"' . $linkStyle . '>$1</a>', $html);

        // Blockquotes
        $html = preg_replace('/^> (.+)$/m', '<blockquote>$1</blockquote>', $html);

        // Horizontal rules
        $html = preg_replace('/^---$/m', '<hr>', $html);

        // Tables
        $html = self::convertTables($html, $forEmail);

        // Unordered lists
        $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $html);

        // Line breaks and paragraphs
        $html = preg_replace('/\n\n/', '</p><p>', $html);
        $html = '<p>' . $html . '</p>';

        // Clean up empty paragraphs and fix nesting
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
    private static function convertTables(string $html, bool $forEmail = false): string {
        $pattern = '/\|(.+)\|\n\|[-| ]+\|\n((?:\|.+\|\n?)+)/';

        $tableStyle = $forEmail ? ' border="1" cellpadding="8" cellspacing="0"' : ' class="table table-bordered"';

        return preg_replace_callback($pattern, function ($matches) use ($tableStyle) {
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

            return "<table{$tableStyle}><thead>{$headerHtml}</thead><tbody>{$bodyHtml}</tbody></table>";
        }, $html);
    }
}
