#!/usr/bin/env php
<?php
/**
 * Tenant Provisioning CLI (SQLite) — for AI-Builder power-user instances.
 *
 * myctobot's WorkspaceSchemaBuilder is MySQL-only; AI-Builder instances run SQLite
 * (the DB is a file inside the jail, so the agent can read/modify data and rollback
 * is atomic via git). This script builds a self-contained SQLite workspace: config
 * file, schema (via the existing Schema/Seeds, run with a SQLite-compatible harness),
 * and a REAL admin login (the seeds only scaffold the schema, they don't create one).
 *
 * Usage:
 *   php scripts/addtenant.php --tenant=slug --admin=email@domain.com [--name="Name"] [--dry-run]
 *
 * Matches dealeryes' addtenant.php signature so capricorn/bin/provision-instance.sh
 * works unchanged.
 */

if (php_sapi_name() !== 'cli') { die("CLI only.\n"); }
chdir(dirname(__DIR__));
require_once __DIR__ . '/../vendor/autoload.php';

use RedBeanPHP\R as R;

$opt = getopt('', ['tenant:', 'admin:', 'name:', 'dry-run']);
$slug   = $opt['tenant'] ?? null;
$admin  = $opt['admin'] ?? null;
$name   = $opt['name'] ?? null;
$dryRun = isset($opt['dry-run']);

$errors = [];
if (empty($slug))  $errors[] = '--tenant=slug is required';
elseif (!preg_match('/^[a-z][a-z0-9]{1,49}$/', $slug)) $errors[] = 'slug must start with a letter, lowercase alnum, 2-50 chars';
if (empty($admin)) $errors[] = '--admin=email is required';
elseif (!filter_var($admin, FILTER_VALIDATE_EMAIL)) $errors[] = 'invalid --admin email';
if ($errors) { foreach ($errors as $e) fwrite(STDERR, "ERROR: $e\n"); exit(1); }

$name       = $name ?: ucfirst($slug);
$configPath = "conf/config.{$slug}.ini";
$dbPath     = "database/{$slug}.sqlite";
$sessionName = 'MCTO_' . strtoupper($slug);
$baseUrl    = "https://{$slug}.myctobot.ai";
$now        = date('Y-m-d H:i:s');

if (file_exists($configPath)) { fwrite(STDERR, "ERROR: config exists: $configPath\n"); exit(1); }

echo "=== myctobot Tenant Provisioning (SQLite) ===\n";
echo "Tenant:  {$slug}\nName:     {$name}\nAdmin:    {$admin}\nConfig:   {$configPath}\nDB:       {$dbPath}\n";
if ($dryRun) { echo "\nDRY RUN — no changes.\n"; exit(0); }

// 1) Config file (SQLite)
$config = <<<INI
; myctobot - {$name}
; AI-Builder instance — auto-provisioned {$now}

[app]
name = "myctobot - {$name}"
environment = development
debug = true
build_mode = true
baseurl = "{$baseUrl}"
timezone = "America/New_York"
session_name = "{$sessionName}"
session_lifetime = 86400

[database]
type = sqlite
path = {$dbPath}

[logging]
level = DEBUG
file = log/{$slug}.log

[security]
csrf_enabled = true

[cors]
enabled = false
INI;
echo "[1/3] Writing config... ";
file_put_contents($configPath, $config);
@chmod($configPath, 0640);
echo "OK\n";

// 2) Build schema by running the existing seeds against SQLite (tolerant).
echo "[2/3] Building schema (SQLite)... ";
if (!is_dir('database')) mkdir('database', 0755, true);
@unlink($dbPath);
R::setup("sqlite:{$dbPath}");
R::freeze(false);
R::exec('PRAGMA foreign_keys = OFF');   // SQLite analogue of MySQL's SET FOREIGN_KEY_CHECKS=0

// Seed harness (SQLite-compatible $_tableCheck; same closures the seeds expect).
$_tableCheck = fn(string $t): bool =>
    (int) R::getCell("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?", [$t]) > 0;
$_deferred = [];
$_defer = function ($bean) use (&$_deferred) { if ($bean && !empty($bean->id)) $_deferred[] = $bean; };
$_scaffold = [];

$seeders = glob(__DIR__ . '/../services/Schema/Seeds/*.php');
sort($seeders);
$okCount = 0; $warns = [];
foreach ($seeders as $f) {
    try { include $f; $okCount++; }
    catch (\Throwable $e) { $warns[basename($f)] = substr($e->getMessage(), 0, 80); }
}
$tables = (int) R::getCell("SELECT COUNT(*) FROM sqlite_master WHERE type='table'");
echo "OK ({$okCount}/" . count($seeders) . " seeds, {$tables} tables)\n";
foreach ($warns as $f => $m) echo "    WARN {$f}: {$m}\n";

// 3) Create a REAL admin login (seeds only scaffold; remove scaffold members first).
echo "[3/3] Creating admin... ";
try { R::exec("DELETE FROM member WHERE email = 'schema@example.com'"); } catch (\Throwable $e) {}
$pw = bin2hex(random_bytes(6));   // 12-char one-time password
$m = R::dispense('member');
$m->email          = strtolower($admin);
$m->username       = explode('@', $admin)[0];
$m->password       = password_hash($pw, PASSWORD_DEFAULT);
$m->level          = 1;            // ROOT of their own isolated instance
$m->email_verified = true;
$m->created_at     = $now;
$m->updated_at     = $now;
R::store($m);
echo "OK\n";

echo "\n=== PROVISIONED ===\n";
echo "URL:    {$baseUrl}\n";
echo "Login:  {$admin} / {$pw}\n";
echo "Config: {$configPath}\nDB:     {$dbPath}\n";
echo "===================\n";
exit(0);
