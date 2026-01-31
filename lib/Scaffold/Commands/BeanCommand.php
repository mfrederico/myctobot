<?php
/**
 * Bean Command
 *
 * Data manipulation operations for beans via CLI.
 * Supports update, associate, export, and list operations.
 */

namespace app\Scaffold\Commands;

use app\Scaffold\ScaffoldManager;
use app\Bean;

class BeanCommand {

    private ScaffoldManager $manager;

    public function __construct(ScaffoldManager $manager) {
        $this->manager = $manager;
    }

    /**
     * Run bean operation
     *
     * @param string $operation Operation: update, associate, export, list
     * @param string $beanName Bean/table name
     * @param array $data Data for the operation
     * @param string|null $associate Associated bean name (for associate operation)
     */
    public function run(string $operation, string $beanName, array $data = [], ?string $associate = null): void {
        $beanName = strtolower(str_replace(['_', '-', ' '], '', $beanName));

        switch ($operation) {
            case 'update':
                $this->update($beanName, $data);
                break;
            case 'associate':
                $this->associate($beanName, $associate, $data);
                break;
            case 'export':
                $this->export($beanName, $data);
                break;
            case 'list':
                $this->listRecords($beanName, $data);
                break;
            case 'find':
                $this->findRecords($beanName, $data);
                break;
            case 'findone':
                $this->findOneRecord($beanName, $data);
                break;
            default:
                $this->error("Unknown operation: {$operation}");
        }
    }

    /**
     * Update a bean record
     */
    public function update(string $beanName, array $data): void {
        if (!isset($data['id'])) {
            $this->error("'id' is required in data for updates");
            return;
        }

        $id = (int) $data['id'];
        unset($data['id']);

        $bean = Bean::load($beanName, $id);
        if (!$bean->id) {
            $this->error("{$beanName} with id {$id} not found");
            return;
        }

        foreach ($data as $key => $value) {
            $bean->{$key} = $value;
            if ($this->manager->isVerbose()) {
                echo "  Set {$key} = " . json_encode($value) . "\n";
            }
        }

        Bean::store($bean);
        echo "✓ Updated {$beanName} #{$id}\n";
    }

    /**
     * Associate two beans (many-to-many)
     */
    public function associate(string $beanName, string $associateName, array $data): void {
        if (!isset($data['id'])) {
            $this->error("'id' (primary bean ID) is required in data");
            return;
        }

        $associateName = strtolower(str_replace(['_', '-', ' '], '', $associateName));
        $primaryId = (int) $data['id'];
        $bean = Bean::load($beanName, $primaryId);

        if (!$bean->id) {
            $this->error("{$beanName} with id {$primaryId} not found");
            return;
        }

        // Find the associated bean ID from various possible keys
        $associateId = $data[strtolower($associateName) . '_id']
            ?? $data['associate_id']
            ?? $data['related_id']
            ?? $data[$associateName . 'id']
            ?? null;

        if (!$associateId) {
            $this->error("Associated bean ID not found in data. Expected '{$associateName}_id', 'associate_id', or 'related_id'");
            return;
        }

        $associatedBean = Bean::load($associateName, (int) $associateId);
        if (!$associatedBean->id) {
            $this->error("{$associateName} with id {$associateId} not found");
            return;
        }

        // Create many-to-many association (sharedBeanList)
        $associatedName = 'shared' . ucfirst(strtolower($associateName)) . 'List';
        $bean->{$associatedName}[] = $associatedBean;
        Bean::store($bean);

        echo "✓ Associated {$beanName} #{$primaryId} with {$associateName} #{$associateId}\n";
        if ($this->manager->isVerbose()) {
            echo "  Relationship: {$associatedName}\n";
        }
    }

    /**
     * Export bean as JSON
     */
    public function export(string $beanName, array $data): void {
        if (!isset($data['id'])) {
            $this->error("'id' is required in data for export");
            return;
        }

        $id = (int) $data['id'];
        $bean = Bean::load($beanName, $id);

        if (!$bean->id) {
            $this->error("{$beanName} with id {$id} not found");
            return;
        }

        $export = $bean->export();

        // Optionally include relationships
        $includeRels = $data['include_relations'] ?? false;
        if ($includeRels) {
            // This is a simplified version - full implementation would iterate relations
            // For now, just export the bean itself
        }

        echo json_encode($export, JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * Find records by arbitrary field conditions
     *
     * @param string $beanName Bean/table name
     * @param array $data Field => value pairs to match
     */
    public function findRecords(string $beanName, array $data): void {
        if (empty($data)) {
            $this->error("--data with field conditions is required for --find");
            return;
        }

        // Extract special options
        $limit = (int) ($data['_limit'] ?? 20);
        $orderBy = $data['_order'] ?? 'id DESC';
        unset($data['_limit'], $data['_order']);

        // Build WHERE clause from remaining data
        $conditions = [];
        $bindings = [];
        foreach ($data as $field => $value) {
            if ($value === null) {
                $conditions[] = "{$field} IS NULL";
            } else {
                $conditions[] = "{$field} = ?";
                $bindings[] = $value;
            }
        }

        $where = implode(' AND ', $conditions);
        $sql = "{$where} ORDER BY {$orderBy} LIMIT {$limit}";

        $beans = Bean::find($beanName, $sql, $bindings);

        if (empty($beans)) {
            echo "No records found.\n";
            return;
        }

        $records = array_map(fn($b) => $b->export(), $beans);
        echo json_encode($records, JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * Find single record by arbitrary field conditions
     *
     * @param string $beanName Bean/table name
     * @param array $data Field => value pairs to match
     */
    public function findOneRecord(string $beanName, array $data): void {
        if (empty($data)) {
            $this->error("--data with field conditions is required for --findone");
            return;
        }

        // Build WHERE clause
        $conditions = [];
        $bindings = [];
        foreach ($data as $field => $value) {
            if ($value === null) {
                $conditions[] = "{$field} IS NULL";
            } else {
                $conditions[] = "{$field} = ?";
                $bindings[] = $value;
            }
        }

        $where = implode(' AND ', $conditions);

        $bean = Bean::findOne($beanName, $where, $bindings);

        if (!$bean) {
            echo "No record found.\n";
            return;
        }

        echo json_encode($bean->export(), JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * List records from a bean table
     */
    public function listRecords(string $beanName, array $data = []): void {
        $limit = (int) ($data['limit'] ?? 20);
        $offset = (int) ($data['offset'] ?? 0);
        $where = $data['where'] ?? '';
        $bindings = $data['bindings'] ?? [];

        $sql = $where ? "{$where} LIMIT {$limit} OFFSET {$offset}" : "1 ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";

        $beans = Bean::find($beanName, $sql, $bindings);

        if (empty($beans)) {
            echo "No records found.\n";
            return;
        }

        // Output as JSON array
        $records = array_map(fn($b) => $b->export(), $beans);
        echo json_encode($records, JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * List all tables with counts and hierarchy
     */
    public function listTables(): void {
        try {
            $tables = \RedBeanPHP\R::inspect();
            sort($tables);

            // Analyze relationships
            $relationships = $this->analyzeRelationships($tables);

            echo "\nDatabase Structure:\n";
            echo str_repeat('─', 50) . "\n";
            echo "Legend: [s] = shared/many-to-many link table\n";
            echo str_repeat('─', 50) . "\n\n";

            // Display hierarchically
            $displayed = [];
            $rootCount = count($relationships['roots']);
            foreach ($relationships['roots'] as $index => $table) {
                $isLast = ($index === $rootCount - 1);
                $this->displayTableHierarchy($table, $relationships, $displayed, 0, $isLast, '');
            }

            // Show orphaned tables (no relationships)
            $orphaned = array_diff($tables, $displayed);
            if (!empty($orphaned)) {
                echo "\n── Standalone Tables ──\n";
                foreach ($orphaned as $table) {
                    $count = Bean::count($table);
                    $padding = max(1, 45 - strlen($table));
                    printf("%s%s%5d rows\n", $table, str_repeat(' ', $padding), $count);
                }
            }

            echo "\n" . str_repeat('─', 50) . "\n";
            echo "Total: " . count($tables) . " tables\n\n";

        } catch (\Exception $e) {
            $this->error("Error listing tables: " . $e->getMessage());
        }
    }

    /**
     * Analyze foreign key relationships between tables
     */
    private function analyzeRelationships(array $tables): array {
        $parents = [];    // parent => [children]
        $children = [];   // child => parent
        $shared = [];     // shared/link tables

        foreach ($tables as $table) {
            try {
                $columns = \RedBeanPHP\R::inspect($table);

                // Check for foreign keys (columns ending in _id)
                foreach ($columns as $column => $type) {
                    if (preg_match('/^(.+)_id$/', $column, $matches)) {
                        $parentTable = strtolower($matches[1]);

                        // Verify parent table exists
                        if (in_array($parentTable, $tables)) {
                            if (!isset($parents[$parentTable])) {
                                $parents[$parentTable] = [];
                            }
                            $parents[$parentTable][] = $table;
                            $children[$table] = $parentTable;
                        }
                    }
                }

                // Check if this is a shared/link table (e.g., "bean1_bean2")
                if (strpos($table, '_') !== false) {
                    $parts = explode('_', $table);
                    if (count($parts) === 2) {
                        // Verify both parts are actual tables
                        if (in_array($parts[0], $tables) && in_array($parts[1], $tables)) {
                            $shared[] = $table;
                        }
                    }
                }

            } catch (\Exception $e) {
                // Skip tables we can't inspect
                continue;
            }
        }

        // Find root tables (tables that are parents but not children, or have no relationships)
        $roots = [];
        foreach ($tables as $table) {
            if (isset($parents[$table]) && !isset($children[$table])) {
                $roots[] = $table;
            }
        }

        return [
            'parents' => $parents,
            'children' => $children,
            'shared' => $shared,
            'roots' => $roots
        ];
    }

    /**
     * Display table with its children hierarchically
     */
    private function displayTableHierarchy(
        string $table,
        array $relationships,
        array &$displayed,
        int $depth,
        bool $isLast = true,
        string $prefix = '',
        int $maxWidth = 45
    ): void {
        if (in_array($table, $displayed)) {
            return; // Prevent infinite loops
        }

        $displayed[] = $table;
        $count = Bean::count($table);

        // Build tree connector
        $connector = '';
        if ($depth > 0) {
            $connector = $isLast ? '└── ' : '├── ';
        }

        // Check if this is a shared/link table
        $isShared = in_array($table, $relationships['shared']);
        $marker = $isShared ? ' [s]' : '';

        // Calculate padding to align row counts
        $lineContent = $prefix . $connector . $table;
        $padding = max(1, $maxWidth - mb_strlen($lineContent));

        printf("%s%s%s%s%5d rows%s\n", $prefix, $connector, $table, str_repeat(' ', $padding), $count, $marker);

        // Calculate prefix for children
        $childPrefix = $prefix;
        if ($depth > 0) {
            $childPrefix .= $isLast ? '    ' : '│   ';
        }

        // Display children
        $children = $relationships['parents'][$table] ?? [];
        $childCount = count($children);
        foreach ($children as $index => $child) {
            $childIsLast = ($index === $childCount - 1);
            $this->displayTableHierarchy($child, $relationships, $displayed, $depth + 1, $childIsLast, $childPrefix, $maxWidth);
        }
    }

    /**
     * Print error message
     */
    private function error(string $message): void {
        fwrite(STDERR, "Error: {$message}\n");
    }
}
