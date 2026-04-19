<?php
/**
 * Edit View Template
 *
 * This template OUTPUTS PHP code for the generated view file.
 * Variables starting with $ in the output are runtime variables.
 *
 * Variables available at generation time:
 * - $ctx: Context object with all scaffold data
 */

// Build form fields using field templates - these need special handling
// since they also contain runtime PHP
$formFields = '';
foreach ($ctx->getEditableFields() as $field) {
    $formFields .= $ctx->renderField($field);
}

// Build relationship sections
$relSections = '';
foreach ($ctx->relationships as $rel) {
    if (in_array($rel['type'], ['has-many', 'many-to-many'])) {
        $relName = $rel['beanClass'];
        $varName = $rel['bean'] . 's';
        $relSections .= <<<REL

            <!-- Related {$relName} -->
            <?php if (!empty(\${$varName})): ?>
            <div class="card mt-4">
                <div class="card-header">
                    <i class="bi bi-link-45deg"></i> Related {$relName}
                    <span class="badge bg-secondary"><?= count(\${$varName}) ?></span>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <?php foreach (\${$varName} as \$rel): ?>
                            <tr>
                                <td><?= h(\$rel->name ?? \$rel->title ?? 'ID: ' . \$rel->id) ?></td>
                                <td class="text-end">
                                    <a href="/{$rel['bean']}/edit/<?= \$rel->id ?>" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

REL;
    }
}

// Output the generated view file
echo '<' . "?php\n";
echo "// Runtime variables: \$bean, \$isNew, \$csrf, \$title\n";
echo "// These are passed from the controller\n";
echo '?' . ">\n";
?>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><?php echo '<?= $isNew ? \'Create\' : \'Edit\' ?>'; ?> <?=$ctx->className?></h1>
                <a href="/<?=$ctx->beanName?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to List
                </a>
            </div>

            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-<?php echo '<?= $isNew ? \'plus-lg\' : \'pencil\' ?>'; ?>"></i>
                    <?php echo '<?= $isNew ? \'New ' . $ctx->className . '\' : \'' . $ctx->className . ' #\' . $bean->id ?>'; ?>

                </div>
                <div class="card-body">
                    <form method="POST" action="/<?=$ctx->beanName?>/<?php echo '<?= $isNew ? \'store\' : \'edit/\' . $bean->id ?>'; ?>">
                        <!-- CSRF Token -->
                        <?php echo '<?php if (!empty($csrf) && is_array($csrf)): ?>'; ?>

                            <?php echo '<?php foreach ($csrf as $csrfName => $csrfValue): ?>'; ?>

                                <input type="hidden" name="<?php echo '<?= h($csrfName) ?>'; ?>" value="<?php echo '<?= h($csrfValue) ?>'; ?>">
                            <?php echo '<?php endforeach; ?>'; ?>

                        <?php echo '<?php endif; ?>'; ?>


<?=$formFields?>

                        <div class="d-flex justify-content-between mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg"></i> <?php echo '<?= $isNew ? \'Create\' : \'Save Changes\' ?>'; ?>

                            </button>
                            <?php echo '<?php if (!$isNew): ?>'; ?>

                            <button type="button" class="btn btn-outline-danger" onclick="deleteItem(<?php echo '<?= $bean->id ?>'; ?>)">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                            <?php echo '<?php endif; ?>'; ?>

                        </div>
                    </form>
                </div>
            </div>
<?=$relSections?>

            <?php echo '<?php if (!$isNew && $bean->created_at): ?>'; ?>

            <div class="mt-3 text-muted small">
                <i class="bi bi-clock"></i> Created: <?php echo '<?= date(\'M j, Y H:i\', strtotime($bean->created_at)) ?>'; ?>

                <?php echo '<?php if ($bean->updated_at): ?>'; ?>

                | Updated: <?php echo '<?= date(\'M j, Y H:i\', strtotime($bean->updated_at)) ?>'; ?>

                <?php echo '<?php endif; ?>'; ?>

            </div>
            <?php echo '<?php endif; ?>'; ?>

        </div>
    </div>
</div>

<?php echo '<?php if (!$isNew): ?>'; ?>

<script>
function deleteItem(id) {
    if (confirm('Are you sure you want to delete this <?=$ctx->beanName?>?')) {
        fetch('/<?=$ctx->beanName?>/delete/' + id, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.href = '/<?=$ctx->beanName?>';
            } else {
                alert('Error: ' + (data.message || 'Failed to delete'));
            }
        })
        .catch(error => alert('Error: ' + error.message));
    }
}
</script>
<?php echo '<?php endif; ?>'; ?>
