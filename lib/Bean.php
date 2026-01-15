<?php
/**
 * Bean - RedBeanPHP Wrapper
 *
 * Normalizes bean type names to work around RedBeanPHP's strict naming requirements.
 * R::dispense() requires all lowercase, no underscores. This wrapper accepts:
 * - camelCase: 'enterpriseSettings' -> 'enterprisesettings'
 * - snake_case: 'enterprise_settings' -> 'enterprisesettings'
 * - Already lowercase: 'enterprisesettings' -> 'enterprisesettings'
 *
 * Usage:
 *   use \app\Bean;
 *
 *   $setting = Bean::findOne('enterpriseSettings', 'setting_key = ?', ['api_key']);
 *   if (!$setting) {
 *       $setting = Bean::dispense('enterpriseSettings');
 *       $setting->setting_key = 'api_key';
 *   }
 *   Bean::store($setting);
 */

namespace app;

use \RedBeanPHP\R as R;

class Bean {

    /**
     * Normalize bean type to lowercase, no underscores
     * This is required for R::dispense() to work
     *
     * @param string $type Bean type (camelCase, snake_case, or lowercase)
     * @return string Normalized lowercase bean type
     */
    public static function normalize(string $type): string {
        return strtolower(str_replace('_', '', $type));
    }

    /**
     * Create a new bean
     *
     * @param string $type Bean type
     * @return \RedBeanPHP\OODBBean
     */
    public static function dispense(string $type) {
        return R::dispense(self::normalize($type));
    }

    /**
     * Load a bean by ID
     *
     * @param string $type Bean type
     * @param int $id Bean ID
     * @return \RedBeanPHP\OODBBean
     */
    public static function load(string $type, int $id) {
        return R::load(self::normalize($type), $id);
    }

    /**
     * Find a single bean matching criteria
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @return \RedBeanPHP\OODBBean|null
     */
    public static function findOne(string $type, ?string $sql = null, array $params = []) {
        return R::findOne(self::normalize($type), $sql, $params);
    }

    /**
     * Find a single bean or throw an exception if not found
     * Reduces duplicate pattern: $bean = Bean::findOne(...); if (!$bean) { throw/error; }
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @param string|null $errorMessage Custom error message (default: "{Type} not found")
     * @return \RedBeanPHP\OODBBean
     * @throws \Exception If bean not found
     */
    public static function findOneOrFail(string $type, ?string $sql = null, array $params = [], ?string $errorMessage = null) {
        $bean = R::findOne(self::normalize($type), $sql, $params);
        if (!$bean) {
            $message = $errorMessage ?? ucfirst(self::normalize($type)) . ' not found';
            throw new \Exception($message);
        }
        return $bean;
    }

    /**
     * Load a bean by ID or throw an exception if not found
     * Reduces duplicate pattern: $bean = Bean::load(...); if (!$bean->id) { throw/error; }
     *
     * @param string $type Bean type
     * @param int $id Bean ID
     * @param string|null $errorMessage Custom error message
     * @return \RedBeanPHP\OODBBean
     * @throws \Exception If bean not found
     */
    public static function loadOrFail(string $type, int $id, ?string $errorMessage = null) {
        $bean = R::load(self::normalize($type), $id);
        if (!$bean || !$bean->id) {
            $message = $errorMessage ?? ucfirst(self::normalize($type)) . ' not found';
            throw new \Exception($message);
        }
        return $bean;
    }

    /**
     * Find all beans matching criteria
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @return array Array of beans
     */
    public static function find(string $type, ?string $sql = null, array $params = []) {
        return R::find(self::normalize($type), $sql, $params);
    }

    /**
     * Find all beans matching criteria (alias for find)
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @return array Array of beans
     */
    public static function findAll(string $type, ?string $sql = null, array $params = []) {
        return R::findAll(self::normalize($type), $sql, $params);
    }

    /**
     * Count beans matching criteria
     *
     * @param string $type Bean type
     * @param string|null $sql SQL where clause
     * @param array $params Bound parameters
     * @return int Count
     */
    public static function count(string $type, ?string $sql = null, array $params = []) {
        // R::count calls trim() on $sql, so ensure it's never null
        return R::count(self::normalize($type), $sql ?? '', $params);
    }

    /**
     * Store a bean
     *
     * @param \RedBeanPHP\OODBBean $bean Bean to store
     * @return int|string Bean ID
     */
    public static function store($bean) {
        return R::store($bean);
    }

    /**
     * Delete a bean
     *
     * @param \RedBeanPHP\OODBBean|string $beanOrType Bean or type name
     * @param int|null $id Optional ID if first param is type
     * @return void
     */
    public static function trash($beanOrType, ?int $id = null) {
        if (is_string($beanOrType) && $id !== null) {
            return R::trash(self::normalize($beanOrType), $id);
        }
        return R::trash($beanOrType);
    }

    /**
     * Delete multiple beans
     *
     * @param array $beans Array of beans to delete
     * @return void
     */
    public static function trashAll(array $beans) {
        return R::trashAll($beans);
    }

    /**
     * Execute raw SQL (use sparingly - only for DDL or complex operations)
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return int Affected rows
     */
    public static function exec(string $sql, array $params = []) {
        return R::exec($sql, $params);
    }

    /**
     * Get all rows as arrays (for complex SELECT with joins)
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return array Array of arrays
     */
    public static function getAll(string $sql, array $params = []) {
        return R::getAll($sql, $params);
    }

    /**
     * Execute SQL and return first row
     *
     * @param string $sql SQL statement
     * @param array $params Bound parameters
     * @return array|null First row or null
     */
    public static function getRow(string $sql, array $params = []) {
        return R::getRow($sql, $params);
    }

    /**
     * Convert array of rows to beans
     *
     * @param string $type Bean type
     * @param array $rows Array of row arrays
     * @return array Array of beans
     */
    public static function convertToBeans(string $type, array $rows) {
        $type = self::normalize($type);
        return R::convertToBeans($type, $rows);
    }

    /** @var array Track registered database keys */
    private static array $registeredDatabases = [];

    /**
     * Check if a database key is already registered
     *
     * @param string $key Database key
     * @return bool
     */
    public static function hasDatabase(string $key): bool {
        return isset(self::$registeredDatabases[$key]);
    }

    /**
     * Add a database connection (safe - won't error if already registered)
     *
     * @param string $key Database key
     * @param string $dsn DSN string
     * @param string|null $user Username
     * @param string|null $pass Password
     * @param bool $frozen Freeze mode
     * @return bool True if newly added, false if already existed
     */
    public static function addDatabase(string $key, string $dsn, ?string $user = null, ?string $pass = null, bool $frozen = false): bool {
        if (self::hasDatabase($key)) {
            return false; // Already registered
        }

        R::addDatabase($key, $dsn, $user, $pass, $frozen);
        self::$registeredDatabases[$key] = true;
        return true;
    }

    /**
     * Select a database connection
     *
     * @param string $key Database key
     */
    public static function selectDatabase(string $key) {
        return R::selectDatabase($key);
    }

    /**
     * Add and select a database in one call (convenience method)
     *
     * @param string $key Database key
     * @param string $dsn DSN string
     * @param string|null $user Username
     * @param string|null $pass Password
     * @param bool $frozen Freeze mode
     */
    public static function useDatabase(string $key, string $dsn, ?string $user = null, ?string $pass = null, bool $frozen = false): void {
        self::addDatabase($key, $dsn, $user, $pass, $frozen);
        self::selectDatabase($key);
    }

    /**
     * Set freeze mode
     *
     * @param bool $frozen Freeze mode
     */
    public static function freeze(bool $frozen = true) {
        return R::freeze($frozen);
    }
}
