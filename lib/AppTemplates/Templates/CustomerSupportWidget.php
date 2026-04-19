<?php
/**
 * Customer Support Widget Template
 *
 * Creates a detachable app pre-configured as a customer support widget
 * for a Shopify store, using the existing /widget/js endpoint.
 */

namespace app\lib\AppTemplates\Templates;

use app\lib\AppTemplates\AppTemplateInterface;
use app\Bean;
use app\services\ShopifyClient;

class CustomerSupportWidget implements AppTemplateInterface
{
    public function getSlug(): string
    {
        return 'customer-support-widget';
    }

    public function getName(): string
    {
        return 'Customer Support Widget';
    }

    public function getDescription(): string
    {
        return 'AI-powered customer support chat widget for your Shopify store. Connects to a support pipeline for automated responses.';
    }

    public function getIcon(): string
    {
        return 'bi-headset';
    }

    public function getConfigSchema(): array
    {
        return [
            [
                'name' => 'shopify_connection_id',
                'label' => 'Shopify Store',
                'type' => 'select',
                'required' => true,
                'help' => 'Select which Shopify store this widget is for',
                'options_source' => 'shopify_connections',
            ],
            [
                'name' => 'pipeline_id',
                'label' => 'Support Pipeline',
                'type' => 'select',
                'required' => true,
                'help' => 'Pipeline that handles customer support conversations',
                'options_source' => 'pipelines',
            ],
            [
                'name' => 'greeting_text',
                'label' => 'Greeting Message',
                'type' => 'text',
                'required' => false,
                'default' => 'Hi! How can we help you today?',
                'help' => 'Initial message shown when the widget opens',
            ],
            [
                'name' => 'widget_position',
                'label' => 'Widget Position',
                'type' => 'select',
                'required' => false,
                'default' => 'bottom-right',
                'help' => 'Where the chat button appears on the page',
                'options' => [
                    ['value' => 'bottom-right', 'label' => 'Bottom Right'],
                    ['value' => 'bottom-left', 'label' => 'Bottom Left'],
                ],
            ],
        ];
    }

    public function getScreens(array $config): array
    {
        $greeting = htmlspecialchars($config['greeting_text'] ?? 'Hi! How can we help you today?');
        $position = htmlspecialchars($config['widget_position'] ?? 'bottom-right');

        return [
            [
                'title' => 'Widget Status',
                'route_path' => '/',
                'is_default' => true,
                'html' => <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Support Widget - Status</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-headset display-1 text-primary"></i>
                        <h2 class="mt-3">Customer Support Widget</h2>
                        <p class="text-muted mb-4">
                            This app provides an AI-powered customer support chat widget
                            for your Shopify store.
                        </p>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-1"></i>
                            <strong>Greeting:</strong> {$greeting}<br>
                            <strong>Position:</strong> {$position}
                        </div>
                        <p class="text-muted small">
                            The widget is served via the <code>/widget/js</code> endpoint
                            and embedded on your Shopify store as a script tag.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
HTML
            ],
        ];
    }

    public function getRequiredPipelines(): array
    {
        return []; // Pipeline is selected by user, not a fixed slug
    }

    public function validateConfig(array $config): array
    {
        $errors = [];

        if (empty($config['shopify_connection_id'])) {
            $errors[] = 'Shopify store is required';
        }
        if (empty($config['pipeline_id'])) {
            $errors[] = 'Support pipeline is required';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    public function afterCreate(int $appId, array $config): void
    {
        $connectionId = (int)($config['shopify_connection_id'] ?? 0);
        $pipelineId = (int)($config['pipeline_id'] ?? 0);

        if ($connectionId <= 0) return;

        // Ensure the connection has a widget token
        $conn = ShopifyClient::getConnection($connectionId);
        if (!$conn) return;

        $metadata = json_decode($conn->metadata_json ?: '{}', true);

        // Generate widget token if missing
        if (empty($metadata['public_agent_token'])) {
            $metadata['public_agent_token'] = 'wgt_' . bin2hex(random_bytes(16));
        }

        // Link the pipeline
        if ($pipelineId > 0) {
            $metadata['pipelines_id'] = $pipelineId;
        }

        // Store config in metadata
        $metadata['widget_greeting'] = $config['greeting_text'] ?? 'Hi! How can we help you today?';
        $metadata['widget_position'] = $config['widget_position'] ?? 'bottom-right';

        $conn->metadata_json = json_encode($metadata);
        $conn->updated_at = date('Y-m-d H:i:s');
        Bean::store($conn);

        // Bind the selected pipeline to the detachable app
        if ($pipelineId > 0) {
            $existing = Bean::findOne('detachableapppipelines',
                ' detachableapps_id = ? AND pipelines_id = ? ',
                [$appId, $pipelineId]
            );
            if (!$existing) {
                $binding = Bean::dispense('detachableapppipelines');
                $binding->detachableapps_id = $appId;
                $binding->pipelines_id = $pipelineId;
                $binding->alias = 'support';
                $binding->is_active = 1;
                Bean::store($binding);
            }
        }
    }
}
