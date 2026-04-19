<?php
/**
 * Cron: Check for inactive sales reps and disable + reassign
 * Run daily: 0 1 * * * php /home/ubuntu/production/myctobot/scripts/cron-inactivity-check.php
 */

require_once __DIR__ . '/../../bootstrap.php';

use \app\Bean;

$thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));

// Find sales reps who haven't logged in for 30+ days
$inactiveReps = Bean::find('member',
    'level = ? AND (is_disabled IS NULL OR is_disabled = 0) AND (last_login IS NULL OR last_login < ?)',
    [LEVELS['SALES'], $thirtyDaysAgo]
);

foreach ($inactiveReps as $rep) {
    echo "Disabling inactive sales rep: {$rep->username} (#{$rep->id}), last login: " . ($rep->lastLogin ?? 'never') . "\n";

    // Disable the account
    $rep->isDisabled = 1;
    $rep->disabledAt = date('Y-m-d H:i:s');
    $rep->disabledReason = 'Inactivity (30+ days)';
    Bean::store($rep);

    // Reassign all their CRM contacts to ROOT (id=1)
    $contacts = Bean::find('crmcontact', 'member_id = ?', [$rep->id]);
    $count = 0;
    foreach ($contacts as $contact) {
        $contact->memberId = SYSTEM_ADMIN_ID;
        Bean::store($contact);
        $count++;
    }

    // Log the activity
    $activity = Bean::dispense('crmactivity');
    $activity->memberId = SYSTEM_ADMIN_ID;
    $activity->activityType = 'rep_disabled';
    $activity->description = "Disabled {$rep->username} for inactivity. Reassigned {$count} contact(s) to admin.";
    Bean::store($activity);

    echo "  -> Reassigned {$count} contact(s) to admin\n";
}

echo "Done. Checked " . count($inactiveReps) . " inactive rep(s).\n";
