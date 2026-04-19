<?php
/**
 * Wizard Command
 *
 * Interactive wizard for creating models, controllers, and views.
 * Walks the user through field definitions, relationships, and methods.
 */

namespace app\Scaffold\Commands;

use app\Scaffold\ScaffoldManager;
use app\Scaffold\Context;

class WizardCommand {

    private ScaffoldManager $manager;

    public function __construct(ScaffoldManager $manager) {
        $this->manager = $manager;
    }

    /**
     * Run the interactive wizard
     */
    public function run(): void {
        $this->printHeader();

        // Step 1: Model name
        $modelName = $this->prompt("Enter bean/model name (e.g., 'product', 'orderitem')");
        $modelName = strtolower(str_replace(['_', '-', ' '], '', $modelName));

        if (empty($modelName)) {
            $this->error("Model name cannot be empty");
            return;
        }

        $ctx = $this->manager->createContext($modelName);

        echo "\nModel will be created as: Model_" . ucfirst($modelName) . "\n";
        echo "Table name: {$modelName}\n\n";

        // Step 2: Fields
        $this->collectFields($ctx);

        // Step 3: Relationships
        $this->collectRelationships($ctx);

        // Step 4: Model methods
        $this->collectMethods($ctx);

        // Step 5: Controller type
        $ctx->controllerType = $this->selectControllerType();

        // Step 6: Permission level (only if controller is being created)
        if ($ctx->controllerType !== 'none') {
            $ctx->permissionLevel = $this->selectPermissionLevel();
        }

        // Summary
        $this->printSummary($ctx);

        $proceed = $this->prompt("\nGenerate files? (y/n)", "y");
        if (strtolower($proceed) !== 'y') {
            echo "Cancelled.\n";
            return;
        }

        // Generate files based on controller type
        $this->generateFiles($ctx);

        $this->printNextSteps($ctx);
    }

    /**
     * Print the wizard header
     */
    private function printHeader(): void {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║       MyCTOBot Model Creation Wizard                         ║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n\n";
    }

    /**
     * Collect field definitions
     */
    private function collectFields(Context $ctx): void {
        echo "─── Step 2: Define Fields ───────────────────────────────────────\n";
        echo "Note: created_at and updated_at are auto-generated.\n\n";
        echo "Enter fields one per line. Format: field_name:type[:options]\n";
        echo "Types: string, text, int, float, bool, datetime, json, enum\n";
        echo "Options: required, unique, default=VALUE, widget=NAME\n";
        echo "Examples:\n";
        echo "  email:string:required,unique\n";
        echo "  appointment:datetime:widget=fancyDateSelector\n";
        echo "  status:enum=pending|active|archived:required\n";
        echo "  integration_type:enum=jira|github|shopify:default=jira\n";
        echo "Enter blank line when done.\n\n";

        while (true) {
            $fieldInput = $this->prompt("Field");
            if (empty($fieldInput)) break;

            $parsed = $ctx->fieldRegistry->parseFieldString($fieldInput);
            if ($parsed) {
                $ctx->fields[$parsed['name']] = $parsed;
                $extra = '';
                if (!empty($parsed['enum_values'])) {
                    $extra = ' [' . implode('|', $parsed['enum_values']) . ']';
                }
                echo "  Added: {$parsed['name']} ({$parsed['type']}, widget: {$parsed['widget']}){$extra}\n";
            } else {
                echo "  Could not parse field. Try: fieldname:type\n";
            }
        }

        echo "\nFields defined: " . implode(', ', array_keys($ctx->fields)) . "\n\n";
    }

    /**
     * Collect relationship definitions
     */
    private function collectRelationships(Context $ctx): void {
        echo "─── Step 3: Define Relationships ────────────────────────────────\n";

        while (true) {
            echo "\nRelationship types:\n";
            echo "  1. has-many (ownBeanList) - This model owns many of another\n";
            echo "  2. has-one (ownBeanList) - This model owns one of another\n";
            echo "  3. many-to-many (sharedBeanList) - Bidirectional relationship\n";
            echo "  4. belongs-to (parent) - This model belongs to another\n";
            echo "  5. Done - No more relationships\n";

            $choice = $this->prompt("Choose (1-5)", "5");

            if ($choice === '5' || empty($choice)) break;

            $relatedBean = $this->prompt("Related bean name (e.g., 'product', 'category')");
            $relatedBean = strtolower(str_replace(['_', '-', ' '], '', $relatedBean));

            if (empty($relatedBean)) continue;

            // Check if related bean exists
            if (!$this->beanExists($relatedBean)) {
                $createIt = $this->prompt("Bean '$relatedBean' doesn't exist. Create it first? (y/n)", "y");
                if (strtolower($createIt) === 'y') {
                    echo "\n--- Creating related bean: {$relatedBean} ---\n";
                    $this->createMinimalBean($relatedBean);
                    echo "--- Returning to {$ctx->beanName} ---\n\n";
                }
            }

            $typeMap = [
                '1' => 'has-many',
                '2' => 'has-one',
                '3' => 'many-to-many',
                '4' => 'belongs-to'
            ];

            if (isset($typeMap[$choice])) {
                $ctx->addRelationship($typeMap[$choice], $relatedBean);
                $rel = end($ctx->relationships);
                echo "  Added: {$rel['type']} -> {$relatedBean} ({$rel['property']})\n";
            }
        }
    }

    /**
     * Collect model methods
     */
    private function collectMethods(Context $ctx): void {
        echo "\n─── Step 4: Model Methods ─────────────────────────────────────────\n";
        echo "Define custom methods for the model.\n\n";

        // Suggest fullName if firstname/lastname exist
        if ($this->hasNameFields($ctx)) {
            $add = $this->prompt("Add fullName() method? (y/n)", "y");
            if (strtolower($add) === 'y') {
                $ctx->addMethod(
                    'fullName',
                    'string',
                    "return trim((\$this->bean->first_name ?? \$this->bean->firstname ?? '') . ' ' . (\$this->bean->last_name ?? \$this->bean->lastname ?? ''));"
                );
            }
        }

        // Suggest isActive if active/status exists
        if ($this->hasActiveField($ctx)) {
            $add = $this->prompt("Add isActive() method? (y/n)", "y");
            if (strtolower($add) === 'y') {
                $ctx->addMethod(
                    'isActive',
                    'bool',
                    "return !empty(\$this->bean->is_active ?? \$this->bean->active ?? (\$this->bean->status === 'active'));"
                );
            }
        }

        // Add count methods for relationships
        foreach ($ctx->relationships as $rel) {
            if (in_array($rel['type'], ['has-many', 'many-to-many'])) {
                $methodName = 'count' . $rel['beanClass'];
                $add = $this->prompt("Add {$methodName}() method? (y/n)", "y");
                if (strtolower($add) === 'y') {
                    $countMethod = $rel['type'] === 'many-to-many' ? 'countShared' : 'countOwn';
                    $ctx->addMethod(
                        $methodName,
                        'int',
                        "return \$this->bean->withCondition(\$where ?? '', \$bindings)->{$countMethod}('{$rel['bean']}');",
                        ['?string $where = null', 'array $bindings = []']
                    );
                }
            }
        }

        // Custom methods
        while (true) {
            $addCustom = $this->prompt("\nAdd a custom method? (y/n)", "n");
            if (strtolower($addCustom) !== 'y') break;

            $methodName = $this->prompt("Method name (e.g., 'getLastLogin')");
            $returnType = $this->prompt("Return type (string, int, bool, array, void)", "mixed");
            $methodBody = $this->prompt("Method body (PHP code, or 'skip' to leave empty)");

            if (!empty($methodName)) {
                $ctx->addMethod(
                    $methodName,
                    $returnType,
                    $methodBody !== 'skip' ? $methodBody : '// TODO: Implement'
                );
            }
        }
    }

    /**
     * Select controller type
     */
    private function selectControllerType(): string {
        echo "\n─── Step 5: Controller Type ───────────────────────────────────────\n";
        echo "What kind of controller do you need?\n";
        echo "  1. CRUD - Session-based with views (web UI)\n";
        echo "  2. API  - Stateless JSON endpoints\n";
        echo "  3. BOTH - CRUD + separate API controller\n";
        echo "  4. NONE - Model only, no controller\n";

        $choice = $this->prompt("Choose (1-4)", "1");

        return match($choice) {
            '2' => 'api',
            '3' => 'both',
            '4' => 'none',
            default => 'crud'
        };
    }

    /**
     * Select permission level for authcontrol
     */
    private function selectPermissionLevel(): ?int {
        echo "\n─── Step 6: Permission Level ──────────────────────────────────────\n";
        echo "Who can access this endpoint?\n";
        echo "  1. PUBLIC (101) - Anyone, no login required\n";
        echo "  2. MEMBER (100) - Logged-in users (Recommended)\n";
        echo "  3. ADMIN  (50)  - Administrators only\n";
        echo "  4. ROOT   (1)   - Super admin only\n";
        echo "  5. SKIP        - Don't create authcontrol entry\n";

        $choice = $this->prompt("Choose (1-5)", "2");

        return match($choice) {
            '1' => 101,  // PUBLIC
            '2' => 100,  // MEMBER
            '3' => 50,   // ADMIN
            '4' => 1,    // ROOT
            '5' => null, // SKIP
            default => 100
        };
    }

    /**
     * Get permission level name
     */
    private function getPermissionName(?int $level): string {
        return match($level) {
            101 => 'PUBLIC',
            100 => 'MEMBER',
            50 => 'ADMIN',
            1 => 'ROOT',
            null => 'SKIP',
            default => "LEVEL {$level}"
        };
    }

    /**
     * Print summary before generation
     */
    private function printSummary(Context $ctx): void {
        echo "\n─── Summary ───────────────────────────────────────────────────────\n";
        echo "Model: Model_" . $ctx->className . "\n";
        echo "Table: {$ctx->beanName}\n";
        echo "Fields: " . count($ctx->fields) . "\n";
        echo "Relationships: " . count($ctx->relationships) . "\n";
        echo "Methods: " . count($ctx->methods) . "\n";
        echo "Controller: " . strtoupper($ctx->controllerType) . "\n";
        if ($ctx->controllerType !== 'none') {
            echo "Permission: " . $this->getPermissionName($ctx->permissionLevel) . "\n";
        }
    }

    /**
     * Generate files based on context
     */
    private function generateFiles(Context $ctx): void {
        // Always generate model
        $this->manager->generate('model', $ctx);

        // Generate based on controller type
        switch ($ctx->controllerType) {
            case 'crud':
                $this->manager->generate('crud', $ctx);
                $this->manager->generate('view', $ctx);
                break;
            case 'api':
                $this->manager->generate('api', $ctx);
                break;
            case 'both':
                $this->manager->generate('crud', $ctx);
                $this->manager->generate('api', $ctx);
                $this->manager->generate('view', $ctx);
                break;
            // 'none' - only model
        }

        // Insert authcontrol entry if permission level is set
        if ($ctx->permissionLevel !== null && $ctx->controllerType !== 'none') {
            $this->insertAuthControl($ctx);
        }
    }

    /**
     * Insert authcontrol record for the new endpoint
     */
    private function insertAuthControl(Context $ctx): void {
        $route = $ctx->beanName;
        $level = $ctx->permissionLevel;

        if ($this->manager->isDryRun()) {
            echo "[DRY-RUN] Would insert authcontrol: route='{$route}', level={$level} ({$this->getPermissionName($level)})\n";
            return;
        }

        try {
            // Check if entry already exists
            $existing = \app\Bean::findOne('authcontrol', 'route = ?', [$route]);
            if ($existing) {
                echo "⚠ Authcontrol entry for '{$route}' already exists (level: {$existing->level})\n";
                return;
            }

            // Insert new entry
            $auth = \app\Bean::dispense('authcontrol');
            $auth->route = $route;
            $auth->level = $level;
            \app\Bean::store($auth);

            echo "✓ Authcontrol: {$route} → {$this->getPermissionName($level)} ({$level})\n";

            // Also add API route if 'both' controller type
            if ($ctx->controllerType === 'both') {
                $apiRoute = $route . 'api';
                $existingApi = \app\Bean::findOne('authcontrol', 'route = ?', [$apiRoute]);
                if (!$existingApi) {
                    $authApi = \app\Bean::dispense('authcontrol');
                    $authApi->route = $apiRoute;
                    $authApi->level = $level;
                    \app\Bean::store($authApi);
                    echo "✓ Authcontrol: {$apiRoute} → {$this->getPermissionName($level)} ({$level})\n";
                }
            }
        } catch (\Exception $e) {
            echo "⚠ Could not insert authcontrol: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Print next steps after generation
     */
    private function printNextSteps(Context $ctx): void {
        echo "\n✓ Generation complete!\n";
        echo "\nNext steps:\n";
        echo "  1. Review generated files\n";

        $step = 2;
        if ($ctx->controllerType !== 'none' && $ctx->controllerType !== 'api') {
            echo "  {$step}. Test: https://yoursite.myctobot.ai/{$ctx->beanName}\n";
            $step++;
        }

        if ($ctx->controllerType === 'api' || $ctx->controllerType === 'both') {
            echo "  {$step}. API endpoint: /{$ctx->beanName}api\n";
            $step++;
        }

        if ($ctx->permissionLevel === null && $ctx->controllerType !== 'none') {
            echo "  {$step}. Add auth control entry (you skipped this):\n";
            echo "     INSERT INTO authcontrol (route, level) VALUES ('{$ctx->beanName}', 100);\n";
        }
    }

    /**
     * Check if bean table exists
     */
    private function beanExists(string $beanName): bool {
        try {
            $tables = \RedBeanPHP\R::inspect();
            return in_array(strtolower($beanName), $tables);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create minimal bean table
     */
    private function createMinimalBean(string $beanName): void {
        echo "Creating minimal bean structure for: {$beanName}\n";

        if ($this->manager->isDryRun()) {
            echo "[DRY-RUN] Would create table: {$beanName}\n";
            return;
        }

        $bean = \app\Bean::dispense($beanName);
        $bean->created_at = date('Y-m-d H:i:s');
        $bean->updated_at = date('Y-m-d H:i:s');
        $bean->name = ''; // Placeholder
        \app\Bean::trash($bean);
        echo "Table '{$beanName}' created.\n";
    }

    /**
     * Check if context has name fields (firstname/lastname)
     */
    private function hasNameFields(Context $ctx): bool {
        $nameFields = ['firstname', 'first_name', 'lastname', 'last_name'];
        foreach ($nameFields as $f) {
            if (isset($ctx->fields[$f])) return true;
        }
        return false;
    }

    /**
     * Check if context has active/status field
     */
    private function hasActiveField(Context $ctx): bool {
        $activeFields = ['active', 'is_active', 'status'];
        foreach ($activeFields as $f) {
            if (isset($ctx->fields[$f])) return true;
        }
        return false;
    }

    /**
     * Prompt for user input
     */
    private function prompt(string $message, string $default = ''): string {
        $defaultText = $default ? " [{$default}]" : '';
        echo "{$message}{$defaultText}: ";
        $input = trim(fgets(STDIN));
        return $input !== '' ? $input : $default;
    }

    /**
     * Print error message
     */
    private function error(string $message): void {
        fwrite(STDERR, "Error: {$message}\n");
    }
}
