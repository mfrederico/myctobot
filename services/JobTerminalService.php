<?php
/**
 * Job Terminal Service
 *
 * Recovers an AI Dev job's terminal output from its work directory when the
 * job record has none.
 *
 * Jobs finished before checkpoints carried result.raw_output have an empty
 * last_output, so the job view's Output card never rendered - yet the real
 * transcript is often still sitting in /tmp as spawn.log. This reads it back
 * so those jobs are not blank forever.
 *
 * Best effort by design: /tmp is cleaned, so a miss is normal and returns null
 * rather than erroring.
 */

namespace app\services;

class JobTerminalService {

    /** Where the dispatcher stages job work directories. */
    private const WORK_ROOT = '/tmp';

    /** Files to look for, most useful first. */
    private const LOG_FILES = ['spawn.log', 'session.log', 'terminal_snapshot.txt'];

    /** Cap returned text so a huge scrollback cannot blow up the page. */
    private const MAX_BYTES = 60000;

    /**
     * Find terminal output for a job.
     *
     * @param string $issueKey  e.g. "owner/repo#123" or "PROJ-45"
     * @param string $workspace Workspace slug
     * @return array{text:string, source:string}|null
     */
    public static function find(string $issueKey, string $workspace): ?array {
        $dirs = self::candidateDirs($issueKey, $workspace);

        foreach ($dirs as $dir) {
            foreach (self::LOG_FILES as $file) {
                $path = $dir . '/' . $file;

                // Containment check. The issue key is attacker-influenced (it
                // comes from an issue title/repo name), so the resolved path
                // must be provably inside the work root before anything is read.
                $real = realpath($path);
                if ($real === false || !is_file($real)) {
                    continue;
                }
                if (strpos($real, self::WORK_ROOT . '/aoe-') !== 0) {
                    continue;
                }

                $text = self::readTail($real);
                if ($text === '') {
                    continue;
                }

                return ['text' => $text, 'source' => basename($dir) . '/' . $file];
            }
        }

        return null;
    }

    /**
     * Work directories that could belong to this issue, newest first.
     *
     * The dispatcher names them aoe-{workspace}-{issue-slug}-{shortid}, and a
     * single issue routinely has several (retries, and the session-id
     * divergence that produces a staging dir plus a tmux dir).
     */
    private static function candidateDirs(string $issueKey, string $workspace): array {
        $slug = strtolower($issueKey);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '') {
            return [];
        }

        $wsSlug = preg_replace('/[^a-z0-9-]+/', '', strtolower($workspace)) ?? '';
        $pattern = self::WORK_ROOT . '/aoe-' . $wsSlug . '-' . $slug . '-*';

        $dirs = glob($pattern, GLOB_ONLYDIR) ?: [];

        // Newest first: the most recent attempt is the one worth showing.
        usort($dirs, static fn($a, $b) => filemtime($b) <=> filemtime($a));

        return $dirs;
    }

    /**
     * Read the tail of a log and make it safe to render.
     */
    private static function readTail(string $path): string {
        $size = filesize($path);
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return '';
        }

        if ($size > self::MAX_BYTES) {
            fseek($fh, -self::MAX_BYTES, SEEK_END);
        }
        $raw = (string) stream_get_contents($fh);
        fclose($fh);

        return self::clean($raw);
    }

    /**
     * Strip ANSI escapes and control characters.
     *
     * These logs are captures of a full-screen TUI, so they are dense with
     * cursor positioning and colour codes that render as garbage in HTML.
     */
    public static function clean(string $raw): string {
        $out = preg_replace('/\x1b\[[0-9;?]*[a-zA-Z]/', '', $raw) ?? $raw;
        $out = preg_replace('/\x1b\][^\x07\x1b]*(\x07|\x1b\\\\)/', '', $out) ?? $out;
        $out = str_replace("\r", "\n", $out);
        $out = preg_replace('/[^\x09\x0a\x20-\x7e]/', '', $out) ?? $out;

        // Collapse redraw frames.
        //
        // These captures are of a full-screen TUI, which repaints its whole
        // viewport on every token. Stripping the cursor-positioning codes
        // leaves the text but not the positioning, so the same paragraph
        // reappears dozens of times. Keeping only the first sighting of a line
        // turns an unreadable wall into something close to a transcript.
        $seen = [];
        $kept = [];
        foreach (explode("\n", $out) as $line) {
            $line = rtrim($line);
            $key = preg_replace('/\s+/', '', $line);

            if ($key === '') {
                // Keep blank lines only as single separators.
                if (end($kept) !== '') {
                    $kept[] = '';
                }
                continue;
            }

            // Short fragments are spinner/status debris, not content.
            if (strlen($key) < 4) {
                continue;
            }

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $kept[] = $line;
        }

        $out = implode("\n", $kept);
        $out = preg_replace('/\n{3,}/', "\n\n", $out) ?? $out;

        return trim($out);
    }
}
