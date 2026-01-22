<?php
/**
 * WorkspaceSchemaBuilder - Creates workspace database schema using RedBeanPHP
 *
 * Uses RedBeanPHP associations to create tables with proper foreign keys.
 * Schema is defined in modular seed files in Schema/Seeds/*.php
 *
 * @see https://redbeanphp.com/index.php?p=/association
 */

namespace app\services;

use RedBeanPHP\R as R;

class WorkspaceSchemaBuilder {

    private string $dbName;
    private string $dbUser;
    private string $dbPass;
    private string $dbHost;

    public function __construct(string $dbName, string $dbUser, string $dbPass, string $dbHost = 'localhost') {
        $this->dbName = $dbName;
        $this->dbUser = $dbUser;
        $this->dbPass = $dbPass;
        $this->dbHost = $dbHost;
    }

    /**
     * Build the complete workspace schema using RedBeanPHP
     * All table creation and seeding is done via files in Schema/Seeds/
     */
    public function build(): void {
        // Add workspace database as a secondary connection
        $dsn = "mysql:host={$this->dbHost};dbname={$this->dbName};charset=utf8mb4";
        R::addDatabase('workspace_provision', $dsn, $this->dbUser, $this->dbPass);
        R::selectDatabase('workspace_provision');
        R::freeze(false); // Allow schema modifications

        // Run all seeders from Schema/Seeds directory (sorted alphabetically)
        $seedsDir = __DIR__ . '/Schema/Seeds';
        $seeders = glob($seedsDir . '/*.php');
        sort($seeders);

        foreach ($seeders as $seederFile) {
            include $seederFile;
        }

        // Switch back to default database
        R::selectDatabase('default');
    }
}
