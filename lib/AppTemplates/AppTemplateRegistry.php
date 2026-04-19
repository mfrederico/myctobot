<?php
/**
 * AppTemplateRegistry
 *
 * Static registry for app templates. Auto-discovers template classes
 * from the Templates/ subdirectory.
 */

namespace app\lib\AppTemplates;

class AppTemplateRegistry
{
    /** @var AppTemplateInterface[] */
    private static array $templates = [];
    private static bool $discovered = false;

    /**
     * Register a template instance
     */
    public static function register(AppTemplateInterface $template): void
    {
        self::$templates[$template->getSlug()] = $template;
    }

    /**
     * Get a template by slug
     */
    public static function get(string $slug): ?AppTemplateInterface
    {
        self::discover();
        return self::$templates[$slug] ?? null;
    }

    /**
     * Get all registered templates
     *
     * @return AppTemplateInterface[]
     */
    public static function getAll(): array
    {
        self::discover();
        return self::$templates;
    }

    /**
     * Auto-discover template classes from Templates/ directory
     */
    private static function discover(): void
    {
        if (self::$discovered) return;
        self::$discovered = true;

        $dir = __DIR__ . '/Templates';
        if (!is_dir($dir)) return;

        foreach (glob($dir . '/*.php') as $file) {
            require_once $file;

            $className = 'app\\lib\\AppTemplates\\Templates\\' . basename($file, '.php');
            if (class_exists($className)) {
                $instance = new $className();
                if ($instance instanceof AppTemplateInterface) {
                    self::register($instance);
                }
            }
        }
    }
}
