<?php
/**
 * Cron: Send daily magic login links to sales reps
 * Run weekday mornings: 0 6 * * 1-5 php /home/ubuntu/production/myctobot/scripts/cron-magic-links.php
 */

require_once __DIR__ . '/../../bootstrap.php';

use \app\Bean;

// Load app secret from config
$config = parse_ini_file(__DIR__ . '/../../conf/config.ini', true);
$appSecret = $config['security']['magic_link_secret'] ?? 'myctobot-magic-default-key';
$baseUrl = $config['app']['baseurl'] ?? 'https://shipcannon.myctobot.ai';

// Find active sales reps (level = 75) who are not disabled
$salesReps = Bean::find('member', 'level = ? AND (is_disabled IS NULL OR is_disabled = 0)', [LEVELS['SALES']]);

$today = date('Y-m-d');
$expiresAt = $today . ' 23:59:59';

foreach ($salesReps as $rep) {
    // Generate deterministic HMAC token for today
    $token = hash_hmac('sha256', $rep->id . ':' . $today, $appSecret);

    // Check if link already exists for today
    $existing = Bean::findOne('magiclink', 'member_id = ? AND expires_at = ?', [$rep->id, $expiresAt]);
    if ($existing) {
        continue; // Already sent today
    }

    // Create magic link record
    $link = Bean::dispense('magiclink');
    $link->memberId = $rep->id;
    $link->token = $token;
    $link->expiresAt = $expiresAt;
    Bean::store($link);

    // TODO: Send email via MailerService when mail is configured
    $loginUrl = "{$baseUrl}/magic-login?token={$token}";
    $name = $rep->displayName();
    echo "Magic link for {$rep->email}: {$loginUrl}\n";
}

echo "Done. Processed " . count($salesReps) . " sales rep(s).\n";
