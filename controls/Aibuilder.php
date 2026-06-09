<?php
/**
 * AI Builder — in-app entry to the customer's jailed coding-agent terminal.
 *
 * The tenant admin opens /aibuilder on their own instance subdomain. We mint a
 * short-lived HMAC token and render an xterm.js terminal that connects (same-origin)
 * to the aibuilder-terminal bridge, which spawns a bubblewrap-jailed agent (Claude
 * Code or qwen-code) confined to THIS instance. Checkpoint/Rollback call the capricorn
 * instance scripts so the customer can undo anything — including SQLite DB changes.
 *
 * The jail is the security boundary (capricorn/bin/jail-run.sh). This controller only
 * gates access (tenant admin), mints the token, and brokers snapshot/rollback. App +
 * slug are strictly validated before any shell use.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

class Aibuilder extends Control {

    private const SLUG_RE = '/^[a-z][a-z0-9]{1,49}$/';
    private const APP_RE  = '/^[a-z][a-z0-9]{1,30}$/';

    private function cfg(): array {
        return @parse_ini_file('conf/aibuilder.ini', true) ?: [];
    }

    /** App codename = source folder name, derived from the running directory. */
    private function appCode(): string {
        $dir = basename(getcwd());                 // "<sub>.<app>" (instance) or "<app>" (shared)
        $app = (strpos($dir, '.') !== false) ? substr($dir, strpos($dir, '.') + 1) : $dir;
        return strtolower($app);
    }

    private function slug(): string {
        return strtolower((string) (Flight::get('workspace') ?: ($_SERVER['WORKSPACE'] ?? '')));
    }

    private function instanceDir(string $app, string $sub): string {
        return '/var/www/html/default/' . $sub . '.' . $app;
    }

    /** base64url(payload) + "." + hex(HMAC-SHA256(payload, secret)) — mirrors mint-token.js */
    private function mintToken(string $app, string $sub, int $memberId): string {
        $cfg    = $this->cfg();
        $secret = (string) ($cfg['token']['secret'] ?? '');
        $ttl    = (int) ($cfg['token']['ttl'] ?? 120);
        $payload = json_encode(['app' => $app, 'sub' => $sub, 'member_id' => $memberId, 'exp' => time() + $ttl]);
        $b64 = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        return $b64 . '.' . hash_hmac('sha256', $b64, $secret);
    }

    private function minLevel(): int {
        return (int) ($this->cfg()['access']['min_level'] ?? LEVELS['ADMIN']);
    }

    /** GET /aibuilder — render the terminal. */
    public function index() {
        if (!$this->requireLevel($this->minLevel())) return;

        $app = $this->appCode();
        $sub = $this->slug();
        $hasInstance = preg_match(self::APP_RE, $app) && preg_match(self::SLUG_RE, $sub)
            && is_file($this->instanceDir($app, $sub) . '/public/index.php');

        $this->render('aibuilder/index', [
            'title'          => 'AI Builder',
            'ab_app'         => $app,
            'ab_sub'         => $sub,
            'ab_token'       => $hasInstance ? $this->mintToken($app, $sub, (int) $this->member->id) : '',
            'ab_wspath'      => (string) ($this->cfg()['bridge']['ws_path'] ?? '/aibuilder/ws'),
            'ab_hasInstance' => (bool) $hasInstance,
        ]);
    }

    /** GET /aibuilder/refresh — fresh token (AJAX, e.g. on reconnect). */
    public function refresh() {
        if (!$this->requireLevel($this->minLevel())) return;
        [$app, $sub] = $this->validatedTarget();
        if (!$app) return;
        Flight::json(['success' => true, 'token' => $this->mintToken($app, $sub, (int) $this->member->id)]);
    }

    /** POST /aibuilder/checkpoint — take a rollback checkpoint. */
    public function checkpoint() {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        [$app, $sub] = $this->validatedTarget();
        if (!$app) return;
        $label = preg_replace('/[^a-z0-9]/i', '', (string) ($_POST['label'] ?? ''));
        $out = $this->runScript('snapshot-instance.sh', array_filter([$app, $sub, $label]));
        Flight::json(['success' => $out['ok'], 'checkpoint' => trim($out['stdout']), 'log' => $out['stderr']]);
    }

    /** GET /aibuilder/checkpoints — list checkpoints for the rollback UI. */
    public function checkpoints() {
        if (!$this->requireLevel($this->minLevel())) return;
        [$app, $sub] = $this->validatedTarget();
        if (!$app) return;
        $out = $this->runScript('rollback-instance.sh', ['--list', $app, $sub]);
        $items = [];
        foreach (explode("\n", $out['stdout']) as $line) {
            if (preg_match('/^\s*(checkpoint-\S+)\s+(\S+)\s+(\S+)/', $line, $m)) {
                $items[] = ['name' => $m[1], 'date' => $m[2], 'commit' => $m[3]];
            }
        }
        Flight::json(['success' => true, 'checkpoints' => $items]);
    }

    /** POST /aibuilder/rollback/<checkpoint> — restore a checkpoint. */
    public function rollback() {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        [$app, $sub] = $this->validatedTarget();
        if (!$app) return;
        $ckpt = (string) ($this->opId() ?: ($_POST['checkpoint'] ?? 'checkpoint-baseline'));
        if (!preg_match('/^[a-z0-9-]{3,60}$/i', $ckpt)) {
            Flight::json(['success' => false, 'error' => 'Invalid checkpoint'], 400); return;
        }
        $out = $this->runScript('rollback-instance.sh', [$app, $sub, $ckpt]);
        Flight::json(['success' => $out['ok'], 'log' => $out['stdout'] . $out['stderr']]);
    }

    // --- helpers -------------------------------------------------------------

    private function validatedTarget(): array {
        $app = $this->appCode(); $sub = $this->slug();
        if (!preg_match(self::APP_RE, $app) || !preg_match(self::SLUG_RE, $sub)
            || !is_file($this->instanceDir($app, $sub) . '/public/index.php')) {
            Flight::json(['success' => false, 'error' => 'No AI Builder instance for this workspace'], 404);
            return [null, null];
        }
        return [$app, $sub];
    }

    private function runScript(string $script, array $args): array {
        $cfg = $this->cfg();
        $binDir = rtrim((string) ($cfg['ops']['bin_dir'] ?? '/home/ubuntu/capricorn/bin'), '/');
        $prefix = (string) ($cfg['ops']['sudo_prefix'] ?? '');
        $cmd = trim($prefix . ' ' . escapeshellarg($binDir . '/' . $script));
        foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string) $a); }
        $cmd .= ' 2>/tmp/aibuilder.err';
        $stdout = shell_exec($cmd) ?? '';
        $stderr = @file_get_contents('/tmp/aibuilder.err') ?: '';
        $ok = (stripos($stdout, 'DONE') !== false) || (stripos($stdout, 'checkpoint') !== false);
        return ['ok' => $ok, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
