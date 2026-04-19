<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .table-responsive { max-height: 70vh; }
        .table th { position: sticky; top: 0; background: #f8f9fa; z-index: 1; }
        .cell-truncate { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        pre.json-cell { max-width: 300px; max-height: 100px; overflow: auto; font-size: 0.75rem; margin: 0; }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-primary mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="/admin?api_key=<?= urlencode($apiKey ?? '') ?>">
                <i class="bi bi-database me-2"></i>{{APP_NAME}} Admin
            </a>
            <div>
                <a href="/admin/export/<?= htmlspecialchars($table) ?>?api_key=<?= urlencode($apiKey ?? '') ?>"
                   class="btn btn-outline-light btn-sm me-2">
                    <i class="bi bi-download me-1"></i>Export JSON
                </a>
                <a href="/" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Back to App
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <a href="/admin?api_key=<?= urlencode($apiKey ?? '') ?>" class="btn btn-outline-secondary btn-sm me-2">
                    <i class="bi bi-arrow-left"></i>
                </a>
                <span class="h4 mb-0"><?= htmlspecialchars($table) ?></span>
                <span class="badge bg-primary ms-2"><?= $total ?> total rows</span>
            </div>
            <div>
                Page <?= $page ?> of <?= $totalPages ?>
            </div>
        </div>

        <?php if (!empty($rows)): ?>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <?php foreach ($columns as $col): ?>
                            <th><?= htmlspecialchars($col) ?></th>
                            <?php endforeach; ?>
                            <th width="80">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($columns as $col): ?>
                            <td>
                                <?php
                                $val = $row[$col];
                                if ($val === null) {
                                    echo '<span class="text-muted">null</span>';
                                } elseif (is_string($val) && ($val[0] ?? '') === '{') {
                                    echo '<pre class="json-cell">' . htmlspecialchars($val) . '</pre>';
                                } elseif (is_string($val) && strlen($val) > 50) {
                                    echo '<span class="cell-truncate" title="' . htmlspecialchars($val) . '">';
                                    echo htmlspecialchars(substr($val, 0, 50)) . '...';
                                    echo '</span>';
                                } else {
                                    echo htmlspecialchars($val);
                                }
                                ?>
                            </td>
                            <?php endforeach; ?>
                            <td>
                                <button class="btn btn-outline-danger btn-sm"
                                        onclick="deleteRecord('<?= htmlspecialchars($table) ?>', <?= $row['id'] ?>)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?api_key=<?= urlencode($apiKey ?? '') ?>&page=<?= $page - 1 ?>">Previous</a>
                </li>
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?api_key=<?= urlencode($apiKey ?? '') ?>&page=<?= $i ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?api_key=<?= urlencode($apiKey ?? '') ?>&page=<?= $page + 1 ?>">Next</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>

        <?php else: ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>No records found in this table.
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function deleteRecord(table, id) {
            if (!confirm('Delete this record?')) return;

            fetch('/admin/delete/' + id + '?table=' + table + '&api_key=<?= urlencode($apiKey ?? '') ?>', {
                method: 'POST'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.error || 'Delete failed');
                }
            });
        }
    </script>
</body>
</html>
