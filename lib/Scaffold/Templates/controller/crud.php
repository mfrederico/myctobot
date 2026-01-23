<?php
/**
 * CRUD Controller Template
 *
 * Variables available:
 * - $ctx: Context object with all scaffold data
 */

// Build field assignments for store/update
$fieldAssignments = [];
foreach ($ctx->getEditableFields() as $field) {
    $name = $field['name'];
    switch ($field['type']) {
        case 'bool':
            $fieldAssignments[] = "            \$bean->{$name} = \$this->getParam('{$name}') ? 1 : 0;";
            break;
        case 'int':
            $fieldAssignments[] = "            \$bean->{$name} = (int) \$this->getParam('{$name}');";
            break;
        case 'float':
            $fieldAssignments[] = "            \$bean->{$name} = (float) \$this->getParam('{$name}');";
            break;
        default:
            $fieldAssignments[] = "            \$bean->{$name} = trim(\$this->getParam('{$name}') ?? '');";
    }
}

// Build relationship loading for edit view
$relLoading = [];
foreach ($ctx->relationships as $rel) {
    if (in_array($rel['type'], ['has-many', 'many-to-many'])) {
        $varName = $rel['bean'] . 's';
        $relLoading[] = "            '{$varName}' => \$bean->{$rel['property']},";
    }
}

// Output the template
echo "<?php\n";
?>
/**
 * <?=$ctx->className?> Controller
 * CRUD operations for <?=$ctx->beanName?> beans
 */

namespace app;

use \Flight as Flight;
use \app\Bean;

class <?=$ctx->className?> extends BaseControls\Control {

    /**
     * List all <?=$ctx->beanName?> records
     */
    public function index() {
        if (!$this->requireLogin()) return;

        $items = Bean::findAll('<?=$ctx->beanName?>', ' ORDER BY created_at DESC ');

        $this->render('<?=$ctx->beanName?>/index', [
            'title' => '<?=$ctx->className?> List',
            'items' => $items
        ]);
    }

    /**
     * Show create form
     */
    public function create() {
        if (!$this->requireLogin()) return;

        $this->render('<?=$ctx->beanName?>/edit', [
            'title' => 'Create <?=$ctx->className?>',
            'bean' => null,
            'isNew' => true
        ]);
    }

    /**
     * Edit existing record
     */
    public function edit() {
        if (!$this->requireLogin()) return;

        $id = $this->opId();
        if (!$id) {
            $this->flash('error', 'No <?=$ctx->beanName?> specified');
            Flight::redirect('/<?=$ctx->beanName?>');
            return;
        }

        $bean = Bean::load('<?=$ctx->beanName?>', $id);
        if (!$bean->id) {
            $this->flash('error', '<?=$ctx->className?> not found');
            Flight::redirect('/<?=$ctx->beanName?>');
            return;
        }

        $request = Flight::request();
        if ($request->method === 'POST') {
<?=implode("\n", $fieldAssignments)?>

            try {
                Bean::store($bean);
                $this->flash('success', '<?=$ctx->className?> updated successfully');
                Flight::redirect('/<?=$ctx->beanName?>');
            } catch (\Exception $e) {
                $this->flash('error', 'Error: ' . $e->getMessage());
            }
        }

        $this->render('<?=$ctx->beanName?>/edit', [
            'title' => 'Edit <?=$ctx->className?>',
            'bean' => $bean,
            'isNew' => false,
<?php if (!empty($relLoading)): ?>
<?=implode("\n", $relLoading)?>

<?php endif; ?>
        ]);
    }

    /**
     * Store new record
     */
    public function store() {
        if (!$this->requireLogin()) return;
        if (!$this->requirePost()) return;

        $bean = Bean::dispense('<?=$ctx->beanName?>');
<?=implode("\n", $fieldAssignments)?>

        try {
            $id = Bean::store($bean);
            $this->flash('success', '<?=$ctx->className?> created successfully');
            Flight::redirect('/<?=$ctx->beanName?>/edit/' . $id);
        } catch (\Exception $e) {
            $this->flash('error', 'Error: ' . $e->getMessage());
            Flight::redirect('/<?=$ctx->beanName?>/create');
        }
    }

    /**
     * Delete record
     */
    public function delete() {
        if (!$this->requireLogin()) return;

        $id = $this->opId();
        if (!$id) {
            $this->jsonError('No <?=$ctx->beanName?> specified');
            return;
        }

        $bean = Bean::load('<?=$ctx->beanName?>', $id);
        if (!$bean->id) {
            $this->jsonError('<?=$ctx->className?> not found');
            return;
        }

        try {
            Bean::trash($bean);
            if (Flight::request()->ajax) {
                $this->jsonSuccess([], '<?=$ctx->className?> deleted');
            } else {
                $this->flash('success', '<?=$ctx->className?> deleted');
                Flight::redirect('/<?=$ctx->beanName?>');
            }
        } catch (\Exception $e) {
            if (Flight::request()->ajax) {
                $this->jsonError($e->getMessage());
            } else {
                $this->flash('error', 'Error: ' . $e->getMessage());
                Flight::redirect('/<?=$ctx->beanName?>');
            }
        }
    }

    /**
     * Toggle active status (AJAX)
     */
    public function toggle() {
        if (!$this->requireLogin()) return;

        $id = $this->opId();
        $bean = Bean::load('<?=$ctx->beanName?>', $id);

        if (!$bean->id) {
            $this->jsonError('<?=$ctx->className?> not found');
            return;
        }

        $bean->is_active = $bean->is_active ? 0 : 1;
        Bean::store($bean);

        $this->jsonSuccess([
            'id' => $bean->id,
            'is_active' => $bean->is_active
        ], $bean->is_active ? 'Activated' : 'Deactivated');
    }
}
