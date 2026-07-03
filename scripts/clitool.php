#!/usr/bin/env php
<?php
/**
 * MyCTOBot CLI Tool
 *
 * A comprehensive tool for RedBeanPHP + FlightPHP development:
 * - Create models with FUSE hooks (interactive wizard)
 * - Define relationships (own/shared)
 * - Generate controllers (CRUD/API/Both)
 * - Scaffold views with Bootstrap 5
 * - Manipulate bean data from command line
 *
 * USAGE:
 * ------
 * # Interactive model creation wizard:
 * php scripts/clitool.php --workspace=gwt --wizard
 *
 * # Update bean data:
 * php scripts/clitool.php --workspace=gwt --bean=member --data='{"id":1,"firstname":"bilbo"}'
 *
 * # Associate beans (many-to-many):
 * php scripts/clitool.php --workspace=gwt --bean=member --associate=jiraboard --data='{"id":1,"jiraboard_id":5}'
 *
 * # Export bean as JSON:
 * php scripts/clitool.php --workspace=gwt --bean=member --data='{"id":1}' --getjson
 *
 * # Scaffold model/control/view from existing table:
 * php scripts/clitool.php --workspace=gwt --bean=product --scaffold=model,control,view
 *
 * # List all tables in workspace:
 * php scripts/clitool.php --workspace=gwt --list
 */

error_reporting(E_ALL);
$scriptDir = dirname(__FILE__);
$baseDir = dirname($scriptDir);
chdir($baseDir);

// Parse command line options
$options = getopt('', [
    'workspace:',
    'bean:',
    'data:',
    'associate:',
    'getjson',
    'getall',
    'find',
    'findone',
    'create',
    'findorcreate',
    'match:',
    'trash',
    'scaffold:',
    'wizard',
    'list',
    'verbose',
    'help',
    'script',
    'dry-run',
    'limit:',
    'order:',
    // authcontrol (permission) management
    'authcontrol-add',
    'authcontrol-scan',
    'authcontrol-seed-missing',
    'control:',
    'method:',
    'level:',
    'desc:'
]);

if (isset($options['help']) || (count($options) === 0)) {
    showHelp();
    exit(0);
}

$verbose = isset($options['verbose']);
$dryRun = isset($options['dry-run']);

// Load dependencies
require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/bootstrap.php';

// Workspace handling
$workspace = $options['workspace'] ?? null;
if ($workspace) {
    $configFile = $baseDir . "/conf/config.{$workspace}.ini";
    if (!file_exists($configFile)) {
        fwrite(STDERR, "Error: Workspace config not found: {$configFile}\n");
        exit(1);
    }
} else {
    $configFile = $baseDir . '/conf/config.ini';
}

// Bootstrap the application
$bootstrap = new \app\Bootstrap($configFile);

// Load scaffold library
require_once $baseDir . '/lib/Scaffold/FieldRegistry.php';
require_once $baseDir . '/lib/Scaffold/Context.php';
require_once $baseDir . '/lib/Scaffold/Generators/GeneratorInterface.php';
require_once $baseDir . '/lib/Scaffold/Generators/ModelGenerator.php';
require_once $baseDir . '/lib/Scaffold/Generators/CrudControllerGenerator.php';
require_once $baseDir . '/lib/Scaffold/Generators/ApiControllerGenerator.php';
require_once $baseDir . '/lib/Scaffold/Generators/ViewGenerator.php';
require_once $baseDir . '/lib/Scaffold/Commands/WizardCommand.php';
require_once $baseDir . '/lib/Scaffold/Commands/ScaffoldCommand.php';
require_once $baseDir . '/lib/Scaffold/Commands/BeanCommand.php';
require_once $baseDir . '/lib/Scaffold/ScaffoldManager.php';

use app\Scaffold\ScaffoldManager;
use app\Scaffold\Commands\BeanCommand;

// Initialize scaffold manager
$manager = new ScaffoldManager($baseDir);
$manager->setVerbose($verbose);
$manager->setDryRun($dryRun);
$manager->setWorkspace($workspace);

// ============================================================================
// COMMAND ROUTING
// ============================================================================

if (isset($options['wizard'])) {
    $manager->runWizard();
    exit(0);
}

if (isset($options['list'])) {
    $beanCmd = new BeanCommand($manager);
    $beanCmd->listTables();
    exit(0);
}

// ----------------------------------------------------------------------------
// authcontrol (permission) management
// ----------------------------------------------------------------------------

/**
 * Enumerate routable controller methods: public methods with all-lowercase
 * names (CamelCase methods are not URL-routable). Returns [ [control, method], ... ].
 */
function ac_enumerateRoutableMethods(string $baseDir): array {
    $routes = [];
    foreach (glob($baseDir . '/controls/*.php') as $file) {
        $control = strtolower(basename($file, '.php'));
        $src = file_get_contents($file);
        // public function methodname(   — lowercase name only
        if (preg_match_all('/public\s+(?:static\s+)?function\s+([a-z][a-z0-9_]*)\s*\(/', $src, $m)) {
            foreach ($m[1] as $method) {
                if (in_array($method, ['__construct', '__destruct', '__get', '__set', '__call'], true)) {
                    continue;
                }
                $routes[$control . '::' . $method] = [$control, $method];
            }
        }
    }
    ksort($routes);
    return $routes;
}

function ac_existingRules(): array {
    $rules = [];
    foreach (\app\Bean::findAll('authcontrol') as $r) {
        $rules[strtolower($r->control) . '::' . strtolower($r->method)] = (int) $r->level;
    }
    return $rules;
}

/**
 * A route is "covered" if it has an authcontrol rule (exact or control::*
 * wildcard) OR is in PermissionCache's always-public list (which is checked
 * before authcontrol, so such routes never fall through to the deny default).
 */
function ac_isCovered(string $control, string $method, array $rules): bool {
    $key = $control . '::' . $method;
    if (isset($rules[$key]) || isset($rules[$control . '::*'])) {
        return true;
    }
    static $public = null;
    if ($public === null) {
        $public = class_exists('\app\PermissionCache') ? \app\PermissionCache::publicRoutes() : [];
    }
    return in_array($key, $public, true) || in_array($control . '::*', $public, true);
}

function ac_upsert(string $control, string $method, int $level, ?string $desc = null): string {
    $control = strtolower($control);
    $method  = strtolower($method);
    $existing = \app\Bean::findOne('authcontrol', 'LOWER(control) = ? AND LOWER(method) = ?', [$control, $method]);
    if ($existing) {
        $existing->level = $level;
        if ($desc !== null) { $existing->description = $desc; }
        \app\Bean::store($existing);
        $action = 'updated';
    } else {
        $bean = \app\Bean::dispense('authcontrol');
        $bean->control = $control;
        $bean->method = $method;
        $bean->level = $level;
        $bean->description = $desc ?? '';
        $bean->validcount = 0;
        $bean->created_at = date('Y-m-d H:i:s');
        \app\Bean::store($bean);
        $action = 'created';
    }
    if (class_exists('\app\PermissionCache')) { \app\PermissionCache::clear(); }
    return $action;
}

if (isset($options['authcontrol-add'])) {
    $control = $options['control'] ?? null;
    $method  = $options['method'] ?? null;
    if (!$control || !$method || !isset($options['level'])) {
        fwrite(STDERR, "Error: --authcontrol-add requires --control, --method, --level\n");
        fwrite(STDERR, "  Levels: 1=ROOT 50=ADMIN 75=SALES 100=MEMBER 101=PUBLIC\n");
        exit(1);
    }
    $level = (int) $options['level'];
    $action = ac_upsert($control, $method, $level, $options['desc'] ?? null);
    echo "authcontrol {$action}: " . strtolower($control) . "::" . strtolower($method) . " => level {$level}\n";
    exit(0);
}

if (isset($options['authcontrol-scan'])) {
    $routes = ac_enumerateRoutableMethods($baseDir);
    $rules  = ac_existingRules();
    $gaps = [];
    foreach ($routes as $key => [$control, $method]) {
        if (!ac_isCovered($control, $method, $rules)) {
            $gaps[] = $key;
        }
    }
    echo "Routable methods: " . count($routes) . " | covered (rule or public): " . (count($routes) - count($gaps)) . " | GAPS (would be DENIED): " . count($gaps) . "\n";
    if ($gaps) {
        echo "\nMethods with NO authcontrol rule (fail-closed => denied):\n";
        foreach ($gaps as $g) { echo "  {$g}\n"; }
        echo "\nGrant one with:\n  php scripts/clitool.php --workspace={$workspace} --authcontrol-add --control=X --method=Y --level=100\n";
    }
    exit(0);
}

if (isset($options['authcontrol-seed-missing'])) {
    // Level for each gap is INFERRED from the controller's existing sibling
    // rules (the most restrictive = lowest number, so we never accidentally
    // widen access). Controllers with no existing rule fall back to --level
    // (default ROOT=1 = locked, pending manual review). This keeps the app
    // working after the fail-closed flip while never exposing a sensitive
    // route more broadly than its siblings already are.
    $fallback = isset($options['level']) ? (int) $options['level'] : 1;
    $routes = ac_enumerateRoutableMethods($baseDir);
    $rules  = ac_existingRules();

    // Most restrictive (lowest) existing level per control.
    $controlLevel = [];
    foreach ($rules as $key => $lvl) {
        $control = explode('::', $key, 2)[0];
        $controlLevel[$control] = isset($controlLevel[$control]) ? min($controlLevel[$control], $lvl) : $lvl;
    }

    $seeded = 0;
    foreach ($routes as $key => [$control, $method]) {
        if (ac_isCovered($control, $method, $rules)) {
            continue;
        }
        $level = $controlLevel[$control] ?? $fallback;
        $note  = isset($controlLevel[$control]) ? "inherited from {$control}::" : 'fallback (review)';
        if ($dryRun) {
            echo "[DRY-RUN] would seed {$key} => level {$level} ({$note})\n";
        } else {
            ac_upsert($control, $method, $level, 'auto-seeded: ' . $note);
            echo "seeded {$key} => level {$level} ({$note})\n";
        }
        $seeded++;
    }
    echo ($dryRun ? "[DRY-RUN] " : "") . "Seeded {$seeded} missing route(s). Review any at level 1 (fallback) and set the correct level.\n";
    exit(0);
}

if (isset($options['scaffold'])) {
    $beanName = $options['bean'] ?? null;
    if (!$beanName) {
        fwrite(STDERR, "Error: --bean is required for scaffolding\n");
        exit(1);
    }
    $scaffoldParts = array_map('trim', explode(',', $options['scaffold']));
    $manager->runScaffold($beanName, $scaffoldParts);
    exit(0);
}

if (isset($options['bean'])) {
    $beanName = $options['bean'];
    $data = isset($options['data']) ? json_decode($options['data'], true) : [];

    if (json_last_error() !== JSON_ERROR_NONE && isset($options['data'])) {
        fwrite(STDERR, "Error: Invalid JSON in --data: " . json_last_error_msg() . "\n");
        exit(1);
    }

    if (isset($options['trash'])) {
        // --trash deletes a record by ID
        if (empty($data) || !isset($data['id'])) {
            fwrite(STDERR, "Error: --data='{\"id\":N}' is required for --trash\n");
            exit(1);
        }
        $manager->runBeanCommand('trash', $beanName, $data);
    } elseif (isset($options['create'])) {
        // --create creates a new record
        if (empty($data)) {
            fwrite(STDERR, "Error: --data is required for --create\n");
            exit(1);
        }
        $manager->runBeanCommand('create', $beanName, $data);
    } elseif (isset($options['findorcreate'])) {
        // --findorcreate finds by match fields or creates with all data
        if (empty($data)) {
            fwrite(STDERR, "Error: --data is required for --findorcreate\n");
            exit(1);
        }
        $matchFields = isset($options['match']) ? explode(',', $options['match']) : [];
        if (empty($matchFields)) {
            fwrite(STDERR, "Error: --match=field1,field2 is required for --findorcreate\n");
            exit(1);
        }
        $manager->runBeanCommand('findorcreate', $beanName, $data, null, $matchFields);
    } elseif (isset($options['getall'])) {
        // --getall doesn't require --data
        $getAllOptions = [
            'limit' => (int) ($options['limit'] ?? 50),
            'order' => $options['order'] ?? 'id DESC'
        ];
        $manager->runBeanCommand('getall', $beanName, $getAllOptions);
    } elseif (isset($options['getjson'])) {
        $manager->runBeanCommand('export', $beanName, $data);
    } elseif (isset($options['find'])) {
        $manager->runBeanCommand('find', $beanName, $data);
    } elseif (isset($options['findone'])) {
        $manager->runBeanCommand('findone', $beanName, $data);
    } elseif (isset($options['associate'])) {
        $manager->runBeanCommand('associate', $beanName, $data, $options['associate']);
    } elseif (!empty($data)) {
        $manager->runBeanCommand('update', $beanName, $data);
    } else {
        fwrite(STDERR, "Error: --data is required for bean operations (or use --getall to list all records)\n");
        exit(1);
    }
    exit(0);
}

showHelp();
exit(1);

// ============================================================================
// HELP
// ============================================================================

function showHelp(): void {
    echo <<<HELP
MyCTOBot CLI Tool - RedBeanPHP + FlightPHP Development Helper

USAGE:
  php scripts/clitool.php [options]

OPTIONS:
  --workspace=NAME   Workspace to operate on (loads conf/config.NAME.ini)
  --wizard           Interactive model creation wizard
  --list             List all bean tables in the workspace database
  --bean=NAME        Bean/table name for operations
  --data=JSON        JSON data for bean operations
  --associate=NAME   Associate bean with another (many-to-many)
  --getjson          Export bean as JSON (requires id in data)
  --getall           Get all records from a bean (no --data needed)
  --find             Find records by any field(s) in data (returns array)
  --findone          Find single record by any field(s) in data
  --findorcreate     Find by match fields, or create if not found
  --match=FIELDS     Comma-separated fields for findorcreate lookup
  --create           Create a new record (no id needed)
  --trash            Delete a record by ID (requires id in data)
  --limit=N          Limit results (default: 50, used with --getall/--find)
  --order=FIELD      Order by field (default: "id DESC")
  --scaffold=PARTS   Generate files (model,control,view,api,all)
  --verbose          Show detailed output
  --dry-run          Show what would be done without writing files
  --help             Show this help

EXAMPLES:
  # Interactive wizard to create a new model
  php scripts/clitool.php --workspace=gwt --wizard

  # Update a member record
  php scripts/clitool.php --workspace=gwt --bean=member \\
      --data='{"id":1,"firstname":"Bilbo","lastname":"Baggins"}'

  # Associate member with jiraboard (many-to-many)
  php scripts/clitool.php --workspace=gwt --bean=member \\
      --associate=jiraboard --data='{"id":1,"jiraboard_id":5}'

  # Export bean as JSON (by ID)
  php scripts/clitool.php --workspace=gwt --bean=member \\
      --data='{"id":1}' --getjson

  # Get all records from a bean (no --data needed)
  php scripts/clitool.php --workspace=gwt --bean=apikeys --getall

  # Get all with custom limit and order
  php scripts/clitool.php --workspace=gwt --bean=member --getall --limit=100 --order="created_at ASC"

  # Find records by any field (returns array)
  php scripts/clitool.php --workspace=gwt --bean=pipelinerunlogs \\
      --data='{"input_token":"abc123"}' --findone

  # Find multiple records with limit/order options
  php scripts/clitool.php --workspace=gwt --bean=pipelineruns \\
      --data='{"status":"running","_limit":10,"_order":"created_at DESC"}' --find

  # Find or create a record (uses control+method as lookup key)
  php scripts/clitool.php --workspace=gwt --bean=authcontrol \\
      --findorcreate --match=control,method \\
      --data='{"control":"shopify","method":"callback","level":101}'

  # Delete a record by ID
  php scripts/clitool.php --workspace=gwt --bean=mcpservers \
      --data='{"id":43}' --trash

  # Scaffold model, controller, and views from existing table
  php scripts/clitool.php --workspace=gwt --bean=product \\
      --scaffold=model,control,view

  # Scaffold everything (model + CRUD controller + views)
  php scripts/clitool.php --workspace=gwt --bean=product --scaffold=all

  # List all tables
  php scripts/clitool.php --workspace=gwt --list

CUSTOM FIELD WIDGETS:
  When defining fields in the wizard, you can specify custom widgets:
    email:string:widget=email          # Email input with icon
    password:string:widget=password    # Password with toggle visibility
    start_date:datetime:widget=fancyDateSelector  # Your custom widget

  Create custom widgets in: lib/Scaffold/Templates/fields/

TEMPLATE CUSTOMIZATION:
  All generated code comes from templates in lib/Scaffold/Templates/:
    model.php              - RedBeanPHP FUSE model
    controller/crud.php    - Session-based CRUD controller
    controller/api.php     - Stateless API controller
    view/index.php         - List view with table
    view/edit.php          - Create/edit form
    fields/*.php           - Form field widgets


HELP;
}
