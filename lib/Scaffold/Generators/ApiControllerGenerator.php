<?php
/**
 * API Controller Generator
 *
 * Generates stateless JSON API controllers with:
 * - RESTful endpoints (GET, POST, PUT, DELETE)
 * - Bearer token authentication
 * - JSON request/response handling
 */

namespace app\Scaffold\Generators;

use app\Scaffold\Context;

class ApiControllerGenerator implements GeneratorInterface {

    public function generate(Context $ctx): bool {
        $content = $ctx->render('controller/api.php');
        $path = $this->getOutputPaths($ctx)[0];

        return $ctx->writeFile($path, $content);
    }

    public function getOutputPaths(Context $ctx): array {
        return [
            $ctx->baseDir . '/controls/' . $ctx->className . 'api.php'
        ];
    }
}
