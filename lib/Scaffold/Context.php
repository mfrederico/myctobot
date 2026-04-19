<?php
/**
 * Scaffold Context
 *
 * Holds all data needed for template rendering and provides helper methods.
 * This object is passed to all templates and generators.
 */

namespace app\Scaffold;

class Context {

    /** @var string Bean/table name (lowercase, no underscores) */
    public string $beanName;

    /** @var string Class name (ucfirst of beanName) */
    public string $className;

    /** @var array Field definitions */
    public array $fields = [];

    /** @var array Relationship definitions */
    public array $relationships = [];

    /** @var array Custom model methods */
    public array $methods = [];

    /** @var string Controller type: crud, api, both, none */
    public string $controllerType = 'crud';

    /** @var int|null Permission level for authcontrol (null = skip) */
    public ?int $permissionLevel = null;

    /** @var string Base directory of the project */
    public string $baseDir;

    /** @var bool Dry run mode */
    public bool $dryRun = false;

    /** @var bool Verbose output */
    public bool $verbose = false;

    /** @var FieldRegistry */
    public FieldRegistry $fieldRegistry;

    public function __construct(string $beanName, string $baseDir) {
        $this->beanName = strtolower(str_replace(['_', '-', ' '], '', $beanName));
        $this->className = ucfirst($this->beanName);
        $this->baseDir = $baseDir;
        $this->fieldRegistry = new FieldRegistry();

        // Add default timestamp fields
        $this->fields['created_at'] = [
            'name' => 'created_at',
            'type' => 'datetime',
            'widget' => 'datetime',
            'options' => ['auto'],
            'label' => 'Created At'
        ];
        $this->fields['updated_at'] = [
            'name' => 'updated_at',
            'type' => 'datetime',
            'widget' => 'datetime',
            'options' => ['auto'],
            'label' => 'Updated At'
        ];
    }

    /**
     * Add a field definition
     */
    public function addField(string $name, string $type, array $options = [], ?string $widget = null): self {
        $this->fields[$name] = [
            'name' => $name,
            'type' => $type,
            'widget' => $widget ?? $this->fieldRegistry->getDefaultWidget($type),
            'options' => $options,
            'label' => $this->humanize($name)
        ];
        return $this;
    }

    /**
     * Add a relationship
     */
    public function addRelationship(string $type, string $relatedBean): self {
        $relatedBean = strtolower(str_replace(['_', '-', ' '], '', $relatedBean));

        $rel = [
            'type' => $type,
            'bean' => $relatedBean,
            'beanClass' => ucfirst($relatedBean),
        ];

        switch ($type) {
            case 'has-many':
            case 'has-one':
                $rel['property'] = 'own' . ucfirst($relatedBean) . 'List';
                $rel['prefix'] = 'own';
                break;
            case 'many-to-many':
                $rel['property'] = 'shared' . ucfirst($relatedBean) . 'List';
                $rel['prefix'] = 'shared';
                break;
            case 'belongs-to':
                $rel['property'] = $relatedBean;
                $rel['prefix'] = '';
                break;
        }

        $this->relationships[] = $rel;
        return $this;
    }

    /**
     * Add a custom model method
     */
    public function addMethod(string $name, string $returnType, string $body, array $params = []): self {
        $this->methods[] = [
            'name' => $name,
            'return' => $returnType,
            'body' => $body,
            'params' => $params
        ];
        return $this;
    }

    /**
     * Get fields that should be editable in forms (excludes auto fields)
     */
    public function getEditableFields(): array {
        return array_filter($this->fields, function($field) {
            return !in_array('auto', $field['options'] ?? [])
                && !in_array($field['name'], ['id', 'created_at', 'updated_at']);
        });
    }

    /**
     * Get fields suitable for display in list views (max 5-6 columns)
     */
    public function getDisplayFields(int $max = 5): array {
        $priority = ['name', 'title', 'email', 'status', 'is_active', 'created_at'];
        $display = [];

        // First, add priority fields
        foreach ($priority as $pf) {
            if (isset($this->fields[$pf]) && count($display) < $max) {
                $display[$pf] = $this->fields[$pf];
            }
        }

        // Fill remaining with other fields
        foreach ($this->fields as $name => $field) {
            if (!isset($display[$name]) && count($display) < $max && !in_array($name, ['id', 'updated_at'])) {
                $display[$name] = $field;
            }
        }

        return $display;
    }

    /**
     * Get relationships of a specific type
     */
    public function getRelationshipsByType(string $type): array {
        return array_filter($this->relationships, fn($r) => $r['type'] === $type);
    }

    /**
     * Check if a relationship type exists
     */
    public function hasRelationships(string $type = null): bool {
        if ($type === null) {
            return !empty($this->relationships);
        }
        return !empty($this->getRelationshipsByType($type));
    }

    /**
     * Convert snake_case to Human Readable
     */
    public function humanize(string $name): string {
        return ucwords(str_replace(['_', '-'], ' ', $name));
    }

    /**
     * Convert to camelCase
     */
    public function camelize(string $name): string {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $name))));
    }

    /**
     * Map database type to PHP type hint
     */
    public function phpType(string $type): string {
        return match($type) {
            'int' => 'int',
            'float' => 'float',
            'bool' => 'bool',
            'datetime', 'date' => 'string',
            'json' => 'string',
            'text' => 'string',
            default => 'string'
        };
    }

    /**
     * Get the template directory path
     */
    public function getTemplateDir(): string {
        return __DIR__ . '/Templates';
    }

    /**
     * Render a template file with this context
     */
    public function render(string $templatePath): string {
        $fullPath = $this->getTemplateDir() . '/' . $templatePath;

        if (!file_exists($fullPath)) {
            throw new \RuntimeException("Template not found: {$fullPath}");
        }

        // Extract context properties as variables for the template
        $ctx = $this;

        ob_start();
        include $fullPath;
        return ob_get_clean();
    }

    /**
     * Render a field widget template
     */
    public function renderField(array $field): string {
        $widget = $field['widget'] ?? 'text';
        $templatePath = "fields/{$widget}.php";
        $fullPath = $this->getTemplateDir() . '/' . $templatePath;

        // Fallback to type-based template
        if (!file_exists($fullPath)) {
            $templatePath = "fields/{$field['type']}.php";
            $fullPath = $this->getTemplateDir() . '/' . $templatePath;
        }

        // Ultimate fallback to text
        if (!file_exists($fullPath)) {
            $templatePath = "fields/text.php";
            $fullPath = $this->getTemplateDir() . '/' . $templatePath;
        }

        $ctx = $this;
        $f = $field; // Shorthand for template

        ob_start();
        include $fullPath;
        return ob_get_clean();
    }

    /**
     * Output helper for templates
     */
    public function log(string $message): void {
        if ($this->verbose) {
            echo "  {$message}\n";
        }
    }

    /**
     * Write a file (respects dry-run mode)
     */
    public function writeFile(string $path, string $content): bool {
        if ($this->dryRun) {
            echo "[DRY-RUN] Would write: {$path}\n";
            if ($this->verbose) {
                echo "--- Preview ---\n" . substr($content, 0, 500) . "\n--- End ---\n";
            }
            return true;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $content);
        echo "✓ Created: {$path}\n";
        return true;
    }
}
