<?php
/**
 * View Generator
 *
 * Generates Bootstrap 5 view templates:
 * - index.php (list view with table)
 * - edit.php (create/edit form with relationship sections)
 */

namespace app\Scaffold\Generators;

use app\Scaffold\Context;

class ViewGenerator implements GeneratorInterface {

    public function generate(Context $ctx): bool {
        $viewDir = $ctx->baseDir . '/views/' . $ctx->beanName;

        // Ensure directory exists
        if (!$ctx->dryRun && !is_dir($viewDir)) {
            mkdir($viewDir, 0755, true);
        }

        $success = true;

        // Generate index view
        $indexContent = $ctx->render('view/index.php');
        $success = $ctx->writeFile("{$viewDir}/index.php", $indexContent) && $success;

        // Generate edit view
        $editContent = $ctx->render('view/edit.php');
        $success = $ctx->writeFile("{$viewDir}/edit.php", $editContent) && $success;

        return $success;
    }

    public function getOutputPaths(Context $ctx): array {
        $viewDir = $ctx->baseDir . '/views/' . $ctx->beanName;
        return [
            "{$viewDir}/index.php",
            "{$viewDir}/edit.php"
        ];
    }
}
