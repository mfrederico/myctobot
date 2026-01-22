<?php
/**
 * Seed sample Studio templates
 *
 * These templates demonstrate the orchestration capabilities
 * and give users starting points for common use cases.
 */

$templates = [
    // Flash Sale Orchestrator - The flagship template
    [
        'name' => 'Flash Sale Orchestrator',
        'slug' => 'flash-sale',
        'category' => 'ecommerce',
        'description' => 'Run a time-limited flash sale: discount products, update theme, announce on social, then automatically revert everything.',
        'icon' => 'bi-lightning-charge-fill',
        'color' => 'warning',
        'required_connections' => ['shopify'],
        'estimated_duration_minutes' => 10,
        'wizard_config' => [
            'steps' => [
                [
                    'id' => 'store',
                    'title' => 'Select Store',
                    'type' => 'connection_picker',
                    'variable' => 'shopify_store',
                    'connection_type' => 'shopify',
                    'multiple' => false
                ],
                [
                    'id' => 'products',
                    'title' => 'Which Products?',
                    'type' => 'ai_assisted_text',
                    'variable' => 'product_criteria',
                    'placeholder' => 'Describe which products to include in the sale...',
                    'examples' => [
                        'Products with less than 5 sales in the last 30 days',
                        'All items in the Summer Collection',
                        'Products tagged "clearance"'
                    ]
                ],
                [
                    'id' => 'sale_config',
                    'title' => 'Sale Settings',
                    'type' => 'form',
                    'help_text' => 'Configure your flash sale parameters.',
                    'fields' => [
                        [
                            'name' => 'discount_percent',
                            'label' => 'Discount Percentage',
                            'type' => 'slider',
                            'min' => 5,
                            'max' => 50,
                            'default' => 25,
                            'required' => true
                        ],
                        [
                            'name' => 'duration_hours',
                            'label' => 'Sale Duration (hours)',
                            'type' => 'slider',
                            'min' => 1,
                            'max' => 72,
                            'default' => 4,
                            'required' => true
                        ],
                        [
                            'name' => 'update_theme',
                            'label' => 'Update Theme Banner',
                            'type' => 'select',
                            'options' => ['Yes', 'No'],
                            'default' => 'Yes'
                        ]
                    ]
                ],
                [
                    'id' => 'messaging',
                    'title' => 'Sale Messaging',
                    'type' => 'ai_assisted_text',
                    'variable' => 'sale_message',
                    'placeholder' => 'Describe the tone and key points for sale messaging...',
                    'examples' => [
                        'Urgent, FOMO-inducing, emphasize limited time',
                        'Friendly and helpful, focus on value',
                        'Professional, highlight quality products'
                    ]
                ],
                [
                    'id' => 'summary',
                    'title' => 'Review & Launch',
                    'type' => 'summary'
                ]
            ]
        ],
        'pipeline_template' => [
            'steps' => [
                ['row' => 0, 'type' => 'mcp_call', 'name' => 'Query Products', 'config' => ['server' => 'shopify', 'tool' => 'search_products', 'params' => ['criteria' => '{{product_criteria}}']]],
                ['row' => 0, 'type' => 'parser', 'name' => 'Store Original State', 'config' => ['capture' => 'revert_data']],
                ['row' => 1, 'type' => 'ai_agent', 'name' => 'Generate Sale Copy', 'config' => ['prompt' => 'Generate sale messaging based on: {{sale_message}}']],
                ['row' => 2, 'type' => 'mcp_call', 'name' => 'Create Discounts', 'config' => ['server' => 'shopify', 'tool' => 'create_discount', 'params' => ['percent' => '{{discount_percent}}']]],
                ['row' => 2, 'type' => 'mcp_call', 'name' => 'Update Theme Banner', 'config' => ['server' => 'shopify', 'tool' => 'update_theme_section', 'conditional' => '{{update_theme}} == "Yes"']],
                ['row' => 3, 'type' => 'harvest', 'name' => 'Collect Changes', 'config' => []],
                ['row' => 4, 'type' => 'schedule_task', 'name' => 'Schedule Revert', 'config' => ['task_type' => 'revert_action', 'delay_expression' => '{{duration_hours}} * 3600']],
                ['row' => 5, 'type' => 'direct_exec', 'name' => 'Log Completion', 'config' => []]
            ]
        ],
        'variables_schema' => [
            'type' => 'object',
            'properties' => [
                'shopify_store' => ['type' => 'string'],
                'product_criteria' => ['type' => 'string'],
                'discount_percent' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 50],
                'duration_hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 72],
                'update_theme' => ['type' => 'string', 'enum' => ['Yes', 'No']],
                'sale_message' => ['type' => 'string']
            ],
            'required' => ['shopify_store', 'product_criteria', 'discount_percent', 'duration_hours']
        ]
    ],

    // Code Review Orchestrator
    [
        'name' => 'Automated Code Review',
        'slug' => 'code-review',
        'category' => 'devops',
        'description' => 'Run AI-powered code review on pull requests: analyze changes, check for issues, post review comments.',
        'icon' => 'bi-code-slash',
        'color' => 'info',
        'required_connections' => ['github'],
        'estimated_duration_minutes' => 5,
        'wizard_config' => [
            'steps' => [
                [
                    'id' => 'repo',
                    'title' => 'Select Repository',
                    'type' => 'connection_picker',
                    'variable' => 'github_repo',
                    'connection_type' => 'github',
                    'multiple' => false
                ],
                [
                    'id' => 'review_config',
                    'title' => 'Review Settings',
                    'type' => 'form',
                    'fields' => [
                        [
                            'name' => 'review_depth',
                            'label' => 'Review Depth',
                            'type' => 'select',
                            'options' => ['Quick scan', 'Standard', 'Thorough'],
                            'default' => 'Standard'
                        ],
                        [
                            'name' => 'auto_approve',
                            'label' => 'Auto-approve if no issues',
                            'type' => 'select',
                            'options' => ['Yes', 'No'],
                            'default' => 'No'
                        ]
                    ]
                ],
                [
                    'id' => 'focus',
                    'title' => 'Review Focus',
                    'type' => 'ai_assisted_text',
                    'variable' => 'review_focus',
                    'placeholder' => 'What should the review focus on?',
                    'examples' => [
                        'Security vulnerabilities and SQL injection',
                        'Performance issues and memory leaks',
                        'Code style and best practices'
                    ]
                ],
                [
                    'id' => 'summary',
                    'title' => 'Review & Activate',
                    'type' => 'summary'
                ]
            ]
        ],
        'pipeline_template' => [
            'steps' => [
                ['row' => 0, 'type' => 'webhook_trigger', 'name' => 'PR Opened', 'config' => ['event' => 'pull_request.opened']],
                ['row' => 1, 'type' => 'mcp_call', 'name' => 'Fetch PR Details', 'config' => ['server' => 'github', 'tool' => 'get_pull_request']],
                ['row' => 2, 'type' => 'ai_agent', 'name' => 'Analyze Changes', 'config' => ['focus' => '{{review_focus}}', 'depth' => '{{review_depth}}']],
                ['row' => 3, 'type' => 'mcp_call', 'name' => 'Post Review', 'config' => ['server' => 'github', 'tool' => 'create_review']]
            ]
        ],
        'variables_schema' => [
            'type' => 'object',
            'properties' => [
                'github_repo' => ['type' => 'string'],
                'review_depth' => ['type' => 'string'],
                'auto_approve' => ['type' => 'string'],
                'review_focus' => ['type' => 'string']
            ]
        ]
    ],

    // PHP Modernization
    [
        'name' => 'PHP Modernization',
        'slug' => 'php-modernization',
        'category' => 'devops',
        'description' => 'Automatically modernize PHP codebases: update deprecated syntax, add type hints, improve code quality.',
        'icon' => 'bi-arrow-up-circle',
        'color' => 'primary',
        'required_connections' => ['github'],
        'estimated_duration_minutes' => 15,
        'wizard_config' => [
            'steps' => [
                [
                    'id' => 'repo',
                    'title' => 'Select Repository',
                    'type' => 'connection_picker',
                    'variable' => 'github_repo',
                    'connection_type' => 'github'
                ],
                [
                    'id' => 'modernization_config',
                    'title' => 'Modernization Options',
                    'type' => 'form',
                    'fields' => [
                        [
                            'name' => 'target_php_version',
                            'label' => 'Target PHP Version',
                            'type' => 'select',
                            'options' => ['8.0', '8.1', '8.2', '8.3'],
                            'default' => '8.2'
                        ],
                        [
                            'name' => 'add_type_hints',
                            'label' => 'Add Type Hints',
                            'type' => 'select',
                            'options' => ['Yes', 'No'],
                            'default' => 'Yes'
                        ],
                        [
                            'name' => 'create_pr',
                            'label' => 'Create Pull Request',
                            'type' => 'select',
                            'options' => ['Yes', 'No'],
                            'default' => 'Yes'
                        ]
                    ]
                ],
                [
                    'id' => 'summary',
                    'title' => 'Review & Start',
                    'type' => 'summary'
                ]
            ]
        ],
        'pipeline_template' => [
            'steps' => [
                ['row' => 0, 'type' => 'mcp_call', 'name' => 'Clone Repository', 'config' => ['server' => 'github', 'tool' => 'clone_repo']],
                ['row' => 1, 'type' => 'ai_agent', 'name' => 'Analyze PHP Files', 'config' => ['task' => 'identify_modernization_opportunities']],
                ['row' => 2, 'type' => 'ai_agent', 'name' => 'Apply Modernizations', 'config' => ['target_version' => '{{target_php_version}}']],
                ['row' => 3, 'type' => 'mcp_call', 'name' => 'Create PR', 'config' => ['server' => 'github', 'tool' => 'create_pull_request']]
            ]
        ],
        'variables_schema' => [
            'type' => 'object',
            'properties' => [
                'github_repo' => ['type' => 'string'],
                'target_php_version' => ['type' => 'string'],
                'add_type_hints' => ['type' => 'string'],
                'create_pr' => ['type' => 'string']
            ]
        ]
    ],

    // Shopify Theme Updates
    [
        'name' => 'Theme Customization',
        'slug' => 'shopify-themes',
        'category' => 'ecommerce',
        'description' => 'AI-powered theme updates: describe changes in natural language, preview, then deploy to your store.',
        'icon' => 'bi-palette',
        'color' => 'success',
        'required_connections' => ['shopify'],
        'estimated_duration_minutes' => 8,
        'wizard_config' => [
            'steps' => [
                [
                    'id' => 'store',
                    'title' => 'Select Store',
                    'type' => 'connection_picker',
                    'variable' => 'shopify_store',
                    'connection_type' => 'shopify'
                ],
                [
                    'id' => 'changes',
                    'title' => 'Describe Changes',
                    'type' => 'ai_assisted_text',
                    'variable' => 'theme_changes',
                    'placeholder' => 'Describe the theme changes you want...',
                    'examples' => [
                        'Add a countdown timer to the homepage hero section',
                        'Change the product page layout to show larger images',
                        'Add a loyalty points display to product cards'
                    ]
                ],
                [
                    'id' => 'deploy_config',
                    'title' => 'Deployment Options',
                    'type' => 'form',
                    'fields' => [
                        [
                            'name' => 'preview_first',
                            'label' => 'Preview Before Deploy',
                            'type' => 'select',
                            'options' => ['Yes', 'No'],
                            'default' => 'Yes'
                        ],
                        [
                            'name' => 'backup_theme',
                            'label' => 'Backup Current Theme',
                            'type' => 'select',
                            'options' => ['Yes', 'No'],
                            'default' => 'Yes'
                        ]
                    ]
                ],
                [
                    'id' => 'summary',
                    'title' => 'Review & Deploy',
                    'type' => 'summary'
                ]
            ]
        ],
        'pipeline_template' => [
            'steps' => [
                ['row' => 0, 'type' => 'mcp_call', 'name' => 'Fetch Theme', 'config' => ['server' => 'shopify', 'tool' => 'get_theme']],
                ['row' => 1, 'type' => 'ai_agent', 'name' => 'Generate Changes', 'config' => ['changes' => '{{theme_changes}}']],
                ['row' => 2, 'type' => 'mcp_call', 'name' => 'Backup Theme', 'config' => ['server' => 'shopify', 'tool' => 'backup_theme', 'conditional' => '{{backup_theme}} == "Yes"']],
                ['row' => 3, 'type' => 'mcp_call', 'name' => 'Deploy Changes', 'config' => ['server' => 'shopify', 'tool' => 'update_theme']]
            ]
        ],
        'variables_schema' => [
            'type' => 'object',
            'properties' => [
                'shopify_store' => ['type' => 'string'],
                'theme_changes' => ['type' => 'string'],
                'preview_first' => ['type' => 'string'],
                'backup_theme' => ['type' => 'string']
            ]
        ]
    ],

    // Jira Sprint Planning
    [
        'name' => 'Sprint Planning Assistant',
        'slug' => 'sprint-planning',
        'category' => 'project_management',
        'description' => 'AI-powered sprint planning: analyze backlog, suggest sprint scope, create sprint, and assign stories.',
        'icon' => 'bi-kanban',
        'color' => 'info',
        'required_connections' => ['jira'],
        'estimated_duration_minutes' => 10,
        'wizard_config' => [
            'steps' => [
                [
                    'id' => 'board',
                    'title' => 'Select Board',
                    'type' => 'connection_picker',
                    'variable' => 'jira_board',
                    'connection_type' => 'jira'
                ],
                [
                    'id' => 'sprint_config',
                    'title' => 'Sprint Configuration',
                    'type' => 'form',
                    'fields' => [
                        [
                            'name' => 'sprint_duration',
                            'label' => 'Sprint Duration (weeks)',
                            'type' => 'select',
                            'options' => ['1', '2', '3', '4'],
                            'default' => '2'
                        ],
                        [
                            'name' => 'team_capacity',
                            'label' => 'Team Capacity (story points)',
                            'type' => 'slider',
                            'min' => 10,
                            'max' => 100,
                            'default' => 40
                        ]
                    ]
                ],
                [
                    'id' => 'priorities',
                    'title' => 'Sprint Focus',
                    'type' => 'ai_assisted_text',
                    'variable' => 'sprint_focus',
                    'placeholder' => 'What should this sprint focus on?',
                    'examples' => [
                        'Bug fixes and stability improvements',
                        'New checkout flow features',
                        'Technical debt reduction'
                    ]
                ],
                [
                    'id' => 'summary',
                    'title' => 'Review & Create',
                    'type' => 'summary'
                ]
            ]
        ],
        'pipeline_template' => [
            'steps' => [
                ['row' => 0, 'type' => 'mcp_call', 'name' => 'Fetch Backlog', 'config' => ['server' => 'jira', 'tool' => 'get_backlog']],
                ['row' => 1, 'type' => 'ai_agent', 'name' => 'Analyze & Plan', 'config' => ['focus' => '{{sprint_focus}}', 'capacity' => '{{team_capacity}}']],
                ['row' => 2, 'type' => 'mcp_call', 'name' => 'Create Sprint', 'config' => ['server' => 'jira', 'tool' => 'create_sprint']],
                ['row' => 3, 'type' => 'mcp_call', 'name' => 'Move Issues', 'config' => ['server' => 'jira', 'tool' => 'move_issues_to_sprint']]
            ]
        ],
        'variables_schema' => [
            'type' => 'object',
            'properties' => [
                'jira_board' => ['type' => 'string'],
                'sprint_duration' => ['type' => 'string'],
                'team_capacity' => ['type' => 'integer'],
                'sprint_focus' => ['type' => 'string']
            ]
        ]
    ]
];

// Insert templates
foreach ($templates as $tpl) {
    $bean = \RedBeanPHP\R::dispense('studiotemplates');
    $bean->name = $tpl['name'];
    $bean->slug = $tpl['slug'];
    $bean->category = $tpl['category'];
    $bean->description = $tpl['description'];
    $bean->icon = $tpl['icon'];
    $bean->color = $tpl['color'];
    $bean->required_connections_json = json_encode($tpl['required_connections']);
    $bean->wizard_config_json = json_encode($tpl['wizard_config']);
    $bean->pipeline_template_json = json_encode($tpl['pipeline_template']);
    $bean->variables_schema_json = json_encode($tpl['variables_schema']);
    $bean->estimated_duration_minutes = $tpl['estimated_duration_minutes'];
    $bean->is_active = 1;
    $bean->created_at = date('Y-m-d H:i:s');
    $bean->updated_at = date('Y-m-d H:i:s');
    \RedBeanPHP\R::store($bean);
}
