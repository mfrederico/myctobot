<?php
/**
 * Index View Template
 *
 * Variables available:
 * - $ctx: Context object with all scaffold data
 */

// Get display fields (max 5 columns)
$displayFields = $ctx->getDisplayFields(5);

// Build table headers
$headers = '';
foreach ($displayFields as $field) {
    $headers .= "                                    <th>{$field['label']}</th>\n";
}

// Build table cells
$cells = '';
foreach ($displayFields as $field) {
    $name = $field['name'];
    switch ($field['type']) {
        case 'bool':
            $cells .= <<<CELL
                                    <td>
                                        <?php if (\$item->{$name}): ?>
                                        <span class="badge bg-success">Yes</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">No</span>
                                        <?php endif; ?>
                                    </td>

CELL;
            break;
        case 'datetime':
            $cells .= "                                    <td><small><?= \$item->{$name} ? date('M j, Y H:i', strtotime(\$item->{$name})) : '-' ?></small></td>\n";
            break;
        case 'date':
            $cells .= "                                    <td><small><?= \$item->{$name} ? date('M j, Y', strtotime(\$item->{$name})) : '-' ?></small></td>\n";
            break;
        case 'text':
            $cells .= "                                    <td><small><?= h(substr(\$item->{$name} ?? '', 0, 50)) ?><?= strlen(\$item->{$name} ?? '') > 50 ? '...' : '' ?></small></td>\n";
            break;
        default:
            $cells .= "                                    <td><?= h(\$item->{$name} ?? '') ?></td>\n";
    }
}
?>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><?=$ctx->className?> List</h1>
                <a href="/<?=$ctx->beanName?>/create" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i> New <?=$ctx->className?>

                </a>
            </div>

            <?php if (empty($items)): ?>
            <div class="alert alert-info">
                <h4 class="alert-heading"><i class="bi bi-info-circle"></i> No Records Yet</h4>
                <p>No <?=$ctx->beanName?> records have been created.</p>
                <hr>
                <a href="/<?=$ctx->beanName?>/create" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i> Create First <?=$ctx->className?>

                </a>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-list"></i> <?=$ctx->className?> Records
                    <span class="badge bg-light text-dark ms-2"><?= count($items) ?></span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
<?=$headers?>                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                <tr>
<?=$cells?>                                    <td class="text-end">
                                        <div class="btn-group" role="group">
                                            <a href="/<?=$ctx->beanName?>/edit/<?= $item->id ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    onclick="deleteItem(<?= $item->id ?>)"
                                                    title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

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
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Failed to delete'));
            }
        })
        .catch(error => alert('Error: ' + error.message));
    }
}
</script>
