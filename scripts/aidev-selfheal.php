#!/usr/bin/env php
<?php
/**
 * AI Developer self-heal
 *
 * Run this after signing the Claude CLI back in on a workstation.
 *
 * When the CLI has no usable login it still starts: it prints "Login expired -
 * Please run /login" and parks at a prompt. The tmux session stays alive, so
 * the job looks like it is running, its record never reconciles, and its
 * concurrency slot is held. Enough of those and every new dispatch is refused.
 *
 * Those agents CANNOT be rescued in place. /login is an interactive OAuth flow
 * that needs a browser, and the agent never consumed its task anyway - the
 * spawn logs show "Cogitated for 0s" before it parked. The recoverable unit is
 * the JOB, not the session: clear the wreckage, then let the normal dispatch
 * path run it again from the top.
 *
 * Usage:
 *   php scripts/aidev-selfheal.php --workspace=gwt [--member=1] [--retry] [--dry-run]
 *
 *   --retry    re-add the 'ai-dev' label so the webhook re-dispatches the job
 *   --dry-run  report only, change nothing
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/bootstrap.php';

$opts = getopt('', ['workspace:', 'member:', 'retry', 'dry-run', 'help']);

if (isset($opts['help']) || empty($opts['workspace'])) {
    echo "Usage: php scripts/aidev-selfheal.php --workspace=SLUG [--member=ID] [--retry] [--dry-run]\n";
    exit(isset($opts['help']) ? 0 : 1);
}

$workspace = (string) $opts['workspace'];
$memberId  = (int) ($opts['member'] ?? 1);
$retry     = isset($opts['retry']);
$dryRun    = isset($opts['dry-run']);

$_SERVER['WORKSPACE'] = $workspace;
$configFile = $baseDir . "/conf/config.{$workspace}.ini";
if (!file_exists($configFile)) {
    fwrite(STDERR, "No config for workspace '{$workspace}' ({$configFile})\n");
    exit(1);
}
new \app\Bootstrap($configFile);

require_once $baseDir . '/services/ClaudeCredentialService.php';
require_once $baseDir . '/services/AIDevJobService.php';
require_once $baseDir . '/services/GitHubClient.php';

use app\Bean;
use app\services\ClaudeCredentialService;
use app\services\AIDevJobService;
use app\services\GitHubClient;

echo "AI Developer self-heal - workspace={$workspace} member={$memberId}"
   . ($dryRun ? " [DRY RUN]" : "") . "\n\n";

// 1. Is the CLI actually signed in now? Healing before that just re-sticks
//    every job on the next dispatch.
$auth = ClaudeCredentialService::checkCli();
printf("Claude auth: %s (%s)\n", $auth['signed_in'] ? 'OK' : 'NOT SIGNED IN', $auth['reason']);

if (!$auth['signed_in']) {
    echo "\nRefusing to heal: sign in first with `claude` then /login on the workstation,\n"
       . "otherwise re-dispatched jobs park on the same banner.\n";
    exit(2);
}

// 2. Which jobs are stuck?
$stuck = [];
foreach (Bean::find('aidevjobs', ' member_id = ? AND status = ? ', [$memberId, 'running']) as $job) {
    $stuck[] = ['id' => (int) $job->id, 'issue_key' => (string) $job->issue_key];
}

if (empty($stuck)) {
    echo "\nNothing in 'running'. Nothing to do.\n";
    exit(0);
}

echo "\nJobs currently 'running':\n";
foreach ($stuck as $s) {
    echo "  #{$s['id']}  {$s['issue_key']}\n";
}

if ($dryRun) {
    echo "\n[DRY RUN] would reap any whose session is gone or parked on the login banner.\n";
    exit(0);
}

// 3. Reap. This also posts the reason to the issue and clears its labels.
$reaped = AIDevJobService::healStuckJobs($memberId);
echo "\nReaped {$reaped} stuck job(s).\n";

if ($reaped === 0) {
    echo "All running jobs have healthy sessions - left alone.\n";
    exit(0);
}

// 4. Optionally re-dispatch, by re-adding the label the reap just removed.
//    Going through the webhook rather than calling the dispatcher directly
//    keeps one dispatch path instead of two that can drift.
if (!$retry) {
    echo "Re-add the 'ai-dev' label to retry, or re-run with --retry.\n";
    exit(0);
}

echo "\nRe-dispatching (re-adding the 'ai-dev' label):\n";
foreach ($stuck as $s) {
    $job = Bean::load('aidevjobs', $s['id']);
    if (!$job->id || $job->status !== 'failed') {
        continue;   // not one we just reaped
    }

    if (!preg_match('/^([^\/]+)\/([^#]+)#(\d+)$/', $s['issue_key'], $m)) {
        echo "  - {$s['issue_key']}: not a GitHub issue key, skipping\n";
        continue;
    }

    try {
        $token = \app\Github::getTokenStatic($m[1], $m[2], $memberId);
        if (empty($token)) {
            echo "  ! {$s['issue_key']}: no GitHub token\n";
            continue;
        }
        (new GitHubClient($token))->addLabels($m[1], $m[2], (int) $m[3], ['ai-dev']);
        echo "  + {$s['issue_key']}: ai-dev re-added\n";
    } catch (\Throwable $e) {
        echo "  ! {$s['issue_key']}: " . $e->getMessage() . "\n";
    }
}

echo "\nDone.\n";
