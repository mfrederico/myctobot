<?php
/**
 * Model Generator
 *
 * Generates RedBeanPHP FUSE model classes with:
 * - Field documentation
 * - FUSE hooks (dispense, update)
 * - Custom methods
 * - Validation
 */

namespace app\Scaffold\Generators;

use app\Scaffold\Context;

class ModelGenerator implements GeneratorInterface {

    public function generate(Context $ctx): bool {
        $content = $ctx->render('model.php');
        $path = $this->getOutputPaths($ctx)[0];

        return $ctx->writeFile($path, $content);
    }

    public function getOutputPaths(Context $ctx): array {
        return [
            $ctx->baseDir . '/models/Model_' . $ctx->className . '.php'
        ];
    }
}
