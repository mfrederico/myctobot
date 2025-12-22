<?php
/**
 * RedBeanPHP Association Regression Tests
 *
 * Tests the refactored code that now uses RedBeanPHP associations
 * (ownBeanList/sharedBeanList) instead of manual FK management.
 *
 * Run with: php tests/AssociationRegressionTest.php
 */

namespace app\tests;

// Bootstrap
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Bean.php';

use RedBeanPHP\R as R;
use app\Bean;

class AssociationRegressionTest {

    private static int $passed = 0;
    private static int $failed = 0;
    private static array $errors = [];
    private static int $testMemberId;
    private static string $testDbPath;

    /**
     * Run all tests
     */
    public static function run(): void {
        echo "=== RedBeanPHP Association Regression Tests ===\n\n";

        self::setup();

        try {
            // Default database tests (MySQL - R::)
            self::testMemberSubscriptionAssociation();
            self::testMemberSettingsAssociation();
            self::testMemberAtlassiantokenAssociation();
            self::testMemberContactAssociation();
            self::testContactResponseAssociation();
            self::testMemberDigestjobsAssociation();

            // User database tests (SQLite - Bean::)
            self::testBoardAidevjobsAssociation();
            self::testBoardBoardrepomappingAssociation();
            self::testRepoConnectionsCascadeDelete();

        } catch (\Exception $e) {
            echo "\n[FATAL] Test execution failed: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
        }

        self::teardown();
        self::printResults();
    }

    /**
     * Setup test environment
     */
    private static function setup(): void {
        echo "Setting up test environment...\n";

        // Connect to default database (MySQL) for testing
        // Use test database or create in-memory SQLite for isolation
        $testMode = getenv('TEST_MODE') ?: 'sqlite';

        if ($testMode === 'sqlite') {
            // Use in-memory SQLite for default database tests
            R::setup('sqlite::memory:');
            R::freeze(false);

            // Create test tables
            self::createTestTables();
        } else {
            // Use actual MySQL database (be careful!)
            $config = parse_ini_file(__DIR__ . '/../conf/config.ini');
            R::setup(
                "mysql:host={$config['db_host']};dbname={$config['db_name']}",
                $config['db_user'],
                $config['db_pass']
            );
        }

        // Create test member
        $member = R::dispense('member');
        $member->email = 'test-' . time() . '@example.com';
        $member->display_name = 'Test User';
        $member->created_at = date('Y-m-d H:i:s');
        R::store($member);
        self::$testMemberId = $member->id;

        // Setup user database (SQLite) for Bean:: tests
        self::$testDbPath = '/tmp/test_user_' . time() . '.sqlite';
        self::setupUserDatabase();

        echo "Test member ID: " . self::$testMemberId . "\n";
        echo "Test user DB: " . self::$testDbPath . "\n\n";
    }

    /**
     * Create test tables for in-memory SQLite
     */
    private static function createTestTables(): void {
        R::exec('CREATE TABLE IF NOT EXISTS member (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT,
            display_name TEXT,
            google_id TEXT,
            created_at TEXT
        )');

        R::exec('CREATE TABLE IF NOT EXISTS subscription (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER,
            tier TEXT,
            status TEXT,
            stripe_customer_id TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        R::exec('CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER,
            setting_key TEXT,
            setting_value TEXT,
            updated_at TEXT
        )');

        R::exec('CREATE TABLE IF NOT EXISTS atlassiantoken (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER,
            cloud_id TEXT,
            access_token TEXT,
            refresh_token TEXT,
            site_url TEXT,
            site_name TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        R::exec('CREATE TABLE IF NOT EXISTS contact (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER,
            name TEXT,
            email TEXT,
            subject TEXT,
            message TEXT,
            status TEXT,
            created_at TEXT
        )');

        R::exec('CREATE TABLE IF NOT EXISTS contactresponse (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contact_id INTEGER,
            member_id INTEGER,
            response TEXT,
            created_at TEXT
        )');

        R::exec('CREATE TABLE IF NOT EXISTS digestjobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER,
            job_id TEXT,
            board_id INTEGER,
            status TEXT,
            created_at TEXT
        )');
    }

    /**
     * Setup user database for Bean:: tests
     */
    private static function setupUserDatabase(): void {
        // Create SQLite database for user
        $pdo = new \PDO('sqlite:' . self::$testDbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE IF NOT EXISTS jiraboards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            board_id INTEGER,
            board_name TEXT,
            project_key TEXT,
            cloud_id TEXT,
            created_at TEXT
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS aidevjobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            jiraboards_id INTEGER,
            repoconnections_id INTEGER,
            issue_key TEXT,
            status TEXT,
            cloud_id TEXT,
            created_at TEXT
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS repoconnections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider TEXT,
            repo_owner TEXT,
            repo_name TEXT,
            created_at TEXT
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS boardrepomapping (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            jiraboards_id INTEGER,
            repoconnections_id INTEGER,
            is_default INTEGER,
            created_at TEXT
        )');

        // Connect Bean to this database
        Bean::addDatabase('user', 'sqlite:' . self::$testDbPath);
        Bean::selectDatabase('user');
        Bean::freeze(false);
    }

    /**
     * Teardown test environment
     */
    private static function teardown(): void {
        echo "\nCleaning up...\n";

        // Clean up test data from default database
        try {
            $member = R::load('member', self::$testMemberId);
            if ($member->id) {
                // Use xown for cascade delete
                $member->xownSubscriptionList = [];
                $member->xownSettingsList = [];
                $member->xownAtlassiantokenList = [];
                $member->xownContactList = [];
                $member->xownDigestjobsList = [];
                R::store($member);
                R::trash($member);
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }

        // Remove test user database
        if (file_exists(self::$testDbPath)) {
            unlink(self::$testDbPath);
        }
    }

    /**
     * Print test results
     */
    private static function printResults(): void {
        $total = self::$passed + self::$failed;
        echo "\n=== Results ===\n";
        echo "Passed: " . self::$passed . " / " . $total . "\n";
        echo "Failed: " . self::$failed . " / " . $total . "\n";

        if (!empty(self::$errors)) {
            echo "\nFailures:\n";
            foreach (self::$errors as $error) {
                echo "  - {$error}\n";
            }
        }

        echo "\n";
        exit(self::$failed > 0 ? 1 : 0);
    }

    /**
     * Assert helper
     */
    private static function assert(bool $condition, string $testName, string $message = ''): void {
        if ($condition) {
            echo "[PASS] {$testName}\n";
            self::$passed++;
        } else {
            $fullMessage = $message ? "{$testName}: {$message}" : $testName;
            echo "[FAIL] {$fullMessage}\n";
            self::$failed++;
            self::$errors[] = $fullMessage;
        }
    }

    // ========================================
    // Default Database Tests (R::)
    // ========================================

    /**
     * Test: member->ownSubscriptionList association
     */
    private static function testMemberSubscriptionAssociation(): void {
        echo "\n--- Testing member->ownSubscriptionList ---\n";

        $member = R::load('member', self::$testMemberId);

        // Test 1: Create subscription via association
        $subscription = R::dispense('subscription');
        $subscription->tier = 'pro';
        $subscription->status = 'active';
        $subscription->created_at = date('Y-m-d H:i:s');
        $member->ownSubscriptionList[] = $subscription;
        R::store($member);

        self::assert(
            $subscription->id > 0,
            'Subscription created via association',
            'Subscription ID should be set after store'
        );

        // Test 2: Verify FK was set automatically
        $reloadedSub = R::load('subscription', $subscription->id);
        self::assert(
            (int)$reloadedSub->member_id === self::$testMemberId,
            'FK member_id auto-set by association',
            "Expected member_id=" . self::$testMemberId . ", got=" . $reloadedSub->member_id
        );

        // Test 3: Lazy load subscriptions
        $member2 = R::load('member', self::$testMemberId);
        $subs = $member2->ownSubscriptionList;
        self::assert(
            count($subs) >= 1,
            'Lazy load ownSubscriptionList',
            'Expected at least 1 subscription'
        );

        // Test 4: Find active subscription in list
        $activeSub = null;
        foreach ($member2->ownSubscriptionList as $s) {
            if ($s->status === 'active') {
                $activeSub = $s;
                break;
            }
        }
        self::assert(
            $activeSub !== null && $activeSub->tier === 'pro',
            'Filter subscriptions from association list',
            'Should find active pro subscription'
        );
    }

    /**
     * Test: member->ownSettingsList association
     */
    private static function testMemberSettingsAssociation(): void {
        echo "\n--- Testing member->ownSettingsList ---\n";

        $member = R::load('member', self::$testMemberId);

        // Test 1: Create setting via association
        $setting = R::dispense('settings');
        $setting->setting_key = 'test_key';
        $setting->setting_value = 'test_value';
        $setting->updated_at = date('Y-m-d H:i:s');
        $member->ownSettingsList[] = $setting;
        R::store($member);

        self::assert(
            $setting->id > 0,
            'Setting created via association'
        );

        // Test 2: Find setting by key in association
        $member2 = R::load('member', self::$testMemberId);
        $foundValue = null;
        foreach ($member2->ownSettingsList as $s) {
            if ($s->setting_key === 'test_key') {
                $foundValue = $s->setting_value;
                break;
            }
        }
        self::assert(
            $foundValue === 'test_value',
            'Find setting by key via association'
        );

        // Test 3: Update existing setting
        foreach ($member2->ownSettingsList as $s) {
            if ($s->setting_key === 'test_key') {
                $s->setting_value = 'updated_value';
                break;
            }
        }
        R::store($member2);

        $member3 = R::load('member', self::$testMemberId);
        $updatedValue = null;
        foreach ($member3->ownSettingsList as $s) {
            if ($s->setting_key === 'test_key') {
                $updatedValue = $s->setting_value;
                break;
            }
        }
        self::assert(
            $updatedValue === 'updated_value',
            'Update setting via association'
        );
    }

    /**
     * Test: member->ownAtlassiantokenList association
     */
    private static function testMemberAtlassiantokenAssociation(): void {
        echo "\n--- Testing member->ownAtlassiantokenList ---\n";

        $member = R::load('member', self::$testMemberId);

        // Test 1: Create token via association
        $token = R::dispense('atlassiantoken');
        $token->cloud_id = 'test-cloud-123';
        $token->access_token = 'test-access-token';
        $token->site_url = 'https://test.atlassian.net';
        $token->created_at = date('Y-m-d H:i:s');
        $member->ownAtlassiantokenList[] = $token;
        R::store($member);

        self::assert(
            $token->id > 0,
            'Atlassian token created via association'
        );

        // Test 2: Find token by cloud_id
        $member2 = R::load('member', self::$testMemberId);
        $foundToken = null;
        foreach ($member2->ownAtlassiantokenList as $t) {
            if ($t->cloud_id === 'test-cloud-123') {
                $foundToken = $t;
                break;
            }
        }
        self::assert(
            $foundToken !== null && $foundToken->access_token === 'test-access-token',
            'Find token by cloud_id via association'
        );
    }

    /**
     * Test: member->ownContactList association
     */
    private static function testMemberContactAssociation(): void {
        echo "\n--- Testing member->ownContactList ---\n";

        $member = R::load('member', self::$testMemberId);

        // Test 1: Create contact via association
        $contact = R::dispense('contact');
        $contact->name = 'Test Contact';
        $contact->email = 'contact@example.com';
        $contact->subject = 'Test Subject';
        $contact->message = 'Test message content';
        $contact->status = 'new';
        $contact->created_at = date('Y-m-d H:i:s');
        $member->ownContactList[] = $contact;
        R::store($member);

        self::assert(
            $contact->id > 0,
            'Contact created via association'
        );

        // Test 2: Verify FK
        $reloadedContact = R::load('contact', $contact->id);
        self::assert(
            (int)$reloadedContact->member_id === self::$testMemberId,
            'Contact member_id auto-set'
        );

        // Test 3: Access member from contact (reverse association)
        $contactMember = $reloadedContact->member;
        self::assert(
            $contactMember !== null && (int)$contactMember->id === self::$testMemberId,
            'Reverse association contact->member'
        );
    }

    /**
     * Test: contact->ownContactresponseList association
     */
    private static function testContactResponseAssociation(): void {
        echo "\n--- Testing contact->ownContactresponseList ---\n";

        // Get the contact we created earlier
        $member = R::load('member', self::$testMemberId);
        $contacts = $member->ownContactList;
        $contact = reset($contacts);

        if (!$contact) {
            self::assert(false, 'Setup: Contact exists for response test');
            return;
        }

        // Test 1: Create response via association
        $response = R::dispense('contactresponse');
        $response->response = 'Test response content';
        $response->created_at = date('Y-m-d H:i:s');
        $response->member = $member;  // Admin who responded
        $contact->ownContactresponseList[] = $response;
        R::store($contact);

        self::assert(
            $response->id > 0,
            'Response created via association'
        );

        // Test 2: Verify FK
        $reloadedResponse = R::load('contactresponse', $response->id);
        self::assert(
            (int)$reloadedResponse->contact_id === (int)$contact->id,
            'Response contact_id auto-set'
        );

        // Test 3: Cascade delete with xown
        $contactId = $contact->id;
        $responseId = $response->id;

        $contact->xownContactresponseList = [];
        R::store($contact);

        $deletedResponse = R::load('contactresponse', $responseId);
        self::assert(
            !$deletedResponse->id,
            'xownContactresponseList cascade delete'
        );
    }

    /**
     * Test: member->ownDigestjobsList association
     */
    private static function testMemberDigestjobsAssociation(): void {
        echo "\n--- Testing member->ownDigestjobsList ---\n";

        $member = R::load('member', self::$testMemberId);

        // Test 1: Create digest job via association
        $digestJob = R::dispense('digestjobs');
        $digestJob->job_id = 'test-job-' . time();
        $digestJob->board_id = 1;
        $digestJob->status = 'queued';
        $digestJob->created_at = date('Y-m-d H:i:s');
        $member->ownDigestjobsList[] = $digestJob;
        R::store($member);

        self::assert(
            $digestJob->id > 0,
            'Digest job created via association'
        );

        // Test 2: Verify FK
        $reloadedJob = R::load('digestjobs', $digestJob->id);
        self::assert(
            (int)$reloadedJob->member_id === self::$testMemberId,
            'Digest job member_id auto-set'
        );
    }

    // ========================================
    // User Database Tests (Bean::)
    // ========================================

    /**
     * Test: jiraboards->ownAidevjobsList association
     */
    private static function testBoardAidevjobsAssociation(): void {
        echo "\n--- Testing jiraboards->ownAidevjobsList (Bean::) ---\n";

        // Create a board
        $board = Bean::dispense('jiraboards');
        $board->board_id = 123;
        $board->board_name = 'Test Board';
        $board->project_key = 'TEST';
        $board->cloud_id = 'test-cloud';
        $board->created_at = date('Y-m-d H:i:s');
        Bean::store($board);

        self::assert(
            $board->id > 0,
            'Board created'
        );

        // Create a repo connection
        $repo = Bean::dispense('repoconnections');
        $repo->provider = 'github';
        $repo->repo_owner = 'testowner';
        $repo->repo_name = 'testrepo';
        $repo->created_at = date('Y-m-d H:i:s');
        Bean::store($repo);

        // Test 1: Create job via association
        $job = Bean::dispense('aidevjobs');
        $job->issue_key = 'TEST-123';
        $job->status = 'pending';
        $job->cloud_id = 'external-cloud-id';
        $job->created_at = date('Y-m-d H:i:s');
        $job->repoconnections = $repo;  // Set repo via association
        $board->ownAidevjobsList[] = $job;
        Bean::store($board);

        self::assert(
            $job->id > 0,
            'AI Dev job created via board association'
        );

        // Test 2: Verify board FK (jiraboards_id)
        $reloadedJob = Bean::load('aidevjobs', $job->id);
        self::assert(
            (int)$reloadedJob->jiraboards_id === (int)$board->id,
            'Job jiraboards_id auto-set by association'
        );

        // Test 3: Verify repo FK (repoconnections_id)
        self::assert(
            (int)$reloadedJob->repoconnections_id === (int)$repo->id,
            'Job repoconnections_id auto-set by association'
        );

        // Test 4: Lazy load jobs from board
        $board2 = Bean::load('jiraboards', $board->id);
        $jobs = $board2->ownAidevjobsList;
        self::assert(
            count($jobs) >= 1,
            'Lazy load ownAidevjobsList from board'
        );
    }

    /**
     * Test: jiraboards->ownBoardrepomappingList association
     */
    private static function testBoardBoardrepomappingAssociation(): void {
        echo "\n--- Testing jiraboards->ownBoardrepomappingList (Bean::) ---\n";

        // Get existing board and repo
        $boards = Bean::find('jiraboards', 'LIMIT 1');
        $board = reset($boards);
        $repos = Bean::find('repoconnections', 'LIMIT 1');
        $repo = reset($repos);

        if (!$board || !$repo) {
            self::assert(false, 'Setup: Board and repo exist for mapping test');
            return;
        }

        // Test 1: Create mapping via association
        $mapping = Bean::dispense('boardrepomapping');
        $mapping->is_default = 1;
        $mapping->created_at = date('Y-m-d H:i:s');
        $mapping->repoconnections = $repo;
        $board->ownBoardrepomappingList[] = $mapping;
        Bean::store($board);

        self::assert(
            $mapping->id > 0,
            'Board-repo mapping created via association'
        );

        // Test 2: Verify FKs
        $reloadedMapping = Bean::load('boardrepomapping', $mapping->id);
        self::assert(
            (int)$reloadedMapping->jiraboards_id === (int)$board->id,
            'Mapping jiraboards_id auto-set'
        );
        self::assert(
            (int)$reloadedMapping->repoconnections_id === (int)$repo->id,
            'Mapping repoconnections_id auto-set'
        );

        // Test 3: Find mapping in board's list by repo ID
        $board2 = Bean::load('jiraboards', $board->id);
        $foundMapping = null;
        foreach ($board2->ownBoardrepomappingList as $m) {
            if ((int)$m->repoconnections_id === (int)$repo->id) {
                $foundMapping = $m;
                break;
            }
        }
        self::assert(
            $foundMapping !== null && $foundMapping->is_default == 1,
            'Find mapping by repo_id in board association'
        );
    }

    /**
     * Test: repoconnections->xownBoardrepomappingList cascade delete
     */
    private static function testRepoConnectionsCascadeDelete(): void {
        echo "\n--- Testing repoconnections cascade delete (Bean::) ---\n";

        // Create a new repo for this test
        $repo = Bean::dispense('repoconnections');
        $repo->provider = 'github';
        $repo->repo_owner = 'deletetest';
        $repo->repo_name = 'deleterepo';
        $repo->created_at = date('Y-m-d H:i:s');
        Bean::store($repo);

        // Get a board
        $boards = Bean::find('jiraboards', 'LIMIT 1');
        $board = reset($boards);

        // Create a mapping
        $mapping = Bean::dispense('boardrepomapping');
        $mapping->is_default = 0;
        $mapping->created_at = date('Y-m-d H:i:s');
        $mapping->jiraboards = $board;
        $repo->ownBoardrepomappingList[] = $mapping;
        Bean::store($repo);

        $mappingId = $mapping->id;
        self::assert(
            $mappingId > 0,
            'Setup: Mapping created for cascade test'
        );

        // Test: Use xown to cascade delete mappings when repo deleted
        $repo->xownBoardrepomappingList = [];
        Bean::store($repo);
        Bean::trash($repo);

        $deletedMapping = Bean::load('boardrepomapping', $mappingId);
        self::assert(
            !$deletedMapping->id,
            'xownBoardrepomappingList cascade delete on repo'
        );
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    AssociationRegressionTest::run();
}
