<?php
/**
 * CRUD Controller Generator
 *
 * Generates session-based FlightPHP controllers with:
 * - index (list)
 * - create (form)
 * - store (save new)
 * - edit (form + update)
 * - delete
 * - toggle (AJAX)
 */

namespace app\Scaffold\Generators;

use app\Scaffold\Context;

class CrudControllerGenerator implements GeneratorInterface {

    public function generate(Context $ctx): bool {
        $content = $ctx->render('controller/crud.php');
        $path = $this->getOutputPaths($ctx)[0];

        return $ctx->writeFile($path, $content);
    }

    public function getOutputPaths(Context $ctx): array {
        return [
            $ctx->baseDir . '/controls/' . $ctx->className . '.php'
        ];
    }
}
