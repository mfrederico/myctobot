<?php
/**
 * PdfRenderer
 * Converts an HTML string into PDF bytes using TCPDF (pure-PHP, no external binary).
 *
 * Inline CSS and a useful subset of HTML are supported. Base64 data-URI images
 * (e.g. signature PNGs from a <canvas>) are extracted to temporary files so they
 * render reliably across TCPDF versions.
 *
 * Usage:
 *   use app\services\PdfRenderer;
 *   $pdfBytes = PdfRenderer::fromHtml($html, ['title' => 'Signed Agreement']);
 */

namespace app\services;

use \TCPDF;
use \Flight as Flight;
use \Exception as Exception;

class PdfRenderer {

    /**
     * Render an HTML string to PDF bytes.
     *
     * @param string $html  HTML fragment/document (inline CSS supported).
     * @param array  $opts  Optional: title, author, subject, orientation (P|L).
     * @return string       Raw PDF bytes.
     * @throws Exception     If rendering fails.
     */
    public static function fromHtml(string $html, array $opts = []): string {
        if (!class_exists('TCPDF')) {
            throw new Exception('TCPDF is not installed (run: composer require tecnickcom/tcpdf)');
        }

        $orientation = ($opts['orientation'] ?? 'P') === 'L' ? 'L' : 'P';

        try {
            // Flatten any transparent data-URI images onto white in place. TCPDF
            // renders base64 data URIs directly, so we keep them inline — no temp
            // files or cache directory (those behave inconsistently under PHP-FPM
            // and silently drop the image).
            $html = self::normalizeDataUriImages($html);

            $pdf = new TCPDF($orientation, 'mm', 'A4', true, 'UTF-8', false);

            $pdf->SetCreator('MyCTOBot');
            $pdf->SetAuthor($opts['author'] ?? 'MyCTOBot');
            $pdf->SetTitle($opts['title'] ?? 'Document');
            if (!empty($opts['subject'])) {
                $pdf->SetSubject($opts['subject']);
            }

            // No default header/footer chrome — this is a standalone document.
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);

            $pdf->SetMargins(15, 15, 15);
            $pdf->SetAutoPageBreak(true, 15);
            $pdf->SetFont('helvetica', '', 10);

            $pdf->AddPage();
            $pdf->writeHTML($html, true, false, true, false, '');

            // 'S' returns the document as a string instead of streaming it.
            $bytes = $pdf->Output('document.pdf', 'S');
            if ($bytes === false || $bytes === '') {
                throw new Exception('TCPDF produced empty output');
            }

            return $bytes;
        } catch (\Throwable $e) {
            $log = Flight::get('log');
            if ($log) {
                $log->error('PDF rendering failed', ['error' => $e->getMessage()]);
            }
            throw new Exception('PDF rendering failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Flatten transparent base64 data-URI <img> sources onto a white background,
     * re-encoding them as opaque PNG data URIs (kept inline — no temp files).
     *
     * Signature-pad canvas exports are transparent PNGs; flattening guarantees a
     * visible mark on the white PDF page regardless of how a viewer composites
     * alpha. If GD is unavailable the original (already-valid) data URI is kept.
     */
    private static function normalizeDataUriImages(string $html): string {
        $log = Flight::get('log');

        return preg_replace_callback(
            '/src\s*=\s*([\'"])data:image\/(png|jpe?g|gif);base64,([^\'"]+)\1/i',
            function ($m) use ($log) {
                $quote = $m[1];
                $data  = base64_decode($m[3], true);
                if ($data === false) {
                    if ($log) { $log->warning('PdfRenderer: invalid base64 image data, keeping original'); }
                    return $m[0];
                }

                $flat = self::flattenToPng($data);
                if ($flat === null) {
                    if ($log) { $log->info('PdfRenderer: GD flatten unavailable, keeping original data URI'); }
                    return $m[0];
                }

                if ($log) { $log->debug('PdfRenderer: flattened signature image', ['bytes' => strlen($flat)]); }
                return 'src=' . $quote . 'data:image/png;base64,' . base64_encode($flat) . $quote;
            },
            $html
        );
    }

    /**
     * Decode raw image bytes with GD, composite onto an opaque white background,
     * and return non-alpha PNG bytes. Returns null if GD is unavailable or the
     * image can't be decoded.
     */
    private static function flattenToPng(string $bytes): ?string {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);

        $canvas = imagecreatetruecolor($w, $h);
        $white  = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
        // Composite the (possibly transparent) source over the white canvas.
        imagecopy($canvas, $src, 0, 0, 0, 0, $w, $h);

        ob_start();
        // Non-interlaced, no alpha — TCPDF parses this directly.
        $ok = imagepng($canvas, null, 6);
        $png = ob_get_clean();

        imagedestroy($src);
        imagedestroy($canvas);

        return ($ok && $png !== '') ? $png : null;
    }
}
