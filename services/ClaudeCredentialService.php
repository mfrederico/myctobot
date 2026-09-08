<?php
/**
 * Claude Credential Service
 *
 * Answers one question: will the Claude CLI on a workstation actually get a
 * session, or will it start, print "Login expired - Please run /login", and sit
 * at a prompt forever?
 *
 * That failure is worse than a crash. The tmux session stays ALIVE, so every
 * liveness check says the job is running, the record never reconciles, and the
 * slot is held until someone notices by hand. Three of those and the
 * concurrency limiter refuses all new work.
 *
 * Approach ported from tiknix's app\AgentState::credentialsUsable(), including
 * its central rule: ONLY CLAIM "NO" WHEN IT CAN BE PROVEN. An unreadable file
 * or an unfamiliar shape counts as signed in - refusing to dispatch out of
 * ignorance would block real work, which is the opposite of the point.
 */

namespace app\services;

use \Flight as Flight;

class ClaudeCredentialService {

    /** Default location of the Claude CLI credential file on a workstation. */
    private const CRED_PATH = '~/.claude/.credentials.json';

    /** Banners the CLI shows when it has no usable session. */
    private const LOGGED_OUT_MARKERS = [
        'Login expired',
        'Not logged in',
        'Please run /login',
        'OAuth session expired',
    ];

    /**
     * Is the credential file on this host good for a session?
     *
     * @param string|null $json Raw contents of .credentials.json (null = unreadable)
     * @return array{signed_in:bool, reason:string, expires_at:?int}
     */
    public static function inspect(?string $json): array {
        if ($json === null || trim($json) === '') {
            // Cannot read it - that is not proof of being logged out. The file is
            // mode 0600 under another account in plenty of valid setups.
            return ['signed_in' => true, 'reason' => 'credentials not readable (assuming valid)', 'expires_at' => null];
        }

        $data = json_decode($json, true);
        $oauth = is_array($data) ? ($data['claudeAiOauth'] ?? null) : null;

        if (!is_array($oauth)) {
            // Unrecognised shape, or an API-key setup that needs no OAuth at all.
            return ['signed_in' => true, 'reason' => 'no OAuth block (assuming valid)', 'expires_at' => null];
        }

        $nowMs = time() * 1000;
        $live = static function ($token, $expiresAt) use ($nowMs): bool {
            if (!is_string($token) || trim($token) === '') {
                return false;
            }
            $exp = (int) $expiresAt;
            return $exp !== 0 && $exp > $nowMs;   // 0 = never issued or cleared
        };

        $accessLive = $live($oauth['accessToken'] ?? null, $oauth['expiresAt'] ?? 0);
        $refreshLive = $live($oauth['refreshToken'] ?? null, $oauth['refreshTokenExpiresAt'] ?? 0);

        $expiresAt = isset($oauth['expiresAt']) ? (int) $oauth['expiresAt'] : null;

        if ($accessLive) {
            return ['signed_in' => true, 'reason' => 'access token valid', 'expires_at' => $expiresAt];
        }
        if ($refreshLive) {
            // Expired access token but a live refresh token: the CLI refreshes on
            // start. Fine to dispatch.
            return ['signed_in' => true, 'reason' => 'access token expired, refresh token still valid', 'expires_at' => $expiresAt];
        }

        return [
            'signed_in' => false,
            'reason' => 'both access and refresh tokens are expired or empty - the workstation needs `claude` + /login',
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Read and inspect the credential file on a workstation.
     *
     * @param string|null $sshPrefix e.g. "ssh -i key -p 22 user@host" (null = local)
     * @return array{signed_in:bool, reason:string, expires_at:?int}
     */
    public static function checkWorkstation(?string $sshPrefix = null): array {
        $read = 'cat ' . self::CRED_PATH . ' 2>/dev/null';
        $cmd = $sshPrefix ? $sshPrefix . ' ' . escapeshellarg($read) : $read;

        $json = @shell_exec($cmd);

        return self::inspect(is_string($json) ? $json : null);
    }

    /**
     * Does this terminal output show the CLI sitting logged out?
     *
     * Used to spot the zombie case: session alive, agent doing nothing, because
     * it is parked on the login banner.
     */
    public static function looksLoggedOut(string $paneText): bool {
        // Strip ANSI so the markers survive the CLI's colouring.
        $clean = preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $paneText) ?? $paneText;

        foreach (self::LOGGED_OUT_MARKERS as $marker) {
            if (stripos($clean, $marker) !== false) {
                return true;
            }
        }

        return false;
    }
}
