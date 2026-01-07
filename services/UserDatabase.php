<?php
/**
 * User Database Connection Manager
 *
 * LEGACY COMPATIBILITY LAYER - All data is now in one MySQL database per tenant.
 * connect(), disconnect(), and with() are no-ops that just execute the callback.
 *
 * Original purpose: Handled RedBeanPHP connections to per-user SQLite databases.
 * New architecture: Single MySQL database per tenant, no database switching needed.
 *
 * Usage remains the same (for backwards compatibility):
 *   $job = UserDatabase::with($memberId, function() use ($issueKey) {
 *       return R::findOne('aidevjobs', 'issue_key = ?', [$issueKey]);
 *   });
 *
 * The callback is executed directly without any database switching.
 */

namespace app\services;

use \RedBeanPHP\R as R;

class UserDatabase {

    private static ?int $currentMemberId = null;

    /**
     * Connect to a user's database (legacy no-op)
     *
     * @param int $memberId Member ID
     * @return void
     */
    public static function connect(int $memberId): void {
        // No-op: All data is now in single MySQL database per tenant
        self::$currentMemberId = $memberId;
    }

    /**
     * Disconnect from user database (legacy no-op)
     */
    public static function disconnect(): void {
        // No-op: No database switching needed
        self::$currentMemberId = null;
    }

    /**
     * Execute callback (legacy wrapper - no database switching)
     *
     * @param int $memberId Member ID
     * @param callable $callback Function to execute
     * @return mixed Result of callback
     */
    public static function with(int $memberId, callable $callback) {
        self::$currentMemberId = $memberId;
        try {
            return $callback();
        } finally {
            self::$currentMemberId = null;
        }
    }

    /**
     * Check if connected to a user database (legacy - always returns false)
     */
    public static function isConnected(): bool {
        return self::$currentMemberId !== null;
    }

    /**
     * Get the current member ID
     */
    public static function getCurrentMemberId(): ?int {
        return self::$currentMemberId;
    }

    /**
     * Get the database path for a member (legacy - returns null)
     */
    public static function getDbPath(int $memberId): ?string {
        // No longer using per-user SQLite files
        return null;
    }
}
