#!/usr/bin/env php
<?php
/**
 * Test FUSE Model Database Switching
 *
 * Verifies that FUSE models correctly switch between:
 * - Default database (MySQL) for member, subscription
 * - User database (SQLite) for jiraboards
 */

// Bootstrap the application
require_once __DIR__ . '/../bootstrap.php';
$app = new \app\Bootstrap('conf/config.ini');

use \RedBeanPHP\R as R;
use \app\services\UserDatabaseService;
use \app\services\SubscriptionService;

echo "=== FUSE Model Database Switching Test ===\n\n";

// Get a test member ID (use the known enterprise user)
$testEmail = 'mfrederico@greenworkstools.com';
$member = R::findOne('member', 'email = ?', [$testEmail]);

if (!$member) {
    die("Test member not found: {$testEmail}\n");
}

$memberId = $member->id;
echo "Test member: {$member->email} (ID: {$memberId})\n\n";

// Test 1: Load member from default DB
echo "--- Test 1: Load Member (Default DB) ---\n";
$loadedMember = R::load('member', $memberId);
echo "Member email: {$loadedMember->email}\n";
echo "Member tier (via model method): {$loadedMember->getTier()}\n";
$test1Pass = $loadedMember->getTier() === 'enterprise';
echo "Result: " . ($test1Pass ? "PASS" : "FAIL (expected enterprise)") . "\n\n";

// Test 2: Load subscription from default DB
echo "--- Test 2: Load Subscription (Default DB) ---\n";
$subscription = R::findOne('subscription', 'member_id = ?', [$memberId]);
if ($subscription) {
    echo "Subscription tier: {$subscription->tier}\n";
    echo "Subscription status: {$subscription->status}\n";
    echo "Is active (via model method): " . ($subscription->isActive() ? 'yes' : 'no') . "\n";
    $test2Pass = $subscription->tier === 'enterprise' && $subscription->isActive();
} else {
    echo "No subscription found\n";
    $test2Pass = false;
}
echo "Result: " . ($test2Pass ? "PASS" : "FAIL") . "\n\n";

// Test 3: Connect to user database and load jiraboards
echo "--- Test 3: Load Jiraboards (User DB) ---\n";
echo "Connecting to user database for member {$memberId}...\n";
UserDatabaseService::connect($memberId);

// Load a jiraboard
$board = R::findOne('jiraboards', ' 1=1 ');
if ($board) {
    echo "Board ID: {$board->id}\n";
    echo "Board project key: {$board->project_key}\n";
    echo "AI Dev enabled (via model): " . ($board->isAiDevEnabled() ? 'yes' : 'no') . "\n";
    echo "Uses local runner (via model): " . ($board->usesLocalRunner() ? 'yes' : 'no') . "\n";
    $test3Pass = true;
} else {
    echo "No jiraboards found in user database\n";
    $test3Pass = false;
}
echo "Result: " . ($test3Pass ? "PASS" : "FAIL (no boards)") . "\n\n";

// Test 4: After switching to user DB, can we still get member tier?
// This is the CRITICAL test - member tier needs default DB
echo "--- Test 4: Get Member Tier After User DB Switch (CRITICAL) ---\n";
echo "We're now on user_{$memberId} SQLite database...\n";

// Try to get tier - this SHOULD switch back to default DB
$tier = $loadedMember->getTier();
echo "Member tier: {$tier}\n";
$test4Pass = $tier === 'enterprise';
echo "Result: " . ($test4Pass ? "PASS" : "FAIL (expected enterprise, got {$tier})") . "\n\n";

// Test 5: Interleaved access - load board, then check tier, then board again
echo "--- Test 5: Interleaved Database Access ---\n";

// First, reconnect to user DB
UserDatabaseService::connect($memberId);
echo "1. Connected to user DB\n";

// Load a board
$b1 = R::findOne('jiraboards', ' 1=1 ');
echo "2. Loaded board: " . ($b1 ? $b1->project_key : 'none') . "\n";

// Get tier (should switch to default DB)
$tier2 = SubscriptionService::getTier($memberId);
echo "3. Got tier via SubscriptionService: {$tier2}\n";

// Now try to load board again - we need to reconnect to user DB
UserDatabaseService::connect($memberId);
$b2 = R::findOne('jiraboards', ' 1=1 ');
echo "4. Loaded board after tier check: " . ($b2 ? $b2->project_key : 'none') . "\n";

$test5Pass = $tier2 === 'enterprise' && $b2 !== null;
echo "Result: " . ($test5Pass ? "PASS" : "FAIL") . "\n\n";

// Test 6: Direct SubscriptionService test after user DB operations
echo "--- Test 6: SubscriptionService After User DB Context ---\n";
UserDatabaseService::connect($memberId);
echo "1. Connected to user DB\n";

// Do a user DB operation
$boardCount = R::count('jiraboards');
echo "2. Board count in user DB: {$boardCount}\n";

// Now get tier - SubscriptionService should handle DB switching
$tier3 = SubscriptionService::getTier($memberId);
echo "3. Tier from SubscriptionService: {$tier3}\n";

$test6Pass = $tier3 === 'enterprise';
echo "Result: " . ($test6Pass ? "PASS" : "FAIL (expected enterprise)") . "\n\n";

// Final summary
echo "=== Test Summary ===\n";
$allPass = $test1Pass && $test2Pass && $test3Pass && $test4Pass && $test5Pass && $test6Pass;
echo "Test 1 (Load Member): " . ($test1Pass ? "PASS" : "FAIL") . "\n";
echo "Test 2 (Load Subscription): " . ($test2Pass ? "PASS" : "FAIL") . "\n";
echo "Test 3 (Load Jiraboards): " . ($test3Pass ? "PASS" : "FAIL") . "\n";
echo "Test 4 (Tier After User DB): " . ($test4Pass ? "PASS" : "FAIL") . "\n";
echo "Test 5 (Interleaved Access): " . ($test5Pass ? "PASS" : "FAIL") . "\n";
echo "Test 6 (SubscriptionService): " . ($test6Pass ? "PASS" : "FAIL") . "\n";
echo "\nOverall: " . ($allPass ? "ALL TESTS PASSED" : "SOME TESTS FAILED") . "\n";
