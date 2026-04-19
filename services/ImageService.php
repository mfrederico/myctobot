<?php
namespace app\services;

/**
 * Image manipulation service for resizing and optimizing images
 * Uses PHP GD library for image processing
 */
class ImageService
{
    /** @var int Default max width for resized images */
    const DEFAULT_MAX_WIDTH = 1200;

    /** @var int Default max height for resized images */
    const DEFAULT_MAX_HEIGHT = 1200;

    /** @var int Default JPEG quality (0-100) */
    const DEFAULT_JPEG_QUALITY = 85;

    /** @var int Default PNG compression (0-9) */
    const DEFAULT_PNG_COMPRESSION = 6;

    /**
     * Resize image data to fit within max dimensions while maintaining aspect ratio
     *
     * @param string $imageData Raw binary image data
     * @param int $maxWidth Maximum width in pixels
     * @param int $maxHeight Maximum height in pixels
     * @param string|null $outputFormat Force output format (jpeg, png, webp) or null to keep original
     * @param int $quality JPEG quality (0-100) or PNG compression (0-9)
     * @return array ['data' => string, 'mimeType' => string, 'width' => int, 'height' => int, 'resized' => bool]
     * @throws \Exception If image processing fails
     */
    public static function resize(
        string $imageData,
        int $maxWidth = self::DEFAULT_MAX_WIDTH,
        int $maxHeight = self::DEFAULT_MAX_HEIGHT,
        ?string $outputFormat = null,
        int $quality = self::DEFAULT_JPEG_QUALITY
    ): array {
        // Check if GD is available
        if (!extension_loaded('gd')) {
            throw new \Exception('GD extension is not available');
        }

        // Create image resource from data
        $image = @imagecreatefromstring($imageData);
        if ($image === false) {
            throw new \Exception('Failed to create image from data - invalid or unsupported format');
        }

        // Get original dimensions
        $origWidth = imagesx($image);
        $origHeight = imagesy($image);

        // Detect source format
        $sourceFormat = self::detectFormat($imageData);
        $targetFormat = $outputFormat ?? $sourceFormat;

        // Calculate new dimensions maintaining aspect ratio
        $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);

        // Only resize if image is larger than max dimensions
        $resized = false;
        if ($ratio < 1) {
            $newWidth = (int) round($origWidth * $ratio);
            $newHeight = (int) round($origHeight * $ratio);
            $resized = true;
        } else {
            $newWidth = $origWidth;
            $newHeight = $origHeight;
        }

        // Create resized image if needed
        if ($resized) {
            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

            // Preserve transparency for PNG and WebP
            if ($targetFormat === 'png' || $targetFormat === 'webp') {
                imagealphablending($resizedImage, false);
                imagesavealpha($resizedImage, true);
                $transparent = imagecolorallocatealpha($resizedImage, 0, 0, 0, 127);
                imagefill($resizedImage, 0, 0, $transparent);
            }

            // Resize using high-quality resampling
            imagecopyresampled(
                $resizedImage, $image,
                0, 0, 0, 0,
                $newWidth, $newHeight,
                $origWidth, $origHeight
            );

            imagedestroy($image);
            $image = $resizedImage;
        }

        // Output to buffer
        ob_start();

        switch ($targetFormat) {
            case 'jpeg':
            case 'jpg':
                imagejpeg($image, null, $quality);
                $mimeType = 'image/jpeg';
                break;

            case 'png':
                // PNG compression is 0-9, convert from quality scale if needed
                $compression = min(9, max(0, (int) round((100 - $quality) / 11)));
                imagepng($image, null, $compression);
                $mimeType = 'image/png';
                break;

            case 'webp':
                imagewebp($image, null, $quality);
                $mimeType = 'image/webp';
                break;

            case 'gif':
                imagegif($image);
                $mimeType = 'image/gif';
                break;

            default:
                // Default to JPEG for unknown formats
                imagejpeg($image, null, $quality);
                $mimeType = 'image/jpeg';
        }

        $outputData = ob_get_clean();
        imagedestroy($image);

        return [
            'data' => $outputData,
            'mimeType' => $mimeType,
            'width' => $newWidth,
            'height' => $newHeight,
            'originalWidth' => $origWidth,
            'originalHeight' => $origHeight,
            'resized' => $resized
        ];
    }

    /**
     * Resize image data for Claude context window
     * Optimized settings for AI model consumption
     *
     * @param string $imageData Raw binary image data
     * @param int $maxWidth Maximum width (default 1200 for good detail)
     * @param int $maxHeight Maximum height (default 1200)
     * @return array ['data' => string, 'mimeType' => string, ...]
     */
    public static function resizeForContext(
        string $imageData,
        int $maxWidth = 1200,
        int $maxHeight = 1200
    ): array {
        // For context window optimization, convert to JPEG with moderate quality
        // This reduces base64 size significantly while maintaining readability
        return self::resize(
            $imageData,
            $maxWidth,
            $maxHeight,
            'jpeg',  // Convert to JPEG for better compression
            80       // Quality 80 is good balance of size vs clarity
        );
    }

    /**
     * Get image dimensions without loading full image
     *
     * @param string $imageData Raw binary image data
     * @return array|null ['width' => int, 'height' => int] or null on failure
     */
    public static function getDimensions(string $imageData): ?array {
        $info = @getimagesizefromstring($imageData);
        if ($info === false) {
            return null;
        }

        return [
            'width' => $info[0],
            'height' => $info[1],
            'mimeType' => $info['mime'] ?? null
        ];
    }

    /**
     * Detect image format from binary data
     *
     * @param string $imageData Raw binary image data
     * @return string Format name (jpeg, png, gif, webp, unknown)
     */
    public static function detectFormat(string $imageData): string {
        $info = @getimagesizefromstring($imageData);
        if ($info === false) {
            return 'unknown';
        }

        $type = $info[2];

        switch ($type) {
            case IMAGETYPE_JPEG:
                return 'jpeg';
            case IMAGETYPE_PNG:
                return 'png';
            case IMAGETYPE_GIF:
                return 'gif';
            case IMAGETYPE_WEBP:
                return 'webp';
            default:
                return 'unknown';
        }
    }

    /**
     * Check if an image needs resizing based on dimensions
     *
     * @param string $imageData Raw binary image data
     * @param int $maxWidth Maximum allowed width
     * @param int $maxHeight Maximum allowed height
     * @return bool True if image exceeds max dimensions
     */
    public static function needsResize(
        string $imageData,
        int $maxWidth = self::DEFAULT_MAX_WIDTH,
        int $maxHeight = self::DEFAULT_MAX_HEIGHT
    ): bool {
        $dims = self::getDimensions($imageData);
        if ($dims === null) {
            return false;
        }

        return $dims['width'] > $maxWidth || $dims['height'] > $maxHeight;
    }

    /**
     * Estimate base64 size of image data
     *
     * @param string $imageData Raw binary image data
     * @return int Estimated base64 encoded size in bytes
     */
    public static function estimateBase64Size(string $imageData): int {
        // Base64 encoding increases size by ~33%
        return (int) ceil(strlen($imageData) * 4 / 3);
    }
}
