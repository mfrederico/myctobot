<?php
/**
 * AppTemplateInterface
 *
 * Defines the contract for detachable app templates.
 * Templates provide pre-built app blueprints that can be instantiated
 * without requiring a Stitch connection.
 */

namespace app\lib\AppTemplates;

interface AppTemplateInterface
{
    /**
     * Unique slug identifying this template
     */
    public function getSlug(): string;

    /**
     * Human-readable template name
     */
    public function getName(): string;

    /**
     * Short description of what this template creates
     */
    public function getDescription(): string;

    /**
     * Bootstrap icon class (e.g., 'bi-headset')
     */
    public function getIcon(): string;

    /**
     * Returns field definitions for the template config form.
     *
     * Each field: [
     *   'name'           => string,
     *   'label'          => string,
     *   'type'           => 'text'|'select'|'boolean'|'textarea',
     *   'required'       => bool,
     *   'default'        => mixed,
     *   'help'           => string,
     *   'options'        => array of ['value' => ..., 'label' => ...],  // for select
     *   'options_source' => string  // dynamic: 'shopify_connections', 'pipelines'
     * ]
     *
     * @return array
     */
    public function getConfigSchema(): array;

    /**
     * Returns pre-built screen definitions for the app.
     *
     * Each screen: [
     *   'title'      => string,
     *   'route_path' => string,
     *   'is_default' => bool,
     *   'html'       => string   // pre-built HTML content
     * ]
     *
     * @param array $config User-provided config values
     * @return array
     */
    public function getScreens(array $config): array;

    /**
     * Pipeline slugs that this template needs bound to the app.
     *
     * @return array of pipeline slug strings
     */
    public function getRequiredPipelines(): array;

    /**
     * Validate user-provided config values.
     *
     * @param array $config
     * @return array ['valid' => bool, 'errors' => string[]]
     */
    public function validateConfig(array $config): array;

    /**
     * Post-creation hook. Called after the app and screens are created.
     * Use for auto-configuring tokens, binding pipelines, etc.
     *
     * @param int $appId The created detachable app ID
     * @param array $config User-provided config values
     */
    public function afterCreate(int $appId, array $config): void;
}
