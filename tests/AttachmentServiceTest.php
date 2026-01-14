<?php
/**
 * AttachmentService Unit Tests
 *
 * Tests the AttachmentService for detecting, validating, and processing file attachments.
 * Run with: php tests/AttachmentServiceTest.php
 */

namespace Tests;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../services/AttachmentService.php';
require_once __DIR__ . '/../services/JiraClient.php';

use app\services\AttachmentService;

class AttachmentServiceTest {

    private static int $passed = 0;
    private static int $failed = 0;
    private static array $errors = [];

    /**
     * Run all tests
     */
    public static function run(): void {
        echo "=== AttachmentService Unit Tests ===\n\n";

        // Run tests
        self::testDetectAttachments();
        self::testDetectAttachmentsEmpty();
        self::testAttachmentClassification();
        self::testValidateAttachmentSize();
        self::testValidateOversizedAttachment();
        self::testFormatAcknowledgmentWithAttachments();
        self::testFormatAcknowledgmentEmpty();
        self::testFormatAcknowledgmentWithWarnings();
        self::testFormatSize();
        self::testGetStatistics();
        self::testGetConfig();

        self::printResults();
    }

    /**
     * Test detection of attachments from issue data
     */
    private static function testDetectAttachments(): void {
        echo "Testing detectAttachments()...\n";

        $mockJiraClient = self::createMockJiraClient();
        $service = new AttachmentService($mockJiraClient);

        $issue = self::createMockIssue([
            [
                'id' => '12345',
                'filename' => 'screenshot.png',
                'size' => 1024 * 500, // 500 KB
                'mimeType' => 'image/png',
                'content' => 'https://example.com/attachment/12345',
                'created' => '2024-01-15T10:00:00.000Z',
                'author' => ['displayName' => 'John Doe']
            ],
            [
                'id' => '12346',
                'filename' => 'requirements.pdf',
                'size' => 1024 * 1024 * 2, // 2 MB
                'mimeType' => 'application/pdf',
                'content' => 'https://example.com/attachment/12346',
                'created' => '2024-01-15T11:00:00.000Z',
                'author' => ['displayName' => 'Jane Smith']
            ]
        ]);

        $attachments = $service->detectAttachments($issue);

        self::assertEqual(count($attachments), 2, 'Detects 2 attachments');
        self::assertEqual($attachments[0]['filename'], 'screenshot.png', 'First attachment filename');
        self::assertEqual($attachments[0]['type'], 'image', 'First attachment type is image');
        self::assertEqual($attachments[1]['filename'], 'requirements.pdf', 'Second attachment filename');
        self::assertEqual($attachments[1]['type'], 'document', 'Second attachment type is document');

        echo "\n";
    }

    /**
     * Test detection with no attachments
     */
    private static function testDetectAttachmentsEmpty(): void {
        echo "Testing detectAttachments() with no attachments...\n";

        $mockJiraClient = self::createMockJiraClient();
        $service = new AttachmentService($mockJiraClient);

        $issue = self::createMockIssue([]);

        $attachments = $service->detectAttachments($issue);

        self::assertEqual(count($attachments), 0, 'Returns empty array for no attachments');
        self::assertTrue(is_array($attachments), 'Result is an array');

        echo "\n";
    }

    /**
     * Test attachment type classification
     */
    private static function testAttachmentClassification(): void {
        echo "Testing attachment classification...\n";

        $mockJiraClient = self::createMockJiraClient();
        $service = new AttachmentService($mockJiraClient);

        $testCases = [
            ['filename' => 'photo.jpg', 'mimeType' => 'image/jpeg', 'expected' => 'image'],
            ['filename' => 'logo.png', 'mimeType' => 'image/png', 'expected' => 'image'],
            ['filename' => 'animation.gif', 'mimeType' => 'image/gif', 'expected' => 'image'],
            ['filename' => 'report.pdf', 'mimeType' => 'application/pdf', 'expected' => 'document'],
            ['filename' => 'notes.txt', 'mimeType' => 'text/plain', 'expected' => 'document'],
            ['filename' => 'data.json', 'mimeType' => 'application/json', 'expected' => 'document'],
            ['filename' => 'index.php', 'mimeType' => 'text/x-php', 'expected' => 'code'],
            ['filename' => 'script.js', 'mimeType' => 'text/javascript', 'expected' => 'code'],
            ['filename' => 'styles.css', 'mimeType' => 'text/css', 'expected' => 'code'],
            ['filename' => 'backup.zip', 'mimeType' => 'application/zip', 'expected' => 'archive'],
            ['filename' => 'data.tar.gz', 'mimeType' => 'application/gzip', 'expected' => 'archive'],
            ['filename' => 'unknown.xyz', 'mimeType' => 'application/octet-stream', 'expected' => 'other']
        ];

        foreach ($testCases as $testCase) {
            $issue = self::createMockIssue([[
                'id' => '1',
                'filename' => $testCase['filename'],
                'size' => 1024,
                'mimeType' => $testCase['mimeType'],
                'content' => 'https://example.com/file',
                'author' => ['displayName' => 'Test']
            ]]);

            $attachments = $service->detectAttachments($issue);
            self::assertEqual(
                $attachments[0]['type'],
                $testCase['expected'],
                "{$testCase['filename']} classified as {$testCase['expected']}"
            );
        }

        echo "\n";
    }

    /**
     * Test size validation for normal attachment
     */
    private static function testValidateAttachmentSize(): void {
        echo "Testing validateAttachmentSize() for normal size...\n";

        $mockJiraClient = self::createMockJiraClient();
        // Use default 25MB limit
        $service = new AttachmentService($mockJiraClient);

        $attachment = [
            'filename' => 'document.pdf',
            'size' => 1024 * 1024 * 5, // 5 MB
            'size_formatted' => '5 MB'
        ];

        $result = $service->validateAttachmentSize($attachment);

        self::assertTrue($result['valid'], 'Attachment is valid');
        self::assertTrue(strpos($result['message'], 'within size limits') !== false, 'Message indicates valid');

        echo "\n";
    }

    /**
     * Test size validation for oversized attachment
     */
    private static function testValidateOversizedAttachment(): void {
        echo "Testing validateAttachmentSize() for oversized...\n";

        $mockJiraClient = self::createMockJiraClient();
        // Set a small limit for testing (1 MB)
        $service = new AttachmentService($mockJiraClient, null, 1024 * 1024);

        $attachment = [
            'filename' => 'large-video.mp4',
            'size' => 1024 * 1024 * 50, // 50 MB
            'size_formatted' => '50 MB'
        ];

        $result = $service->validateAttachmentSize($attachment);

        self::assertFalse($result['valid'], 'Attachment is invalid (oversized)');
        self::assertTrue(strpos($result['message'], 'WARNING') !== false, 'Message contains warning');
        self::assertTrue(strpos($result['message'], 'exceeds') !== false, 'Message indicates exceeds limit');

        echo "\n";
    }

    /**
     * Test acknowledgment formatting with attachments
     */
    private static function testFormatAcknowledgmentWithAttachments(): void {
        echo "Testing formatAcknowledgment() with attachments...\n";

        $mockJiraClient = self::createMockJiraClient();
        $service = new AttachmentService($mockJiraClient);

        $attachments = [
            [
                'filename' => 'screenshot.png',
                'size' => 512000,
                'size_formatted' => '500 KB',
                'type' => 'image',
                'is_oversized' => false
            ],
            [
                'filename' => 'spec.pdf',
                'size' => 1048576,
                'size_formatted' => '1 MB',
                'type' => 'document',
                'is_oversized' => false
            ]
        ];

        $acknowledgment = $service->formatAcknowledgment($attachments);

        self::assertTrue(strpos($acknowledgment, 'Attachments Detected') !== false, 'Contains title');
        self::assertTrue(strpos($acknowledgment, '2 file(s)') !== false, 'Contains file count');
        self::assertTrue(strpos($acknowledgment, 'screenshot.png') !== false, 'Contains image filename');
        self::assertTrue(strpos($acknowledgment, 'spec.pdf') !== false, 'Contains document filename');
        self::assertTrue(strpos($acknowledgment, 'Images') !== false, 'Has Images section');
        self::assertTrue(strpos($acknowledgment, 'Documents') !== false, 'Has Documents section');

        echo "\n";
    }

    /**
     * Test acknowledgment formatting with no attachments
     */
    private static function testFormatAcknowledgmentEmpty(): void {
        echo "Testing formatAcknowledgment() with no attachments...\n";

        $mockJiraClient = self::createMockJiraClient();
        $service = new AttachmentService($mockJiraClient);

        $acknowledgment = $service->formatAcknowledgment([]);

        self::assertTrue(strpos($acknowledgment, 'No file attachments') !== false, 'Indicates no attachments');

        echo "\n";
    }

    /**
     * Test acknowledgment formatting with oversized warnings
     */
    private static function testFormatAcknowledgmentWithWarnings(): void {
        echo "Testing formatAcknowledgment() with oversized warnings...\n";

        $mockJiraClient = self::createMockJiraClient();
        $service = new AttachmentService($mockJiraClient);

        $attachments = [
            [
                'filename' => 'small.png',
                'size' => 512000,
                'size_formatted' => '500 KB',
                'type' => 'image',
                'is_oversized' => false
            ],
            [
                'filename' => 'huge-video.mp4',
                'size' => 1024 * 1024 * 100,
                'size_formatted' => '100 MB',
                'type' => 'other',
                'is_oversized' => true
            ]
        ];

        $acknowledgment = $service->formatAcknowledgment($attachments, true);

        self::assertTrue(strpos($acknowledgment, '[OVERSIZED]') !== false, 'Contains OVERSIZED marker');
        self::assertTrue(strpos($acknowledgment, 'Warnings') !== false, 'Has Warnings section');
        self::assertTrue(strpos($acknowledgment, 'huge-video.mp4') !== false, 'Warns about specific file');

        echo "\n";
    }

    /**
     * Test formatSize method
     */
    private static function testFormatSize(): void {
        echo "Testing formatSize()...\n";

        $mockJiraClient = self::createMockJiraClient();
        $service = new AttachmentService($mockJiraClient);

        self::assertEqual($service->formatSize(500), '500 B', 'Formats bytes');
        self::assertEqual($service->formatSize(1024), '1 KB', 'Formats 1 KB');
        self::assertEqual($service->formatSize(1536), '1.5 KB', 'Formats 1.5 KB');
        self::assertEqual($service->formatSize(1024 * 1024), '1 MB', 'Formats 1 MB');
        self::assertEqual($service->formatSize(1024 * 1024 * 2.5), '2.5 MB', 'Formats 2.5 MB');
        self::assertEqual($service->formatSize(1024 * 1024 * 1024), '1 GB', 'Formats 1 GB');

        echo "\n";
    }

    /**
     * Test getStatistics method
     */
    private static function testGetStatistics(): void {
        echo "Testing getStatistics()...\n";

        $mockJiraClient = self::createMockJiraClient();
        $service = new AttachmentService($mockJiraClient);

        $attachments = [
            ['filename' => 'a.png', 'size' => 1000, 'type' => 'image', 'is_oversized' => false, 'is_indexable' => true],
            ['filename' => 'b.jpg', 'size' => 2000, 'type' => 'image', 'is_oversized' => false, 'is_indexable' => true],
            ['filename' => 'c.pdf', 'size' => 5000, 'type' => 'document', 'is_oversized' => false, 'is_indexable' => true],
            ['filename' => 'd.zip', 'size' => 10000, 'type' => 'archive', 'is_oversized' => true, 'is_indexable' => false]
        ];

        $stats = $service->getStatistics($attachments);

        self::assertEqual($stats['total_count'], 4, 'Total count is 4');
        self::assertEqual($stats['total_size'], 18000, 'Total size is 18000 bytes');
        self::assertEqual($stats['by_type']['image'], 2, 'Has 2 images');
        self::assertEqual($stats['by_type']['document'], 1, 'Has 1 document');
        self::assertEqual($stats['by_type']['archive'], 1, 'Has 1 archive');
        self::assertEqual($stats['oversized_count'], 1, 'Has 1 oversized');
        self::assertEqual($stats['indexable_count'], 3, 'Has 3 indexable');

        echo "\n";
    }

    /**
     * Test getConfig method
     */
    private static function testGetConfig(): void {
        echo "Testing getConfig()...\n";

        $mockJiraClient = self::createMockJiraClient();
        $service = new AttachmentService($mockJiraClient, 'http://rag.example.com', 1024 * 1024 * 10);

        $config = $service->getConfig();

        self::assertEqual($config['max_size_bytes'], 1024 * 1024 * 10, 'Max size is 10MB');
        self::assertEqual($config['max_size_formatted'], '10 MB', 'Max size formatted correctly');
        self::assertEqual($config['rag_service_url'], 'http://rag.example.com', 'RAG URL is set');
        self::assertTrue(is_array($config['image_types']), 'Has image types');
        self::assertTrue(is_array($config['document_types']), 'Has document types');

        echo "\n";
    }

    // === Helper Methods ===

    /**
     * Create a mock Jira issue with attachments
     */
    private static function createMockIssue(array $attachments): array {
        return [
            'key' => 'TEST-123',
            'fields' => [
                'summary' => 'Test Issue',
                'description' => 'Test description',
                'attachment' => $attachments
            ]
        ];
    }

    /**
     * Create a mock JiraClient
     * Since AttachmentService only uses the client for indexToRag (which calls the RAG service),
     * we can use a simple mock object
     */
    private static function createMockJiraClient(): object {
        return new class {
            // Mock methods if needed
        };
    }

    /**
     * Assert two values are equal
     */
    private static function assertEqual($actual, $expected, string $message): void {
        if ($actual === $expected) {
            self::$passed++;
            echo "  [PASS] {$message}\n";
        } else {
            self::$failed++;
            self::$errors[] = "{$message}: expected " . var_export($expected, true) . ", got " . var_export($actual, true);
            echo "  [FAIL] {$message}\n";
        }
    }

    /**
     * Assert value is true
     */
    private static function assertTrue($value, string $message): void {
        if ($value === true) {
            self::$passed++;
            echo "  [PASS] {$message}\n";
        } else {
            self::$failed++;
            self::$errors[] = "{$message}: expected true, got " . var_export($value, true);
            echo "  [FAIL] {$message}\n";
        }
    }

    /**
     * Assert value is false
     */
    private static function assertFalse($value, string $message): void {
        if ($value === false) {
            self::$passed++;
            echo "  [PASS] {$message}\n";
        } else {
            self::$failed++;
            self::$errors[] = "{$message}: expected false, got " . var_export($value, true);
            echo "  [FAIL] {$message}\n";
        }
    }

    /**
     * Print test results summary
     */
    private static function printResults(): void {
        $total = self::$passed + self::$failed;
        echo "\n=== Test Results ===\n";
        echo "Passed: " . self::$passed . "/{$total}\n";
        echo "Failed: " . self::$failed . "/{$total}\n";

        if (count(self::$errors) > 0) {
            echo "\nErrors:\n";
            foreach (self::$errors as $error) {
                echo "  - {$error}\n";
            }
        }

        echo "\n";
        exit(self::$failed > 0 ? 1 : 0);
    }
}

// Run tests
AttachmentServiceTest::run();
