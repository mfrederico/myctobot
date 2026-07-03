<?php
/**
 * EgressGuard
 * Centralized SSRF protection for any server-side request whose URL is derived
 * from user/tenant input (knowledgebase imports, webhook_out, mcp_call, plugin
 * repositories, etc.).
 *
 * Usage:
 *   use app\services\EgressGuard;
 *   $check = EgressGuard::validate($url);
 *   if (!$check['allowed']) { throw new \Exception('Blocked URL: ' . $check['reason']); }
 *   $ch = curl_init($url);
 *   curl_setopt_array($ch, EgressGuard::curlOptions());   // safe defaults
 *
 * Model: only http/https, resolve the host and reject any resolved IP that is
 * loopback/private/link-local/reserved, and disable redirect following (a
 * redirect is a common SSRF bypass). Callers that must follow redirects should
 * re-run validate() on each hop themselves.
 */

namespace app\services;

class EgressGuard {

    private static array $allowedSchemes = ['http', 'https'];

    /**
     * Validate a URL for safe outbound fetching.
     *
     * @return array{allowed:bool, reason:string, host:?string, ips:array}
     */
    public static function validate(string $url): array {
        $url = trim($url);
        if ($url === '') {
            return ['allowed' => false, 'reason' => 'empty URL', 'host' => null, 'ips' => []];
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return ['allowed' => false, 'reason' => 'unparseable URL', 'host' => null, 'ips' => []];
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, self::$allowedSchemes, true)) {
            return ['allowed' => false, 'reason' => "scheme '{$scheme}' not allowed", 'host' => null, 'ips' => []];
        }

        $host = $parts['host'];

        // Reject credentials in the URL (user:pass@host) — often used to confuse parsers.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['allowed' => false, 'reason' => 'credentials in URL not allowed', 'host' => $host, 'ips' => []];
        }

        // Resolve the host to every IP and reject if ANY is internal (defense
        // against split-horizon / multi-record DNS).
        $ips = self::resolveHost($host);
        if (empty($ips)) {
            return ['allowed' => false, 'reason' => "host '{$host}' did not resolve", 'host' => $host, 'ips' => []];
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return ['allowed' => false, 'reason' => "host resolves to non-public address {$ip}", 'host' => $host, 'ips' => $ips];
            }
        }

        return ['allowed' => true, 'reason' => 'ok', 'host' => $host, 'ips' => $ips];
    }

    /**
     * Convenience: throw if the URL is not allowed.
     * @throws \RuntimeException
     */
    public static function assertAllowed(string $url): void {
        $r = self::validate($url);
        if (!$r['allowed']) {
            throw new \RuntimeException('Blocked outbound request: ' . $r['reason']);
        }
    }

    /**
     * Safe curl option defaults for guarded requests: restrict protocols and do
     * NOT follow redirects (a redirect to an internal address is a classic SSRF
     * bypass). Merge/override as needed, but keep FOLLOWLOCATION off unless you
     * re-validate each hop.
     */
    public static function curlOptions(int $timeout = 15): array {
        $opts = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
        ];
        // CURLOPT_PROTOCOLS may be undefined on very old builds; guard it.
        if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $opts[CURLOPT_PROTOCOLS]       = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        return $opts;
    }

    /**
     * Resolve a hostname to all IPv4 + IPv6 addresses. If the host is already an
     * IP literal, return it directly.
     */
    private static function resolveHost(string $host): array {
        $host = trim($host, '[]'); // strip IPv6 brackets if present
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $rec) {
                if (!empty($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }
        return array_values(array_unique($ips));
    }

    /**
     * True only for globally-routable public addresses. Rejects private, loopback,
     * link-local, and reserved ranges for both IPv4 and IPv6.
     */
    private static function isPublicIp(string $ip): bool {
        // FILTER_FLAG_NO_PRIV_RANGE + NO_RES_RANGE covers RFC1918, loopback,
        // link-local, and reserved blocks for v4 and v6.
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
