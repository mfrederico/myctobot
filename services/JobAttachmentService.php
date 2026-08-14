<?php
/**
 * Job Attachment Service
 *
 * Downloads issue attachments (designs, mockups, screenshots) into the job work
 * directory so the agent can open them by path.
 *
 * Handing an agent a bare attachment URL does not work: GitHub's
 * user-attachments assets 404 anonymously on private repos, and Jira's
 * /attachment/content/ URLs need the tenant's OAuth token. A path the agent
 * cannot reach is worse than no path - it reads as an instruction to look at
 * something and then fails.
 *
 * Tenant separation: everything is written inside the caller's per-job work
 * directory (which is already per-workspace), the directory is 0700 and files
 * are 0600, and every filename is sanitised and containment-checked before use.
 * Attachment filenames are attacker-controlled - they come from whoever filed
 * the issue - so they are never trusted as path components.
 */

namespace app\services;

class JobAttachmentService {

    /** @var int Default cap per file (25MB), overridable via ATTACHMENT_MAX_SIZE_MB */
    private const DEFAULT_MAX_BYTES = 25 * 1024 * 1024;

    /** @var string Subdirectory of the work dir that holds downloads */
    private const SUBDIR = 'attachments';

    /**
     * MIME types worth pulling down. Anything else is left as a URL - we are
     * fetching briefing material for the agent, not mirroring arbitrary files.
     */
    private const PULLABLE_MIME = [
        'image/png'                => 'png',
        'image/jpeg'               => 'jpg',
        'image/jpg'                => 'jpg',
        'image/gif'                => 'gif',
        'image/webp'               => 'webp',
        'image/svg+xml'            => 'svg',
        'application/pdf'          => 'pdf',
        'text/plain'               => 'txt',
        'text/csv'                 => 'csv',
        'text/markdown'            => 'md',
        'application/json'         => 'json',
    ];

    /**
     * GitHub attachment URL shapes found in issue and comment bodies.
     */
    private const GITHUB_ASSET_PATTERNS = [
        '#https://github\.com/user-attachments/assets/[A-Za-z0-9\-]+#i',
        '#https://github\.com/[^/\s]+/[^/\s]+/assets/\d+/[A-Za-z0-9\-]+#i',
        '#https://[A-Za-z0-9\-]*\.?githubusercontent\.com/[^\s<>"\')]+#i',
    ];

    /**
     * Pull GitHub attachments referenced in issue/comment text.
     *
     * GitHub answers the asset URL with a 302 to a pre-signed S3 link. The token
     * is sent ONLY on that first hop - the redirect target carries its own
     * signature, so forwarding the Authorization header to Amazon would leak the
     * tenant's GitHub credential to a third-party host for no benefit.
     *
     * @param string $text      Issue body + comments to scan
     * @param string $token     GitHub access token for THIS tenant
     * @param string $workDir   Per-job work directory
     * @return array{stored:array,failed:array,skipped:int,urls:array}
     */
    public static function pullGitHub(string $text, string $token, string $workDir): array {
        $out = ['stored' => [], 'failed' => [], 'unsupported' => [], 'skipped' => 0, 'urls' => []];

        $urls = self::findGitHubAssetUrls($text);
        if (empty($urls)) {
            return $out;
        }

        $dir = self::prepareDir($workDir);
        if ($dir === null) {
            $out['failed'][] = 'could not create attachments directory';
            return $out;
        }

        $index = 0;
        foreach ($urls as $url) {
            $index++;
            $out['urls'][] = $url;

            try {
                $resolved = self::resolveGitHubAsset($url, $token);
                if ($resolved === null) {
                    $out['failed'][] = self::shortUrl($url) . ' (could not resolve download URL)';
                    continue;
                }

                // Second hop: signed URL, no credentials attached.
                $fetched = self::fetch($resolved['url'], []);
                if ($fetched === null) {
                    $out['failed'][] = self::shortUrl($url) . ' (download failed)';
                    continue;
                }

                $mime = $resolved['mime'] ?: $fetched['mime'];
                $stored = self::store($dir, $workDir, $index, $resolved['name'], $mime, $fetched['body']);

                if ($stored === null) {
                    $out['failed'][] = self::shortUrl($url) . ' (not a pullable type, too large, or unwritable)';
                    continue;
                }

                if (!empty($stored['reused'])) {
                    $out['skipped']++;
                }
                $out['stored'][] = $stored;

            } catch (\Throwable $e) {
                // A design file that will not download is a worse brief, not a
                // failed job - never let this abort the dispatch.
                $out['failed'][] = self::shortUrl($url) . ' (' . $e->getMessage() . ')';
            }
        }

        return $out;
    }

    /**
     * Pull Jira attachments listed on the issue.
     *
     * @param array  $attachments $issue['fields']['attachment']
     * @param object $jiraClient  Tenant-scoped JiraClient
     * @param string $workDir     Per-job work directory
     * @return array{stored:array,failed:array,skipped:int,urls:array}
     */
    public static function pullJira(array $attachments, $jiraClient, string $workDir): array {
        $out = ['stored' => [], 'failed' => [], 'unsupported' => [], 'skipped' => 0, 'urls' => []];

        if (empty($attachments)) {
            return $out;
        }

        $dir = self::prepareDir($workDir);
        if ($dir === null) {
            $out['failed'][] = 'could not create attachments directory';
            return $out;
        }

        $index = 0;
        foreach ($attachments as $att) {
            $index++;
            $name = (string) ($att['filename'] ?? '');
            $mime = (string) ($att['mimeType'] ?? '');
            $url  = (string) ($att['content'] ?? '');
            $size = (int) ($att['size'] ?? 0);

            if ($url === '') {
                $out['failed'][] = $name . ' (no content URL)';
                continue;
            }
            $out['urls'][] = $url;

            // Not a type we mirror - still name it, because silence here reads as
            // "this ticket had no attachments" and the agent never asks about it.
            if (!self::isPullable($mime, $name)) {
                $out['unsupported'][] = $name . ' (' . ($mime ?: 'unknown type') . ')';
                continue;
            }

            if ($size > self::maxBytes()) {
                $out['failed'][] = $name . ' (' . self::formatSize($size) . ', over the limit)';
                continue;
            }

            try {
                // Reuse the tenant's authenticated client rather than rebuilding auth.
                $body = method_exists($jiraClient, 'downloadAttachmentContent')
                    ? $jiraClient->downloadAttachmentContent($url)
                    : null;

                if (empty($body)) {
                    $out['failed'][] = $name . ' (download failed)';
                    continue;
                }

                $stored = self::store($dir, $workDir, $index, $name, $mime, $body);
                if ($stored === null) {
                    $out['failed'][] = $name . ' (not a pullable type, too large, or unwritable)';
                    continue;
                }

                if (!empty($stored['reused'])) {
                    $out['skipped']++;
                }
                $out['stored'][] = $stored;

            } catch (\Throwable $e) {
                $out['failed'][] = $name . ' (' . $e->getMessage() . ')';
            }
        }

        return $out;
    }

    /**
     * Render the downloaded files as a prompt section.
     *
     * Paths are relative to the work directory and labelled as attachments, so
     * they do not read as application code that has strayed into an odd folder.
     *
     * @param array  $result Result from pullGitHub()/pullJira()
     * @param string $label  Source name for the heading (e.g. 'GitHub')
     * @return string
     */
    public static function formatForPrompt(array $result, string $label = ''): string {
        $stored      = $result['stored'] ?? [];
        $failed      = $result['failed'] ?? [];
        $unsupported = $result['unsupported'] ?? [];

        if (empty($stored) && empty($failed) && empty($unsupported)) {
            return '';
        }

        $heading = trim($label . ' Attachments');
        $out = "\n## {$heading}\n";

        if (!empty($stored)) {
            $out .= "These files were downloaded from the ticket into your working directory. "
                  . "Open them directly - do NOT try to fetch the original URLs, they require credentials you do not have.\n\n";

            foreach ($stored as $file) {
                $kind = $file['kind'] === 'image'
                    ? 'a design, mockup or screenshot'
                    : 'an attached document';
                $out .= "- `{$file['path']}` ({$file['mime']}, " . self::formatSize($file['size']) . ") — {$kind}\n";
            }
            $out .= "\n";
        }

        if (!empty($unsupported)) {
            $out .= "Also attached to the ticket but not downloaded (unsupported file type). "
                  . "Ask the requester for the contents if you need them:\n";
            foreach ($unsupported as $f) {
                $out .= "- {$f}\n";
            }
            $out .= "\n";
        }

        if (!empty($failed)) {
            // Say so explicitly: silence here reads as "there were no attachments".
            $out .= "Could not download (do not retry, the agent has no credentials for these):\n";
            foreach ($failed as $f) {
                $out .= "- {$f}\n";
            }
            $out .= "\n";
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Create the attachments directory, owner-only.
     */
    private static function prepareDir(string $workDir): ?string {
        $dir = rtrim($workDir, '/') . '/' . self::SUBDIR;

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }

        // Tighten even if a previous run created it 0755: these are a customer's
        // design files and the work dir lives in shared /tmp.
        @chmod($dir, 0700);

        return $dir;
    }

    /**
     * Write bytes to disk and return the descriptor, or null if rejected.
     *
     * Names are prefixed with an index because attachment names collide - two
     * different screenshots are routinely both called image.png, and writing by
     * name alone would silently leave only one of them.
     */
    private static function store(
        string $dir,
        string $workDir,
        int $index,
        string $name,
        string $mime,
        string $bytes
    ): ?array {
        $size = strlen($bytes);

        if ($size === 0 || $size > self::maxBytes()) {
            return null;
        }

        $mime = self::normaliseMime($mime);
        if (!self::isPullable($mime, $name)) {
            return null;
        }

        $safe = self::safeName($name, $mime, $index);
        $path = $dir . '/' . $safe;
        $rel  = self::SUBDIR . '/' . $safe;

        // Containment: after sanitising there should be no way out of the work
        // directory, but verify rather than assume - the name came from a user.
        $expected = rtrim($workDir, '/') . '/' . $rel;
        if ($path !== $expected || str_contains($safe, '/') || str_contains($safe, '\\')) {
            return null;
        }

        $kind = str_starts_with($mime, 'image/') ? 'image' : 'document';

        // Idempotent by size: a re-run over the same issue does not re-download
        // every mockup.
        if (is_file($path) && filesize($path) === $size) {
            return ['path' => $rel, 'name' => $name, 'mime' => $mime, 'size' => $size, 'kind' => $kind, 'reused' => true];
        }

        // Written to a temp name and moved, so a half-written file is never
        // mistaken for a complete one by the size check above.
        $tmp = $path . '.part';
        if (@file_put_contents($tmp, $bytes) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return null;
        }
        @chmod($path, 0600);

        return ['path' => $rel, 'name' => $name, 'mime' => $mime, 'size' => $size, 'kind' => $kind, 'reused' => false];
    }

    /**
     * Build a filesystem-safe name. Never trusted as a path component.
     */
    private static function safeName(string $name, string $mime, int $index): string {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? '';
        $name = ltrim($name, '.');           // no dotfiles, no traversal remnants
        $name = substr($name, 0, 100);

        if ($name === '') {
            $name = 'attachment';
        }

        // Give it an extension if the name arrived without one (GitHub asset
        // URLs are bare UUIDs), so the agent's tools recognise the type.
        if (pathinfo($name, PATHINFO_EXTENSION) === '') {
            $ext = self::PULLABLE_MIME[$mime] ?? null;
            if ($ext) {
                $name .= '.' . $ext;
            }
        }

        return $index . '-' . $name;
    }

    private static function isPullable(string $mime, string $name): bool {
        $mime = self::normaliseMime($mime);

        if (isset(self::PULLABLE_MIME[$mime])) {
            return true;
        }

        // Fall back to the extension when the server sent nothing useful.
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return $ext !== '' && in_array($ext, self::PULLABLE_MIME, true);
    }

    private static function normaliseMime(string $mime): string {
        $mime = strtolower(trim($mime));
        if (str_contains($mime, ';')) {
            $mime = trim(explode(';', $mime)[0]);
        }
        return $mime;
    }

    /**
     * Ask GitHub where the asset really lives, without following the redirect.
     *
     * @return array{url:string,name:string,mime:string}|null
     */
    private static function resolveGitHubAsset(string $url, string $token): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'User-Agent: MyCTOBot',
                'Accept: */*',
            ],
        ]);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSz = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $type     = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        // No curl_close(): a no-op since PHP 8.0 and deprecated in 8.5, where it
        // prints a notice into the runner's output on every attachment.
        unset($ch);

        if ($response === false) {
            return null;
        }

        // Already the file itself (githubusercontent links serve directly).
        if ($status === 200 && !str_starts_with(self::normaliseMime($type), 'text/html')) {
            return [
                'url'  => $url,
                'name' => basename(parse_url($url, PHP_URL_PATH) ?: ''),
                'mime' => self::normaliseMime($type),
            ];
        }

        if ($status < 300 || $status >= 400) {
            return null;
        }

        $headers  = substr($response, 0, $headerSz);
        $location = '';
        if (preg_match('/^location:\s*(.+)$/mi', $headers, $m)) {
            $location = trim($m[1]);
        }
        if ($location === '') {
            return null;
        }

        // The signed URL carries the real filename and content type.
        $path = parse_url($location, PHP_URL_PATH) ?: '';
        $name = basename($path);
        $mime = '';
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        if (!empty($query['response-content-type'])) {
            $mime = self::normaliseMime((string) $query['response-content-type']);
        }

        return ['url' => $location, 'name' => $name, 'mime' => $mime];
    }

    /**
     * @return array{body:string,mime:string}|null
     */
    private static function fetch(string $url, array $headers): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => array_merge(['User-Agent: MyCTOBot'], $headers),
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        unset($ch);

        if ($body === false || $status !== 200 || $body === '') {
            return null;
        }

        return ['body' => $body, 'mime' => self::normaliseMime($type)];
    }

    /**
     * @return string[]
     */
    private static function findGitHubAssetUrls(string $text): array {
        $urls = [];

        foreach (self::GITHUB_ASSET_PATTERNS as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[0] as $url) {
                    $urls[] = rtrim($url, '.,;:!?)');
                }
            }
        }

        return array_values(array_unique($urls));
    }

    public static function maxBytes(): int {
        $env = getenv('ATTACHMENT_MAX_SIZE_MB');
        if ($env !== false && is_numeric($env)) {
            return (int) $env * 1024 * 1024;
        }
        return self::DEFAULT_MAX_BYTES;
    }

    private static function formatSize(int $bytes): string {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . 'MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . 'KB';
        }
        return $bytes . 'B';
    }

    private static function shortUrl(string $url): string {
        return strlen($url) > 70 ? substr($url, 0, 67) . '...' : $url;
    }
}
