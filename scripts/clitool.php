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
    'scaffold:',
    'wizard',
    'list',
    'verbose',
    'help',
    'script',
    'dry-run'
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

    if (isset($options['getjson'])) {
        $manager->runBeanCommand('export', $beanName, $data);
    } elseif (isset($options['associate'])) {
        $manager->runBeanCommand('associate', $beanName, $data, $options['associate']);
    } elseif (!empty($data)) {
        $manager->runBeanCommand('update', $beanName, $data);
    } else {
        fwrite(STDERR, "Error: --data is required for bean operations\n");
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
  --getjson          Export bean as JSON
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

  # Export bean as JSON
  php scripts/clitool.php --workspace=gwt --bean=member \\
      --data='{"id":1}' --getjson

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
