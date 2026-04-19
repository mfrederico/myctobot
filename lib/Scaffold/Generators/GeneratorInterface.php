<?php
/**
 * Generator Interface
 *
 * Contract for all scaffold generators.
 */

namespace app\Scaffold\Generators;

use app\Scaffold\Context;

interface GeneratorInterface {

    /**
     * Generate files based on context
     *
     * @param Context $ctx Scaffold context with all definitions
     * @return bool Success status
     */
    public function generate(Context $ctx): bool;

    /**
     * Get the output file path(s) this generator will create
     *
     * @param Context $ctx Scaffold context
     * @return array List of file paths
     */
    public function getOutputPaths(Context $ctx): array;
}
