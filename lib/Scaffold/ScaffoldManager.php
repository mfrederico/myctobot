<?php
/**
 * Scaffold Manager
 *
 * Main orchestrator for the scaffold system.
 * Coordinates commands, generators, and templates.
 */

namespace app\Scaffold;

use app\Scaffold\Commands\WizardCommand;
use app\Scaffold\Commands\ScaffoldCommand;
use app\Scaffold\Commands\BeanCommand;
use app\Scaffold\Generators\ModelGenerator;
use app\Scaffold\Generators\CrudControllerGenerator;
use app\Scaffold\Generators\ApiControllerGenerator;
use app\Scaffold\Generators\ViewGenerator;

class ScaffoldManager {

    private string $baseDir;
    private bool $verbose = false;
    private bool $dryRun = false;
    private ?string $workspace = null;

    /** @var array Registered generators */
    private array $generators = [];

    public function __construct(string $baseDir) {
        $this->baseDir = $baseDir;
        $this->registerDefaultGenerators();
    }

    /**
     * Register default generators
     */
    private function registerDefaultGenerators(): void {
        $this->generators = [
            'model' => ModelGenerator::class,
            'crud' => CrudControllerGenerator::class,
            'controller' => CrudControllerGenerator::class, // Alias
            'api' => ApiControllerGenerator::class,
            'view' => ViewGenerator::class,
            'views' => ViewGenerator::class, // Alias
        ];
    }

    /**
     * Register a custom generator
     */
    public function registerGenerator(string $name, string $class): self {
        $this->generators[$name] = $class;
        return $this;
    }

    /**
     * Set verbose mode
     */
    public function setVerbose(bool $verbose): self {
        $this->verbose = $verbose;
        return $this;
    }

    /**
     * Set dry-run mode
     */
    public function setDryRun(bool $dryRun): self {
        $this->dryRun = $dryRun;
        return $this;
    }

    /**
     * Set workspace
     */
    public function setWorkspace(?string $workspace): self {
        $this->workspace = $workspace;
        return $this;
    }

    /**
     * Get the base directory
     */
    public function getBaseDir(): string {
        return $this->baseDir;
    }

    /**
     * Create a new context for scaffolding
     */
    public function createContext(string $beanName): Context {
        $ctx = new Context($beanName, $this->baseDir);
        $ctx->verbose = $this->verbose;
        $ctx->dryRun = $this->dryRun;
        return $ctx;
    }

    /**
     * Run a generator by name
     */
    public function generate(string $generatorName, Context $ctx): bool {
        $generatorName = strtolower($generatorName);

        if (!isset($this->generators[$generatorName])) {
            throw new \InvalidArgumentException("Unknown generator: {$generatorName}");
        }

        $generatorClass = $this->generators[$generatorName];
        $generator = new $generatorClass();

        if ($this->verbose) {
            echo "Running generator: {$generatorName}\n";
        }

        return $generator->generate($ctx);
    }

    /**
     * Run multiple generators
     */
    public function generateMultiple(array $generatorNames, Context $ctx): array {
        $results = [];
        foreach ($generatorNames as $name) {
            try {
                $results[$name] = $this->generate($name, $ctx);
            } catch (\Exception $e) {
                $results[$name] = false;
                echo "Error in generator '{$name}': " . $e->getMessage() . "\n";
            }
        }
        return $results;
    }

    /**
     * Run the interactive wizard
     */
    public function runWizard(): void {
        $command = new WizardCommand($this);
        $command->run();
    }

    /**
     * Run scaffold command (generate from existing or spec)
     */
    public function runScaffold(string $beanName, array $parts): void {
        $command = new ScaffoldCommand($this);
        $command->run($beanName, $parts);
    }

    /**
     * Run bean data operations
     */
    public function runBeanCommand(string $operation, string $beanName, array $data, ?string $associate = null, ?array $matchFields = null): void {
        $command = new BeanCommand($this);
        $command->run($operation, $beanName, $data, $associate, $matchFields);
    }

    /**
     * List all tables in the database
     */
    public function listTables(): array {
        try {
            $tables = \RedBeanPHP\R::inspect();
            sort($tables);
            return $tables;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get available generators
     */
    public function getGenerators(): array {
        return array_keys($this->generators);
    }

    /**
     * Get the template directory
     */
    public function getTemplateDir(): string {
        return __DIR__ . '/Templates';
    }

    /**
     * Check if verbose mode is enabled
     */
    public function isVerbose(): bool {
        return $this->verbose;
    }

    /**
     * Check if dry-run mode is enabled
     */
    public function isDryRun(): bool {
        return $this->dryRun;
    }
}
