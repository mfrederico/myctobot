<?php
/**
 * ImageService Unit Tests
 *
 * Tests the ImageService for resizing and processing images.
 * Run with: php tests/ImageServiceTest.php
 */

namespace app\tests;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../services/ImageService.php';

use app\services\ImageService;

class ImageServiceTest {

    private static int $passed = 0;
    private static int $failed = 0;
    private static array $errors = [];

    /**
     * Run all tests
     */
    public static function run(): void {
        echo "=== ImageService Unit Tests ===\n\n";

        // Check GD extension
        if (!extension_loaded('gd')) {
            echo "[FATAL] GD extension is not available. Cannot run tests.\n";
            exit(1);
        }

        echo "GD extension: available\n";
        echo "GD version: " . (defined('GD_VERSION') ? GD_VERSION : 'unknown') . "\n\n";

        // Run tests
        self::testDetectFormat();
        self::testGetDimensions();
        self::testNeedsResize();
        self::testResizeLargeImage();
        self::testResizeSmallImageNoOp();
        self::testResizeToJpeg();
        self::testResizeToPng();
        self::testResizeForContext();
        self::testResizeMaintainsAspectRatio();
        self::testResizeWithTransparency();
        self::testEstimateBase64Size();
        self::testInvalidImageData();

        self::printResults();
    }

    /**
     * Test format detection from binary data
     */
    private static function testDetectFormat(): void {
        echo "Testing detectFormat()...\n";

        // Test PNG
        $png = self::createTestImage(100, 100, 'png');
        self::assertEqual(ImageService::detectFormat($png), 'png', 'PNG format detection');

        // Test JPEG
        $jpeg = self::createTestImage(100, 100, 'jpeg');
        self::assertEqual(ImageService::detectFormat($jpeg), 'jpeg', 'JPEG format detection');

        // Test GIF
        $gif = self::createTestImage(100, 100, 'gif');
        self::assertEqual(ImageService::detectFormat($gif), 'gif', 'GIF format detection');

        // Test invalid data
        self::assertEqual(ImageService::detectFormat('not an image'), 'unknown', 'Invalid data detection');

        echo "\n";
    }

    /**
     * Test dimension extraction
     */
    private static function testGetDimensions(): void {
        echo "Testing getDimensions()...\n";

        $img = self::createTestImage(800, 600, 'png');
        $dims = ImageService::getDimensions($img);

        self::assertEqual($dims['width'], 800, 'Width extraction');
        self::assertEqual($dims['height'], 600, 'Height extraction');
        self::assertEqual($dims['mimeType'], 'image/png', 'MIME type extraction');

        // Test invalid data
        $invalid = ImageService::getDimensions('not an image');
        self::assertEqual($invalid, null, 'Invalid data returns null');

        echo "\n";
    }

    /**
     * Test needsResize detection
     */
    private static function testNeedsResize(): void {
        echo "Testing needsResize()...\n";

        // Large image needs resize
        $large = self::createTestImage(2000, 1500, 'png');
        self::assertTrue(ImageService::needsResize($large, 1200, 1200), 'Large image needs resize');

        // Small image does not need resize
        $small = self::createTestImage(800, 600, 'png');
        self::assertFalse(ImageService::needsResize($small, 1200, 1200), 'Small image does not need resize');

        // Edge case: exactly at limit
        $exact = self::createTestImage(1200, 1200, 'png');
        self::assertFalse(ImageService::needsResize($exact, 1200, 1200), 'Exact size does not need resize');

        // Width over, height under
        $wideOnly = self::createTestImage(1500, 800, 'png');
        self::assertTrue(ImageService::needsResize($wideOnly, 1200, 1200), 'Wide image needs resize');

        echo "\n";
    }

    /**
     * Test resizing a large image
     */
    private static function testResizeLargeImage(): void {
        echo "Testing resize() with large image...\n";

        $original = self::createTestImage(2000, 1500, 'png');
        $result = ImageService::resize($original, 1200, 1200);

        self::assertTrue($result['resized'], 'Image was resized');
        self::assertEqual($result['width'], 1200, 'Width is correct (1200)');
        self::assertEqual($result['height'], 900, 'Height maintains aspect ratio (900)');
        self::assertEqual($result['originalWidth'], 2000, 'Original width tracked');
        self::assertEqual($result['originalHeight'], 1500, 'Original height tracked');
        self::assertTrue(strlen($result['data']) > 0, 'Output data is not empty');

        echo "\n";
    }

    /**
     * Test that small images are not resized
     */
    private static function testResizeSmallImageNoOp(): void {
        echo "Testing resize() with small image (no-op)...\n";

        $original = self::createTestImage(800, 600, 'png');
        $result = ImageService::resize($original, 1200, 1200);

        self::assertFalse($result['resized'], 'Image was not resized');
        self::assertEqual($result['width'], 800, 'Width unchanged');
        self::assertEqual($result['height'], 600, 'Height unchanged');

        echo "\n";
    }

    /**
     * Test conversion to JPEG
     */
    private static function testResizeToJpeg(): void {
        echo "Testing resize() with JPEG output...\n";

        $png = self::createTestImage(1000, 800, 'png');
        $result = ImageService::resize($png, 1200, 1200, 'jpeg', 80);

        self::assertEqual($result['mimeType'], 'image/jpeg', 'Output is JPEG');
        self::assertEqual(ImageService::detectFormat($result['data']), 'jpeg', 'Data is actually JPEG');

        echo "\n";
    }

    /**
     * Test conversion to PNG
     */
    private static function testResizeToPng(): void {
        echo "Testing resize() with PNG output...\n";

        $jpeg = self::createTestImage(1000, 800, 'jpeg');
        $result = ImageService::resize($jpeg, 1200, 1200, 'png');

        self::assertEqual($result['mimeType'], 'image/png', 'Output is PNG');
        self::assertEqual(ImageService::detectFormat($result['data']), 'png', 'Data is actually PNG');

        echo "\n";
    }

    /**
     * Test resizeForContext optimizations
     */
    private static function testResizeForContext(): void {
        echo "Testing resizeForContext()...\n";

        // Large image should be resized and converted to JPEG
        $large = self::createTestImage(3000, 2000, 'png');
        $result = ImageService::resizeForContext($large, 1200, 1200);

        self::assertTrue($result['resized'], 'Large image was resized');
        self::assertEqual($result['mimeType'], 'image/jpeg', 'Converted to JPEG for compression');
        self::assertTrue($result['width'] <= 1200, 'Width within limits');
        self::assertTrue($result['height'] <= 1200, 'Height within limits');

        // Check that output is smaller than input for real-world scenario
        // (For solid colors this may not be true, but log for info)
        $originalSize = strlen($large);
        $newSize = strlen($result['data']);
        echo "  Original PNG: {$originalSize} bytes, Resized JPEG: {$newSize} bytes\n";

        echo "\n";
    }

    /**
     * Test that aspect ratio is maintained correctly
     */
    private static function testResizeMaintainsAspectRatio(): void {
        echo "Testing aspect ratio maintenance...\n";

        // Landscape image
        $landscape = self::createTestImage(4000, 2000, 'png');
        $result = ImageService::resize($landscape, 1200, 1200);
        $ratio = $result['width'] / $result['height'];
        self::assertTrue(abs($ratio - 2.0) < 0.01, 'Landscape 2:1 ratio maintained');

        // Portrait image
        $portrait = self::createTestImage(1000, 3000, 'png');
        $result = ImageService::resize($portrait, 1200, 1200);
        $ratio = $result['width'] / $result['height'];
        self::assertTrue(abs($ratio - 0.333) < 0.01, 'Portrait 1:3 ratio maintained');

        // Square image
        $square = self::createTestImage(2000, 2000, 'png');
        $result = ImageService::resize($square, 1200, 1200);
        self::assertEqual($result['width'], $result['height'], 'Square remains square');

        echo "\n";
    }

    /**
     * Test transparency preservation in PNG
     */
    private static function testResizeWithTransparency(): void {
        echo "Testing transparency preservation...\n";

        // Create PNG with transparency
        $img = imagecreatetruecolor(2000, 1500);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);

        // Draw a red circle
        $red = imagecolorallocate($img, 255, 0, 0);
        imagefilledellipse($img, 1000, 750, 500, 500, $red);

        ob_start();
        imagepng($img);
        $original = ob_get_clean();
        imagedestroy($img);

        // Resize as PNG to preserve transparency
        $result = ImageService::resize($original, 1200, 1200, 'png');

        self::assertTrue($result['resized'], 'Transparent image was resized');
        self::assertEqual($result['mimeType'], 'image/png', 'Kept as PNG');

        // Verify the output is valid PNG
        $check = @imagecreatefromstring($result['data']);
        self::assertTrue($check !== false, 'Output is valid image');
        if ($check) {
            imagedestroy($check);
        }

        echo "\n";
    }

    /**
     * Test base64 size estimation
     */
    private static function testEstimateBase64Size(): void {
        echo "Testing estimateBase64Size()...\n";

        $data = str_repeat('x', 1000);
        $estimated = ImageService::estimateBase64Size($data);
        $actual = strlen(base64_encode($data));

        // Should be within 5% of actual
        $diff = abs($estimated - $actual);
        self::assertTrue($diff < ($actual * 0.05), "Estimate ({$estimated}) close to actual ({$actual})");

        echo "\n";
    }

    /**
     * Test handling of invalid image data
     */
    private static function testInvalidImageData(): void {
        echo "Testing invalid image handling...\n";

        $invalidData = 'this is not an image';

        // detectFormat should return unknown
        self::assertEqual(ImageService::detectFormat($invalidData), 'unknown', 'Invalid format detected');

        // getDimensions should return null
        self::assertEqual(ImageService::getDimensions($invalidData), null, 'getDimensions returns null');

        // resize should throw exception
        $threw = false;
        try {
            ImageService::resize($invalidData, 1200, 1200);
        } catch (\Exception $e) {
            $threw = true;
            self::assertTrue(
                strpos($e->getMessage(), 'invalid') !== false || strpos($e->getMessage(), 'Failed') !== false,
                'Exception message mentions invalid data'
            );
        }
        self::assertTrue($threw, 'resize() throws exception for invalid data');

        echo "\n";
    }

    // === Helper Methods ===

    /**
     * Create a test image with specified dimensions and format
     */
    private static function createTestImage(int $width, int $height, string $format = 'png'): string {
        $img = imagecreatetruecolor($width, $height);

        // Fill with a gradient for more realistic compression testing
        for ($y = 0; $y < $height; $y++) {
            $color = imagecolorallocate($img, ($y * 255) / $height, 100, 150);
            imageline($img, 0, $y, $width, $y, $color);
        }

        ob_start();
        switch ($format) {
            case 'jpeg':
            case 'jpg':
                imagejpeg($img, null, 90);
                break;
            case 'gif':
                imagegif($img);
                break;
            case 'webp':
                imagewebp($img, null, 90);
                break;
            case 'png':
            default:
                imagepng($img);
        }
        $data = ob_get_clean();
        imagedestroy($img);

        return $data;
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
ImageServiceTest::run();
