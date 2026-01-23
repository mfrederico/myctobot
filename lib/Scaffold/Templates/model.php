<?php
/**
 * Model Template
 *
 * Variables available:
 * - $ctx: Context object with all scaffold data
 */

// Build field documentation
$fieldDocs = [];
foreach ($ctx->fields as $field) {
    $phpType = $ctx->phpType($field['type']);
    $fieldDocs[] = " * @property {$phpType} \${$field['name']}";
}

// Build relationship documentation
$relDocs = [];
foreach ($ctx->relationships as $rel) {
    if ($rel['type'] === 'belongs-to') {
        $relDocs[] = " * @property \\RedBeanPHP\\OODBBean \${$rel['bean']} Parent {$rel['bean']}";
    } else {
        $relDocs[] = " * @property \\RedBeanPHP\\OODBBean[] \${$rel['property']} Related {$rel['bean']} beans";
    }
}

// Build dispense defaults
$dispenseDefaults = [];
foreach ($ctx->fields as $field) {
    foreach ($field['options'] as $opt) {
        if (strpos($opt, 'default=') === 0) {
            $value = substr($opt, 8);
            $dispenseDefaults[] = "        \$this->bean->{$field['name']} = {$value};";
        }
    }
}

// Build validation code
$validationCode = [];
foreach ($ctx->fields as $field) {
    if (in_array('required', $field['options'])) {
        $validationCode[] = <<<PHP
        if (empty(\$this->bean->{$field['name']})) {
            throw new \\Exception('{$field['name']} is required');
        }
PHP;
    }
}

// Build custom methods
$methodsCode = '';
foreach ($ctx->methods as $method) {
    $params = isset($method['params']) ? implode(', ', $method['params']) : '';
    $returnType = $method['return'] !== 'mixed' ? ": {$method['return']}" : '';
    $methodsCode .= <<<PHP

    /**
     * {$method['name']}
     *
     * @return {$method['return']}
     */
    public function {$method['name']}({$params}){$returnType} {
        {$method['body']}
    }
PHP;
}

// Output the template
echo "<?php\n";
?>
/**
 * Model_<?=$ctx->className?>

 * FUSE model for <?=$ctx->beanName?> table
 *
<?=implode("\n", $fieldDocs)?>

<?php if (!empty($relDocs)): ?>
<?=implode("\n", $relDocs)?>

<?php endif; ?>
 */

use \RedBeanPHP\R as R;

class Model_<?=$ctx->className?> extends \RedBeanPHP\SimpleModel {

    /**
     * Called when bean is dispensed (created new)
     */
    public function dispense(): void {
        $this->bean->created_at = date('Y-m-d H:i:s');
<?php if (!empty($dispenseDefaults)): ?>
<?=implode("\n", $dispenseDefaults)?>

<?php endif; ?>
    }

    /**
     * Called before storing bean
     */
    public function update(): void {
        $this->bean->updated_at = date('Y-m-d H:i:s');
<?php if (!empty($validationCode)): ?>

<?=implode("\n\n", $validationCode)?>

<?php endif; ?>
    }
<?=$methodsCode?>

    /**
     * Convert bean to array for API responses
     *
     * @return array
     */
    public function toArray(): array {
        return $this->bean->export();
    }
}
