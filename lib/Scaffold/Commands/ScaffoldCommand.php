<?php
/**
 * Scaffold Command
 *
 * Generate model/controller/views from an existing bean or specification.
 * Introspects the database table to determine field types.
 */

namespace app\Scaffold\Commands;

use app\Scaffold\ScaffoldManager;
use app\Scaffold\Context;

class ScaffoldCommand {

    private ScaffoldManager $manager;

    public function __construct(ScaffoldManager $manager) {
        $this->manager = $manager;
    }

    /**
     * Run scaffold generation
     *
     * @param string $beanName Bean/table name
     * @param array $parts Parts to generate: model, controller/crud, api, view/views
     */
    public function run(string $beanName, array $parts): void {
        $beanName = strtolower(str_replace(['_', '-', ' '], '', $beanName));
        $ctx = $this->manager->createContext($beanName);

        // Introspect existing table or use defaults
        $this->introspectTable($ctx);

        // Normalize part names
        $normalizedParts = $this->normalizeParts($parts);

        if ($this->manager->isVerbose()) {
            echo "Scaffolding '{$beanName}' with parts: " . implode(', ', $normalizedParts) . "\n";
            echo "Fields found: " . count($ctx->fields) . "\n\n";
        }

        // Generate each requested part
        $results = $this->manager->generateMultiple($normalizedParts, $ctx);

        // Summary
        $success = array_filter($results);
        $failed = array_filter($results, fn($r) => !$r);

        echo "\n✓ Scaffold complete for '{$beanName}'\n";
        if (!empty($failed)) {
            echo "  Failed: " . implode(', ', array_keys($failed)) . "\n";
        }
    }

    /**
     * Introspect database table to determine fields
     */
    private function introspectTable(Context $ctx): void {
        try {
            $columns = \RedBeanPHP\R::inspect($ctx->beanName);

            if (empty($columns)) {
                if ($this->manager->isVerbose()) {
                    echo "Warning: Table '{$ctx->beanName}' not found. Using minimal defaults.\n";
                }
                $this->useDefaults($ctx);
                return;
            }

            // Clear default timestamp fields (we'll re-add if found)
            $ctx->fields = [];

            foreach ($columns as $colName => $colType) {
                if ($colName === 'id') continue;

                $type = $this->detectColumnType($colType);
                $widget = $ctx->fieldRegistry->getDefaultWidget($type);

                // Smart widget detection from field name
                $detected = $ctx->fieldRegistry->detectFromName($colName);
                if ($detected) {
                    $type = $detected['type'];
                    $widget = $detected['widget'];
                }

                $options = [];
                if (in_array($colName, ['created_at', 'updated_at'])) {
                    $options[] = 'auto';
                }

                $ctx->fields[$colName] = [
                    'name' => $colName,
                    'type' => $type,
                    'widget' => $widget,
                    'options' => $options,
                    'label' => $ctx->humanize($colName)
                ];
            }

            // Detect relationships from foreign key columns
            $this->detectRelationships($ctx, $columns);

            if ($this->manager->isVerbose()) {
                echo "Introspected " . count($ctx->fields) . " fields from table '{$ctx->beanName}'\n";
            }

        } catch (\Exception $e) {
            if ($this->manager->isVerbose()) {
                echo "Warning: Could not introspect table: " . $e->getMessage() . "\n";
            }
            $this->useDefaults($ctx);
        }
    }

    /**
     * Detect column type from database type string
     */
    private function detectColumnType(string $dbType): string {
        $dbType = strtoupper($dbType);

        if (strpos($dbType, 'INT') !== false) {
            return strpos($dbType, 'TINYINT') !== false ? 'bool' : 'int';
        }
        if (strpos($dbType, 'FLOAT') !== false || strpos($dbType, 'DOUBLE') !== false || strpos($dbType, 'DECIMAL') !== false) {
            return 'float';
        }
        if (strpos($dbType, 'TEXT') !== false || strpos($dbType, 'BLOB') !== false) {
            return 'text';
        }
        if (strpos($dbType, 'DATETIME') !== false || strpos($dbType, 'TIMESTAMP') !== false) {
            return 'datetime';
        }
        if (strpos($dbType, 'DATE') !== false) {
            return 'date';
        }
        if (strpos($dbType, 'JSON') !== false) {
            return 'json';
        }

        return 'string';
    }

    /**
     * Detect relationships from foreign key columns
     */
    private function detectRelationships(Context $ctx, array $columns): void {
        foreach ($columns as $colName => $colType) {
            // RedBeanPHP FK pattern: related_bean_id
            if (preg_match('/^(\w+)_id$/', $colName, $matches)) {
                $relatedBean = $matches[1];

                // Check if it's a real table
                try {
                    $tables = \RedBeanPHP\R::inspect();
                    if (in_array($relatedBean, $tables)) {
                        $ctx->addRelationship('belongs-to', $relatedBean);
                        if ($this->manager->isVerbose()) {
                            echo "  Detected relationship: belongs-to -> {$relatedBean}\n";
                        }
                    }
                } catch (\Exception $e) {
                    // Ignore
                }
            }
        }
    }

    /**
     * Use default fields when table doesn't exist
     */
    private function useDefaults(Context $ctx): void {
        $ctx->fields = [
            'name' => [
                'name' => 'name',
                'type' => 'string',
                'widget' => 'text',
                'options' => [],
                'label' => 'Name'
            ],
            'created_at' => [
                'name' => 'created_at',
                'type' => 'datetime',
                'widget' => 'datetime',
                'options' => ['auto'],
                'label' => 'Created At'
            ],
            'updated_at' => [
                'name' => 'updated_at',
                'type' => 'datetime',
                'widget' => 'datetime',
                'options' => ['auto'],
                'label' => 'Updated At'
            ]
        ];
    }

    /**
     * Normalize part names to generator names
     */
    private function normalizeParts(array $parts): array {
        $normalized = [];

        foreach ($parts as $part) {
            $part = strtolower(trim($part));

            switch ($part) {
                case 'model':
                    $normalized[] = 'model';
                    break;
                case 'control':
                case 'controller':
                case 'crud':
                    $normalized[] = 'crud';
                    break;
                case 'api':
                    $normalized[] = 'api';
                    break;
                case 'view':
                case 'views':
                    $normalized[] = 'view';
                    break;
                case 'all':
                    $normalized = ['model', 'crud', 'view'];
                    break;
                default:
                    echo "Warning: Unknown scaffold part '{$part}'\n";
            }
        }

        return array_unique($normalized);
    }
}
