<?php
/**
 * Field Registry
 *
 * Manages field types, their default widgets, and validation rules.
 * Custom widgets can be registered for specialized UI components.
 */

namespace app\Scaffold;

class FieldRegistry {

    /**
     * Map of data types to their default widgets
     */
    private array $typeWidgetMap = [
        'string'   => 'text',
        'text'     => 'textarea',
        'int'      => 'number',
        'float'    => 'number',
        'bool'     => 'checkbox',
        'datetime' => 'datetime',
        'date'     => 'date',
        'json'     => 'json',
        'email'    => 'email',
        'password' => 'password',
        'url'      => 'url',
        'select'   => 'select',
        'enum'     => 'enum',
    ];

    /**
     * Registered custom widgets with metadata
     */
    private array $customWidgets = [];

    /**
     * Field name patterns that auto-detect type
     */
    private array $namePatterns = [
        '/email$/i'      => ['type' => 'string', 'widget' => 'email'],
        '/password$/i'   => ['type' => 'string', 'widget' => 'password'],
        '/url$/i'        => ['type' => 'string', 'widget' => 'url'],
        '/description$/i'=> ['type' => 'text', 'widget' => 'textarea'],
        '/content$/i'    => ['type' => 'text', 'widget' => 'textarea'],
        '/body$/i'       => ['type' => 'text', 'widget' => 'textarea'],
        '/notes$/i'      => ['type' => 'text', 'widget' => 'textarea'],
        '/is_\w+$/i'     => ['type' => 'bool', 'widget' => 'checkbox'],
        '/has_\w+$/i'    => ['type' => 'bool', 'widget' => 'checkbox'],
        '/_enabled$/i'   => ['type' => 'bool', 'widget' => 'checkbox'],
        '/_active$/i'    => ['type' => 'bool', 'widget' => 'checkbox'],
        '/_at$/i'        => ['type' => 'datetime', 'widget' => 'datetime'],
        '/_date$/i'      => ['type' => 'date', 'widget' => 'date'],
        '/_json$/i'      => ['type' => 'json', 'widget' => 'json'],
        '/_id$/i'        => ['type' => 'int', 'widget' => 'number'],
        '/count$/i'      => ['type' => 'int', 'widget' => 'number'],
        '/amount$/i'     => ['type' => 'float', 'widget' => 'number'],
        '/price$/i'      => ['type' => 'float', 'widget' => 'number'],
        '/total$/i'      => ['type' => 'float', 'widget' => 'number'],
    ];

    /**
     * Get the default widget for a data type
     */
    public function getDefaultWidget(string $type): string {
        return $this->typeWidgetMap[$type] ?? 'text';
    }

    /**
     * Register a custom widget
     *
     * @param string $name Widget name (e.g., 'fancyDateSelector')
     * @param array $meta Metadata: template, js, css, description
     */
    public function registerWidget(string $name, array $meta = []): self {
        $this->customWidgets[$name] = array_merge([
            'template' => "fields/{$name}.php",
            'js' => [],
            'css' => [],
            'description' => '',
        ], $meta);
        return $this;
    }

    /**
     * Check if a widget exists (built-in or custom)
     */
    public function widgetExists(string $name): bool {
        // Check custom widgets
        if (isset($this->customWidgets[$name])) {
            return true;
        }

        // Check if template file exists
        $templatePath = __DIR__ . "/Templates/fields/{$name}.php";
        return file_exists($templatePath);
    }

    /**
     * Get widget metadata
     */
    public function getWidgetMeta(string $name): ?array {
        return $this->customWidgets[$name] ?? null;
    }

    /**
     * Get all registered custom widgets
     */
    public function getCustomWidgets(): array {
        return $this->customWidgets;
    }

    /**
     * Auto-detect field type and widget from field name
     */
    public function detectFromName(string $fieldName): ?array {
        foreach ($this->namePatterns as $pattern => $config) {
            if (preg_match($pattern, $fieldName)) {
                return $config;
            }
        }
        return null;
    }

    /**
     * Parse a field definition string
     *
     * Examples:
     *   email:string:required,widget=email
     *   status:enum=pending|active|archived:required
     *   integration_type:enum=jira|github|shopify
     */
    public function parseFieldString(string $input): ?array {
        $parts = explode(':', $input);
        if (empty($parts[0])) {
            return null;
        }

        $name = strtolower(trim($parts[0]));
        $typeSpec = isset($parts[1]) ? trim($parts[1]) : 'string';
        $optionsStr = $parts[2] ?? '';

        // Check for enum type with values: enum=value1|value2|value3
        $enumValues = [];
        if (preg_match('/^enum=(.+)$/i', $typeSpec, $matches)) {
            $type = 'enum';
            $enumValues = array_map('trim', explode('|', $matches[1]));
        } else {
            $type = strtolower($typeSpec);
        }

        // Parse options (required, unique, default=VALUE, widget=NAME)
        $options = [];
        $widget = null;
        $default = null;

        foreach (explode(',', $optionsStr) as $opt) {
            $opt = trim($opt);
            if (empty($opt)) continue;

            if (strpos($opt, '=') !== false) {
                [$key, $value] = explode('=', $opt, 2);
                if ($key === 'widget') {
                    $widget = $value;
                } elseif ($key === 'default') {
                    $default = $value;
                    $options[] = "default={$value}";
                } else {
                    $options[] = $opt;
                }
            } else {
                $options[] = $opt;
            }
        }

        // Auto-detect from name if type not explicitly set
        if ($type === 'string') {
            $detected = $this->detectFromName($name);
            if ($detected) {
                $type = $detected['type'];
                $widget = $widget ?? $detected['widget'];
            }
        }

        // Default widget from type
        $widget = $widget ?? $this->getDefaultWidget($type);

        $result = [
            'name' => $name,
            'type' => $type,
            'widget' => $widget,
            'options' => $options,
            'label' => ucwords(str_replace('_', ' ', $name))
        ];

        // Add enum values if present
        if (!empty($enumValues)) {
            $result['enum_values'] = $enumValues;
        }

        return $result;
    }

    /**
     * Get valid data types
     */
    public function getValidTypes(): array {
        return array_keys($this->typeWidgetMap);
    }

    /**
     * Get all available widgets (built-in + custom)
     */
    public function getAvailableWidgets(): array {
        $widgets = [];

        // Scan template directory for built-in widgets
        $templateDir = __DIR__ . '/Templates/fields';
        if (is_dir($templateDir)) {
            foreach (glob("{$templateDir}/*.php") as $file) {
                $widgets[] = basename($file, '.php');
            }
        }

        // Add custom widgets
        foreach (array_keys($this->customWidgets) as $name) {
            if (!in_array($name, $widgets)) {
                $widgets[] = $name;
            }
        }

        sort($widgets);
        return $widgets;
    }
}
