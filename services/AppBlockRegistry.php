<?php
/**
 * AppBlockRegistry - Defines all available block types for the app builder
 *
 * Each block type has:
 * - A unique type identifier
 * - Category (layout, data, input, interactive)
 * - Icon (Bootstrap Icons)
 * - Config schema defining what can be configured
 * - Default config values
 */

namespace app\services;

class AppBlockRegistry
{
    /**
     * Block categories
     */
    public const CATEGORIES = [
        'layout' => ['label' => 'Layout', 'icon' => 'bi-layout-text-window', 'description' => 'Page structure and content'],
        'data' => ['label' => 'Data', 'icon' => 'bi-database', 'description' => 'Display data from pipelines'],
        'input' => ['label' => 'Input', 'icon' => 'bi-input-cursor-text', 'description' => 'Collect user input'],
        'interactive' => ['label' => 'Interactive', 'icon' => 'bi-lightning', 'description' => 'Interactive elements'],
    ];

    /**
     * Get all registered block types
     */
    public static function getAll(): array
    {
        return [
            'header' => self::headerBlock(),
            'card' => self::cardBlock(),
            'kpi_row' => self::kpiRowBlock(),
            'data_table' => self::dataTableBlock(),
            'form' => self::formBlock(),
            'chat_widget' => self::chatWidgetBlock(),
            'alert' => self::alertBlock(),
            'button_group' => self::buttonGroupBlock(),
            'list_group' => self::listGroupBlock(),
            'markdown' => self::markdownBlock(),
            'row' => self::rowBlock(),
            'tabs' => self::tabsBlock(),
            'chart' => self::chartBlock(),
        ];
    }

    /**
     * Get block types grouped by category
     */
    public static function getGrouped(): array
    {
        $all = self::getAll();
        $grouped = [];
        foreach (self::CATEGORIES as $catKey => $catInfo) {
            $grouped[$catKey] = [
                'label' => $catInfo['label'],
                'icon' => $catInfo['icon'],
                'description' => $catInfo['description'],
                'blocks' => [],
            ];
        }

        foreach ($all as $type => $block) {
            $cat = $block['category'];
            if (isset($grouped[$cat])) {
                $grouped[$cat]['blocks'][$type] = $block;
            }
        }

        return $grouped;
    }

    /**
     * Get a single block type definition
     */
    public static function get(string $type): ?array
    {
        $all = self::getAll();
        return $all[$type] ?? null;
    }

    /**
     * Get default config for a block type
     */
    public static function getDefaultConfig(string $type): array
    {
        $def = self::get($type);
        return $def ? $def['default_config'] : [];
    }

    /**
     * Create a new block instance with defaults
     */
    public static function createBlock(string $type, array $configOverrides = []): array
    {
        $def = self::get($type);
        if (!$def) {
            throw new \InvalidArgumentException("Unknown block type: {$type}");
        }

        return [
            'id' => 'block_' . bin2hex(random_bytes(4)),
            'type' => $type,
            'config' => array_merge($def['default_config'], $configOverrides),
            'data_binding' => [],
            'events' => [],
            'visibility' => [],
        ];
    }

    // ─── Block Type Definitions ──────────────────────────────────

    private static function headerBlock(): array
    {
        return [
            'type' => 'header',
            'label' => 'Header',
            'description' => 'Page header with title, breadcrumb, and action buttons',
            'icon' => 'bi-card-heading',
            'category' => 'layout',
            'supports_data_binding' => false,
            'config_schema' => [
                'title' => ['type' => 'string', 'label' => 'Title', 'required' => true],
                'subtitle' => ['type' => 'string', 'label' => 'Subtitle'],
                'show_breadcrumb' => ['type' => 'boolean', 'label' => 'Show Breadcrumb', 'default' => false],
                'breadcrumb' => ['type' => 'json', 'label' => 'Breadcrumb Items', 'description' => 'Array of {label, url} objects'],
                'actions' => ['type' => 'json', 'label' => 'Action Buttons', 'description' => 'Array of {label, icon, variant, event, target}'],
            ],
            'default_config' => [
                'title' => 'Page Title',
                'subtitle' => '',
                'show_breadcrumb' => false,
                'breadcrumb' => [],
                'actions' => [],
            ],
        ];
    }

    private static function cardBlock(): array
    {
        return [
            'type' => 'card',
            'label' => 'Card',
            'description' => 'Content card with title, body, and optional footer',
            'icon' => 'bi-card-text',
            'category' => 'layout',
            'supports_data_binding' => true,
            'config_schema' => [
                'title' => ['type' => 'string', 'label' => 'Title'],
                'icon' => ['type' => 'string', 'label' => 'Icon', 'description' => 'Bootstrap icon class'],
                'variant' => ['type' => 'enum', 'label' => 'Variant', 'options' => ['default', 'primary', 'success', 'warning', 'danger', 'info']],
                'body_type' => ['type' => 'enum', 'label' => 'Body Type', 'options' => ['text', 'html', 'template']],
                'content' => ['type' => 'text', 'label' => 'Content', 'description' => 'Supports markdown'],
                'footer' => ['type' => 'string', 'label' => 'Footer Text'],
            ],
            'default_config' => [
                'title' => 'Card Title',
                'icon' => '',
                'variant' => 'default',
                'body_type' => 'text',
                'content' => '',
                'footer' => '',
            ],
        ];
    }

    private static function kpiRowBlock(): array
    {
        return [
            'type' => 'kpi_row',
            'label' => 'KPI Row',
            'description' => 'Row of colored stat cards with icons and values',
            'icon' => 'bi-speedometer2',
            'category' => 'data',
            'supports_data_binding' => true,
            'config_schema' => [
                'columns' => ['type' => 'integer', 'label' => 'Columns', 'default' => 4, 'min' => 2, 'max' => 6],
                'cards' => ['type' => 'json', 'label' => 'KPI Cards', 'description' => 'Array of {label, icon, color, value_key, format}'],
            ],
            'default_config' => [
                'columns' => 4,
                'cards' => [
                    ['label' => 'Total', 'icon' => 'bi-bag', 'color' => 'primary', 'value_key' => 'total', 'format' => 'number'],
                    ['label' => 'Active', 'icon' => 'bi-check-circle', 'color' => 'success', 'value_key' => 'active', 'format' => 'number'],
                    ['label' => 'Pending', 'icon' => 'bi-clock', 'color' => 'warning', 'value_key' => 'pending', 'format' => 'number'],
                    ['label' => 'Issues', 'icon' => 'bi-exclamation-triangle', 'color' => 'danger', 'value_key' => 'issues', 'format' => 'number'],
                ],
            ],
        ];
    }

    private static function dataTableBlock(): array
    {
        return [
            'type' => 'data_table',
            'label' => 'Data Table',
            'description' => 'Responsive table with pagination, sorting, and badges',
            'icon' => 'bi-table',
            'category' => 'data',
            'supports_data_binding' => true,
            'config_schema' => [
                'title' => ['type' => 'string', 'label' => 'Title'],
                'columns' => ['type' => 'column_mapper', 'label' => 'Columns'],
                'row_click' => ['type' => 'json', 'label' => 'Row Click Action', 'description' => '{event, target}'],
                'pagination' => ['type' => 'integer', 'label' => 'Items Per Page', 'default' => 10],
                'searchable' => ['type' => 'boolean', 'label' => 'Enable Search', 'default' => true],
                'empty_message' => ['type' => 'string', 'label' => 'Empty Message', 'default' => 'No records found'],
            ],
            'default_config' => [
                'title' => 'Data Table',
                'columns' => [
                    ['field' => 'id', 'label' => 'ID', 'sortable' => true],
                    ['field' => 'name', 'label' => 'Name', 'sortable' => true],
                    ['field' => 'status', 'label' => 'Status', 'render' => 'badge', 'badge_map' => ['active' => 'success', 'pending' => 'warning']],
                ],
                'row_click' => [],
                'pagination' => 10,
                'searchable' => true,
                'empty_message' => 'No records found',
            ],
        ];
    }

    private static function formBlock(): array
    {
        return [
            'type' => 'form',
            'label' => 'Form',
            'description' => 'Input form with validation and pipeline submission',
            'icon' => 'bi-ui-checks',
            'category' => 'input',
            'supports_data_binding' => false,
            'config_schema' => [
                'title' => ['type' => 'string', 'label' => 'Title'],
                'fields' => ['type' => 'json', 'label' => 'Form Fields', 'description' => 'Array of {name, label, type, required, placeholder, icon}'],
                'submit_label' => ['type' => 'string', 'label' => 'Submit Button Label', 'default' => 'Submit'],
                'submit_icon' => ['type' => 'string', 'label' => 'Submit Button Icon', 'default' => 'bi-check-lg'],
                'layout' => ['type' => 'enum', 'label' => 'Layout', 'options' => ['vertical', 'horizontal', 'inline']],
            ],
            'default_config' => [
                'title' => '',
                'fields' => [
                    ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'placeholder' => 'you@example.com', 'icon' => 'bi-envelope'],
                ],
                'submit_label' => 'Submit',
                'submit_icon' => 'bi-check-lg',
                'layout' => 'vertical',
            ],
        ];
    }

    private static function chatWidgetBlock(): array
    {
        return [
            'type' => 'chat_widget',
            'label' => 'Chat Widget',
            'description' => 'AI-powered chat interface with message history',
            'icon' => 'bi-chat-dots',
            'category' => 'interactive',
            'supports_data_binding' => true,
            'config_schema' => [
                'title' => ['type' => 'string', 'label' => 'Title', 'default' => 'Support Chat'],
                'welcome_message' => ['type' => 'string', 'label' => 'Welcome Message'],
                'display' => ['type' => 'enum', 'label' => 'Display', 'options' => ['inline', 'fullscreen']],
                'quick_actions' => ['type' => 'json', 'label' => 'Quick Actions', 'description' => 'Array of {label, message}'],
                'agent_id' => ['type' => 'integer', 'label' => 'AI Agent ID'],
            ],
            'default_config' => [
                'title' => 'Support Chat',
                'welcome_message' => 'How can I help you today?',
                'display' => 'inline',
                'quick_actions' => [],
                'agent_id' => null,
            ],
        ];
    }

    private static function alertBlock(): array
    {
        return [
            'type' => 'alert',
            'label' => 'Alert',
            'description' => 'Bootstrap alert with icon and optional dismiss',
            'icon' => 'bi-exclamation-triangle',
            'category' => 'interactive',
            'supports_data_binding' => false,
            'config_schema' => [
                'variant' => ['type' => 'enum', 'label' => 'Variant', 'options' => ['primary', 'secondary', 'success', 'danger', 'warning', 'info']],
                'icon' => ['type' => 'string', 'label' => 'Icon'],
                'content' => ['type' => 'text', 'label' => 'Content', 'required' => true],
                'dismissible' => ['type' => 'boolean', 'label' => 'Dismissible', 'default' => true],
            ],
            'default_config' => [
                'variant' => 'info',
                'icon' => 'bi-info-circle',
                'content' => 'This is an informational alert.',
                'dismissible' => true,
            ],
        ];
    }

    private static function buttonGroupBlock(): array
    {
        return [
            'type' => 'button_group',
            'label' => 'Button Group',
            'description' => 'Row or column of action buttons',
            'icon' => 'bi-collection',
            'category' => 'input',
            'supports_data_binding' => false,
            'config_schema' => [
                'layout' => ['type' => 'enum', 'label' => 'Layout', 'options' => ['horizontal', 'vertical']],
                'buttons' => ['type' => 'json', 'label' => 'Buttons', 'description' => 'Array of {label, icon, variant, event, pipeline_slug, target}'],
            ],
            'default_config' => [
                'layout' => 'horizontal',
                'buttons' => [
                    ['label' => 'Action 1', 'icon' => 'bi-play', 'variant' => 'primary', 'event' => 'run_pipeline', 'pipeline_slug' => '', 'target' => ''],
                    ['label' => 'Action 2', 'icon' => 'bi-arrow-right', 'variant' => 'outline-secondary', 'event' => 'navigate', 'target' => '/'],
                ],
            ],
        ];
    }

    private static function listGroupBlock(): array
    {
        return [
            'type' => 'list_group',
            'label' => 'List Group',
            'description' => 'List of items with badges and click actions',
            'icon' => 'bi-list-ul',
            'category' => 'data',
            'supports_data_binding' => true,
            'config_schema' => [
                'title' => ['type' => 'string', 'label' => 'Title'],
                'item_template' => ['type' => 'string', 'label' => 'Item Template', 'description' => 'Template string with {field} placeholders'],
                'badge_field' => ['type' => 'string', 'label' => 'Badge Field'],
                'badge_map' => ['type' => 'json', 'label' => 'Badge Color Map', 'description' => 'Map of value => Bootstrap color'],
                'click_action' => ['type' => 'json', 'label' => 'Click Action', 'description' => '{event, target}'],
                'max_items' => ['type' => 'integer', 'label' => 'Max Items', 'default' => 10],
            ],
            'default_config' => [
                'title' => 'List',
                'item_template' => '{name}',
                'badge_field' => '',
                'badge_map' => [],
                'click_action' => [],
                'max_items' => 10,
            ],
        ];
    }

    private static function markdownBlock(): array
    {
        return [
            'type' => 'markdown',
            'label' => 'Markdown',
            'description' => 'Rich text content rendered from markdown',
            'icon' => 'bi-markdown',
            'category' => 'layout',
            'supports_data_binding' => false,
            'config_schema' => [
                'content' => ['type' => 'text', 'label' => 'Markdown Content', 'required' => true],
            ],
            'default_config' => [
                'content' => "## Welcome\n\nAdd your content here.",
            ],
        ];
    }

    private static function rowBlock(): array
    {
        return [
            'type' => 'row',
            'label' => 'Row / Columns',
            'description' => 'Side-by-side columns with Bootstrap grid',
            'icon' => 'bi-columns-gap',
            'category' => 'layout',
            'supports_data_binding' => false,
            'config_schema' => [
                'columns' => ['type' => 'json', 'label' => 'Columns', 'description' => 'Array of {col_class, blocks[]}'],
            ],
            'default_config' => [
                'columns' => [
                    ['col_class' => 'col-md-6', 'blocks' => []],
                    ['col_class' => 'col-md-6', 'blocks' => []],
                ],
            ],
        ];
    }

    private static function tabsBlock(): array
    {
        return [
            'type' => 'tabs',
            'label' => 'Tabs',
            'description' => 'Tabbed content with nested blocks in each tab',
            'icon' => 'bi-segmented-nav',
            'category' => 'layout',
            'supports_data_binding' => false,
            'config_schema' => [
                'tabs' => ['type' => 'json', 'label' => 'Tabs', 'description' => 'Array of {label, icon, blocks[]}'],
            ],
            'default_config' => [
                'tabs' => [
                    ['label' => 'Tab 1', 'icon' => '', 'blocks' => []],
                    ['label' => 'Tab 2', 'icon' => '', 'blocks' => []],
                ],
            ],
        ];
    }

    private static function chartBlock(): array
    {
        return [
            'type' => 'chart',
            'label' => 'Chart',
            'description' => 'Chart.js visualization (bar, line, pie, doughnut)',
            'icon' => 'bi-bar-chart-line',
            'category' => 'data',
            'supports_data_binding' => true,
            'config_schema' => [
                'chart_type' => ['type' => 'enum', 'label' => 'Chart Type', 'options' => ['bar', 'line', 'pie', 'doughnut']],
                'title' => ['type' => 'string', 'label' => 'Title'],
                'height' => ['type' => 'integer', 'label' => 'Height (px)', 'default' => 300],
                'labels_field' => ['type' => 'string', 'label' => 'Labels Field', 'description' => 'Data field for X-axis labels'],
                'datasets' => ['type' => 'json', 'label' => 'Datasets', 'description' => 'Array of {label, data_field, color}'],
            ],
            'default_config' => [
                'chart_type' => 'bar',
                'title' => 'Chart',
                'height' => 300,
                'labels_field' => 'label',
                'datasets' => [
                    ['label' => 'Dataset 1', 'data_field' => 'value', 'color' => '#0d6efd'],
                ],
            ],
        ];
    }

    /**
     * Get available event types for a block type
     */
    public static function getEvents(string $type): array
    {
        $events = [
            'form' => ['onSubmit'],
            'button_group' => ['onClick'],
            'data_table' => ['onClick', 'onLoad', 'onRefresh'],
            'kpi_row' => ['onLoad', 'onRefresh'],
            'list_group' => ['onClick', 'onLoad'],
            'chat_widget' => ['onMessage', 'onLoad'],
            'chart' => ['onLoad', 'onRefresh'],
            'card' => ['onLoad'],
        ];

        return $events[$type] ?? [];
    }

    /**
     * Get available action types
     */
    public static function getActions(): array
    {
        return [
            'run_pipeline' => [
                'label' => 'Run Pipeline',
                'description' => 'Execute a pipeline with input data',
                'params' => ['pipeline_slug', 'input_map', 'on_success', 'on_error'],
            ],
            'navigate' => [
                'label' => 'Navigate',
                'description' => 'Go to another screen or URL',
                'params' => ['target'],
            ],
            'show_alert' => [
                'label' => 'Show Alert',
                'description' => 'Display an alert message',
                'params' => ['message', 'variant'],
            ],
            'refresh_block' => [
                'label' => 'Refresh Block',
                'description' => 'Re-fetch data for a block',
                'params' => ['block_id'],
            ],
            'set_state' => [
                'label' => 'Set State',
                'description' => 'Set a client-side state variable',
                'params' => ['key', 'value'],
            ],
        ];
    }
}
